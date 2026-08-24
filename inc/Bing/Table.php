<?php
/**
 * Bing daily-stats table — one row per day: crawl counts, index size and
 * robots blocks as Bing reported them, plus the human-traffic series
 * (clicks and impressions) from GetRankAndTrafficStats.
 *
 * Bing's own window is short; this table is why the index-size trend keeps
 * growing anyway. Rows are site-level AGGREGATES — no URLs, no queries,
 * nothing that could stand for a person.
 *
 * Two datasets share the date key, each owning its columns: crawl polls
 * never touch clicks/impressions, traffic polls never touch the crawl
 * counts. The traffic columns are NULLABLE — NULL means "Bing never
 * reported this day" (a crawl-only row, or a day older than the traffic
 * API's reach), 0 means "reported: none". Readers skip NULL days; printing
 * them as zeros would claim "nobody came" where the truth is "unknown".
 *
 * @package Agentimus
 */

namespace Agentimus\Bing;

defined( 'ABSPATH' ) || exit;

final class Table {

	/** @var string Schema version — bump on any structural change. */
	const VERSION = '2';

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
			clicks int(10) unsigned DEFAULT NULL,
			impressions int(10) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY date_at (date_at)
		) $collate;" );

		// Verify the columns actually exist before recording the version —
		// stamping a drifted schema would break every query forever.
		foreach ( array( 'date_at', 'crawled', 'in_index', 'in_links', 'blocked_robots', 'code_2xx', 'code_301', 'code_302', 'code_4xx', 'code_5xx', 'clicks', 'impressions' ) as $column ) {
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
	/**
	 * Did Bing actually report anything for this day?
	 *
	 * ⭐⭐ A DAY WITH NO COUNTERS IS A NON-ANSWER, NOT A ZERO. Bing hands back a
	 * row per date in its window whether or not it has numbers for that date,
	 * and every absent field arrives here as 0 {@see Client::crawl_stats()}. A
	 * site does not hold 230 pages on Saturday, 0 on Sunday and 229 on Monday —
	 * that middle row is Bing saying nothing, and storing it as a measurement is
	 * how "0 pages in Bing's index" ends up on screen under a heading that means
	 * it.
	 *
	 * ⛔ NOT `in_index === 0` on its own. A quiet day — nothing crawled, no
	 * errors — is a real day, and its index count still stands at 229, so it is
	 * not all-zero and is never dropped by this. Only a row where every counter
	 * is zero cannot be something Bing measured.
	 *
	 * @param array<string,mixed> $row A crawl row, either shape.
	 * @return bool
	 */
	public static function reported( array $row ) {
		foreach ( array( 'crawled', 'in_index', 'in_links', 'blocked_robots', 'code_2xx', 'code_301', 'code_302', 'code_4xx', 'code_5xx' ) as $k ) {
			if ( (int) ( isset( $row[ $k ] ) ? $row[ $k ] : 0 ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	public static function upsert( array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return;
		}
		$table = self::name();

		// ⚠️ AN EMPTY ROW MUST NEVER OVERWRITE A REAL ONE. Bing re-sends its
		// whole window on every poll, so a date it reported properly yesterday
		// can come back today carrying nothing — and ON DUPLICATE KEY UPDATE
		// would happily replace good numbers with zeros. Empty rows are still
		// worth INSERTing when the date is new: that is how the screen can show
		// a day Bing answered for and said nothing about, rather than closing
		// the gap and pretending the day never existed.
		$full  = array();
		$empty = array();
		foreach ( $rows as $row ) {
			if ( self::reported( $row ) ) {
				$full[] = $row;
			} else {
				$empty[] = $row;
			}
		}
		if ( $empty ) {
			self::insert_missing( $empty );
		}
		if ( empty( $full ) ) {
			return;
		}

		$values = array();
		foreach ( $full as $row ) {
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
	 * Record dates Bing answered for but reported nothing about — and only when
	 * the date is new. INSERT IGNORE, never an upsert: the whole point is that
	 * these must not touch a row that already holds numbers.
	 *
	 * @param array<int,array> $rows Rows that carry no counters.
	 * @return void
	 */
	private static function insert_missing( array $rows ) {
		global $wpdb;
		$table  = self::name();
		$values = array();
		foreach ( $rows as $row ) {
			$values[] = $wpdb->prepare( '(%s)', (string) $row['date_at'] );
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every tuple prepared above; table name is our own.
			"INSERT IGNORE INTO $table (date_at) VALUES " . implode( ',', $values )
		);
	}

	/**
	 * Store the daily human-traffic series. Only its own two columns: an
	 * upsert here must not zero a day's crawl numbers, nor the other way
	 * round ({@see upsert()} lists its columns for the same reason). A new
	 * date inserts with the crawl columns at their defaults — those days are
	 * older than Bing's crawl window, and 0 is what it reports for them.
	 *
	 * @param array $rows Rows shaped like Client::traffic_stats() returns.
	 * @return void
	 */
	public static function record_traffic( array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return;
		}
		$table  = self::name();
		$values = array();
		foreach ( $rows as $row ) {
			$values[] = $wpdb->prepare(
				'(%s,%d,%d)',
				(string) $row['date_at'],
				(int) $row['clicks'],
				(int) $row['impressions']
			);
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every tuple prepared above; table name is our own.
			"INSERT INTO $table (date_at,clicks,impressions) VALUES " . implode( ',', $values ) . '
			ON DUPLICATE KEY UPDATE clicks = VALUES(clicks), impressions = VALUES(impressions)'
		);
	}

	/**
	 * The traffic series, oldest first — only days Bing actually reported
	 * (see the NULL rule in the class docblock). Shaped like Google's stored
	 * trend rows so {@see \Agentimus\Search\Report::weekly_from_daily()} and
	 * every reader downstream need no second branch.
	 *
	 * @param int $days Window length in days.
	 * @return array<int,array{date:string,clicks:int,impressions:int}>
	 */
	public static function traffic_series( $days ) {
		global $wpdb;
		$table = self::name();
		$since = gmdate( 'Y-m-d', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT date_at, clicks, impressions FROM $table
			WHERE clicks IS NOT NULL AND date_at >= %s ORDER BY date_at ASC",
			$since
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'date'        => (string) $row['date_at'],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
			);
		}
		return $out;
	}

	/**
	 * The series summed over a date range — the site-wide totals the Search
	 * Performance tiles quote. `days` says how many reported days the sums
	 * are built from: 0 means the series doesn't cover this range at all,
	 * and the caller keeps its previous figures rather than printing zeros.
	 *
	 * @param string $start Y-m-d, inclusive.
	 * @param string $end   Y-m-d, inclusive.
	 * @return array{clicks:int,impressions:int,days:int}
	 */
	public static function traffic_totals( $start, $end ) {
		global $wpdb;
		$table = self::name();
		$row   = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(impressions),0) AS impressions, COUNT(*) AS days
			FROM $table WHERE clicks IS NOT NULL AND date_at BETWEEN %s AND %s",
			(string) $start,
			(string) $end
		), ARRAY_A );

		return array(
			'clicks'      => (int) ( isset( $row['clicks'] ) ? $row['clicks'] : 0 ),
			'impressions' => (int) ( isset( $row['impressions'] ) ? $row['impressions'] : 0 ),
			'days'        => (int) ( isset( $row['days'] ) ? $row['days'] : 0 ),
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
