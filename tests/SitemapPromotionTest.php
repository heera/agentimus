<?php
/**
 * Solo-mode sitemap promotion — {@see Sitemap::promoted()} winning detect()
 * outright, the `wp_sitemaps_enabled` stand-down of core's duplicate, and the
 * once-per-flip cache sync ({@see SeoContext::sync_cached_mode()}) that keeps
 * the cached advertising (robots.txt / llms.txt) truthful across mode changes.
 *
 * The bootstrap's wp_sitemaps_get_server stub mirrors the real coupling: its
 * verdict runs through the wp_sitemaps_enabled filter, the exact seam
 * {@see Sitemap::register()} hooks.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Cache;
use Agentimus\SeoContext;
use Agentimus\Settings;
use Agentimus\Sitemap;
use PHPUnit\Framework\TestCase;

final class SitemapPromotionTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_transients_on'] = true; // The cache-sync test needs live transients.
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* ---- promotion vs the cascade ------------------------------------------ */

	/** Solo + the sitemap feature on (both defaults): promotion is on. */
	public function test_promoted_by_default_in_solo_mode() {
		$this->assertTrue( Sitemap::promoted() );
	}

	/** Promotion beats core: even with core sitemaps on, ours is THE sitemap —
	 * advertised at core's own canonical address, never a moved one. */
	public function test_promotion_wins_over_core() {
		$GLOBALS['_af_core_sitemaps'] = true;
		$detected                     = Sitemap::detect();
		$this->assertSame( 'agentimus', $detected['source'] );
		$this->assertSame( 'https://example.test' . Sitemap::INDEX_PATH, $detected['url'] );
	}

	/* ---- routing: the standard path serves, every other door redirects ------ */

	/** Promoted: the index answers at /wp-sitemap.xml itself. */
	public function test_promoted_index_serves_at_the_standard_path() {
		$this->assertSame( 'index', Sitemap::route_for( Sitemap::INDEX_PATH ) );
	}

	/** Promoted: the legacy path and the de-facto convention both 301 home —
	 * a registration of either keeps working instead of dying as a 404. */
	public function test_promoted_legacy_doors_redirect() {
		$this->assertSame( 'redirect', Sitemap::route_for( Sitemap::PATH ) );
		$this->assertSame( 'redirect', Sitemap::route_for( '/sitemap.xml' ) );
	}

	/** Promoted: sub-sitemaps and unrelated paths are not the router's business. */
	public function test_promoted_router_ignores_children_and_strangers() {
		$this->assertSame( '', Sitemap::route_for( '/agentimus-sitemap-post-1.xml' ) );
		$this->assertSame( '', Sitemap::route_for( '/robots.txt' ) );
	}

	/** Not promoted (feature off): every path passes through — core or a suite
	 * owns these addresses, and we must not shadow or redirect them. */
	public function test_router_stands_down_when_not_promoted() {
		\update_option( Settings::OPTION, array( 'enable_sitemap' => false ) );
		$this->assertSame( '', Sitemap::route_for( Sitemap::INDEX_PATH ) );
		$this->assertSame( '', Sitemap::route_for( '/sitemap.xml' ) );
		$this->assertSame( '', Sitemap::route_for( Sitemap::PATH ) );
	}

	/** Not promoted (coexist): same stand-down, decided by mode not toggle. */
	public function test_router_stands_down_in_coexist_mode() {
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		$this->assertSame( '', Sitemap::route_for( Sitemap::INDEX_PATH ) );
		$this->assertSame( '', Sitemap::route_for( Sitemap::PATH ) );
	}

	/** The feature toggle still rules: off = no promotion, core keeps the slot. */
	public function test_toggle_off_leaves_core_the_sitemap() {
		\update_option( Settings::OPTION, array( 'enable_sitemap' => false ) );
		$GLOBALS['_af_core_sitemaps'] = true;
		$this->assertFalse( Sitemap::promoted() );
		$detected = Sitemap::detect();
		$this->assertSame( 'core', $detected['source'] );
		$this->assertStringContainsString( '/wp-sitemap.xml', $detected['url'] );
	}

	/** Coexist mode: no promotion — the old gap-only cascade, core first. */
	public function test_coexist_mode_keeps_the_gap_only_cascade() {
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		$GLOBALS['_af_core_sitemaps'] = true;
		$this->assertFalse( Sitemap::promoted() );
		$this->assertSame( 'core', Sitemap::detect()['source'] );
	}

	/* ---- the core stand-down filter ---------------------------------------- */

	public function test_core_sitemaps_stand_down_while_promoted() {
		Sitemap::register();
		$this->assertFalse( (bool) \apply_filters( 'wp_sitemaps_enabled', true ) );
	}

	public function test_core_sitemaps_untouched_when_not_promoted() {
		\update_option( Settings::OPTION, array( 'enable_sitemap' => false ) );
		Sitemap::register();
		$this->assertTrue( (bool) \apply_filters( 'wp_sitemaps_enabled', true ) );
	}

	public function test_core_sitemaps_untouched_in_coexist_mode() {
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		Sitemap::register();
		$this->assertTrue( (bool) \apply_filters( 'wp_sitemaps_enabled', true ) );
	}

	/** The filter only ever turns core OFF — a disabled core stays disabled. */
	public function test_filter_never_turns_core_on() {
		\update_option( Settings::OPTION, array( 'enable_sitemap' => false ) );
		Sitemap::register();
		$this->assertFalse( (bool) \apply_filters( 'wp_sitemaps_enabled', false ) );
	}

	/** End to end through the stub: core's own server reports disabled while promoted. */
	public function test_core_server_reports_disabled_through_the_seam() {
		$GLOBALS['_af_core_sitemaps'] = true;
		Sitemap::register();
		$this->assertFalse( \wp_sitemaps_get_server()->sitemaps_enabled() );
	}

	/* ---- the mode-flip cache sync ------------------------------------------ */

	public function test_mode_flip_flushes_the_content_caches_once() {
		// First sync: no recorded mode yet → one flush, mode stored.
		\set_transient( Cache::LLMS_TXT, 'stale' );
		SeoContext::sync_cached_mode();
		$this->assertFalse( \get_transient( Cache::LLMS_TXT ) );
		$this->assertSame( 'solo', \get_option( SeoContext::MODE_OPTION ) );

		// Same mode again: caches stay put — no flush storm on every request.
		\set_transient( Cache::LLMS_TXT, 'fresh' );
		SeoContext::sync_cached_mode();
		$this->assertSame( 'fresh', \get_transient( Cache::LLMS_TXT ) );

		// The mode flips (a suite arrived): one more flush, new mode stored.
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		SeoContext::sync_cached_mode();
		$this->assertFalse( \get_transient( Cache::LLMS_TXT ) );
		$this->assertSame( 'coexist', \get_option( SeoContext::MODE_OPTION ) );
	}
}
