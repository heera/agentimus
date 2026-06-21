<?php
/**
 * Guard — optional hard enforcement at the agent endpoints. When the owner turns
 * blocking on, a request whose User-Agent is on the denylist (or matches the
 * spoofed/legacy-device heuristic) is refused with a 403 instead of being served
 * the discovery/llms documents.
 *
 * OFF by default, by design. The standards-track default is the *advisory*
 * robots.txt / Content-Signal policy — a polite request that well-behaved agents
 * honour. This Guard is the teeth for owners who want to actually stop the
 * scrapers that ignore robots.txt.
 *
 * Scope is deliberately narrow: only the documents this plugin GENERATES
 * (discovery.json, agent-card.json, llms.txt, …) are gated. Real on-disk
 * /.well-known files — ACME HTTP-01 challenges, a hand-placed security.txt — are
 * streamed by WellKnown::stream() and are NEVER guarded, so certificate issuance
 * and other infrastructure can't be broken by a blocklist.
 *
 * The decision ({@see denies()}) is pure and unit-tested; the response
 * ({@see maybe_block()}) is the thin "emit 403 and exit" wrapper the serve paths
 * call.
 *
 * @package Agentimus
 */

namespace Agentimus;

use Agentimus\Activity\Classifier;

defined( 'ABSPATH' ) || exit;

final class Guard {

	/**
	 * Whether a request from this User-Agent should be denied, given the current
	 * settings. Pure: no output, no exit — safe to call and test in isolation.
	 *
	 * @param string|null $ua Raw User-Agent. Read from the request when null.
	 * @return bool
	 */
	public static function denies( $ua = null ) {
		if ( null === $ua ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only UA check on a public endpoint.
		}
		// Bound the string we match against: a malicious client could send a huge
		// UA, and a custom regex over an unbounded subject is a DoS surface.
		$ua_lc    = substr( strtolower( trim( (string) $ua ) ), 0, 1000 );
		$settings = new Settings();
		$deny     = false;

		// A missing UA is recorded as "No user-agent" but never blocked here: it's
		// too blunt (many legitimate fetchers omit it) and trivially spoofed anyway.
		// The protected allow-list is the safety net: a verified good agent (search
		// engines by default) is NEVER denied, so an over-broad rule like "bot"
		// can't accidentally de-index the site.
		if ( '' !== $ua_lc && $settings->enabled( 'block_agents' ) && ! self::is_protected( $ua_lc ) ) {
			// 1. The owner's explicit denylist. Each entry is a substring by default,
			// a glob when it has * / ?, or a regex when wrapped in /…/ — see ua_matches().
			foreach ( (array) $settings->get( 'blocked_agents', array() ) as $needle ) {
				if ( self::ua_matches( $ua_lc, $needle ) ) {
					$deny = true;
					break;
				}
			}

			// 2. The spoofed/legacy-device heuristic — the SAME definition the
			// activity log labels "Likely spoof/scanner", so blocking and reporting
			// can never drift apart.
			if ( ! $deny && $settings->enabled( 'block_spoofed' ) && Classifier::is_spoof( $ua_lc ) ) {
				$deny = true;
			}
		}

		/**
		 * Final say on whether to deny this request. Lets an add-on layer its own
		 * policy on top — an IP allow-list exception that rescues a flagged UA, or
		 * an extra rule that denies one the built-ins passed. Runs AFTER the
		 * protected allow-list, so an add-on can deliberately deny a protected
		 * agent if it really means to (intent overrides the accident-guard).
		 *
		 * @param bool   $deny Whether to deny, per the built-in rules.
		 * @param string $ua   Lowercased User-Agent ('' when absent).
		 */
		return (bool) apply_filters( 'agentimus_deny_request', $deny, $ua_lc );
	}

