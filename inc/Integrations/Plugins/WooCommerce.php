<?php
/**
 * WooCommerce — the shop, described to AI assistants.
 *
 * Without this a store is either missing from discovery or shows up as a bare
 * namespace stub the owner had to opt into by hand, reading "REST API namespace
 * published via discovery", which tells an assistant nothing.
 *
 * ⭐ ONLY WHAT THIS SITE REALLY HAS, asked in a way that does not change with the
 * kind of request. The Store API is named when its own class is here — not when
 * a route map happens to hold it. WooCommerce registers `wc/store` on
 * `rest_api_init`, so the map has it during a REST call and not during an admin
 * page load: that made the public document list two endpoints while the admin
 * preview of the same document listed one. What a machine gets is decided when
 * it calls, so the honest question is "does this site have a Store API".
 *
 * ⛔ `wc/v3` is never named — it answers 401 without a key. The base class
 * enforces that for every provider; this one only has to not ask.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class WooCommerce extends Provider {

	const ID = 'woocommerce';

	/** WooCommerce's public, read-only storefront API — no login, by its design. */
	const STORE_URL = '/wp-json/wc/store/v1/products';

	/** The class that serves it. Present or absent in every context alike. */
	const STORE_CLASS = '\Automattic\WooCommerce\StoreApi\StoreApi';

	/**
	 * WooCommerce's main class, read from `includes/class-woocommerce.php:55`.
	 * ⛔ No constant fallback: `WC_VERSION` is defined by that same class file, so
	 * it would prove nothing the class does not already prove.
	 */
	const CLASSES = array( 'WooCommerce' );

	protected static function name() {
		return 'WooCommerce';
	}

	protected static function blurb() {
		return __( 'Products and store pages, described to AI assistants.', 'agentimus' );
	}

	protected static function type() {
		return 'commerce';
	}

	protected static function title() {
		return __( 'WooCommerce Store', 'agentimus' );
	}

	protected static function description() {
		return __( 'The product catalog of this store, readable without a login.', 'agentimus' );
	}

	protected static function capabilities() {
		return array( 'commerce.products.read' );
	}

	/**
	 * ⛔ NOT the plain /wp/v2/product route: the site-content row already
	 * advertises /wp/v2 and lists content.product.read, so naming it here said
	 * one thing in two places. Nothing is lost — this site's own schema for the
	 * Store API carries name, permalink, description, prices, images, stock, sku
	 * and categories, so an assistant that wants the readable page has the
	 * permalink right there.
	 */
	protected static function endpoints() {
		if ( ! class_exists( self::STORE_CLASS ) ) {
			return array();
		}
		return array(
			array(
				'url'         => self::STORE_URL,
				'description' => __( 'Browse products, with their prices, stock and images.', 'agentimus' ),
			),
		);
	}

	/** The shop's own content, so its pages join what assistants may read. */
	protected static function post_types() {
		return array( 'product' );
	}
}
