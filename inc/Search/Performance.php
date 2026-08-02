<?php
/**
 * Performance — the whole-picture read of a query snapshot: what the site
 * earned in the window, which queries brought it, and which pages did the
 * earning.
 *
 * The counterpart to {@see Opportunities}: same stored rows, opposite lens.
 * Opportunities answers "what should I fix?"; this answers "how am I doing?".
 * Every figure is summed or averaged from what the engine itself reported —
 * nothing modelled, nothing estimated.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Performance {

	/** @var int Rows in each top list. */
	const TOP = 8;

	/**
	 * Summarize a snapshot.
	 *
	 * @param array $rows Snapshot rows ({@see Table::snapshot()}).
	 * @return array {
	 *     totals:      array{clicks:int,impressions:int,ctr:float,position:float},
	 *     top_queries: array<int,array>,
	 *     top_pages:   array<int,array>,
	 * }
	 */
	public static function build( array $rows ) {
		$clicks      = 0;
		$impressions = 0;
		$pos_weight  = 0.0; // Impression-weighted, so one obscure query can't drag the average.
		$queries     = array();
		$pages       = array();
		// Machine traffic, tracked on the SAME basis as the totals it distorts. This
		// screen stays the raw record — nothing is removed — but a click rate labelled
		// "of the people who saw you" must not silently count scraper probes as people.
		$probe_site  = 0;
		$probe_page  = 0;

		foreach ( $rows as $row ) {
			$c = (int) $row['clicks'];
			$i = (int) $row['impressions'];
			$p = (float) $row['position'];

			// Page-attributed rows and site-wide rows describe the SAME traffic from
			// two angles (Bing reports both), so the totals count the site-wide view
			// when it exists and the per-page rows otherwise — never both, or every
			// number would double. The per-page rows always feed the page list.
			$has_page = '' !== (string) $row['page_url'];
			$is_probe = Opportunities::is_operator_query( (string) $row['query'] );

			if ( ! $has_page ) {
				$clicks      += $c;
				$impressions += $i;
				$pos_weight  += $p * $i;
				if ( $is_probe ) {
					$probe_site += $i;
				}
			}

			$key = (string) $row['query'];
			if ( '' !== $key ) {
				if ( ! isset( $queries[ $key ] ) ) {
					$queries[ $key ] = array( 'query' => $key, 'clicks' => 0, 'impressions' => 0, 'pos_weight' => 0.0, 'is_probe' => $is_probe );
				}
				// A query can appear site-wide AND per page; keep the richer of the
				// two rather than summing the same demand twice.
				if ( $i > $queries[ $key ]['impressions'] ) {
					$queries[ $key ]['clicks']      = $c;
					$queries[ $key ]['impressions'] = $i;
					$queries[ $key ]['pos_weight']  = $p * $i;
				}
			}

			if ( $has_page ) {
				$pkey = (string) $row['page_url'];
				if ( ! isset( $pages[ $pkey ] ) ) {
					$pages[ $pkey ] = array(
						'page_url'    => $pkey,
						'page_id'     => (int) $row['page_id'],
						'clicks'      => 0,
						'impressions' => 0,
						'pos_weight'  => 0.0,
					);
				}
				$pages[ $pkey ]['clicks']      += $c;
				$pages[ $pkey ]['impressions'] += $i;
				$pages[ $pkey ]['pos_weight']  += $p * $i;
				if ( $is_probe ) {
					$probe_page += $i;
				}
			}
		}

		// No site-wide rows at all (Google reports only query×page): total from pages.
		$probe = $probe_site;
		if ( 0 === $impressions && ! empty( $pages ) ) {
			foreach ( $pages as $page ) {
				$clicks      += $page['clicks'];
				$impressions += $page['impressions'];
				$pos_weight  += $page['pos_weight'];
			}
			$probe = $probe_page;
		}

		return array(
			'totals'      => array(
				'clicks'      => $clicks,
				'impressions' => $impressions,
				'ctr'         => $impressions > 0 ? round( ( $clicks / $impressions ) * 100, 1 ) : 0.0,
				'position'    => $impressions > 0 ? round( $pos_weight / $impressions, 1 ) : 0.0,
				// What share of the figures above came from search-operator probes
				// rather than people. Stated, never subtracted: this screen promises
				// the engine's own numbers.
				'probeShare'  => $impressions > 0 ? (int) round( ( $probe / $impressions ) * 100 ) : 0,
			),
			'top_queries' => self::top( $queries, 'query' ),
			'top_pages'   => self::top( $pages, 'page' ),
			// How many there are in total, so a top-8 list can admit it is a top-8
			// list. A cap presented without its total reads as the whole picture.
			'counts'      => array(
				'queries' => count( $queries ),
				'pages'   => count( $pages ),
			),
		);
	}

	/**
	 * Sort by impressions, cap, and finish each row's derived figures.
	 *
	 * @param array  $rows Keyed rows carrying clicks/impressions/pos_weight.
	 * @param string $kind 'query' or 'page' (page rows keep their identity fields).
	 * @return array<int,array>
	 */
	private static function top( array $rows, $kind ) {
		$rows = array_values( $rows );
		usort( $rows, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );

		$out = array();
		foreach ( array_slice( $rows, 0, self::TOP ) as $row ) {
			$entry = array(
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'ctr'         => $row['impressions'] > 0 ? round( ( $row['clicks'] / $row['impressions'] ) * 100, 1 ) : 0.0,
				'position'    => $row['impressions'] > 0 ? round( $row['pos_weight'] / $row['impressions'], 1 ) : 0.0,
			);
			if ( 'query' === $kind ) {
				$entry['query'] = (string) $row['query'];
				// Marks the row as a search operator, not a person. The row stays —
				// this is the raw record — but a list headed "what people searched
				// for" must not present a scraper's probe as a person's question.
				$entry['is_probe'] = ! empty( $row['is_probe'] );
			} else {
				$entry['page_url'] = (string) $row['page_url'];
				$entry['page_id']  = (int) $row['page_id'];
			}
			$out[] = $entry;
		}
		return $out;
	}
}
