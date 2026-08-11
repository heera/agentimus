<?php
/**
 * RestApi adapter — the pure, WordPress-free helpers behind zero-config
 * auto-discovery (namespace classification, slugging, capability derivation).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Adapters\RestApi;
use PHPUnit\Framework\TestCase;

final class RestApiAdapterTest extends TestCase {

	public function test_core_capabilities_maps_and_dedupes() {
		$this->assertSame(
			array(
				'content.posts.read',
				'content.pages.read',
				'content.media.read',
				'content.categories.read',
				'content.tags.read',
			),
			RestApi::core_capabilities( array( 'posts', 'pages', 'media' ), array( 'categories', 'tags' ) )
		);

		// Duplicates collapse; blanks are dropped.
		$this->assertSame(
			array( 'content.posts.read' ),
			RestApi::core_capabilities( array( 'posts', 'posts', '' ), array() )
		);
	}

	/** @dataProvider namespaces */
	public function test_is_third_party_classification( string $namespace, bool $expected ) {
		$this->assertSame( $expected, RestApi::is_third_party( $namespace, RestApi::SKIP ) );
	}

	public function namespaces(): array {
		return array(
			// Core / own — skipped.
			'core content'      => array( 'wp/v2', false ),
			'core internal'     => array( 'wp-site-health/v1', false ),
			'oembed'            => array( 'oembed/1.0', false ),
			'own'               => array( 'agentimus/v1', false ),
			// Third-party — surfaced (no plugin names are special-cased).
			'woo store'         => array( 'wc/store/v1', true ),
			'fluent cart'       => array( 'fluent-cart/v2', true ),
			'arbitrary plugin'  => array( 'acme/v1', true ),
			// Must NOT be over-skipped just for starting with "wp".
			'wp-prefixed plugin' => array( 'wpforms/v1', true ),
		);
	}

	public function test_is_allowed_opt_in() {
		$allowed = array( 'wc/store/v1', 'klaviyo/v1' );
		$this->assertTrue( RestApi::is_allowed( 'wc/store/v1', $allowed ) );
		$this->assertFalse( RestApi::is_allowed( 'wc-telemetry', $allowed ) );
		// Default (nothing opted in) publishes nothing third-party.
		$this->assertFalse( RestApi::is_allowed( 'wc/store/v1', array() ) );
	}

	public function test_slug() {
		$this->assertSame( 'acme-v1', RestApi::slug( 'acme/v1' ) );
		$this->assertSame( 'wc-store-v1', RestApi::slug( 'wc/store/v1' ) );
		// Underscores (and any non-alphanumeric) must become hyphens, or the id
		// fails the Resource slug pattern — the bug that produced a validation error.
		$this->assertSame( 'wc-v3-wc-paypal', RestApi::slug( 'wc/v3/wc_paypal' ) );
	}

	/** @dataProvider endpoints */
	public function test_endpoint_covers( string $url, string $namespace, bool $expected ) {
		$this->assertSame( $expected, RestApi::endpoint_covers( $url, $namespace ) );
	}

	public function endpoints(): array {
		return array(
			'relative exact'        => array( '/wp-json/acme/v1', 'acme/v1', true ),
			'absolute exact'        => array( 'https://shop.test/wp-json/acme/v1', 'acme/v1', true ),
			'route under namespace' => array( '/wp-json/acme/v1/products', 'acme/v1', true ),
			'trailing slash'        => array( '/wp-json/acme/v1/', 'acme/v1', true ),
			'query string ignored'  => array( '/wp-json/acme/v1?per_page=5', 'acme/v1', true ),
			'case-insensitive'      => array( '/wp-json/Acme/V1', 'acme/v1', true ),
			// Must NOT match a sibling namespace that merely shares a prefix.
			'prefix not a segment'  => array( '/wp-json/acme/v10', 'acme/v1', false ),
			'different namespace'   => array( '/wp-json/other/v1', 'acme/v1', false ),
			'empty url'             => array( '', 'acme/v1', false ),
		);
	}

	public function test_is_described_by_endpoint() {
		// An explicit provider registered a rich resource whose endpoint addresses
		// the namespace (under a *different* id) — the auto-stub must stand down.
		$resources = array(
			'acme-store' => array(
				'id'        => 'acme-store',
				'endpoints' => array( array( 'url' => '/wp-json/acme/v1/products', 'type' => 'rest' ) ),
			),
		);
		$this->assertTrue( RestApi::is_described( 'acme/v1', $resources ) );
		$this->assertFalse( RestApi::is_described( 'klaviyo/v1', $resources ) );
	}

	public function test_is_described_by_minted_id() {
		// A provider intentionally claimed the id the adapter would mint — treated
		// as an override, so the adapter skips it rather than fighting over last-wins.
		$resources = array( 'acme-v1' => array( 'id' => 'acme-v1' ) );
		$this->assertTrue( RestApi::is_described( 'acme/v1', $resources ) );
	}

	public function test_is_described_false_for_unknown() {
		$this->assertFalse( RestApi::is_described( 'acme/v1', array() ) );
	}

	/**
	 * The seam core fell through: a plugin owning ONE route inside wp/v2 —
	 * which every custom post type with show_in_rest does — must not count as
	 * describing core's whole content API. It did, and a site's posts, pages
	 * and media disappeared from discovery.json while still answering 200.
	 */
	public function test_route_inside_a_namespace_does_not_describe_its_root() {
		$resources = array(
			'fluent-cart' => array(
				'id'        => 'fluent-cart',
				'endpoints' => array( array( 'url' => '/wp-json/wp/v2/fluent-products', 'type' => 'rest' ) ),
			),
		);

		// The prefix match still holds — that is the right answer for a
		// plugin's own namespace, and other callers depend on it.
		$this->assertTrue( RestApi::is_described( 'wp/v2', $resources ) );

		// The root does not: nothing here describes core content.
		$this->assertFalse( RestApi::is_root_described( 'wp/v2', $resources ) );
	}

	public function test_root_described_by_an_endpoint_on_the_namespace_itself() {
		foreach ( array( '/wp-json/wp/v2', 'https://example.com/wp-json/wp/v2', '/wp-json/wp/v2/' ) as $url ) {
			$resources = array(
				'core-ish' => array( 'id' => 'core-ish', 'endpoints' => array( array( 'url' => $url ) ) ),
			);
			$this->assertTrue( RestApi::is_root_described( 'wp/v2', $resources ), $url );
		}
	}

	public function test_root_described_by_minted_id_and_false_when_absent() {
		$this->assertTrue( RestApi::is_root_described( 'acme/v1', array( 'acme-v1' => array( 'id' => 'acme-v1' ) ) ) );
		$this->assertFalse( RestApi::is_root_described( 'wp/v2', array() ) );
		// Never let a longer name ending in the same run answer for it.
		$resources = array( 'x' => array( 'id' => 'x', 'endpoints' => array( array( 'url' => '/wp-json/acme/wp/v2' ) ) ) );
		$this->assertTrue( RestApi::is_root_described( 'wp/v2', $resources ) );
	}
}
