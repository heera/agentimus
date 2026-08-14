<?php
/**
 * FluentCart provider card — presence, name, and the one line under it.
 * Same phase-one grammar as WooCommerce: honest presence, "Described" when here.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentCart {

	const ID = 'fluentcart';

	/**
	 * Whether the plugin is active here. The class is the primary probe; the
	 * version constant catches a build whose autoloader hasn't run yet.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'FluentCart\App\App' ) || defined( 'FLUENTCART_VERSION' );
	}

	/**
	 * The card row the screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => self::ID,
			'name'    => 'FluentCart',
			'blurb'   => __( 'Store and checkout pages, described to AI assistants.', 'agentimus' ),
			'present' => self::present(),
		);
	}
}
