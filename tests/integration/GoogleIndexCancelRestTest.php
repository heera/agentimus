<?php
/**
 * POST /google/index/cancel — the other door that was missing.
 *
 * ⚠️ Same shape of hole as {@see GoogleRefreshRestTest}: the card had a Cancel
 * control and no route behind it. It ended the chunk loop in ONE browser tab,
 * while the run itself carried on server-side — the continuation event finished
 * it within minutes, and a page refresh started the loop up again. The queue is
 * the server's, so the stop has to be the server's too.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Google\Index;
use Agentimus\Google\Settings as GoogleSettings;

final class GoogleIndexCancelRestTest extends RestTestCase {

	private const ROUTE = '/agentimus/v1/google/index/cancel';

	public function set_up(): void {
		parent::set_up();
		delete_option( Index::OPTION );
		delete_option( Index::PAUSE_OPTION );
	}

	/** A connection, without a real key — no request leaves this test. */
	private function connect(): void {
		( new GoogleSettings() )->connect( '{"type":"service_account"}', 'bot@example.iam.gserviceaccount.com', 'sc-domain:example.org' );
	}

	/** A run in flight, written straight to the option: no Google needed to stop one. */
	private function run_in_flight(): void {
		update_option( Index::OPTION, array(
			'rows'       => array(),
			'checked_at' => time(),
			'watch'      => array( array( 'url' => 'https://example.org/a/', 'post_id' => 1, 'reason' => 'busy' ) ),
			'queue'      => array( array( 'url' => 'https://example.org/a/', 'post_id' => 1, 'reason' => 'busy' ) ),
		), false );
	}

	public function test_the_route_exists_at_all() {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes(), 'The card has a Cancel control; it needs a door to knock on.' );
	}

	/**
	 * It writes state, so a GET must not reach it — the same rule the refresh
	 * route holds to. (WordPress answers 404 rather than 405 when a route
	 * exists but the method has no handler.)
	 */
	public function test_a_get_does_not_run_it() {
		wp_set_current_user( $this->admin );
		$status = rest_do_request( new \WP_REST_Request( 'GET', self::ROUTE ) )->get_status();
		$this->assertContains( $status, array( 404, 405 ) );
	}

	/** Stopping the owner's run is the owner's call. */
	public function test_only_someone_who_runs_this_site_can_stop_a_run() {
		$this->connect();
		foreach ( array( 0, $this->subscriber, $this->editor ) as $user ) {
			wp_set_current_user( $user );
			$this->assertContains(
				rest_do_request( new \WP_REST_Request( 'POST', self::ROUTE ) )->get_status(),
				array( 401, 403 )
			);
		}
	}

	public function test_it_says_so_when_google_is_not_connected() {
		delete_option( 'agentimus_google' );
		wp_set_current_user( $this->admin );

		$response = rest_do_request( new \WP_REST_Request( 'POST', self::ROUTE ) );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'agentimus_google_off', is_array( $data ) ? $data['code'] : $data->get_error_code() );
	}

	/**
	 * ⭐ The whole point, in one dispatch: the answer the card redraws from has
	 * to carry the stop, or the screen goes on looking like a run in progress.
	 */
	public function test_it_stops_the_run_and_the_answer_says_so() {
		$this->connect();
		$this->run_in_flight();
		wp_set_current_user( $this->admin );

		$view = rest_do_request( new \WP_REST_Request( 'POST', self::ROUTE ) )->get_data();

		$this->assertSame( 1, $view['pending'], 'The queue is stopped, not thrown away.' );
		$this->assertGreaterThan( 0, $view['pausedAt'], 'And the card is told why it is not moving.' );
		$this->assertTrue( Index::is_paused() );
	}

	/** Stopping nothing changes nothing — no stamp is left to gate the next run. */
	public function test_stopping_a_finished_run_leaves_no_mark() {
		$this->connect();
		wp_set_current_user( $this->admin );

		$view = rest_do_request( new \WP_REST_Request( 'POST', self::ROUTE ) )->get_data();

		$this->assertSame( 0, $view['pending'] );
		$this->assertSame( 0, $view['pausedAt'] );
		$this->assertFalse( Index::is_paused() );
	}
}
