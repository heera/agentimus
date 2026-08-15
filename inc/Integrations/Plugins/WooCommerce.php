<?php
/**
 * WooCommerce provider — the card, and the store it describes to AI assistants.
 *
 * A card alone only answers "is this plugin here?". This provider answers the
 * question after it: what IS the store, and which address may a machine read it
 * from. Without one, a shop is either missing from discovery entirely or shows
 * up as a bare namespace stub the owner had to opt into by hand — a line saying
 * "REST API namespace published via discovery", which tells an assistant
 * nothing.
 *
 * ⭐ ONLY WHAT THIS SITE REALLY HAS. The address is named only when the thing
 * that serves it exists here — the Store API's own class. Nothing is advertised
 * because WooCommerce is "usually" a certain shape.
 *
 * ⚠️ AND THE CHECK MUST NOT CHANGE WITH THE KIND OF REQUEST. Asking the REST
 * server for its route map looks like the stricter test and is in fact a worse
 * one: WooCommerce registers `wc/store` on `rest_api_init`, so the map holds it
 * during a REST call and not during an admin page load. That made the public
 * document list two endpoints while the admin preview of the same document
 * listed one — a screen and a file disagreeing about the same site. What a
 * machine actually gets is decided when it calls, so the honest question is
 * "does this site have a Store API", not "is its route in the map of the
 * request I happen to be inside".
 *
 * ⛔ NEVER THE ADMIN ROUTES. `wc/v3/*` is WooCommerce's authenticated
 * management API — it answers 401 to a machine that has no key, and a discovery
 * document that points at it is sending assistants at a locked door. Only the
 * public, read-only surface is named: the Store API, which WooCommerce
 * publishes for exactly this purpose and serves without a login.
 *
 * Registering at the default priority is what keeps the generic stub away: the
 * REST auto-adapter runs at 99 and fills gaps only, so a namespace described
 * here is left alone rather than duplicated.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Plugins;

defined( 'ABSPATH' ) || exit;

final class WooCommerce {

	const ID = 'woocommerce';

	/** WooCommerce's public, read-only storefront API — no login, by its design. */
	const STORE_URL = '/wp-json/wc/store/v1/products';

	/** The class that serves it. Present or absent in every context alike. */
	const STORE_CLASS = '\Automattic\WooCommerce\StoreApi\StoreApi';

	// ⛔ NOT the plain /wp/v2/product route. It was here, and it was one thing
	// said twice: the WordPress Core row already advertises /wp/v2 and lists
	// content.product.read, so products were described in two rows meaning the
	// same door. Dropping it loses nothing — this site's own schema for the
	// Store API carries name, permalink, description, prices, images, stock,
	// sku and categories, so an assistant that wants the readable page has the
	// permalink right there. One row, one job.

	/**
	 * Hook the store's description onto the discovery collection.
	 *
	 * Default priority on purpose: explicit providers speak at 10, the REST
	 * auto-adapter fills what is left at 99.
	 */
	public static function register() {
		add_action( AGENTIMUS_CANONICAL_HOOK, array( __CLASS__, 'provide' ) );
	}

	/**
	 * Describe the store, if there is a store and it really answers.
	 *
	 * @param \Agentimus\Discovery\Registry $registry The collector.
	 */
	public static function provide( $registry ) {
		$resource = self::resource();
		if ( array() === $resource || ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}
		$registry->register( $resource );
	}

	/**
	 * The store as one discovery resource, or an empty array when there is
	 * nothing true to say.
	 *
	 * @return array
	 */
	public static function resource() {
		if ( ! self::present() ) {
			return array();
		}

		$endpoints = self::public_endpoints();
		if ( array() === $endpoints ) {
			return array(); // A store no machine can read is not a store to advertise.
		}

		return array(
			'id'           => self::ID,
			'title'        => __( 'WooCommerce Store', 'agentimus' ),
			'type'         => 'commerce',
			'description'  => __( 'The product catalog of this store, readable without a login.', 'agentimus' ),
			'capabilities' => array( 'commerce.products.read' ),
			'endpoints'    => $endpoints,
		);
	}

	/**
	 * The read-only endpoints this site actually serves — named only when the
	 * thing that answers them is here.
	 *
	 * @return array<int,array>
	 */
	private static function public_endpoints() {
		$endpoints = array();

		if ( class_exists( self::STORE_CLASS ) ) {
			$endpoints[] = array(
				'url'         => self::STORE_URL,
				'type'        => 'rest',
				'methods'     => array( 'GET' ),
				'auth'        => 'none',
				'description' => __( 'Browse products, with their prices, stock and images.', 'agentimus' ),
			);
		}

		return $endpoints;
	}

	/**
	 * Whether the plugin is active here.
	 *
	 * @return bool
	 */
	public static function present() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * The card row the screen renders.
	 *
	 * @return array{id:string,name:string,blurb:string,present:bool}
	 */
	public static function describe() {
		return array(
			'id'      => self::ID,
			'name'    => 'WooCommerce',
			'blurb'   => __( 'Products and store pages, described to AI assistants.', 'agentimus' ),
			'present' => self::present(),
		);
	}
}
