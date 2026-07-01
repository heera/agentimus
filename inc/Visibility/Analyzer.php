<?php
/**
 * Turns one provider answer into the visibility signals we store: was the brand
 * mentioned, was the site cited, where did the brand rank against competitors,
 * and which competitors showed up.
 *
 * Detection is deliberately simple and transparent (case-insensitive substring +
 * host matching), so a result is explainable — no opaque scoring. It's a floor,
 * not a courtroom: a mention is "the name or domain appears in the answer."
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

defined( 'ABSPATH' ) || exit;

final class Analyzer {

	/**
	 * @param array    $result      Provider result { text, citations, error }.
	 * @param string   $brand       Brand/name to look for.
	 * @param string   $domain      Bare site host (for citation detection).
	 * @param string[] $competitors Competitor names to look for.
	 * @return array { mentioned, cited, position, competitors }
	 */
	public static function analyze( array $result, $brand, $domain, array $competitors ) {
		$text = (string) ( $result['text'] ?? '' );

		$brand_present  = self::contains( $text, $brand );
		$domain_in_text = self::contains( $text, $domain );
		$domain_cited   = $domain_in_text || self::domain_in_citations( $domain, (array) ( $result['citations'] ?? array() ) );

		$mentioned = $brand_present || $domain_in_text;

		$found_competitors = array();
		foreach ( $competitors as $c ) {
			if ( self::contains( $text, $c ) ) {
				$found_competitors[] = $c;
			}
		}

		return array(
			'mentioned'   => $mentioned,
			'cited'       => $domain_cited,
			'position'    => self::position( $text, $brand, $competitors, $mentioned ),
			'competitors' => $found_competitors,
		);
	}

	/**
	 * Case-insensitive substring test that ignores empty needles.
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Term.
	 * @return bool
	 */
	private static function contains( $haystack, $needle ) {
		$needle = trim( (string) $needle );
		if ( '' === $needle || '' === $haystack ) {
			return false;
		}
		return false !== stripos( $haystack, $needle );
	}

	/**
	 * Whether the site's domain appears among the answer's cited source URLs.
	 *
	 * @param string   $domain    Bare host.
	 * @param string[] $citations Source URLs.
	 * @return bool
	 */
	private static function domain_in_citations( $domain, array $citations ) {
		$domain = trim( strtolower( (string) $domain ) );
		if ( '' === $domain ) {
			return false;
		}
		foreach ( $citations as $url ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );
			$host = is_string( $host ) ? strtolower( $host ) : (string) $url;
			if ( false !== strpos( $host, $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The brand's 1-based rank among the terms that appear, ordered by where each
	 * first occurs in the answer. 1 means the brand is named before any competitor;
	 * 0 means the brand isn't mentioned at all.
	 *
	 * @param string   $text        Answer.
	 * @param string   $brand       Brand term.
	 * @param string[] $competitors Competitor terms.
	 * @param bool     $mentioned   Whether the brand was detected.
	 * @return int
	 */
	private static function position( $text, $brand, array $competitors, $mentioned ) {
		if ( ! $mentioned ) {
			return 0;
		}

		$positions = array();
		$brand_at  = self::first_index( $text, $brand );
		if ( $brand_at >= 0 ) {
			$positions[] = $brand_at;
		}
		foreach ( $competitors as $c ) {
			$at = self::first_index( $text, $c );
			if ( $at >= 0 ) {
				$positions[] = $at;
			}
		}

		if ( empty( $positions ) ) {
			return 1;
		}
		sort( $positions );
		// If the brand wasn't found by name (matched only via domain), treat it as
		// present but unranked among named competitors — report last place + 1.
		if ( $brand_at < 0 ) {
			return count( $positions ) + 1;
		}
		return array_search( $brand_at, $positions, true ) + 1;
	}

	/**
	 * First case-insensitive index of a term, or -1.
	 *
	 * @param string $text Text.
	 * @param string $term Term.
	 * @return int
	 */
	private static function first_index( $text, $term ) {
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return -1;
		}
		$at = stripos( $text, $term );
		return false === $at ? -1 : (int) $at;
	}
}
