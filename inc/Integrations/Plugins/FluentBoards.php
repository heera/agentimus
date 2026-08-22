<?php
/**
 * FluentBoards — project boards and tasks.
 *
 * ⭐ Nothing here is public, and that is the whole finding. Every
 * `fluent-boards/v2/...` route answered 401 on a real site with the plugin
 * active, and boards are not a post type anyone can read. So this provider
 * describes nothing, and the card says so in those words rather than showing
 * a tick for being installed.
 *
 * It is still on the roster: the owner asked whether Agentimus knows about
 * this plugin, and "yes, and there is nothing public in it" is an answer.
 * Silence is not.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentBoards extends Provider {

	const ID = 'fluentboards';

	/**
	 * The plugin's own page, so a card can open it.
	 *
	 * ⛔ NEVER GUESSED. Source: Verified 2026-08-22: 200, no redirect, title 'FluentBoards: The Simplest WordPress Project Management Plugin'.
	 */
	const HOME = 'https://fluentboards.com';

	/** Read from FluentBoards' own bootstrap: `fluent-boards.php:23`, `app/App.php:3`. */
	const CLASSES = array( 'FluentBoards\\App\\App' );

	const CONSTANTS = array( 'FLUENT_BOARDS_PLUGIN_VERSION' );

	protected static function name() {
		return 'FluentBoards';
	}

	protected static function blurb() {
		return __( 'Project boards and tasks.', 'agentimus' );
	}
}
