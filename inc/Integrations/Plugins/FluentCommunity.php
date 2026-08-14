<?php
/**
 * FluentCommunity provider card — presence, name, and the one line under it.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentCommunity {

	const ID = 'fluentcommunity';

	/**
	 * Whether the plugin is active here.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'FluentCommunity\App\App' ) || defined( 'FLUENT_COMMUNITY_PLUGIN_VERSION' );
	}

	/**
	 * The card row the screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => self::ID,
			'name'    => 'FluentCommunity',
			'blurb'   => __( 'Community spaces, described to AI assistants.', 'agentimus' ),
			'present' => self::present(),
		);
	}
}
