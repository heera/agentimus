<?php
/**
 * Which reported searches a page may NOT be held to.
 *
 * A search engine reports every string that showed this site, and some of those
 * strings are not questions anyone asked this site to answer: a scraper's
 * operator probe, a URL pasted into the search box, somebody's prompt pasted in
 * whole. A page judged against one of those fails a question it was never asked
 * — and no edit can ever clear it, which turns the owner's worklist into a list
 * of rows nothing can close. That is the same fault 1.46.0 named one level down,
 * where a fix tool's guard was drawn tighter than its own check.
 *
 * ⛔⛔ TWO DIFFERENT QUESTIONS LIVE HERE, and merging them would make a screen
 * lie:
 *
 *   {@see is_operator()} — "does this use search operators?" That is evidence
 *   about WHO searched: `site:`, `inurl:` and friends are how crawlers and SEO
 *   tools probe, not how people type. The Search screen labels those impressions
 *   machine traffic, and the MCP payload calls the field `isProbe` — "a
 *   search-operator probe, not a person's search". Widening THAT would put a
 *   claim about the searcher behind evidence that does not support it.
 *
 *   {@see is_noise()} — "can a page honestly be judged against this?" A URL is
 *   not a probe: a person may well have pasted it. It still is not a question
 *   this site's writing can answer, so no page should be marked down for
 *   failing it.
 *
 * ⭐ WHAT THIS DELIBERATELY DOES NOT CATCH. "yes or no" and "on the website
 * itself" are real strings a real engine really reported, and nothing here can
 * tell them from a short genuine search without guessing about a site it knows
 * nothing about. Guessing would silently hide real demand, which is worse than
 * leaving a row standing. The honest remedy for those is the owner dismissing
 * the SEARCH — not a cleverer filter, and not setting aside a good page to
 * silence one bad query.
 *
 * @package Agentimus
 */

namespace Agentimus\Search;

