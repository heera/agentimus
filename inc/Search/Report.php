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
				// Bing has no query×page report: page detail costs one HTTP call per
				// page, so the poll takes the busiest few. Every page-level figure
				// downstream is therefore scoped to those, and a screen that doesn't
				// say so is presenting a sample as the whole site.
				'pageCap'   => \Agentimus\Bing\Module::QUERY_TOP_PAGES,
			),
			'google' => array(
				'connected' => $google->connected(),
				'hasData'   => $google->connected() && Table::has_rows( 'google' ),
				'lastError' => (string) $google->get( 'last_error', '' ),
				// Google reports query×page directly — no per-page cap.
				'pageCap'   => 0,
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
			// Google-only extras — empty-but-present for Bing, whose API has
			// no daily split and no Discover. `ready` gates the week-on-week
			// claim: zeros where the truth is "unknown yet" would be a lie.
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

		if ( 'google' === $state['source'] ) {
			$out = array_merge( $out, self::google_extras() );
		}

		return $out;
	}

	/**
	 * The Google-only extras — daily series, week-on-week, Discover — from
	 * one producer, so the admin screen and an assistant can never be told
	 * different trends. Bing has no daily split and no Discover; callers
	 * simply don't ask for its extras.
	 *
	 * @return array{daily:array,weekly:array,discover:array{impressions:int,clicks:int}}
	 */
	public static function google_extras() {
		$trend    = get_option( \Agentimus\Google\Module::TREND_OPTION, array() );
		$trend    = is_array( $trend ) ? $trend : array();
		$daily    = isset( $trend['daily'] ) && is_array( $trend['daily'] ) ? array_values( $trend['daily'] ) : array();
		$discover = isset( $trend['discover'] ) && is_array( $trend['discover'] ) ? $trend['discover'] : array();
		return array(
			// The recent series, not the whole archive — 16 weeks tells the
			// story; the option keeps the longer history.
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
		$report     = Opportunities::build( Table::snapshot( $state['source'] ), $aside, $aside_urls );
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
		$out['almostThere']    = array_map( array( __CLASS__, 'wire_card' ), $report['almost_there'] );
		$out['seenNotClicked'] = array_map( array( __CLASS__, 'wire_card' ), $report['seen_not_chosen'] );

		return $out;
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
