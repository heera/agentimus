<?php
/**
 * The AI authoring assist REST route (/agentimus/v1/suggest). Proves the contract that
 * matters without a live AI provider: it is permission-gated per post, validates its
 * input, refuses a disabled field, and — crucially — DEGRADES CLEANLY to a 503 when no
 * AI provider is configured instead of fataling. The generation success path needs a
 * real provider (a key), so it is exercised manually, not here.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Settings;

final class AssistRestTest extends RestTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->enable( array( 'enable_ai_description' => true, 'enable_topics' => true, 'enable_page_checks' => true ) );
		$this->post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Why the HTTP QUERY method exists',
				'post_content' => 'A plain-language guide to the HTTP QUERY method and when to use it.',
			)
		);
	}

	private function enable( array $flags ) {
		update_option( Settings::OPTION, array_merge( (array) get_option( Settings::OPTION, array() ), $flags ) );
	}

	private function suggest( $post, $field ) {
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/suggest' );
		$request->set_param( 'post', $post );
		$request->set_param( 'field', $field );
		return rest_do_request( $request );
	}

	public function test_a_subscriber_is_denied() {
		wp_set_current_user( $this->subscriber );
		$this->assertContains( $this->suggest( $this->post_id, 'description' )->get_status(), array( 401, 403 ) );
	}

	public function test_an_unknown_field_is_rejected() {
		wp_set_current_user( $this->admin );
		$resp = $this->suggest( $this->post_id, 'bogus' );
		$this->assertSame( 400, $resp->get_status() );
	}

	public function test_a_disabled_field_is_refused() {
		$this->enable( array( 'enable_topics' => false ) );
		wp_set_current_user( $this->admin );
		$resp = $this->suggest( $this->post_id, 'topics' );
		$this->assertSame( 400, $resp->get_status() );
		$this->assertSame( 'agentimus_field_off', $resp->get_data()['code'] );
	}

	public function test_it_degrades_cleanly_without_a_provider() {
		// Force "no AI available" regardless of what this machine has configured, so the
		// assertion is deterministic in CI: the route must return a clean 503, never fatal.
		add_filter( 'wp_supports_ai', '__return_false' );
		wp_set_current_user( $this->admin );

		$resp = $this->suggest( $this->post_id, 'description' );
		$this->assertSame( 503, $resp->get_status() );
		$this->assertSame( 'agentimus_ai_unavailable', $resp->get_data()['code'] );

		remove_filter( 'wp_supports_ai', '__return_false' );
	}

	public function test_a_missing_post_is_not_drafted() {
		wp_set_current_user( $this->admin );
		// A non-existent post fails the edit_post permission gate (403) before the callback.
		$this->assertContains( $this->suggest( 99999999, 'description' )->get_status(), array( 403, 404 ) );
	}

	/* -- /suggest-fix (AI Readability) ----------------------------------- */

	private function suggest_fix( $post, $check ) {
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/suggest-fix' );
		$request->set_param( 'post', $post );
		$request->set_param( 'check', $check );
		return rest_do_request( $request );
	}

	public function test_fix_rejects_a_non_fixable_check() {
		wp_set_current_user( $this->admin );
		// 'freshness' has no AI fix — rejected before anything else runs.
		$this->assertSame( 400, $this->suggest_fix( $this->post_id, 'freshness' )->get_status() );
	}

	public function test_fix_degrades_cleanly_without_a_provider() {
		add_filter( 'wp_supports_ai', '__return_false' );
		wp_set_current_user( $this->admin );

		$resp = $this->suggest_fix( $this->post_id, 'summary' );
		$this->assertSame( 503, $resp->get_status() );
		$this->assertSame( 'agentimus_ai_unavailable', $resp->get_data()['code'] );

		remove_filter( 'wp_supports_ai', '__return_false' );
	}
}
