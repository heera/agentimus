<?php
/**
 * The consent page ({@see \Agentimus\Oauth\Consent}) and the OAuth discovery
 * surfaces around it: the rendered form offers only what the walls allow, the
 * amber note appears exactly when a write request was downgraded, the hidden
 * fields carry the original authorize params for re-validation, the discovery
 * documents gate on the MCP server, and a 401 from the MCP route advertises
 * the resource metadata that starts the whole walk.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Envelope;
use Agentimus\Discovery\Registry;
use Agentimus\Discovery\WellKnown;
use Agentimus\Oauth\Consent;
use Agentimus\Oauth\Server;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class OauthConsentTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_registry();
		\_af_reset_options();
		Server::reset_request();
		$GLOBALS['_af_users'] = array( 7 => true );
		$_GET                 = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		Server::reset_request();
		unset( $GLOBALS['_af_users'] );
		$_GET = array();
	}

	private function consent( array $settings = array() ) {
		update_option( Settings::OPTION, $settings );
		return new Consent( new Settings() );
	}

	private function request( $scope = 'write' ) {
		$client = Server::register_client(
			array(
				'client_name'   => 'Claude',
				'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ),
			)
		);
		$params = array(
			'client_id'             => $client['client_id'],
			'redirect_uri'          => 'https://claude.ai/api/mcp/auth_callback',
			'response_type'         => 'code',
			'code_challenge'        => OauthServerTest::RFC_CHALLENGE,
			'code_challenge_method' => 'S256',
			'scope'                 => $scope,
			'state'                 => 'xyz',
		);
		$_GET   = $params;
		return Server::validate_authorize_request( $params );
	}

	/* ---- the rendered form ------------------------------------------------ */

	/** His ruling 11-22: one click. Allow gives Everything; Allow less opens every box, all ticked. */
	public function test_write_request_offers_one_allow_button_and_allow_less() {
		$consent = $this->consent( array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 1 ) );
		$html    = $consent->html( $this->request( 'write' ) );

		$this->assertStringContainsString( 'name="decision" value="approve">Allow Claude</button>', $html );
		$this->assertStringNotContainsString( 'name="grant"', $html ); // The old Read only / Read and write choice is gone.
		$this->assertStringContainsString( '<summary>Allow less</summary>', $html );
		foreach ( array( 'posts', 'publish', 'content', 'reviews', 'settings', 'maintenance' ) as $box ) {
			$this->assertStringContainsString( 'name="boxes[]" value="' . $box . '" checked', $html );
		}
		$this->assertStringContainsString( 'name="decision" value="approve_some"', $html );
		$this->assertStringNotContainsString( 'does not allow agent writes', $html );
		$this->assertStringContainsString( '“Claude” asks to connect', $html );
		$this->assertStringContainsString( 'claude.ai', $html ); // The checkable fact beside the claim.
	}

	public function test_write_request_on_walled_site_offers_read_only_with_the_note() {
		$consent = $this->consent( array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 0 ) );
		$html    = $consent->html( $this->request( 'write' ) );

		$this->assertStringNotContainsString( 'name="grant" value="write"', $html ); // Walls beat requests: no write radio.
		$this->assertStringContainsString( 'does not allow agent writes', $html );
		$this->assertStringContainsString( 'can only read until you allow writes in Settings', $html );
	}

	public function test_read_request_shows_no_write_choice_and_no_note() {
		$consent = $this->consent( array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 1 ) );
		$html    = $consent->html( $this->request( 'read' ) );

		$this->assertStringNotContainsString( 'name="grant" value="write"', $html );
		$this->assertStringNotContainsString( 'does not allow agent writes', $html ); // Nothing was downgraded.
		$this->assertStringNotContainsString( 'Allow less', $html ); // Reading has no boxes to untick.
		$this->assertStringContainsString( 'It asks only to read', $html );
	}

	public function test_form_carries_the_original_params_and_a_nonce() {
		$consent = $this->consent( array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 1 ) );
		$html    = $consent->html( $this->request( 'write' ) );

		$this->assertStringContainsString( 'name="code_challenge" value="' . OauthServerTest::RFC_CHALLENGE . '"', $html );
		$this->assertStringContainsString( 'name="state" value="xyz"', $html );
		$this->assertStringContainsString( 'agentimus_oauth_consent', $html ); // The nonce action.
		$this->assertStringNotContainsString( '<script', $html );              // Server-rendered, script-free.
	}

	public function test_client_name_is_escaped_in_the_page() {
		update_option( Settings::OPTION, array( 'enable_mcp_server' => 1 ) );
		$client = Server::register_client(
			array(
				'client_name'   => 'Claude<script>alert(1)</script>',
				'redirect_uris' => array( 'https://claude.ai/cb' ),
			)
		);
		$params = array(
			'client_id'             => $client['client_id'],
			'redirect_uri'          => 'https://claude.ai/cb',
			'response_type'         => 'code',
			'code_challenge'        => OauthServerTest::RFC_CHALLENGE,
			'code_challenge_method' => 'S256',
		);
		$_GET   = $params;
		$html   = ( new Consent( new Settings() ) )->html( Server::validate_authorize_request( $params ) );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	/* ---- handing the browser back to the app -------------------------------- */

	/**
	 * Found 2026-11-25: heera.it's `form-action 'self'` header made the browser
	 * block the 302 that answered Allow, so no approval could finish. The hand-
	 * back is now a page that moves on, which that header does not cover.
	 */
	public function test_allow_hands_back_with_a_page_that_moves_on_not_a_form_redirect() {
		$consent = $this->consent( array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 1 ) );
		$html    = $consent->return_page( $this->request( 'write' ), array( 'code' => 'abc123' ) );
		$url     = 'https://claude.ai/api/mcp/auth_callback?code=abc123&amp;state=xyz';

		$this->assertStringContainsString( '<meta http-equiv="refresh" content="0;url=' . $url . '">', $html );
		$this->assertStringContainsString( '<a class="btn approve" href="' . $url . '">Continue</a>', $html );
		$this->assertStringContainsString( '<meta name="referrer" content="no-referrer">', $html );
		$this->assertStringContainsString( 'Approved. Returning you to Claude', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_cancel_hands_back_the_refusal_the_same_way() {
		$consent = $this->consent( array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 1 ) );
		$html    = $consent->return_page( $this->request( 'write' ), array( 'error' => 'access_denied' ) );

		$this->assertStringContainsString( 'content="0;url=https://claude.ai/api/mcp/auth_callback?error=access_denied&amp;state=xyz"', $html );
		$this->assertStringContainsString( 'Cancelled. Returning you to Claude', $html );
	}

	/** An editor's own scheme survives: esc_url would have emptied it. */
	public function test_a_custom_scheme_callback_is_kept() {
		update_option( Settings::OPTION, array( 'enable_mcp_server' => 1 ) );
		$cb     = 'cursor://anysphere.cursor-retrieval/oauth/callback';
		$client = Server::register_client( array( 'client_name' => 'Cursor', 'redirect_uris' => array( $cb ) ) );
		$params = array(
			'client_id'             => $client['client_id'],
			'redirect_uri'          => $cb,
			'response_type'         => 'code',
			'code_challenge'        => OauthServerTest::RFC_CHALLENGE,
			'code_challenge_method' => 'S256',
		);
		$_GET = $params;
		$html = ( new Consent( new Settings() ) )->return_page( Server::validate_authorize_request( $params ), array( 'code' => 'c1' ) );

		$this->assertStringContainsString( 'content="0;url=' . $cb . '?code=c1"', $html );
	}

	/* ---- discovery documents ---------------------------------------------- */

	public function test_oauth_discovery_docs_gate_on_the_mcp_server() {
		update_option( Settings::OPTION, array() ); // Server off.
		$envelope = new Envelope( new Settings(), Registry::instance() );
		$this->assertSame( '', $envelope->oauth_authorization_server_json() );
		$this->assertSame( '', $envelope->oauth_mcp_protected_resource_json() );

		update_option( Settings::OPTION, array( 'enable_mcp_server' => 1 ) );
		$as = json_decode( ( new Envelope( new Settings(), Registry::instance() ) )->oauth_authorization_server_json(), true );
		$this->assertSame( 'https://example.test/agentimus/mcp', $as['issuer'] );
		$this->assertSame( array( 'S256' ), $as['code_challenge_methods_supported'] );

		$pr = json_decode( ( new Envelope( new Settings(), Registry::instance() ) )->oauth_mcp_protected_resource_json(), true );
		$this->assertSame( array( 'https://example.test/agentimus/mcp' ), $pr['authorization_servers'] );
	}

	public function test_oauth_well_known_paths_are_routed() {
		$this->assertContains( 'oauth-authorization-server/agentimus/mcp', WellKnown::nested_routes() );
		$this->assertContains( 'oauth-protected-resource/agentimus/mcp', WellKnown::nested_routes() );
	}

	/**
	 * RFC 9728 §3.1: a client derives the metadata URL by inserting
	 * /.well-known/oauth-protected-resource between the host and the RESOURCE
	 * path — for our MCP endpoint that is …/oauth-protected-resource/wp-json/
	 * agentimus/v1/mcp. ChatGPT computes that form and never reads the
	 * WWW-Authenticate hint; a 404 there reads as "does not implement OAuth".
	 */
	public function test_prm_is_also_routed_at_the_resource_derived_path() {
		$this->assertSame( 'oauth-protected-resource/wp-json/agentimus/v1/mcp', WellKnown::mcp_resource_prm_route() );
		$this->assertContains( 'oauth-protected-resource/wp-json/agentimus/v1/mcp', WellKnown::nested_routes() );
	}

	/* ---- the 401 breadcrumb ----------------------------------------------- */

	public function test_mcp_401_carries_the_www_authenticate_pointer() {
		$response = new \WP_REST_Response( array(), 401 );
		$request  = new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' );

		$out     = Server::advertise( $response, null, $request );
		$headers = $out->get_headers();
		$this->assertArrayHasKey( 'WWW-Authenticate', $headers );
		$this->assertStringContainsString( '/.well-known/oauth-protected-resource/agentimus/mcp', $headers['WWW-Authenticate'] );
	}

	public function test_other_routes_and_statuses_are_untouched() {
		$ok  = Server::advertise( new \WP_REST_Response( array(), 200 ), null, new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' ) );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $ok->get_headers() );

		$foreign = Server::advertise( new \WP_REST_Response( array(), 401 ), null, new \WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $foreign->get_headers() );
	}
}
