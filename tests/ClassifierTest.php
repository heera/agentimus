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

	public function test_unknown_non_mozilla_string_falls_through_to_unidentified() {
		$this->assertSame( 'Unidentified', Classifier::classify( 'SomeRandomFetcher/2.0' ) );
	}

	public function test_self_declared_unknown_bot_is_other_bot() {
		$this->assertSame( 'Other bot', Classifier::classify( 'WhateverBot/1.0 (+http://example.com)' ) );
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
		);
	}
}
