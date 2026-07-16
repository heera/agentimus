<?php
/**
 * Guidelines bridge — the digest that folds WP's experimental Content
 * Guidelines into AI prompts and MCP write-tool descriptions. Must be a clean
 * no-op when the feature is absent, pick only the `content`-typed guideline,
 * survive upstream storage surprises, and honor the opt-out filter.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Guidelines;
use PHPUnit\Framework\TestCase;

final class GuidelinesTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		Guidelines::reset_cache();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		Guidelines::reset_cache();
	}

	/**
	 * Stand up the feature with one published content guideline.
	 *
	 * @param int   $id   Guideline post ID.
	 * @param array $meta Meta rows for the post.
	 */
	private function fixture( $id = 90, array $meta = array() ) {
		$GLOBALS['_af_post_types_exist'] = array( 'wp_guideline' );
		$GLOBALS['_af_get_posts']        = array( (object) array( 'ID' => $id ) );
		$GLOBALS['_af_terms'][ $id ]     = array( 'wp_guideline_type' => array( (object) array( 'name' => 'Content', 'slug' => 'content' ) ) );
		$GLOBALS['_af_postmeta'][ $id ]  = $meta;
	}

	public function test_absent_feature_is_a_silent_no_op() {
		$this->assertFalse( Guidelines::available() );
		$this->assertSame( '', Guidelines::digest() );
		$this->assertSame( '', Guidelines::brief() );
	}

	public function test_no_content_typed_guideline_yields_empty() {
		$this->fixture( 90, array( '_guideline_copy' => 'First person.' ) );
		// Re-type the post as a non-content guideline.
		$GLOBALS['_af_terms'][90] = array( 'wp_guideline_type' => array( (object) array( 'name' => 'Memory', 'slug' => 'memory' ) ) );

		$this->assertSame( '', Guidelines::digest() );
	}

	public function test_digest_assembles_categories_and_block_rules() {
		$this->fixture(
			90,
			array(
				'_guideline_copy'               => "First person.\nFlat periods.",
				'_guideline_site'               => 'A personal engineering site.',
				'_guideline_block_core/heading' => 'Sentence case only.',
			)
		);

		$md = Guidelines::digest();

		$this->assertStringContainsString( 'Copy & tone: First person. Flat periods.', $md );
		$this->assertStringContainsString( 'Site context: A personal engineering site.', $md );
		$this->assertStringContainsString( 'Block rules — core/heading: Sentence case only.', $md );
		// Internal newlines collapse; category lines stay separate lines.
		$this->assertSame( 3, count( explode( "\n", $md ) ) );
	}

	public function test_skips_untyped_posts_and_uses_the_content_one() {
		$GLOBALS['_af_post_types_exist'] = array( 'wp_guideline' );
		$GLOBALS['_af_get_posts']        = array( (object) array( 'ID' => 90 ), (object) array( 'ID' => 91 ) );
		$GLOBALS['_af_terms'][90]        = array( 'wp_guideline_type' => array( (object) array( 'name' => 'Artifact', 'slug' => 'artifact' ) ) );
		$GLOBALS['_af_terms'][91]        = array( 'wp_guideline_type' => array( (object) array( 'name' => 'Content', 'slug' => 'content' ) ) );
		$GLOBALS['_af_postmeta'][90]     = array( '_guideline_copy' => 'WRONG ONE' );
		$GLOBALS['_af_postmeta'][91]     = array( '_guideline_copy' => 'Right one.' );

		$md = Guidelines::digest();

		$this->assertStringContainsString( 'Right one.', $md );
		$this->assertStringNotContainsString( 'WRONG ONE', $md );
	}

	public function test_empty_categories_are_omitted() {
		$this->fixture( 90, array( '_guideline_copy' => '   ', '_guideline_images' => 'Real photos only.' ) );

		$md = Guidelines::digest();

		$this->assertSame( 'Images: Real photos only.', $md );
	}

	public function test_filter_can_replace_or_silence_the_digest() {
		$this->fixture( 90, array( '_guideline_copy' => 'First person.' ) );
		add_filter( 'agentimus_guidelines', static function () { return ''; } );

		$this->assertSame( '', Guidelines::digest() );
	}

	public function test_brief_clips_long_digests_at_a_word_boundary() {
		$this->fixture( 90, array( '_guideline_copy' => str_repeat( 'A guideline sentence about tone. ', 80 ) ) );

		$brief = Guidelines::brief();

		$this->assertLessThanOrEqual( Guidelines::BRIEF_CHARS + 3, strlen( $brief ) ); // +… allowance
		$this->assertStringEndsWith( '…', $brief );
		$this->assertStringNotContainsString( "tone. A…", $brief ); // no mid-word tail
	}

	public function test_digest_is_request_cached_until_reset() {
		$this->fixture( 90, array( '_guideline_copy' => 'First person.' ) );
		$first = Guidelines::digest();

		$GLOBALS['_af_postmeta'][90]['_guideline_copy'] = 'Changed.';
		$this->assertSame( $first, Guidelines::digest() );

		Guidelines::reset_cache();
		$this->assertStringContainsString( 'Changed.', Guidelines::digest() );
	}
}
