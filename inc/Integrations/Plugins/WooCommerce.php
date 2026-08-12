<?php
/**
 * WooCommerce provider card — presence, name, and the one line under it.
 *
 * Phase one of a provider is honest presence: the card says whether the plugin
 * is here, and "Described" states the standing fact that a present store's
 * content joins what this site describes to AI assistants. The deeper
 * first-party WP_Discovery provider ships later; nothing here pretends it
 * already has.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class WooCommerce {

	const ID = 'woocommerce';

	/**
	 * Whether the plugin is active here.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * The card row the screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => self::ID,
			'name'    => 'WooCommerce',
			'blurb'   => __( 'Products and store pages, described to AI assistants.', 'agentimus' ),
			'present' => self::present(),
		);
	}
}
