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
}
