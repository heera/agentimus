<?php
/**
 * X (Twitter) — the first OAuth connection, held to promises of its own:
 *
 *   1. NO SECRET, EVER. The flow is PKCE for a public client: begin() takes
 *      only the client id, the challenge is derived, and no field anywhere
 *      stores or transmits a client secret.
 *   2. THE STATE IS SINGLE-USE. A completed (or failed) exchange burns it;
 *      a replayed or expired state is refused with the road named.
 *   3. ROTATION IS SURVIVAL. X rotates the refresh token on renewal — the
 *      new one must be stored; when X omits it, the old one is kept.
 *   4. A REFUSED RENEWAL PAUSES, NEVER VANISHES. The refusal is written on
 *      the connection, and announce() hands it to the queue as the row's
 *      honest error.
 *   5. THE 280 IS WEIGHED AT THE QUEUE. URLs count 23 whatever their length;
 *      an over-weight draft is refused whole before it is promised.
 *   6. X speaks in its own words on failure, and the disconnect revokes at
 *      X before forgetting — best effort, never blocking.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Announcements;
use Agentimus\Integrations\Connections;
use Agentimus\Integrations\Services\Twitter;
use PHPUnit\Framework\TestCase;

final class TwitterTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_cron_events']   = array();
		// The transient shim is opt-in; the oauth state rides transients.
		$GLOBALS['_af_transients_on'] = true;
	}

	protected function tearDown(): void {
		\_af_reset_options();
		unset( $GLOBALS['_af_cron_events'] );
	}

	/** A standing grant, as a completed exchange would have stored it. */
	private function connect( $expires_in = 7200 ) {
		Connections::store(
			Twitter::ID,
			array(
				'client_id'     => 'client-abc',
				'access_token'  => 'access-1',
				'refresh_token' => 'refresh-1',
				'expires_at'    => time() + $expires_in,
				'handle'        => 'heera_it',
				'refresh_error' => '',
			)
		);
	}

	private function enable_sharing() {
		$settings                               = new Settings();
		$all                                    = $settings->all();
		$all['integrations']['x_share_enabled'] = true;
		$settings->update( $all );
	}

	/* -- No secret, ever -------------------------------------------------------- */

	public function test_begin_builds_a_pkce_authorize_url_and_stores_only_the_client_id() {
		$url = Twitter::begin( 'client-abc' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'code_challenge_method=S256', $url );
		$this->assertStringContainsString( 'code_challenge=', $url );
		$this->assertStringContainsString( 'client_id=client-abc', $url );
		$this->assertStringNotContainsString( 'client_secret', $url );

		$row = Connections::read( Twitter::ID );
		$this->assertSame( array( 'client_id' => 'client-abc' ), $row, 'Only the id — the grant arrives with complete().' );
	}

	public function test_begin_without_a_client_id_is_refused() {
		$verdict = Twitter::begin( '  ' );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_x_client', $verdict->get_error_code() );
	}

	/* -- The state is single-use --------------------------------------------------- */

	public function test_complete_burns_the_state_and_refuses_a_replay() {
		Twitter::begin( 'client-abc' );
		$state = $this->minted_state();

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"access-1","refresh_token":"refresh-1","expires_in":7200}', 'headers' => array() ),
			array( 'response' => array( 'code' => 200 ), 'body' => '{"data":{"username":"heera_it"}}', 'headers' => array() ),
		);
		$this->assertTrue( Twitter::complete( 'code-1', $state ) );
		$this->assertSame( 'heera_it', Twitter::connection()['handle'] );
		$this->assertTrue( Twitter::connected() );

		$replay = Twitter::complete( 'code-1', $state );
		$this->assertInstanceOf( \WP_Error::class, $replay );
		$this->assertSame( 'agentimus_x_state', $replay->get_error_code() );
	}

	public function test_a_refused_exchange_stores_no_grant() {
		Twitter::begin( 'client-abc' );
		$state = $this->minted_state();

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 400 ), 'body' => '{"error":"invalid_request","error_description":"Value passed for the authorization code was invalid."}', 'headers' => array() ),
		);
		$verdict = Twitter::complete( 'bad-code', $state );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertStringContainsString( 'authorization code', $verdict->get_error_message(), 'X’s own words travel.' );
		$this->assertFalse( Twitter::connected() );
	}

	/* -- Rotation is survival -------------------------------------------------------- */

	public function test_renewal_stores_the_rotated_refresh_token() {
		$this->connect( 10 ); // Nearly expired: the next token ask renews.

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"access-2","refresh_token":"refresh-2","expires_in":7200}', 'headers' => array() ),
		);
		$token = Twitter::access_token();

		$this->assertSame( 'access-2', $token );
		$this->assertSame( 'refresh-2', Twitter::connection()['refresh_token'], 'The rotated token IS the connection now.' );
	}

	public function test_a_renewal_answer_without_a_refresh_token_keeps_the_old_one() {
		$this->connect( 10 );

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"access-2","expires_in":7200}', 'headers' => array() ),
		);
		Twitter::access_token();

		$this->assertSame( 'refresh-1', Twitter::connection()['refresh_token'] );
	}

	public function test_a_fresh_access_token_is_used_without_a_renewal() {
		$this->connect( 7200 );
		$GLOBALS['_af_http_last'] = null;

		$this->assertSame( 'access-1', Twitter::access_token() );
		$this->assertNull( $GLOBALS['_af_http_last'], 'Two good hours left — nothing on the wire.' );
	}

	/* -- A refused renewal pauses, never vanishes ---------------------------------------- */

	public function test_a_refused_renewal_is_written_on_the_connection() {
		$this->connect( 10 );

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 400 ), 'body' => '{"error":"invalid_request","error_description":"Refresh token has been revoked."}', 'headers' => array() ),
		);
		$verdict = Twitter::access_token();

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertStringContainsString( 'revoked', Twitter::connection()['refresh_error'], 'The card can say why announcing paused.' );
	}

	/* -- The 280 is weighed at the queue --------------------------------------------------- */

	public function test_tweet_length_weighs_every_url_at_twenty_three() {
		$this->assertSame( 23, Twitter::tweet_length( 'https://heera.it/a-very-long-address-that-would-never-fit-in-twenty-three-characters' ) );
		$this->assertSame( 5 + 1 + 23, Twitter::tweet_length( 'Hello https://heera.it/post' ) );
		$this->assertSame( 3, Twitter::tweet_length( 'abc' ) );
	}

	public function test_the_queue_refuses_an_overweight_draft_whole() {
		$this->connect();
		$this->enable_sharing();

		$verdict = ( new Announcements( new Settings() ) )->queue(
			array(
				'network' => 'x',
				'body'    => str_repeat( 'y', 281 ),
				'at'      => time() + 3600,
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_announce_long', $verdict->get_error_code() );
		$this->assertStringContainsString( '281', $verdict->get_error_message(), 'The sentence carries the draft’s own weight.' );
		$this->assertArrayNotHasKey( Announcements::OPTION, $GLOBALS['_af_options'], 'Refused whole, nothing half-stored.' );
	}

	public function test_a_lawful_draft_queues_and_sends_to_x() {
		$this->connect();
		$this->enable_sharing();
		$engine = new Announcements( new Settings() );

		$id = $engine->queue( array( 'network' => 'x', 'body' => 'Agentimus 1.37 is out.', 'at' => time() ) );
		$this->assertIsInt( $id );

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 201 ), 'body' => '{"data":{"id":"1"}}', 'headers' => array() ),
		);
		$engine->dispatch();

		$rows = get_option( Announcements::OPTION );
		$this->assertSame( 'sent', $rows[ $id ]['status'] );
		$wire = json_decode( $GLOBALS['_af_http_last']['args']['body'], true );
		$this->assertSame( 'Agentimus 1.37 is out.', $wire['text'], 'Verbatim, as queued.' );
	}

	/* -- X speaks in its own words; the disconnect revokes ---------------------------------- */

	public function test_a_failed_post_parks_with_xs_words() {
		$this->connect();
		$this->enable_sharing();
		$engine = new Announcements( new Settings() );

		$id = $engine->queue( array( 'network' => 'x', 'body' => 'x', 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 429 ), 'body' => '{"title":"Too Many Requests","detail":"Too Many Requests"}', 'headers' => array() ),
		);
		$engine->dispatch();

		$rows = get_option( Announcements::OPTION );
		$this->assertSame( 'failed', $rows[ $id ]['status'] );
		$this->assertStringContainsString( 'X answered 429', $rows[ $id ]['error'] );
	}

	public function test_sharing_active_needs_both_the_switch_and_the_grant() {
		$this->assertFalse( Twitter::sharing_active( new Settings() ) );
		$this->enable_sharing();
		$this->assertFalse( Twitter::sharing_active( new Settings() ), 'A switch with no grant is not active.' );
		$this->connect();
		$this->assertTrue( Twitter::sharing_active( new Settings() ) );
		Twitter::forget();
		$this->assertFalse( Twitter::sharing_active( new Settings() ), 'The grant leaving halts the use.' );
	}

	/* -- Helpers -------------------------------------------------------------------------------- */

	/** The state minted by the last begin() — read from the transient shim. */
	private function minted_state() {
		foreach ( array_keys( $GLOBALS['_af_transients'] ) as $key ) {
			if ( 0 === strpos( (string) $key, Twitter::STATE_TRANSIENT ) ) {
				return substr( (string) $key, strlen( Twitter::STATE_TRANSIENT ) );
			}
		}
		$this->fail( 'No oauth state was minted.' );
	}
}
