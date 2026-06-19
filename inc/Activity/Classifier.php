<?php
/**
 * Classify a raw User-Agent string into a friendly agent label, so the activity
 * dashboard reads "Claude", "GPTBot", "Perplexity" rather than raw UA noise.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

defined( 'ABSPATH' ) || exit;

final class Classifier {

	/**
	 * Lowercase UA-token → label. Ordered: more specific tokens first (e.g.
	 * applebot-extended before applebot). Extensible via filter.
	 *
	 * @return array<string,string>
	 */
	private static function map() {
		$map = array(
			'gptbot'             => 'GPTBot (OpenAI)',
			'oai-searchbot'      => 'OpenAI SearchBot',
			'chatgpt-user'       => 'ChatGPT',
			'claudebot'          => 'ClaudeBot (Anthropic)',
			'claude-user'        => 'Claude',
			'anthropic-ai'       => 'Anthropic',
			'perplexitybot'      => 'PerplexityBot',
			'perplexity-user'    => 'Perplexity',
			'google-extended'    => 'Google-Extended',
			'googlebot'          => 'Googlebot',
			'bingbot'            => 'Bingbot',
			'applebot-extended'  => 'Applebot-Extended',
			'applebot'           => 'Applebot',
			'amazonbot'          => 'Amazonbot',
			'bytespider'         => 'Bytespider (ByteDance)',
			'ccbot'              => 'CCBot (Common Crawl)',
			'meta-externalagent' => 'Meta',
			'facebookexternalhit' => 'Meta',
			'cohere-ai'          => 'Cohere',
			'diffbot'            => 'Diffbot',
			'duckduckbot'        => 'DuckDuckGo',
			'yandexbot'          => 'YandexBot',
		);

		/**
		 * Filter the User-Agent → label map.
		 *
		 * @param array<string,string> $map Token => label.
		 */
		return (array) apply_filters( 'agentimus_agent_map', $map );
	}

	/**
	 * Resolve a label for a User-Agent.
	 *
	 * @param string $ua Raw User-Agent.
	 * @return string
	 */
	public static function classify( $ua ) {
		$ua = strtolower( (string) $ua );
		// No User-Agent at all — distinct from an unrecognized one, and worth
		// naming as such rather than hiding it under a vague "Unknown".
		if ( '' === $ua ) {
			return 'No user-agent';
		}
		foreach ( self::map() as $token => $label ) {
			if ( false !== strpos( $ua, $token ) ) {
				return $label;
			}
		}
		// A self-declared crawler we don't have a friendly name for.
		if ( preg_match( '/bot|crawler|spider|crawl/', $ua ) ) {
			return 'Other bot';
		}
		// HTTP client libraries / command-line tools — scripts, not agents
		// (curl, wget, Python requests, Node fetch, Go, Java, Postman, …).
		if ( preg_match( '#curl|wget|python|node-fetch|\bnode\b|go-http|okhttp|libwww|postman|httpie|axios|guzzle|java/|ruby|^php#', $ua ) ) {
			return 'Script/tool';
		}
		if ( false !== strpos( $ua, 'mozilla' ) ) {
			return 'Browser';
		}
		// Non-empty, but matches nothing above.
		return 'Unidentified';
	}
}
