<?php
/**
 * FluentBooking provider card — presence, name, and the one line under it.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentBooking {

	const ID = 'fluentbooking';

	/**
	 * Whether the plugin is active here.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'FluentBooking\App\App' ) || defined( 'FLUENT_BOOKING_VERSION' );
	}

	/**
	 * The card row the screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => self::ID,
			'name'    => 'FluentBooking',
			'blurb'   => __( 'Booking pages an assistant can point people to.', 'agentimus' ),
			'present' => self::present(),
		);
	}
}
