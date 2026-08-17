<?php
/**
 * Reachability — does an address we advertise as open really open for a stranger?
 *
 * The rules these tests hold the checker to, in the order they matter:
 *
 *   1. it NEVER records a verdict unless it really was nobody who asked;
 *   2. a real anonymous request outranks the code's own opinion, both ways;
 *   3. an address it could not establish is not advertised — but one it has
 *      never LOOKED at still is, because not having looked is not evidence;
 *   4. an inconclusive re-check never un-advertises something an earlier run
 *      proved;
 *   5. one plugin's mess costs its own address and nobody else's.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Reachability;
use PHPUnit\Framework\TestCase;

final class ReachabilityTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		\_af_reset_registry();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		\_af_reset_registry();
	}

	/* -- Helpers ------------------------------------------------------------ */

	/** Seed the route table with one GET route and its permission callback. */
	private function route( $route, $permission ) {
		$GLOBALS['_af_rest_routes'] = array(
			$route => array(
				array( 'methods' => array( 'GET' => true ), 'permission_callback' => $permission ),
			),
		);
	}

	/** Queue one HTTP answer for the loopback request. */
	private function http( $code ) {
		$GLOBALS['_af_http_queue'] = array( array( 'response' => array( 'code' => $code ), 'body' => '{}', 'headers' => array() ) );
	}

	private function verdict_for( $url ) {
		return Reachability::record( $url );
	}

	/* -- 1. The guard the whole class rests on ------------------------------ */

	/**
	 * ⛔ THE ONE THAT MATTERS MOST. "Can a stranger read this" is only answerable
	 * by a stranger. A run started while somebody is signed in would answer for
	 * THAT person and publish their access as everyone's — so it records nothing
	 * at all and says why.
	 */
	public function test_it_refuses_to_answer_while_somebody_is_signed_in() {
		$GLOBALS['_af_current_user_id'] = 7;
		$this->route( '/acme/v1/things', '__return_true' );

		$data = Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$this->assertSame( array(), $data['addresses'], 'Not one verdict may be recorded.' );
		$this->assertSame( 7, $data['ran_as'] );
		$this->assertNotSame( '', $data['error'], 'And it must say why it recorded nothing.' );
		$this->assertNull( $this->verdict_for( '/wp-json/acme/v1/things' ) );
	}

	/** ⛔ And it never fakes it: nobody's identity is switched to get an answer. */
	public function test_it_does_not_switch_the_current_user_to_get_its_answer() {
		$GLOBALS['_af_current_user_id'] = 7;
		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );
		$this->assertSame( 7, (int) $GLOBALS['_af_current_user_id'], 'The signed-in user is left exactly as they were.' );
	}

	/* -- 2. The rungs, and which one wins ----------------------------------- */

	public function test_a_route_declared_open_is_public_without_calling_anything() {
		$this->route( '/acme/v1/things', '__return_true' );
		$this->http( 0 ); // Loopback unavailable — the declaration alone is enough.
		$GLOBALS['_af_http_queue'] = array( new \WP_Error( 'http_request_failed', 'no route to host' ) );

		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$v = $this->verdict_for( '/wp-json/acme/v1/things' );
		$this->assertSame( 'public', $v['state'] );
		$this->assertSame( 'declared-open', $v['why'] );
		$this->assertTrue( Reachability::may_advertise( '/wp-json/acme/v1/things' ) );
	}

	public function test_the_routes_own_permission_check_is_asked_when_it_is_not_a_declaration() {
		$this->route( '/acme/v1/things', '__return_false' );
		$GLOBALS['_af_http_queue'] = array( new \WP_Error( 'http_request_failed', 'blocked' ) );

		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$v = $this->assertRefused( '/wp-json/acme/v1/things' );
		$this->assertSame( 'refused-a-stranger', $v['why'] );
	}

	/**
	 * ⭐ A real anonymous 200 IS the definition of public, whatever the code
	 * thinks. A callback can refuse a request it was handed without the params it
	 * expects; a stranger who really got the data is not a theory.
	 */
	public function test_a_real_answer_outranks_a_refusing_callback() {
		$this->route( '/acme/v1/things', '__return_false' );
		$this->http( 200 );

		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$v = $this->verdict_for( '/wp-json/acme/v1/things' );
		$this->assertSame( 'public', $v['state'] );
		$this->assertSame( 'answered', $v['why'] );
	}

	/**
	 * ⭐ And the other way round, which is the case that protects a site: a
	 * security plugin or a login wall refuses a route whose own callback would
	 * have let anyone in. Only the real request can see that.
	 */
	public function test_a_real_refusal_outranks_an_allowing_callback() {
		$this->route( '/acme/v1/things', '__return_true' );
		$this->http( 401 );

		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$v = $this->assertRefused( '/wp-json/acme/v1/things' );
		$this->assertSame( 'refused-a-stranger', $v['why'] );
		$this->assertSame( 401, $v['code'] );
	}

	/**
	 * ⭐ CLAIM B, THE UPDATE PROBLEM. A vendor moves a route in an update: nothing
	 * is registered there any more and a real request 404s. We stop advertising
	 * it, with nobody having to notice by hand.
	 */
	public function test_an_address_a_vendor_moved_away_stops_being_advertised() {
		$GLOBALS['_af_rest_routes'] = array( '/acme/v2/things' => array() ); // v1 is gone.
		$this->http( 404 );

		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$v = $this->assertRefused( '/wp-json/acme/v1/things' );
		$this->assertSame( 'gone', $v['why'] );
	}

	/* -- 3. Back off, but only after looking -------------------------------- */

	/**
	 * ⭐ THE FAIL-OPEN THAT KEEPS A RELEASE SAFE. An address nobody has checked is
	 * published exactly as it is today. Otherwise updating the plugin would empty
	 * every site's document until cron next fired.
	 */
	public function test_an_address_nobody_has_checked_is_still_advertised() {
		$this->assertTrue( Reachability::may_advertise( '/wp-json/never/looked' ) );
		$this->assertNull( $this->verdict_for( '/wp-json/never/looked' ) );
	}

	/** ⛔ But "we looked and could not tell" is a no. That is his rule exactly. */
	public function test_an_address_we_looked_at_and_could_not_establish_is_not_advertised() {
		$this->route( '/acme/v1/things', null ); // Registered, but nobody said who may read it.
		$this->http( 500 );

		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$v = $this->verdict_for( '/wp-json/acme/v1/things' );
		$this->assertSame( 'unknown', $v['state'] );
		$this->assertSame( 'no-permission-check', $v['why'] );
		$this->assertFalse( Reachability::may_advertise( '/wp-json/acme/v1/things' ) );
	}

	/* -- 4. Stale truth beats fresh nothing --------------------------------- */

	/**
	 * ⭐ A blocked loopback, or one plugin throwing once, must not quietly
	 * un-advertise an address an earlier run proved open. Only a real answer moves
	 * the needle.
	 */
	public function test_an_inconclusive_recheck_keeps_the_verdict_the_last_one_reached() {
		$this->route( '/acme/v1/things', '__return_true' );
		$this->http( 200 );
		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );
		$this->assertSame( 'public', $this->verdict_for( '/wp-json/acme/v1/things' )['state'] );

		// Now the route table is empty and the request fails outright.
		$GLOBALS['_af_rest_routes'] = array();
		$GLOBALS['_af_http_queue']  = array( new \WP_Error( 'http_request_failed', 'timed out' ) );
		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$v = $this->verdict_for( '/wp-json/acme/v1/things' );
		$this->assertSame( 'public', $v['state'], 'The proved verdict stands.' );
		$this->assertSame( 'could-not-reach', $v['unsure_why'], 'And the doubt is recorded beside it.' );
		$this->assertTrue( Reachability::may_advertise( '/wp-json/acme/v1/things' ) );
	}

	/* -- 5. One plugin's mess is its own ------------------------------------ */

	public function test_a_permission_check_that_throws_costs_only_its_own_address() {
		$GLOBALS['_af_rest_routes'] = array(
			'/acme/v1/things' => array(
				array(
					'methods'             => array( 'GET' => true ),
					'permission_callback' => static function () {
						throw new \RuntimeException( 'the vendor exploded' );
					},
				),
			),
			'/good/v1/things' => array(
				array( 'methods' => array( 'GET' => true ), 'permission_callback' => '__return_true' ),
			),
		);
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 500 ), 'body' => '', 'headers' => array() ),
			array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ),
		);

		Reachability::refresh( array( '/wp-json/acme/v1/things', '/wp-json/good/v1/things' ) );

		$this->assertSame( 'permission-check-errored', $this->verdict_for( '/wp-json/acme/v1/things' )['why'] );
		$this->assertFalse( Reachability::may_advertise( '/wp-json/acme/v1/things' ) );
		$this->assertTrue( Reachability::may_advertise( '/wp-json/good/v1/things' ), 'The neighbour is untouched.' );
	}

	/* -- The read path spends nothing --------------------------------------- */

	/** ⛔ HIS STANDING RULE: no loopback in anything a page load touches. */
	public function test_the_read_path_never_fetches() {
		$GLOBALS['_af_http_last'] = null;

		Reachability::may_advertise( '/wp-json/acme/v1/things' );
		Reachability::record( '/wp-json/acme/v1/things' );
		Reachability::data();

		$this->assertNull( $GLOBALS['_af_http_last'], 'Reading a verdict must not call anything.' );
	}

	/* -- Only GET, and only what claims to be open -------------------------- */

	public function test_only_a_get_handler_is_ever_asked() {
		$GLOBALS['_af_rest_routes'] = array(
			'/acme/v1/things' => array(
				array( 'methods' => array( 'POST' => true ), 'permission_callback' => '__return_true' ),
			),
		);
		$this->http( 500 );

		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$this->assertSame( 'unknown', $this->verdict_for( '/wp-json/acme/v1/things' )['state'], 'A write handler says nothing about reading.' );
	}

	public function test_only_addresses_that_claim_to_need_no_sign_in_are_candidates() {
		$open   = array( 'url' => '/wp-json/a', 'type' => 'rest', 'auth' => 'none' );
		$locked = array( 'url' => '/wp-json/b', 'type' => 'rest', 'auth' => 'apikey' );
		$graph  = array( 'url' => '/graphql', 'type' => 'graphql', 'auth' => 'none' );

		$this->assertTrue( Reachability::claims_to_be_open( $open ) );
		$this->assertFalse( Reachability::claims_to_be_open( $locked ), 'We already tell readers it is locked; nothing to prove.' );
		$this->assertFalse( Reachability::claims_to_be_open( $graph ), 'A GET status does not mean the same thing there.' );
	}

	public function test_an_endpoint_inherits_its_resources_auth_when_it_declares_none() {
		$endpoint = array( 'url' => '/wp-json/a', 'type' => 'rest' );
		$this->assertTrue( Reachability::claims_to_be_open( $endpoint, array( 'auth' => array( 'type' => 'none' ) ) ) );
		$this->assertFalse( Reachability::claims_to_be_open( $endpoint, array( 'auth' => array( 'type' => 'oauth2' ) ) ) );
	}

	/* -- Addresses, written one way ----------------------------------------- */

	public function test_an_address_is_stored_the_same_way_however_it_was_written() {
		$this->assertSame( '/wp-json/acme/v1', Reachability::key( '/wp-json/acme/v1' ) );
		$this->assertSame( '/wp-json/acme/v1', Reachability::key( 'https://example.test/wp-json/acme/v1' ) );
		$this->assertSame( '/wp-json/acme/v1', Reachability::key( '/wp-json/acme/v1?per_page=1' ) );
	}

	/** ⛔ Somebody else's server is not ours to knock on, so it is left alone. */
	public function test_an_off_site_address_is_never_checked_and_never_dropped() {
		$this->assertSame( '', Reachability::key( 'https://someone-else.example/api' ) );
		$this->assertTrue( Reachability::may_advertise( 'https://someone-else.example/api' ) );
	}

	/* -- The probe does not pollute the site's own logs ---------------------- */

	public function test_the_probe_asks_as_a_stranger_and_names_itself() {
		$this->route( '/acme/v1/things', '__return_false' );
		$this->http( 200 );

		Reachability::refresh( array( '/wp-json/acme/v1/things' ) );

		$call = $GLOBALS['_af_http_last'];
		$this->assertSame( array(), $call['args']['cookies'], 'No cookies: a stranger carries none.' );
		$this->assertArrayHasKey( 'X-Agentimus-Selfcheck', $call['args']['headers'], 'Our own probe must not read as agent traffic.' );
		$this->assertSame( 0, $call['args']['redirection'], 'A redirect is a different address, not this one.' );
	}

	/** @return array The verdict, asserted refused. */
	private function assertRefused( $url ) {
		$v = $this->verdict_for( $url );
		$this->assertSame( 'refused', $v['state'] );
		$this->assertFalse( Reachability::may_advertise( $url ) );
		return $v;
	}
}
