<?php
/**
 * Deletion tombstones for the change feed. The live feed ({@see Changes}) is a
 * pure query over posts that still exist, so it can't report removals — a deleted
 * post is simply gone from the database. This records a small "was here, now gone"
 * marker at the moment a public post leaves the site (unpublished, trashed or
 * deleted), so {@see Changes::document()} can emit an `action:"deleted"` item and
 * a caching agent knows to evict that URL.
 *
 * Storage is a non-autoloaded option holding a ring of the newest removals, keyed
 * by post ID (so trash-then-delete of the same post is one entry), pruned by age
 * and a hard count cap. Deletions are rare, so the write cost is negligible.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Tombstones {

	/** Option holding the id => {id,type,url,ts} ring. Non-autoloaded. */
	const OPTION = 'agentimus_tombstones';

	/** Hard safety cap on stored tombstones (age is the primary bound). */
	const MAX = 500;

	/** Default retention window (days). Filterable via agentimus_tombstone_retain_days. */
	const RETAIN_DAYS = 90;

	/**
	 * Hook the capture points — but only while the feed is on, since nothing reads
	 * tombstones when it's off.
	 */
	public function register() {
		if ( ! ( new Settings() )->enabled( 'enable_changes' ) ) {
			return;
		}
		// publish → any non-public status covers unpublish AND trashing.
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		// Permanent deletion (of a published post, or one hard-deleted past the trash).
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ), 10, 2 );
	}

	/**
	 * A post left the published state. Only a transition AWAY from publish is a
	 * removal — draft→publish is an addition the live feed already reports.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $old_status || 'publish' === $new_status ) {
			return;
		}
		self::maybe_record( $post );
	}

	/**
	 * A post is about to be permanently deleted — its row (and permalink) still
	 * exist here, so capture now. before_delete_post passes $post since WP 5.5.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post, when provided by core.
	 */
	public static function on_delete( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( $post instanceof \WP_Post ) {
			self::maybe_record( $post );
		}
	}

	/**
	 * Record a tombstone for an in-scope post, capturing the PUBLIC url it had.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function maybe_record( \WP_Post $post ) {
		if ( ! in_array( $post->post_type, Content::post_types(), true ) ) {
			return; // Not agent-visible content — the feed never listed it.
		}
		$url = self::public_url( $post );
		if ( '' === $url ) {
			return;
		}
		$store                      = self::all();
		$store[ (int) $post->ID ]   = array(
			'id'   => (int) $post->ID,
			'type' => (string) $post->post_type,
			'url'  => $url,
			'ts'   => time(),
		);
		self::save( $store );
	}

	/**
	 * The pretty PUBLIC permalink, even though the post is now trashed/unpublished:
	 * present it as published just long enough for get_permalink() to resolve the
	 * real URL (a trashed post's own permalink is a "__trashed" placeholder). The
	 * mutation is reverted before returning, so no other listener sees it.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function public_url( \WP_Post $post ) {
		$saved_status = $post->post_status;
		$saved_name   = $post->post_name;
		// Trashing appends "__trashed" to the slug (wp_add_trashed_suffix_to_post_name_for_post);
		// strip it so we reconstruct the URL the post actually had while public.
		$post->post_name   = self::strip_trashed_suffix( $post->post_name );
		$post->post_status = 'publish';
		$url               = (string) get_permalink( $post );
		$post->post_status = $saved_status;
		$post->post_name   = $saved_name;
		return $url;
	}

	/**
	 * Remove a single trailing "__trashed" suffix WordPress adds to a trashed
	 * post's slug, recovering the original public slug. Pure.
	 *
	 * @param string $name Post slug.
	 * @return string
	 */
	public static function strip_trashed_suffix( $name ) {
		$name = (string) $name;
		return '__trashed' === substr( $name, -9 ) ? substr( $name, 0, -9 ) : $name;
	}

	/**
	 * Feed rows for the deletions in the retention window, newest first is left to
	 * the caller (the feed merges + sorts them with live items). Excludes ids that
	 * are live again (a re-published post must not read as deleted).
	 *
	 * @param int[] $exclude Post ids currently live (present in the live feed).
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( array $exclude = array() ) {
		$cutoff  = time() - self::retain_days() * DAY_IN_SECONDS;
		$exclude = array_map( 'intval', $exclude );
		$rows    = array();
		foreach ( self::all() as $t ) {
			if ( ! isset( $t['ts'], $t['id'], $t['url'] ) || (int) $t['ts'] < $cutoff ) {
				continue;
			}
			if ( in_array( (int) $t['id'], $exclude, true ) ) {
				continue;
			}
			$rows[] = array(
				'id'      => (int) $t['id'],
				'type'    => (string) $t['type'],
				'url'     => (string) $t['url'],
				'action'  => 'deleted',
				'deleted' => gmdate( 'c', (int) $t['ts'] ),
				'_ts'     => (int) $t['ts'],
			);
		}
		return $rows;
	}

	/**
	 * The raw store (id => record).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function all() {
		$store = get_option( self::OPTION, array() );
		return is_array( $store ) ? $store : array();
	}

	/**
	 * Persist the store, pruned by age and capped to the newest MAX by timestamp.
	 * Stored non-autoloaded — it's read only when the feed is served or a post is
	 * removed, never on every page load.
	 *
	 * @param array<int,array<string,mixed>> $store Store.
	 */
	private static function save( array $store ) {
		$cutoff = time() - self::retain_days() * DAY_IN_SECONDS;
		$store  = array_filter(
			$store,
			static function ( $t ) use ( $cutoff ) {
				return isset( $t['ts'] ) && (int) $t['ts'] >= $cutoff;
			}
		);
		uasort(
			$store,
			static function ( $a, $b ) {
				return (int) $b['ts'] <=> (int) $a['ts'];
			}
		);
		if ( count( $store ) > self::MAX ) {
			$store = array_slice( $store, 0, self::MAX, true );
		}
		update_option( self::OPTION, $store, false );
	}

	/**
	 * Retention window in days, clamped to a sane range.
	 *
	 * @return int
	 */
	private static function retain_days() {
		/**
		 * How long a deletion stays in the change feed before it's pruned.
		 *
		 * @param int $days Default RETAIN_DAYS.
		 */
		$days = (int) apply_filters( 'agentimus_tombstone_retain_days', self::RETAIN_DAYS );
		return max( 1, min( 3650, $days ) );
	}
}
