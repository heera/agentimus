<?php
/**
 * Fluent Cart adapter — a second built-in provider, proving the public hook
 * works for an arbitrary commerce plugin (not just WooCommerce).
 *
 * Like the others it registers through the public `wpdiscovery_register`
 * hook. Fluent Cart exposes a full REST API at /wp-json/fluent-cart/v2
 * (products, orders, customers, subscriptions, coupons, checkout). Capabilities
 * are declared as dot-notation intent; the concrete route lives in `endpoints`.
 *
 * @package Agentify
 */

namespace Agentify\Discovery\Adapters;

use Agentify\Discovery\Registry;

defined( 'ABSPATH' ) || exit;

final class FluentCart {

	/**
	 * Hook the public registration action; availability is checked at fire-time.
	 */
	public function register() {
		add_action( AGENTIFY_CANONICAL_HOOK, array( $this, 'provide' ) );
	}

	/**
	 * Whether Fluent Cart is active.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return defined( 'FLUENTCART_VERSION' );
	}

	/**
	 * Self-description for the admin Discovery Hub adapters list.
	 *
	 * @return array{id:string,title:string,available:bool}
	 */
	public static function info() {
		return array(
			'id'        => 'fluent-cart',
			'title'     => 'Fluent Cart',
			'available' => self::is_available(),
		);
	}

	/**
	 * Declare Fluent Cart's commerce capabilities into the registry.
	 *
	 * @param Registry $registry The collector.
	 */
	public function provide( Registry $registry ) {
		if ( ! self::is_available() ) {
			return;
		}

		$capabilities = array(
			'commerce.products.read',
			'commerce.orders.read',
			'commerce.customers.read',
			'commerce.subscriptions.read',
			'commerce.coupons.read',
			'commerce.checkout.write',
		);

		/**
		 * Filter the commerce capabilities Fluent Cart advertises.
		 *
		 * @param string[] $capabilities Dot-notation capabilities.
		 */
		$capabilities = (array) apply_filters( 'agentify_fluent_cart_capabilities', $capabilities );

		$registry->register(
			array(
				'id'           => 'fluent-cart',
				'title'        => 'Fluent Cart',
				'type'         => 'commerce',
				'description'  => __( 'Products, orders, customers, subscriptions and checkout via the Fluent Cart REST API.', 'agentify' ),
				'version'      => defined( 'FLUENTCART_VERSION' ) ? FLUENTCART_VERSION : '',
				'capabilities' => $capabilities,
				'endpoints'    => array(
					array(
						'url'         => '/wp-json/fluent-cart/v2',
						'type'        => 'rest',
						'methods'     => array( 'GET', 'POST', 'PUT', 'DELETE' ),
						'auth'        => 'wp',
						'description' => __( 'Store-management REST API (requires an authenticated session).', 'agentify' ),
					),
				),
				'auth'         => array(
					'type' => 'custom',
					'docs' => 'https://fluentcart.com/docs/',
				),
				'docs'         => 'https://fluentcart.com/docs/',
			)
		);
	}
}
