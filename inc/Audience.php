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
		$ai     = self::ai_half( $referral, $window );
		$all    = self::analytics_half( $window );

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
				// When analytics is connected, EVERYONE is the headline and the two
				// named routes become shares of it. Without it, the headline can
				// only be the routes we can see — which is a smaller claim, and the
				// limits below say so out loud rather than letting it pose as all.
				'all'     => $all,
				'search'  => $search,
				'ai'      => $ai,
				'arrived' => $all['connected'] ? $all['users'] : $search['clicks'] + $ai['visits'],
				'whole'   => $all['connected'],
			),
			'machines' => $machines,
			'limits'   => self::limits( $search, $ai, $machines, $all ),
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
	 * Everyone — the GA4 half, when the owner has connected it.
	 *
	 * `activeUsers` is the headline rather than sessions or page views: sessions
	 * count the same reader twice for coming back after lunch, and views count
	 * them again for every click. The question this card asks is "how many
	 * people", so the metric has to be people.
	 *
	 * @param int $window The card's window in days.
	 * @return array{connected:bool,users:int,sessions:int,views:int,stale:bool,fetched:int,window:int}
	 */
	private static function analytics_half( $window ) {
		$settings = new Google\Settings();
		$empty    = array(
			'connected'     => false,
			'users'         => 0,
			'newUsers'      => 0,
			'sessions'      => 0,
			'views'         => 0,
			'engaged'       => 0,
			'engagedPct'    => 0,
			'avgSeconds'    => 0,
			'perVisit'      => 0.0,
			'pages'         => array(),
			// null, not 0: "GA4 has no opinion" and "GA4 counted none" are
			// different answers, and the screen shows them differently.
			'aiSessions'    => null,
			'otherSessions' => null,
			'aiBySource'    => array(),
			'stale'         => false,
			'fetched'       => 0,
			'window'        => (int) $window,
		);
		if ( ! $settings->analytics_connected() ) {
			return $empty;
		}

		$snap   = Google\Module::analytics_snapshot();
		$totals = isset( $snap['totals'] ) && is_array( $snap['totals'] ) ? $snap['totals'] : array();
		if ( empty( $totals ) ) {
			// Connected, but nothing fetched yet (or every attempt failed). Still
			// NOT "connected: true with zero people" — that reads as a dead site.
			return $empty;
		}

		$fetched = (int) ( isset( $snap['fetched'] ) ? $snap['fetched'] : 0 );

		$split = isset( $snap['split'] ) && is_array( $snap['split'] ) ? $snap['split'] : null;

		$pages = array();
		foreach ( array_slice( (array) ( isset( $snap['pages'] ) ? $snap['pages'] : array() ), 0, 6 ) as $row ) {
			$pages[] = array(
				'path'  => (string) ( isset( $row['path'] ) ? $row['path'] : '' ),
				'views' => (int) ( isset( $row['views'] ) ? $row['views'] : 0 ),
				'users' => (int) ( isset( $row['users'] ) ? $row['users'] : 0 ),
			);
		}

		return array(
			'connected' => true,
			'users'     => (int) ( isset( $totals['users'] ) ? $totals['users'] : 0 ),
			'newUsers'  => (int) ( isset( $totals['newUsers'] ) ? $totals['newUsers'] : 0 ),
			'sessions'  => (int) ( isset( $totals['sessions'] ) ? $totals['sessions'] : 0 ),
			'views'     => (int) ( isset( $totals['views'] ) ? $totals['views'] : 0 ),
			// How the visit went, not just that it happened.
			'engaged'    => (int) ( isset( $totals['engaged'] ) ? $totals['engaged'] : 0 ),
			'engagedPct' => (int) ( isset( $totals['engagedPct'] ) ? $totals['engagedPct'] : 0 ),
			'avgSeconds' => (int) ( isset( $totals['avgSeconds'] ) ? $totals['avgSeconds'] : 0 ),
			'perVisit'   => (float) ( isset( $totals['perVisit'] ) ? $totals['perVisit'] : 0 ),
			'pages'      => $pages,
			// GA4's OWN reading of how many an assistant sent. Kept separate from
			// the local count on purpose — the Readers screen shows both and names
			// the reason they differ, rather than picking a winner.
			'aiSessions' => null === $split ? null : (int) ( isset( $split['ai'] ) ? $split['ai'] : 0 ),
			'otherSessions' => null === $split ? null : (int) ( isset( $split['other'] ) ? $split['other'] : 0 ),
			// Which assistants, in Google's reading. Named hosts are turned into
			// the names people actually use — nobody thinks of their readers as
			// arriving from "gemini.google.com".
			'aiBySource' => null === $split ? array() : self::name_sources( isset( $split['bySource'] ) ? (array) $split['bySource'] : array() ),
			// Older than two polls. A number that has quietly stopped moving must
			// be able to say so; silence looks exactly like a flat week.
			'stale'     => $fetched > 0 && ( time() - $fetched ) > 2 * DAY_IN_SECONDS,
			'fetched'   => $fetched,
			'window'    => (int) ( isset( $snap['window'] ) ? $snap['window'] : $window ),
		);
	}

	/**
	 * Turn GA4's referring hosts into the names people say out loud.
	 *
	 * An unknown host is kept as-is rather than dropped or relabelled "Other":
	 * a new assistant showing up is exactly the thing worth noticing, and
	 * hiding it behind a bucket is how it goes unnoticed for months.
	 *
	 * @param array<string,int> $by_source host => visits.
	 * @return array<int,array{source:string,hits:int}>
	 */
	private static function name_sources( array $by_source ) {
		$names = array(
			'chatgpt.com'            => 'ChatGPT',
			'chat.openai.com'        => 'ChatGPT',
			'openai.com'             => 'ChatGPT',
			'perplexity.ai'          => 'Perplexity',
			'www.perplexity.ai'      => 'Perplexity',
			'gemini.google.com'      => 'Gemini',
			'bard.google.com'        => 'Gemini',
			'claude.ai'              => 'Claude',
			'copilot.microsoft.com'  => 'Copilot',
			'bing.com/chat'          => 'Copilot',
			'grok.com'               => 'Grok',
			'x.ai'                   => 'Grok',
			'chat.deepseek.com'      => 'DeepSeek',
			'deepseek.com'           => 'DeepSeek',
			'duckduckgo.com/aichat'  => 'DuckDuckGo AI',
			'you.com'                => 'You.com',
			'phind.com'              => 'Phind',
			'poe.com'                => 'Poe',
			'meta.ai'                => 'Meta AI',
			'mistral.ai'             => 'Mistral',
			'chat.mistral.ai'        => 'Mistral',
		);

		// Two hosts can share one name (chatgpt.com and chat.openai.com), and a
		// reader does not care which — so they are summed, not listed twice.
		$merged = array();
		foreach ( $by_source as $host => $hits ) {
			$label              = isset( $names[ $host ] ) ? $names[ $host ] : (string) $host;
			$merged[ $label ]   = ( isset( $merged[ $label ] ) ? $merged[ $label ] : 0 ) + (int) $hits;
		}
		arsort( $merged );

		$out = array();
		foreach ( array_slice( $merged, 0, 6, true ) as $label => $hits ) {
			$out[] = array( 'source' => $label, 'hits' => (int) $hits );
		}
		return $out;
	}

	/**
	 * People an AI assistant sent — the referral half, reshaped for the card.
	 *
	 * @param array $referral A Referrals::summary() payload.
	 * @return array{enabled:bool,visits:int,today:int,sources:int,top:array}
	 */
	private static function ai_half( array $referral, $window = 30 ) {
		$totals = isset( $referral['totals'] ) && is_array( $referral['totals'] ) ? $referral['totals'] : array();
		$by     = isset( $referral['bySource'] ) && is_array( $referral['bySource'] ) ? $referral['bySource'] : array();

		$top = array();
		foreach ( array_slice( $by, 0, 6 ) as $row ) {
			$top[] = array(
				'source' => (string) ( isset( $row['source'] ) ? $row['source'] : ( isset( $row['label'] ) ? $row['label'] : '' ) ),
				'hits'   => (int) ( isset( $row['hits'] ) ? $row['hits'] : 0 ),
			);
		}

		// Where those readers actually landed. The most useful thing on the
		// screen: it names the pages that are earning the citations.
		$pages = array();
		foreach ( array_slice( (array) ( isset( $referral['topPages'] ) ? $referral['topPages'] : array() ), 0, 6 ) as $row ) {
			$pages[] = array(
				'path' => (string) ( isset( $row['path'] ) ? $row['path'] : '' ),
				'hits' => (int) ( isset( $row['hits'] ) ? $row['hits'] : 0 ),
			);
		}

		$visits = isset( $totals['window'] ) ? (int) $totals['window'] : 0;
		$prev   = 0;
		if ( ! empty( $referral['enabled'] ) ) {
			$prev = Activity\Referrals::previous_window_total( $window );
		}

		return array(
			'enabled' => ! empty( $referral['enabled'] ),
			'visits'  => $visits,
			'today'   => isset( $totals['today'] ) ? (int) $totals['today'] : 0,
			'sources' => isset( $referral['sourceCount'] ) ? (int) $referral['sourceCount'] : 0,
			'top'     => $top,
			'pages'   => $pages,
			// The window before this one, same length, no overlap — so the
			// headline can say whether this is going anywhere.
			'prev'    => $prev,
			'change'  => $visits - $prev,
			// A first window has nothing to compare against, and inventing
			// "+100%" out of a zero baseline is how dashboards lie cheerfully.
			'hasPrev' => $prev > 0,
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
	 * @param array $all      The analytics half.
	 * @return array<int,array{key:string,text:string}>
	 */
	private static function limits( array $search, array $ai, array $machines, array $all = array() ) {
		$out = array();

		// The most important line on the card when analytics is absent: without
		// it, "People" is two named routes, NOT the site's audience — and a
		// headline number that quietly means something narrower than it looks is
		// the exact failure this whole block exists to prevent.
		if ( empty( $all['connected'] ) ) {
			$out[] = array(
				'key'   => 'people-partial',
				'scope' => 'humans',
				'text'  => __( 'Not your whole audience — search and AI only.', 'agentimus' ),
			);
		} else {
			$out[] = array(
				'key'   => 'people-sampled',
				'scope' => 'both',
				'text'  => __( 'The two counts never reconcile exactly.', 'agentimus' ),
			);
		}

		if ( ! empty( $all['stale'] ) ) {
			$out[] = array(
				'key'   => 'people-stale',
				'scope' => 'humans',
				'text'  => __( 'The analytics figure is over two days old.', 'agentimus' ),
			);
		}

		if ( $search['connected'] ) {
			$out[] = array(
				'key'   => 'search-blended',
				'scope' => 'humans',
				'text'  => __( 'Search hides AI clicks; AI visits are a floor.', 'agentimus' ),
			);
		} else {
			$out[] = array(
				'key'   => 'search-missing',
				'scope' => 'humans',
				'text'  => __( 'No search engine connected — empty, not zero.', 'agentimus' ),
			);
		}

		if ( $machines['enabled'] ) {
			$out[] = array(
				'key'   => 'machines-endpoints',
				'scope' => 'machines',
				'text'  => __( 'Agent files only — not ordinary pages.', 'agentimus' ),
			);
		} else {
			$out[] = array(
				'key'   => 'machines-off',
				'scope' => 'machines',
				'text'  => __( 'Not recording — empty, not zero.', 'agentimus' ),
			);
		}

		// The "AI-sent readers are a floor" warning used to be its own entry. It
		// is folded into the search line above, because the two said the same
		// thing from different ends — and because every group here has to fit
		// ONE line, or the three columns stop reading as three equal blocks.

		return $out;
	}
}
