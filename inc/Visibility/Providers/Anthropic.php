<?php
/**
 * Anthropic (Claude) provider — the Messages endpoint.
 *
 * Two modes:
 *  - Default: a plain Messages request. Reflects what Claude already knows about
 *    the brand from training.
 *  - Live web search ($web_search): adds Claude's built-in server-side web_search
 *    tool, so the answer is grounded on a live search and carries source citations
 *    (see citations_from). Wire shape verified against Anthropic's current
 *    Messages + web search tool reference.
 *
 * Wire details:
 *   - POST https://api.anthropic.com/v1/messages
 *   - headers: x-api-key, anthropic-version: 2023-06-01
 *   - body: { model, max_tokens, messages: [{ role, content }], tools?: [...] }
 *   - NO temperature/top_p — those are rejected (400) on the current Opus/Fable
 *     tiers, so we send only the required fields.
 *   - response: content[] is a list of blocks; the answer is the joined text of
 *     the `type: "text"` blocks, and web-search citations ride on those blocks.
 *     A safety decline returns HTTP 200 with stop_reason "refusal" and empty
 *     content — handled as an empty answer.
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

	/** @var int Cap on live searches per check — keeps a grounded answer cheap and,
	 * just as importantly, fast enough to finish inside the web timeout. */
	const MAX_WEB_SEARCHES = 3;

	/** {@inheritDoc} */
	public function id() {
		return 'anthropic';
	}

	/** {@inheritDoc} */
	public function query( $prompt, $key, $model, $web_search = false ) {
		$body = array(
			'model'      => $model,
			'max_tokens' => self::MAX_TOKENS,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
		);

		// Ground the answer on a live web search. Claude runs the search on
		// Anthropic's own infrastructure and attaches source citations to the
		// answer text, which citations_from() collects.
		if ( $web_search ) {
			$body['tools'] = array(
				array(
					'type'     => $this->web_search_type( $model ),
					'name'     => 'web_search',
					'max_uses' => self::MAX_WEB_SEARCHES,
				),
			);
		}

		$result = $this->post_json(
			self::ENDPOINT,
			array(
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
			),
			$body,
			$web_search ? self::WEB_TIMEOUT : self::TIMEOUT
		);

		if ( isset( $result['error'] ) ) {
			return $this->fail( $result['error'] );
		}

		$json = $result['json'];
		return $this->answer(
			$this->text_from( $json ),
			$this->citations_from( $json ),
			isset( $json['content'] )
		);
	}

	/**
	 * The web-search tool version to request for a given model. The dynamic-filtering
	 * tool (web_search_20260209) is available on the current Opus/Sonnet/Fable tiers;
	 * lighter or older models (e.g. Claude Haiku) and unknown custom IDs fall back to
	 * the original tool, which is universally accepted. Picking a version the model
	 * doesn't support is a 400, so this stays conservative.
	 *
	 * @param string $model Model id.
	 * @return string
	 */
	private function web_search_type( $model ) {
		$dynamic = array( 'opus-4-8', 'opus-4-7', 'opus-4-6', 'sonnet-5', 'sonnet-4-6', 'fable-5', 'mythos-5' );
		foreach ( $dynamic as $family ) {
			if ( false !== strpos( (string) $model, $family ) ) {
				return 'web_search_20260209';
			}
		}
		return 'web_search_20250305';
	}

	/**
	 * Cited source URLs. Web search adds `web_search_result_location` citations to the
	 * answer's text blocks — the URLs Claude actually referenced (the direct analogue
	 * of the other engines' answer citations). Empty when the answer wasn't grounded.
	 *
	 * @param array $json Decoded response.
	 * @return string[]
	 */
	private function citations_from( array $json ) {
		$blocks = isset( $json['content'] ) && is_array( $json['content'] ) ? $json['content'] : array();

		$urls = array();
		foreach ( $blocks as $block ) {
			if ( ! isset( $block['type'] ) || 'text' !== $block['type'] || empty( $block['citations'] ) ) {
				continue;
			}
			foreach ( (array) $block['citations'] as $c ) {
				if ( isset( $c['type'], $c['url'] ) && 'web_search_result_location' === $c['type'] ) {
					$urls[] = (string) $c['url'];
				}
			}
		}
		return $urls;
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
