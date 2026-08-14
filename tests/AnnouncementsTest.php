<?php
/**
 * The announcements queue — the laws that make scheduled public speech safe:
 *
 *   1. REFUSED WHOLE. A row that can only fail is never accepted: an unknown
 *      network, a switched-off sharing use, an empty body — each refusal is
 *      one plain sentence, and nothing half-stores.
 *   2. AT ITS TIME. The sweep sends what is due and leaves the future alone;
 *      a moment already behind us means now, and sent_at records when it
 *      REALLY went.
 *   3. VERBATIM. The body posts exactly as queued — the approved draft is the
 *      contract, and nothing re-renders at send time.
 *   4. NO RETRY WITHOUT A HAND. One scheduled send, one attempt; a failure
 *      parks with the network's own words and waits for retry() — the queue
 *      never re-books it by itself. Twice-posted is spam; late-but-deliberate
 *      is not.
 *   5. THE VERBS KEEP THEIR LANES. cancel() is queued-only, retry() is
 *      failed-only, remove() is finished-only — a verb crossing lanes would
 *      blur what each promises.
 *   6. A PROMISE IS NOT HISTORY. The cap drops the oldest FINISHED rows and
 *      cannot touch a queued one.
 *   7. INERT UNTIL OWED. register() books not one hook while nothing is
 *      queued — and re-arms a schedule WP-cron lost when something is.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Announcements;
use Agentimus\Integrations\Rest;
use Agentimus\Integrations\Services\Telegram;
use PHPUnit\Framework\TestCase;

final class AnnouncementsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_cron_events'] = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_cron_events'] );
	}

	/** Announcing by Telegram, switched on whole: token + channel + enable. */
	private function arm_telegram() {
		Telegram::store_token( '111:abc' );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/integrations' );
		foreach ( array( 'service' => 'telegram', 'action' => 'share', 'enabled' => true, 'channel' => '@heera_updates' ) as $k => $v ) {
			$request->set_param( $k, $v );
		}
		( new Rest( new Settings() ) )->act( $request );
		$GLOBALS['_af_http_last'] = null;
	}

	private function engine() {
		return new Announcements( new Settings() );
	}

	/* -- Refused whole ------------------------------------------------------------ */

	public function test_an_unknown_network_is_refused() {
		$verdict = $this->engine()->queue( array( 'network' => 'myspace', 'body' => 'hello', 'at' => time() ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_announce_network', $verdict->get_error_code() );
		$this->assertArrayNotHasKey( Announcements::OPTION, $GLOBALS['_af_options'], 'A refusal stores nothing.' );
	}

	public function test_a_switched_off_sharing_use_is_refused_with_the_road_named() {
		$verdict = $this->engine()->queue( array( 'network' => 'telegram', 'body' => 'hello', 'at' => time() ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_announce_off', $verdict->get_error_code() );
		$this->assertStringContainsString( 'Sharing', $verdict->get_error_message(), 'The sentence points at the screen that fixes it.' );
	}

	public function test_an_empty_body_is_refused() {
		$this->arm_telegram();

		$verdict = $this->engine()->queue( array( 'network' => 'telegram', 'body' => "  \n ", 'at' => time() ) );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_announce_empty', $verdict->get_error_code() );
	}

	/* -- At its time ----------------------------------------------------------------- */

	public function test_a_future_row_waits_and_a_due_row_goes() {
		$this->arm_telegram();
		$engine = $this->engine();

		$due    = $engine->queue( array( 'network' => 'telegram', 'body' => 'now', 'at' => time() - 10 ) );
		$future = $engine->queue( array( 'network' => 'telegram', 'body' => 'later', 'at' => time() + 3600 ) );
		$this->assertIsInt( $due );
		$this->assertIsInt( $future );

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$engine->dispatch();

		$rows = get_option( Announcements::OPTION );
		$this->assertSame( 'sent', $rows[ $due ]['status'] );
		$this->assertGreaterThan( 0, $rows[ $due ]['sent_at'], 'sent_at records when it really went.' );
		$this->assertSame( 'queued', $rows[ $future ]['status'], 'The future is left alone.' );
	}

	public function test_a_past_moment_means_now_not_never() {
		$this->arm_telegram();

		$id   = $this->engine()->queue( array( 'network' => 'telegram', 'body' => 'x', 'at' => 1000 ) );
		$rows = get_option( Announcements::OPTION );

		$this->assertGreaterThanOrEqual( time() - 5, $rows[ $id ]['scheduled_at'], 'A behind-us moment is clamped to now.' );
	}

	/* -- Verbatim ------------------------------------------------------------------------ */

	public function test_the_body_posts_exactly_as_queued() {
		$this->arm_telegram();
		$engine = $this->engine();
		$body   = "Agentimus 1.36.0 is out.\n\nhttps://heera.it/agentimus-1-36-0";

		$id = $engine->queue( array( 'network' => 'telegram', 'body' => $body, 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$engine->dispatch();

		$wire = json_decode( $GLOBALS['_af_http_last']['args']['body'], true );
		$this->assertSame( $body, $wire['text'], 'The approved draft is the contract.' );
		$this->assertSame( '@heera_updates', $wire['chat_id'], 'It speaks in the sharing room, not the events chat.' );
		$this->assertIsInt( $id );
	}

	/* -- No retry without a hand ------------------------------------------------------------ */

	public function test_a_failure_parks_with_the_networks_words_and_is_not_rebooked() {
		$this->arm_telegram();
		$engine = $this->engine();

		$id = $engine->queue( array( 'network' => 'telegram', 'body' => 'x', 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 429 ), 'body' => '{"ok":false,"description":"Too Many Requests: retry after 30"}', 'headers' => array() ),
		);
		$GLOBALS['_af_cron_events'] = array();
		$engine->dispatch();

		$rows = get_option( Announcements::OPTION );
		$this->assertSame( 'failed', $rows[ $id ]['status'] );
		$this->assertStringContainsString( 'Too Many Requests', $rows[ $id ]['error'], 'The network’s own words, the ones the owner can act on.' );
		$this->assertSame( array(), $GLOBALS['_af_cron_events'], 'No tick is booked for a failure — the next move is the owner’s.' );
	}

	public function test_retry_is_the_hand_and_it_requeues_for_now() {
		$this->arm_telegram();
		$engine = $this->engine();

		$id = $engine->queue( array( 'network' => 'telegram', 'body' => 'x', 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 500 ), 'body' => '{}', 'headers' => array() ),
		);
		$engine->dispatch();

		$this->assertTrue( $engine->retry( $id ) );
		$rows = get_option( Announcements::OPTION );
		$this->assertSame( 'queued', $rows[ $id ]['status'] );
		$this->assertSame( '', $rows[ $id ]['error'], 'The old error leaves with the new promise.' );

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$engine->dispatch();
		$this->assertSame( 'sent', get_option( Announcements::OPTION )[ $id ]['status'] );
	}

	/* -- The verbs keep their lanes ---------------------------------------------------------- */

	public function test_cancel_is_queued_only_and_keeps_nothing() {
		$this->arm_telegram();
		$engine = $this->engine();

		$id = $engine->queue( array( 'network' => 'telegram', 'body' => 'x', 'at' => time() + 3600 ) );
		$this->assertTrue( $engine->cancel( $id ) );
		$this->assertArrayNotHasKey( Announcements::OPTION, $GLOBALS['_af_options'], 'Cancelled means it never happened — and the option leaves with its last row.' );

		$sent = $engine->queue( array( 'network' => 'telegram', 'body' => 'x', 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$engine->dispatch();
		$this->assertInstanceOf( \WP_Error::class, $engine->cancel( $sent ), 'A sent row is history, not a promise — cancel refuses.' );
	}

	public function test_retry_and_remove_refuse_the_wrong_lanes() {
		$this->arm_telegram();
		$engine = $this->engine();

		$queued = $engine->queue( array( 'network' => 'telegram', 'body' => 'x', 'at' => time() + 3600 ) );

		$this->assertInstanceOf( \WP_Error::class, $engine->retry( $queued ), 'Retry is failed-only.' );
		$this->assertInstanceOf( \WP_Error::class, $engine->remove( $queued ), 'Remove is finished-only.' );
		$this->assertInstanceOf( \WP_Error::class, $engine->retry( 999 ), 'A row that never was.' );
	}

	public function test_remove_takes_a_finished_row_out_of_the_ledger() {
		$this->arm_telegram();
		$engine = $this->engine();

		$id = $engine->queue( array( 'network' => 'telegram', 'body' => 'x', 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$engine->dispatch();

		$this->assertTrue( $engine->remove( $id ) );
		$this->assertArrayNotHasKey( Announcements::OPTION, $GLOBALS['_af_options'] );
	}

	/* -- A promise is not history ----------------------------------------------------------------- */

	public function test_the_cap_drops_the_oldest_finished_and_never_a_queued_row() {
		$this->arm_telegram();
		$engine = $this->engine();

		// A full ledger of finished rows, oldest first, plus one queued promise.
		$rows = array();
		for ( $i = 1; $i <= Announcements::HISTORY_MAX + 5; $i++ ) {
			$rows[ $i ] = array(
				'id'           => $i,
				'post_id'      => 0,
				'network'      => 'telegram',
				'body'         => 'x',
				'scheduled_at' => 1000 + $i,
				'created_at'   => 1000 + $i,
				'status'       => 'sent',
				'sent_at'      => 1000 + $i,
				'error'        => '',
			);
		}
		$promise          = Announcements::HISTORY_MAX + 100;
		$rows[ $promise ] = array(
			'id'           => $promise,
			'post_id'      => 0,
			'network'      => 'telegram',
			'body'         => 'the promise',
			'scheduled_at' => 1, // Ancient — and still untouchable.
			'created_at'   => 1,
			'status'       => 'queued',
			'sent_at'      => 0,
			'error'        => '',
		);
		update_option( Announcements::OPTION, $rows, false );

		// The queued row is due (its moment is long past) — the sweep sends it,
		// then caps the ledger.
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$engine->dispatch();

		$kept = get_option( Announcements::OPTION );
		$this->assertArrayHasKey( $promise, $kept, 'The promise survived the cap and went out.' );
		$this->assertSame( 'sent', $kept[ $promise ]['status'] );
		$this->assertLessThanOrEqual( Announcements::HISTORY_MAX, count( $kept ) - 0, 'The ledger holds the cap.' );
		$this->assertArrayNotHasKey( 1, $kept, 'The oldest finished row left first.' );
	}

	/* -- Inert until owed ----------------------------------------------------------------------------- */

	public function test_register_books_nothing_while_nothing_is_queued() {
		$this->engine()->register();

		$this->assertSame( array(), $GLOBALS['_af_cron_events'], 'Not one hook until a row is owed.' );
	}

	public function test_register_rearms_a_schedule_cron_lost() {
		$this->arm_telegram();
		$this->engine()->queue( array( 'network' => 'telegram', 'body' => 'x', 'at' => time() + 3600 ) );

		// The shim's wp_next_scheduled() is always false — exactly the lost-
		// schedule world register() must heal.
		$GLOBALS['_af_cron_events'] = array();
		$this->engine()->register();

		$this->assertCount( 1, $GLOBALS['_af_cron_events'], 'The lost tick is re-booked.' );
		$this->assertSame( Announcements::CRON, $GLOBALS['_af_cron_events'][0]['hook'] );
	}

	/* -- The glance ---------------------------------------------------------------------------------------- */

	public function test_summary_counts_the_ledger_at_a_glance() {
		$this->arm_telegram();
		$engine = $this->engine();

		$engine->queue( array( 'network' => 'telegram', 'body' => 'soon', 'at' => time() + 3600 ) );
		$sent = $engine->queue( array( 'network' => 'telegram', 'body' => 'now', 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$engine->dispatch();

		// An old sent row and a failed one, planted directly: sentWeek must
		// count only the fresh sends, failed must count regardless of age.
		$rows      = get_option( Announcements::OPTION );
		$rows[900] = array( 'id' => 900, 'post_id' => 0, 'network' => 'telegram', 'body' => 'old', 'scheduled_at' => time() - 700000, 'created_at' => time() - 700000, 'status' => 'sent', 'sent_at' => time() - 700000, 'error' => '' );
		$rows[901] = array( 'id' => 901, 'post_id' => 0, 'network' => 'telegram', 'body' => 'bad', 'scheduled_at' => time() - 100, 'created_at' => time() - 100, 'status' => 'failed', 'sent_at' => 0, 'error' => 'nope' );
		update_option( Announcements::OPTION, $rows, false );

		$summary = $engine->summary();

		$this->assertSame( 4, $summary['total'] );
		$this->assertSame( 1, $summary['queued'] );
		$this->assertSame( 1, $summary['sentWeek'], 'The week window keeps out the old send.' );
		$this->assertSame( 1, $summary['failed'] );
		$this->assertSame( 1, $summary['networks']['telegram']['queued'] );
		$this->assertGreaterThan( time() - 10, $summary['networks']['telegram']['lastSentAt'], 'The freshest send is the one named.' );
		$this->assertIsInt( $sent );
	}

	public function test_post_network_state_is_the_cards_memory() {
		$this->arm_telegram();
		$engine = $this->engine();

		$sent = $engine->queue( array( 'network' => 'telegram', 'post_id' => 7, 'body' => 'x', 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$engine->dispatch();
		$queued = $engine->queue( array( 'network' => 'telegram', 'post_id' => 7, 'body' => 'y', 'at' => time() + 3600 ) );
		$engine->queue( array( 'network' => 'telegram', 'post_id' => 8, 'body' => 'z', 'at' => time() + 3600 ) );

		$state = $engine->post_network_state( 7, 'telegram' );
		$this->assertGreaterThan( 0, $state['lastSentAt'] );
		$this->assertGreaterThan( time(), $state['queuedAt'], 'The soonest queued promise, this post\'s own.' );
		$this->assertFalse( $state['failed'] );

		$other = $engine->post_network_state( 9, 'telegram' );
		$this->assertSame( array( 'queuedAt' => 0, 'lastSentAt' => 0, 'failed' => false ), $other, 'A post with no history says nothing.' );
		$this->assertIsInt( $sent );
		$this->assertIsInt( $queued );
	}

	/* -- The Share tab's door (REST queue action) --------------------------------------------------------- */

	public function test_the_rest_queue_action_stores_the_approved_draft() {
		$this->arm_telegram();

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/announcements' );
		foreach ( array( 'action' => 'queue', 'network' => 'telegram', 'post_id' => 42, 'body' => 'The draft, as approved.', 'at' => time() + 3600 ) as $k => $v ) {
			$request->set_param( $k, $v );
		}
		$verdict = ( new Rest( new Settings() ) )->announcements_act( $request );

		$this->assertNotInstanceOf( \WP_Error::class, $verdict );
		$rows = get_option( Announcements::OPTION );
		$row  = array_shift( $rows );
		$this->assertSame( 'The draft, as approved.', $row['body'] );
		$this->assertSame( 42, $row['post_id'] );
		$this->assertSame( 'queued', $row['status'] );
	}

	public function test_the_rest_queue_action_passes_the_engines_refusal_through() {
		// No sharing use armed: the refusal names the road, with a 400.
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/announcements' );
		foreach ( array( 'action' => 'queue', 'network' => 'telegram', 'body' => 'x', 'at' => time() ) as $k => $v ) {
			$request->set_param( $k, $v );
		}
		$verdict = ( new Rest( new Settings() ) )->announcements_act( $request );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_announce_off', $verdict->get_error_code() );
	}

	/* -- The screen's read ------------------------------------------------------------------------------- */

	public function test_rows_pages_promises_first_then_freshest_history() {
		$this->arm_telegram();
		$engine = $this->engine();

		$old_sent = $engine->queue( array( 'network' => 'telegram', 'body' => 'old', 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}', 'headers' => array() ),
		);
		$engine->dispatch();

		$soon  = $engine->queue( array( 'network' => 'telegram', 'body' => 'soon', 'at' => time() + 100 ) );
		$later = $engine->queue( array( 'network' => 'telegram', 'body' => 'later', 'at' => time() + 9000 ) );

		$page = $engine->rows( 1, 2 );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( array( $soon, $later ), array_column( $page['rows'], 'id' ), 'Promises first, soonest on top.' );

		$page2 = $engine->rows( 2, 2 );
		$this->assertSame( array( $old_sent ), array_column( $page2['rows'], 'id' ), 'History follows, freshest first.' );
	}
}
