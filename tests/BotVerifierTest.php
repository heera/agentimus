<?php
/**
 * BotVerifier — the forward-confirmed reverse-DNS decision that lets an opt-in owner
 * confirm a claimed search-engine crawler by its source IP, so a scanner pasting a
 * real crawler's User-Agent can't inherit its trust. DNS is injected via the
 * agentimus_reverse_dns / agentimus_forward_dns filters, so the logic is exercised
 * without a live network.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\BotVerifier;
use PHPUnit\Framework\TestCase;

final class BotVerifierTest extends TestCase {

	const GOOGLEBOT  = 'mozilla/5.0 (compatible; googlebot/2.1; +http://www.google.com/bot.html)';
	const GOOGLE_IP  = '66.249.66.1';

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Simulate the network: a PTR hostname for any IP, and a forward-resolution IP set. */
	private function fake_dns( $host, array $forward_ips ) {
		add_filter( 'agentimus_reverse_dns', static function () use ( $host ) { return $host; }, 10, 2 );
		add_filter( 'agentimus_forward_dns', static function () use ( $forward_ips ) { return $forward_ips; }, 10, 2 );
	}

	/* -- claimed_engine ---------------------------------------------------- */

	public function test_claimed_engine_detects_a_verifiable_engine_only() {
		$this->assertSame( 'googlebot', BotVerifier::claimed_engine( self::GOOGLEBOT ) );
		$this->assertSame( '', BotVerifier::claimed_engine( 'mozilla/5.0 chrome/120 safari/537' ) );
	}

	/* -- host_in_domains: look-alike resistant ----------------------------- */

	public function test_host_in_domains_matches_real_subdomains_and_apex_but_not_lookalikes() {
		$domains = array( '.googlebot.com' );
		$this->assertTrue( BotVerifier::host_in_domains( 'crawl-66-249-66-1.googlebot.com', $domains ) );
		$this->assertTrue( BotVerifier::host_in_domains( 'googlebot.com', $domains ), 'apex' );
		$this->assertFalse( BotVerifier::host_in_domains( 'evil-googlebot.com', $domains ), 'suffix look-alike' );
		$this->assertFalse( BotVerifier::host_in_domains( 'googlebot.com.attacker.net', $domains ), 'subdomain trick' );
	}

	/* -- verify_engine: the FCrDNS decision -------------------------------- */

	public function test_a_genuine_crawler_ip_forward_confirms() {
		$this->fake_dns( 'crawl-66-249-66-1.googlebot.com', array( self::GOOGLE_IP ) );
		$this->assertTrue( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ) );
	}

	public function test_a_spoofer_whose_ptr_is_not_the_engine_fails() {
		$this->fake_dns( 'host.scanner-farm.example', array( '203.0.113.9' ) );
		$this->assertFalse( BotVerifier::verify_engine( self::GOOGLEBOT, '203.0.113.9' ) );
	}

	public function test_no_ptr_record_fails() {
		$this->fake_dns( '', array() );
		$this->assertFalse( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ) );
	}

	public function test_forward_resolution_not_matching_the_ip_fails() {
		// PTR is in googlebot.com, but the hostname resolves to a DIFFERENT IP — the
		// forward-confirmation half of FCrDNS catches this.
		$this->fake_dns( 'crawl.googlebot.com', array( '1.2.3.4' ) );
		$this->assertFalse( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ) );
	}

	public function test_a_ua_claiming_no_verifiable_engine_is_not_verified() {
		$this->fake_dns( 'crawl.googlebot.com', array( self::GOOGLE_IP ) );
		$this->assertFalse( BotVerifier::verify_engine( 'mozilla/5.0 chrome/120', self::GOOGLE_IP ) );
	}

	public function test_an_empty_ip_is_never_verified() {
		$this->fake_dns( 'crawl.googlebot.com', array( self::GOOGLE_IP ) );
		$this->assertFalse( BotVerifier::verify_engine( self::GOOGLEBOT, '' ) );
	}
}