	/**
	 * Whether a lowercased UA matches a single denylist entry. The entry is read as:
	 *   • REGEX  when wrapped in /…/ — e.g. `/semrushbot\/\d+/` (forced case-insensitive);
	 *   • GLOB   when it contains `*` or `?` — `*` = any run, `?` = any one char;
	 *   • plain case-insensitive SUBSTRING otherwise (the safe default).
	 * Two accident-guards: an entry that is ONLY wildcards ("*") matches nothing
	 * (blocking everyone is never a sane denylist row, so we treat it as a no-op),
	 * and a regex that fails to compile degrades to a literal substring test so a
	 * typo can never error the endpoint.
	 *
	 * @param string $ua     Lowercased, length-bounded User-Agent.
	 * @param string $needle Raw denylist entry.
	 * @return bool
	 */
	private static function ua_matches( $ua, $needle ) {
		$needle = trim( (string) $needle );
		if ( '' === $needle ) {
			return false;
		}

		// Explicit /regex/ — only when it actually has an opening and a later closing
		// slash; otherwise it's just a path-y substring and stays literal.
		if ( '/' === $needle[0] && false !== strpos( $needle, '/', 1 ) ) {
			$regex = self::compile_regex( $needle );
			if ( null !== $regex ) {
				return 1 === @preg_match( $regex, $ua ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- pathological admin pattern must not warn on a public request.
			}
			// Unparseable — fall through and match the raw text literally.
		}

		// Glob — translate * and ? after escaping everything else, so no other
		// metacharacter in the admin's text can do anything unexpected.
		if ( false !== strpos( $needle, '*' ) || false !== strpos( $needle, '?' ) ) {
			if ( '' === trim( str_replace( array( '*', '?' ), '', $needle ) ) ) {
				return false; // All-wildcard entry → would block everyone → no-op.
			}
			$regex = '#' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( strtolower( $needle ), '#' ) ) . '#';
			return 1 === @preg_match( $regex, $ua ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- defensive; glob output is always valid.
		}

		// Default: plain case-insensitive substring.
		return false !== strpos( $ua, strtolower( $needle ) );
	}

	/**
	 * Turn a `/pattern/` entry into a usable, case-insensitive PCRE, or null if it
	 * doesn't compile. Re-delimited to `#` (so the user's `/` need not be escaped)
	 * and test-compiled against the empty string before use.
	 *
	 * @param string $needle e.g. "/semrushbot\/\d+/".
	 * @return string|null
	 */
	private static function compile_regex( $needle ) {
		$close = strrpos( $needle, '/' );
		$body  = (string) substr( $needle, 1, $close - 1 );
		if ( '' === $body ) {
			return null;
		}
		$regex = '#' . str_replace( '#', '\#', $body ) . '#i';
		return false === @preg_match( $regex, '' ) ? null : $regex; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- compile-probe; invalid pattern returns false.
	}

	/**
	 * Whether the UA belongs to a protected agent that must never be denied — the
	 * accident-guard against an over-broad rule. Defaults to the major search
	 * engines (blocking those carries real, silent SEO cost). Filterable: empty it
	 * to allow blocking anything, or extend it with your own always-allow agents.
	 *
	 * @param string $ua_lc Lowercased User-Agent.
	 * @return bool
	 */
	private static function is_protected( $ua_lc ) {
		foreach ( self::protected_agents() as $allow ) {
			if ( '' !== $allow && false !== strpos( $ua_lc, $allow ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The always-allow agent substrings (lowercase). The default protects the
	 * search engines whose accidental blocking would hurt the site most.
	 *
	 * @return string[]
	 */
	public static function protected_agents() {
		$protected = array( 'googlebot', 'bingbot', 'duckduckbot', 'applebot', 'yandex' );

		/**
		 * Filter the always-allow list — agents that the block feature must never
		 * deny, whatever the denylist or spoof heuristic say.
		 *
		 * @param string[] $protected Lowercase UA substrings.
		 */
		$protected = (array) apply_filters( 'agentimus_block_allowlist', $protected );
		return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $protected ) ) ) );
	}

	/**
	 * Refuse the current request with a bare 403 and stop — but only when
	 * {@see denies()} says so. A no-op otherwise, so a serve path can gate every
	 * emit with a single leading call. Mirrors the HEAD handling of the real
	 * emitters (headers, no body).
	 */
	public static function maybe_block() {
		if ( ! self::denies() ) {
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
		}
		$is_head = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		if ( ! $is_head ) {
			echo "403 Forbidden\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- static plain text.
		}
		exit;
	}
}
