<?php
/**
 * The bundled MCP Adapter, end to end against a real WordPress: OFF by default
 * (no route), ON after the owner opts in (route exists, anonymous is denied,
 * an admin completes a JSON-RPC initialize), and the adapter's generic
 * "default server" stays suppressed because Agentimus did the bootstrapping.
 *
 * ONE ordered test method on purpose: the adapter's instance() guards itself
 * with a private static $initialized, so mcp_adapter_init fires exactly once
 * per PHP process — the off-state must be observed BEFORE the enable, and all
 * on-state assertions must run against the same WP_REST_Server that saw that
 * single init.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\AdapterBootstrap;
use Agentimus\Settings;

final class McpServerRestTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+); the feature self-gates below that.' );
		}
	}

	public function test_mcp_server_is_opt_in_end_to_end() {
		// 1. Default (toggle off): the plugin booted normally for this suite, and
		// set_up()'s rest_api_init registered every route — ours must not be there.
		$this->assertArrayNotHasKey(
			'/agentimus/v1/mcp',
			rest_get_server()->get_routes(),
			'The MCP route must not exist while enable_mcp_server is off.'
		);

		// 2. The owner opts in. Drive the boot-time gate chain directly (the public
		// seam) instead of re-running plugins_loaded, then stand up a fresh REST
		// server so rest_api_init:15 lets the adapter initialise and fire
		// mcp_adapter_init — where Registrar::register_mcp_server() (hooked at real
		// plugin boot) now passes its gate.
		$settings = ( new Settings() )->all();
		$settings['enable_mcp_server'] = true;
		update_option( Settings::OPTION, $settings );

		( new AdapterBootstrap( new Settings() ) )->maybe_bootstrap();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/agentimus/v1/mcp', $routes, 'Opting in must publish the scoped server route.' );

		// 3. The default server must NOT ride along: Agentimus bootstrapped the
		// adapter, so the generic execute-any-ability endpoint stays suppressed.
		foreach ( array_keys( $routes ) as $route ) {
			$this->assertStringNotContainsString(
				'mcp-adapter-default-server',
				$route,
				'The adapter default server must stay suppressed when Agentimus bootstraps.'
			);
		}

		// 4. The anonymous PROTOCOL contract (McpPublicSurface): the handshake —
		// initialize and ping — answers without auth, statelessly (no session
		// minted), while everything else keeps its 401 so OAuth-capable clients
		// still get the WWW-Authenticate signal.
		//
		// tools/list is NOT part of that contract, and the assertion below is the
		// seam this file exists to hold. It used to answer anonymously on the
		// premise that the names, descriptions and schemas were already public at
		// mcp.json — untrue for abilities that require sign-in, which is all of
		// ours. WellKnownDocsTest::test_a_non_public_resource_reaches_no_served_surface
		// forbids exactly that leak in the documents; nothing was watching the
		// protocol, so the same signatures went out the other door for a release.
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array() ) ) );
		$anon = rest_do_request( $request );
		$this->assertSame( 200, $anon->get_status(), 'anonymous initialize must answer' );
		$anon_init = json_decode( wp_json_encode( $anon->get_data() ), true );
		$this->assertNotEmpty( $anon_init['result']['serverInfo']['name'], 'anonymous initialize carries the server identity' );
		// The advertised capabilities must match what this server actually holds,
		// in BOTH directions. Claiming one that answers empty reads as broken;
		// hiding one that is full is worse — a client decides what to ASK for
		// from this object, so an unadvertised resources capability means
		// resources/list is never called and the documents are unreachable.
		// (Shipped that way for one dev build: the trim predated the resources.)
		$this->assertArrayHasKey( 'tools', $anon_init['result']['capabilities'] );
		$this->assertArrayHasKey( 'resources', $anon_init['result']['capabilities'], 'this server registers documents, so it must admit to them' );
		$this->assertArrayNotHasKey( 'prompts', $anon_init['result']['capabilities'], 'no prompts are registered, so none may be advertised' );
		$headers = $anon->get_headers();
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $headers, 'the anonymous surface is stateless — no session minted' );

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => array() ) ) );
		$anon_list = rest_do_request( $request );
		$this->assertSame( 401, $anon_list->get_status(), 'anonymous tools/list must not hand out sign-in-only tool signatures' );
		$this->assertStringNotContainsString(
			'scan-exposed-files',
			wp_json_encode( $anon_list->get_data() ),
			'and must not name them in the refusal either'
		);

		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array( 'name' => 'agentimus-read-readiness', 'arguments' => array() ) ) ) );
		$this->assertSame( 401, rest_do_request( $request )->get_status(), 'anonymous tools/call must stay denied' );

		// 5. An authenticated admin completes the MCP handshake.
		wp_set_current_user( $this->admin );
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array() ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), 'initialize: ' . wp_json_encode( $response->get_data() ) );

		// The transport hands back a session id + negotiated protocol version on
		// initialize; every later call must echo both headers (MCP handshake).
		// The session header is attached via rest_post_dispatch, which serve_request
		// applies but rest_do_request() does not — apply it as the real server would.
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );
		$headers  = $response->get_headers();
		$session  = isset( $headers['Mcp-Session-Id'] ) ? $headers['Mcp-Session-Id'] : '';
		// The handler returns nested result OBJECTS; normalise to arrays like a wire client would.
		$init     = json_decode( wp_json_encode( $response->get_data() ), true );
		$protocol = isset( $init['result']['protocolVersion'] ) ? $init['result']['protocolVersion'] : '';
		$this->assertNotSame( '', $session, 'initialize must mint a session id' );

		// 6. And the tool list is the scoped read set — our nine, nothing else.
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Mcp-Session-Id', $session );
		$request->set_header( 'MCP-Protocol-Version', $protocol );
		$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => array() ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), 'tools/list: ' . wp_json_encode( $response->get_data() ) );
		$data  = json_decode( wp_json_encode( $response->get_data() ), true );
		$tools = isset( $data['result']['tools'] ) ? wp_list_pluck( $data['result']['tools'], 'name' ) : array();
		$this->assertNotEmpty( $tools, 'tools/list must return the read set' );
		foreach ( $tools as $tool ) {
			$this->assertStringStartsWith( 'agentimus-', str_replace( '/', '-', $tool ), 'Only agentimus tools may be exposed, got: ' . $tool );
		}

		// 7. And the documents answer for real. Advertising the capability and
		// registering the abilities are two halves that can each be right while
		// the site still offers nothing readable — so ask the way a client asks,
		// over the wire, and require the URIs back.
		$request = new \WP_REST_Request( 'POST', '/agentimus/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Mcp-Session-Id', $session );
		$request->set_header( 'MCP-Protocol-Version', $protocol );
		$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list', 'params' => array() ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), 'resources/list: ' . wp_json_encode( $response->get_data() ) );
		$listed = json_decode( wp_json_encode( $response->get_data() ), true );
		$uris   = isset( $listed['result']['resources'] ) ? wp_list_pluck( $listed['result']['resources'], 'uri' ) : array();
		$this->assertNotEmpty( $uris, 'resources/list must return the documents this site publishes' );
		foreach ( $uris as $uri ) {
			// The adapter refuses a relative URI outright, so a bare "/llms.txt"
			// would not be missing from this list — it would never have registered.
			$this->assertMatchesRegularExpression( '#^https?://#', (string) $uri, 'a resource URI must be absolute, got: ' . $uri );
		}
	}
}
