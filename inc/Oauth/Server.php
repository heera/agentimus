<?php
/**
 * OAuth 2.1 authorization server for the MCP endpoint — the "one-time
 * approval" connect path. The connection token ({@see \Agentimus\McpToken})
 * covers clients that accept a pasted secret; this server covers clients that
 * take only a URL (claude.ai, ChatGPT, Cursor's Authenticate button) and run
 * authorization-code + PKCE themselves.
 *
 * Flow: unauthenticated MCP call → 401 + WWW-Authenticate → client reads the
 * protected-resource and authorization-server metadata → registers itself
 * (RFC 7591, public client, no secret) → sends the owner's browser to the
 * consent page → owner approves a scope → code → token exchange (PKCE S256)
 * → MCP calls with `Authorization: Bearer agoa_…`.
 *
 * Doctrine, mirroring the connection token:
 *  - Secrets are stored as SHA-256 fingerprints only; the raw value exists in
 *    exactly one response. Prefixes (agoa_/agor_/agoc_) make leaked-secret
 *    scanning possible.
 *  - PKCE S256 is required — no exceptions, no plain method. Codes are
 *    single-use, 60-second, and bound to client + redirect_uri + challenge.
 *  - The owner decides the scope at the door: a write request can be granted
 *    read-only, and a site whose write wall is off grants read-only always.
 *  - Every grant acts as the approving admin and is honored ONLY on the MCP
 *    route; each client's grant is revocable alone.
 *
 * @package Agentimus
 */

namespace Agentimus\Oauth;

use Agentimus\McpToken;

defined( 'ABSPATH' ) || exit;

final class Server {

	/** All server state: clients, codes, tokens, refresh — fingerprints only. */
	const OPTION = 'agentimus_oauth';

	/** Secret shapes. The prefix says which door a bearer credential belongs to. */
	const PREFIX_ACCESS  = 'agoa_';
	const PREFIX_REFRESH = 'agor_';
	const PREFIX_CLIENT  = 'agoc_';

	/** Authorization-code lifetime — one browser redirect, nothing more. */
	const CODE_TTL = 60;

	/** Access-token lifetime; clients refresh silently. */
	const ACCESS_TTL = HOUR_IN_SECONDS;

	/** Refresh-token lifetime — a connection unused for 30 days re-consents. */
	const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	/** @var array|null Grant of the token that authenticated THIS request (scope, client_id, name), or null. */
	private static $request_grant = null;

	/* ---------------------------------------------------------------------- *
	 *  Discovery documents
	 * ---------------------------------------------------------------------- */

	/**
	 * Issuer identifier. Path-based (RFC 8414 §2) so our well-known documents
	 * live under /.well-known/…/agentimus/mcp and can never collide with
	 * another plugin running its own OAuth server on the same site.
	 *
	 * @return string
	 */
	public static function issuer() {
		return untrailingslashit( home_url( '/agentimus/mcp' ) );
	}

	/**
	 * The protected resource — the MCP endpoint itself.
	 *
	 * @return string
	 */
	public static function resource() {
		return rest_url( 'agentimus/v1/mcp' );
	}

	/**
	 * The browser-facing consent page. Outside the REST API (a rewrite), so
	 * plain cookie auth works after the wp-login round-trip — a REST route
	 * would treat the nonce-less admin as logged out and loop.
	 *
	 * @return string
	 */
	public static function authorize_url() {
		return home_url( '/agentimus/connect' );
	}

