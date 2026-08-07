<?php
/**
 * The theme-facing template tags ({@see inc/template-tags.php}).
 *
 * These three functions are the plugin's only promise to theme authors, so the
 * contract is what gets tested: they agree with the machine surfaces, they fall
 * back to the post in the loop, and they go quiet — never fatal, never invent —
 * when a feature is off, a post is missing or a field was left blank.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Description;
use Agentimus\MediaContext;
use Agentimus\Settings;
use Agentimus\Topics;
use PHPUnit\Framework\TestCase;

final class TemplateTagsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_current_post_id'] );
	}

	/**
	 * Register a published post fixture.
	 *
	 * @param int   $id   Post ID.
	 * @param array $meta Post meta.
	 * @param array $post Post-field overrides.
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
		$GLOBALS['_af_postmeta'][ $id ] = $meta;
	}

	/* ---- they exist at all -------------------------------------------------- */

	/**
	 * The whole point of the file: a theme can reach these without naming a
	 * single internal class. If one is ever renamed, every theme that adopted it
	 * breaks silently — so the names themselves are the contract.
	 */
	public function test_every_tag_is_defined() {
		$this->assertTrue( function_exists( 'agentimus_get_topics' ) );
		$this->assertTrue( function_exists( 'agentimus_get_description' ) );
		$this->assertTrue( function_exists( 'agentimus_get_media_context' ) );
	}

	/**
	 * A theme must never be handed a way to serve humans and machines differently
	 * — that would argue against the plugin's own case. Deliberately absent.
	 */
	public function test_there_is_no_agent_request_tag() {
		$this->assertFalse( function_exists( 'agentimus_is_agent_request' ) );
	}

	/* ---- topics ------------------------------------------------------------- */

	public function test_topics_match_what_the_machine_surfaces_get() {
		$this->fixture( 1, array( Topics::META_TOPICS => array( 'Fragrance', 'Reviews' ) ) );

		$this->assertSame( array( 'Fragrance', 'Reviews' ), \agentimus_get_topics( 1 ) );
		$this->assertSame( Topics::for_post( 1 ), \agentimus_get_topics( 1 ) );
	}

	/** Feature off: an empty list, so a template's own `foreach` prints nothing. */
	public function test_topics_are_empty_when_the_feature_is_off() {
		$this->fixture( 2, array( Topics::META_TOPICS => array( 'Fragrance' ) ) );
		\update_option( Settings::OPTION, array( 'enable_topics' => false ) );

		$this->assertSame( array(), \agentimus_get_topics( 2 ) );
	}

	/* ---- description -------------------------------------------------------- */

	public function test_description_matches_what_the_machine_surfaces_get() {
		$this->fixture( 3, array( Description::META => 'A hand-written summary.' ) );

		$this->assertSame( 'A hand-written summary.', \agentimus_get_description( 3 ) );
		$this->assertSame( Description::for_post( 3 ), \agentimus_get_description( 3 ) );
	}

	/** The same fallback chain the schema uses — the excerpt when nothing explicit. */
	public function test_description_falls_back_the_same_way_the_schema_does() {
		$this->fixture( 4, array(), array( 'post_excerpt' => 'From the excerpt.' ) );

		$this->assertSame( 'From the excerpt.', \agentimus_get_description( 4 ) );
	}

	public function test_description_is_empty_when_the_feature_is_off() {
		$this->fixture( 5, array( Description::META => 'A summary.' ) );
		\update_option( Settings::OPTION, array( 'enable_ai_description' => false ) );

		$this->assertSame( '', \agentimus_get_description( 5 ) );
	}

	/* ---- media context ------------------------------------------------------ */

	/**
	 * The note is written against the URL the block holds; a template usually has
	 * the resolved player address instead. Both must find it, or the tag would be
	 * useless exactly where a theme would reach for it.
	 */
	public function test_media_context_is_found_by_either_url_the_video_has() {
		$this->fixture(
			6,
			array( MediaContext::META => array( 'https://www.youtube.com/watch?v=abc123' => 'Madonna performing Frozen live.' ) )
		);

		$this->assertSame( 'Madonna performing Frozen live.', \agentimus_get_media_context( 'https://www.youtube.com/watch?v=abc123', 6 ) );
		$this->assertSame( 'Madonna performing Frozen live.', \agentimus_get_media_context( 'https://www.youtube.com/embed/abc123', 6 ) );
	}

	/** A different video on the same post gets its own answer, not a borrowed one. */
	public function test_media_context_never_borrows_another_items_note() {
		$this->fixture(
			7,
			array( MediaContext::META => array( 'https://www.youtube.com/watch?v=abc123' => 'The first video.' ) )
		);

		$this->assertSame( '', \agentimus_get_media_context( 'https://vimeo.com/999', 7 ) );
	}

	public function test_media_context_is_empty_for_a_blank_url() {
		$this->fixture( 8, array( MediaContext::META => array( 'https://vimeo.com/1' => 'A note.' ) ) );

		$this->assertSame( '', \agentimus_get_media_context( '', 8 ) );
		$this->assertSame( '', \agentimus_get_media_context( '   ', 8 ) );
	}

	/* ---- the loop, and the empty cases -------------------------------------- */

	/** Called bare inside a loop, each tag reads the post being rendered. */
	public function test_every_tag_falls_back_to_the_current_post() {
		$this->fixture(
			9,
			array(
				Topics::META_TOPICS => array( 'Perfume' ),
				Description::META   => 'The current post.',
				MediaContext::META  => array( 'https://vimeo.com/42' => 'A note about it.' ),
			)
		);
		$GLOBALS['_af_current_post_id'] = 9;

		$this->assertSame( array( 'Perfume' ), \agentimus_get_topics() );
		$this->assertSame( 'The current post.', \agentimus_get_description() );
		$this->assertSame( 'A note about it.', \agentimus_get_media_context( 'https://vimeo.com/42' ) );
	}

	/**
	 * Outside the loop, or on a post that no longer exists, a theme gets empties
	 * rather than a fatal — a template tag must never be able to white-screen a
	 * front end over missing data.
	 */
	public function test_no_post_returns_empties_rather_than_failing() {
		$this->assertSame( array(), \agentimus_get_topics() );
		$this->assertSame( '', \agentimus_get_description() );
		$this->assertSame( '', \agentimus_get_media_context( 'https://vimeo.com/1' ) );

		$this->assertSame( array(), \agentimus_get_topics( 4242 ) );
		$this->assertSame( '', \agentimus_get_description( 4242 ) );
		$this->assertSame( '', \agentimus_get_media_context( 'https://vimeo.com/1', 4242 ) );
	}

	/** A WP_Post object is as acceptable as an ID, the way core tags accept both. */
	public function test_a_post_object_works_as_well_as_an_id() {
		$this->fixture( 10, array( Description::META => 'By object.' ) );

		$this->assertSame( 'By object.', \agentimus_get_description( $GLOBALS['_af_posts'][10] ) );
	}
}
