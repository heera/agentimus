<?php
/**
 * Guard — the opt-in hard-block decision (Guard::denies), plus the Settings
 * defaults/sanitisation backing it. The response side (maybe_block: 403 + exit)
 * is a thin wrapper and is exercised live, not here.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Guard;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class GuardTest extends TestCase {

	const NOKIA     = 'Nokia6630/1.0 (2.3.129) SymbianOS/8.0 Series60/2.6 Profile/MIDP-2.0 Configuration/CLDC-1.1';
	const CHROME    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
	const SEMRUSH   = 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)';
	const AHREFS    = 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)';
	const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Persist a partial settings array (merged over defaults by Settings::all()). */
	private function configure( array $settings ): void {
		update_option( Settings::OPTION, $settings );
	}

	/* -- The master switch is off by default ----------------------------- */

	public function test_blocking_off_by_default_serves_everyone() {
		$this->assertFalse( Guard::denies( self::NOKIA ), 'A fresh install must never block.' );
		$this->assertFalse( Guard::denies( self::SEMRUSH ) );
	}

	public function test_denylist_is_inert_while_the_master_switch_is_off() {
		$this->configure( array( 'block_agents' => false, 'blocked_agents' => array( 'SemrushBot' ) ) );
		$this->assertFalse( Guard::denies( self::SEMRUSH ) );
	}

	/* -- Spoof heuristic -------------------------------------------------- */

	public function test_spoofed_legacy_device_is_denied_when_blocking_on() {
		$this->configure( array( 'block_agents' => true ) ); // block_spoofed defaults true
		$this->assertTrue( Guard::denies( self::NOKIA ) );
	}

	public function test_spoof_heuristic_can_be_turned_off_independently() {
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false ) );
		$this->assertFalse( Guard::denies( self::NOKIA ), 'With the heuristic off, only the explicit denylist applies.' );
	}

	public function test_real_browser_and_known_agent_are_never_auto_denied() {
		$this->configure( array( 'block_agents' => true ) );
		$this->assertFalse( Guard::denies( self::CHROME ) );
		$this->assertFalse( Guard::denies( 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)' ) );
	}

	/* -- Custom denylist (case-insensitive substring) -------------------- */

	public function test_denylist_substring_matches_case_insensitively() {
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( 'semrushbot' ) ) );
		$this->assertTrue( Guard::denies( self::SEMRUSH ) );
		$this->assertFalse( Guard::denies( self::CHROME ) );
	}

	/* -- Bot verification (opt-in, forward-confirmed reverse DNS) --------- */

	/** With verification OFF (the default), a UA that claims Googlebot is protected on
	 *  the UA string alone — the historical behaviour, unchanged. */
	public function test_verification_is_off_by_default_and_the_ua_bypass_stands() {
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( 'scanner' ) ) );
		$this->assertFalse( Guard::denies( 'scanner masquerading as Googlebot/2.1', true ) );
	}

	/** With verification ON, a forged Googlebot whose IP does NOT forward-confirm loses
	 *  its always-allow status and is caught by the denylist — the spoof-bypass fix. */
	public function test_verification_denies_a_spoofed_engine_that_fails_reverse_dns() {
		add_filter( 'agentimus_verify_bots', static function () { return true; } );
		add_filter( 'agentimus_client_ip', static function () { return '203.0.113.9'; } );
		add_filter( 'agentimus_reverse_dns', static function () { return 'host.scanner-farm.example'; }, 10, 2 );
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( 'scanner' ) ) );

		$this->assertTrue( Guard::denies( 'scanner masquerading as Googlebot/2.1', true ) );
	}

	/** With verification ON, a genuine Googlebot (forward-confirmed) keeps its
	 *  protection — even against a rule that names it — so we never de-index a real
	 *  crawler. */
	public function test_verification_still_protects_a_forward_confirmed_engine() {
		add_filter( 'agentimus_verify_bots', static function () { return true; } );
		add_filter( 'agentimus_client_ip', static function () { return '66.249.66.1'; } );
		add_filter( 'agentimus_reverse_dns', static function () { return 'crawl-66-249-66-1.googlebot.com'; }, 10, 2 );
		add_filter( 'agentimus_forward_dns', static function () { return array( '66.249.66.1' ); }, 10, 2 );
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( 'googlebot' ) ) );

		$this->assertFalse( Guard::denies( self::GOOGLEBOT, true ) );
	}

	/** The admin toggle (the persisted verify_bots setting) drives verification too —
	 *  not only the code filter. */
	public function test_verification_is_driven_by_the_setting_not_only_the_filter() {
		add_filter( 'agentimus_client_ip', static function () { return '203.0.113.9'; } );
		add_filter( 'agentimus_reverse_dns', static function () { return 'host.scanner-farm.example'; }, 10, 2 );
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'verify_bots' => true, 'blocked_agents' => array( 'scanner' ) ) );

		$this->assertTrue( Guard::denies( 'scanner masquerading as Googlebot/2.1', true ) );
	}

	/** FAIL-OPEN: when the resolver is slow (a DNS hiccup, not a spoof), a genuine
	 *  Googlebot keeps its protection and is served — even against a rule that names it.
	 *  A DNS latency problem must never lose a real crawler. */
	public function test_verification_fails_open_when_dns_is_slow_and_keeps_the_crawler() {
		add_filter( 'agentimus_verify_bots', static function () { return true; } );
		add_filter( 'agentimus_client_ip', static function () { return '66.249.66.1'; } );
		add_filter( 'agentimus_verify_slow_ms', static function () { return 5; } );
		add_filter( 'agentimus_reverse_dns', static function () { usleep( 20000 ); return 'crawl-66-249-66-1.googlebot.com'; }, 10, 2 );
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( 'googlebot' ) ) );

		$this->assertFalse( Guard::denies( self::GOOGLEBOT, true ), 'A slow DNS lookup must not de-index a real crawler.' );
	}

	/* -- Never block a missing UA (too blunt, trivially spoofed) ---------- */

	public function test_empty_ua_is_not_blocked_even_with_blocking_on() {
		$this->configure( array( 'block_agents' => true ) );
		$this->assertFalse( Guard::denies( '' ) );
	}

	/* -- Settings defaults & sanitisation -------------------------------- */

	public function test_defaults_ship_blocking_off_with_spoof_heuristic_armed() {
		$d = ( new Settings() )->defaults();
		$this->assertFalse( $d['block_agents'] );
		$this->assertTrue( $d['block_spoofed'] );
		$this->assertSame( array(), $d['blocked_agents'] );
	}

	public function test_sanitize_round_trips_blocking_fields_and_drops_blanks() {
		$clean = ( new Settings() )->sanitize(
			array(
				'block_agents'   => true,
				'block_spoofed'  => false,
				'blocked_agents' => array( 'AhrefsBot', '   ', 'DotBot' ),
			)
		);
		$this->assertTrue( $clean['block_agents'] );
		$this->assertFalse( $clean['block_spoofed'] );
		$this->assertSame( array( 'AhrefsBot', 'DotBot' ), $clean['blocked_agents'] );
	}

	public function test_sanitize_refuses_denylist_entries_under_three_chars() {
		// A 1–2 char entry matched as a substring would deny half the internet at the
		// AI endpoints — same 3+ floor as the Verified-bots UA needle.
		$clean = ( new Settings() )->sanitize(
			array( 'blocked_agents' => array( 'a', 'ab', 'abc', 'SemrushBot' ) )
		);
		$this->assertSame( array( 'abc', 'SemrushBot' ), $clean['blocked_agents'] );
	}

	public function test_suggest_token_never_proposes_a_sub_three_char_token() {
		// The sanitiser would silently drop it, so proposing one would make the queue's
		// one-click Block a no-op. The UA's product token here is 'go' (2 chars).
		$this->assertSame( '', Guard::suggest_token( 'go/1.13 (linux)' ) );
	}

	public function test_sanitize_keeps_spoof_default_true_when_key_is_absent() {
		// A partial update that omits block_spoofed must not silently disarm it.
		$clean = ( new Settings() )->sanitize( array( 'block_agents' => true ) );
		$this->assertTrue( $clean['block_spoofed'] );
	}

	/* -- Glob / regex denylist entries ----------------------------------- */

	public function test_glob_wildcard_entry_matches() {
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( 'Ahrefs*' ) ) );
		$this->assertTrue( Guard::denies( self::AHREFS ) );
		$this->assertFalse( Guard::denies( self::CHROME ) );
	}

	public function test_regex_entry_matches() {
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( '/semrushbot\/\d+/' ) ) );
		$this->assertTrue( Guard::denies( self::SEMRUSH ) );
		$this->assertFalse( Guard::denies( self::CHROME ) );
	}

	public function test_invalid_regex_degrades_to_literal_and_never_errors() {
		// An unparseable pattern must not throw on a public request, nor match a
		// normal browser by accident.
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( '/(unclosed/' ) ) );
		$this->assertFalse( Guard::denies( self::CHROME ) );
	}

	/* -- Accident-guards ------------------------------------------------- */

	public function test_protected_search_engine_is_never_blocked_by_a_broad_rule() {
		// "bot" is broad enough to hit Googlebot — but the allow-list saves it,
		// while a non-protected crawler the same rule matches is still denied.
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( 'bot' ) ) );
		$this->assertFalse( Guard::denies( self::GOOGLEBOT ), 'Googlebot must never be blocked by an over-broad rule.' );
		$this->assertTrue( Guard::denies( self::AHREFS ) );
	}

	public function test_appending_a_protected_token_does_not_evade_a_specific_rule() {
		// The bypass: a scanner appends "googlebot" to its UA to ride the protected
		// allow-list. Structured engine matching means the bare word earns no trust,
		// so the owner's specific rule still blocks it.
		$forged = 'Mozilla/5.0 (compatible; EvilScraper/1.0; +http://evil.test) googlebot';
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( 'evilscraper' ) ) );
		$this->assertTrue( Guard::denies( $forged ), 'A forged "googlebot" suffix must not buy immunity.' );
		// …while the real Googlebot and its image variant stay protected.
		$this->assertFalse( Guard::denies( self::GOOGLEBOT ) );
		$this->assertFalse( Guard::denies( 'Googlebot-Image/1.0 (+http://www.google.com/bot.html)' ) );
	}

	public function test_is_real_engine_accepts_genuine_crawlers_and_rejects_forgeries() {
		$this->assertTrue( Guard::is_real_engine( strtolower( self::GOOGLEBOT ) ) );
		$this->assertTrue( Guard::is_real_engine( 'mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ) );
		$this->assertTrue( Guard::is_real_engine( 'mozilla/5.0 (compatible; yandexbot/3.0; +http://yandex.com/bots)' ) );
		// Bare/appended tokens with no product/version shape are not the real engine.
		$this->assertFalse( Guard::is_real_engine( 'evilscraper/1.0 (+googlebot)' ) );
		$this->assertFalse( Guard::is_real_engine( 'sneaky bingbot scanner' ) );
		$this->assertFalse( Guard::is_real_engine( 'something googlebot' ) );
	}

	public function test_all_wildcard_entry_is_a_noop_not_a_block_everyone() {
		$this->configure( array( 'block_agents' => true, 'block_spoofed' => false, 'blocked_agents' => array( '*' ) ) );
		$this->assertFalse( Guard::denies( self::CHROME ) );
		$this->assertFalse( Guard::denies( self::AHREFS ) );
	}

	public function test_protected_agents_defaults_include_major_search_engines() {
		$this->assertContains( 'googlebot', Guard::protected_agents() );
		$this->assertContains( 'bingbot', Guard::protected_agents() );
	}

	/* -- Block-token suggestion (the one-click "Block this") -------------- */

	public function test_suggest_token_extracts_the_crawler_product_name() {
		$this->assertSame( 'semrushbot', Guard::suggest_token( self::SEMRUSH ) );
		$this->assertSame( 'ahrefsbot', Guard::suggest_token( self::AHREFS ) );
		$this->assertSame( 'ccbot', Guard::suggest_token( 'CCBot/2.0 (https://commoncrawl.org/faq/)' ) );
	}

	public function test_suggest_token_is_empty_for_generic_http_tools() {
		// curl/wget/python-requests are broad tool names — and fetching the AI files is
		// what the plugin invites — so they are not safe one-click blocks.
		$this->assertSame( '', Guard::suggest_token( 'curl/8.7.1' ) );
		$this->assertSame( '', Guard::suggest_token( 'python-requests/2.31.0' ) );
		$this->assertSame( '', Guard::suggest_token( 'Wget/1.21.3' ) );
	}

	public function test_suggest_token_is_empty_for_a_generic_browser() {
		// A real browser's only tokens are mozilla/applewebkit/chrome/safari — all
		// generic, so there is no safe rule to propose.
		$this->assertSame( '', Guard::suggest_token( self::CHROME ) );
	}

	public function test_suggest_token_is_empty_for_protected_and_missing() {
		$this->assertSame( '', Guard::suggest_token( self::GOOGLEBOT ), 'A protected search engine is never proposed for blocking.' );
		$this->assertSame( '', Guard::suggest_token( '' ) );
	}

	/* -- One-click block persistence (Settings::block_agent / spoofed) ---- */

	public function test_block_agent_appends_the_token_and_arms_enforcement() {
		$saved = ( new Settings() )->block_agent( 'SemrushBot' );
		$this->assertTrue( $saved['block_agents'], 'Blocking must be turned on, or the denylist is inert.' );
		$this->assertContains( 'SemrushBot', $saved['blocked_agents'] );
		$this->assertTrue( Guard::denies( self::SEMRUSH ), 'The client is denied immediately after blocking it.' );
	}

	public function test_block_agent_does_not_duplicate_case_insensitively() {
		$settings = new Settings();
		$settings->block_agent( 'AhrefsBot' );
		$saved = $settings->block_agent( 'ahrefsbot' );
		$this->assertSame( array( 'AhrefsBot' ), $saved['blocked_agents'] );
	}

	public function test_block_spoofed_class_arms_master_and_heuristic() {
		$saved = ( new Settings() )->block_spoofed_class();
		$this->assertTrue( $saved['block_agents'] );
		$this->assertTrue( $saved['block_spoofed'] );
		$this->assertTrue( Guard::denies( self::NOKIA ) );
	}

	/* -- Range-verdict enforcement (proven impostors) --------------------- */

	const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';

	/** Seed the range cache the way the daily cron would. */
	private function seedRanges( string $token, array $prefixes, int $age = 0 ): void {
		$entry = \Agentimus\VerifierRegistry::entry( $token );
		update_option(
			\Agentimus\BotRanges::OPTION,
			array(
				$token => array(
					'url'        => $entry ? $entry['url'] : '',
					'fetched_at' => time() - $age,
					'prefixes'   => $prefixes,
				),
			)
		);
	}

	public function test_a_proven_fake_gptbot_is_denied_when_blocking_and_verification_are_on() {
		add_filter( 'agentimus_client_ip', static function () { return '203.0.113.9'; } );
		$this->seedRanges( 'gptbot', array( '192.0.2.0/24' ) ); // Fresh, and 203.0.113.9 is outside.
		$this->configure( array( 'block_agents' => true, 'verify_bots' => true ) ); // block_spoofed defaults true.

		$this->assertTrue( Guard::denies( self::GPTBOT, true ), 'Outside the operator’s fresh published ranges = a proven impostor.' );
	}

	public function test_a_gptbot_inside_the_published_ranges_is_served() {
		add_filter( 'agentimus_client_ip', static function () { return '192.0.2.9'; } );
		$this->seedRanges( 'gptbot', array( '192.0.2.0/24' ) );
		$this->configure( array( 'block_agents' => true, 'verify_bots' => true ) );

		$this->assertFalse( Guard::denies( self::GPTBOT, true ) );
	}

	public function test_a_stale_range_file_fails_open_and_never_denies() {
		add_filter( 'agentimus_client_ip', static function () { return '203.0.113.9'; } );
		$this->seedRanges( 'gptbot', array( '192.0.2.0/24' ), \Agentimus\BotRanges::FRESH_TTL + HOUR_IN_SECONDS );
		$this->configure( array( 'block_agents' => true, 'verify_bots' => true ) );

		$this->assertFalse( Guard::denies( self::GPTBOT, true ), 'A stale list may predate new addresses — publisher down must never block a real bot.' );
	}

	public function test_an_unfetched_range_file_fails_open_too() {
		add_filter( 'agentimus_client_ip', static function () { return '203.0.113.9'; } );
		$this->configure( array( 'block_agents' => true, 'verify_bots' => true ) ); // No cache seeded at all.

		$this->assertFalse( Guard::denies( self::GPTBOT, true ) );
	}

	public function test_impostor_enforcement_needs_both_switches() {
		add_filter( 'agentimus_client_ip', static function () { return '203.0.113.9'; } );
		$this->seedRanges( 'gptbot', array( '192.0.2.0/24' ) );

		$this->configure( array( 'block_agents' => true, 'verify_bots' => true, 'block_spoofed' => false ) );
		$this->assertFalse( Guard::denies( self::GPTBOT, true ), 'The impostor class rides the block_spoofed switch.' );

		$this->configure( array( 'block_agents' => true, 'verify_bots' => false ) );
		$this->assertFalse( Guard::denies( self::GPTBOT, true ), 'No verification, no verdict, no denial.' );

		$this->configure( array( 'block_agents' => false, 'verify_bots' => true ) );
		$this->assertFalse( Guard::denies( self::GPTBOT, true ), 'Blocking off serves everyone, impostors included.' );
	}

	public function test_owner_allow_outranks_a_proven_forgery() {
		add_filter( 'agentimus_client_ip', static function () { return '203.0.113.9'; } );
		$this->seedRanges( 'gptbot', array( '192.0.2.0/24' ) );
		$this->configure( array( 'block_agents' => true, 'verify_bots' => true, 'allowed_agents' => array( 'gptbot' ) ) );

		$this->assertFalse( Guard::denies( self::GPTBOT, true ), 'The owner’s explicit allow is a deliberate choice — it protects unconditionally.' );
	}

	public function test_a_verified_bot_the_owner_blocks_stays_blocked() {
		// Verification must never overrule an explicit owner rule: opting out of AI
		// training (denylisting gptbot) applies to the GENUINE crawler too.
		add_filter( 'agentimus_client_ip', static function () { return '192.0.2.9'; } );
		$this->seedRanges( 'gptbot', array( '192.0.2.0/24' ) ); // Genuinely inside the ranges…
		$this->configure( array( 'block_agents' => true, 'verify_bots' => true, 'blocked_agents' => array( 'gptbot' ) ) );

		$this->assertTrue( Guard::denies( self::GPTBOT, true ), '…but the owner said no, and the owner wins.' );
	}

	public function test_an_engine_is_denied_via_fresh_ranges_when_rdns_is_unavailable() {
		// The resolver answers nothing usable (inconclusive rDNS), but Google's FRESH
		// published range file excludes the address — equally conclusive, same denial.
		add_filter( 'agentimus_verify_bots', static function () { return true; } );
		add_filter( 'agentimus_client_ip', static function () { return '203.0.113.9'; } );
		add_filter( 'agentimus_reverse_dns', static function () { return ''; }, 10, 2 ); // No PTR.
		$this->seedRanges( 'googlebot', array( '66.249.64.0/19' ) );
		$this->configure( array( 'block_agents' => true ) );

		$this->assertTrue( Guard::denies( self::GOOGLEBOT, true ), 'A fresh range exclusion condemns even when DNS cannot.' );
	}

	/** REGRESSION (heera.it, 2026-07-18): identity checks must NOT run for an explicit
	 *  UA — that's a display call (the review panel's "already blocked" badge), where
	 *  the current IP is the ADMIN's own browser. Judged against that IP, every bot
	 *  claim "conclusively fails", every impostor row read as blocked, and the queue
	 *  hid a genuinely flagged client as already-handled. */
	public function test_display_calls_never_run_identity_checks() {
		add_filter( 'agentimus_client_ip', static function () { return '203.0.113.50'; } ); // The admin's browser.
		$this->seedRanges( 'gptbot', array( '192.0.2.0/24' ) ); // Fresh, admin IP outside.
		$this->configure( array( 'block_agents' => true, 'verify_bots' => true ) );

		$this->assertFalse( Guard::denies( self::GPTBOT ), 'An explicit-UA (display) call judges standing rules only.' );
		$this->assertTrue( Guard::denies( self::GPTBOT, true ), 'The same UA on the live request path is still refused.' );
	}

	/* -- Trust-list (the "Allow" action) --------------------------------- */

	public function test_allow_agent_makes_a_client_never_blocked() {
		$settings = new Settings();
		$settings->block_agent( 'AhrefsBot' );  // on the denylist + enforcement on…
		$settings->allow_agent( 'AhrefsBot' );  // …but now trusted.
		$this->assertContains( 'AhrefsBot', ( new Settings() )->get( 'allowed_agents', array() ) );
		$this->assertFalse( Guard::denies( self::AHREFS ), 'A trusted agent is never blocked, even when on the denylist.' );
	}
}
