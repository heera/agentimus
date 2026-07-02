<?php
/**
 * Per-page AI Topics: the resolver ({@see Topics::for_post()}) and its two
 * emission surfaces (JSON-LD `keywords`, per-page markdown front matter).
 *
 * Covers the manual/derived merge, case-insensitive dedupe, the cap, the
 * feature gate, the per-post-vs-site derive default, and that a filter cannot
 * bust the cap or inject blanks/markup into a machine surface.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Markdown;
use Agentimus\Schema;
use Agentimus\Settings;
use Agentimus\Topics;
use PHPUnit\Framework\TestCase;

final class TopicsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/**
	 * Register a published post fixture with optional topic meta + terms.
	 *
	 * @param int    $id       Post ID.
	 * @param array  $meta     Post meta (e.g. Topics::META_TOPICS, META_DERIVE).
	 * @param array  $terms    [ taxonomy => names[] ].
	 * @param array  $post     Post-field overrides.
	 */
	private function fixture( $id, array $meta = array(), array $terms = array(), array $post = array() ) {
		$GLOBALS['_af_posts'][ $id ] = (object) array_merge(
			array(
				'ID'            => $id,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
				'post_title'    => 'A Post',
				'post_content'  => '<p>Body.</p>',
				'post_author'   => 1,
			),
			$post
		);
		if ( ! empty( $meta ) ) {
			$GLOBALS['_af_postmeta'][ $id ] = $meta;
		}
		if ( ! empty( $terms ) ) {
			$GLOBALS['_af_terms'][ $id ] = $terms;
		}
	}

	public function test_manual_topics_only() {
		$this->fixture( 1, array( Topics::META_TOPICS => array( 'llms.txt', 'AI visibility' ), Topics::META_DERIVE => '0' ) );
		$this->assertSame( array( 'llms.txt', 'AI visibility' ), Topics::for_post( 1 ) );
	}

	public function test_derived_from_tags_and_categories() {
		$this->fixture(
			2,
			array( Topics::META_DERIVE => '1' ),
			array( 'category' => array( 'News' ), 'post_tag' => array( 'WordPress' ) )
		);
		// Categories first, then tags.
		$this->assertSame( array( 'News', 'WordPress' ), Topics::for_post( 2 ) );
	}

	public function test_manual_and_derived_merge_dedupe_case_insensitively() {
		$this->fixture(
			3,
			array( Topics::META_TOPICS => array( 'WordPress', 'AI' ), Topics::META_DERIVE => '1' ),
			array( 'post_tag' => array( 'wordpress', 'SEO' ) )
		);
		// Manual wins the dedupe: the lowercase "wordpress" tag is dropped, "SEO" kept.
		$this->assertSame( array( 'WordPress', 'AI', 'SEO' ), Topics::for_post( 3 ) );
	}

	public function test_cap_is_enforced() {
		\update_option( Settings::OPTION, array( 'topics_max' => 3 ) );
		$this->fixture( 4, array( Topics::META_TOPICS => array( 'a', 'b', 'c', 'd', 'e' ), Topics::META_DERIVE => '0' ) );
		$this->assertSame( array( 'a', 'b', 'c' ), Topics::for_post( 4 ) );
	}

	public function test_feature_disabled_returns_empty() {
		\update_option( Settings::OPTION, array( 'enable_topics' => false ) );
		$this->fixture( 5, array( Topics::META_TOPICS => array( 'x', 'y' ), Topics::META_DERIVE => '0' ) );
		$this->assertSame( array(), Topics::for_post( 5 ) );
	}

	public function test_derive_default_applies_when_post_has_no_choice() {
		// No META_DERIVE on the post → the site default decides.
		$this->fixture( 6, array(), array( 'category' => array( 'Guides' ) ) );

		\update_option( Settings::OPTION, array( 'topics_derive_default' => true ) );
		$this->assertSame( array( 'Guides' ), Topics::for_post( 6 ) );

		\update_option( Settings::OPTION, array( 'topics_derive_default' => false ) );
		$this->assertSame( array(), Topics::for_post( 6 ) );
	}

	public function test_per_post_choice_overrides_site_default() {
		// Site default off, but this post opted in explicitly.
		\update_option( Settings::OPTION, array( 'topics_derive_default' => false ) );
		$this->fixture( 7, array( Topics::META_DERIVE => '1' ), array( 'category' => array( 'Opted In' ) ) );
		$this->assertSame( array( 'Opted In' ), Topics::for_post( 7 ) );
	}

	public function test_filter_can_refine_but_cannot_bust_cap_or_inject_blanks() {
		\update_option( Settings::OPTION, array( 'topics_max' => 2 ) );
		$this->fixture( 8, array( Topics::META_TOPICS => array( 'One' ), Topics::META_DERIVE => '0' ) );

		\add_filter(
			'agentimus_post_topics',
			static function ( $topics ) {
				// A provider returns markup, blanks, a dupe and an over-cap extra.
				return array_merge( $topics, array( '<b>Two</b>', '', 'one', 'Three' ) );
			}
		);

		$out = Topics::for_post( 8 );
		$this->assertSame( array( 'One', 'Two' ), $out ); // markup stripped, blank dropped, "one" deduped, capped at 2.
	}

	public function test_missing_post_returns_empty() {
		$this->assertSame( array(), Topics::for_post( 999 ) );
	}

	/** The editor UI reads this seed to build a live preview that matches for_post(). */
	public function test_derived_topics_exposes_taxonomy_seed() {
		$this->fixture( 30, array(), array( 'category' => array( 'News' ), 'post_tag' => array( 'WordPress' ) ) );
		$this->assertSame( array( 'News', 'WordPress' ), Topics::derived_topics( 30 ) );
		$this->assertSame( array(), Topics::derived_topics( 998 ) ); // missing post
	}

	public function test_uncategorized_is_excluded_from_derived() {
		// The default placeholder category must never become an AI keyword.
		$this->fixture( 40, array( Topics::META_DERIVE => '1' ), array( 'category' => array( 'Uncategorized' ) ) );
		$this->assertSame( array(), Topics::derived_topics( 40 ) );
		$this->assertSame( array(), Topics::for_post( 40 ) );
	}

	public function test_uncategorized_dropped_but_real_terms_kept() {
		$this->fixture( 41, array( Topics::META_DERIVE => '1' ), array( 'category' => array( 'Uncategorized', 'News' ), 'post_tag' => array( 'WordPress' ) ) );
		$this->assertSame( array( 'News', 'WordPress' ), Topics::for_post( 41 ) );
	}

	public function test_topic_exclude_filter_is_honoured() {
		\add_filter( 'agentimus_topic_exclude', static function () { return array( 'news' ); } );
		$this->fixture( 42, array( Topics::META_DERIVE => '1' ), array( 'category' => array( 'News', 'Guides' ) ) );
		$this->assertSame( array( 'Guides' ), Topics::for_post( 42 ) );
	}

	public function test_derive_taxonomies_filter_lets_a_vendor_add_its_taxonomy() {
		// A content type with only a vendor taxonomy (like WooCommerce's product_cat).
		$GLOBALS['_af_taxonomies'] = array( 'product_cat' );
		\add_filter( 'agentimus_derive_taxonomies', static function ( $tax ) { $tax[] = 'product_cat'; return $tax; } );
		$this->fixture( 50, array( Topics::META_DERIVE => '1' ), array( 'product_cat' => array( 'Hoodies', 'Cotton' ) ), array( 'post_type' => 'product' ) );
		$this->assertSame( array( 'Hoodies', 'Cotton' ), Topics::for_post( 50 ) );
	}

	public function test_core_ignores_a_vendor_taxonomy_without_the_filter() {
		// Vendor-neutral: core only knows category/post_tag, so product_cat is untouched
		// until a vendor opts it in via agentimus_derive_taxonomies.
		$GLOBALS['_af_taxonomies'] = array( 'product_cat' );
		$this->fixture( 51, array( Topics::META_DERIVE => '1' ), array( 'product_cat' => array( 'Hoodies' ) ), array( 'post_type' => 'product' ) );
		$this->assertSame( array(), Topics::for_post( 51 ) );
	}

	public function test_cap_accessor_clamps_to_range() {
		\update_option( Settings::OPTION, array( 'topics_max' => 999 ) );
		$this->assertSame( 50, Topics::cap() );
		\update_option( Settings::OPTION, array( 'topics_max' => 0 ) );
		$this->assertSame( 1, Topics::cap() );
	}

	public function test_sanitize_manual_parses_comma_string_and_clips_length() {
		$long = str_repeat( 'x', 100 );
		$out  = Topics::sanitize_manual( "  Alpha ,, <b>Beta</b>\nAlpha\n$long" );
		$this->assertSame( 'Alpha', $out[0] );
		$this->assertSame( 'Beta', $out[1] );           // tags stripped
		$this->assertSame( 3, count( $out ) );           // blank + duplicate "Alpha" dropped
		$this->assertSame( 60, strlen( $out[2] ) );      // clipped to MAX_LEN
	}

	public function test_sanitize_flag_canonicalises() {
		$this->assertSame( '1', Topics::sanitize_flag( '1' ) );
		$this->assertSame( '1', Topics::sanitize_flag( 'on' ) );
		$this->assertSame( '1', Topics::sanitize_flag( true ) );
		$this->assertSame( '0', Topics::sanitize_flag( '0' ) );
		$this->assertSame( '0', Topics::sanitize_flag( '' ) );
		$this->assertSame( '0', Topics::sanitize_flag( 'nope' ) );
	}

	/** JSON-LD: topics surface as schema.org `keywords` on the per-post node. */
	public function test_schema_emits_keywords_when_topics_set() {
		$this->fixture( 10, array( Topics::META_TOPICS => array( 'Alpha', 'Beta' ), Topics::META_DERIVE => '0' ) );
		$GLOBALS['_af_current_post_id'] = 10;
		$GLOBALS['_af_is_singular']     = true;

		\ob_start();
		( new Schema( new Settings() ) )->output();
		$out = (string) \ob_get_clean();

		$this->assertStringContainsString( '"keywords"', $out );
		$this->assertStringContainsString( 'Alpha', $out );
		$this->assertStringContainsString( 'Beta', $out );
	}

	/** JSON-LD: topics also surface as `about` DefinedTerm entities. */
	public function test_schema_emits_about_definedterm_nodes() {
		$this->fixture( 60, array( Topics::META_TOPICS => array( 'WordPress' ), Topics::META_DERIVE => '0' ) );
		$GLOBALS['_af_current_post_id'] = 60;
		$GLOBALS['_af_is_singular']     = true;

		\ob_start();
		( new Schema( new Settings() ) )->output();
		$out = (string) \ob_get_clean();

		$this->assertStringContainsString( '"about"', $out );
		$this->assertStringContainsString( 'DefinedTerm', $out );
	}

	/** An owner/add-on can attach authoritative links (Wikidata) as `sameAs`. */
	public function test_schema_topic_links_become_sameas() {
		\add_filter(
			'agentimus_topic_links',
			static function ( $urls, $topic ) {
				return 'WordPress' === $topic ? array( 'https://www.wikidata.org/wiki/Q11235' ) : $urls;
			},
			10,
			2
		);
		$this->fixture( 62, array( Topics::META_TOPICS => array( 'WordPress' ), Topics::META_DERIVE => '0' ) );
		$GLOBALS['_af_current_post_id'] = 62;
		$GLOBALS['_af_is_singular']     = true;

		\ob_start();
		( new Schema( new Settings() ) )->output();
		$out = (string) \ob_get_clean();

		$this->assertStringContainsString( '"sameAs"', $out );
		// Slashes are escaped (\/) inside the <script> JSON — match on the stable parts.
		$this->assertStringContainsString( 'wikidata.org', $out );
		$this->assertStringContainsString( 'Q11235', $out );
	}

	public function test_schema_omits_keywords_when_no_topics() {
		$this->fixture( 11, array( Topics::META_DERIVE => '0' ) ); // no manual topics, derive off
		$GLOBALS['_af_current_post_id'] = 11;
		$GLOBALS['_af_is_singular']     = true;

		\ob_start();
		( new Schema( new Settings() ) )->output();
		$out = (string) \ob_get_clean();

		$this->assertStringNotContainsString( '"keywords"', $out );
	}

	/** Markdown: topics surface as a front-matter line on the single-page render. */
	public function test_markdown_emits_topics_line() {
		$this->fixture( 12, array( Topics::META_TOPICS => array( 'Alpha', 'Beta' ), Topics::META_DERIVE => '0' ), array(), array( 'post_type' => 'page', 'post_title' => 'Page' ) );
		$md = Markdown::post( 12 );
		$this->assertStringContainsString( 'Topics: Alpha, Beta', $md );
	}

	public function test_markdown_omits_topics_line_when_none() {
		$this->fixture( 13, array( Topics::META_DERIVE => '0' ), array(), array( 'post_type' => 'page' ) );
		$md = Markdown::post( 13 );
		$this->assertStringNotContainsString( 'Topics:', $md );
	}

	public function test_markdown_never_emits_topics_for_protected_post() {
		// The password guard short-circuits before any topics can leak.
		$this->fixture( 14, array( Topics::META_TOPICS => array( 'Secret' ), Topics::META_DERIVE => '0' ), array(), array( 'post_type' => 'page', 'post_password' => 'x' ) );
		$md = Markdown::post( 14 );
		$this->assertSame( "# Not found\n", $md );
		$this->assertStringNotContainsString( 'Secret', $md );
	}
}
