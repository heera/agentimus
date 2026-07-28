<?php
/**
 * REST permission matrix, enforced against a real WP_REST_Server. The audit flagged
 * that nothing asserted a non-admin gets 403; this dispatches EVERY registered
 * agentimus route as a subscriber and proves each one denies — except the single
 * public discovery endpoint, which must stay open. Dynamic, so a future route added
 * without a permission gate fails this test.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class RestPermissionTest extends RestTestCase {

	/** Deliberately public: the WP namespace index, the discovery document, the
	 *  front-end AI-referral beacon (same-origin + rate-limited, never admin data),
	 *  and the two OAuth front doors — registration is how an unknown client
	 *  introduces itself (RFC 7591), and the token exchange authenticates with the
	 *  code + PKCE verifier, not a WP session (RFC 6749). Exempting them here does
	 *  not leave them untested: OauthRestTest pins what "public" actually means
	 *  (404 while the MCP server is off, spec behaviour once it is on). */
	private const PUBLIC_ROUTES = array(
		'/agentimus/v1',
		'/agentimus/v1/discovery',
		'/agentimus/v1/ai-hit',
		'/agentimus/v1/oauth/register',
		'/agentimus/v1/oauth/token',
	);

	public function test_no_admin_route_is_reachable_by_a_subscriber() {
		wp_set_current_user( $this->subscriber );

		$checked = 0;
		foreach ( $this->agentimus_routes() as $route => $endpoints ) {
			if ( in_array( $route, self::PUBLIC_ROUTES, true ) ) {
				continue;
			}
			foreach ( $endpoints as $endpoint ) {
				foreach ( array_keys( (array) $endpoint['methods'] ) as $method ) {
					if ( 'OPTIONS' === $method ) {
						continue; // CORS preflight is public by design.
					}
					// A route with a URL path param (e.g. /assistant/post/(?P<id>\d+))
					// is registered as a regex; dispatching that pattern verbatim
					// matches NO route and 404s before any permission gate runs.
					// Substitute a concrete value for each path param so the request
					// actually reaches — and must be denied by — the gate.
					$path    = preg_replace( '#\(\?P<[^>]+>[^)]+\)#', '1', $route );
					$request = new \WP_REST_Request( $method, $path );
					// WP validates REQUIRED params before the permission callback, so a
					// missing one would 400 before the 403. Supply a dummy value for each
					// required arg, so the request actually reaches — and is denied by —
					// the permission gate.
					foreach ( (array) ( $endpoint['args'] ?? array() ) as $arg => $spec ) {
						if ( ! empty( $spec['required'] ) ) {
							$request->set_param( $arg, '1' );
						}
					}
					$status = rest_do_request( $request )->get_status();
					$this->assertContains(
						$status,
						array( 401, 403 ),
						sprintf( '%s %s must deny a subscriber (got %d)', $method, $route, $status )
					);
					$checked++;
				}
			}
		}

		$this->assertGreaterThanOrEqual( 15, $checked, 'sanity: the full route surface was exercised' );
	}

	public function test_an_editor_is_also_denied_the_admin_routes() {
		wp_set_current_user( $this->editor ); // has edit caps, but not manage_options.
		$status = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/settings' ) )->get_status();
		$this->assertContains( $status, array( 401, 403 ) );
	}

	public function test_the_public_discovery_endpoint_is_open_to_anonymous() {
		wp_set_current_user( 0 );
		$resp = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/discovery' ) );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertArrayHasKey( 'spec_version', (array) $resp->get_data() );
	}

	public function test_an_admin_is_not_forbidden() {
		wp_set_current_user( $this->admin );
		$status = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/validate' ) )->get_status();
		$this->assertNotContains( $status, array( 401, 403 ), 'an administrator must pass the permission gate' );
	}
}
