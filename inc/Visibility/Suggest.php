<?php
/**
 * Prompt suggestions for AI-Visibility (Phase 1 · T4). Turns the site's own signals
 * — its brand/identity name, the Topics suggestion pool (used topics + tags/categories
 * + declared expertise), and any competitors already listed — into natural tracking
 * questions the owner can one-click add. Suggestions are always opt-in: the owner
 * curates which to keep, so a slightly-awkward candidate costs nothing.
 *
 * Pure given its inputs, so the phrasing and de-duplication are unit-testable; the
 * for_product() wrapper wires the real sources.
 *
 * @package Agentimus\Visibility
 */

namespace Agentimus\Visibility;

use Agentimus\Settings as CoreSettings;
use Agentimus\Topics;

defined( 'ABSPATH' ) || exit;

final class Suggest {

	/** Most candidate questions to offer at once. */
	const MAX = 10;

	/**
	 * Build candidate questions from explicit inputs, de-duplicated against each other
	 * and the prompts already tracked. Pure.
	 *
	 * @param string             $brand       The name to ask about.
	 * @param array<int,string>  $topics      Candidate topics (most relevant first).
	 * @param array<int,string>  $competitors Competitor names (for "X vs Y").
	 * @param array<int,string>  $existing    Prompts already tracked — never re-suggested.
	 * @return array<int,string> Up to {@see MAX} question strings.
	 */
	public static function questions( $brand, array $topics, array $competitors = array(), array $existing = array() ) {
		$brand       = trim( (string) $brand );
		$topics      = self::clean( $topics );
		$competitors = self::clean( $competitors );

		$candidates = array();
		if ( '' !== $brand ) {
			$candidates[] = sprintf( 'What is %s?', $brand );
		}
		foreach ( array_slice( $topics, 0, 5 ) as $t ) {
			$candidates[] = sprintf( 'What is the best %s?', $t );
		}
		if ( '' !== $brand ) {
			foreach ( array_slice( $competitors, 0, 3 ) as $c ) {
				$candidates[] = sprintf( '%s vs %s', $brand, $c );
			}
		}
		foreach ( array_slice( $topics, 0, 3 ) as $t ) {
			$candidates[] = sprintf( 'How do I choose %s?', $t );
		}

		// De-dupe (case-insensitive) against the already-tracked prompts and each other.
		$seen = array();
		foreach ( $existing as $e ) {
			$seen[ self::norm( $e ) ] = true;
		}
		$out = array();
		foreach ( $candidates as $q ) {
			$key = self::norm( $q );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $q;
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Suggestions for one tracked product, wiring the real sources: its own name (or
	 * the site's identity name), the Topics pool, its competitors, and — excluded —
	 * the prompts it already tracks.
	 *
	 * @param array         $product A visibility product row { name, competitors, prompts }.
	 * @param CoreSettings  $core    Core settings (identity). Instantiated if omitted.
	 * @return array<int,string>
	 */
	public static function for_product( array $product, CoreSettings $core = null ) {
		$core  = $core instanceof CoreSettings ? $core : new CoreSettings();
		$named = isset( $product['name'] ) ? trim( (string) $product['name'] ) : '';
		$brand = '' !== $named ? $named : trim( (string) $core->identity( 'name', get_bloginfo( 'name' ) ) );

		return self::questions(
			$brand,
			class_exists( Topics::class ) ? (array) Topics::suggestions() : array(),
			isset( $product['competitors'] ) ? (array) $product['competitors'] : array(),
			isset( $product['prompts'] ) ? (array) $product['prompts'] : array()
		);
	}

	/** Trim, drop empties, de-dupe (case-insensitive) — order preserved. Pure. */
	private static function clean( array $list ) {
		$seen = array();
		$out  = array();
		foreach ( $list as $v ) {
			$v = trim( (string) $v );
			$k = self::norm( $v );
			if ( '' === $k || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = $v;
		}
		return $out;
	}

	/** A comparison key: lowercased, trimmed, trailing punctuation dropped. Pure. */
	private static function norm( $s ) {
		return rtrim( strtolower( trim( (string) $s ) ), " ?.!" );
	}
}
