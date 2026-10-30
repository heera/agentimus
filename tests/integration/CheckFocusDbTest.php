<?php
/**
 * check-focus measures a page against a search an agent names, saved or drafted,
 * and writes nothing.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Focus;

final class CheckFocusDbTest extends DbTestCase {

	/** @var int */
	private $post;

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+); the feature self-gates below that.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post = self::factory()->post->create(
			array(
				'post_title'   => 'Choosing a teapot',
				'post_content' => "<!-- wp:paragraph -->\n<p>Cast iron teapots hold heat for a long time and suit a slow pot of green tea.</p>\n<!-- /wp:paragraph -->",
			)
		);
	}

	private function check( array $input ) {
		$ability = wp_get_ability( 'agentimus/check-focus' );
		$this->assertNotNull( $ability, 'agentimus/check-focus did not register.' );
		return $ability->execute( $input );
	}

	public function test_it_measures_the_saved_page() {
		$out = $this->check( array( 'post_id' => $this->post, 'focus' => 'cast iron teapots' ) );

		$this->assertNotWPError( $out );
		$this->assertSame( 'saved', $out['measured'] );
		$this->assertCount( 1, $out['searches'] );
		$this->assertSame( 'answered', $out['searches'][0]['state'] );
		$this->assertSame( 'cast iron teapots', $out['searches'][0]['search'] );
		$this->assertNotSame( '', $out['searches'][0]['why'] );
	}

	public function test_a_search_the_page_does_not_answer_says_so() {
		$out = $this->check( array( 'post_id' => $this->post, 'focus' => 'mountain bike repair' ) );
		$this->assertSame( 'missing', $out['searches'][0]['state'] );
	}

	/** ⭐ The reason this exists beside check-page: test a rewrite before saving it. */
	public function test_a_draft_is_measured_instead_of_the_saved_body() {
		$out = $this->check(
			array(
				'post_id' => $this->post,
				'focus'   => 'mountain bike repair',
				'content' => '<p>Mountain bike repair starts with a clean chain.</p>',
			)
		);

		$this->assertSame( 'draft', $out['measured'] );
		$this->assertSame( 'answered', $out['searches'][0]['state'] );
	}

	public function test_each_search_is_measured_on_its_own() {
		$out = $this->check( array( 'post_id' => $this->post, 'focus' => 'cast iron teapots, mountain bike repair' ) );

		$this->assertSame(
			array( 'answered', 'missing' ),
			array_column( $out['searches'], 'state' )
		);
	}

	public function test_it_writes_nothing() {
		$before = get_post( $this->post );
		update_post_meta( $this->post, Focus::META, 'green tea' );

		$this->check(
			array(
				'post_id' => $this->post,
				'focus'   => 'mountain bike repair',
				'content' => '<p>Something else entirely.</p>',
				'title'   => 'Another title',
			)
		);

		$after = get_post( $this->post );
		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_title, $after->post_title );
		$this->assertSame( $before->post_modified_gmt, $after->post_modified_gmt );
		$this->assertSame( 'green tea', get_post_meta( $this->post, Focus::META, true ) );
	}

	public function test_a_missing_page_or_focus_is_refused() {
		$this->assertWPError( $this->check( array( 'post_id' => $this->post, 'focus' => '   ' ) ) );
		$this->assertWPError( $this->check( array( 'post_id' => 999999, 'focus' => 'teapots' ) ) );
	}

	public function test_only_someone_who_can_edit_the_page_can_check_it() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $this->check( array( 'post_id' => $this->post, 'focus' => 'teapots' ) ) );
	}
}
