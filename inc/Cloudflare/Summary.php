<?php
/**
 * The edge summary — one composition shared by the admin REST route and the
 * MCP read tool, so an assistant asking "what happened at the edge?" gets
 * exactly what the owner's own screen shows: totals, per-crawler rows,
 * per-company rollups, and the policy conflicts (dismissals respected — an
 * owner's deliberate "accepted" is part of the site's state).
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

use Agentimus\Activity\Catalog;

defined( 'ABSPATH' ) || exit;

final class Summary {

	/**
	 * Build the summary payload.
	 *
	 * @param Settings            $cloudflare The Cloudflare connection store.
	 * @param \Agentimus\Settings $core       The core settings — the declared policy.
	 * @param int                 $days       Window length in days (already clamped by the caller).
	 * @return array
	 */
	public static function build( Settings $cloudflare, \Agentimus\Settings $core, $days ) {
		$days = max( 1, (int) $days );
		$view = $cloudflare->public_view();
		if ( empty( $view['connected'] ) ) {
			return array_merge( $view, array( 'days' => $days ) );
		}

		$catalog = Catalog::known();
		// The block list stores UA names as typed ("GPTBot"); our rows carry
		// lowercase catalog tokens — normalise once so the two ever meet.
		$owner_blocked = array_map( 'strtolower', array_map( 'strval', (array) $core->get( 'blocked_trainers', array() ) ) );

		$crawlers = array();
		$totals   = array( 'requests' => 0, 'cached' => 0, 'origin' => 0, 'blocked' => 0, 'bytes' => 0 );
		foreach ( Table::summary( $days ) as $row ) {
			$meta       = isset( $catalog[ $row['ua'] ] ) ? (array) $catalog[ $row['ua'] ] : array();
			$crawlers[] = array_merge( $row, array(
				'name'         => isset( $meta[0] ) ? (string) $meta[0] : (string) $row['ua'],
				'operator'     => isset( $meta[1] ) ? (string) $meta[1] : '',
				'blockedByYou' => in_array( strtolower( (string) $row['ua'] ), $owner_blocked, true ),
			) );
			foreach ( array( 'requests', 'cached', 'origin', 'blocked', 'bytes' ) as $k ) {
				$totals[ $k ] += (int) $row[ $k ];
			}
		}

		// Roll crawlers up to the company an owner actually negotiates with.
		$companies = array();
		foreach ( $crawlers as $c ) {
			$op = '' !== $c['operator'] ? $c['operator'] : $c['name'];
			if ( ! isset( $companies[ $op ] ) ) {
				$companies[ $op ] = array( 'operator' => $op, 'requests' => 0, 'bytes' => 0 );
			}
			$companies[ $op ]['requests'] += (int) $c['requests'];
			$companies[ $op ]['bytes']    += (int) $c['bytes'];
		}
		$companies = array_values( $companies );
		usort( $companies, static function ( $a, $b ) {
			return $b['bytes'] <=> $a['bytes'];
		} );

		$signal    = (array) $core->get( 'content_signal', array() );
		$conflicts = Conflicts::detect( $crawlers, array(
			'ai_input'       => ! isset( $signal['ai_input'] ) || false !== $signal['ai_input'],
			'ai_train'       => ! isset( $signal['ai_train'] ) || false !== $signal['ai_train'],
			'blocked_agents' => $owner_blocked,
		), $days, Table::recent( 24 ) );

		// Dismissals: first forget the ones whose conflict is no longer firing
		// (that situation ended — a recurrence must show again). The ones the
		// owner chose to hide while they persist are SPLIT OUT, not dropped:
		// hidden is a display choice, and every consumer — the Settings screen
		// that can bring one back, an agent reading the summary — deserves the
		// whole truth.
		$active_ids = array();
		foreach ( $conflicts as $conflict ) {
			$active_ids[] = (string) $conflict['id'];
		}
		$cloudflare->prune_dismissed( $active_ids );
		$hidden_ids = $cloudflare->dismissed_ids();

		// ⭐ WHEN EACH ONE STARTED. The conflicts themselves are recomputed every
		// read and remembered nowhere, so without this no surface could answer
		// "since when?" — and a warning whose age is unknown is one an owner
		// cannot weigh. Stamped here, beside the dismissal bookkeeping, because
		// this is the one place that knows which conflicts are live; it writes
		// only when that set changes ({@see Settings::note_first_seen}).
		$first_seen = $cloudflare->note_first_seen( $active_ids );

		// Read once for every conflict, and only when there is one to date.
		$daily     = $conflicts ? Table::daily( 30 ) : array();
		$operators = array();
		foreach ( $crawlers as $c ) {
			$operators[ (string) $c['ua'] ] = (string) $c['operator'];
		}

		// Resolve each conflict's dashboard destination to a real deep link here,
		// so no consumer ever builds URLs.
		$visible = array();
		$hidden  = array();
		foreach ( $conflicts as $conflict ) {
			$conflict['url'] = self::dash_url( $conflict['link'], (string) $view['zoneName'] );
			unset( $conflict['link'] );
			$id = (string) $conflict['id'];

			// ⭐⭐ WHEN IT BEGAN, PREFERRED OVER WHEN WE NOTICED. The recorded stamp
			// can only date a conflict from the moment this plugin first looked at
			// it, so the day the feature is installed it reports today for a
			// problem that started last week — a number we cannot stand behind.
			// The hours are on disk, so the beginning is derived from them and the
			// stamp is kept only for the case where they cannot answer.
			// ⚠️ THE THREE ANSWERS ARE DIFFERENT CLAIMS and the wording says which:
			// a derived start ("since the 26th"), a run older than everything we
			// kept ("for at least N days" — we do not know when it began), and no
			// history at all ("first seen today" — all we can honestly report).
			$onset               = Conflicts::onset( $daily, $id, $operators );
			$conflict['since']   = 0;
			$conflict['sinceOf'] = 'unknown';
			if ( '' !== $onset['at'] && ! $onset['bounded'] ) {
				$conflict['since']   = (int) strtotime( $onset['at'] . ' 00:00:00 UTC' );
				$conflict['sinceOf'] = 'started';
			} elseif ( '' !== $onset['at'] ) {
				$conflict['since']   = (int) strtotime( $onset['at'] . ' 00:00:00 UTC' );
				$conflict['sinceOf'] = 'atleast';
			} elseif ( isset( $first_seen[ $id ] ) ) {
				$conflict['since']   = (int) $first_seen[ $id ];
				$conflict['sinceOf'] = 'noticed';
			}

			// ⛔⛔ FORMATTED ONCE, HERE, IN THE SITE'S TIMEZONE. Three surfaces show
			// this date — the card, the findings row and the weekly email — and the
			// first cut let the browser format it for the card while PHP formatted
			// it for the other two. On a site set to UTC read from a UTC+6 laptop
			// they disagreed by a day, on screen, about the same fact. One clock,
			// named by the site, is the only version of this that can't drift.
			$date                  = $conflict['since']
				? wp_date( (string) get_option( 'date_format', 'F j, Y' ), $conflict['since'] )
				: '';
			$conflict['sinceText'] = self::since_text( (string) $conflict['sinceOf'], $date );
			if ( in_array( (string) $conflict['id'], $hidden_ids, true ) ) {
				$hidden[] = $conflict;
			} else {
				$visible[] = $conflict;
			}
		}

		return array_merge( $view, array(
			'days'            => $days,
			'totals'          => $totals,
			'crawlers'        => $crawlers,
			'companies'       => $companies,
			'conflicts'       => $visible,
			'hiddenConflicts' => $hidden,
			'dashUrl'         => self::dash_url( 'bots', (string) $view['zoneName'] ),
		) );
	}

	/**
	 * The one sentence every surface prints about a conflict's age.
	 *
	 * ⛔⛔ THE WORDING CARRIES THE CLAIM. "Started the 26th", "running since at
	 * least the 30th" and "first seen today" are three different statements, and
	 * printing them all as "Started X" would put a date we derived, a date we ran
	 * out of data at, and the day we happened to install the feature behind the
	 * same word. On the site this was built for that last one said a problem
	 * three days old had started today.
	 *
	 * Built here so the card, the findings row and the weekly email cannot word
	 * it differently — the same reason the date itself is formatted once.
	 *
	 * @param string $kind 'started', 'atleast', 'noticed' or 'unknown'.
	 * @param string $date The formatted date, or '' when there is none.
	 * @return string
	 */
	private static function since_text( $kind, $date ) {
		if ( '' === $date ) {
			return '';
		}
		if ( 'started' === $kind ) {
			/* translators: %s: a date, e.g. August 26, 2026. */
			return sprintf( __( 'Started %s', 'agentimus' ), $date );
		}
		if ( 'atleast' === $kind ) {
			/* translators: %s: a date, e.g. July 30, 2026. */
			return sprintf( __( 'Running since at least %s', 'agentimus' ), $date );
		}
		/* translators: %s: a date, e.g. August 29, 2026. */
		return sprintf( __( 'First seen %s', 'agentimus' ), $date );
	}

	/**
	 * A Cloudflare dashboard deep link. The `:account` placeholder is resolved
	 * by Cloudflare's own dash after login. Paths verified against the live
	 * dash 2026-07-31: `security/bots` redirects to the Bot-traffic settings
	 * tab (Bot Fight Mode's home), and AI Crawl Control's crawler-management
	 * page lives at `ai/security` (NOT `ai-crawl-control`, which 404s).
	 *
	 * @param string $target 'bots' (Bot Fight Mode) or 'ai-crawlers' (AI Crawl Control → Security).
	 * @param string $zone   Zone name.
	 * @return string
	 */
	public static function dash_url( $target, $zone ) {
		$path = 'ai-crawlers' === $target ? 'ai/security' : 'security/bots';
		return 'https://dash.cloudflare.com/?to=/:account/' . rawurlencode( $zone ) . '/' . $path;
	}
}
