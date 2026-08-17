<?php
/**
 * Registry — the collector providers register with (spec §04).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Registry;
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

	/**
	 * ⭐ A plugin keeps its row when it uses a word we don't know — and the owner
	 * is told which word was changed, and how to declare it on purpose. Silence
	 * here would be us quietly editing somebody else's plugin.
	 */
	public function test_an_undeclared_kind_is_kept_and_the_change_is_named() {
		$reg = Registry::instance();
		$this->assertTrue( $reg->register( array( 'id' => 'hub', 'title' => 'Hub', 'type' => 'community' ) ) );

		$this->assertArrayHasKey( 'hub', $reg->resources(), 'The row survives the word.' );
		$this->assertSame( 'x-community', $reg->resources()['hub']['type'] );

		$warnings = array_values(
			array_filter( $reg->notices(), static function ( $n ) { return 'warning' === $n['level']; } )
		);
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'community', $warnings[0]['message'] );
		$this->assertStringContainsString( 'x-community', $warnings[0]['message'] );
		$this->assertStringContainsString( 'agentimus_resource_types', $warnings[0]['message'], 'It must say how to declare a kind on purpose.' );
	}

	/** ⛔ A kind we know, or one the site declared, is nobody's business to warn about. */
	public function test_a_known_kind_is_recorded_without_a_word_of_complaint() {
		\_af_reset_options();
		add_filter(
			'agentimus_resource_types',
			static function ( $types ) {
				$types[] = 'loyalty';
				return $types;
			}
		);

		$reg = Registry::instance();
		$reg->register( array( 'id' => 'shop', 'title' => 'Shop', 'type' => 'commerce' ) );
		$reg->register( array( 'id' => 'points', 'title' => 'Points', 'type' => 'loyalty' ) );

		$this->assertSame( 'loyalty', $reg->resources()['points']['type'] );
		$this->assertSame( array(), $reg->notices() );
		\_af_reset_options();
	}

	public function test_add_well_known_requires_a_source() {
		$reg = Registry::instance();
		$this->assertInstanceOf( WP_Error::class, $reg->add_well_known( array( 'name' => 'security.txt' ) ) );
		$this->assertTrue( $reg->add_well_known( array( 'name' => 'security.txt', 'callback' => static function () { return 'x'; } ) ) );
		$this->assertArrayHasKey( 'security.txt', $reg->well_known() );
	}
}
