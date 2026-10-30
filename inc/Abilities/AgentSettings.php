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
		$all = $this->settings->all();
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
}
