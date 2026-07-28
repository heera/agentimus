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

	public function test_write_request_on_writes_on_site_offers_both_scopes() {
		$consent = $this->consent( array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 1 ) );
		$html    = $consent->html( $this->request( 'write' ) );

		$this->assertStringContainsString( 'name="grant" value="write" checked', $html ); // The request preselects…
		$this->assertStringContainsString( 'name="grant" value="read"', $html );  // …but read stays offered: downgrade at the door.
		$this->assertStringNotContainsString( 'does not allow agent writes', $html );
		$this->assertStringContainsString( '“Claude” asks to connect', $html );
		$this->assertStringContainsString( 'claude.ai', $html ); // The checkable fact beside the claim.
	}

	public function test_write_request_on_walled_site_offers_read_only_with_the_note() {
		$consent = $this->consent( array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 0 ) );
		$html    = $consent->html( $this->request( 'write' ) );

		$this->assertStringNotContainsString( 'name="grant" value="write"', $html ); // Walls beat requests: no write radio.
		$this->assertStringContainsString( 'does not allow agent writes', $html );
	}

	public function test_read_request_shows_no_write_choice_and_no_note() {
		$consent = $this->consent( array( 'enable_mcp_server' => 1, 'enable_agent_writes' => 1 ) );
		$html    = $consent->html( $this->request( 'read' ) );

		$this->assertStringNotContainsString( 'name="grant" value="write"', $html );
		$this->assertStringNotContainsString( 'does not allow agent writes', $html ); // Nothing was downgraded.
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
