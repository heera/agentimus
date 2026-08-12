<?php
/**
 * Fluent Forms provider card — presence, name, and the one line under it.
 * A form is a CONTACT capability: the door an assistant can point a person to.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentForms {

	const ID = 'fluentforms';

	/**
	 * Whether the plugin is active here.
	 *
	 * @return bool
	 */
	public static function present() {
		return defined( 'FLUENTFORM' ) || class_exists( 'FluentForm\App\App' );
	}

	/**
	 * The card row the screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => self::ID,
			'name'    => 'Fluent Forms',
			'blurb'   => __( 'Your forms — a way for assistants to reach you.', 'agentimus' ),
			'present' => self::present(),
		);
	}
}
