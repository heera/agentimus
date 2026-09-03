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
use Agentimus\Integrations\Connections;

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

	/* ---- share cards ------------------------------------------------------- */

	private function cards() {
		ob_start();
		$this->seo()->output_social_cards();
		return (string) ob_get_clean();
	}

	public function test_cards_on_a_singular_view() {
		$this->fixture_on_view( 7, null );
		$out = $this->cards();
		$this->assertStringContainsString( '<meta property="og:type" content="article" />', $out );
		$this->assertStringContainsString( '<meta property="og:title" content="A Post" />', $out );
		$this->assertStringContainsString( '<meta property="og:site_name" content="Test Site" />', $out );
		$this->assertStringContainsString( '<meta property="og:description" content="Body." />', $out ); // Description falls back to the body summary.
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.com/?p=7" />', $out );
		// No featured image, no site default, no Site Icon in the test env:
		$this->assertStringNotContainsString( 'og:image', $out );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary" />', $out );
		// X is not connected here: the card must not guess an account.
		$this->assertStringNotContainsString( 'twitter:site', $out );
	}

	/** twitter:site names the X account the site is — the one card fact the
	 * og: tags cannot carry — and only when the owner has connected X. */
	public function test_cards_name_the_connected_x_account() {
		$this->fixture_on_view( 7, null );
		Connections::store( 'x', array( 'client_id' => 'cid', 'refresh_token' => 'rt', 'handle' => 'heera' ) );
		$out = $this->cards();
		Connections::forget( 'x' );
		$this->assertStringContainsString( '<meta name="twitter:site" content="@heera" />', $out );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary" />', $out, 'the card type still prints beside it' );
	}

	/** A stored handle without a usable grant (disconnected, or a half-finished
	 * authorize) is not a connection — nothing is printed. */
	public function test_cards_skip_twitter_site_without_a_live_grant() {
		$this->fixture_on_view( 7, null );
		Connections::store( 'x', array( 'client_id' => 'cid', 'refresh_token' => '', 'handle' => '@heera' ) );
		$out = $this->cards();
		Connections::forget( 'x' );
		$this->assertStringNotContainsString( 'twitter:site', $out );
	}

	public function test_cards_use_the_seo_title_when_set() {
		$this->fixture_on_view( 7 );
		$this->assertStringContainsString(
			'<meta property="og:title" content="Hand-written SEO title" />',
			$this->cards()
		);
	}

	public function test_cards_prefer_the_featured_image() {
		$this->fixture_on_view( 7, null );
		$GLOBALS['_af_thumbnails'][7]    = 55;
		$GLOBALS['_af_attachments'][55]  = array( 'https://example.test/featured.jpg', 1200, 630 );
		$out = $this->cards();
		unset( $GLOBALS['_af_thumbnails'][7] );
		$this->assertStringContainsString( '<meta property="og:image" content="https://example.test/featured.jpg" />', $out );
		$this->assertStringContainsString( '<meta property="og:image:width" content="1200" />', $out );
		$this->assertStringContainsString( '<meta property="og:image:height" content="630" />', $out );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $out );
	}

	public function test_cards_fall_back_to_the_site_default_image() {
		$this->fixture_on_view( 7, null );
		\update_option( Settings::OPTION, array( 'social_default_image' => 44 ) );
		$GLOBALS['_af_attachments'][44] = array( 'https://example.test/default.jpg', 800, 800 );
		$this->assertStringContainsString(
			'<meta property="og:image" content="https://example.test/default.jpg" />',
			$this->cards()
		);
	}

	public function test_cards_on_the_front_page() {
		$GLOBALS['_af_is_front_page'] = true;
		$out = $this->cards();
		$this->assertStringContainsString( '<meta property="og:type" content="website" />', $out );
		$this->assertStringContainsString( '<meta property="og:title" content="Test Site" />', $out );
		$this->assertStringContainsString( '<meta property="og:description" content="A test site." />', $out ); // The tagline.
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.test/" />', $out );
	}

	public function test_cards_stand_down_for_a_non_public_post() {
		$this->fixture_on_view( 7 );
		$GLOBALS['_af_posts'][7]->post_status = 'draft';
		$this->assertSame( '', $this->cards() );
	}

	public function test_cards_stand_down_in_coexist_mode() {
		$this->fixture_on_view( 7 );
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		$this->assertSame( '', $this->cards() );
	}

	public function test_cards_stand_down_via_their_own_filter() {
		$this->fixture_on_view( 7 );
		\add_filter( 'agentimus_emit_social_cards', function () {
			return false;
		} );
		$this->assertSame( '', $this->cards() );
	}

	public function test_cards_skip_archive_views() {
		$GLOBALS['_af_is_category'] = true;
		$this->assertSame( '', $this->cards() );
	}

	/** The default-image setting clamps to a non-negative int; 0 means none. */
	public function test_social_default_image_setting_clamps() {
		$s = new Settings();
		$s->update( array_merge( $s->all(), array( 'social_default_image' => -5 ) ) );
		$this->assertSame( 0, ( new Settings() )->get( 'social_default_image' ) );
		$s->update( array_merge( $s->all(), array( 'social_default_image' => '44' ) ) );
		$this->assertSame( 44, ( new Settings() )->get( 'social_default_image' ) );
	}

	/* ---- gap detection (the head buffer) ----------------------------------- */

	/** Run a fake head through the buffer pair and return the final output. */
	private function buffered_head( $head_html ) {
		$seo = $this->seo();
		ob_start();
		$seo->buffer_start();
		echo $head_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$seo->buffer_end();
		return (string) ob_get_clean();
	}

	/** An empty head on a card-worthy view: ours append after the pass-through. */
	public function test_buffer_appends_cards_and_canonical_to_a_bare_head() {
		$GLOBALS['_af_is_front_page'] = true;
		$out = $this->buffered_head( "<title>x</title>\n" );
		$this->assertStringContainsString( '<title>x</title>', $out );
		$this->assertStringContainsString( 'property="og:type"', $out );
		$this->assertStringContainsString( 'rel="canonical" href="https://example.test/"', $out );
	}

	/** A theme already printed og: tags — ours stand down, the theme's pass through. */
	public function test_buffer_stands_cards_down_when_the_head_has_og_tags() {
		$GLOBALS['_af_is_front_page'] = true;
		$theme = '<meta property="og:title" content="Theme card">' . "\n";
		$out   = $this->buffered_head( $theme );
		$this->assertStringContainsString( 'Theme card', $out );
		$this->assertSame( 1, substr_count( $out, 'property="og:title"' ) ); // Exactly one source.
		// Canonical is judged separately — the theme covered cards, not canonical.
		$this->assertStringContainsString( 'rel="canonical"', $out );
	}

	/** A canonical already present — ours stands down, cards still append. */
	public function test_buffer_stands_canonical_down_when_the_head_has_one() {
		$GLOBALS['_af_is_front_page'] = true;
		$theme = '<link rel="canonical" href="https://example.test/theme-says/">' . "\n";
		$out   = $this->buffered_head( $theme );
		$this->assertSame( 1, substr_count( $out, 'rel="canonical"' ) );
		$this->assertStringContainsString( 'https://example.test/theme-says/', $out );
		$this->assertStringContainsString( 'property="og:type"', $out );
	}

	/** Coexist mode: the buffer never even opens, the head passes through untouched. */
	public function test_buffer_is_inert_in_coexist_mode() {
		$GLOBALS['_af_is_front_page'] = true;
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		$this->assertSame( "<title>x</title>\n", $this->buffered_head( "<title>x</title>\n" ) );
	}

	/* ---- canonical --------------------------------------------------------- */

	private function canonical() {
		ob_start();
		$this->seo()->output_canonical();
		return (string) ob_get_clean();
	}

	public function test_canonical_on_the_blog_as_front_page() {
		$GLOBALS['_af_is_front_page'] = true;
		$this->assertSame(
			'<link rel="canonical" href="https://example.test/" />' . "\n",
			$this->canonical()
		);
	}

	public function test_canonical_on_a_category_archive() {
		$GLOBALS['_af_is_category'] = true;
		$GLOBALS['_af_term_link']   = 'https://example.test/category/news/';
		$this->assertStringContainsString( 'https://example.test/category/news/', $this->canonical() );
	}

	public function test_canonical_on_an_author_archive() {
		$GLOBALS['_af_is_author']          = true;
		$GLOBALS['_af_queried_object_id'] = 3;
		$this->assertStringContainsString( 'https://example.test/author/3/', $this->canonical() );
	}

	/** A term-link failure (WP_Error) prints nothing — never a guessed URL. */
	public function test_canonical_skips_a_failed_term_link() {
		$GLOBALS['_af_is_category'] = true;
		$GLOBALS['_af_term_link']   = new \stdClass(); // Stand-in for WP_Error: not a string.
		$this->assertSame( '', $this->canonical() );
	}

	public function test_canonical_skips_paged_views() {
		$GLOBALS['_af_is_front_page'] = true;
		$GLOBALS['_af_is_paged']      = true;
		$this->assertSame( '', $this->canonical() );
	}

	public function test_canonical_skips_singular_views() {
		$this->fixture_on_view( 7 );
		$this->assertSame( '', $this->canonical() ); // Core's rel_canonical owns these.
	}

	public function test_canonical_stands_down_in_coexist_mode() {
		$GLOBALS['_af_is_front_page'] = true;
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		$this->assertSame( '', $this->canonical() );
	}
}
