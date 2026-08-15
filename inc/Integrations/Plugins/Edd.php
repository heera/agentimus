<?php
/**
 * Easy Digital Downloads — the roster card, and nothing more yet.
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

final class Edd extends Provider {

	const ID = 'edd';

	/**
	 * Whether the plugin is active here. The class is the primary probe; the
	 * version constant catches a build whose autoloader hasn't run yet.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'Easy_Digital_Downloads' ) || defined( 'EDD_VERSION' );
	}

	protected static function name() {
		return 'Easy Digital Downloads';
	}

	protected static function blurb() {
		return __( 'Downloads and store pages, described to AI assistants.', 'agentimus' );
	}
}
