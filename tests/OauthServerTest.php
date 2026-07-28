<?php
/**
 * The OAuth 2.1 server ({@see \Agentimus\Oauth\Server}) — the security core
 * behind one-time-approval connects. Registration validates redirect URIs,
 * authorize refuses everything but PKCE S256, codes are single-use and bound,
 * the owner's downgrade at the door caps the minted scope, refresh rotates,
 * and one client's revocation never touches another's grant.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\McpToken;
use Agentimus\Oauth\Server;
use Agentimus\AgentAccess\Events;
use PHPUnit\Framework\TestCase;

final class OauthServerTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		Server::reset_request();
		McpToken::reset_request();
		$GLOBALS['_af_users']   = array( 7 => true );
		$_SERVER['REQUEST_URI'] = '/wp-json/agentimus/v1/mcp';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['rest_route'] );
	}

	protected function tearDown(): void {
		\_af_reset_options();
		Server::reset_request();
		McpToken::reset_request();
		unset( $GLOBALS['_af_users'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['rest_route'] );
	}

	/* ---- helpers ---------------------------------------------------------- */

	/** RFC 7636 appendix B: the spec's own verifier/challenge pair. */
	const RFC_VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const RFC_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	private function register( $name = 'Claude', $uri = 'https://claude.ai/api/mcp/auth_callback' ) {
		return Server::register_client(
			array(
				'client_name'   => $name,
				'redirect_uris' => array( $uri ),
			)
		);
	}

	/** Run the whole front half: register → validate → approve → code. */
	private function code_for( $scope = 'write', $requested = 'write' ) {
		$client = $this->register();
		$req    = Server::validate_authorize_request(
			array(
				'client_id'             => $client['client_id'],
				'redirect_uri'          => $client['redirect_uris'][0],
				'response_type'         => 'code',
				'code_challenge'        => self::RFC_CHALLENGE,
				'code_challenge_method' => 'S256',
				'scope'                 => $requested,
			)
		);
		$this->assertIsArray( $req );
		return array( $client, $req, Server::issue_code( $req, $scope, 7 ) );
	}

	private function exchange( $client, $code, $verifier = self::RFC_VERIFIER ) {
		return Server::exchange_token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client['client_id'],
				'redirect_uri'  => $client['redirect_uris'][0],
				'code_verifier' => $verifier,
			)
		);
	}

	private function bearer( $token ) {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
	}

	/* ---- discovery -------------------------------------------------------- */

	public function test_metadata_promises_only_what_exists() {
		$as = Server::authorization_server_metadata();
		$this->assertSame( array( 'S256' ), $as['code_challenge_methods_supported'] );
		$this->assertSame( array( 'none' ), $as['token_endpoint_auth_methods_supported'] );
		$this->assertSame( array( 'code' ), $as['response_types_supported'] );
		$this->assertStringEndsWith( '/agentimus/mcp', $as['issuer'] ); // Path-based: collision-proof well-knowns.

		$pr = Server::protected_resource_metadata();
		$this->assertSame( array( $as['issuer'] ), $pr['authorization_servers'] );
	}

	/* ---- registration ----------------------------------------------------- */

	public function test_registration_requires_a_valid_redirect_uri() {
		$err = Server::register_client( array( 'client_name' => 'X' ) );
		$this->assertInstanceOf( \WP_Error::class, $err );

		$err = Server::register_client( array( 'redirect_uris' => array( 'not a url', '' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $err );
	}

	public function test_registration_mints_a_prefixed_public_client() {
		$client = $this->register( '  Claude <b>x</b>  ' );
		$this->assertMatchesRegularExpression( '/^agoc_[0-9a-f]{32}$/', $client['client_id'] );
		$this->assertSame( 'none', $client['token_endpoint_auth_method'] ); // Public client: PKCE, no secret.
		$this->assertStringNotContainsString( '<', $client['client_name'] );
	}

	/* ---- authorize validation --------------------------------------------- */

	public function test_unknown_client_and_foreign_redirect_are_not_redirectable() {
		$err = Server::validate_authorize_request( array( 'client_id' => 'agoc_nope', 'redirect_uri' => 'https://claude.ai/cb' ) );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertArrayNotHasKey( 'redirectable', (array) $err->get_error_data() );

		$client = $this->register();
		$err    = Server::validate_authorize_request(
			array(
				'client_id'    => $client['client_id'],
				'redirect_uri' => 'https://evil.example/cb', // Not registered → never redirect there.
			)
		);
		$this->assertSame( 'invalid_redirect_uri', $err->get_error_code() );
		$this->assertArrayNotHasKey( 'redirectable', (array) $err->get_error_data() );
	}

	public function test_pkce_s256_is_mandatory() {
		$client = $this->register();
		$base   = array(
			'client_id'     => $client['client_id'],
			'redirect_uri'  => $client['redirect_uris'][0],
			'response_type' => 'code',
		);

		$err = Server::validate_authorize_request( $base ); // No challenge at all.
		$this->assertSame( 'invalid_request', $err->get_error_code() );

		$err = Server::validate_authorize_request( $base + array( 'code_challenge' => self::RFC_CHALLENGE, 'code_challenge_method' => 'plain' ) );
		$this->assertSame( 'invalid_request', $err->get_error_code() );
		$this->assertTrue( (bool) $err->get_error_data()['redirectable'] ); // Registered redirect → safe to report back.
	}

	public function test_scope_dialects_resolve_to_wants_write() {
		$client = $this->register();
		$base   = array(
			'client_id'             => $client['client_id'],
			'redirect_uri'          => $client['redirect_uris'][0],
			'response_type'         => 'code',
			'code_challenge'        => self::RFC_CHALLENGE,
			'code_challenge_method' => 'S256',
		);
		$this->assertFalse( Server::validate_authorize_request( $base + array( 'scope' => 'read' ) )['wants_write'] );
		$this->assertTrue( Server::validate_authorize_request( $base + array( 'scope' => 'mcp' ) )['wants_write'] );
		$this->assertTrue( Server::validate_authorize_request( $base + array( 'scope' => '' ) )['wants_write'] ); // Empty asks for everything.
	}

	/** Walls beat requests: a write request on a writes-off site can only offer read. */
	public function test_max_grantable_never_exceeds_the_walls() {
		$this->assertSame( 'write', Server::max_grantable( true, true ) );
		$this->assertSame( 'read', Server::max_grantable( true, false ) );
		$this->assertSame( 'read', Server::max_grantable( false, true ) );
	}

	/* ---- the full happy path ---------------------------------------------- */

	public function test_code_exchange_mints_working_tokens() {
		list( $client, , $code ) = $this->code_for( 'write' );

		$tokens = $this->exchange( $client, $code );
		$this->assertIsArray( $tokens );
		$this->assertMatchesRegularExpression( '/^agoa_[0-9a-f]{64}$/', $tokens['access_token'] );
		$this->assertMatchesRegularExpression( '/^agor_[0-9a-f]{64}$/', $tokens['refresh_token'] );
		$this->assertSame( 'write', $tokens['scope'] );

		// The raw secrets appear in the response and NOWHERE in storage.
		$stored = wp_json_encode( \get_option( Server::OPTION ) );
		$this->assertStringNotContainsString( $tokens['access_token'], $stored );
		$this->assertStringNotContainsString( $tokens['refresh_token'], $stored );

		// The access token seats the approving user on the MCP route.
		$this->bearer( $tokens['access_token'] );
		$this->assertSame( 7, Server::authenticate( false ) );
		$this->assertTrue( Server::request_allows_writes() );
		$this->assertSame( 'agentimus-oauth:' . $client['client_id'], Server::request_credential() );
	}

	public function test_codes_are_single_use() {
		list( $client, , $code ) = $this->code_for();
		$this->assertIsArray( $this->exchange( $client, $code ) );
		$this->assertInstanceOf( \WP_Error::class, $this->exchange( $client, $code ) );
	}

	public function test_wrong_verifier_and_wrong_bindings_fail() {
		list( $client, , $code ) = $this->code_for();
		$err = $this->exchange( $client, $code, 'wrong-verifier-wrong-verifier-wrong-verifier' );
		$this->assertSame( 'invalid_grant', $err->get_error_code() );

		// The failed attempt burned the code — a retry with the right verifier is too late.
		$this->assertInstanceOf( \WP_Error::class, $this->exchange( $client, $code ) );

		list( $client2, , $code2 ) = $this->code_for();
		$err = Server::exchange_token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code2,
				'client_id'     => 'agoc_' . str_repeat( '0', 32 ), // Someone else's client id.
				'redirect_uri'  => $client2['redirect_uris'][0],
				'code_verifier' => self::RFC_VERIFIER,
			)
		);
		$this->assertSame( 'invalid_grant', $err->get_error_code() );
	}

	/** The owner approved less than the client asked for — the token obeys the owner. */
	public function test_downgrade_at_the_door_caps_the_token() {
		list( $client, , $code ) = $this->code_for( 'read', 'write' );
		$tokens                  = $this->exchange( $client, $code );
		$this->assertSame( 'read', $tokens['scope'] );

		$this->bearer( $tokens['access_token'] );
		Server::authenticate( false );
		$this->assertFalse( Server::request_allows_writes() );
		$gate = Server::gate_write_permission( static function () {
			return true;
		} );
		$this->assertFalse( $gate( array() ) );
	}

	/* ---- refresh ---------------------------------------------------------- */

	public function test_refresh_rotates_and_kills_the_old_pair() {
		list( $client, , $code ) = $this->code_for();
		$old = $this->exchange( $client, $code );

		$new = Server::exchange_token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $old['refresh_token'],
				'client_id'     => $client['client_id'],
			)
		);
		$this->assertIsArray( $new );
		$this->assertNotSame( $old['access_token'], $new['access_token'] );

		// Old refresh: dead. Old access: dead. New access: alive.
		$this->assertInstanceOf(
			\WP_Error::class,
			Server::exchange_token( array( 'grant_type' => 'refresh_token', 'refresh_token' => $old['refresh_token'] ) )
		);
		$this->bearer( $old['access_token'] );
		$this->assertFalse( Server::authenticate( false ) );
		Server::reset_request();
		$this->bearer( $new['access_token'] );
		$this->assertSame( 7, Server::authenticate( false ) );
	}

	public function test_refresh_rejects_a_foreign_client_id() {
		list( $client, , $code ) = $this->code_for();
		$tokens                  = $this->exchange( $client, $code );
		$err                     = Server::exchange_token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $tokens['refresh_token'],
				'client_id'     => 'agoc_' . str_repeat( 'f', 32 ),
			)
		);
		$this->assertSame( 'invalid_grant', $err->get_error_code() );
	}

	/* ---- authentication edges --------------------------------------------- */

	public function test_token_is_ignored_off_the_mcp_route_and_when_seated() {
		list( $client, , $code ) = $this->code_for();
		$tokens                  = $this->exchange( $client, $code );
		$this->bearer( $tokens['access_token'] );

		$this->assertSame( 42, Server::authenticate( 42 ) ); // Seat taken — untouched.

		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$this->assertFalse( Server::authenticate( false ) ); // Wrong door — a stranger.
	}

	public function test_expired_access_token_falls_through() {
		list( $client, , $code ) = $this->code_for();
		$tokens                  = $this->exchange( $client, $code );

		// Age the stored entry past its TTL — option surgery, not sleep().
		$state = \get_option( Server::OPTION );
		foreach ( $state['tokens'] as $hash => $entry ) {
			$state['tokens'][ $hash ]['expires'] = time() - 10;
		}
		\update_option( Server::OPTION, $state );

		$this->bearer( $tokens['access_token'] );
		$this->assertFalse( Server::authenticate( false ) );
	}

	public function test_deleted_approver_kills_the_grant() {
		list( $client, , $code ) = $this->code_for();
		$tokens                  = $this->exchange( $client, $code );
		$GLOBALS['_af_users']    = array(); // The approving account is gone.
		$this->bearer( $tokens['access_token'] );
		$this->assertFalse( Server::authenticate( false ) );
	}

	/* ---- grants surface --------------------------------------------------- */

	public function test_revoking_one_client_leaves_the_other_connected() {
		list( $a, , $code_a ) = $this->code_for();
		$tokens_a             = $this->exchange( $a, $code_a );

		$b      = $this->register( 'Cursor', 'https://cursor.com/cb' );
		$req_b  = Server::validate_authorize_request(
			array(
				'client_id'             => $b['client_id'],
				'redirect_uri'          => 'https://cursor.com/cb',
				'response_type'         => 'code',
				'code_challenge'        => self::RFC_CHALLENGE,
				'code_challenge_method' => 'S256',
				'scope'                 => 'read',
			)
		);
		$tokens_b = $this->exchange( $b, Server::issue_code( $req_b, 'read', 7 ) );

		$this->assertTrue( Server::revoke_client( $a['client_id'] ) );

		$this->bearer( $tokens_a['access_token'] );
		$this->assertFalse( Server::authenticate( false ) ); // A is out…
		Server::reset_request();
		$this->bearer( $tokens_b['access_token'] );
		$this->assertSame( 7, Server::authenticate( false ) ); // …B never noticed.

		$rows = Server::grants();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Cursor', $rows[0]['name'] );
		$this->assertSame( 'cursor.com', $rows[0]['host'] );
	}

	/**
	 * Using a grant stamps its last-used time — the Connected-agents card's
	 * liveness line. Regression: authenticate() minted the request grant but
	 * never called touch(), so a busy connection read "never used" forever.
	 */
	public function test_using_a_grant_stamps_last_used() {
		list( $client, , $code ) = $this->code_for();
		$tokens                  = $this->exchange( $client, $code );

		$rows = Server::grants();
		$this->assertNull( $rows[0]['lastUsed'] );

		$this->bearer( $tokens['access_token'] );
		Server::authenticate( false );

		$rows = Server::grants();
		$this->assertNotNull( $rows[0]['lastUsed'] );
		$this->assertEqualsWithDelta( time(), $rows[0]['lastUsed'], 5 );
	}

	public function test_grants_lists_only_approved_clients() {
		$this->register( 'Lurker' ); // Registered, never approved.
		$this->assertSame( array(), Server::grants() );

		$this->code_for(); // Approval happens at issue_code.
		$rows = Server::grants();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'write', $rows[0]['scope'] );
	}

	/* ---- the log label ---------------------------------------------------- */

	public function test_agent_access_names_the_approved_connection() {
		list( $client, , $code ) = $this->code_for();
		$this->exchange( $client, $code );

		$out = Events::shape(
			array(
				'kind'    => Events::KIND_ABILITY_USED,
				'user_id' => '7',
				'cred'    => 'agentimus-oauth:' . $client['client_id'],
				'subject' => 'agentimus/read-readiness',
			)
		);
		$this->assertSame( 'Claude’s connection', $out['credName'] );

		Server::revoke_client( $client['client_id'] );
		$out = Events::shape( array( 'kind' => Events::KIND_ABILITY_USED, 'user_id' => '7', 'cred' => 'agentimus-oauth:' . $client['client_id'] ) );
		$this->assertSame( '', $out['credName'] ); // Revoked → resolver goes quiet, the UI words it.
	}
}
