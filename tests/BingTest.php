<?php
/**
 * Bing search source — the pure halves: WCF date parsing, host matching, and
 * the key's at-rest encryption with the verification code's survival across a
 * disconnect.
 *
 * @package Agentimus\Tests
 */

use Agentimus\Bing\Client;
use Agentimus\Bing\Rest;
use Agentimus\Bing\Settings;
use PHPUnit\Framework\TestCase;

final class BingTest extends TestCase {

	protected function setUp(): void {
		_af_reset_options();
	}

	// ── Client::wcf_date ────────────────────────────────────────────────────

	public function test_wcf_dates_parse_to_utc_days() {
		// 2026-07-01T00:00:00Z in milliseconds.
		$this->assertSame( '2026-07-01', Client::wcf_date( '/Date(1782864000000)/' ) );
		// A zone suffix must not break the parse — the day is taken from UTC.
		$this->assertSame( '2026-07-01', Client::wcf_date( '/Date(1782864000000-0700)/' ) );
	}

	public function test_garbage_dates_become_empty_not_wrong() {
		$this->assertSame( '', Client::wcf_date( '' ) );
		$this->assertSame( '', Client::wcf_date( '2026-07-01' ) );
		$this->assertSame( '', Client::wcf_date( '/Date(abc)/' ) );
	}

	// ── Rest::hosts_match ───────────────────────────────────────────────────

	public function test_hosts_match_tolerates_www_and_scheme() {
		$this->assertTrue( Rest::hosts_match( 'http://www.example.com/', 'https://example.com/' ) );
		$this->assertTrue( Rest::hosts_match( 'https://example.com', 'https://example.com/sub/' ) );
		$this->assertFalse( Rest::hosts_match( 'https://example.com/', 'https://example.org/' ) );
		// A subdomain is a DIFFERENT site — www is the only tolerated prefix.
		$this->assertFalse( Rest::hosts_match( 'https://blog.example.com/', 'https://example.com/' ) );
		$this->assertFalse( Rest::hosts_match( '', '' ) );
	}

	// ── Settings ────────────────────────────────────────────────────────────

	public function test_key_is_stored_encrypted_and_never_in_public_view() {
		$settings = new Settings();
		$settings->connect( 'plain-api-key-123', 'https://example.com/' );

		$stored = get_option( Settings::OPTION );
		$this->assertNotSame( 'plain-api-key-123', $stored['api_key'], 'the key must not sit in the option as plaintext' );
		$this->assertSame( 'plain-api-key-123', $settings->api_key(), 'decryption must round-trip' );

		$view = $settings->public_view();
		$this->assertTrue( $view['connected'] );
		$this->assertArrayNotHasKey( 'api_key', $view );
		$this->assertArrayNotHasKey( 'msvalidate', $view, 'the raw code stays server-side; the view carries only hasMsvalidate' );
	}

	public function test_disconnect_forgets_the_key_but_keeps_the_verification_code() {
		$settings = new Settings();
		$settings->set_msvalidate( 'ABC123' );
		$settings->connect( 'plain-api-key-123', 'https://example.com/' );

		$settings->disconnect();

		$this->assertFalse( $settings->connected() );
		$this->assertSame( '', $settings->api_key() );
		// The printed tag is what makes reconnecting one paste — it survives.
		$this->assertSame( 'ABC123', $settings->get( 'msvalidate' ) );
		$view = $settings->public_view();
		$this->assertTrue( $view['hasMsvalidate'] );
	}
}
