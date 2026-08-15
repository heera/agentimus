<?php
/**
 * The WooCommerce provider — what a present store tells AI assistants.
 *
 * A roster card only answers "is this plugin here?". The provider answers the
 * question after it, and these tests hold the two rules that keep the answer
 * honest:
 *
 *   1. ONLY WHAT THIS SITE REALLY HAS, asked in a way that does not change with
 *      the kind of request. A store whose Store API is absent must not have one
 *      advertised for it, and a site with no WooCommerce says nothing at all.
 *      ⚠️ The REST route map cannot answer this: WooCommerce registers wc/store
 *      on rest_api_init, so the map holds it during a REST call and not during
 *      an admin page load — which made the public document and the admin
 *      preview of that same document disagree.
 *   2. ⛔ NEVER THE ADMIN API. wc/v3 answers 401 without a key. Naming it in a
 *      discovery document sends assistants at a locked door.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Integrations\Plugins\WooCommerce;
use PHPUnit\Framework\TestCase;

final class PluginProviderWooTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		$this->forgetWoo();
	}

	/** A whole store: the Store API class present, and the product type registered. */
	private function wholeStore() {
		$this->haveStoreApi();
		$GLOBALS['_af_post_types_exist'] = array( 'product' );
	}

	/** The Store API's own class, which is what serves wc/store. */
	private function haveStoreApi() {
		if ( ! class_exists( '\\Automattic\\WooCommerce\\StoreApi\\StoreApi' ) ) {
			eval( 'namespace Automattic\\WooCommerce\\StoreApi; class StoreApi {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the only way to test both sides of class_exists in one process.
		}
	}

	/**
	 * WooCommerce's own class, which present() reads. Declared on the fly so the
	 * suite can run both sides of "is there a store here".
	 */
	private function haveWoo() {
		if ( ! class_exists( '\WooCommerce' ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- the only way to test both sides of class_exists in one process.
		}
	}

	/**
	 * A collected registry holding exactly one resource. The Registry dispatches
	 * the hook's callbacks itself, so the callback is placed the way WordPress
	 * would hold it (same shape DiscoveryDispatchTest uses).
	 */
	private function registryHolding( array $resource ) {
		$hook            = new \stdClass();
		$hook->callbacks = array(
			10 => array(
				'woo' => array(
					'function'      => static function ( $registry ) use ( $resource ) {
						$registry->register( $resource );
					},
					'accepted_args' => 1,
				),
			),
		);
		$GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ] = $hook;

		return \Agentimus\Discovery\Registry::instance()->collect();
	}

	private function forgetWoo() {
		unset( $GLOBALS['_af_post_types_exist'] );
	}

	/* ---- nothing to say -------------------------------------------------- */

	public function test_without_woocommerce_the_provider_says_nothing() {
		if ( class_exists( '\WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce was declared by an earlier test in this process.' );
		}
		$GLOBALS['_af_post_types_exist'] = array( 'product' );

		$this->assertSame( array(), WooCommerce::resource(), 'No store, no claim — even with a product type registered.' );
	}

	/* ---- only what this site really has ---------------------------------- */

	public function test_a_store_with_nothing_public_advertises_nothing() {
		$this->haveWoo();
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\StoreApi' ) ) {
			$this->markTestSkipped( 'The Store API class was declared by an earlier test in this process.' );
		}
		$GLOBALS['_af_post_types_exist'] = array(); // No product type either.

		$this->assertSame(
			array(),
			WooCommerce::resource(),
			'A store no machine can read is not a store to advertise.'
		);

		$collector = new class() {
			public $got = array();
			public function register( $resource ) {
				$this->got[] = $resource;
			}
		};
		WooCommerce::provide( $collector );
		$this->assertSame( array(), $collector->got, 'Silence, rather than an empty claim.' );
	}

	public function test_the_store_api_is_named_when_the_site_serves_it() {
		$this->haveWoo();
		$this->wholeStore();

		$resource = WooCommerce::resource();

		$this->assertSame( 'woocommerce', $resource['id'] );
		$this->assertSame( 'commerce', $resource['type'] );
		$this->assertSame( array( 'commerce.products.read' ), $resource['capabilities'] );

		$urls = array_column( $resource['endpoints'], 'url' );
		$this->assertSame(
			array( '/wp-json/wc/store/v1/products', '/wp-json/wp/v2/product' ),
			$urls,
			'The public storefront API first, then the product pages.'
		);
		foreach ( $resource['endpoints'] as $endpoint ) {
			$this->assertSame( 'none', $endpoint['auth'], 'Everything advertised is readable without a login.' );
			$this->assertSame( array( 'GET' ), $endpoint['methods'], 'Read only.' );
		}
	}

	public function test_the_answer_does_not_change_with_the_kind_of_request() {
		$this->haveWoo();
		$this->wholeStore();

		$first = WooCommerce::resource();
		// Nothing about a REST route map here — the same two facts, asked again.
		$second = WooCommerce::resource();

		$this->assertSame( $first, $second );
		$this->assertCount( 2, $first['endpoints'], 'An admin page and a REST call must describe the same store.' );
	}

	/* ---- ⛔ the locked door ------------------------------------------------ */

	public function test_the_authenticated_admin_api_is_never_advertised() {
		$this->haveWoo();
		$this->wholeStore();

		$json = wp_json_encode( WooCommerce::resource() );

		$this->assertStringNotContainsString( 'wc/v3', $json, 'wc/v3 answers 401 without a key; a discovery document must never point there.' );
	}

	/* ---- the registry hand-off -------------------------------------------- */

	public function test_provide_registers_the_resource_it_built() {
		$this->haveWoo();
		$this->wholeStore();

		$collector = new class() {
			public $got = array();
			public function register( $resource ) {
				$this->got[] = $resource;
			}
		};

		WooCommerce::provide( $collector );

		$this->assertCount( 1, $collector->got );
		$this->assertSame( 'woocommerce', $collector->got[0]['id'] );
	}

	public function test_provide_survives_a_collector_it_cannot_use() {
		$this->haveWoo();
		$this->wholeStore();

		// A registry that cannot collect — a third-party engine mid-refactor, or
		// nothing at all. Discovery must not fatal on someone else's mistake.
		WooCommerce::provide( null );
		WooCommerce::provide( new \stdClass() );

		$this->assertTrue( true, 'Reaching here without a fatal is the assertion.' );
	}

	/* ---- the card is untouched -------------------------------------------- */

	public function test_the_roster_card_still_answers_for_the_screen() {
		$card = WooCommerce::describe();

		$this->assertSame( 'woocommerce', $card['id'] );
		$this->assertSame( 'WooCommerce', $card['name'] );
		$this->assertArrayHasKey( 'present', $card );
	}

	/* ---- how the screen must file it ------------------------------------- */

	/**
	 * ⭐ Three sources, not two. Agentimus's name sits on the provider line of
	 * BOTH what a scanner found and what a hand-written provider describes, so
	 * the roster is what tells them apart. Getting this wrong put "Found
	 * automatically · via the REST API" under a store nothing ever scanned.
	 */
	public function test_a_described_plugin_is_not_filed_as_a_scan() {
		$this->haveWoo();
		$this->wholeStore();

		$rows = \Agentimus\Discovery\Hub::data( new \Agentimus\Settings(), $this->registryHolding( WooCommerce::resource() ) )['resources'];
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( 'woocommerce' === $candidate['id'] ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row, 'The store must reach the screen.' );
		$this->assertTrue( $row['described'], 'Agentimus wrote this description; it did not find it.' );
		$this->assertFalse( $row['auto'], 'Filing it as a scan names an engine that never ran.' );
	}
}
