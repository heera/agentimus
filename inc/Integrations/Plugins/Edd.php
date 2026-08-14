<?php
/**
 * Easy Digital Downloads provider card — presence, name, and the one line under it.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class Edd {

	const ID = 'edd';

	/**
	 * Whether the plugin is active here.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'Easy_Digital_Downloads' ) || defined( 'EDD_VERSION' );
	}

	/**
	 * The card row the screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => self::ID,
			'name'    => 'Easy Digital Downloads',
			'blurb'   => __( 'Downloads and store pages, described to AI assistants.', 'agentimus' ),
			'present' => self::present(),
		);
	}
}
