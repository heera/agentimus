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
}
