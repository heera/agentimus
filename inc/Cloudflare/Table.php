<?php
/**
 * Edge aggregates table — one row per (UTC hour, crawler), holding the counts
 * the panel shows: total edge requests, cache-served, origin-served, blocked
 * at the edge, and bytes out.
 *
 * Cloudflare's Free plan forgets these numbers within days; this table is why
 * the panel's history keeps growing anyway. Rows hold AGGREGATES for known AI
 * crawlers only — no IPs, no paths, nothing that could stand for a person —
 * so the local-retention doctrine is satisfied by construction.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

defined( 'ABSPATH' ) || exit;

final class Table {

	/** @var string Schema version — bump on any structural change. */
	const VERSION = '1';

	/** @var string Option recording the installed schema version. */
	const VERSION_OPTION = 'agentimus_edge_db_version';

	/** @var int Age-based retention (days). At ~15 crawlers × 24 rows/day this is small. */
	const RETENTION_DAYS = 400;

	/** @var int Absolute row cap — nothing grows unbounded even if retention misbehaves. */
	const MAX_ROWS = 200000;

	/**
	 * The fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_edge_hourly';
	}

	/**
	 * Install only when the recorded version is stale — cheap enough to call on
	 * every boot, so sub-sites that missed activation self-heal.
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
			hour_at datetime NOT NULL,
			ua varchar(64) NOT NULL,
			requests int(10) unsigned NOT NULL DEFAULT 0,
			cached int(10) unsigned NOT NULL DEFAULT 0,
			origin int(10) unsigned NOT NULL DEFAULT 0,
			blocked int(10) unsigned NOT NULL DEFAULT 0,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY hour_ua (hour_at,ua),
			KEY hour_at (hour_at)
		) $collate;" );

		// Verify the columns actually exist before recording the version — dbDelta
		// can silently skip an ADD COLUMN on a drifted schema, and stamping the
		// version anyway would break every query forever.
		foreach ( array( 'hour_at', 'ua', 'requests', 'cached', 'origin', 'blocked', 'bytes' ) as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own prefix helper.
			if ( null === $found ) {
				return;
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Store one poll's aggregates. A re-polled hour REPLACES its previous row
	 * (each poll recomputes the whole window from Cloudflare, so adding would
	 * double-count).
	 *
	 * @param array $rows Rows shaped { hour_at, ua, requests, cached, origin, blocked, bytes }.
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
				'(%s,%s,%d,%d,%d,%d,%d)',
				(string) $row['hour_at'],
				(string) $row['ua'],
				(int) $row['requests'],
				(int) $row['cached'],
				(int) $row['origin'],
				(int) $row['blocked'],
				(int) $row['bytes']
			);
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every tuple prepared above; table name is our own.
			"INSERT INTO $table (hour_at,ua,requests,cached,origin,blocked,bytes) VALUES " . implode( ',', $values ) . '
			ON DUPLICATE KEY UPDATE
				requests = VALUES(requests), cached = VALUES(cached), origin = VALUES(origin),
				blocked = VALUES(blocked), bytes = VALUES(bytes)'
		);
	}

	/**
	 * The most recent stored hour, as a Unix timestamp — where the next poll
	 * resumes from. 0 when the table is empty.
	 *
	 * @return int
	 */
	public static function latest_hour() {
		global $wpdb;
		$table = self::name();
		$hour  = $wpdb->get_var( "SELECT MAX(hour_at) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- our own table.
		return $hour ? (int) strtotime( $hour . ' UTC' ) : 0;
	}

	/**
	 * Per-crawler totals over the last N days, busiest first.
	 *
	 * @param int $days Window length in days.
	 * @return array<int,array{ua:string,requests:int,cached:int,origin:int,blocked:int,bytes:int}>
	 */
	public static function summary( $days ) {
		global $wpdb;
		$table = self::name();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT ua,
				SUM(requests) AS requests, SUM(cached) AS cached, SUM(origin) AS origin,
				SUM(blocked) AS blocked, SUM(bytes) AS bytes
			FROM $table WHERE hour_at >= %s GROUP BY ua ORDER BY requests DESC",
			$since
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'ua'       => (string) $row['ua'],
				'requests' => (int) $row['requests'],
				'cached'   => (int) $row['cached'],
				'origin'   => (int) $row['origin'],
				'blocked'  => (int) $row['blocked'],
				'bytes'    => (int) $row['bytes'],
			);
		}
		return $out;
	}

	/**
	 * Per-crawler totals for the last N hours — the "is this still happening?"
	 * read the conflicts use, next to summary()'s "how big was it this week".
	 *
	 * @param int $hours Window length in hours.
	 * @return array<string,array{blocked:int,passed:int}> Keyed by crawler token.
	 */
	public static function recent( $hours ) {
		global $wpdb;
		$table = self::name();
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $hours ) * HOUR_IN_SECONDS ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT ua, SUM(blocked) AS blocked, SUM(cached) + SUM(origin) AS passed
			FROM $table WHERE hour_at >= %s GROUP BY ua",
			$since
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['ua'] ] = array(
				'blocked' => (int) $row['blocked'],
				'passed'  => (int) $row['passed'],
			);
		}
		return $out;
	}

	/**
	 * Age out old rows, then enforce the absolute cap regardless — so nothing
	 * grows unbounded even if the clock-based prune misses.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;
		$table  = self::name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE hour_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- our own table.
		if ( $count > self::MAX_ROWS ) {
			$excess = $count - self::MAX_ROWS;
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table ORDER BY hour_at ASC LIMIT %d", $excess ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		}
	}
}
