<?php
/**
 * Smoke test — proves the integration harness itself works: the plugin bootstraps
 * inside a REAL WordPress + database (not the unit stub), its autoloader resolves
 * classes, and a real $wpdb is talking to a real server.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use WP_UnitTestCase;

final class SmokeTest extends WP_UnitTestCase {

	public function test_plugin_bootstraps_inside_real_wordpress() {
		$this->assertTrue( defined( 'AGENTIMUS_VERSION' ), 'the plugin main file loaded' );
		$this->assertTrue( class_exists( '\\Agentimus\\Plugin' ), 'the PSR-4 autoloader resolves plugin classes' );
		// Real WordPress, not the hand-rolled unit stub.
		$this->assertTrue( function_exists( 'wp_insert_post' ) && function_exists( 'register_rest_route' ) );
	}

	public function test_a_real_wpdb_is_connected() {
		global $wpdb;
		$this->assertInstanceOf( '\\wpdb', $wpdb );
		$this->assertSame( '1', (string) $wpdb->get_var( 'SELECT 1' ) );
	}
}
