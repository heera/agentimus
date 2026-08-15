<?php
/**
 * LinkedIn — the confidential-client connection, held to promises that differ
 * from X's in exactly the ways the platform does:
 *
 *   1. THE SECRET HAS ONE HOME. begin() stores the client secret in the
 *      connection row and it never appears in the authorize URL; a re-begin
 *      that omits it keeps the stored one, and a first begin without one is
 *      refused with the reason named.
 *   2. THE STATE IS SINGLE-USE, like X's.
 *   3. THE GRANT IS WHOLE OR NOTHING. A completed exchange stores token,
 *      expiry, member URN and name together; an exchange that cannot learn
 *      WHO it speaks as stores no grant at all.
 *   4. SIXTY DAYS END — THEY DO NOT RENEW. LinkedIn grants standard apps no
 *      refresh token, so expiry pauses announcing honestly: sharing_active
 *      goes false, the queue refuses new promises with the reconnect
 *      sentence, and a due row parks as failed carrying the same sentence.
 *   5. THE 3000 IS MEASURED AT THE QUEUE — plain characters, no URL weighing:
 *      LinkedIn counts what is written.
 *   6. LinkedIn speaks in its own words on failure, and the body posts
 *      VERBATIM as the member's own public voice.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Integrations\Announcements;
use Agentimus\Integrations\Connections;
use Agentimus\Integrations\Services\LinkedIn;
use PHPUnit\Framework\TestCase;

final class LinkedInTest extends TestCase {

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
	private function connect( $expires_in = 5184000 ) {
		Connections::store(
			LinkedIn::ID,
			array(
				'client_id'     => 'li-client',
				'client_secret' => 'li-secret',
				'access_token'  => 'li-access-1',
				'expires_at'    => time() + $expires_in,
				'member_urn'    => 'urn:li:person:AbC123',
				'name'          => 'Sheikh Heera',
			)
		);
	}

	private function enable_sharing() {
		$settings = new Settings();
		$all      = $settings->all();

		$all['integrations']['linkedin_share_enabled'] = true;
		$settings->update( $all );
	}

	/* -- The secret has one home ------------------------------------------------- */

	public function test_begin_stores_the_secret_in_the_row_and_keeps_it_out_of_the_url() {
		$url = LinkedIn::begin( 'li-client', 'li-secret' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'response_type=code', $url );
		$this->assertStringContainsString( 'client_id=li-client', $url );
		$this->assertStringContainsString( 'w_member_social', rawurldecode( $url ) );
		$this->assertStringNotContainsString( 'li-secret', $url, 'The secret never travels in a URL.' );

		$row = Connections::read( LinkedIn::ID );
		$this->assertSame( 'li-secret', $row['client_secret'] );
		$this->assertArrayNotHasKey( 'access_token', $row, 'The grant arrives with complete().' );
	}

	public function test_a_re_begin_without_the_secret_keeps_the_stored_one() {
		LinkedIn::begin( 'li-client', 'li-secret' );
		LinkedIn::begin( 'li-client', '' );

		$this->assertSame( 'li-secret', Connections::read( LinkedIn::ID )['client_secret'] );
	}

	public function test_a_first_begin_without_a_secret_is_refused() {
		$verdict = LinkedIn::begin( 'li-client', '  ' );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_linkedin_secret', $verdict->get_error_code() );
	}

	/* -- The state is single-use --------------------------------------------------- */

	public function test_complete_burns_the_state_and_refuses_a_replay() {
		LinkedIn::begin( 'li-client', 'li-secret' );
		$state = $this->minted_state();

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"li-access-1","expires_in":5184000}', 'headers' => array() ),
			array( 'response' => array( 'code' => 200 ), 'body' => '{"sub":"AbC123","name":"Sheikh Heera"}', 'headers' => array() ),
		);
		$this->assertTrue( LinkedIn::complete( 'code-1', $state ) );
		$this->assertTrue( LinkedIn::connected() );
		$this->assertSame( 'urn:li:person:AbC123', LinkedIn::connection()['member_urn'] );
		$this->assertSame( 'Sheikh Heera', LinkedIn::connection()['name'] );
		$this->assertFalse( LinkedIn::expired(), 'Sixty fresh days.' );

		$replay = LinkedIn::complete( 'code-1', $state );
		$this->assertInstanceOf( \WP_Error::class, $replay );
		$this->assertSame( 'agentimus_linkedin_state', $replay->get_error_code() );
	}

	/* -- The grant is whole or nothing ----------------------------------------------- */

	public function test_a_refused_exchange_stores_no_grant_and_linkedins_words_travel() {
		LinkedIn::begin( 'li-client', 'li-secret' );
		$state = $this->minted_state();

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 400 ), 'body' => '{"error":"invalid_grant","error_description":"The provided authorization grant is invalid."}', 'headers' => array() ),
		);
		$verdict = LinkedIn::complete( 'bad-code', $state );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertStringContainsString( 'authorization grant', $verdict->get_error_message() );
		$this->assertFalse( LinkedIn::connected() );
	}

	public function test_an_exchange_that_cannot_learn_the_member_stores_no_grant() {
		LinkedIn::begin( 'li-client', 'li-secret' );
		$state = $this->minted_state();

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"li-access-1","expires_in":5184000}', 'headers' => array() ),
			array( 'response' => array( 'code' => 403 ), 'body' => '{"message":"Not enough permissions"}', 'headers' => array() ),
		);
		$verdict = LinkedIn::complete( 'code-1', $state );

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_linkedin_identity', $verdict->get_error_code() );
		$this->assertFalse( LinkedIn::connected(), 'A token with no author is no grant.' );
	}

	/* -- Sixty days end, they do not renew --------------------------------------------- */

	public function test_expiry_pauses_the_sharing_use() {
		$this->enable_sharing();
		$this->connect( 5184000 );
		$this->assertTrue( LinkedIn::sharing_active( new Settings() ) );

		$this->connect( -10 ); // The same grant, sixty days later.
		$this->assertTrue( LinkedIn::connected(), 'The grant still EXISTS — the card says reconnect, not connect.' );
		$this->assertTrue( LinkedIn::expired() );
		$this->assertFalse( LinkedIn::sharing_active( new Settings() ) );
	}

	public function test_the_queue_refuses_an_expired_grant_with_the_reconnect_sentence() {
		$this->enable_sharing();
		$this->connect( -10 );

		$verdict = ( new Announcements( new Settings() ) )->queue(
			array( 'network' => 'linkedin', 'body' => 'Hello.', 'at' => time() + 3600 )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertStringContainsString( 'reconnect', $verdict->get_error_message() );
	}

	public function test_a_due_row_meeting_an_expired_grant_parks_with_the_reconnect_sentence() {
		$this->enable_sharing();
		$this->connect();
		$engine = new Announcements( new Settings() );

		$id = $engine->queue( array( 'network' => 'linkedin', 'body' => 'Hello.', 'at' => time() ) );
		$this->assertIsInt( $id );

		$this->connect( -10 ); // The sixty days run out while the row waits.
		$GLOBALS['_af_http_last'] = null;
		$engine->dispatch();

		$rows = get_option( Announcements::OPTION );
		$this->assertSame( 'failed', $rows[ $id ]['status'] );
		$this->assertStringContainsString( 'reconnect', $rows[ $id ]['error'] );
		$this->assertNull( $GLOBALS['_af_http_last'], 'Nothing went on the wire with a dead grant.' );
	}

	/* -- The 3000 is measured at the queue ------------------------------------------------ */

	public function test_the_queue_refuses_a_draft_over_three_thousand_whole() {
		$this->enable_sharing();
		$this->connect();

		$verdict = ( new Announcements( new Settings() ) )->queue(
			array( 'network' => 'linkedin', 'body' => str_repeat( 'y', 3001 ), 'at' => time() + 3600 )
		);

		$this->assertInstanceOf( \WP_Error::class, $verdict );
		$this->assertSame( 'agentimus_announce_long', $verdict->get_error_code() );
		$this->assertStringContainsString( '3001', $verdict->get_error_message() );
		$this->assertArrayNotHasKey( Announcements::OPTION, $GLOBALS['_af_options'], 'Refused whole, nothing half-stored.' );
	}

	public function test_urls_carry_no_special_weight_on_linkedin() {
		$this->enable_sharing();
		$this->connect();

		// 2990 letters + a space + a 9-character URL = 3000 exactly: lawful.
		$verdict = ( new Announcements( new Settings() ) )->queue(
			array( 'network' => 'linkedin', 'body' => str_repeat( 'y', 2990 ) . ' https://h', 'at' => time() + 3600 )
		);

		$this->assertIsInt( $verdict );
	}

	/* -- Verbatim on the wire; LinkedIn's own words on failure ------------------------------ */

	public function test_a_lawful_draft_queues_and_posts_as_the_member() {
		$this->enable_sharing();
		$this->connect();
		$engine = new Announcements( new Settings() );

		$id = $engine->queue( array( 'network' => 'linkedin', 'body' => 'Agentimus 1.37 is out.', 'at' => time() ) );
		$this->assertIsInt( $id );

		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 201 ), 'body' => '{"id":"urn:li:share:1"}', 'headers' => array() ),
		);
		$engine->dispatch();

		$rows = get_option( Announcements::OPTION );
		$this->assertSame( 'sent', $rows[ $id ]['status'] );

		$wire = json_decode( $GLOBALS['_af_http_last']['args']['body'], true );
		$this->assertSame( 'urn:li:person:AbC123', $wire['author'], 'Authored as the member the grant named.' );
		$this->assertSame( 'Agentimus 1.37 is out.', $wire['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'], 'Verbatim, as queued.' );
		$this->assertSame( 'PUBLIC', $wire['visibility']['com.linkedin.ugc.MemberNetworkVisibility'] );
	}

	public function test_a_failed_post_parks_with_linkedins_words() {
		$this->enable_sharing();
		$this->connect();
		$engine = new Announcements( new Settings() );

		$id = $engine->queue( array( 'network' => 'linkedin', 'body' => 'x', 'at' => time() ) );
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 422 ), 'body' => '{"message":"Content is a duplicate of a recent post","serviceErrorCode":100}', 'headers' => array() ),
		);
		$engine->dispatch();

		$rows = get_option( Announcements::OPTION );
		$this->assertSame( 'failed', $rows[ $id ]['status'] );
		$this->assertStringContainsString( 'LinkedIn answered 422', $rows[ $id ]['error'] );
		$this->assertStringContainsString( 'duplicate', $rows[ $id ]['error'] );
	}

	public function test_sharing_active_needs_the_switch_the_grant_and_the_days() {
		$this->assertFalse( LinkedIn::sharing_active( new Settings() ) );
		$this->enable_sharing();
		$this->assertFalse( LinkedIn::sharing_active( new Settings() ), 'A switch with no grant is not active.' );
		$this->connect();
		$this->assertTrue( LinkedIn::sharing_active( new Settings() ) );
		LinkedIn::forget();
		$this->assertFalse( LinkedIn::sharing_active( new Settings() ), 'The grant leaving halts the use.' );
	}

	/* -- Helpers -------------------------------------------------------------------------------- */

	/** The state minted by the last begin() — read from the transient shim. */
	private function minted_state() {
		foreach ( array_keys( $GLOBALS['_af_transients'] ) as $key ) {
			if ( 0 === strpos( (string) $key, LinkedIn::STATE_TRANSIENT ) ) {
				return substr( (string) $key, strlen( LinkedIn::STATE_TRANSIENT ) );
			}
		}
		$this->fail( 'No oauth state was minted.' );
	}
}