use Agentimus\Focus;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Noise {

	/** A machine's operator probe: `site:`, `inurl:`, `intitle:`… */
	const OPERATOR = 'operator';

	/** A web address typed or pasted into the search box. */
	const ADDRESS = 'address';

	/** Longer than anything a person types into a search box. */
	const PASTE = 'paste';

	/**
	 * The owner looked at this search and said it is not a question their site
	 * should be judged by.
	 *
	 * ⭐ THE REMEDY FOR WHAT NO RULE CAN DETECT. "yes or no" and "on the website
	 * itself" are real reported strings that nothing can tell from a short real
	 * search without guessing — see the class docblock. Before this existed the
	 * only lever was setting aside the PAGE they landed on, which removes good
	 * writing from the worklist to silence a bad query. The owner had already
	 * paid that price once on a real site: a post was excused from grading
	 * because a spam URL pointed at it.
	 *
	 * ⛔ Kept LAST in {@see kind()} on purpose. When a search is both dismissed
	 * and, say, an operator probe, the automatic rule is the BINDING reason —
	 * restoring it would not bring the search back, and a screen offering
	 * "restore" for a row that cannot return is a lie about what the button does.
	 */
	const DISMISSED = 'dismissed';

	/**
	 * Bind the one hook this needs: the dismissed list is read per query and
	 * cached, and the REST call that changes it rebuilds the report in the SAME
	 * request — so a cache with no way to clear itself would answer the screen
	 * with the list as it was a moment before the owner changed it.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'flush' ) );
	}

	/** Forget the cached dismissal list. @return void */
	public static function flush() {
		self::$dismissed = null;
	}

	/** @var array<int,string>|null Dismissed searches, normalized. Null = unread. */
	private static $dismissed = null;

	/**
	 * One spelling for one search, so a difference in case or spacing can never
	 * store an entry that matches nothing and cannot be restored.
	 *
	 * @param string $query Any search.
	 * @return string
	 */
	public static function normal( $query ) {
		return trim( preg_replace( '/\s+/u', ' ', strtolower( (string) $query ) ) );
	}

	/**
	 * The searches this owner has dismissed, normalized.
	 *
	 * ⭐ Public so the owner's noise report can list them WHOLE and offer each
	 * one back. A ledger that filters silently and cannot be read is the thing
	 * this whole class exists not to be.
	 *
	 * @return array<int,string>
	 */
	public static function dismissed() {
		if ( null === self::$dismissed ) {
			$list = ( new Settings() )->get( 'search_dismissed', array() );
			self::$dismissed = array_map( array( __CLASS__, 'normal' ), (array) $list );
		}
		return self::$dismissed;
	}

	/**
	 * Has the owner dismissed this search?
	 *
	 * @param string $query The reported search.
	 * @return bool
	 */
	public static function is_dismissed( $query ) {
		$q = self::normal( $query );
		return '' !== $q && in_array( $q, self::dismissed(), true );
	}

	/**
	 * Search operators — the shape of a machine probe.
	 *
	 * ⛔ Kept NARROW on purpose. This answers "who searched", and the Search
	 * screen puts the word "machine traffic" behind the answer. See the class
	 * docblock for why {@see is_noise()} is the one that grew instead.
	 *
	 * @param string $query The reported search.
	 * @return bool
	 */
	public static function is_operator( $query ) {
		$q = strtolower( trim( (string) $query ) );
		if ( '' === $q ) {
			return false;
		}
		foreach ( array( 'site:', 'intext:', 'inurl:', 'intitle:', 'filetype:', 'cache:', 'related:' ) as $operator ) {
			if ( preg_match( '/(?:^|[\s("\'\[])[-+]?' . preg_quote( $operator, '/' ) . '/u', $q ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A web address, not a question.
	 *
	 * ⚠️ ONLY THE UNAMBIGUOUS FORMS — a scheme, or a leading `www.`. A bare
	 * `example.com` is deliberately NOT caught, because "vue.js", "node.js" and
	 * "next.js" are domain-shaped and are among the most searched strings on
	 * earth. Killing a real search to catch a fake one is the wrong trade: this
	 * under-reaches on purpose, and says so.
	 *
	 * @param string $query The reported search.
	 * @return bool
	 */
	public static function is_address( $query ) {
		$q = strtolower( trim( (string) $query ) );
		return '' !== $q && ( false !== strpos( $q, '://' ) || 0 === strpos( $q, 'www.' ) );
	}

	/**
	 * Longer than a search: a prompt, a paragraph, a question pasted whole.
	 *
	 * ⭐ THE LIMIT IS NOT INVENTED. It is {@see Focus::MAX_LEN}, the cap on the
	 * focus field the owner types into — commented there as "A search, not a
	 * sentence". If the owner could not enter it as what this page is for, the
	 * plugin must not judge the page against it. One idea, one number.
	 *
	 * ⚠️ Measured in characters, matching how Focus::sanitize() truncates. On the
	 * real corpus this was calibrated against, the longest genuine search ran to
	 * 74 characters against a cap of 120 — the headroom is deliberate, because
	 * dropping a real search is the costlier mistake.
	 *
	 * @param string $query The reported search.
	 * @return bool
	 */
	public static function is_paste( $query ) {
		return mb_strlen( trim( (string) $query ) ) > Focus::MAX_LEN;
	}

	/**
	 * WHY this search cannot judge a page, or '' when it can.
	 *
	 * Returning the reason rather than a bare true keeps the owner's noise
	 * report able to say what it dropped and on what grounds — the difference
	 * between a filter they can audit and one they have to trust.
	 *
	 * @param string $query The reported search.
	 * @return string One of the class constants, or '' for a real search.
	 */
	public static function kind( $query ) {
		if ( self::is_operator( $query ) ) {
			return self::OPERATOR;
		}
		if ( self::is_address( $query ) ) {
			return self::ADDRESS;
		}
		if ( self::is_paste( $query ) ) {
			return self::PASTE;
		}
		// LAST — see the constant. An automatic rule that also applies is the
		// reason that binds, because undoing this one would not bring it back.
		if ( self::is_dismissed( $query ) ) {
			return self::DISMISSED;
		}
		return '';
	}

	/**
	 * Is this a string no page should be marked down for failing?
	 *
	 * ⛔ THE ONE PREDICATE for every surface that judges a page by a search —
	 * the coverage worklist, the focus choices offered, split-search collisions,
	 * the opportunity list and the progress index. One owner, so a query dropped
	 * from one of them can never still be judged by another.
	 *
	 * @param string $query The reported search.
	 * @return bool
	 */
	public static function is_noise( $query ) {
		return '' !== self::kind( $query );
	}
}
