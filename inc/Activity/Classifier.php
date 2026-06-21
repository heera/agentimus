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
		// A client impersonating a long-dead mobile/embedded stack (Symbian, J2ME,
		// old Nokia/BlackBerry/Windows CE…). No real visitor fetches a machine
		// endpoint from a 2004 feature phone — these are near-always scanners and
		// scrapers hiding behind a quaint, "harmless" user-agent. Checked before the
		// generic bot/browser tiers because such strings usually still carry a
		// "Mozilla" token and would otherwise be mislabelled "Browser".
		if ( self::is_spoof( $ua ) ) {
			return 'Likely spoof/scanner';
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

	/**
	 * Lowercase regex fragments that mark a User-Agent as a near-certain spoof: a
	 * client claiming a long-obsolete mobile/embedded PLATFORM that no longer exists
	 * on the live web, let alone fetching a JSON/text machine endpoint. Scanners reach
	 * for these precisely because they read as an innocuous old handset.
	 *
	 * These are platform/stack markers (Symbian, Java ME, Windows CE, Palm…), NOT brand
	 * or carrier names: a brand like "Nokia" or carrier like "DoCoMo" still ships modern
	 * Android phones, so matching those produced false positives (a real "Nokia 5.4"
	 * flagged as a scanner — and, with blocking on, 403'd). is_spoof() also bails on any
	 * "android" UA, so a modern device echoing an old name is never caught. Filterable.
	 *
	 * @return string[]
	 */
	private static function spoof_signatures() {
		$signatures = array(
			'symbianos', 'series ?60', 'series ?40', 's60v',
			'midp[-/]', 'cldc[-/]', 'j2me',
			'sonyericsson', 'samsung-sgh',
			'blackberry ?[0-9]', 'bb10',
			'windows ?ce', 'wince', 'pocketpc',
			'openwave', 'up\.browser', 'up\.link', 'avantgo',
			'portalmmm',
			'netfront', 'obigo', 'teleca', 'polaris ?[0-9]',
			'palmos', 'maemo',
		);

		/**
		 * Filter the spoof/legacy-device signatures. Each entry is a lowercase
		 * regex fragment matched (case-insensitively, '#' delimiter) against the UA.
		 *
		 * @param string[] $signatures Regex fragments.
		 */
		return (array) apply_filters( 'agentimus_spoof_signatures', $signatures );
	}

	/**
	 * Whether a User-Agent looks like a spoofed/legacy-device scanner — the same
	 * test that powers the "Likely spoof/scanner" label, exposed so the optional
	 * request Guard can DENY exactly what the activity log NAMES (one definition,
	 * no drift). Known agents are not pre-excluded here because the only caller for
	 * blocking, and classify() itself, both check the known-agent map first.
	 *
	 * @param string $ua Raw User-Agent.
	 * @return bool
	 */
	public static function is_spoof( $ua ) {
		$ua = strtolower( (string) $ua );
		if ( '' === $ua ) {
			return false;
		}
		// A genuine dead-platform device (Symbian, Java ME, Windows CE, Palm…) predates
		// Android and never runs it. So any UA that says "android" is a modern device —
		// bail out. This kills false positives like current Nokia ("Nokia 5.4") or
		// carrier (DoCoMo) Android phones whose model/carrier name echoes a dead brand.
		if ( false !== strpos( $ua, 'android' ) ) {
			return false;
		}
		foreach ( self::spoof_signatures() as $signature ) {
			if ( preg_match( '#' . $signature . '#', $ua ) ) {
				return true;
			}
		}
		return false;
	}
}
