<?php
/**
 * FAQ extraction — pulls question/answer pairs out of rendered post HTML so the
 * schema layer can publish a FAQPage. (Google deprecated FAQ rich results in
 * 2023 for most sites, but LLM agents still read FAQPage to lift clean Q&A.)
 *
 * Two deliberate, low-false-positive signals — we do NOT scrape every heading:
 *   1. `<details><summary>Question</summary> answer… </details>` disclosure
 *      blocks (the native WordPress "Details" block — an intentional FAQ/accordion).
 *   2. A heading (h2–h4) whose text ends in "?", with the sibling content up to the
 *      next heading as its answer.
 *
 * Pure and WP-free: it takes HTML and returns pairs, so the caller renders the
 * content (do_blocks) and the schema layer decides whether there are enough pairs
 * to publish. The two-signal floor lives in the caller, not here.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Faq {

	/** Max characters kept per answer — bounds the JSON-LD payload. */
	const ANSWER_MAX = 1200;

	/**
	 * Extract FAQ pairs from rendered HTML, in document order, de-duplicated by
	 * question. Returns `[ ['q' => …, 'a' => …], … ]` (empty when nothing matches
	 * or DOM parsing is unavailable).
	 *
	 * @param string $html Rendered post content.
	 * @return array<int,array{q:string,a:string}>
	 */
	public static function extract( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) || ! class_exists( '\DOMDocument' ) ) {
			return array();
		}

		$dom  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true ); // Swallow malformed-HTML warnings.
		// Wrap in a div + force UTF-8 so loadHTML doesn't mangle entities or hoist nodes.
		$dom->loadHTML( '<?xml encoding="UTF-8"><div id="agentimus-faq-root">' . $html . '</div>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$pairs = array();

		// 1. Disclosure blocks: <details><summary>Q</summary> A </details>.
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'details' ) ) as $details ) {
			$summary = null;
			foreach ( $details->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType && 'summary' === strtolower( $child->nodeName ) ) {
					$summary = $child;
					break;
				}
			}
			if ( null === $summary ) {
				continue;
			}
			$q = self::norm( $summary->textContent );
			$a = '';
			foreach ( $details->childNodes as $child ) {
				if ( $child !== $summary ) {
					$a .= ' ' . $child->textContent;
				}
			}
			self::push( $pairs, $q, $a );
		}

		// 2. Heading-question → following sibling content. Walk the wrapper's direct
		//    children in order: open a question on an h2–h4 ending in "?", and gather
		//    everything until the next heading as its answer. Skip <details> here so
		//    a preceding heading-question doesn't absorb a disclosure block's text.
		$root = $dom->getElementById( 'agentimus-faq-root' );
		if ( $root ) {
			$open   = '';
			$buffer = '';
			foreach ( $root->childNodes as $node ) {
				$is_el = XML_ELEMENT_NODE === $node->nodeType;
				if ( $is_el && preg_match( '/^h[2-4]$/i', $node->nodeName ) ) {
					self::push( $pairs, $open, $buffer );
					$text   = self::norm( $node->textContent );
					$open   = self::is_question( $text ) ? $text : '';
					$buffer = '';
					continue;
				}
				if ( $is_el && 'details' === strtolower( $node->nodeName ) ) {
					continue;
				}
				if ( '' !== $open ) {
					$buffer .= ' ' . $node->textContent;
				}
			}
			self::push( $pairs, $open, $buffer );
		}

		return array_values( $pairs );
	}

	/**
	 * Add a pair if both sides are non-empty and the question is new (case-insensitive),
	 * clipping the answer. Keyed by normalized question so the two signals don't double up.
	 *
	 * @param array  $pairs Accumulator, keyed by lowercased question.
	 * @param string $q     Question text.
	 * @param string $a     Raw answer text.
	 */
	private static function push( array &$pairs, $q, $a ) {
		$q = self::norm( $q );
		$a = self::clip( self::norm( $a ) );
		if ( '' === $q || '' === $a ) {
			return;
		}
		$key = strtolower( $q );
		if ( ! isset( $pairs[ $key ] ) ) {
			$pairs[ $key ] = array( 'q' => $q, 'a' => $a );
		}
	}

	/**
	 * Whether a heading reads as a question: ends in "?" and is short enough to be
	 * one (a long paragraph that happens to end in "?" is prose, not an FAQ heading).
	 *
	 * @param string $text Heading text.
	 * @return bool
	 */
	private static function is_question( $text ) {
		return '' !== $text && '?' === substr( $text, -1 ) && mb_strlen( $text ) <= 200;
	}

	/**
	 * Collapse whitespace and trim — textContent already strips tags.
	 *
	 * @param string $s Raw text.
	 * @return string
	 */
	private static function norm( $s ) {
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $s ) );
	}

	/**
	 * Bound an answer to ANSWER_MAX characters at a sensible cut.
	 *
	 * @param string $s Normalized answer.
	 * @return string
	 */
	private static function clip( $s ) {
		if ( mb_strlen( $s ) <= self::ANSWER_MAX ) {
			return $s;
		}
		return rtrim( mb_substr( $s, 0, self::ANSWER_MAX ) ) . '…';
	}
}
