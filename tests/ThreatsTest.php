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
		$this->assertTrue( $r['sources'][0]['flags']['spoof'], 'Most severe first (both unblocked).' );
	}

	public function test_an_already_blocked_row_sinks_below_an_actionable_one() {
		// With enforcement on, the spoof is already denied (handled) while the new bot
		// still needs a decision — so the actionable row leads despite lower severity.
		update_option( Settings::OPTION, array( 'block_agents' => true ) ); // block_spoofed defaults true.
		$r = $this->analyze(
			array(
				$this->source( self::NOKIA, 'Likely spoof/scanner', 5, HOUR_IN_SECONDS ),
				$this->source( self::NEWBOT, 'Other bot', 3, HOUR_IN_SECONDS ),
			),
			array(),
			array( 'blockingOn' => true )
		);
		$this->assertFalse( $r['sources'][0]['blocked'], 'Actionable (unblocked) row leads.' );
		$this->assertTrue( $r['sources'][1]['blocked'], 'Already-blocked row sinks.' );
	}

	public function test_a_protected_search_engine_is_excluded_as_trusted() {
		// Googlebot is allow-listed — never blocked, never "suspicious" — so it must
		// not appear in the panel even when newly seen.
		$r = $this->analyze( array( $this->source( self::GOOGLEBOT, 'Googlebot', 2, HOUR_IN_SECONDS ) ) );
		$this->assertCount( 0, $r['sources'] );
		$this->assertSame( 0, $r['counts']['new'] );
	}

	public function test_a_forged_googlebot_is_still_surfaced_for_review() {
		// Appending "googlebot" to a UA used to make it vanish from the panel (the
		// monitoring blind spot). With structured engine matching, a forgery that
		// lacks the real product token is no longer treated as trusted, so a heavy
		// source is still surfaced.
		$forged = 'Mozilla/5.0 (compatible; EvilScraper/1.0; +http://evil.test) googlebot';
		$r      = $this->analyze( array( $this->source( $forged, 'Other bot', 800, 10 * DAY_IN_SECONDS, 0 ) ) );
		$this->assertCount( 1, $r['sources'], 'A forged "googlebot" must not hide from review.' );
		$this->assertTrue( $r['sources'][0]['flags']['heavy'] );
	}

	public function test_an_allowed_agent_is_excluded_from_the_panel() {
		// "Allow" adds the token to allowed_agents → it's treated as trusted/protected
		// and never appears in the review list again.
		update_option( Settings::OPTION, array( 'allowed_agents' => array( 'newbot' ) ) );
		$r = $this->analyze( array( $this->source( self::NEWBOT, 'Other bot', 3, HOUR_IN_SECONDS ) ) );
		$this->assertCount( 0, $r['sources'] );
	}

	/* -- Identity card (the recognition layer) --------------------------- */

	public function test_a_recognised_crawler_carries_its_identity_card() {
		// A known SEO crawler flagged for volume gets a plain-English card (kind +
		// operator + docs link), so the owner can judge it without knowing the token.
		$r = $this->analyze( array( $this->source( self::SEMRUSH, 'SemrushBot', 800, 10 * DAY_IN_SECONDS, 0 ) ) );
		$s = $r['sources'][0];
		$this->assertIsArray( $s['known'] );
		$this->assertSame( 'SemrushBot', $s['known']['name'] );
		$this->assertSame( 'Semrush', $s['known']['operator'] );
		$this->assertSame( 'seo', $s['known']['kind'] );
	}

	public function test_an_unrecognised_source_has_no_identity_card() {
		$r = $this->analyze( array( $this->source( self::NEWBOT, 'Other bot', 3, HOUR_IN_SECONDS, 600 ) ) );
		$this->assertNull( $r['sources'][0]['known'] );
	}

	public function test_a_new_only_browser_is_dropped_as_noise() {
		// A one-off newly-seen browser we can't safely block isn't worth surfacing in
		// a suspicious panel — only spoof/heavy/actionable rows make the cut.
		$r = $this->analyze( array( $this->source( self::CHROME, 'Browser', 2, HOUR_IN_SECONDS ) ) );
		$this->assertCount( 0, $r['sources'] );
	}

	public function test_a_heavy_no_user_agent_source_reports_the_no_ua_reason() {
		$r = $this->analyze( array( $this->source( '', 'No user-agent', 700, 10 * DAY_IN_SECONDS ) ) );
		$s = $r['sources'][0];
		$this->assertTrue( $s['flags']['heavy'] );
		$this->assertSame( '', $s['action'] );
		$this->assertSame( 'no-ua', $s['reason'] );
	}

	/* -- UA-variant merge (one block token = one decision) --------------- */

	const NEWBOT_V1 = 'Mozilla/5.0 (compatible; NewBot/1.0; +http://example.test/bot)';
	const NEWBOT_V2 = 'Mozilla/5.0 (compatible; NewBot/2.0; +http://example.test/bot)';

	public function test_ua_variants_sharing_a_token_merge_into_one_row() {
		// Two UA strings of the same client (a version bump) both resolve to the token
		// "newbot", so one Block denies both — they must read as a single decision.
		$r = $this->analyze(
			array(
				$this->source( self::NEWBOT_V1, 'Other bot', 4, HOUR_IN_SECONDS, 1200 ), // older variant
				$this->source( self::NEWBOT_V2, 'Other bot', 3, HOUR_IN_SECONDS, 300 ),  // most recent
			)
		);
		$this->assertCount( 1, $r['sources'], 'Variants of one client are one row.' );
		$s = $r['sources'][0];
		$this->assertSame( 'newbot', $s['token'] );
		$this->assertSame( 7, $s['hits'], 'Volume is summed across variants.' );
		$this->assertSame( 2, $s['variants'] );
		$this->assertSame( self::NEWBOT_V2, $s['ua'], 'The most-recent variant fronts the row.' );
		$this->assertContains( self::NEWBOT_V1, $s['variantUas'] );
		$this->assertSame( 1, $r['counts']['new'], 'A merged row counts once, not per variant.' );
	}

	public function test_distinct_clients_are_not_merged() {
		// Different block tokens are different decisions and stay separate rows.
		$r = $this->analyze(
			array(
				$this->source( self::NEWBOT, 'Other bot', 3, HOUR_IN_SECONDS, 300 ),
				$this->source( 'Mozilla/5.0 (compatible; OtherBot/1.0; +http://x.test/bot)', 'Other bot', 3, HOUR_IN_SECONDS, 300 ),
			)
		);
		$this->assertCount( 2, $r['sources'] );
		$this->assertSame( 1, $r['sources'][0]['variants'] );
	}

	public function test_a_single_row_reports_one_variant() {
		$r = $this->analyze( array( $this->source( self::NEWBOT, 'Other bot', 3, HOUR_IN_SECONDS, 600 ) ) );
		$this->assertSame( 1, $r['sources'][0]['variants'] );
		$this->assertSame( array( self::NEWBOT ), $r['sources'][0]['variantUas'] );
	}

	/* -- Live reverse-DNS verdict (verify the identity, keep no IP) -------- */

	/** A per-UA aggregate carrying the folded reverse-DNS verdict (0/1/2). */
	private function sourceV( string $ua, string $agent, int $hits, int $first_ago, int $verdict ): array {
		return $this->source( $ua, $agent, $hits, $first_ago ) + array( 'verdict' => $verdict );
	}

	public function test_a_forward_confirmed_engine_stays_excluded_as_trusted() {
		// Verdict 1 = reverse-DNS confirmed the real Googlebot. Still trusted, still no
		// decision to make — so it stays out of the review queue.
		$r = $this->analyze( array( $this->sourceV( self::GOOGLEBOT, 'Googlebot', 2, HOUR_IN_SECONDS, 1 ) ) );
		$this->assertCount( 0, $r['sources'] );
	}

	public function test_a_spoofed_engine_verdict_surfaces_as_an_impersonator() {
		// Verdict 2 = reverse-DNS caught it lying about being Googlebot. Its "protected"
		// status is forged, so it must surface — ranked top, with an honest, non-blockable
		// reason (you can't deny it by name without denying the real crawler).
		$r = $this->analyze( array( $this->sourceV( self::GOOGLEBOT, 'Googlebot', 40, HOUR_IN_SECONDS, 2 ) ) );
		$this->assertCount( 1, $r['sources'], 'A spoofed-engine verdict must not stay hidden as trusted.' );
		$s = $r['sources'][0];
		$this->assertSame( 'spoofed', $s['verdict'] );
		$this->assertSame( 'fake-engine', $s['reason'] );
		$this->assertSame( '', $s['action'], 'No safe one-click: blocking "Googlebot" would hit the real one.' );
		$this->assertFalse( $s['flags']['spoof'], 'A forged engine is distinct from the legacy-device spoof class.' );
		$this->assertFalse( $s['blocked'] );
	}

	public function test_a_low_volume_impersonator_still_surfaces() {
		// Even with no volume/novelty signal, impersonation alone is enough to surface it.
		$r = $this->analyze( array( $this->sourceV( self::GOOGLEBOT, 'Googlebot', 1, 10 * DAY_IN_SECONDS, 2 ) ) );
		$this->assertCount( 1, $r['sources'] );
		$this->assertSame( 'spoofed', $r['sources'][0]['verdict'] );
	}

	public function test_a_clean_row_reports_an_empty_verdict() {
		$r = $this->analyze( array( $this->source( self::NEWBOT, 'Other bot', 3, HOUR_IN_SECONDS ) ) );
		$this->assertSame( '', $r['sources'][0]['verdict'], 'No verification data → no verdict string.' );
	}

	/* -- Ignore / dismiss (a "not now" that respects and re-surfaces) ------ */

	/** Analyze with a dismissals map keyed as the endpoint would file it. */
	private function withDismissed( array $sources, array $dismissed, array $recent = array() ): array {
		return $this->analyze( $sources, $recent, array( 'dismissed' => $dismissed ) );
	}

	public function test_a_dismissed_client_is_suppressed() {
		$dismissed = array( 'tok:newbot' => array( 'at' => self::NOW, 'hits' => 3 ) );
		$r = $this->withDismissed( array( $this->source( self::NEWBOT, 'Other bot', 3, HOUR_IN_SECONDS ) ), $dismissed );
		$this->assertCount( 0, $r['sources'], 'A dismissed, unchanged client stays out of the queue.' );
	}

	public function test_a_dismissed_client_does_not_resurface_on_minor_growth() {
		// From 3 → 20 hits: neither doubled-and-plus-a-burst, so still "not now".
		$dismissed = array( 'tok:newbot' => array( 'at' => self::NOW, 'hits' => 3 ) );
		$r = $this->withDismissed( array( $this->source( self::NEWBOT, 'Other bot', 20, HOUR_IN_SECONDS ) ), $dismissed );
		$this->assertCount( 0, $r['sources'] );
	}

	public function test_a_dismissed_client_resurfaces_when_volume_grows_materially() {
		// From 3 → 300 hits: a different picture from the one waved off — bring it back.
		$dismissed = array( 'tok:newbot' => array( 'at' => self::NOW, 'hits' => 3 ) );
		$r = $this->withDismissed( array( $this->source( self::NEWBOT, 'Other bot', 300, HOUR_IN_SECONDS ) ), $dismissed );
		$this->assertCount( 1, $r['sources'], 'Materially busier than when dismissed → back for review.' );
	}

	public function test_a_dismissed_impersonator_is_acknowledged_and_suppressed() {
		// An owner can acknowledge (Ignore) a caught impersonator — the in-plugin remedy,
		// since the real fix is an IP rule at the host/CDN. Dismissed + unchanged → gone.
		$dismissed = array( 'ua:' . md5( strtolower( self::GOOGLEBOT ) ) => array( 'at' => self::NOW, 'hits' => 8 ) );
		$r = $this->withDismissed( array( $this->sourceV( self::GOOGLEBOT, 'Googlebot', 8, HOUR_IN_SECONDS, 2 ) ), $dismissed );
		$this->assertCount( 0, $r['sources'], 'A dismissed, unchanged impersonator is set aside.' );
	}

	public function test_a_dismissed_impersonator_resurfaces_if_it_keeps_hammering() {
		// But a persistent one that materially grows comes back on its own.
		$dismissed = array( 'ua:' . md5( strtolower( self::GOOGLEBOT ) ) => array( 'at' => self::NOW, 'hits' => 8 ) );
		$r = $this->withDismissed( array( $this->sourceV( self::GOOGLEBOT, 'Googlebot', 500, HOUR_IN_SECONDS, 2 ) ), $dismissed );
		$this->assertCount( 1, $r['sources'] );
		$this->assertSame( 'spoofed', $r['sources'][0]['verdict'] );
	}
}
