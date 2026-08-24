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

	/** @var int Bounds the answer length (and therefore per-check token cost). */
	const MAX_TOKENS = 1024;

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
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array( 'maxOutputTokens' => self::MAX_TOKENS ),
		);

		// Ground the answer on Google Search (returns groundingMetadata with sources).
		if ( $web_search ) {
			$body['tools'] = array( array( 'google_search' => (object) array() ) );
		}

		$result = $this->post_json( $url, array( 'x-goog-api-key' => $key ), $body, $web_search ? self::WEB_TIMEOUT : self::TIMEOUT );

		if ( isset( $result['error'] ) ) {
			return $this->fail( $result['error'] );
		}

		$json = $result['json'];
		return $this->answer( $this->text_from( $json ), $this->citations_from( $json ), isset( $json['candidates'] ) );
	}

	/**
	 * Cited sources from grounding metadata:
	 * candidates[0].groundingMetadata.groundingChunks[].web. Empty when the
	 * answer was not grounded (search tool off, or the model chose not to search).
	 *
	 * ⛔ `web.uri` is NEVER the source. Google hands back its own redirector —
	 * `https://vertexaisearch.cloud.google.com/grounding-api-redirect/…` — for
	 * every chunk of every answer, so a list of them names Google three times
	 * and the site that was actually read not at all. The real site is in
	 * `web.domain` where the response carries one, else in `web.title`, which
	 * grounding fills with the source's host ("aljazeera.com" in Google's own
	 * sample).
	 *
	 * ⭐ Keeping only the uri did more than spoil a label: {@see Analyzer} matches
	 * this site against the HOST of each citation, and that host was always
	 * Google's, so a Gemini row could never be counted as linking the owner's
	 * site. "0% linked · never linked its site" was a structural zero — the one
	 * number on that screen that had never been measured. His catch, 2026-08-24.
	 *
	 * @param array $json Decoded response.
	 * @return array[] List of { url, label }.
	 */
	private function citations_from( array $json ) {
		$chunks = isset( $json['candidates'][0]['groundingMetadata']['groundingChunks'] )
			? $json['candidates'][0]['groundingMetadata']['groundingChunks']
			: array();

		$sources = array();
		foreach ( (array) $chunks as $chunk ) {
			$web = isset( $chunk['web'] ) && is_array( $chunk['web'] ) ? $chunk['web'] : array();

			$label = '';
			foreach ( array( 'domain', 'title' ) as $field ) {
				if ( isset( $web[ $field ] ) && '' !== trim( (string) $web[ $field ] ) ) {
					$label = trim( (string) $web[ $field ] );
					break;
				}
			}

			$url = isset( $web['uri'] ) ? trim( (string) $web['uri'] ) : '';
			if ( '' === $url && '' === $label ) {
				continue; // A chunk that names nothing is not a source.
			}

			$sources[] = array(
				'url'   => $url,
				'label' => $label,
			);
		}
		return $sources;
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
