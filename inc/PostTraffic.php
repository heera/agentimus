<?php
/**
 * PostTraffic — one post's traffic, read from the three places it already lands.
 *
 * ⭐ ONE READER, THREE SCREENS. The Your Content list, the WordPress posts list
 * and the editor's Traffic tab all ask this class, so the same post can never
 * show two different numbers in two places.
 *
 * - AI visits: readers an AI assistant sent ({@see Activity\Referrals}).
 * - Bot reads: machines that fetched the page or its Markdown copy
 *   ({@see Activity\Recorder}). Refused requests and PROVEN fakes are not
 *   reads; a bot that could not be checked still counts, and says so.
 * - Search: what the connected engine reported for the page ({@see Search\Table}).
 *
 * The first two count on our own clock (the report window); search is the
 * engine's own date range, and every payload names both rather than pretend
 * they are one span.
 *
 * ⚠️ Rows logged before the `post_id` columns existed carry 0. They are matched
 * to their post by path, a few at a time {@see backfill()}, and until that is
 * done every payload says `matching: true` — an older visit not yet counted is
 * an absence that names itself, never a silent zero.
 *
 * @package Agentimus
 */

namespace Agentimus;

use Agentimus\Activity\Referrals;
use Agentimus\Activity\Repository;
use Agentimus\Activity\Table as HitTable;
use Agentimus\Search\Report;
use Agentimus\Search\Table as SearchTable;

defined( 'ABSPATH' ) || exit;

final class PostTraffic {

	/** Progress of the one-time path → post match, per table: a path cursor, or 'done'. */
	const BACKFILL_OPTION = 'agentimus_post_traffic_backfill';

	/** Distinct paths matched per backfill step. url_to_postid() is a few queries each. */
	const BACKFILL_BATCH = 50;

	/** The request-log surfaces that are a read of ONE post. */
	const READ_ENDPOINTS = array( 'page', 'markdown' );

	/** What the lists can be ordered by. */
	const SORTS = array( 'ai', 'bots', 'clicks' );

	/** Bots named in one post's breakdown; the rest are counted, not listed. */
	const TOP_BOTS = 8;

	/** Searches listed for one post. */
	const TOP_QUERIES = 5;

	/**
	 * Clients that are not bots reading the page: HTTP libraries and command-line
	 * tools, and WordPress sites (this one's own loopback requests among them).
	 * His call, 2026-10-19. Counted apart as `notReaders`, never silently dropped.
	 */
	const NOT_READERS = array( 'Script/tool', 'WordPress' );

