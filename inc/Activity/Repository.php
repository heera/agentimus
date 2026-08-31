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
use Agentimus\ReviewBadge;
use Agentimus\VerifierRegistry;

defined( 'ABSPATH' ) || exit;

final class Repository {

	/** Default reporting window — and the default retention: the dashboard reports
	 *  on exactly the days it keeps (filter `agentimus_activity_retention_days`). */
	const WINDOW_DAYS = 30;

	/** Default rows in each dashboard breakdown; each filterable (see stats()). */
	const TOP_CLIENTS   = 8;
	const TOP_ENDPOINTS = 12;

	/** Max distinct values offered per filter dropdown {@see log_facets()}. */
	const FACET_LIMIT = 200;

	/** Hard ceiling on stored rows — a backstop to the daily age-based prune so an
	 *  extreme-traffic day can't bank unbounded rows before the cron fires.
	 *  Generous; filter `agentimus_activity_max_rows` (0 disables the cap). */
	const MAX_ROWS = 50000;

	/** Review-queue option keys and TTL, kept here as aliases for callers and tests
	 *  that referenced them before the queue moved to {@see ReviewQueue}. */
	const DISMISS_OPTION  = ReviewQueue::DISMISS_OPTION;
	const REVERIFY_OPTION = ReviewQueue::REVERIFY_OPTION;
	const REVERIFY_TTL    = ReviewQueue::REVERIFY_TTL;

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
			// ⭐ The window's FIRST DAY, shipped beside the totals it produced. A
			// screen that links from one of those numbers has to filter the target
			// by the same span, and recomputing this date in the browser would put
			// the boundary in a second timezone. {@see Audience::from_stats()}
			'since'      => substr( $month, 0, 10 ),
			'today'      => gmdate( 'Y-m-d' ),
			'retention'  => self::retention_days(),
			'autoPrune'  => self::auto_prune(),
			'maxRows'    => self::max_rows(),
			'totals'     => array(
				'today'  => self::count_since( $today ),
				'week'   => self::count_since( $week ),
				'month'  => self::count_since( $month ),
				'all'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name (not user input); SQL identifiers can't be bound via prepare().
				'agents' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT agent) FROM $table WHERE refused = 0 AND hit_at >= %s", $month ) ), // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value is bound via prepare().
				// ⭐⭐ COUNTED HERE, BESIDE THE NUMBERS IT SITS NEXT TO — his rule,
				// 2026-08-21: one source of truth, no surface counting for itself.
				// This is the log, the same table, the same $month boundary and the
				// same instant as the three totals above, so the Machines card can
				// never again disagree with the Requests screen beneath it.
				// ⛔ It replaced a count taken from the REVIEW QUEUE, which is a
				// different population: the queue holds what still needs a decision,
				// so a dismissed impostor left it and the headline fell back to
				// zero — on his site, 11 forgeries in the window read as "0 caught
				// faking an identity". A queue is a to-do list; this is a record.
				'impostors' => self::count_since( $month, 2 ),
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
	/**
	 * Rows grouped by a whitelisted column over a window, busiest first.
	 *
	 * ⭐⭐ ONE DEFINITION OF "COUNT BY CLIENT", his rule 2026-08-21. The weekly
	 * digest used to carry its own copy of this query with its own window; two
	 * hand-written aggregates of the same thing agree only until one is edited.
	 * The dashboard's breakdown and the digest's now differ in ARGUMENTS — the
	 * window each asks about — and in nothing else.
	 *
	 * ⛔ refused = 0, like every read total: a turned-away request fetched
	 * nothing and must never inflate a client's hits. {@see count_between()} for
	 * the one deliberate exception (verdict counts, which DO include refusals).
	 *
	 * @param string $column 'agent' or 'endpoint' (anything else falls back to 'agent').
	 * @param string $from   GMT threshold, inclusive.
	 * @param int    $limit  Max rows.
	 * @param string $to     Optional GMT upper bound, exclusive. Open-ended when ''.
	 * @return array<int,array{label:string,hits:int}>
	 */
	public static function counts_by( $column, $from, $limit, $to = '' ) {
		global $wpdb;
		$table  = Table::name();
		$column = in_array( $column, array( 'agent', 'endpoint' ), true ) ? $column : 'agent';

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name and $column is whitelisted just above; SQL identifiers cannot be bound via prepare(), and every VALUE is.
		if ( '' === $to ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT $column AS label, COUNT(*) AS hits FROM $table WHERE refused = 0 AND hit_at >= %s GROUP BY $column ORDER BY hits DESC LIMIT %d", $from, $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT $column AS label, COUNT(*) AS hits FROM $table WHERE refused = 0 AND hit_at >= %s AND hit_at < %s GROUP BY $column ORDER BY hits DESC LIMIT %d", $from, $to, $limit ),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array_map(
			static function ( $r ) {
				return array(
					'label' => (string) $r['label'],
					'hits'  => (int) $r['hits'],
				);
			},
			(array) $rows
		);
	}

