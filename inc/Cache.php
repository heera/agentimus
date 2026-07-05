<?php
/**
 * Tiny transient cache for the generated text endpoints, plus the content
 * hooks that bust it. Respects an external object cache automatically (it is
 * just the Transients API).
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Cache {

	const LLMS_TXT  = 'agentimus_llms_txt';
	const LLMS_FULL = 'agentimus_llms_full';
	const CHANGES   = 'agentimus_changes'; // Cached item list for the /agentimus-changes.json feed.
	const LLMS_FULL_STAT = 'agentimus_llms_full_stat'; // Last-generation status for /llms-full.txt (bytes/truncated/reason/items/generated_at).
	const DISCOVERY = 'agentimus';
	const SECURITY_TXT = 'agentimus_security_txt';
	// The sitemap is generated as an index + many paginated sub-sitemaps, so it
	// can't use one fixed key. This holds a generation token that namespaces all
	// of its transient keys; deleting it invalidates every page at once.
	const SITEMAP_GEN = 'agentimus_sitemap_gen';

	const TTL = HOUR_IN_SECONDS;
	// A truncated full-text body is re-attempted sooner — content or settings may
	// change and bring it back under budget.
	const TTL_PARTIAL = 15 * MINUTE_IN_SECONDS;

	// Namespacing + lifetime for the short-lived build locks that keep a cold-cache
	// crawler burst from stampeding the expensive generators (see acquire_lock()).
	const LOCK_PREFIX = 'agentimus_lock_';
	const LOCK_GROUP  = 'agentimus';
	// A build lock lasts at most this long — a ceiling on one generation so a builder
	// that dies mid-run can't wedge the lock; the winner normally releases it far sooner.
	const LOCK_TTL = 30;

	/**
	 * Read a cached value.
	 *
	 * @param string $key Transient key.
	 * @return string|false
	 */
	public static function get( $key ) {
		return get_transient( $key );
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Transient key.
	 * @param mixed  $value Body (string) or a status array.
	 * @param int    $ttl   Lifetime in seconds; defaults to the standard TTL.
	 */
	public static function set( $key, $value, $ttl = self::TTL ) {
		set_transient( $key, $value, $ttl );
	}

	/**
	 * Try to acquire a short-lived build lock so only ONE request runs an expensive,
	 * post-count-scaling generation on a cold cache; a concurrent caller gets false and
	 * should serve a fallback (a stale copy, or a lighter document) instead of piling a
	 * second heavy build onto the same cold-cache moment — a cache stampede.
	 *
	 * Backed by wp_cache_add() — an atomic "write only if absent" — so it is a true
	 * cross-request mutex on any site with a PERSISTENT object cache (Redis/Memcached),
	 * exactly where high crawler traffic and stampedes occur. Without a persistent cache
	 * the add is per-request and always succeeds, so every request builds as it does
	 * today: the lock only ever helps, never blocks or breaks a build. When the object-
	 * cache API is entirely absent (minimal/CLI contexts) we likewise return true.
	 *
	 * @param string $key Base key being built (e.g. self::LLMS_FULL); the lock derives from it.
	 * @param int    $ttl Lock lifetime in seconds. Defaults to self::LOCK_TTL.
	 * @return bool True to the single caller that should build.
	 */
	public static function acquire_lock( $key, $ttl = self::LOCK_TTL ) {
		if ( ! function_exists( 'wp_cache_add' ) ) {
			return true;
		}
		return (bool) wp_cache_add( self::LOCK_PREFIX . $key, 1, self::LOCK_GROUP, max( 1, (int) $ttl ) );
	}

	/**
	 * Release a build lock acquired via acquire_lock(). Call it in a `finally` so a
	 * builder that throws frees the lock at once rather than holding it for the full TTL.
	 *
	 * @param string $key Base key passed to acquire_lock().
	 * @return void
	 */
	public static function release_lock( $key ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::LOCK_PREFIX . $key, self::LOCK_GROUP );
		}
	}

	/**
	 * Drop every generated cache.
	 */
	public static function flush() {
		delete_transient( self::LLMS_TXT );
		delete_transient( self::LLMS_FULL );
		delete_transient( self::LLMS_FULL_STAT );
		delete_transient( self::CHANGES );
		delete_transient( self::DISCOVERY );
		delete_transient( self::SECURITY_TXT );
		delete_transient( self::SITEMAP_GEN ); // Orphans every sub-sitemap transient.

		/**
		 * Fires after the generated caches are dropped (content changed) — the seam
		 * used to schedule a debounced out-of-band re-warm of the heavy full-text edition.
		 */
		do_action( 'agentimus_cache_flushed' );
	}

	/**
	 * Drop only the discovery-document cache. Used when a provider plugin is
	 * activated/deactivated: that changes the discovery registry, but NOT your
	 * llms.txt / sitemap content — so there's no reason to bust those or re-warm
	 * the heavy full-text edition on every unrelated plugin toggle. Deliberately
	 * does not fire `agentimus_cache_flushed`.
	 */
	public static function flush_discovery() {
		delete_transient( self::DISCOVERY );
	}

	/**
	 * Bust the cache whenever content or site identity changes.
	 */
	public static function register_flush_hooks() {
		// Content / identity changes rebuild everything (llms.txt, full text,
		// schema, discovery, sitemap).
		$content_hooks = array(
			'save_post',
			'deleted_post',
			'trashed_post',
			'created_term',
			'edited_term',
			'delete_term',
			'update_option_blogname',
			'update_option_blogdescription',
		);
		foreach ( $content_hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}

		// A provider plugin coming or going changes ONLY the discovery registry —
		// flush just that, not the heavy content caches, so toggling an unrelated
		// plugin never regenerates llms-full.txt.
		add_action( 'activated_plugin', array( __CLASS__, 'flush_discovery' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'flush_discovery' ) );
	}
}
