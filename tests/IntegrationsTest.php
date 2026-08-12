<?php
/**
 * Integrations — the pipeline's four promises, held in isolation:
 *
 *   1. The wire CONTRACT. Every event's payload is built key by key and
 *      versioned; these tests pin the exact key set per event, so any change
 *      to what leaves the site is a conscious act (the SchemaDrift discipline,
 *      applied to the webhook). The impostor payload is additionally pinned to
 *      carry a client NAME and nothing else — no IP can ever ride along.
 *   2. The SIGNATURE. X-Agentimus-Signature is an HMAC-SHA256 of the exact
 *      transmitted body under the stored secret — verified against the bytes
 *      the (stubbed) wp_remote_post actually saw, not against a re-encoding.
 *   3. The QUEUE. Bounded at 200, FIFO, oldest dropped when full; a failure
 *      backs off and is retried, runs out of tries, and leaves its error on
 *      the connection's state where the card shows it.
 *   4. INERTNESS. Disconnected means register() stands down, enqueue refuses,
 *      and no cron is ever scheduled — the WebMCP doctrine, testable.
 *
 * Plus the settings side: the 'integrations' key survives a partial save that
 * omits it (the Settings::update() reset trap this codebase has already paid
 * for once), and sanitize() validates event names against the catalog.
 *
 * @package Agentimus\Tests
 */

namespace {
	// Recurring-event recorder — not in the shared bootstrap (only the single-
	// event stub is); guarded so a future stub there wins. Recording is the
	// point: it is how the inertness tests SEE whether a cron was booked.
	if ( ! function_exists( 'wp_schedule_event' ) ) {
		function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
			$GLOBALS['_af_cron_events'][] = array(
				'at'         => (int) $timestamp,
				'recurrence' => (string) $recurrence,
				'hook'       => (string) $hook,
			);
			return true;
		}
	}
}

namespace Agentimus\Tests {

	use Agentimus\Findings;
	use Agentimus\Settings;
	use Agentimus\Integrations\Dispatcher;
	use Agentimus\Integrations\Events;
	use Agentimus\Integrations\Services\Webhook;
	use PHPUnit\Framework\TestCase;

	final class IntegrationsTest extends TestCase {

		protected function setUp(): void {
			\_af_reset_options();
			$GLOBALS['_af_cron_events'] = array();
		}

		protected function tearDown(): void {
			\_af_reset_options();
			unset( $GLOBALS['_af_cron_events'] );
		}

		/** A connected webhook: settings switched on + a stored secret. */
		private function connect( array $events = null ) {
			$GLOBALS['_af_options'][ Settings::OPTION ] = array(
				'integrations' => array(
					'webhook_enabled' => true,
					'webhook_url'     => 'https://hooks.example.test/in',
					'webhook_events'  => null === $events ? Events::names() : $events,
				),
			);
			$GLOBALS['_af_options'][ Webhook::SECRET_OPTION ] = 'test-secret-0123456789abcdef';
		}

		private function settings() {
			return new Settings();
		}

		/* ---- the wire contract ---------------------------------------------- */

		public function test_envelope_carries_exactly_the_contract_keys() {
			$envelope = Events::envelope( 'finding_opened', array( 'x' => 1 ) );

			$this->assertSame( array( 'event', 'version', 'site', 'at', 'data' ), array_keys( $envelope ) );
			$this->assertSame( 'finding_opened', $envelope['event'] );
			$this->assertSame( 1, $envelope['version'] );
			$this->assertSame( 'https://example.test/', $envelope['site'] );
			$this->assertIsInt( $envelope['at'] );
			$this->assertSame( array( 'x' => 1 ), $envelope['data'] );
		}

		public function test_catalog_names_the_six_events() {
			$this->assertSame(
				array(
					'finding_opened',
					'digest_sent',
					'impostor_flagged',
					'robots_policy_changed',
					'citation_run_finished',
					'agent_wrote_content',
				),
				Events::names()
			);
		}

		public function test_finding_payload_shape() {
			$payload = Events::finding_payload(
				array(
					'id'    => 'config_gap',
					'check' => 'llms_txt',
					'tier'  => 'urgent',
					'title' => 'llms.txt is missing',
					'why'   => 'Turn it on.',
					// Internal fields that must NOT leak onto the wire:
					'weight'   => 90,
					'evidence' => array( 'secret-ish internals' ),
					'action'   => array( 'tab' => 'settings' ),
				)
			);

			$this->assertSame( array( 'id', 'tier', 'title', 'why', 'url' ), array_keys( $payload ) );
			$this->assertSame( 'config_gap:llms_txt', $payload['id'] );
			$this->assertSame( 'urgent', $payload['tier'] );
			$this->assertSame( 'https://example.test/wp-admin/admin.php?page=agentimus#findings', $payload['url'] );
		}

