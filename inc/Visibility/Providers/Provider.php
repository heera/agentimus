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
 * Why these talk to the vendor APIs directly instead of routing through WordPress 7.0's
 * AI Client the way {@see \Agentimus\Assist} does: the AI Client can enable web search,
 * but it cannot give back the SOURCES the engine cited, and `citations` above is the
 * signal a visibility check grades. Its typed result models only text/file/function-call
 * parts, and its one escape hatch (`getAdditionalData()`) receives just the leftover
 * TOP-LEVEL keys of the vendor response — while every engine buries its sources inside
 * the structure that gets stripped (Gemini in `candidates[].groundingMetadata`, OpenAI in
 * `choices[].message.annotations`, Anthropic on the content blocks). Perplexity — whose
 * `citations` are top-level and would survive — has no core connector at all. So until the
 * AI Client models citations, reading them means speaking each vendor's own wire format,
 * which is why this screen keeps its own keys. Revisit if core grows a citation DTO.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility\Providers;

defined( 'ABSPATH' ) || exit;

abstract class Provider {

	/** @var int Per-request timeout for a plain (from-memory) answer. */
	const TIMEOUT = 45;

	/** @var int Per-request timeout for a live web-search answer. The engine runs a
	 * slow server-side loop (search → read → maybe search again → answer) before it
	 * returns the first byte, so these need much more room than a memory answer. */
	const WEB_TIMEOUT = 120;

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
	 * The provider id → class registry. The single source of truth for which
	 * engines exist: every id here must also appear in {@see \Agentimus\Visibility\Settings::catalog()}
	 * (its metadata) and vice versa — a drift means a configured engine silently
	 * never runs, so a test pins the two lists together.
	 *
	 * @var array<string,string>
	 */
	const MAP = array(
		'openai'     => OpenAI::class,
		'perplexity' => Perplexity::class,
		'gemini'     => Gemini::class,
		'anthropic'  => Anthropic::class,
	);

	/**
	 * Instantiate a provider by id, or null if unknown.
	 *
	 * @param string $id Provider id.
	 * @return Provider|null
	 */
	public static function make( $id ) {
		if ( ! isset( self::MAP[ $id ] ) ) {
			return null;
		}
		$class = self::MAP[ $id ];
		return new $class();
	}

	/**
	 * POST JSON and return the decoded body, or an error result.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $headers Request headers.
	 * @param array  $body    Body to JSON-encode.
	 * @param int    $timeout Per-request timeout (seconds). Defaults to the plain
	 *                        TIMEOUT; pass WEB_TIMEOUT for a live web-search call.
	 * @return array { json?: array, error?: string }
	 */
	protected function post_json( $url, array $headers, array $body, $timeout = self::TIMEOUT ) {
		$args = array(
			'timeout' => (int) $timeout,
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
	 * Normalize a parsed 2xx answer, guarding against a shape mismatch. A success body
	 * whose expected answer container was ABSENT ($shaped === false) and that yielded
	 * no text and no citations is not a real "the model said nothing" — it's a provider
	 * changing its response format, or a proxy returning an HTML/error page with a 200.
	 * Recording that as a genuine empty answer would silently poison the visibility
	 * metric with fabricated "not mentioned" data, so surface it as an error instead.
	 * An empty answer WITH the right shape (e.g. a safety refusal) is a legitimate
	 * "not mentioned" and passes through untouched.
	 *
	 * @param string   $text      Extracted answer text.
	 * @param string[] $citations Extracted cited URLs.
	 * @param bool     $shaped    Whether the expected answer container was present.
	 * @return array { text, citations, error }
	 */
	protected function answer( $text, array $citations, $shaped ) {
		if ( ! $shaped && '' === (string) $text && empty( $citations ) ) {
			return $this->fail( __( 'The provider returned an unexpected response format.', 'agentimus' ) );
		}
		return $this->ok( $text, $citations );
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
