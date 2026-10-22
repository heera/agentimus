<?php
/**
 * The public beacon's flood cap counts per CLOCK window.
 *
 * ⛔ Found in the 2026-10-23 audit: the counter lived under one fixed key and
 * every hit re-set it with a fresh 60-second life, so under steady traffic it
 * never expired — it climbed to the cap and then refused every hit for a
 * minute, over and over. At half the advertised rate about 30% of real
 * AI-referral visits were dropped. Every sibling limiter (BotVerifier's
 * lookup budget, BotSignature's fetch budget, the recorder's flood cap)
 * buckets its key by window; this one now does too.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Module;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class BeaconRateTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_transients_on'] = true;
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function hit(): bool {
		$m = new \ReflectionMethod( Module::class, 'beacon_within_rate' );
		$m->setAccessible( true );
		return $m->invoke( new Module( new Settings() ) );
	}

	public function test_hits_are_counted_in_the_current_clock_window() {
		$this->hit();
		$this->hit();

		$window = 'agentimus_beacon_rate_' . (int) floor( time() / Module::BEACON_RATE_WINDOW );
		$this->assertSame( 2, (int) get_transient( $window ), 'Counted under this window’s own key…' );
		$this->assertFalse( get_transient( 'agentimus_beacon_rate' ), '…never under one key whose life every hit extends.' );
	}

	public function test_a_full_window_before_this_one_does_not_block_this_one() {
		$previous = 'agentimus_beacon_rate_' . ( (int) floor( time() / Module::BEACON_RATE_WINDOW ) - 1 );
		set_transient( $previous, Module::BEACON_RATE_MAX, Module::BEACON_RATE_WINDOW * 2 );
		set_transient( 'agentimus_beacon_rate', Module::BEACON_RATE_MAX, Module::BEACON_RATE_WINDOW );

		$this->assertTrue( $this->hit() );
	}

	public function test_the_cap_still_holds_within_a_window() {
		$window = 'agentimus_beacon_rate_' . (int) floor( time() / Module::BEACON_RATE_WINDOW );
		set_transient( $window, Module::BEACON_RATE_MAX, Module::BEACON_RATE_WINDOW * 2 );

		$this->assertFalse( $this->hit() );
	}
}
