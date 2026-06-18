<?php
/**
 * Tiny transient cache for the generated text endpoints, plus the content
 * hooks that bust it. Respects an external object cache automatically (it is
 * just the Transients API).
 *
 * @package Agentomatic
 */

namespace Agentomatic;

defined( 'ABSPATH' ) || exit;

final class Cache {

	const LLMS_TXT  = 'agentomatic_llms_txt';
	const LLMS_FULL = 'agentomatic_llms_full';
	const LLMS_FULL_STAT = 'agentomatic_llms_full_stat'; // Last-generation status for /llms-full.txt (bytes/truncated/reason/items/generated_at).
	const DISCOVERY = 'agentomatic';
	const SECURITY_TXT = 'agentomatic_security_txt';
	// The sitemap is generated as an index + many paginated sub-sitemaps, so it
	// can't use one fixed key. This holds a generation token that namespaces all
	// of its transient keys; deleting it invalidates every page at once.
	const SITEMAP_GEN = 'agentomatic_sitemap_gen';

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
		do_action( 'agentomatic_cache_flushed' );
	}

	/**
	 * Bust the cache whenever content or site identity changes.
	 */
	public static function register_flush_hooks() {
		$hooks = array(
			'save_post',
			'deleted_post',
			'trashed_post',
			'created_term',
			'edited_term',
			'delete_term',
			'update_option_blogname',
			'update_option_blogdescription',
			// A provider plugin coming or going changes the discovery registry.
			'activated_plugin',
			'deactivated_plugin',
		);
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
	}
}
