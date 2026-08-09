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
}
