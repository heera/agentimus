<?php
/**
 * Registry — the collector providers register with (spec §04).
 *
 * @package Agentomatic\Tests
 */

namespace Agentomatic\Tests;

use Agentomatic\Discovery\Registry;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class RegistryTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_registry();
	}

	public function test_register_valid_resource() {
		$reg = Registry::instance();
		$this->assertTrue( $reg->register( array( 'id' => 'shop', 'title' => 'Shop', 'type' => 'commerce' ) ) );
		$this->assertArrayHasKey( 'shop', $reg->resources() );
	}

	public function test_duplicate_id_later_wins_with_warning() {
		$reg = Registry::instance();
		$reg->register( array( 'id' => 'shop', 'title' => 'First', 'type' => 'commerce' ) );
		$reg->register( array( 'id' => 'shop', 'title' => 'Second', 'type' => 'commerce' ) );
		$this->assertSame( 'Second', $reg->resources()['shop']['title'] );
		$warnings = array_filter( $reg->notices(), static function ( $n ) { return 'warning' === $n['level']; } );
		$this->assertNotEmpty( $warnings );
	}

	public function test_invalid_registration_returns_error_and_records_notice() {
		$reg = Registry::instance();
		$res = $reg->register( array( 'id' => 'shop', 'type' => 'commerce' ) ); // missing title
		$this->assertInstanceOf( WP_Error::class, $res );
		$errors = array_filter( $reg->notices(), static function ( $n ) { return 'error' === $n['level']; } );
		$this->assertNotEmpty( $errors );
		$this->assertArrayNotHasKey( 'shop', $reg->resources() );
	}

	public function test_add_well_known_requires_a_source() {
		$reg = Registry::instance();
		$this->assertInstanceOf( WP_Error::class, $reg->add_well_known( array( 'name' => 'security.txt' ) ) );
		$this->assertTrue( $reg->add_well_known( array( 'name' => 'security.txt', 'callback' => static function () { return 'x'; } ) ) );
		$this->assertArrayHasKey( 'security.txt', $reg->well_known() );
	}
}
