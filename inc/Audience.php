<?php
/**
 * Audience — who reached this site: PEOPLE, or MACHINES.
 *
 * The plugin has always held both answers and never put them side by side. Agent
 * fetches lived on one screen, readers AI sent lived on another, search clicks on
 * a third, and nothing anywhere said which of those numbers were human. An owner
 * comparing them had to know, unaided, that "166 requests" and "59 visits" count
 * different species.
 *
 * This assembles both halves once, in the same window, so a screen can state them
 * plainly — and, just as importantly, states what they CANNOT say. Every number
 * here already existed; none of them is estimated, modelled or inferred, and
 * where a split is impossible this says so rather than guessing a ratio.
 *
 * ⚠️ The three honest limits, all verifiable in this codebase:
 *
 *  1. Search clicks are people, but Search Console folds AI Overview appearances
 *     into the same figures and exposes no dimension that separates them. There
 *     is no API for that split — not a missing feature here, a missing feature
 *     there.
 *  2. Machine fetches are counted per ENDPOINT, not per page: {@see \Agentimus\Activity\Table}
 *     stores `endpoint` (llms.txt, a .md twin, a discovery document), and the
 *     recorder only ever sees bot-facing routes. A crawler reading an ordinary
 *     page is not in this number, because nothing records it.
 *  3. Readers AI sent are counted when the visit still carries a recognisable
 *     referrer or campaign tag. An assistant that strips both is invisible here.
 *
 * @package Agentimus
 */

namespace Agentimus;

use Agentimus\Search\Table as SearchTable;

defined( 'ABSPATH' ) || exit;

final class Audience {

	/**
	 * Both halves, from stats the caller has already paid for.
	 *
	 * Takes {@see \Agentimus\Activity\Repository::stats()} rather than calling it:
	 * the dashboard's poll computes that payload anyway, and recomputing it here
	 * would double every COUNT on the busiest request the admin makes. The only
	 * query this adds is one indexed aggregate per connected search source.
	 *
	 * @param array $stats An Activity\Repository::stats() payload.
	 * @return array
	 */
	public static function from_stats( array $stats ) {
		$window   = isset( $stats['window'] ) ? (int) $stats['window'] : 30;
		$logging  = ! empty( $stats['enabled'] );
		$totals   = isset( $stats['totals'] ) && is_array( $stats['totals'] ) ? $stats['totals'] : array();
		$referral = isset( $stats['referrals'] ) && is_array( $stats['referrals'] ) ? $stats['referrals'] : array();

		$search = self::search_half();
		$ai     = self::ai_half( $referral );

		$machines = array(
			'enabled'  => $logging,
			'fetches'  => isset( $totals['month'] ) ? (int) $totals['month'] : 0,
			'today'    => isset( $totals['today'] ) ? (int) $totals['today'] : 0,
			'agents'   => isset( $totals['agents'] ) ? (int) $totals['agents'] : 0,
			// Clients caught claiming an identity that verification did not
			// support. Part of the machine picture, and nothing else on screen
			// carries it next to a headline count.
			'impostors' => self::impostor_count( $stats ),
		);

		return array(
			'window'   => $window,
			// The two headline numbers, and they are NOT summed anywhere. A
			// combined "total audience" would be a lie built from two different
			// units — a fetch is not a visit — and inviting the comparison is the
			// whole point of putting them side by side.
			'people'   => array(
				'search'  => $search,
				'ai'      => $ai,
				'arrived' => $search['clicks'] + $ai['visits'],
			),
			'machines' => $machines,
			'limits'   => self::limits( $search, $ai, $machines ),
		);
	}

	/**
	 * People arriving from search engines: the engines' own reported clicks.
	 *
	 * @return array{connected:bool,source:string,clicks:int,impressions:int,rows:int,start:string,end:string}
	 */
	private static function search_half() {
		$state     = Search\Report::source_state();
		$sources   = isset( $state['sources'] ) ? (array) $state['sources'] : array();
		$connected = false;

		$clicks      = 0;
		$impressions = 0;
		$rows        = 0;
		$start       = '';
		$end         = '';
		$named       = array();

		foreach ( array( 'google', 'bing' ) as $key ) {
			if ( empty( $sources[ $key ]['hasData'] ) ) {
				continue;
			}
			$connected = true;
			$named[]   = 'google' === $key ? 'Google' : 'Bing';

			$t            = SearchTable::totals( $key );
			$clicks      += $t['clicks'];
			$impressions += $t['impressions'];
			$rows        += $t['rows'];
			// The widest window any connected source reports, so the label above
			// these numbers can never claim a span one of them does not cover.
			$start = ( '' === $start || ( '' !== $t['start'] && $t['start'] < $start ) ) ? $t['start'] : $start;
			$end   = ( '' === $end || $t['end'] > $end ) ? $t['end'] : $end;
		}

		return array(
			'connected'   => $connected,
			'source'      => implode( ' + ', $named ),
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'rows'        => $rows,
			'start'       => $start,
			'end'         => $end,
		);
	}

