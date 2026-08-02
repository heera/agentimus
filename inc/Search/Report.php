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
		return $out;
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

		$all      = $core->all();
		$aside    = ( isset( $all['search_ignored'] ) && is_array( $all['search_ignored'] ) ) ? array_map( 'intval', $all['search_ignored'] ) : array();
		$report   = Opportunities::build( Table::snapshot( $state['source'] ), $aside );
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
