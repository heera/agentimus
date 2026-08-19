<?php
/**
 * GET /worklist — and the fact that ASKING FOR IT CHANGES THE ANSWER.
 *
 * The route reads a chunk of pages before it counts, so on any site with a
 * backlog its counts are newer than whatever else is already on screen. His
 * site, 2026-08-19: the Findings row said "69 Posts and Pages are worth fixing"
 * directly above chips reading 68, because a page had been re-read between the
 * two and came back clean. The payload has to SAY it moved, or the screen
 * cannot know it is now holding two answers.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Grades;

final class WorklistSweptRestTest extends RestTestCase {

	private function get_worklist() {
		$req = new \WP_REST_Request( 'GET', '/agentimus/v1/worklist' );
		return rest_get_server()->dispatch( $req );
	}

	public function test_it_reports_how_many_pages_the_request_itself_re_read() {
		wp_set_current_user( $this->admin );
		delete_option( Grades::VERSION_OPTION );
		Grades::install();

		foreach ( range( 1, 3 ) as $i ) {
			self::factory()->post->create( array(
				'post_title'   => "Unread $i",
				'post_content' => '<p>A plain paragraph about a plain thing, long enough to be read.</p>',
				'post_status'  => 'publish',
			) );
		}

		$res = $this->get_worklist();

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertArrayHasKey( 'swept', $data, 'Without this the screen cannot know its other numbers just went stale.' );
		$this->assertGreaterThan( 0, (int) $data['swept'], 'Three pages nobody had read: this request read them.' );
		$this->assertSame( $data['counts']['fixable'] + $data['counts']['clear'] + $data['counts']['setAside'], 3 );
	}

	/**
	 * ⛔ And a settled site must report zero, or the screen re-reads its
	 * findings on every visit to a list that changed nothing.
	 */
	public function test_a_site_with_nothing_left_to_read_reports_zero() {
		wp_set_current_user( $this->admin );
		delete_option( Grades::VERSION_OPTION );
		Grades::install();

		self::factory()->post->create( array(
			'post_title'   => 'Already read',
			'post_content' => '<p>A plain paragraph about a plain thing, long enough to be read.</p>',
			'post_status'  => 'publish',
		) );

		$this->get_worklist();          // The first look does the reading…
		$data = $this->get_worklist()->get_data(); // …the second finds nothing to do.

		$this->assertSame( 0, (int) $data['swept'] );
	}
}
