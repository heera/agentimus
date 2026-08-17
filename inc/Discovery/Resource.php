<?php
/**
 * Resource — the normalizer + validator for one registered discovery entry.
 *
 * This is *the standard*: the shape third-party plugins hard-code when they
 * register. Field names and semantics here are frozen at spec 1.0. `normalize()`
 * returns a clean, fully-defaulted array or a WP_Error explaining the rejection
 * (surfaced to the admin Validation screen) so authors get actionable feedback.
 *
 * @package Agentimus
 */

namespace Agentimus\Discovery;

defined( 'ABSPATH' ) || exit;

final class Resource {

	/**
	 * Controlled vocabulary for `type`. Anything outside this set must use an
	 * `x-<vendor>-<name>` extension token, keeping the index queryable while
	 * staying open-ended.
	 *
	 * @var string[]
	 */
	const TYPES = array(
		'content', 'commerce', 'scheduling', 'courses', 'forms', 'crm', 'auth',
		'search', 'media', 'messaging', 'analytics', 'payments', 'directory', 'agent',
	);

	/** @var string[] Allowed endpoint transport types. */
	const ENDPOINT_TYPES = array( 'rest', 'graphql', 'mcp', 'openapi', 'a2a', 'soap', 'rpc' );

	/** @var string[] Allowed auth schemes. */
	const AUTH_TYPES = array( 'none', 'apikey', 'basic', 'oauth2', 'oidc', 'custom' );

	/** @var string[] Endpoint types that flatten into the envelope `apis[]`. */
	const API_TYPES = array( 'rest', 'graphql', 'openapi', 'soap', 'rpc' );

	/**
	 * Every kind this site knows: the built-in vocabulary, plus any kind a plugin
	 * has declared for itself.
	 *
	 * ⭐ HIS CALL, 2026-08-16: a plugin author must be able to add a new kind — and
	 * so must we, when this plugin grows one. A fixed list of fourteen words is a
	 * closed door, and the whole point of the registration hook is that we do not
	 * decide for a vendor what their thing is.
	 *
	 * ⭐ DECLARING A KIND IS THE DIFFERENCE. A plugin that adds its word here means
	 * it; a plugin that simply sends a word we have never heard of has probably
	 * mistyped one of ours (`comerce`, `store`). The first is published verbatim;
	 * the second is marked `x-` and its author told ({@see extension_type()}). One
	 * line of setup separates a deliberate new kind from a typo, which is exactly
	 * what the index needs to stay answerable.
	 *
	 * ⛔ The filter ADDS. It is handed an empty array, not the built-ins, so no
	 * plugin can remove `commerce` from under another plugin — a filter that
	 * returned its own list instead of merging would otherwise silently re-mark
	 * every other resource on the site and change what this site publishes.
	 *
	 * @return string[]
	 */
	public static function types() {
		$types = self::TYPES;

		/**
		 * Kinds of resource this site knows, beyond the built-in vocabulary.
		 *
		 *     add_filter( 'agentimus_resource_types', function ( $types ) {
		 *         $types[] = 'loyalty';
		 *         return $types;
		 *     } );
		 *
		 * Each must be a lowercase slug. The built-in kinds are always known and
		 * cannot be removed here.
		 *
		 * @param string[] $types Additional kinds. Empty to start.
		 */
		foreach ( (array) apply_filters( 'agentimus_resource_types', array() ) as $added ) {
			$added = self::type_token( $added );
			if ( '' === $added || in_array( $added, $types, true ) ) {
				continue;
			}
			$types[] = $added;
		}

		return $types;
	}

