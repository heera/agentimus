<?php
/**
 * Pause and the call limit, per connection, on the MCP route.
 *
 * A connection is one approved app, the shared token, or one application
 * password. A paused one is refused every call, with a sentence that says
 * so, while its keys stay: resuming needs no new approval. The call limit
 * (Settings `mcp_call_limit`, off by default, his call 2026-11-25) counts each
 * connection's requests per clock minute and refuses the rest of that minute
 * with 429 and Retry-After.
 *
 * ⛔ Refused after sign-in, not at it. Refusing to seat a paused app would
 * answer 401, which tells an app to sign in again: it would send the owner
 * an approval page for a connection that is merely paused.
 *
 * The count is per clock minute. With a persistent object cache it is atomic;
 * without one it is read then written, so requests that land at the same
 * instant can pass the last slot together. A limit is a brake on a busy agent,
 * not a security boundary; pause and Disconnect are the hard stops.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\McpToken;
use Agentimus\Oauth\Server;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class ConnectionGuard {

	/** The limits the owner can choose, calls per minute per connection; 0 = off. */
	const LIMITS = array( 0, 30, 60, 120, 300 );

	/** @var Settings|null */
	private static $settings = null;

	/** @var int Seconds to wait, set when this request was refused for the limit. */
	private static $retry_after = 0;

	/**
	 * Hooks. Priority 2: after the seats are confined to the MCP route (0) and
	 * HardBlocks starts (1).
	 *
	 * @param Settings $settings The core settings.
	 * @return void
	 */
	public static function watch( Settings $settings ) {
		self::$settings = $settings;
		add_filter( 'rest_request_before_callbacks', array( self::class, 'check' ), 2, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'retry_header' ), 10, 3 );
	}

	/**
	 * rest_request_before_callbacks: refuse a paused connection, then one over
	 * the limit. Silent off the MCP route, for a browser login, and on errors
	 * already decided.
	 *
	 * @param mixed            $response Dispatch result so far (null = proceed).
	 * @param array            $handler  The matched handler (unused).
	 * @param \WP_REST_Request $request  The request core matched.
	 * @return mixed
	 */
	public static function check( $response, $handler, $request ) {
		self::$retry_after = 0;
		if ( is_wp_error( $response ) || ! McpToken::is_route( $request->get_route() ) ) {
			return $response;
		}
		$conn = self::connection();
		if ( null === $conn ) {
			return $response;
		}
		if ( self::paused( $conn ) ) {
			return new \WP_Error(
				'agentimus_connection_paused',
				__( 'This connection is paused by the site owner. Nothing changed; it works again once they resume it.', 'agentimus' ),
				array( 'status' => 403 )
			);
		}
		$limit = self::limit();
		if ( $limit > 0 ) {
			$wait = self::spend( $conn, $limit, time() );
			if ( $wait > 0 ) {
				self::$retry_after = $wait;
				return new \WP_Error(
					'agentimus_call_limit',
					sprintf(
						/* translators: 1: calls per minute, 2: seconds to wait. */
						__( 'Too many calls: this site allows %1$d a minute for each connection. Try again in %2$d seconds.', 'agentimus' ),
						$limit,
						$wait
					),
					array( 'status' => 429 )
				);
			}
		}
		return $response;
	}

	/**
	 * rest_post_dispatch: a limit refusal tells the app when to try again.
	 *
	 * @param mixed $result Dispatch result.
	 * @return mixed
	 */
	public static function retry_header( $result ) {
		if ( self::$retry_after > 0 && $result instanceof \WP_REST_Response && 429 === $result->get_status() ) {
			$result->header( 'Retry-After', (string) self::$retry_after );
		}
		return $result;
	}

	/**
	 * Which connection this request came through, or null for one that is not
	 * a connection (a browser login).
	 *
	 * @return array{kind: string, id: string}|null
	 */
	public static function connection() {
		$client = Server::request_client_id();
		if ( '' !== $client ) {
			return array( 'kind' => 'grant', 'id' => $client );
		}
		if ( McpToken::used_this_request() ) {
			return array( 'kind' => 'token', 'id' => 'shared' );
		}
		if ( function_exists( 'rest_get_authenticated_app_password' ) ) {
			$uuid = rest_get_authenticated_app_password();
			if ( null !== $uuid && '' !== (string) $uuid ) {
				return array( 'kind' => 'app', 'id' => (string) $uuid );
			}
		}
		return null;
	}

	/**
	 * Whether this connection is paused. Application passwords have no pause
	 * here: WordPress's own screen revokes them.
	 *
	 * @param array $conn From connection().
	 * @return bool
	 */
	private static function paused( array $conn ) {
		if ( 'grant' === $conn['kind'] ) {
			return Server::is_paused( $conn['id'] );
		}
		if ( 'token' === $conn['kind'] ) {
			return McpToken::is_paused();
		}
		return false;
	}

	/**
	 * The site's limit, calls per minute per connection; 0 = none.
	 *
	 * @return int
	 */
	private static function limit() {
		$limit = null === self::$settings ? 0 : (int) self::$settings->get( 'mcp_call_limit', 0 );
		return in_array( $limit, self::LIMITS, true ) ? $limit : 0;
	}

	/**
	 * Count one call for this connection in this clock minute. Returns 0 when
	 * it fits, else the seconds until the next minute begins.
	 *
	 * @param array $conn  From connection().
	 * @param int   $limit Calls allowed per minute.
	 * @param int   $now   The time.
	 * @return int
	 */
	public static function spend( array $conn, $limit, $now ) {
		$key    = 'agentimus_calls_' . md5( $conn['kind'] . ':' . $conn['id'] );
		$minute = (int) floor( $now / MINUTE_IN_SECONDS );
		$wait   = max( 1, MINUTE_IN_SECONDS - ( $now % MINUTE_IN_SECONDS ) );

		// A persistent object cache (Redis, Memcached) adds one atomically, so
		// calls landing together cannot all take the last slot. Measured on
		// heera.it 2026-11-25: ten at a time against a limit of 30 let 35
		// through on the read-then-write count below.
		if ( wp_using_ext_object_cache() ) {
			$slot = $key . '_' . $minute;
			wp_cache_add( $slot, 0, 'agentimus', 2 * MINUTE_IN_SECONDS );
			$count = wp_cache_incr( $slot, 1, 'agentimus' );
			if ( false !== $count ) {
				return $count > $limit ? $wait : 0;
			}
		}

		$seen   = get_transient( $key );
		$count  = is_array( $seen ) && isset( $seen['m'], $seen['n'] ) && (int) $seen['m'] === $minute ? (int) $seen['n'] : 0;
		if ( $count >= $limit ) {
			return $wait;
		}
		set_transient( $key, array( 'm' => $minute, 'n' => $count + 1 ), 2 * MINUTE_IN_SECONDS );
		return 0;
	}
}
