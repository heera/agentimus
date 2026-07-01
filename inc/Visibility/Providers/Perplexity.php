<?php
/**
 * Perplexity provider — its Chat Completions endpoint answers from live web
 * search and returns the sources it used, so this is the engine that gives the
 * richest "did an AI actually cite my site" signal.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility\Providers;

defined( 'ABSPATH' ) || exit;

final class Perplexity extends Provider {

	const ENDPOINT = 'https://api.perplexity.ai/chat/completions';

	/** {@inheritDoc} */
	public function id() {
		return 'perplexity';
	}

	/** {@inheritDoc} */
	public function query( $prompt, $key, $model, $web_search = false ) {
		$result = $this->post_json(
			self::ENDPOINT,
			array( 'authorization' => 'Bearer ' . $key ),
			array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'user', 'content' => $prompt ),
				),
			)
		);

		if ( isset( $result['error'] ) ) {
			return $this->fail( $result['error'] );
		}

		$json = $result['json'];
		$text = isset( $json['choices'][0]['message']['content'] )
			? (string) $json['choices'][0]['message']['content']
			: '';

		return $this->ok( $text, $this->citations( $json ) );
	}

	/**
	 * Perplexity returns its sources as a top-level `citations` array of URLs, or
	 * the newer `search_results` list of objects. Read whichever is present.
	 *
	 * @param array $json Decoded response.
	 * @return string[]
	 */
	private function citations( array $json ) {
		if ( isset( $json['citations'] ) && is_array( $json['citations'] ) ) {
			return array_map( 'strval', $json['citations'] );
		}
		if ( isset( $json['search_results'] ) && is_array( $json['search_results'] ) ) {
			$urls = array();
			foreach ( $json['search_results'] as $r ) {
				if ( isset( $r['url'] ) ) {
					$urls[] = (string) $r['url'];
				}
			}
			return $urls;
		}
		return array();
	}
}
