<?php
/**
 * FluentCart — the store's catalog, described to AI assistants.
 *
 * ⭐ What it offers a machine is its PRODUCT PAGES, not its own API. Probed on a
 * real site: `/wp-json/wp/v2/fluent-products` answers 200 to anyone, while
 * `/wp-json/fluent-cart/v2/products` answers 401 — that namespace is the store's
 * management API and is never named here.
 *
 * ⚠️ NOT the same resource as the `fluent-cart` one an early adapter plugin of
 * his registers: that one is a separate plugin's declaration, with its own id and
 * its own hyphen. Both may sit on one site without colliding, and if that adapter
 * is present its row appears under "plugins that describe themselves" while this
 * one appears under "described by Agentimus" — two sources saying so, which is
 * the truth rather than a duplicate.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class FluentCart extends Provider {

	const ID = 'fluentcart';

	/**
	 * The plugin's own page, so a card can open it.
	 *
	 * ⛔ NEVER GUESSED. Source: FluentCart's own `Plugin URI:` header (fluent-cart/fluent-cart.php).
	 */
	const HOME = 'https://fluentcart.com';

	/** The store's own product type — public, and the readable half of the shop. */
	const PRODUCT_TYPE = 'fluent-products';

	/** Read from FluentCart's own bootstrap: `fluent-cart.php:18`, `app/App.php:3`. */
	const CLASSES = array( 'FluentCart\\App\\App' );

	const CONSTANTS = array( 'FLUENTCART_VERSION' );

	protected static function name() {
		return 'FluentCart';
	}

	protected static function blurb() {
		return __( 'Store and checkout pages, described to AI assistants.', 'agentimus' );
	}

	protected static function type() {
		return 'commerce';
	}

	protected static function title() {
		return __( 'FluentCart Products', 'agentimus' );
	}

	protected static function description() {
		return __( 'The products this store sells, readable without a login.', 'agentimus' );
	}

	protected static function capabilities() {
		return array( 'commerce.products.read' );
	}

	/**
	 * The product type's own read route — named only when this site really
	 * registered that type as public, and at the address the type itself
	 * declares rather than one built from its name.
	 */
	protected static function endpoints() {
		return self::type_endpoint(
			self::PRODUCT_TYPE,
			__( 'Browse the products — name, description and permalink.', 'agentimus' )
		);
	}

	/** The product pages themselves, folded into what this site advertises. */
	protected static function post_types() {
		return array( self::PRODUCT_TYPE );
	}
}
