<?php
/**
 * Review-queue menu badge — the bubble markup, the count's cache and
 * fail-quiet paths, and the Heartbeat handler's ask-first gating.
 *
 * The count's DB path (Repository::threats) is exercised by integration; here
 * the transient cache is pre-seeded so every path stays database-free.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\ReviewBadge;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ReviewBadgeTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_transients_on'] = true;
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* -- Bubble markup ------------------------------------------------------ */

	public function test_bubble_uses_the_core_class_that_covers_a_menu_without_a_submenu() {
		// ⚠️⚠️ `awaiting-mod`, NOT `update-plugins`. Core's current-item rule is
		//
		//     #adminmenu li a.wp-has-current-submenu .update-plugins,
		//     #adminmenu li.current a .awaiting-mod
		//
		// so `.update-plugins` is styled on the current item ONLY when the menu
		// has a submenu. This one has none, and the bubble fell through to the
		// base rule — painted the scheme's highlight colour, on a row already
		// painted the scheme's highlight colour. Measured live: row and bubble
		// both rgb(56,88,233), no pill visible at all.
		//
		// ⛔ If this test is ever "modernised" back to update-plugins, the badge
		// goes invisible on exactly one screen — the plugin's own — which is the
		// screen nobody thinks to check.
		$html = ReviewBadge::bubble( 3 );
		$this->assertStringContainsString( 'class="awaiting-mod count-3"', $html );
		$this->assertStringNotContainsString( 'update-plugins', $html );
		$this->assertStringContainsString( '<span class="pending-count" aria-hidden="false">3</span>', $html );
	}

	public function test_decorate_leaves_the_title_alone_at_zero() {
		set_transient( ReviewBadge::TRANSIENT, 0, 60 );
		$this->assertSame( 'Agentimus', ReviewBadge::decorate( 'Agentimus', new Settings() ) );
	}

	public function test_decorate_appends_the_bubble_when_something_awaits() {
		set_transient( ReviewBadge::TRANSIENT, 2, 60 );
		$title = ReviewBadge::decorate( 'Agentimus', new Settings() );
		$this->assertStringStartsWith( 'Agentimus ', $title );
		$this->assertStringContainsString( 'count-2', $title );
	}

	/* -- Count -------------------------------------------------------------- */

	public function test_count_prefers_the_cache_over_recomputing() {
		set_transient( ReviewBadge::TRANSIENT, 7, 60 );
		// With a warm cache no DB is touched — this test would fatal otherwise
		// (the unit bootstrap has no $wpdb).
		$this->assertSame( 7, ReviewBadge::count( new Settings() ) );
	}

	public function test_count_is_zero_with_the_visit_log_off() {
		// No cache seeded: with enable_activity off the DB path is skipped
		// entirely and 0 is returned (and cached).
		update_option( Settings::OPTION, array( 'enable_activity' => false ) );
		$this->assertSame( 0, ReviewBadge::count( new Settings() ) );
		$this->assertSame( 0, get_transient( ReviewBadge::TRANSIENT ) );
	}

	/* -- Invalidation -------------------------------------------------------- */

	public function test_forget_drops_the_cached_count() {
		set_transient( ReviewBadge::TRANSIENT, 7, 60 );
		ReviewBadge::forget();
		$this->assertFalse( get_transient( ReviewBadge::TRANSIENT ) );
	}

	public function test_any_settings_write_drops_the_cached_count() {
		// The live-site bug this locks out: Block appended to blocked_agents but the
		// badge's transient survived — the sidebar showed the OLD number for up to
		// a minute, across full page reloads. Every settings write invalidates.
		set_transient( ReviewBadge::TRANSIENT, 2, 60 );
		( new Settings() )->block_agent( 'BadBot' );
		$this->assertFalse( get_transient( ReviewBadge::TRANSIENT ) );
	}

	public function test_an_ignore_drops_the_cached_count() {
		set_transient( ReviewBadge::TRANSIENT, 2, 60 );
		\Agentimus\Activity\Repository::dismiss( 'SomeBot/1.0', 12 );
		$this->assertFalse( get_transient( ReviewBadge::TRANSIENT ) );
	}

	public function test_an_unignore_drops_the_cached_count() {
		\Agentimus\Activity\Repository::dismiss( 'SomeBot/1.0', 12 );
		$keys = array_keys( \Agentimus\Activity\Repository::dismissed_map() );
		set_transient( ReviewBadge::TRANSIENT, 1, 60 );
		\Agentimus\Activity\Repository::undismiss( $keys[0] );
		$this->assertFalse( get_transient( ReviewBadge::TRANSIENT ) );
	}

	public function test_a_recheck_drops_the_cached_count() {
		set_transient( ReviewBadge::TRANSIENT, 2, 60 );
		\Agentimus\Activity\Repository::record_reverify( 'SomeBot/1.0', 1 );
		$this->assertFalse( get_transient( ReviewBadge::TRANSIENT ) );
	}

	/* -- Heartbeat ---------------------------------------------------------- */

	public function test_heartbeat_stays_silent_unless_asked() {
		$badge    = new ReviewBadge( new Settings() );
		$response = $badge->heartbeat( array( 'other' => 1 ), array() );
		$this->assertArrayNotHasKey( 'agentimus_review', $response );
		$this->assertSame( array( 'other' => 1 ), $response );
	}

	public function test_heartbeat_answers_with_the_count_when_asked() {
		set_transient( ReviewBadge::TRANSIENT, 4, 60 );
		$badge    = new ReviewBadge( new Settings() );
		$response = $badge->heartbeat( array(), array( 'agentimus_review' => 1 ) );
		$this->assertSame( array( 'count' => 4 ), $response['agentimus_review'] );
	}

	public function test_heartbeat_denies_a_user_without_manage_options() {
		set_transient( ReviewBadge::TRANSIENT, 4, 60 );
		$GLOBALS['_af_user_can'] = false;
		$badge    = new ReviewBadge( new Settings() );
		$response = $badge->heartbeat( array(), array( 'agentimus_review' => 1 ) );
		$this->assertArrayNotHasKey( 'agentimus_review', $response );
	}

	/* -- Inline listener ----------------------------------------------------- */

	public function test_listener_asks_and_targets_the_menu_node() {
		$js = ReviewBadge::inline_js();
		$this->assertStringContainsString( 'data.agentimus_review=1', $js );
		$this->assertStringContainsString( 'toplevel_page_agentimus', $js );
		// The live updater rebuilds the same markup client-side, so its classes
		// must match {@see ReviewBadge::bubble()} exactly — a mismatch leaves the
		// heartbeat updating an element core no longer styles.
		$this->assertStringContainsString( 'awaiting-mod count-', $js );
		$this->assertStringContainsString( '.pending-count', $js );
		$this->assertStringNotContainsString( 'update-plugins', $js );
	}

	public function test_listener_publishes_the_painter_for_the_spa() {
		// The SPA repaints the sidebar badge through this global the moment its
		// queue changes — it must exist even if jQuery (Heartbeat's event bus)
		// doesn't, so the definition sits before the jQuery bail-out.
		$js = ReviewBadge::inline_js();
		$this->assertStringContainsString( 'window.agentimusReviewBadge=function', $js );
		$this->assertTrue(
			strpos( $js, 'window.agentimusReviewBadge=function' ) < strpos( $js, 'if(!$){return;}' )
		);
	}
}
