<?php
/**
 * REST API adapter — zero-config auto-discovery.
 *
 * The whole point: a site is discoverable even when NO plugin hooks into
 * `wpdiscovery_register`. WordPress already holds a complete map of what a site
 * exposes — its registered REST namespaces, public post types and taxonomies —
 * so this adapter simply reads that map and emits a baseline discovery:
 *
 *   - one `wordpress-core` content resource derived from `wp/v2` + the public,
 *     REST-enabled post types and taxonomies actually registered on the site, and
 *   - one lightweight resource per *third-party* REST namespace that never
 *     declared itself, so its API still shows up under `apis[]`.
 *
 * This reflects only what `/wp-json/` already makes public — it indexes, it does
 * not expose anything new. Providers that hook in later *enrich* this baseline
 * with intent and agent cards; they are no longer a prerequisite for a useful
 * discovery document. No AI, no external calls — just introspection.
 *
 * @package Agentify
 */

namespace Agentify\Discovery\Adapters;

use Agentify\Discovery\Registry;

defined( 'ABSPATH' ) || exit;

final class RestApi {

	/**
	 * Namespace prefixes to skip: WordPress core (`wp/`, `wp-`, `oembed`) and this
	 * plugin (`agentify`). Everything else is treated as a third-party API to
	 * surface — no plugin-specific names are hardcoded here.
	 */
	const SKIP = array( 'wp/', 'wp-', 'oembed', 'agentify' );

	/**
	 * Hook the public registration action. Availability is checked at fire-time,
	 * by which point the REST server has its full route map.
	 */
	public function register() {
		add_action( AGENTIFY_CANONICAL_HOOK, array( $this, 'provide' ) );
	}

	/**
	 * Whether REST introspection is possible (always true on supported WP).
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'rest_get_server' );
	}

	/**
	 * Self-description for the admin Discovery Hub adapters list.
	 *
	 * @return array{id:string,title:string,available:bool}
	 */
	public static function info() {
		return array(
			'id'        => 'rest-api',
			'title'     => 'REST API (auto-discovery)',
			'available' => self::is_available(),
		);
	}

	/**
	 * Emit the baseline discovery derived from the site's own registries.
	 *
	 * @param Registry $registry The collector.
	 */
	public function provide( Registry $registry ) {
		if ( ! self::is_available() ) {
			return;
		}

		/**
		 * Toggle REST auto-discovery off entirely.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'agentify_rest_discovery', true ) ) {
			return;
		}

		$namespaces = (array) rest_get_server()->get_namespaces();

		// 1. WordPress core content, with capabilities derived from the public,
		//    REST-enabled post types and taxonomies actually registered here.
		if ( in_array( 'wp/v2', $namespaces, true ) ) {
			$registry->register(
				array(
					'id'           => 'wordpress-core',
					'title'        => 'WordPress Core',
					'type'         => 'content',
					'description'  => __( 'Core content exposed via the WordPress REST API.', 'agentify' ),
					'capabilities' => self::core_capabilities(
						self::rest_bases( get_post_types( array( 'public' => true, 'show_in_rest' => true ), 'objects' ) ),
						self::rest_bases( get_taxonomies( array( 'public' => true, 'show_in_rest' => true ), 'objects' ) )
					),
					'endpoints'    => array(
						array(
							'url'         => '/wp-json/wp/v2',
							'type'        => 'rest',
							'methods'     => array( 'GET' ),
							'auth'        => 'none',
							'description' => __( 'Public WordPress REST API (read).', 'agentify' ),
						),
					),
				)
			);
		}

		/**
		 * Namespace prefixes to skip (core, this plugin, dedicated adapters).
		 *
		 * @param string[] $skip Prefixes.
		 */
		$skip = (array) apply_filters( 'agentify_rest_skip_namespaces', self::SKIP );

		// 2. Third-party namespaces that never declared themselves — surface the
		//    API so it is at least discoverable (no intent labels we can't infer).
		foreach ( $namespaces as $namespace ) {
			if ( ! self::is_third_party( $namespace, $skip ) ) {
				continue;
			}
			$registry->register(
				array(
					'id'          => self::slug( $namespace ),
					'title'       => (string) $namespace,
					'type'        => 'x-rest-api',
					'description' => __( 'Auto-discovered REST API namespace.', 'agentify' ),
					'endpoints'   => array(
						array( 'url' => '/wp-json/' . $namespace, 'type' => 'rest', 'auth' => 'none' ),
					),
				)
			);
		}
	}

	/* ---------------------------------------------------------------------- *
	 *  Pure helpers (no WordPress dependency — unit-tested)
	 * ---------------------------------------------------------------------- */

	/**
	 * Map REST bases of post types + taxonomies to dot-notation read capabilities.
	 *
	 * @param string[] $post_bases Post-type REST bases (e.g. posts, pages).
	 * @param string[] $tax_bases  Taxonomy REST bases (e.g. categories, tags).
	 * @return string[] Deduplicated `content.<base>.read` tokens.
	 */
	public static function core_capabilities( array $post_bases, array $tax_bases ) {
		$caps = array();
		foreach ( array_merge( $post_bases, $tax_bases ) as $base ) {
			$base = sanitize_key( (string) $base );
			if ( '' !== $base ) {
				$caps[] = 'content.' . $base . '.read';
			}
		}
		return array_values( array_unique( $caps ) );
	}

	/**
	 * Whether a namespace belongs to a third-party plugin (not core / us / a
	 * dedicated adapter), and therefore worth surfacing as a discovered API.
	 *
	 * @param string   $namespace REST namespace, e.g. "acme/v1".
	 * @param string[] $skip      Prefixes to exclude.
	 * @return bool
	 */
	public static function is_third_party( $namespace, array $skip ) {
		$namespace = (string) $namespace;
		if ( '' === $namespace ) {
			return false;
		}
		foreach ( $skip as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $namespace, (string) $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A valid resource-id slug for a namespace. Any run of non-alphanumeric
	 * characters (slashes, underscores, dots) becomes a single hyphen, so the
	 * result always satisfies the Resource id pattern: "wc/v3/wc_paypal" →
	 * "wc-v3-wc-paypal", "acme/v1" → "acme-v1".
	 *
	 * @param string $namespace REST namespace.
	 * @return string
	 */
	public static function slug( $namespace ) {
		$slug = preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $namespace ) );
		return trim( (string) $slug, '-' );
	}

	/**
	 * Extract REST bases from post-type / taxonomy objects (WP-coupled glue).
	 *
	 * @param array $objects WP_Post_Type[] | WP_Taxonomy[].
	 * @return string[]
	 */
	private static function rest_bases( $objects ) {
		$bases = array();
		foreach ( (array) $objects as $obj ) {
			if ( ! is_object( $obj ) ) {
				continue;
			}
			$base = ! empty( $obj->rest_base ) ? $obj->rest_base : ( ! empty( $obj->name ) ? $obj->name : '' );
			if ( '' !== $base ) {
				$bases[] = $base;
			}
		}
		return $bases;
	}
}
