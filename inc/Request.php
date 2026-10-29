<?php
/**
 * Small request-inspection helpers shared by the public response emitters.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

/**
 * The public endpoints ({@see Endpoints}, {@see Discovery\WellKnown}) each emit a
 * body and stop. Two facts about the incoming request are read the same way at
 * every one of those exits — whether it is a HEAD (headers but no body) and the
 * raw request URI — so they live here rather than being spelled out per emitter.
 * The response headers themselves stay with each emitter: they differ per surface
 * (robots' 404 reset, the discovery docs' signatures, the markdown no-store
 * dialect) in ways a single writer couldn't carry without losing fidelity.
 */
final class Request {

	/**
	 * Whether this is a HEAD request — the emitters send headers but no body.
	 *
	 * @return bool
	 */
	public static function is_head() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * The sanitised raw request URI (path and query), or '/' when absent.
	 *
	 * @return string
	 */
	public static function uri() {
		return isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	}

	/**
	 * The request path relative to the site's home, always starting with '/'.
	 * On a subdirectory install (home = https://example.com/blog) the raw path
	 * of /blog/llms.txt is "/blog/llms.txt"; this returns "/llms.txt", the form
	 * every surface compares against and builds from with home_url().
	 *
	 * @return string
	 */
	public static function path() {
		$path = '/' . ltrim( (string) wp_parse_url( self::uri(), PHP_URL_PATH ), '/' );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && ( $path === $base || 0 === strpos( $path, $base . '/' ) ) ) {
			$path = (string) substr( $path, strlen( $base ) );
		}
		return '' === $path ? '/' : $path;
	}
}
