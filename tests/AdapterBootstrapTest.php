<?php
/**
 * AdapterBootstrap gate chain — the OFF path must be a true zero: no filter
 * touched, no vendor file required, no adapter class pulled in. The unit env
 * has no Abilities API (function_exists('wp_register_ability') is false), so
 * these tests also lock gate 2: even an enabled toggle goes nowhere on a
 * pre-6.9 WordPress. The full ON path (require + instance() + our server
 * appearing on the REST surface) is integration-tested in
 * tests/integration/McpServerRestTest.php against a real WordPress.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Abilities\AdapterBootstrap;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class AdapterBootstrapTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	private function bootstrap( array $settings = array() ): AdapterBootstrap {
		update_option( Settings::OPTION, $settings );
		return new AdapterBootstrap( new Settings() );
	}

	public function test_off_by_default_touches_nothing() {
		$this->bootstrap()->maybe_bootstrap();
		$this->assertArrayNotHasKey(
			'mcp_adapter_create_default_server',
			$GLOBALS['_af_filters'],
			'The default (off) must not register the default-server suppression filter.'
		);
	}

	public function test_enabled_without_the_abilities_api_stands_down() {
		// The unit env deliberately lacks wp_register_ability() — this IS the
		// WP < 6.9 site. The toggle alone must not reach the filter or the vendor tree.
		$this->assertFalse( function_exists( 'wp_register_ability' ), 'precondition: unit env has no Abilities API' );
		$this->bootstrap( array( 'enable_mcp_server' => 1 ) )->maybe_bootstrap();
		$this->assertArrayNotHasKey( 'mcp_adapter_create_default_server', $GLOBALS['_af_filters'] );
	}

	public function test_default_flag_exists_and_is_off() {
		$this->assertFalse(
			( new Settings() )->enabled( 'enable_mcp_server' ),
			'enable_mcp_server must default to OFF — the MCP server is strictly opt-in.'
		);
	}
}
