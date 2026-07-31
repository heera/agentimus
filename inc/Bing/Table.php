<?php
/**
 * Bing daily-stats table — one row per day: crawl counts, index size and
 * robots blocks as Bing reported them.
 *
 * Bing's own window is short; this table is why the index-size trend keeps
 * growing anyway. Rows are site-level AGGREGATES — no URLs, no queries,
 * nothing that could stand for a person.
 *
 * @package Agentimus
 */

namespace Agentimus\Bing;

defined( 'ABSPATH' ) || exit;

final class Table {

	/** @var string Schema version — bump on any structural change. */
	const VERSION = '1';

	/** @var string Option recording the installed schema version. */
	const VERSION_OPTION = 'agentimus_bing_db_version';

	/** @var int Age-based retention (days) — a row per day, this is tiny. */
	const RETENTION_DAYS = 800;

	/**
	 * The fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_bing_daily';
	}

	/**
	 * Install only when the recorded version is stale.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Create or migrate the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, lowercase types.
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			date_at date NOT NULL,
			crawled int(10) unsigned NOT NULL DEFAULT 0,
			in_index int(10) unsigned NOT NULL DEFAULT 0,
			in_links int(10) unsigned NOT NULL DEFAULT 0,
			blocked_robots int(10) unsigned NOT NULL DEFAULT 0,
			code_2xx int(10) unsigned NOT NULL DEFAULT 0,
			code_301 int(10) unsigned NOT NULL DEFAULT 0,
			code_302 int(10) unsigned NOT NULL DEFAULT 0,
			code_4xx int(10) unsigned NOT NULL DEFAULT 0,
			code_5xx int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY date_at (date_at)
		) $collate;" );

		// Verify the columns actually exist before recording the version —
		// stamping a drifted schema would break every query forever.
		foreach ( array( 'date_at', 'crawled', 'in_index', 'in_links', 'blocked_robots', 'code_2xx', 'code_301', 'code_302', 'code_4xx', 'code_5xx' ) as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own prefix helper.
			if ( null === $found ) {
				return;
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Store one poll's rows. A re-polled day REPLACES its previous row —
	 * every poll re-reads Bing's whole window, so adding would double-count.
	 *
	 * @param array $rows Rows shaped like Client::crawl_stats() returns.
	 * @return void
	 */
	public static function upsert( array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return;
		}
		$table  = self::name();
		$values = array();
		foreach ( $rows as $row ) {
			$values[] = $wpdb->prepare(
				'(%s,%d,%d,%d,%d,%d,%d,%d,%d,%d)',
				(string) $row['date_at'],
				(int) $row['crawled'],
				(int) $row['in_index'],
				(int) $row['in_links'],
				(int) $row['blocked_robots'],
				(int) $row['code_2xx'],
				(int) $row['code_301'],
				(int) $row['code_302'],
				(int) $row['code_4xx'],
				(int) $row['code_5xx']
			);
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every tuple prepared above; table name is our own.
			"INSERT INTO $table (date_at,crawled,in_index,in_links,blocked_robots,code_2xx,code_301,code_302,code_4xx,code_5xx) VALUES " . implode( ',', $values ) . '
			ON DUPLICATE KEY UPDATE
				crawled = VALUES(crawled), in_index = VALUES(in_index), in_links = VALUES(in_links),
				blocked_robots = VALUES(blocked_robots), code_2xx = VALUES(code_2xx), code_301 = VALUES(code_301),
				code_302 = VALUES(code_302), code_4xx = VALUES(code_4xx), code_5xx = VALUES(code_5xx)'
		);
	}

	/**
	 * The last N days of rows, oldest first.
	 *
	 * @param int $days Window length in days.
	 * @return array<int,array>
	 */
	public static function window( $days ) {
		global $wpdb;
		$table = self::name();
		$since = gmdate( 'Y-m-d', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT date_at, crawled, in_index, in_links, blocked_robots,
				code_2xx, code_301, code_302, code_4xx, code_5xx
			FROM $table WHERE date_at >= %s ORDER BY date_at ASC",
			$since
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$clean = array( 'date_at' => (string) $row['date_at'] );
			foreach ( array( 'crawled', 'in_index', 'in_links', 'blocked_robots', 'code_2xx', 'code_301', 'code_302', 'code_4xx', 'code_5xx' ) as $k ) {
				$clean[ $k ] = (int) $row[ $k ];
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * Age out old rows.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;
		$table  = self::name();
		$cutoff = gmdate( 'Y-m-d', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE date_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
	}
}
