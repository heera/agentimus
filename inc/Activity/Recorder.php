<?php
/**
 * Recorder — logs one agent hit on a discovery/llms endpoint.
 *
 * First-party, local-only (never transmitted anywhere). Stores the endpoint,
 * the classified agent, and a truncated User-Agent — and DELIBERATELY no IP
 * address, so there's no PII/GDPR footprint by default. Called only from the
 * discovery/agent serve paths (low-frequency), so a single INSERT per hit is
 * negligible.
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
	public static function record( $endpoint ) {
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
		// TWO buckets only — 'a' for ALL recognised traffic, 'u' for all unrecognised — deliberately
		// NOT one per bot name. A per-name bucket handed each of the ~22 forgeable known-bot strings
		// its own 300-budget, so an attacker rotating names multiplied the budget to thousands of
		// guaranteed INSERTs/min. Sharing one recognised budget removes that multiplier entirely:
		// rotating names now buys nothing. A single real crawler is unaffected (it was one name =
		// one bucket before, and it is the whole 'a' bucket now); only many DISTINCT names in one
		// window — an attack, or the rare simultaneous multi-crawler burst — share the budget, and
		// over it they are sampled (not dropped), preserving proportions.
		$recognised = Classifier::is_recognised_agent( $ua );
		$threshold  = $recognised ? self::RECOGNISED_THRESHOLD : self::FLOOD_THRESHOLD;
		$count      = self::note_hit( $recognised ? 'a' : 'u', $threshold );
		if ( ! self::survives_flood( $count, $threshold, wp_rand( 1, self::FLOOD_SAMPLE ) ) ) {
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
			$verdict = self::engine_verdict( $ua, $ip );
		} else {
			$verdict = self::verdict( $ua );
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
				'hit_at'   => current_time( 'mysql', true ), // GMT.
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

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
		return self::engine_verdict( $ua, Guard::client_ip() );
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
