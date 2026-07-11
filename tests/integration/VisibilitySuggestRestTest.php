<?php
/**
 * AI-Visibility suggestions over REAL REST: the product `category` round-trips through a
 * save, drives the suggested questions, and the AI route degrades honestly when no AI
 * provider is configured (the default in tests, and on most sites).
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class VisibilitySuggestRestTest extends RestTestCase {

	/** POST a body to a plugin route as the given user. */
	private function post( $route, array $body, $user ) {
		wp_set_current_user( $user );
		$req = new \WP_REST_Request( 'POST', '/agentimus/v1' . $route );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $req );
	}

	public function test_category_survives_a_save_round_trip() {
		$res = $this->post(
			'/visibility/config',
			array(
				'targets' => array(
					array( 'name' => 'Agentimus', 'category' => 'WordPress SEO plugin', 'prompts' => array( 'What is Agentimus?' ) ),
				),
			),
			$this->admin
		);
		$this->assertSame( 200, $res->get_status() );

		$data = $res->get_data();
		$this->assertSame( 'WordPress SEO plugin', $data['config']['targets'][0]['category'] );

		// And it is really persisted, not just echoed back.
		wp_set_current_user( $this->admin );
		$get = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/agentimus/v1/visibility/config' ) );
		$this->assertSame( 'WordPress SEO plugin', $get->get_data()['config']['targets'][0]['category'] );
	}

	public function test_suggest_builds_unbranded_questions_from_the_category() {
		$res = $this->post(
			'/visibility/suggest',
			array( 'name' => 'Agentimus', 'category' => 'WordPress SEO plugin', 'competitors' => array( 'Yoast' ) ),
			$this->admin
		);
		$this->assertSame( 200, $res->get_status() );

		$questions = $res->get_data()['questions'];
		$this->assertContains( 'What is the best WordPress SEO plugin?', $questions );
		$this->assertContains( 'Agentimus vs Yoast', $questions );
	}

	/**
	 * The regression this feature exists to prevent: with no category, we offer brand
	 * questions only. We must never invent a market from the site's editorial topics —
	 * a suggestion becomes a graded prompt, and an unanswerable one reports as a
	 * permanent "AI never mentions you".
	 */
	public function test_suggest_invents_no_market_without_a_category() {
		$res = $this->post( '/visibility/suggest', array( 'name' => 'Agentimus' ), $this->admin );
		$this->assertSame( 200, $res->get_status() );

		$questions = $res->get_data()['questions'];
		$this->assertSame( array( 'What is Agentimus?' ), $questions );
		foreach ( $questions as $q ) {
			$this->assertStringNotContainsString( 'What is the best', $q );
		}
	}

	/** Already-tracked questions are never offered back. */
	public function test_suggest_skips_questions_already_tracked() {
		$res = $this->post(
			'/visibility/suggest',
			array(
				'name'     => 'Agentimus',
				'category' => 'WordPress SEO plugin',
				'prompts'  => array( 'what is the best wordpress seo plugin' ),
			),
			$this->admin
		);
		$this->assertNotContains( 'What is the best WordPress SEO plugin?', $res->get_data()['questions'] );
	}

	/**
	 * No AI provider configured (the test env, and any site that hasn't set one up): the
	 * AI route must say so cleanly rather than 500, so the UI can fall back to templates.
	 */
	public function test_ai_suggest_degrades_cleanly_without_a_provider() {
		if ( \Agentimus\Assist::ai_available() ) {
			$this->markTestSkipped( 'This environment has an AI provider configured.' );
		}
		$res = $this->post(
			'/visibility/suggest-ai',
			array( 'name' => 'Agentimus', 'category' => 'WordPress SEO plugin' ),
			$this->admin
		);
		$this->assertSame( 503, $res->get_status() );
		$this->assertSame( 'agentimus_ai_unavailable', $res->get_data()['code'] );
	}

	/** Both suggest routes are admin-only. */
	public function test_suggest_routes_are_admin_only() {
		foreach ( array( '/visibility/suggest', '/visibility/suggest-ai' ) as $route ) {
			$res = $this->post( $route, array( 'name' => 'Agentimus' ), $this->subscriber );
			$this->assertSame( 403, $res->get_status(), $route . ' must reject a subscriber' );
		}
	}

	/**
	 * The AI route must refuse without a category, exactly as the templates do. It cannot
	 * lean on an empty brand to detect "no context" — the brand falls back to the site
	 * title, so it is never empty — and without this it would ask the model to invent a
	 * market, the very regression the category field exists to end.
	 */
	public function test_ai_suggest_refuses_without_a_category() {
		$res = $this->post( '/visibility/suggest-ai', array( 'name' => 'Agentimus' ), $this->admin );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'agentimus_ai_no_category', $res->get_data()['code'] );
	}

	/**
	 * The category is capped in characters, not bytes — a byte cut would land mid-character
	 * on a non-Latin category and store a broken string.
	 */
	public function test_a_long_multibyte_category_is_cut_cleanly() {
		$long = str_repeat( '日', 100 ); // 100 chars / 300 bytes — over the 80-char cap.
		$res  = $this->post(
			'/visibility/config',
			array( 'targets' => array( array( 'name' => 'Acme', 'category' => $long, 'prompts' => array( 'q?' ) ) ) ),
			$this->admin
		);
		$this->assertSame( 200, $res->get_status() );

		$stored = $res->get_data()['config']['targets'][0]['category'];
		$this->assertSame( 80, mb_strlen( $stored ), 'cut to 80 characters, not 80 bytes' );
		$this->assertSame( $stored, wp_check_invalid_utf8( $stored ), 'must remain valid UTF-8' );
	}
}
