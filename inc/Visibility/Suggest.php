<?php
/**
 * Prompt suggestions for AI-Visibility. Turns what the owner told us about a tracked
 * product — its name, the CATEGORY it competes in, and its rivals — into the questions
 * a buyer would actually type into an AI assistant. Suggestions are opt-in: the owner
 * picks which to keep.
 *
 * The category is the load-bearing input, and it is deliberately NOT inferred from the
 * site's topics/tags/categories. That pool is EDITORIAL (what the site writes about);
 * these templates need a COMMERCIAL noun (what the product is). Feeding one to the other
 * produced questions like "What is the best JavaScript?" or "What is the best
 * Miscellaneous?" — which are worse than useless here, because a suggestion becomes a
 * TRACKED PROMPT that gets graded every run: a question the product could never
 * legitimately answer scores a permanent zero and reports as "AI never mentions you".
 * So: ask the owner what the product IS, and say nothing when we don't know.
 *
 * Two generators, same normalization:
 *  - {@see questions()} — templates. Pure, instant, free, no network. Always available.
 *  - {@see ai_questions()} — asks the site's own configured AI (WordPress 7.0's AI Client,
 *    the same provider-agnostic path {@see \Agentimus\Assist} uses, so no raw API key is
 *    ever touched) for unbranded buyer-intent questions. Optional; falls back to the
 *    templates when no AI is configured.
 *
 * Phrasing note: every template avoids the a/an problem by construction — "the right X",
 * "a good X" — so no article heuristic can mangle "a user-management plugin" into "an".
 *
 * @package Agentimus\Visibility
 */

namespace Agentimus\Visibility;

use Agentimus\Assist;
use Agentimus\Settings as CoreSettings;

defined( 'ABSPATH' ) || exit;

final class Suggest {

	/** Most candidate questions to offer at once. */
	const MAX = 10;

	/** Longest question kept — matches the per-prompt cap in {@see Settings::sanitize_targets()}. */
	const MAX_LENGTH = 300;

	/** How many questions to ask the AI for. A few more than {@see MAX} so duplicates can be dropped. */
	const AI_COUNT = 12;

	/** Output-token budget. Deliberately roomy, for the same reason as {@see Assist::OUTPUT_TOKENS}
	 * but more so: a reasoning model spends "thinking" tokens against this cap BEFORE it
	 * answers, and here the answer is a LIST — so a tight budget doesn't shorten each
	 * question, it silently drops most of them. Measured: at 700 a Gemini flash model
	 * returned 2 of 12. The list itself is only ~200 tokens; the rest is headroom. */
	const AI_TOKENS = 1600;

	/**
	 * Build candidate questions from explicit inputs, de-duplicated against each other
	 * and the prompts already tracked. Pure.
	 *
	 * With no category we can only ask about the brand itself ("What is X?", "X vs Y") —
	 * so that is all we offer. We never pad the list with guesses.
	 *
	 * @param string            $brand       The name to ask about.
	 * @param string            $category    What the product IS ("WordPress SEO plugin"). May be ''.
	 * @param array<int,string> $competitors Competitor names (for "X vs Y").
	 * @param array<int,string> $existing    Prompts already tracked — never re-suggested.
	 * @return array<int,string> Up to {@see MAX} question strings.
	 */
	public static function questions( $brand, $category = '', array $competitors = array(), array $existing = array() ) {
		$brand       = self::tidy( $brand );
		$category    = self::tidy( $category );
		$competitors = self::clean( $competitors );

		$candidates = array();

		if ( '' !== $brand ) {
			$candidates[] = sprintf( 'What is %s?', $brand );
		}

		// The questions worth grading: a buyer shopping the category, with no brand in the
		// question. If AI names the product here, that is real visibility.
		if ( '' !== $category ) {
			$candidates[] = sprintf( 'What is the best %s?', $category );
			$candidates[] = sprintf( 'How do I choose the right %s?', $category );
			$candidates[] = sprintf( 'Which %s do you recommend?', $category );
		}

		if ( '' !== $brand ) {
			foreach ( array_slice( $competitors, 0, 3 ) as $c ) {
				$candidates[] = sprintf( '%s vs %s', $brand, $c );
			}
			if ( '' !== $category ) {
				$candidates[] = sprintf( 'Is %s a good %s?', $brand, $category );
			}
		}

		return self::refine( $candidates, $existing );
	}

