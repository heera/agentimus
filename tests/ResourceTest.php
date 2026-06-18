<?php
/**
 * Resource — the registration validator/normalizer (spec §04).
 *
 * @package Agentomatic\Tests
 */

namespace Agentomatic\Tests;

use Agentomatic\Discovery\Resource;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class ResourceTest extends TestCase {

	public function test_valid_resource_normalizes_with_default_auth() {
		$r = Resource::normalize( array( 'id' => 'acme-bookings', 'title' => 'Acme', 'type' => 'scheduling' ) );
		$this->assertIsArray( $r );
		$this->assertSame( 'acme-bookings', $r['id'] );
		$this->assertSame( 'scheduling', $r['type'] );
		$this->assertSame( array( 'type' => 'none', 'oidc' => '', 'scopes' => array(), 'docs' => '' ), $r['auth'] );
	}

	/** @dataProvider missingRequired */
	public function test_missing_required_fields_are_rejected( array $raw ) {
		$this->assertInstanceOf( WP_Error::class, Resource::normalize( $raw ) );
	}

	public function missingRequired(): array {
		return array(
			'no id'    => array( array( 'title' => 'X', 'type' => 'commerce' ) ),
			'no title' => array( array( 'id' => 'a', 'type' => 'commerce' ) ),
			'no type'  => array( array( 'id' => 'a', 'title' => 'A' ) ),
		);
	}

	public function test_invalid_slug_is_rejected() {
		$this->assertInstanceOf( WP_Error::class, Resource::normalize( array( 'id' => 'Bad_ID', 'title' => 'X', 'type' => 'commerce' ) ) );
	}

	public function test_unknown_type_rejected_but_x_extension_accepted() {
		$this->assertInstanceOf( WP_Error::class, Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'frobnicate' ) ) );
		$ok = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'x-acme-loyalty' ) );
		$this->assertIsArray( $ok );
		$this->assertSame( 'x-acme-loyalty', $ok['type'] );
	}

	public function test_string_endpoint_is_coerced_to_rest() {
		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'commerce', 'endpoints' => array( '/wp-json/x' ) ) );
		$this->assertCount( 1, $r['endpoints'] );
		$this->assertSame( '/wp-json/x', $r['endpoints'][0]['url'] );
		$this->assertSame( 'rest', $r['endpoints'][0]['type'] );
	}

	public function test_provider_is_auto_attributed_and_overwrites_author_value() {
		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'commerce', 'provider' => array( 'plugin' => 'evil/evil.php' ) ) );
		$this->assertSame( 'agentomatic/agentomatic.php', $r['provider']['plugin'] );
	}

	public function test_capabilities_are_preserved_as_list() {
		$r = Resource::normalize( array( 'id' => 'a', 'title' => 'A', 'type' => 'commerce', 'capabilities' => array( 'commerce.products.read', 'commerce.cart.write' ) ) );
		$this->assertSame( array( 'commerce.products.read', 'commerce.cart.write' ), $r['capabilities'] );
	}
}
