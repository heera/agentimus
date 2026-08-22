<?php
/**
 * FluentCRM — the roster card, and nothing more yet.
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

final class FluentCrm extends Provider {

	const ID = 'fluentcrm';

	/**
	 * The plugin's own page, so a card can open it.
	 *
	 * ⛔ NEVER GUESSED. Source: FluentCRM's own `Plugin URI:` header (fluent-crm/fluent-crm.php).
	 */
	const HOME = 'https://fluentcrm.com';

	/** Read from FluentCRM's own bootstrap: `fluent-crm.php:20`, `app/App.php:3`. */
	const CLASSES = array( 'FluentCrm\\App\\App' );

	const CONSTANTS = array( 'FLUENTCRM' );

	protected static function name() {
		return 'FluentCRM';
	}

	protected static function blurb() {
		return __( 'Email marketing, contacts and campaigns.', 'agentimus' );
	}
}
