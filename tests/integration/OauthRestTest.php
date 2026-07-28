<?php
/**
 * The two OAuth front doors, dispatched against a real WP_REST_Server. They are
 * public by spec, so RestPermissionTest exempts them from its deny-everything
 * sweep — this test is the other half of that bargain: it pins what "public"
 * actually means. While the MCP server is off, both doors answer 404, the same
 * "this surface does not exist" as every other MCP endpoint. Once the owner
 * opts in, registration behaves per RFC 7591 (mints a public client, never a
 * secret) and the token endpoint speaks RFC 6749 errors that OAuth clients
 * can parse.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Oauth\Server;
use Agentimus\Settings;

final class OauthRestTest extends RestTestCase {

	public function test_both_doors_answer_404_while_the_mcp_server_is_off() {
		wp_set_current_user( 0 );

		foreach ( array( '/agentimus/v1/oauth/register', '/agentimus/v1/oauth/token' ) as $path ) {
			$status = rest_do_request( new \WP_REST_Request( 'POST', $path ) )->get_status();
			$this->assertSame( 404, $status, $path . ' must look absent while enable_mcp_server is off' );
		}
	}

	public function test_registration_mints_a_public_client_once_opted_in() {
		$this->enable_mcp_server();
		wp_set_current_user( 0 ); // registration is unauthenticated by spec.

		$response = rest_do_request(
			$this->json_request(
				'/agentimus/v1/oauth/register',
				array(
					'client_name'   => 'Test MCP client',
					'redirect_uris' => array( 'https://client.example/callback' ),
				)
			)
		);
		$this->assertSame( 201, $response->get_status() );

		$data = (array) $response->get_data();
		$this->assertStringStartsWith( Server::PREFIX_CLIENT, $data['client_id'] );
		$this->assertArrayNotHasKey( 'client_secret', $data, 'a public client proves itself with PKCE, never a secret' );
		$this->assertSame( 'none', $data['token_endpoint_auth_method'] );
	}

	public function test_registration_without_a_redirect_uri_is_refused() {
		$this->enable_mcp_server();
		wp_set_current_user( 0 );

		$response = rest_do_request(
			$this->json_request( '/agentimus/v1/oauth/register', array( 'client_name' => 'no uris' ) )
		);
		$this->assertSame( 400, $response->get_status(), 'no valid redirect_uri must not register a client' );
	}

	public function test_the_token_endpoint_speaks_rfc_6749_errors_once_opted_in() {
		$this->enable_mcp_server();
		wp_set_current_user( 0 );

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/oauth/token' );
		$request->set_body_params(
			array(
				'grant_type' => 'authorization_code',
				'code'       => 'bogus',
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertSame( 'invalid_grant', $data['error'] ?? '', 'errors go out in the {"error": …} dialect OAuth clients parse' );

		$headers = $response->get_headers();
		$this->assertSame( 'no-store', $headers['Cache-Control'] ?? '', 'token responses must never be cacheable' );
	}

	private function enable_mcp_server() {
		$settings                      = ( new Settings() )->all();
		$settings['enable_mcp_server'] = true;
		update_option( Settings::OPTION, $settings );
	}

	private function json_request( $path, array $body ) {
		$request = new \WP_REST_Request( 'POST', $path );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $request;
	}
}
