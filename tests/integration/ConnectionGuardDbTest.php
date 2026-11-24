<?php
/**
 * ⭐ Step G (his call 2026-11-25): Pause per connection, and one call limit per
 * connection, off by default. No expiry.
 *
 * Connections are seated the way a real request seats them: a bearer header on
 * the MCP address, through the authenticators. The guard is asked exactly as
 * core asks it, with the request core matched.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\ConnectionAccess;
use Agentimus\Abilities\ConnectionGuard;
use Agentimus\Abilities\HardBlocks;
use Agentimus\McpToken;
use Agentimus\Oauth\Rest;
use Agentimus\Oauth\Server;
use Agentimus\Settings;

final class ConnectionGuardDbTest extends DbTestCase {

	const VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	/** @var Settings */
	private $settings;

	/** @var int */
	private $admin;

	/** @var array */
	private $server_before;

	public function set_up(): void {
		parent::set_up();
		$this->server_before = $_SERVER;
		$this->sign_out();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		// A real request has a page; an earlier test can leave none, and then no
		// token seats (see the skills tests).
		$GLOBALS['pagenow'] = 'index.php';
		$this->admin        = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->settings = new Settings();
		$all            = $this->settings->all();
		$all['enable_mcp_server']   = true;
		$all['enable_agent_writes'] = true;
		$all['mcp_call_limit']      = 0;
		$this->settings->update( $all );
		delete_option( 'agentimus_oauth' );
		delete_option( McpToken::OPTION );
	}

	public function tear_down(): void {
		$this->sign_out();
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$_SERVER = $this->server_before;
		parent::tear_down();
	}

	/* -- helpers ---------------------------------------------------------- */

	private function approve() {
		$client = Server::register_client(
			array(
				'client_name'   => 'Claude',
				'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ),
			)
		);
		$req    = Server::validate_authorize_request(
			array(
				'client_id'             => $client['client_id'],
				'redirect_uri'          => 'https://claude.ai/api/mcp/auth_callback',
				'response_type'         => 'code',
				'code_challenge'        => self::CHALLENGE,
				'code_challenge_method' => 'S256',
				'scope'                 => 'write',
			)
		);
		$code   = Server::issue_code( $req, 'write', $this->admin, ConnectionAccess::everything() );
		$tokens = Server::exchange_token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client['client_id'],
				'redirect_uri'  => 'https://claude.ai/api/mcp/auth_callback',
				'code_verifier' => self::VERIFIER,
			)
		);
		$this->assertIsArray( $tokens );
		return array( $client['client_id'], $tokens['access_token'] );
	}

	private function sign_in( $bearer ) {
		$this->sign_out();
		$_SERVER['REQUEST_URI']        = '/wp-json/agentimus/v1/mcp';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
		$seated                        = McpToken::authenticate( false );
		$seated                        = $seated ? $seated : Server::authenticate( false );
		$this->assertSame( $this->admin, $seated, 'The credential must really have signed in, or this proves nothing.' );
	}

	private function sign_out() {
		McpToken::reset_request();
		Server::reset_request();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}

	/** Ask the guard as core does, for a call on the MCP address. */
	private function call() {
		return ConnectionGuard::check( null, array(), new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' ) );
	}

	private function limit( $n ) {
		$all                   = $this->settings->all();
		$all['mcp_call_limit'] = $n;
		$this->settings->update( $all );
	}

	/* -- pause ------------------------------------------------------------ */

	public function test_a_paused_app_is_refused_and_resumes_without_a_new_approval() {
		list( $id, $token ) = $this->approve();

		$this->assertTrue( Server::set_paused( $id, true ) );
		$this->sign_in( $token ); // Still signs in: its keys stay…
		$refused = $this->call();
		$this->assertWPError( $refused ); // …but every call is refused…
		$this->assertSame( 'agentimus_connection_paused', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] ); // …and never 401, which would send the owner a new approval page.
		$this->assertTrue( Server::grants()[0]['paused'] );

		$this->assertFalse( Server::set_paused( $id, false ) );
		$this->sign_in( $token ); // The same key, no new approval.
		$this->assertNull( $this->call() );
	}

	public function test_pausing_one_app_leaves_another_working() {
		list( $a ) = $this->approve();
		list( , $b_token ) = $this->approve();
		Server::set_paused( $a, true );

		$this->sign_in( $b_token );
		$this->assertNull( $this->call() );
	}

	public function test_a_paused_shared_token_is_refused() {
		$token = McpToken::create( 'write', $this->admin );
		$this->assertTrue( McpToken::set_paused( true ) );
		$this->assertTrue( McpToken::status()['paused'] );

		$this->sign_in( $token );
		$this->assertSame( 'agentimus_connection_paused', $this->call()->get_error_code() );

		McpToken::set_paused( false );
		$this->sign_in( $token );
		$this->assertNull( $this->call() );
	}

	public function test_disconnect_clears_a_pause() {
		list( $id ) = $this->approve();
		Server::set_paused( $id, true );
		Server::revoke_client( $id );
		$this->assertNull( Server::set_paused( $id, false ) ); // Nothing approved left to resume.
	}

	public function test_an_agent_cannot_resume_or_pause_itself() {
		list( $id, $token ) = $this->approve();
		Server::set_paused( $id, true );
		McpToken::create( 'write', $this->admin );

		$mcp = new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' );
		HardBlocks::start( null, array(), $mcp ); // An agent call is running.
		try {
			Server::set_paused( $id, false );
			McpToken::set_paused( true );
		} finally {
			HardBlocks::finish( null, array(), $mcp );
		}
		$this->assertTrue( Server::is_paused( $id ) );
		$this->assertFalse( McpToken::is_paused() );
	}

	public function test_the_pause_route_refuses_an_application_password() {
		$GLOBALS['wp_rest_application_password_uuid'] = 'test-uuid';
		$this->assertFalse( ( new Rest( $this->settings ) )->can_change_access() );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		$this->assertTrue( ( new Rest( $this->settings ) )->can_change_access() );
	}

	/* -- the call limit --------------------------------------------------- */

	public function test_there_is_no_limit_by_default() {
		$this->assertSame( 0, ( new Settings() )->defaults()['mcp_call_limit'] );
		list( , $token ) = $this->approve();
		$this->sign_in( $token );
		for ( $i = 0; $i < 400; $i++ ) {
			$this->assertNull( $this->call() );
		}
	}

	public function test_over_the_limit_the_rest_of_the_minute_is_refused_with_retry_after() {
		$this->limit( 30 );
		list( , $token ) = $this->approve();
		$this->sign_in( $token );
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertNull( $this->call(), "call $i should fit" );
		}
		$refused = $this->call();
		$this->assertWPError( $refused );
		$this->assertSame( 'agentimus_call_limit', $refused->get_error_code() );
		$this->assertSame( 429, $refused->get_error_data()['status'] );
		$this->assertStringContainsString( 'allows 30 a minute', $refused->get_error_message() );

		$response = ConnectionGuard::retry_header( new \WP_REST_Response( null, 429 ) );
		$wait     = (int) $response->get_headers()['Retry-After'];
		$this->assertGreaterThanOrEqual( 1, $wait );
		$this->assertLessThanOrEqual( 60, $wait );
	}

	public function test_each_connection_has_its_own_count() {
		$this->limit( 30 );
		list( , $a ) = $this->approve();
		list( , $b ) = $this->approve();
		$this->sign_in( $a );
		for ( $i = 0; $i < 31; $i++ ) {
			$this->call();
		}
		$this->sign_in( $b );
		$this->assertNull( $this->call() );
	}

	public function test_the_count_starts_again_next_minute() {
		$conn = array( 'kind' => 'grant', 'id' => 'x' );
		$t    = 1800000000; // A minute boundary.
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 0, ConnectionGuard::spend( $conn, 30, $t + 10 ) );
		}
		$this->assertSame( 50, ConnectionGuard::spend( $conn, 30, $t + 10 ) );
		$this->assertSame( 0, ConnectionGuard::spend( $conn, 30, $t + 60 ) );
	}

	/** heera.it 2026-11-25: ten at once let 35 through a limit of 30 on the plain count. */
	public function test_with_a_persistent_object_cache_the_count_is_atomic() {
		$was = wp_using_ext_object_cache( true );
		try {
			$conn = array( 'kind' => 'grant', 'id' => 'cached' );
			$t    = 1800000000;
			for ( $i = 0; $i < 30; $i++ ) {
				$this->assertSame( 0, ConnectionGuard::spend( $conn, 30, $t + 10 ) );
			}
			$this->assertSame( 50, ConnectionGuard::spend( $conn, 30, $t + 10 ) );
			$this->assertSame( 31, wp_cache_get( 'agentimus_calls_' . md5( 'grant:cached' ) . '_' . ( $t / 60 ), 'agentimus' ) ); // Counted by incr, not by a transient.
			$this->assertFalse( get_transient( 'agentimus_calls_' . md5( 'grant:cached' ) ) );
			$this->assertSame( 0, ConnectionGuard::spend( $conn, 30, $t + 60 ) );
		} finally {
			wp_using_ext_object_cache( $was );
		}
	}

	public function test_an_application_password_is_a_connection_too() {
		$this->limit( 30 );
		$GLOBALS['wp_rest_application_password_uuid'] = 'test-uuid';
		$this->assertSame( array( 'kind' => 'app', 'id' => 'test-uuid' ), ConnectionGuard::connection() );
	}

	public function test_a_browser_login_and_other_routes_are_not_counted() {
		$this->limit( 30 );
		$this->assertNull( ConnectionGuard::connection() ); // The admin's own browser.
		for ( $i = 0; $i < 40; $i++ ) {
			$this->assertNull( $this->call() );
		}
		list( , $token ) = $this->approve();
		$this->sign_in( $token );
		for ( $i = 0; $i < 40; $i++ ) {
			$this->assertNull( ConnectionGuard::check( null, array(), new \WP_REST_Request( 'GET', '/agentimus/v1/oauth/grants' ) ) );
		}
	}

	public function test_only_the_offered_limits_are_kept_and_an_agent_cannot_change_it() {
		$this->limit( 45 );
		$this->assertSame( 0, $this->settings->get( 'mcp_call_limit' ) );
		$this->limit( 60 );
		$this->assertSame( 60, $this->settings->get( 'mcp_call_limit' ) );

		$mcp = new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' );
		HardBlocks::start( null, array(), $mcp ); // An agent call is running.
		try {
			$this->limit( 0 );
		} finally {
			HardBlocks::finish( null, array(), $mcp );
		}
		$this->assertSame( 60, $this->settings->get( 'mcp_call_limit' ) );
	}
}
