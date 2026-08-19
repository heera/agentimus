<?php
/**
 * Report — the search screens' payloads, shaped once and served twice: to the
 * admin over REST, and to agents over MCP.
 *
 * The admin and an assistant must never be told different things about the same
 * numbers, so both read this. What differs is only presentation: the REST
 * controller adds view-only trimmings, the MCP tools get the flatter shape their
 * schemas declare.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

use Agentimus\Bing;
use Agentimus\Google;

defined( 'ABSPATH' ) || exit;

final class Report {

	/**
	 * Which sources can answer, and which one a request reads: the asked-for
	 * source when it has data, else the richer one that does (Google first — its
	 * report joins query×page natively, where Bing needs several endpoints).
	 *
	 * @param string $asked Requested source ('google' | 'bing' | '').
	 * @return array{sources:array,source:string}
	 */
	public static function source_state( $asked = '' ) {
		$bing   = new Bing\Settings();
		$google = new Google\Settings();

		$sources = array(
			'bing'   => array(
				'connected' => $bing->connected(),
				'hasData'   => $bing->connected() && Table::has_rows( 'bing' ),
				'lastError' => (string) $bing->get( 'last_query_error', '' ),
				// ⚠️ ZERO SINCE THE ROTATION LANDED, and the change matters.
				// This used to be 10, meaning "page detail exists for your ten
				// busiest pages and nowhere else, for ever" — because the poll
				// asked about the same ten every day and the write erased the
				// rest. Bing is now worked through a few pages at a time until
				// every page has been asked about, so no fixed cap applies. What
				// replaces it is not one number but two: how many pages have been
				// asked about, and how many are still waiting.
				// ⛔ The asked/waiting counts do NOT belong here, tempting as it is:
				// source_state() is called on nearly every screen read, and two
				// COUNT queries on a hot path is a real cost for a figure only one
				// or two screens ever print. They are gathered where they are used
				// {@see \Agentimus\Worklist::payload()} instead.
				'pageCap'   => 0,
				// Rows the last poll could not keep, because the store holds only
				// so many. This belongs HERE and not in the connection's own view:
				// it is a fact about the data we hold, not about the connection.
				'dropped'   => (int) $bing->get( 'dropped_rows', 0 ),
			),
			'google' => array(
				'connected' => $google->connected(),
				'hasData'   => $google->connected() && Table::has_rows( 'google' ),
				'lastError' => (string) $google->get( 'last_error', '' ),
				// Google reports query×page directly — no per-page cap, and every
				// page it knows about arrives in one report, so nothing waits.
				'pageCap'   => 0,
				'dropped'   => (int) $google->get( 'dropped_rows', 0 ),
			),
		);

		$asked  = sanitize_key( (string) $asked );
		$source = '';
		if ( isset( $sources[ $asked ] ) && $sources[ $asked ]['hasData'] ) {
			$source = $asked;
		} elseif ( $sources['google']['hasData'] ) {
			$source = 'google';
		} elseif ( $sources['bing']['hasData'] ) {
			$source = 'bing';
		}

		return array( 'sources' => $sources, 'source' => $source );
	}

	/**
	 * How many pages the ACTIVE source can speak about, or 0 for "all of them".
	 *
	 * ⚠️ NOW ALWAYS 0, and kept only so the screens that branch on it keep taking
	 * their "no sampling" path. It used to answer 10 for Bing, because the poll
	 * asked about the ten busiest pages and erased every other page's rows on
	 * each run — a sample that could never grow. Bing is now worked through a few
	 * pages per poll until all of them have been asked about, so no page is
	 * permanently outside anything.
	 *
	 * The question this used to answer badly — "is this page blank because it has
	 * no searches, or because nobody asked?" — is now answered exactly, per page,
	 * by {@see \Agentimus\Search\Asks}. Screens should move to that; the branches
	 * still reading this go quiet on their own in the meantime, because a cap of
	 * zero means "no cap".
	 *
	 * @return int Pages covered, or 0 when the source reports every page.
	 */
	/**
	 * How many of the site's pages the ACTIVE source has not been asked about yet.
	 *
	 * Only Bing can be waiting on anything: it answers about one page per
	 * request, so its page-level detail fills in over days. Google reports every
	 * page it knows about in one report, so nothing is ever pending.
	 *
	 * ⚠️ Costs a COUNT, so it is called by the one or two screens that print it,
	 * never from {@see source_state()} — which nearly every screen read touches.
	 *
	 * @return int
	 */
	public static function waiting_pages() {
		$state = self::source_state();
		if ( 'bing' !== (string) $state['source'] ) {
			return 0;
		}
		return Asks::waiting( 'bing' );
	}

	public static function page_cap() {
		$state  = self::source_state();
		$source = (string) $state['source'];
		if ( '' === $source || ! isset( $state['sources'][ $source ]['pageCap'] ) ) {
			return 0;
		}
		return (int) $state['sources'][ $source ]['pageCap'];
	}

	/**
	 * The performance summary, MCP-shaped.
	 *
	 * @param \Agentimus\Settings $core   Core settings.
	 * @param string              $source Requested source.
	 * @return array
	 */
	public static function performance( \Agentimus\Settings $core, $source = '' ) {
		unset( $core ); // Not needed here; kept for a uniform tool signature.
		$state = self::source_state( $source );
		$out   = array(
			'source'     => $state['source'],
			'sources'    => $state['sources'],
			'range'      => array( 'start' => '', 'end' => '' ),
			'totals'     => array( 'impressions' => 0, 'clicks' => 0, 'ctr' => 0.0, 'position' => 0.0, 'probeShare' => 0 ),
			'counts'     => array( 'queries' => 0, 'pages' => 0 ),
			'topQueries' => array(),
			'topPages'   => array(),
			// The trend extras — empty-but-present until a source fills them.
			// Both engines report a daily split (Google via the date dimension,
			// Bing via GetRankAndTrafficStats); only Discover is Google's.
			// `ready` gates the week-on-week claim: zeros where the truth is
			// "unknown yet" would be a lie.
			'daily'      => array(),
			'weekly'     => array(
				'ready'    => false,
				'thisWeek' => array( 'impressions' => 0, 'clicks' => 0 ),
				'lastWeek' => array( 'impressions' => 0, 'clicks' => 0 ),
			),
			'updatedAt'  => 0,
			'discover'   => array( 'impressions' => 0, 'clicks' => 0 ),
		);
		if ( '' === $state['source'] ) {
			return $out;
		}

		$rows = Table::snapshot( $state['source'] );
		$perf = Performance::build( $rows );

		$out['totals']     = $perf['totals'];
		$out['counts']     = $perf['counts'];
		$out['topQueries'] = array_map( array( __CLASS__, 'wire_query' ), $perf['top_queries'] );
		$out['topPages']   = array_map( array( __CLASS__, 'wire_page' ), $perf['top_pages'] );
		if ( ! empty( $rows ) ) {
			$out['range'] = array(
				'start' => (string) $rows[0]['range_start'],
				'end'   => (string) $rows[0]['range_end'],
			);
		}

		$out           = array_merge( $out, self::extras( $state['source'] ) );
		$out['totals'] = self::apply_series_totals( $out['totals'], $state['source'] );

		return $out;
	}

	/**
	 * One source's window totals for ANY card that quotes them — the Search
	 * Performance tiles and the dashboard's audience row must never disagree
	 * about the same engine.
	 *
	 * For Bing, the stored query rows are its top-N sample and undercount the
	 * site (measured live: the sample held 1,388 of 8,047 impressions), so
	 * when the daily traffic series covers the snapshot's window it supplies
	 * clicks and impressions instead; `series` says whether it did. Google's
	 * rows ARE the full report, so its sums pass through untouched.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return array{clicks:int,impressions:int,rows:int,start:string,end:string,series:bool}
	 */
	public static function window_totals( $source ) {
		$t           = Table::totals( $source );
		$t['series'] = false;
		if ( 'bing' === $source && '' !== $t['start'] ) {
			$d = \Agentimus\Bing\Table::traffic_totals( $t['start'], $t['end'] );
			if ( $d['days'] > 0 ) {
				$t['clicks']      = $d['clicks'];
				$t['impressions'] = $d['impressions'];
				$t['series']      = true;
			}
		}
		return $t;
	}

	/**
	 * Swap sampled totals for the daily series' where the series answers —
	 * shared by both doors ({@see performance()} and the REST controller), so
	 * the admin and an assistant read the same tiles. Position is left as the
	 * sample's impression-weighted figure: the daily series carries no rank.
	 *
	 * @param array  $totals A Performance::build() totals block.
	 * @param string $source 'bing' or 'google'.
	 * @return array
	 */
	public static function apply_series_totals( array $totals, $source ) {
		$wt = self::window_totals( $source );
		if ( empty( $wt['series'] ) ) {
			return $totals;
		}
		$totals['clicks']      = $wt['clicks'];
		$totals['impressions'] = $wt['impressions'];
		$totals['ctr']         = $wt['impressions'] > 0 ? round( ( $wt['clicks'] / $wt['impressions'] ) * 100, 1 ) : 0.0;
		return $totals;
	}

	/**
	 * The trend extras — daily series, week-on-week, Discover — from one
	 * producer, so the admin screen and an assistant can never be told
	 * different trends. Google's series lives in the trend option, Bing's in
	 * its daily table; both come out in one shape. Discover stays Google's:
	 * Bing has no equivalent surface, and 0 there means exactly that.
	 *
	 * @param string $source 'bing' or 'google'.
	 * @return array{daily:array,weekly:array,updatedAt:int,discover:array{impressions:int,clicks:int}}
	 */
	public static function extras( $source ) {
		if ( 'bing' === $source ) {
			$daily = \Agentimus\Bing\Table::traffic_series( \Agentimus\Google\Module::TREND_KEEP );
			return array(
				'daily'     => array_slice( $daily, -112 ),
				'weekly'    => self::weekly_from_daily( $daily ),
				'updatedAt' => (int) ( new \Agentimus\Bing\Settings() )->get( 'traffic_at', 0 ),
				'discover'  => array( 'impressions' => 0, 'clicks' => 0 ),
			);
		}

		$trend    = get_option( \Agentimus\Google\Module::TREND_OPTION, array() );
		$trend    = is_array( $trend ) ? $trend : array();
		$daily    = isset( $trend['daily'] ) && is_array( $trend['daily'] ) ? array_values( $trend['daily'] ) : array();
		$discover = isset( $trend['discover'] ) && is_array( $trend['discover'] ) ? $trend['discover'] : array();
		return array(
			// The recent series, not the whole archive — 16 weeks tells the
			// story; the store keeps the longer history.
			'daily'     => array_slice( $daily, -112 ),
			'weekly'    => self::weekly_from_daily( $daily ),
			// 0 = never refreshed; readers show a staleness note past ~3 days
			// so last-good data can't pose as current.
			'updatedAt' => (int) ( isset( $trend['daily_at'] ) ? $trend['daily_at'] : 0 ),
			'discover'  => array(
				'impressions' => (int) ( isset( $discover['impressions'] ) ? $discover['impressions'] : 0 ),
				'clicks'      => (int) ( isset( $discover['clicks'] ) ? $discover['clicks'] : 0 ),
			),
		);
	}

	/**
	 * This week vs last from a daily series (oldest first). `ready` stays
	 * false under 14 days of history — zeros that mean "unknown" must never
	 * print as zeros that mean "nothing happened".
	 *
	 * @param array $daily Rows [{date, clicks, impressions}], oldest first.
	 * @return array{ready:bool,thisWeek:array{impressions:int,clicks:int},lastWeek:array{impressions:int,clicks:int}}
	 */
	public static function weekly_from_daily( array $daily ) {
		$out = array(
			'ready'    => false,
			'thisWeek' => array( 'impressions' => 0, 'clicks' => 0 ),
			'lastWeek' => array( 'impressions' => 0, 'clicks' => 0 ),
		);
		if ( count( $daily ) < 14 ) {
			return $out;
		}
		$tail = array_slice( array_values( $daily ), -14 );
		$sum  = static function ( array $days ) {
			$t = array( 'impressions' => 0, 'clicks' => 0 );
			foreach ( $days as $d ) {
				$t['impressions'] += (int) ( isset( $d['impressions'] ) ? $d['impressions'] : 0 );
				$t['clicks']      += (int) ( isset( $d['clicks'] ) ? $d['clicks'] : 0 );
			}
			return $t;
		};
		return array(
			'ready'    => true,
			'thisWeek' => $sum( array_slice( $tail, 7 ) ),
			'lastWeek' => $sum( array_slice( $tail, 0, 7 ) ),
		);
	}

	/**
	 * The opportunities worklist, MCP-shaped — including the honest states, so
	 * an agent never mistakes "nothing to report" for "nothing to fix".
	 *
	 * @param \Agentimus\Settings $core   Core settings (holds the set-aside list).
	 * @param string              $source Requested source.
	 * @return array
	 */
	public static function opportunities( \Agentimus\Settings $core, $source = '' ) {
		$state = self::source_state( $source );
		$out   = array(
			'state'          => 'not_connected',
			'source'         => $state['source'],
			'sources'        => $state['sources'],
			'medianCtr'      => null,
			'ctrBar'         => null,
			'medianReason'   => '',
			'medianRows'     => 0,
			'medianNeeds'    => 0,
			'noise'          => array( 'searches' => 0, 'share' => 0, 'examples' => array() ),
			'counts'         => array( 'opportunities' => 0, 'almost' => 0, 'seen' => 0, 'setAside' => 0 ),
			'almostThere'    => array(),
			'seenNotClicked' => array(),
			'collisions'     => array(),
			'collisionsTotal' => 0,
			// How many measured pages this report is NOT speaking about, because
			// their content type is switched off for checking. An absence has to
			// name itself: rows quietly vanishing from a worklist is how somebody
			// concludes their traffic did.
			'outOfScope'     => 0,
		);

		$connected = $state['sources']['bing']['connected'] || $state['sources']['google']['connected'];
		if ( ! $connected ) {
			return $out;
		}
		if ( '' === $state['source'] ) {
			$out['state'] = 'collecting';
			return $out;
		}

		$all        = $core->all();
		$aside      = ( isset( $all['search_ignored'] ) && is_array( $all['search_ignored'] ) ) ? array_map( 'intval', $all['search_ignored'] ) : array();
		$aside_urls = ( isset( $all['search_ignored_urls'] ) && is_array( $all['search_ignored_urls'] ) ) ? array_map( 'strval', $all['search_ignored_urls'] ) : array();

		// ⭐ ONE SCOPE for the whole screen. These worklists used to answer to
		// nothing: an owner could switch Products off, watch Your Content stop
		// listing them, and still be told here that a product was one push from
		// page one. Two universes on one screen, with nothing on it explaining
		// the difference — the same fault as the counts that disagreed.
		//
		// ⛔ NOT the performance report, which is next door and stays whole. That
		// one reports the traffic a site actually got; hiding real numbers because
		// of a preference about what to WORK ON would be a different lie.
		//
		// Read once and shared with the collision pass below — it used to take the
		// same snapshot twice.
		$scoped     = self::in_check_scope( Table::snapshot( $state['source'] ) );
		$rows       = $scoped['rows'];
		$out['outOfScope'] = $scoped['dropped'];
		// Built FIRST, and handed to the worklist: a search two of this site's
		// pages are splitting belongs to the split row, so the weaker page must
		// not also be listed as one push from page one for it. One pass, one
		// verdict about who is winning, both sections reading it.
		$collisions = Collisions::build( $rows, $aside, $aside_urls );
		$report     = Opportunities::build( $rows, $aside, $aside_urls, $collisions );
		$has_work = $report['counts']['opportunities'] > 0;

		$out['state']          = $has_work ? 'ready' : ( $report['judged'] ? 'clear' : 'too_thin' );
		$out['medianCtr']      = $report['median_ctr'];
		$out['ctrBar']         = $report['ctr_bar'];
		$out['medianReason']   = (string) $report['median_reason'];
		$out['medianRows']     = (int) $report['median_rows'];
		$out['medianNeeds']    = (int) $report['median_needs'];
		$out['noise']          = array(
			'searches' => (int) $report['noise']['searches'],
			'share'    => (int) $report['noise']['share'],
			// Verbatim, so a reader can judge the filter instead of trusting it.
			'examples' => array_map(
				static function ( $ex ) {
					return array(
						'query'       => (string) $ex['query'],
						'impressions' => (int) $ex['impressions'],
					);
				},
				$report['noise']['examples']
			),
		);
		$out['counts']         = array(
			'opportunities' => (int) $report['counts']['opportunities'],
			'almost'        => (int) $report['counts']['almost'],
			'seen'          => (int) $report['counts']['seen'],
			'setAside'      => (int) $report['counts']['set_aside'],
		);
		$out['almostThere']    = self::mark_waiting( array_map( array( __CLASS__, 'wire_card' ), $report['almost_there'] ), Opportunities::KIND_NEAR );
		$out['seenNotClicked'] = self::mark_waiting( array_map( array( __CLASS__, 'wire_card' ), $report['seen_not_chosen'] ), Opportunities::KIND_SEEN );

		// Searches several pages are splitting — same snapshot, same set-aside
		// list, so a page parked on the Optimize ledger stops being counted
		// against a search here too. The wire carries the heaviest few; the
		// total says what was held back (no silent caps).
		$out['collisionsTotal'] = count( $collisions );
		$out['collisions']      = array_map( array( Collisions::class, 'wire' ), array_slice( $collisions, 0, 5 ) );

		return $out;
	}

	/**
	 * Keep only the measured rows this site is actually checking.
	 *
	 * ⭐ A row with NO post behind it always stays — the home page, an archive, a
	 * permalink that has since gone. No content type governs those, so a decision
	 * about content types cannot speak for them, and dropping them would hide the
	 * one page most sites care about most.
	 *
	 * ⚠️ Costs NOTHING on the ordinary site. When every checkable type is checked
	 * — the default, and what most sites will always run — this returns the rows
	 * untouched without asking the database anything. The one query it can run is
	 * a single indexed lookup of the distinct page ids in the snapshot, not one
	 * per row.
	 *
	 * ⭐ PUBLIC because the admin's own route builds its report from a second
	 * snapshot of its own ({@see \Agentimus\Search\Rest::report()}). One filter,
	 * two callers: a copy of this rule that drifted would put the owner's screen
	 * and an assistant's answer in disagreement about which pages exist.
	 *
	 * @param array $rows Snapshot rows.
	 * @return array{rows:array,dropped:int}
	 */
	public static function in_check_scope( array $rows ) {
		$checked = \Agentimus\Content::check_post_types();
		$all     = \Agentimus\Content::checkable();
		sort( $checked );
		sort( $all );
		if ( $checked === $all ) {
			return array( 'rows' => $rows, 'dropped' => 0 );
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}
		if ( ! $ids ) {
			return array( 'rows' => $rows, 'dropped' => 0 );
		}

		global $wpdb;
		$ids   = array_map( 'intval', array_keys( $ids ) );
		$in    = implode( ',', $ids );
		$types = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids cast to int above.
			"SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ($in)",
			ARRAY_A
		);
		$map = array();
		foreach ( $types as $row ) {
			$map[ (int) $row['ID'] ] = (string) $row['post_type'];
		}

		$kept    = array();
		$dropped = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
			// Unknown id → keep. A row we cannot resolve is not a row we know to
			// be out of scope, and guessing "exclude" would silently delete a page
			// from the owner's worklist on the strength of a missing join.
			if ( $id < 1 || ! isset( $map[ $id ] ) || in_array( $map[ $id ], $checked, true ) ) {
				$kept[] = $row;
				continue;
			}
			$dropped[ $id ] = true;
		}

		// PAGES held back, not rows: one page can hold a dozen query rows, and
		// "12 pages are not being checked" beside a site with two products would
		// read as a miscount.
		return array( 'rows' => $kept, 'dropped' => count( $dropped ) );
	}

	/**
	 * Flag the pages whose owner-side work is already finished.
	 *
	 * This is the AGENT'S copy of the worklist, and it was the last place still
	 * telling the old lie. The admin screens learned to separate "still needs an
	 * edit" from "done, waiting on the next report"; an assistant asked to take a
	 * page from here and rewrite its title and description was still being handed
	 * pages whose title and description were already written — and would dutifully
	 * rewrite them.
	 *
	 * Same test as every other surface ({@see Standing}), so the agent and the
	 * owner cannot be told different things about the same page.
	 *
	 * @param array  $cards Wire cards.
	 * @param string $kind  Opportunities::KIND_NEAR or ::KIND_SEEN.
	 * @return array<int,array>
	 */
	private static function mark_waiting( array $cards, $kind ) {
		$done = Standing::map( Standing::card_ids( $cards ), $kind );
		foreach ( $cards as &$card ) {
			$id = (int) $card['postId'];
			// A page with no post behind it can never be judged finished: nothing
			// to measure, no title field to read. "We cannot tell" is never "done".
			$card['waiting'] = $id > 0 && ! empty( $done[ $id ] );
		}
		unset( $card );
		return $cards;
	}

	/**
	 * One top-search row, flattened for the wire. Named rather than inlined so the
	 * schema-drift test can run the REAL shaper instead of a copy of it.
	 *
	 * @param array $q A Performance top-query row.
	 * @return array
	 */
	private static function wire_query( array $q ) {
		return array(
			'query'       => (string) $q['query'],
			'isProbe'     => (bool) $q['is_probe'],
			'impressions' => (int) $q['impressions'],
			'clicks'      => (int) $q['clicks'],
			'ctr'         => (float) $q['ctr'],
			'position'    => (float) $q['position'],
		);
	}

	/**
	 * One top-page row, flattened for the wire.
	 *
	 * @param array $page A Performance top-page row.
	 * @return array
	 */
	private static function wire_page( array $page ) {
		$id = (int) $page['page_id'];
		return array(
			'title'       => $id > 0 ? html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : (string) wp_parse_url( (string) $page['page_url'], PHP_URL_PATH ),
			'url'         => (string) $page['page_url'],
			'postId'      => $id,
			'impressions' => (int) $page['impressions'],
			'clicks'      => (int) $page['clicks'],
			'ctr'         => (float) $page['ctr'],
			'position'    => (float) $page['position'],
		);
	}

	/**
	 * One page card, flattened for the wire.
	 *
	 * @param array $card An engine card.
	 * @return array
	 */
	private static function wire_card( array $card ) {
		$id = (int) $card['page_id'];
		return array(
			'title'       => $id > 0 ? html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : (string) wp_parse_url( (string) $card['page_url'], PHP_URL_PATH ),
			'url'         => (string) $card['page_url'],
			'postId'      => $id,
			'impressions' => (int) $card['impressions'],
			'clicks'      => (int) $card['clicks'],
			'ctr'         => (float) $card['ctr'],
			'position'    => (float) $card['position'],
			'searches'    => (int) $card['searches'],
			'wholePage'   => (bool) $card['whole_page'],
			'queries'     => array_map(
				static function ( $q ) {
					return array(
						'query'       => (string) $q['query'],
						'impressions' => (int) $q['impressions'],
						'clicks'      => (int) $q['clicks'],
						'ctr'         => (float) $q['ctr'],
						'position'    => (float) $q['position'],
					);
				},
				$card['queries']
			),
		);
	}
}
