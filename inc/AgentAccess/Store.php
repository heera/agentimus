<?php
/**
 * Store — reads and writes the Agent Access event rollup.
 *
 * The only subtle part is {@see record()}: it must tell the caller whether an event is NEW,
 * because "new" is what we alert on. A credential using an ability for the hundredth time is
 * a receipt, not news, and re-alerting on it is exactly the alert fatigue that makes owners
 * mute a security feature and then miss the one notification that mattered.
 *
 * @package Agentimus
 */

namespace Agentimus\AgentAccess;

defined( 'ABSPATH' ) || exit;

final class Store {

	/** Days an event is kept. This table is tiny by design, so we can afford a long memory. */
	const RETENTION_DAYS = 90;

	/** Hard backstop on total rows, regardless of retention. */
	const MAX_ROWS = 5000;

	/** Rows returned to the admin screen by default. */
	const DEFAULT_LIMIT = 100;

	/**
	 * Record one event. Upserts: a repeat of the same (kind, user, credential, subject) bumps
	 * `hits` and `last_at` rather than inserting a second row.
	 *
	 * @param string $kind    An Events::KIND_* constant.
	 * @param int    $user_id User the credential belongs to.
	 * @param string $cred    Application-password UUID (never the password), or ''.
	 * @param string $subject Ability name, or the application password's label.
	 * @param string $detail  Optional short note.
	 * @return bool TRUE when this is the FIRST time we have seen this event (i.e. worth alerting on).
	 */
	public static function record( $kind, $user_id, $cred = '', $subject = '', $detail = '' ) {
		$kind = (string) $kind;
		if ( ! Events::is_kind( $kind ) ) {
			return false;
		}

		$user_id = (int) $user_id;
		$cred    = substr( (string) $cred, 0, 64 );
		$subject = substr( (string) $subject, 0, 191 );
		$detail  = substr( (string) $detail, 0, 191 );

		global $wpdb;
		$table = self::name();
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (kind, user_id, cred, subject, detail, hits, first_at, last_at, seen)
				 VALUES (%s, %d, %s, %s, %s, 1, %s, %s, 0)
				 ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = VALUES(last_at)",
				$kind,
				$user_id,
				$cred,
				$subject,
				$detail,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// MySQL reports 1 affected row for a genuine INSERT and 2 for an ON DUPLICATE KEY UPDATE
		// that changed something. That difference is the whole point: 1 means "first time", which
		// is what the bell reacts to. Note the update path deliberately leaves `seen` alone, so a
		// row that merely accrues hits never re-alerts.
		$is_new = ( 1 === (int) $affected );

		if ( $is_new && 1 === wp_rand( 1, 20 ) ) {
			self::trim_to_cap();
		}

		return $is_new;
	}

	/**
	 * One page of events, newest first — a CURSOR walk, matching the request log.
	 *
	 * Ordered by `id`, not `last_at`. This is a rollup table, so `last_at` MOVES when a row's hit
	 * count increments — order by it and a row can hop between pages while the owner is walking
	 * them, which is how you miss a row or see it twice. `id` is immutable, so the walk is stable.
	 * It is also the truer order for this screen: a row only ever becomes UNREAD on insert, never
	 * on a rollup, so the alerting is already insertion-ordered. (`last_at` is still shown in its
	 * own column — nothing is hidden, it just doesn't drive the sort.)
	 *
	 * @param int         $limit  Rows per page.
	 * @param string|null $kind   Optional kind filter.
	 * @param int         $before Cursor: return rows with an id BELOW this (0 = first page).
	 * @return array{events:array[],hasMore:bool,cursor:int|null}
	 */
	public static function page( $limit = self::DEFAULT_LIMIT, $kind = null, $before = 0 ) {
		global $wpdb;
		$table  = self::name();
		$limit  = max( 1, min( 500, (int) $limit ) );
		$before = max( 0, (int) $before );

		$where  = array();
		$params = array();
		if ( null !== $kind && Events::is_kind( $kind ) ) {
			$where[]  = 'kind = %s';
			$params[] = (string) $kind;
		}
		if ( $before > 0 ) {
			$where[]  = 'id < %d';
			$params[] = $before;
		}
		$sql = "SELECT * FROM $table";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		// Fetch one MORE than asked: its presence is how we know another page exists, without a
		// second COUNT query.
		$sql     .= ' ORDER BY id DESC LIMIT %d';
		$params[] = $limit + 1;

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; every value is bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$rows     = (array) $rows;
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$events = array_map( array( Events::class, 'shape' ), $rows );
		$last   = end( $events );

		return array(
			'events'  => $events,
			'hasMore' => $has_more,
			'cursor'  => ( $last && ! empty( $last['id'] ) ) ? (int) $last['id'] : null,
		);
	}