	/**
	 * Validate and normalize a raw registration array.
	 *
	 * @param array $raw Author-supplied entry.
	 * @return array|\WP_Error Clean resource array, or WP_Error on a fatal field.
	 */
	public static function normalize( $raw ) {
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'wpd_resource_invalid', __( 'A discovery resource must be an array.', 'agentimus' ) );
		}

		// --- id (required) ------------------------------------------------- //
		$id = isset( $raw['id'] ) ? sanitize_key( (string) $raw['id'] ) : '';
		if ( '' === $id || ! preg_match( '/^[a-z0-9](-?[a-z0-9]+)*$/', $id ) ) {
			return new \WP_Error(
				'wpd_resource_id',
				/* translators: %s: supplied id. */
				sprintf( __( 'Resource "id" must be a lowercase slug; got "%s".', 'agentimus' ), isset( $raw['id'] ) ? (string) $raw['id'] : '' )
			);
		}

		// --- title (required) ---------------------------------------------- //
		$title = isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
		if ( '' === $title ) {
			/* translators: %s: resource id. */
			return new \WP_Error( 'wpd_resource_title', sprintf( __( 'Resource "%s" is missing a title.', 'agentimus' ), $id ) );
		}

		// --- type (required; an undeclared kind is marked, not refused) ----- //
		$type = self::type_token( isset( $raw['type'] ) ? $raw['type'] : '' );
		if ( '' !== $type && ! in_array( $type, self::types(), true ) && ! preg_match( '/^x-[a-z0-9-]+$/', $type ) ) {
			$type = self::extension_type( $type );
		}
		if ( '' === $type ) {
			$asked = isset( $raw['type'] ) && is_scalar( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
			return new \WP_Error(
				'wpd_resource_type',
				'' === $asked
					/* translators: %s: resource id. */
					? sprintf( __( 'Resource "%s" is missing a type.', 'agentimus' ), $id )
					: sprintf(
						/* translators: 1: resource id, 2: supplied type. */
						__( 'Resource "%1$s" has a type ("%2$s") with no word in it. Use a known kind, or an "x-name" extension.', 'agentimus' ),
						$id,
						$asked
					)
			);
		}

		$clean = array(
			'id'           => $id,
			'title'        => $title,
			'type'         => $type,
			// `public`: may this Resource be PUBLISHED to anonymous callers? Default true — a
			// provider describing a public surface says nothing and nothing changes.
			//
			// A provider sets it FALSE when the thing it describes is structurally unusable by an
			// anonymous agent, so advertising it serves that agent nothing and merely hands anyone
			// who asks a map of the site's internals. Distinct from owner suppression (which is the
			// OWNER's decision, and which the admin screen surfaces so it can be reversed): this is
			// the PROVIDER stating a fact about its own resource. Both are honoured by
			// {@see Envelope::published_resources()}; neither hides it from the admin.
			'public'       => ! isset( $raw['public'] ) || (bool) $raw['public'],
			'description'  => isset( $raw['description'] ) ? sanitize_text_field( (string) $raw['description'] ) : '',
			'version'      => isset( $raw['version'] ) ? sanitize_text_field( (string) $raw['version'] ) : '',
			'capabilities' => self::string_list( isset( $raw['capabilities'] ) ? $raw['capabilities'] : array() ),
			// `abilities`: names of WP Abilities API units this resource fulfils
			// (the executable bridge for the intent strings in `capabilities`).
			'abilities'    => self::string_list( isset( $raw['abilities'] ) ? $raw['abilities'] : array() ),
			// `tools`: MCP-shaped tool definitions (typically projected from the
			// Abilities registry by the AbilitiesApi adapter).
			'tools'        => self::tools( isset( $raw['tools'] ) ? $raw['tools'] : array() ),
			'endpoints'    => self::endpoints( isset( $raw['endpoints'] ) ? $raw['endpoints'] : array() ),
			'schemas'      => self::url_list( isset( $raw['schemas'] ) ? $raw['schemas'] : array() ),
			'auth'         => self::auth( isset( $raw['auth'] ) ? $raw['auth'] : array() ),
			'well_known'   => self::well_known_refs( isset( $raw['well_known'] ) ? $raw['well_known'] : array() ),
			'agent'        => self::agent( isset( $raw['agent'] ) ? $raw['agent'] : array() ),
			'docs'         => isset( $raw['docs'] ) ? self::url( $raw['docs'] ) : '',
			// provider is always derived, never trusted from input.
			'provider'     => self::detect_provider(),
		);

		return $clean;
	}

	/* ---------------------------------------------------------------------- *
	 *  Field coercers
	 * ---------------------------------------------------------------------- */

	/**
	 * A word outside the vocabulary, written in the form the standard reserves
	 * for exactly that: `x-<word>`.
	 *
	 * ⭐ HIS CALL, 2026-08-16: mark it and keep the entry. Refusing the type used
	 * to throw away the WHOLE resource, so one wrong word made a plugin vanish
	 * from the document — FluentCommunity said "community" and its row simply was
	 * not there. A plugin loses nothing for a word we don't know, and the reader
	 * still gets the entry.
	 *
	 * ⛔ And still not a free-for-all. The known words are what makes the index
	 * answerable ("every commerce resource on every site"); letting any word
	 * through would mean a typo — `comerce`, `store` — never matches anything and
	 * nobody is ever told. The `x-` mark keeps the two apart: a reader can always
	 * tell a known kind from a word this plugin made up.
	 *
	 * ⭐ A plugin that MEANT its new kind never lands here: it declares the word
	 * with `agentimus_resource_types` ({@see types()}) and is published verbatim.
	 * This path is for the word nobody declared, which is usually a mistake.
	 *
	 * The mark is not applied silently — {@see \Agentimus\Discovery\Registry::add()}
	 * records a warning naming the word, what happened to it, and how to declare a
	 * kind on purpose.
	 *
	 * @param string $type A kind that is not one this site knows.
	 * @return string The `x-` token, or '' when there is nothing usable to mark.
	 */
	public static function extension_type( $type ) {
		$token = self::type_token( $type );
		// '' is nothing to mark, and so is a bare 'x': both `x-` and `x` fold to
		// it, and neither is a kind — publishing "x-x" would be marking a word
		// that was never there.
		if ( '' === $token || 'x' === $token ) {
			return '';
		}
		// Already marked: re-marking would publish "x-x-loyalty".
		if ( 0 === strpos( $token, 'x-' ) ) {
			return $token;
		}
		return 'x-' . $token;
	}

	/**
	 * One kind, written the one way this file compares kinds.
	 *
	 * ⭐ BOTH SIDES FOLD THE SAME WAY, and that is the whole job. `sanitize_key()`
	 * keeps underscores and the token form does not, so a plugin that declared
	 * `my_kind` and then registered a resource of type `my_kind` would have failed
	 * to match its OWN declaration — the word would have been marked `x-my-kind`
	 * while the vocabulary held `my_kind`. Folding every run of anything that is
	 * not a letter or digit into one hyphen, on both sides, removes that class of
	 * bug rather than documenting it.
	 *
	 * @param mixed $type Whatever an author wrote.
	 * @return string A `[a-z0-9-]` token, or '' when nothing is left of it.
	 */
	public static function type_token( $type ) {
		if ( ! is_scalar( $type ) ) {
			return ''; // An array or an object is not a word.
		}
		return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $type ) ), '-' );
	}

	/**
	 * Normalize endpoints. Strings become `{url, type:"rest"}`; arrays are
	 * validated. Entries without a usable url are dropped.
	 *
	 * @param mixed $value Raw endpoints.
	 * @return array[]
	 */
	private static function endpoints( $value ) {
		$out = array();
		foreach ( (array) $value as $endpoint ) {
			if ( is_string( $endpoint ) ) {
				$endpoint = array( 'url' => $endpoint );
			}
			if ( ! is_array( $endpoint ) || empty( $endpoint['url'] ) ) {
				continue;
			}
			$url = self::url( $endpoint['url'] );
			if ( '' === $url ) {
				continue;
			}
			$type    = isset( $endpoint['type'] ) ? sanitize_key( (string) $endpoint['type'] ) : 'rest';
			$methods = array();
			foreach ( (array) ( isset( $endpoint['methods'] ) ? $endpoint['methods'] : array() ) as $m ) {
				$m = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $m ) );
				if ( '' !== $m ) {
					$methods[] = $m;
				}
			}
			$out[] = array(
				'url'         => $url,
				'type'        => in_array( $type, self::ENDPOINT_TYPES, true ) ? $type : 'rest',
				'methods'     => array_values( array_unique( $methods ) ),
				'auth'        => isset( $endpoint['auth'] ) ? sanitize_key( (string) $endpoint['auth'] ) : '',
				'description' => isset( $endpoint['description'] ) ? sanitize_text_field( (string) $endpoint['description'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * Normalize the auth descriptor.
	 *
	 * @param mixed $value Raw auth.
	 * @return array
	 */
	private static function auth( $value ) {
		$value = is_array( $value ) ? $value : array();
		$type  = isset( $value['type'] ) ? sanitize_key( (string) $value['type'] ) : 'none';
		return array(
			'type'   => in_array( $type, self::AUTH_TYPES, true ) ? $type : 'none',
			'oidc'   => isset( $value['oidc'] ) ? self::url( $value['oidc'] ) : '',
			'scopes' => self::string_list( isset( $value['scopes'] ) ? $value['scopes'] : array() ),
			'docs'   => isset( $value['docs'] ) ? self::url( $value['docs'] ) : '',
		);
	}

	/**
	 * Normalize an A2A-style agent-card fragment. Empty input yields an empty
	 * array (the resource simply won't appear under `agents[]`).
	 *
	 * @param mixed $value Raw agent fragment.
	 * @return array
	 */
	private static function agent( $value ) {
		$value = is_array( $value ) ? $value : array();
		if ( empty( $value ) ) {
			return array();
		}
		$skills = array();
		foreach ( (array) ( isset( $value['skills'] ) ? $value['skills'] : array() ) as $skill ) {
			if ( ! is_array( $skill ) || empty( $skill['id'] ) ) {
				continue;
			}
			$skills[] = array(
				'id'          => sanitize_key( (string) $skill['id'] ),
				'description' => isset( $skill['description'] ) ? sanitize_text_field( (string) $skill['description'] ) : '',
			);
		}
		return array(
			'name'        => isset( $value['name'] ) ? sanitize_text_field( (string) $value['name'] ) : '',
			'description' => isset( $value['description'] ) ? sanitize_text_field( (string) $value['description'] ) : '',
			'skills'      => $skills,
			'endpoint'    => isset( $value['endpoint'] ) ? self::url( $value['endpoint'] ) : '',
			'auth'        => isset( $value['auth'] ) ? sanitize_key( (string) $value['auth'] ) : '',
		);
	}

	/**
	 * Normalize a list of MCP-shaped tool definitions. Each tool mirrors the MCP
	 * `tools/list` shape: a `name` (optionally `namespace/name`), human label,
	 * description, JSON-Schema input/output, behaviour annotations and the auth
	 * scheme required to invoke it.
	 *
	 * @param mixed $value Raw tools.
	 * @return array[]
	 */
	private static function tools( $value ) {
		$out = array();
		foreach ( (array) $value as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) $tool['name'] );
			if ( ! preg_match( '#^[a-z0-9][a-z0-9_.-]*(/[a-z0-9][a-z0-9_.-]*)?$#i', $name ) ) {
				continue; // Accept "name" or "namespace/name"; reject anything odd.
			}
			$out[] = array(
				'name'         => $name,
				// 'tool' (runnable) or 'resource' (a document an assistant attaches).
				// MCP keeps the two in separate lists and so must every count on the
				// admin screen — flattened, the Agentimus namespace read "25 tools"
				// for 21 tools and 4 documents. INTERNAL: stripped from the served
				// document by Envelope::wire_resource(), for the same reason `public`
				// is — discovery.json ships a canonical schema and an extra key would
				// make a strict consumer reject it. Anything unmarked stays a tool,
				// which is what a third-party provider's plain list has always meant.
				'kind'         => ( isset( $tool['kind'] ) && 'resource' === $tool['kind'] ) ? 'resource' : 'tool',
				// The VENDOR's own decision about this one tool: advertise it, or
				// keep it out of the served document. Not ours to make — we neither
				// publish what a vendor kept back nor hide what it published.
				// INTERNAL, like `kind`: {@see Envelope::wire_resource()} both acts
				// on it and strips it. Default TRUE, because a provider handing us a
				// plain list is advertising all of it.
				'public'       => ! isset( $tool['public'] ) || (bool) $tool['public'],
				'title'        => isset( $tool['title'] ) ? sanitize_text_field( (string) $tool['title'] ) : '',
				'description'  => isset( $tool['description'] ) ? sanitize_text_field( (string) $tool['description'] ) : '',
				'inputSchema'  => self::schema( isset( $tool['inputSchema'] ) ? $tool['inputSchema'] : array() ),
				'outputSchema' => self::schema( isset( $tool['outputSchema'] ) ? $tool['outputSchema'] : array() ),
				'annotations'  => self::annotations( isset( $tool['annotations'] ) ? $tool['annotations'] : array() ),
				'auth'         => isset( $tool['auth'] ) ? sanitize_key( (string) $tool['auth'] ) : 'none',
				// Resource-kind entries only: the public address the document lives
				// at, so the admin can link the row. INTERNAL like `kind` — stripped
				// from the served document by Envelope::wire_resource().
				'uri'          => isset( $tool['uri'] ) ? esc_url_raw( (string) $tool['uri'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * A JSON-Schema fragment is structural data — keep it as an array, drop it if
	 * it isn't one. (The values are emitted as-is; they come from the trusted
	 * Abilities registry or a registering plugin's own declaration.)
	 *
	 * @param mixed $value Raw schema.
	 * @return array
	 */
	private static function schema( $value ) {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * MCP tool annotations — a flat map of behaviour hints (readOnlyHint,
	 * destructiveHint, idempotentHint…) coerced to booleans.
	 *
	 * @param mixed $value Raw annotations.
	 * @return array<string,bool>
	 */
	private static function annotations( $value ) {
		$out = array();
		foreach ( (array) $value as $key => $flag ) {
			// MCP annotation keys are camelCase (readOnlyHint, destructiveHint…);
			// strip unsafe chars but preserve case rather than sanitize_key().
			$key = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key );
			if ( '' !== $key ) {
				$out[ $key ] = (bool) $flag;
			}
		}
		return $out;
	}

	/**
	 * Normalize `well_known` references carried on a resource. These are pointers
	 * the envelope lists; actually *serving* a well-known doc is done through
	 * Registry::add_well_known().
	 *
	 * @param mixed $value Raw refs.
	 * @return array[]
	 */
	private static function well_known_refs( $value ) {
		$out = array();
		foreach ( (array) $value as $ref ) {
			if ( is_string( $ref ) ) {
				$ref = array( 'name' => $ref );
			}
			if ( ! is_array( $ref ) || empty( $ref['name'] ) ) {
				continue;
			}
			$name = ltrim( sanitize_file_name( (string) $ref['name'] ), '/' );
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'name' => $name,
				'url'  => isset( $ref['url'] ) ? self::url( $ref['url'] ) : home_url( '/.well-known/' . $name ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 *  Primitives
	 * ---------------------------------------------------------------------- */

	/**
	 * Sanitize a list of plain strings (array, or comma/newline string).
	 *
	 * @param mixed $value Raw.
	 * @return string[]
	 */
	private static function string_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		$value = array_map( 'sanitize_text_field', array_map( 'trim', (array) $value ) );
		return array_values( array_filter( $value, static function ( $v ) {
			return '' !== $v;
		} ) );
	}

	/**
	 * Sanitize a list of URLs (site-relative paths permitted).
	 *
	 * @param mixed $value Raw.
	 * @return string[]
	 */
	private static function url_list( $value ) {
		$out = array();
		foreach ( (array) $value as $url ) {
			$url = self::url( $url );
			if ( '' !== $url ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize one URL, allowing site-relative paths ("/wp-json/...") through.
	 * Relative inputs are kept relative here; the Envelope absolutizes on output.
	 *
	 * @param mixed $url Raw.
	 * @return string
	 */
	public static function url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		// Site-relative path ("/wp-json/..."): strip anything that isn't a safe
		// path/query character and keep it relative; the Envelope absolutizes later.
		if ( '/' === $url[0] ) {
			$path = preg_replace( '#[^A-Za-z0-9._~:/?\#\[\]@!$&\'()*+,;=%-]#', '', $url );
			return '/' . ltrim( (string) $path, '/' );
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Best-effort attribution. Walks out past this plugin's own frames and
	 * WordPress core's hook-dispatch frames; the first frame outside both is the
	 * registrant. A registrant in a plugin, a mu-plugin, or a theme is all
	 * third-party — what matters for owner curation is "Agentimus's own code vs
	 * everyone else," so anything that is not us is attributed to its source and
	 * stays owner-curatable. Only a pure internal call (every frame ours/core —
	 * e.g. a built-in auto-discovery adapter) attributes to this plugin itself.
	 * Authors cannot spoof this — it overwrites any author-supplied `provider`.
	 *
	 * @return array{plugin:string}
	 */
	private static function detect_provider() {
		$self    = function_exists( 'plugin_basename' ) ? plugin_basename( AGENTIMUS_FILE ) : basename( AGENTIMUS_FILE );
		$ours    = wp_normalize_path( AGENTIMUS_DIR );
		$wpinc   = wp_normalize_path( ABSPATH . WPINC . '/' );
		$content = wp_normalize_path( WP_CONTENT_DIR );
		$plugins = wp_normalize_path( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins' );
		$mu      = wp_normalize_path( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' );
		$themes  = wp_normalize_path( $content . '/themes' );

		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );
			if ( 0 === strpos( $file, $ours ) || 0 === strpos( $file, $wpinc ) ) {
				continue; // Our own code, or core's do_action dispatch — keep climbing.
			}
			// First frame outside Agentimus and core = the registrant. Attribute by
			// where it lives; check mu-plugins before plugins (mu lives outside the
			// plugins dir, but be explicit) and themes too — none of these are us.
			if ( 0 === strpos( $file, $plugins ) ) {
				return array( 'plugin' => plugin_basename( $file ) );
			}
			if ( 0 === strpos( $file, $mu ) ) {
				return array( 'plugin' => 'mu-plugins/' . basename( $file ) );
			}
			if ( 0 === strpos( $file, $themes ) ) {
				$rel = ltrim( substr( $file, strlen( $themes ) ), '/' );
				return array( 'plugin' => 'theme/' . strtok( $rel, '/' ) );
			}
			// Reached a caller that is neither ours/core nor a plugin/mu/theme — the
			// request entry point (index.php, WP-CLI). A real registrant always sits
			// ABOVE the entry point, so if we got here without matching one, this is an
			// internal/adapter call: attribute to us.
			break;
		}
		return array( 'plugin' => $self );
	}
}
