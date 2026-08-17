<?php
/**
 * Easy Digital Downloads — the catalogue of what this shop sells.
 *
 * ⭐ Probed on a real site before it was written: `/wp-json/wp/v2/edd-downloads`
 * answers 200 to anyone, and the `download` type is registered public and
 * REST-enabled — EDD's own declaration that this content is public.
 *
 * ⚠️ The route is read from the type's registration, never built from its name.
 * EDD registers the type as `download` and serves it at `edd-downloads`; a
 * provider that assembled `/wp/v2/download` would have advertised a 404 on
 * every EDD site, and looked right doing it.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class Edd extends Provider {

	const ID = 'edd';

	/** The type EDD sells from. Its public address comes from its own registration. */
	const DOWNLOAD_TYPE = 'download';

	/**
	 * Read from EDD's own bootstrap: `includes/class-easy-digital-downloads.php:13`
	 * declares the class and line 367 defines the constant.
	 */
	const CLASSES = array( 'Easy_Digital_Downloads' );

	const CONSTANTS = array( 'EDD_VERSION' );

	protected static function name() {
		return 'Easy Digital Downloads';
	}

	protected static function blurb() {
		return __( 'Downloads and store pages, described to AI assistants.', 'agentimus' );
	}

	protected static function type() {
		return 'commerce';
	}

	protected static function title() {
		return __( 'Digital downloads', 'agentimus' );
	}

	protected static function description() {
		return __( 'The downloads this shop sells, readable without a login.', 'agentimus' );
	}

	protected static function capabilities() {
		return array( 'commerce.products.read' );
	}

	protected static function endpoints() {
		return self::type_endpoint(
			self::DOWNLOAD_TYPE,
			__( 'Browse the downloads — name, description and permalink.', 'agentimus' )
		);
	}

	/** The download pages themselves, folded into what this site advertises. */
	protected static function post_types() {
		return array( self::DOWNLOAD_TYPE );
	}
}
