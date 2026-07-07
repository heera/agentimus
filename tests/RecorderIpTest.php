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
}
