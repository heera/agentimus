<?php
/**
 * Reachability — does the address we advertise really open for a stranger?
 *
 * Every address in a discovery document carries a claim: `auth: none` means
 * "anyone may read this". Until this class existed, nothing ever checked that
 * claim. It was decided by whoever wrote the provider, from what was true on the
 * site they happened to test, and then published — signed — to anyone who asked.
 * Two ways that goes wrong, both seen for real:
 *
 *   1. The address is public on one site and locked on another. FluentCommunity
 *      serves its spaces list to strangers only while the community's own access
 *      setting says "public"; on a members-only community the same address
 *      answers 401. We advertised it on both.
 *   2. The address stops existing. A vendor renames a namespace or moves a route
 *      in an update, and we keep advertising a door that is no longer there —
 *      with every test still green, because nothing knocks.
 *
 * ⭐⭐ HIS RULE, 2026-08-17, and the reason this is not a per-plugin fix: *"this
 * will not work for other plugins that are automatically found, so we can't rely
 * on known facts — we must figure out the right way as if we know nothing about
 * a plugin, everything at runtime. If we are not able to figure something out
 * then we back off, but we will not just assume things."*
 *
 * So nothing here knows one plugin from another. It asks the site.
 *
 * ## The three rungs, in the order they are trusted
 *
 * 1. **A real anonymous request.** The only check that also sees a security
 *    plugin, a login wall, or a firewall rule — the things no amount of reading
 *    code can predict. A 2xx to a stranger IS the definition of public; a 401 or
 *    403 IS the definition of not. It decides whenever it says either.
 * 2. **The route's own permission callback, asked as nobody.** Every REST route
 *    registers one; it belongs to the plugin, not to us. `__return_true` is
 *    WordPress's own way of writing "anyone may call this" and needs no call at
 *    all. Anything else is a function we can simply run and read the answer.
 * 3. **Nothing.** Which is an answer too: we could not establish it, so we do not
 *    advertise it, and the Discovery screen says which address and why.
 *
 * ## What this class refuses to do
 *
 * ⛔ **It never runs on a page load.** The read path ({@see may_advertise()}) is
 * one option read. Every fetch and every callback happens in a background cron
 * event — his standing rule, and the reason a discovery document costs nothing
 * to serve.
 *
 * ⛔ **It never pretends to be nobody.** The answer "may a stranger read this"
 * is only true if the thing asking IS a stranger. In cron there is no signed-in
 * user, so we already are one — but that is CHECKED before a single verdict is
 * recorded ({@see refresh()}), because a cron run started by a signed-in tool
 * would answer for that person and quietly publish their access as everyone's.
 * We do not switch the current user to fake it: that would lie to every other
 * callback in the request.
 *
 * ⛔ **It never asks for anything but GET.** GET is all we ever advertise, and a
 * permission callback is only *supposed* to be a pure check — some do session or
 * counter work on the way. Read-only, one at a time, each in its own guard, so a
 * plugin that throws costs its own address and nothing else.
 *
 * ## Fail-open, deliberately
 *
 * An address nobody has looked at yet is advertised, exactly as it is today —
 * "not checked" is not evidence of a locked door, and a plugin update must not
 * empty every site's document until cron next fires. An address we DID look at
 * and could not establish is dropped. And an inconclusive run never overwrites a
 * verdict an earlier run reached: stale truth beats fresh nothing (the doctrine
 * {@see \Agentimus\RouteProbe} already runs on). It takes a real refusal, or a
 * first look that came back empty-handed, to make us go quiet.
 *
 * @package Agentimus
 */

namespace Agentimus\Discovery;

use Agentimus\Activity\Owner;

defined( 'ABSPATH' ) || exit;

final class Reachability {

	/** Stored verdicts (option; autoload off — only the publication gate reads it). */
	const OPTION = 'agentimus_reachability';

	/**
	 * The recurring check. ⚠️ Never equal to {@see FILTER}: actions and filters
	 * share one hook namespace, so a filter of the same name would invoke the cron
	 * callback and recurse — the trap RouteProbe documents.
	 */
	const CRON = 'agentimus_reachability_check';

	/** Filter tag on data() — the seam tests and site owners override. */
	const FILTER = 'agentimus_reachability';

