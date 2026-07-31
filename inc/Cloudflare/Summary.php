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

		// Resolve each conflict's dashboard destination to a real deep link here,
		// so no consumer ever builds URLs.
		$visible = array();
		$hidden  = array();
		foreach ( $conflicts as $conflict ) {
			$conflict['url'] = self::dash_url( $conflict['link'], (string) $view['zoneName'] );
			unset( $conflict['link'] );
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
