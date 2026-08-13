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
	const VERSION = '2';

	/**
	 * @var string Google's default surface: the combined "All" tab.
	 *
	 * Search Console splits its reporting by surface and defaults to this one.
	 * The Image and Video tabs are counted separately and arrive as their own
	 * rows, which is why every read states the type it wants instead of taking
	 * whatever the table holds — two surfaces summed is a number nobody reported.
	 */
	const TYPE_WEB = 'web';

	/**
	 * @var string Bing's only surface: everything, together.
	 *
	 * Bing's API has no image/video/news split anywhere in it (verified against
	 * the published method list, 2026-08-14). Storing its rows as 'web' would put
	 * a distinction in Bing's mouth that Bing never made, so they get their own
	 * honest name.
	 */
	const TYPE_ALL = 'all';

	/**
	 * The surface a source reports by default — what a caller means when it asks
	 * for "the numbers" without naming a surface.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return string
	 */
	public static function primary_type( $source ) {
		return 'bing' === sanitize_key( (string) $source ) ? self::TYPE_ALL : self::TYPE_WEB;
	}

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
		// search_type defaults to 'web', which is exactly what every row written
		// before this column existed was — so the migration needs no data pass.
		// staged marks a snapshot mid-write: rows land staged, and only replace
		// the live ones once the whole write has succeeded {@see replace()}.
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(10) NOT NULL DEFAULT 'bing',
			search_type varchar(10) NOT NULL DEFAULT 'web',
			staged tinyint(1) NOT NULL DEFAULT 0,
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
			KEY page_id (page_id),
			KEY source_type_page (source, search_type, page_id, staged)
		) $collate;" );

		foreach ( array( 'source', 'search_type', 'staged', 'query_text', 'page_url', 'page_id', 'clicks', 'impressions', 'position', 'range_start', 'range_end' ) as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from our own prefix helper.
			if ( null === $found ) {
				return;
			}
		}

		// ⚠️ Rows written before the surface column existed take its default,
		// 'web'. That is right for Google and WRONG for Bing, whose reads look
		// for 'all' — so without this line every Bing number on every screen
		// would disappear the moment the plugin updated, and stay gone until the
		// next daily poll rewrote them. An upgrade must never cost an owner their
		// data, not even for a day.
		//
		// Idempotent, and indexed by source, so running it on each version bump
		// costs nothing.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"UPDATE $table SET search_type = %s WHERE source = %s AND search_type = %s",
			self::TYPE_ALL,
			'bing',
			self::TYPE_WEB
		) );

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Replace one source's snapshot, for one surface, with a fresh poll's rows.
	 *
	 * The engines re-report their whole trailing window every time, so a poll
	 * supersedes its snapshot rather than adding to it. An EMPTY rows array is a
	 * no-op, never a wipe — a failed or empty API answer must not erase the last
	 * good snapshot.
	 *
	 * ⚠️ THREE THINGS THIS METHOD USED TO GET WRONG, all of them silent:
	 *
	 * 1. It DELETED the live rows before writing the new ones, so a write that
	 *    died halfway left the site with less than it had before the poll. Rows
	 *    now land marked `staged` and only take over once the whole write has
	 *    succeeded; a failure throws the staged rows away and the previous
	 *    snapshot stands untouched.
	 * 2. A failed chunk returned early with a positive count, and the final flush
	 *    had its result thrown away entirely — so a run that stored 8,000 of
	 *    40,000 rows reported itself as a clean poll and every screen read the
	 *    survivors as the whole truth. The return now carries `ok`, which is
	 *    false on ANY shortfall, and callers must check that rather than compare
	 *    against zero.
	 * 3. The MAX_ROWS cap dropped the tail with nothing said. Search Console
	 *    hands rows back CLICKS-DESCENDING, so what fell off was the quiet long
	 *    tail — exactly the pages this plugin exists to find. The return now says
	 *    how many were dropped so a screen can admit it.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @param array  $rows   Rows: { query, page_url, page_id, clicks, impressions, position, range_start, range_end }.
	 * @param string      $type     The surface these rows describe; defaults to the source's own.
	 * @param string|null $page_url Limit the swap to ONE page's rows; null replaces the whole surface.
	 *                              Bing is asked about one page at a time, so its poll must be able to
	 *                              refresh the page it just asked about WITHOUT discarding the pages it
	 *                              did not reach this run — the behaviour that kept its coverage stuck
	 *                              on the same handful of pages for ever. '' scopes to the site-wide
	 *                              rows, which carry no page of their own.
	 * @return array{written:int,expected:int,dropped:int,ok:bool}
	 */
	public static function replace( $source, array $rows, $type = '', $page_url = null ) {
		global $wpdb;

		$source = sanitize_key( (string) $source );
		$type   = '' === (string) $type ? self::primary_type( $source ) : sanitize_key( (string) $type );
		$table  = self::name();

		$expected = count( $rows );
		$dropped  = $expected > self::MAX_ROWS ? $expected - self::MAX_ROWS : 0;
		$result   = array(
			'written'  => 0,
			'expected' => $expected,
			'dropped'  => $dropped,
			'ok'       => true,
		);
		if ( 0 === $expected ) {
			return $result;
		}

		// A crashed run can leave staged rows behind. They belong to nobody, and
		// this poll is about to write its own.
		self::clear_staged( $source, $type, $page_url );

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
				"INSERT INTO $table (source,search_type,staged,query_text,page_url,page_id,clicks,impressions,position,range_start,range_end) VALUES " . implode( ',', $values )
			);
			$values = array();
			if ( false === $done ) {
				return false;
			}
			$written += (int) $done;
			return true;
		};

		$failed = false;
		foreach ( $rows as $row ) {
			if ( $count >= self::MAX_ROWS ) {
				break;
			}
			$count++;
			$values[] = $wpdb->prepare(
				'(%s,%s,1,%s,%s,%d,%d,%d,%f,%s,%s)',
				$source,
				$type,
				mb_substr( (string) ( isset( $row['query'] ) ? $row['query'] : '' ), 0, 191 ),
				// ⚠️ When a page scope is given, it WINS over whatever the row
				// carries. The swap finds rows by page, so a row whose page did
				// not match the scope would be written and then never promoted —
				// silently losing the whole answer. Rather than trust every caller
				// to keep the two in step, the scope simply decides.
				null === $page_url
					? (string) ( isset( $row['page_url'] ) ? $row['page_url'] : '' )
					: (string) $page_url,
				(int) ( isset( $row['page_id'] ) ? $row['page_id'] : 0 ),
				(int) ( isset( $row['clicks'] ) ? $row['clicks'] : 0 ),
				(int) ( isset( $row['impressions'] ) ? $row['impressions'] : 0 ),
				(float) ( isset( $row['position'] ) ? $row['position'] : 0 ),
				(string) ( isset( $row['range_start'] ) ? $row['range_start'] : gmdate( 'Y-m-d', time() - 56 * DAY_IN_SECONDS ) ),
				(string) ( isset( $row['range_end'] ) ? $row['range_end'] : gmdate( 'Y-m-d' ) )
			);

			if ( count( $values ) >= self::INSERT_CHUNK && ! $flush() ) {
				$failed = true;
				break;
			}
		}
		if ( ! $failed && ! $flush() ) {
			$failed = true;
		}

		$result['written'] = $written;
		$result['ok']      = ! $failed && $written === ( $expected - $dropped );

		if ( ! $result['ok'] ) {
			// Nothing half-written ever becomes the truth. The staged rows go, the
			// previous snapshot stays, and the caller reports a failed poll.
			self::clear_staged( $source, $type, $page_url );
			$result['written'] = 0;
			return $result;
		}

		if ( ! self::promote_staged( $source, $type, $page_url ) ) {
			self::clear_staged( $source, $type, $page_url );
			$result['written'] = 0;
			$result['ok']      = false;
		}

		return $result;
	}

	/**
	 * Replace the rows for ONE page, leaving every other page's rows alone.
	 *
	 * What the whole-surface replace above cannot do for an engine that answers
	 * one page per request. Bing's poll asks about a few pages each day; without
	 * this, writing those few would erase every page it did not get to, so its
	 * coverage could never grow past one day's batch — which is exactly the state
	 * this arc exists to fix.
	 *
	 * @param string $source   'bing' or 'google'.
	 * @param string $page_url The page these rows belong to; '' for the site-wide set.
	 * @param array  $rows     Rows for that page.
	 * @param string $type     Surface; defaults to the source's own.
	 * @return array{written:int,expected:int,dropped:int,ok:bool}
	 */
	public static function replace_page( $source, $page_url, array $rows, $type = '' ) {
		return self::replace( $source, $rows, $type, (string) $page_url );
	}

	/**
	 * Remove one page's rows outright.
	 *
	 * ⚠️ The ONLY caller may be one that has just had a clear answer from the
	 * engine that this page has no searches. {@see replace()} deliberately treats
	 * an empty list as "nothing to write" rather than "erase everything", because
	 * an empty API answer is usually a blip. This is the opposite case, and it
	 * has to exist separately so the two can never be confused: once the ask
	 * ledger records "asked, nothing came back", leaving yesterday's rows behind
	 * would put the table and the ledger in direct contradiction.
	 *
	 * @param string $source   'bing' or 'google'.
	 * @param string $page_url The page.
	 * @param string $type     Surface; defaults to the source's own.
	 * @return void
	 */
	public static function clear_page( $source, $page_url, $type = '' ) {
		global $wpdb;
		$source = sanitize_key( (string) $source );
		$type   = '' === (string) $type ? self::primary_type( $source ) : sanitize_key( (string) $type );
		$table  = self::name();

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"DELETE FROM $table WHERE source = %s AND search_type = %s AND page_url = %s",
			$source,
			$type,
			(string) $page_url
		) );
	}

	/**
	 * Throw away a half-written snapshot, leaving the live one alone.
	 *
	 * @param string      $source   Source key.
	 * @param string      $type     Surface key.
	 * @param string|null $page_url Scope to one page, or null for the whole surface.
	 * @return void
	 */
	private static function clear_staged( $source, $type, $page_url = null ) {
		global $wpdb;
		$table = self::name();

		if ( null === $page_url ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
				"DELETE FROM $table WHERE source = %s AND search_type = %s AND staged = 1",
				$source,
				$type
			) );
			return;
		}

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"DELETE FROM $table WHERE source = %s AND search_type = %s AND staged = 1 AND page_url = %s",
			$source,
			$type,
			(string) $page_url
		) );
	}

	/**
	 * Swap a completed staged snapshot in for the live one.
	 *
	 * Two statements, deliberately WITHOUT wrapping them in a transaction.
	 * That leaves a gap of a millisecond or two, between the old rows going and
	 * the new ones becoming live, in which a reader would see this source as
	 * having no data. The reasoning for accepting it, since it is a real hole:
	 *
	 * - What this replaced deleted the live rows and then wrote for as long as
	 *   the write took — seconds, on a large site — and left NOTHING at all when
	 *   the write failed. A two-statement gap once a day is not in the same
	 *   category as that, and the worst a reader sees is one page load saying
	 *   "still collecting" instead of a number.
	 * - `START TRANSACTION` would commit any transaction already open around it,
	 *   and WordPress has no transaction discipline for a plugin to join. It also
	 *   silently breaks the test suite's own isolation, which is how a fix like
	 *   this quietly costs more than it buys.
	 * - Closing the gap properly needs a generation pointer read by every query.
	 *   That is a subquery on the hottest read in the plugin, plus a second place
	 *   for the truth to live and disagree with itself. Not worth a millisecond.
	 *
	 * @param string      $source   Source key.
	 * @param string      $type     Surface key.
	 * @param string|null $page_url Scope to one page, or null for the whole surface.
	 * @return bool
	 */
	private static function promote_staged( $source, $type, $page_url = null ) {
		global $wpdb;
		$table = self::name();

		if ( null === $page_url ) {
			$cleared = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
				"DELETE FROM $table WHERE source = %s AND search_type = %s AND staged = 0",
				$source,
				$type
			) );
			if ( false === $cleared ) {
				// The old rows are untouched, so the staged set is simply abandoned
				// by the caller and the previous snapshot goes on being the truth.
				return false;
			}
			return false !== $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
				"UPDATE $table SET staged = 0 WHERE source = %s AND search_type = %s AND staged = 1",
				$source,
				$type
			) );
		}

		$cleared = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"DELETE FROM $table WHERE source = %s AND search_type = %s AND staged = 0 AND page_url = %s",
			$source,
			$type,
			(string) $page_url
		) );
		if ( false === $cleared ) {
			return false;
		}
		return false !== $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"UPDATE $table SET staged = 0 WHERE source = %s AND search_type = %s AND staged = 1 AND page_url = %s",
			$source,
			$type,
			(string) $page_url
		) );
	}

	/**
	 * One source's window totals, as one aggregate.
	 *
	 * Deliberately SQL rather than summing {@see snapshot()}: a caller that only
	 * wants "how many clicks" must not pull the whole window into PHP to find
	 * out. That mattered little at 5,000 rows and matters a great deal at
	 * 25,000 — and the audience card asks this on every dashboard poll.
	 *
	 * ⚠️ Scoped to ONE surface, always. Summing the "All" tab and the Image tab
	 * together produces a figure neither Google nor anybody else ever reported.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @param string $type   Surface; defaults to the source's own.
	 * @return array{clicks:int,impressions:int,rows:int,start:string,end:string}
	 */
	public static function totals( $source, $type = '' ) {
		global $wpdb;
		$table  = self::name();
		$source = sanitize_key( (string) $source );
		$type   = '' === (string) $type ? self::primary_type( $source ) : sanitize_key( (string) $type );
		$row    = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(impressions),0) AS impressions, COUNT(*) AS rows_held,
				MIN(range_start) AS range_start, MAX(range_end) AS range_end
			FROM $table WHERE source = %s AND search_type = %s AND staged = 0",
			$source,
			$type
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
	 * One source's full snapshot, for one surface.
	 *
	 * ⚠️ The surface is never optional in effect — a caller that omits it gets
	 * the source's own default. Two surfaces in one list would double-count
	 * every page that appears in both, and no caller downstream can tell them
	 * apart once they are mixed.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @param string $type   Surface; defaults to the source's own.
	 * @return array<int,array>
	 */
	public static function snapshot( $source, $type = '' ) {
		global $wpdb;
		$table  = self::name();
		$source = sanitize_key( (string) $source );
		$type   = '' === (string) $type ? self::primary_type( $source ) : sanitize_key( (string) $type );
		$rows   = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT query_text, page_url, page_id, clicks, impressions, position, range_start, range_end
			FROM $table WHERE source = %s AND search_type = %s AND staged = 0 ORDER BY impressions DESC",
			$source,
			$type
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
	 * @param string $type   Surface; defaults to the source's own.
	 * @return bool
	 */
	public static function has_rows( $source, $type = '' ) {
		global $wpdb;
		$table  = self::name();
		$source = sanitize_key( (string) $source );
		$type   = '' === (string) $type ? self::primary_type( $source ) : sanitize_key( (string) $type );
		return (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			"SELECT 1 FROM $table WHERE source = %s AND search_type = %s AND staged = 0 LIMIT 1",
			$source,
			$type
		) );
	}
}
