<?php
/**
 * Telegram — the second service, held to the same four promises as the first,
 * plus the two that are its own:
 *
 *   1. The FORMATTER. Every event becomes one plain readable message; these
 *      tests pin each event's exact text, so what lands on a phone changes
 *      only by conscious act. The fallback for a filter-added event names the
 *      event rather than dropping it.
 *   2. The TWO-STEP PROOF. Connect-time verification fails with two different
 *      sentences — a token Telegram rejected, a chat the bot can't reach — so
 *      the owner always knows WHICH field to recheck.
 *   3. The MINIMUM TIER. With "urgent only" set, a finding below urgent stays
 *      off the phone while every other event passes; the webhook beside it
 *      still receives everything (the payload gate is per service).
 *   4. The TOKEN. Stored in its own option like the webhook's secret, admitted
 *      only as existing, and never allowed to leak into a delivery error.
 *   5. INERTNESS. Disconnected means wants() refuses and deliver() declines —
 *      and the pipeline enqueues one row per service, so flushing one service
 *      leaves its neighbour's pending events standing.
 *   6. SETTINGS. The chat id's vocabulary is enforced on the way in, the
 *      enabled flag collapses without a chat, and the tier only knows its two
 *      words.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Dispatcher;
use Agentimus\Integrations\Events;
use Agentimus\Integrations\Services\Telegram;
use Agentimus\Integrations\Services\Webhook;
use PHPUnit\Framework\TestCase;

final class TelegramTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_cron_events'] = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_cron_events'] );
	}

	/** A connected Telegram: settings switched on + a stored token. */
	private function connect( array $events = null, $tier = 'all' ) {
		$stored                                     = isset( $GLOBALS['_af_options'][ Settings::OPTION ] ) ? $GLOBALS['_af_options'][ Settings::OPTION ] : array();
		$stored['integrations']                     = array_merge(
			isset( $stored['integrations'] ) ? (array) $stored['integrations'] : array(),
			array(
				'telegram_enabled' => true,
				'telegram_chat'    => '123456789',
				'telegram_events'  => null === $events ? Events::names() : $events,
				'telegram_tier'    => $tier,
			)
		);
		$GLOBALS['_af_options'][ Settings::OPTION ] = $stored;
		$GLOBALS['_af_options'][ Telegram::TOKEN_OPTION ] = '111:token-abc';
	}

	/** The webhook connected beside it — the fan-out tests' second lane. */
	private function connect_webhook( array $events = null ) {
		$stored                                     = isset( $GLOBALS['_af_options'][ Settings::OPTION ] ) ? $GLOBALS['_af_options'][ Settings::OPTION ] : array();
		$stored['integrations']                     = array_merge(
			isset( $stored['integrations'] ) ? (array) $stored['integrations'] : array(),
			array(
				'webhook_enabled' => true,
				'webhook_url'     => 'https://hooks.example.test/in',
				'webhook_events'  => null === $events ? Events::names() : $events,
			)
		);
		$GLOBALS['_af_options'][ Settings::OPTION ] = $stored;
		$GLOBALS['_af_options'][ Webhook::SECRET_OPTION ] = 'test-secret-0123456789abcdef';
	}

	private function settings() {
		return new Settings();
	}

	/* ---- the formatter (one exact message per event) ---------------------- */

	public function test_finding_message_is_tier_title_url() {
		$text = Telegram::format(
			Events::FINDING_OPENED,
			Events::envelope(
				Events::FINDING_OPENED,
				array(
					'id'    => 'config_gap:llms_txt',
					'tier'  => 'urgent',
					'title' => 'llms.txt is missing',
					'why'   => 'Turn it on.',
					'url'   => 'https://example.test/wp-admin/admin.php?page=agentimus#findings',
				)
			)
		);

		$this->assertSame(
			"New finding on example.test — urgent\n" .
			"llms.txt is missing\n" .
			'https://example.test/wp-admin/admin.php?page=agentimus#findings',
			$text
		);
	}

	public function test_finding_message_speaks_the_tier_in_words() {
		$text = Telegram::format(
			Events::FINDING_OPENED,
			Events::envelope( Events::FINDING_OPENED, array( 'tier' => 'worth', 'title' => 'x', 'url' => 'https://example.test/a' ) )
		);
		$this->assertStringContainsString( 'worth knowing', $text );
	}

	public function test_digest_message_is_the_numbers() {
		$text = Telegram::format(
			Events::DIGEST_SENT,
			Events::envelope(
				Events::DIGEST_SENT,
				array(
					'period'    => array( 'label' => 'Aug 3 – Aug 9', 'days' => 7 ),
					'agents'    => 41,
					'referrals' => 9,
					'impostors' => 2,
					'score'     => array( 'now' => 88, 'prev' => 81 ),
				)
			)
		);

		$this->assertSame(
			"Weekly digest for example.test (Aug 3 – Aug 9): 41 agent visits, 9 AI referrals, 2 impostors caught.\n" .
			'Score 88 (was 81)',
			$text
		);
	}

	public function test_digest_message_omits_a_score_it_does_not_have() {
		$text = Telegram::format(
			Events::DIGEST_SENT,
			Events::envelope(
				Events::DIGEST_SENT,
				array(
					'period'    => array( 'label' => '', 'days' => 7 ),
					'agents'    => 1,
					'referrals' => 0,
					'impostors' => 0,
					'score'     => array( 'now' => null, 'prev' => null ),
				)
			)
		);

		$this->assertSame( 'Weekly digest for example.test: 1 agent visits, 0 AI referrals, 0 impostors caught.', $text );
	}

	public function test_impostor_message_is_the_client_name() {
		$text = Telegram::format(
			Events::IMPOSTOR_FLAGGED,
			Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) )
		);
		$this->assertSame( 'Impostor caught on example.test — a client calling itself “Googlebot” failed its operator’s check.', $text );
	}

	public function test_robots_message_lists_only_the_sides_that_moved() {
		$text = Telegram::format(
			Events::ROBOTS_CHANGED,
			Events::envelope(
				Events::ROBOTS_CHANGED,
				array(
					'added'   => array( 'disallow: /private', '+3 more' ),
					'removed' => array(),
					'at'      => 1700000000,
				)
			)
		);

		$this->assertSame(
			"robots.txt policy changed on example.test\n" .
			"Added:\n" .
			"  disallow: /private\n" .
			'  +3 more',
			$text
		);
		$this->assertStringNotContainsString( 'Removed:', $text, 'An untouched side gets no heading.' );
	}

	public function test_citation_message_carries_the_counts_and_the_cap() {
		$plain = Telegram::format(
			Events::CITATION_RUN_FINISHED,
			Events::envelope( Events::CITATION_RUN_FINISHED, array( 'runId' => 1, 'checks' => 12, 'capped' => false ) )
		);
		$this->assertSame( 'Citation check finished on example.test — 12 checks run.', $plain );

		$capped = Telegram::format(
			Events::CITATION_RUN_FINISHED,
			Events::envelope( Events::CITATION_RUN_FINISHED, array( 'runId' => 1, 'checks' => 1, 'capped' => true ) )
		);
		$this->assertSame( 'Citation check finished on example.test — 1 check run. The run stopped at its cap.', $capped );
	}

	public function test_wrote_message_is_action_and_title() {
		$text = Telegram::format(
			Events::AGENT_WROTE_CONTENT,
			Events::envelope(
				Events::AGENT_WROTE_CONTENT,
				array( 'postId' => 7, 'title' => 'Hello', 'action' => 'update', 'ability' => 'agentimus/update-content' )
			)
		);
		$this->assertSame( "An AI assistant updated “Hello” on example.test.\nVia agentimus/update-content.", $text );

		$created = Telegram::format(
			Events::AGENT_WROTE_CONTENT,
			Events::envelope( Events::AGENT_WROTE_CONTENT, array( 'postId' => 7, 'title' => 'Hello', 'action' => 'create', 'ability' => '' ) )
		);
		$this->assertSame( 'An AI assistant created “Hello” on example.test.', $created, 'No ability line when no ability was in flight.' );
	}

	public function test_unknown_event_is_named_not_dropped() {
		$text = Telegram::format( 'addon_event', Events::envelope( 'addon_event', array( 'x' => 1 ) ) );
		$this->assertSame( 'addon_event on example.test.', $text );
	}

	/* ---- the minimum tier -------------------------------------------------- */

	public function test_urgent_only_mutes_lesser_findings_but_nothing_else() {
		$this->connect( null, 'urgent' );
		$settings = $this->settings();

		$this->assertTrue( Telegram::accepts( $settings, Events::FINDING_OPENED, array( 'tier' => 'urgent' ) ) );
		$this->assertFalse( Telegram::accepts( $settings, Events::FINDING_OPENED, array( 'tier' => 'worth' ) ) );
		$this->assertFalse( Telegram::accepts( $settings, Events::FINDING_OPENED, array() ), 'No tier claimed = not provably urgent.' );
		$this->assertTrue( Telegram::accepts( $settings, Events::IMPOSTOR_FLAGGED, array( 'client' => 'x' ) ), 'The tier is a findings word; other events pass.' );
	}

	public function test_tier_all_lets_every_finding_through() {
		$this->connect(); // tier 'all'.
		$this->assertTrue( Telegram::accepts( $this->settings(), Events::FINDING_OPENED, array( 'tier' => 'worth' ) ) );
	}

	public function test_the_gate_is_per_service_the_webhook_still_hears_everything() {
		$this->connect( array( Events::FINDING_OPENED ), 'urgent' );
		$this->connect_webhook( array( Events::FINDING_OPENED ) );

		$ids = \Agentimus\Integrations\Services::wanting(
			$this->settings(),
			Events::FINDING_OPENED,
			array( 'tier' => 'worth', 'title' => 'x' )
		);

		$this->assertSame( array( Webhook::ID ), $ids, 'The muted finding still reaches the webhook.' );
	}

	/* ---- the two-step proof ------------------------------------------------- */

	public function test_verify_names_the_token_when_getme_fails() {
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 401 ), 'body' => '{"ok":false,"error_code":401,"description":"Unauthorized"}', 'headers' => array() ),
		);

		$verdict = Telegram::verify( 'bad-token', '123456789' );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_telegram_token', $verdict->get_error_code() );
		$this->assertSame( 'https://api.telegram.org/botbad-token/getMe', $GLOBALS['_af_http_last']['url'], 'getMe is the first and only call — no test message rides on a bad token.' );
	}

	public function test_verify_names_the_chat_when_the_test_message_fails() {
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true,"result":{"username":"my_bot"}}', 'headers' => array() ),
			array( 'response' => array( 'code' => 400 ), 'body' => '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}', 'headers' => array() ),
		);

		$verdict = Telegram::verify( '111:token-abc', '42' );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_telegram_chat', $verdict->get_error_code() );
	}

	public function test_verify_passes_when_both_steps_answer() {
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true,"result":{"username":"my_bot"}}', 'headers' => array() ),
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true,"result":{"message_id":1}}', 'headers' => array() ),
		);

		$this->assertTrue( Telegram::verify( '111:token-abc', '123456789' ) );

		$last = $GLOBALS['_af_http_last'];
		$this->assertSame( 'https://api.telegram.org/bot111:token-abc/sendMessage', $last['url'] );
		$body = json_decode( $last['args']['body'], true );
		$this->assertSame( '123456789', $body['chat_id'] );
		$this->assertStringContainsString( 'Agentimus connected', $body['text'], 'The test message explains itself.' );
	}

	/* ---- delivery ----------------------------------------------------------- */

	public function test_deliver_posts_one_plain_message_to_the_chat() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true,"result":{"message_id":9}}', 'headers' => array() ),
		);

		$verdict = Telegram::deliver(
			Events::IMPOSTOR_FLAGGED,
			Events::envelope( Events::IMPOSTOR_FLAGGED, array( 'client' => 'Googlebot' ) )
		);

		$this->assertTrue( $verdict );
		$last = $GLOBALS['_af_http_last'];
		$this->assertSame( 'https://api.telegram.org/bot111:token-abc/sendMessage', $last['url'] );
		$this->assertSame( 5, $last['args']['timeout'] );

		$body = json_decode( $last['args']['body'], true );
		$this->assertSame( '123456789', $body['chat_id'] );
		$this->assertSame( 'Impostor caught on example.test — a client calling itself “Googlebot” failed its operator’s check.', $body['text'] );
		$this->assertTrue( $body['disable_web_page_preview'] );
	}

	public function test_delivery_failure_speaks_telegrams_own_words_but_never_the_token() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 400 ), 'body' => '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}', 'headers' => array() ),
		);

		$verdict = Telegram::deliver( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array() ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'Telegram answered 400: Bad Request: chat not found', $verdict->get_error_message() );
		$this->assertStringNotContainsString( '111:token-abc', $verdict->get_error_message(), 'The credential never rides in an error.' );
	}

	public function test_drain_routes_a_telegram_row_to_telegram_and_records_its_state() {
		$this->connect();
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true,"result":{"message_id":1}}', 'headers' => array() ),
		);

		$dispatcher = new Dispatcher( $this->settings() );
		$this->assertTrue( $dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array( 'agents' => 1 ) ), Telegram::ID ) );
		$dispatcher->drain();

		$this->assertSame( 0, $dispatcher->depth() );
		$this->assertStringContainsString( 'api.telegram.org', $GLOBALS['_af_http_last']['url'], 'The row went to its own service, not the webhook.' );
		$this->assertGreaterThan( 0, Telegram::state()['lastDeliveredAt'] );
		$this->assertSame( 0, Webhook::state()['lastDeliveredAt'], 'The neighbour\'s honesty line is untouched.' );
	}

	/* ---- the fan-out and the per-service queue ------------------------------ */

	public function test_one_emit_becomes_one_row_per_subscribed_service() {
		$this->connect( array( Events::ROBOTS_CHANGED ) );
		$this->connect_webhook( array( Events::ROBOTS_CHANGED ) );

		( new Events( $this->settings() ) )->on_robots_changed( array( 'at' => time(), 'added' => array( 'disallow: /' ), 'removed' => array() ) );

		$dispatcher = new Dispatcher( $this->settings() );
		$this->assertSame( 2, $dispatcher->depth() );
		$this->assertSame( 1, $dispatcher->depth_for( Webhook::ID ) );
		$this->assertSame( 1, $dispatcher->depth_for( Telegram::ID ) );
	}

	public function test_flushing_one_service_leaves_the_neighbours_rows() {
		$this->connect( array( Events::ROBOTS_CHANGED ) );
		$this->connect_webhook( array( Events::ROBOTS_CHANGED ) );
		( new Events( $this->settings() ) )->on_robots_changed( array( 'at' => time(), 'added' => array( 'disallow: /' ), 'removed' => array() ) );

		Dispatcher::flush_service( Telegram::ID );

		$dispatcher = new Dispatcher( $this->settings() );
		$this->assertSame( 0, $dispatcher->depth_for( Telegram::ID ) );
		$this->assertSame( 1, $dispatcher->depth_for( Webhook::ID ), 'A disconnect discards only its own pending events.' );
	}

	public function test_telegram_alone_arms_the_pipeline_and_the_findings_cron() {
		$this->connect(); // All events ticked; no webhook anywhere.

		$this->assertTrue( ( new Dispatcher( $this->settings() ) )->register(), 'Any connected service arms the drain.' );
		$this->assertTrue( ( new Events( $this->settings() ) )->register() );
		$this->assertTrue( Events::wants_findings_cron( $this->settings() ) );
	}

	/* ---- inertness ---------------------------------------------------------- */

	public function test_disconnected_telegram_wants_nothing_and_delivers_nothing() {
		$settings = $this->settings();

		$this->assertFalse( Telegram::connected( $settings ) );
		$this->assertFalse( Telegram::wants( $settings, Events::DIGEST_SENT ) );

		$verdict = Telegram::deliver( Events::DIGEST_SENT, Events::envelope( Events::DIGEST_SENT, array() ) );
		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_telegram_unconfigured', $verdict->get_error_code() );
		$this->assertNull( $GLOBALS['_af_http_last'], 'Unconfigured = not one network call.' );
	}

	public function test_enabled_without_token_is_still_not_connected() {
		$this->connect();
		delete_option( Telegram::TOKEN_OPTION );
		$this->assertFalse( Telegram::connected( $this->settings() ), 'No token = nothing could be sent = not connected.' );
	}

	/* ---- the token ---------------------------------------------------------- */

	public function test_token_is_stored_and_admitted_only_as_existing() {
		$this->assertFalse( Telegram::has_token() );

		Telegram::store_token( '111:token-abc' );
		$this->assertTrue( Telegram::has_token() );

		Telegram::forget_token();
		$this->assertFalse( Telegram::has_token() );
	}

	/* ---- settings: the chat vocabulary and the collapses -------------------- */

	public function test_normalize_chat_vocabulary() {
		$this->assertSame( '123456789', Telegram::normalize_chat( ' 123456789 ' ) );
		$this->assertSame( '-1001234567890', Telegram::normalize_chat( '-1001234567890' ), 'Groups and channels are negative numbers.' );
		$this->assertSame( '@my_channel', Telegram::normalize_chat( '@my_channel' ) );
		$this->assertSame( '', Telegram::normalize_chat( 'https://t.me/my_channel' ), 'A link is not a chat id.' );
		$this->assertSame( '', Telegram::normalize_chat( 'not a chat' ) );
		$this->assertSame( '', Telegram::normalize_chat( '' ) );
	}

	public function test_sanitize_collapses_enabled_without_a_chat() {
		$clean = ( new Settings() )->sanitize(
			array(
				'integrations' => array(
					'telegram_enabled' => true,
					'telegram_chat'    => 'not a chat',
					'telegram_events'  => array( 'digest_sent' ),
				),
			)
		);

		$this->assertFalse( $clean['integrations']['telegram_enabled'], 'A connection with no chat cannot exist.' );
		$this->assertSame( '', $clean['integrations']['telegram_chat'] );
	}

	public function test_sanitize_knows_only_two_tier_words() {
		$settings = new Settings();

		$urgent = $settings->sanitize( array( 'integrations' => array( 'telegram_tier' => 'urgent' ) ) );
		$this->assertSame( 'urgent', $urgent['integrations']['telegram_tier'] );

		$invented = $settings->sanitize( array( 'integrations' => array( 'telegram_tier' => 'loud' ) ) );
		$this->assertSame( 'all', $invented['integrations']['telegram_tier'] );
	}

	public function test_partial_save_without_the_key_keeps_the_stored_connection() {
		$this->connect();

		$clean = ( new Settings() )->sanitize( array( 'enable_llms_txt' => true ) );

		$this->assertTrue( $clean['integrations']['telegram_enabled'] );
		$this->assertSame( '123456789', $clean['integrations']['telegram_chat'] );
		$this->assertSame( Events::names(), $clean['integrations']['telegram_events'] );
	}
}
