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

			// An ability registered with `show_in_rest => false` has NO REST route at all —
			// it is not in the wp-abilities listing and cannot be run remotely by ANY agent
			// (it is an in-process PHP helper, e.g. core/get-user-info). Counting it as an
			// agent tool overcounted every surface at once: the namespace card, the tiles'
			// site-wide total and the Abilities API row all promised one more tool than the
			// endpoint they point at can ever serve.
			$meta = (array) self::read( $ability, 'get_meta', array() );
			if ( isset( $meta['show_in_rest'] ) && false === $meta['show_in_rest'] ) {
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

		$needs_auth     = false;
		$any_advertised = false;

		foreach ( $items as $item ) {
			$ability     = $item['ability'];
			$name        = $item['name'];
			$short       = strpos( $name, '/' ) ? substr( $name, strpos( $name, '/' ) + 1 ) : $name;
			$desc        = (string) self::read( $ability, 'get_description' );

			// TWO SEPARATE QUESTIONS, and conflating them was the bug.
			//
			//   1. Does the vendor advertise this tool? Their call, never ours —
			//      we neither publish what they kept back nor hide what they
			//      published. That is the `mcp.public` mark, and nothing else.
			//   2. What does running it take? Ours to state honestly. A tool that
			//      CHANGES something is never "no sign-in needed", whoever marked
			//      it public: WooCommerce marks product-delete public, which asks
			//      us to advertise it, not to promise anyone may run it.
			$advertised = self::advertised( $ability );
			$read_only  = self::read_only_hint( $ability, $name );
			$auth       = self::auth_for( $advertised, $read_only );

			// Only what is actually served decides the group's own line.
			if ( $advertised ) {
				$any_advertised = true;
				$needs_auth     = $needs_auth || 'none' !== $auth;
				$abilities[]    = $name;
			}

			$tools[] = array(
				'name'         => $name,
				// An ability carrying an MCP `uri` is a RESOURCE — a document an
				// assistant attaches, not a tool it runs. MCP itself keeps the two
				// apart (tools/list vs resources/list) and so does our own README,
				// but this array flattened them, so every count downstream said
				// "tools" for a number that included four documents.
				'kind'         => self::is_resource( $ability ) ? 'resource' : 'tool',
				// The vendor's decision, carried per tool so a namespace with a
				// mixed hand publishes exactly the hand it was dealt. Held-back
				// tools still reach the ADMIN screen — the owner sees everything
				// their site holds; only the served document is trimmed.
				'public'       => $advertised,
				'title'        => (string) self::read( $ability, 'get_label' ),
				'description'  => $desc,
				'inputSchema'  => (array) self::read( $ability, 'get_input_schema', array() ),
				'outputSchema' => (array) self::read( $ability, 'get_output_schema', array() ),
				'annotations'  => array( 'readOnlyHint' => self::read_only_hint( $ability, $name ) ),
				'auth'         => $auth,
				// Resources only: the public address the document lives at, so the
				// admin can link the row to the file itself. '' for runnable tools.
				'uri'          => self::resource_uri( $ability ),
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
			// The namespace VERBATIM, never title-cased. ucfirst() turned real
			// vendor names into misspellings — "Woocommerce", "Mailpoet", "Ai" —
			// and put two spellings of one vendor on the same screen. A namespace
			// is a slug, not a name: printing it as it is can never be wrong, and
			// there is no honest way to derive a brand's capitalisation from one.
			'title'        => $namespace . ' abilities',
			'type'         => 'agent',
			// Registered either way, so the Discovery screen can still show the owner what their
			// site exposes — but kept out of every SERVED surface when it is all sign-in-only.
			'public'       => $publish_gated || $any_advertised,
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
				'name'        => $namespace . ' Agent',
				'description' => sprintf( '%s capabilities exposed as MCP tools.', $namespace ),
				'skills'      => $skills,
				'endpoint'    => '',
				'auth'        => $needs_auth ? 'wp' : '',
			),
		);
	}

	/**
	 * What running this tool takes. Ours to say, and never louder than the truth.
	 *
	 * Anyone may call a tool that is BOTH advertised by its vendor AND only reads.
	 * Everything else needs a signed-in user: an ability nobody advertised, and —
	 * the case that started this — a tool that CHANGES something, however its
	 * vendor marked it. WooCommerce marks product-delete public; that is a request
	 * to list it, not a promise that anyone may run it.
	 *
	 * Under-claiming costs an agent one unnecessary auth header. Over-claiming
	 * walks it into a 401 and misinforms every reader of the document. Those are
	 * not symmetric.
	 *
	 * @param bool $advertised Whether the vendor advertises it.
	 * @param bool $read_only  Whether it only reads.
	 * @return string 'none' when anyone may call it, 'wp' when it needs a sign-in.
	 */
	private static function auth_for( $advertised, $read_only ) {
		return ( $advertised && $read_only ) ? 'none' : 'wp';
	}

	/**
	 * Whether the VENDOR advertises this ability — their decision, read from the
	 * `meta.mcp.public` mark and nothing else.
	 *
	 * ⭐ THE RULE (his, 2026-08-15): we do not advertise what a vendor keeps back,
	 * and we do not hide what a vendor advertises. We never invent an entry on a
	 * plugin's behalf. So this answers one question only, and the answer belongs
	 * to whoever registered the ability. It holds for every plugin on the site,
	 * today's and tomorrow's — nothing here is written for a particular vendor.
	 *
	 * ⛔ It does NOT answer "may anyone run this". That is a separate question,
	 * answered by what the tool does, and mixing the two published five ways to
	 * change a shop under the words "no sign-in needed". A mark that means
	 * "advertise me" cannot be read as "everyone may use me".
	 *
	 * Default false is the safe direction and the correct one: the Abilities API
	 * requires a permission callback at registration, so an ability is gated
	 * unless it says otherwise. Our own Registrar sets the flag FALSE deliberately,
	 * to keep Agentimus's tools off a public surface.
	 *
	 * @param mixed $ability The ability object.
	 * @return bool True when the ability declares itself advertised.
	 */
	private static function advertised( $ability ) {
		$meta = (array) self::read( $ability, 'get_meta', array() );
		$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();

		return ! empty( $mcp['public'] );
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
	/**
	 * Whether an ability is exposed as an MCP RESOURCE rather than a tool.
	 *
	 * The declaration is `meta.mcp.uri` ({@see \Agentimus\Abilities\Registrar::add()}'s
	 * `$mcp` argument); the flat `meta.uri` is accepted too, since that is where the
	 * readOnly hint already looks and an adapter version could put it either place.
	 *
	 * @param object $ability The ability object.
	 * @return bool
	 */
	private static function is_resource( $ability ) {
		return '' !== self::resource_uri( $ability );
	}

	/**
	 * The MCP resource URI an ability carries, or '' when it carries none (a tool).
	 *
	 * @param object $ability The ability object.
	 * @return string
	 */
	private static function resource_uri( $ability ) {
		$meta = (array) self::read( $ability, 'get_meta', array() );
		if ( ! empty( $meta['uri'] ) ) {
			return (string) $meta['uri'];
		}
		return ( isset( $meta['mcp']['uri'] ) && '' !== $meta['mcp']['uri'] ) ? (string) $meta['mcp']['uri'] : '';
	}

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
