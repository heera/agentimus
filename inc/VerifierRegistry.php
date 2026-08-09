<?php
/**
 * VerifierRegistry — the editable registry of bots this site can verify, and how.
 *
 * Verification is only ever a comparison against an operator's OWN published
 * commitment, and operators publish two kinds: reverse-DNS domain suffixes
 * ("genuine Googlebot PTRs end in .googlebot.com") and IP-range files
 * (googlebot.json, gptbot.json…). Each registry entry names a bot (the token its
 * User-Agent carries) and carries one or both commitments. {@see BotVerifier}
 * consumes the rDNS half, {@see BotRanges} the range half.
 *
 * The registry is OWNER-EDITABLE — the whole point. Built-ins ship for the
 * operators whose commitments we've confirmed, but a new bot may publish
 * verification data tomorrow, and an owner must be able to add it without
 * waiting for a plugin release — and to turn any built-in off. Two settings
 * hold the edits: `verifier_disabled` (built-in tokens switched off) and
 * `verifier_custom` (owner-added entries). Removing an entry only makes its
 * bot UNVERIFIABLE (verdicts stay 0 — unchecked); it never flags anything.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class VerifierRegistry {

	/** Ceiling on owner-added entries — far above real use, just a bound. */
	const MAX_CUSTOM = 20;

	/**
	 * Built-in entries. Domain suffixes match the operators' verification docs; range-file
	 * URLs were each fetched and format-checked before shipping (all use the shared
	 * `{"prefixes":[{"ipv4Prefix":…}]}` format Google introduced). Yandex publishes rDNS
	 * only — no range file.
	 *
	 * @return array<string,array{token:string,label:string,ua:string,domains:string[],url:string,builtin:bool}>
	 */
	public static function builtins() {
		$entries = array(
			'googlebot'     => array(
				'label'   => 'Googlebot',
				'ua'      => 'googlebot',
				'domains' => array( '.googlebot.com', '.google.com' ),
				'url'     => 'https://developers.google.com/search/apis/ipranges/googlebot.json',
			),
			'bingbot'       => array(
				'label'   => 'Bingbot',
				'ua'      => 'bingbot',
				'domains' => array( '.search.msn.com' ),
				'url'     => 'https://www.bing.com/toolbox/bingbot.json',
			),
			'duckduckbot'   => array(
				'label'   => 'DuckDuckBot',
				'ua'      => 'duckduckbot',
				'domains' => array( '.duckduckgo.com' ),
				'url'     => 'https://duckduckgo.com/duckduckbot.json',
			),
			'applebot'      => array(
				'label'   => 'Applebot',
				'ua'      => 'applebot',
				'domains' => array( '.applebot.apple.com', '.apple.com' ),
				'url'     => 'https://search.developer.apple.com/applebot.json',
			),
			'yandex'        => array(
				'label'   => 'Yandex',
				'ua'      => 'yandex',
				'domains' => array( '.yandex.com', '.yandex.net', '.yandex.ru' ),
				'url'     => '',
			),
			'gptbot'        => array(
				'label'   => 'GPTBot (OpenAI)',
				'ua'      => 'gptbot',
				'domains' => array(),
				'url'     => 'https://openai.com/gptbot.json',
			),
			'oai-searchbot' => array(
				'label'   => 'OAI-SearchBot (OpenAI)',
				'ua'      => 'oai-searchbot',
				'domains' => array(),
				'url'     => 'https://openai.com/searchbot.json',
			),
			'perplexitybot' => array(
				'label'   => 'PerplexityBot',
				'ua'      => 'perplexitybot',
				'domains' => array(),
				'url'     => 'https://www.perplexity.ai/perplexitybot.json',
			),
		);

		$out = array();
		foreach ( $entries as $token => $e ) {
			$e['token']    = $token;
			$e['builtin']  = true;
			$out[ $token ] = $e;
		}
		return $out;
	}

	/**
	 * The effective registry: built-ins the owner hasn't disabled, plus their custom
	 * entries (already sanitised at save — {@see Settings::sanitize}).
	 *
	 * @return array<string,array> Token => entry.
	 */
	public static function entries() {
		$all      = get_option( Settings::OPTION, array() );
		$disabled = isset( $all['verifier_disabled'] ) ? (array) $all['verifier_disabled'] : array();
		$custom   = isset( $all['verifier_custom'] ) ? (array) $all['verifier_custom'] : array();

		$entries = self::builtins();
		foreach ( $disabled as $token ) {
			unset( $entries[ (string) $token ] );
		}
		foreach ( $custom as $e ) {
			if ( ! is_array( $e ) || empty( $e['token'] ) || empty( $e['ua'] ) ) {
				continue;
			}
			$entries[ (string) $e['token'] ] = array(
				'token'   => (string) $e['token'],
				'label'   => isset( $e['label'] ) ? (string) $e['label'] : (string) $e['token'],
				'ua'      => strtolower( (string) $e['ua'] ),
				'domains' => isset( $e['domains'] ) ? array_values( (array) $e['domains'] ) : array(),
				'url'     => isset( $e['url'] ) ? (string) $e['url'] : '',
				'builtin' => false,
			);
		}

		/**
		 * Filter the effective verified-bot registry (token => entry).
		 *
		 * @param array $entries Registry entries.
		 */
		return (array) apply_filters( 'agentimus_verifier_registry', $entries );
	}

	/**
	 * One entry, or null.
	 *
	 * @param string $token Entry token.
	 * @return array|null
	 */
	public static function entry( $token ) {
		$entries = self::entries();
		return isset( $entries[ $token ] ) ? $entries[ $token ] : null;
	}

	/**
	 * The registry entry a lowercased UA claims, or '' when it claims none. Longest
	 * UA-needle wins, so a specific claim ("oai-searchbot") is never shadowed by a
	 * shorter one it happens to contain.
	 *
	 * @param string $ua_lc Lowercased User-Agent.
	 * @return string Token, or ''.
	 */
	public static function claimed( $ua_lc ) {
		$ua_lc = (string) $ua_lc;
		if ( '' === $ua_lc ) {
			return '';
		}
		$best     = '';
		$best_len = 0;
		foreach ( self::entries() as $token => $e ) {
			$needle = (string) $e['ua'];
			if ( '' === $needle || strlen( $needle ) <= $best_len ) {
				continue;
			}
			if ( false !== strpos( $ua_lc, $needle ) ) {
				$best     = (string) $token;
				$best_len = strlen( $needle );
			}
		}
		return $best;
	}

	/**
	 * The rDNS half of the registry: token => domain suffixes, for entries that have
	 * them. This is {@see BotVerifier::engine_domains()}'s source, so disabling or
	 * adding an entry changes every rDNS consumer at once.
	 *
	 * @return array<string,string[]>
	 */
	public static function rdns_map() {
		$map = array();
		foreach ( self::entries() as $token => $e ) {
			if ( ! empty( $e['domains'] ) ) {
				$map[ $token ] = (array) $e['domains'];
			}
		}
		return $map;
	}

}
