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
 * @package Agentimus
 */

namespace Agentimus\Discovery\Adapters;

use Agentimus\Discovery\Registry;

defined( 'ABSPATH' ) || exit;

final class RestApi {

	/**
	 * Namespace prefixes to skip: WordPress core (`wp/`, `wp-`, `oembed`) and this
	 * plugin (`agentimus`). Everything else is treated as a third-party API to
	 * surface — no plugin-specific names are hardcoded here.
	 */
	const SKIP = array( 'wp/', 'wp-', 'oembed', 'agentimus' );

	/**
	 * Hook the public registration action. Availability is checked at fire-time,
	 * by which point the REST server has its full route map.
	 *
	 * Runs LATE (priority 99) so every explicit provider — which hooks at the
	 * default priority 10 — has already registered by the time we fill gaps. That
	 * makes auto-discovery a true fallback: a namespace a plugin described itself
	 * is left untouched, never shadowed or duplicated by a generic stub.
	 */
	public function register() {
		add_action( AGENTIMUS_CANONICAL_HOOK, array( $this, 'provide' ), 99 );
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
		if ( ! apply_filters( 'agentimus_rest_discovery', true ) ) {
			return;
		}

		$namespaces = (array) rest_get_server()->get_namespaces();

		// Whatever explicit providers already registered (we run late, priority 99).
		// A namespace any of these already describe is left to them — we only fill
		// gaps, so the auto-stub never shadows or duplicates a first-class entry.
		$existing = $registry->resources();

		// 1. WordPress core content, with capabilities derived from the public,
		//    REST-enabled post types and taxonomies actually registered here.
		if ( in_array( 'wp/v2', $namespaces, true ) && ! isset( $existing['wordpress-core'] ) && ! self::is_described( 'wp/v2', $existing ) ) {
			$registry->register(
				array(
					'id'           => 'wordpress-core',
					'title'        => 'WordPress Core',
					'type'         => 'content',
					'description'  => __( 'Core content exposed via the WordPress REST API.', 'agentimus' ),
					'capabilities' => self::content_capabilities(),
					'endpoints'    => array(
						array(
							'url'         => '/wp-json/wp/v2',
							'type'        => 'rest',
							'methods'     => array( 'GET' ),
							'auth'        => 'none',
							'description' => __( 'Public WordPress REST API (read).', 'agentimus' ),
						),
					),
				)
			);
		}

		$skip = (array) apply_filters( 'agentimus_rest_skip_namespaces', self::SKIP );

		/**
		 * Allow-list of third-party REST namespaces to PUBLISH. Default is the
		 * owner's "Detected REST APIs" checklist (a setting) — nothing third-party
		 * is published unless explicitly opted in, so the document stays clean
		 * (no automatic rule reliably tells an agent-useful API from internal
		 * plumbing, so the owner decides).
		 *
		 * @param string[] $allowed Namespace names to publish.
		 */
		$allowed = (array) apply_filters( 'agentimus_rest_namespaces', self::allowed() );

		// 2. Only the third-party namespaces the owner opted in (or a filter added)
		//    AND that no explicit provider has already described.
		foreach ( $namespaces as $namespace ) {
			if ( ! self::is_third_party( $namespace, $skip ) || ! self::is_allowed( $namespace, $allowed ) ) {
				continue;
			}
			if ( self::is_described( $namespace, $existing ) ) {
				continue;
			}
			$registry->register(
				array(
					'id'          => self::slug( $namespace ),
					'title'       => (string) $namespace,
					'type'        => 'x-rest-api',
					'description' => __( 'REST API namespace published via discovery.', 'agentimus' ),
					'endpoints'   => array(
						array( 'url' => '/wp-json/' . $namespace, 'type' => 'rest', 'auth' => 'none' ),
					),
				)
			);
		}
	}

