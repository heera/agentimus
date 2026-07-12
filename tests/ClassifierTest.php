<?php
/**
 * Classifier — the User-Agent → friendly-label waterfall, with focus on the
 * "Likely spoof/scanner" tier and the shared is_spoof() heuristic that the
 * request Guard reuses (so the log label and the block decision can't drift).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Classifier;
use PHPUnit\Framework\TestCase;

final class ClassifierTest extends TestCase {

	/** The real-world hit that started this: a 2004 Symbian phone string. */
	const NOKIA = 'Nokia6630/1.0 (2.3.129) SymbianOS/8.0 Series60/2.6 Profile/MIDP-2.0 Configuration/CLDC-1.1';
	const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
	const GPTBOT = 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)';

	/* -- classify() label tiers ------------------------------------------ */

	public function test_known_agent_still_wins_over_everything() {
		$this->assertSame( 'GPTBot (OpenAI)', Classifier::classify( self::GPTBOT ) );
	}

	public function test_legacy_device_string_is_labelled_spoof_scanner() {
		$this->assertSame( 'Likely spoof/scanner', Classifier::classify( self::NOKIA ) );
	}

	public function test_real_browser_is_a_browser_not_a_spoof() {
		$this->assertSame( 'Browser', Classifier::classify( self::CHROME ) );
	}

	public function test_empty_ua_is_named_not_spoofed() {
		$this->assertSame( 'No user-agent', Classifier::classify( '' ) );
	}

	public function test_self_declaring_client_is_named_from_its_own_ua() {
		// A client that isn't cataloged but declares its own product token is named
		// by that token — matching the review queue (Catalog::self_declared) instead
		// of a generic bucket. Regression: "TheWebReport" (allow-listed, cleanly named
		// in the review queue) used to read "Unidentified" in the activity feed.
		$this->assertSame( 'TheWebReport', Classifier::classify( 'TheWebReport/1.0; +https://theweb.report' ) );
		$this->assertSame( 'SomeRandomFetcher', Classifier::classify( 'SomeRandomFetcher/2.0' ) );
	}

	public function test_client_with_no_parseable_name_is_unrecognized() {
		// Non-empty UA, but nothing to name it by: no known token, no product token,
		// no browser/bot/script markers. Renamed from "Unidentified".
		$this->assertSame( 'Unrecognized', Classifier::classify( 'opaque-client-no-version' ) );
	}

	public function test_self_declared_unknown_bot_is_named_from_its_token() {
		// Regression: a UA whose token trips the bot-word bucket ("ethicrawl" contains
		// "crawl") used to read "Other bot" in the feed while the review queue titled
		// it by its own product token — the bucket now prefers that token too.
		$this->assertSame( 'WhateverBot', Classifier::classify( 'WhateverBot/1.0 (+http://example.com)' ) );
		$this->assertSame( 'ethicrawl', Classifier::classify( 'ethicrawl/0.1 (Ethical crawler respecting robots and AI directives; http://ethicrawl.ai/crawler; contact@ethicrawl.ai)' ) );
	}

	public function test_bot_ua_with_no_product_token_stays_other_bot() {
		$this->assertSame( 'Other bot', Classifier::classify( 'some unnamed crawler' ) );
	}

	public function test_a_catalogued_crawler_is_named_from_the_catalog() {
		// ShapBot has no hand-written label, but the recognition catalog names it, so
		// the feed and the review queue agree — rather than a vague "Other bot".
		$this->assertSame( 'ShapBot', Classifier::classify( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ShapBot/0.1.0' ) );
	}

	/* -- is_spoof() heuristic -------------------------------------------- */

	/**
	 * @dataProvider spoof_uas
	 */
	public function test_is_spoof_flags_legacy_device_strings( $ua ) {
		$this->assertTrue( Classifier::is_spoof( $ua ), $ua );
	}

	public function spoof_uas() {
		return array(
			'symbian nokia'   => array( self::NOKIA ),
			'j2me midp'       => array( 'SonyEricssonK750i/R1J Browser/SEMC-Browser/4.2 Profile/MIDP-2.0 Configuration/CLDC-1.1' ),
			'windows ce'      => array( 'Mozilla/4.0 (compatible; MSIE 6.0; Windows CE; IEMobile 7.11)' ),
			'blackberry'      => array( 'BlackBerry9000/4.6.0.167 Profile/MIDP-2.0 Configuration/CLDC-1.1' ),
			'openwave'        => array( 'UP.Browser/6.2.3.8 (GUI) MMP/2.0' ),
		);
	}

	/**
	 * @dataProvider real_uas
	 */
	public function test_is_spoof_leaves_real_agents_alone( $ua ) {
		$this->assertFalse( Classifier::is_spoof( $ua ), $ua );
	}

	public function real_uas() {
		return array(
			'chrome'     => array( self::CHROME ),
			'gptbot'     => array( self::GPTBOT ),
			'empty'      => array( '' ),
			'iphone'     => array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' ),
			'android'    => array( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36' ),
			'lg_webos_tv' => array( 'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0 Safari/537.36 WebAppManager' ),
			'curl'       => array( 'curl/8.4.0' ),
			// Regression: modern devices whose model/carrier name echoes a dead brand
			// must NOT be flagged (these were false positives — and 403'd with blocking on).
			'nokia_5.4_android'  => array( 'Mozilla/5.0 (Linux; Android 11; Nokia 5.4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.45 Mobile Safari/537.36' ),
			'docomo_android'     => array( 'Mozilla/5.0 (Linux; Android 10; SO-01M) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Mobile Safari/537.36 DoCoMo' ),
			'nokia_8110_kaios'   => array( 'Mozilla/5.0 (Mobile; Nokia 8110 4G; rv:48.0) Gecko/48.0 Firefox/48.0 KAIOS/2.5' ),
		);
	}

	/* -- is_recognised_agent() — the logger's spoof-aware fast-pass gate -- */

	/** A genuinely-recognised crawler is recognised → it earns the log fast-pass. */
	public function test_recognised_named_agent_is_recognised() {
		$this->assertTrue( Classifier::is_recognised_agent( self::GPTBOT ) );
		$this->assertTrue( Classifier::is_recognised_agent( 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +https://anthropic.com)' ) );
		// Catalogued-but-unlabelled crawlers count too (named from the catalog).
		$this->assertTrue( Classifier::is_recognised_agent( 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ShapBot/0.1.0' ) );
	}

	/**
	 * Generic/unknown clients are NOT recognised → they are throttle-eligible.
	 *
	 * @dataProvider unrecognised_uas
	 */
	public function test_generic_clients_are_not_recognised( $ua ) {
		$this->assertFalse( Classifier::is_recognised_agent( $ua ), $ua );
	}

	public function unrecognised_uas() {
		return array(
			'browser'        => array( self::CHROME ),
			'no_ua'          => array( '' ),
			'script_curl'    => array( 'curl/8.4.0' ),
			'other_bot'      => array( 'WhateverBot/1.0 (+http://example.com)' ),
			'self_declared'  => array( 'SomeRandomFetcher/2.0' ),
			// A self-declaring, allow-listed-but-uncataloged agent is NAMED in the feed
			// yet still throttle-eligible — it must not earn the flood fast-pass.
			'thewebreport'   => array( 'TheWebReport/1.0; +https://theweb.report' ),
			'flood_evilbot'  => array( 'EvilBot-7/4213 (flood test)' ),
			'legacy_spoof'   => array( self::NOKIA ),
		);
	}

	/**
	 * The security-critical case: a UA that pastes a known bot's NAME but also
	 * trips the legacy-device spoof test must NOT be recognised — otherwise a
	 * scanner could spoof its way to the fast-pass. is_spoof() vetoes the name.
	 */
	public function test_spoofed_known_bot_name_does_not_earn_the_fast_pass() {
		$spoofed_gptbot = 'GPTBot/1.1 SymbianOS/8.0 Series60/2.6 Profile/MIDP-2.0';
		// classify() still NAMES it (the map matches before the spoof tier)…
		$this->assertSame( 'GPTBot (OpenAI)', Classifier::classify( $spoofed_gptbot ) );
		// …but recognition is spoof-aware, so it is denied the fast-pass.
		$this->assertTrue( Classifier::is_spoof( $spoofed_gptbot ) );
		$this->assertFalse( Classifier::is_recognised_agent( $spoofed_gptbot ) );
	}
}
