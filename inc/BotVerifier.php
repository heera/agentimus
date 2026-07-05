<?php
/**
 * BotVerifier — optional forward-confirmed reverse DNS (FCrDNS) for the search-engine
 * crawlers the Guard trusts. A User-Agent is forgeable, so "Googlebot" in a UA proves
 * nothing; but the crawlers worth trusting (Googlebot, Bingbot, …) all publish that
 * their source IPs reverse-resolve into the engine's own domain AND forward-resolve
 * back to the same IP. Confirming that removes the trivial "paste a real crawler's UA"
 * bypass of the always-allow list.
 *
 * OFF by default (see {@see Guard::verification_on}): DNS is an outbound lookup, and
 * this plugin makes none unless the owner opts in.
 *
 * FAIL-OPEN by design. The verdict is three-valued:
 *   true  = forward-confirmed (a real crawler).
 *   false = CONCLUSIVELY someone else — the resolver answered promptly and the IP does
 *           not belong to the claimed engine. Only this strips a crawler's always-allow.
 *   null  = COULD NOT determine — no IP, the resolver was slow/unreachable, the lookup
 *           budget is spent, the circuit breaker is open, or the answer was incomplete
 *           (e.g. an IPv6 client on a host without AAAA support). The caller keeps the
 *           crawler protected, so a DNS hiccup never loses a real crawler.
 *
 * Three guards keep it cheap and safe on the unauthenticated bot path: a per-IP verdict
 * cache (an IP's crawler identity is stable), a bounded NEW-lookup budget per window
 * (anti-amplification — past it, uncached IPs return null, never a DNS storm), and a
 * circuit breaker that stops touching DNS for a cooldown after a couple of slow/failing
 * lookups (so a broken resolver can never keep stalling requests). Reverse/forward
 * resolution and the thresholds are filterable, so a site behind a CDN can wire in its
 * own resolver and the decision logic is testable without a live network.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class BotVerifier {

	/** Per-IP verdict cache: an IP's crawler identity is stable, so cache it a while. */
	const CACHE_PREFIX = 'agentimus_botv_';
	const CACHE_TTL    = 6 * HOUR_IN_SECONDS;

	/** Bounded NEW-lookup budget per window (anti-amplification). */
	const BUDGET_PREFIX = 'agentimus_botv_budget_';
	const BUDGET_WINDOW = 60;
	const BUDGET_MAX    = 30;

	/**
	 * Circuit breaker: a lookup slower than SLOW_MS is a "strike"; after TRIP_STRIKES
	 * strikes within STRIKE_TTL the breaker opens for TRIP_TTL, during which no DNS is
	 * performed and every claimed crawler fails OPEN (stays protected). So a slow or
	 * unreachable resolver can stall at most a couple of requests before it stops trying.
	 */
	const SLOW_MS      = 900;
	const STRIKE_KEY   = 'agentimus_botv_strikes';
	const STRIKE_TTL   = 120;
	const TRIP_KEY     = 'agentimus_botv_trip';
	const TRIP_STRIKES = 2;
	const TRIP_TTL     = 300;

	/**
	 * Verifiable engines: the token as it appears in the UA => the reverse-DNS domain
	 * suffixes an IP claiming it must resolve into. Filterable, so a site can add its
	 * CDN's own verified crawler or tighten the set.
	 *
	 * @return array<string,string[]>
	 */
	public static function engine_domains() {
		$map = array(
			'googlebot'   => array( '.googlebot.com', '.google.com' ),
			'bingbot'     => array( '.search.msn.com' ),
			'duckduckbot' => array( '.duckduckgo.com' ),
			'applebot'    => array( '.applebot.apple.com', '.apple.com' ),
			'yandex'      => array( '.yandex.com', '.yandex.net', '.yandex.ru' ),
		);

		/**
		 * Filter the verifiable-engine map (UA token => rDNS domain suffixes).
		 *
		 * @param array<string,string[]> $map Engine token => domain suffixes.
		 */
		return (array) apply_filters( 'agentimus_verified_bot_domains', $map );
	}

	/**
	 * The engine a lowercased UA claims, for verification, or '' when it claims none we
	 * can verify. Pure.
	 *
	 * @param string $ua_lc Lowercased User-Agent.
	 * @return string Engine token, or ''.
	 */
	public static function claimed_engine( $ua_lc ) {
		foreach ( self::engine_domains() as $token => $domains ) {
			if ( false !== strpos( (string) $ua_lc, (string) $token ) ) {
				return (string) $token;
			}
		}
		return '';
	}

	/**
	 * Tri-state verdict for the engine a UA claims. true = forward-confirmed;
	 * false = conclusively not that engine; null = could not determine (fail open).
	 *
	 * @param string $ua_lc Lowercased User-Agent.
	 * @param string $ip    Source IP.
	 * @return bool|null
	 */
	public static function verify_engine( $ua_lc, $ip ) {
		$engine = self::claimed_engine( $ua_lc );
		if ( '' === $engine ) {
			return false; // Not a claim we verify (the Guard gates on is_real_engine).
		}
		if ( '' === (string) $ip ) {
			return null; // No IP to check → cannot determine → keep the crawler protected.
		}
		$all     = self::engine_domains();
		$domains = isset( $all[ $engine ] ) ? (array) $all[ $engine ] : array();
		return self::verify_ip( (string) $ip, $domains );
	}

	/**
	 * Cached tri-state FCrDNS verdict for $ip against $domains. Bounded by the per-window
	 * budget and the circuit breaker — both of which fail OPEN (null), never closed.
	 *
	 * @param string   $ip      Source IP.
	 * @param string[] $domains Expected rDNS domain suffixes.
	 * @return bool|null
	 */
	public static function verify_ip( $ip, array $domains ) {
		$key    = self::CACHE_PREFIX . md5( $ip . '|' . implode( ',', $domains ) );
		$cached = get_transient( $key );
		if ( 'v' === $cached ) {
			return true;
		}
		if ( 's' === $cached ) {
			return false;
		}

		// Circuit breaker open (the resolver has been slow/failing) → don't touch DNS;
		// fail OPEN so a broken resolver can't keep stalling requests or lose crawlers.
		if ( get_transient( self::TRIP_KEY ) ) {
			return null;
		}
		// Past the anti-amplification budget → don't perform a NEW lookup; fail OPEN.
		if ( ! self::spend_lookup() ) {
			return null;
		}

		$verdict = self::fcrdns( $ip, $domains ); // true | false | null

		// Cache CONCLUSIVE verdicts only. An inconclusive (null) result is never cached,
		// so a transient DNS failure is not remembered as a lasting "unverified".
		if ( true === $verdict ) {
			set_transient( $key, 'v', self::CACHE_TTL );
		} elseif ( false === $verdict ) {
			set_transient( $key, 's', self::CACHE_TTL );
		}
		return $verdict;
	}

	/**
	 * The forward-confirmed reverse-DNS check, three-valued and time-bounded. A slow
	 * reverse or forward lookup records a strike and returns null (never proceeds to
	 * stack a second slow lookup); a non-match is only called a spoof (false) when we
	 * actually resolved addresses of the client IP's family, else it stays null.
	 *
	 * @param string   $ip      Source IP.
	 * @param string[] $domains Expected rDNS domain suffixes.
	 * @return bool|null
	 */
	private static function fcrdns( $ip, array $domains ) {
		$t0   = microtime( true );
		$host = strtolower( rtrim( self::reverse( $ip ), '.' ) );
		if ( self::too_slow( $t0 ) ) {
			self::record_strike();
			return null; // Slow reverse lookup → keep the crawler; retry later.
		}
		if ( '' === $host || $host === strtolower( (string) $ip ) ) {
			return false; // Resolver answered promptly with no usable PTR → conclusive.
		}
		if ( ! self::host_in_domains( $host, $domains ) ) {
			return false; // PTR points to another domain → conclusive.
		}

		// Forward-confirm: the PTR hostname must resolve back to the same IP.
		$t1  = microtime( true );
		$ips = self::forward( $host );
		if ( self::too_slow( $t1 ) ) {
			self::record_strike();
			return null; // Slow forward lookup → keep the crawler.
		}
		foreach ( $ips as $resolved ) {
			if ( self::same_ip( $resolved, $ip ) ) {
				return true;
			}
		}
		// No match. Only a fake PTR (conclusive spoof) when the hostname actually
		// resolved to addresses of the client's family; if we got none of that family
		// (e.g. an IPv6 client on a host that can't resolve AAAA) we could not really
		// check — stay inconclusive and keep the crawler.
		return self::forward_had_family( $ips, $ip ) ? false : null;
	}

	/**
	 * Whether $host is, or is a subdomain of, one of the domain suffixes. Each suffix
	 * carries a leading dot, so a look-alike like "evil-googlebot.com" cannot match
	 * ".googlebot.com". Pure.
	 *
	 * @param string   $host    Lowercased hostname (no trailing dot).
	 * @param string[] $domains Domain suffixes, each e.g. ".googlebot.com".
	 * @return bool
	 */
	public static function host_in_domains( $host, array $domains ) {
		$host = strtolower( (string) $host );
		foreach ( $domains as $suffix ) {
			$s = strtolower( (string) $suffix );
			if ( '' === $s ) {
				continue;
			}
			$bare = ltrim( $s, '.' );
			if ( $host === $bare ) {
				return true; // Exact apex, e.g. host = googlebot.com.
			}
			// A real subdomain: ends with ".googlebot.com" AND has a label before the dot.
			if ( strlen( $host ) > strlen( $s ) && substr( $host, -strlen( $s ) ) === $s ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether $ips contains at least one address of the same family (IPv4/IPv6) as $ip —
	 * i.e. whether the forward lookup could actually speak to the client's address family.
	 *
	 * @param string[] $ips List of resolved IP strings.
	 * @param string   $ip  Client IP.
	 * @return bool
	 */
	private static function forward_had_family( array $ips, $ip ) {
		$target = self::family( $ip );
		if ( '' === $target ) {
			return false;
		}
		foreach ( $ips as $cand ) {
			if ( self::family( $cand ) === $target ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The address family of an IP string: 'v4', 'v6', or '' when not a valid IP.
	 *
	 * @param string $ip IP string.
	 * @return string
	 */
	private static function family( $ip ) {
		$packed = @inet_pton( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid input returns false.
		if ( false === $packed ) {
			return '';
		}
		if ( 4 === strlen( $packed ) ) {
			return 'v4';
		}
		return 16 === strlen( $packed ) ? 'v6' : '';
	}

	/**
	 * Whether two IP strings are the same address (normalized).
	 *
	 * @param string $a IP.
	 * @param string $b IP.
	 * @return bool
	 */
	private static function same_ip( $a, $b ) {
		$pa = @inet_pton( (string) $a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid input returns false.
		$pb = @inet_pton( (string) $b ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid input returns false.
		return false !== $pa && $pa === $pb;
	}

	/**
	 * Reverse DNS (PTR) for an IP → hostname. Filterable (return a string to override,
	 * null to fall through to the native lookup).
	 *
	 * @param string $ip Source IP.
	 * @return string Hostname, or '' when none.
	 */
	private static function reverse( $ip ) {
		/**
		 * Short-circuit the reverse-DNS lookup. Return a hostname string (or '' for
		 * none) to override; null to use the native resolver.
		 *
		 * @param string|null $host Overriding hostname, or null.
		 * @param string      $ip   Source IP.
		 */
		$host = apply_filters( 'agentimus_reverse_dns', null, $ip );
		if ( null === $host && function_exists( 'gethostbyaddr' ) ) {
			$host = @gethostbyaddr( (string) $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- resolver failure returns false.
		}
		return is_string( $host ) ? $host : '';
	}

	/**
	 * Forward DNS (A/AAAA) for a hostname → list of IP strings. Filterable (return an
	 * array to override, null to fall through to the native lookup).
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private static function forward( $host ) {
		/**
		 * Short-circuit the forward-DNS lookup. Return an array of IP strings to
		 * override; null to use the native resolver.
		 *
		 * @param array|null $ips  Overriding IP list, or null.
		 * @param string     $host Hostname.
		 */
		$ips = apply_filters( 'agentimus_forward_dns', null, $host );
		if ( null === $ips ) {
			$ips = self::native_forward( $host );
		}
		return is_array( $ips ) ? $ips : array();
	}

	/**
	 * Native forward resolution: IPv4 via gethostbynamel plus IPv6 via AAAA records.
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private static function native_forward( $host ) {
		$out = array();
		if ( function_exists( 'gethostbynamel' ) ) {
			$v4 = @gethostbynamel( (string) $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- resolver failure returns false. IPv4 only.
			if ( is_array( $v4 ) ) {
				$out = $v4;
			}
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$v6 = @dns_get_record( (string) $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- resolver failure returns false.
			if ( is_array( $v6 ) ) {
				foreach ( $v6 as $r ) {
					if ( isset( $r['ipv6'] ) ) {
						$out[] = (string) $r['ipv6'];
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Whether the elapsed time since $start exceeds the slow-lookup threshold.
	 *
	 * @param float $start microtime(true) captured before the lookup.
	 * @return bool
	 */
	private static function too_slow( $start ) {
		$ms = ( microtime( true ) - $start ) * 1000;
		/**
		 * Milliseconds beyond which a single DNS lookup counts as "slow" (a circuit-
		 * breaker strike, and a fail-open null verdict).
		 *
		 * @param int $ms The threshold in milliseconds.
		 */
		$limit = (int) apply_filters( 'agentimus_verify_slow_ms', self::SLOW_MS );
		return $ms > max( 1, $limit );
	}

	/**
	 * Record one slow/failed-lookup strike; open the circuit breaker once enough
	 * accumulate within the window, so DNS is skipped entirely for a cooldown.
	 *
	 * @return void
	 */
	private static function record_strike() {
		$n = (int) get_transient( self::STRIKE_KEY ) + 1;
		set_transient( self::STRIKE_KEY, $n, self::STRIKE_TTL );

		/**
		 * How many slow-lookup strikes open the verification circuit breaker.
		 *
		 * @param int $strikes Strike count that trips the breaker.
		 */
		$trip = (int) apply_filters( 'agentimus_verify_trip_strikes', self::TRIP_STRIKES );
		if ( $n >= max( 1, $trip ) ) {
			set_transient( self::TRIP_KEY, 1, self::TRIP_TTL );
		}
	}

	/**
	 * Count one new lookup against the per-window budget; false once it is spent.
	 *
	 * @return bool True while the budget allows a new lookup.
	 */
	private static function spend_lookup() {
		$max = (int) apply_filters( 'agentimus_verify_lookup_budget', self::BUDGET_MAX );
		$key = self::BUDGET_PREFIX . (int) floor( time() / self::BUDGET_WINDOW );
		$n   = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, self::BUDGET_WINDOW * 2 );
		return $n <= max( 1, $max );
	}
}