	/**
	 * RFC 9728 protected-resource metadata.
	 *
	 * @return array
	 */
	public static function protected_resource_metadata() {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => array( 'read', 'write' ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * RFC 8414 authorization-server metadata — only what we truly implement.
	 *
	 * @return array
	 */
	public static function authorization_server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => rest_url( 'agentimus/v1/oauth/token' ),
			'registration_endpoint'                 => rest_url( 'agentimus/v1/oauth/register' ),
			'scopes_supported'                      => array( 'read', 'write' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			// The auth.md (workos.com/auth-md) agent_auth block — only what we truly
			// run: anonymous RFC 7591 client registration, credentialed by the
			// standard authorization-code + PKCE grant the site owner approves in the
			// browser. No identity assertions (ID-JAG), no claim ceremony — so those
			// types are simply not advertised.
			'agent_auth'                            => array(
				'skill'                    => home_url( '/auth.md' ),
				'register_uri'             => rest_url( 'agentimus/v1/oauth/register' ),
				'identity_types_supported' => array( 'anonymous' ),
				'anonymous'                => array(
					'credential_types_supported' => array( 'authorization_code' ),
				),
			),
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Dynamic client registration (RFC 7591)
	 * ---------------------------------------------------------------------- */

	/**
	 * Register a public client. We take its redirect URIs and self-claimed
	 * name, and mint an id — no secret; PKCE is the proof of possession.
	 *
	 * @param array $body Parsed JSON registration request.
	 * @return array|\WP_Error
	 */
	public static function register_client( array $body ) {
		$uris = array();
		if ( isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ) {
			foreach ( $body['redirect_uris'] as $uri ) {
				$uri = self::clean_redirect_uri( $uri );
				if ( '' !== $uri ) {
					$uris[] = $uri;
				}
			}
			$uris = array_values( array_unique( $uris ) );
		}
		if ( empty( $uris ) ) {
			return new \WP_Error( 'invalid_client_metadata', 'At least one valid redirect_uri is required.', array( 'status' => 400 ) );
		}

		// The name is the CLIENT'S claim about itself; the consent page words it
		// that way ("calls itself …"), so store it plainly — but bounded.
		$name = isset( $body['client_name'] ) ? substr( sanitize_text_field( (string) $body['client_name'] ), 0, 80 ) : '';
		if ( '' === $name ) {
			$name = 'MCP client';
		}

		$client_id = self::PREFIX_CLIENT . bin2hex( random_bytes( 16 ) );

		$state = self::state();
		// Registration is unauthenticated by spec; cap the roster so a bot
		// can't grow the option unbounded. Oldest unapproved die first.
		if ( count( $state['clients'] ) >= 50 ) {
			self::prune_unapproved_clients( $state );
		}
		$state['clients'][ $client_id ] = array(
			'redirect_uris' => $uris,
			'name'          => $name,
			'created'       => time(),
		);
		self::save( $state );

		return array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'redirect_uris'              => $uris,
			'client_name'                => $name,
			'token_endpoint_auth_method' => 'none',
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Authorize: validate → (consent page decides) → issue code
	 * ---------------------------------------------------------------------- */

	/**
	 * Validate an authorize request without issuing anything. Returns the
	 * sanitized request on success. On failure the error data says whether
	 * redirecting the error back is safe (`redirectable`) — a redirect_uri
	 * that failed validation must never be redirected to (open-redirect).
	 *
	 * @param array $params Query params from the consent page URL.
	 * @return array|\WP_Error
	 */
	public static function validate_authorize_request( array $params ) {
		$client_id    = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		$redirect_uri = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';

		$client = self::client( $client_id );
		if ( null === $client ) {
			return new \WP_Error( 'invalid_client', 'Unknown client_id.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $redirect_uri, $client['redirect_uris'], true ) ) {
			return new \WP_Error( 'invalid_redirect_uri', 'redirect_uri does not match a registered value.', array( 'status' => 400 ) );
		}

		$response_type = isset( $params['response_type'] ) ? (string) $params['response_type'] : '';
		if ( 'code' !== $response_type ) {
			return new \WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.', array( 'status' => 400, 'redirectable' => true ) );
		}

		$challenge = isset( $params['code_challenge'] ) ? (string) $params['code_challenge'] : '';
		$method    = isset( $params['code_challenge_method'] ) ? (string) $params['code_challenge_method'] : '';
		if ( 'S256' !== $method || ! preg_match( '/^[A-Za-z0-9\-_]{43}$/', $challenge ) ) {
			return new \WP_Error( 'invalid_request', 'PKCE with code_challenge_method=S256 is required.', array( 'status' => 400, 'redirectable' => true ) );
		}

		return array(
			'client_id'      => $client_id,
			'client_name'    => $client['name'],
			'redirect_uri'   => $redirect_uri,
			'code_challenge' => $challenge,
			'wants_write'    => self::scope_requests_write( isset( $params['scope'] ) ? (string) $params['scope'] : '' ),
			'state'          => isset( $params['state'] ) ? (string) $params['state'] : '',
		);
	}

	/**
	 * The scope the consent page may OFFER for a request: the owner decides
	 * at the door, but never more than the walls allow — a write request on a
	 * writes-off site can only become a read grant (shown with the amber
	 * note, per the approved prototype).
	 *
	 * @param bool $wants_write     What the client asked for.
	 * @param bool $writes_allowed  The site's write-tier wall.
	 * @return string 'read' or 'write' — the strongest offerable scope.
	 */
	public static function max_grantable( $wants_write, $writes_allowed ) {
		return ( $wants_write && $writes_allowed ) ? 'write' : 'read';
	}

	/**
	 * Issue an authorization code after the owner approved. The scope stored
	 * is the OWNER'S choice (possibly a downgrade), never above the walls.
	 *
	 * @param array  $req     Output of validate_authorize_request().
	 * @param string $scope   'read' or 'write' — the owner's granted scope.
	 * @param int    $user_id The approving user the grant will act as.
	 * @return string The single-use authorization code.
	 */
	public static function issue_code( array $req, $scope, $user_id ) {
		$code  = bin2hex( random_bytes( 32 ) );
		$state = self::state();

		$state['codes'][ hash( 'sha256', $code ) ] = array(
			'client_id'    => (string) $req['client_id'],
			'redirect_uri' => (string) $req['redirect_uri'],
			'challenge'    => (string) $req['code_challenge'],
			'scope'        => 'write' === $scope ? 'write' : 'read',
			'user_id'      => (int) $user_id,
			'expires'      => time() + self::CODE_TTL,
		);
		// Approval is also the moment the client stops being an anonymous
		// registration: remember who approved it, when, and WHAT was granted —
		// the owner's decision is the grant's scope of record, independent of
		// which tokens happen to be alive right now.
		if ( isset( $state['clients'][ $req['client_id'] ] ) ) {
			$state['clients'][ $req['client_id'] ]['approved']    = time();
			$state['clients'][ $req['client_id'] ]['approved_by'] = (int) $user_id;
			$state['clients'][ $req['client_id'] ]['scope']       = 'write' === $scope ? 'write' : 'read';
		}
		self::save( $state );
		return $code;
	}

	/* ---------------------------------------------------------------------- *
	 *  Token endpoint
	 * ---------------------------------------------------------------------- */

	/**
	 * Exchange a code (+ PKCE verifier) or a refresh token for tokens.
	 *
	 * @param array $body POST body params.
	 * @return array|\WP_Error RFC 6749 token response, or an error whose data
	 *                         carries the OAuth `error` code.
	 */
	public static function exchange_token( array $body ) {
		$grant = isset( $body['grant_type'] ) ? (string) $body['grant_type'] : '';
		if ( 'authorization_code' === $grant ) {
			return self::grant_authorization_code( $body );
		}
		if ( 'refresh_token' === $grant ) {
			return self::grant_refresh_token( $body );
		}
		return self::oauth_error( 'unsupported_grant_type', 'Unsupported grant_type.' );
	}

	/**
	 * authorization_code: single-use, expiry, bindings, PKCE — then mint.
	 *
	 * @param array $body POST body.
	 * @return array|\WP_Error
	 */
	private static function grant_authorization_code( array $body ) {
		$code     = isset( $body['code'] ) ? (string) $body['code'] : '';
		$key      = hash( 'sha256', $code );
		$state    = self::state();

		if ( '' === $code || ! isset( $state['codes'][ $key ] ) ) {
			return self::oauth_error( 'invalid_grant', 'Unknown or expired authorization code.' );
		}
		$entry = $state['codes'][ $key ];

		// Single-use: burn it before judging it, so a failed attempt can't retry.
		unset( $state['codes'][ $key ] );
		self::save( $state );

		if ( (int) $entry['expires'] < time() ) {
			return self::oauth_error( 'invalid_grant', 'Authorization code expired.' );
		}
		$client_id    = isset( $body['client_id'] ) ? (string) $body['client_id'] : '';
		$redirect_uri = isset( $body['redirect_uri'] ) ? (string) $body['redirect_uri'] : '';
		$verifier     = isset( $body['code_verifier'] ) ? (string) $body['code_verifier'] : '';
		if ( ! hash_equals( (string) $entry['client_id'], $client_id ) ) {
			return self::oauth_error( 'invalid_grant', 'client_id mismatch.' );
		}
		if ( ! hash_equals( (string) $entry['redirect_uri'], $redirect_uri ) ) {
			return self::oauth_error( 'invalid_grant', 'redirect_uri mismatch.' );
		}
		if ( '' === $verifier || ! hash_equals( (string) $entry['challenge'], self::s256( $verifier ) ) ) {
			return self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
		}

		return self::mint_tokens( (string) $entry['client_id'], (string) $entry['scope'], (int) $entry['user_id'] );
	}

	/**
	 * refresh_token: rotate — the old refresh token and its access token die
	 * the moment the new pair exists.
	 *
	 * @param array $body POST body.
	 * @return array|\WP_Error
	 */
	private static function grant_refresh_token( array $body ) {
		$refresh = isset( $body['refresh_token'] ) ? (string) $body['refresh_token'] : '';
		$rhash   = hash( 'sha256', $refresh );
		$state   = self::state();

		if ( '' === $refresh || ! isset( $state['refresh'][ $rhash ] ) ) {
			return self::oauth_error( 'invalid_grant', 'Unknown refresh token.' );
		}
		$entry     = $state['refresh'][ $rhash ];
		$client_id = isset( $body['client_id'] ) ? (string) $body['client_id'] : '';
		if ( '' !== $client_id && ! hash_equals( (string) $entry['client_id'], $client_id ) ) {
			return self::oauth_error( 'invalid_grant', 'client_id mismatch.' );
		}

		unset( $state['refresh'][ $rhash ] );
		if ( ! empty( $entry['access_hash'] ) ) {
			unset( $state['tokens'][ $entry['access_hash'] ] );
		}
		self::save( $state );

		return self::mint_tokens( (string) $entry['client_id'], (string) $entry['scope'], (int) $entry['user_id'] );
	}

	/**
	 * Mint an access + refresh pair, store fingerprints, return raw values —
	 * their only appearance.
	 *
	 * @param string $client_id Client the grant belongs to.
	 * @param string $scope     'read' or 'write'.
	 * @param int    $user_id   User the grant acts as.
	 * @return array
	 */
	private static function mint_tokens( $client_id, $scope, $user_id ) {
		$access  = self::PREFIX_ACCESS . bin2hex( random_bytes( 32 ) );
		$refresh = self::PREFIX_REFRESH . bin2hex( random_bytes( 32 ) );
		$ahash   = hash( 'sha256', $access );
		$rhash   = hash( 'sha256', $refresh );

		$state = self::state();

		$state['tokens'][ $ahash ] = array(
			'client_id' => (string) $client_id,
			'scope'     => 'write' === $scope ? 'write' : 'read',
			'user_id'   => (int) $user_id,
			'expires'   => time() + self::ACCESS_TTL,
		);
		$state['refresh'][ $rhash ] = array(
			'access_hash' => $ahash,
			'client_id'   => (string) $client_id,
			'scope'       => 'write' === $scope ? 'write' : 'read',
			'user_id'     => (int) $user_id,
			'expires'     => time() + self::REFRESH_TTL,
		);
		self::save( $state );

		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => 'write' === $scope ? 'write' : 'read',
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Authentication on the MCP route
	 * ---------------------------------------------------------------------- */

	/**
	 * Hook bearer authentication. Priority 31: after core (20) and after the
	 * connection token (30) — each inspector only ever fills an empty seat.
	 * Also advertises the auth server on MCP 401s, which is how a client
	 * discovers it can connect at all.
	 *
	 * @return void
	 */
	public static function watch() {
		add_filter( 'determine_current_user', array( self::class, 'authenticate' ), 31 );
		add_filter( 'rest_post_dispatch', array( self::class, 'advertise' ), 10, 3 );
		// The grant's seat is judged from the URL before core matches a route;
		// this holds it against the route core matched. See McpToken::confine().
		add_filter( 'rest_request_before_callbacks', array( self::class, 'confine' ), 0, 3 );
	}

	/**
	 * rest_post_dispatch: a 401 from the MCP route grows a WWW-Authenticate
	 * header pointing at the protected-resource metadata (RFC 9728 §5.1) —
	 * the breadcrumb the whole OAuth discovery walk starts from.
	 *
	 * @param mixed            $result  Dispatch result.
	 * @param \WP_REST_Server  $server  REST server (unused).
	 * @param \WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function advertise( $result, $server, $request ) {
		if ( $result instanceof \WP_REST_Response
			&& 401 === $result->get_status()
			&& McpToken::is_route( $request->get_route() ) ) { // The MCP route's own 401 — not a sibling admin route's.
			$result->header(
				'WWW-Authenticate',
				'Bearer resource_metadata="' . home_url( '/.well-known/oauth-protected-resource/agentimus/mcp' ) . '"'
			);
		}
		return $result;
	}

	/**
	 * determine_current_user: honor a valid access token on the MCP route.
	 * Anything else falls through untouched.
	 *
	 * @param int|false $user_id Whoever earlier auth already seated.
	 * @return int|false
	 */
	public static function authenticate( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		$token = McpToken::bearer_for( self::PREFIX_ACCESS );
		if ( '' === $token || ! McpToken::targets_mcp_route() ) {
			return $user_id;
		}

		$state = self::state();
		$hash  = hash( 'sha256', $token );
		if ( ! isset( $state['tokens'][ $hash ] ) ) {
			return $user_id;
		}
		$entry = $state['tokens'][ $hash ];
		if ( (int) $entry['expires'] < time() ) {
			return $user_id;
		}
		$owner = get_userdata( (int) $entry['user_id'] );
		if ( false === $owner || null === $owner ) {
			return $user_id; // The approving account is gone; its grants died with it.
		}

		$client              = self::client( (string) $entry['client_id'] );
		self::$request_grant = array(
			'client_id' => (string) $entry['client_id'],
			'scope'     => (string) $entry['scope'],
			'name'      => null !== $client ? $client['name'] : 'MCP client',
		);
		self::touch(); // The Connected-agents card's "last used" line lives or dies here.
		return (int) $entry['user_id'];
	}

	/**
	 * rest_request_before_callbacks: refuse a grant-seated request on any
	 * route but the MCP endpoint — the backstop the connection token keeps
	 * ({@see McpToken::confine()}), for the same reason: the seat is judged
	 * from the URL before core matches a route, and this is where the matched
	 * route can be held against it. Silent when no grant seated this request,
	 * and on errors already decided.
	 *
	 * @param mixed            $response Dispatch result so far (null = proceed).
	 * @param array            $handler  The matched handler (unused).
	 * @param \WP_REST_Request $request  The request core matched.
	 * @return mixed
	 */
	public static function confine( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || null === self::$request_grant ) {
			return $response;
		}
		if ( McpToken::is_route( $request->get_route() ) ) {
			return $response;
		}
		return McpToken::off_route_error();
	}

	/**
	 * Whether THIS request may run write tools. True for every non-OAuth auth
	 * path; a grant answers for its scope. Composed with the connection
	 * token's identical gate by the Registrar.
	 *
	 * @return bool
	 */
	public static function request_allows_writes() {
		return null === self::$request_grant || 'write' === self::$request_grant['scope'];
	}

	/**
	 * Wrap a write ability's permission callback with the grant-scope cap.
	 *
	 * @param callable $permission The ability's own permission callback.
	 * @return callable
	 */
	public static function gate_write_permission( callable $permission ) {
		return static function ( $input = null ) use ( $permission ) {
			if ( ! self::request_allows_writes() ) {
				return false;
			}
			return call_user_func( $permission, $input );
		};
	}

	/**
	 * The Agent-Access credential for this request when an OAuth grant
	 * authenticated it: `agentimus-oauth:<client_id>`, resolved back to
	 * "<name>'s connection" at render time — identity the owner approved.
	 *
	 * @return string '' when OAuth did not authenticate this request.
	 */
	public static function request_credential() {
		if ( null === self::$request_grant ) {
			return '';
		}
		return 'agentimus-oauth:' . self::$request_grant['client_id'];
	}

	/**
	 * Test seam: forget the request-grant memory between simulated requests.
	 *
	 * @return void
	 */
	public static function reset_request() {
		self::$request_grant = null;
	}

	/* ---------------------------------------------------------------------- *
	 *  The grants surface (Connected agents card)
	 * ---------------------------------------------------------------------- */

	/**
	 * Approved connections for the admin screen: one row per client that has
	 * been approved, with scope and liveness derived from its tokens. Screen-
	 * safe — names and timestamps, never fingerprints.
	 *
	 * @return array[]
	 */
	public static function grants() {
		$state = self::state();
		$rows  = array();
		foreach ( $state['clients'] as $client_id => $client ) {
			if ( empty( $client['approved'] ) ) {
				continue; // Registered but never approved — not a connection.
			}
			$rows[] = array(
				'clientId' => (string) $client_id,
				'name'     => (string) $client['name'],
				'host'     => self::redirect_host( $client ),
				'scope'    => isset( $client['scope'] ) && 'write' === $client['scope'] ? 'write' : 'read',
				'approved' => (int) $client['approved'],
				'lastUsed' => isset( $client['last_used'] ) ? (int) $client['last_used'] : null,
			);
		}
		return $rows;
	}

	/**
	 * Revoke ONE client's grant: its tokens, refresh tokens, and registration
	 * all go at once. Every other connection is untouched.
	 *
	 * @param string $client_id The client to disconnect.
	 * @return bool Whether anything was removed.
	 */
	public static function revoke_client( $client_id ) {
		$client_id = (string) $client_id;
		$state     = self::state();
		$found     = isset( $state['clients'][ $client_id ] );
		unset( $state['clients'][ $client_id ] );
		foreach ( array( 'codes', 'tokens', 'refresh' ) as $bucket ) {
			foreach ( $state[ $bucket ] as $key => $entry ) {
				if ( isset( $entry['client_id'] ) && $entry['client_id'] === $client_id ) {
					unset( $state[ $bucket ][ $key ] );
					$found = true;
				}
			}
		}
		if ( $found ) {
			self::save( $state );
		}
		return $found;
	}

	/**
	 * Revoke everything — every client, every token. The uninstall/reset path.
	 *
	 * @return void
	 */
	public static function revoke_all() {
		delete_option( self::OPTION );
	}

	/**
	 * Record a grant's use for the Connected-agents card, throttled like the
	 * connection token's last-used stamp — one write per minute per client.
	 *
	 * @return void
	 */
	public static function touch() {
		if ( null === self::$request_grant ) {
			return;
		}
		$client_id = self::$request_grant['client_id'];
		$state     = self::state();
		if ( ! isset( $state['clients'][ $client_id ] ) ) {
			return;
		}
		$last = isset( $state['clients'][ $client_id ]['last_used'] ) ? (int) $state['clients'][ $client_id ]['last_used'] : 0;
		if ( ( time() - $last ) < MINUTE_IN_SECONDS ) {
			return;
		}
		$state['clients'][ $client_id ]['last_used'] = time();
		self::save( $state );
	}

	/**
	 * Resolve an Agent-Access oauth credential back to its human label.
	 *
	 * @param string $cred Stored credential (e.g. "agentimus-oauth:agoc_…").
	 * @return string "<name>'s connection", or '' when it isn't an oauth cred
	 *                or the grant no longer exists (revoked — the UI words that).
	 */
	public static function credential_label( $cred ) {
		if ( 0 !== strpos( (string) $cred, 'agentimus-oauth:' ) ) {
			return '';
		}
		$client = self::client( substr( (string) $cred, strlen( 'agentimus-oauth:' ) ) );
		if ( null === $client ) {
			return '';
		}
		return $client['name'] . '’s connection';
	}

	/* ---------------------------------------------------------------------- *
	 *  State + helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Load state with defaults, pruning expired codes/tokens/refresh on the
	 * way so the option can never grow unbounded.
	 *
	 * @return array
	 */
	private static function state() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$state = array();
		foreach ( array( 'clients', 'codes', 'tokens', 'refresh' ) as $bucket ) {
			$state[ $bucket ] = isset( $stored[ $bucket ] ) && is_array( $stored[ $bucket ] ) ? $stored[ $bucket ] : array();
		}
		$now = time();
		foreach ( array( 'codes', 'tokens', 'refresh' ) as $bucket ) {
			foreach ( $state[ $bucket ] as $key => $entry ) {
				if ( ! isset( $entry['expires'] ) || (int) $entry['expires'] < $now ) {
					unset( $state[ $bucket ][ $key ] );
				}
			}
		}
		return $state;
	}

	/**
	 * Persist state. Autoload off — this option is touched only on OAuth and
	 * MCP requests, never on normal page views.
	 *
	 * @param array $state State to persist.
	 * @return void
	 */
	private static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Look up a registered client, shape-guaranteed.
	 *
	 * @param string $client_id Client id.
	 * @return array|null
	 */
	private static function client( $client_id ) {
		$client_id = (string) $client_id;
		if ( '' === $client_id ) {
			return null;
		}
		$state = self::state();
		if ( ! isset( $state['clients'][ $client_id ] ) || ! is_array( $state['clients'][ $client_id ] ) ) {
			return null;
		}
		$c = $state['clients'][ $client_id ];
		return array(
			'redirect_uris' => isset( $c['redirect_uris'] ) && is_array( $c['redirect_uris'] ) ? array_map( 'strval', $c['redirect_uris'] ) : array(),
			'name'          => isset( $c['name'] ) ? (string) $c['name'] : 'MCP client',
		);
	}

	/**
	 * When the roster is full, drop clients that registered but were never
	 * approved — bot registrations by definition; approved grants are never
	 * pruned this way.
	 *
	 * @param array $state State, modified in place.
	 * @return void
	 */
	private static function prune_unapproved_clients( array &$state ) {
		foreach ( $state['clients'] as $id => $client ) {
			if ( empty( $client['approved'] ) ) {
				unset( $state['clients'][ $id ] );
			}
		}
	}

	/**
	 * The host of a client's first redirect URI — the checkable fact the
	 * consent page and the grants list both show beside the claimed name.
	 *
	 * @param array $client Stored client entry.
	 * @return string
	 */
	private static function redirect_host( array $client ) {
		$uris = isset( $client['redirect_uris'] ) && is_array( $client['redirect_uris'] ) ? $client['redirect_uris'] : array();
		if ( empty( $uris ) ) {
			return '';
		}
		$host = wp_parse_url( (string) $uris[0], PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}

	/**
	 * Whether a requested scope string asks for write powers. Clients speak
	 * many dialects; anything naming `write` (or the `mcp` umbrella some
	 * clients send) counts as a write request. An empty scope asks for
	 * everything, so it counts too — the owner decides at the door anyway.
	 *
	 * @param string $requested Space-separated scope string.
	 * @return bool
	 */
	private static function scope_requests_write( $requested ) {
		$requested = trim( (string) $requested );
		if ( '' === $requested ) {
			return true;
		}
		$parts = preg_split( '/\s+/u', $requested );
		$parts = is_array( $parts ) ? $parts : array();
		return in_array( 'write', $parts, true ) || in_array( 'mcp', $parts, true );
	}

	/**
	 * Validate and clean one registered redirect URI, or '' if unusable.
	 *
	 * Deliberately NOT esc_url_raw(): that strips every scheme outside
	 * WordPress's web allow-list, which silently killed the custom schemes
	 * desktop MCP clients register (claude://…, cursor://…, vscode://…) —
	 * registration then failed with "no valid redirect_uri" and the app just
	 * said it couldn't sign in. Instead: require a syntactically real scheme,
	 * refuse the ones that can execute (javascript:, data:, vbscript:, file:,
	 * blob:), and refuse anything with whitespace or control characters, which
	 * is how a header-splitting payload would arrive.
	 *
	 * @param mixed $uri Candidate redirect URI from the registration request.
	 * @return string The URI, or '' when it must not be stored.
	 */
	private static function clean_redirect_uri( $uri ) {
		$uri = is_string( $uri ) ? trim( $uri ) : '';
		if ( '' === $uri || strlen( $uri ) > 512 ) {
			return '';
		}
		if ( preg_match( '/[\x00-\x20\x7F]/', $uri ) ) {
			return ''; // Whitespace or control bytes — never a real callback.
		}
		if ( ! preg_match( '#^([a-zA-Z][a-zA-Z0-9+.\-]*)://[^\s]+$#', $uri, $m ) ) {
			return ''; // Must be scheme://something — an opaque URI is not a callback.
		}
		$scheme = strtolower( $m[1] );
		if ( in_array( $scheme, array( 'javascript', 'data', 'vbscript', 'file', 'blob' ), true ) ) {
			return '';
		}
		return $uri;
	}

	/**
	 * BASE64URL(SHA256(verifier)) — the PKCE S256 transformation (RFC 7636).
	 *
	 * @param string $verifier Code verifier from the token request.
	 * @return string
	 */
	private static function s256( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * A WP_Error whose data carries the OAuth `error` code, so the token
	 * route can render the RFC 6749 error body.
	 *
	 * @param string $code    OAuth error code.
	 * @param string $message Human-readable description.
	 * @return \WP_Error
	 */
	private static function oauth_error( $code, $message ) {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status'            => 400,
				'error'             => $code,
				'error_description' => $message,
			)
		);
	}
}
