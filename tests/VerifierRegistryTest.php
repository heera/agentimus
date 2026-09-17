<?php
/**
 * VerifierRegistry + BotRanges — the owner-editable verified-bots registry and the
 * published-IP-range verification method. The registry merge (built-ins minus
 * disabled plus custom), claim resolution, the rDNS bridge into BotVerifier, the
 * range-file parser, and the staleness-asymmetric range verdict are all pure or
 * option-backed, so they're exercised here without network or WP.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\BotRanges;
use Agentimus\BotVerifier;
use Agentimus\Settings;
use Agentimus\VerifierRegistry;
use PHPUnit\Framework\TestCase;

final class VerifierRegistryTest extends TestCase {

	const NOW = 1700000000;

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Store registry edits the way the plugin does — inside the settings option. */
	private function saveEdits( array $disabled = array(), array $custom = array() ): void {
		update_option(
			Settings::OPTION,
			array(
				'verifier_disabled' => $disabled,
				'verifier_custom'   => $custom,
			)
		);
	}

	/* -- Registry merge ---------------------------------------------------- */

	public function test_builtins_cover_the_rdns_engines_and_the_range_operators() {
		$entries = VerifierRegistry::entries();
		foreach ( array( 'googlebot', 'bingbot', 'duckduckbot', 'applebot', 'yandex', 'gptbot', 'oai-searchbot', 'perplexitybot' ) as $token ) {
			$this->assertArrayHasKey( $token, $entries );
		}
		$this->assertNotEmpty( $entries['googlebot']['domains'], 'Googlebot verifies by rDNS.' );
		$this->assertNotEmpty( $entries['googlebot']['url'], 'Googlebot also publishes a range file.' );
		$this->assertSame( array(), $entries['gptbot']['domains'], 'GPTBot is range-only.' );
		$this->assertSame( '', $entries['yandex']['url'], 'Yandex publishes rDNS only.' );
		$this->assertSame( array(), $entries['duckduckbot']['domains'], 'DuckDuckGo publishes an address list only — no rDNS.' );
		$this->assertNotEmpty( $entries['duckduckbot']['url'] );
	}

	public function test_a_disabled_builtin_leaves_the_registry_and_the_rdns_map() {
		$this->saveEdits( array( 'googlebot' ) );
		$this->assertArrayNotHasKey( 'googlebot', VerifierRegistry::entries() );
		$this->assertArrayNotHasKey( 'googlebot', BotVerifier::engine_domains(), 'BotVerifier consumes the same registry.' );
		$this->assertArrayHasKey( 'bingbot', BotVerifier::engine_domains() );
	}

	public function test_a_disabled_engine_becomes_unverifiable_not_fake() {
		$this->saveEdits( array( 'googlebot' ) );
		// The claim no longer resolves, so verification is INDETERMINATE — never a
		// conclusive false that would strip the real crawler's always-allow.
		$this->assertSame( '', BotVerifier::claimed_engine( 'mozilla/5.0 (compatible; googlebot/2.1)' ) );
		$this->assertNull( BotVerifier::verify_engine( 'mozilla/5.0 (compatible; googlebot/2.1)', '203.0.113.9' ) );
	}

	public function test_a_custom_entry_joins_the_registry_and_wins_claims() {
		$this->saveEdits(
			array(),
			array(
				array(
					'token'   => 'c_newbot',
					'label'   => 'NewBot',
					'ua'      => 'newbot',
					'domains' => array( '.crawl.newbot.example' ),
					'url'     => '',
				),
			)
		);
		$this->assertSame( 'c_newbot', VerifierRegistry::claimed( 'mozilla/5.0 (compatible; newbot/1.0)' ) );
		$this->assertArrayHasKey( 'c_newbot', BotVerifier::engine_domains(), 'A custom rDNS entry feeds the engine map.' );
	}

	public function test_claims_resolve_longest_needle_first() {
		// "oai-searchbot" must never be shadowed by a shorter custom needle it contains.
		$this->saveEdits(
			array(),
			array(
				array(
					'token'   => 'c_search',
					'label'   => 'Search',
					'ua'      => 'search',
					'domains' => array( '.example.org' ),
					'url'     => '',
				),
			)
		);
		$this->assertSame( 'oai-searchbot', VerifierRegistry::claimed( 'mozilla/5.0 (compatible; oai-searchbot/1.0)' ) );
	}

	/* -- Range-file parsing ------------------------------------------------- */

	public function test_parser_reads_the_shared_publisher_format() {
		$body = '{"creationTime":"2026-01-01","prefixes":[{"ipv4Prefix":"192.0.2.0/24"},{"ipv6Prefix":"2001:db8::/32"}]}';
		$this->assertSame( array( '192.0.2.0/24', '2001:db8::/32' ), BotRanges::parse_prefixes( $body ) );
	}

	public function test_parser_reads_a_plain_cidr_array_and_drops_garbage() {
		$body = '["198.51.100.0/24","not-an-ip","10.0.0.1"]';
		$this->assertSame( array( '198.51.100.0/24', '10.0.0.1' ), BotRanges::parse_prefixes( $body ) );
	}

	public function test_parser_rejects_unusable_bodies() {
		$this->assertNull( BotRanges::parse_prefixes( 'not json' ) );
		$this->assertNull( BotRanges::parse_prefixes( '{"prefixes":[]}' ) );
		$this->assertNull( BotRanges::parse_prefixes( '["only","garbage","here"]' ) );
	}

	/* -- Range verdict: the staleness asymmetry ----------------------------- */

	private function row( array $prefixes, int $age ): array {
		return array(
			'prefixes'   => $prefixes,
			'fetched_at' => self::NOW - $age,
		);
	}

	public function test_a_range_match_verifies_even_from_a_stale_copy() {
		$row = $this->row( array( '192.0.2.0/24' ), 30 * DAY_IN_SECONDS );
		$this->assertSame( 1, BotRanges::verdict_from_row( $row, '192.0.2.7', self::NOW ), 'Ranges are near-append-only: an old positive is still sound.' );
	}

	public function test_a_non_match_condemns_only_while_the_copy_is_fresh() {
		$fresh = $this->row( array( '192.0.2.0/24' ), HOUR_IN_SECONDS );
		$stale = $this->row( array( '192.0.2.0/24' ), BotRanges::FRESH_TTL + HOUR_IN_SECONDS );
		$this->assertSame( 2, BotRanges::verdict_from_row( $fresh, '203.0.113.9', self::NOW ), 'Fresh list, address outside it → impostor.' );
		$this->assertSame( 0, BotRanges::verdict_from_row( $stale, '203.0.113.9', self::NOW ), 'A stale list may predate new addresses → unchecked, never a false accusation.' );
	}

	public function test_an_empty_row_or_missing_ip_is_always_unchecked() {
		$this->assertSame( 0, BotRanges::verdict_from_row( array(), '192.0.2.7', self::NOW ) );
		$this->assertSame( 0, BotRanges::verdict_from_row( $this->row( array( '192.0.2.0/24' ), 0 ), '', self::NOW ) );
	}

	public function test_ipv6_prefixes_match() {
		$row = $this->row( array( '2001:db8::/32' ), HOUR_IN_SECONDS );
		$this->assertSame( 1, BotRanges::verdict_from_row( $row, '2001:db8:1::42', self::NOW ) );
		$this->assertSame( 2, BotRanges::verdict_from_row( $row, '2001:dead::1', self::NOW ) );
	}

	/* -- The add-form's URL probe ------------------------------------------- */

	public function test_probe_accepts_a_real_range_file_and_reports_the_count() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"prefixes":[{"ipv4Prefix":"192.0.2.0/24"},{"ipv6Prefix":"2001:db8::/32"}]}',
			'headers'  => array(),
		);
		$this->assertSame( array( 'ok' => true, 'prefixes' => 2 ), BotRanges::probe( 'https://example.com/bot.json' ) );
	}

	public function test_probe_names_each_failure_honestly() {
		// A URL that could never verify anyone must be refused at the form, with the
		// reason distinguished: wrong scheme ≠ dead URL ≠ wrong content.
		$this->assertSame( 'not-https', BotRanges::probe( 'http://example.com/bot.json' )['reason'] );

		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '<!doctype html><html><body>a homepage, not a range file</body></html>',
			'headers'  => array(),
		);
		$this->assertSame( 'not-a-range-file', BotRanges::probe( 'https://heera.example' )['reason'] );

		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 404 ),
			'body'     => '',
			'headers'  => array(),
		);
		$this->assertSame( 'unreachable', BotRanges::probe( 'https://example.com/missing.json' )['reason'] );
	}

	/* -- Serve-path verdict reads the cache only ---------------------------- */

	public function test_serve_path_verdict_uses_the_cached_file_and_never_fetches() {
		update_option(
			BotRanges::OPTION,
			array(
				'gptbot' => array(
					'url'        => 'https://openai.com/gptbot.json',
					'fetched_at' => time() - HOUR_IN_SECONDS,
					'prefixes'   => array( '192.0.2.0/24' ),
				),
			)
		);
		$this->assertSame( 1, BotRanges::verdict( 'gptbot', '192.0.2.9' ) );
		$this->assertSame( 2, BotRanges::verdict( 'gptbot', '203.0.113.9' ) );
	}

	public function test_serve_path_verdict_is_unchecked_when_nothing_is_cached() {
		$this->assertSame( 0, BotRanges::verdict( 'gptbot', '192.0.2.9' ), 'Publisher never fetched (or down) → unchecked, nothing breaks.' );
	}

	public function test_a_cached_copy_for_a_superseded_url_is_not_trusted() {
		update_option(
			BotRanges::OPTION,
			array(
				'gptbot' => array(
					'url'        => 'https://old.example/file.json',
					'fetched_at' => time(),
					'prefixes'   => array( '192.0.2.0/24' ),
				),
			)
		);
		$this->assertSame( 0, BotRanges::verdict( 'gptbot', '192.0.2.9' ), 'The entry’s URL changed — the old file no longer describes it.' );
	}

	/* -- The claim cascade: the operator's own list outranks its PTR -------- */

	const DDG_UA    = 'DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)';
	const GOOGLE_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	/** Seed one operator's range cache the way the daily cron would. */
	private function seedRange( string $token, array $prefixes, int $age ): void {
		update_option(
			BotRanges::OPTION,
			array(
				$token => array(
					'url'        => VerifierRegistry::entry( $token )['url'],
					'fetched_at' => time() - $age,
					'prefixes'   => $prefixes,
				),
			)
		);
	}

	/** REGRESSION (heera.it, 2026-09-17): every DuckDuckBot request was booked as an
	 *  impostor, each from an address on duckduckbot.json. The registry demanded a
	 *  duckduckgo.com PTR that DuckDuckGo never promised, and its cloud addresses have none. */
	public function test_a_listed_duckduckbot_verifies_without_a_dns_lookup() {
		$lookups = 0;
		add_filter( 'agentimus_reverse_dns', static function () use ( &$lookups ) {
			$lookups++;
			return '';
		} );
		$this->seedRange( 'duckduckbot', array( '20.191.45.212/32' ), HOUR_IN_SECONDS );

		$this->assertSame( 1, BotVerifier::claim_verdict( self::DDG_UA, '20.191.45.212' ) );
		$this->assertSame( 2, BotVerifier::claim_verdict( self::DDG_UA, '203.0.113.9' ), 'Off the fresh list is still an impostor.' );
		$this->assertSame( 0, $lookups, 'DuckDuckBot is range-only: no reverse lookup is spent on it.' );
	}

	public function test_an_address_on_the_operators_own_list_outranks_a_missing_ptr() {
		add_filter( 'agentimus_reverse_dns', static function () { return ''; } ); // No PTR: rDNS says no.
		$this->seedRange( 'googlebot', array( '66.249.64.0/19' ), HOUR_IN_SECONDS );

		$this->assertSame( 1, BotVerifier::claim_verdict( self::GOOGLE_UA, '66.249.66.1' ), 'Google’s own list names the address — a missing PTR does not outvote it.' );
		$this->assertSame( 2, BotVerifier::claim_verdict( self::GOOGLE_UA, '203.0.113.9' ) );
		$this->assertSame( 1, BotVerifier::claim_verdict( self::GOOGLE_UA, '66.249.66.1', true ), 'The admin re-check reads the same cascade.' );
	}

	public function test_a_list_that_does_not_name_the_address_leaves_rdns_no_standing() {
		add_filter( 'agentimus_reverse_dns', static function () { return ''; } );
		$this->seedRange( 'googlebot', array( '66.249.64.0/19' ), BotRanges::FRESH_TTL + HOUR_IN_SECONDS );

		$this->assertSame( 2, BotVerifier::claim_verdict( self::GOOGLE_UA, '203.0.113.9' ), 'A stale list cannot condemn — but its silence does not lift rDNS’s no either.' );
	}

	/* -- Settings sanitisation of custom entries ---------------------------- */

	private function sanitize( array $custom ): array {
		$s = new Settings();
		$clean = $s->sanitize( array( 'verifier_custom' => $custom ) );
		return $clean['verifier_custom'];
	}

	public function test_sanitize_normalises_domains_and_keeps_a_valid_entry() {
		$out = $this->sanitize(
			array(
				array(
					'label'   => 'NewBot',
					'ua'      => 'NewBot',
					'domains' => array( 'crawl.newbot.example', '.already.dotted.example', 'https://pasted.example' ),
					'url'     => '',
				),
			)
		);
		$this->assertCount( 1, $out );
		$this->assertSame( 'c_newbot', $out[0]['token'] );
		$this->assertSame( 'newbot', $out[0]['ua'], 'Needle is lowercased.' );
		$this->assertSame( array( '.crawl.newbot.example', '.already.dotted.example', '.pasted.example' ), $out[0]['domains'] );
	}

	public function test_sanitize_drops_entries_without_a_real_needle_or_source() {
		$out = $this->sanitize(
			array(
				array( 'label' => 'Tiny', 'ua' => 'ab', 'domains' => array( 'x.example' ), 'url' => '' ), // Needle too short — would claim-match everything.
				array( 'label' => 'Empty', 'ua' => 'goodbot', 'domains' => array(), 'url' => '' ),        // No verification source at all.
			)
		);
		$this->assertSame( array(), $out );
	}

	public function test_sanitize_keeps_https_urls_only_and_never_shadows_a_builtin() {
		$out = $this->sanitize(
			array(
				array( 'label' => 'Plain', 'ua' => 'plainbot', 'domains' => array(), 'url' => 'http://insecure.example/ranges.json' ),
				array( 'token' => 'googlebot', 'label' => 'Fake Google', 'ua' => 'googlebot', 'domains' => array( '.evil.example' ), 'url' => '' ),
			)
		);
		// The http URL is rejected, leaving that entry sourceless → dropped. The
		// builtin-shadowing token is rejected outright.
		$this->assertSame( array(), $out );
	}

	public function test_sanitize_keeps_a_stable_custom_token_across_saves() {
		$out = $this->sanitize(
			array(
				array( 'token' => 'c_mybot', 'label' => 'Renamed Later', 'ua' => 'mybot', 'domains' => array( '.mybot.example' ), 'url' => '' ),
			)
		);
		$this->assertSame( 'c_mybot', $out[0]['token'], 'The token keys the range cache — it must survive a re-save.' );
	}
}
