<?php
/**
 * Settings — the privacy-safe content default (posts + pages only) and the
 * sanitiser's "never widen to all public types" guarantee.
 *
 * A fresh install must advertise/dump ONLY posts and pages; every other public
 * post type (WooCommerce products, CRM/support/forms CPTs, …) is opt-IN. These
 * tests lock the two paths that live in Settings: defaults() and the sanitize()
 * empty-selection fallback. (Content::post_types() — the third, output-time
 * path — delegates to Settings::default_post_types() and is verified live on a
 * real multi-type site.)
 *
 * @package Agentify\Tests
 */

namespace Agentify\Tests;

use Agentify\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsDefaultsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Make a third public type exist so "safe default" ≠ "all public types". */
	private function with_extra_type(): void {
		$GLOBALS['_af_available_post_types'] = array( 'post', 'page', 'product' );
	}

	/* -- The safe default ------------------------------------------------- */

	public function test_default_post_types_is_post_and_page_only() {
		$this->with_extra_type();
		$this->assertSame( array( 'post', 'page' ), Settings::default_post_types() );
	}

	public function test_defaults_post_types_excludes_other_public_types() {
		$this->with_extra_type();
		$defaults = ( new Settings() )->defaults();
		$this->assertSame( array( 'post', 'page' ), $defaults['post_types'] );
		$this->assertNotContains( 'product', $defaults['post_types'] );
	}

	public function test_default_post_types_falls_back_to_all_when_no_post_or_page() {
		// The rare headless/commerce-only site that registers neither post nor page.
		$GLOBALS['_af_available_post_types'] = array( 'product' );
		$this->assertSame( array( 'product' ), Settings::default_post_types() );
	}

	/* -- The sanitiser never widens to "all public types" ----------------- */

	public function test_sanitize_keeps_an_explicit_opt_in_selection() {
		$this->with_extra_type();
		$clean = ( new Settings() )->sanitize( array( 'post_types' => array( 'product' ) ) );
		$this->assertSame( array( 'product' ), $clean['post_types'] );
	}

	public function test_sanitize_empty_preserves_the_current_selection() {
		$this->with_extra_type();
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );
		$clean = ( new Settings() )->sanitize( array( 'post_types' => array() ) );
		$this->assertSame( array( 'post' ), $clean['post_types'], 'An empty submit must not widen the stored selection.' );
		$this->assertNotContains( 'product', $clean['post_types'] );
	}

	public function test_sanitize_empty_with_stale_selection_falls_back_to_safe_default() {
		$this->with_extra_type();
		update_option( Settings::OPTION, array( 'post_types' => array( 'gone' ) ) ); // no longer a registered type
		$clean = ( new Settings() )->sanitize( array( 'post_types' => array() ) );
		$this->assertSame( array( 'post', 'page' ), $clean['post_types'] );
		$this->assertNotContains( 'product', $clean['post_types'], 'A stale selection must fall back to post+page, never to all public types.' );
	}
}
