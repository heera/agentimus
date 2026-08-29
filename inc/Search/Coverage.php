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

	/**
	 * ⛔⛔ NOT A READING OF THE PAGE — the search itself could not be read.
	 *
	 * {@see terms()} extracts `[a-z0-9._-]`, so a search written in Cyrillic,
	 * Greek, Arabic, Hebrew, Devanagari or any CJK script yields NOTHING to look
	 * for. That case used to return {@see MISSING}, and the difference is not
	 * academic: on a site in any of those languages every page carrying a
	 * reported search was told "None of it is on the page — this may not be what
	 * the post is for", was counted as needing work, and could never be fixed by
	 * writing anything. Measured 2026-08-25: a page that answers its search
	 * perfectly scored MISSING in Russian, Chinese, Japanese, Arabic, Greek and
	 * Hindi, and ANSWERED in English.
	 *
	 * ⭐ THE LAW IT BROKE is the one 1.45.0 already wrote down: an UNKNOWN must
	 * never print as a MEASUREMENT. Nothing was compared here, so nothing may be
	 * claimed — least of all the worst available verdict.
	 *
	 * ⚠️ Also the honest answer when a search holds only common or two-letter
	 * words, and when the `agentimus_coverage` filter returns something this
	 * class cannot use. Every path that produces no comparison ends here.
	 */
	const UNREADABLE = 'unreadable';

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
	 * A fingerprint of the rules THIS class judges by.
	 *
	 * ⛔⛔ THE LAW 1.46.0 WROTE DOWN: a change to what a check SAYS must move
	 * whatever key decides "has this row been re-read?" — correct code that
	 * nothing is re-read under is indistinguishable from code never written.
	 * {@see \Agentimus\Grades} stores ONE `ruleset` per row, and that row holds a
	 * coverage verdict as well as the content checks, so this half has to be in
	 * it. Without this, the sites hurt worst by {@see UNREADABLE} — every page on
	 * every non-Latin-script install, told its writing answers nothing — would
	 * keep their stored accusation until somebody re-saved each post by hand.
	 *
	 * ⚠️ The stopword list is FILTERED, not the constant: dropping a word from it
	 * changes what "answered" means, and {@see \Agentimus\PageCheck::ruleset()}
	 * folds its own filter in for exactly the same reason. ⛔ It must be stable
	 * between requests — a value that moved on every call would re-grade for ever.
	 *
	 * @return string 8 hex characters.
	 */
	public static function ruleset() {
		static $cache = array();

		$stop = (array) apply_filters( 'agentimus_coverage_stopwords', self::STOPWORDS );
		sort( $stop );
		$key = implode( ',', $stop );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		// The states and thresholds, name and value, in a stable order — the same
		// whole-constants sweep PageCheck uses, and for the same reason: a new one
		// is far likelier to change a verdict than not.
		$constants = ( new \ReflectionClass( __CLASS__ ) )->getConstants();
		ksort( $constants );
		$parts = array();
		foreach ( $constants as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$parts[] = $name . '=' . ( is_bool( $value ) ? (int) $value : (string) $value );
			}
		}

		$cache[ $key ] = substr( md5( implode( ',', $parts ) . '|' . $key ), 0, 8 );
		return $cache[ $key ];
	}

	/**
	 * Is this state a READING of a page, rather than a note about why there was
	 * no reading?
	 *
	 * ⛔ THE ONE PLACE THAT DECIDES IT. Every surface asking "does this page
	 * answer its search?" has to tell three things apart: a verdict, no verdict
	 * yet ('' — nothing has been measured), and a search we could not read
	 * ({@see UNREADABLE}). Only the first may ever be shown as an answer or
	 * counted as work, and a caller writing its own `!== ANSWERED` test gets
	 * the last two wrong in the direction that accuses the owner.
	 *
	 * @param string $state A stored or measured coverage state.
	 * @return bool
	 */
	public static function is_measured( $state ) {
		$state = (string) $state;
		return '' !== $state && self::UNREADABLE !== $state;
	}

	/**
	 * Measure one search against one page.
	 *
	 * @param string $html  The page's rendered HTML.
	 * @param string $title The page's title (a match here is worth saying out loud).
	 * @param string $query The search, as the engine reported it.
	 * @return array{state:string,words:int,in_passage:int,on_page:int,in_title:int,heading:string,quote:string}
	 */
	public static function measure( $html, $title, $query ) {
		// ⛔ The fallback verdict is UNREADABLE, not MISSING. Every `return $empty`
		// below is a path where nothing was compared, and the old default turned
		// each of them into a claim about the page {@see UNREADABLE}.
		$empty = array(
			'state'      => self::UNREADABLE,
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
			return $empty; // Nothing in the search this class can look for.
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
		// ASCII-only lowercasing, deliberately. strtolower() asks the C library
		// byte by byte, and on BSD-family hosts a UTF-8 locale maps bytes inside
		// multi-byte letters — leaving invalid UTF-8 that the /u split below
		// refuses, with a PHP warning per call. Nothing beyond a-z survives the
		// split anyway, so ASCII case is the only case there is.
		$text = strtr(
			html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ),
			'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
			'abcdefghijklmnopqrstuvwxyz'
		);

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

	/**
	 * The verdict in words: what this state is called, and WHY it came out that
	 * way for this particular search.
	 *
	 * ⭐ ONE OWNER FOR THE SENTENCE. These four readings were written inline in
	 * {@see \Agentimus\Focus::render_verdict()}, which is a rendering method — so
	 * the owner's editor panel could explain a verdict ("3 of 5 words appear
	 * anywhere on the page") while every other surface had nothing but the state
	 * word to repeat. An agent handed "barely" and no reason cannot act on it,
	 * and a second surface writing its own phrasing for the same measurement is
	 * how two screens start disagreeing about one number. The words live with
	 * the measurement that produced them; the renderer keeps only its own
	 * business — tone and mark.
	 *
	 * ⚠️ UNRECOGNISED STATES ARE INVITED HERE. {@see measure()} is filterable on
	 * purpose — its docblock offers `agentimus_coverage` to anyone replacing this
	 * with a semantic model or a language this stemmer does not fit — so a state
	 * word this class never named is a scenario, not a hypothetical. Indexing a
	 * map blind used to fatal the whole editor screen.
	 *
	 * ⭐ And the answer is the one {@see \Agentimus\PageCheck::issue_label()}
	 * already settled for the same question: NAME IT WITH WHAT YOU HAVE. A state
	 * we cannot explain still gets its own word as the label, and an empty
	 * reason — because silence reads as "no verdict", and the raw word at least
	 * names something the owner can go and look up. Only a verdict with no state
	 * at all says nothing, because there is nothing to say.
	 *
	 * @param array $cover A {@see measure()} verdict.
	 * @return array{label:string,why:string} `why` is empty for a state we cannot read; both are empty when there is no state.
	 */
	public static function explain( array $cover ) {
		$state = isset( $cover['state'] ) ? (string) $cover['state'] : '';
		$words = isset( $cover['words'] ) ? (int) $cover['words'] : 0;

		switch ( $state ) {
			case self::ANSWERED:
				$heading = isset( $cover['heading'] ) ? (string) $cover['heading'] : '';
				return array(
					'label' => __( 'Answered', 'agentimus' ),
					'why'   => '' !== $heading
						? sprintf(
							/* translators: %s: the heading above the passage that answers. */
							__( 'One passage carries it, under “%s”.', 'agentimus' ),
							$heading
						)
						: __( 'One passage carries the whole search.', 'agentimus' ),
				);
			case self::SCATTERED:
				return array(
					'label' => __( 'Scattered', 'agentimus' ),
					'why'   => sprintf(
						/* translators: %d: how many words the search has. */
						__( 'All %d words are on the page, but never together in one passage.', 'agentimus' ),
						$words
					),
				);
			case self::BARELY:
				return array(
					'label' => __( 'Barely', 'agentimus' ),
					'why'   => sprintf(
						/* translators: 1: words found, 2: words in the search. */
						__( '%1$d of %2$d words appear anywhere on the page.', 'agentimus' ),
						isset( $cover['on_page'] ) ? (int) $cover['on_page'] : 0,
						$words
					),
				);
			case self::MISSING:
				return array(
					'label' => __( 'Missing', 'agentimus' ),
					'why'   => __( 'None of it is on the page — this may not be what the post is for.', 'agentimus' ),
				);
			case self::UNREADABLE:
				// ⭐ NAMES ITS OWN LIMIT. An owner writing in Greek or Japanese is
				// owed the reason their pages are not being judged, not silence —
				// and certainly not a verdict about writing nobody compared.
				return array(
					'label' => __( 'Not measured', 'agentimus' ),
					'why'   => __( 'This search gives the check no word it can look for, so the page has not been judged on it. It reads words written with Latin letters and numbers.', 'agentimus' ),
				);
		}

		// Named with its own word, never with another verdict's sentence.
		return array( 'label' => $state, 'why' => '' );
	}

}