		public function test_digest_payload_is_summary_numbers_only() {
			$payload = Events::digest_payload(
				array(
					'period'    => array( 'label' => 'Aug 3 – Aug 9', 'days' => 7 ),
					'agents'    => array( 'total' => 41, 'prev' => 12, 'by_client' => array( 'GPTBot' => 30 ) ),
					'referrals' => array( 'total' => 9, 'by_source' => array( 'ChatGPT' => 9 ) ),
					'impostors' => array( 'total' => 2 ),
					'score'     => array( 'now' => 88, 'prev' => 81 ),
					'links'     => array( 'stop' => 'https://example.test/secret-stop-link' ),
				)
			);

			$this->assertSame( array( 'period', 'agents', 'referrals', 'impostors', 'score' ), array_keys( $payload ) );
			$this->assertSame( 41, $payload['agents'] );
			$this->assertSame( 9, $payload['referrals'] );
			$this->assertSame( 2, $payload['impostors'] );
			$this->assertSame( array( 'now' => 88, 'prev' => 81 ), $payload['score'] );
			// The per-client breakdowns and the stop link stay home.
			$this->assertStringNotContainsString( 'stop', wp_json_encode( $payload ) );
		}

		public function test_impostor_payload_is_the_client_name_and_nothing_else() {
			$payload = Events::impostor_payload( '  Googlebot  ' );
			$this->assertSame( array( 'client' => 'Googlebot' ), $payload );
		}

		public function test_robots_payload_clips_long_line_lists_and_says_so() {
			$added   = array();
			for ( $i = 0; $i < 25; $i++ ) {
				$added[] = 'disallow: /path-' . $i;
			}
			$payload = Events::robots_payload( array( 'at' => 1700000000, 'added' => $added, 'removed' => array() ) );

			$this->assertSame( array( 'added', 'removed', 'at' ), array_keys( $payload ) );
			$this->assertCount( 21, $payload['added'], '20 lines + the count of the rest.' );
			$this->assertSame( '+5 more', end( $payload['added'] ) );
			$this->assertSame( array(), $payload['removed'] );
			$this->assertSame( 1700000000, $payload['at'] );
		}

		public function test_citation_payload_shape() {
			$payload = Events::citation_payload( array( 'ran' => true, 'runId' => 1723400000, 'checks' => 12, 'capped' => false ) );
			$this->assertSame( array( 'runId' => 1723400000, 'checks' => 12, 'capped' => false ), $payload );
		}

		public function test_wrote_payload_shape_and_action_vocabulary() {
			$GLOBALS['_af_posts'][7] = new \WP_Post( array( 'ID' => 7, 'post_title' => 'A &amp; B' ) );

			$payload = Events::wrote_payload( 7, 'update', 'agentimus/update-content' );
			$this->assertSame( array( 'postId', 'title', 'action', 'ability' ), array_keys( $payload ) );
			$this->assertSame( 'A & B', $payload['title'], 'Entities are decoded — the wire carries words, not markup.' );
			$this->assertSame( 'update', $payload['action'] );

			// Anything that is not 'update' collapses to 'create': a two-word vocabulary.
			$this->assertSame( 'create', Events::wrote_payload( 7, 'weird', '' )['action'] );
		}

		/* ---- the signature --------------------------------------------------- */

		public function test_delivery_signs_the_exact_transmitted_body() {
			$this->connect();
			$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() ) );

