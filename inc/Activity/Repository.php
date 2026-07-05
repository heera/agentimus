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

	/** Default rows in each dashboard breakdown; each filterable (see stats()). */
	const TOP_CLIENTS   = 8;
	const TOP_ENDPOINTS = 12;
	const TOP_PAGES     = 12;

	/** Hard ceiling on stored rows — a backstop to the daily age-based prune so an
	 *  extreme-traffic day can't bank unbounded rows before the cron fires.
	 *  Generous; filter `agentimus_activity_max_rows` (0 disables the cap). */
	const MAX_ROWS = 50000;

	/**
	 * Days of activity kept — and therefore reported on. Filterable; defaults to
	 * {@see WINDOW_DAYS}. The daily chart, the aggregate window and the prune cutoff
	 * all derive from this, so "what you see" always equals "what's retained".
	 *
	 * @return int
	 */
	private static function retention_days() {
		/**
		 * Filter the activity-log retention, in days. Governs the daily chart span,
		 * the aggregate reporting window, and the prune cutoff.
		 *
		 * @param int $days Default 30.
		 */
		$days = (int) apply_filters( 'agentimus_activity_retention_days', self::WINDOW_DAYS );
		return max( 1, $days );
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

		$window = self::retention_days();
		$today  = gmdate( 'Y-m-d 00:00:00' );
		$week   = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$month  = gmdate( 'Y-m-d H:i:s', time() - $window * DAY_IN_SECONDS );

		return array(
			'enabled'    => (bool) $settings->enabled( 'enable_activity' ),
			'window'     => $window,
			'totals'     => array(
				'today'  => self::count_since( $today ),
				'week'   => self::count_since( $week ),
				'month'  => self::count_since( $month ),
				'all'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name (not user input); SQL identifiers can't be bound via prepare().
				'agents' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT agent) FROM $table WHERE hit_at >= %s", $month ) ), // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value is bound via prepare().
			),
			// Rows in each breakdown are filterable (a site may want more or fewer),
			// clamped to a sane 1–200: agentimus_activity_{clients,endpoints,pages}_limit.
			'byAgent'      => self::group_counts( 'agent', $month, self::clamp_limit( apply_filters( 'agentimus_activity_clients_limit', self::TOP_CLIENTS ) ) ),
			'byEndpoint'   => self::group_counts( 'endpoint', $month, self::clamp_limit( apply_filters( 'agentimus_activity_endpoints_limit', self::TOP_ENDPOINTS ) ) ),
			'byPage'       => self::top_pages( $month, self::clamp_limit( apply_filters( 'agentimus_activity_pages_limit', self::TOP_PAGES ) ) ),
			// Whether per-page hits can accrue at all — they only come from the Markdown
			// twin endpoint. The UI hides the "Top pages" card when this is false AND there
			// is no history (a permanently-empty card), but still shows existing data.
			'pagesTracked' => (bool) $settings->enabled( 'enable_markdown' ),
			'daily'        => self::daily(),
			'recent'       => self::recent( 50 ),
			'threats'      => self::threats( $settings ),
			'referrals'    => Referrals::summary( $window ),
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
	 * Top counts grouped by a column over the window.
	 *
	 * @param string $column 'agent' or 'endpoint' (whitelisted).
	 * @param string $since  GMT threshold.
	 * @param int    $limit  Max rows.
	 * @return array<int,array{label:string,hits:int}>
	 */
	private static function group_counts( $column, $since, $limit ) {
		global $wpdb;
		$table  = Table::name();
		$column = in_array( $column, array( 'agent', 'endpoint' ), true ) ? $column : 'agent';
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table and $column is whitelisted just above; SQL identifiers can't be bound via prepare(), only the values ($since/$limit), which are.
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT $column AS label, COUNT(*) AS hits FROM $table WHERE hit_at >= %s GROUP BY $column ORDER BY hits DESC LIMIT %d", $since, $limit ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array_map(
			static function ( $r ) {
				return array( 'label' => (string) $r['label'], 'hits' => (int) $r['hits'] );
			},
			(array) $rows
		);
	}

	/**
	 * The pages that AI agents fetched most (the per-post .md twin) over the window, with
	 * each page's busiest single agent — the "which page, how many hits, from whom" report.
	 * Rows with post_id 0 (llms.txt, the markdown index, discovery docs) are non-page
	 * endpoints and excluded. Title/URL are resolved live; a since-deleted post is kept
	 * (its hits are real history) and labelled as removed. No IP is involved — post + agent
	 * + count only.
	 *
	 * @param string $since GMT threshold (reporting window start).
	 * @param int    $limit Max pages.
	 * @return array<int,array{id:int,title:string,url:?string,hits:int,topAgent:?array{label:string,hits:int}}>
	 */
	public static function top_pages( $since, $limit = 12 ) {
		global $wpdb;
		$table = Table::name();
		$limit = max( 1, min( 100, (int) $limit ) );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; values ($since/$limit) are bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, COUNT(*) AS hits FROM $table WHERE post_id > 0 AND hit_at >= %s GROUP BY post_id ORDER BY hits DESC LIMIT %d", $since, $limit ),
			ARRAY_A
		);
		// Busiest agent per page: a count-DESC per-(page,agent) rollup, folded so the first
		// row seen for a page is its top agent (mirrors the daily breakdown pattern).
		$agent_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, agent, COUNT(*) AS c FROM $table WHERE post_id > 0 AND hit_at >= %s GROUP BY post_id, agent ORDER BY c DESC", $since ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$top_agent = array();
		foreach ( (array) $agent_rows as $r ) {
			$pid = (int) $r['post_id'];
			if ( ! isset( $top_agent[ $pid ] ) ) {
				$top_agent[ $pid ] = array( 'label' => (string) $r['agent'], 'hits' => (int) $r['c'] );
			}
		}

		$out = array();
		foreach ( (array) $rows as $r ) {
			$pid   = (int) $r['post_id'];
			$title = get_the_title( $pid );
			$url   = get_permalink( $pid );
			$out[] = array(
				'id'       => $pid,
				/* translators: %d: numeric ID of a page that has since been deleted. */
				'title'    => '' !== $title ? $title : sprintf( __( '#%d (removed)', 'agentimus' ), $pid ),
				'url'      => is_string( $url ) ? $url : null,
				'hits'     => (int) $r['hits'],
				'topAgent' => isset( $top_agent[ $pid ] ) ? $top_agent[ $pid ] : null,
			);
		}
		return $out;
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
		$days  = self::retention_days();
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
	 * @return array<int,array{endpoint:string,agent:string,ua:string,at:string}>
	 */
	private static function recent( $limit ) {
		global $wpdb;
		$table = Table::name();
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value ($limit) is bound via prepare().
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT endpoint, agent, ua, hit_at FROM $table ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return array_map(
			static function ( $r ) {
				return array(
					'endpoint' => (string) $r['endpoint'],
					'agent'    => (string) $r['agent'],
					'ua'       => (string) $r['ua'],
					'at'       => gmdate( 'c', strtotime( $r['hit_at'] . ' UTC' ) ), // ISO-8601 for client-side relative time.
				);
			},
			(array) $rows
		);
	}

	/**
	 * Every hit recorded on a given GMT calendar date, newest first and capped —
	 * the *full* day, not the recent window {@see recent()} is limited to. Powers
	 * the dashboard's per-day "View requests" modal.
	 *
	 * @param string $date  GMT date, 'Y-m-d'.
	 * @param int    $limit Max rows to return.
	 * @return array{date:string,total:int,rows:array<int,array{endpoint:string,agent:string,ua:string,at:string}>,capped:bool}
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
			$wpdb->prepare( "SELECT endpoint, agent, ua, hit_at FROM $table WHERE hit_at >= %s AND hit_at < %s ORDER BY id DESC LIMIT %d", $start, $end, $limit ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = array_map(
			static function ( $r ) {
				return array(
					'endpoint' => (string) $r['endpoint'],
					'agent'    => (string) $r['agent'],
					'ua'       => (string) $r['ua'],
					'at'       => gmdate( 'c', strtotime( $r['hit_at'] . ' UTC' ) ),
				);
			},
			(array) $rows
		);

		return array(
			'date'   => (string) $date,
			'total'  => $total,
			'rows'   => $out,
			'capped' => $total > count( $out ),
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
		$since = gmdate( 'Y-m-d H:i:s', $now - self::WINDOW_DAYS * DAY_IN_SECONDS );
		$hour  = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );

		// One row per distinct UA over the window. MAX(agent) is unambiguous: the
		// agent label is a pure function of the UA, so every row in a UA-group shares
		// it. Bounded to the 200 busiest sources — far more than a content site sees,
		// and the pure pass only keeps the flagged ones anyway.
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; values are bound via prepare().
		$sources = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ua, MAX(agent) AS agent, COUNT(*) AS hits, MIN(hit_at) AS first_seen, MAX(hit_at) AS last_seen FROM $table WHERE hit_at >= %s GROUP BY ua ORDER BY hits DESC LIMIT 200",
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

		return self::analyze_threats(
			(array) $sources,
			$recent,
			$now,
			array(
				'blockingOn' => (bool) $settings->enabled( 'block_agents' ),
				'newSecs'    => (int) apply_filters( 'agentimus_new_agent_seconds', self::NEW_AGENT_HOURS * HOUR_IN_SECONDS ),
				'burstMin'   => (int) apply_filters( 'agentimus_burst_min_hits', self::BURST_MIN_HITS ),
				'heavyMin'   => (int) apply_filters( 'agentimus_heavy_min_hits', self::HEAVY_MIN_HITS ),
				'limit'      => (int) apply_filters( 'agentimus_threats_limit', self::THREATS_LIMIT ),
			)
		);
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

		$out    = array();
		$counts = array( 'new' => 0, 'heavy' => 0, 'spoof' => 0 );

		foreach ( $sources as $s ) {
			$ua = isset( $s['ua'] ) ? (string) $s['ua'] : '';

			// A protected/allow-listed search engine (Googlebot, Bingbot…) is trusted
			// by definition — never denied, never "suspicious". Keep it out entirely.
			if ( '' !== $ua && Guard::is_protected_ua( $ua ) ) {
				continue;
			}

			$hits  = isset( $s['hits'] ) ? (int) $s['hits'] : 0;
			$first = isset( $s['first_seen'] ) ? strtotime( $s['first_seen'] . ' UTC' ) : 0;
			$last  = isset( $s['last_seen'] ) ? strtotime( $s['last_seen'] . ' UTC' ) : 0;
			$rec   = isset( $recent[ $ua ] ) ? (int) $recent[ $ua ] : 0;

			$is_new   = $first > 0 && ( $now - $first ) <= $new_secs;
			$is_heavy = $rec >= $burst_min || $hits >= $heavy_min;
			$is_spoof = Classifier::is_spoof( $ua );

			if ( ! $is_new && ! $is_heavy && ! $is_spoof ) {
				continue; // Nothing flags it.
			}

			$blocked  = Guard::denies( $ua );
			$token    = $blocked ? '' : Guard::suggest_token( $ua );
			$severity = ( $is_spoof ? 4 : 0 ) + ( $is_heavy ? 2 : 0 ) + ( $is_new ? 1 : 0 );

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
			} else {
				$reason = '' === trim( $ua ) ? 'no-ua' : 'no-token';
			}

			// A "new"-only source we can neither block nor flag as spoof/heavy is just
			// noise here (a one-off new browser/script). Show only genuinely suspicious
			// (spoof/heavy) or actionable / already-blocked rows. (Counted post-merge.)
			if ( ! $is_spoof && ! $is_heavy && ! $blocked && '' === $action ) {
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
			);
		}

		// Collapse UA variants of one client: two user-agents that resolve to the same
		// block token (a version bump, a parenthetical comment…) are one decision —
		// one Block denies both — so listing them separately implies two. Fold matching
		// actionable rows into one (summed volume, widest first/last window, OR'd flags),
		// keeping the most-recent UA as the face of the row.
		$out = self::merge_token_variants( $out );

		// Count only what finally shows (post-merge) so the chips and badge match the
		// rows the owner actually sees.
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
		foreach ( array( 'new', 'heavy', 'spoof' ) as $flag ) {
			$keep['flags'][ $flag ] = $keep['flags'][ $flag ] || $add['flags'][ $flag ];
		}
		// ISO-8601 with a fixed +00:00 offset sorts lexically = chronologically.
		if ( '' !== $add['firstSeen'] && ( '' === $keep['firstSeen'] || $add['firstSeen'] < $keep['firstSeen'] ) ) {
			$keep['firstSeen'] = $add['firstSeen'];
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
	 * Delete rows older than the (filterable) retention window. Scheduled daily.
	 */
	public static function prune() {
		global $wpdb;
		$table  = Table::name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE hit_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value is bound via prepare().
	}

	/**
	 * Cap the table to the newest {@see MAX_ROWS} rows — a backstop to the daily,
	 * age-based {@see prune()} so an extreme-traffic day can't bank unbounded rows
	 * before the cron fires. Called opportunistically (sampled) from the insert
	 * path, so it costs nothing on the common request. Filterable; a cap of 0
	 * disables it.
	 */
	public static function trim_to_cap() {
		$max = (int) apply_filters( 'agentimus_activity_max_rows', self::MAX_ROWS );
		if ( $max < 1 ) {
			return; // Cap disabled.
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
	 * Empty the log.
	 */
	public static function clear() {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- admin-gated truncation of our own prefix-derived table.
	}
}