	/**
	 * Ask the site's own configured AI for buyer-intent questions in the product's
	 * category. Returns the same shape as {@see questions()}.
	 *
	 * Deliberately asks for UNBRANDED questions: a question containing the product's name
	 * is nearly guaranteed to name it back, which measures nothing. The signal is whether
	 * AI reaches for this product when the buyer never said its name.
	 *
	 * @param array        $product A visibility product row { name, category, domain, competitors, prompts }.
	 * @param CoreSettings $core    Core settings (identity). Instantiated if omitted.
	 * @return array<int,string>|\WP_Error Questions, or an error when no AI is configured.
	 */
	public static function ai_questions( array $product, ?CoreSettings $core = null ) {
		$core     = $core instanceof CoreSettings ? $core : new CoreSettings();
		$brand    = self::brand_of( $product, $core );
		$category = self::tidy( $product['category'] ?? '' );
		$existing = isset( $product['prompts'] ) ? (array) $product['prompts'] : array();

		// Validate the request before the environment: the category is required here for the
		// same reason the templates refuse without it — buyer questions only mean something
		// inside a market, and with no category the model would invent one from the site
		// title, which is exactly the "What is the best JavaScript?" failure this feature was
		// rewritten to end. Note the guard CANNOT lean on an empty brand: brand_of() falls
		// back to the site name, so the brand is essentially never empty on a real site.
		if ( '' === $category ) {
			return new \WP_Error(
				'agentimus_ai_no_category',
				__( 'Tell us what kind of thing this is (for example “WordPress SEO plugin”) and we can suggest the questions a buyer would ask.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		if ( ! Assist::ai_available() || ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error(
				'agentimus_ai_unavailable',
				__( 'No AI is set up on this site yet. Add a provider under Settings → AI, or use the template suggestions.', 'agentimus' ),
				array( 'status' => 503 )
			);
		}

		$system = 'You write the questions real people type into an AI assistant when they are shopping for a '
			. 'product like the one described. Reply with the questions ONLY, one per line — no numbering, no '
			. 'bullets, no preamble, no commentary. Each must be a natural, standalone question under 100 '
			. 'characters, and must end with a question mark. Vary the buying intent (best-of, how-to-choose, '
			. 'use-case, price, alternatives). Two hard rules: (1) NEVER put the product\'s own name in a '
			. 'question — the whole point is to see whether the assistant recommends it unprompted. (2) A '
			. 'competitor\'s name may appear ONLY in an "alternatives to X" style question — never as the '
			. 'subject of a recommendation, because a question about a rival measures the rival, not us.';

		$builder = wp_ai_client_prompt( self::ai_brief( $brand, $category, $product, $core, $existing ) )
			->using_system_instruction( $system )
			->using_max_tokens( self::AI_TOKENS )
			->using_temperature( 0.7 ); // Higher than Assist's 0.4: we want a varied spread, not one right answer.

		if ( ! $builder->is_supported_for_text_generation() ) {
			return new \WP_Error(
				'agentimus_ai_unavailable',
				__( 'No AI text model is configured. Add or enable one under Settings → AI.', 'agentimus' ),
				array( 'status' => 503 )
			);
		}

		$text = $builder->generate_text();
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		// Enforce the "never name the product" rule rather than trusting the model to obey
		// it: a question containing our own name is nearly guaranteed to get our name back,
		// which would score as visibility we did not actually earn.
		//
		// This is a plain substring test, so a product named after a common word ("Members",
		// "Redirection", "Forms") also loses innocent questions — which is why an empty result
		// falls back to the templates instead of erroring. Better a smaller honest list than a
		// dead end the owner can only escape by renaming their product.
		$questions = self::strip_branded( self::parse_lines( is_string( $text ) ? $text : '' ), $brand );

		if ( empty( $questions ) ) {
			return self::questions( $brand, $category, isset( $product['competitors'] ) ? (array) $product['competitors'] : array(), $existing );
		}
		return self::refine( $questions, $existing );
	}

	/**
	 * Drop any question that names the product itself. Pure, so the rule is testable.
	 *
	 * @param array<int,string> $questions Candidate questions.
	 * @param string            $brand     The product name (may be '').
	 * @return array<int,string>
	 */
	public static function strip_branded( array $questions, $brand ) {
		$brand = self::tidy( $brand );
		if ( '' === $brand ) {
			return array_values( $questions );
		}
		return array_values(
			array_filter(
				$questions,
				static function ( $q ) use ( $brand ) {
					return false === stripos( (string) $q, $brand );
				}
			)
		);
	}

	/**
	 * The facts we hand the model. Everything is already-entered settings — nothing is
	 * scraped and no page content is sent.
	 *
	 * @param string       $brand    Product name.
	 * @param string       $category What it is.
	 * @param array        $product  The product row.
	 * @param CoreSettings $core     Core settings.
	 * @param array        $existing Prompts already tracked.
	 * @return string
	 */
	private static function ai_brief( $brand, $category, array $product, CoreSettings $core, array $existing ) {
		$lines = array( sprintf( 'Write %d questions.', self::AI_COUNT ), '' );

		if ( '' !== $brand ) {
			$lines[] = 'Product name (never mention it): ' . $brand;
		}
		if ( '' !== $category ) {
			$lines[] = 'What it is: ' . $category;
		}

		$about = self::tidy( $core->identity( 'description', '' ) );
		if ( '' === $about ) {
			$about = self::tidy( get_bloginfo( 'description' ) );
		}
		if ( '' !== $about ) {
			$lines[] = 'Who makes it: ' . $about;
		}

		$rivals = self::clean( isset( $product['competitors'] ) ? (array) $product['competitors'] : array() );
		if ( ! empty( $rivals ) ) {
			$lines[] = 'It competes with: ' . implode( ', ', array_slice( $rivals, 0, 6 ) );
		}

		$tracked = self::clean( $existing );
		if ( ! empty( $tracked ) ) {
			$lines[] = '';
			$lines[] = 'Already asked — write DIFFERENT questions to these:';
			foreach ( array_slice( $tracked, 0, 15 ) as $q ) {
				$lines[] = '- ' . $q;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * A model's line-per-question reply into a clean list: drop bullets/numbering the
	 * model added anyway, and anything that isn't a plausible question.
	 *
	 * Every pattern here is UTF-8 aware (`/u`), which is load-bearing, not decorative. In
	 * byte mode PCRE's `\R` matches the raw byte 0x85 — which occurs INSIDE ordinary UTF-8
	 * characters (Cyrillic "х" is D1 85, "Å" is C3 85, and much of CJK) — so the split would
	 * cut mid-character and hand back a mangled fragment that still ends in "?" and still
	 * passes every filter below. A Russian or Chinese site would silently be offered broken
	 * questions, and an accepted one becomes a graded prompt forever.
	 *
	 * @param string $text Raw model output.
	 * @return array<int,string>
	 */
	private static function parse_lines( $text ) {
		$out = array();
		foreach ( (array) preg_split( '/\R/u', (string) $text ) as $line ) {
			// Strip "1. ", "1) ", "- ", "* ", "• " and any surrounding quotes.
			$line = preg_replace( '/^\s*(?:\d+[.)]|[-*\x{2022}])\s*/u', '', (string) $line );
			$line = trim( (string) $line );
			$line = trim( $line, "\"'“”‘’" );
			$line = self::tidy( $line );
			// Length in CHARACTERS, not bytes — a 120-character Chinese question is ~360
			// bytes and is perfectly valid.
			if ( '' === $line || mb_strlen( $line ) > self::MAX_LENGTH ) {
				continue;
			}
			// A question, not a stray "Here are 12 questions:" preamble. Accepts the
			// full-width "？" a model answering in Japanese or Chinese will use.
			if ( ! preg_match( '/[?？]$/u', $line ) ) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Suggestions for one tracked product, wiring the real sources: its own name (or the
	 * site's identity name), the category the owner gave it, its competitors, and —
	 * excluded — the prompts it already tracks.
	 *
	 * @param array        $product A visibility product row { name, category, competitors, prompts }.
	 * @param CoreSettings $core    Core settings (identity). Instantiated if omitted.
	 * @return array<int,string>
	 */
	public static function for_product( array $product, ?CoreSettings $core = null ) {
		$core = $core instanceof CoreSettings ? $core : new CoreSettings();

		return self::questions(
			self::brand_of( $product, $core ),
			isset( $product['category'] ) ? (string) $product['category'] : '',
			isset( $product['competitors'] ) ? (array) $product['competitors'] : array(),
			isset( $product['prompts'] ) ? (array) $product['prompts'] : array()
		);
	}

	/** The product's own name, falling back to the site identity name, then the site title. */
	private static function brand_of( array $product, CoreSettings $core ) {
		$named = isset( $product['name'] ) ? self::tidy( $product['name'] ) : '';
		if ( '' !== $named ) {
			return $named;
		}
		return self::tidy( $core->identity( 'name', get_bloginfo( 'name' ) ) );
	}

	/**
	 * De-dupe candidates (case-insensitively) against the already-tracked prompts and each
	 * other, preserving order, and cap the count. Shared by both generators.
	 *
	 * @param array<int,string> $candidates Raw candidates, best first.
	 * @param array<int,string> $existing   Prompts already tracked.
	 * @return array<int,string>
	 */
	private static function refine( array $candidates, array $existing ) {
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

	/** Trim, drop empties, de-dupe (case-insensitive) — order preserved. Pure. */
	private static function clean( array $list ) {
		$seen = array();
		$out  = array();
		foreach ( $list as $v ) {
			$v = self::tidy( $v );
			$k = self::norm( $v );
			if ( '' === $k || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = $v;
		}
		return $out;
	}

	/** One free-text value, flattened to a single clean line. Pure. */
	private static function tidy( $s ) {
		$s = preg_replace( '/\s+/u', ' ', (string) $s );
		return trim( (string) $s );
	}

	/** A comparison key: lowercased, trimmed, trailing punctuation dropped. Pure. */
	private static function norm( $s ) {
		return rtrim( strtolower( self::tidy( $s ) ), " ?.!" );
	}
}
