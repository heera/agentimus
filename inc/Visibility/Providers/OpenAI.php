<?php
/**
 * OpenAI (ChatGPT) provider.
 *
 * Two modes:
 *  - Default: the Chat Completions endpoint. Reflects what the model already knows
 *    about the brand from training.
 *  - Live web search ($web_search): the Responses API with the built-in web_search
 *    tool, so the answer is grounded on a live search and carries url_citation
 *    annotations. Needs a search-capable model (e.g. gpt-4.1). Wire shape verified
 *    against OpenAI's current Web Search (Responses API) docs.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility\Providers;

defined( 'ABSPATH' ) || exit;

final class OpenAI extends Provider {

	const CHAT_ENDPOINT      = 'https://api.openai.com/v1/chat/completions';
	const RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

	/** @var int Bounds the answer length (and therefore per-check token cost). */
	const MAX_TOKENS = 1024;

	/** {@inheritDoc} */
	public function id() {
		return 'openai';
	}

	/** {@inheritDoc} */
	public function query( $prompt, $key, $model, $web_search = false ) {
		return $web_search
			? $this->query_web( $prompt, $key, $model )
			: $this->query_chat( $prompt, $key, $model );
	}

	/**
	 * Training-memory answer via Chat Completions.
	 */
	private function query_chat( $prompt, $key, $model ) {
		$result = $this->post_json(
			self::CHAT_ENDPOINT,
			array( 'authorization' => 'Bearer ' . $key ),
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

		$text = isset( $result['json']['choices'][0]['message']['content'] )
			? (string) $result['json']['choices'][0]['message']['content']
			: '';

		return $this->answer( $text, array(), isset( $result['json']['choices'] ) );
	}

	/**
	 * Live-web answer via the Responses API + web_search tool.
	 */
	private function query_web( $prompt, $key, $model ) {
		$result = $this->post_json(
			self::RESPONSES_ENDPOINT,
			array( 'authorization' => 'Bearer ' . $key ),
			array(
				'model'             => $model,
				'tools'             => array( array( 'type' => 'web_search' ) ),
				'input'             => $prompt,
				'max_output_tokens' => self::MAX_TOKENS,
			),
			self::WEB_TIMEOUT // Live web search runs a slow server-side loop.
		);

		if ( isset( $result['error'] ) ) {
			return $this->fail( $result['error'] );
		}

		list( $text, $citations ) = $this->parse_responses( $result['json'] );
		return $this->answer( $text, $citations, isset( $result['json']['output'] ) );
	}

	/**
	 * Extract the answer text and cited URLs from a Responses API payload. The
	 * answer is the joined `output_text` of the `message` items; citations are the
	 * `url_citation` annotations on those texts.
	 *
	 * @param array $json Decoded response.
	 * @return array [ string $text, string[] $citations ]
	 */
	private function parse_responses( array $json ) {
		$text      = '';
		$citations = array();

		$output = isset( $json['output'] ) && is_array( $json['output'] ) ? $json['output'] : array();
		foreach ( $output as $item ) {
			if ( ! isset( $item['type'] ) || 'message' !== $item['type'] || empty( $item['content'] ) ) {
				continue;
			}
			foreach ( (array) $item['content'] as $part ) {
				if ( ! isset( $part['type'] ) || 'output_text' !== $part['type'] ) {
					continue;
				}
				$text .= (string) ( $part['text'] ?? '' );
				foreach ( (array) ( $part['annotations'] ?? array() ) as $a ) {
					if ( isset( $a['type'], $a['url'] ) && 'url_citation' === $a['type'] ) {
						$citations[] = (string) $a['url'];
					}
				}
			}
		}

		return array( $text, $citations );
	}
}
