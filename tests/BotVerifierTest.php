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

	/* -- Admin re-check: bypasses the budget, busts the cache -------------- */

	public function test_reverify_runs_even_when_the_lookup_budget_is_spent() {
		// The serve path's anti-amplification budget guards the unauthenticated path; a
		// capability-gated admin re-check is not that threat, so it must run even when the
		// budget is fully spent (where verify_engine() fails open to null on a fresh IP). The
		// forward set carries both IPs so each forward-confirms against itself.
		add_filter( 'agentimus_verify_lookup_budget', static function () { return 1; } );
		$this->fake_dns( 'crawl.googlebot.com', array( self::GOOGLE_IP, '66.249.66.2' ) );

		// One fresh lookup spends the single budgeted slot.
		$this->assertTrue( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ) );
		// A second, different fresh IP is now past budget on the serve path → inconclusive.
		$this->assertNull( BotVerifier::verify_engine( self::GOOGLEBOT, '66.249.66.2' ) );
		// …but an admin re-check of that same IP still runs and forward-confirms it.
		$this->assertTrue( BotVerifier::reverify_engine( self::GOOGLEBOT, '66.249.66.2' ) );
	}

	public function test_reverify_busts_a_stale_cached_verdict() {
		// A prior serve-path check cached "verified" for this IP+engine. If the address is later
		// reassigned (now a spoofer), the serve path keeps replaying the stale 'verified' — but a
		// re-check must look again and return the fresh, conclusive spoof. Both use the same engine
		// domains, so they share the cache key the re-check has to bust.
		$this->fake_dns( 'crawl.googlebot.com', array( self::GOOGLE_IP ) );
		$this->assertTrue( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ) );
		$this->assertTrue( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ), 'served from cache' );

		// The address now reverse-resolves to a non-crawler domain.
		remove_all_filters( 'agentimus_reverse_dns' );
		remove_all_filters( 'agentimus_forward_dns' );
		$this->fake_dns( 'host.scanner-farm.example', array( '203.0.113.9' ) );

		// The serve path still trusts the stale cache; only the re-check looks again.
		$this->assertTrue( BotVerifier::verify_engine( self::GOOGLEBOT, self::GOOGLE_IP ), 'serve path replays the cached verdict' );
		$this->assertFalse( BotVerifier::reverify_engine( self::GOOGLEBOT, self::GOOGLE_IP ), 'a re-check ignores the stale cached verdict' );
	}

	public function test_reverify_of_a_non_verifiable_ua_and_of_a_missing_ip_are_both_null() {
		// A UA that claims nothing in the (owner-editable) registry is INDETERMINATE, not
		// "conclusively fake" — an owner removing an engine must make it unverifiable,
		// never strip the real crawler's protection or mint a spoofed verdict.
		$this->assertNull( BotVerifier::reverify_engine( 'mozilla/5.0 chrome/120 safari/537', self::GOOGLE_IP ) );
		$this->assertNull( BotVerifier::reverify_engine( self::GOOGLEBOT, '' ) );
	}

	/* -- identify_ip: the engine-agnostic "Check an IP" tool --------------- */

	public function test_identify_names_a_genuine_crawler_and_its_engine() {
		$this->fake_dns( 'crawl-66-249-66-1.googlebot.com', array( self::GOOGLE_IP ) );
		$r = BotVerifier::identify_ip( self::GOOGLE_IP );
		$this->assertSame( 1, $r['verdict'] );
		$this->assertSame( 'googlebot', $r['engine'] );
		$this->assertSame( 'crawl-66-249-66-1.googlebot.com', $r['host'] );
	}

	public function test_identify_reports_a_non_engine_host_as_no_known_engine() {
		$this->fake_dns( 'host.some-isp.example', array( '203.0.113.9' ) );
		$r = BotVerifier::identify_ip( '203.0.113.9' );
		$this->assertSame( 0, $r['verdict'] );
		$this->assertSame( '', $r['engine'] );
		$this->assertSame( 'host.some-isp.example', $r['host'], 'The resolved host is still reported.' );
	}

	public function test_identify_flags_a_forged_ptr_as_an_impostor() {
		// PTR sits in googlebot.com but forward-resolves to a DIFFERENT same-family IP.
		$this->fake_dns( 'crawl.googlebot.com', array( '1.2.3.4' ) );
		$r = BotVerifier::identify_ip( self::GOOGLE_IP );
		$this->assertSame( 2, $r['verdict'] );
		$this->assertSame( 'googlebot', $r['engine'] );
	}

	public function test_identify_reports_no_ptr_record() {
		$this->fake_dns( '', array() );
		$r = BotVerifier::identify_ip( self::GOOGLE_IP );
		$this->assertSame( 0, $r['verdict'] );
		$this->assertSame( '', $r['host'] );
	}

	public function test_identify_rejects_a_non_ip() {
		$r = BotVerifier::identify_ip( 'not-an-ip' );
		$this->assertSame( 0, $r['verdict'] );
		$this->assertSame( '', $r['host'] );
	}

	public function test_identify_reports_the_owning_network() {
		$this->fake_dns( 'crawl-66-249-66-1.googlebot.com', array( self::GOOGLE_IP ) );
		$this->assertSame( 'googlebot.com', BotVerifier::identify_ip( self::GOOGLE_IP )['network'] );
	}

	/* -- network_from_host: the registrable "owning network" --------------- */

	public function test_network_from_host_strips_ip_encoding_labels() {
		$this->assertSame( 'amazonaws.com', BotVerifier::network_from_host( 'ec2-52-1-2-3.compute.amazonaws.com' ) );
		$this->assertSame( 'googlebot.com', BotVerifier::network_from_host( 'crawl-66-249-66-1.googlebot.com' ) );
		$this->assertSame( 'example.co.uk', BotVerifier::network_from_host( 'host12.node.example.co.uk' ), 'two-level TLD kept' );
		$this->assertSame( 'foo.com', BotVerifier::network_from_host( 'foo.com' ) );
		$this->assertSame( '', BotVerifier::network_from_host( '' ) );
		$this->assertSame( '', BotVerifier::network_from_host( 'localhost' ), 'no dot → no network' );
	}

	/* -- attribute_ip: serve-path attribution (cached, budgeted, fail-open) - */

	public function test_attribute_names_the_network_and_verifies_an_engine() {
		$this->fake_dns( 'crawl-66-249-66-1.googlebot.com', array( self::GOOGLE_IP ) );
		$a = BotVerifier::attribute_ip( self::GOOGLE_IP );
		$this->assertSame( 'googlebot.com', $a['network'] );
		$this->assertSame( 'googlebot', $a['engine'] );
		$this->assertSame( 1, $a['verdict'] );
		$this->assertArrayNotHasKey( 'ip', $a, 'attribution never returns the raw IP' );
	}

	public function test_attribute_names_a_non_engine_network_without_a_verdict() {
		$this->fake_dns( 'ec2-203-0-113-9.compute.amazonaws.com', array( '203.0.113.9' ) );
		$a = BotVerifier::attribute_ip( '203.0.113.9' );
		$this->assertSame( 'amazonaws.com', $a['network'] );
		$this->assertSame( '', $a['engine'] );
		$this->assertSame( 0, $a['verdict'], 'no engine claim → nothing to verify' );
	}

	public function test_attribute_flags_a_forged_engine_ptr() {
		$this->fake_dns( 'crawl.googlebot.com', array( '1.2.3.4' ) );
		$a = BotVerifier::attribute_ip( self::GOOGLE_IP );
		$this->assertSame( 'googlebot.com', $a['network'] );
		$this->assertSame( 2, $a['verdict'] );
	}

	public function test_attribute_fails_open_to_empty_when_the_budget_is_spent() {
		add_filter( 'agentimus_verify_lookup_budget', static function () { return 1; } );
		$this->fake_dns( 'ec2-203-0-113-9.compute.amazonaws.com', array( '203.0.113.9' ) );
		$this->assertSame( 'amazonaws.com', BotVerifier::attribute_ip( '203.0.113.9' )['network'] );
		// A different fresh IP is now past budget → empty attribution, never a wrong one.
		$this->assertSame( '', BotVerifier::attribute_ip( '198.51.100.7' )['network'] );
	}

	public function test_attribute_caches_per_ip() {
		$this->fake_dns( 'ec2-1.compute.amazonaws.com', array( '203.0.113.20' ) );
		$this->assertSame( 'amazonaws.com', BotVerifier::attribute_ip( '203.0.113.20' )['network'] );
		// Repoint DNS; the cached attribution is still returned without a fresh lookup.
		remove_all_filters( 'agentimus_reverse_dns' );
		remove_all_filters( 'agentimus_forward_dns' );
		$this->fake_dns( 'somewhere.else.net', array( '203.0.113.20' ) );
		$this->assertSame( 'amazonaws.com', BotVerifier::attribute_ip( '203.0.113.20' )['network'], 'served from cache' );
	}
}