	/**
	 * The post a landing path belongs to, or 0.
	 *
	 * @param string $path Path, optionally with the query a plain-permalink site keeps ("/?p=12").
	 * @return int
	 */
	public static function post_for_path( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return 0;
		}
		if ( '/' === $path ) {
			// The home page is a post only when a static page is set as it.
			return 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		}
		$id = (int) url_to_postid( home_url( $path ) );
		if ( ! $id && false === strpos( $path, '?' ) ) {
			$id = (int) url_to_postid( home_url( trailingslashit( $path ) ) );
		}
		return $id;
	}

	/**
	 * Days the AI-visit and bot-read counts cover — the same window every other
	 * traffic screen reports over.
	 *
	 * @return int
	 */
	public static function window() {
		return max( 1, (int) Repository::report_days() );
	}

	/**
	 * First day (Y-m-d, GMT) inside the window. Inclusive of today, like the referral card.
	 *
	 * @return string
	 */
	private static function since_day() {
		return gmdate( 'Y-m-d', time() - ( self::window() - 1 ) * DAY_IN_SECONDS );
	}

	/**
	 * One post, in full: totals and who they came from.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function for_post( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( ! is_object( $wpdb ) ) {
			return self::empty_payload( $post_id ); // No database to ask — nothing measured, said as such.
		}
		self::backfill();

		$since = self::since_day();
		$refs  = Referrals::name();
		$hits  = HitTable::name();
		$ends  = implode( ',', array_fill( 0, count( self::READ_ENDPOINTS ), '%s' ) );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own prefix-derived tables; every value is bound.
		$sources = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT source AS label, SUM(hits) AS hits FROM $refs WHERE post_id = %d AND day >= %s GROUP BY source ORDER BY hits DESC",
			$post_id,
			$since
		), ARRAY_A );
		$bots    = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT agent AS label, COUNT(*) AS hits, MAX(verdict = 1) AS verified, MAX(hit_at) AS last_at FROM $hits
			WHERE post_id = %d AND hit_at >= %s AND refused = 0 AND verdict <> 2 AND " . self::readers_sql() . " AND endpoint IN ($ends)
			GROUP BY agent ORDER BY hits DESC",
			array_merge( array( $post_id, $since . ' 00:00:00' ), self::READ_ENDPOINTS )
		), ARRAY_A );
		// What the count leaves out, so the Request Log narrowed to this page (which
		// lists these too, with their status) never looks like it disagrees.
		$left_out = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $hits WHERE post_id = %d AND hit_at >= %s AND ( refused = 1 OR verdict = 2 ) AND endpoint IN ($ends)",
			array_merge( array( $post_id, $since . ' 00:00:00' ), self::READ_ENDPOINTS )
		) );
		$not_readers = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $hits WHERE post_id = %d AND hit_at >= %s AND refused = 0 AND verdict <> 2 AND NOT " . self::readers_sql() . " AND endpoint IN ($ends)",
			array_merge( array( $post_id, $since . ' 00:00:00' ), self::READ_ENDPOINTS )
		) );
		// The two day-by-day series behind the chart: one row per day that had any.
		$ai_days  = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT day AS d, SUM(hits) AS n FROM $refs WHERE post_id = %d AND day >= %s GROUP BY day",
			$post_id,
			$since
		), ARRAY_A );
		$bot_days = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(hit_at) AS d, COUNT(*) AS n FROM $hits
			WHERE post_id = %d AND hit_at >= %s AND refused = 0 AND verdict <> 2 AND " . self::readers_sql() . " AND endpoint IN ($ends)
			GROUP BY DATE(hit_at)",
			array_merge( array( $post_id, $since . ' 00:00:00' ), self::READ_ENDPOINTS )
		), ARRAY_A );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$ai_total  = 0;
		$by_source = array();
		foreach ( $sources as $r ) {
			$ai_total   += (int) $r['hits'];
			$by_source[] = array( 'label' => (string) $r['label'], 'hits' => (int) $r['hits'] );
		}
		$bot_total = 0;
		$by_bot    = array();
		foreach ( $bots as $r ) {
			$bot_total += (int) $r['hits'];
			$by_bot[]   = array(
				'label'    => (string) $r['label'],
				'hits'     => (int) $r['hits'],
				'verified' => (bool) (int) $r['verified'],
				'last'     => (string) $r['last_at'], // GMT, "Y-m-d H:i:s".
			);
		}

		return array(
			'postId'    => $post_id,
			'window'    => self::window(),
			'recording' => (bool) ( new Settings() )->enabled( 'enable_activity' ),
			// ⚠️ Off, bot reads count only the Markdown copy — the page itself is not logged.
			'pageViews' => Activity\PageViews::enabled(),
			'matching'  => ! self::backfill_done(),
			'ai'        => array( 'total' => $ai_total, 'bySource' => $by_source ),
			'bots'      => array(
				'total'  => $bot_total,
				'byBot'  => array_slice( $by_bot, 0, self::TOP_BOTS ),
				// ⚠️ How many bots the list above leaves out, so it never reads as all of them.
				'more'   => max( 0, count( $by_bot ) - self::TOP_BOTS ),
				// Requests for this page from proven fakes, or refused at the door: not reads, not counted.
				'leftOut' => $left_out,
				// Requests from scripts and WordPress sites: real, but not a bot reading the page.
				'notReaders' => $not_readers,
			),
			// ⭐ EVERY day of the window, quiet ones included — a chart that skips the
			// empty days draws two busy days a month apart as neighbours.
			'daily'     => self::days( $ai_days, $bot_days ),
			'search'    => self::with_queries( self::search_for( array( $post_id ) )[ $post_id ], $post_id ),
		);
	}

	/**
	 * The window as one row per day, oldest first, zeros where nothing came.
	 *
	 * @param array $ai_days  Rows of { d, n }.
	 * @param array $bot_days Rows of { d, n }.
	 * @return array<int,array{date:string,ai:int,bots:int}>
	 */
	private static function days( array $ai_days, array $bot_days ) {
		$ai   = array();
		$bots = array();
		foreach ( $ai_days as $r ) {
			$ai[ (string) $r['d'] ] = (int) $r['n'];
		}
		foreach ( $bot_days as $r ) {
			$bots[ (string) $r['d'] ] = (int) $r['n'];
		}
		$out   = array();
		$start = strtotime( self::since_day() . ' 00:00:00 UTC' );
		for ( $i = 0; $i < self::window(); $i++ ) {
			$day   = gmdate( 'Y-m-d', $start + $i * DAY_IN_SECONDS );
			$out[] = array(
				'date' => $day,
				'ai'   => isset( $ai[ $day ] ) ? $ai[ $day ] : 0,
				'bots' => isset( $bots[ $day ] ) ? $bots[ $day ] : 0,
			);
		}
		return $out;
	}

	/**
	 * The searches that brought people to this page, busiest first.
	 *
	 * @param array|null $search  {@see search_for()} for the post.
	 * @param int        $post_id Post ID.
	 * @return array|null The same, with `queries` added.
	 */
	private static function with_queries( $search, $post_id ) {
		if ( null === $search ) {
			return null;
		}
		global $wpdb;
		$source = (string) Report::source_state()['source'];
		$table  = SearchTable::name();
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; values bound.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT query_text, clicks, impressions, position FROM $table
			WHERE source = %s AND search_type = %s AND staged = 0 AND page_id = %d
			ORDER BY clicks DESC, impressions DESC, query_text ASC LIMIT %d",
			$source,
			SearchTable::primary_type( $source ),
			(int) $post_id,
			self::TOP_QUERIES
		), ARRAY_A );
		$search['queries'] = array();
		foreach ( $rows as $r ) {
			$search['queries'][] = array(
				'query'       => (string) $r['query_text'],
				'clicks'      => (int) $r['clicks'],
				'impressions' => (int) $r['impressions'],
				'position'    => round( (float) $r['position'], 1 ),
			);
		}
		return $search;
	}

	/**
	 * The SQL that keeps {@see NOT_READERS} out of a bot-read count.
	 *
	 * @return string
	 */
	private static function readers_sql() {
		return "agent NOT IN ('" . implode( "','", array_map( 'esc_sql', self::NOT_READERS ) ) . "')";
	}

	/**
	 * A post's payload with nothing counted, flagged `matching` so it never reads as a measured zero.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function empty_payload( $post_id ) {
		return array(
			'postId'    => (int) $post_id,
			'window'    => self::window(),
			'recording' => false,
			'pageViews' => false,
			'matching'  => true,
			'ai'        => array( 'total' => 0, 'bySource' => array() ),
			'bots'      => array( 'total' => 0, 'byBot' => array(), 'more' => 0, 'leftOut' => 0, 'notReaders' => 0 ),
			'daily'     => array(),
			'search'    => null,
		);
	}

	/**
	 * The three headline numbers for many posts at once — one grouped query per
	 * source, never one per row.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int,array{ai:int,bots:int,clicks:int|null}>
	 */
	public static function totals_for( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array( 'ai' => 0, 'bots' => 0, 'clicks' => null );
		}
		if ( ! $ids || ! is_object( $wpdb ) ) {
			return $out;
		}
		self::backfill();

		$in    = implode( ',', $ids ); // Ints, cast above.
		$since = self::since_day();
		$refs  = Referrals::name();
		$hits  = HitTable::name();
		$ends  = implode( ',', array_fill( 0, count( self::READ_ENDPOINTS ), '%s' ) );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own prefix-derived tables; ids cast to int; every other value is bound.
		$ai   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, SUM(hits) AS n FROM $refs WHERE post_id IN ($in) AND day >= %s GROUP BY post_id", $since ), ARRAY_A );
		$bots = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, COUNT(*) AS n FROM $hits WHERE post_id IN ($in) AND hit_at >= %s AND refused = 0 AND verdict <> 2 AND " . self::readers_sql() . " AND endpoint IN ($ends) GROUP BY post_id",
			array_merge( array( $since . ' 00:00:00' ), self::READ_ENDPOINTS )
		), ARRAY_A );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $ai as $r ) {
			$out[ (int) $r['post_id'] ]['ai'] = (int) $r['n'];
		}
		foreach ( $bots as $r ) {
			$out[ (int) $r['post_id'] ]['bots'] = (int) $r['n'];
		}
		foreach ( self::search_for( $ids ) as $id => $search ) {
			$out[ $id ]['clicks'] = null === $search ? null : (int) $search['clicks'];
		}
		return $out;
	}

	/**
	 * What the connected engine reported for each post, or null for a post it
	 * reported nothing for (or when no engine is connected).
	 *
	 * ⚠️ Summed from page × search rows. The engines leave rare and private
	 * searches out of that report, so these can sit a little under the engine's
	 * own page total — `partial` says so, for both engines.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int,array{engine:string,clicks:int,impressions:int,position:float,start:string,end:string,partial:bool}|null>
	 */
	private static function search_for( array $ids ) {
		global $wpdb;
		$out = array_fill_keys( array_map( 'intval', $ids ), null );
		$source = (string) Report::source_state()['source'];
		if ( '' === $source || ! $out ) {
			return $out;
		}
		$table = SearchTable::name();
		$in    = implode( ',', array_keys( $out ) ); // Ints, cast above.
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; ids cast to int; the rest bound.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT page_id, SUM(clicks) AS clicks, SUM(impressions) AS impressions,
				SUM(position * impressions) AS weighted, MIN(range_start) AS range_start, MAX(range_end) AS range_end
			FROM $table WHERE source = %s AND search_type = %s AND staged = 0 AND page_id IN ($in) GROUP BY page_id",
			$source,
			SearchTable::primary_type( $source )
		), ARRAY_A );

		$engine = 'google' === $source ? 'Google' : 'Bing';
		foreach ( $rows as $r ) {
			$impr                        = (int) $r['impressions'];
			$out[ (int) $r['page_id'] ] = array(
				'engine'      => $engine,
				'clicks'      => (int) $r['clicks'],
				'impressions' => $impr,
				// Weighted by impressions: a search seen once must not pull the average as hard as one seen a thousand times.
				'position'    => $impr > 0 ? round( (float) $r['weighted'] / $impr, 1 ) : 0.0,
				'start'       => (string) $r['range_start'],
				'end'         => (string) $r['range_end'],
				'partial'     => true,
			);
		}
		return $out;
	}

	/**
	 * A LEFT JOIN that hands a list query one traffic number per post, for
	 * ordering by it. Aliased `pt`, with the number in `pt.n` (NULL = none).
	 *
	 * @param string $sort   One of {@see SORTS}.
	 * @param string $on_col The outer query's post-ID column, e.g. "g.post_id".
	 * @return string SQL, values already bound; '' for an unknown sort.
	 */
	public static function join_sql( $sort, $on_col ) {
		global $wpdb;
		$since = self::since_day();
		switch ( $sort ) {
			case 'ai':
				$inner = $wpdb->prepare( 'SELECT post_id, SUM(hits) AS n FROM ' . Referrals::name() . ' WHERE post_id > 0 AND day >= %s GROUP BY post_id', $since ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- our own table name.
				break;
			case 'bots':
				$ends  = implode( ',', array_fill( 0, count( self::READ_ENDPOINTS ), '%s' ) );
				$inner = $wpdb->prepare( 'SELECT post_id, COUNT(*) AS n FROM ' . HitTable::name() . " WHERE post_id > 0 AND hit_at >= %s AND refused = 0 AND verdict <> 2 AND " . self::readers_sql() . " AND endpoint IN ($ends) GROUP BY post_id", array_merge( array( $since . ' 00:00:00' ), self::READ_ENDPOINTS ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- our own table name; placeholders built from a constant.
				break;
			case 'clicks':
				$source = (string) Report::source_state()['source'];
				if ( '' === $source ) {
					$inner = 'SELECT 0 AS post_id, 0 AS n FROM DUAL WHERE 1 = 0';
					break;
				}
				$inner = $wpdb->prepare( 'SELECT page_id AS post_id, SUM(clicks) AS n FROM ' . SearchTable::name() . ' WHERE source = %s AND search_type = %s AND staged = 0 AND page_id > 0 GROUP BY page_id', $source, SearchTable::primary_type( $source ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- our own table name.
				break;
			default:
				return '';
		}
		return " LEFT JOIN ($inner) pt ON pt.post_id = $on_col";
	}

	/** Bots named per page in the Pages view; the rest are counted. */
	const PAGE_BOTS = 3;

	/** Pages per screen in the Pages view, and the most one request may ask for. */
	const PAGES_PER = 25;
	const PAGES_MAX = 100;

	/**
	 * The Request Log's Pages view: every post or page with traffic in a date
	 * range, busiest first — the same reads {@see for_post()} counts, so a page's
	 * line here, its Your Content row and its posts-list cell agree.
	 *
	 * ⚠️ Reads of addresses that are not one post (archives, tag pages, the blog
	 * index, a path that names nothing) have no page to sit under. They are
	 * counted in `unattributed` rather than silently missing from the sum.
	 *
	 * @param array $args from, to (Y-m-d, inclusive; default the report window), offset, per_page.
	 * @return array
	 */
	public static function pages( array $args = array() ) {
		global $wpdb;
		self::backfill();

		$from = ! empty( $args['from'] ) ? (string) $args['from'] : self::since_day();
		$to   = ! empty( $args['to'] ) ? (string) $args['to'] : gmdate( 'Y-m-d' );
		$per  = isset( $args['per_page'] ) && (int) $args['per_page'] > 0 ? min( self::PAGES_MAX, (int) $args['per_page'] ) : self::PAGES_PER;
		$off  = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$refs   = Referrals::name();
		$hits   = HitTable::name();
		$ends   = implode( ',', array_fill( 0, count( self::READ_ENDPOINTS ), '%s' ) );
		$reads  = 'refused = 0 AND verdict <> 2 AND ' . self::readers_sql() . " AND endpoint IN ($ends)";
		$range  = array_merge( array( $from . ' 00:00:00', gmdate( 'Y-m-d 00:00:00', strtotime( $to . ' UTC' ) + DAY_IN_SECONDS ) ), self::READ_ENDPOINTS );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own prefix-derived tables; $reads is built from literals; every value is bound.
		$bots = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, COUNT(*) AS n, MAX(hit_at) AS last_at FROM $hits WHERE post_id > 0 AND hit_at >= %s AND hit_at < %s AND $reads GROUP BY post_id",
			$range
		), ARRAY_A );
		$ai   = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, SUM(hits) AS n FROM $refs WHERE post_id > 0 AND day >= %s AND day <= %s GROUP BY post_id",
			$from,
			$to
		), ARRAY_A );
		$unattributed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $hits WHERE post_id = 0 AND hit_at >= %s AND hit_at < %s AND $reads",
			$range
		) );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Bounded by the number of posts that had any traffic, never by the log's size.
		$all = array();
		foreach ( $bots as $r ) {
			$all[ (int) $r['post_id'] ] = array( 'bots' => (int) $r['n'], 'ai' => 0, 'last' => (string) $r['last_at'] );
		}
		foreach ( $ai as $r ) {
			$id = (int) $r['post_id'];
			if ( ! isset( $all[ $id ] ) ) {
				$all[ $id ] = array( 'bots' => 0, 'ai' => 0, 'last' => '' );
			}
			$all[ $id ]['ai'] = (int) $r['n'];
		}
		uksort(
			$all,
			static function ( $a, $b ) use ( $all ) {
				// Busiest first; ties broken the same way every time, newest post first.
				return array( $all[ $b ]['bots'], $all[ $b ]['ai'], $b ) <=> array( $all[ $a ]['bots'], $all[ $a ]['ai'], $a );
			}
		);

		$slice = array_slice( $all, $off, $per, true );
		$named = self::top_bots( array_keys( $slice ), $range, $reads );
		$rows  = array();
		foreach ( $slice as $id => $t ) {
			$post   = get_post( $id );
			$rows[] = array(
				'postId' => (int) $id,
				'title'  => $post ? Worklist::title_of( $post ) : '',
				'type'   => $post ? (string) $post->post_type : '',
				'url'    => $post ? (string) get_permalink( $post ) : '',
				'bots'   => $t['bots'],
				'ai'     => $t['ai'],
				'readBy' => isset( $named[ $id ] ) ? $named[ $id ]['top'] : array(),
				'botKinds' => isset( $named[ $id ] ) ? $named[ $id ]['kinds'] : 0,
				// ISO 8601, like every time the Request Log hands its screen.
				'last'   => '' === $t['last'] ? '' : gmdate( 'c', strtotime( $t['last'] . ' UTC' ) ),
			);
		}

		return array(
			'group'        => 'pages',
			'pages'        => $rows,
			'pageTotal'    => count( $all ),
			'from'         => $from,
			'to'           => $to,
			'unattributed' => $unattributed,
			'matching'     => ! self::backfill_done(),
		);
	}

	/**
	 * The busiest few bots on each of these posts, and how many there were.
	 *
	 * @param int[]  $ids   Post IDs.
	 * @param array  $range Bound values for the range and endpoints.
	 * @param string $reads The reads filter.
	 * @return array<int,array{top:array,kinds:int}>
	 */
	private static function top_bots( array $ids, array $range, $reads ) {
		global $wpdb;
		$ids = array_map( 'intval', $ids );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$hits = HitTable::name();
		// phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table; ids cast; $reads is literals; values bound.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, agent, COUNT(*) AS n FROM $hits WHERE post_id IN ($in) AND hit_at >= %s AND hit_at < %s AND $reads GROUP BY post_id, agent ORDER BY n DESC, agent ASC",
			$range
		), ARRAY_A );
		$out = array();
		foreach ( $rows as $r ) {
			$id = (int) $r['post_id'];
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array( 'top' => array(), 'kinds' => 0 );
			}
			++$out[ $id ]['kinds'];
			if ( count( $out[ $id ]['top'] ) < self::PAGE_BOTS ) {
				$out[ $id ]['top'][] = array( 'label' => (string) $r['agent'], 'hits' => (int) $r['n'] );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 *  Matching older rows to their posts
	 * ---------------------------------------------------------------------- */

	/**
	 * Whether every row logged before the post columns existed has been matched.
	 *
	 * @return bool
	 */
	public static function backfill_done() {
		$state = (array) get_option( self::BACKFILL_OPTION, array() );
		return isset( $state['hits'], $state['referrals'] ) && 'done' === $state['hits'] && 'done' === $state['referrals'];
	}

	/**
	 * Match one batch of older rows to their posts, by path. Walks each table's
	 * distinct paths in order behind a cursor, so a path that names no post is
	 * asked about once and never again. Cheap once finished: one option read.
	 *
	 * @param int $batch Distinct paths per table this step.
	 * @return bool Whether everything is now matched.
	 */
	public static function backfill( $batch = self::BACKFILL_BATCH ) {
		if ( self::backfill_done() ) {
			return true;
		}
		global $wpdb;
		$state = (array) get_option( self::BACKFILL_OPTION, array() );
		$batch = max( 1, (int) $batch );

		$tables = array(
			'hits'      => array( HitTable::name(), " AND endpoint = 'page'" ),
			'referrals' => array( Referrals::name(), '' ),
		);
		foreach ( $tables as $key => $spec ) {
			if ( isset( $state[ $key ] ) && 'done' === $state[ $key ] ) {
				continue;
			}
			list( $table, $extra ) = $spec;
			$cursor = isset( $state[ $key ] ) ? (string) $state[ $key ] : '';
			// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own prefix-derived tables; $extra is a literal; values bound.
			$paths = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT path FROM $table WHERE post_id = 0 AND path > %s$extra ORDER BY path LIMIT %d",
				$cursor,
				$batch
			) );
			if ( '' !== (string) $wpdb->last_error ) {
				// ⛔ A failed read is not an empty one: marking this 'done' would leave
				// every older row unmatched for good. Try again on the next step.
				continue;
			}
			foreach ( $paths as $path ) {
				$id = self::post_for_path( (string) $path );
				if ( $id > 0 ) {
					$wpdb->query( $wpdb->prepare( "UPDATE $table SET post_id = %d WHERE post_id = 0 AND path = %s$extra", $id, (string) $path ) );
				}
			}
			// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$state[ $key ] = count( $paths ) < $batch ? 'done' : (string) end( $paths );
		}
		update_option( self::BACKFILL_OPTION, $state, false );
		return self::backfill_done();
	}
}
