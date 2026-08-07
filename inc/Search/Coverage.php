<?php
/**
 * Coverage — does one passage of this page actually answer the search people
 * used to reach it?
 *
 * Counting a search's words anywhere on a page rewards a mention in a footnote:
 * a page that says "crawler" in the intro and "blocking" in a caption scores
 * full marks for "ai crawler blocking" while answering nothing. That is the
 * mistake every keyword-density tool makes, and it is why a page can rank at #9
 * for years without anyone being able to say what to fix.
 *
 * So this asks the question an answer engine asks: is there ONE passage that
 * carries the whole search? Four outcomes, and the middle one is the useful one:
 *
 *   answered   one passage carries every meaningful word — and we name the
 *              heading above it, so the owner can go and read it
 *   scattered  every word is on the page, but never together. The page mentions
 *              the subject; it doesn't answer the question
 *   barely     some words appear, most don't — usually a page ranking by accident
 *   missing    none of it is here. Either the page isn't for this search, or
 *              it's an open opportunity
 *
 * Pure over strings: no post, no database, no network. That keeps it testable
 * and keeps the rule in one place — the editor panel and the worklist must never
 * disagree about the same page.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

defined( 'ABSPATH' ) || exit;

final class Coverage {

	/** One passage carries every meaningful word of the search. */
	const ANSWERED = 'answered';

	/** All the words are on the page, but no single passage holds them together. */
	const SCATTERED = 'scattered';

	/** Some of the words appear; most don't. */
	const BARELY = 'barely';

	/** None of the search is on the page. */
	const MISSING = 'missing';

	/** Shortest word taken seriously. Below this it is noise in every language we can check. */
	const MIN_WORD = 3;

	/** Characters of the winning passage kept as the quote shown back to the owner. */
	const QUOTE_LEN = 160;

	/** Passages parsed per page. A cap, not a judgement — long pages still measure. */
	const MAX_PASSAGES = 400;

	/**
	 * Words too common to carry meaning in a search. Deliberately short: every
	 * word dropped here is one the page no longer has to contain, so an
	 * over-long list would hand out "answered" for free.
	 *
	 * @var string[]
	 */
	const STOPWORDS = array(
		'the', 'and', 'for', 'how', 'what', 'why', 'who', 'are', 'was', 'were',
		'with', 'that', 'this', 'you', 'your', 'can', 'does', 'did', 'from',
		'not', 'but', 'all', 'out', 'get', 'use', 'using', 'when', 'into',
		'its', 'has', 'have', 'will', 'about', 'best', 'top',
	);

	/**
	 * Measure one search against one page.
	 *
	 * @param string $html  The page's rendered HTML.
	 * @param string $title The page's title (a match here is worth saying out loud).
	 * @param string $query The search, as the engine reported it.
	 * @return array{state:string,words:int,in_passage:int,on_page:int,in_title:int,heading:string,quote:string}
	 */
	public static function measure( $html, $title, $query ) {
		$empty = array(
			'state'      => self::MISSING,
			'words'      => 0,
			'in_passage' => 0,
			'on_page'    => 0,
			'in_title'   => 0,
			'heading'    => '',
			'quote'      => '',
			'terms'      => array(),
		);

		$terms  = self::terms( $query );
		$wanted = array_column( $terms, 'stem' );
		$total  = count( $wanted );
		if ( ! $total ) {
			return $empty; // Nothing meaningful was searched for.
		}
		$empty['words'] = $total;

		$best       = array( 'hit' => 0, 'heading' => '', 'text' => '' );
		$best_words = array();
		$anywhere   = array();

		foreach ( self::passages( $html ) as $passage ) {
			$have = self::words( $passage['heading'] . ' ' . $passage['text'] );
			$hit  = array_intersect( $wanted, $have );
			if ( $hit ) {
				$anywhere = array_unique( array_merge( $anywhere, $hit ) );
			}
			if ( count( $hit ) > $best['hit'] ) {
				$best       = array(
					'hit'     => count( $hit ),
					'heading' => $passage['heading'],
					'text'    => $passage['text'],
				);
				$best_words = array_values( $hit );
			}
		}

		$on_page = count( $anywhere );
		if ( $best['hit'] >= $total ) {
			$state = self::ANSWERED;
		} elseif ( $on_page >= $total ) {
			$state = self::SCATTERED;
		} elseif ( $on_page > 0 ) {
			$state = self::BARELY;
		} else {
			$state = self::MISSING;
		}

		$out = array(
			'state'      => $state,
			'words'      => $total,
			'in_passage' => (int) $best['hit'],
			'on_page'    => $on_page,
			'in_title'   => count( array_intersect( $wanted, self::words( $title ) ) ),
			// Only worth naming when the passage actually answered — pointing at
			// the best of a bad set would send the owner to the wrong paragraph.
			'heading'    => self::ANSWERED === $state ? (string) $best['heading'] : '',
			'quote'      => self::ANSWERED === $state ? self::clip( $best['text'] ) : '',
			// Word by word, in the searcher's own spelling. A count says "none of
			// the 3 words appear"; this says WHICH — and on a page that half
			// matches, which one to go and write about.
			'terms'      => array_map(
				static function ( $t ) use ( $anywhere, $best_words ) {
					return array(
						'word'       => $t['word'],
						'on_page'    => in_array( $t['stem'], $anywhere, true ),
						'in_passage' => in_array( $t['stem'], $best_words, true ),
					);
				},
				$terms
			),
		);

		/**
		 * The coverage verdict for one search against one page. Replace the
		 * measurement wholesale (a semantic model, a language this stemmer does
		 * not fit) without touching the surfaces that render it.
		 *
		 * @param array  $out   The verdict.
		 * @param string $query The search measured.
		 * @param string $html  The page's rendered HTML.
		 */
		$out = apply_filters( 'agentimus_coverage', $out, $query, $html );
		return is_array( $out ) ? $out : $empty;
	}

	/**
	 * The meaningful words of a string: lowercased, stopwords dropped, plurals
	 * and gerunds folded, deduplicated. Order is not kept — a search's words
	 * answering in a different order is still an answer.
	 *
	 * @param string $text Any text.
	 * @return string[]
	 */
	public static function words( $text ) {
		return array_values( array_unique( array_column( self::terms( $text ), 'stem' ) ) );
	}

	/**
	 * The same extraction, keeping the word the SEARCHER typed alongside its
	 * stem — so a surface can show "llms.txt" rather than whatever the stemmer
	 * folded it to. {@see words()} is this, deduplicated to stems.
	 *
	 * @param string $text Any text.
	 * @return array<int,array{word:string,stem:string}>
	 */
	public static function terms( $text ) {
		$text = strtolower( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );

		/**
		 * Words treated as carrying no meaning of their own.
		 *
		 * @param string[] $stopwords The default list.
		 */
		$stop = (array) apply_filters( 'agentimus_coverage_stopwords', self::STOPWORDS );

		$out  = array();
		$seen = array();
		// Dots and hyphens survive the split: "llms.txt" and "json-ld" are single
		// terms, and breaking them would let a page match "txt" and call it a hit.
		foreach ( preg_split( '/[^a-z0-9._-]+/u', $text ) as $word ) {
			$word = trim( $word, '._-' );
			if ( strlen( $word ) < self::MIN_WORD || in_array( $word, $stop, true ) ) {
				continue;
			}
			$stem = self::stem( $word );
			if ( isset( $seen[ $stem ] ) ) {
				continue; // One entry per stem, keeping the FIRST spelling seen.
			}
			$seen[ $stem ] = true;
			$out[]         = array( 'word' => $word, 'stem' => $stem );
		}
		return $out;
	}

	/**
	 * Fold the endings that make one word look like two. Crude on purpose: a
	 * real stemmer would need a language, and this only has to stop "crawler"
	 * and "crawlers" counting as different answers.
	 *
	 * @param string $word A lowercased word.
	 * @return string
	 */
	public static function stem( $word ) {
		// Stripped repeatedly, to a FIXED POINT. One pass is not enough and the
		// failure is silent: "classes" loses its "es" and stops at "class", while
		// "class" loses its "s" and stops at "clas" — so the plural and the
		// singular fold to different words and a page answering one no longer
		// matches a search using the other. What this needs is not linguistic
		// accuracy (that would need a language) but SELF-CONSISTENCY: whatever
		// the rules mangle a word into, they must mangle its other forms into the
		// same thing. Iterating guarantees stem( stem( x ) ) === stem( x ).
		for ( $pass = 0; $pass < 4; $pass++ ) {
			$before = $word;
			foreach ( array( 'ing', 'ed', 'es', 's' ) as $suffix ) {
				$len = strlen( $suffix );
				// Keep a real root behind the ending, so "less" doesn't become "l".
				if ( strlen( $word ) > $len + 3 && substr( $word, -$len ) === $suffix ) {
					$word = substr( $word, 0, -$len );
					break;
				}
			}
			if ( $word === $before ) {
				break;
			}
		}
		return $word;
	}

	/**
	 * Split rendered HTML into the units a reader actually reads — paragraphs
	 * and list items — each tagged with the heading it sits under.
	 *
	 * The heading counts as part of its passages: "How to block GPTBot" above a
	 * paragraph explaining how is one answer, not two halves of one.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int,array{heading:string,text:string}>
	 */
	public static function passages( $html ) {
		$html = (string) $html;
		// Script and style bodies are not prose and would match on variable names.
		$html = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html );

		$parts = preg_split(
			'/(<h[1-6]\b[^>]*>.*?<\/h[1-6]>|<p\b[^>]*>.*?<\/p>|<li\b[^>]*>.*?<\/li>|<blockquote\b[^>]*>.*?<\/blockquote>|<figcaption\b[^>]*>.*?<\/figcaption>)/is',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		$passages = array();
		$heading  = '';
		foreach ( (array) $parts as $chunk ) {
			$chunk = trim( (string) $chunk );
			if ( '' === $chunk || '<' !== $chunk[0] ) {
				continue; // Text between the blocks we recognise; not a passage of its own.
			}
			$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $chunk ) ) );
			if ( '' === $text ) {
				continue;
			}
			if ( preg_match( '/^<h[1-6]\b/i', $chunk ) ) {
				$heading = $text; // Governs the passages below it, until the next one.
				continue;
			}
			$passages[] = array(
				'heading' => $heading,
				'text'    => $text,
			);
			if ( count( $passages ) >= self::MAX_PASSAGES ) {
				break;
			}
		}
		return $passages;
	}

	/**
	 * A short, whole-word excerpt of the passage that answered.
	 *
	 * @param string $text Passage text.
	 * @return string
	 */
	private static function clip( $text ) {
		$text = (string) $text;
		if ( mb_strlen( $text ) <= self::QUOTE_LEN ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, self::QUOTE_LEN );
		$space = mb_strrpos( $cut, ' ' );
		return ( false !== $space ? mb_substr( $cut, 0, $space ) : $cut ) . '…';
	}
}
