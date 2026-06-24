<?php
/**
 * Settings store — a single option array, with defaults, typed getters and
 * sanitisation. The one source of truth shared by every module and the REST API.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'agentimus_settings';

	/**
	 * Default settings. Identity defaults stay deliberately empty so the admin
	 * is nudged to fill in a real author/organisation profile rather than ship
	 * a generic one.
	 *
	 * @return array
	 */
	public function defaults() {
		$defaults = array(
			'enable_llms_txt'  => true,
			'enable_llms_full' => true,
			'enable_markdown'  => true,
			'enable_robots'    => true,
			'enable_schema'    => true,
			'enable_activity'  => true,
			'enable_sitemap'   => true, // Gap-only fallback: stands down when core/SEO provides one, so it's safe on by default.
			'enable_security_txt' => false, // Opt-in: generate /.well-known/security.txt only when asked AND no file/other plugin already provides one.
			'enable_signing'   => false,    // Opt-in: RFC 9421 signatures on the discovery docs + a published Web Bot Auth key directory.
			'llms_full_posts'  => 50,
			'llms_full_max_kb' => 1024, // Hard byte budget for /llms-full.txt (KB): generation stops cleanly here and links the index. Keeps the file ingestible and under common 1 MB object-cache row limits.
			'post_types'       => self::default_post_types(),
			'rest_namespaces'  => array(), // Owner-curated REST namespaces to publish in discovery (opt-in; empty = none).
			'oauth_auth_server' => '',     // Optional OAuth authorization-server URL; when set, serve RFC 9728 protected-resource metadata. Never fabricate RFC 8414.
			'suppressed_resources' => array(), // Owner opt-OUT: ids of provider-registered Resources to hide from all output. Declared Resources default to published (spec §04), so empty = publish everything a provider declared.
			'identity'         => array(
				'entity_type'   => 'Person', // Person, or an Organization subtype (Organization, LocalBusiness, Store, …) — see entity_types().
				'name'          => get_bloginfo( 'name' ),
				'role'          => '',
				'about'         => '',
				'not_description' => '', // What the site is NOT — a disambiguation so agents don't miscategorize it (Schema.org disambiguatingDescription).
				'audience'      => '', // Who the site is for (Schema.org audience.audienceType).
				'contact_email' => '', // Opt-in; published in discovery.json only if set.
				'expertise'     => array(),
				'same_as'       => array(),
				'services'      => array(), // Opt-in: owner-declared services [{name, description, url}] → Schema.org Service. Empty = none (never guessed from content).
			),
			'security'         => array(
				'contacts'            => array(), // Extra Contact URIs/emails beyond identity.contact_email (which seeds the first one).
				'policy'              => '',       // URL to the vuln-disclosure policy.
				'acknowledgments'     => '',       // URL to a hall-of-fame / thanks page.
				'encryption'          => '',       // URL to an OpenPGP key.
				'hiring'              => '',       // URL to security job listings.
				'preferred_languages' => '',       // e.g. "en, fr"; empty falls back to the site locale.
				'expires_days'        => 182,      // RFC 9116 SHOULD keep Expires < 1 year.
			),
			'content_signal'   => array(
				'search'   => true,
				'ai_input' => true,
				'ai_train' => false,
			),
			'blocked_trainers' => self::known_trainers(),
			// AI-usage signals beyond robots.txt: publish the SAME ai-train decision
			// where robots.txt can't reach — a W3C TDM Reservation Protocol response
			// header and /.well-known/tdmrep.json. On by default so the existing
			// "no AI training" default stance is asserted in every channel, not just
			// the advisory one ("one decision, every channel").
			'enable_ai_header'    => true,  // tdm-reservation header on content pages (only when training is blocked).
			'enable_tdmrep'       => true,  // serve /.well-known/tdmrep.json (only when training is blocked).
			'ai_noai_header'      => false, // also send the non-standard X-Robots-Tag: noai (best-effort).
			'tdm_policy_url'      => '',     // optional URL to the owner's TDM / AI-usage policy.
			// Optional HARD enforcement (opt-in). robots.txt above is advisory; this
			// actually refuses the request with a 403. Off by default so a fresh
			// install never silently blocks anyone.
			'block_agents'     => false, // Master switch for denying agents at the generated endpoints.
			'block_spoofed'    => true,  // When blocking is on, also deny spoofed/legacy-device UAs (the "Likely spoof/scanner" class). No effect while block_agents is false.
			'blocked_agents'   => array(), // Owner's custom user-agent substrings to deny (case-insensitive). Empty = none.
			'allowed_agents'   => array(), // Owner's trust-list (via the activity panel's "Allow"): never blocked, never flagged for review. Empty = none.
		);

		/**
		 * Filter the default settings.
		 *
		 * @param array $defaults Default settings.
		 */
		return apply_filters( 'agentimus_default_settings', $defaults );
	}

	/**
	 * The canonical catalogue of known model-training crawlers. Seeds the default
	 * blocklist and powers the "add a known crawler" suggestions in the admin, so
	 * a user who removes one can always find its exact user-agent again.
	 *
	 * @return string[]
	 */
	public static function known_trainers() {
		$known = array(
			'Amazonbot',
			'Applebot-Extended',
			'Bytespider',
			'CCBot',
			'ClaudeBot',
			'GPTBot',
			'Google-Extended',
			'meta-externalagent',
		);

		/**
		 * Filter the known training-crawler catalogue.
		 *
		 * @param string[] $known User-agent tokens.
		 */
		$known = (array) apply_filters( 'agentimus_known_trainers', $known );
		return array_values( array_unique( array_filter( array_map( 'trim', $known ) ) ) );
	}

	/**
	 * Suggested user-agents for the hard-block denylist: aggressive SEO/scraper
	 * crawlers that frequently ignore robots.txt. Powers the "add a known scanner"
	 * chips in the admin — purely suggestions; nothing is denied until the owner
	 * adds it AND turns blocking on. (The legacy-device spoof class is handled
	 * separately by the block_spoofed heuristic, so it isn't duplicated here.)
	 *
	 * @return string[]
	 */
	public static function known_scanners() {
		$known = array(
			'AhrefsBot',
			'SemrushBot',
			'DotBot',
			'MJ12bot',
			'BLEXBot',
			'PetalBot',
			'DataForSeoBot',
			'SeekportBot',
			'serpstatbot',
			'ZoominfoBot',
		);

		/**
		 * Filter the suggested scanner catalogue shown in the admin denylist UI.
		 *
		 * @param string[] $known User-agent tokens.
		 */
		$known = (array) apply_filters( 'agentimus_known_scanners', $known );
		return array_values( array_unique( array_filter( array_map( 'trim', $known ) ) ) );
	}

	/**
	 * The privacy-safe default content selection: posts and pages only. Every
	 * other public post type (WooCommerce products, CRM/support/forms/boards
	 * CPTs, …) is opt-IN, so a fresh install never advertises or dumps content
	 * the owner didn't choose. Falls back to all public types only on the rare
	 * site that registers neither `post` nor `page`.
	 *
	 * @return string[]
	 */
	public static function default_post_types() {
		$available = Content::available();
		$safe      = array_values( array_intersect( array( 'post', 'page' ), $available ) );
		return ! empty( $safe ) ? $safe : $available;
	}

	/**
	 * The full, merged settings array.
	 *
	 * @return array
	 */
	public function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$all   = wp_parse_args( $saved, $this->defaults() );
		// Merge the identity sub-array too (wp_parse_args is shallow).
		$all['identity'] = wp_parse_args(
			isset( $saved['identity'] ) && is_array( $saved['identity'] ) ? $saved['identity'] : array(),
			$this->defaults()['identity']
		);
		$all['security'] = wp_parse_args(
			isset( $saved['security'] ) && is_array( $saved['security'] ) ? $saved['security'] : array(),
			$this->defaults()['security']
		);

		/**
		 * Filter the resolved settings on read.
		 *
		 * @param array $all Resolved settings.
		 */
		return apply_filters( 'agentimus_settings', $all );
	}

	/**
	 * A single top-level value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * A boolean toggle.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function enabled( $key ) {
		return (bool) $this->get( $key, false );
	}

	/**
	 * An identity sub-value (name, about, expertise, …).
	 *
	 * @param string $key     Identity key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function identity( $key, $default = null ) {
		$identity = (array) $this->get( 'identity', array() );
		return array_key_exists( $key, $identity ) ? $identity[ $key ] : $default;
	}

	/**
	 * Persist a (raw) settings array after sanitising it.
	 *
	 * @param array $input Raw input (e.g. from REST).
	 * @return array The sanitised, stored settings.
	 */
	public function update( array $input ) {
		$clean = $this->sanitize( $input );
		update_option( self::OPTION, $clean );
		Cache::flush();
		return $clean;
	}

	/**
	 * Add one user-agent token to the hard-block denylist AND arm enforcement —
	 * the persistence side of the activity panel's one-click "Block this client".
	 * The denylist is inert while block_agents is off, so a "block" that didn't turn
	 * blocking on would silently do nothing; flipping it on here makes the see-it →
	 * block-it loop actually bite. Reads the full resolved settings and writes them
	 * back through update() (a partial update would reset omitted keys to defaults).
	 * Idempotent: a token already present (case-insensitively) is not duplicated.
	 *
	 * @param string $token Raw user-agent substring (or glob/regex) to deny.
	 * @return array The stored settings.
	 */
	public function block_agent( $token ) {
		$token = substr( trim( (string) $token ), 0, 200 );
		$all   = $this->all();
		if ( '' !== $token ) {
			$list  = array_values( (array) $all['blocked_agents'] );
			$lower = array_map( 'strtolower', $list );
			if ( ! in_array( strtolower( $token ), $lower, true ) ) {
				$list[] = $token;
			}
			$all['blocked_agents'] = $list;
		}
		$all['block_agents'] = true;
		return $this->update( $all );
	}

	/**
	 * Arm hard enforcement for the spoofed/legacy-device class — the action behind
	 * "Block scanners" on a flagged spoof row. Turns on the master switch and the
	 * spoof heuristic together (no per-UA token: the class is matched by
	 * {@see \Agentimus\Activity\Classifier::is_spoof()}). Full read-modify-write, as
	 * in {@see block_agent()}.
	 *
	 * @return array The stored settings.
	 */
	public function block_spoofed_class() {
		$all                  = $this->all();
		$all['block_agents']  = true;
		$all['block_spoofed'] = true;
		return $this->update( $all );
	}

	/**
	 * Add one user-agent token to the owner's trust-list — the "Allow" action on a
	 * flagged row. An allowed agent is treated like a protected search engine: never
	 * blocked (even by a broad rule) and never flagged for review again. Full
	 * read-modify-write, deduped case-insensitively (see {@see block_agent()}).
	 *
	 * @param string $token Raw user-agent substring to trust.
	 * @return array The stored settings.
	 */
	public function allow_agent( $token ) {
		$token = substr( trim( (string) $token ), 0, 200 );
		$all   = $this->all();
		if ( '' !== $token ) {
			$list  = array_values( (array) $all['allowed_agents'] );
			$lower = array_map( 'strtolower', $list );
			if ( ! in_array( strtolower( $token ), $lower, true ) ) {
				$list[] = $token;
			}
			$all['allowed_agents'] = $list;
		}
		return $this->update( $all );
	}

	/**
	 * Restore every setting to its factory default, wiping the stored option and
	 * any generated caches. Identity text, crawler policy and feature toggles all
	 * revert. Returns the resolved (default) settings.
	 *
	 * @return array
	 */
	public function reset() {
		delete_option( self::OPTION );
		add_option( self::OPTION, $this->defaults() );
		Cache::flush();

		/**
		 * Fires after settings are reset to defaults (a Pro add-on can clear its
		 * own state too).
		 */
		do_action( 'agentimus_settings_reset' );

		return $this->all();
	}

	/**
	 * Seed defaults on activation if the option is absent.
	 */
	public function ensure_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $this->defaults() );
		}
	}

	/**
	 * The selectable schema.org entity types (filterable). 'Person' is the human
	 * case; the rest are Organization subtypes (a shop is a Store → LocalBusiness →
	 * Organization). Add-ons/devs can extend via the `agentimus_entity_types` filter
	 * (e.g. add 'Restaurant'). 'Person' is always guaranteed present so the
	 * sanitiser's fallback stays valid.
	 *
	 * @return string[]
	 */
	public function entity_types() {
		$types = array( 'Person', 'Organization', 'LocalBusiness', 'Store' );
		$types = (array) apply_filters( 'agentimus_entity_types', $types );
		$types = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );
		return in_array( 'Person', $types, true ) ? $types : array_merge( array( 'Person' ), $types );
	}

	/**
	 * Sanitise a raw settings array against the schema.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( array $input ) {
		$defaults = $this->defaults();
		$clean    = array();

		foreach ( array( 'enable_llms_txt', 'enable_llms_full', 'enable_markdown', 'enable_robots', 'enable_schema', 'enable_activity', 'enable_sitemap', 'enable_security_txt', 'enable_signing' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		$posts                    = isset( $input['llms_full_posts'] ) ? (int) $input['llms_full_posts'] : $defaults['llms_full_posts'];
		$clean['llms_full_posts'] = max( 1, min( 500, $posts ) );

		$kb                        = isset( $input['llms_full_max_kb'] ) ? (int) $input['llms_full_max_kb'] : $defaults['llms_full_max_kb'];
		$clean['llms_full_max_kb'] = max( 64, min( 20480, $kb ) );

		$clean['oauth_auth_server'] = isset( $input['oauth_auth_server'] ) ? esc_url_raw( (string) $input['oauth_auth_server'] ) : '';

		$available           = Content::available();
		$requested           = $this->sanitize_list( isset( $input['post_types'] ) ? $input['post_types'] : array(), 'sanitize_key' );
		$clean['post_types'] = array_values( array_intersect( $requested, $available ) );
		if ( empty( $clean['post_types'] ) ) {
			// Never store an empty set (that would hide all content) — but never
			// silently widen to ALL public types either, which would leak every
			// CPT. Keep the current selection if there is one; otherwise fall
			// back to the privacy-safe default (posts + pages).
			$current             = array_values( array_intersect( (array) ( new self() )->get( 'post_types', array() ), $available ) );
			$clean['post_types'] = ! empty( $current ) ? $current : self::default_post_types();
		}

		// Owner-curated REST namespaces to publish (e.g. "wc/store/v1"). Keep only
		// namespace-safe characters; nothing is published unless explicitly listed.
		$ns_in                    = isset( $input['rest_namespaces'] ) && is_array( $input['rest_namespaces'] ) ? $input['rest_namespaces'] : array();
		$clean['rest_namespaces'] = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $ns ) {
							return preg_replace( '#[^a-z0-9/_-]#', '', strtolower( (string) $ns ) );
						},
						$ns_in
					)
				)
			)
		);

		// Owner opt-OUT list: ids of provider-declared Resources to suppress from
		// all served output. Stored by id so the choice survives the provider later
		// changing that Resource (spec §04, M14). Ids match the Resource slug shape.
		$sup_in                        = isset( $input['suppressed_resources'] ) && is_array( $input['suppressed_resources'] ) ? $input['suppressed_resources'] : array();
		$clean['suppressed_resources'] = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $id ) {
							return trim( (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $id ) ), '-' );
						},
						$sup_in
					)
				)
			)
		);

		// Content-Signal is a fixed vocabulary (search / ai-input / ai-train),
		// stored as booleans so no free-text can reach the public robots.txt.
		$signal_in               = isset( $input['content_signal'] ) && is_array( $input['content_signal'] ) ? $input['content_signal'] : array();
		$clean['content_signal'] = array(
			'search'   => ! empty( $signal_in['search'] ),
			'ai_input' => ! empty( $signal_in['ai_input'] ),
			'ai_train' => ! empty( $signal_in['ai_train'] ),
		);

		$identity_in          = isset( $input['identity'] ) && is_array( $input['identity'] ) ? $input['identity'] : array();
		$type                 = isset( $identity_in['entity_type'] ) ? (string) $identity_in['entity_type'] : 'Person';
		$clean['identity']    = array(
			'entity_type' => in_array( $type, $this->entity_types(), true ) ? $type : 'Person',
			'name'        => isset( $identity_in['name'] ) ? sanitize_text_field( (string) $identity_in['name'] ) : '',
			'role'        => isset( $identity_in['role'] ) ? sanitize_text_field( (string) $identity_in['role'] ) : '',
			'about'         => isset( $identity_in['about'] ) ? sanitize_textarea_field( (string) $identity_in['about'] ) : '',
			'not_description' => isset( $identity_in['not_description'] ) ? sanitize_textarea_field( (string) $identity_in['not_description'] ) : '',
			'audience'      => isset( $identity_in['audience'] ) ? sanitize_text_field( (string) $identity_in['audience'] ) : '',
			'contact_email' => isset( $identity_in['contact_email'] ) ? sanitize_email( (string) $identity_in['contact_email'] ) : '',
			'expertise'     => $this->sanitize_list( isset( $identity_in['expertise'] ) ? $identity_in['expertise'] : array(), 'sanitize_text_field' ),
			'same_as'       => $this->sanitize_list( isset( $identity_in['same_as'] ) ? $identity_in['same_as'] : array(), 'esc_url_raw' ),
			'services'      => $this->sanitize_services( isset( $identity_in['services'] ) ? $identity_in['services'] : array() ),
		);

		// security.txt (RFC 9116) fields. Contacts accept an email or an http(s)/
		// mailto/tel URI; the rest are URLs; the freshness window is clamped < 1 year.
		$security_in       = isset( $input['security'] ) && is_array( $input['security'] ) ? $input['security'] : array();
		$clean['security'] = array(
			'contacts'            => $this->sanitize_list( isset( $security_in['contacts'] ) ? $security_in['contacts'] : array(), array( $this, 'sanitize_contact_value' ) ),
			'policy'              => isset( $security_in['policy'] ) ? esc_url_raw( (string) $security_in['policy'] ) : '',
			'acknowledgments'     => isset( $security_in['acknowledgments'] ) ? esc_url_raw( (string) $security_in['acknowledgments'] ) : '',
			'encryption'          => isset( $security_in['encryption'] ) ? esc_url_raw( (string) $security_in['encryption'] ) : '',
			'hiring'              => isset( $security_in['hiring'] ) ? esc_url_raw( (string) $security_in['hiring'] ) : '',
			'preferred_languages' => isset( $security_in['preferred_languages'] ) ? sanitize_text_field( (string) $security_in['preferred_languages'] ) : '',
			'expires_days'        => isset( $security_in['expires_days'] ) ? max( 1, min( 365, (int) $security_in['expires_days'] ) ) : 182,
		);

		$trainers                 = isset( $input['blocked_trainers'] ) ? $input['blocked_trainers'] : $defaults['blocked_trainers'];
		$clean['blocked_trainers'] = $this->sanitize_list( $trainers, 'sanitize_text_field' );

		// AI-usage signal channels. The two default-ON channels use the isset-guard
		// (like block_spoofed) so a partial programmatic update that omits the key
		// keeps the default rather than silently going dark; the default-OFF extras
		// are a plain ! empty().
		$clean['enable_ai_header'] = ! isset( $input['enable_ai_header'] ) ? $defaults['enable_ai_header'] : ! empty( $input['enable_ai_header'] );
		$clean['enable_tdmrep']    = ! isset( $input['enable_tdmrep'] ) ? $defaults['enable_tdmrep'] : ! empty( $input['enable_tdmrep'] );
		$clean['ai_noai_header']   = ! empty( $input['ai_noai_header'] );
		$clean['tdm_policy_url']    = isset( $input['tdm_policy_url'] ) ? esc_url_raw( (string) $input['tdm_policy_url'] ) : '';

		// Optional hard-block enforcement. block_spoofed defaults true and is only
		// flipped off by an explicit false, so a client that omits the key (e.g. a
		// partial programmatic update) keeps the safe default rather than silently
		// disabling the spoof heuristic.
		$clean['block_agents']   = ! empty( $input['block_agents'] );
		$clean['block_spoofed']  = ! isset( $input['block_spoofed'] ) ? $defaults['block_spoofed'] : ! empty( $input['block_spoofed'] );
		$agents                  = isset( $input['blocked_agents'] ) ? $input['blocked_agents'] : array();
		$clean['blocked_agents'] = $this->sanitize_list( $agents, 'sanitize_text_field' );
		$allowed                 = isset( $input['allowed_agents'] ) ? $input['allowed_agents'] : array();
		$clean['allowed_agents'] = $this->sanitize_list( $allowed, 'sanitize_text_field' );

		/**
		 * Filter the sanitised settings before they are stored.
		 *
		 * @param array $clean Sanitised settings.
		 * @param array $input Raw input.
		 */
		return apply_filters( 'agentimus_sanitize_settings', $clean, $input );
	}

	/**
	 * Sanitise a list of strings (array, or newline/comma string) and drop blanks.
	 *
	 * @param mixed    $value    Raw list.
	 * @param callable $callback Per-item sanitiser.
	 * @return string[]
	 */
	private function sanitize_list( $value, $callback ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		$value = array_map( $callback, array_map( 'trim', (array) $value ) );
		return array_values( array_filter( $value, static function ( $item ) {
			return '' !== $item;
		} ) );
	}

	/**
	 * Sanitise the owner-declared services list into clean `{name, description, url}`
	 * rows. A row with no name is dropped (a nameless service is meaningless);
	 * description and url are optional. Accepts the array the UI sends.
	 *
	 * @param mixed $value Raw services list.
	 * @return array<int,array{name:string,description:string,url:string}>
	 */
	private function sanitize_services( $value ) {
		$out = array();
		foreach ( (array) $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'name'        => $name,
				'description' => isset( $row['description'] ) ? sanitize_textarea_field( (string) $row['description'] ) : '',
				'url'         => isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * Sanitise one security.txt Contact value: an email is kept as a bare address
	 * (SecurityTxt prefixes mailto: at render time); otherwise it must be an
	 * http(s)/mailto/tel URI. Anything else collapses to '' and is dropped.
	 *
	 * @param string $value Raw contact.
	 * @return string
	 */
	public function sanitize_contact_value( $value ) {
		$value = trim( (string) $value );
		if ( is_email( $value ) ) {
			return sanitize_email( $value );
		}
		// Require an explicit, allowed scheme so esc_url_raw() can't turn a bare
		// word into a bogus "https://…" contact.
		if ( ! preg_match( '#^(https?|mailto|tel):#i', $value ) ) {
			return '';
		}
		return (string) esc_url_raw( $value, array( 'https', 'http', 'mailto', 'tel' ) );
	}
}
