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

	/**
	 * @var int Sanity cap per source per poll.
	 *
	 * Was 5,000, described as "far above anything the APIs return" — it is not.
	 * Search Console reports page+query rows sorted by clicks DESCENDING, so a
	 * cap does not sample the window, it TRUNCATES THE TAIL: on a site with more
	 * than this many page/query pairs, the pages that earn a handful of clicks
	 * each simply vanish. Those are exactly the pages the worklist is for, and a
	 * page with no row gets no promoted focus — the feature silently degrades on
	 * the sites that need it most, with nothing on screen saying so.
	 */
	const MAX_ROWS = 25000;

	/**
	 * @var int Tuples per INSERT.
	 *
	 * The rows used to go in as ONE statement. At 5,000 tuples that was already
	 * a large packet; at 25,000 it is a `max_allowed_packet` error on default
	 * MySQL — which would have arrived as a silently empty snapshot, since the
	 * query result was never checked. Chunked, and each chunk is checked.
	 */
	const INSERT_CHUNK = 500;

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
	 * @return int Rows actually written — 0 means nothing was stored.
	 */
	public static function replace( $source, array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return 0;
		}
		$source = sanitize_key( (string) $source );
		$table  = self::name();

		$wpdb->delete( $table, array( 'source' => $source ), array( '%s' ) );

		// Built and flushed a chunk at a time. Holding every prepared tuple first
		// meant a third full copy of the window in memory — on top of the API's
		// decoded response and the caller's mapped rows — which at 25,000 rows is
		// how a poll gets itself killed on a 128MB host. Only INSERT_CHUNK
		// tuples are ever alive here now.
		$written = 0;
		$values  = array();
		$count   = 0;
		$flush   = function () use ( &$values, &$written, $table, $wpdb ) {
			if ( empty( $values ) ) {
				return true;
			}
			$done = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every tuple prepared below; table name is our own.
				"INSERT INTO $table (source,query_text,page_url,page_id,clicks,impressions,position,range_start,range_end) VALUES " . implode( ',', $values )
			);
			$values = array();
			if ( false === $done ) {
				return false;
			}
			$written += (int) $done;
			return true;
		};

		foreach ( $rows as $row ) {
			if ( $count >= self::MAX_ROWS ) {
				break;
			}
			$count++;
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

			// A failed chunk stops the run rather than letting the rest land on
			// top of a hole: the screens would read the survivors as the whole
			// truth. The count returned says how much actually made it.
			if ( count( $values ) >= self::INSERT_CHUNK && ! $flush() ) {
				return $written;
			}
		}
		$flush();

		return $written;
	}

	/**
	 * One source's window totals, as one aggregate.
	 *
	 * Deliberately SQL rather than summing {@see snapshot()}: a caller that only
	 * wants "how many clicks" must not pull the whole window into PHP to find
	 * out. That mattered little at 5,000 rows and matters a great deal at
	 * 25,000 — and the audience card asks this on every dashboard poll.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return array{clicks:int,impressions:int,rows:int,start:string,end:string}
	 */
	public static function totals( $source ) {
		global $wpdb;
		$table = self::name();
		$row   = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(impressions),0) AS impressions, COUNT(*) AS rows_held,
				MIN(range_start) AS range_start, MAX(range_end) AS range_end
			FROM $table WHERE source = %s",
			sanitize_key( (string) $source )
		), ARRAY_A );

		return array(
			'clicks'      => (int) ( isset( $row['clicks'] ) ? $row['clicks'] : 0 ),
			'impressions' => (int) ( isset( $row['impressions'] ) ? $row['impressions'] : 0 ),
			'rows'        => (int) ( isset( $row['rows_held'] ) ? $row['rows_held'] : 0 ),
			'start'       => (string) ( isset( $row['range_start'] ) ? $row['range_start'] : '' ),
			'end'         => (string) ( isset( $row['range_end'] ) ? $row['range_end'] : '' ),
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
