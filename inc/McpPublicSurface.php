<?php
/**
 * McpPublicSurface — the anonymous handshake surface of the MCP endpoint:
 * answers unauthenticated `initialize` and `ping` (plus the initialized
 * notification) so any MCP client can complete the protocol handshake and learn
 * that a server is here BEFORE authenticating.
 *
 * This publishes nothing that isn't already public: the server's identity and
 * protocol version, and nothing else. It once answered `tools/list` too, on the
 * premise that the tool names, descriptions and schemas were "already public by
 * design at /.well-known/mcp.json" — which was never true for abilities that
 * require sign-in, and those are all of ours. See PUBLIC_METHODS below.
 *
 * What it changes is the PROTOCOL answer — a scanner or a cautious client no
 * longer reads "401" as "no MCP here". Everything else (tools/list, tools/call,
 * resources, sessions) still requires authentication and flows through the
 * adapter untouched, keeping its 401 + WWW-Authenticate, which is the signal
 * OAuth-capable clients key on.
 *
 * Implementation: a rest_pre_dispatch interceptor that reuses the adapter's OWN
 * handlers, statelessly — the adapter's session layer binds sessions to real
 * users, so the anonymous path answers without minting a session (a server that
 * returns no Mcp-Session-Id is a stateless server per Streamable HTTP). Any
 * surprise (adapter shape drift, a throwing handler) falls through to the
 * adapter's normal authenticated path — fail closed to the old behaviour.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class McpPublicSurface {

	/** @var Settings */
	private $settings;

	/** JSON-RPC methods answerable without authentication — read-only protocol metadata. */
	/**
	 * What an unauthenticated caller may ask.
	 *
	 * `tools/list` is deliberately NOT here. It was, on the premise — stated in
	 * this file's own header — that the names, descriptions and schemas were
	 * "already public by design at /.well-known/mcp.json". They are not: three
	 * weeks earlier, abilities that all require sign-in were marked non-public
	 * precisely so their signatures would stay OUT of the served documents, and
	 * `WellKnownDocsTest::test_a_non_public_resource_reaches_no_served_surface`
	 * asserts it by name — scan-exposed-files, and "nor may its schemas /
	 * descriptions leak there". Answering tools/list anonymously handed back
	 * over the protocol exactly what that rule keeps out of the files.
	 *
	 * The handshake still works: `initialize` answers, so a scanner or a cautious
	 * client never reads 401 as "no MCP here", and tools/list now returns the
	 * adapter's 401 + WWW-Authenticate — which tells a client "there are tools,
	 * authenticate" rather than the empty list that would say "there are none".
	 */
	const PUBLIC_METHODS = array( 'initialize', 'ping' );

	/**
	 * @param Settings $settings Feature flags.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the interceptor.
	 */
	public function register() {
		add_filter( 'rest_pre_dispatch', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Answer anonymous public-method calls on the MCP route; touch nothing else.
	 *
	 * @param mixed            $result  Dispatch short-circuit value (null = proceed).
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request The request.
	 * @return mixed
	 */
	public function intercept( $result, $server, $request ) {
		if ( null !== $result || ! $this->settings->enabled( 'enable_mcp_server' ) ) {
			return $result;
		}
		if ( '/agentimus/v1/mcp' !== $request->get_route() || 'POST' !== $request->get_method() ) {
			return $result;
		}
		if ( get_current_user_id() ) {
			return $result; // Authenticated → the adapter's full, sessionful path.
		}

		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) || empty( $body['method'] ) || ! is_string( $body['method'] ) ) {
			return $result; // Batches and malformed bodies keep the old answer.
		}
		$method = $body['method'];

		// The post-initialize notification: acknowledge (202, no body) so a
		// spec-following anonymous client isn't bounced mid-handshake.
		if ( 'notifications/initialized' === $method ) {
			return new \WP_REST_Response( null, 202 );
		}
		if ( ! in_array( $method, self::PUBLIC_METHODS, true ) ) {
			return $result; // tools/call & friends: 401 + WWW-Authenticate as before.
		}

		$mcp = $this->agentimus_server();
		if ( null === $mcp ) {
			return $result;
		}

		try {
			switch ( $method ) {
				case 'initialize':
					$params  = ( isset( $body['params'] ) && is_array( $body['params'] ) ) ? $body['params'] : array();
					$version = ( isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ) ? $params['protocolVersion'] : '';
					$payload = ( new \WP\MCP\Handlers\Initialize\InitializeHandler( $mcp ) )->handle( $version )->toArray();
					break;
				default: // ping.
					$payload = new \stdClass(); // An empty JSON object, per the spec.
			}
		} catch ( \Throwable $e ) {
			return $result; // Adapter drift → the old 401, never a broken protocol answer.
		}

		$id       = isset( $body['id'] ) ? $body['id'] : null;
		$response = new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $payload,
			),
			200
		);
		$response->header( 'Content-Type', 'application/json; charset=UTF-8' );
		return $response;
	}

	/**
	 * The live Agentimus MCP server object from the adapter, or null.
	 *
	 * @return object|null
	 */
	private function agentimus_server() {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) || ! did_action( 'mcp_adapter_init' ) ) {
			return null;
		}
		try {
			$adapter = \WP\MCP\Core\McpAdapter::instance();
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'get_servers' ) ) {
				return null;
			}
			foreach ( (array) $adapter->get_servers() as $srv ) {
				if ( is_object( $srv ) && method_exists( $srv, 'get_server_id' ) && 'agentimus' === (string) $srv->get_server_id() ) {
					return $srv;
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}
}
