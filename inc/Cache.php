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
	// Readiness-report derived numbers, recomputed only on a content/settings change
	// (not on every admin load): the /llms.txt word count and the full-text size estimate.
	const LLMS_WORDS    = 'agentimus_llms_words';
	const LLMS_FULL_EST = 'agentimus_llms_full_est';
	// The AEO/GEO "Optimize" pillar aggregates per-page citability across a sample of
	// posts (each parses HTML) — too heavy for every dashboard load, so it's cached and
	// busted with the rest on a content/settings change.
	const OPTIMIZE = 'agentimus_optimize';
	const DISCOVERY = 'agentimus';
	const SECURITY_TXT = 'agentimus_security_txt';
	// The sitemap is generated as an index + many paginated sub-sitemaps, so it
	// can't use one fixed key. This holds a generation token that namespaces all
	// of its transient keys; deleting it invalidates every page at once.
	const SITEMAP_GEN = 'agentimus_sitemap_gen';

	/**
	 * Fingerprint of the content types the cached documents were built from.
	 *
	 * An option, not a transient: it has to outlive the very caches it guards.
	 */
	const TYPES_FINGERPRINT = 'agentimus_types_fingerprint';

	/** @var string The plugin version the generated caches were built by. */
	const BUILT_BY = 'agentimus_cache_built_by';

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
	 * Drop ONE cached value.
	 *
	 * {@see flush()} is for "the site's content or settings changed, so nothing
	 * we derived from them is safe". This is the narrower case: one producer
	 * advanced its own input and only its own answer is stale — the grading
	 * sweep finishing a chunk, for instance. Flushing everything there would
	 * throw away llms.txt and the sitemap every minute during a long sweep.
	 *
	 * @param string $key Transient key.
	 * @return void
	 */
	public static function forget( $key ) {
		delete_transient( $key );
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
		delete_transient( self::LLMS_WORDS );
		delete_transient( self::LLMS_FULL_EST );
		delete_transient( self::OPTIMIZE );
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
	 * Flush everything when the set of ADVERTISED CONTENT TYPES has changed.
	 *
	 * Deactivating a plugin only flushes the discovery registry — deliberately,
	 * so toggling something unrelated never regenerates llms-full.txt, which is
	 * expensive. But a plugin that owned an advertised type is not unrelated:
	 * deactivate FluentCart and llms.txt, the sitemap and the schema keep their
	 * cached copies, still listing product URLs that now 404, for up to an hour.
	 * Publishing links we know are dead is worse than the rebuild we were saving.
	 *
	 * Checked by FINGERPRINT rather than by hooking deactivation, for two
	 * reasons. At deactivation the plugin is still loaded, so its types are still
	 * registered and the change has not happened yet — the next request is where
	 * it shows. And the list can change for reasons no plugin hook reports: a
	 * type vetoed, a filter reconsidering, a CPT renamed. Comparing the list
	 * itself catches every cause and cannot miss one nobody thought of.
	 *
	 * Admin-only: plugins are switched in the admin, so the next request after
	 * one is an admin request. On the front end the existing TTL already bounds
	 * staleness, and this is not worth a filter run on every page view.
	 *
	 * @return void
	 */
	public static function verify_types() {
		$now  = md5( implode( ',', Content::post_types() ) );
		$seen = (string) get_option( self::TYPES_FINGERPRINT, '' );
		if ( $now === $seen ) {
			return;
		}
		// First run has nothing to compare against — record it and flush nothing;
		// there is no stale cache to clear on a site that has never had one.
		if ( '' !== $seen ) {
			self::flush();
		}
		update_option( self::TYPES_FINGERPRINT, $now, false );
	}

	/**
	 * Drop everything generated by a PREVIOUS VERSION of this plugin.
	 *
	 * ⭐⭐ Caught on his live site, twice in one evening. A cached answer outlives
	 * an update: the Optimized pillar caches for an hour, and nothing noticed
	 * that the code which produced it had just been replaced. So he uploaded a
	 * build with a corrected count, reloaded, and read the OLD number back —
	 * "75 graded · 88 being read again", computed by code no longer on the site.
	 * The same shape had already produced a card reading "0 graded" beside a
	 * healthy list.
	 *
	 * Content changes bust these caches, and a schema migration busts them, but a
	 * plugin update did neither. Anything generated by code that is gone is not a
	 * cache, it is a memory of a different program.
	 *
	 * ⛔⛔ AND AN ABSENT RECORD IS AN UPGRADE, NOT A FRESH INSTALL. The first cut
	 * of this copied {@see verify_types()}'s "first run records and flushes
	 * nothing" guard — and so did nothing on the one upgrade it existed for: no
	 * site running an older version has this option, which is exactly the case
	 * with a stale cache. A genuinely fresh install has nothing cached, so the
	 * flush there costs nothing and the guard bought nothing but the bug.
	 *
	 * @return void
	 */
	public static function verify_version() {
		$seen = (string) get_option( self::BUILT_BY, '' );
		if ( AGENTIMUS_VERSION === $seen ) {
			return;
		}
		self::flush();
		// Autoloaded: this is read on every request, and a miss costs a query.
		update_option( self::BUILT_BY, AGENTIMUS_VERSION, true );
	}

	/**
	 * Bust the cache whenever content or site identity changes.
	 */
	public static function register_flush_hooks() {
		// ⭐ Before any hook: an update that changed how a document or a score is
		// GENERATED must not be answered from the previous version's cache, and
		// the very next read is where that would happen.
		self::verify_version();

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

		// …but if that plugin owned a type we were ADVERTISING, the content caches
		// are stale too. Late on admin_init, so every type has registered.
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'verify_types' ), 99 );
		}
	}
}
