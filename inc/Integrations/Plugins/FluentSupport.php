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
	 * The plugin's own page, so a card can open it.
	 *
	 * ⛔ NEVER GUESSED. Source: Verified 2026-08-22: 200, no redirect, title 'Fluent Support: Best Support Ticket Plugin for WordPress'.
	 */
	const HOME = 'https://fluentsupport.com';

	/**
	 * ⚠️ THE FALLBACK NAMED A CONSTANT THAT HAS NEVER EXISTED. It read
	 * `FLUENTSUPPORT_PLUGIN_PATH`; Fluent Support defines `FLUENT_SUPPORT_VERSION`,
	 * `FLUENT_SUPPORT_PLUGIN_PATH` and the rest — every one with the underscore
	 * after FLUENT (read from 2.3.1, `fluent-support.php:14-19`). The safety net
	 * could never once have fired, and nothing would have shown it until the day
	 * the class probe was all that was left. ⭐ A fallback nobody has seen work is
	 * not a fallback.
	 */
	const CLASSES = array( 'FluentSupport\\App\\App' );

	const CONSTANTS = array( 'FLUENT_SUPPORT_VERSION' );

	protected static function name() {
		return 'Fluent Support';
	}

	protected static function blurb() {
		return __( 'Customer support tickets.', 'agentimus' );
	}
}
