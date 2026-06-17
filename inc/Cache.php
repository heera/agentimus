<?php
/**
 * Tiny transient cache for the generated text endpoints, plus the content
 * hooks that bust it. Respects an external object cache automatically (it is
 * just the Transients API).
 *
 * @package Agentify
 */

namespace Agentify;

defined( 'ABSPATH' ) || exit;

final class Cache {

	const LLMS_TXT  = 'agentify_llms_txt';
	const LLMS_FULL = 'agentify_llms_full';
	const DISCOVERY = 'agentify_discovery';
	const SECURITY_TXT = 'agentify_security_txt';
	// The sitemap is generated as an index + many paginated sub-sitemaps, so it
	// can't use one fixed key. This holds a generation token that namespaces all
	// of its transient keys; deleting it invalidates every page at once.
	const SITEMAP_GEN = 'agentify_sitemap_gen';

	const TTL = HOUR_IN_SECONDS;

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
	 * @param string $value Body.
	 */
	public static function set( $key, $value ) {
		set_transient( $key, $value, self::TTL );
	}

	/**
	 * Drop every generated cache.
	 */
	public static function flush() {
		delete_transient( self::LLMS_TXT );
		delete_transient( self::LLMS_FULL );
		delete_transient( self::DISCOVERY );
		delete_transient( self::SECURITY_TXT );
		delete_transient( self::SITEMAP_GEN ); // Orphans every sub-sitemap transient.
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
