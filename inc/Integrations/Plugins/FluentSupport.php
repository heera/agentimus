<?php
/**
 * Fluent Support — the roster card, and nothing more yet.
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

final class FluentSupport extends Provider {

	const ID = 'fluentsupport';

	/**
	 * Whether the plugin is active here. The class is the primary probe; the
	 * version constant catches a build whose autoloader hasn't run yet.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'FluentSupport\\App\\App' ) || defined( 'FLUENTSUPPORT_PLUGIN_PATH' );
	}

	protected static function name() {
		return 'Fluent Support';
	}

	protected static function blurb() {
		return __( 'Help desk pages, described to AI assistants.', 'agentimus' );
	}
}
