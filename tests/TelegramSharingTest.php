<?php
/**
 * The SHARING use of the Telegram bot — the first use to ride the shared
 * connection store, and the proof that "one credential, two uses" holds:
 *
 *   1. TWO ROOMS. The sharing use keeps its own switch and its own channel;
 *      nothing it stores touches the events use beside it, and vice versa.
 *   2. THE SHARED KEY. sharing_active() is true only with the bot's token in
 *      the store — a bot connected on the Services tab counts here, and a
 *      missing bot refuses the enable with a sentence naming Services.
 *   3. THE CHANNEL IS PROVED. Enabling with a channel this use never proved
 *      sends one test post, in the sharing use's own words; re-saving the
 *      same channel sends nothing. The switch-off keeps the channel, so
 *      coming back is one click.
 *   4. THE DISCONNECT IS TOTAL. Disconnecting the bot on Services switches
 *      the sharing use off and forgets its channel — one credential gone,
 *      every use halted.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Rest;
use Agentimus\Integrations\Services\Telegram;
use PHPUnit\Framework\TestCase;

final class TelegramSharingTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_cron_events'] = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_cron_events'] );
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

	/** One queued 200 — the test post landing. */
	private function queue_ok() {
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
	}

	/* -- Two rooms -------------------------------------------------------------- */

	public function test_sharing_config_defaults_whole() {
		$this->assertSame(
			array( 'enabled' => false, 'channel' => '' ),
			Telegram::sharing_config( new Settings() )
		);
	}

	public function test_the_sharing_save_leaves_the_events_use_untouched() {
		Telegram::store_token( '111:abc' );
		$this->queue_ok();

		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );

		$events_use = Telegram::config( new Settings() );
		$this->assertFalse( $events_use['enabled'], 'Announcing must not switch the events use on.' );
		$this->assertSame( '', $events_use['chat'], 'The events room stays its own.' );
	}

	/* -- The shared key ----------------------------------------------------------- */

	public function test_sharing_active_needs_all_three() {
		$settings = new Settings();
		$this->assertFalse( Telegram::sharing_active( $settings ), 'Nothing stored, nothing active.' );

		Telegram::store_token( '111:abc' );
		$this->queue_ok();
		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );
		$this->assertTrue( Telegram::sharing_active( new Settings() ) );

		// The bot leaving the store kills the use — the shared key, shared.
		Telegram::forget_token();
		$this->assertFalse( Telegram::sharing_active( new Settings() ) );
	}

	public function test_enable_without_a_bot_names_the_services_tab() {
		$verdict = $this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_telegram_token', $verdict->get_error_code() );
		$this->assertStringContainsString( 'Services', $verdict->get_error_message() );
	}

	public function test_a_garbage_channel_is_refused_before_any_send() {
		Telegram::store_token( '111:abc' );

		$verdict = $this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => 'not a channel' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_telegram_channel', $verdict->get_error_code() );
		$this->assertNull( $GLOBALS['_af_http_last'], 'Garbage never reaches the wire.' );
	}

	/* -- The channel is proved ----------------------------------------------------- */

	public function test_enabling_proves_a_new_channel_with_one_post() {
		Telegram::store_token( '111:abc' );
		$this->queue_ok();

		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );

		$this->assertNotNull( $GLOBALS['_af_http_last'], 'One proof, one post.' );
		$this->assertStringContainsString( '/sendMessage', $GLOBALS['_af_http_last']['url'] );
		$this->assertStringContainsString( 'Announcements', $GLOBALS['_af_http_last']['args']['body'], 'The proof speaks the sharing use\'s words, not the events connect-sentence.' );
		$this->assertSame( array( 'enabled' => true, 'channel' => '@heera_updates' ), Telegram::sharing_config( new Settings() ) );
	}

	public function test_resaving_the_proved_channel_sends_nothing() {
		Telegram::store_token( '111:abc' );
		$this->queue_ok();
		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );
		$GLOBALS['_af_http_last'] = null;

		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );

		$this->assertNull( $GLOBALS['_af_http_last'], 'A proved channel is not re-proved.' );
	}

	public function test_a_failed_proof_stores_nothing() {
		Telegram::store_token( '111:abc' );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 403 ), 'body' => '{"ok":false,"description":"Forbidden: bot is not a member of the channel chat"}', 'headers' => array() ),
		);

		$verdict = $this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_telegram_channel', $verdict->get_error_code() );
		$this->assertStringContainsString( 'admin', $verdict->get_error_message(), 'The sentence teaches the admin rule.' );
		$this->assertSame( array( 'enabled' => false, 'channel' => '' ), Telegram::sharing_config( new Settings() ), 'A refused enable must not half-store.' );
	}

	public function test_switching_off_keeps_the_channel() {
		Telegram::store_token( '111:abc' );
		$this->queue_ok();
		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );

		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => false ) ) );

		$this->assertSame( array( 'enabled' => false, 'channel' => '@heera_updates' ), Telegram::sharing_config( new Settings() ), 'Coming back is one click.' );
		$this->assertFalse( Telegram::sharing_active( new Settings() ) );
	}

	/* -- The disconnect is total ----------------------------------------------------- */

	public function test_disconnecting_the_bot_halts_the_sharing_use() {
		Telegram::store_token( '111:abc' );
		$this->queue_ok();
		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );

		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'disconnect' ) ) );

		$this->assertFalse( Telegram::has_token() );
		$this->assertSame( array( 'enabled' => false, 'channel' => '' ), Telegram::sharing_config( new Settings() ), 'The bot gone, the use forgotten whole.' );
	}

	/* -- The payload ------------------------------------------------------------------ */

	public function test_the_status_payload_carries_the_sharing_block() {
		Telegram::store_token( '111:abc' );
		$this->queue_ok();
		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );

		$payload = $this->rest()->status();
		$payload = is_array( $payload ) ? $payload : $payload->get_data();

		$this->assertSame(
			array( 'enabled' => true, 'channel' => '@heera_updates', 'hasToken' => true, 'active' => true, 'queued' => 0, 'lastSentAt' => 0 ),
			$payload['sharing']['telegram']
		);
		$this->assertSame(
			array( 'total' => 0, 'queued' => 0, 'sentWeek' => 0, 'failed' => 0 ),
			$payload['sharing']['ledger'],
			'The roll-up line speaks from the payload, empty ledger and all.'
		);
	}

	/* -- The test announcement ---------------------------------------------------------- */

	public function test_share_test_posts_a_labelled_test_to_the_channel() {
		Telegram::store_token( '111:abc' );
		$this->queue_ok();
		$this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) ) );
		$GLOBALS['_af_http_last'] = null;
		$this->queue_ok();

		$verdict = $this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share-test' ) ) );

		$this->assertNotInstanceOf( \WP_Error::class, $verdict );
		$wire = json_decode( $GLOBALS['_af_http_last']['args']['body'], true );
		$this->assertSame( '@heera_updates', $wire['chat_id'] );
		$this->assertStringContainsString( 'test', $wire['text'], 'A reader who sees it can place it — it says what it is.' );
	}

	public function test_share_test_is_refused_while_announcing_is_off() {
		Telegram::store_token( '111:abc' );

		$verdict = $this->rest()->act( $this->request( array( 'service' => 'telegram', 'action' => 'share-test' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_telegram_sharing_off', $verdict->get_error_code() );
		$this->assertNull( $GLOBALS['_af_http_last'], 'No channel to land in, nothing on the wire.' );
	}
}
