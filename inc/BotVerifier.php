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
 * this plugin makes none unless the owner opts in. When on it runs ONLY on the already-
 * opt-in hard-block path, caches each IP's verdict, and spends a bounded number of NEW
 * lookups per window — so a flood of spoofed IPs can't turn it into a DNS-amplification
 * vector (past the budget, an uncached IP is treated as unverified: fail-closed for
 * trust). Reverse/forward resolution is filterable (`agentimus_reverse_dns` /
 * `agentimus_forward_dns`) so a site behind a CDN can wire in its own resolver, and so
 * the decision logic is testable without a live network.
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
	 * Whether $ip forward-confirms as the engine the UA claims. False when the UA
	 * claims nothing verifiable, the IP is empty, or the DNS check does not confirm.
	 *
	 * @param string $ua_lc Lowercased User-Agent.
	 * @param string $ip    Source IP.
	 * @return bool
	 */
	public static function verify_engine( $ua_lc, $ip ) {
		$engine = self::claimed_engine( $ua_lc );
		if ( '' === $engine || '' === (string) $ip ) {
			return false;
		}
		$all     = self::engine_domains();
		$domains = isset( $all[ $engine ] ) ? (array) $all[ $engine ] : array();
		return self::verify_ip( (string) $ip, $domains );
	}

	/**
	 * Whether $ip reverse-resolves into one of $domains AND forward-resolves back to
	 * $ip. Cached per (ip, domains); bounded by the per-window lookup budget.
	 *
	 * @param string   $ip      Source IP.
	 * @param string[] $domains Expected rDNS domain suffixes.
	 * @return bool
	 */
	public static function verify_ip( $ip, array $domains ) {
		$key    = self::CACHE_PREFIX . md5( $ip . '|' . implode( ',', $domains ) );
		$cached = get_transient( $key );
		if ( '1' === $cached ) {
			return true;
		}
		if ( '0' === $cached ) {
			return false;
		}

		// Past the per-window budget, don't perform an uncached lookup — treat the IP as
		// unverified (fail-closed for trust) so a spoofed-IP flood can't amplify into a
		// DNS storm.
		if ( ! self::spend_lookup() ) {
			return false;
		}

		$ok = self::fcrdns( $ip, $domains );
		set_transient( $key, $ok ? '1' : '0', self::CACHE_TTL );
		return $ok;
	}

	/**
	 * The forward-confirmed reverse-DNS check itself.
	 *
	 * @param string   $ip      Source IP.
	 * @param string[] $domains Expected rDNS domain suffixes.
	 * @return bool
	 */
	private static function fcrdns( $ip, array $domains ) {
		$host = strtolower( rtrim( self::reverse( $ip ), '.' ) );
		if ( '' === $host || $host === strtolower( (string) $ip ) ) {
			return false; // No PTR record → cannot confirm.
		}
		if ( ! self::host_in_domains( $host, $domains ) ) {
			return false; // PTR points somewhere other than the claimed engine.
		}
		// Forward-confirm: the PTR hostname must resolve back to the same IP.
		foreach ( self::forward( $host ) as $resolved ) {
			if ( self::same_ip( $resolved, $ip ) ) {
				return true;
			}
		}
		return false;
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
