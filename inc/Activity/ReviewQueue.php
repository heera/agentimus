<?php
/**
 * ReviewQueue — the "Suspicious activity" review queue. It reads the hit log to
 * flag and rank suspicious sources, layers the owner's "Ignore" dismissals and
 * "Re-check" verdicts (each kept in its own bounded option, never rewriting the
 * log), and prunes those overlays. Split out of {@see Repository}, which keeps
 * thin delegating statics under the historical names; the two windowing reads
 * it still needs (report_days / retention_days) stay there and are called back.
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

use Agentimus\Settings;
use Agentimus\Guard;
use Agentimus\ReviewBadge;
use Agentimus\VerifierRegistry;

defined( 'ABSPATH' ) || exit;

final class ReviewQueue {

	/** A source first seen within this many hours is flagged "new". */
	const NEW_AGENT_HOURS = 48;

	/** Hits in the last hour at/above this count flag a "heavy" (burst) source. */
	const BURST_MIN_HITS = 30;

	/** Hits over the whole window at/above this count also flag "heavy" (sustained). */
	const HEAVY_MIN_HITS = 500;

	/** Max suspicious sources returned to the panel. */
	const THREATS_LIMIT = 12;

	/** Where "Ignore" dismissals live: a map of review-key => { at, hits }. Its own
	 *  option (autoload off) so it stays out of the user-facing settings form, and is
	 *  bounded to the most recent DISMISS_MAX entries. */
	const DISMISS_OPTION = 'agentimus_review_dismissed';
	const DISMISS_MAX    = 200;

	/** Where admin "Re-check" results live: a map of review-key => { verdict, at }, layered
	 *  over the ingest verdict at analysis time (the hit log is never rewritten). Its own
	 *  option (autoload off), bounded to REVERIFY_MAX, and short-lived — a re-check is a
	 *  point-in-time fact, so a stale one must stop overriding a client's live verdict. */
	const REVERIFY_OPTION = 'agentimus_review_reverified';
	const REVERIFY_MAX    = 200;
	const REVERIFY_TTL    = 7 * DAY_IN_SECONDS;

	/**
	 * Suspicious-source signals for the dashboard's "Suspicious activity" section.
	 * Thin DB layer: pulls per-UA aggregates over the window plus a last-hour burst
	 * count, then hands them to the pure {@see analyze_threats()} for flagging and
	 * ranking. UA-only by design — no IP is stored — so this is heuristic
	 * VISIBILITY (novelty, request rate, spoofed-UA), never a substitute for a WAF.
	 *
	 * @param Settings $settings Settings store.
	 * @return array{sources:array,counts:array,blockingOn:bool}
	 */
	public static function threats( Settings $settings ) {
		global $wpdb;
		$table = Table::name();
		$now   = time();
		// The REPORTED span, not the retained one. HEAVY_MIN_HITS is documented as a total
		// "over the whole window", and the window a viewer sees is report_days() — so the
		// queue counts exactly the hits the dashboard is showing. Reading WINDOW_DAYS raw was
		// wrong for a shorter retention (queue looked back 30 days into data kept for 7);
		// reading retention_days() would be wrong for a longer one (queue counts 90 days
		// while every card on the dashboard shows 30). Calendar-day boundary for the same
		// reason as stats(): "what the dashboard is showing" is now a calendar window.
		$since = gmdate( 'Y-m-d 00:00:00', $now - ( Repository::report_days() - 1 ) * DAY_IN_SECONDS );
		$hour  = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );

		// One row per distinct UA over the window. MAX(agent) is unambiguous: the
		// agent label is a pure function of the UA, so every row in a UA-group shares
		// it. Bounded to the 200 busiest sources — far more than a content site sees,
		// and the pure pass only keeps the flagged ones anyway.
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is our own prefix-derived name; values are bound via prepare().
		// MAX(verdict) folds the per-hit reverse-DNS results to the WORST seen for this UA
		// (2 spoofed > 1 verified > 0 unchecked): if any hit under this UA conclusively
		// failed verification, the client is an impersonator and must surface as one.
		// The CASE aggregates carry the split behind that fold: one UA string can serve
		// TWO populations — the real engine (verdict 1) and an impersonator borrowing its
		// exact name (verdict 2) — and a row branded "spoofed" whose hits/last_seen span
		// both would let the impostor's verdict wear the real engine's volume and recency.
		// Caught live 2026-08-29: "Failed verification · 95 requests · 5h ago" where the
		// 5h-ago request was the REAL Googlebot's verified sitemap fetch and the last
		// actual forgery was six days old.
		$sources = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ua, MAX(agent) AS agent, COUNT(*) AS hits, MAX(verdict) AS verdict, SUM(CASE WHEN verdict = 2 THEN 1 ELSE 0 END) AS spoof_hits, MAX(CASE WHEN verdict = 2 THEN hit_at END) AS spoof_last_seen, SUM(CASE WHEN verdict = 1 THEN 1 ELSE 0 END) AS verified_hits, MAX(network) AS network, MAX(signer) AS signer, MAX(refused) AS refused, MIN(hit_at) AS first_seen, MAX(hit_at) AS last_seen FROM $table WHERE hit_at >= %s GROUP BY ua ORDER BY hits DESC LIMIT 200",
				$since
			),
			ARRAY_A
		);
		$recent_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT ua, COUNT(*) AS c FROM $table WHERE hit_at >= %s GROUP BY ua", $hour ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$recent = array();
		foreach ( (array) $recent_rows as $r ) {
			$recent[ (string) $r['ua'] ] = (int) $r['c'];
		}

		$result = self::analyze_threats(
			(array) $sources,
			$recent,
			$now,
			array(
				'blockingOn'   => (bool) $settings->enabled( 'block_agents' ),
				'blockSpoofed' => (bool) $settings->enabled( 'block_spoofed' ),
				// Whether "Verify bot identities" is on (filter included, like the request
				// log's verifyOn) — the panel needs it to say WHY a row is unchecked:
				// "checking is off" and "checked, no clear answer" are different advice.
				'verifyOn'     => Guard::verification_on(),
				'newSecs'      => (int) apply_filters( 'agentimus_new_agent_seconds', self::NEW_AGENT_HOURS * HOUR_IN_SECONDS ),
				'burstMin'   => (int) apply_filters( 'agentimus_burst_min_hits', self::BURST_MIN_HITS ),
				'heavyMin'   => (int) apply_filters( 'agentimus_heavy_min_hits', self::HEAVY_MIN_HITS ),
				'limit'      => (int) apply_filters( 'agentimus_threats_limit', self::THREATS_LIMIT ),
				'dismissed'  => self::dismissed_map(),
				'reverified' => self::reverified_map(),
			)
		);

		// Opt-in: decorate each row with the actual source IPs captured for that client
		// (keyed by the same dismiss_key used at capture time). Only when IP storage is on
		// — otherwise the store is empty and there's nothing to fetch.
		if ( $settings->enabled( 'store_flagged_ips' ) && ! empty( $result['sources'] ) ) {
			$keys = array();
			foreach ( $result['sources'] as $row ) {
				$keys[] = self::dismiss_key( $row['ua'] );
			}
			$ip_map = FlaggedIps::for_keys( $keys );
			foreach ( $result['sources'] as &$row ) {
				$key         = self::dismiss_key( $row['ua'] );
				$row['ips']  = isset( $ip_map[ $key ] ) ? $ip_map[ $key ] : array();
			}
			unset( $row );
		}

		// The home pages these clients declare in their own User-Agents: note them
		// for the weekly background look, and hand each row whatever the last look
		// found. observe() records and queues; it never fetches — this is a read
		// path, and it runs behind the admin bell on every page load.
		// A caught impostor is skipped on both counts. The URL in a forged
		// "bingbot/2.0" User-Agent is Bing's own page, copied along with the rest of
		// the string: it will answer, and that answer says nothing whatever about
		// the client sending it. Checking it wastes a request; showing it puts a
		// green tick on a card that has already proven a forgery.
		$declared = array();
		foreach ( $result['sources'] as $row ) {
			if ( 'spoofed' === $row['verdict'] ) {
				continue;
			}
			if ( ! empty( $row['guide']['url'] ) ) {
				$declared[] = (string) $row['guide']['url'];
			}
		}
		if ( ! empty( $declared ) ) {
			IdentityProbe::observe( $declared );
			foreach ( $result['sources'] as &$row ) {
				if ( 'spoofed' !== $row['verdict'] && ! empty( $row['guide']['url'] ) ) {
					// null until the first probe lands — the panel renders that as
					// "not checked", never as a result.
					$row['guide']['reachable'] = IdentityProbe::state( $row['guide']['url'] );
				}
			}
			unset( $row );
		}

		return $result;
	}

	/**
	 * Pure flagging + ranking of suspicious sources — no DB, no time() — so it's
	 * unit-testable in isolation. Each input source is `{ua, agent, hits, first_seen,
	 * last_seen}` (GMT strings). Flags: NEW (first seen within newSecs), HEAVY (burst
	 * in the last hour OR sustained over the window), SPOOF (legacy-device heuristic).
	 * Only flagged sources are returned, ranked spoof > heavy > new then by volume.
	 * Each carries the live "already blocked" state ({@see Guard::denies()}) and, when
	 * actionable, a safe block token / spoof-class action for the one-click button.
	 *
	 * @param array $sources Per-UA aggregates.
	 * @param array $recent  Map of UA => hits in the last hour.
	 * @param int   $now     Current GMT unix time.
	 * @param array $opts    blockingOn, newSecs, burstMin, heavyMin, limit.
	 * @return array{sources:array,counts:array,blockingOn:bool}
	 */
	public static function analyze_threats( array $sources, array $recent, $now, array $opts ) {
		$new_secs  = isset( $opts['newSecs'] ) ? (int) $opts['newSecs'] : self::NEW_AGENT_HOURS * HOUR_IN_SECONDS;
		$burst_min = isset( $opts['burstMin'] ) ? (int) $opts['burstMin'] : self::BURST_MIN_HITS;
		$heavy_min = isset( $opts['heavyMin'] ) ? (int) $opts['heavyMin'] : self::HEAVY_MIN_HITS;
		$limit     = isset( $opts['limit'] ) ? (int) $opts['limit'] : self::THREATS_LIMIT;

		$out        = array();
		$counts     = array( 'new' => 0, 'heavy' => 0, 'spoof' => 0 );
		$reverified = isset( $opts['reverified'] ) ? (array) $opts['reverified'] : array();

		foreach ( $sources as $s ) {
			$ua      = isset( $s['ua'] ) ? (string) $s['ua'] : '';
			$verdict = isset( $s['verdict'] ) ? (int) $s['verdict'] : 0;

			// Layer an admin "Re-check" result over the ingest verdict, keyed by the same
			// review identity — so a re-confirmed impostor stays flagged (and a re-cleared one
			// drops out) everywhere the verdict is used below (the protected-engine skip, the
			// fake-engine flag, severity, the panel badge), all WITHOUT rewriting the hit log.
			$reverified_at = '';
			$rv_key        = self::dismiss_key( $ua );
			// Only a CONCLUSIVE re-check (verified/spoofed) overrides. An inconclusive one
			// (0 — resolver down, undetermined) carries no new information, so it must never
			// clear a verdict the way null never overrides a cached one at ingest.
			if ( isset( $reverified[ $rv_key ]['verdict'] ) && (int) $reverified[ $rv_key ]['verdict'] > 0 ) {
				$verdict = (int) $reverified[ $rv_key ]['verdict'];
				$rv_at   = isset( $reverified[ $rv_key ]['at'] ) ? (string) $reverified[ $rv_key ]['at'] : '';
				if ( '' !== $rv_at ) {
					$reverified_at = gmdate( 'c', strtotime( $rv_at . ' UTC' ) );
				}
			}

			// A protected/allow-listed search engine (Googlebot, Bingbot…) is trusted
			// by definition — never denied, never "suspicious". Keep it out entirely —
			// UNLESS live reverse-DNS caught it impersonating the engine it claims
			// (verdict 2): a forged "Googlebot" is exactly what the owner must see.
			if ( '' !== $ua && Guard::is_protected_ua( $ua ) && 2 !== $verdict ) {
				continue;
			}

			$hits  = isset( $s['hits'] ) ? (int) $s['hits'] : 0;
			$first = isset( $s['first_seen'] ) ? strtotime( $s['first_seen'] . ' UTC' ) : 0;
			$last  = isset( $s['last_seen'] ) ? strtotime( $s['last_seen'] . ' UTC' ) : 0;
			$rec   = isset( $recent[ $ua ] ) ? (int) $recent[ $ua ] : 0;

			// The population split behind a shared name (see the query note in threats()):
			// how many of this UA's requests FAILED the identity check, when the last
			// failure was, and how many forward-confirmed as the genuine engine. Zero /
			// empty when the caller predates the split or verification never ran.
			$spoof_hits    = isset( $s['spoof_hits'] ) ? (int) $s['spoof_hits'] : 0;
			$spoof_last    = empty( $s['spoof_last_seen'] ) ? 0 : strtotime( $s['spoof_last_seen'] . ' UTC' );
			$verified_hits = isset( $s['verified_hits'] ) ? (int) $s['verified_hits'] : 0;

			$is_new      = $first > 0 && ( $now - $first ) <= $new_secs;
			$is_heavy    = $rec >= $burst_min || $hits >= $heavy_min;
			$is_spoof    = Classifier::is_spoof( $ua );
			$fake_engine = 2 === $verdict; // Live reverse-DNS: claims an engine it isn't.

			if ( ! $is_new && ! $is_heavy && ! $is_spoof && ! $fake_engine ) {
				continue; // Nothing flags it.
			}

			$blocked  = Guard::denies( $ua );
			$token    = $blocked ? '' : Guard::suggest_token( $ua );
			// An active impersonator outranks everything — it's a client lying about who
			// it is, which no volume/novelty signal matches for seriousness.
			$severity = ( $fake_engine ? 5 : 0 ) + ( $is_spoof ? 4 : 0 ) + ( $is_heavy ? 2 : 0 ) + ( $is_new ? 1 : 0 );

			// What the one-click action does for this row: nothing when already
			// denied; for a spoofed UA, arm the whole scanner class (more useful than
			// blocking its single legacy-device string); else block the derived token
			// when one is safe; otherwise it isn't safely actionable (no UA, a real
			// browser, or a protected search engine) — say why instead.
			$action = '';
			$reason = '';
			if ( $blocked ) {
				$action = '';
			} elseif ( $is_spoof ) {
				$action = 'spoofed';
			} elseif ( '' !== $token ) {
				$action = 'agent';
			} elseif ( $fake_engine ) {
				// Impersonating a search engine, but its UA carries no safe block token
				// (blocking "Googlebot" would also block the real crawler). Not one-click
				// actionable — the honest remedy is an IP/firewall rule at the host/CDN.
				$reason = 'fake-engine';
			} else {
				$reason = '' === trim( $ua ) ? 'no-ua' : 'no-token';
			}

			// A "new"-only source we can neither block nor flag as spoof/heavy is just
			// noise here (a one-off new browser/script). Show only genuinely suspicious
			// (spoof/heavy/impersonating) or actionable / already-blocked rows. (Counted
			// post-merge.)
			if ( ! $is_spoof && ! $is_heavy && ! $fake_engine && ! $blocked && '' === $action ) {
				continue;
			}

			// The verifier-registry entry this UA claims (if any): its label and which
			// checks exist for it, so the panel words verification honestly per method.
			$claim_token = VerifierRegistry::claimed( strtolower( $ua ) );
			$claim_entry = '' !== $claim_token ? VerifierRegistry::entry( $claim_token ) : null;
			$claim       = $claim_entry ? array(
				'label'  => (string) $claim_entry['label'],
				'rdns'   => ! empty( $claim_entry['domains'] ),
				'ranges' => '' !== (string) $claim_entry['url'],
			) : null;

			$known   = Catalog::identify( $ua );
			$out[] = array(
				'ua'        => substr( $ua, 0, 255 ),
				'agent'     => isset( $s['agent'] ) ? (string) $s['agent'] : '',
				'known'     => $known,
				// For an unknown client, give the owner somewhere to look: its own
				// self-declared (+URL) page, else a web search. Null when recognised.
				'guide'     => $known ? null : Catalog::self_declared( $ua ),
				'hits'      => $hits,
				'recent'    => $rec,
				'firstSeen' => $first ? gmdate( 'c', $first ) : '',
				'lastSeen'  => $last ? gmdate( 'c', $last ) : '',
				'flags'     => array(
					'new'   => $is_new,
					'heavy' => $is_heavy,
					'spoof' => $is_spoof,
				),
				'severity'  => $severity,
				'blocked'   => $blocked,
				'action'    => $action,
				'token'     => $token,
				'reason'    => $reason,
				// Live reverse-DNS result, for the "Check this bot" panel: 'spoofed' =
				// caught impersonating the engine it claims, 'verified' = forward-confirmed,
				// '' = unchecked (verification off, or not an engine we can check).
				'verdict'   => 2 === $verdict ? 'spoofed' : ( 1 === $verdict ? 'verified' : '' ),
				// Whether this client CLAIMS an entry in the (owner-editable) verifier
				// registry — rDNS engine or published-range operator. Disambiguates an
				// empty verdict: false here means "not a bot Verify can check" (e.g.
				// Bytespider) — so the UI won't offer the dead-end "turn on Verify" nudge.
				'verifiable' => '' !== $claim_token,
				// The claimed registry entry, for method-aware panel copy: which bot it
				// claims and which checks exist for it. Null when it claims none.
				'claim'      => $claim,
				// When the owner last ran an admin "Re-check" for this client (ISO-8601), so
				// the panel can show "re-checked 2m ago". '' when never re-checked.
				'reverifiedAt' => $reverified_at,
				// The owning network (reverse-DNS attribution) when "identify every bot" is on;
				// '' otherwise. Org-level (e.g. amazonaws.com), never the IP.
				'network'    => isset( $s['network'] ) ? (string) $s['network'] : '',
				// Web Bot Auth face for the card: verdict 'verified' + signer = cryptographically
				// proven ("OpenAI agent"); verdict 'spoofed' + signer = a signature that failed
				// the math, naming the claimed origin ("chatgpt.com"). '' = DNS/range verdict.
				'signer'     => isset( $s['signer'] ) ? (string) $s['signer'] : '',
				// This client was turned away at the door — the card says so, so the
				// owner never wonders whether it got through.
				'refused'    => ! empty( $s['refused'] ),
				// The split behind a spoofed verdict: a verdict must count and date the
				// requests that EARNED it. `hits`/`lastSeen` above span every request
				// under this name — on a row where the real engine shares the UA with
				// its impersonator, they are mostly the real engine's, so a "failed
				// verification" surface must quote these instead.
				'spoofHits'     => $spoof_hits,
				'spoofLastSeen' => $spoof_last ? gmdate( 'c', $spoof_last ) : '',
				'verifiedHits'  => $verified_hits,
			);
		}

		// Collapse UA variants of one client: two user-agents that resolve to the same
		// block token (a version bump, a parenthetical comment…) are one decision —
		// one Block denies both — so listing them separately implies two. Fold matching
		// actionable rows into one (summed volume, widest first/last window, OR'd flags),
		// keeping the most-recent UA as the face of the row.
		$out = self::merge_token_variants( $out );

		// Drop rows the owner dismissed ("not now"), unless they've materially changed
		// since. Done AFTER the merge so the re-surface test compares against the same
		// summed volume the owner saw when they chose to dismiss.
		$out = self::apply_dismissals(
			$out,
			isset( $opts['dismissed'] ) ? (array) $opts['dismissed'] : array(),
			$burst_min
		);

		// Count only what finally shows (post-merge, post-dismiss) so the chips and badge
		// match the rows the owner actually sees.
		$counts = array( 'new' => 0, 'heavy' => 0, 'spoof' => 0 );
		foreach ( $out as $row ) {
			if ( $row['flags']['new'] ) {
				++$counts['new'];
			}
			if ( $row['flags']['heavy'] ) {
				++$counts['heavy'];
			}
			if ( $row['flags']['spoof'] ) {
				++$counts['spoof'];
			}
		}

		// Rank for a "review" panel: rows that still need a decision lead; an
		// already-blocked client is handled, so it sinks. Within each group, most
		// severe first, then by raw volume.
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['blocked'] !== $b['blocked'] ) {
					return $a['blocked'] ? 1 : -1;
				}
				if ( $a['severity'] !== $b['severity'] ) {
					return $b['severity'] - $a['severity'];
				}
				return $b['hits'] - $a['hits'];
			}
		);

		return array(
			'sources'    => array_slice( $out, 0, max( 1, $limit ) ),
			'counts'     => $counts,
			'blockingOn' => ! empty( $opts['blockingOn'] ),
			// Whether the spoofed/impostor class-block is armed — with blockingOn, it
			// means a PROVEN impostor is refused at the AI endpoints automatically, and
			// the panel's advice can say so instead of implying the owner must act.
			'blockSpoofed' => ! empty( $opts['blockSpoofed'] ),
			// Whether identity verification is on, so an unchecked row can say why it is
			// unchecked — and the panel never advises turning on a setting already on.
			'verifyOn'   => ! empty( $opts['verifyOn'] ),
			// How long the "new" flag lasts. The queue is rebuilt from the log on every
			// read, so a novelty-only row leaves ON ITS OWN when this window lapses —
			// the UI needs the number to say so up front ("leaves in 31h") instead of
			// letting the owner discover a silent disappearance. From the resolved
			// option, so a filtered `agentimus_new_agent_seconds` shows its real value.
			'newSecs'    => $new_secs,
		);
	}

	/**
	 * Fold review rows that share a block token into one. Only actionable ('agent')
	 * rows merge — a spoof row arms a whole class and a no-token row has no shared
	 * action, so those stay as they are. Order is preserved (first occurrence keeps
	 * its slot); later variants are summed into it.
	 *
	 * @param array $rows Per-UA rows built by {@see analyze_threats()}.
	 * @return array
	 */
	private static function merge_token_variants( array $rows ) {
		$out   = array();
		$index = array(); // token => position in $out.
		foreach ( $rows as $row ) {
			$key = ( 'agent' === $row['action'] && '' !== $row['token'] ) ? $row['token'] : '';
			if ( '' === $key || ! isset( $index[ $key ] ) ) {
				$row['variants']   = 1;
				$row['variantUas'] = array( $row['ua'] );
				if ( '' !== $key ) {
					$index[ $key ] = count( $out );
				}
				$out[] = $row;
				continue;
			}
			$i         = $index[ $key ];
			$out[ $i ] = self::fold_variant( $out[ $i ], $row );
		}
		return $out;
	}

	/**
	 * Merge one extra UA variant ($add) into the row being kept ($keep): summed
	 * volume, widest seen-window, OR'd flags, with the most-recent variant's UA and
	 * identity card as the row's representative face. Variant UAs are listed (capped)
	 * for the row's tooltip.
	 *
	 * @param array $keep Accumulator row.
	 * @param array $add  Variant to fold in.
	 * @return array
	 */
	private static function fold_variant( array $keep, array $add ) {
		$keep['hits']    += $add['hits'];
		$keep['recent']  += $add['recent'];
		$keep['severity'] = max( $keep['severity'], $add['severity'] );
		// The population split folds like the totals it splits: counts sum, and the
		// newest failure dates the merged verdict (ISO-8601 +00:00 sorts lexically).
		$keep['spoofHits']    += $add['spoofHits'];
		$keep['verifiedHits'] += $add['verifiedHits'];
		if ( '' !== $add['spoofLastSeen'] && ( '' === $keep['spoofLastSeen'] || $add['spoofLastSeen'] > $keep['spoofLastSeen'] ) ) {
			$keep['spoofLastSeen'] = $add['spoofLastSeen'];
		}
		// Worst verdict wins (spoofed > verified > unchecked): a client that impersonated
		// its claimed engine in ANY variant is an impersonator.
		if ( 'spoofed' === $add['verdict'] || ( 'verified' === $add['verdict'] && '' === $keep['verdict'] ) ) {
			$keep['verdict'] = $add['verdict'];
		}
		// The signature face travels with the verdict: any variant that carried one
		// gives the folded row its name to show.
		if ( '' === ( $keep['signer'] ?? '' ) && '' !== ( $add['signer'] ?? '' ) ) {
			$keep['signer'] = $add['signer'];
		}
		foreach ( array( 'new', 'heavy', 'spoof' ) as $flag ) {
			$keep['flags'][ $flag ] = $keep['flags'][ $flag ] || $add['flags'][ $flag ];
		}
		// ISO-8601 with a fixed +00:00 offset sorts lexically = chronologically.
		if ( '' !== $add['firstSeen'] && ( '' === $keep['firstSeen'] || $add['firstSeen'] < $keep['firstSeen'] ) ) {
			$keep['firstSeen'] = $add['firstSeen'];
		}
		// Keep the freshest re-check stamp across folded variants (they share a review key,
		// so this is normally identical — max() just guards the edge where it isn't).
		if ( '' !== $add['reverifiedAt'] && ( '' === $keep['reverifiedAt'] || $add['reverifiedAt'] > $keep['reverifiedAt'] ) ) {
			$keep['reverifiedAt'] = $add['reverifiedAt'];
		}
		// Keep a non-empty network attribution across folded variants.
		if ( '' === $keep['network'] && '' !== $add['network'] ) {
			$keep['network'] = $add['network'];
		}
		// The latest-seen variant becomes the row's face (UA + identity card + claim —
		// the claim is derived from the UA, so it travels with it).
		if ( $add['lastSeen'] > $keep['lastSeen'] ) {
			$keep['lastSeen'] = $add['lastSeen'];
			$keep['ua']       = $add['ua'];
			$keep['agent']    = $add['agent'];
			$keep['known']    = $add['known'];
			$keep['guide']    = $add['guide'];
			$keep['claim']    = $add['claim'];
		}
		++$keep['variants'];
		if ( count( $keep['variantUas'] ) < 6 ) {
			$keep['variantUas'][] = $add['ua'];
		}
		return $keep;
	}

	/**
	 * The stable "identity" key a dismissal is filed under — the same value derived at
	 * dismiss-time and at analysis-time, so a "not now" sticks to the right client across
	 * refreshes and UA version-bumps. A client with a safe block token keys on that token
	 * (so every variant of it is one dismissal, mirroring the one-decision merge); one
	 * without keys on a hash of its UA. Pure.
	 *
	 * @param string $ua Raw User-Agent.
	 * @return string Dismissal key.
	 */
	public static function dismiss_key( $ua ) {
		$ua    = (string) $ua;
		$token = Guard::suggest_token( $ua ); // Already lowercased; '' when none is safe.
		if ( '' !== $token ) {
			return 'tok:' . $token;
		}
		return 'ua:' . md5( strtolower( trim( $ua ) ) );
	}

	/**
	 * Drop dismissed rows from a merged review set — unless the client has changed enough
	 * to earn another look. A dismissal (including of a caught impersonator — the owner has
	 * seen it and the in-plugin remedy is an acknowledgement, since the real fix is an IP
	 * rule at the host/CDN) is honoured until the client's volume both DOUBLES and grows by
	 * at least a burst's worth — a materially different picture from the one the owner waved
	 * off, not mere drift. So a persistent impersonator that keeps hammering re-surfaces on
	 * its own. Pure (the map is passed in).
	 *
	 * @param array $rows      Merged review rows.
	 * @param array $dismissed Map of key => { at, hits }.
	 * @param int   $burst_min The burst threshold, reused as the minimum re-surface delta.
	 * @return array Rows that still belong in the queue.
	 */
	private static function apply_dismissals( array $rows, array $dismissed, $burst_min ) {
		if ( empty( $dismissed ) ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$key = self::dismiss_key( $row['ua'] );
			if ( isset( $dismissed[ $key ] ) ) {
				$was  = isset( $dismissed[ $key ]['hits'] ) ? (int) $dismissed[ $key ]['hits'] : 0;
				$grew = (int) $row['hits'] >= max( $was * 2, $was + (int) $burst_min );
				if ( ! $grew ) {
					continue; // Dismissed and materially unchanged — suppress.
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * The dismissals map (key => { at, hits }). Always an array.
	 *
	 * @return array
	 */
	public static function dismissed_map() {
		$map = get_option( self::DISMISS_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * The dismissals as display rows for the client manager, newest first.
	 * Backward compatible with entries written before `label` existed: a token
	 * key ("tok:semrushbot") still names itself; a hash key yields '' and the
	 * UI shows a neutral "Unnamed client".
	 *
	 * @return array<int,array{key:string,label:string,at:int,hits:int}>
	 */
	public static function dismissals() {
		$out = array();
		foreach ( self::dismissed_map() as $key => $row ) {
			$label = isset( $row['label'] ) ? (string) $row['label'] : '';
			if ( '' === $label && 0 === strpos( (string) $key, 'tok:' ) ) {
				$label = substr( (string) $key, 4 );
			}
			$out[] = array(
				'key'   => (string) $key,
				'label' => $label,
				'at'    => isset( $row['at'] ) ? (int) $row['at'] : 0,
				'hits'  => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['at'] <=> $a['at'];
			}
		);
		return $out;
	}

	/**
	 * Forget one dismissal — the client manager's "Un-ignore". The client
	 * reappears in the review queue on its next build if it is still visiting.
	 *
	 * @param string $key Review key ("tok:…" / "ua:…") as listed by dismissals().
	 * @return bool Whether the key existed.
	 */
	public static function undismiss( $key ) {
		$key = (string) $key;
		$map = self::dismissed_map();
		if ( ! isset( $map[ $key ] ) ) {
			return false;
		}
		unset( $map[ $key ] );
		update_option( self::DISMISS_OPTION, $map, false );
		ReviewBadge::forget(); // The client may re-enter the queue — the menu badge recounts.
		return true;
	}

	/**
	 * The admin "Re-check" overlay (review-key => { verdict:int, at:string }), with entries
	 * past {@see REVERIFY_TTL} filtered out AT READ time — a re-check is a point-in-time fact,
	 * so a stale one must never keep overriding a client's live ingest verdict, even before the
	 * daily prune runs. `at` is a GMT 'Y-m-d H:i:s' string, so the lexical compare is chronological.
	 *
	 * @return array<string,array{verdict:int,at:string}>
	 */
	public static function reverified_map() {
		$map = get_option( self::REVERIFY_OPTION, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::REVERIFY_TTL );
		$fresh  = array();
		foreach ( $map as $k => $v ) {
			if ( isset( $v['at'] ) && (string) $v['at'] >= $cutoff ) {
				$fresh[ (string) $k ] = array(
					'verdict' => isset( $v['verdict'] ) ? (int) $v['verdict'] : 0,
					'at'      => (string) $v['at'],
				);
			}
		}
		return $fresh;
	}

	/**
	 * Record one admin "Re-check" result, keyed by the client's review identity so it layers
	 * onto the right row across UA version-bumps (mirrors {@see dismiss()}). Bounded to the
	 * most recent REVERIFY_MAX entries.
	 *
	 * @param string $ua      The client's raw User-Agent.
	 * @param int    $verdict Fresh verdict: 0 = undetermined, 1 = verified, 2 = spoofed.
	 * @return void
	 */
	public static function record_reverify( $ua, $verdict ) {
		$map = get_option( self::REVERIFY_OPTION, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$map[ self::dismiss_key( $ua ) ] = array(
			'verdict' => max( 0, min( 2, (int) $verdict ) ),
			'at'      => current_time( 'mysql', true ), // GMT, matches the map's compare format.
		);
		if ( count( $map ) > self::REVERIFY_MAX ) {
			uasort(
				$map,
				static function ( $a, $b ) {
					return strcmp( isset( $b['at'] ) ? (string) $b['at'] : '', isset( $a['at'] ) ? (string) $a['at'] : '' );
				}
			);
			$map = array_slice( $map, 0, self::REVERIFY_MAX, true );
		}
		update_option( self::REVERIFY_OPTION, $map, false );
		ReviewBadge::forget(); // A fresh verdict can unflag (or flag) the row — the menu badge recounts.
	}

	/**
	 * Drop re-check overlays past their TTL from storage (freshness is also enforced at read by
	 * {@see reverified_map()}; this just keeps the option from growing). Runs with the daily prune.
	 *
	 * @return void
	 */
	public static function prune_reverified() {
		$map = get_option( self::REVERIFY_OPTION, array() );
		if ( ! is_array( $map ) || empty( $map ) ) {
			return;
		}
		$fresh = self::reverified_map();
		if ( count( $fresh ) !== count( $map ) ) {
			update_option( self::REVERIFY_OPTION, $fresh, false );
		}
	}

	/**
	 * File an "Ignore" for a client: record its identity key with the volume the owner
	 * saw, so {@see apply_dismissals()} can later tell "unchanged" from "changed". Bounded
	 * to the most-recently dismissed DISMISS_MAX entries.
	 *
	 * @param string $ua   The client's raw User-Agent.
	 * @param int    $hits Its hit count at dismiss-time (what the owner saw).
	 * @return void
	 */
	public static function dismiss( $ua, $hits ) {
		$map                             = self::dismissed_map();
		$map[ self::dismiss_key( $ua ) ] = array(
			'at'   => time(),
			'hits' => max( 0, (int) $hits ),
			// Display name for the client manager, resolved the same way the
			// activity feed names clients. Entries written before this field
			// existed simply have none (the manager falls back to the key).
			'label' => Classifier::classify( (string) $ua ),
		);
		if ( count( $map ) > self::DISMISS_MAX ) {
			uasort(
				$map,
				static function ( $a, $b ) {
					return ( isset( $b['at'] ) ? (int) $b['at'] : 0 ) - ( isset( $a['at'] ) ? (int) $a['at'] : 0 );
				}
			);
			$map = array_slice( $map, 0, self::DISMISS_MAX, true );
		}
		update_option( self::DISMISS_OPTION, $map, false );
		ReviewBadge::forget(); // The ignored client leaves the queue — the menu badge recounts.
	}

	/**
	 * Forget dismissals older than the retention window, so a long-gone client that
	 * returns gets a fresh review rather than a stale, silent suppression. Runs with the
	 * daily prune.
	 *
	 * @return void
	 */
	public static function prune_dismissed() {
		$map = self::dismissed_map();
		if ( empty( $map ) ) {
			return;
		}
		$cutoff = time() - Repository::retention_days() * DAY_IN_SECONDS;
		$kept   = array();
		foreach ( $map as $k => $v ) {
			if ( ( isset( $v['at'] ) ? (int) $v['at'] : 0 ) >= $cutoff ) {
				$kept[ $k ] = $v;
			}
		}
		if ( count( $kept ) !== count( $map ) ) {
			update_option( self::DISMISS_OPTION, $kept, false );
		}
	}

	/**
	 * Forget both review overlays — used when the log is cleared, since the history
	 * they were judged against is gone.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::DISMISS_OPTION );
		delete_option( self::REVERIFY_OPTION );
	}
}
