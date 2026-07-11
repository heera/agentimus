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
 * @package Agentimus
 */

namespace Agentimus\Discovery\Adapters;

use Agentimus\Discovery\Registry;

defined( 'ABSPATH' ) || exit;

final class AbilitiesApi {

	/**
	 * Hook the public registration action. Availability is checked at fire-time.
	 */
	public function register() {
		add_action( AGENTIMUS_CANONICAL_HOOK, array( $this, 'provide' ) );
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
		// Inline guard (not just self::is_available()) so static analysis can see
		// that wp_get_abilities() — a WP 6.9+ API — is never called on older cores;
		// the plugin's baseline (llms/schema/robots/discovery) supports 6.3+.
		if ( ! function_exists( 'wp_get_abilities' ) ) {
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
			if ( ! apply_filters( 'agentimus_discoverable_ability', true, $name, $ability ) ) {
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

		$needs_auth = false;

		foreach ( $items as $item ) {
			$ability     = $item['ability'];
			$name        = $item['name'];
			$abilities[] = $name;
			$short       = strpos( $name, '/' ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;
			$desc        = (string) self::read( $ability, 'get_description' );
			$auth        = self::auth_for( $ability );

			$needs_auth = $needs_auth || 'none' !== $auth;

			$tools[] = array(
				'name'         => $name,
				'title'        => (string) self::read( $ability, 'get_label' ),
				'description'  => $desc,
				'inputSchema'  => (array) self::read( $ability, 'get_input_schema', array() ),
				'outputSchema' => (array) self::read( $ability, 'get_output_schema', array() ),
				'annotations'  => array( 'readOnlyHint' => self::read_only_hint( $ability, $name ) ),
				'auth'         => $auth,
			);

			$skills[] = array( 'id' => sanitize_key( $short ), 'description' => '' !== $desc ? $desc : (string) self::read( $ability, 'get_label' ) );
		}

		/**
		 * Whether to publish abilities that NOBODY anonymous can call.
		 *
		 * Default FALSE, and this is the deliberate position. Every ability here is gated behind a
		 * capability check, so an anonymous agent can never invoke one — publishing them to the
		 * anonymous discovery document gives that agent nothing it can use, while handing anyone who
		 * asks a complete map of the site's tooling: each tool's name, its LLM-facing description
		 * and its full input/output schemas. `agentimus/scan-exposed-files`, for instance, described
		 * in public exactly which sensitive paths the site probes for.
		 *
		 * It also contradicted our own {@see \Agentimus\Abilities\Registrar}, which sets
		 * `mcp.public => false` on every ability precisely to keep them off a public surface.
		 *
		 * Nothing is lost: the owner still sees them on the Discovery screen, and an agent holding
		 * real credentials discovers them the proper way — core's own authenticated
		 * `wp-abilities/v1/abilities` listing, which returns exactly what that caller may run.
		 *
		 * @param bool   $publish   Whether to advertise gated abilities anonymously.
		 * @param string $namespace The ability namespace.
		 */
		$publish_gated = (bool) apply_filters( 'agentimus_publish_gated_abilities', false, $namespace );

		return array(
			'id'           => 'abilities-' . sanitize_key( $namespace ),
			'title'        => ucfirst( $namespace ) . ' abilities',
			'type'         => 'agent',
			// Registered either way, so the Discovery screen can still show the owner what their
			// site exposes — but kept out of every SERVED surface when it is all sign-in-only.
			'public'       => $publish_gated || ! $needs_auth,
			/* translators: 1: count, 2: namespace. */
			'description'  => sprintf( _n( '%1$d ability from the "%2$s" namespace.', '%1$d abilities from the "%2$s" namespace.', count( $items ), 'agentimus' ), count( $items ), $namespace ),
			'abilities'    => $abilities,
			'tools'        => $tools,
			// The resource inherits the strictest requirement of the tools inside it. Left unset it
			// defaulted to type "none" — telling agents the whole set was callable anonymously.
			//
			// 'basic', not 'wp': Resource::AUTH_TYPES only accepts none|apikey|basic|oauth2|oidc|
			// custom and silently falls back to 'none' for anything else, so 'wp' here would quietly
			// reinstate the very lie we are fixing. 'basic' is also the literal truth — a WordPress
			// application password is HTTP Basic auth over REST.
			'auth'         => array( 'type' => $needs_auth ? 'basic' : 'none' ),
			'agent'        => array(
				'name'        => ucfirst( $namespace ) . ' Agent',
				'description' => sprintf( '%s capabilities exposed as MCP tools.', ucfirst( $namespace ) ),
				'skills'      => $skills,
				'endpoint'    => '',
				'auth'        => $needs_auth ? 'wp' : '',
			),
		);
	}

	/**
	 * What an agent must present to call this ability.
	 *
	 * This USED to be `(bool) read( $ability, 'get_permission_callback' ) ? 'wp' : 'none'`, and it
	 * was a lie on every single ability. Core's WP_Ability exposes no `get_permission_callback()`
	 * — only get_name/get_label/get_description/get_category/get_input_schema/get_output_schema/
	 * get_meta/get_meta_item — so read()'s method_exists() check always failed, returned '', cast to
	 * false, and the ternary could never yield 'wp'. Every ability in the public discovery document
	 * was published as `auth: none`, including ones gated behind manage_options. An agent that
	 * believed us walked straight into a 401, and telling agents the truth about a site is the one
	 * job this plugin has.
	 *
	 * There is nothing to introspect, so we take the safe direction instead of guessing. The
	 * Abilities API requires a permission_callback at registration, so "gated" is the correct
	 * default; an ability that genuinely is public can say so by declaring `meta.mcp.public`, the
	 * same flag the MCP adapter reads (see Abilities\Registrar, which sets it FALSE precisely to
	 * keep our own abilities off a public surface).
	 *
	 * Under-claiming costs an agent one unnecessary auth header. Over-claiming sends it into a 401
	 * and misinforms every reader of the document. Those are not symmetric.
	 *
	 * @param mixed $ability The ability object.
	 * @return string 'wp' when authentication is required, 'none' when the ability declares itself public.
	 */
	private static function auth_for( $ability ) {
		$meta = (array) self::read( $ability, 'get_meta', array() );
		$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();

		return ! empty( $mcp['public'] ) ? 'none' : 'wp';
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
	 * Resolve a tool's `readOnlyHint` from the strongest available signal — a name
	 * verb is the weakest, so it's the last resort, never the primary:
	 *
	 *   1. The ability's DECLARED annotation (`meta.annotations.readonly`) — the
	 *      developer's explicit contract; honoured whether true or false. The
	 *      Abilities API defaults it to null, so `isset()` reads "declared".
	 *   2. The ability's TYPE — a resource (it carries a `uri`/`mimeType`) is a read
	 *      by definition, so it's read-only even when its name has no read verb
	 *      (e.g. a "contribution-guide" resource).
	 *   3. A GUARDED name heuristic (see looks_read_only) — may only ever assert
	 *      true; it never overrides a declared false, and ambiguity stays false.
	 *
	 * @param mixed  $ability The ability object.
	 * @param string $name    Ability name (e.g. "core/get-site-info").
	 * @return bool
	 */
	private static function read_only_hint( $ability, $name ) {
		$meta        = (array) self::read( $ability, 'get_meta', array() );
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

		// 1. An explicit declaration wins (true OR false). null/absent ⇒ undeclared.
		if ( isset( $annotations['readonly'] ) ) {
			return (bool) $annotations['readonly'];
		}

		// 2. A resource (carries a uri/mimeType) is a read, never a mutation.
		if ( ! empty( $meta['uri'] ) || ! empty( $meta['mimeType'] ) ) {
			return true;
		}

		// 3. Guarded name heuristic.
		return self::looks_read_only( $name );
	}

	/**
	 * GUARDED name heuristic for an undeclared tool: read-only only when the name
	 * leads with a read verb AND carries no mutation token anywhere. This keeps
	 * `get-orders` read-only while refusing to mark `get-and-delete` — a "get" that
	 * actually writes — as safe. It only ever returns true; ambiguity stays false.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	private static function looks_read_only( $name ) {
		$verb = strpos( $name, '/' ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;

		// Must lead with a read verb…
		if ( ! preg_match( '/^(get|list|read|search|find|fetch|query|count|view)[-_]/', $verb ) ) {
			return false;
		}

		// …and carry no mutation token (a "get" that also writes is not "safe").
		$mutations = 'create|add|update|edit|set|put|delete|remove|destroy|clear|reset|prune|purge|sync|send|cancel|refund|import|export|write|save|store|generate|issue|revoke|approve|reject';
		return ! preg_match( '/[-_](' . $mutations . ')([-_]|$)/', $verb );
	}
}
