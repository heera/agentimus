<?php
/**
 * WordPress Abilities API adapter — the generic bridge to the MCP layer.
 *
 * The Abilities API (heading for core) is where plugins register typed,
 * permission-gated units of functionality. This adapter reads that registry and
 * projects each ability into an MCP-shaped tool, grouped by namespace into one
 * discovery resource per namespace. Because it aggregates the *official*
 * registry — not a bespoke hook — ANY plugin that registers an ability becomes
 * discoverable with zero extra work. We advertise tools; we never execute them.
 *
 * @package Agentify
 */

namespace Agentify\Discovery\Adapters;

use Agentify\Discovery\Registry;

defined( 'ABSPATH' ) || exit;

final class AbilitiesApi {

	/**
	 * Hook the public registration action. Availability is checked at fire-time.
	 */
	public function register() {
		add_action( AGENTIFY_CANONICAL_HOOK, array( $this, 'provide' ) );
	}

	/**
	 * Whether the Abilities API is present on this site.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'wp_get_abilities' );
	}

	/**
	 * Self-description for the admin Discovery Hub adapters list.
	 *
	 * @return array{id:string,title:string,available:bool}
	 */
	public static function info() {
		return array(
			'id'        => 'wp-abilities',
			'title'     => 'WordPress Abilities API',
			'available' => self::is_available(),
		);
	}

	/**
	 * Project the abilities registry into discovery resources (one per namespace).
	 *
	 * @param Registry $registry The collector.
	 */
	public function provide( Registry $registry ) {
		if ( ! self::is_available() ) {
			return;
		}

		$by_namespace = array();
		foreach ( (array) wp_get_abilities() as $ability ) {
			$name = (string) self::read( $ability, 'get_name' );
			if ( '' === $name ) {
				continue;
			}

			/**
			 * Gate which abilities are advertised in the public discovery doc.
			 * Discovery exposes tool *signatures*, not execution — but a site may
			 * still wish to hide that a sensitive operation exists.
			 *
			 * @param bool   $discoverable Default true.
			 * @param string $name         Ability name (e.g. "core/get-site-info").
			 * @param mixed  $ability      The ability object.
			 */
			if ( ! apply_filters( 'agentify_discoverable_ability', true, $name, $ability ) ) {
				continue;
			}

			$namespace = strpos( $name, '/' ) ? substr( $name, 0, strpos( $name, '/' ) ) : 'misc';
			$by_namespace[ $namespace ][] = array( 'name' => $name, 'ability' => $ability );
		}

		foreach ( $by_namespace as $namespace => $items ) {
			$registry->register( $this->resource_for( $namespace, $items ) );
		}
	}

	/**
	 * Build one discovery resource for a namespace's abilities.
	 *
	 * @param string $namespace Namespace slug.
	 * @param array[] $items    Each {name, ability}.
	 * @return array
	 */
	private function resource_for( $namespace, $items ) {
		$tools     = array();
		$abilities = array();
		$skills    = array();

		foreach ( $items as $item ) {
			$ability     = $item['ability'];
			$name        = $item['name'];
			$abilities[] = $name;
			$short       = strpos( $name, '/' ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;
			$desc        = (string) self::read( $ability, 'get_description' );
			$has_perm    = (bool) self::read( $ability, 'get_permission_callback' );

			$tools[] = array(
				'name'         => $name,
				'title'        => (string) self::read( $ability, 'get_label' ),
				'description'  => $desc,
				'inputSchema'  => (array) self::read( $ability, 'get_input_schema', array() ),
				'outputSchema' => (array) self::read( $ability, 'get_output_schema', array() ),
				'annotations'  => array( 'readOnlyHint' => self::looks_read_only( $name ) ),
				// A permission callback means an authenticated WP context is needed.
				'auth'         => $has_perm ? 'wp' : 'none',
			);

			$skills[] = array( 'id' => sanitize_key( $short ), 'description' => '' !== $desc ? $desc : (string) self::read( $ability, 'get_label' ) );
		}

		return array(
			'id'           => 'abilities-' . sanitize_key( $namespace ),
			'title'        => ucfirst( $namespace ) . ' abilities',
			'type'         => 'agent',
			/* translators: 1: count, 2: namespace. */
			'description'  => sprintf( _n( '%1$d ability from the "%2$s" namespace.', '%1$d abilities from the "%2$s" namespace.', count( $items ), 'agentify' ), count( $items ), $namespace ),
			'abilities'    => $abilities,
			'tools'        => $tools,
			'agent'        => array(
				'name'        => ucfirst( $namespace ) . ' Agent',
				'description' => sprintf( '%s capabilities exposed as MCP tools.', ucfirst( $namespace ) ),
				'skills'      => $skills,
				'endpoint'    => '',
				'auth'        => '',
			),
		);
	}

	/**
	 * Read a value off an ability object via a getter, tolerating API shape drift
	 * across Abilities API versions.
	 *
	 * @param mixed  $ability The ability object.
	 * @param string $method  Getter name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	private static function read( $ability, $method, $default = '' ) {
		if ( is_object( $ability ) && method_exists( $ability, $method ) ) {
			$value = $ability->$method();
			return null === $value ? $default : $value;
		}
		return $default;
	}

	/**
	 * Heuristic readOnly hint from the ability name (get-/list-/read-… verbs).
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	private static function looks_read_only( $name ) {
		$verb = strpos( $name, '/' ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;
		return (bool) preg_match( '/^(get|list|read|search|find|fetch|query|count|view)[-_]/', $verb );
	}
}
