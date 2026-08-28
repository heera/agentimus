<?php
/**
 * Recorder — logs one agent hit on a discovery/llms endpoint.
 *
 * First-party, local-only (never transmitted anywhere). Stores the endpoint,
 * the classified agent, and a truncated User-Agent — and DELIBERATELY no IP
 * address, so there's no PII/GDPR footprint by default.
 *
 * Called from the discovery/agent serve paths, and — when the owner leaves it on
 * — from {@see PageViews} for a machine reading ordinary HTML. Those two streams
 * differ in volume by orders of magnitude, which is why they no longer share a
 * write budget ({@see flood_bucket()}).
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

use Agentimus\Settings;
use Agentimus\Guard;
use Agentimus\BotVerifier;

defined( 'ABSPATH' ) || exit;

final class Recorder {

	/** Sample rate for the opportunistic row-count cap: roughly one insert in this
	 *  many runs the bounded trim (see Repository::trim_to_cap), keeping the table
	 *  near its ceiling mid-day without a per-request COUNT. */
	const CAP_CHECK_ODDS = 200;

	/** Flood window (seconds): throttle-eligible hits are counted per window. */
	const FLOOD_WINDOW = 60;

	/** Unrecognised hits allowed in a window before flood sampling engages. */
	const FLOOD_THRESHOLD = 60;

	/** Recognised-crawler hits allowed per window before sampling engages — generous,
	 *  so a real bot's normal burst on the (low-frequency) discovery endpoints is kept
	 *  in full, while a flood spoofing a known bot's NAME is still capped. Recognition
	 *  is a forgeable UA string, so recognised traffic gets a budget, not a blank pass.
	 *  This budget is SHARED across all recognised traffic (see record()), not per-name —
	 *  a single window-wide ceiling regardless of how many known bot names appear. */
	const RECOGNISED_THRESHOLD = 300;

	/** Once a window is flooding, keep roughly one unrecognised hit in this many. */
	const FLOOD_SAMPLE = 20;

	/** Transient key prefix for the per-window unrecognised-hit counter. */
	const RATE_PREFIX = 'agentimus_rec_rate_';

	/** @var bool|null Per-request cache of the enable flag. */
	private static $enabled = null;

	/** @var bool|null Per-request cache of the opt-in flagged-IP-storage flag. */
	private static $store_ips = null;

	/** @var bool|null Per-request cache of the opt-in identify-every-bot flag. */
	private static $identify = null;

	/**
	 * Record a hit on the named endpoint, if logging is enabled.
	 *
	 * @param string $endpoint Short endpoint label (e.g. "discovery.json").
	 */
	/**
	 * Record a request that was TURNED AWAY at the door, never served.
	 *
	 * The log's promise is "what agents fetched", so a refusal must never be
	 * mistaken for a read: these rows carry `refused = 1` and are excluded from
	 * every read total (hits per day, by endpoint, top clients). What they DO
	 * feed is the record the owner actually wants — the review queue and the
	 * weekly digest — because "someone impersonated OpenAI on your site and was
	 * refused" is exactly the event enforcement used to swallow in silence.
	 *
	 * Deliberately narrow: only PROVEN-IDENTITY refusals (a forged signature, or
	 * a claimed crawler whose operator's own check says it isn't). An ordinary
	 * denylist block is a rule the owner already knows about, and logging a
	 * blocked scanner's every retry would bury the signal in noise.
	 *
	 * @param string $endpoint Endpoint label the client tried to fetch.
	 * @return void
	 */
	public static function record_refusal( $endpoint ) {
		self::record( $endpoint, true );
	}

	/**
	 * @param string $endpoint Endpoint label.
	 * @param bool   $refused  True when the request was refused, not served.
	 */
	public static function record( $endpoint, $refused = false ) {
		if ( ! self::enabled() ) {
			return;
		}

		// Skip the site owner inspecting their own endpoints. {@see Owner::skip()} — which
		// also carries the `agentimus_activity_skip_self` filter — because a REST-served
		// endpoint sees no current user unless the cookie came with a nonce.
		if ( Owner::skip() ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only logging of a public endpoint hit.

		$agent = Classifier::classify( $ua );

		// Write budget. Recognition is only a forgeable UA-string match, so a recognised crawler
		// (GPTBot, Googlebot, ClaudeBot…) is NOT given a blank pass — it gets a GENEROUS budget (a
		// real bot's burst on these low-frequency endpoints is the signal we want, kept in full),
		// while every other client gets a tight one. Over budget, a hit is sampled to
		// ~1-in-FLOOD_SAMPLE, so no single source drives unbounded INSERTs; the row cap
		// (trim_to_cap) is the hard backstop.
		//
		// Two buckets PER STREAM — 'a' for ALL recognised traffic, 'u' for all unrecognised
		// (see flood_bucket(), which keeps the page-view stream's pair separate) — deliberately
		// NOT one per bot name. A per-name bucket handed each of the ~22 forgeable known-bot strings
		// its own 300-budget, so an attacker rotating names multiplied the budget to thousands of
		// guaranteed INSERTs/min. Sharing one recognised budget removes that multiplier entirely:
		// rotating names now buys nothing. A single real crawler is unaffected (it was one name =
		// one bucket before, and it is the whole 'a' bucket now); only many DISTINCT names in one
		// window — an attack, or the rare simultaneous multi-crawler burst — share the budget, and
		// over it they are sampled (not dropped), preserving proportions.
		$recognised = Classifier::is_recognised_agent( $ua );
		$threshold  = $recognised ? self::RECOGNISED_THRESHOLD : self::FLOOD_THRESHOLD;
		$count      = self::note_hit( self::flood_bucket( $endpoint, $recognised ), $threshold );
		if ( ! self::survives_flood( $count, $threshold, wp_rand( 1, self::FLOOD_SAMPLE ) )
			&& ! self::beats_flood_by_signature() ) {
			return;
		}

		// Attribution + verdict. When "identify every bot" is on, ONE reverse-DNS resolves the
		// owning NETWORK for every bot AND (for a verifiable engine) its verdict from the same
		// lookup — so we never look up twice, and identify implies engine verification for free.
		// Otherwise fall back to the engine-only verdict path (gated on verify_bots). Either way
		// the source IP is used for the lookup and DISCARDED — only the network/verdict persist.
		$network = '';
		if ( self::identify_on() ) {
			$ip = Guard::client_ip();
			// Network is IP-only attribution (the owning org). The VERDICT stays claim-based
			// ({@see engine_verdict}) — NOT attribute_ip's PTR-derived verdict — so a UA claiming
			// an engine from an IP that isn't that engine is still caught as an impostor. Runs even
			// when verify_bots is off: turning identify on implies confirming a claimed engine too.
			$network = (string) BotVerifier::attribute_ip( $ip )['network'];
			$verdict = self::client_verdict( $ua, $ip );
		} else {
			$verdict = self::verdict( $ua );
		}
		// A Web Bot Auth signature outranks both claim-based paths: verified math from
		// a recognised operator is a stronger "1" than any DNS inference, and failed
		// math is a stronger "2". Unsigned/unknown/indeterminate leaves the claim-based
		// verdict exactly as it was — most crawlers don't sign yet, and never lose
		// standing for it.
		// ⭐ THE SIGNER IS RECORDED SEPARATELY FROM THE VERDICT, and on purpose: a
		// signature that changed nothing about this client's standing still HAPPENED,
		// and a row is the only place that fact can survive. See signature_face().
		$sig_verdict = self::signature_verdict();
		$signer      = self::signature_face();
		if ( 0 !== $sig_verdict ) {
			$verdict = $sig_verdict;
		}
		$is_spoof = Classifier::is_spoof( $ua );

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB -- single insert into our own table.
			Table::name(),
			array(
				'endpoint' => substr( (string) $endpoint, 0, 64 ),
				'agent'    => substr( $agent, 0, 64 ),
				'ua'       => substr( $ua, 0, 255 ),
				'verdict'  => $verdict,
				'network'  => substr( $network, 0, 128 ),
				'signer'   => substr( $signer, 0, 64 ),
				'refused'  => $refused ? 1 : 0,
				'hit_at'   => current_time( 'mysql', true ), // GMT.
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( 2 === (int) $verdict ) {
			/**
			 * Fires when a recorded hit carries a PROVEN-impostor verdict — a client
			 * claiming a verified bot's name whose address conclusively failed the
			 * operator's own check. Carries the client NAME only, never an IP; any
			 * listener owes its own debouncing (this fires per hit, and a forged
			 * crawler's crawl is many hits).
			 *
			 * @param string $client The client label Classifier::classify() gave this UA.
			 * @param string $ua     The raw user-agent string.
			 */
			do_action( 'agentimus_impostor_flagged', $agent, $ua );
		}

		// OPT-IN, minimised IP capture. Only when the owner turned it on, and only for a
		// client that is suspicious at record time — a proven impersonator (verdict 2) or a
		// legacy-device spoof — so the review card can show real addresses to block. The IP
		// is resolved only here, stored ONLY for these flagged clients, and kept nowhere
		// else (a normal install captures nothing). See {@see FlaggedIps}.
		if ( self::should_capture_ip( $verdict, $is_spoof, self::store_ips_enabled() ) ) {
			FlaggedIps::record( Repository::dismiss_key( $ua ), Guard::client_ip() );
		}

		// Opportunistic backstop: most inserts pay only a cheap rand(); roughly one
		// in CAP_CHECK_ODDS runs a single bounded DELETE, so the table can't bank
		// unbounded rows on an extreme-traffic day before the daily prune cron.
		if ( 1 === wp_rand( 1, self::CAP_CHECK_ODDS ) ) {
			Repository::trim_to_cap();
		}
	}

	/**
	 * Count one hit against a per-source flood bucket for the current window and return
	 * the running total. A coarse per-minute transient counter, keyed per source so one
	 * client's volume never samples another's — approximate under concurrency (a lost
	 * increment only ever UNDER-counts, i.e. errs toward logging), which is fine here:
	 * the row cap (trim_to_cap) is the hard backstop. Honours an external object cache
	 * automatically (it is just the Transients API).
	 *
	 * @param string $bucket    Source key — 'a' (all recognised) or 'u' (all unrecognised).
	 * @param int    $threshold This bucket's budget, used to stop counting once sampling has begun.
	 * @return int Hits so far this window for this bucket.
	 */
	private static function note_hit( $bucket, $threshold ) {
		$key   = self::RATE_PREFIX . md5( (string) $bucket ) . '_' . (int) floor( time() / self::FLOOD_WINDOW );
		$count = (int) get_transient( $key ) + 1;
		// FREEZE the stored counter one past the threshold. Beyond it every hit is already sampled,
		// so the exact number changes no decision — and on a site with NO persistent object cache
		// each set_transient is a wp_options write, so a sustained flood otherwise wrote an
		// ever-larger integer on every single request. Freezing caps that write pressure at
		// ~threshold writes per bucket per window (two buckets total). The read stays — it is cheap —
		// only the per-hit write is bounded. Outlives the window so a flood straddling the boundary
		// still reads as elevated; the next window starts from zero.
		if ( $count <= (int) $threshold + 1 ) {
			set_transient( $key, $count, self::FLOOD_WINDOW * 2 );
		}
		return $count;
	}

	/**
	 * Which flood budget this hit spends.
	 *
	 * ⛔⛔ PAGE VIEWS GET THEIR OWN, and this is not a nicety. A crawl of the
	 * CONTENT is orders of magnitude more requests than a crawl of the generated
	 * files — one llms.txt fetch, then five hundred articles. Sharing a bucket
	 * would let the page stream spend the whole per-minute budget and start
	 * sampling away the llms.txt, .md and sitemap hits this log was built to
	 * show. Two streams, two ceilings, so adding the second cannot cost the
	 * first anything.
	 *
	 * ⚠️ The existing keys ('a' / 'u') are deliberately UNCHANGED, so an upgrade
	 * does not hand every site a fresh budget mid-window.
	 *
	 * @param string $endpoint   The endpoint label being recorded.
	 * @param bool   $recognised Whether the UA names a specific known crawler.
	 * @return string Bucket key.
	 */
	private static function flood_bucket( $endpoint, $recognised ) {
		$stream = ( PageViews::LABEL === $endpoint ) ? 'p' : '';
		return $stream . ( $recognised ? 'a' : 'u' );
	}

	/**
	 * Whether this request's signature earns it a place in the log even though its
	 * bucket is over budget.
	 *
	 * ⭐⭐ A CRYPTOGRAPHICALLY VERIFIED REQUEST IS NEVER SAMPLED AWAY. Signed
	 * traffic is vanishingly rare — nothing has ever signed to heera.it — and it
	 * is the entire reason the `signer` column exists. Losing the one signed
	 * request in a million to a 1-in-20 flood sample would put us straight back
	 * where we were: the maths passed, and the log said nothing happened.
	 *
	 * ⛔ VERIFIED ONLY, never merely "carried a signature". A FAILED signature is
	 * cheap to manufacture at volume — any garbage in the header fails — so
	 * exempting those would hand a flooder an unlimited bypass around the write
	 * budget. A verified one needs the operator's private key, which is not
	 * something a flood can produce.
	 *
	 * @return bool
	 */
	private static function beats_flood_by_signature() {
		if ( ! Guard::verification_on() ) {
			return false;
		}
		return 'verified' === \Agentimus\BotSignature::current()['state'];
	}

	/**
	 * The live forward-confirmed reverse-DNS verdict for a UA that CLAIMS a verifiable
	 * search engine, as a storable tinyint — the "verify the identity, keep no IP"
	 * design. Runs only when the owner has opted into verification and only for a UA
	 * that names a checkable engine (Googlebot, Bingbot…); every other hit returns 0 for
	 * free, doing no DNS. The source IP is resolved, used for the lookup, and DISCARDED —
	 * only the verdict is persisted, so the log keeps its no-PII footprint.
	 *
	 * {@see BotVerifier} owns the lookup and its safety rails (per-IP cache, a bounded
	 * per-window lookup budget, and a circuit breaker) so this stays cheap on the
	 * unauthenticated serve path and FAILS OPEN — a slow/unreachable resolver yields 0
	 * (unchecked), never a false "spoofed".
	 *
	 * @param string $ua Raw User-Agent.
	 * @return int 0 = unchecked/inconclusive, 1 = verified, 2 = spoofed.
	 */
	private static function verdict( $ua ) {
		if ( ! Guard::verification_on() ) {
			return 0;
		}
		return self::client_verdict( $ua, Guard::client_ip() );
	}

	/**
	 * The current request's Web Bot Auth verdict in activity-table vocabulary:
	 * 1 = the signature verifies AND the signer is a recognised operator;
	 * 2 = the signature conclusively fails (a forged claim);
	 * 0 = everything else — unsigned, unknown signer, or indeterminate — which
	 * must never override the claim-based verdict either way.
	 *
	 * @return int
	 */
	private static function signature_verdict() {
		if ( ! Guard::verification_on() ) {
			return 0;
		}
		if ( \Agentimus\BotSignature::conclusively_failed() ) {
			return 2;
		}
		return '' !== \Agentimus\BotSignature::verified_known_label() ? 1 : 0;
	}

	/**
	 * WHO the current request's signature speaks for, for the log's `signer`
	 * column — asked and answered independently of what that signature was WORTH.
	 *
	 * ⭐⭐ RECORDED EVEN WHEN THE VERDICT STAYS 0. A valid signature from an origin
	 * this site does not recognise grants no standing — that is deliberate, and
	 * {@see BotSignature::verified_known_label()} keeps it that way — but until
	 * this existed it also left no trace whatsoever, because the signer was only
	 * written when the verdict moved. So the first operator to sign a site that
	 * is neither Google nor OpenAI was INVISIBLE: the crypto passed, the row said
	 * "unchecked", and the owner asking "has anything signed to me yet?" was told
	 * no by a log that had in fact seen it. The three readable pairs now are:
	 *   verdict 1 + signer — signed, and by an operator we recognise;
	 *   verdict 0 + signer — signed, by an operator we cannot vouch for;
	 *   verdict 2 + signer — signed badly: the maths failed, and it claimed them.
	 * A row can never carry a signer for any other reason — face() speaks only
	 * for the verified and the failed — so the pairs stay unambiguous.
	 *
	 * Costs nothing extra: the verdict path above has already memoised the one
	 * inspection this request will ever do ({@see BotSignature::current()}).
	 *
	 * @return string
	 */
	private static function signature_face() {
		if ( ! Guard::verification_on() ) {
			return '';
		}
		return \Agentimus\BotSignature::face();
	}

	/**
	 * The full claim-based verdict: reverse-DNS first (the per-request check), then the
	 * published-IP-range fallback for a claim the registry can range-verify — either a
	 * range-only operator (GPTBot…) or an rDNS engine whose lookup was inconclusive
	 * (resolver down, budget spent). The range side reads a cron-fetched cache and
	 * NEVER fetches on this path ({@see BotRanges::verdict}), so it adds no latency
	 * and no new failure mode; with the publisher unreachable it just answers 0.
	 *
	 * @param string $ua Raw User-Agent.
	 * @param string $ip Source IP.
	 * @return int 0 = unchecked/inconclusive, 1 = verified, 2 = spoofed.
	 */
	public static function client_verdict( $ua, $ip ) {
		// The cascade itself lives with the verifier now ({@see BotVerifier::claim_verdict})
		// — one definition shared with the Guard and the admin re-check.
		return BotVerifier::claim_verdict( $ua, $ip, false );
	}

	/**
	 * The claim-based verdict: forward-confirm the engine a UA CLAIMS against the source IP. Keyed
	 * on the UA's claim (not the IP's PTR-derived engine), so a UA claiming Applebot from a non-Apple
	 * address is still caught as an impostor (2). Ungated — the caller decides when to run it (the
	 * verify_bots path, and the identify_bots path, which implies engine confirmation too). Returns
	 * 0 for a UA that names no verifiable engine, doing no DNS.
	 *
	 * @param string $ua Raw User-Agent.
	 * @param string $ip Source IP.
	 * @return int 0 = unchecked/inconclusive, 1 = verified, 2 = spoofed.
	 */
	public static function engine_verdict( $ua, $ip ) {
		$ua_lc = strtolower( (string) $ua );
		if ( '' === BotVerifier::claimed_engine( $ua_lc ) ) {
			return 0; // Not a claim we can verify — no lookup, no cost.
		}
		$verified = BotVerifier::verify_engine( $ua_lc, (string) $ip );
		if ( true === $verified ) {
			return 1;
		}
		if ( false === $verified ) {
			return 2;
		}
		return 0; // null = could not determine → fail open.
	}

	/**
	 * Whether a hit survives flood sampling, given its source's window count and the
	 * threshold that applies to its class. Pure, with the random roll injected, so the
	 * policy is unit-testable without transients or RNG. At or below the threshold every
	 * hit is kept; above it a hit is kept only when the roll lands (≈ 1 in FLOOD_SAMPLE).
	 * A recognised crawler gets a generous threshold and an unrecognised client a tight
	 * one — but NEITHER is unlimited, so no single source can drive unbounded inserts.
	 *
	 * @param int $count     Hits from this source this window.
	 * @param int $threshold Hits kept in full before sampling engages.
	 * @param int $roll      A 1..FLOOD_SAMPLE roll.
	 * @return bool True to log the hit.
	 */
	public static function survives_flood( $count, $threshold, $roll ) {
		if ( (int) $count <= (int) $threshold ) {
			return true;
		}
		return 1 === (int) $roll;
	}

	/**
	 * Whether activity logging is on (cached for the request).
	 *
	 * @return bool
	 */
	private static function enabled() {
		if ( null === self::$enabled ) {
			self::$enabled = (bool) ( new Settings() )->enabled( 'enable_activity' );
		}
		return self::$enabled;
	}

	/**
	 * Whether this hit's IP should be captured — the pure decision behind the opt-in
	 * flagged-IP store. TRUE only when the owner enabled it AND the client is suspicious at
	 * record time: a proven impersonator (verdict 2) or a legacy-device spoof. Never for
	 * ordinary traffic. Pure, so the minimisation rule is unit-tested in isolation.
	 *
	 * @param int  $verdict  Live reverse-DNS verdict (2 = spoofed/impersonator).
	 * @param bool $is_spoof Legacy-device spoof heuristic.
	 * @param bool $enabled  Whether store_flagged_ips is on.
	 * @return bool
	 */
	public static function should_capture_ip( $verdict, $is_spoof, $enabled ) {
		return (bool) $enabled && ( 2 === (int) $verdict || (bool) $is_spoof );
	}

	/**
	 * Whether opt-in IP capture for flagged clients is on (cached for the request).
	 *
	 * @return bool
	 */
	private static function store_ips_enabled() {
		if ( null === self::$store_ips ) {
			self::$store_ips = (bool) ( new Settings() )->enabled( 'store_flagged_ips' );
		}
		return self::$store_ips;
	}

	/**
	 * Whether opt-in "identify every bot" attribution is on (cached for the request).
	 *
	 * @return bool
	 */
	private static function identify_on() {
		if ( null === self::$identify ) {
			self::$identify = (bool) ( new Settings() )->enabled( 'identify_bots' );
		}
		return self::$identify;
	}
}
