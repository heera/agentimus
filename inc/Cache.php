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
	 * Drop every generated cache.
	 */
	public static function flush() {
		delete_transient( self::LLMS_TXT );
		delete_transient( self::LLMS_FULL );
		delete_transient( self::LLMS_FULL_STAT );
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
