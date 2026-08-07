<?php
/**
 * Identity probe — asks whether the home page a crawler declares in its own
 * User-Agent actually answers.
 *
 * Well-behaved crawlers put a URL in their UA ("+https://example.com/bot") and
 * keep a page there explaining who they are and how to block them. Agentimus
 * already parses that URL out and shows it ({@see Catalog::self_declared()}),
 * labelled as the client's claim. Nothing ever asked whether it leads anywhere —
 * so a bot pointing at a 404 looked exactly like one pointing at a real page.
 *
 * Doctrine (mirrors RouteProbe): the read path NEVER fetches. The review queue
 * records which URLs it saw and reads the stored result; the requests themselves
 * run in a one-off cron event, at most one per host per week, and only ever
 * through wp_safe_remote_get — these URLs come from strangers, so WP's own
 * private-range rejection is what stands between us and an SSRF.
 *
 * This changes no verdict and blocks nothing. It is evidence for the owner, not
 * a rule: a dead URL is a reason to look closer, not proof of bad intent, and
 * "we couldn't reach it" is reported as saying nothing at all, because a timeout
 * may be this server's own network.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

defined( 'ABSPATH' ) || exit;

final class IdentityProbe {

	/** Stored results (option; autoload off — only the review queue reads it). */
	const OPTION = 'agentimus_identity_probes';

	/**
	 * Filter tag on the stored map — the seam tests and site owners override.
	 * WordPress actions and filters share ONE hook namespace, so this must never
	 * equal CRON: apply_filters( FILTER ) would invoke the cron-registered run()
	 * and recurse without end (the trap RouteProbe documents). A test locks the
	 * two apart.
	 */
	const FILTER = 'agentimus_identity_probes';

	/** The one-off cron event that runs the outbound requests. */
	const CRON = 'agentimus_identity_probe_run';

	/** How long a result stands before the URL is looked at again. */
	const RECHECK_AFTER = 7 * DAY_IN_SECONDS;

	/** Requests per cron run, and never twice to the same host in one run. */
	const PER_RUN = 5;

	/** Most URLs kept on record; the least recently declared fall off first. */
	const MAX_URLS = 60;

	/**
	 * Most URLs kept for any ONE host. A real crawler declares one home page, so
	 * this costs nothing legitimate — and without it, a flood of user-agents all
	 * naming different paths on one victim host would turn this site into a slow
	 * drip of requests against it. Two, not one, so a genuine operator running
	 * two crawlers off one domain is still described honestly.
	 */
	const MAX_PER_HOST = 2;

	/** A URL no live client has declared for this long is forgotten entirely. */
	const FORGET_AFTER = 30 * DAY_IN_SECONDS;

	/** Response cap — we need to know something answered, not what it said. */
	const MAX_BYTES = 8192;

	/** Seconds to wait. Short: nobody is watching this run. */
	const TIMEOUT = 5;

	/** Longest URL accepted from a User-Agent string. */
	const MAX_URL_LEN = 300;

	/** The page answered. */
	const ANSWERS = 'answers';

	/** The host is there and says there is no such page — or there is no host. */
	const MISSING = 'missing';

	/** Reached nothing conclusive. Reported as saying nothing. */
	const UNREACHED = 'unreached';

	/**
	 * Hook the cron handler. Nothing is scheduled here — a probe is only ever
	 * queued by {@see observe()}, i.e. when a self-declared client is actually
	 * on the review queue. A site that never sees one never makes a request.
	 *
	 * @return void
	 */
	public static function watch() {
		add_action( self::CRON, array( self::class, 'run' ) );
	}

	/**
	 * Clear the queued event (deactivation).
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Note the self-declared URLs currently on the review queue, and queue the
	 * background run when any of them is due a look.
	 *
	 * Called from a read path (the queue, the admin bell), so it must stay cheap
	 * and must never fetch: it writes the option only when something actually
	 * changed, and "last seen" is kept to the hour so an idle admin session
	 * doesn't write on every page load.
	 *
	 * @param array<int,string> $urls Declared URLs, as parsed from live User-Agents.
	 * @return void
	 */
	public static function observe( array $urls ) {
		$store   = self::store();
		$now     = time();
		$changed = false;

		foreach ( $urls as $url ) {
			$url = self::normalize( $url );
			if ( '' === $url ) {
				continue;
			}
			if ( ! isset( $store[ $url ] ) ) {
				$store[ $url ] = array(
					'state' => '',
					'code'  => 0,
					'at'    => 0,
					'seen'  => $now,
				);
				$changed = true;
			} elseif ( ( $now - (int) $store[ $url ]['seen'] ) > HOUR_IN_SECONDS ) {
				$store[ $url ]['seen'] = $now;
				$changed               = true;
			}
		}

		// prune() only ever drops entries, so a smaller map is exactly "something
		// was forgotten". Compared by count, not identity: prune() re-orders as it
		// decides what to drop, and an order-only difference must not cost a write
		// on every admin page load.
		$pruned = self::prune( $store, $now );
		if ( count( $pruned ) !== count( $store ) ) {
			$store   = $pruned;
			$changed = true;
		}

		if ( $changed ) {
			self::save( $store );
		}
		if ( null !== self::next_due( $store, $now ) ) {
			self::schedule_soon();
		}
	}

	/**
	 * What the last probe of this URL found, or null when it has never been
	 * probed — which is also what a caller sees for the first few minutes after
	 * a new crawler appears. Callers must render null as "not checked", never as
	 * a result.
	 *
	 * @param string $url The declared URL.
	 * @return array{state:string,code:int,at:string}|null
	 */
	public static function state( $url ) {
		$url   = self::normalize( $url );
		$store = self::store();
		if ( '' === $url || empty( $store[ $url ]['state'] ) ) {
			return null;
		}
		return array(
			'state' => (string) $store[ $url ]['state'],
			'code'  => (int) $store[ $url ]['code'],
			'at'    => gmdate( 'c', (int) $store[ $url ]['at'] ),
		);
	}

	/**
	 * Run the due requests and store what came back. Cron context only.
	 *
	 * Bounded twice over: PER_RUN requests, and never two to the same host in
	 * one run. When more are still due afterwards, another run is queued rather
	 * than fetched now — a site that has just met thirty new crawlers spreads
	 * them out instead of opening thirty sockets.
	 *
	 * @return void
	 */
	public static function run() {
		$store = self::store();
		$now   = time();
		$hosts = array();
		$due   = array();

		foreach ( $store as $url => $entry ) {
			if ( ! self::is_due( $entry, $now ) ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host || isset( $hosts[ $host ] ) ) {
				continue;
			}
			$hosts[ $host ] = true;
			$due[]          = $url;
			if ( count( $due ) >= self::PER_RUN ) {
				break;
			}
		}

		if ( empty( $due ) ) {
			return;
		}

		foreach ( $due as $url ) {
			$result                 = self::probe( $url );
			$store[ $url ]['state'] = $result['state'];
			$store[ $url ]['code']  = $result['code'];
			$store[ $url ]['at']    = $now;
		}

		self::save( $store );

		// Anything left (more URLs than PER_RUN, or several sharing a host) gets
		// its own run rather than a longer one.
		if ( null !== self::next_due( $store, $now ) ) {
			self::schedule_soon();
		}
	}

	/**
	 * One capped, short-timeout GET against a stranger's URL.
	 *
	 * wp_safe_remote_get, always: the URL came out of a User-Agent header, so it
	 * is attacker-controlled, and core's private-range/port rejection is what
	 * keeps it from being pointed at this network's own insides. The UA names
	 * this plugin and links its page, which is the same courtesy we are checking
	 * the other client for.
	 *
	 * @param string $url Normalized declared URL.
	 * @return array{state:string,code:int}
	 */
	private static function probe( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'Agentimus/' . AGENTIMUS_VERSION . ' (identity check; +https://wordpress.org/plugins/agentimus/)',
			)
		);

		$resolves = true;
		if ( is_wp_error( $response ) ) {
			$resolves = self::resolves( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		}

		return self::classify( $response, $resolves );
	}

	/**
	 * Turn one response into one of the three states. Pure.
	 *
	 * Only two things are conclusive: a page that answered, and a server that
	 * said there is no such page. Everything else — a 403 that may be a firewall
	 * turning US away, a 500 that may be a bad afternoon, a timeout that may be
	 * this server's own network — is UNREACHED, and the surfaces say so out loud.
	 * A wrong "this bot is lying" is worse than no answer at all.
	 *
	 * @param array|\WP_Error $response Result of the request.
	 * @param bool            $resolves Whether the host resolves at all. Only
	 *                                  consulted for a failed request: a host
	 *                                  that resolves to nothing is as conclusive
	 *                                  as a 404, and is the common shape of an
	 *                                  invented identity.
	 * @return array{state:string,code:int}
	 */
	public static function classify( $response, $resolves = true ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'state' => $resolves ? self::UNREACHED : self::MISSING,
				'code'  => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'state' => self::ANSWERS,
				'code'  => $code,
			);
		}
		if ( 404 === $code || 410 === $code ) {
			return array(
				'state' => self::MISSING,
				'code'  => $code,
			);
		}
		return array(
			'state' => self::UNREACHED,
			'code'  => $code,
		);
	}

	/**
	 * Whether a host exists in DNS at all. Consulted only after a failed
	 * request, to tell "there is no such host" (conclusive) from "we couldn't
	 * get there" (not).
	 *
	 * @param string $host Hostname.
	 * @return bool True when it resolves, or when we cannot tell — the answer
	 *              that keeps an undecidable case out of the conclusive state.
	 */
	private static function resolves( $host ) {
		$host = strtolower( trim( $host ) );

		/**
		 * Short-circuit the DNS lookup behind the identity probe. Return true or
		 * false to decide; null (the default) looks the host up here. Tests use
		 * this seam, as may a site whose PHP cannot resolve names directly.
		 *
		 * @param bool|null $resolves Whether the host resolves.
		 * @param string    $host     The hostname being checked.
		 */
		$pre = apply_filters( 'agentimus_identity_probe_resolves', null, $host );
		if ( null !== $pre ) {
			return (bool) $pre;
		}

		if ( '' === $host || ! function_exists( 'checkdnsrr' ) ) {
			return true;
		}
		return checkdnsrr( $host, 'A' ) || checkdnsrr( $host, 'AAAA' ) || checkdnsrr( $host, 'CNAME' );
	}

	/**
	 * The stored map, keyed by normalized URL. Each entry is
	 * `{state, code, at, seen}`; an empty `state` means "recorded, not yet
	 * probed".
	 *
	 * @return array<string,array>
	 */
	private static function store() {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();

		/**
		 * Override or inspect the stored identity-probe results.
		 *
		 * @param array<string,array> $raw The stored map, keyed by URL.
		 */
		$filtered = apply_filters( self::FILTER, $raw );
		return is_array( $filtered ) ? $filtered : array();
	}

	/**
	 * @param array<string,array> $store The map to persist.
	 * @return void
	 */
	private static function save( array $store ) {
		update_option( self::OPTION, $store, false );
	}

	/**
	 * Queue the one-off run, if it is not already queued.
	 *
	 * @return void
	 */
	private static function schedule_soon() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON );
		}
	}

	/**
	 * Whether one entry wants a look: never probed, or probed long enough ago.
	 *
	 * @param array $entry One stored entry.
	 * @param int   $now   Current unix time.
	 * @return bool
	 */
	private static function is_due( array $entry, $now ) {
		$at = isset( $entry['at'] ) ? (int) $entry['at'] : 0;
		return $at <= 0 || ( $now - $at ) >= self::RECHECK_AFTER;
	}

	/**
	 * The first URL in the map that wants a look, or null when none does.
	 *
	 * @param array<string,array> $store The map.
	 * @param int                 $now   Current unix time.
	 * @return string|null
	 */
	private static function next_due( array $store, $now ) {
		foreach ( $store as $url => $entry ) {
			if ( is_array( $entry ) && self::is_due( $entry, $now ) ) {
				return (string) $url;
			}
		}
		return null;
	}

	/**
	 * Forget URLs no client has declared in FORGET_AFTER, hold each host to
	 * MAX_PER_HOST, then keep only the MAX_URLS most recently declared overall.
	 * A crawler that stops visiting stops costing a row; no flood of invented
	 * URLs can grow the option — or the request volume aimed at any one host —
	 * without bound. Pure.
	 *
	 * @param array<string,array> $store The map.
	 * @param int                 $now   Current unix time.
	 * @return array<string,array>
	 */
	public static function prune( array $store, $now ) {
		$kept = array();
		foreach ( $store as $url => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$seen = isset( $entry['seen'] ) ? (int) $entry['seen'] : 0;
			if ( $seen > 0 && ( $now - $seen ) > self::FORGET_AFTER ) {
				continue;
			}
			$kept[ $url ] = $entry;
		}

		$kept = self::newest_first( $kept );

		$per_host = array();
		foreach ( $kept as $url => $entry ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( ! isset( $per_host[ $host ] ) ) {
				$per_host[ $host ] = 0;
			}
			++$per_host[ $host ];
			if ( $per_host[ $host ] > self::MAX_PER_HOST ) {
				unset( $kept[ $url ] );
			}
		}

		if ( count( $kept ) > self::MAX_URLS ) {
			$kept = array_slice( $kept, 0, self::MAX_URLS, true );
		}

		return $kept;
	}

	/**
	 * The map ordered by when a live client last declared each URL, most recent
	 * first — so every cap below keeps what is still being seen and drops what
	 * has gone quiet. Pure.
	 *
	 * @param array<string,array> $store The map.
	 * @return array<string,array>
	 */
	private static function newest_first( array $store ) {
		uasort(
			$store,
			static function ( $a, $b ) {
				$sa = isset( $a['seen'] ) ? (int) $a['seen'] : 0;
				$sb = isset( $b['seen'] ) ? (int) $b['seen'] : 0;
				return $sb <=> $sa;
			}
		);
		return $store;
	}

	/**
	 * The storage key for a declared URL: scheme and host lowercased, fragment
	 * dropped, anything that isn't a plain http(s) URL rejected outright. Pure.
	 *
	 * The path is kept — two crawlers can declare different pages on one host,
	 * and answering for the wrong one would be a made-up result. (Volume is
	 * bounded at fetch time instead: one request per host per run, one look per
	 * URL per week.)
	 *
	 * @param string $url Raw declared URL.
	 * @return string Normalized URL, or '' when it is not one we will fetch.
	 */
	public static function normalize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || strlen( $url ) > self::MAX_URL_LEN ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		// Credentials in a crawler's declared home page are never legitimate, and
		// would ride along into a stored option.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) || false === strpos( $host, '.' ) ) {
			return ''; // Malformed, or a single-label host no public crawler uses.
		}

		$out = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$out .= ':' . (int) $parts['port'];
		}
		$out .= ( isset( $parts['path'] ) && '' !== $parts['path'] ) ? $parts['path'] : '/';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$out .= '?' . $parts['query'];
		}
		return $out;
	}
}
