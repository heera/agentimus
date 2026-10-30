<?php
/**
 * The plugin settings an agent may see.
 *
 * ⛔ AN ALLOWLIST, never a reuse of Settings::all(). That array holds the Slack,
 * Discord and webhook URLs, which are the power to post in those channels, plus
 * the switches that decide what an agent may do at all. Every key here is named
 * on purpose, so a setting added later stays hidden until somebody decides it is
 * safe to hand over.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\AgentAccess\Events;
use Agentimus\AgentAccess\Module;
use Agentimus\AgentAccess\Store;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class AgentSettings {

	const FLAGS = array(
		'enable_llms_txt',
		'enable_llms_full',
		'enable_markdown',
		'enable_robots',
		'enable_schema',
		'enable_topics',
		'enable_ai_description',
		'ai_description_meta_tag',
		'enable_seo_titles',
		'enable_social_cards',
		'enable_canonicals',
		'enable_ask_ai',
		'topics_derive_default',
		'enable_page_checks',
		'enable_share_copy',
		'enable_sitemap',
		'enable_changes',
		'enable_signing',
		'bypass_shared_cache',
		'purge_cache_on_change',
		'enable_ai_header',
		'enable_tdmrep',
		'ai_noai_header',
	);

	const NUMBERS = array( 'social_default_image', 'topics_max', 'llms_full_posts', 'llms_full_max_kb' );

	const LISTS = array( 'evergreen_categories', 'exposed_extra_paths' );

	const TEXTS = array( 'tdm_policy_url' );

	const IDENTITY_TEXTS = array( 'entity_type', 'name', 'role', 'about', 'not_description', 'audience' );

	const IDENTITY_LISTS = array( 'expertise', 'same_as', 'services', 'projects' );

	/** Calls that change something, per connection, per hour. */
	const HOURLY_LIMIT = 20;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The settings as the site is using them now.
	 *
	 * @return array{settings: array, identity: array}
	 */
	public function read() {
		return self::project( $this->settings->all() );
	}

	/**
	 * Change listed settings, and only listed settings.
	 *
	 * ⛔ One bad key refuses the whole call, before anything is written. The base is
	 * Settings::stored(), never the filtered read, and everything is sent back in
	 * full: Settings::update() replaces the option, so a partial save would switch
	 * off MCP and blank the identity.
	 *
	 * @param array $input { settings?: array, identity?: array }.
	 * @return array|\WP_Error { changed: array, adjusted: string[], message: string }.
	 */
	public function update( array $input ) {
		if ( ! $this->settings->enabled( 'agent_writes_settings' ) ) {
			return new \WP_Error(
				'agentimus_settings_writes_off',
				__( 'Agents may not change settings on this site. The owner can allow it under MCP Server, with “Let agents change settings”. Nothing was changed.', 'agentimus' ),
				array( 'status' => 403 )
			);
		}

		$wanted = array(
			'settings' => isset( $input['settings'] ) ? (array) $input['settings'] : array(),
			'identity' => isset( $input['identity'] ) ? (array) $input['identity'] : array(),
		);
		foreach ( $wanted as $group => $values ) {
			foreach ( $values as $key => $value ) {
				$refusal = self::refusal( $group, (string) $key, $value );
				if ( null !== $refusal ) {
					return $refusal;
				}
			}
		}

		$stored = $this->settings->stored();
		$before = self::project( $stored );

		$pending = false;
		foreach ( $wanted as $group => $values ) {
			foreach ( $values as $key => $value ) {
				if ( $before[ $group ][ $key ] !== $value ) {
					$pending = true;
				}
			}
		}
		if ( ! $pending ) {
			return array(
				'changed'  => array(),
				'adjusted' => array(),
				'message'  => __( 'Those settings already have these values. Nothing was changed.', 'agentimus' ),
			);
		}

		$limited = self::over_limit();
		if ( null !== $limited ) {
			return $limited;
		}

		$next = $stored;
		foreach ( $wanted['settings'] as $key => $value ) {
			$next[ $key ] = $value;
		}
		foreach ( $wanted['identity'] as $key => $value ) {
			$next['identity'][ $key ] = $value;
		}
		$this->settings->update( $next );

		// ⭐ READ IT BACK. The save cleans what it is given (a number past its limit, a
		// bad address), so "changed" reports what the site holds, not what was asked.
		$after    = self::project( $this->settings->stored() );
		$changed  = array();
		$adjusted = array();
		foreach ( $wanted as $group => $values ) {
			foreach ( $values as $key => $value ) {
				$name = 'identity' === $group ? 'identity.' . $key : $key;
				if ( $after[ $group ][ $key ] !== $value ) {
					$adjusted[] = $name;
				}
				if ( $after[ $group ][ $key ] !== $before[ $group ][ $key ] ) {
					$changed[] = array(
						'key'    => $name,
						'before' => $before[ $group ][ $key ],
						'after'  => $after[ $group ][ $key ],
					);
					$this->log( $name, $before[ $group ][ $key ], $after[ $group ][ $key ] );
				}
			}
		}

		return array(
			'changed'  => $changed,
			'adjusted' => $adjusted,
			'message'  => $adjusted
				? __( 'Saved, but the site cleaned some values, so they are not what was sent. `changed` shows what it holds now.', 'agentimus' )
				: __( 'Saved. The owner can see this change in Agent Access.', 'agentimus' ),
		);
	}

	/**
	 * The listed settings out of a settings array.
	 *
	 * @param array $all Settings.
	 * @return array{settings: array, identity: array}
	 */
	private static function project( array $all ) {
		$out = array();

		foreach ( self::FLAGS as $key ) {
			$out[ $key ] = ! empty( $all[ $key ] );
		}
		foreach ( self::NUMBERS as $key ) {
			$out[ $key ] = (int) $all[ $key ];
		}
		foreach ( self::LISTS as $key ) {
			$out[ $key ] = array_values( (array) $all[ $key ] );
		}
		foreach ( self::TEXTS as $key ) {
			$out[ $key ] = (string) $all[ $key ];
		}

		$identity = array();
		foreach ( self::IDENTITY_TEXTS as $key ) {
			$identity[ $key ] = (string) $all['identity'][ $key ];
		}
		foreach ( self::IDENTITY_LISTS as $key ) {
			$identity[ $key ] = array_values( (array) $all['identity'][ $key ] );
		}

		return array(
			'settings' => $out,
			'identity' => $identity,
		);
	}

	/**
	 * @param string $group 'settings' or 'identity'.
	 * @param string $key   Setting key.
	 * @param mixed  $value Value sent.
	 * @return \WP_Error|null
	 */
	private static function refusal( $group, $key, $value ) {
		$types = 'identity' === $group
			? array_merge(
				array_fill_keys( self::IDENTITY_TEXTS, 'string' ),
				array_fill_keys( self::IDENTITY_LISTS, 'array' )
			)
			: array_merge(
				array_fill_keys( self::FLAGS, 'boolean' ),
				array_fill_keys( self::NUMBERS, 'integer' ),
				array_fill_keys( self::LISTS, 'array' ),
				array_fill_keys( self::TEXTS, 'string' )
			);

		if ( ! isset( $types[ $key ] ) ) {
			return new \WP_Error(
				'agentimus_setting_not_allowed',
				sprintf(
					/* translators: %s: the setting's key. */
					__( 'Agents may not change “%s”. Nothing was changed. read-settings lists the settings this tool can reach; everything else belongs to the owner.', 'agentimus' ),
					$key
				),
				array( 'status' => 403 )
			);
		}
		if ( gettype( $value ) !== $types[ $key ] ) {
			return new \WP_Error(
				'agentimus_setting_bad_type',
				sprintf(
					/* translators: 1: the setting's key, 2: the expected type. */
					__( '“%1$s” must be a %2$s. Nothing was changed.', 'agentimus' ),
					$key,
					$types[ $key ]
				),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/**
	 * Count a changing call against this connection's hourly limit.
	 *
	 * @return \WP_Error|null
	 */
	private static function over_limit() {
		$key   = 'agentimus_settings_writes_' . md5( get_current_user_id() . '|' . Module::current_credential() );
		$count = (int) get_transient( $key );
		if ( $count >= self::HOURLY_LIMIT ) {
			return new \WP_Error(
				'agentimus_settings_rate_limited',
				sprintf(
					/* translators: %d: changes allowed per hour. */
					__( 'This connection has changed settings %d times in the last hour, which is the limit. Nothing was changed. Try again later.', 'agentimus' ),
					self::HOURLY_LIMIT
				),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return null;
	}

	/**
	 * Leave the before and after where the owner reads agent activity.
	 *
	 * ⚠️ IN THE SUBJECT, cut short. Agent Access shows the subject and not the detail,
	 * and the table keeps one row per kind, connection and first 100 characters of
	 * subject, with a repeat keeping the first row's text. So each distinct change
	 * gets its own row, or 12 → 11 → 12 reads "12 → 11" on a setting back at 12.
	 *
	 * @param string $name   Setting.
	 * @param mixed  $before Value before.
	 * @param mixed  $after  Value after.
	 */
	private function log( $name, $before, $after ) {
		if ( Module::enabled( $this->settings ) ) {
			Store::record(
				Events::KIND_SETTING_CHANGED,
				get_current_user_id(),
				Module::current_credential(),
				$name . ': ' . self::shown( $before ) . ' → ' . self::shown( $after )
			);
		}
	}

	/**
	 * @param mixed $value A setting's value.
	 * @return string The value for a log line.
	 */
	private static function shown( $value ) {
		$text = is_string( $value ) ? $value : (string) wp_json_encode( $value );
		return mb_strlen( $text ) > 34 ? mb_substr( $text, 0, 33 ) . '…' : $text;
	}
}
