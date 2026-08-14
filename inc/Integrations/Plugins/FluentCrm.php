<?php
/**
 * FluentCRM provider card — presence, name, and the one line under it.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentCrm {

	const ID = 'fluentcrm';

	/**
	 * Whether the plugin is active here.
	 *
	 * @return bool
	 */
	public static function present() {
		return defined( 'FLUENTCRM' ) || class_exists( 'FluentCrm\App\App' );
	}

	/**
	 * The card row the screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => self::ID,
			'name'    => 'FluentCRM',
			'blurb'   => __( 'Newsletter sign-up, described to AI assistants.', 'agentimus' ),
			'present' => self::present(),
		);
	}
}