	/** How long a verdict stands before the daily run is treated as overdue. */
	const STALE_AFTER = 2 * DAY_IN_SECONDS;

	/** One address's leash. Short: a slow door is a door we cannot promise. */
	const TIMEOUT = 5;

	/** Enough of a body to see we got one; we read the STATUS, not the content. */
	const MAX_BYTES = 2048;

	/**
	 * The most addresses one run will check. A site can register any number; the
	 * cap keeps a cron run bounded, and what it skipped is recorded rather than
	 * silently dropped — an unchecked address stays advertised, so a cap can only
	 * ever cost us a proof, never someone's row.
	 */
	const MAX_ADDRESSES = 60;

	/* ---------------------------------------------------------------------- *
	 *  The read path — one option, no network, no callbacks
	 * ---------------------------------------------------------------------- */

	/**
	 * Whether this address may be published as something a stranger can read.
	 *
	 * ⭐ THE THREE ANSWERS, and the middle one is the whole design:
	 *   • never looked  → yes (no information is not evidence of a locked door)
	 *   • proved public → yes
	 *   • anything else → no, including "we looked and could not tell"
	 *
	 * @param string $url The address as registered (site-relative or absolute).
	 * @return bool
	 */
	public static function may_advertise( $url ) {
		$record = self::record( $url );
		if ( null === $record ) {
			return true;
		}
		return 'public' === $record['state'];
	}

	/**
	 * The stored verdict for one address, or null when none has ever been reached.
	 *
	 * @param string $url The address as registered.
	 * @return array{state:string,why:string,code:int,checked_at:int}|null
	 */
	public static function record( $url ) {
		$key  = self::key( $url );
		$data = self::data();
		return ( '' !== $key && isset( $data['addresses'][ $key ] ) ) ? $data['addresses'][ $key ] : null;
	}

	/**
	 * Everything this class has stored.
	 *
	 * Shape: {
	 *   checked_at: int,     // when a run last completed
	 *   ran_as:     int,     // the user id the last run asked as; anything but 0 is a refusal to record
	 *   error:      string,  // why a whole run recorded nothing, '' when it ran
	 *   skipped:    int,     // addresses past MAX_ADDRESSES, left unchecked and so left advertised
	 *   addresses:  array<string,array{state:string,why:string,code:int,checked_at:int}>,
	 * }
	 *
	 * @return array
	 */
	public static function data() {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$raw = array_merge(
			array(
				'checked_at' => 0,
				'ran_as'     => 0,
				'error'      => '',
				'skipped'    => 0,
				'addresses'  => array(),
			),
			$raw
		);
		if ( ! is_array( $raw['addresses'] ) ) {
			$raw['addresses'] = array();
		}

		/**
		 * Override or inspect the stored reachability verdicts. Tests inject states
		 * here; a site owner can force an empty array to fall back to advertising
		 * everything the way it worked before this check existed.
		 *
		 * @param array $raw The stored verdicts.
		 */
		return (array) apply_filters( self::FILTER, $raw );
	}

	/* ---------------------------------------------------------------------- *
	 *  Scheduling
	 * ---------------------------------------------------------------------- */

