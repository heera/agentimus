<?php
/**
 * Store — reads and writes the Agent Access event rollup.
 *
 * The owner's rule for this feature is deliberately loud: they want to SEE every agent access,
 * without exception, so the nav pill re-lights on any hit — routine or not (see {@see record()},
 * which resets `seen` on every upsert). {@see record()} still returns whether an event was seen
 * for the FIRST time, but that "new" signal is a caller convenience now, not what drives the pill.
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
				// seen = 0 on the update path too: the owner asked to be alerted to EVERY access,
				// not only the first of a kind. So a repeat re-marks the row unread — the nav pill
				// lights again for any agent activity, however routine.
				"INSERT INTO $table (kind, user_id, cred, subject, detail, hits, first_at, last_at, seen)
				 VALUES (%s, %d, %s, %s, %s, 1, %s, %s, 0)
				 ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = VALUES(last_at), seen = 0",
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
		// that changed something. That difference still tells the caller "first time of this kind",
		// which is what {@see record()}'s return documents. The nav pill, though, is driven by the
		// `seen` column (see unseen_count()), and the upsert now resets `seen` on every hit — so a
		// repeat still returns FALSE here while correctly re-lighting the pill.
		$is_new = ( 1 === (int) $affected );

		if ( $is_new && 1 === wp_rand( 1, 20 ) ) {
			self::trim_to_cap();
		}

		return $is_new;
	}

	/**
	 * One page of events, most-recently-active first — a CURSOR walk, matching the request log.
	 *
	 * Ordered by `last_at DESC, id DESC`. The owner asked that the newest activity always sit at the
	 * TOP, and on a rollup table "newest activity" is `last_at` (which moves when a row's hit count
	 * increments), not the insertion `id`. Because `last_at` moves, an `id`-only cursor would let a
	 * row hop pages mid-walk, so the cursor is COMPOSITE — a "last_at~id" pair — and the keyset
	 * predicate `(last_at < X OR (last_at = X AND id < Y))` walks that exact (last_at, id) ordering
	 * with no gaps or repeats. `id` breaks ties (many rows can share a one-second `last_at`) and is
	 * immutable, so the tiebreak is stable.
	 *
	 * @param int         $limit  Rows per page.
	 * @param string|null $kind   Optional kind filter.
	 * @param string|int  $before Cursor: a "last_at~id" pair; return rows ordered strictly BELOW it
	 *                            ('' or 0 = first page).
	 * @return array{events:array[],hasMore:bool,cursor:string|null}
	 */
	public static function page( $limit = self::DEFAULT_LIMIT, $kind = null, $before = 0 ) {
		global $wpdb;
		$table  = self::name();
		$limit  = max( 1, min( 500, (int) $limit ) );

		$where  = array();
		$params = array();
		if ( null !== $kind && Events::is_kind( $kind ) ) {
			$where[]  = 'kind = %s';
			$params[] = (string) $kind;
		}
		$cursor = self::parse_cursor( $before );
		if ( null !== $cursor ) {
			$where[]  = '(last_at < %s OR (last_at = %s AND id < %d))';
			$params[] = $cursor['at'];
			$params[] = $cursor['at'];
			$params[] = $cursor['id'];
		}
		$sql = "SELECT * FROM $table";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		// Fetch one MORE than asked: its presence is how we know another page exists, without a
		// second COUNT query.
		$sql     .= ' ORDER BY last_at DESC, id DESC LIMIT %d';
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
		$last   = end( $rows );

		$cursor_out = null;
		if ( $last && isset( $last['last_at'], $last['id'] ) ) {
			$cursor_out = $last['last_at'] . '~' . (int) $last['id'];
		}

		return array(
			'events'  => $events,
			'hasMore' => $has_more,
			'cursor'  => $cursor_out,
		);
	}

	/**
	 * Parse a "last_at~id" cursor string into its two halves, or NULL for a first-page request.
	 *
	 * Tolerant by design: a legacy bare-integer cursor (an old `id`) or any malformed value simply
	 * yields NULL — a harmless fall back to the first page rather than a fatal or a bad query.
	 *
	 * @param string|int $before Raw cursor from the request.
	 * @return array{at:string,id:int}|null
	 */
	private static function parse_cursor( $before ) {
		$before = trim( (string) $before );
		if ( '' === $before || false === strpos( $before, '~' ) ) {
			return null;
		}
		list( $at, $id ) = explode( '~', $before, 2 );
		$at = substr( trim( $at ), 0, 32 );
		if ( '' === $at ) {
			return null;
		}
		return array(
			'at' => $at,
			'id' => max( 0, (int) $id ),
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
	/**
	 * The screen's headline numbers, in one query each: how many distinct
	 * abilities have actually run here, and when anything last happened.
	 *
	 * "Watching every ability on this site" is true but abstract — an owner
	 * cannot tell from it whether the watching has ever seen anything. These
	 * two facts answer that without scrolling the feed, and they are honest
	 * about zero: a site where nothing has run says so.
	 *
	 * @return array{abilities:int,lastAt:string} lastAt is ISO-8601, '' when nothing has happened.
	 */
	public static function headline() {
		global $wpdb;
		$table = self::name();

		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; the kinds are our own literals.
		$abilities = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT subject) FROM $table WHERE kind IN ( 'ability_used', 'ability_refused' ) AND subject <> ''"
		);
		$last = (string) $wpdb->get_var( "SELECT MAX(last_at) FROM $table" );
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'abilities' => $abilities,
			'lastAt'    => '' !== $last ? gmdate( 'c', strtotime( $last . ' UTC' ) ) : '',
		);
	}

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
