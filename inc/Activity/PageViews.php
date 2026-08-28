<?php
/**
 * PageViews — record a MACHINE's visit to an ordinary page, not just to the
 * generated agent surfaces.
 *
 * ⛔⛔ WHY THIS EXISTS. Until this, {@see Recorder::record()} was called from
 * four serve-paths only: the generated endpoints, the .well-known docs, the
 * discovery REST route, and refusals. Ordinary HTML was logged NOWHERE. So the
 * Request Log's own promise — "every visit a machine made to your site" — was
 * false, and worse: Web Bot Auth signs requests for PAGES. Google's agent could
 * sign every request it made for an article and the site would have no record
 * that a signature had ever arrived, because the only rows we kept were for
 * files that agents fetch far less often than they read content.
 *
 * ⛔ MACHINES ONLY. The screen is badged MACHINES and humans are counted
 * separately, without a User-Agent, by {@see Referrals}. The test here is the
 * exact inverse of the one Referrals uses ('Browser' === classify), so the two
 * can never both claim a visit or both disqualify it.
 *
 * ⚠️⚠️ WHAT THIS CANNOT SEE, and the screen says so. A page served from a cache
 * in front of PHP — nginx FastCGI, a CDN, a page-cache plugin — never runs
 * WordPress, so it can never be recorded here. On a cached site these counts are
 * a FLOOR, not a total. (Most crawler traffic does reach origin, because a
 * crawler's spread of URLs misses the cache more often than a reader's does, but
 * "most" is not "all" and the number must not be read as a census.)
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

use Agentimus\Settings;
use Agentimus\Guard;
use Agentimus\BotSignature;

defined( 'ABSPATH' ) || exit;

final class PageViews {

	/**
	 * The endpoint label every page view is recorded under.
	 *
	 * ⭐ ONE LABEL FOR ALL PAGES, exactly as `markdown` is one label for every
	 * .md twin on the site. The `endpoint` column answers "WHICH SURFACE", not
	 * "which URL" — it is varchar(64), it feeds a dropdown of distinct values
	 * ({@see Repository::log_facets()}), and pouring every path on the site into
	 * it would turn a filter with a dozen honest options into an unusable list
	 * of hundreds, while truncating the long paths that made it that way.
	 */
	const LABEL = 'page';

	/** @var bool|null Per-request cache of the enable flag. */
	private static $enabled = null;

	/**
	 * Record this front-end request if it is a machine reading a page.
	 *
	 * Hooked late on `template_redirect` (priority 30), beside {@see
	 * Referrals::maybe_record()} — by then WordPress has resolved the query, so
	 * `is_404()` and friends can be trusted.
	 *
	 * @return void
	 */
	public static function maybe_record() {
		if ( ! self::enabled() ) {
			return;
		}
		// Admin screens, REST and AJAX are not page views. (The discovery REST
		// route records itself; recording it twice would double every hit.)
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		// A GET or a HEAD is a read. Anything else is a submission, and a POST is
		// never "what this agent fetched".
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		// ⛔ A 404 IS NOT A FETCH, and the same exclusions the referral counter
		// uses apply for the same reason: the log's totals are reads of real
		// content, and a crawler probing dead URLs must not read as one.
		// Feeds and robots.txt are machine surfaces with their own meaning; they
		// are deliberately left out rather than folded into "page".
		if ( is_feed() || is_404() || is_trackback() || is_robots() ) {
			return;
		}
		if ( ! self::is_machine() ) {
			return;
		}
		// Everything else — the owner-skip, flood control, the identity verdict,
		// the signer, the row cap — is the Recorder's, unchanged and shared with
		// every other surface. This class only decides WHETHER a page view counts.
		Recorder::record( self::LABEL );
	}

	/**
	 * Whether the client that asked for this page is a machine.
	 *
	 * Two ways to be one, and the second is the point of the whole feature:
	 *
	 * 1. The User-Agent says so — anything {@see Classifier::classify()} does not
	 *    call a plain 'Browser'. That is the same line {@see Referrals} draws, in
	 *    the same direction, so a visit is either a human referral or a machine
	 *    read and never both.
	 * 2. ⭐ It carried a SIGNATURE. An agent driving a real browser sends a real
	 *    browser's User-Agent, and no amount of string-matching will ever separate
	 *    it from the person whose laptop it is running on. A signature can, and it
	 *    is the only thing that can — so a signed request is a machine no matter
	 *    what it calls itself. This is exactly the traffic the log was blind to.
	 *
	 * ⚠️ THE ABSENCE NAMES ITSELF: an unsigned agent behind a browser UA is
	 * indistinguishable from a reader and is counted as one. Nothing here guesses.
	 *
	 * @return bool
	 */
	private static function is_machine() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only classification, no state change.
		if ( 'Browser' !== Classifier::classify( $ua ) ) {
			return true;
		}
		// Cheap for the overwhelming majority: with no signature headers on the
		// request, inspect() returns 'unsigned' after four isset() checks and the
		// verdict is memoised for the rest of the request either way.
		return Guard::verification_on() && '' !== BotSignature::face();
	}

	/**
	 * Whether page-view logging is on. Requires BOTH switches: the master activity
	 * toggle, and this stream's own — the volume is a different order of magnitude
	 * from the endpoint stream, so it gets its own answer.
	 *
	 * @return bool
	 */
	private static function enabled() {
		if ( null === self::$enabled ) {
			$settings = new Settings();
			/**
			 * Filter whether machine page views are recorded.
			 *
			 * @param bool $on Whether both the activity log and this stream are enabled.
			 */
			self::$enabled = (bool) apply_filters(
				'agentimus_log_page_views',
				$settings->enabled( 'enable_activity' ) && $settings->enabled( 'log_page_views' )
			);
		}
		return self::$enabled;
	}

	/**
	 * Test seam: forget the cached enable flag.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset_cache() {
		self::$enabled = null;
	}
}
