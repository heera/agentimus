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

	/** The store's own product type — public, and the readable half of the shop. */
	const PRODUCT_TYPE = 'fluent-products';

	/**
	 * Whether the plugin is active here. The class is the primary probe; the
	 * version constant catches a build whose autoloader hasn't run yet.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'FluentCart\App\App' ) || defined( 'FLUENTCART_VERSION' );
	}

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
	 * registered that type, so a FluentCart build that renames or hides it
	 * advertises nothing rather than a 404.
	 */
	protected static function endpoints() {
		if ( ! post_type_exists( self::PRODUCT_TYPE ) ) {
			return array();
		}
		return array(
			array(
				'url'         => '/wp-json/wp/v2/' . self::PRODUCT_TYPE,
				'description' => __( 'Browse the products — name, description and permalink.', 'agentimus' ),
			),
		);
	}

	/** The product pages themselves, folded into what this site advertises. */
	protected static function post_types() {
		return array( self::PRODUCT_TYPE );
	}
}
