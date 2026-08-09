<?php
/**
 * OAuth REST controller — the connect front doors and the connected-agents roster.
 *
 * The register and token endpoints are PUBLIC by spec: registration is how an
 * unknown client introduces itself (RFC 7591), and the token endpoint
 * authenticates with the code + PKCE verifier, not with a session. Both self-gate
 * on the MCP server being enabled (a 404 otherwise, like every other MCP surface).
 * The grants roster is manage_options-gated. All logic lives in {@see Server};
 * this owns only the HTTP shape.
 *
 * @package Agentimus
 */

namespace Agentimus\Oauth;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Rest {

	/** @var string REST namespace. */
	const NS = 'agentimus/v1';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings The core settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks only.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function routes() {
		// The OAuth front doors. Both PUBLIC by spec: registration is how an
		// unknown client introduces itself (RFC 7591), and the token endpoint
		// authenticates with the code + PKCE verifier, not with a session.
		register_rest_route(
			self::NS,
			'/oauth/register',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'oauth_register' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'oauth_token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/grants',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'oauth_grants' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'oauth_revoke_grant' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * POST /oauth/register — RFC 7591 dynamic client registration. Gated only
	 * on the MCP server being on; a 404 otherwise, same as every other MCP
	 * surface.
	 *
	 * @param \WP_REST_Request $request The registration request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function oauth_register( \WP_REST_Request $request ) {
		if ( ! $this->settings->enabled( 'enable_mcp_server' ) ) {
			return new \WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'agentimus' ), array( 'status' => 404 ) );
		}
		$body = $request->get_json_params();
		$made = Server::register_client( is_array( $body ) ? $body : array() );
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		$response = rest_ensure_response( $made );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * POST /oauth/token — code (+ PKCE) or refresh exchange. Errors go out in
	 * the RFC 6749 shape ({"error": …}), not WordPress's, because that is the
	 * dialect OAuth clients parse; the response is never cacheable.
	 *
	 * @param \WP_REST_Request $request The token request (form-encoded by spec).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function oauth_token( \WP_REST_Request $request ) {
		if ( ! $this->settings->enabled( 'enable_mcp_server' ) ) {
			return new \WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'agentimus' ), array( 'status' => 404 ) );
		}
		$result = Server::exchange_token( $request->get_params() );
		if ( is_wp_error( $result ) ) {
			$data     = (array) $result->get_error_data();
			$response = new \WP_REST_Response(
				array(
					'error'             => isset( $data['error'] ) ? $data['error'] : 'invalid_request',
					'error_description' => $result->get_error_message(),
				),
				isset( $data['status'] ) ? (int) $data['status'] : 400
			);
		} else {
			$response = rest_ensure_response( $result );
		}
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * GET /oauth/grants — the Connected-agents rows. Names and dates only.
	 *
	 * @return \WP_REST_Response
	 */
	public function oauth_grants() {
		return rest_ensure_response( array( 'grants' => Server::grants() ) );
	}

	/**
	 * DELETE /oauth/grants — revoke ONE client's connection; everyone else
	 * stays connected.
	 *
	 * @param \WP_REST_Request $request { clientId }.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function oauth_revoke_grant( \WP_REST_Request $request ) {
		$client_id = (string) $request->get_param( 'clientId' );
		if ( ! Server::revoke_client( $client_id ) ) {
			return new \WP_Error( 'agentimus_unknown_grant', __( 'That connection no longer exists.', 'agentimus' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'grants' => Server::grants() ) );
	}

	/**
	 * Manage-options gate for the roster routes.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}
}