	/**
	 * Hook the cron handler and keep the daily event alive. A plugin coming or
	 * going is exactly when an address changes hands, so those re-queue at once.
	 *
	 * @return void
	 */
	public static function watch() {
		add_action( self::CRON, array( self::class, 'refresh' ) );
		add_action( 'activated_plugin', array( self::class, 'schedule_soon' ) );
		add_action( 'deactivated_plugin', array( self::class, 'schedule_soon' ) );

		if ( ! wp_next_scheduled( self::CRON ) ) {
			// A minute out, not immediately: on a fresh activation the plugins that
			// register the routes may not all have run yet.
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Bring the next run forward.
	 *
	 * @return void
	 */
	public static function schedule_soon() {
		$next = (int) wp_next_scheduled( self::CRON );
		if ( $next && $next <= time() + MINUTE_IN_SECONDS ) {
			return; // Already about to run.
		}
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON );
	}

	/**
	 * Clear the schedule (deactivation).
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** Whether the stored verdicts are old enough that the schedule looks broken. */
	public static function is_stale() {
		$data = self::data();
		return (int) $data['checked_at'] > 0 && ( time() - (int) $data['checked_at'] ) > self::STALE_AFTER;
	}

	/* ---------------------------------------------------------------------- *
	 *  The run — cron only
	 * ---------------------------------------------------------------------- */

	/**
	 * Check every address this site would advertise as needing no sign-in.
	 *
	 * @param array|null $candidates Addresses to check, for tests. Null = collect them.
	 * @return array The stored data after the run.
	 */
	public static function refresh( $candidates = null ) {
		$previous = self::data();

		// ⛔ THE GUARD THIS WHOLE CLASS RESTS ON. "Can a stranger read it" is only
		// answerable by a stranger. In cron nobody is signed in, so we already are
		// one — but a run started by a signed-in tool (wp-cli --user, an admin
		// hitting wp-cron.php with cookies) would answer for THAT person and
		// publish their access as everyone's. We do not switch the user to fix it:
		// impersonating nobody would lie to every other callback in the request.
		$user = get_current_user_id();
		if ( 0 !== (int) $user ) {
			$previous['ran_as'] = (int) $user;
			$previous['error']  = __( 'Skipped: something was signed in, so the answers would have been theirs and not a stranger’s.', 'agentimus' );
			update_option( self::OPTION, $previous, false );
			return $previous;
		}

		$candidates = null === $candidates ? self::candidates() : (array) $candidates;
		$skipped    = max( 0, count( $candidates ) - self::MAX_ADDRESSES );
		$candidates = array_slice( $candidates, 0, self::MAX_ADDRESSES );

		$token   = class_exists( Owner::class ) ? Owner::mint_probe_token() : '';
		$now     = time();
		$results = array();

		foreach ( $candidates as $url ) {
			$key = self::key( $url );
			if ( '' === $key ) {
				continue;
			}
			$verdict = self::decide( $key, $token );

			// ⭐ Stale truth beats fresh nothing. An inconclusive look never
			// overwrites a verdict an earlier run reached — a blocked loopback or a
			// plugin that threw once must not quietly un-advertise a working
			// address. Only a real answer moves the needle.
			if ( 'unknown' === $verdict['state'] && isset( $previous['addresses'][ $key ]['state'] ) && 'unknown' !== $previous['addresses'][ $key ]['state'] ) {
				$kept               = $previous['addresses'][ $key ];
				$kept['unsure_at']  = $now;
				$kept['unsure_why'] = $verdict['why'];
				$results[ $key ]    = $kept;
				continue;
			}

			$verdict['checked_at'] = $now;
			$results[ $key ]       = $verdict;
		}

		$data = array(
			'checked_at' => $now,
			'ran_as'     => 0,
			'error'      => '',
			'skipped'    => $skipped,
			'addresses'  => $results,
		);
		update_option( self::OPTION, $data, false );

		return $data;
	}

	/**
	 * One address, decided.
	 *
	 * @param string $key   Site-relative path, already normalised.
	 * @param string $token This run's self-check token.
	 * @return array{state:string,why:string,code:int}
	 */
	private static function decide( $key, $token ) {
		$handler = self::handler_for( $key );

		// RUNG 1 — a real anonymous request. It is the only check that sees a
		// security plugin, a login wall or a firewall, so when it speaks plainly it
		// decides, even against the route's own callback: a stranger who really
		// gets a 200 can read this, whatever the code says, and a stranger who
		// really gets a 401 cannot.
		$code = self::ask_over_http( $key, $token );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'state' => 'public', 'why' => 'answered', 'code' => $code );
		}
		if ( 401 === $code || 403 === $code ) {
			return array( 'state' => 'refused', 'why' => 'refused-a-stranger', 'code' => $code );
		}
		// 404 with nothing registered here is the vendor-moved-it case: the address
		// is gone, and advertising it is how we would have carried on for months.
		if ( 404 === $code && null === $handler ) {
			return array( 'state' => 'refused', 'why' => 'gone', 'code' => $code );
		}

		// RUNG 2 — the route's own permission check, asked as nobody.
		if ( null !== $handler ) {
			$asked = self::ask_the_route( $key, $handler );
			if ( true === $asked['allowed'] ) {
				return array( 'state' => 'public', 'why' => $asked['why'], 'code' => $code );
			}
			if ( false === $asked['allowed'] ) {
				return array( 'state' => 'refused', 'why' => $asked['why'], 'code' => $code );
			}
			return array( 'state' => 'unknown', 'why' => $asked['why'], 'code' => $code );
		}

