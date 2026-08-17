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
 * A well-shaped URL was the same lie one step later: Slack and Discord stored
 * a connection without ever calling it, so a revoked or mistyped webhook read
 * CONNECTED until some real event failed days later. Both now prove the road
 * on connect — and these tests hold the whole rule: the proof lands before
 * anything is stored, a bounce refuses in the service's own words, a bad
 * re-connect leaves a working connection standing, and a mere checkbox edit
 * posts nothing at all.
 *
 * ⚠️ They were NOT the only two. That claim stood here for a day and was
 * wrong: the outgoing webhook — the service whose URL is typed by hand rather
 * than minted in a vendor's console — had the same defect and no test to
 * catch it. It proves on connect as of 08-16, and carries the extra promise
 * the others have nothing to make: a refused proof keeps no secret either.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Rest;
use Agentimus\Integrations\Services\Slack;
use Agentimus\Integrations\Services\Discord;
use Agentimus\Integrations\Services\Telegram;
use Agentimus\Integrations\Services\Sheets;
use Agentimus\Integrations\Services\Webhook;
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

	/** The one response the next call gets. */
	private function answers( $code, $body = '' ) {
		$GLOBALS['_af_http_queue'] = array(
			array(
				'response' => array( 'code' => (int) $code ),
				'body'     => (string) $body,
				'headers'  => array(),
			),
		);
	}

	/** A connection already stored and working, the way a returning owner's is. */
	private function stored( $prefix, $url, array $events = array( 'digest_sent' ) ) {
		$saved                                      = isset( $GLOBALS['_af_options'][ Settings::OPTION ] ) ? (array) $GLOBALS['_af_options'][ Settings::OPTION ] : array();
		$saved['integrations']                      = array_merge(
			isset( $saved['integrations'] ) ? (array) $saved['integrations'] : array(),
			array(
				$prefix . '_enabled' => true,
				$prefix . '_url'     => $url,
				$prefix . '_events'  => $events,
			)
		);
		$GLOBALS['_af_options'][ Settings::OPTION ] = $saved;
	}

	/** What is actually stored right now, whatever the payload claimed. */
	private function integrations() {
		return (array) ( new Settings() )->get( 'integrations', array() );
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

	/* ---- the connect proof -------------------------------------------------- */

	public function test_slack_connect_proves_the_road_before_it_stores() {
		$this->answers( 200, 'ok' );

		$payload = $this->rest()->act(
			$this->request( array( 'service' => 'slack', 'action' => 'connect', 'url' => 'https://hooks.slack.com/services/T0/B0/x', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['slack']['enabled'] );
		$this->assertSame( array( 'digest_sent' ), $payload['slack']['events'] );

		$last = $GLOBALS['_af_http_last'];
		$this->assertSame( 'https://hooks.slack.com/services/T0/B0/x', $last['url'], 'A connect proves the road.' );
		$body = json_decode( $last['args']['body'], true );
		$this->assertStringContainsString( 'test message', $body['text'], 'The proof is one plain line, not a formatted event.' );
		// ⛔ THE RULE THIS GUARDS (found by the 2026-08-17 Slack walkthrough): the proof
		// is posted by verify(), which runs BEFORE store(). A connect that posts its
		// message and then fails to save leaves the owner holding a message that says
		// they are connected when they are not — which is exactly what happened, once.
		// The message may state what it PROVES (the road) and never a stored state.
		$this->assertStringNotContainsString( 'Agentimus connected', $body['text'], 'The proof must never claim a connection it has not stored.' );
		$this->assertArrayNotHasKey( 'blocks', $body );
		$this->assertGreaterThan( 0, Slack::state()['lastDeliveredAt'], 'A message landed, so the card starts truthful.' );
	}

	public function test_slack_connect_refuses_a_bounced_webhook_and_stores_nothing() {
		$this->answers( 404, 'no_service' );

		$verdict = $this->rest()->act(
			$this->request( array( 'service' => 'slack', 'action' => 'connect', 'url' => 'https://hooks.slack.com/services/T0/B0/dead', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_slack_status', $verdict->get_error_code() );
		$this->assertSame( 'Slack answered 404: no_service', $verdict->get_error_message(), 'Slack\'s own word rides into the refusal.' );
		$this->assertSame( 400, $verdict->get_error_data()['status'] );

		$integrations = $this->integrations();
		$this->assertFalse( $integrations['slack_enabled'] ?? false, 'A webhook that bounced is not a connection.' );
		$this->assertSame( '', $integrations['slack_url'] ?? '', 'Nothing is written until the road answers.' );
	}

	public function test_a_bounced_reconnect_leaves_the_standing_connection_alone() {
		$this->stored( 'slack', 'https://hooks.slack.com/services/T0/B0/good' );
		$this->answers( 404, 'no_service' );

		$verdict = $this->rest()->act(
			$this->request( array( 'service' => 'slack', 'action' => 'connect', 'url' => 'https://hooks.slack.com/services/T0/B0/typo', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$integrations = $this->integrations();
		$this->assertTrue( $integrations['slack_enabled'], 'A bad re-connect must not take down a working one.' );
		$this->assertSame( 'https://hooks.slack.com/services/T0/B0/good', $integrations['slack_url'] );
	}

	public function test_a_slack_save_with_an_unchanged_url_posts_nothing() {
		$this->stored( 'slack', 'https://hooks.slack.com/services/T0/B0/x' );

		$payload = $this->rest()->act(
			$this->request(
				array(
					'service' => 'slack',
					'action'  => 'save',
					'url'     => 'https://hooks.slack.com/services/T0/B0/x',
					'events'  => array( 'digest_sent', 'impostor_flagged' ),
				)
			)
		);

		$this->assertIsArray( $payload );
		$this->assertSame( array( 'digest_sent', 'impostor_flagged' ), $payload['slack']['events'] );
		$this->assertNull( $GLOBALS['_af_http_last'], 'A checkbox edit is not a new road — nothing is posted.' );
	}

	public function test_a_slack_save_with_a_changed_url_re_proves() {
		$this->stored( 'slack', 'https://hooks.slack.com/services/T0/B0/old' );
		$this->answers( 200, 'ok' );

		$payload = $this->rest()->act(
			$this->request(
				array(
					'service' => 'slack',
					'action'  => 'save',
					'url'     => 'https://hooks.slack.com/services/T0/B0/new',
					'events'  => array( 'digest_sent' ),
				)
			)
		);

		$this->assertIsArray( $payload );
		$this->assertSame( 'https://hooks.slack.com/services/T0/B0/new', $GLOBALS['_af_http_last']['url'], 'A changed url is a road nobody has walked.' );
		$this->assertSame( 'https://hooks.slack.com/services/T0/B0/new', $this->integrations()['slack_url'] );
	}

	public function test_discord_connect_proves_the_road_before_it_stores() {
		$this->answers( 204 );

		$payload = $this->rest()->act(
			$this->request( array( 'service' => 'discord', 'action' => 'connect', 'url' => 'https://discord.com/api/webhooks/1/x', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['discord']['enabled'] );

		$last = $GLOBALS['_af_http_last'];
		$this->assertSame( 'https://discord.com/api/webhooks/1/x', $last['url'] );
		$body = json_decode( $last['args']['body'], true );
		$this->assertStringContainsString( 'test message', $body['content'], 'Discord\'s plain field, not an embed.' );
		$this->assertStringNotContainsString( 'Agentimus connected', $body['content'], 'The proof must never claim a connection it has not stored.' );
		$this->assertArrayNotHasKey( 'embeds', $body );
		$this->assertGreaterThan( 0, Discord::state()['lastDeliveredAt'] );
	}

	public function test_discord_connect_refuses_a_deleted_webhook_and_stores_nothing() {
		$this->answers( 404, '{"message": "Unknown Webhook", "code": 10015}' );

		$verdict = $this->rest()->act(
			$this->request( array( 'service' => 'discord', 'action' => 'connect', 'url' => 'https://discord.com/api/webhooks/1/gone', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'Discord answered 404: Unknown Webhook', $verdict->get_error_message(), 'Discord names the fault; we repeat it.' );

		$integrations = $this->integrations();
		$this->assertFalse( $integrations['discord_enabled'] ?? false );
		$this->assertSame( '', $integrations['discord_url'] ?? '' );
	}

	public function test_a_discord_save_with_an_unchanged_url_posts_nothing() {
		$this->stored( 'discord', 'https://discord.com/api/webhooks/1/x' );

		$payload = $this->rest()->act(
			$this->request(
				array(
					'service' => 'discord',
					'action'  => 'save',
					'url'     => 'https://discord.com/api/webhooks/1/x',
					'events'  => array( 'digest_sent', 'robots_policy_changed' ),
				)
			)
		);

		$this->assertIsArray( $payload );
		$this->assertSame( array( 'digest_sent', 'robots_policy_changed' ), $payload['discord']['events'] );
		$this->assertNull( $GLOBALS['_af_http_last'] );
	}

	public function test_a_discord_save_with_a_changed_url_re_proves() {
		$this->stored( 'discord', 'https://discord.com/api/webhooks/1/old' );
		$this->answers( 204 );

		$this->rest()->act(
			$this->request(
				array(
					'service' => 'discord',
					'action'  => 'save',
					'url'     => 'https://discord.com/api/webhooks/2/new',
					'events'  => array( 'digest_sent' ),
				)
			)
		);

		$this->assertSame( 'https://discord.com/api/webhooks/2/new', $GLOBALS['_af_http_last']['url'] );
	}

	public function test_a_transport_failure_names_the_service_it_could_not_reach() {
		$GLOBALS['_af_http_queue'] = array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$verdict = $this->rest()->act(
			$this->request( array( 'service' => 'slack', 'action' => 'connect', 'url' => 'https://hooks.slack.com/services/T0/B0/x', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_slack_unreachable', $verdict->get_error_code() );
		$this->assertStringContainsString( 'Slack could not be reached', $verdict->get_error_message() );
		$this->assertStringContainsString( 'Operation timed out', $verdict->get_error_message(), 'The transport\'s own words survive the wrapping.' );
	}

	/* ---- the webhook's proof ------------------------------------------------ */

	public function test_webhook_connect_proves_the_road_before_it_stores() {
		$this->answers( 200, 'ok' );

		$payload = $this->rest()->act(
			$this->request( array( 'action' => 'connect', 'url' => 'https://hooks.example.test/in', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['webhook']['enabled'] );

		$last = $GLOBALS['_af_http_last'];
		$this->assertSame( 'https://hooks.example.test/in', $last['url'], 'A connect proves the road.' );
		$this->assertSame( Webhook::TEST_EVENT, $last['args']['headers']['X-Agentimus-Event'] );

		$body = json_decode( $last['args']['body'], true );
		$this->assertSame( array( 'event', 'version', 'site', 'at', 'data' ), array_keys( $body ), 'The proof wears the same envelope as every real event.' );

		// The secret the proof signed with is the secret that was kept: a mint
		// that stored first and proved second would pass every other assertion
		// here and still hand the receiver a signature it can never check.
		$this->assertSame(
			Webhook::sign( $last['args']['body'], Webhook::secret() ),
			$last['args']['headers']['X-Agentimus-Signature']
		);
		$this->assertSame( $payload['secret'], Webhook::secret(), 'The plaintext shown once is the one now stored.' );
		$this->assertGreaterThan( 0, Webhook::state()['lastDeliveredAt'], 'An event landed, so the card starts truthful.' );
	}

	public function test_webhook_connect_refuses_a_dead_receiver_and_keeps_neither_url_nor_secret() {
		$this->answers( 500 );

		$verdict = $this->rest()->act(
			$this->request( array( 'action' => 'connect', 'url' => 'https://hooks.example.test/dead', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_webhook_status', $verdict->get_error_code() );
		$this->assertSame( 'The webhook URL answered 500.', $verdict->get_error_message() );
		$this->assertSame( 400, $verdict->get_error_data()['status'] );

		$integrations = $this->integrations();
		$this->assertFalse( $integrations['webhook_enabled'] ?? false, 'A receiver that never answered is not a connection.' );
		$this->assertSame( '', $integrations['webhook_url'] ?? '' );
		$this->assertFalse( Webhook::has_secret(), 'A secret nobody took must not outlive the refusal.' );
	}

	public function test_a_bounced_webhook_reconnect_leaves_the_standing_connection_and_its_secret_alone() {
		$this->stored( 'webhook', 'https://hooks.example.test/good' );
		Webhook::keep_secret( 'the-secret-the-receiver-already-has' );
		$this->answers( 404 );

		$verdict = $this->rest()->act(
			$this->request( array( 'action' => 'connect', 'url' => 'https://hooks.example.test/typo', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$integrations = $this->integrations();
		$this->assertTrue( $integrations['webhook_enabled'], 'A bad re-connect must not take down a working one.' );
		$this->assertSame( 'https://hooks.example.test/good', $integrations['webhook_url'] );
		$this->assertSame( 'the-secret-the-receiver-already-has', Webhook::secret(), 'The working receiver can still check what it is sent.' );
	}

	public function test_a_webhook_save_that_moves_only_checkboxes_posts_nothing() {
		$this->stored( 'webhook', 'https://hooks.example.test/in' );
		Webhook::keep_secret( 'kept' );
		$GLOBALS['_af_http_last'] = null;

		$payload = $this->rest()->act(
			$this->request( array( 'action' => 'save', 'url' => 'https://hooks.example.test/in', 'events' => array( 'digest_sent', 'finding_opened' ) ) )
		);

		$this->assertIsArray( $payload );
		$this->assertNull( $GLOBALS['_af_http_last'], 'Ticking a box must not put a test event in the owner\'s relay.' );
		$this->assertSame( array( 'digest_sent', 'finding_opened' ), $this->integrations()['webhook_events'] );
		$this->assertSame( 'kept', Webhook::secret(), 'A save keeps the secret the receiver already knows.' );
		$this->assertArrayNotHasKey( 'secret', $payload, 'The plaintext appears once, at the connect that made it.' );
	}

	public function test_a_webhook_save_with_a_changed_url_proves_the_new_one_with_the_secret_it_already_has() {
		$this->stored( 'webhook', 'https://hooks.example.test/old' );
		Webhook::keep_secret( 'unchanged-secret' );
		$this->answers( 200, 'ok' );

		$payload = $this->rest()->act(
			$this->request( array( 'action' => 'save', 'url' => 'https://hooks.example.test/new', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertIsArray( $payload );
		$last = $GLOBALS['_af_http_last'];
		$this->assertSame( 'https://hooks.example.test/new', $last['url'], 'A URL this connection has never posted to must answer first.' );
		$this->assertSame(
			Webhook::sign( $last['args']['body'], 'unchanged-secret' ),
			$last['args']['headers']['X-Agentimus-Signature'],
			'Moving the address does not rotate the credential.'
		);
		$this->assertSame( 'https://hooks.example.test/new', $this->integrations()['webhook_url'] );
		$this->assertSame( 'unchanged-secret', Webhook::secret() );
	}

	public function test_a_webhook_transport_failure_names_what_could_not_be_reached() {
		$GLOBALS['_af_http_queue'] = array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$verdict = $this->rest()->act(
			$this->request( array( 'action' => 'connect', 'url' => 'https://hooks.example.test/in', 'events' => array( 'digest_sent' ) ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_webhook_unreachable', $verdict->get_error_code() );
		$this->assertStringContainsString( 'The webhook URL could not be reached', $verdict->get_error_message() );
		$this->assertStringContainsString( 'Operation timed out', $verdict->get_error_message(), 'The transport\'s own words survive the wrapping.' );
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
		$this->assertGreaterThan( 0, Sheets::state()['lastDeliveredAt'], 'Rows landed, so the card starts truthful.' );
	}

	/* ---- the telegram door -------------------------------------------------- */

	public function test_telegram_connect_records_the_message_it_just_delivered() {
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ), // getMe.
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ), // The test message.
		);

		$payload = $this->rest()->act(
			$this->request(
				array(
					'service' => 'telegram',
					'action'  => 'connect',
					'token'   => '111:token-abc',
					'chat'    => '123456789',
					'events'  => array( 'digest_sent' ),
				)
			)
		);

		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['telegram']['enabled'] );
		$this->assertGreaterThan( 0, Telegram::state()['lastDeliveredAt'], 'The test message landed — the card says so.' );
	}

	public function test_a_telegram_save_that_moves_only_checkboxes_neither_sends_nor_claims_a_delivery() {
		$saved                                      = array(
			'integrations' => array(
				'telegram_enabled' => true,
				'telegram_chat'    => '123456789',
				'telegram_events'  => array( 'digest_sent' ),
				'telegram_tier'    => 'all',
			),
		);
		$GLOBALS['_af_options'][ Settings::OPTION ] = $saved;
		$GLOBALS['_af_options'][ Telegram::TOKEN_OPTION ] = '111:token-abc';

		$payload = $this->rest()->act(
			$this->request(
				array(
					'service' => 'telegram',
					'action'  => 'save',
					'chat'    => '123456789',
					'events'  => array( 'digest_sent', 'impostor_flagged' ),
				)
			)
		);

		$this->assertIsArray( $payload );
		$this->assertSame( array( 'digest_sent', 'impostor_flagged' ), $payload['telegram']['events'] );
		$this->assertNull( $GLOBALS['_af_http_last'], 'A checkbox edit sends nothing.' );
		$this->assertSame( 0, Telegram::state()['lastDeliveredAt'], 'Nothing was delivered, so nothing is claimed.' );
	}

	/* ---- the provider roster ------------------------------------------------ */

	public function test_the_plugins_roster_lists_every_provider_in_card_order() {
		$payload = $this->rest()->status();

		// The running order the screen shows (his call, 2026-08-15): the Fluent
		// family, then WooCommerce, then EDD, then whatever joins later.
		$this->assertSame(
			array( 'fluentcart', 'fluentforms', 'fluentcrm', 'fluentbooking', 'fluentcommunity', 'fluentsupport', 'fluentboards', 'woocommerce', 'edd' ),
			array_column( $payload['plugins'], 'id' )
		);
		foreach ( $payload['plugins'] as $row ) {
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayHasKey( 'blurb', $row );
			$this->assertArrayHasKey( 'present', $row );
			$this->assertArrayHasKey( 'describes', $row );
			$this->assertFalse( $row['present'], 'None of the described plugins run in this suite.' );
			$this->assertFalse( $row['describes'], 'And an absent plugin never claims to be described.' );
		}
	}
}
