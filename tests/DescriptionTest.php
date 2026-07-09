<?php
/**
 * Per-page AI description: the resolver ({@see Description::for_post()}) and its three
 * emission surfaces — JSON-LD `description`, the per-page markdown lead blockquote, and
 * the gap-only `<meta name="description">`.
 *
 * Covers the explicit-vs-excerpt-vs-content resolution, the feature gate, cleaning/
 * capping, that a filter cannot inject markup or bust the cap, that the meta tag stands
 * down when the feature is off / suppressed / not a singular published post, and that
 * the JSON-LD and markdown surfaces carry (or omit) the description accordingly.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Description;
use Agentimus\Markdown;
use Agentimus\Schema;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class DescriptionTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/**
	 * Register a published post fixture.
	 *
	 * @param int   $id   Post ID.
	 * @param array $meta Post meta (e.g. Description::META).
	 * @param array $post Post-field overrides (post_excerpt, post_content, post_status…).
	 */
	private function fixture( $id, array $meta = array(), array $post = array() ) {
		$GLOBALS['_af_posts'][ $id ] = (object) array_merge(
			array(
				'ID'            => $id,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
				'post_title'    => 'A Post',
				'post_content'  => '<p>Body.</p>',
				'post_excerpt'  => '',
				'post_author'   => 1,
			),
			$post
		);
		if ( ! empty( $meta ) ) {
			$GLOBALS['_af_postmeta'][ $id ] = $meta;
		}
	}

	/** Turn the feature off for a single test. */
	private function disable() {
		\update_option( Settings::OPTION, array( 'enable_ai_description' => false ) );
	}

	// ---- resolver -----------------------------------------------------------

	public function test_explicit_description_wins() {
		$this->fixture( 1, array( Description::META => 'A hand-written AI summary.' ), array( 'post_excerpt' => 'The excerpt.' ) );
		$this->assertSame( 'A hand-written AI summary.', Description::for_post( 1 ) );
	}

	public function test_falls_back_to_excerpt_when_no_explicit() {
		$this->fixture( 2, array(), array( 'post_excerpt' => 'From the excerpt.' ) );
		$this->assertSame( 'From the excerpt.', Description::for_post( 2 ) );
	}

	/** No explicit + no manual excerpt → a short summary derived from the body. */
	public function test_falls_back_to_content_summary_when_no_excerpt() {
		$this->fixture( 3, array(), array( 'post_content' => '<p>One two three four five.</p>', 'post_excerpt' => '' ) );
		$this->assertSame( 'One two three four five.', Description::for_post( 3 ) );
	}

	/** The content summary strips shortcodes and block markup. */
	public function test_content_summary_strips_shortcodes_and_blocks() {
		$this->fixture(
			4,
			array(),
			array( 'post_content' => '<!-- wp:paragraph --><p>Real [shortcode a="b"] words here.</p><!-- /wp:paragraph -->', 'post_excerpt' => '' )
		);
		$this->assertSame( 'Real words here.', Description::for_post( 4 ) );
	}

	/** A filter can override the resolved value entirely (explicit, excerpt or derived). */
	public function test_filter_can_override_the_resolved_description() {
		$this->fixture( 30, array(), array( 'post_content' => 'Body words.', 'post_excerpt' => '' ) );
		\add_filter( 'agentimus_post_description', static function () { return 'Filter wins.'; } );
		$this->assertSame( 'Filter wins.', Description::for_post( 30 ) );
	}

	public function test_returns_empty_when_feature_disabled() {
		$this->disable();
		$this->fixture( 5, array( Description::META => 'Ignored while off.' ) );
		$this->assertSame( '', Description::for_post( 5 ) );
	}

	public function test_missing_post_returns_empty() {
		$this->assertSame( '', Description::for_post( 999 ) );
	}

	public function test_returns_empty_when_nothing_available() {
		$this->fixture( 6, array(), array( 'post_content' => '', 'post_excerpt' => '' ) );
		$this->assertSame( '', Description::for_post( 6 ) );
	}

	// ---- cleaning + hardening ----------------------------------------------

	public function test_clean_strips_tags_and_collapses_whitespace() {
		$this->fixture( 7, array( Description::META => "  <b>Bold</b>   and\n\nspaced  " ) );
		$this->assertSame( 'Bold and spaced', Description::for_post( 7 ) );
	}

	public function test_value_is_capped_at_max_len() {
		$long = str_repeat( 'word ', 200 ); // ~1000 chars.
		$this->fixture( 8, array( Description::META => $long ) );
		$out = Description::for_post( 8 );
		$this->assertLessThanOrEqual( Description::MAX_LEN, mb_strlen( $out ) );
	}

	public function test_filter_cannot_inject_markup_or_bust_cap() {
		$this->fixture( 9, array( Description::META => 'Clean.' ) );
		\add_filter(
			'agentimus_post_description',
			static function () {
				return '<script>alert(1)</script>' . str_repeat( 'x', 5000 );
			}
		);
		$out = Description::for_post( 9 );
		$this->assertStringNotContainsString( '<script>', $out );
		$this->assertLessThanOrEqual( Description::MAX_LEN, mb_strlen( $out ) );
	}

	// ---- JSON-LD surface ----------------------------------------------------

	/** JSON-LD: the description surfaces as schema.org `description` on the per-post node. */
	public function test_schema_emits_description_when_set() {
		$this->fixture( 10, array( Description::META => 'What this page is about.' ) );
		$GLOBALS['_af_current_post_id'] = 10;
		$GLOBALS['_af_is_singular']     = true;

		\ob_start();
		( new Schema( new Settings() ) )->output();
		$out = (string) \ob_get_clean();

		$this->assertStringContainsString( '"description"', $out );
		$this->assertStringContainsString( 'What this page is about.', $out );
	}

	public function test_schema_omits_post_description_when_feature_off() {
		$this->disable();
		$this->fixture( 11, array( Description::META => 'Should not appear.' ) );
		$GLOBALS['_af_current_post_id'] = 11;
		$GLOBALS['_af_is_singular']     = true;

		\ob_start();
		( new Schema( new Settings() ) )->output();
		$out = (string) \ob_get_clean();

		$this->assertStringNotContainsString( 'Should not appear.', $out );
	}

	// ---- markdown surface ---------------------------------------------------

	public function test_markdown_includes_summary_blockquote() {
		$this->fixture( 12, array( Description::META => 'A crisp one-liner for agents.' ), array( 'post_type' => 'page' ) );
		$out = Markdown::post( 12 );
		$this->assertStringContainsString( '> A crisp one-liner for agents.', $out );
	}

	public function test_markdown_omits_blockquote_when_no_description() {
		$this->disable();
		$this->fixture( 13, array( Description::META => 'Off.' ), array( 'post_type' => 'page', 'post_excerpt' => 'Also off.' ) );
		$out = Markdown::post( 13 );
		$this->assertStringNotContainsString( '> Off.', $out );
		$this->assertStringNotContainsString( '> Also off.', $out );
	}

	// ---- gap-only <meta name="description"> --------------------------------

	/** A candidate page whose head has no description tag gets ours appended. */
	public function test_meta_tag_appended_when_head_has_none() {
		$this->fixture( 14, array( Description::META => 'Meta summary line.' ) );
		$GLOBALS['_af_current_post_id'] = 14;
		$GLOBALS['_af_is_singular']     = true;

		$out = ( new Description( new Settings() ) )->filter_head( '<title>x</title>' );

		$this->assertStringContainsString( '<meta name="description"', $out );
		$this->assertStringContainsString( 'Meta summary line.', $out );
	}

	/** The whole point: Agentimus's description REPLACES the theme's tag (still one tag). */
	public function test_meta_tag_replaces_existing_theme_tag() {
		$this->fixture( 15, array( Description::META => 'Ours wins.' ) );
		$GLOBALS['_af_current_post_id'] = 15;
		$GLOBALS['_af_is_singular']     = true;

		$head = '<title>x</title><meta name="description" content="Theme auto-truncation.">';
		$out  = ( new Description( new Settings() ) )->filter_head( $head );

		$this->assertStringContainsString( 'Ours wins.', $out );                       // ours won
		$this->assertStringNotContainsString( 'Theme auto-truncation.', $out );        // theme's replaced
		$this->assertSame( 1, \preg_match_all( '/name="description"/', $out ) );        // exactly one tag
	}

	/** A twitter:description tag must NOT be mistaken for the description tag. */
	public function test_meta_tag_leaves_twitter_description_alone() {
		$this->fixture( 16, array( Description::META => 'Ours.' ) );
		$GLOBALS['_af_current_post_id'] = 16;
		$GLOBALS['_af_is_singular']     = true;

		$head = '<meta name="twitter:description" content="Keep me.">';
		$out  = ( new Description( new Settings() ) )->filter_head( $head );

		$this->assertStringContainsString( 'name="twitter:description"', $out );        // untouched
		$this->assertStringContainsString( 'Keep me.', $out );
		$this->assertStringContainsString( '<meta name="description" content="Ours.">', $out ); // ours appended
	}

	/** With no description of its own, Agentimus must never blank an existing tag. */
	public function test_meta_tag_left_alone_when_agentimus_has_nothing() {
		$this->fixture( 20, array(), array( 'post_content' => '', 'post_excerpt' => '' ) ); // no explicit, no excerpt, no body
		$GLOBALS['_af_current_post_id'] = 20;
		$GLOBALS['_af_is_singular']     = true;

		$head = '<meta name="description" content="Theme description stays.">';
		$out  = ( new Description( new Settings() ) )->filter_head( $head );

		$this->assertSame( $head, $out );
	}

	public function test_meta_tag_not_appended_when_feature_disabled() {
		$this->disable();
		$this->fixture( 16, array( Description::META => 'Hidden.' ) );
		$GLOBALS['_af_current_post_id'] = 16;
		$GLOBALS['_af_is_singular']     = true;

		$out = ( new Description( new Settings() ) )->filter_head( '<title>x</title>' );
		$this->assertStringNotContainsString( '<meta name="description"', $out );
	}

	public function test_meta_tag_not_appended_when_not_singular() {
		$this->fixture( 17, array( Description::META => 'Nope.' ) );
		$GLOBALS['_af_current_post_id'] = 17;
		$GLOBALS['_af_is_singular']     = false;

		$out = ( new Description( new Settings() ) )->filter_head( '<title>x</title>' );
		$this->assertStringNotContainsString( '<meta name="description"', $out );
	}

	public function test_meta_tag_not_appended_for_unpublished_post() {
		$this->fixture( 18, array( Description::META => 'Draft.' ), array( 'post_status' => 'draft' ) );
		$GLOBALS['_af_current_post_id'] = 18;
		$GLOBALS['_af_is_singular']     = true;

		$out = ( new Description( new Settings() ) )->filter_head( '<title>x</title>' );
		$this->assertStringNotContainsString( '<meta name="description"', $out );
	}

	public function test_meta_tag_suppressed_by_filter() {
		$this->fixture( 19, array( Description::META => 'Suppress me.' ) );
		$GLOBALS['_af_current_post_id'] = 19;
		$GLOBALS['_af_is_singular']     = true;
		\add_filter( 'agentimus_emit_meta_description', static function () { return false; } );

		$out = ( new Description( new Settings() ) )->filter_head( '<title>x</title>' );
		$this->assertStringNotContainsString( '<meta name="description"', $out );
	}

	/** Sub-toggle off: the HTML head is untouched, but the resolver (JSON-LD + .md) still carries it. */
	public function test_meta_tag_sub_toggle_off_suppresses_html_only() {
		\update_option( Settings::OPTION, array( 'ai_description_meta_tag' => false ) ); // master stays on (default)
		$this->fixture( 21, array( Description::META => 'Still in the AI data.' ) );
		$GLOBALS['_af_current_post_id'] = 21;
		$GLOBALS['_af_is_singular']     = true;

		$head = '<title>x</title>';
		$out  = ( new Description( new Settings() ) )->filter_head( $head );
		$this->assertSame( $head, $out ); // <head> left alone

		$this->assertSame( 'Still in the AI data.', Description::for_post( 21 ) ); // JSON-LD + .md unaffected
	}

	/** In a clean test env (no SEO plugin constants) the detector reports absent. */
	public function test_seo_plugin_absent_in_clean_env() {
		$this->assertFalse( Schema::seo_plugin_present() );
	}
}
