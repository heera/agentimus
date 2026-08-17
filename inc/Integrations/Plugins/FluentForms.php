<?php
/**
 * Fluent Forms — the roster card, and nothing more yet.
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

final class FluentForms extends Provider {

	const ID = 'fluentforms';

	/** Read from Fluent Forms' own bootstrap: `fluentform.php:17`, `app/App.php:3`. */
	const CLASSES = array( 'FluentForm\\App\\App' );

	const CONSTANTS = array( 'FLUENTFORM' );

	protected static function name() {
		return 'Fluent Forms';
	}

	protected static function blurb() {
		return __( 'Contact and survey forms.', 'agentimus' );
	}
}
