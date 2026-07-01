<?php
/**
 * Anthropic (Claude) provider — the Messages endpoint.
 *
 * Wire details verified against Anthropic's current API reference:
 *   - POST https://api.anthropic.com/v1/messages
 *   - headers: x-api-key, anthropic-version: 2023-06-01
 *   - body: { model, max_tokens, messages: [{ role, content }] }
 *   - NO temperature/top_p — those are rejected (400) on the current Opus/Fable
 *     tiers, so we send only the required fields.
 *   - response: content[] is a list of blocks; the answer is the joined text of
 *     the `type: "text"` blocks. A safety decline returns HTTP 200 with
 *     stop_reason "refusal" and empty content — handled as an empty answer.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility\Providers;

defined( 'ABSPATH' ) || exit;

final class Anthropic extends Provider {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	const API_VERSION = '2023-06-01';

	/** @var int Bounds the answer length (and therefore per-check token cost). */
	const MAX_TOKENS = 1024;

	/** {@inheritDoc} */
	public function id() {
		return 'anthropic';
	}

	/** {@inheritDoc} */
	public function query( $prompt, $key, $model, $web_search = false ) {
		$result = $this->post_json(
			self::ENDPOINT,
			array(
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
			),
			array(
				'model'      => $model,
				'max_tokens' => self::MAX_TOKENS,
				'messages'   => array(
					array( 'role' => 'user', 'content' => $prompt ),
				),
			)
		);

		if ( isset( $result['error'] ) ) {
			return $this->fail( $result['error'] );
		}

		return $this->ok( $this->text_from( $result['json'] ) );
	}

	/**
	 * Join the text of the `text` content blocks. Non-text blocks (and the empty
	 * content of a refusal) contribute nothing.
	 *
	 * @param array $json Decoded response.
	 * @return string
	 */
	private function text_from( array $json ) {
		$blocks = isset( $json['content'] ) && is_array( $json['content'] ) ? $json['content'] : array();

		$text = '';
		foreach ( $blocks as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= (string) $block['text'];
			}
		}
		return $text;
	}
}
