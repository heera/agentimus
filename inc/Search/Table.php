<?php
/**
 * Search query-stats table — the latest window of per-query search performance
 * as the engines themselves reported it: which query, which of the site's own
 * pages, how many impressions and clicks, at what average position.
 *
 * A SNAPSHOT, not a time series: every poll replaces its source's rows wholesale
 * (the engines re-report their whole trailing window each time, so accumulating
 * would double-count and balloon). Rows are engine-published aggregates about
 * the site's OWN pages — search demand, never a person: no searcher identity
 * exists anywhere in the source APIs, let alone here.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Table {

	/** @var string Schema version — bump on any structural change. */
	const VERSION = '1';

	/** @var string Option recording the installed schema version. */
	const VERSION_OPTION = 'agentimus_search_db_version';

	/** @var int Sanity cap per source per poll — far above anything the APIs return. */
	const MAX_ROWS = 5000;

	/**
	 * The fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_search_queries';
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
			source varchar(10) NOT NULL DEFAULT 'bing',
			query_text varchar(191) NOT NULL DEFAULT '',
			page_url text,
			page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			clicks int(10) unsigned NOT NULL DEFAULT 0,
			impressions int(10) unsigned NOT NULL DEFAULT 0,
			position decimal(6,2) NOT NULL DEFAULT 0,
			range_start date DEFAULT NULL,
			range_end date DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY source (source),
			KEY page_id (page_id)
		) $collate;" );

		foreach ( array( 'source', 'query_text', 'page_url', 'page_id', 'clicks', 'impressions', 'position', 'range_start', 'range_end' ) as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own prefix helper.
			if ( null === $found ) {
				return;
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Replace one source's snapshot with a fresh poll's rows.
	 *
	 * Replace-then-insert inside the caller's poll lock: the engines re-report
	 * their whole window every time, so the previous snapshot is superseded, not
	 * appended to. An EMPTY rows array is a no-op, never a wipe — a failed or
	 * empty API answer must not erase the last good snapshot.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @param array  $rows   Rows: { query, page_url, page_id, clicks, impressions, position, range_start, range_end }.
	 * @return void
	 */
	public static function replace( $source, array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return;
		}
		$source = sanitize_key( (string) $source );
		$table  = self::name();

		$wpdb->delete( $table, array( 'source' => $source ), array( '%s' ) );

		$values = array();
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
			$values[] = $wpdb->prepare(
				'(%s,%s,%s,%d,%d,%d,%f,%s,%s)',
				$source,
				mb_substr( (string) ( isset( $row['query'] ) ? $row['query'] : '' ), 0, 191 ),
				(string) ( isset( $row['page_url'] ) ? $row['page_url'] : '' ),
				(int) ( isset( $row['page_id'] ) ? $row['page_id'] : 0 ),
				(int) ( isset( $row['clicks'] ) ? $row['clicks'] : 0 ),
				(int) ( isset( $row['impressions'] ) ? $row['impressions'] : 0 ),
				(float) ( isset( $row['position'] ) ? $row['position'] : 0 ),
				(string) ( isset( $row['range_start'] ) ? $row['range_start'] : gmdate( 'Y-m-d', time() - 56 * DAY_IN_SECONDS ) ),
				(string) ( isset( $row['range_end'] ) ? $row['range_end'] : gmdate( 'Y-m-d' ) )
			);
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every tuple prepared above; table name is our own.
			"INSERT INTO $table (source,query_text,page_url,page_id,clicks,impressions,position,range_start,range_end) VALUES " . implode( ',', $values )
		);
	}

	/**
	 * One source's full snapshot.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return array<int,array>
	 */
	public static function snapshot( $source ) {
		global $wpdb;
		$table = self::name();
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT query_text, page_url, page_id, clicks, impressions, position, range_start, range_end
			FROM $table WHERE source = %s ORDER BY impressions DESC",
			sanitize_key( (string) $source )
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'query'       => (string) $row['query_text'],
				'page_url'    => (string) $row['page_url'],
				'page_id'     => (int) $row['page_id'],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'position'    => (float) $row['position'],
				'range_start' => (string) $row['range_start'],
				'range_end'   => (string) $row['range_end'],
			);
		}
		return $out;
	}

	/**
	 * Whether a source has any rows at all — the "collecting vs ready" gate.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return bool
	 */
	public static function has_rows( $source ) {
		global $wpdb;
		$table = self::name();
		return (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT 1 FROM $table WHERE source = %s LIMIT 1",
			sanitize_key( (string) $source )
		) );
	}
}
