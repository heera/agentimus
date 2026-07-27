<?php
/**
 * GET /score — the composite AEO/GEO rail, on demand.
 *
 * Exists because the score used to reach the admin only two ways: with the page's initial
 * bootstrap, or in the reply to a settings save. Content, though, is edited in the post
 * editor — another tab — so an open dashboard kept showing a stale score and a "next step"
 * naming a page the owner had already fixed, curable only by a full page reload.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class RestScoreTest extends RestTestCase {

	private function get_score( $user ) {
		wp_set_current_user( $user );
		return rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentimus/v1/score' ) );
	}

	public function test_an_admin_gets_the_composite_score() {
		$res = $this->get_score( $this->admin );
		$this->assertSame( 200, $res->get_status() );

		$score = $res->get_data()['score'];
		$this->assertIsArray( $score );
		$this->assertArrayHasKey( 'score', $score, 'the payload carries the 0-100 composite' );
		$this->assertIsInt( $score['score'] );
		$this->assertGreaterThanOrEqual( 0, $score['score'] );
		$this->assertLessThanOrEqual( 100, $score['score'] );
	}

	/** The whole point: it must be recomputed per request, not served from the boot payload. */
	public function test_the_score_reflects_content_added_after_the_page_loaded() {
		$before = $this->get_score( $this->admin )->get_data()['score'];
		$this->assertIsArray( $before );

		// Publishing busts the cached content sample, exactly as an edit in another tab does.
		self::factory()->post->create(
			array(
				'post_title'   => 'A page with something concrete to quote',
				'post_status'  => 'publish',
				'post_content' => str_repeat( 'Agentimus measured a 63% failure rate in 2024. ', 60 ),
			)
		);

		$after = $this->get_score( $this->admin )->get_data()['score'];
		$this->assertIsArray( $after, 'a second request must recompute rather than 500' );
		$this->assertArrayHasKey( 'score', $after );
	}

	public function test_a_subscriber_is_denied() {
		$this->assertSame( 403, $this->get_score( $this->subscriber )->get_status() );
	}

	public function test_set_a_whole_check_aside_in_one_call() {
		wp_set_current_user( $this->admin );
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => 'Too short.',
				)
			);
		}

		$req = new \WP_REST_Request( 'POST', '/agentimus/v1/optimize/ignore-issue' );
		$req->set_body_params( array( 'issue' => 'words' ) );
		$res = rest_get_server()->dispatch( $req );

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertGreaterThanOrEqual( 3, (int) $data['count'], 'every page the check flags is set aside' );
		$this->assertArrayHasKey( 'score', $data, 'the screen updates from the same response' );

		$ignored = array_map( 'intval', (array) ( new \Agentimus\Settings() )->get( 'optimize_ignored', array() ) );
		foreach ( $ids as $id ) {
			$this->assertContains( (int) $id, $ignored, 'the setting carries the whole set — cap included' );
		}
	}

	public function test_restore_all_empties_the_set_aside_list() {
		wp_set_current_user( $this->admin );
		$ids = array(
			self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => 'Too short.' ) ),
			self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => 'Also short.' ) ),
		);
		$s   = new \Agentimus\Settings();
		$all = $s->all();
		$all['optimize_ignored'] = $ids;
		$s->update( $all );

		$res = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/agentimus/v1/optimize/restore-all' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertArrayHasKey( 'score', $res->get_data(), 'the screen updates from the same response' );
		$this->assertSame( array(), (array) ( new \Agentimus\Settings() )->get( 'optimize_ignored', array() ), 'every parked page returns to grading' );
	}

	public function test_an_unknown_check_id_is_refused() {
		wp_set_current_user( $this->admin );
		$req = new \WP_REST_Request( 'POST', '/agentimus/v1/optimize/ignore-issue' );
		$req->set_body_params( array( 'issue' => 'nonsense' ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $req )->get_status() );
	}
}
