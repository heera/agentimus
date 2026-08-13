<?php
/**
 * Title Case for every title the plugin generates — the standing rule.
 *
 * His law (2026-08-13): wherever Agentimus crafts or proposes a title — the
 * Writing Assistant's outlines and drafts today, any surface added later —
 * the title is Title Case. Enforced belt and braces: the prompts ask the
 * model for it, and this normalizer guarantees it on the way in.
 *
 * The boundary the law itself draws: a title an AGENT or the OWNER supplies
 * as content (an MCP create-content payload, hand-typed text) is THEIR text
 * and is never run through this.
 *
 * What "Title Case" means here, in order of precedence:
 *   - A word already carrying ANY uppercase keeps its own spelling — PHP,
 *     WordPress, McDonald's, iPhone are not ours to re-case.
 *   - A word with an internal dot or a leading @/# is a name, a file or a
 *     handle (llms.txt, heera.it, @heera) — left exactly as written.
 *   - Small words (articles, conjunctions, short prepositions) stay lower —
 *     except as the first word, the last word, or the first after a colon
 *     or a dash, where every style guide raises them.
 *   - Everything else gets its first letter raised, on both sides of a
 *     hyphen (Well-Being).
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Titles {

	/** The words that stay small mid-title. */
	const SMALL = array( 'a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'so', 'yet', 'as', 'at', 'by', 'in', 'of', 'off', 'on', 'per', 'to', 'up', 'via', 'vs', 'vs.' );

	/**
	 * A generated title, in Title Case.
	 *
	 * @param string $title The title as the model (or a template) produced it.
	 * @return string
	 */
	public static function case( $title ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return '';
		}

		$words    = preg_split( '/\s+/u', $title );
		$last     = count( $words ) - 1;
		$boundary = true; // The title's start; flips on after a colon or dash.

		foreach ( $words as $i => $word ) {
			$bare  = mb_strtolower( (string) preg_replace( '/[^\p{L}\p{N}]/u', '', $word ) );
			$small = in_array( $bare, self::SMALL, true );
			$force = $boundary || $i === $last;

			if ( $small && ! $force && preg_match( '/^\p{Lu}\p{Ll}*$/u', $word ) ) {
				// An over-capitalized small word ("Of", "The") mid-title comes
				// back down. Only the plain Ucfirst form — "OF" in an acronym's
				// clothes is not ours to judge.
				$words[ $i ] = mb_strtolower( $word );
			} elseif ( $force || ! $small ) {
				$words[ $i ] = self::cap( $word );
			}

			$boundary = (bool) preg_match( '/[:\x{2014}\x{2013}-]$/u', $word );
		}

		return implode( ' ', $words );
	}

	/**
	 * One word, first letter raised — unless the word's own spelling outranks us.
	 *
	 * @param string $word The word.
	 * @return string
	 */
	private static function cap( $word ) {
		if ( preg_match( '/\p{Lu}/u', $word ) ) {
			return $word; // Its own capitals stand.
		}
		if ( preg_match( '/\p{L}\.\p{L}|^[@#]/u', $word ) ) {
			return $word; // A file, a domain, a handle.
		}
		return (string) preg_replace_callback(
			'/(?:^|-)\p{Ll}/u',
			static function ( $m ) {
				return mb_strtoupper( $m[0] );
			},
			$word
		);
	}
}
