<?php
/**
 * ClientIp — resolving the real source IP behind a trusted proxy, safely. The header a
 * proxy adds (Cloudflare's CF-Connecting-IP) is honoured ONLY when the direct peer is
 * inside that proxy's ranges, so a forged header from the open internet is ignored.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase {

	// A real Cloudflare IPv4 (in 104.16.0.0/13) and IPv6 (in 2606:4700::/32).
	const CF_V4 = '104.16.5.5';
	const CF_V6 = '2606:4700::1111';

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'] );
		\_af_reset_options();
	}

	private function request( $remote, $cf = null ) {
		$_SERVER['REMOTE_ADDR'] = $remote;
		if ( null === $cf ) {
			unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		} else {
			$_SERVER['HTTP_CF_CONNECTING_IP'] = $cf;
		}
	}

	/* -- in_range: IPv4 + IPv6 CIDR matching ------------------------------- */

	public function test_in_range_matches_ipv4_within_and_rejects_outside() {
		$this->assertTrue( ClientIp::in_range( '104.16.5.5', '104.16.0.0/13' ) );
		$this->assertFalse( ClientIp::in_range( '8.8.8.8', '104.16.0.0/13' ) );
	}

	public function test_in_range_matches_ipv6_within_and_rejects_outside() {
		$this->assertTrue( ClientIp::in_range( '2606:4700::1', '2606:4700::/32' ) );
		$this->assertFalse( ClientIp::in_range( '2001:db8::1', '2606:4700::/32' ) );
	}

	public function test_in_range_never_matches_across_families() {
		$this->assertFalse( ClientIp::in_range( '104.16.5.5', '2606:4700::/32' ) );
		$this->assertFalse( ClientIp::in_range( '2606:4700::1', '104.16.0.0/13' ) );
	}

	/* -- resolve: the safety guarantee ------------------------------------- */

	/** THE security test: a forged CF-Connecting-IP from a NON-Cloudflare peer is
	 *  ignored — you cannot spoof your source IP by just sending the header. */
	public function test_a_forged_header_from_a_non_proxy_ip_is_ignored() {
		$this->request( '203.0.113.9', '66.249.66.1' ); // direct peer is NOT Cloudflare
		$this->assertSame( '203.0.113.9', ClientIp::resolve(), 'must keep REMOTE_ADDR' );
	}

	/** Behind Cloudflare (direct peer inside CF ranges), the CF-Connecting-IP is trusted. */
	public function test_behind_cloudflare_the_forwarded_ip_is_used() {
		$this->request( self::CF_V4, '66.249.66.1' );
		$this->assertSame( '66.249.66.1', ClientIp::resolve() );
	}

	public function test_behind_cloudflare_an_ipv6_client_is_resolved() {
		$this->request( self::CF_V6, '2001:4860:4860::8888' );
		$this->assertSame( '2001:4860:4860::8888', ClientIp::resolve() );
	}

	public function test_behind_cloudflare_but_no_header_falls_back_to_remote() {
		$this->request( self::CF_V4, null );
		$this->assertSame( self::CF_V4, ClientIp::resolve() );
	}

	public function test_behind_cloudflare_but_invalid_header_falls_back_to_remote() {
		$this->request( self::CF_V4, 'not-an-ip' );
		$this->assertSame( self::CF_V4, ClientIp::resolve() );
	}

	public function test_a_missing_remote_addr_resolves_to_empty() {
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( '', ClientIp::resolve() );
	}

	/* -- extensible without a UI setting ----------------------------------- */

	public function test_a_custom_trusted_proxy_can_be_added_by_filter() {
		add_filter( 'agentimus_trusted_proxies', static function () {
			return array( array( 'header' => 'HTTP_X_REAL_IP', 'ranges' => array( '10.0.0.0/8' ) ) );
		} );
		$_SERVER['REMOTE_ADDR']    = '10.1.2.3'; // a trusted internal load balancer
		$_SERVER['HTTP_X_REAL_IP'] = '66.249.66.1';
		$this->assertSame( '66.249.66.1', ClientIp::resolve() );

		// …but the same header from an untrusted peer is still ignored.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame( '203.0.113.9', ClientIp::resolve() );
		unset( $_SERVER['HTTP_X_REAL_IP'] );
	}
}
