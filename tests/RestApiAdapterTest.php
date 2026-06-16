<?php
/**
 * RestApi adapter — the pure, WordPress-free helpers behind zero-config
 * auto-discovery (namespace classification, slugging, capability derivation).
 *
 * @package Agentify\Tests
 */

namespace Agentify\Tests;

use Agentify\Discovery\Adapters\RestApi;
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
			'own'               => array( 'agentify/v1', false ),
			// Third-party — surfaced (no plugin names are special-cased).
			'woo store'         => array( 'wc/store/v1', true ),
			'fluent cart'       => array( 'fluent-cart/v2', true ),
			'arbitrary plugin'  => array( 'acme/v1', true ),
			// Must NOT be over-skipped just for starting with "wp".
			'wp-prefixed plugin' => array( 'wpforms/v1', true ),
		);
	}

	public function test_slug() {
		$this->assertSame( 'acme-v1', RestApi::slug( 'acme/v1' ) );
		$this->assertSame( 'wc-store-v1', RestApi::slug( 'wc/store/v1' ) );
		// Underscores (and any non-alphanumeric) must become hyphens, or the id
		// fails the Resource slug pattern — the bug that produced a validation error.
		$this->assertSame( 'wc-v3-wc-paypal', RestApi::slug( 'wc/v3/wc_paypal' ) );
	}
}
