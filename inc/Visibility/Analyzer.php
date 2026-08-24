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
	 * Analyze one answer for a single tracked product: was its name mentioned, was
	 * its website cited, where does it rank against its competitors, and which of
	 * those competitors showed up.
	 *
	 * @param array    $result      Provider result { text, citations, error }; each
	 *                              citation is a { url, label } record or a bare URL.
	 * @param string   $name        The product/brand name to look for.
	 * @param string   $domain      The product's bare host (for citation detection).
	 * @param string[] $competitors The product's competitor names.
	 * @return array { mentioned, cited, position, competitors }
	 */
	public static function analyze( array $result, $name, $domain, array $competitors ) {
		$text = (string) ( $result['text'] ?? '' );

		$name_present   = self::contains( $text, $name );
		$domain_in_text = self::contains( $text, $domain );
		$domain_cited   = $domain_in_text || self::domain_in_citations( $domain, (array) ( $result['citations'] ?? array() ) );

		$mentioned = $name_present || $domain_in_text;

		$found_competitors = array();
		foreach ( $competitors as $c ) {
			if ( self::contains( $text, $c ) ) {
				$found_competitors[] = $c;
			}
		}

		return array(
			'mentioned'   => $mentioned,
			'cited'       => $domain_cited,
			'position'    => self::position( $text, $name, $competitors, $mentioned ),
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
	 * Whether the site's domain appears among the answer's cited sources.
	 *
	 * ⭐ Every host a source could stand for is tested, not just its URL's:
	 * a grounded Gemini citation's URL is Google's redirector on every single
	 * chunk, and the site that was actually read is named only in the label. A
	 * URL-only test could never match this site there, which made "0% linked"
	 * an outcome of the code rather than a reading of the answer.
	 *
	 * @param string $domain    Bare host.
	 * @param array  $citations Cited sources (URL strings and/or { url, label } records).
	 * @return bool
	 */
	private static function domain_in_citations( $domain, array $citations ) {
		$domain = trim( strtolower( (string) $domain ) );
		if ( '' === $domain ) {
			return false;
		}
		$domain = preg_replace( '~^www\.~', '', $domain );
		$suffix = '.' . $domain;
		foreach ( $citations as $citation ) {
			foreach ( Sources::hosts( $citation ) as $host ) {
				// The exact host or a subdomain of it — never a look-alike. A bare
				// substring test would count "notmyexample.com" or "myexample.com.evil.net"
				// as citing "myexample.com".
				if ( $host === $domain || ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) ) {
					return true;
				}
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
