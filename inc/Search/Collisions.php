<?php
/**
 * Collisions — searches that several of this site's pages are splitting.
 *
 * The engine can only send one search to one page at a time, so when two or
 * more pages keep appearing for the same query they take turns — and every
 * turn a weaker page takes is a click the strong one loses. That is why a
 * page can sit at #11 forever while its sibling sits at #14: the votes are
 * divided. Nothing here is modelled or estimated; a collision is only ever
 * asserted from the engine's own reported rows.
 *
 * The site-level twin of the per-page SCATTERED coverage state: same idea —
 * one subject, many places — measured across pages instead of inside one.
 *
 * Pure math over {@see Table::snapshot()} rows, in the {@see Opportunities}
 * mould: WordPress is touched only by the caller (set-aside list, titles).
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Collisions {

	/**
	 * A query must have been shown at least this often, in total, before a
	 * split is worth a row — thin data must never accuse a page.
	 */
	const MIN_SHOWN = 50;

	/** A page competes only if it holds at least this share of the showings. */
	const MIN_SHARE = 0.10;

	/**
	 * One page holding this much of the query already owns it — the others
	 * are noise, not competition, and there is nothing to fix.
	 */
	const OWNED = 0.80;

	/**
	 * Find the searches this site is splitting.
	 *
	 * @param array $rows           Snapshot rows ({@see Table::snapshot()}).
	 * @param array $set_aside      Post IDs the owner set aside (the shared Optimize list).
	 * @param array $set_aside_urls URL keys ({@see Pages::key()}) set aside for pages
	 *                              that never resolved to a post.
	 * @return array<int,array{
	 *     query:string, shown:int, clicks:int, best:float, worst:float,
	 *     pages:array<int,array{url:string,page_id:int,clicks:int,impressions:int,position:float,share:float,winner:bool}>
	 * }> Ranked by showings, heaviest split first.
	 */
	public static function build( array $rows, array $set_aside = array(), array $set_aside_urls = array() ) {
		$set_aside      = array_map( 'intval', $set_aside );
		$set_aside_urls = array_map( 'strval', $set_aside_urls );

		// 1. Page-attributed rows only, grouped by the query as the engine
		// reported it (case-folded; never stemmed — merging "llms txt" with
		// "llms.txt file" would assert a collision the engine never showed).
		$by_query = array();
		foreach ( $rows as $row ) {
			$url = isset( $row['page_url'] ) ? (string) $row['page_url'] : '';
			if ( '' === $url ) {
				continue; // Site-wide rows describe the same traffic without a page.
			}
			$query = strtolower( trim( isset( $row['query'] ) ? (string) $row['query'] : '' ) );
			if ( '' === $query || Opportunities::is_operator_query( $query ) ) {
				continue;
			}
			$id = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
			if ( ( $id > 0 && in_array( $id, $set_aside, true ) )
				|| in_array( Pages::key( $url ), $set_aside_urls, true ) ) {
				continue; // A page set apart on purpose stops being counted against a search.
			}

			$key = Pages::key( $url );
			if ( ! isset( $by_query[ $query ][ $key ] ) ) {
				$by_query[ $query ][ $key ] = array(
					'url'         => $url,
					'page_id'     => $id,
					'clicks'      => 0,
					'impressions' => 0,
					'pos_weight'  => 0.0,
				);
			}
			$c = (int) ( isset( $row['clicks'] ) ? $row['clicks'] : 0 );
			$i = (int) ( isset( $row['impressions'] ) ? $row['impressions'] : 0 );
			$p = (float) ( isset( $row['position'] ) ? $row['position'] : 0.0 );

			$by_query[ $query ][ $key ]['clicks']      += $c;
			$by_query[ $query ][ $key ]['impressions'] += $i;
			$by_query[ $query ][ $key ]['pos_weight']  += $p * $i;
			if ( $id > 0 && 0 === $by_query[ $query ][ $key ]['page_id'] ) {
				$by_query[ $query ][ $key ]['page_id'] = $id;
			}
		}

		// 2. Judge each query.
		$out = array();
		foreach ( $by_query as $query => $pages ) {
			if ( count( $pages ) < 2 ) {
				continue;
			}

			$shown = 0;
			foreach ( $pages as $page ) {
				$shown += $page['impressions'];
			}
			if ( $shown < self::MIN_SHOWN ) {
				continue;
			}

			// Only pages holding a real share compete; the rest are stray rows.
			$competing = array();
			foreach ( $pages as $page ) {
				$share = $page['impressions'] / $shown;
				if ( $share >= self::MIN_SHARE ) {
					$page['share']    = round( $share, 3 );
					$page['position'] = $page['impressions'] > 0 ? round( $page['pos_weight'] / $page['impressions'], 1 ) : 0.0;
					unset( $page['pos_weight'] );
					$competing[] = $page;
				}
			}
			if ( count( $competing ) < 2 ) {
				continue;
			}

			// One page already owning the query is a result, not a problem.
			$top_share = 0.0;
			foreach ( $competing as $page ) {
				$top_share = max( $top_share, $page['share'] );
			}
			if ( $top_share >= self::OWNED ) {
				continue;
			}

			// The winner earns the click: most clicks, then the better position.
			usort(
				$competing,
				static function ( $a, $b ) {
					if ( $a['clicks'] !== $b['clicks'] ) {
						return $b['clicks'] - $a['clicks'];
					}
					return $a['position'] <=> $b['position'];
				}
			);
			$clicks = 0;
			$best   = null;
			$worst  = null;
			foreach ( $competing as $k => $page ) {
				$competing[ $k ]['winner'] = 0 === $k;
				$clicks                   += $page['clicks'];
				$best                      = null === $best ? $page['position'] : min( $best, $page['position'] );
				$worst                     = null === $worst ? $page['position'] : max( $worst, $page['position'] );
			}

			$out[] = array(
				'query'  => $query,
				'shown'  => $shown,
				'clicks' => $clicks,
				'best'   => (float) $best,
				'worst'  => (float) $worst,
				'pages'  => $competing,
			);
		}

		// 3. Heaviest split first — the ranking IS the priority.
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['shown'] - $a['shown'];
			}
		);

		return $out;
	}

	/**
	 * One split search, flattened for the wire — titles resolved the way the
	 * opportunity cards resolve theirs, so both doors (the admin screen's REST
	 * and the Findings report) tell the same story in the same words.
	 *
	 * @param array $collision A {@see build()} row.
	 * @return array
	 */
	public static function wire( array $collision ) {
		return array(
			'query'  => (string) $collision['query'],
			'shown'  => (int) $collision['shown'],
			'clicks' => (int) $collision['clicks'],
			'best'   => (float) $collision['best'],
			'worst'  => (float) $collision['worst'],
			'pages'  => array_map(
				static function ( $page ) {
					$id = (int) $page['page_id'];
					return array(
						'title'       => $id > 0 ? html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) : (string) wp_parse_url( (string) $page['url'], PHP_URL_PATH ),
						'url'         => (string) $page['url'],
						'postId'      => $id,
						'editUrl'     => $id > 0 ? (string) get_edit_post_link( $id, 'raw' ) : '',
						'clicks'      => (int) $page['clicks'],
						'impressions' => (int) $page['impressions'],
						'position'    => (float) $page['position'],
						'share'       => (float) $page['share'],
						'winner'      => ! empty( $page['winner'] ),
					);
				},
				$collision['pages']
			),
		);
	}
}
