<?php
/**
 * BotVerifier — the forward-confirmed reverse-DNS decision that lets an opt-in owner
 * confirm a claimed search-engine crawler by its source IP, so a scanner pasting a
 * real crawler's User-Agent can't inherit its trust. DNS is injected via the
 * agentimus_reverse_dns / agentimus_forward_dns filters, so the logic is exercised
 * without a live network.
 *
 * The verdict is three-valued: true = forward-confirmed, false = conclusively someone
 * else, null = could-not-determine (fail OPEN — keep the crawler). These tests pin all
 * three, plus the slow-lookup / circuit-breaker / budget paths that must stay fail-open.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\BotVerifier;
use PHPUnit\Framework\TestCase;

final class BotVerifierTest extends TestCase {

	const GOOGLEBOT  = 'mozilla/5.0 (compatible; googlebot/2.1; +http://www.google.com/bot.html)';
	const GOOGLE_IP  = '66.249.66.1';
	const GOOGLE_IP6 = '2001:4860:4860::8888';

	protected function setUp(): void {
		\_af_reset_options();
		// Exercise the real budget / circuit-breaker counters, which live in transients.
		$GLOBALS['_af_transients_on'] = true;
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

	/* -- verify_engine: VERIFIED (true) ------------------------------------ */

	public function test_a_genuine_crawler_ip_forward_confirms() {
		$this->fake_dns( 'crawl-66-249-66-1.googlebot.com', array( self::GOOGLE_IP ) );
		$this->assertTrue( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ) );
	}

	public function test_a_genuine_ipv6_crawler_forward_confirms() {
		$this->fake_dns( 'crawl.googlebot.com', array( self::GOOGLE_IP6 ) );
		$this->assertTrue( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP6 ) );
	}

	/* -- verify_engine: CONCLUSIVE SPOOF (false) --------------------------- */

	public function test_a_spoofer_whose_ptr_is_not_the_engine_is_conclusively_false() {
		$this->fake_dns( 'host.scanner-farm.example', array( '203.0.113.9' ) );
		$this->assertFalse( BotVerifier::verify_engine( self::GOOGLEBOT, '203.0.113.9' ) );
	}

	public function test_a_prompt_no_ptr_record_is_conclusively_false() {
		// The resolver answered promptly with no PTR → the IP is not a reverse-verifiable
		// crawler. (A real crawler IP always has a PTR.)
		$this->fake_dns( '', array() );
		$this->assertFalse( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ) );
	}

	public function test_a_fake_ptr_that_forward_resolves_elsewhere_is_conclusively_false() {
		// PTR is in googlebot.com, but the hostname resolves to a DIFFERENT same-family IP
		// — the forward-confirmation half of FCrDNS catches this.
		$this->fake_dns( 'crawl.googlebot.com', array( '1.2.3.4' ) );
		$this->assertFalse( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ) );
	}

	public function test_a_fake_ptr_for_an_ipv6_client_resolving_to_another_v6_is_false() {
		$this->fake_dns( 'crawl.googlebot.com', array( '2001:4860:4860::1111' ) );
		$this->assertFalse( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP6 ) );
	}

	/* -- verify_engine: INCONCLUSIVE (null → keep the crawler) ------------- */

	public function test_an_empty_ip_is_inconclusive_not_a_spoof() {
		$this->fake_dns( 'crawl.googlebot.com', array( self::GOOGLE_IP ) );
		$this->assertNull( BotVerifier::verify_engine( self::GOOGLEBOT, '' ) );
	}

	public function test_an_ipv6_client_whose_forward_yields_only_ipv4_is_inconclusive() {
		// Host without AAAA support: the PTR matches, but forward resolves only IPv4 for an
		// IPv6 client — we couldn't actually check that family, so DON'T call it a spoof.
		$this->fake_dns( 'crawl.googlebot.com', array( self::GOOGLE_IP ) ); // v4 only
		$this->assertNull( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP6 ) );
	}

	public function test_a_slow_reverse_lookup_is_inconclusive_and_never_a_spoof() {
		add_filter( 'agentimus_verify_slow_ms', static function () { return 5; } );
		add_filter( 'agentimus_reverse_dns', static function () { usleep( 20000 ); return 'host.scanner-farm.example'; }, 10, 2 );
		// Even though the PTR is a non-crawler domain (would be a conclusive spoof if
		// prompt), a SLOW lookup fails open — a slow resolver must never lose a crawler.
		$this->assertNull( BotVerifier::verify_engine( self::GOOGLEBOT, '203.0.113.9' ) );
	}

	public function test_budget_exhaustion_fails_open_not_closed() {
		add_filter( 'agentimus_verify_lookup_budget', static function () { return 1; } );
		$this->fake_dns( 'crawl.googlebot.com', array( self::GOOGLE_IP ) );

		// First (fresh IP) spends the single budgeted lookup and confirms.
		$this->assertTrue( BotVerifier::verify_ip( self::GOOGLE_IP, array( '.googlebot.com' ) ) );
		// Second (different fresh IP) is past budget → inconclusive, NOT a spoof.
		$this->assertNull( BotVerifier::verify_ip( '66.249.66.2', array( '.googlebot.com' ) ) );
	}

	/* -- circuit breaker: repeated slow lookups stop touching DNS ---------- */

	public function test_repeated_slow_lookups_trip_the_breaker_and_then_fail_open() {
		add_filter( 'agentimus_verify_slow_ms', static function () { return 5; } );
		add_filter( 'agentimus_reverse_dns', static function () { usleep( 20000 ); return 'crawl.googlebot.com'; }, 10, 2 );

		// Two slow lookups on distinct IPs each strike; the second trips the breaker.
		$this->assertNull( BotVerifier::verify_ip( '198.51.100.1', array( '.googlebot.com' ) ) );
		$this->assertNull( BotVerifier::verify_ip( '198.51.100.2', array( '.googlebot.com' ) ) );
		$this->assertNotEmpty( get_transient( BotVerifier::TRIP_KEY ), 'breaker should be open' );

		// While open, a NEW IP returns null without any DNS — even one that would confirm.
		$this->assertNull( BotVerifier::verify_ip( '198.51.100.3', array( '.googlebot.com' ) ) );
	}
}
