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

	/**
	 * @var string Schema version — bump on any structural change.
	 *
	 * 2: gradeable/points/flag_ids, so the Optimized pillar can be scored over
	 *    the WHOLE site from this table instead of over a 25-post sample taken
	 *    fresh in a page load. The sweep already ran every one of those checks
	 *    and threw the result away.
	 */
	const VERSION = '2';

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
	 * @var int Seconds one sweep run may spend grading, whatever the chunk says.
	 *
	 * ⭐ For the shared hosting most of this plugin's sites live on. The chunk
	 * bounds how MANY pages a run reads; this bounds how LONG it may take, and
	 * the two are not the same on a host where rendering one page costs a
	 * second. Whichever is reached first ends the run — a slow host simply
	 * grades fewer per tick and takes more ticks, instead of running its cron
	 * request into the execution limit.
	 */
	const SWEEP_SECONDS = 10;

	/**
	 * @var int Days after which a verdict is re-read, once nothing is waiting.
	 *
	 * Only the idle beat spends this {@see stale_graded()}. Nothing on a site
	 * still filling its table is delayed by it.
	 */
	const REGRADE_AFTER_DAYS = 30;

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
			gradeable tinyint(1) NOT NULL DEFAULT 0,
			points smallint(5) unsigned NOT NULL DEFAULT 0,
			flag_ids varchar(191) NOT NULL DEFAULT '',
			content_hash char(32) NOT NULL DEFAULT '',
			graded_at datetime DEFAULT NULL,
			PRIMARY KEY  (post_id),
			KEY rank_order (needs_work, stake),
			KEY graded (graded_at),
			KEY scoring (gradeable, points)
		) $collate;" );

		foreach ( array( 'post_id', 'needs_work', 'flags', 'stake', 'coverage', 'has_focus', 'gradeable', 'points', 'flag_ids', 'content_hash', 'graded_at' ) as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own prefix helper.
			if ( null === $found ) {
				return;
			}
		}

		// ⚠️⚠️ EVERY EXISTING ROW IS NOW A LIE ABOUT THE NEW COLUMNS — dbDelta
		// fills them with defaults, so an upgraded site would report `points = 0`
		// for pages that were never scored and hand the Optimized pillar a site
		// full of zeroes. Clearing `graded_at` puts every row back in the sweep's
		// queue: `remaining()` counts them, every screen already says "still
		// reading your content", and the pillar reports NO DATA rather than a
		// wrong number until the sweep has actually looked.
		//
		// ⛔ Never replace this with a default that merely looks plausible.
		if ( '' !== (string) get_option( self::VERSION_OPTION, '' ) ) {
			$wpdb->query( "UPDATE $table SET graded_at = NULL" ); // phpcs:ignore WordPress.DB -- our own table, no user input.
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
	 * ⚠️ Every READ asks it too, since the Optimized pillar started reading this
	 * table: a report can run before `register()` has installed it, and an
	 * unguarded SELECT against a missing table is that same log entry for
	 * something the owner cannot act on. Readers answer "nothing known yet",
	 * which is true and is already a state the screens say out loud.
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
			"INSERT INTO $table (post_id, needs_work, flags, stake, coverage, has_focus, gradeable, points, flag_ids, content_hash, graded_at)
			VALUES (%d, %d, %d, %d, %s, %d, %d, %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE needs_work = VALUES(needs_work), flags = VALUES(flags), stake = VALUES(stake),
				coverage = VALUES(coverage), has_focus = VALUES(has_focus), gradeable = VALUES(gradeable),
				points = VALUES(points), flag_ids = VALUES(flag_ids), content_hash = VALUES(content_hash),
				graded_at = VALUES(graded_at)",
			$post_id,
			empty( $grade['needsWork'] ) ? 0 : 1,
			max( 0, (int) ( isset( $grade['flags'] ) ? $grade['flags'] : 0 ) ),
			max( 0, (int) ( isset( $grade['stake'] ) ? $grade['stake'] : 0 ) ),
			substr( (string) ( isset( $grade['coverage'] ) ? $grade['coverage'] : '' ), 0, 20 ),
			empty( $grade['hasFocus'] ) ? 0 : 1,
			// ⚠️ Stored WITHOUT the owner's set-aside folded in. Set-aside is a
			// decision they change from the screen; baking it into a swept column
			// would leave the score reading a judgement they had already undone.
			// Every query here applies the ledger itself, at read time.
			empty( $grade['gradeable'] ) ? 0 : 1,
			min( 100, max( 0, (int) ( isset( $grade['points'] ) ? $grade['points'] : 0 ) ) ),
			substr( self::pack_ids( isset( $grade['flagIds'] ) ? (array) $grade['flagIds'] : array() ), 0, 191 ),
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
	 * Published posts with no usable grade yet — never-graded ones FIRST.
	 *
	 * "No usable grade" is a missing row OR one whose graded_at was cleared by a
	 * save. The hash is checked by the caller, which is the only place that knows
	 * what the post says now.
	 *
	 * ⚠️ `g.post_id IS NULL DESC` is the whole fairness of the sweep, and it was
	 * missing. Saving a post clears its grade, so an edit puts an ALREADY-KNOWN
	 * page back in this queue — and the order used to be `post_modified DESC`,
	 * which put it at the FRONT, ahead of everything never read. On a site that
	 * edits all day (a store touching product rows, a busy newsroom) the
	 * re-grades can arrive faster than a 20-a-minute chunk drains them, and the
	 * pages nobody has ever looked at are overtaken for ever: the tail starves
	 * while the card promises it is "still reading your content".
	 *
	 * Never-graded content is a set that only shrinks, so putting it first makes
	 * the backlog drain monotonically no matter how much editing happens beside
	 * it. Within each group the order stays newest-first, which is what an owner
	 * expects to see filled in first.
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
			ORDER BY (g.post_id IS NULL) DESC, p.post_modified DESC
			LIMIT %d",
			array_merge( $types, array( $limit ) )
		) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The oldest verdicts still on the books — the re-grade horizon.
	 *
	 * A grade is only ever refreshed by an edit ({@see mark_stale}), so a page
	 * nobody has touched keeps its verdict for ever. That is fine for the half
	 * that reads the content, and wrong for the half that reads SEARCH: a page
	 * can lose the query it used to answer without a character of it changing.
	 *
	 * ⭐ This runs ONLY when nothing is waiting to be graded for the first time,
	 * and only ever on the hourly beat — never with the one-minute follow-up the
	 * initial fill uses. A site still filling pays nothing for it, and a site
	 * that has finished spends cron ticks it was already burning to find an empty
	 * queue. On a very large site a full lap takes longer than the horizon
	 * itself; that is the intended behaviour, not a shortfall — the oldest
	 * verdict is always the one being refreshed, and the work never piles up.
	 *
	 * @param array<int,string> $types Post types to consider.
	 * @param int               $limit How many.
	 * @param int               $days  Re-read a verdict older than this.
	 * @return array<int,int> Post IDs, oldest verdict first.
	 */
	public static function stale_graded( array $types, $limit, $days = self::REGRADE_AFTER_DAYS ) {
		global $wpdb;

		$limit = max( 0, (int) $limit );
		if ( 0 === $limit || empty( $types ) || ! self::installed() ) {
			return array();
		}
		$table   = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// ⚠️ UTC, because {@see record()} stamps graded_at with gmdate() — NOT
		// current_time(). A site-local cutoff here would be off by the site's
		// offset in the direction that re-reads content early.
		$before  = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );

		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; every value bound.
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN $table g ON g.post_id = p.ID
			WHERE p.post_status = 'publish' AND p.post_type IN ($holders)
				AND g.graded_at IS NOT NULL AND g.graded_at < %s
			ORDER BY g.graded_at ASC
			LIMIT %d",
			array_merge( $types, array( $before, $limit ) )
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

		if ( empty( $types ) || ! self::installed() ) {
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
		if ( empty( $types ) || ! self::installed() ) {
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
	 * Flagged check ids, stored so they can be counted without re-rendering a
	 * page. Comma-wrapped on both ends (`,words,summary,`) — an unwrapped list
	 * cannot be matched in SQL without `summary` also hitting `no_summary`, and
	 * even though the tally below is done in PHP, a column that is only safe
	 * when nobody writes the obvious query is a trap left lying around.
	 *
	 * @param array<int,string> $ids Check ids.
	 * @return string
	 */
	private static function pack_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
		return $ids ? ',' . implode( ',', $ids ) . ',' : '';
	}

	/**
	 * @param string $packed A pack_ids() string.
	 * @return array<int,string>
	 */
	private static function unpack_ids( $packed ) {
		return array_values( array_filter( explode( ',', (string) $packed ) ) );
	}

	/**
	 * The Optimized pillar, over the WHOLE site.
	 *
	 * ⭐ This is what the grade store bought, a second time. The pillar used to
	 * average a sample of the 25 most recently modified posts, parsed fresh in a
	 * page load — the only shape possible before this table existed. So the
	 * Readiness card could say "every graded post and page is ready for AI to
	 * quote" over eighteen items while the content list, on the next screen,
	 * held twelve pages with content flags. Both true of what they measured; one
	 * of them written as though it spoke for the site.
	 *
	 * Every number here is the same measurement the sweep already performs, read
	 * back instead of recomputed: `points` is {@see \Agentimus\PageCheck::summarize()},
	 * and the issue tally is its flagged ids.
	 *
	 * ⚠️ `graded_at IS NOT NULL` is load-bearing. A page the sweep has not
	 * reached yet must not be averaged in as a zero — {@see remaining()} is how a
	 * screen says the reading is unfinished, and `score => null` is how this says
	 * it has nothing to claim yet.
	 *
	 * @param array<int,string> $types Post types to consider.
	 * @param array<int,int>    $aside Post IDs the owner set aside.
	 * @param int               $per   Most affected posts kept per issue.
	 * @return array{score:int|null,posts:int,issues:array<string,array{count:int,posts:array<int,int>}>}
	 */
	public static function optimize( array $types, array $aside, $per = 6 ) {
		global $wpdb;

		$out = array( 'score' => null, 'posts' => 0, 'issues' => array() );
		if ( empty( $types ) || ! self::installed() ) {
			return $out;
		}

		$table   = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$aside   = array_values( array_unique( array_filter( array_map( 'intval', $aside ) ) ) );
		$in      = $aside ? implode( ',', $aside ) : '0';
		$where   = "p.post_status = 'publish' AND p.post_type IN ($holders)
			AND g.gradeable = 1 AND g.graded_at IS NOT NULL AND g.post_id NOT IN ($in)";

		// The score in one aggregate — no row ever reaches PHP for this half, so
		// a site with ten thousand posts costs the same as one with ten.
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; ids cast to int, everything else bound.
			"SELECT COUNT(*) AS n, AVG(g.points) AS avg_points
			FROM $table g INNER JOIN {$wpdb->posts} p ON p.ID = g.post_id
			WHERE $where",
			$types
		), ARRAY_A );

		$n = (int) ( isset( $row['n'] ) ? $row['n'] : 0 );
		if ( $n < 1 ) {
			return $out; // Nothing read yet, or nothing article-like to read.
		}
		$out['posts'] = $n;
		$out['score'] = (int) round( (float) $row['avg_points'] );

		// The tally needs the ids themselves, so these rows do reach PHP — but
		// only the FLAGGED ones, which is the minority by design and the whole
		// point of the column being empty when a page is clean.
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; ids cast to int, everything else bound.
			"SELECT g.post_id, g.flag_ids
			FROM $table g INNER JOIN {$wpdb->posts} p ON p.ID = g.post_id
			WHERE $where AND g.flag_ids <> ''
			ORDER BY g.stake DESC, g.post_id DESC",
			$types
		), ARRAY_A );

		$per = max( 1, (int) $per );
		foreach ( (array) $rows as $r ) {
			foreach ( self::unpack_ids( isset( $r['flag_ids'] ) ? $r['flag_ids'] : '' ) as $id ) {
				if ( ! isset( $out['issues'][ $id ] ) ) {
					$out['issues'][ $id ] = array( 'count' => 0, 'posts' => array() );
				}
				++$out['issues'][ $id ]['count'];
				// ⚠️ The COUNT is the whole truth; the post list is a sample of it,
				// ordered by what a fix is worth. A caller printing the list must
				// not print its length as the count.
				if ( count( $out['issues'][ $id ]['posts'] ) < $per ) {
					$out['issues'][ $id ]['posts'][] = (int) $r['post_id'];
				}
			}
		}

		return $out;
	}

	/**
	 * Every published, gradeable page a given check flags — the WHOLE set, not a
	 * preview and not a sample.
	 *
	 * This is what "set all aside" acts on, so it has to agree with the count the
	 * owner read before clicking. It used to walk the 25-post sample, which meant
	 * a button under "Low reading ease · 12" could quietly park only the handful
	 * of those twelve that happened to be recently edited.
	 *
	 * ⭐ The comma-wrapping done by {@see pack_ids()} is what makes this LIKE safe:
	 * `,summary,` cannot match inside `,no_summary,`.
	 *
	 * @param array<int,string> $types Post types to consider.
	 * @param array<int,int>    $aside Post IDs already set aside.
	 * @param string            $flag  Check id.
	 * @return array<int,int>
	 */
	public static function posts_with_flag( array $types, array $aside, $flag ) {
		global $wpdb;

		$flag = (string) $flag;
		if ( empty( $types ) || '' === $flag || ! self::installed() ) {
			return array();
		}

		$table   = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$aside   = array_values( array_unique( array_filter( array_map( 'intval', $aside ) ) ) );
		$in      = $aside ? implode( ',', $aside ) : '0';

		$args   = $types;
		$args[] = '%,' . $wpdb->esc_like( $flag ) . ',%';

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; ids cast to int, everything else bound.
			"SELECT g.post_id
			FROM $table g INNER JOIN {$wpdb->posts} p ON p.ID = g.post_id
			WHERE p.post_status = 'publish' AND p.post_type IN ($holders)
				AND g.gradeable = 1 AND g.graded_at IS NOT NULL AND g.post_id NOT IN ($in)
				AND g.flag_ids LIKE %s
			ORDER BY g.stake DESC, g.post_id DESC",
			$args
		) ) );
	}

	/**
	 * The flagged check ids already stored for these posts.
	 *
	 * Lets a screen say what a page was flagged for without re-rendering it —
	 * the set-aside list used to re-analyze up to 25 of its rows for exactly
	 * this, and silently listed the rest without any flags at all.
	 *
	 * @param array<int,int> $post_ids Post IDs.
	 * @return array<int,array<int,string>> post_id => check ids.
	 */
	public static function flag_ids_for( array $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( ! $post_ids || ! self::installed() ) {
			return array();
		}

		$table = self::name();
		$in    = implode( ',', $post_ids );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB -- our own table; every id cast to int above.
			"SELECT post_id, flag_ids FROM $table WHERE post_id IN ($in) AND graded_at IS NOT NULL",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['post_id'] ] = self::unpack_ids( isset( $r['flag_ids'] ) ? $r['flag_ids'] : '' );
		}
		return $out;
	}

	/**
	 * The fixable bucket split by WHAT it is asking for: pages with content
	 * flags, and pages whose only problem is that they do not answer the search
	 * they are found for.
	 *
	 * Exclusive, and flags lead — the same rule the row badge uses, so a page
	 * with both is counted once, under the edits it can actually be given. The
	 * two add up to `counts()['fixable']`, which is the only reason they are
	 * safe to print side by side.
	 *
	 * This exists because the front door had no way to describe this list in the
	 * list's own terms. It described a 25-post sample instead, and so could
	 * report silence while the list showed thirty-six rows.
	 *
	 * @param array<int,string> $types Post types to consider.
	 * @param array<int,int>    $aside Post IDs the owner has set aside.
	 * @return array{flagged:int,unanswered:int}
	 */
	public static function fixable_split( array $types, array $aside ) {
		global $wpdb;

		$out = array( 'flagged' => 0, 'unanswered' => 0 );
		if ( empty( $types ) || ! self::installed() ) {
			return $out;
		}

		$table   = self::name();
		$holders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$aside   = array_values( array_unique( array_filter( array_map( 'intval', $aside ) ) ) );
		$in      = $aside ? implode( ',', $aside ) : '0';

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table; ids cast to int, everything else bound.
			"SELECT
				SUM(CASE WHEN g.flags > 0 THEN 1 ELSE 0 END) AS flagged,
				SUM(CASE WHEN g.flags = 0 THEN 1 ELSE 0 END) AS unanswered
			FROM $table g INNER JOIN {$wpdb->posts} p ON p.ID = g.post_id
			WHERE p.post_status = 'publish' AND p.post_type IN ($holders)
				AND g.needs_work = 1 AND g.post_id NOT IN ($in)",
			$types
		), ARRAY_A );

		$row = isset( $rows[0] ) ? $rows[0] : array();
		return array(
			'flagged'    => (int) ( isset( $row['flagged'] ) ? $row['flagged'] : 0 ),
			'unanswered' => (int) ( isset( $row['unanswered'] ) ? $row['unanswered'] : 0 ),
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

		if ( empty( $types ) || ! self::installed() ) {
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
