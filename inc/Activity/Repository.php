<?php
/**
 * Repository — the read/maintenance side of the activity log: dashboard stats,
 * retention pruning and clearing. All timestamps are stored/queried in GMT;
 * the UI renders relative times client-side, so there's no timezone ambiguity.
 *
 * @package Agentomatic
 */

namespace Agentomatic\Activity;

use Agentomatic\Settings;

defined( 'ABSPATH' ) || exit;

final class Repository {

	/** Reporting window for aggregates. */
	const WINDOW_DAYS = 30;

	/** Sparkline span. */
	const DAILY_DAYS = 14;

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
		$days   = (int) apply_filters( 'agentomatic_activity_retention_days', self::WINDOW_DAYS );
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
