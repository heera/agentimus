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
	 * HIS MOVE, 2026-08-19: he opened the list narrowed to "Featured image not
	 * described", fixed the first page, came back — and the row was still under
	 * a heading saying these are the pages flagged it.
	 *
	 * ⭐ The row STAYING is right: a row that vanishes the moment it is fixed is
	 * a fix nobody got to see, which is the failure this plugin already
	 * corrected once in the score. What was missing is the row saying it has
	 * left the filter. The rows are rendered live while the list is SELECTED
	 * from the stored verdicts, so a page fixed a minute ago is picked by the
	 * store and comes back clean — and now says which.
	 *
	 * ⛔ The whole id list, never the three labels a row shows: a page with four
	 * flags hides one behind "+2 more", and a label test would call it fixed.
	 */
	public function test_a_row_says_when_it_no_longer_carries_the_check_it_was_listed_for() {
		wp_set_current_user( $this->admin );
		delete_option( Grades::VERSION_OPTION );
		Grades::install();

		$thin = self::factory()->post->create( array(
			'post_title'   => 'Thin for now',
			'post_content' => 'Too short.',
			'post_status'  => 'publish',
		) );
		$this->get_worklist(); // Reads it: the store now says this page is thin.

		// The owner fixes it. The verdict ages; nothing re-reads it yet.
		wp_update_post( array(
			'ID'           => $thin,
			'post_content' => wp_slash( '<p>' . str_repeat( 'A plain sentence about a plain thing that goes on a while. ', 40 ) . '</p>' ),
		) );

		// ⚠️ THE PATH THE SCREEN ACTUALLY TAKES. Coming back from the editor it
		// does NOT re-fetch the list — it asks which of the rows it is holding
		// have moved on and replaces those in place, which is why the row is
		// still on screen at all. (Re-fetching would have swept the page first
		// and dropped it, and then he would never have seen the fix land.)
		$rows = ( new \Agentimus\Worklist( new \Agentimus\Settings() ) )->rows_for( array( $thin ) );

		$this->assertCount( 1, $rows, 'It is still the row the screen is holding.' );
		$this->assertIsArray( $rows[0]['flagIds'] );
		$this->assertNotContains( 'words', $rows[0]['flagIds'], 'And it now says it no longer carries the check the list was narrowed to.' );
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
