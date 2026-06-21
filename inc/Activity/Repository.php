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

	/** Reporting window for aggregates. */
	const WINDOW_DAYS = 30;

	/** Sparkline span. */
	const DAILY_DAYS = 14;

	/** A source first seen within this many hours is flagged "new". */
	const NEW_AGENT_HOURS = 48;

	/** Hits in the last hour at/above this count flag a "heavy" (burst) source. */
	const BURST_MIN_HITS = 30;

	/** Hits over the whole window at/above this count also flag "heavy" (sustained). */
	const HEAVY_MIN_HITS = 500;

	/** Max suspicious sources returned to the panel. */
	const THREATS_LIMIT = 12;

	/**
	 * Assemble the dashboard payload.
	 *
	 * @param Settings $settings Settings store.
	 * @return array
	 */
	public static function stats( Settings $settings ) {
		global $wpdb;
		$table = Table::name();

		$today = gmdate( 'Y-m-d 00:00:00' );
		$week  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$month = gmdate( 'Y-m-d H:i:s', time() - self::WINDOW_DAYS * DAY_IN_SECONDS );

		return array(
			'enabled'    => (bool) $settings->enabled( 'enable_activity' ),
			'window'     => self::WINDOW_DAYS,
			'totals'     => array(
				'today'  => self::count_since( $today ),
				'week'   => self::count_since( $week ),
				'month'  => self::count_since( $month ),
				'all'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name (not user input); SQL identifiers can't be bound via prepare().
				'agents' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT agent) FROM $table WHERE hit_at >= %s", $month ) ), // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value is bound via prepare().
			),
			'byAgent'    => self::group_counts( 'agent', $month, 8 ),
			'byEndpoint' => self::group_counts( 'endpoint', $month, 12 ),
			'daily'      => self::daily(),
			'recent'     => self::recent( 50 ),
			'threats'    => self::threats( $settings ),
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
	 * Hits per day for the sparkline, gap-filled so every day is present.
	 *
	 * @return array<int,array{date:string,hits:int}>
	 */
	private static function daily() {
		global $wpdb;
		$table = Table::name();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( self::DAILY_DAYS - 1 ) * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value ($since) is bound via prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT DATE(hit_at) AS d, COUNT(*) AS c FROM $table WHERE hit_at >= %s GROUP BY DATE(hit_at)", $since ),
			OBJECT_K
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$out = array();
		for ( $i = self::DAILY_DAYS - 1; $i >= 0; $i-- ) {
			$date  = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$out[] = array( 'date' => $date, 'hits' => isset( $rows[ $date ] ) ? (int) $rows[ $date ]->c : 0 );
		}
		return $out;
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
			$ua    = isset( $s['ua'] ) ? (string) $s['ua'] : '';
			$hits  = isset( $s['hits'] ) ? (int) $s['hits'] : 0;
			$first = isset( $s['first_seen'] ) ? strtotime( $s['first_seen'] . ' UTC' ) : 0;
			$last  = isset( $s['last_seen'] ) ? strtotime( $s['last_seen'] . ' UTC' ) : 0;
			$rec   = isset( $recent[ $ua ] ) ? (int) $recent[ $ua ] : 0;

			$is_new   = $first > 0 && ( $now - $first ) <= $new_secs;
			$is_heavy = $rec >= $burst_min || $hits >= $heavy_min;
			$is_spoof = Classifier::is_spoof( $ua );

			if ( ! $is_new && ! $is_heavy && ! $is_spoof ) {
				continue; // Not suspicious — skip.
			}
			if ( $is_new ) {
				++$counts['new'];
			}
			if ( $is_heavy ) {
				++$counts['heavy'];
			}
			if ( $is_spoof ) {
				++$counts['spoof'];
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

			$out[] = array(
				'ua'        => substr( $ua, 0, 255 ),
				'agent'     => isset( $s['agent'] ) ? (string) $s['agent'] : '',
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

		// Rank: most severe first, then by raw volume.
		usort(
			$out,
			static function ( $a, $b ) {
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
	 * Delete rows older than the (filterable) retention window. Scheduled daily.
	 */
	public static function prune() {
		global $wpdb;
		$table = Table::name();

		/**
		 * Filter the activity-log retention in days.
		 *
		 * @param int $days Default 30.
		 */
		$days   = (int) apply_filters( 'agentimus_activity_retention_days', self::WINDOW_DAYS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE hit_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived table name; the value is bound via prepare().
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
