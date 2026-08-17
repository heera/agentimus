<?php
/**
 * FluentBooking — the roster card, and nothing more yet.
 *
 * ⭐ A provider with no public address to give is COMPLETE, not unfinished: this
 * plugin's content lives behind a login, so the honest answer is to say it is
 * here and advertise nothing. The base class does the rest — declare an endpoint
 * only when one is proved public on a real site.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentBooking extends Provider {

	const ID = 'fluentbooking';

	/** Read from FluentBooking's own bootstrap: `fluent-booking.php:22`, `app/App.php:3`. */
	const CLASSES = array( 'FluentBooking\\App\\App' );

	const CONSTANTS = array( 'FLUENT_BOOKING_VERSION' );

	protected static function name() {
		return 'FluentBooking';
	}

	protected static function blurb() {
		return __( 'Appointment booking and availability.', 'agentimus' );
	}
}