	/**
	 * The most recent events, newest first. Thin wrapper over {@see page()} for callers that
	 * only want the rows.
	 *
	 * @param int         $limit Max rows.
	 * @param string|null $kind  Optional kind filter.
	 * @return array[] Shaped rows (see Events::shape).
	 */
	public static function recent( $limit = self::DEFAULT_LIMIT, $kind = null ) {
		$page = self::page( $limit, $kind, 0 );
		return $page['events'];
	}

	/**
	 * How many events the owner has not looked at yet — the bell's badge count.
	 *
	 * @return int
	 */
	public static function unseen_count() {
		global $wpdb;
		$table = self::name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE seen = 0" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; no user input in this query.
	}

	/**
	 * Mark everything as read (the owner opened the feed).
	 *
	 * @return void
	 */
	public static function mark_seen() {
		global $wpdb;
		$table = self::name();
		$wpdb->query( "UPDATE $table SET seen = 1 WHERE seen = 0" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; no user input in this query.
	}

	/**
	 * Total stored events.
	 *
	 * @return int
	 */
	public static function total() {
		global $wpdb;
		$table = self::name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; no user input in this query.
	}

	/**
	 * Delete events older than the retention window. Scheduled with the daily activity prune.
	 *
	 * @return void
	 */
	public static function prune() {
		/**
		 * Filter how many days an Agent Access event is kept.
		 *
		 * @param int $days Retention in days.
		 */
		$days = (int) apply_filters( 'agentimus_agent_access_retention_days', self::RETENTION_DAYS );
		if ( $days < 1 ) {
			return;
		}

		global $wpdb;
		$table  = self::name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE last_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the cutoff is bound.

		self::trim_to_cap();
	}

	/**
	 * Hard row cap, independent of retention — the backstop that guarantees this table can
	 * never grow without bound however the events arrive.
	 *
	 * @return void
	 */
	private static function trim_to_cap() {
		global $wpdb;
		$table = self::name();

		// Delete by ID against an explicit keep-list, NOT by a `last_at < cutoff` comparison.
		//
		// The comparison approach is broken here and fails in exactly the case the cap exists for.
		// `last_at` comes from current_time('mysql'), which has ONE-SECOND granularity, so a burst
		// — a runaway integration or a compromised admin minting keys in a loop — writes thousands
		// of rows all sharing one timestamp. Every row then compares EQUAL to the cutoff, `< cutoff`
		// matches nothing, and the delete silently no-ops. A steady trickle trims fine; an actual
		// flood sails straight past the cap.
		//
		// `id` is auto-increment and unique, so it can never tie. Ordering the keep-list by
		// (last_at DESC, id DESC) still keeps the most recently ACTIVE rows — which is what the cap
		// is for — while the delete itself keys on something that cannot collide. MySQL will not let
		// a DELETE subquery read the table it is deleting from, hence the derived-table wrapper.
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the limit is a bound int.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE id NOT IN (
					SELECT id FROM ( SELECT id FROM $table ORDER BY last_at DESC, id DESC LIMIT %d ) AS keep
				)",
				self::MAX_ROWS
			)
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$table = self::name();
		$wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; no user input in this query.
	}

	/**
	 * @return string
	 */
	private static function name() {
		return Table::name();
	}
}