		// RUNG 3 — nothing. Which is an answer: we do not advertise it, and the
		// screen names it rather than letting the row disappear without a word.
		return array( 'state' => 'unknown', 'why' => 0 === $code ? 'could-not-reach' : 'no-route', 'code' => $code );
	}

	/**
	 * Ask the route's own permission callback, as nobody.
	 *
	 * ⭐ `__return_true` is answered without calling anything: it is WordPress's
	 * own way of writing "anyone may call this", and reading it is certain where
	 * running it is merely likely. WooCommerce's Store API is registered exactly
	 * that way.
	 *
	 * @param string $key     Site-relative path.
	 * @param array  $handler The GET handler from the route table.
	 * @return array{allowed:bool|null,why:string}
	 */
	private static function ask_the_route( $key, array $handler ) {
		$callback = isset( $handler['permission_callback'] ) ? $handler['permission_callback'] : null;

		if ( is_string( $callback ) && '__return_true' === $callback ) {
			return array( 'allowed' => true, 'why' => 'declared-open' );
		}
		if ( null === $callback ) {
			// WordPress lets a route with no permission callback through, but the
			// author almost certainly forgot rather than decided. ⛔ We will not read
			// somebody's oversight as their permission.
			return array( 'allowed' => null, 'why' => 'no-permission-check' );
		}
		if ( ! is_callable( $callback ) ) {
			return array( 'allowed' => null, 'why' => 'unreadable-permission-check' );
		}

		try {
			$request = new \WP_REST_Request( 'GET', $handler['route'] );
			$answer  = call_user_func( $callback, $request );
		} catch ( \Throwable $e ) {
			// One plugin's mess costs its own address and nobody else's.
			return array( 'allowed' => null, 'why' => 'permission-check-errored' );
		}

		if ( true === $answer ) {
			return array( 'allowed' => true, 'why' => 'allowed-a-stranger' );
		}
		return array( 'allowed' => false, 'why' => 'refused-a-stranger' );
	}

	/**
	 * One anonymous GET against this site's own address.
	 *
	 * ⚠️ It leaves from the server, so it proves WordPress and everything inside
	 * it — and can miss a rule that lives at the edge (Cloudflare, a WAF). It is
	 * still the closest thing to a stranger we can be, and infinitely closer than
	 * the nothing that was here before.
	 *
	 * ⛔ No cookies, no auth header, and the self-check token so the activity log
	 * skips it — our own probe must never appear as agent traffic.
	 *
	 * @param string $key   Site-relative path.
	 * @param string $token This run's self-check token.
	 * @return int HTTP status, or 0 when the request itself failed.
	 */
	private static function ask_over_http( $key, $token ) {
		$response = wp_remote_get(
			home_url( $key ),
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES,
				'cookies'             => array(),
				'user-agent'          => 'Agentimus/' . ( defined( 'AGENTIMUS_VERSION' ) ? AGENTIMUS_VERSION : '' ) . ' self-check',
				'headers'             => array(
					'Accept'                 => 'application/json',
					'Cache-Control'          => 'no-cache',
					'X-Agentimus-Selfcheck'  => $token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return 0;
		}
		return (int) wp_remote_retrieve_response_code( $response );
	}

	/* ---------------------------------------------------------------------- *
	 *  Finding the route behind an address
	 * ---------------------------------------------------------------------- */

	/**
	 * The GET handler this site has registered for an address, or null.
	 *
	 * ⚠️ A route missing when we look is NOT proof it does not exist — the REST
	 * route table is built by plugins on `rest_api_init`, and what has run depends
	 * on the request. That is precisely why this never decides alone: a missing
	 * route only becomes "gone" when a real request also came back 404.
	 *
	 * @param string $key Site-relative path, e.g. /wp-json/wc/store/v1/products.
	 * @return array|null The handler, with its `route` pattern added.
	 */
	private static function handler_for( $key ) {
		if ( ! function_exists( 'rest_get_server' ) || ! function_exists( 'rest_get_url_prefix' ) ) {
			return null;
		}

		$prefix = '/' . trim( (string) rest_get_url_prefix(), '/' );
		if ( 0 !== strpos( $key, $prefix . '/' ) && $key !== $prefix ) {
			return null; // Not a REST address; nothing here can speak for it.
		}
		$route = substr( $key, strlen( $prefix ) );
		$route = '' === $route ? '/' : $route;

		$routes = (array) rest_get_server()->get_routes();

		$found = isset( $routes[ $route ] ) ? $routes[ $route ] : null;
		if ( null === $found ) {
			// Registered routes are patterns; ours is a concrete path.
			foreach ( $routes as $pattern => $handlers ) {
				if ( preg_match( '@^' . str_replace( '@', '\\@', (string) $pattern ) . '$@i', $route ) ) {
					$found = $handlers;
					$route = $pattern;
					break;
				}
			}
		}
		if ( null === $found ) {
			return null;
		}

		foreach ( (array) $found as $handler ) {
			if ( ! is_array( $handler ) || empty( $handler['methods'] ) ) {
				continue;
			}
			// ⛔ GET only. It is all we advertise, and it is the only method whose
			// permission check is safe to run for an answer.
			if ( empty( $handler['methods']['GET'] ) ) {
				continue;
			}
			$handler['route'] = $route;
			return $handler;
		}
		return null;
	}

	/* ---------------------------------------------------------------------- *
	 *  Candidates
	 * ---------------------------------------------------------------------- */

	/**
	 * Every address this site would publish as needing no sign-in.
	 *
	 * ⛔ Only endpoints that CLAIM to be open are candidates. An endpoint honestly
	 * declared `apikey` or `oauth2` makes no promise to check — we are already
	 * telling readers it is locked.
	 *
	 * @return string[] Site-relative paths.
	 */
	public static function candidates() {
		$registry = Registry::instance()->collect();

		$out = array();
		foreach ( (array) $registry->resources() as $resource ) {
			if ( empty( $resource['endpoints'] ) || ! is_array( $resource['endpoints'] ) ) {
				continue;
			}
			foreach ( $resource['endpoints'] as $endpoint ) {
				if ( ! self::claims_to_be_open( $endpoint, $resource ) ) {
					continue;
				}
				$key = self::key( isset( $endpoint['url'] ) ? $endpoint['url'] : '' );
				if ( '' !== $key && ! in_array( $key, $out, true ) ) {
					$out[] = $key;
				}
			}
		}
		return $out;
	}

	/**
	 * Whether an endpoint is being published as readable without a sign-in.
	 *
	 * @param array $endpoint One endpoint.
	 * @param array $resource Its resource, whose auth is the fallback.
	 * @return bool
	 */
	public static function claims_to_be_open( $endpoint, $resource = array() ) {
		if ( ! is_array( $endpoint ) || empty( $endpoint['url'] ) ) {
			return false;
		}
		// Only REST addresses. A GraphQL or MCP door does not answer a GET with a
		// status that means what this class needs it to mean.
		if ( isset( $endpoint['type'] ) && 'rest' !== $endpoint['type'] ) {
			return false;
		}

		$auth = isset( $endpoint['auth'] ) ? (string) $endpoint['auth'] : '';
		if ( '' === $auth ) {
			$auth = isset( $resource['auth']['type'] ) ? (string) $resource['auth']['type'] : 'none';
		}
		return 'none' === $auth || '' === $auth;
	}

	/**
	 * One address, written the one way this class stores addresses: the path on
	 * this site.
	 *
	 * ⛔ '' for anything not on this site. We check our own doors, never somebody
	 * else's server — and an off-site address is left alone entirely, so it stays
	 * advertised exactly as it was.
	 *
	 * @param string $url As registered: site-relative or absolute.
	 * @return string Site-relative path, or ''.
	 */
	public static function key( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( '/' === $url[0] ) {
			return '/' . ltrim( strtok( $url, '?' ), '/' );
		}

		$home = home_url();
		if ( 0 !== strpos( $url, $home ) ) {
			return '';
		}
		$path = substr( $url, strlen( $home ) );
		$path = strtok( (string) $path, '?' );
		return '' === $path ? '/' : '/' . ltrim( $path, '/' );
	}
}
