<?php
/**
 * Repository::analyze_threats — the pure flagging/ranking behind the dashboard's
 * "Suspicious activity" section. No DB and no time() (now is injected), so the
 * heuristics (new / heavy / spoof), the one-click action choice and the ranking
 * are all exercised in isolation. The thin SQL wrapper (threats()) is not tested
 * here, matching the project's "decision is pure & tested, fetch is thin" split.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Repository;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ThreatsTest extends TestCase {

	/** Fixed "now" so first-seen windows are deterministic. */
	const NOW = 1700000000;

	const NOKIA     = 'Nokia6630/1.0 (2.3.129) SymbianOS/8.0 Series60/2.6 Profile/MIDP-2.0 Configuration/CLDC-1.1';
	const SEMRUSH   = 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)';
	const CHROME    = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
	const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
	const NEWBOT    = 'Mozilla/5.0 (compatible; NewBot/1.0; +http://example.test/bot)';

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** A GMT 'Y-m-d H:i:s' string $secs before the fixed NOW. */
	private function gmt( int $secs_ago ): string {
		return gmdate( 'Y-m-d H:i:s', self::NOW - $secs_ago );
	}

	/** Build one per-UA aggregate row as threats() would hand it over. */
	private function source( string $ua, string $agent, int $hits, int $first_ago, int $last_ago = 0 ): array {
		return array(
			'ua'         => $ua,
			'agent'      => $agent,
			'hits'       => $hits,
			'first_seen' => $this->gmt( $first_ago ),
			'last_seen'  => $this->gmt( $last_ago ),
		);
	}

	/** Run the analyzer at the fixed NOW; blocking off unless overridden. */
	private function analyze( array $sources, array $recent = array(), array $opts = array() ): array {
		return Repository::analyze_threats( $sources, $recent, self::NOW, $opts + array( 'blockingOn' => false ) );
	}

	/* -- Nothing suspicious ---------------------------------------------- */

	public function test_a_quiet_old_browser_is_not_flagged() {
		$r = $this->analyze( array( $this->source( self::CHROME, 'Browser', 5, 10 * DAY_IN_SECONDS, DAY_IN_SECONDS ) ) );
		$this->assertCount( 0, $r['sources'] );
		$this->assertSame( array( 'new' => 0, 'heavy' => 0, 'spoof' => 0 ), $r['counts'] );
	}

	/* -- New ------------------------------------------------------------- */

	public function test_a_newly_seen_crawler_is_flagged_new_and_blockable() {
		$r = $this->analyze( array( $this->source( self::NEWBOT, 'Other bot', 3, HOUR_IN_SECONDS, 600 ) ) );
		$this->assertCount( 1, $r['sources'] );
		$s = $r['sources'][0];
		$this->assertTrue( $s['flags']['new'] );
		$this->assertFalse( $s['flags']['heavy'] );
		$this->assertFalse( $s['flags']['spoof'] );
		$this->assertSame( 'agent', $s['action'] );
		$this->assertSame( 'newbot', $s['token'] );
		$this->assertFalse( $s['blocked'] );
		$this->assertSame( 1, $r['counts']['new'] );
	}

	/* -- Heavy: burst vs sustained --------------------------------------- */

	public function test_a_last_hour_burst_flags_heavy() {
		$ua = 'SomeScraper/2.0';
		$r  = $this->analyze(
			array( $this->source( $ua, 'Other bot', 45, 10 * DAY_IN_SECONDS, 0 ) ),
			array( $ua => 45 )
		);
		$s = $r['sources'][0];
		$this->assertTrue( $s['flags']['heavy'] );
		$this->assertFalse( $s['flags']['new'], 'First seen 10 days ago is not new.' );
		$this->assertSame( 45, $s['recent'] );
	}

	public function test_sustained_window_volume_flags_heavy_without_a_burst() {
		$r = $this->analyze( array( $this->source( 'BulkBot/1.0', 'Other bot', 800, 10 * DAY_IN_SECONDS, 0 ) ) );
		$this->assertTrue( $r['sources'][0]['flags']['heavy'] );
	}

	/* -- Spoof ----------------------------------------------------------- */

	public function test_a_spoofed_legacy_device_is_flagged_and_offers_the_class_action() {
		$r = $this->analyze( array( $this->source( self::NOKIA, 'Likely spoof/scanner', 4, HOUR_IN_SECONDS, 600 ) ) );
		$s = $r['sources'][0];
		$this->assertTrue( $s['flags']['spoof'] );
		$this->assertSame( 'spoofed', $s['action'], 'A spoof row arms the whole class, not its single UA.' );
		$this->assertFalse( $s['blocked'] );
		$this->assertSame( 1, $r['counts']['spoof'] );
	}

	public function test_a_spoof_source_reads_as_blocked_once_enforcement_is_on() {
		update_option( Settings::OPTION, array( 'block_agents' => true ) ); // block_spoofed defaults true.
		$r = $this->analyze(
			array( $this->source( self::NOKIA, 'Likely spoof/scanner', 4, HOUR_IN_SECONDS ) ),
			array(),
			array( 'blockingOn' => true )
		);
		$s = $r['sources'][0];
		$this->assertTrue( $s['blocked'] );
		$this->assertSame( '', $s['action'] );
		$this->assertTrue( $r['blockingOn'] );
	}

	/* -- Ranking & non-actionable cases ---------------------------------- */

	public function test_spoof_outranks_a_new_only_source() {
		$r = $this->analyze(
			array(
				$this->source( self::NEWBOT, 'Other bot', 3, HOUR_IN_SECONDS ),
				$this->source( self::NOKIA, 'Likely spoof/scanner', 2, HOUR_IN_SECONDS ),
			)
		);
		$this->assertTrue( $r['sources'][0]['flags']['spoof'], 'Most severe first.' );
	}

	public function test_a_new_but_protected_search_engine_is_flagged_yet_not_actionable() {
		$r = $this->analyze( array( $this->source( self::GOOGLEBOT, 'Googlebot', 2, HOUR_IN_SECONDS ) ) );
		$s = $r['sources'][0];
		$this->assertTrue( $s['flags']['new'] );
		$this->assertSame( '', $s['action'] );
		$this->assertSame( 'no-token', $s['reason'] );
	}

	public function test_a_heavy_no_user_agent_source_reports_the_no_ua_reason() {
		$r = $this->analyze( array( $this->source( '', 'No user-agent', 700, 10 * DAY_IN_SECONDS ) ) );
		$s = $r['sources'][0];
		$this->assertTrue( $s['flags']['heavy'] );
		$this->assertSame( '', $s['action'] );
		$this->assertSame( 'no-ua', $s['reason'] );
	}
}