	/**
	 * People an AI assistant sent — the referral half, reshaped for the card.
	 *
	 * @param array $referral A Referrals::summary() payload.
	 * @return array{enabled:bool,visits:int,today:int,sources:int,top:array}
	 */
	private static function ai_half( array $referral ) {
		$totals = isset( $referral['totals'] ) && is_array( $referral['totals'] ) ? $referral['totals'] : array();
		$by     = isset( $referral['bySource'] ) && is_array( $referral['bySource'] ) ? $referral['bySource'] : array();

		$top = array();
		foreach ( array_slice( $by, 0, 3 ) as $row ) {
			$top[] = array(
				'source' => (string) ( isset( $row['source'] ) ? $row['source'] : ( isset( $row['label'] ) ? $row['label'] : '' ) ),
				'hits'   => (int) ( isset( $row['hits'] ) ? $row['hits'] : 0 ),
			);
		}

		return array(
			'enabled' => ! empty( $referral['enabled'] ),
			'visits'  => isset( $totals['window'] ) ? (int) $totals['window'] : 0,
			'today'   => isset( $totals['today'] ) ? (int) $totals['today'] : 0,
			'sources' => isset( $referral['sourceCount'] ) ? (int) $referral['sourceCount'] : 0,
			'top'     => $top,
		);
	}

	/**
	 * Clients whose claimed identity failed verification, if the queue holds any.
	 *
	 * @param array $stats The stats payload.
	 * @return int
	 */
	private static function impostor_count( array $stats ) {
		$threats = isset( $stats['threats'] ) && is_array( $stats['threats'] ) ? $stats['threats'] : array();

		// The queue's own tally, when it is there.
		if ( isset( $threats['counts']['spoof'] ) && is_numeric( $threats['counts']['spoof'] ) ) {
			return (int) $threats['counts']['spoof'];
		}

		// Otherwise count the verdicts. `sources` is capped for display, so this
		// can only ever UNDER-report — which is the right direction to be wrong
		// in for a number that accuses someone of forging an identity.
		$n = 0;
		foreach ( (array) ( isset( $threats['sources'] ) ? $threats['sources'] : array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['verdict'] ) && 'spoofed' === $row['verdict'] ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * What these numbers cannot say — shipped WITH them, not in a docs page.
	 *
	 * Each entry is only present when it actually applies to this site, so the
	 * list is never boilerplate an owner learns to skip. A limit that is always
	 * on screen is read as decoration; one that appears because it is true today
	 * is read as information.
	 *
	 * @param array $search   The search half.
	 * @param array $ai       The AI-referral half.
	 * @param array $machines The machine half.
	 * @return array<int,array{key:string,text:string}>
	 */
	private static function limits( array $search, array $ai, array $machines ) {
		$out = array();

		if ( $search['connected'] ) {
			$out[] = array(
				'key'  => 'search-blended',
				'text' => __( 'Search clicks count people. Google folds AI Overview appearances into the same figures and publishes no way to separate them, so a share of these impressions was an AI answer quoting you.', 'agentimus' ),
			);
		} else {
			$out[] = array(
				'key'  => 'search-missing',
				'text' => __( 'No search engine is connected, so the people-from-search half is empty — not zero. Connect Google or Bing under Settings → Data sources.', 'agentimus' ),
			);
		}

		if ( $machines['enabled'] ) {
			$out[] = array(
				'key'  => 'machines-endpoints',
				'text' => __( 'Machine fetches are counted on the routes built for agents — llms.txt, the .md twins, the discovery documents. A crawler reading an ordinary page is not in this number, because nothing here records ordinary page requests.', 'agentimus' ),
			);
		} else {
			$out[] = array(
				'key'  => 'machines-off',
				'text' => __( 'Agent activity is not being recorded, so the machine half is empty — not zero. Turn on “Record agent access” in Settings.', 'agentimus' ),
			);
		}

		if ( ! empty( $ai['enabled'] ) ) {
			$out[] = array(
				'key'  => 'ai-referrer',
				'text' => __( 'Readers an assistant sent are counted when the visit still carries a recognisable referrer or campaign tag. An assistant that strips both never appears here.', 'agentimus' ),
			);
		}

		return $out;
	}
}
