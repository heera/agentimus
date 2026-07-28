<?php
/**
 * MCP connection token — one revocable secret that connects any MCP client to
 * this site's server, in place of minting an application password per client.
 *
 * Doctrine:
 *  - The plaintext is shown ONCE at creation; only a SHA-256 fingerprint is
 *    stored. Lost tokens are rotated, never recovered.
 *  - The token is a door key, the settings are the walls: a read-only token
 *    never runs a write tool, and a write token still obeys the write-tier
 *    settings — it can never do more than the owner allowed there.
 *  - Token auth acts as the WordPress user who created it (capability checks
 *    and the Agent Access audit trail stay exactly as they are for every
 *    other auth path), and it is honored ONLY on the MCP route.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class McpToken {

	/** Stored state: fingerprint + metadata, never the plaintext. */
	const OPTION = 'agentimus_mcp_token';

	/** Token shape: agmcp_<r|w>_<40 hex>. The prefix makes leaked-secret scanning possible. */
	const PREFIX = 'agmcp_';

	/** Write last-used at most this often — one UPDATE per burst, not per call. */
	const LAST_USED_THROTTLE = MINUTE_IN_SECONDS;

	/** The MCP REST route this token is honored on — and nowhere else. */
	const ROUTE = '/agentimus/v1/mcp';

	/**
	 * Sentinel stored as the Agent-Access credential for token-authenticated
	 * requests. Shaped so it can never collide with an application-password
	 * UUID, and resolved back to a human label at render time.
	 */
	const CRED = 'agentimus-connection-token';

	/** @var string|null Scope of the token that authenticated THIS request, or null when token auth was not used. */
	private static $request_scope = null;

	/**
	 * Hook token authentication. Runs after core's cookie and application
	 * password checks (both priority 20) so it only ever fills an empty seat.
	 *
	 * @return void
	 */
	public static function watch() {
		add_filter( 'determine_current_user', array( self::class, 'authenticate' ), 30 );
	}

	/**
	 * Create the token (rotating replaces the old one — every connected client
	 * is out the moment this saves). Returns the plaintext exactly once.
	 *
	 * @param string $scope   'read' or 'write'.
	 * @param int    $user_id The owner the token acts as.
	 * @return string The plaintext token.
	 */
	public static function create( $scope, $user_id ) {
		$scope = 'write' === $scope ? 'write' : 'read';
		$token = self::PREFIX . ( 'write' === $scope ? 'w' : 'r' ) . '_' . bin2hex( random_bytes( 20 ) );

		update_option(
			self::OPTION,
			array(
				'hash'         => hash( 'sha256', $token ),
				'scope'        => $scope,
				'user_id'      => (int) $user_id,
				'created_at'   => time(),
				'last_used_at' => null,
				'last_used_ua' => '',
			),
			false
		);

		return $token;
	}

	/**
	 * Revoke — every client is disconnected at once.
	 *
	 * @return void
	 */
	public static function revoke() {
		delete_option( self::OPTION );
	}

	/**
	 * Safe metadata for the admin screen. Never includes the fingerprint.
	 *
	 * @return array|null Null when no token exists.
	 */
	public static function status() {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || empty( $state['hash'] ) ) {
			return null;
		}
		return array(
			'scope'        => (string) $state['scope'],
			'created_at'   => (int) $state['created_at'],
			'last_used_at' => empty( $state['last_used_at'] ) ? null : (int) $state['last_used_at'],
			'last_used_ua' => (string) $state['last_used_ua'],
		);
	}

	/**
	 * determine_current_user filter: honor a valid Bearer token on the MCP
	 * route. Anything else — wrong route, wrong prefix, wrong token, missing
	 * owner — falls through untouched, so every other auth path is exactly as
	 * strict as before this class existed.
	 *
	 * @param int|false $user_id Whoever earlier auth already seated.
	 * @return int|false
	 */
	public static function authenticate( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		$token = self::bearer();
		if ( '' === $token || 0 !== strpos( $token, self::PREFIX ) || ! self::is_mcp_request() ) {
			return $user_id;
		}

		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || empty( $state['hash'] ) ) {
			return $user_id;
		}
		if ( ! hash_equals( (string) $state['hash'], hash( 'sha256', $token ) ) ) {
			return $user_id;
		}
		$owner = get_userdata( (int) $state['user_id'] );
		if ( false === $owner || null === $owner ) {
			return $user_id; // The owning account is gone; the token died with it.
		}

		self::$request_scope = (string) $state['scope'];
		self::touch( $state );
		return (int) $state['user_id'];
	}

	/**
	 * Whether THIS request may run write tools. True for every non-token auth
	 * path (application passwords and cookies answer to the settings alone);
	 * a token answers for its scope on top of them.
	 *
	 * @return bool
	 */
	public static function request_allows_writes() {
		return null === self::$request_scope || 'write' === self::$request_scope;
	}

	/**
	 * Wrap a write ability's permission callback with the token-scope cap.
	 * Central seam: the Registrar wraps every non-readonly ability through
	 * here, so a read-only token cannot run ANY write tool, current or future.
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
	 * Whether the connection token authenticated THIS request. Lets the
	 * Agent-Access recorder name the door that was used instead of falling
	 * back to "a logged-in session".
	 *
	 * @return bool
	 */
	public static function used_this_request() {
		return null !== self::$request_scope;
	}

	/**
	 * Test seam: forget the request-scope memory between simulated requests.
	 *
	 * @return void
	 */
	public static function reset_request() {
		self::$request_scope = null;
	}

	/**
	 * Record a use — throttled, so a busy agent costs one row write per
	 * minute, not one per tool call.
	 *
	 * @param array $state The stored option value (already validated).
	 * @return void
	 */
	private static function touch( array $state ) {
		$now = time();
		if ( ! empty( $state['last_used_at'] ) && ( $now - (int) $state['last_used_at'] ) < self::LAST_USED_THROTTLE ) {
			return;
		}
		$ua                    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 120 ) : '';
		$state['last_used_at'] = $now;
		$state['last_used_ua'] = $ua;
		update_option( self::OPTION, $state, false );
	}

	/**
	 * The Bearer credential of this request when it wears the given prefix,
	 * or ''. Shared with the OAuth server — the prefix says which door a
	 * credential belongs to, so each authenticator only ever sees its own.
	 *
	 * @param string $prefix Expected secret prefix (agmcp_, agoa_, …).
	 * @return string
	 */
	public static function bearer_for( $prefix ) {
		$token = self::bearer();
		return 0 === strpos( $token, (string) $prefix ) ? $token : '';
	}

	/**
	 * The Bearer credential of this request, or ''. Reads the usual header and
	 * the REDIRECT_ fallback some FastCGI setups leave it under.
	 *
	 * @return string
	 */
	private static function bearer() {
		$header = '';
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$header = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				break;
			}
		}
		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return '';
		}
		return trim( substr( $header, 7 ) );
	}

	/**
	 * Whether this request targets the MCP route — pretty permalinks or the
	 * ?rest_route= form. Public because the OAuth server honors its access
	 * tokens on exactly the same route and nowhere else.
	 *
	 * @return bool
	 */
	public static function targets_mcp_route() {
		return self::is_mcp_request();
	}

	/**
	 * Whether this request targets the MCP route — pretty permalinks or the
	 * ?rest_route= form. The token is honored nowhere else.
	 *
	 * @return bool
	 */
	private static function is_mcp_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false !== strpos( $uri, self::ROUTE ) ) {
			return true;
		}
		$route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing detection only, no state change.
		return 0 === strpos( $route, self::ROUTE );
	}
}
