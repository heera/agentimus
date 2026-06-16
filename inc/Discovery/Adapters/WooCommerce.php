<?php
/**
 * WooCommerce adapter — the first built-in provider.
 *
 * It is *not* a privileged back-channel: it registers through the exact public
 * `wp_discovery_register` hook a third-party plugin would use, so it doubles as
 * the reference implementation of the registration contract. Capabilities are
 * declared as dot-notation intent (commerce.products.read), per the protocol's
 * "describe intent, not implementation" rule — the concrete /wp-json/wc/* paths
 * live only in `endpoints`.
 *
 * @package Agentify
 */

namespace Agentify\Discovery\Adapters;

use Agentify\Discovery\Registry;

defined( 'ABSPATH' ) || exit;

final class WooCommerce {

	/**
	 * Hook the public registration action. The availability check is deferred to
	 * fire-time (not here): plugin load order means WooCommerce may not be defined
	 * yet at `plugins_loaded`, but it always is by `template_redirect`/REST.
	 */
	public function register() {
		add_action( WP_DISCOVERY_HOOK, array( $this, 'provide' ) );
	}

	/**
	 * Whether this adapter should contribute. Public so the admin "Registered
	 * plugins" screen can show it as detected-but-inactive when WC is absent.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Self-description for the admin Discovery Hub adapters list.
	 *
	 * @return array{id:string,title:string,available:bool}
	 */
	public static function info() {
		return array(
			'id'        => 'woocommerce',
			'title'     => 'WooCommerce',
			'available' => self::is_available(),
		);
	}

	/**
	 * Declare WooCommerce's commerce capabilities into the registry.
	 *
	 * @param Registry $registry The collector.
	 */
	public function provide( Registry $registry ) {
		if ( ! self::is_available() ) {
			return;
		}

		$registry->register(
			array(
				'id'           => 'woocommerce',
				'title'        => 'WooCommerce',
				'type'         => 'commerce',
				'description'  => __( 'Products, categories, cart and checkout, plus an authenticated store-management API.', 'agentify' ),
				'version'      => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'capabilities' => $this->capabilities(),
				'endpoints'    => $this->endpoints(),
				'auth'         => array(
					'type' => 'apikey',
					'docs' => 'https://woocommerce.github.io/woocommerce-rest-api-docs/',
				),
				'agent'        => $this->agent_card(),
				'docs'         => 'https://woocommerce.github.io/woocommerce-rest-api-docs/',
			)
		);
	}

	/**
	 * Dot-notation capabilities. Public Store API capabilities first (no auth),
	 * then the ones gated behind authenticated keys on the v3 admin API.
	 *
	 * @return string[]
	 */
	private function capabilities() {
		$public = array(
			'commerce.products.read',
			'commerce.categories.read',
			'commerce.cart.write',
			'commerce.checkout.write',
		);
		$authenticated = array(
			'commerce.orders.read',
			'commerce.orders.write',
			'commerce.customers.read',
			'commerce.coupons.read',
		);

		/**
		 * Filter the commerce capabilities WooCommerce advertises. A site that
		 * locks down its Store API can prune the public set here.
		 *
		 * @param string[] $capabilities Dot-notation capabilities.
		 */
		return (array) apply_filters( 'agentify_woocommerce_capabilities', array_merge( $public, $authenticated ) );
	}

	/**
	 * The two interfaces an agent can reach: the public Store API and the
	 * authenticated admin REST API.
	 *
	 * @return array[]
	 */
	private function endpoints() {
		return array(
			array(
				'url'         => '/wp-json/wc/store/v1',
				'type'        => 'rest',
				'methods'     => array( 'GET', 'POST' ),
				'auth'        => 'none',
				'description' => __( 'Public Store API: browse products and operate the cart/checkout.', 'agentify' ),
			),
			array(
				'url'         => '/wp-json/wc/v3',
				'type'        => 'rest',
				'methods'     => array( 'GET', 'POST', 'PUT', 'DELETE' ),
				'auth'        => 'apikey',
				'description' => __( 'Authenticated store-management API (requires REST API keys).', 'agentify' ),
			),
		);
	}

	/**
	 * An A2A-style agent card so the generated agent-card.json advertises a
	 * shopping skill set pointed at the public Store API.
	 *
	 * @return array
	 */
	private function agent_card() {
		return array(
			'name'        => __( 'Store Agent', 'agentify' ),
			'description' => __( 'Browse the catalogue and build a cart via the WooCommerce Store API.', 'agentify' ),
			'endpoint'    => '/wp-json/wc/store/v1',
			'auth'        => 'none',
			'skills'      => array(
				array( 'id' => 'search_products', 'description' => __( 'Search and list products.', 'agentify' ) ),
				array( 'id' => 'get_product', 'description' => __( 'Fetch a single product by id or slug.', 'agentify' ) ),
				array( 'id' => 'add_to_cart', 'description' => __( 'Add an item to the cart.', 'agentify' ) ),
			),
		);
	}
}
