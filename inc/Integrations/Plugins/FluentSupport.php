<?php
/**
 * Fluent Support provider card — presence, name, and the one line under it.
 *
 * The angle: the site's support desk, described so an assistant knows where a
 * reader gets help — and opening a ticket becomes a capability an assistant
 * can use on a reader's behalf.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentSupport {

	const ID = 'fluentsupport';

	/**
	 * Whether the plugin is active here.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'FluentSupport\App\App' ) || defined( 'FLUENTSUPPORT_PLUGIN_PATH' );
	}

	/**
	 * The card row the screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => self::ID,
			'name'    => 'Fluent Support',
			'blurb'   => __( 'The support desk, described — an assistant can open a ticket for a reader.', 'agentimus' ),
			'present' => self::present(),
		);
	}
}
