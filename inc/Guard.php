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
	 * Identity checks (the verification strip and the proven-impostor rule) compare
	 * the CLIENT IP against the UA's claim — meaningful only when that IP belongs to
	 * the request that carried this UA, i.e. the LIVE request. A caller passing a UA
	 * string (the review panel computing a row's "already blocked" badge) is asking
	 * about standing rules in the abstract: there the current IP is the admin's own
	 * browser, which would conclusively "fail" any bot's identity check and hide
	 * every impostor row as already-handled. So identity checks default to ON for
	 * the live request and OFF for an explicit UA; tests that pin an IP via the
	 * `agentimus_client_ip` filter pass true.
	 *
	 * @param string|null $ua             Raw User-Agent. Read from the request when null.
	 * @param bool|null   $check_identity Run the IP-based identity checks. Null = auto
	 *                                    (true only when $ua is read from the request).
	 * @return bool
	 */
	public static function denies( $ua = null, $check_identity = null ) {
		if ( null === $check_identity ) {
			$check_identity = null === $ua;
		}
		if ( null === $ua ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only UA check on a public endpoint.
		}
		// Bound the string we match against: a malicious client could send a huge
		// UA, and a custom regex over an unbounded subject is a DoS surface.
		$ua_lc    = substr( strtolower( trim( (string) $ua ) ), 0, 1000 );
		$settings = new Settings();
		$deny     = false;

		$protected = self::is_protected( $ua_lc );

		// Optional identity verification. When it's on, a UA that CLAIMS a real search
		// engine keeps its always-allow status unless the claim CONCLUSIVELY failed —
		// by forward-confirmed reverse DNS, or (when rDNS couldn't answer) by the
		// operator's FRESH published IP-range file ({@see conclusively_forged}). It is
		// FAIL-OPEN: a slow resolver, a spent budget, a stale or unfetched range file —
		// anything inconclusive — keeps the crawler protected, because a hiccup must
		// never lose a real crawler. Only definite operator-sourced evidence drops it
		// to the block rules. The owner's explicit allow-list is a deliberate choice,
		// not a spoofable identity, so it protects unconditionally.
		if ( $check_identity && $protected && '' !== $ua_lc && self::verification_on() && self::is_real_engine( $ua_lc )
			&& ! self::owner_allows( $ua_lc ) && self::conclusively_forged( $ua_lc ) ) {
			$protected = false;
		}

		// A missing UA is recorded as "No user-agent" but never blocked here: it's
		// too blunt (many legitimate fetchers omit it) and trivially spoofed anyway.
		// The protected allow-list is the safety net: a verified good agent (search
		// engines by default) is NEVER denied, so an over-broad rule like "bot"
		// can't accidentally de-index the site.
		if ( '' !== $ua_lc && $settings->enabled( 'block_agents' ) && ! $protected ) {
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
			// can never drift apart. A request cryptographically proven to come from
			// a recognised operator (Web Bot Auth) is exempt from this HEURISTIC —
			// an inference must not outvote a proof — but not from the owner's
			// explicit denylist above, which already ran.
			if ( ! $deny && $settings->enabled( 'block_spoofed' ) && Classifier::is_spoof( $ua_lc )
				&& ! ( self::verification_on() && '' !== BotSignature::verified_known_label() ) ) {
				$deny = true;
			}

			// 3. A PROVEN impostor: the client claims a bot in the verifier registry and
			// the claim conclusively failed (reverse DNS, or a fresh published-range
			// file). Same switch as the spoof class — an identity forgery is the same
			// kind of deception, just proven instead of inferred. This is what refuses
			// a fake "GPTBot" outright: it isn't protected (only engines are), so the
			// strip above never touched it, and its name is rarely on a denylist.
			// Fail-open like everything here: inconclusive evidence never denies. Note
			// the ORDER — the owner's denylist ran first, so a VERIFIED bot the owner
			// explicitly blocks stays blocked (verification never overrides an owner
			// rule); and a protected/owner-allowed UA never reaches this branch at all.
			if ( $check_identity && ! $deny && $settings->enabled( 'block_spoofed' ) && self::verification_on()
				&& self::conclusively_forged( $ua_lc ) ) {
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
	 * Whether this request's claimed bot identity CONCLUSIVELY failed verification —
	 * the one predicate that may cost a claimed bot its protection or (under
	 * block_spoofed) the request. Two operator-sourced proofs, tried in order:
	 *   • forward-confirmed reverse DNS answered a definite NO (verdict false); or
	 *   • rDNS was inapplicable/inconclusive (null) AND the claimed operator's
	 *     published IP-range file is FRESH and excludes this address
	 *     ({@see BotRanges::verdict} — its staleness asymmetry means a stale or
	 *     unfetched file can only ever answer "unchecked" here, never "forged").
	 * A UA claiming nothing in the registry is never "forged" (there's no claim to
	 * fail), and a POSITIVE rDNS verdict short-circuits — a verified crawler is not
	 * re-tried against ranges. Cheap on the serve path: the rDNS verdict is cached
	 * per IP, and the range check is an in-memory compare against the cron-fetched
	 * cache — no lookup, no fetch, ever.
	 *
	 * @param string $ua_lc Lowercased User-Agent.
	 * @return bool
	 */
	private static function conclusively_forged( $ua_lc ) {
		// Cheapest and strongest first: a Web Bot Auth signature that conclusively
		// fails the math is a forged claim in its own right — no IP needed, no
		// lookup spent. An UNSIGNED request never reaches this branch's true.
		if ( BotSignature::conclusively_failed() ) {
			return true;
		}

		$ip = self::client_ip();
		if ( '' === $ip ) {
			return false; // Nothing to check against → inconclusive → fail open.
		}
		$verdict = BotVerifier::verify_engine( $ua_lc, $ip ); // true | false | null.
		if ( false === $verdict ) {
			return true;
		}
		if ( null !== $verdict ) {
			return false; // Forward-confirmed genuine.
		}
		$token = VerifierRegistry::claimed( $ua_lc );
		return '' !== $token && 2 === BotRanges::verdict( $token, $ip );
	}

	/**
	 * Whether the UA is TRUSTED — must never be denied, and never surfaced for
	 * review — the accident-guard against an over-broad rule. Trust has two
	 * forgery-resistant sources:
	 *   • a STRUCTURED match for a real search engine (see engine_signatures) —
	 *     deliberately NOT a loose substring, so a scanner that merely appends
	 *     "googlebot" to its UA earns nothing; and
	 *   • the owner's explicit allow-list (the activity panel's "Allow"), matched
	 *     as a substring because the owner chose those tokens themselves.
	 *
	 * A User-Agent is forgeable, so this can never PROVE identity (only verifying
	 * the source network address — e.g. reverse DNS — can, and that isn't reliably
	 * available behind every CDN/host, so we don't depend on it). What structured
	 * matching does remove is the trivial "append the magic word" bypass, with no
	 * network call — identical behaviour on Cloudflare, a cloud host, or cheap
	 * shared hosting.
	 *
	 * @param string $ua_lc Lowercased User-Agent.
	 * @return bool
	 */
	private static function is_protected( $ua_lc ) {
		// Deliberately NOT extended to Web Bot Auth verified signers: protection
		// bypasses even the owner's denylist, and that accident-guard exists for
		// search engines (an over-broad rule must not de-index the site). A signed
		// AI agent the owner explicitly blocks stays blocked — verification never
		// overrides an owner rule (the 1.24 doctrine). Verified signers are instead
		// exempted from the spoof HEURISTIC in denies(), where a false positive is
		// ours, not the owner's.
		return self::is_real_engine( $ua_lc ) || self::owner_allows( $ua_lc );
	}

	/**
	 * Whether the UA matches one of the owner's explicit always-allow substrings — a
	 * deliberate choice by the owner, so it is never subject to bot verification (unlike
	 * a spoofable search-engine claim).
	 *
	 * @param string $ua_lc Lowercased User-Agent.
	 * @return bool
	 */
	private static function owner_allows( $ua_lc ) {
		foreach ( self::allow_substrings() as $allow ) {
			if ( '' !== $allow && false !== strpos( $ua_lc, $allow ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether forward-confirmed reverse-DNS bot verification is enabled. OFF by default
	 * — DNS is an outbound lookup, and this plugin makes none unless the owner opts in
	 * (via this filter). When on it strengthens the always-allow list so a spoofed
	 * search-engine UA can no longer inherit a real crawler's trust. {@see BotVerifier}.
	 *
	 * @return bool
	 */
	public static function verification_on() {
		$on = (bool) ( new Settings() )->enabled( 'verify_bots' );

		/**
		 * Enable forward-confirmed reverse-DNS verification of claimed search engines.
		 * Defaults to the `verify_bots` setting; a filter still wins, so it can be forced
		 * on/off in code regardless of the admin toggle.
		 *
		 * @param bool $on Whether verification is enabled (the stored setting by default).
		 */
		return (bool) apply_filters( 'agentimus_verify_bots', $on );
	}

	/**
	 * The request's source IP for verification. {@see ClientIp::resolve} returns the real
	 * client behind a trusted proxy/CDN (e.g. Cloudflare's CF-Connecting-IP) automatically
	 * — but only when the direct peer is provably that proxy, so the header can't be
	 * forged; otherwise REMOTE_ADDR. Runs only on the verification path, and stays
	 * filterable so a site can still override it entirely.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = ClientIp::resolve();

		/**
		 * Filter the client IP used for bot verification. Already resolves the real client
		 * behind a trusted proxy; this filter can still override it (e.g. an unusual proxy
		 * header, or to pin a value in tests).
		 *
		 * @param string $ip The resolved source IP.
		 */
		return (string) apply_filters( 'agentimus_client_ip', $ip );
	}

	/**
	 * Whether a lowercased UA structurally matches a real search-engine crawler.
	 * Each signature requires the engine's product token in its genuine form — the
	 * `name/version` (or `Googlebot-Image/…`) shape at a UA-token boundary — so a
	 * forged "…some scanner googlebot" (a bare word, no version, not at a product
	 * position) is not mistaken for the real crawler.
	 *
	 * @param string $ua_lc Lowercased User-Agent.
	 * @return bool
	 */
	public static function is_real_engine( $ua_lc ) {
		foreach ( self::engine_signatures() as $signature ) {
			if ( 1 === @preg_match( $signature, $ua_lc ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a filtered pattern must never warn on a public request.
				return true;
			}
		}
		return false;
	}

	/**
	 * Structured UA signatures for the built-in protected search engines —
	 * anchored to a token boundary and the real product/version shape, NOT a bare
	 * substring. Patterns run against the lowercased UA. Filterable so a site can
	 * add or tighten engines (e.g. its CDN's own verified crawler).
	 *
	 * @return string[] PCRE patterns.
	 */
	public static function engine_signatures() {
		$signatures = array(
			'~(?:^|[\s(;,])googlebot[/-]~', // Googlebot/2.1, Googlebot-Image/1.0, …
			'~(?:^|[\s(;,])bingbot/~',      // bingbot/2.0
			'~(?:^|[\s(;,])duckduckbot/~',  // DuckDuckBot/1.1
			'~(?:^|[\s(;,])applebot/~',     // Applebot/0.1
			'~(?:^|[\s(;,])yandex[a-z]*/~', // YandexBot/3.0, YandexImages/3.0, …
		);
		/**
		 * Filter the structured search-engine signatures the Guard treats as
		 * always-allowed (and never surfaces for review). Lowercase-subject PCRE.
		 *
		 * @param string[] $signatures PCRE patterns.
		 */
		return (array) apply_filters( 'agentimus_engine_signatures', $signatures );
	}

	/**
	 * The owner's explicit always-allow substrings: the activity panel's "Allow"
	 * list, plus the legacy filter. Matched as plain substrings because the owner
	 * picked the tokens deliberately. The built-in search engines are NOT here —
	 * they are matched structurally (see engine_signatures).
	 *
	 * @return string[]
	 */
	private static function allow_substrings() {
		$allowed = (array) ( new Settings() )->get( 'allowed_agents', array() );
		/**
		 * Filter the owner's always-allow substrings — agents the block feature
		 * must never deny, whatever the denylist or spoof heuristic say.
		 *
		 * @param string[] $allowed Lowercase UA substrings.
		 */
		$allowed = (array) apply_filters( 'agentimus_block_allowlist', $allowed );
		return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $allowed ) ) ) );
	}

	/**
	 * The built-in always-allowed search engines, in display casing. These are
	 * matched structurally by engine_signatures() and can't be removed via the
	 * owner's list — this is the human-readable companion the admin UI shows so the
	 * owner knows exactly what is trusted automatically. Single source of truth:
	 * protected_agents() lowercases it, so the two never drift.
	 *
	 * @return string[]
	 */
	public static function default_allowed() {
		$engines = array( 'Googlebot', 'Bingbot', 'DuckDuckBot', 'Applebot', 'Yandex' );
		/**
		 * Filter the built-in always-allowed engine NAMES shown in the admin (display
		 * only — the actual matcher is engine_signatures(); keep the two in step).
		 *
		 * @param string[] $engines Display-cased engine names.
		 */
		return array_values( array_filter( (array) apply_filters( 'agentimus_default_allowed', $engines ) ) );
	}

	/**
	 * The always-allow agents, for display/inspection: the built-in search-engine
	 * NAMES plus the owner's allow-list. NOTE matching uses engine_signatures()
	 * (structured) + allow_substrings(), not this loose list — see is_protected().
	 *
	 * @return string[]
	 */
	public static function protected_agents() {
		$engines = array_map( 'strtolower', self::default_allowed() );
		return array_values( array_unique( array_merge( $engines, self::allow_substrings() ) ) );
	}

	/**
	 * Whether a RAW User-Agent belongs to a protected/allow-listed agent (the
	 * search engines that are never denied). Public companion to the internal
	 * lowercase test, so other modules — e.g. the activity panel's threat view —
	 * can treat exactly the same agents as trusted (one definition, no drift).
	 *
	 * @param string $ua Raw User-Agent.
	 * @return bool
	 */
	public static function is_protected_ua( $ua ) {
		return self::is_protected( substr( strtolower( trim( (string) $ua ) ), 0, 1000 ) );
	}

	/**
	 * Derive a SAFE denylist token from a raw User-Agent — the substring the
	 * activity panel's one-click "Block" appends to blocked_agents. Returns '' when
	 * no specific, safe token can be found, so the caller never adds an over-broad
	 * rule. Guarantees:
	 *   • a protected search engine yields '' (never proposes blocking Googlebot);
	 *   • a generic browser yields '' (its only tokens are mozilla/webkit/chrome/… —
	 *     blocking those would 403 every real visitor and most bots);
	 *   • a spoofed/legacy-device UA yields '' (handled by the block_spoofed class,
	 *     not a token — its tokens are generic too).
	 * What it DOES return is the crawler/tool product token: the `name` in the
	 * standard `name/version` signature (SemrushBot, AhrefsBot, python-requests, …),
	 * skipping generic ones, so blocking is specific to the abusing client.
	 *
	 * @param string $ua Raw User-Agent.
	 * @return string Lowercased token, or '' when none is safe.
	 */
	public static function suggest_token( $ua ) {
		$ua_lc = substr( strtolower( trim( (string) $ua ) ), 0, 1000 );
		if ( '' === $ua_lc || self::is_protected( $ua_lc ) ) {
			return '';
		}
		// A generic HTTP client / scripting tool (curl, wget, python-requests…) has a
		// broad name, and fetching the AI files is exactly what this plugin invites —
		// so it is never a safe one-click block. (A heavy one still surfaces for review;
		// block it explicitly in Settings if you must.)
		if ( 'Script/tool' === Classifier::classify( $ua_lc ) ) {
			return '';
		}
		// Every `name/version` pair in the UA, in order. The first one that isn't a
		// generic engine/browser token is the client's real product name. Tokens under
		// 3 chars are never proposed — the denylist sanitiser refuses them (as a
		// substring they'd over-match), so proposing one would be a silent no-op Block.
		if ( preg_match_all( '#([a-z][a-z0-9._+-]{1,40})/[0-9]#', $ua_lc, $matches ) ) {
			foreach ( $matches[1] as $candidate ) {
				if ( strlen( $candidate ) >= 3 && ! self::is_generic_token( $candidate ) ) {
					return $candidate;
				}
			}
		}
		// Fallback: a "compatible; Name" comment with no version (some crawlers).
		if ( preg_match( '#compatible;\s*([a-z][a-z0-9._+-]{1,40})#', $ua_lc, $m )
			&& strlen( $m[1] ) >= 3 && ! self::is_generic_token( $m[1] ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Whether a product token is a generic browser/engine name that must never be a
	 * block rule on its own (it would match nearly every visitor). Filterable.
	 *
	 * @param string $token Lowercased candidate token.
	 * @return bool
	 */
	private static function is_generic_token( $token ) {
		$generic = array(
			'mozilla', 'applewebkit', 'gecko', 'khtml', 'webkit', 'like',
			'safari', 'chrome', 'chromium', 'crios', 'firefox', 'fxios',
			'version', 'edg', 'edge', 'opr', 'opera', 'trident', 'msie',
			'mobile', 'windows', 'macintosh', 'linux', 'android', 'ios', 'x11',
		);
		/**
		 * Filter the generic-token stoplist used by the one-click block suggestion.
		 *
		 * @param string[] $generic Lowercase tokens that are never a safe block rule.
		 */
		$generic = (array) apply_filters( 'agentimus_generic_ua_tokens', $generic );
		return in_array( $token, $generic, true );
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
