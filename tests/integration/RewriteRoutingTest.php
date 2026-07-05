<?php
/**
 * The /.well-known/* routing, via the REAL rewrite rules WordPress generates from the
 * plugin's add_rewrite_rule() calls: a routed name matches a rule to the plugin's
 * query var, and a name the plugin does not own matches NO rule (the rewrite is
 * deliberately un-greedy, so it can't shadow a real on-disk /.well-known file). None
 * of this — real rule generation + matching — exists in the stubbed unit suite.
 *
 * (We inspect the generated rules rather than go_to() the URL, because dispatching a
 * real request fires the plugin's send-headers/stream path, which can't run in CLI.)
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Discovery\WellKnown;
use WP_UnitTestCase;

final class RewriteRoutingTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' ); // rewrite rules are inert on plain permalinks.
		WellKnown::add_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		flush_rewrite_rules( false );
		parent::tear_down();
	}

	/** Every generated rewrite rule that routes to the plugin's well-known query var. */
	private function routing_rules() {
		$out = array();
		foreach ( (array) $GLOBALS['wp_rewrite']->wp_rewrite_rules() as $pattern => $target ) {
			if ( false !== strpos( (string) $target, 'wpd_well_known' ) ) {
				$out[ $pattern ] = $target;
			}
		}
		return $out;
	}

	private function is_routed( $path ) {
		foreach ( array_keys( $this->routing_rules() ) as $pattern ) {
			if ( 1 === @preg_match( '#' . $pattern . '#', $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return true;
			}
		}
		return false;
	}

	public function test_a_routed_well_known_name_has_a_rewrite_rule() {
		$this->assertNotEmpty( $this->routing_rules(), 'the /.well-known routing rules are registered' );
		$this->assertTrue( $this->is_routed( '.well-known/discovery.json' ), 'discovery.json routes to the plugin' );
	}

	public function test_a_name_the_plugin_does_not_own_matches_no_rule() {
		$this->assertFalse( $this->is_routed( '.well-known/definitely-not-ours.txt' ), 'a non-owned name is not claimed' );
	}
}