			$dispatcher = new Dispatcher( $this->settings() );
			$this->assertTrue( $dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array( 'agents' => 1 ) ) ) );
			$dispatcher->drain();

			$last = $GLOBALS['_af_http_last'];
			$this->assertSame( 'https://hooks.example.test/in', $last['url'] );
			$this->assertSame( 5, $last['args']['timeout'] );
			$this->assertSame( 0, $last['args']['redirection'], 'A redirecting receiver must not re-POST the signed body elsewhere.' );
			$this->assertSame( 'digest_sent', $last['args']['headers']['X-Agentimus-Event'] );

			$body = $last['args']['body'];
			$this->assertSame(
				'sha256=' . hash_hmac( 'sha256', $body, 'test-secret-0123456789abcdef' ),
				$last['args']['headers']['X-Agentimus-Signature'],
				'The signature must verify against the bytes actually sent.'
			);

			// Delivered: queue empty, success on the state, no standing error.
			$this->assertSame( 0, $dispatcher->depth() );
			$state = Webhook::state();
			$this->assertGreaterThan( 0, $state['lastDeliveredAt'] );
			$this->assertSame( '', $state['lastError'] );
		}

		public function test_sign_is_prefixed_hmac_sha256() {
			$this->assertSame(
				'sha256=' . hash_hmac( 'sha256', '{"a":1}', 's3cret' ),
				Webhook::sign( '{"a":1}', 's3cret' )
			);
		}

		/* ---- the queue ------------------------------------------------------- */

		public function test_queue_is_bounded_fifo_dropping_the_oldest() {
			$this->connect();
			$dispatcher = new Dispatcher( $this->settings() );

			for ( $i = 1; $i <= Dispatcher::MAX_QUEUE + 5; $i++ ) {
				$dispatcher->enqueue( 'finding_opened', Events::envelope( 'finding_opened', array( 'n' => $i ) ) );
			}

			$queue = $dispatcher->queue();
			$this->assertCount( Dispatcher::MAX_QUEUE, $queue );
			$this->assertSame( 6, $queue[0]['envelope']['data']['n'], 'Full = the oldest yields to the newest.' );
			$this->assertSame( Dispatcher::MAX_QUEUE + 5, $queue[ Dispatcher::MAX_QUEUE - 1 ]['envelope']['data']['n'] );
		}

		public function test_deliveries_happen_in_fifo_order() {
			$this->connect();
			// Two successes queued for two deliveries.
			$ok                        = array( 'response' => array( 'code' => 200 ), 'body' => '', 'headers' => array() );
			$GLOBALS['_af_http_queue'] = array( $ok, $ok );

			$dispatcher = new Dispatcher( $this->settings() );
			$dispatcher->enqueue( 'finding_opened', Events::envelope( 'finding_opened', array( 'n' => 1 ) ) );
			$dispatcher->enqueue( 'finding_opened', Events::envelope( 'finding_opened', array( 'n' => 2 ) ) );
			$dispatcher->drain();

			$this->assertSame( 0, $dispatcher->depth() );
			// The LAST call carries n=2, so n=1 went first.
			$this->assertSame( 2, json_decode( $GLOBALS['_af_http_last']['args']['body'], true )['data']['n'] );
		}

		public function test_failure_backs_off_and_records_the_error() {
			$this->connect();
			$GLOBALS['_af_http_queue'] = array( new \WP_Error( 'http_request_failed', 'cURL error 28' ) );

			$dispatcher = new Dispatcher( $this->settings() );
			$dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array() ) );
			$before = time();
			$dispatcher->drain();

			$queue = $dispatcher->queue();
			$this->assertCount( 1, $queue, 'A failed row is kept for retry.' );
			$this->assertSame( 1, $queue[0]['attempts'] );
			$this->assertGreaterThanOrEqual( $before + Dispatcher::backoff( 1 ), $queue[0]['next_at'] );

			$state = Webhook::state();
			$this->assertSame( 'cURL error 28', $state['lastError'] );
			$this->assertGreaterThan( 0, $state['lastErrorAt'] );
		}

		public function test_backed_off_row_is_not_reattempted_before_its_time() {
			$this->connect();
			$GLOBALS['_af_http_queue'] = array( new \WP_Error( 'http_request_failed', 'down' ) );

			$dispatcher = new Dispatcher( $this->settings() );
			$dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array() ) );
			$dispatcher->drain(); // Fails, backs off.

			$GLOBALS['_af_http_last'] = null;
			$dispatcher->drain(); // Immediately again: the row is not yet due.

			$this->assertNull( $GLOBALS['_af_http_last'], 'No HTTP call before the backoff elapses.' );
			$this->assertCount( 1, $dispatcher->queue() );
		}

		public function test_row_out_of_tries_is_dropped_with_the_failure_on_record() {
			$this->connect();
			$dispatcher = new Dispatcher( $this->settings() );
			$dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array() ) );

			// Walk the row to its last permitted try by rewinding next_at each tick.
			for ( $attempt = 1; $attempt <= Dispatcher::MAX_ATTEMPTS; $attempt++ ) {
				$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 500 ), 'body' => 'oops', 'headers' => array() ) );
				$queue                     = $dispatcher->queue();
				if ( isset( $queue[0] ) ) {
					$queue[0]['next_at'] = 0;
					update_option( Dispatcher::QUEUE_OPTION, $queue );
				}
				$dispatcher->drain();
			}

			$this->assertSame( 0, $dispatcher->depth(), 'Out of tries = dropped, never retried forever.' );
			$this->assertSame( 'The webhook URL answered 500.', Webhook::state()['lastError'] );
		}

		public function test_non_2xx_is_a_failure() {
			$this->connect();
			$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => 410 ), 'body' => '', 'headers' => array() ) );

			$dispatcher = new Dispatcher( $this->settings() );
			$dispatcher->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array() ) );
			$dispatcher->drain();

			$this->assertCount( 1, $dispatcher->queue() );
			$this->assertSame( 'The webhook URL answered 410.', Webhook::state()['lastError'] );
		}

		/* ---- inert unless connected ----------------------------------------- */

		public function test_disconnected_pipeline_registers_nothing_and_refuses_enqueues() {
			// Defaults: integrations off. No secret either.
			$settings = $this->settings();

			$this->assertFalse( ( new Events( $settings ) )->register(), 'Events stands down.' );
			$this->assertFalse( ( new Dispatcher( $settings ) )->register(), 'Dispatcher stands down.' );
			$this->assertFalse( ( new Dispatcher( $settings ) )->enqueue( 'digest_sent', Events::envelope( 'digest_sent', array() ) ) );
			$this->assertSame( 0, ( new Dispatcher( $settings ) )->depth(), 'Nothing may queue while disconnected.' );
			$this->assertSame( array(), $GLOBALS['_af_cron_events'], 'No cron is ever scheduled while disconnected.' );
			$this->assertFalse( Events::wants_findings_cron( $settings ) );
		}

		public function test_enabled_without_secret_is_still_not_connected() {
			$this->connect();
			delete_option( Webhook::SECRET_OPTION );
			$this->assertFalse( Webhook::connected( $this->settings() ), 'No secret = nothing could be signed = not connected.' );
		}

		public function test_connected_pipeline_arms_and_schedules_the_findings_cron() {
			$this->connect(); // All events ticked, finding_opened included.

			$this->assertTrue( ( new Dispatcher( $this->settings() ) )->register() );
			$this->assertTrue( ( new Events( $this->settings() ) )->register() );

			$hooks = array();
			foreach ( $GLOBALS['_af_cron_events'] as $event ) {
				$hooks[] = $event['hook'];
			}
			$this->assertContains( Events::CRON_FINDINGS, $hooks, 'The daily findings diff gets its schedule.' );
		}

		public function test_findings_cron_not_scheduled_when_its_box_is_unticked() {
			$this->connect( array( 'digest_sent' ) ); // Connected, but not subscribed to findings.

			$this->assertTrue( ( new Events( $this->settings() ) )->register(), 'The other listeners still arm.' );
			$this->assertSame( array(), $GLOBALS['_af_cron_events'], 'No subscriber, no daily Findings build.' );
		}

		public function test_unticked_event_is_not_enqueued() {
			$this->connect( array( 'digest_sent' ) );
			$events = new Events( $this->settings() );

			$events->on_robots_changed( array( 'at' => time(), 'added' => array( 'disallow: /' ), 'removed' => array() ) );

			$this->assertSame( 0, ( new Dispatcher( $this->settings() ) )->depth() );
		}

		public function test_ticked_event_is_enqueued_via_its_listener() {
			$this->connect( array( 'robots_policy_changed' ) );
			$events = new Events( $this->settings() );

			$events->on_robots_changed( array( 'at' => time(), 'added' => array( 'disallow: /' ), 'removed' => array() ) );

			$queue = ( new Dispatcher( $this->settings() ) )->queue();
			$this->assertCount( 1, $queue );
			$this->assertSame( 'robots_policy_changed', $queue[0]['event'] );
			$this->assertSame( array( 'disallow: /' ), $queue[0]['envelope']['data']['added'] );
		}

		public function test_impostor_listener_debounces_per_client() {
			$this->connect( array( 'impostor_flagged' ) );
			$events = new Events( $this->settings() );

			$events->on_impostor_flagged( 'Googlebot' );
			$events->on_impostor_flagged( 'Googlebot' ); // The same crawl, minutes later.
			$events->on_impostor_flagged( 'Bingbot' );

			$queue = ( new Dispatcher( $this->settings() ) )->queue();
			$this->assertCount( 2, $queue, 'One announcement per client per quiet period.' );
			$this->assertSame( 'Googlebot', $queue[0]['envelope']['data']['client'] );
			$this->assertSame( 'Bingbot', $queue[1]['envelope']['data']['client'] );
		}

		/* ---- the findings diff ----------------------------------------------- */

		public function test_finding_identity_folds_the_check_into_config_gaps() {
			$this->assertSame( 'near_page_one', Events::finding_identity( array( 'id' => 'near_page_one' ) ) );
			$this->assertSame( 'config_gap:llms_txt', Events::finding_identity( array( 'id' => 'config_gap', 'check' => 'llms_txt' ) ) );
		}

		public function test_fresh_returns_only_unseen_actionable_rows() {
			$rows = array(
				array( 'id' => 'near_page_one', 'tier' => 'worth', 'title' => 'x' ),
				array( 'id' => 'config_gap', 'check' => 'llms_txt', 'tier' => 'urgent', 'title' => 'y' ),
				array( 'id' => 'near_page_one_waiting', 'tier' => Findings::WAITING, 'title' => 'z' ),
			);

			$fresh = Events::fresh( $rows, array( 'near_page_one' ) );

			$this->assertCount( 1, $fresh, 'Seen rows and waiting rows both stay quiet.' );
			$this->assertSame( 'config_gap:llms_txt', Events::finding_identity( $fresh[0] ) );
		}

		public function test_identities_deduplicates_and_bounds() {
			$rows = array(
				array( 'id' => 'a' ),
				array( 'id' => 'a' ),
				array( 'id' => 'b' ),
			);
			$this->assertSame( array( 'a', 'b' ), Events::identities( $rows ) );
		}

		/* ---- settings: merge safety + validation ------------------------------ */

		public function test_partial_save_without_the_key_keeps_the_stored_connection() {
			$this->connect();

			// A settings save that never heard of integrations (the main form,
			// an add-on's partial write). Must not disconnect the webhook.
			$clean = ( new Settings() )->sanitize( array( 'enable_llms_txt' => true ) );

			$this->assertTrue( $clean['integrations']['webhook_enabled'] );
			$this->assertSame( 'https://hooks.example.test/in', $clean['integrations']['webhook_url'] );
			$this->assertSame( Events::names(), $clean['integrations']['webhook_events'] );
		}

		public function test_sanitize_drops_event_names_the_catalog_never_issued() {
			$clean = ( new Settings() )->sanitize(
				array(
					'integrations' => array(
						'webhook_enabled' => true,
						'webhook_url'     => 'https://hooks.example.test/in',
						'webhook_events'  => array( 'digest_sent', 'made_up_event', 'finding_opened' ),
					),
				)
			);

			$this->assertSame( array( 'digest_sent', 'finding_opened' ), $clean['integrations']['webhook_events'] );
		}

		public function test_sanitize_collapses_enabled_without_a_url() {
			$clean = ( new Settings() )->sanitize(
				array(
					'integrations' => array(
						'webhook_enabled' => true,
						'webhook_url'     => '',
						'webhook_events'  => array( 'digest_sent' ),
					),
				)
			);

			$this->assertFalse( $clean['integrations']['webhook_enabled'], 'A connection with no URL cannot exist.' );
		}

		public function test_integrations_default_is_off_and_empty() {
			$defaults = ( new Settings() )->defaults();
			$this->assertSame(
				array(
					'webhook_enabled'  => false,
					'webhook_url'      => '',
					'webhook_events'   => array(),
					'telegram_enabled' => false,
					'telegram_chat'    => '',
					'telegram_events'  => array(),
					'telegram_tier'    => 'all',
					'slack_enabled'    => false,
					'slack_url'        => '',
					'slack_events'     => array(),
				),
				$defaults['integrations'],
				'Every service defaults to off, empty, unsubscribed — the inertness law starts here.'
			);
		}

		/* ---- the secret ------------------------------------------------------- */

		public function test_secret_is_minted_stored_and_admitted_only_as_existing() {
			$this->assertFalse( Webhook::has_secret() );

			$plain = Webhook::mint_secret();
			$this->assertNotSame( '', $plain );
			$this->assertTrue( Webhook::has_secret() );

			Webhook::forget_secret();
			$this->assertFalse( Webhook::has_secret() );
		}

		public function test_success_clears_the_standing_error() {
			Webhook::record_failure( 'it broke' );
			$this->assertSame( 'it broke', Webhook::state()['lastError'] );

			Webhook::record_success();
			$state = Webhook::state();
			$this->assertSame( '', $state['lastError'] );
			$this->assertSame( 0, $state['lastErrorAt'] );
			$this->assertGreaterThan( 0, $state['lastDeliveredAt'] );
		}
	}
}
