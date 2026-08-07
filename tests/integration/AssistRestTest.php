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

	/* -- Spend backstop: per-user rate cap on the paid AI call --------------- */

	public function test_generate_is_rate_limited_per_user() {
		// Both Assist routes gate only on edit_post, so any Contributor+ could otherwise script
		// unbounded paid AI calls — a financial DoS on the owner's bill. generate() enforces a
		// per-user, per-window budget immediately before the one paid call it makes. Exercised via
		// the private helper so no real (paid) provider call is needed.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$rate = new \ReflectionMethod( \Agentimus\Assist::class, 'rate_limited' );
		\_af_accessible( $rate );

		$key = 'agentimus_assist_rate_' . get_current_user_id() . '_' . (int) floor( time() / \Agentimus\Assist::ASSIST_RATE_WINDOW );
		delete_transient( $key );

		$blocked_at = null;
		for ( $i = 1; $i <= \Agentimus\Assist::ASSIST_RATE_MAX + 5; $i++ ) {
			if ( $rate->invoke( null ) ) {
				$blocked_at = $i;
				break;
			}
		}

		$this->assertSame( \Agentimus\Assist::ASSIST_RATE_MAX + 1, $blocked_at, 'The (MAX+1)th call in a window must be refused.' );
		delete_transient( $key );
	}

	public function test_the_rate_cap_is_filterable_off() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'agentimus_assist_rate_max', '__return_zero' );

		$rate = new \ReflectionMethod( \Agentimus\Assist::class, 'rate_limited' );
		\_af_accessible( $rate );

		$blocked = false;
		for ( $i = 0; $i < 50; $i++ ) {
			if ( $rate->invoke( null ) ) {
				$blocked = true;
				break;
			}
		}
		remove_filter( 'agentimus_assist_rate_max', '__return_zero' );
		$this->assertFalse( $blocked, 'A filter returning 0 must disable the cap.' );
	}
}
