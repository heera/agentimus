<?php
/**
 * POST /google/refresh — the door that was missing.
 *
 * ⚠️ Search Console's snapshot could only be refreshed by the daily cron or by
 * re-connecting the key. The Search Performance card had a refresh control, but
 * it re-READ the stored numbers and nothing more — so a snapshot that had
 * quietly stopped updating offered a button that could not fix it, and pressing
 * it looked like it worked. Bing had a real refresh route from the start;
 * Google never did.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class GoogleRefreshRestTest extends RestTestCase {

	private const ROUTE = '/agentimus/v1/google/refresh';

	public function tear_down(): void {
		delete_option( 'agentimus_google' );
		parent::tear_down();
	}

	public function test_the_route_exists_at_all() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( self::ROUTE, $routes, 'The card has a refresh control; it needs a door to knock on.' );
	}

	/**
	 * It writes — it spends API calls and replaces a snapshot — so a GET must
	 * not run it. A refresh reachable by GET is a refresh a crawler or a
	 * link-prefetcher can trigger.
	 *
	 * ⚠️ WordPress answers 404 (`rest_no_route`) rather than 405 when a route
	 * exists but the method has no handler: the method is part of how a route
	 * is matched at all. Asserting 405 here failed against correct behaviour.
	 */
	public function test_a_get_does_not_run_it() {
		wp_set_current_user( $this->admin );
		$status = rest_do_request( new \WP_REST_Request( 'GET', self::ROUTE ) )->get_status();
		$this->assertNotSame( 200, $status );
		$this->assertContains( $status, array( 404, 405 ) );
	}

	public function test_a_stranger_cannot_spend_the_sites_api_quota() {
		wp_set_current_user( 0 );
		$this->assertContains( rest_do_request( new \WP_REST_Request( 'POST', self::ROUTE ) )->get_status(), array( 401, 403 ) );

		wp_set_current_user( $this->subscriber );
		$this->assertContains( rest_do_request( new \WP_REST_Request( 'POST', self::ROUTE ) )->get_status(), array( 401, 403 ) );

		wp_set_current_user( $this->editor );
		$this->assertContains( rest_do_request( new \WP_REST_Request( 'POST', self::ROUTE ) )->get_status(), array( 401, 403 ) );
	}

	/**
	 * Nothing connected is a plain answer, not a crash and not a silent 200 —
	 * an owner pressing refresh on an unconnected card must be told why nothing
	 * happened.
	 */
	public function test_it_says_so_when_google_is_not_connected() {
		delete_option( 'agentimus_google' );
		wp_set_current_user( $this->admin );

		$response = rest_do_request( new \WP_REST_Request( 'POST', self::ROUTE ) );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'agentimus_google_off', is_array( $data ) ? $data['code'] : $data->get_error_code() );
	}
}