	private static function count_since( $threshold, $verdict = null ) {
		global $wpdb;
		$table = Table::name();
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; every value is bound via prepare().
		if ( null === $verdict ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE refused = 0 AND hit_at >= %s", $threshold ) );
		}
		// ⛔ SAME SPLIT AS count_between(), deliberately: a verdict count INCLUDES
		// refusals. A forgery that was turned away is exactly what that number
		// reports, and excluding it is the bug Guard::maybe_block() documents —
		// switching enforcement on used to make the site's own security signal
		// read zero.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE hit_at >= %s AND verdict = %d", $threshold, $verdict ) );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Count rows in a half-open GMT window [$from, $to). With no verdict, refused
	 * requests are excluded — a turned-away request fetched nothing, so it must never
	 * inflate "agent visits". A verdict filter (e.g. 2 = proven impostor) deliberately
	 * COUNTS refusals: a forgery turned away is exactly what that count reports. The
	 * refused-vs-not convention lives here with the table, not in each caller.
	 *
	 * @param string   $from    GMT datetime, inclusive.
	 * @param string   $to      GMT datetime, exclusive.
	 * @param int|null $verdict Verdict filter, or null for reads only.
	 * @return int
	 */
	public static function count_between( $from, $to, $verdict = null ) {
		global $wpdb;
		$table = Table::name();
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; every value is bound via prepare().
		if ( null === $verdict ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE refused = 0 AND hit_at >= %s AND hit_at < %s", $from, $to ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE hit_at >= %s AND hit_at < %s AND verdict = %d", $from, $to, $verdict ) );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
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

		$rows = self::counts_by( $column, $since, $limit );
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table and $column is whitelisted just above; SQL identifiers can't be bound via prepare(), only the values ($start), which are.
		// One bounded pass (distinct labels x days) for every label's per-day counts; the
		// top-N are picked out below, aligned to a gap-filled day list.
		$series_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT $column AS label, DATE(hit_at) AS d, COUNT(*) AS c FROM $table WHERE refused = 0 AND hit_at >= %s GROUP BY $column, DATE(hit_at)", $start ),
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
				// The two half-window sums the trend is computed FROM ride along,
				// so the admin's tooltip can explain the arrow with real numbers
				// instead of leaving a bare percentage to be guessed at.
				$n    = count( $series );
				$half = intdiv( $n, 2 );
				return array(
					'label'   => $label,
					'hits'    => (int) $r['hits'],
					'trend'   => self::trend_pct( $series ),
					'earlier' => array_sum( array_slice( $series, 0, $half ) ),
					'recent'  => array_sum( array_slice( $series, $n - $half ) ),
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
			$wpdb->prepare( "SELECT DATE(hit_at) AS d, COUNT(*) AS c FROM $table WHERE refused = 0 AND hit_at >= %s GROUP BY DATE(hit_at)", $since ),
			OBJECT_K
		);
		// Per-day breakdowns, each ordered by count DESC across all days — so the
		// first rows bucketed into a given day are that day's busiest (a global
		// DESC sort preserves the order within each day's subgroup too).
		$client_rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(hit_at) AS d, agent AS label, COUNT(*) AS c FROM $table WHERE refused = 0 AND hit_at >= %s GROUP BY DATE(hit_at), agent ORDER BY c DESC", $since ),
			ARRAY_A
		);
		$endpoint_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(hit_at) AS d, endpoint AS label, COUNT(*) AS c FROM $table WHERE refused = 0 AND hit_at >= %s GROUP BY DATE(hit_at), endpoint ORDER BY c DESC", $since ),
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
			$wpdb->prepare( "SELECT endpoint, agent, ua, network, verdict, hit_at FROM $table WHERE refused = 0 ORDER BY id DESC LIMIT %d", $limit ),
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
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE refused = 0 AND hit_at >= %s AND hit_at < %s", $start, $end )
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT endpoint, agent, ua, network, verdict, hit_at FROM $table WHERE refused = 0 AND hit_at >= %s AND hit_at < %s ORDER BY id DESC LIMIT %d", $start, $end, $limit ),
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
			// Every operator that has ever signed to this site. Usually empty —
			// almost nothing signs yet — and that emptiness is itself the answer
			// the filter's "Any signature" option exists to deliver.
			'signers'   => $distinct( 'signer' ),
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
			// Web Bot Auth face: with verdict 1, WHO the signature proves ("OpenAI agent");
			// with verdict 0, WHO signed validly but is not an operator this site
			// recognises ("agents.acme.example") — proof of identity that earns no
			// standing, which is still worth showing; with verdict 2, WHO the failed
			// signature claimed ("chatgpt.com"). '' = no signature was involved at all
			// and the verdict came from DNS/ranges (or nothing).
			'signer'   => isset( $r['signer'] ) ? (string) $r['signer'] : '',
			// TRUE = turned away at the door, never served. Counts toward no read total;
			// the row exists so a proven impostor can't be refused in silence.
			'refused'  => ! empty( $r['refused'] ),
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
	 * @param array $args from, to (Y-m-d), agent, endpoint, network, verdict, signer
	 *                    ('*' = signed by anyone), ua, before, per_page.
	 * @return array{rows:array,total:int,perPage:int,cursor:?int,hasMore:bool,retentionDays:int,autoPrune:bool,maxRows:int,verifyOn:bool,identifyOn:bool}
	 */
	/**
	 * One filter value, or several, as a clean list.
	 *
	 * ⭐ ONE PLACE THAT DECIDES WHAT "SEVERAL" MEANS, so the REST layer, the MCP
	 * ability and any future caller cannot disagree about it. Accepts an array or
	 * a comma-separated string (what a query string can carry), trims each part,
	 * drops the empties and de-duplicates — a picker that sends the same value
	 * twice must not widen the SQL by a redundant OR.
	 *
	 * ⚠️ PUBLIC because it is the contract, not a detail: it is what every caller
	 * means by "several", and it is pinned by tests so the meaning cannot drift.
	 * Pure — no DB — like {@see trend_pct()}.
	 *
	 * @param mixed $value Array, comma-separated string, or scalar.
	 * @return array<int,string> Values, in the order given, never containing ''.
	 */
	public static function as_list( $value ) {
		if ( is_array( $value ) ) {
			$parts = $value;
		} elseif ( is_scalar( $value ) ) {
			$parts = explode( ',', (string) $value );
		} else {
			return array();
		}
		$out = array();
		foreach ( $parts as $p ) {
			if ( ! is_scalar( $p ) ) {
				continue;
			}
			$p = trim( (string) $p );
			if ( '' !== $p && ! in_array( $p, $out, true ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

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

		// ⭐⭐ EVERY VALUE FILTER TAKES A LIST — his call, 2026-08-21: one choice per
		// control "is not very flexible". A single value still works and still
		// compiles to `col = %s`; a list compiles to IN (...). ⛔ An empty list is
		// NO filter, never `IN ()` — which is a syntax error in MySQL and would
		// have turned "clear the picker" into a 500.
		foreach ( array( 'agent', 'endpoint', 'network' ) as $col ) {
			$vals = self::as_list( isset( $args[ $col ] ) ? $args[ $col ] : '' );
			if ( ! $vals ) {
				continue;
			}
			if ( 1 === count( $vals ) ) {
				$where[]  = "$col = %s";
				$params[] = $vals[0];
			} else {
				$where[] = "$col IN (" . implode( ', ', array_fill( 0, count( $vals ), '%s' ) ) . ')';
				$params  = array_merge( $params, $vals );
			}
		}
		// 'refused' is an OUTCOME, not a verdict value — it shares this control
		// because "was this client turned away?" is the same question the
		// verification filter answers, asked one step further along.
		// ⚠️ So a multi-pick here can mix the two: "Spoofed OR Refused" is a
		// perfectly ordinary thing to ask, and they live in different columns.
		// The parts are OR'd inside one bracketed group, which then AND's with
		// every other filter — pick two verifications and you widen that control,
		// you do not widen the query.
		$verdicts = self::as_list( isset( $args['verdict'] ) ? $args['verdict'] : '' );
		if ( $verdicts ) {
			$parts = array();
			$ints  = array();
			foreach ( $verdicts as $v ) {
				if ( 'refused' === $v ) {
					$parts[] = 'refused = 1';
					continue;
				}
				if ( is_numeric( $v ) ) {
					$ints[] = (int) $v;
				}
			}
			if ( $ints ) {
				$ints    = array_values( array_unique( $ints ) );
				$parts[] = 1 === count( $ints )
					? 'verdict = %d'
					: 'verdict IN (' . implode( ', ', array_fill( 0, count( $ints ), '%d' ) ) . ')';
				$params  = array_merge( $params, $ints );
			}
			if ( $parts ) {
				$where[] = '(' . implode( ' OR ', $parts ) . ')';
			}
		}
		// ⭐⭐ THE SIGNATURE FILTER, AND ITS WILDCARD. The first question an owner
		// asks about Web Bot Auth is "has ANYTHING signed to me yet?" — and no list
		// of exact names can ask it, because you cannot pick the name of an
		// operator you have never seen. `*` means "carried a signature at all";
		// exact values narrow to one signer. The two mix inside one OR'd group,
		// exactly like verdict/refused above, so picking more widens this control
		// and never the query.
		// ⛔ `*` cannot collide with a stored value: a signer is a bare host or a
		// catalog label, and neither can ever be a lone asterisk.
		$signers = self::as_list( isset( $args['signer'] ) ? $args['signer'] : '' );
		if ( $signers ) {
			$parts = array();
			$names = array();
			foreach ( $signers as $s ) {
				if ( '*' === $s ) {
					$parts[] = "signer <> ''";
					continue;
				}
				$names[] = $s;
			}
			if ( $names ) {
				$names   = array_values( array_unique( $names ) );
				$parts[] = 1 === count( $names )
					? 'signer = %s'
					: 'signer IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')';
				$params  = array_merge( $params, $names );
			}
			if ( $parts ) {
				$where[] = '(' . implode( ' OR ', $parts ) . ')';
			}
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

		// ⭐⭐ SORTING, AND THE PAGING THAT FOLLOWS IT — his call, 2026-08-21:
		// "sortable all". The default (newest first) keeps KEYSET paging: `id <
		// cursor` is exact, never drifts when rows arrive mid-read, and never
		// makes the database count past what it skips.
		// ⛔ A keyset cursor cannot survive a different sort — `id < n` says
		// nothing about where you were in a list ordered by agent — so any other
		// column pages by OFFSET instead. The mode follows the sort rather than
		// the two being configured apart, because a cursor carried into a sorted
		// query is the kind of bug that looks like "some rows are missing".
		// ⚠️ Every sorted query still ends `, id DESC`: without a tiebreak, two
		// rows from the same client land in an order MySQL is free to change
		// between pages, which drops or repeats rows across a page boundary.
		$sortable = array(
			'at'       => 'hit_at',
			'client'   => 'agent',
			'endpoint' => 'endpoint',
			'ua'       => 'ua',
			// Status is two columns on screen — refused first, then the verdict —
			// so it is two columns in the ORDER BY, in that reading order.
			'status'   => 'refused, verdict',
			'network'  => 'network',
		);
		$sort_by  = isset( $args['orderby'] ) && isset( $sortable[ $args['orderby'] ] ) ? (string) $args['orderby'] : 'at';
		$sort_dir = ( isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'ASC' : 'DESC';
		// An EXPLICIT offset opts out of keyset even on the default sort — the
		// numbered pager jumps to "page 7", which no cursor can address. Keyset
		// stays for cursor callers (MCP walks by `before`) and for page one,
		// where the two modes return the same rows anyway.
		$offset_req = max( 0, (int) ( isset( $args['offset'] ) ? $args['offset'] : 0 ) );
		$keyset     = ( 'at' === $sort_by && 'DESC' === $sort_dir && 0 === $offset_req );
		$offset     = $keyset ? 0 : $offset_req;

		if ( $keyset ) {
			$order_sql = 'id DESC';
			$tail_sql  = 'LIMIT %d';
			$tail_args = array( $per_page + 1 );
		} else {
			$cols      = explode( ',', $sortable[ $sort_by ] );
			$order_sql = implode( ', ', array_map( static function ( $c ) use ( $sort_dir ) {
				return trim( $c ) . ' ' . $sort_dir;
			}, $cols ) ) . ', id DESC';
			$tail_sql  = 'LIMIT %d OFFSET %d';
			$tail_args = array( $per_page + 1, $offset );
			// An offset page is cut from the FILTERED set, never from the cursor's
			// narrowed one — $page_sql adds `id < before`, which has no meaning here.
			$page_sql  = $filter_sql;
			$params    = $filter_params;
		}

		// Fetch one extra row to learn whether another page exists, without a second query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, endpoint, agent, ua, network, verdict, signer, refused, hit_at FROM $table WHERE $page_sql ORDER BY $order_sql $tail_sql",
				array_merge( $params, $tail_args )
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
			// ⛔ A cursor ONLY in keyset mode. Handing one back from a sorted page
			// would invite the caller to page with a value that cannot locate it.
			'cursor'        => ( $keyset && $has_more && $last ) ? (int) $last['id'] : null,
			'hasMore'       => $has_more,
			// What the caller asked for, echoed back so a screen can render its
			// own header state from the answer rather than from what it hoped.
			'sort'          => array( 'by' => $sort_by, 'dir' => strtolower( $sort_dir ) ),
			'offset'        => $offset,
			// So the UI can say what it cannot show. `autoPrune` decides whether
			// `retentionDays` is a deletion horizon at all, or merely the setting's value.
			'retentionDays' => $days,
			'autoPrune'     => self::auto_prune(),
			'maxRows'       => self::max_rows(),
			// So the Status column can explain a dash HONESTLY: "nothing to check
			// here" and "checking is switched off" are different facts.
			'verifyOn'      => Guard::verification_on(),
			// Same reason: "not looked up" and "looked up, nothing to attribute" are
			// different facts, and the dash must not blur them.
			'identifyOn'    => (bool) ( new Settings() )->enabled( 'identify_bots' ),
			// ⭐ So the screen can say WHICH SITE it is describing. With page views off
			// the log covers the generated files only, and "no crawler read this
			// article" would be a conclusion drawn from a question never asked.
			'pageViewsOn'   => (bool) ( new Settings() )->enabled( 'log_page_views' ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Review queue — thin delegators to the extracted {@see ReviewQueue}.
	 * The historical Repository:: names stay so callers and tests are
	 * untouched; the suspicious-source analysis and the dismissal / re-check
	 * overlays now live in their own class.
	 * ------------------------------------------------------------------ */

	/** @see ReviewQueue::threats() */
	public static function threats( Settings $settings ) {
		return ReviewQueue::threats( $settings );
	}

	/** @see ReviewQueue::analyze_threats() */
	public static function analyze_threats( array $sources, array $recent, $now, array $opts ) {
		return ReviewQueue::analyze_threats( $sources, $recent, $now, $opts );
	}

	/** @see ReviewQueue::dismiss_key() */
	public static function dismiss_key( $ua ) {
		return ReviewQueue::dismiss_key( $ua );
	}

	/** @see ReviewQueue::dismissed_map() */
	public static function dismissed_map() {
		return ReviewQueue::dismissed_map();
	}

	/** @see ReviewQueue::dismissals() */
	public static function dismissals() {
		return ReviewQueue::dismissals();
	}

	/** @see ReviewQueue::undismiss() */
	public static function undismiss( $key ) {
		return ReviewQueue::undismiss( $key );
	}

	/** @see ReviewQueue::reverified_map() */
	public static function reverified_map() {
		return ReviewQueue::reverified_map();
	}

	/** @see ReviewQueue::record_reverify() */
	public static function record_reverify( $ua, $verdict ) {
		return ReviewQueue::record_reverify( $ua, $verdict );
	}

	/** @see ReviewQueue::dismiss() */
	public static function dismiss( $ua, $hits ) {
		return ReviewQueue::dismiss( $ua, $hits );
	}

	/** @see ReviewQueue::prune_reverified() */
	public static function prune_reverified() {
		return ReviewQueue::prune_reverified();
	}

	/** @see ReviewQueue::prune_dismissed() */
	public static function prune_dismissed() {
		return ReviewQueue::prune_dismissed();
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
		ReviewQueue::forget(); // Re-checks and dismissals judge the same history — clear them with it.
		FlaggedIps::clear(); // The captured IPs are judged against this history — clear them with it.
		ReviewBadge::forget(); // An emptied log empties the queue — the menu badge recounts.
	}
}
