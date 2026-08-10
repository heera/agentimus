<?php
/**
 * Resolves a pasted address to a URL on THIS site — or '' when it is foreign.
 *
 * One rule for every "check this page" box (Google's index lookup, Bing's URL
 * check), extracted from Google\Index where its cases were learned one paste
 * at a time: scheme-relative "//host/path", a bare path, and the site written
 * out host-first without a scheme are all shapes real people paste.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * The pasted-URL resolver.
 */
final class LocalUrl {

	/**
	 * Resolve a pasted address to an absolute URL on this site, '' if foreign.
	 *
	 * @param string $url Whatever was pasted.
	 * @return string Absolute local URL, or '' when the address is not this site's.
	 */
	public static function resolve( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		// Scheme-relative ("//host/path") — rare from a person, common from a paste.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		// With a scheme, the host is knowable and decides it outright.
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $url ) ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			return $host === $home_host ? $url : '';
		}

		// Without one, parse_url reports NO host for either "terms/" or
		// "elsewhere.test/x" — they are the same shape to it. So the first
		// segment is compared against this site's own host, and only that
		// settles it: "example.test/terms" is this site written out in full,
		// while anything else is read as a PATH here.
		//
		// Reading the leftovers as a path is the deliberate choice. The
		// alternative — guessing "has a dot, must be a domain" — refuses
		// "sitemap.xml" as a foreign site, and being wrong about someone's own
		// page is worse than answering "not checked yet" about a path that
		// turns out not to exist.
		$first = strtolower( (string) strtok( ltrim( $url, '/' ), '/' ) );
		if ( $first === $home_host || 'www.' . $first === $home_host || $first === 'www.' . $home_host ) {
			$abs  = ( 0 === strpos( home_url( '/' ), 'http://' ) ? 'http://' : 'https://' ) . ltrim( $url, '/' );
			$host = strtolower( (string) wp_parse_url( $abs, PHP_URL_HOST ) );
			return $host === $home_host ? $abs : '';
		}

		return home_url( '/' . ltrim( $url, '/' ) );
	}
}
