<?php
/**
 * Repository — the read/maintenance side of the activity log: dashboard stats,
 * retention pruning and clearing. All timestamps are stored/queried in GMT;
 * the UI renders relative times client-side, so there's no timezone ambiguity.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

use Agentimus\Settings;
use Agentimus\Guard;
use Agentimus\BotVerifier;

defined( 'ABSPATH' ) || exit;

final class Repository {

	/** Default reporting window — and the default retention: the dashboard reports
	 *  on exactly the days it keeps (filter `agentimus_activity_retention_days`). */
	const WINDOW_DAYS = 30;

	/** A source first seen within this many hours is flagged "new". */
	const NEW_AGENT_HOURS = 48;

	/** Hits in the last hour at/above this count flag a "heavy" (burst) source. */
	const BURST_MIN_HITS = 30;

	/** Hits over the whole window at/above this count also flag "heavy" (sustained). */
	const HEAVY_MIN_HITS = 500;

	/** Max suspicious sources returned to the panel. */
	const THREATS_LIMIT = 12;

	/** Where "Ignore" dismissals live: a map of review-key => { at, hits }. Its own
	 *  option (autoload off) so it stays out of the user-facing settings form, and is
	 *  bounded to the most recent DISMISS_MAX entries. */
	const DISMISS_OPTION = 'agentimus_review_dismissed';
	const DISMISS_MAX    = 200;

	/** Where admin "Re-check" results live: a map of review-key => { verdict, at }, layered
	 *  over the ingest verdict at analysis time (the hit log is never rewritten). Its own
	 *  option (autoload off), bounded to REVERIFY_MAX, and short-lived — a re-check is a
	 *  point-in-time fact, so a stale one must stop overriding a client's live verdict. */
	const REVERIFY_OPTION = 'agentimus_review_reverified';
	const REVERIFY_MAX    = 200;
	const REVERIFY_TTL    = 7 * DAY_IN_SECONDS;

	/** Default rows in each dashboard breakdown; each filterable (see stats()). */
	const TOP_CLIENTS   = 8;
	const TOP_ENDPOINTS = 12;

	/** Max distinct values offered per filter dropdown {@see log_facets()}. */
	const FACET_LIMIT = 200;

	/** Hard ceiling on stored rows — a backstop to the daily age-based prune so an
	 *  extreme-traffic day can't bank unbounded rows before the cron fires.
	 *  Generous; filter `agentimus_activity_max_rows` (0 disables the cap). */
	const MAX_ROWS = 50000;

	/**
	 * One stored setting, without needing a Settings instance — prune() runs on cron with
	 * nothing to hand it one.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value when unset (an upgrading site has no such key yet).
	 * @return mixed
	 */
	private static function setting( $key, $default ) {
		$all = get_option( Settings::OPTION, array() );
		return ( is_array( $all ) && array_key_exists( $key, $all ) ) ? $all[ $key ] : $default;
	}

	/**
	 * Days of activity KEPT. Governs the prune cutoff, the request log's floor and its filter
	 * dropdowns — everything that answers "what still exists".
	 *
	 * Owners set this in Settings → Visit log; the filter still wins, so code beats UI.
	 * An upgrading site has no such key and gets {@see WINDOW_DAYS}, exactly as before.
	 *
	 * @return int
	 */
	public static function retention_days() {
		/**
		 * Filter the activity-log retention, in days. Governs the prune cutoff and how far
		 * back the request log can page.
		 *
		 * @param int $days The stored setting, default 30.
		 */
		$days = (int) apply_filters( 'agentimus_activity_retention_days', (int) self::setting( 'activity_retention_days', self::WINDOW_DAYS ) );
		return max( 1, $days );
	}

	/**
	 * Days of activity REPORTED ON — the dashboard's totals, chart, breakdowns, per-row
	 * trends and review queue.
	 *
	 * Capped at {@see WINDOW_DAYS}: keeping 90 days shouldn't stretch the summary cards, and
	 * the Request log is where a deeper history is meant to be read. Never EXCEEDS retention
	 * either — a 30-day chart on a 7-day retention would draw 23 days of zeros, which reads
	 * as "no crawlers came" rather than "we deleted it". So: min(30, retention).
	 *
	 * @return int
	 */
	public static function report_days() {
		return min( self::WINDOW_DAYS, self::retention_days() );
	}

	/**
	 * Whether the daily cron deletes records once they age past the retention.
	 *
	 * OFF is not "keep forever": {@see trim_to_cap()} still drops the oldest rows once the
	 * table passes {@see max_rows()}. The setting chooses WHICH rule collects, never whether
	 * one does.
	 *
	 * @return bool
	 */
	public static function auto_prune() {
		return (bool) self::setting( 'activity_auto_prune', true );
	}

	/**
	 * Hard ceiling on stored rows. A cap of 0 disables the trim — reachable only through the
	 * filter, never the UI, because an unbounded table is how a shared host runs out of disk.
	 *
	 * @return int
	 */
	public static function max_rows() {
		/**
		 * Filter the hard row cap on the activity table. 0 disables it.
		 *
		 * @param int $max The stored setting, default 50000.
		 */
		return (int) apply_filters( 'agentimus_activity_max_rows', (int) self::setting( 'activity_max_rows', self::MAX_ROWS ) );
	}

	/**
	 * Assemble the dashboard payload.
	 *
	 * @param Settings $settings Settings store.
	 * @return array
	 */
	public static function stats( Settings $settings ) {
		global $wpdb;
		$table = Table::name();

		// The dashboard reports on the retained span, capped at 30 days.
		//
		// Every cutoff is a CALENDAR-day boundary (UTC midnight), the same clock as the
		// "today" tile, the daily chart and the referral counters. The 7/30-day windows
		// used to be rolling (`now - N*86400`), which made those tiles legitimately
		// SHRINK between midnights as old hits aged out second by second — an owner
		// watching auto-refresh read that as data loss (2026-07-14, live sighting).
		// One clock everywhere: numbers only move at UTC midnight, matching the chart
		// they sit above. "7 days" = today plus the 6 full days before it.
		$window = self::report_days();
		$today  = gmdate( 'Y-m-d 00:00:00' );
		$week   = gmdate( 'Y-m-d 00:00:00', time() - 6 * DAY_IN_SECONDS );
		$month  = gmdate( 'Y-m-d 00:00:00', time() - ( $window - 1 ) * DAY_IN_SECONDS );

		return array(
			'enabled'    => (bool) $settings->enabled( 'enable_activity' ),
			// Two different numbers once an owner keeps more than the dashboard shows: `window`
			// is what these cards cover, `retention` is what still exists (and what the Request
			// log can page through). The UI must not use one where it means the other.
			'window'     => $window,
			'retention'  => self::retention_days(),
			'autoPrune'  => self::auto_prune(),
			'maxRows'    => self::max_rows(),
			'totals'     => array(
				'today'  => self::count_since( $today ),
				'week'   => self::count_since( $week ),
				'month'  => self::count_since( $month ),
				'all'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name (not user input); SQL identifiers can't be bound via prepare().
				'agents' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT agent) FROM $table WHERE hit_at >= %s", $month ) ), // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value is bound via prepare().
			),
			// Rows in each breakdown are filterable (a site may want more or fewer),
			// clamped to a sane 1–200: agentimus_activity_{clients,endpoints}_limit.
			'byAgent'      => self::group_counts( 'agent', $month, self::clamp_limit( apply_filters( 'agentimus_activity_clients_limit', self::TOP_CLIENTS ) ) ),
			'byEndpoint'   => self::group_counts( 'endpoint', $month, self::clamp_limit( apply_filters( 'agentimus_activity_endpoints_limit', self::TOP_ENDPOINTS ) ) ),
			'daily'        => self::daily(),
			'recent'       => self::recent( 50 ),
			'threats'      => self::threats( $settings ),
			'referrals'    => Referrals::summary( $window ),
			// What the referral map could NOT name. An empty, disabled shell unless the
			// owner turned the diagnostic on, so it costs no query on a normal dashboard.
			'unknownSources' => UnknownSources::summary( $window ),
		);
	}

	/**
	 * Count rows on/after a GMT threshold.
	 *
	 * @param string $threshold GMT datetime.
	 * @return int
	 */
	private static function count_since( $threshold ) {
		global $wpdb;
		$table = Table::name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE hit_at >= %s", $threshold ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value is bound via prepare().
	}

	/**
	 * Top counts grouped by a column over the window, each row carrying a within-window
	 * volume trend (for a growth/down arrow). The trend is computed from a gap-filled daily
	 * series built here; retention == window, so there is no prior period to compare against
	 * {@see trend_pct}. The series itself isn't shipped — only the resulting trend.
	 *
	 * @param string $column 'agent' or 'endpoint' (whitelisted).
	 * @param string $since  GMT threshold (the reporting window; sets the shown totals).
	 * @param int    $limit  Max rows.
	 * @return array<int,array{label:string,hits:int,trend:int|null}>
	 */
	private static function group_counts( $column, $since, $limit ) {
		global $wpdb;
		$table  = Table::name();
		$column = in_array( $column, array( 'agent', 'endpoint' ), true ) ? $column : 'agent';

		// The calendar-day window the sparkline series spans — matched to {@see daily()}
		// so the two charts line up.
		$days  = self::report_days();
		$start = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table and $column is whitelisted just above; SQL identifiers can't be bound via prepare(), only the values ($since/$start/$limit), which are.
		$rows        = $wpdb->get_results(
			$wpdb->prepare( "SELECT $column AS label, COUNT(*) AS hits FROM $table WHERE hit_at >= %s GROUP BY $column ORDER BY hits DESC LIMIT %d", $since, $limit ),
			ARRAY_A
		);
		// One bounded pass (distinct labels x days) for every label's per-day counts; the
		// top-N are picked out below, aligned to a gap-filled day list.
		$series_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT $column AS label, DATE(hit_at) AS d, COUNT(*) AS c FROM $table WHERE hit_at >= %s GROUP BY $column, DATE(hit_at)", $start ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$by = array(); // label => ( 'Y-m-d' => count ).
		foreach ( (array) $series_rows as $r ) {
			$by[ (string) $r['label'] ][ (string) $r['d'] ] = (int) $r['c'];
		}
		$dates = array(); // Ordered oldest → newest, gap-filled.
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$dates[] = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		}

		return array_map(
			static function ( $r ) use ( $by, $dates ) {
				$label  = (string) $r['label'];
				$counts = isset( $by[ $label ] ) ? $by[ $label ] : array();
				$series = array();
				foreach ( $dates as $d ) {
					$series[] = isset( $counts[ $d ] ) ? (int) $counts[ $d ] : 0;
				}
				return array(
					'label' => $label,
					'hits'  => (int) $r['hits'],
					'trend' => self::trend_pct( $series ),
				);
			},
			(array) $rows
		);
	}

	/**
	 * Within-window volume trend as a signed percentage: the recent half of a daily
	 * series against the earlier half. `null` means "new" — activity now with no earlier
	 * baseline, where a percentage would mislead. When odd-length, the middle day is
	 * dropped so the two halves stay the same size. Pure, so it is unit-testable.
	 *
	 * @param array<int,int> $series Daily counts, oldest → newest.
	 * @return int|null Signed % change, capped to ±999; null = new.
	 */
	public static function trend_pct( array $series ) {
		$n = count( $series );
		if ( $n < 2 ) {
			return 0;
		}
		$half    = intdiv( $n, 2 );
		$earlier = array_sum( array_slice( $series, 0, $half ) );
		$recent  = array_sum( array_slice( $series, $n - $half ) );
		if ( 0 === $earlier ) {
			return $recent > 0 ? null : 0;
		}
		$pct = (int) round( ( $recent - $earlier ) / $earlier * 100 );
		return max( -999, min( 999, $pct ) );
	}

	/**
	 * Clamp a (filtered) breakdown row-limit to a sane 1–200, so a stray filter value
	 * can't request zero rows or an unbounded query.
	 *
	 * @param mixed $n Requested limit.
	 * @return int
	 */
	private static function clamp_limit( $n ) {
		return max( 1, min( 200, (int) $n ) );
	}

	/** Per-day detail keeps at most this many rows per dimension; the rest roll
	 *  into a "+N more" so the inline card never grows with traffic. */
	const DAY_TOP = 5;

	/**
	 * Hits per day for the sparkline, gap-filled so every day is present. Each day
	 * also carries a compact breakdown — its top clients and top endpoints (capped
	 * at {@see DAY_TOP}) plus the *distinct* count of each — so the chart can show a
	 * "who/what drove this day" detail card without ever ballooning: a day with 50
	 * distinct endpoints still returns 5 rows and `endpointCount = 50`.
	 *
	 * @return array<int,array{date:string,hits:int,clients:array,clientCount:int,endpoints:array,endpointCount:int}>
	 */
	private static function daily() {
		global $wpdb;
		$table = Table::name();
		$days  = self::report_days();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value ($since) is bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(hit_at) AS d, COUNT(*) AS c FROM $table WHERE hit_at >= %s GROUP BY DATE(hit_at)", $since ),
			OBJECT_K
		);
		// Per-day breakdowns, each ordered by count DESC across all days — so the
		// first rows bucketed into a given day are that day's busiest (a global
		// DESC sort preserves the order within each day's subgroup too).
		$client_rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(hit_at) AS d, agent AS label, COUNT(*) AS c FROM $table WHERE hit_at >= %s GROUP BY DATE(hit_at), agent ORDER BY c DESC", $since ),
			ARRAY_A
		);
		$endpoint_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(hit_at) AS d, endpoint AS label, COUNT(*) AS c FROM $table WHERE hit_at >= %s GROUP BY DATE(hit_at), endpoint ORDER BY c DESC", $since ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$by_client   = self::bucket_breakdown( $client_rows );
		$by_endpoint = self::bucket_breakdown( $endpoint_rows );
		$empty       = array( 'top' => array(), 'count' => 0 );

		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$c    = isset( $by_client[ $date ] ) ? $by_client[ $date ] : $empty;
			$e    = isset( $by_endpoint[ $date ] ) ? $by_endpoint[ $date ] : $empty;
			$out[] = array(
				'date'          => $date,
				'hits'          => isset( $rows[ $date ] ) ? (int) $rows[ $date ]->c : 0,
				'clients'       => $c['top'],
				'clientCount'   => $c['count'],
				'endpoints'     => $e['top'],
				'endpointCount' => $e['count'],
			);
		}
		return $out;
	}

	/**
	 * Fold count-ordered {d,label,c} rows into per-day {top: first DAY_TOP rows,
	 * count: distinct labels}. Input is sorted by count DESC, so the kept rows are
	 * each day's busiest, while `count` still reflects the full distinct total.
	 *
	 * @param array $rows Ordered breakdown rows.
	 * @return array<string,array{top:array<int,array{label:string,hits:int}>,count:int}>
	 */
	private static function bucket_breakdown( $rows ) {
		$by = array();
		foreach ( (array) $rows as $r ) {
			$date = (string) $r['d'];
			if ( ! isset( $by[ $date ] ) ) {
				$by[ $date ] = array( 'top' => array(), 'count' => 0 );
			}
			++$by[ $date ]['count'];
			if ( count( $by[ $date ]['top'] ) < self::DAY_TOP ) {
				$by[ $date ]['top'][] = array( 'label' => (string) $r['label'], 'hits' => (int) $r['c'] );
			}
		}
		return $by;
	}

	/**
	 * Most recent hits.
	 *
	 * @param int $limit Rows.
	 * @return array<int,array{endpoint:string,agent:string,ua:string,network:string,verdict:int,at:string}>
	 */
	private static function recent( $limit ) {
		global $wpdb;
		$table = Table::name();
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value ($limit) is bound via prepare().
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT endpoint, agent, ua, network, verdict, hit_at FROM $table ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array_map( array( self::class, 'hit_row' ), (array) $rows );
	}

	/**
	 * Every hit recorded on a given GMT calendar date, newest first and capped —
	 * the *full* day, not the recent window {@see recent()} is limited to. Powers
	 * the dashboard's per-day "View requests" modal.
	 *
	 * @param string $date  GMT date, 'Y-m-d'.
	 * @param int    $limit Max rows to return.
	 * @return array{date:string,total:int,rows:array<int,array{endpoint:string,agent:string,ua:string,network:string,verdict:int,at:string}>,capped:bool}
	 */
	public static function day_requests( $date, $limit = 500 ) {
		global $wpdb;
		$table = Table::name();
		$limit = max( 1, min( 2000, (int) $limit ) );
		// Half-open range on hit_at (indexed) instead of DATE(hit_at) = date.
		$start = $date . ' 00:00:00';
		$end   = gmdate( 'Y-m-d 00:00:00', strtotime( $date . ' UTC' ) + DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the values are bound via prepare().
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE hit_at >= %s AND hit_at < %s", $start, $end )
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT endpoint, agent, ua, network, verdict, hit_at FROM $table WHERE hit_at >= %s AND hit_at < %s ORDER BY id DESC LIMIT %d", $start, $end, $limit ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = array_map( array( self::class, 'hit_row' ), (array) $rows );

		return array(
			'date'   => (string) $date,
			'total'  => $total,
			'rows'   => $out,
			'capped' => $total > count( $out ),
		);
	}

	/**
	 * The oldest moment a record can still exist, or '' when there is no such moment.
	 *
	 * The floor exists ONLY because the nightly prune deletes below it. With auto-delete off,
	 * rows age indefinitely (until the cap trims them), so clamping to a retention "floor"
	 * would hide surviving records that the log is supposed to show.
	 *
	 * @return string GMT datetime, or '' for no lower bound.
	 */
	private static function history_floor() {
		if ( ! self::auto_prune() ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS );
	}

	/**
	 * The distinct values the log's filters can offer, so the UI can present dropdowns
	 * instead of asking an owner to type a crawler's exact name from memory.
	 *
	 * Every list is bounded by the retained window (a value that can't be matched isn't
	 * worth offering) and ordered by how often it appears, so the clients you actually get
	 * sit at the top. Each is capped: `agent` and `endpoint` are low-cardinality by design,
	 * but `network` grows with the internet, and a select with 5,000 entries is not a
	 * control — it's a wall.
	 *
	 * @return array{agents:string[],endpoints:string[],networks:string[]}
	 */
	public static function log_facets() {
		global $wpdb;
		$table = Table::name();
		// '' (auto-delete off) means every stored row is fair game; 1970 is an inert bound.
		$since = self::history_floor();
		$since = ( '' === $since ) ? '1970-01-01 00:00:00' : $since;

		$distinct = static function ( $column ) use ( $wpdb, $table, $since ) {
			// $column is whitelisted by the caller below; SQL identifiers can't be bound.
			// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table/$column are ours, not user input; $since is bound via prepare().
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT $column FROM $table WHERE hit_at >= %s AND $column <> '' GROUP BY $column ORDER BY COUNT(*) DESC LIMIT %d",
					$since,
					self::FACET_LIMIT
				)
			);
			// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return array_values( array_map( 'strval', (array) $rows ) );
		};

		return array(
			'agents'    => $distinct( 'agent' ),
			'endpoints' => $distinct( 'endpoint' ),
			'networks'  => $distinct( 'network' ),
		);
	}

	/**
	 * Shape one raw hit row for the client. The single place that decides what a request
	 * row looks like — {@see recent()}, {@see day_requests()} and {@see log()} all use it.
	 *
	 * @param array $r Raw row (endpoint, agent, ua, network, verdict, hit_at).
	 * @return array{endpoint:string,agent:string,ua:string,network:string,verdict:int,at:string}
	 */
	private static function hit_row( array $r ) {
		return array(
			'endpoint' => (string) $r['endpoint'],
			'agent'    => (string) $r['agent'],
			'ua'       => (string) $r['ua'],
			'network'  => (string) $r['network'], // '' unless "identify every bot" is on.
			'verdict'  => (int) $r['verdict'],     // 1 = forward-confirmed; a network with verdict != 1 is self-declared (PTR only).
			'at'       => gmdate( 'c', strtotime( $r['hit_at'] . ' UTC' ) ),
		);
	}

	/**
	 * The filtered, paged request log — the "what did this agent actually do" view the
	 * dashboard's rollups can't answer. Every argument is optional; with none, it returns
	 * the newest page of the whole retained window.
	 *
	 * Paging is KEYSET (`id < $before`), not OFFSET. Walking back through tens of thousands
	 * of rows with OFFSET costs more on every page, and a crawler inserting mid-walk shifts
	 * the window so a row can be skipped or repeated. An id cursor is stable and flat-cost.
	 *
	 * Rows are bounded on `hit_at`, NOT translated into an id range. `id` happens to be
	 * monotonic with `hit_at` in production (Recorder always stamps "now"), but nothing
	 * enforces it — a backfill or a test fixture can insert an old hit with a fresh id, and
	 * an id-range filter would silently drop it. The ordering key stays `id` (stable, unique);
	 * only the range predicate is time.
	 *
	 * `ua` is a PREFIX match: `KEY ua(191)` can serve `LIKE 'x%'` but never `LIKE '%x%'`.
	 *
	 * @param array $args from, to (Y-m-d), agent, endpoint, network, verdict, ua, before, per_page.
	 * @return array{rows:array,total:int,perPage:int,cursor:?int,hasMore:bool,retentionDays:int}
	 */
	public static function log( array $args = array() ) {
		global $wpdb;
		$table    = Table::name();
		$per_page = self::clamp_limit( isset( $args['per_page'] ) ? $args['per_page'] : 50 );
		$before   = isset( $args['before'] ) ? max( 0, (int) $args['before'] ) : 0;
		$days     = self::retention_days();

		// Nothing older than the floor exists, so asking for it would only scan. With
		// auto-delete off there is no floor: older rows survive until the cap trims them, and
		// the log must still page back to them.
		$floor = self::history_floor();
		$start = ! empty( $args['from'] ) ? $args['from'] . ' 00:00:00' : $floor;
		if ( '' === $start ) {
			$start = '1970-01-01 00:00:00';
		} elseif ( '' !== $floor && $start < $floor ) {
			$start = $floor;
		}
		// Half-open upper bound, matching day_requests(): [start, end).
		$end = ! empty( $args['to'] )
			? gmdate( 'Y-m-d 00:00:00', strtotime( $args['to'] . ' UTC' ) + DAY_IN_SECONDS )
			: gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		$where  = array( 'hit_at >= %s', 'hit_at < %s' );
		$params = array( $start, $end );

		foreach ( array( 'agent', 'endpoint', 'network' ) as $col ) {
			if ( isset( $args[ $col ] ) && '' !== $args[ $col ] ) {
				$where[]  = "$col = %s";
				$params[] = (string) $args[ $col ];
			}
		}
		if ( isset( $args['verdict'] ) && '' !== $args['verdict'] && null !== $args['verdict'] ) {
			$where[]  = 'verdict = %d';
			$params[] = (int) $args['verdict'];
		}
		if ( ! empty( $args['ua'] ) ) {
			// esc_like, or a needle of "%x" silently becomes a full-table contains-search.
			$where[]  = 'ua LIKE %s';
			$params[] = $wpdb->esc_like( (string) $args['ua'] ) . '%';
		}

		$filter_sql    = implode( ' AND ', $where );
		$filter_params = $params;

		// The cursor narrows the page, never the total — "showing 50 of 3,412" must count
		// the whole filtered set, not what's left below the cursor.
		if ( $before > 0 ) {
			$where[]  = 'id < %d';
			$params[] = $before;
		}
		$page_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $filter_sql", $filter_params )
		);

		// Fetch one extra row to learn whether another page exists, without a second query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, endpoint, agent, ua, network, verdict, hit_at FROM $table WHERE $page_sql ORDER BY id DESC LIMIT %d",
				array_merge( $params, array( $per_page + 1 ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$rows     = (array) $rows;
		$has_more = count( $rows ) > $per_page;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$last = end( $rows );

		return array(
			'rows'          => array_map( array( self::class, 'hit_row' ), $rows ),
			'total'         => $total,
			'perPage'       => $per_page,
			'cursor'        => $has_more && $last ? (int) $last['id'] : null,
			'hasMore'       => $has_more,
			// So the UI can say what it cannot show. `autoPrune` decides whether
			// `retentionDays` is a deletion horizon at all, or merely the setting's value.
			'retentionDays' => $days,
			'autoPrune'     => self::auto_prune(),
			'maxRows'       => self::max_rows(),
		);
	}

	/**
	 * Suspicious-source signals for the dashboard's "Suspicious activity" section.
	 * Thin DB layer: pulls per-UA aggregates over the window plus a last-hour burst
	 * count, then hands them to the pure {@see analyze_threats()} for flagging and
	 * ranking. UA-only by design — no IP is stored — so this is heuristic
	 * VISIBILITY (novelty, request rate, spoofed-UA), never a substitute for a WAF.
	 *
	 * @param Settings $settings Settings store.
	 * @return array{sources:array,counts:array,blockingOn:bool}
	 */
	public static function threats( Settings $settings ) {
		global $wpdb;
		$table = Table::name();
		$now   = time();
		// The REPORTED span, not the retained one. HEAVY_MIN_HITS is documented as a total
		// "over the whole window", and the window a viewer sees is report_days() — so the
		// queue counts exactly the hits the dashboard is showing. Reading WINDOW_DAYS raw was
		// wrong for a shorter retention (queue looked back 30 days into data kept for 7);
		// reading retention_days() would be wrong for a longer one (queue counts 90 days
		// while every card on the dashboard shows 30). Calendar-day boundary for the same
		// reason as stats(): "what the dashboard is showing" is now a calendar window.
		$since = gmdate( 'Y-m-d 00:00:00', $now - ( self::report_days() - 1 ) * DAY_IN_SECONDS );
		$hour  = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );

		// One row per distinct UA over the window. MAX(agent) is unambiguous: the
		// agent label is a pure function of the UA, so every row in a UA-group shares
		// it. Bounded to the 200 busiest sources — far more than a content site sees,
		// and the pure pass only keeps the flagged ones anyway.
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; values are bound via prepare().
		// MAX(verdict) folds the per-hit reverse-DNS results to the WORST seen for this UA
		// (2 spoofed > 1 verified > 0 unchecked): if any hit under this UA conclusively
		// failed verification, the client is an impersonator and must surface as one.
		$sources = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ua, MAX(agent) AS agent, COUNT(*) AS hits, MAX(verdict) AS verdict, MAX(network) AS network, MIN(hit_at) AS first_seen, MAX(hit_at) AS last_seen FROM $table WHERE hit_at >= %s GROUP BY ua ORDER BY hits DESC LIMIT 200",
				$since
			),
			ARRAY_A
		);
		$recent_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT ua, COUNT(*) AS c FROM $table WHERE hit_at >= %s GROUP BY ua", $hour ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$recent = array();
		foreach ( (array) $recent_rows as $r ) {
			$recent[ (string) $r['ua'] ] = (int) $r['c'];
		}

		$result = self::analyze_threats(
			(array) $sources,
			$recent,
			$now,
			array(
				'blockingOn' => (bool) $settings->enabled( 'block_agents' ),
				'newSecs'    => (int) apply_filters( 'agentimus_new_agent_seconds', self::NEW_AGENT_HOURS * HOUR_IN_SECONDS ),
				'burstMin'   => (int) apply_filters( 'agentimus_burst_min_hits', self::BURST_MIN_HITS ),
				'heavyMin'   => (int) apply_filters( 'agentimus_heavy_min_hits', self::HEAVY_MIN_HITS ),
				'limit'      => (int) apply_filters( 'agentimus_threats_limit', self::THREATS_LIMIT ),
				'dismissed'  => self::dismissed_map(),
				'reverified' => self::reverified_map(),
			)
		);

		// Opt-in: decorate each row with the actual source IPs captured for that client
		// (keyed by the same dismiss_key used at capture time). Only when IP storage is on
		// — otherwise the store is empty and there's nothing to fetch.
		if ( $settings->enabled( 'store_flagged_ips' ) && ! empty( $result['sources'] ) ) {
			$keys = array();
			foreach ( $result['sources'] as $row ) {
				$keys[] = self::dismiss_key( $row['ua'] );
			}
			$ip_map = FlaggedIps::for_keys( $keys );
			foreach ( $result['sources'] as &$row ) {
				$key         = self::dismiss_key( $row['ua'] );
				$row['ips']  = isset( $ip_map[ $key ] ) ? $ip_map[ $key ] : array();
			}
			unset( $row );
		}

		return $result;
	}

	/**
	 * Pure flagging + ranking of suspicious sources — no DB, no time() — so it's
	 * unit-testable in isolation. Each input source is `{ua, agent, hits, first_seen,
	 * last_seen}` (GMT strings). Flags: NEW (first seen within newSecs), HEAVY (burst
	 * in the last hour OR sustained over the window), SPOOF (legacy-device heuristic).
	 * Only flagged sources are returned, ranked spoof > heavy > new then by volume.
	 * Each carries the live "already blocked" state ({@see Guard::denies()}) and, when
	 * actionable, a safe block token / spoof-class action for the one-click button.
	 *
	 * @param array $sources Per-UA aggregates.
	 * @param array $recent  Map of UA => hits in the last hour.
	 * @param int   $now     Current GMT unix time.
	 * @param array $opts    blockingOn, newSecs, burstMin, heavyMin, limit.
	 * @return array{sources:array,counts:array,blockingOn:bool}
	 */
	public static function analyze_threats( array $sources, array $recent, $now, array $opts ) {
		$new_secs  = isset( $opts['newSecs'] ) ? (int) $opts['newSecs'] : self::NEW_AGENT_HOURS * HOUR_IN_SECONDS;
		$burst_min = isset( $opts['burstMin'] ) ? (int) $opts['burstMin'] : self::BURST_MIN_HITS;
		$heavy_min = isset( $opts['heavyMin'] ) ? (int) $opts['heavyMin'] : self::HEAVY_MIN_HITS;
		$limit     = isset( $opts['limit'] ) ? (int) $opts['limit'] : self::THREATS_LIMIT;

		$out        = array();
		$counts     = array( 'new' => 0, 'heavy' => 0, 'spoof' => 0 );
		$reverified = isset( $opts['reverified'] ) ? (array) $opts['reverified'] : array();

		foreach ( $sources as $s ) {
			$ua      = isset( $s['ua'] ) ? (string) $s['ua'] : '';
			$verdict = isset( $s['verdict'] ) ? (int) $s['verdict'] : 0;

			// Layer an admin "Re-check" result over the ingest verdict, keyed by the same
			// review identity — so a re-confirmed impostor stays flagged (and a re-cleared one
			// drops out) everywhere the verdict is used below (the protected-engine skip, the
			// fake-engine flag, severity, the panel badge), all WITHOUT rewriting the hit log.
			$reverified_at = '';
			$rv_key        = self::dismiss_key( $ua );
			// Only a CONCLUSIVE re-check (verified/spoofed) overrides. An inconclusive one
			// (0 — resolver down, undetermined) carries no new information, so it must never
			// clear a verdict the way null never overrides a cached one at ingest.
			if ( isset( $reverified[ $rv_key ]['verdict'] ) && (int) $reverified[ $rv_key ]['verdict'] > 0 ) {
				$verdict = (int) $reverified[ $rv_key ]['verdict'];
				$rv_at   = isset( $reverified[ $rv_key ]['at'] ) ? (string) $reverified[ $rv_key ]['at'] : '';
				if ( '' !== $rv_at ) {
					$reverified_at = gmdate( 'c', strtotime( $rv_at . ' UTC' ) );
				}
			}

			// A protected/allow-listed search engine (Googlebot, Bingbot…) is trusted
			// by definition — never denied, never "suspicious". Keep it out entirely —
			// UNLESS live reverse-DNS caught it impersonating the engine it claims
			// (verdict 2): a forged "Googlebot" is exactly what the owner must see.
			if ( '' !== $ua && Guard::is_protected_ua( $ua ) && 2 !== $verdict ) {
				continue;
			}

			$hits  = isset( $s['hits'] ) ? (int) $s['hits'] : 0;
			$first = isset( $s['first_seen'] ) ? strtotime( $s['first_seen'] . ' UTC' ) : 0;
			$last  = isset( $s['last_seen'] ) ? strtotime( $s['last_seen'] . ' UTC' ) : 0;
			$rec   = isset( $recent[ $ua ] ) ? (int) $recent[ $ua ] : 0;

			$is_new      = $first > 0 && ( $now - $first ) <= $new_secs;
			$is_heavy    = $rec >= $burst_min || $hits >= $heavy_min;
			$is_spoof    = Classifier::is_spoof( $ua );
			$fake_engine = 2 === $verdict; // Live reverse-DNS: claims an engine it isn't.

			if ( ! $is_new && ! $is_heavy && ! $is_spoof && ! $fake_engine ) {
				continue; // Nothing flags it.
			}

			$blocked  = Guard::denies( $ua );
			$token    = $blocked ? '' : Guard::suggest_token( $ua );
			// An active impersonator outranks everything — it's a client lying about who
			// it is, which no volume/novelty signal matches for seriousness.
			$severity = ( $fake_engine ? 5 : 0 ) + ( $is_spoof ? 4 : 0 ) + ( $is_heavy ? 2 : 0 ) + ( $is_new ? 1 : 0 );

			// What the one-click action does for this row: nothing when already
			// denied; for a spoofed UA, arm the whole scanner class (more useful than
			// blocking its single legacy-device string); else block the derived token
			// when one is safe; otherwise it isn't safely actionable (no UA, a real
			// browser, or a protected search engine) — say why instead.
			$action = '';
			$reason = '';
			if ( $blocked ) {
				$action = '';
			} elseif ( $is_spoof ) {
				$action = 'spoofed';
			} elseif ( '' !== $token ) {
				$action = 'agent';
			} elseif ( $fake_engine ) {
				// Impersonating a search engine, but its UA carries no safe block token
				// (blocking "Googlebot" would also block the real crawler). Not one-click
				// actionable — the honest remedy is an IP/firewall rule at the host/CDN.
				$reason = 'fake-engine';
			} else {
				$reason = '' === trim( $ua ) ? 'no-ua' : 'no-token';
			}

			// A "new"-only source we can neither block nor flag as spoof/heavy is just
			// noise here (a one-off new browser/script). Show only genuinely suspicious
			// (spoof/heavy/impersonating) or actionable / already-blocked rows. (Counted
			// post-merge.)
			if ( ! $is_spoof && ! $is_heavy && ! $fake_engine && ! $blocked && '' === $action ) {
				continue;
			}

			$known   = Catalog::identify( $ua );
			$out[] = array(
				'ua'        => substr( $ua, 0, 255 ),
				'agent'     => isset( $s['agent'] ) ? (string) $s['agent'] : '',
				'known'     => $known,
				// For an unknown client, give the owner somewhere to look: its own
				// self-declared (+URL) page, else a web search. Null when recognised.
				'guide'     => $known ? null : Catalog::self_declared( $ua ),
				'hits'      => $hits,
				'recent'    => $rec,
				'firstSeen' => $first ? gmdate( 'c', $first ) : '',
				'lastSeen'  => $last ? gmdate( 'c', $last ) : '',
				'flags'     => array(
					'new'   => $is_new,
					'heavy' => $is_heavy,
					'spoof' => $is_spoof,
				),
				'severity'  => $severity,
				'blocked'   => $blocked,
				'action'    => $action,
				'token'     => $token,
				'reason'    => $reason,
				// Live reverse-DNS result, for the "Check this bot" panel: 'spoofed' =
				// caught impersonating the engine it claims, 'verified' = forward-confirmed,
				// '' = unchecked (verification off, or not an engine we can check).
				'verdict'   => 2 === $verdict ? 'spoofed' : ( 1 === $verdict ? 'verified' : '' ),
				// Whether this client CLAIMS one of the reverse-DNS-verifiable engines
				// (Googlebot/Bingbot/DuckDuckBot/Applebot/Yandex). Disambiguates an empty
				// verdict: false here means "not an engine Verify can check" (e.g.
				// Bytespider) — so the UI won't offer the dead-end "turn on Verify" nudge.
				'verifiable' => '' !== BotVerifier::claimed_engine( strtolower( $ua ) ),
				// When the owner last ran an admin "Re-check" for this client (ISO-8601), so
				// the panel can show "re-checked 2m ago". '' when never re-checked.
				'reverifiedAt' => $reverified_at,
				// The owning network (reverse-DNS attribution) when "identify every bot" is on;
				// '' otherwise. Org-level (e.g. amazonaws.com), never the IP.
				'network'    => isset( $s['network'] ) ? (string) $s['network'] : '',
			);
		}

		// Collapse UA variants of one client: two user-agents that resolve to the same
		// block token (a version bump, a parenthetical comment…) are one decision —
		// one Block denies both — so listing them separately implies two. Fold matching
		// actionable rows into one (summed volume, widest first/last window, OR'd flags),
		// keeping the most-recent UA as the face of the row.
		$out = self::merge_token_variants( $out );

		// Drop rows the owner dismissed ("not now"), unless they've materially changed
		// since. Done AFTER the merge so the re-surface test compares against the same
		// summed volume the owner saw when they chose to dismiss.
		$out = self::apply_dismissals(
			$out,
			isset( $opts['dismissed'] ) ? (array) $opts['dismissed'] : array(),
			$burst_min
		);

		// Count only what finally shows (post-merge, post-dismiss) so the chips and badge
		// match the rows the owner actually sees.
		$counts = array( 'new' => 0, 'heavy' => 0, 'spoof' => 0 );
		foreach ( $out as $row ) {
			if ( $row['flags']['new'] ) {
				++$counts['new'];
			}
			if ( $row['flags']['heavy'] ) {
				++$counts['heavy'];
			}
			if ( $row['flags']['spoof'] ) {
				++$counts['spoof'];
			}
		}

		// Rank for a "review" panel: rows that still need a decision lead; an
		// already-blocked client is handled, so it sinks. Within each group, most
		// severe first, then by raw volume.
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['blocked'] !== $b['blocked'] ) {
					return $a['blocked'] ? 1 : -1;
				}
				if ( $a['severity'] !== $b['severity'] ) {
					return $b['severity'] - $a['severity'];
				}
				return $b['hits'] - $a['hits'];
			}
		);

		return array(
			'sources'    => array_slice( $out, 0, max( 1, $limit ) ),
			'counts'     => $counts,
			'blockingOn' => ! empty( $opts['blockingOn'] ),
			// How long the "new" flag lasts. The queue is rebuilt from the log on every
			// read, so a novelty-only row leaves ON ITS OWN when this window lapses —
			// the UI needs the number to say so up front ("leaves in 31h") instead of
			// letting the owner discover a silent disappearance. From the resolved
			// option, so a filtered `agentimus_new_agent_seconds` shows its real value.
			'newSecs'    => $new_secs,
		);
	}

	/**
	 * Fold review rows that share a block token into one. Only actionable ('agent')
	 * rows merge — a spoof row arms a whole class and a no-token row has no shared
	 * action, so those stay as they are. Order is preserved (first occurrence keeps
	 * its slot); later variants are summed into it.
	 *
	 * @param array $rows Per-UA rows built by {@see analyze_threats()}.
	 * @return array
	 */
	private static function merge_token_variants( array $rows ) {
		$out   = array();
		$index = array(); // token => position in $out.
		foreach ( $rows as $row ) {
			$key = ( 'agent' === $row['action'] && '' !== $row['token'] ) ? $row['token'] : '';
			if ( '' === $key || ! isset( $index[ $key ] ) ) {
				$row['variants']   = 1;
				$row['variantUas'] = array( $row['ua'] );
				if ( '' !== $key ) {
					$index[ $key ] = count( $out );
				}
				$out[] = $row;
				continue;
			}
			$i         = $index[ $key ];
			$out[ $i ] = self::fold_variant( $out[ $i ], $row );
		}
		return $out;
	}

	/**
	 * Merge one extra UA variant ($add) into the row being kept ($keep): summed
	 * volume, widest seen-window, OR'd flags, with the most-recent variant's UA and
	 * identity card as the row's representative face. Variant UAs are listed (capped)
	 * for the row's tooltip.
	 *
	 * @param array $keep Accumulator row.
	 * @param array $add  Variant to fold in.
	 * @return array
	 */
	private static function fold_variant( array $keep, array $add ) {
		$keep['hits']    += $add['hits'];
		$keep['recent']  += $add['recent'];
		$keep['severity'] = max( $keep['severity'], $add['severity'] );
		// Worst verdict wins (spoofed > verified > unchecked): a client that impersonated
		// its claimed engine in ANY variant is an impersonator.
		if ( 'spoofed' === $add['verdict'] || ( 'verified' === $add['verdict'] && '' === $keep['verdict'] ) ) {
			$keep['verdict'] = $add['verdict'];
		}
		foreach ( array( 'new', 'heavy', 'spoof' ) as $flag ) {
			$keep['flags'][ $flag ] = $keep['flags'][ $flag ] || $add['flags'][ $flag ];
		}
		// ISO-8601 with a fixed +00:00 offset sorts lexically = chronologically.
		if ( '' !== $add['firstSeen'] && ( '' === $keep['firstSeen'] || $add['firstSeen'] < $keep['firstSeen'] ) ) {
			$keep['firstSeen'] = $add['firstSeen'];
		}
		// Keep the freshest re-check stamp across folded variants (they share a review key,
		// so this is normally identical — max() just guards the edge where it isn't).
		if ( '' !== $add['reverifiedAt'] && ( '' === $keep['reverifiedAt'] || $add['reverifiedAt'] > $keep['reverifiedAt'] ) ) {
			$keep['reverifiedAt'] = $add['reverifiedAt'];
		}
		// Keep a non-empty network attribution across folded variants.
		if ( '' === $keep['network'] && '' !== $add['network'] ) {
			$keep['network'] = $add['network'];
		}
		// The latest-seen variant becomes the row's face (UA + identity card).
		if ( $add['lastSeen'] > $keep['lastSeen'] ) {
			$keep['lastSeen'] = $add['lastSeen'];
			$keep['ua']       = $add['ua'];
			$keep['agent']    = $add['agent'];
			$keep['known']    = $add['known'];
			$keep['guide']    = $add['guide'];
		}
		++$keep['variants'];
		if ( count( $keep['variantUas'] ) < 6 ) {
			$keep['variantUas'][] = $add['ua'];
		}
		return $keep;
	}

	/**
	 * The stable "identity" key a dismissal is filed under — the same value derived at
	 * dismiss-time and at analysis-time, so a "not now" sticks to the right client across
	 * refreshes and UA version-bumps. A client with a safe block token keys on that token
	 * (so every variant of it is one dismissal, mirroring the one-decision merge); one
	 * without keys on a hash of its UA. Pure.
	 *
	 * @param string $ua Raw User-Agent.
	 * @return string Dismissal key.
	 */
	public static function dismiss_key( $ua ) {
		$ua    = (string) $ua;
		$token = Guard::suggest_token( $ua ); // Already lowercased; '' when none is safe.
		if ( '' !== $token ) {
			return 'tok:' . $token;
		}
		return 'ua:' . md5( strtolower( trim( $ua ) ) );
	}

	/**
	 * Drop dismissed rows from a merged review set — unless the client has changed enough
	 * to earn another look. A dismissal (including of a caught impersonator — the owner has
	 * seen it and the in-plugin remedy is an acknowledgement, since the real fix is an IP
	 * rule at the host/CDN) is honoured until the client's volume both DOUBLES and grows by
	 * at least a burst's worth — a materially different picture from the one the owner waved
	 * off, not mere drift. So a persistent impersonator that keeps hammering re-surfaces on
	 * its own. Pure (the map is passed in).
	 *
	 * @param array $rows      Merged review rows.
	 * @param array $dismissed Map of key => { at, hits }.
	 * @param int   $burst_min The burst threshold, reused as the minimum re-surface delta.
	 * @return array Rows that still belong in the queue.
	 */
	private static function apply_dismissals( array $rows, array $dismissed, $burst_min ) {
		if ( empty( $dismissed ) ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$key = self::dismiss_key( $row['ua'] );
			if ( isset( $dismissed[ $key ] ) ) {
				$was  = isset( $dismissed[ $key ]['hits'] ) ? (int) $dismissed[ $key ]['hits'] : 0;
				$grew = (int) $row['hits'] >= max( $was * 2, $was + (int) $burst_min );
				if ( ! $grew ) {
					continue; // Dismissed and materially unchanged — suppress.
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * The dismissals map (key => { at, hits }). Always an array.
	 *
	 * @return array
	 */
	public static function dismissed_map() {
		$map = get_option( self::DISMISS_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * The dismissals as display rows for the client manager, newest first.
	 * Backward compatible with entries written before `label` existed: a token
	 * key ("tok:semrushbot") still names itself; a hash key yields '' and the
	 * UI shows a neutral "Unnamed client".
	 *
	 * @return array<int,array{key:string,label:string,at:int,hits:int}>
	 */
	public static function dismissals() {
		$out = array();
		foreach ( self::dismissed_map() as $key => $row ) {
			$label = isset( $row['label'] ) ? (string) $row['label'] : '';
			if ( '' === $label && 0 === strpos( (string) $key, 'tok:' ) ) {
				$label = substr( (string) $key, 4 );
			}
			$out[] = array(
				'key'   => (string) $key,
				'label' => $label,
				'at'    => isset( $row['at'] ) ? (int) $row['at'] : 0,
				'hits'  => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['at'] <=> $a['at'];
			}
		);
		return $out;
	}

	/**
	 * Forget one dismissal — the client manager's "Un-ignore". The client
	 * reappears in the review queue on its next build if it is still visiting.
	 *
	 * @param string $key Review key ("tok:…" / "ua:…") as listed by dismissals().
	 * @return bool Whether the key existed.
	 */
	public static function undismiss( $key ) {
		$key = (string) $key;
		$map = self::dismissed_map();
		if ( ! isset( $map[ $key ] ) ) {
			return false;
		}
		unset( $map[ $key ] );
		update_option( self::DISMISS_OPTION, $map, false );
		return true;
	}

	/**
	 * The admin "Re-check" overlay (review-key => { verdict:int, at:string }), with entries
	 * past {@see REVERIFY_TTL} filtered out AT READ time — a re-check is a point-in-time fact,
	 * so a stale one must never keep overriding a client's live ingest verdict, even before the
	 * daily prune runs. `at` is a GMT 'Y-m-d H:i:s' string, so the lexical compare is chronological.
	 *
	 * @return array<string,array{verdict:int,at:string}>
	 */
	public static function reverified_map() {
		$map = get_option( self::REVERIFY_OPTION, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::REVERIFY_TTL );
		$fresh  = array();
		foreach ( $map as $k => $v ) {
			if ( isset( $v['at'] ) && (string) $v['at'] >= $cutoff ) {
				$fresh[ (string) $k ] = array(
					'verdict' => isset( $v['verdict'] ) ? (int) $v['verdict'] : 0,
					'at'      => (string) $v['at'],
				);
			}
		}
		return $fresh;
	}

	/**
	 * Record one admin "Re-check" result, keyed by the client's review identity so it layers
	 * onto the right row across UA version-bumps (mirrors {@see dismiss()}). Bounded to the
	 * most recent REVERIFY_MAX entries.
	 *
	 * @param string $ua      The client's raw User-Agent.
	 * @param int    $verdict Fresh verdict: 0 = undetermined, 1 = verified, 2 = spoofed.
	 * @return void
	 */
	public static function record_reverify( $ua, $verdict ) {
		$map = get_option( self::REVERIFY_OPTION, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$map[ self::dismiss_key( $ua ) ] = array(
			'verdict' => max( 0, min( 2, (int) $verdict ) ),
			'at'      => current_time( 'mysql', true ), // GMT, matches the map's compare format.
		);
		if ( count( $map ) > self::REVERIFY_MAX ) {
			uasort(
				$map,
				static function ( $a, $b ) {
					return strcmp( isset( $b['at'] ) ? (string) $b['at'] : '', isset( $a['at'] ) ? (string) $a['at'] : '' );
				}
			);
			$map = array_slice( $map, 0, self::REVERIFY_MAX, true );
		}
		update_option( self::REVERIFY_OPTION, $map, false );
	}

	/**
	 * Drop re-check overlays past their TTL from storage (freshness is also enforced at read by
	 * {@see reverified_map()}; this just keeps the option from growing). Runs with the daily prune.
	 *
	 * @return void
	 */
	public static function prune_reverified() {
		$map = get_option( self::REVERIFY_OPTION, array() );
		if ( ! is_array( $map ) || empty( $map ) ) {
			return;
		}
		$fresh = self::reverified_map();
		if ( count( $fresh ) !== count( $map ) ) {
			update_option( self::REVERIFY_OPTION, $fresh, false );
		}
	}

	/**
	 * File an "Ignore" for a client: record its identity key with the volume the owner
	 * saw, so {@see apply_dismissals()} can later tell "unchanged" from "changed". Bounded
	 * to the most-recently dismissed DISMISS_MAX entries.
	 *
	 * @param string $ua   The client's raw User-Agent.
	 * @param int    $hits Its hit count at dismiss-time (what the owner saw).
	 * @return void
	 */
	public static function dismiss( $ua, $hits ) {
		$map                             = self::dismissed_map();
		$map[ self::dismiss_key( $ua ) ] = array(
			'at'   => time(),
			'hits' => max( 0, (int) $hits ),
			// Display name for the client manager, resolved the same way the
			// activity feed names clients. Entries written before this field
			// existed simply have none (the manager falls back to the key).
			'label' => Classifier::classify( (string) $ua ),
		);
		if ( count( $map ) > self::DISMISS_MAX ) {
			uasort(
				$map,
				static function ( $a, $b ) {
					return ( isset( $b['at'] ) ? (int) $b['at'] : 0 ) - ( isset( $a['at'] ) ? (int) $a['at'] : 0 );
				}
			);
			$map = array_slice( $map, 0, self::DISMISS_MAX, true );
		}
		update_option( self::DISMISS_OPTION, $map, false );
	}

	/**
	 * Forget dismissals older than the retention window, so a long-gone client that
	 * returns gets a fresh review rather than a stale, silent suppression. Runs with the
	 * daily prune.
	 *
	 * @return void
	 */
	public static function prune_dismissed() {
		$map = self::dismissed_map();
		if ( empty( $map ) ) {
			return;
		}
		$cutoff = time() - self::retention_days() * DAY_IN_SECONDS;
		$kept   = array();
		foreach ( $map as $k => $v ) {
			if ( ( isset( $v['at'] ) ? (int) $v['at'] : 0 ) >= $cutoff ) {
				$kept[ $k ] = $v;
			}
		}
		if ( count( $kept ) !== count( $map ) ) {
			update_option( self::DISMISS_OPTION, $kept, false );
		}
	}

	/**
	 * Delete rows older than the (filterable) retention window. Scheduled daily.
	 */
	public static function prune() {
		// With auto-delete off, records age indefinitely — but only until trim_to_cap() drops
		// the oldest at the row ceiling. Nothing here grows without bound.
		if ( self::auto_prune() ) {
			global $wpdb;
			$table  = Table::name();
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE hit_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value is bound via prepare().
		}
		// Guarantee the row cap at least daily, independent of the sampled insert path
		// (Recorder trims on ~1 insert in CAP_CHECK_ODDS). Without this, lowering the cap on a
		// quiet site does nothing until traffic returns, and with auto-delete off — where the
		// cap is the ONLY thing that collects — a table that stops receiving hits stays over
		// its ceiling indefinitely. Referrals::prune() has always done this; this one hadn't.
		self::trim_to_cap();

		// These two are bounded option maps, not the log: they expire on their own schedule
		// and must be tidied whether or not age-based pruning is on.
		self::prune_dismissed();
		self::prune_reverified();
	}

	/**
	 * Cap the table to the newest {@see MAX_ROWS} rows — a backstop to the daily,
	 * age-based {@see prune()} so an extreme-traffic day can't bank unbounded rows
	 * before the cron fires. Called opportunistically (sampled) from the insert
	 * path, so it costs nothing on the common request. Filterable; a cap of 0
	 * disables it.
	 */
	public static function trim_to_cap() {
		$max = self::max_rows();
		if ( $max < 1 ) {
			return; // Cap disabled (filter-only; the UI never offers it).
		}
		global $wpdb;
		$table = Table::name();
		// id of the (max+1)-th newest row: it and everything older is beyond the cap, so
		// removing `id <= cutoff` leaves EXACTLY the newest $max rows. (An inclusive `<`
		// here kept one row too many — caught by the integration suite.)
		$cutoff = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table ORDER BY id DESC LIMIT 1 OFFSET %d", $max ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the offset is bound via prepare().
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id <= %d", (int) $cutoff ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the id is bound via prepare().
		}
	}

	/**
	 * Empty the log — and, with it, the "Ignore" dismissals: once the history they were
	 * judged against is gone, a stale suppression would only hide a client the owner can
	 * no longer see any reason for.
	 */
	public static function clear() {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- admin-gated truncation of our own prefix-derived table.
		delete_option( self::DISMISS_OPTION );
		delete_option( self::REVERIFY_OPTION ); // Re-checks judge the same history — clear them with it.
		FlaggedIps::clear(); // The captured IPs are judged against this history — clear them with it.
	}
}
