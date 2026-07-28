<?php
/**
 * The MCP connection token ({@see McpToken}) — creation stores a fingerprint
 * (never the plaintext), authentication is honored only with the exact token
 * on the exact route, rotation kills the old token instantly, and the scope
 * cap means a read-only token cannot run a write tool no matter what the
 * settings allow.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\McpToken;
use PHPUnit\Framework\TestCase;

final class McpTokenTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		McpToken::reset_request();
		$GLOBALS['_af_users'] = array( 7 => true );
		$_SERVER['REQUEST_URI'] = '/wp-json/agentimus/v1/mcp';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['rest_route'] );
	}

	protected function tearDown(): void {
		\_af_reset_options();
		McpToken::reset_request();
		unset( $GLOBALS['_af_users'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['rest_route'] );
	}

	private function bearer( $token ) {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
	}

	/* ---- creation & status ------------------------------------------------ */

	/** The shape promises the scope; the store holds a fingerprint, not the secret. */
	public function test_create_shape_and_fingerprint_only_storage() {
		$read  = McpToken::create( 'read', 7 );
		$this->assertMatchesRegularExpression( '/^agmcp_r_[0-9a-f]{40}$/', $read );
		$this->assertMatchesRegularExpression( '/^agmcp_w_[0-9a-f]{40}$/', McpToken::create( 'write', 7 ) );

		$stored = \get_option( McpToken::OPTION );
		$this->assertStringNotContainsString( 'agmcp_', wp_json_encode( $stored ) ); // Plaintext appears nowhere.
		$this->assertArrayNotHasKey( 'plaintext', $stored );
	}

	/** Status is screen-safe: metadata yes, fingerprint no. */
	public function test_status_hides_the_hash() {
		McpToken::create( 'read', 7 );
		$status = McpToken::status();
		$this->assertSame( 'read', $status['scope'] );
		$this->assertArrayNotHasKey( 'hash', $status );
		$this->assertArrayNotHasKey( 'user_id', $status );

		McpToken::revoke();
		$this->assertNull( McpToken::status() );
	}

	/* ---- authentication --------------------------------------------------- */

	public function test_valid_token_on_mcp_route_authenticates_the_owner() {
		$token = McpToken::create( 'read', 7 );
		$this->bearer( $token );
		$this->assertSame( 7, McpToken::authenticate( false ) );
	}

	/** The token opens the MCP door only — any other route ignores it. */
	public function test_token_is_ignored_off_the_mcp_route() {
		$token = McpToken::create( 'read', 7 );
		$this->bearer( $token );
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$this->assertFalse( McpToken::authenticate( false ) );
		$this->assertTrue( McpToken::request_allows_writes() ); // No token in play → settings alone rule.
	}

	/** Plain permalinks reach the route as ?rest_route= — honored the same. */
	public function test_rest_route_query_form_is_recognized() {
		$token = McpToken::create( 'read', 7 );
		$this->bearer( $token );
		$_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fagentimus%2Fv1%2Fmcp';
		$_GET['rest_route']     = '/agentimus/v1/mcp';
		$this->assertSame( 7, McpToken::authenticate( false ) );
	}

	public function test_wrong_token_and_wrong_prefix_fall_through() {
		McpToken::create( 'read', 7 );
		$this->bearer( 'agmcp_r_' . str_repeat( '0', 40 ) );
		$this->assertFalse( McpToken::authenticate( false ) );

		$this->bearer( 'some-app-password' );
		$this->assertFalse( McpToken::authenticate( false ) );
	}

	/** A seat already taken (cookie, app password) is never disturbed. */
	public function test_existing_user_is_untouched() {
		$token = McpToken::create( 'read', 7 );
		$this->bearer( $token );
		$this->assertSame( 42, McpToken::authenticate( 42 ) );
	}

	/** Rotation is disconnection: the old plaintext dies the moment a new one exists. */
	public function test_rotate_invalidates_the_old_token() {
		$old = McpToken::create( 'read', 7 );
		McpToken::create( 'read', 7 ); // Rotate.
		$this->bearer( $old );
		$this->assertFalse( McpToken::authenticate( false ) );
	}

	/** A deleted owner account takes its token with it. */
	public function test_missing_owner_kills_the_token() {
		$token = McpToken::create( 'read', 7 );
		$GLOBALS['_af_users'] = array(); // The account is gone.
		$this->bearer( $token );
		$this->assertFalse( McpToken::authenticate( false ) );
	}

	/* ---- the scope cap ---------------------------------------------------- */

	/** Read token: no write tool runs, even though the ability exists. */
	public function test_read_scope_blocks_the_write_gate() {
		$token = McpToken::create( 'read', 7 );
		$this->bearer( $token );
		McpToken::authenticate( false );

		$gate = McpToken::gate_write_permission( static function () {
			return true; // The ability itself would say yes.
		} );
		$this->assertFalse( $gate( array() ) );
	}

	/** Write token: the gate defers to the ability's own permission. */
	public function test_write_scope_defers_to_the_inner_permission() {
		$token = McpToken::create( 'write', 7 );
		$this->bearer( $token );
		McpToken::authenticate( false );

		$this->assertTrue( call_user_func( McpToken::gate_write_permission( static function () {
			return true;
		} ), array() ) );
		$this->assertFalse( call_user_func( McpToken::gate_write_permission( static function () {
			return false; // Inner "no" still wins.
		} ), array() ) );
	}

	/** No token in the request: the gate is invisible (app-password paths unchanged). */
	public function test_non_token_requests_pass_the_gate_untouched() {
		$gate = McpToken::gate_write_permission( static function () {
			return true;
		} );
		$this->assertTrue( $gate( array() ) );
	}

	/** The Agent-Access recorder asks this to name the door: false with no token, true after one authenticated, false again for the next simulated request. */
	public function test_used_this_request_tracks_token_auth() {
		$this->assertFalse( McpToken::used_this_request() );

		$token = McpToken::create( 'read', 7 );
		$this->bearer( $token );
		McpToken::authenticate( false );
		$this->assertTrue( McpToken::used_this_request() );

		McpToken::reset_request();
		$this->assertFalse( McpToken::used_this_request() );
	}

	/* ---- last-used bookkeeping -------------------------------------------- */

	/** First use stamps; a second use inside the throttle window does not. */
	public function test_last_used_is_recorded_and_throttled() {
		$token = McpToken::create( 'read', 7 );
		$this->bearer( $token );
		$_SERVER['HTTP_USER_AGENT'] = 'claude-code/2.1';

		McpToken::authenticate( false );
		$first = McpToken::status()['last_used_at'];
		$this->assertNotNull( $first );
		$this->assertSame( 'claude-code/2.1', McpToken::status()['last_used_ua'] );

		$_SERVER['HTTP_USER_AGENT'] = 'something-else/9';
		McpToken::reset_request();
		McpToken::authenticate( false );
		$this->assertSame( 'claude-code/2.1', McpToken::status()['last_used_ua'] ); // Throttled — no second write.
	}
}
