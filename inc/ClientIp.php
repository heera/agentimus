<?php
/**
 * ClientIp — the request's real source IP, safely, for reverse-DNS verification.
 *
 * A crawler's IP is what {@see BotVerifier} checks. Read straight, REMOTE_ADDR is the
 * *direct* peer — which behind a CDN/proxy is the PROXY, not the crawler, so every
 * crawler would fail verification. This resolves the true client IP from the proxy's own
 * header (e.g. Cloudflare's CF-Connecting-IP), but ONLY when the direct peer is a proxy
 * we can prove is trusted — its IP falls inside the proxy's published ranges. Otherwise
 * the header is ignored, so a client can never spoof its IP by simply sending one.
 *
 * No setting of its own: it runs whenever reverse-DNS verification runs (its only
 * caller). The trusted-proxy map is filterable, so another CDN or a self-hosted load
 * balancer can be added in code without a UI toggle. Both IPv4 and IPv6 are handled.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class ClientIp {

	/**
	 * Trusted proxies: the CIDR ranges of a proxy's own IPs => the header it puts the
	 * real client IP in. Default is Cloudflare — its ranges are public and stable, and it
	 * sets CF-Connecting-IP while stripping any client-supplied copy, so it can't be
	 * forged. A forwarded header is honoured ONLY when REMOTE_ADDR is inside its ranges.
	 *
	 * @return array<int,array{header:string,ranges:string[]}>
	 */
	public static function trusted_proxies() {
		$proxies = array(
			array(
				'header' => 'HTTP_CF_CONNECTING_IP',
				'ranges' => array(
					// IPv4 — https://www.cloudflare.com/ips-v4
					'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
					'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
					'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
					'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
					// IPv6 — https://www.cloudflare.com/ips-v6
					'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
					'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
				),
			),
		);

		/**
		 * Filter the trusted-proxy map. Add another CDN or a self-hosted load balancer as
		 * `[ 'header' => 'HTTP_X_REAL_IP', 'ranges' => [ CIDRs ] ]`. A forwarded header is
		 * only honoured when the direct peer (REMOTE_ADDR) falls inside that proxy's ranges,
		 * so this can never be used to spoof a source IP from the open internet.
		 *
		 * @param array $proxies Trusted-proxy definitions.
		 */
		return (array) apply_filters( 'agentimus_trusted_proxies', $proxies );
	}

	/**
	 * The real client IP: a trusted proxy's forwarded header when the direct peer is that
	 * proxy, else REMOTE_ADDR. '' when there is no valid source IP.
	 *
	 * @return string
	 */
	public static function resolve() {
		$remote = self::server_ip( 'REMOTE_ADDR' );
		if ( '' === $remote ) {
			return '';
		}
		foreach ( self::trusted_proxies() as $proxy ) {
			$header = isset( $proxy['header'] ) ? (string) $proxy['header'] : '';
			$ranges = isset( $proxy['ranges'] ) ? (array) $proxy['ranges'] : array();
			if ( '' === $header || array() === $ranges ) {
				continue;
			}
			if ( ! self::in_any_range( $remote, $ranges ) ) {
				continue; // Direct peer is not this proxy → its header is untrusted.
			}
			$forwarded = self::server_ip( $header );
			if ( '' !== $forwarded ) {
				return $forwarded; // Trusted: the request really came through this proxy.
			}
		}
		return $remote;
	}

	/**
	 * A validated IP from a $_SERVER key. A header may carry a comma list (X-Forwarded-
	 * For); the first entry is the originating client. '' when absent or not an IP.
	 *
	 * @param string $key $_SERVER key, e.g. REMOTE_ADDR or HTTP_CF_CONNECTING_IP.
	 * @return string
	 */
	private static function server_ip( $key ) {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}
		$raw   = (string) wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as an IP below, never output.
		$parts = explode( ',', $raw );
		$first = trim( (string) $parts[0] );
		return false !== filter_var( $first, FILTER_VALIDATE_IP ) ? $first : '';
	}

	/**
	 * Whether $ip falls inside any of the CIDR ranges (IPv4 or IPv6).
	 *
	 * @param string   $ip     IP to test.
	 * @param string[] $ranges CIDR ranges.
	 * @return bool
	 */
	private static function in_any_range( $ip, array $ranges ) {
		foreach ( $ranges as $cidr ) {
			if ( self::in_range( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether $ip is within $cidr. Binary prefix compare via inet_pton, so IPv4 and IPv6
	 * are both handled and mismatched families never match. Pure.
	 *
	 * @param string $ip   IP to test.
	 * @param string $cidr CIDR, e.g. 104.16.0.0/13 or 2400:cb00::/32.
	 * @return bool
	 */
	public static function in_range( $ip, $cidr ) {
		if ( false === strpos( (string) $cidr, '/' ) ) {
			return false;
		}
		list( $subnet, $bits ) = explode( '/', (string) $cidr, 2 );
		$ip_p  = @inet_pton( (string) $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid input returns false.
		$net_p = @inet_pton( (string) $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid input returns false.
		if ( false === $ip_p || false === $net_p || strlen( $ip_p ) !== strlen( $net_p ) ) {
			return false; // Invalid, or different address families.
		}
		$bits  = max( 0, min( (int) $bits, strlen( $ip_p ) * 8 ) );
		$whole = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( $whole > 0 && 0 !== strncmp( $ip_p, $net_p, $whole ) ) {
			return false;
		}
		if ( $rem > 0 ) {
			$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
			if ( ( ord( $ip_p[ $whole ] ) & ord( $mask ) ) !== ( ord( $net_p[ $whole ] ) & ord( $mask ) ) ) {
				return false;
			}
		}
		return true;
	}
}
