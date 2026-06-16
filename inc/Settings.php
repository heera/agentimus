<?php
/**
 * Settings store — a single option array, with defaults, typed getters and
 * sanitisation. The one source of truth shared by every module and the REST API.
 *
 * @package Agentify
 */

namespace Agentify;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'agentify_settings';

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
			'llms_full_posts'  => 50,
			'post_types'       => Content::available(),
			'rest_namespaces'  => array(), // Owner-curated REST namespaces to publish in discovery (opt-in; empty = none).
			'suppressed_resources' => array(), // Owner opt-OUT: ids of provider-registered Resources to hide from all output. Declared Resources default to published (spec §04), so empty = publish everything a provider declared.
			'identity'         => array(
				'entity_type'   => 'Person', // Person | Organization.
				'name'          => get_bloginfo( 'name' ),
				'role'          => '',
				'about'         => '',
				'contact_email' => '', // Opt-in; published in discovery.json only if set.
				'expertise'     => array(),
				'same_as'       => array(),
			),
			'content_signal'   => array(
				'search'   => true,
				'ai_input' => true,
				'ai_train' => false,
			),
			'blocked_trainers' => self::known_trainers(),
		);

		/**
		 * Filter the default settings.
		 *
		 * @param array $defaults Default settings.
		 */
		return apply_filters( 'agentify_default_settings', $defaults );
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
		$known = (array) apply_filters( 'agentify_known_trainers', $known );
		return array_values( array_unique( array_filter( array_map( 'trim', $known ) ) ) );
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

		/**
		 * Filter the resolved settings on read.
		 *
		 * @param array $all Resolved settings.
		 */
		return apply_filters( 'agentify_settings', $all );
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
		do_action( 'agentify_settings_reset' );

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
	 * Sanitise a raw settings array against the schema.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( array $input ) {
		$defaults = $this->defaults();
		$clean    = array();

		foreach ( array( 'enable_llms_txt', 'enable_llms_full', 'enable_markdown', 'enable_robots', 'enable_schema', 'enable_activity', 'enable_sitemap' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		$posts                    = isset( $input['llms_full_posts'] ) ? (int) $input['llms_full_posts'] : $defaults['llms_full_posts'];
		$clean['llms_full_posts'] = max( 1, min( 500, $posts ) );

		$available           = Content::available();
		$requested           = $this->sanitize_list( isset( $input['post_types'] ) ? $input['post_types'] : array(), 'sanitize_key' );
		$clean['post_types'] = array_values( array_intersect( $requested, $available ) );
		if ( empty( $clean['post_types'] ) ) {
			$clean['post_types'] = $available; // Never store an empty set — that would hide all content.
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
			'entity_type' => in_array( $type, array( 'Person', 'Organization' ), true ) ? $type : 'Person',
			'name'        => isset( $identity_in['name'] ) ? sanitize_text_field( (string) $identity_in['name'] ) : '',
			'role'        => isset( $identity_in['role'] ) ? sanitize_text_field( (string) $identity_in['role'] ) : '',
			'about'         => isset( $identity_in['about'] ) ? sanitize_textarea_field( (string) $identity_in['about'] ) : '',
			'contact_email' => isset( $identity_in['contact_email'] ) ? sanitize_email( (string) $identity_in['contact_email'] ) : '',
			'expertise'     => $this->sanitize_list( isset( $identity_in['expertise'] ) ? $identity_in['expertise'] : array(), 'sanitize_text_field' ),
			'same_as'       => $this->sanitize_list( isset( $identity_in['same_as'] ) ? $identity_in['same_as'] : array(), 'esc_url_raw' ),
		);

		$trainers                 = isset( $input['blocked_trainers'] ) ? $input['blocked_trainers'] : $defaults['blocked_trainers'];
		$clean['blocked_trainers'] = $this->sanitize_list( $trainers, 'sanitize_text_field' );

		/**
		 * Filter the sanitised settings before they are stored.
		 *
		 * @param array $clean Sanitised settings.
		 * @param array $input Raw input.
		 */
		return apply_filters( 'agentify_sanitize_settings', $clean, $input );
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
}