	/**
	 * Third-party namespaces detected on this site — the candidates the owner can
	 * opt into publishing (the full detected set, not the published subset).
	 *
	 * @return string[]
	 */
	public static function detected() {
		if ( ! self::is_available() ) {
			return array();
		}
		$skip = (array) apply_filters( 'agentimus_rest_skip_namespaces', self::SKIP );
		$out  = array();
		foreach ( (array) rest_get_server()->get_namespaces() as $namespace ) {
			if ( self::is_third_party( $namespace, $skip ) ) {
				$out[] = (string) $namespace;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * The owner's opted-in namespace allow-list, from settings.
	 *
	 * @return string[]
	 */
	private static function allowed() {
		if ( ! class_exists( '\Agentimus\Settings' ) ) {
			return array();
		}
		return array_values( (array) ( new \Agentimus\Settings() )->get( 'rest_namespaces', array() ) );
	}

	/**
	 * Content capabilities for `wordpress-core`, derived ONLY from the post types
	 * the owner enabled in Content types (settings.post_types) and the taxonomies
	 * attached to them — so unchecking "Products" really does drop
	 * content.product.read from discovery. Consistent with what feeds llms.txt /
	 * markdown / schema.
	 *
	 * @return string[]
	 */
	private static function content_capabilities() {
		$selected = array();
		if ( class_exists( '\Agentimus\Settings' ) ) {
			$selected = (array) ( new \Agentimus\Settings() )->get( 'post_types', array() );
		}

		$post_objects = array();
		foreach ( (array) get_post_types( array( 'public' => true, 'show_in_rest' => true ), 'objects' ) as $obj ) {
			if ( in_array( $obj->name, $selected, true ) ) {
				$post_objects[] = $obj;
			}
		}

		// Taxonomies attached to the selected post types (public, REST-enabled).
		$tax_names = array();
		foreach ( $post_objects as $obj ) {
			foreach ( (array) get_object_taxonomies( $obj->name, 'names' ) as $tax_name ) {
				$tax_names[ $tax_name ] = true;
			}
		}
		$tax_objects = array();
		foreach ( array_keys( $tax_names ) as $tax_name ) {
			$tax = get_taxonomy( $tax_name );
			if ( $tax && ! empty( $tax->public ) && ! empty( $tax->show_in_rest ) ) {
				$tax_objects[] = $tax;
			}
		}

		return self::core_capabilities( self::rest_bases( $post_objects ), self::rest_bases( $tax_objects ) );
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
	 * Whether a namespace is on the owner's publish allow-list.
	 *
	 * @param string   $namespace Namespace.
	 * @param string[] $allowed   Allow-list (opted-in namespaces).
	 * @return bool
	 */
	public static function is_allowed( $namespace, array $allowed ) {
		return in_array( (string) $namespace, $allowed, true );
	}

	/**
	 * Whether an explicit provider has already described this namespace, in which
	 * case the auto-stub is skipped. True when either:
	 *   - a resource already exists under the id this adapter would mint for the
	 *     namespace (an intentional override), or
	 *   - any existing resource has an endpoint pointing into `/wp-json/<ns>`.
	 *
	 * Pure: takes the resources map (id => resource) so it is unit-testable.
	 *
	 * @param string $namespace REST namespace, e.g. "acme/v1".
	 * @param array  $resources Already-registered resources, keyed by id.
	 * @return bool
	 */
	public static function is_described( $namespace, array $resources ) {
		if ( isset( $resources[ self::slug( $namespace ) ] ) ) {
			return true;
		}
		foreach ( $resources as $resource ) {
			$endpoints = isset( $resource['endpoints'] ) ? (array) $resource['endpoints'] : array();
			foreach ( $endpoints as $endpoint ) {
				$url = isset( $endpoint['url'] ) ? $endpoint['url'] : '';
				if ( self::endpoint_covers( $url, $namespace ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether an endpoint URL addresses a given REST namespace — i.e. its path
	 * contains `/<namespace>` as a whole segment run (so "acme/v1" matches
	 * "/wp-json/acme/v1" and "…/acme/v1/products" but never "…/acme/v10").
	 * Query/fragment and a trailing slash are ignored.
	 *
	 * @param string $url       Endpoint URL (relative or absolute).
	 * @param string $namespace REST namespace.
	 * @return bool
	 */
	public static function endpoint_covers( $url, $namespace ) {
		$url       = strtolower( (string) preg_replace( '/[?#].*$/', '', (string) $url ) );
		$url       = rtrim( $url, '/' );
		$namespace = strtolower( (string) $namespace );
		if ( '' === $url || '' === $namespace ) {
			return false;
		}
		$token = '/' . $namespace;
		$pos   = strpos( $url, $token );
		if ( false === $pos ) {
			return false;
		}
		$after = substr( $url, $pos + strlen( $token ) );
		return '' === $after || '/' === $after[0];
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
