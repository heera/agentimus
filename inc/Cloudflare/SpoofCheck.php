<?php
/**
 * Edge spoof check — whether the blocked traffic behind an edge conflict is
 * really the operator it claims to be.
 *
 * Cloudflare counts crawlers BY USER-AGENT STRING, and a User-Agent is
 * forgeable — so a scanner probing for /.env files while wearing
 * "OAI-SearchBot" is booked, blocked, as OpenAI. The conflict card then tells
 * the owner "Cloudflare is blocking OpenAI, but your policy says allow" about
 * an impersonation campaign being correctly stopped. Found live 2026-08-29:
 * 1,050 blocked "OAI-SearchBot" requests in a day, every checked one from a
 * rented cloud box outside OpenAI's published address ranges, and the real
 * crawler not visiting at all.
 *
 * This is the request log's spoofed/verified split applied at the edge: the
 * same verifier ({@see \Agentimus\BotVerifier::claim_verdict}), the same
 * three-valued honesty (verified / spoofed / could-not-say), against the
 * source addresses Cloudflare's GraphQL API can name for the blocked traffic.
 *
 * It runs in the hourly POLL and stores its verdict — never on a page load.
 * Reading the summary must stay a read of stored rows ({@see Summary}), and
 * this check costs one extra GraphQL call plus address lookups that are only
 * paid while a conflict is actually firing.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

use Agentimus\Activity\Catalog;
use Agentimus\BotVerifier;

defined( 'ABSPATH' ) || exit;

final class SpoofCheck {

	/** @var int Verify at most this many distinct source addresses per run. The
	 * rows arrive busiest-first, so the cap trims the tail, not the story. */
	const MAX_IP_LOOKUPS = 60;

	/** @var int A stored verdict is believed for this long — two missed polls.
	 * ⭐ AGE A VERDICT, NEVER LET IT LINGER: the traffic mix can change in a
	 * day, and a demotion running on last week's sample would hide a real
	 * blockage behind an old all-clear about impostors. */
	const FRESH_SECONDS = 26 * HOUR_IN_SECONDS;

	/** @var float Demotion needs this share of the sample PROVEN outside the
	 * operator's addresses. Below it, too much of the sample is undetermined
	 * — and undetermined must fail toward warning the owner, not toward
	 * explaining the warning away. */
	const MIN_SPOOFED_SHARE = 0.8;

	/**
	 * The poll-time half: find the operators a warn conflict currently accuses
	 * the edge of blocking, read the blocked traffic's source addresses for
	 * the last day, verify each against what that operator publishes, and
	 * store the tallies for {@see Summary::build()} to read.
	 *
	 * The stored map is REPLACED on every successful run, so a verdict dies
	 * with its conflict — the same lifecycle as the dismissals and the
	 * first-seen stamps. A failed API read keeps the previous map untouched;
	 * it ages out through {@see FRESH_SECONDS} instead of vanishing.
	 *
	 * @param Settings            $cloudflare The Cloudflare connection store.
	 * @param \Agentimus\Settings $core       The core settings — the declared policy.
	 * @param Client              $client     The API client.
	 * @return void
	 */
	public static function run( Settings $cloudflare, \Agentimus\Settings $core, Client $client ) {
		if ( ! $cloudflare->connected() ) {
			return;
		}

		// The same rows, policy and currency window Summary::build() hands to
		// Conflicts::detect() — a check for a conflict the panel would never
		// show is money spent on a question nobody asked.
		$catalog  = Catalog::known();
		$crawlers = array();
		foreach ( Table::summary( 7 ) as $row ) {
			$meta       = isset( $catalog[ $row['ua'] ] ) ? (array) $catalog[ $row['ua'] ] : array();
			$crawlers[] = array_merge( $row, array(
				'name'     => isset( $meta[0] ) ? (string) $meta[0] : (string) $row['ua'],
				'operator' => isset( $meta[1] ) ? (string) $meta[1] : '',
			) );
		}
		$signal    = (array) $core->get( 'content_signal', array() );
		$conflicts = Conflicts::detect( $crawlers, array(
			'ai_input'       => ! isset( $signal['ai_input'] ) || false !== $signal['ai_input'],
			'ai_train'       => ! isset( $signal['ai_train'] ) || false !== $signal['ai_train'],
			'blocked_agents' => array_map( 'strtolower', array_map( 'strval', (array) $core->get( 'blocked_trainers', array() ) ) ),
		), 7, Table::recent( 24 ) );

		$wanted = array();
		foreach ( $conflicts as $conflict ) {
			if ( 'warn' === (string) $conflict['level'] && 0 === strpos( (string) $conflict['id'], 'edge-blocks-' ) ) {
				// detect() rolls crawlers up per operator; recover the operator
				// names the same way it built the ids.
				foreach ( $crawlers as $c ) {
					$slug = Conflicts::operator_slug( (string) $c['operator'] );
					if ( '' !== $slug && 'edge-blocks-' . $slug === (string) $conflict['id'] ) {
						$wanted[ (string) $c['operator'] ] = true;
					}
				}
			}
		}
		if ( ! $wanted ) {
			$cloudflare->note_spoof_checks( array() );
			return;
		}

		$now = time();
		$out = $client->blocked_sources( $cloudflare->token(), (string) $cloudflare->get( 'zone_id', '' ), $now - DAY_IN_SECONDS, $now );
		if ( isset( $out['error'] ) ) {
			// Keep the previous verdicts — they age out rather than vanishing —
			// but write the failure down beside them. A check that cannot run
			// must not be indistinguishable from one that never fires: the
			// reserved key matches no operator, so no consumer reads it as a
			// verdict, and the next clean run replaces the whole map.
			$map            = $cloudflare->spoof_checks();
			$map['_failed'] = array( 'at' => $now, 'error' => (string) $out['error'] );
			$cloudflare->note_spoof_checks( $map );
			return;
		}

		$rows = array();
		foreach ( (array) $out['rows'] as $row ) {
			$token = Module::crawler_token( (string) $row['ua'] );
			if ( '' === $token || ! isset( $catalog[ $token ] ) ) {
				continue;
			}
			$op = (string) $catalog[ $token ][1];
			if ( ! isset( $wanted[ $op ] ) ) {
				continue;
			}
			$rows[] = array(
				'ip'       => (string) $row['ip'],
				'ua'       => (string) $row['ua'],
				'operator' => $op,
				'requests' => (int) $row['requests'],
			);
		}

		$checks = self::classify( $rows, array( BotVerifier::class, 'claim_verdict' ) );
		foreach ( $checks as $op => $tally ) {
			$checks[ $op ]['at'] = $now;
		}
		$cloudflare->note_spoof_checks( $checks );
	}

	/**
	 * The pure half: tally blocked requests per operator by what their source
	 * address proved. One verdict per distinct address — an address's identity
	 * is stable within a run — and at most $cap addresses actually looked up;
	 * requests from addresses past the cap count as undetermined, never as
	 * either verdict.
	 *
	 * @param array<int,array{ip:string,ua:string,operator:string,requests:int}> $rows
	 *        Blocked-source rows, busiest first.
	 * @param callable $verdict function( string $ua, string $ip ): int —
	 *        0 undetermined, 1 the operator's own address, 2 someone else's.
	 * @param int      $cap     Distinct-address lookup budget.
	 * @return array<string,array{sampled:int,verified:int,spoofed:int,unknown:int}>
	 */
	public static function classify( array $rows, $verdict, $cap = self::MAX_IP_LOOKUPS ) {
		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$op = (string) $row['operator'];
			$n  = max( 0, (int) $row['requests'] );
			if ( '' === $op || 0 === $n ) {
				continue;
			}
			if ( ! isset( $out[ $op ] ) ) {
				$out[ $op ] = array( 'sampled' => 0, 'verified' => 0, 'spoofed' => 0, 'unknown' => 0 );
			}
			$out[ $op ]['sampled'] += $n;

			$ip = (string) $row['ip'];
			if ( '' === $ip ) {
				$out[ $op ]['unknown'] += $n;
				continue;
			}
			if ( ! array_key_exists( $ip, $seen ) ) {
				$seen[ $ip ] = count( $seen ) < (int) $cap ? (int) call_user_func( $verdict, (string) $row['ua'], $ip ) : 0;
			}
			if ( 1 === $seen[ $ip ] ) {
				$out[ $op ]['verified'] += $n;
			} elseif ( 2 === $seen[ $ip ] ) {
				$out[ $op ]['spoofed'] += $n;
			} else {
				$out[ $op ]['unknown'] += $n;
			}
		}
		return $out;
	}

	/**
	 * Whether a stored check says the warning should stand down: everything it
	 * could determine was someone ELSE wearing the operator's name, none of it
	 * the operator, in volume, and recently.
	 *
	 * Any verified request keeps the warning: real blocking is the actionable
	 * fact whatever noise rides beside it. And a sample that is mostly
	 * undetermined keeps it too — "could not say" is not "not them".
	 *
	 * @param mixed $check A stored tally { at, sampled, verified, spoofed, unknown }.
	 * @param int   $now   Current unix time (injected for tests).
	 * @return bool
	 */
	public static function stands_down( $check, $now ) {
		if ( ! is_array( $check ) ) {
			return false;
		}
		$at       = isset( $check['at'] ) ? (int) $check['at'] : 0;
		$sampled  = isset( $check['sampled'] ) ? (int) $check['sampled'] : 0;
		$verified = isset( $check['verified'] ) ? (int) $check['verified'] : 0;
		$spoofed  = isset( $check['spoofed'] ) ? (int) $check['spoofed'] : 0;
		return $at > 0
			&& ( $now - $at ) <= self::FRESH_SECONDS
			&& 0 === $verified
			&& $spoofed >= Conflicts::MIN_BLOCKED
			&& $sampled > 0
			&& $spoofed >= $sampled * self::MIN_SPOOFED_SHARE;
	}

}
