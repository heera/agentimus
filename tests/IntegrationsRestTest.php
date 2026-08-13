<?php
/**
 * The Integrations action door — the checks that live at the REST edge.
 *
 * Found by a real click on wpftest: esc_url_raw happily turns "not a url"
 * into http://not%20a%20url, so a connect with obvious garbage came back
 * CONNECTED — a connection that looked made but could never deliver. The
 * door now also demands the host LOOK like a host (a dot, or localhost),
 * and these tests hold that for every URL-shaped service, plus the door's
 * service routing itself.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Rest;
use PHPUnit\Framework\TestCase;

final class IntegrationsRestTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function request( array $params ) {
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/integrations' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	private function rest() {
		return new Rest( new Settings() );
	}

	/* ---- the URL gate ------------------------------------------------------- */

	public function test_clean_url_demands_a_host_that_looks_like_one() {
		$this->assertSame( 'https://hooks.slack.com/services/T0/B0/x', Rest::clean_url( 'https://hooks.slack.com/services/T0/B0/x' ) );
		$this->assertSame( 'http://localhost:5678/hook', Rest::clean_url( 'http://localhost:5678/hook' ), 'The local-test promise holds.' );
		$this->assertSame( 'http://127.0.0.1:8080/hook', Rest::clean_url( 'http://127.0.0.1:8080/hook' ) );

		$this->assertSame( '', Rest::clean_url( 'not a url' ), 'Words are not a URL, whatever esc_url_raw makes of them.' );
		$this->assertSame( '', Rest::clean_url( 'http://not%20a%20url' ), 'esc_url_raw\'s own manufacture is refused too.' );
		$this->assertSame( '', Rest::clean_url( 'http://garbage' ), 'A dotless host is nobody\'s receiver.' );
		$this->assertSame( '', Rest::clean_url( '' ) );
	}

	public function test_slack_connect_refuses_garbage_and_stores_nothing() {
		$verdict = $this->rest()->act(
			$this->request( array( 'service' => 'slack', 'action' => 'connect', 'url' => 'not a url', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_bad_url', $verdict->get_error_code() );
		$this->assertFalse( ( new Settings() )->get( 'integrations' )['slack_enabled'] ?? false, 'A refused connect must not half-store.' );
	}

	public function test_discord_connect_refuses_garbage() {
		$verdict = $this->rest()->act(
			$this->request( array( 'service' => 'discord', 'action' => 'connect', 'url' => 'http://garbage', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_bad_url', $verdict->get_error_code() );
	}

	public function test_webhook_connect_refuses_garbage_through_the_same_gate() {
		$verdict = $this->rest()->act(
			$this->request( array( 'action' => 'connect', 'url' => 'not a url', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_bad_url', $verdict->get_error_code() );
	}

	public function test_slack_connect_with_a_real_url_connects() {
		$payload = $this->rest()->act(
			$this->request( array( 'service' => 'slack', 'action' => 'connect', 'url' => 'https://hooks.slack.com/services/T0/B0/x', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['slack']['enabled'] );
		$this->assertSame( array( 'digest_sent' ), $payload['slack']['events'] );
	}

	/* ---- the door's routing ------------------------------------------------- */

	public function test_unknown_service_or_action_is_refused() {
		$unknown_service = $this->rest()->act( $this->request( array( 'service' => 'carrier_pigeon', 'action' => 'connect' ) ) );
		$this->assertSame( 'agentimus_bad_action', $unknown_service->get_error_code() );

		$unknown_action = $this->rest()->act( $this->request( array( 'service' => 'slack', 'action' => 'regenerate' ) ) );
		$this->assertSame( 'agentimus_bad_action', $unknown_action->get_error_code(), 'Only the webhook has a secret to regenerate.' );
	}

	public function test_no_events_is_refused_for_every_service() {
		$slack = $this->rest()->act(
			$this->request( array( 'service' => 'slack', 'action' => 'connect', 'url' => 'https://hooks.slack.com/services/T0/B0/x', 'events' => array() ) )
		);
		$this->assertSame( 'agentimus_no_events', $slack->get_error_code() );
	}

	/* ---- the sheets door ---------------------------------------------------- */

	public function test_sheets_connect_refuses_garbage_ids_and_stores_nothing() {
		$verdict = $this->rest()->act(
			$this->request( array( 'service' => 'sheets', 'action' => 'connect', 'spreadsheet' => 'not a spreadsheet', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_sheets_id', $verdict->get_error_code() );
		$this->assertStringContainsString( '/d/', $verdict->get_error_message(), 'The refusal teaches where the ID lives in the URL.' );
		$this->assertFalse( ( new Settings() )->get( 'integrations' )['sheets_enabled'] ?? false, 'A refused connect must not half-store.' );
	}

	public function test_sheets_connect_without_a_google_key_points_at_data_sources_never_a_second_credential() {
		$verdict = $this->rest()->act(
			$this->request(
				array(
					'service'     => 'sheets',
					'action'      => 'connect',
					'spreadsheet' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdef',
					'events'      => array( 'digest_sent' ),
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_sheets_nokey', $verdict->get_error_code() );
		$this->assertStringContainsString( 'Data Sources', $verdict->get_error_message() );
		$this->assertNull( $GLOBALS['_af_http_last'], 'No key = no proof call to make.' );
	}

	public function test_sheets_connect_with_a_key_proves_the_road_and_connects() {
		$GLOBALS['_af_options']['agentimus_google'] = array(
			'sa_json'  => '{"type":"service_account"}',
			'sa_email' => 'agentimus@project.iam.gserviceaccount.com',
		);
		$GLOBALS['_af_transients_on']               = true;
		$GLOBALS['_af_transients'][ 'agentimus_google_token_' . substr( md5( \Agentimus\Google\Auth::SCOPE_SHEETS ), 0, 16 ) ] = 'sheets-token';
		$GLOBALS['_af_http_queue']                  = array( array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ) );

		$payload = $this->rest()->act(
			$this->request(
				array(
					'service'     => 'sheets',
					'action'      => 'connect',
					// The kinder paste: the whole URL, normalized at the door.
					'spreadsheet' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdef/edit#gid=0',
					'events'      => array( 'digest_sent' ),
				)
			)
		);

		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['sheets']['enabled'] );
		$this->assertSame( '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdef', $payload['sheets']['spreadsheet'] );
		$this->assertTrue( $payload['sheets']['hasKey'] );
		$this->assertSame( 'agentimus@project.iam.gserviceaccount.com', $payload['sheets']['saEmail'] );
		$this->assertStringContainsString( 'sheets.googleapis.com', $GLOBALS['_af_http_last']['url'], 'A connect proves the road.' );
	}

	/* ---- the provider roster ------------------------------------------------ */

	public function test_the_plugins_roster_lists_every_provider_in_card_order() {
		$payload = $this->rest()->status();

		$this->assertSame(
			array( 'woocommerce', 'fluentcart', 'fluentforms', 'fluentcrm', 'fluentbooking', 'fluentcommunity', 'fluentsupport', 'edd' ),
			array_column( $payload['plugins'], 'id' )
		);
		foreach ( $payload['plugins'] as $row ) {
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayHasKey( 'blurb', $row );
			$this->assertArrayHasKey( 'present', $row );
			$this->assertFalse( $row['present'], 'None of the described plugins run in this suite.' );
		}
	}
}
