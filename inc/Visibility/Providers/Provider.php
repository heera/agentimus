<?php
/**
 * Base class for an AI engine Pro can ask a prompt. Each concrete provider knows
 * its own endpoint, auth header and response shape; the base handles the actual
 * HTTP round-trip through WordPress's own HTTP API (wp_remote_post) so the add-on
 * needs no vendor SDK and stays as light as the free core.
 *
 * A query() returns a normalized array:
 *   [ 'text' => string, 'citations' => string[], 'error' => string ]
 * On success `error` is '' and `text` holds the model's answer; on failure `text`
 * is '' and `error` explains why.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility\Providers;

defined( 'ABSPATH' ) || exit;

abstract class Provider {

	/** @var int Per-request timeout. Live-web providers can be slow. */
	const TIMEOUT = 45;

	/** @var int Upper bound (seconds) on a Retry-After backoff, so a run never hangs. */
	const MAX_RETRY_WAIT = 8;

	/** @return string The provider id (matches the settings catalog key). */
	abstract public function id();

	/**
	 * Ask the engine a single prompt.
	 *
	 * @param string $prompt     The tracked prompt.
	 * @param string $key        API key.
	 * @param string $model      Model id.
	 * @param bool   $web_search Answer from a live web search (engines that support it).
	 * @return array { text, citations, error }
	 */
	abstract public function query( $prompt, $key, $model, $web_search = false );

	/**
	 * Instantiate a provider by id, or null if unknown.
	 *
	 * @param string $id Provider id.
	 * @return Provider|null
	 */
	public static function make( $id ) {
		switch ( $id ) {
			case 'openai':
				return new OpenAI();
			case 'perplexity':
				return new Perplexity();
			case 'gemini':
				return new Gemini();
			case 'anthropic':
				return new Anthropic();
		}
		return null;
	}

	/**
	 * POST JSON and return the decoded body, or an error result.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $headers Request headers.
	 * @param array  $body    Body to JSON-encode.
	 * @return array { json?: array, error?: string }
	 */
	protected function post_json( $url, array $headers, array $body ) {
		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => array_merge( array( 'content-type' => 'application/json' ), $headers ),
			'body'    => wp_json_encode( $body ),
		);

		// Two attempts at most: a 429 (rate limit) backs off once, but ONLY when the
		// provider sends a Retry-After telling us how long to wait — a transient
		// per-minute limit self-recovers, while a hard daily-quota 429 (no header)
		// returns its error immediately instead of stalling the run.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$response = wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				return array( 'error' => $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 429 === $code && 0 === $attempt ) {
				$wait = $this->retry_after_seconds( $response );
				if ( $wait > 0 ) {
					sleep( $wait );
					continue; // one retry
				}
			}

			$raw  = wp_remote_retrieve_body( $response );
			$json = json_decode( $raw, true );

			if ( $code < 200 || $code >= 300 ) {
				return array( 'error' => $this->error_from( $json, $code ) );
			}
			return array( 'json' => is_array( $json ) ? $json : array() );
		}

		// Unreachable in normal flow (the retry path returns above); a safety net.
		return array( 'error' => __( 'Rate limited by the provider. Please try again shortly.', 'agentimus' ) );
	}

	/**
	 * Seconds to wait from a 429 response's Retry-After header — numeric seconds or
	 * an HTTP-date — bounded by MAX_RETRY_WAIT. Returns 0 when the header is absent,
	 * so we don't retry a hard-quota 429 that gives no wait signal.
	 *
	 * @param array|\WP_Error $response The HTTP response.
	 * @return int
	 */
	protected function retry_after_seconds( $response ) {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( '' === (string) $header ) {
			return 0;
		}
		$wait = is_numeric( $header )
			? (int) $header
			: (int) ( strtotime( (string) $header ) - time() );
		return max( 0, min( $wait, self::MAX_RETRY_WAIT ) );
	}

	/**
	 * Pull a human-readable message out of a provider error body, falling back to
	 * the HTTP status. Providers nest their message differently, so probe the
	 * common shapes.
	 *
	 * @param mixed $json Decoded body (may be null).
	 * @param int   $code HTTP status.
	 * @return string
	 */
	protected function error_from( $json, $code ) {
		if ( is_array( $json ) ) {
			if ( isset( $json['error']['message'] ) ) {
				return (string) $json['error']['message'];
			}
			if ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
				return (string) $json['error'];
			}
			if ( isset( $json['detail'] ) && is_string( $json['detail'] ) ) {
				return (string) $json['detail'];
			}
			if ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
				return (string) $json['message'];
			}
		}
		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Request failed (HTTP %d).', 'agentimus' ), $code );
	}

	/**
	 * A normalized success result.
	 *
	 * @param string   $text      Answer text.
	 * @param string[] $citations Cited URLs.
	 * @return array
	 */
	protected function ok( $text, array $citations = array() ) {
		return array(
			'text'      => (string) $text,
			'citations' => array_values( array_filter( array_map( 'strval', $citations ) ) ),
			'error'     => '',
		);
	}

	/**
	 * A normalized error result.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	protected function fail( $message ) {
		return array(
			'text'      => '',
			'citations' => array(),
			'error'     => (string) $message,
		);
	}
}
