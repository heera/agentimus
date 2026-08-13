<?php
/**
 * Content grades — one stored row per published post, holding the few numbers
 * the worklist ranks and filters by.
 *
 * WHY THIS EXISTS. Deciding which pages are "worth fixing" means grading every
 * page, and grading a page means rendering and reading it. That is why the
 * worklist read thirty pages and stopped: the ranking could only ever cover what
 * one request could afford to parse, so an owner saw "the 30 most worth looking
 * at, of 340" and had no way to reach the other 310. You cannot page through a
 * ranking you never computed.
 *
 * So the grading moves out of the request. A background sweep grades pages a
 * chunk at a time and writes the answer here; the screen then reads an indexed
 * table, and page four costs exactly what page one costs.
 *
 * ⚠️ WHAT IS STORED IS THE RANKING, NOT THE ROW. A row's detail — which words
 * are missing, which passage answers the search, what each flag says — is still
 * built live for the twenty rows actually on screen. Caching all of that would
 * be a second copy of the truth, free to drift from the post it describes.
 *
 * ⚠️ AND UNTIL THE SWEEP HAS FINISHED, THE COUNTS ARE PARTIAL. A site that has
 * graded 40 of its 300 pages must say so rather than present 40 as the whole
 * picture — {@see remaining()} is what lets the screen tell the truth about its
 * own progress.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Grades {

	/** @var string Schema version — bump on any structural change. */
	const VERSION = '1';

	/** @var string Option recording the installed schema version. */
	const VERSION_OPTION = 'agentimus_grades_db_version';

	/** @var string The sweep's cron hook. */
	const CRON = 'agentimus_grade_sweep';

	/**
	 * @var int Posts graded per sweep run.
	 *
	 * Each one renders and reads a page, so this is the same real cost the
	 * worklist used to pay inside a web request — only now nobody is waiting for
	 * it, and it is bounded per run rather than per screen.
	 */
	const SWEEP_CHUNK = 20;

	/**
	 * The fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'agentimus_content_grades';
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
			post_id bigint(20) unsigned NOT NULL,
			needs_work tinyint(1) NOT NULL DEFAULT 0,
			flags smallint(5) unsigned NOT NULL DEFAULT 0,
			stake int(10) unsigned NOT NULL DEFAULT 0,
			coverage varchar(20) NOT NULL DEFAULT '',
			has_focus tinyint(1) NOT NULL DEFAULT 0,
			content_hash char(32) NOT NULL DEFAULT '',
			graded_at datetime DEFAULT NULL,
			PRIMARY KEY  (post_id),
			KEY rank_order (needs_work, stake),
			KEY graded (graded_at)
		) $collate;" );

		foreach ( array( 'post_id', 'needs_work', 'flags', 'stake', 'coverage', 'has_focus', 'content_hash', 'graded_at' ) as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own prefix helper.
			if ( null === $found ) {
				return;
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Keep the sweep alive. Hourly is the heartbeat; a run with more to do books
	 * its own follow-up a minute later {@see \Agentimus\Worklist::sweep_and_continue()}.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	/**
	 * Stop the sweep. The grades themselves stay — they describe the owner's
	 * content, not this plugin's schedule, and re-reading every page on the next
	 * activation would be a poor thank-you.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * A fingerprint of everything a grade depends on.
	 *
	 * Not the modified date: a post can be re-saved with nothing changed, and
	 * re-reading every page because somebody clicked Update on a typo they then
	 * undid is work nobody asked for. The focus keyword is in here too, because
	 * changing which search a page is measured against changes its grade without
	 * touching a word of the content.
	 *
	 * @param \WP_Post $post  The post.
	 * @param string   $focus The chosen focus, if any.
	 * @return string
	 */
	public static function hash( $post, $focus = '' ) {
		return md5( (string) $post->post_title . '|' . (string) $post->post_content . '|' . (string) $focus );
	}

	/**
	 * Whether the table is present at the version this code expects.
	 *
	 * One autoloaded option read, no query. It guards the writes that hang off
	 * WordPress's own hooks — saving and deleting a post — because those fire on
	 * any site, including one where the table has not been created yet or where
	 * creating it failed. Writing anyway does not merely fail: it writes a
	 * database error into the owner's log every time they save a post, which is
	 * how a plugin gets blamed for something nobody can see.
	 *
	 * @return bool
	 */
	private static function installed() {
		return get_option( self::VERSION_OPTION ) === self::VERSION;
	}

	/**
	 * Store one post's grade.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $grade   { needsWork, flags, stake, coverage, hasFocus, hash }.
	 * @return void
	 */
	public static function record( $post_id, array $grade ) {
		global $wpdb;

		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! self::installed() ) {
			return;
		}
		$table = self::name();

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"INSERT INTO $table (post_id, needs_work, flags, stake, coverage, has_focus, content_hash, graded_at)
			VALUES (%d, %d, %d, %d, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE needs_work = VALUES(needs_work), flags = VALUES(flags), stake = VALUES(stake),
				coverage = VALUES(coverage), has_focus = VALUES(has_focus), content_hash = VALUES(content_hash),
				graded_at = VALUES(graded_at)",
			$post_id,
			empty( $grade['needsWork'] ) ? 0 : 1,
			max( 0, (int) ( isset( $grade['flags'] ) ? $grade['flags'] : 0 ) ),
			max( 0, (int) ( isset( $grade['stake'] ) ? $grade['stake'] : 0 ) ),
			substr( (string) ( isset( $grade['coverage'] ) ? $grade['coverage'] : '' ), 0, 20 ),
			empty( $grade['hasFocus'] ) ? 0 : 1,
			(string) ( isset( $grade['hash'] ) ? $grade['hash'] : '' ),
			gmdate( 'Y-m-d H:i:s' )
		) );
	}

	/**
	 * Forget a post's grade — it was deleted, unpublished, or its type stopped
	 * being one the engines can see.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function forget( $post_id ) {
		global $wpdb;
		if ( ! self::installed() ) {
			return;
		}
		$wpdb->delete( self::name(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * Mark a post's grade as out of date, without recomputing it here.
	 *
	 * Called on save, where the owner is waiting for the editor to come back and
	 * rendering their page again would be felt. The sweep picks it up.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function mark_stale( $post_id ) {
		global $wpdb;
		if ( ! self::installed() ) {
			return;
		}
		$table = self::name();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"UPDATE $table SET graded_at = NULL, content_hash = '' WHERE post_id = %d",
			(int) $post_id
		) );
	}

	/**
	 * Published posts with no usable grade yet, oldest content first.
	 *
	 * "No usable grade" is a missing row OR one whose graded_at was cleared by a
	 * save. The hash is checked by the caller, which is the only place that knows
	 * what the post says now.
	 *
	 * @param array<int,string> $types Post types to consider.
	 * @param int               $limit How many.
	 * @return array<int,int> Post IDs.
	 */
	public static function ungraded( array $types, $limit ) {
		global $wpdb;

		$limit = max( 0, (int) $limit );
		if ( 0 === $limit || empty( $types ) ) {
			return array();
		}
		$table   = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; every value bound.
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN $table g ON g.post_id = p.ID
			WHERE p.post_status = 'publish' AND p.post_type IN ($holders)
				AND (g.post_id IS NULL OR g.graded_at IS NULL)
			ORDER BY p.post_modified DESC
			LIMIT %d",
			array_merge( $types, array( $limit ) )
		) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many published posts are still waiting for a grade.
	 *
	 * The screen's honesty about itself: a list ranked over 40 of 300 pages is
	 * not the same claim as one ranked over all of them, and only this number
	 * lets it say which it is.
	 *
	 * @param array<int,string> $types Post types to consider.
	 * @return int
	 */
	public static function remaining( array $types ) {
		global $wpdb;

		if ( empty( $types ) ) {
			return 0;
		}
		$table   = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; every value bound.
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			LEFT JOIN $table g ON g.post_id = p.ID
			WHERE p.post_status = 'publish' AND p.post_type IN ($holders)
				AND (g.post_id IS NULL OR g.graded_at IS NULL)",
			$types
		) );
	}

	/**
	 * One page of post IDs for a filter, in ranked order.
	 *
	 * ⭐ The whole point of the table. Ordered by whether a page needs work and
	 * then by what fixing it is worth — the same order the worklist has always
	 * used — but as an indexed query, so the tenth page of results costs what the
	 * first does and no page is unreachable.
	 *
	 * @param string            $filter 'fixable' | 'clear' | 'setAside'.
	 * @param array<int,string> $types  Post types to consider.
	 * @param array<int,int>    $aside  Post IDs the owner has set aside.
	 * @param int               $page   1-based page number.
	 * @param int               $per    Rows per page.
	 * @return array{ids:array<int,int>,total:int}
	 */
	public static function page( $filter, array $types, array $aside, $page, $per ) {
		global $wpdb;

		$per  = max( 1, (int) $per );
		$page = max( 1, (int) $page );
		if ( empty( $types ) ) {
			return array( 'ids' => array(), 'total' => 0 );
		}

		$table   = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$args    = $types;

		// Set-aside is a hand-curated list and stays small, so an IN/NOT IN over
		// it is cheaper than a second table. Ints only, cast before use.
		$aside  = array_values( array_unique( array_filter( array_map( 'intval', $aside ) ) ) );
		$in     = $aside ? implode( ',', $aside ) : '0';
		$where  = "p.post_status = 'publish' AND p.post_type IN ($holders)";

		if ( 'setAside' === $filter ) {
			$where .= " AND g.post_id IN ($in)";
		} else {
			$where .= " AND g.post_id NOT IN ($in)";
			$where .= 'fixable' === $filter ? ' AND g.needs_work = 1' : ' AND g.needs_work = 0';
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; ids cast to int, everything else bound.
			"SELECT COUNT(*) FROM $table g INNER JOIN {$wpdb->posts} p ON p.ID = g.post_id WHERE $where",
			$args
		) );

		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; ids cast to int, everything else bound.
			"SELECT g.post_id FROM $table g INNER JOIN {$wpdb->posts} p ON p.ID = g.post_id
			WHERE $where
			ORDER BY g.needs_work DESC, g.stake DESC, g.post_id DESC
			LIMIT %d OFFSET %d",
			array_merge( $args, array( $per, ( $page - 1 ) * $per ) )
		) );

		return array( 'ids' => array_map( 'intval', (array) $ids ), 'total' => $total );
	}

	/**
	 * How many rows each filter holds, over the WHOLE site.
	 *
	 * Exclusive by construction, like the chips they feed: every graded page
	 * lands in exactly one of the three, so the numbers add up to the list.
	 *
	 * @param array<int,string> $types Post types to consider.
	 * @param array<int,int>    $aside Post IDs the owner has set aside.
	 * @return array{fixable:int,clear:int,setAside:int}
	 */
	public static function counts( array $types, array $aside ) {
		global $wpdb;

		$out = array( 'fixable' => 0, 'clear' => 0, 'setAside' => 0 );
		if ( empty( $types ) ) {
			return $out;
		}

		$table   = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$aside   = array_values( array_unique( array_filter( array_map( 'intval', $aside ) ) ) );
		$in      = $aside ? implode( ',', $aside ) : '0';

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; ids cast to int, everything else bound.
			"SELECT
				SUM(CASE WHEN g.post_id IN ($in) THEN 1 ELSE 0 END) AS parked,
				SUM(CASE WHEN g.post_id NOT IN ($in) AND g.needs_work = 1 THEN 1 ELSE 0 END) AS fixable,
				SUM(CASE WHEN g.post_id NOT IN ($in) AND g.needs_work = 0 THEN 1 ELSE 0 END) AS clear
			FROM $table g INNER JOIN {$wpdb->posts} p ON p.ID = g.post_id
			WHERE p.post_status = 'publish' AND p.post_type IN ($holders)",
			$types
		), ARRAY_A );

		$row = isset( $rows[0] ) ? $rows[0] : array();
		return array(
			'fixable'  => (int) ( isset( $row['fixable'] ) ? $row['fixable'] : 0 ),
			'clear'    => (int) ( isset( $row['clear'] ) ? $row['clear'] : 0 ),
			'setAside' => (int) ( isset( $row['parked'] ) ? $row['parked'] : 0 ),
		);
	}

	/**
	 * How many graded pages have no search reaching them.
	 *
	 * @param array<int,string> $types Post types to consider.
	 * @return int
	 */
	public static function without_search( array $types ) {
		global $wpdb;

		if ( empty( $types ) ) {
			return 0;
		}
		$table   = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; every value bound.
			"SELECT COUNT(*) FROM $table g INNER JOIN {$wpdb->posts} p ON p.ID = g.post_id
			WHERE p.post_status = 'publish' AND p.post_type IN ($holders) AND g.has_focus = 0",
			$types
		) );
	}
}
