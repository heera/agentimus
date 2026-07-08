<?php
/**
 * Recorder::should_capture_ip — the pure data-minimisation rule behind the opt-in
 * flagged-IP store. IPs are personal data, so this is the guarantee that a normal install
 * captures nothing and even an enabled one captures ONLY the security-relevant clients.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Recorder;
use PHPUnit\Framework\TestCase;

final class RecorderIpTest extends TestCase {

	public function test_off_captures_nothing_even_for_a_flagged_client() {
		$this->assertFalse( Recorder::should_capture_ip( 2, true, false ) );
		$this->assertFalse( Recorder::should_capture_ip( 0, false, false ) );
	}

	public function test_on_captures_an_impersonator() {
		$this->assertTrue( Recorder::should_capture_ip( 2, false, true ), 'verdict 2 = failed reverse-DNS' );
	}

	public function test_on_captures_a_legacy_device_spoof() {
		$this->assertTrue( Recorder::should_capture_ip( 0, true, true ) );
	}

	public function test_on_never_captures_ordinary_or_verified_traffic() {
		$this->assertFalse( Recorder::should_capture_ip( 0, false, true ), 'ordinary client' );
		$this->assertFalse( Recorder::should_capture_ip( 1, false, true ), 'a forward-confirmed real crawler' );
	}

	/* -- engine_verdict: claim-based, so identify mode never loses impostor detection ----- */

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_transients_on'] = true;
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Inject a PTR + forward set, as {@see BotVerifierTest}. */
	private function fake_dns( $host, array $forward ) {
		add_filter( 'agentimus_reverse_dns', static function () use ( $host ) { return $host; }, 10, 2 );
		add_filter( 'agentimus_forward_dns', static function () use ( $forward ) { return $forward; }, 10, 2 );
	}

	public function test_engine_verdict_flags_an_impostor_by_the_UA_CLAIM_not_the_ptr() {
		// The bug this locks: a UA claiming Applebot from an IP whose PTR is a random non-Apple
		// host must still be verdict 2 (impostor) — the verdict follows the CLAIM, not the PTR's
		// own (empty) engine, so identify mode can't silently downgrade it to 0.
		$this->fake_dns( 'host.scanner-vps.example', array( '203.0.113.77' ) );
		$ua = 'Mozilla/5.0 (compatible; Applebot/0.1; +http://www.apple.com/go/applebot)';
		$this->assertSame( 2, Recorder::engine_verdict( $ua, '203.0.113.77' ) );
	}

	public function test_engine_verdict_verifies_a_genuine_crawler() {
		$this->fake_dns( 'crawl.applebot.apple.com', array( '17.58.63.100' ) );
		$ua = 'Mozilla/5.0 (compatible; Applebot/0.1; +http://www.apple.com/go/applebot)';
		$this->assertSame( 1, Recorder::engine_verdict( $ua, '17.58.63.100' ) );
	}

	public function test_engine_verdict_is_zero_for_a_ua_that_claims_no_verifiable_engine() {
		$this->fake_dns( 'ec2-1.compute.amazonaws.com', array( '203.0.113.9' ) );
		$this->assertSame( 0, Recorder::engine_verdict( 'AcmeCrawler/1.0', '203.0.113.9' ) );
	}
}
