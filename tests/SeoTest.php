<?php
/**
 * Solo-mode head output ({@see Seo}) — the per-page SEO title: sanitisation, the
 * document-title-part swap, and every gate that must stand it down (feature off,
 * coexist mode, non-singular views, no value set).
 *
 * The save() guard chain mirrors {@see Description::save()} verbatim and, like it,
 * relies on nonce/revision APIs the unit bootstrap does not fake — its coverage is
 * the shared pattern, not a re-test here.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Seo;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class SeoTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** A published post with an SEO title set, on the current singular view. */
	private function fixture_on_view( $id, $title = 'Hand-written SEO title' ) {
		$GLOBALS['_af_posts'][ $id ] = (object) array(
			'ID'            => $id,
			'post_status'   => 'publish',
			'post_password' => '',
			'post_type'     => 'post',
			'post_title'    => 'A Post',
			'post_content'  => '<p>Body.</p>',
			'post_excerpt'  => '',
			'post_author'   => 1,
		);
		if ( null !== $title ) {
			$GLOBALS['_af_postmeta'][ $id ] = array( Seo::META_TITLE => $title );
		}
		$GLOBALS['_af_current_post_id'] = $id;
		$GLOBALS['_af_is_singular']     = true;
	}

	private function seo() {
		return new Seo( new Settings() );
	}

	/* ---- sanitisation ------------------------------------------------------ */

	public function test_sanitize_strips_tags_and_collapses_whitespace() {
		$this->assertSame(
			'A clean title',
			Seo::sanitize_title( "  <strong>A\n clean</strong>   title  " )
		);
	}

	public function test_sanitize_caps_the_length() {
		$out = Seo::sanitize_title( str_repeat( 'a', 500 ) );
		$this->assertSame( Seo::TITLE_MAX_LEN, strlen( $out ) );
	}

	public function test_sanitize_returns_empty_for_null() {
		$this->assertSame( '', Seo::sanitize_title( null ) );
	}

	/* ---- the title-part swap ---------------------------------------------- */

	/** Solo mode (clean env), singular view, value set: the title part is swapped, the rest kept. */
	public function test_swaps_the_title_part_in_solo_mode() {
		$this->fixture_on_view( 7 );
		$parts = $this->seo()->filter_title_parts( array( 'title' => 'A Post', 'site' => 'Example' ) );
		$this->assertSame( 'Hand-written SEO title', $parts['title'] );
		$this->assertSame( 'Example', $parts['site'] ); // Site-name suffix untouched.
	}

	/** The stored value is re-sanitised on the way out — stale meta can't ship markup. */
	public function test_swapped_title_is_sanitised() {
		$this->fixture_on_view( 7, '<em>Styled</em>  title' );
		$parts = $this->seo()->filter_title_parts( array( 'title' => 'A Post' ) );
		$this->assertSame( 'Styled title', $parts['title'] );
	}

	public function test_stands_down_without_a_value() {
		$this->fixture_on_view( 7, null );
		$parts = $this->seo()->filter_title_parts( array( 'title' => 'A Post' ) );
		$this->assertSame( 'A Post', $parts['title'] );
	}

	public function test_stands_down_off_singular_views() {
		$this->fixture_on_view( 7 );
		$GLOBALS['_af_is_singular'] = false;
		$parts = $this->seo()->filter_title_parts( array( 'title' => 'Archive' ) );
		$this->assertSame( 'Archive', $parts['title'] );
	}

	public function test_stands_down_when_the_feature_is_off() {
		$this->fixture_on_view( 7 );
		\update_option( Settings::OPTION, array( 'enable_seo_titles' => false ) );
		$parts = $this->seo()->filter_title_parts( array( 'title' => 'A Post' ) );
		$this->assertSame( 'A Post', $parts['title'] );
	}

	/** Coexist mode (an SEO suite owns titles): the swap stands down at request time. */
	public function test_stands_down_in_coexist_mode() {
		$this->fixture_on_view( 7 );
		\add_filter( 'agentimus_solo_mode', function () {
			return false; // What a detected suite resolves to.
		} );
		$parts = $this->seo()->filter_title_parts( array( 'title' => 'A Post' ) );
		$this->assertSame( 'A Post', $parts['title'] );
	}

	/* ---- the editor field's visibility ------------------------------------ */

	public function test_field_shows_in_solo_mode_by_default() {
		$this->assertTrue( Seo::title_ui_enabled() );
	}

	public function test_field_hides_when_the_feature_is_off() {
		\update_option( Settings::OPTION, array( 'enable_seo_titles' => false ) );
		$this->assertFalse( Seo::title_ui_enabled() );
	}

	public function test_field_hides_in_coexist_mode() {
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		$this->assertFalse( Seo::title_ui_enabled() );
	}
}
