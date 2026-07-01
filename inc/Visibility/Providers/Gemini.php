<?php
/**
 * Google Gemini provider — the Generative Language `generateContent` endpoint.
 * The key is sent as the `x-goog-api-key` header (not a query string) so it never
 * lands in a URL that could be logged.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility\Providers;

defined( 'ABSPATH' ) || exit;

final class Gemini extends Provider {

	const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/** {@inheritDoc} */
	public function id() {
		return 'gemini';
	}

	/** {@inheritDoc} */
	public function query( $prompt, $key, $model, $web_search = false ) {
		// Accept either "gemini-2.0-flash" or "models/gemini-2.0-flash".
		$model = preg_replace( '#^models/#', '', trim( $model ) );
		$url   = self::BASE . rawurlencode( $model ) . ':generateContent';

		$body = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
		);

		// Ground the answer on Google Search (returns groundingMetadata with sources).
		if ( $web_search ) {
			$body['tools'] = array( array( 'google_search' => (object) array() ) );
		}

		$result = $this->post_json( $url, array( 'x-goog-api-key' => $key ), $body );

		if ( isset( $result['error'] ) ) {
			return $this->fail( $result['error'] );
		}

		return $this->ok( $this->text_from( $result['json'] ), $this->citations_from( $result['json'] ) );
	}

	/**
	 * Cited source URLs from grounding metadata:
	 * candidates[0].groundingMetadata.groundingChunks[].web.uri. Empty when the
	 * answer was not grounded (search tool off, or the model chose not to search).
	 *
	 * @param array $json Decoded response.
	 * @return string[]
	 */
	private function citations_from( array $json ) {
		$chunks = isset( $json['candidates'][0]['groundingMetadata']['groundingChunks'] )
			? $json['candidates'][0]['groundingMetadata']['groundingChunks']
			: array();

		$urls = array();
		foreach ( (array) $chunks as $chunk ) {
			if ( isset( $chunk['web']['uri'] ) ) {
				$urls[] = (string) $chunk['web']['uri'];
			}
		}
		return $urls;
	}

	/**
	 * Join the text parts of the first candidate.
	 *
	 * @param array $json Decoded response.
	 * @return string
	 */
	private function text_from( array $json ) {
		$parts = isset( $json['candidates'][0]['content']['parts'] )
			? $json['candidates'][0]['content']['parts']
			: array();

		$text = '';
		foreach ( (array) $parts as $part ) {
			if ( isset( $part['text'] ) ) {
				$text .= (string) $part['text'];
			}
		}
		return $text;
	}
}
