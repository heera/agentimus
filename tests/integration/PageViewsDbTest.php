<?php
/**
 * PageViews — which front-end requests become rows, and which must never.
 *
 * ⛔⛔ THE POINT OF THE FEATURE, pinned by
 * {@see test_a_signed_request_behind_a_browser_user_agent_is_recorded}: Web Bot
 * Auth signs requests for PAGES, and until pages were logged a signed visit left
 * no record anywhere on the site. Everything else here exists to stop that one
 * capability dragging humans, 404s and floods into the log with it.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\PageViews;
use Agentimus\Activity\Recorder;
use Agentimus\Activity\Table;
use Agentimus\BotSignature;

final class PageViewsDbTest extends DbTestCase {

	/** A crawler that names itself but is not in the recognition catalog. */
	const BOT_UA = 'SomeCrawler/1.0 (+https://example.test/bot)';

	/** An ordinary reader. */
	const HUMAN_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

	/** @var int */
	private $post_id;

	/**
	 * @var array $_SERVER as we found it.
	 *
	 * ⛔ RESTORED, NEVER UNSET. A tear_down that deleted REQUEST_METHOD outright
	 * left it missing for every test that ran AFTER this file, and WordPress core
	 * reads it unguarded inside wp_validate_auth_cookie() — nine unrelated tests
	 * turned red, in a file this one never touches. Global state is borrowed.
	 */
	private $server = array();

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() ); // phpcs:ignore WordPress.DB
		update_option( 'agentimus_settings', array( 'enable_activity' => true, 'log_page_views' => true ) );
		PageViews::reset_cache();
		// Logged out: an administrator reading their own site is skipped by design
		// ({@see Owner::skip()}), which would silently pass every test below.
		wp_set_current_user( 0 );
		$this->post_id              = self::factory()->post->create( array( 'post_title' => 'A page' ) );
		$this->server               = $_SERVER;
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['HTTP_USER_AGENT'] = self::BOT_UA;
		$this->reset_buckets();
	}

	public function tear_down(): void {
		BotSignature::prime_memo( null );
		PageViews::reset_cache();
		$_SERVER = $this->server;
		$this->reset_buckets();
		parent::tear_down();
	}

	/** All four flood buckets — the two streams' pairs — for the current window. */
	private function reset_buckets(): void {
		$win = (int) floor( time() / Recorder::FLOOD_WINDOW );
		foreach ( array( 'a', 'u', 'pa', 'pu' ) as $bucket ) {
			delete_transient( Recorder::RATE_PREFIX . md5( $bucket ) . '_' . $win );
		}
	}

	private function rows( $endpoint = null ): int {
		global $wpdb;
		if ( null === $endpoint ) {
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Table::name() . ' WHERE endpoint = %s', $endpoint ) ); // phpcs:ignore WordPress.DB
	}

	/** Visit the post as the current $_SERVER says, and run the recorder. */
	private function visit_page(): void {
		$this->go_to( get_permalink( $this->post_id ) );
		PageViews::maybe_record();
	}

	/* -- What a page view is ------------------------------------------------ */

	public function test_a_crawler_reading_an_article_is_recorded_as_a_page() {
		$this->visit_page();

		$this->assertSame( 1, $this->rows( PageViews::LABEL ) );
	}

	/**
	 * ⛔ One label for every URL, exactly as `markdown` is one label for every .md
	 * twin. The endpoint column answers "which surface", and pouring every path on
	 * the site into a varchar(64) that feeds a filter dropdown would wreck both.
	 */
	public function test_every_url_shares_one_endpoint_label() {
		$second = self::factory()->post->create();

		$this->visit_page();
		$this->go_to( get_permalink( $second ) );
		PageViews::maybe_record();

		global $wpdb;
		$labels = $wpdb->get_col( 'SELECT DISTINCT endpoint FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( PageViews::LABEL ), $labels );
		$this->assertSame( 2, $this->rows( PageViews::LABEL ), 'two pages, two rows, one label' );
	}

	/* -- Who is never recorded ---------------------------------------------- */

	/**
	 * ⛔⛔ THE SCREEN IS BADGED "MACHINES" AND HUMANS ARE COUNTED ELSEWHERE,
	 * without a User-Agent, by Referrals. This is the exact inverse of the test
	 * Referrals applies, so a visit can never be claimed by both.
	 */
	public function test_a_reader_is_never_recorded() {
		$_SERVER['HTTP_USER_AGENT'] = self::HUMAN_UA;

		$this->visit_page();

		$this->assertSame( 0, $this->rows(), 'the request log is not a visitor log' );
	}

	public function test_a_missing_page_is_not_a_fetch() {
		// ⚠️ By post id, not by a pretty path: the test install runs plain
		// permalinks, so an invented path resolves to the home page and the
		// fixture quietly stops being a 404 at all. (It did — the assertion
		// below is what caught it.)
		$this->go_to( home_url( '/?p=99999999' ) );
		PageViews::maybe_record();

		$this->assertTrue( is_404(), 'the fixture really is a 404' );
		$this->assertSame( 0, $this->rows(), 'a crawler probing dead URLs has not read anything' );
	}

	public function test_a_feed_is_not_a_page() {
		$this->go_to( home_url( '/?feed=rss2' ) );
		PageViews::maybe_record();

		$this->assertTrue( is_feed(), 'the fixture really is a feed' );
		$this->assertSame( 0, $this->rows() );
	}

	public function test_a_submission_is_not_a_read() {
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->visit_page();

		$this->assertSame( 0, $this->rows() );
	}

	public function test_the_owner_can_switch_the_whole_stream_off() {
		update_option( 'agentimus_settings', array( 'enable_activity' => true, 'log_page_views' => false ) );
		PageViews::reset_cache();

		$this->visit_page();

		$this->assertSame( 0, $this->rows() );
	}

	public function test_the_master_activity_switch_still_governs_it() {
		update_option( 'agentimus_settings', array( 'enable_activity' => false, 'log_page_views' => true ) );
		PageViews::reset_cache();

		$this->visit_page();

		$this->assertSame( 0, $this->rows() );
	}

	/* -- The reason the feature exists -------------------------------------- */

	/**
	 * ⭐⭐ AN AGENT DRIVING A REAL BROWSER SENDS A REAL BROWSER'S USER-AGENT. No
	 * amount of string-matching separates it from the person whose laptop it is
	 * running on — a signature can, and it is the only thing that can. Google's
	 * agent and OpenAI sign the requests they make for PAGES, so this row is the
	 * whole reason page views are logged at all.
	 */
	public function test_a_signed_request_behind_a_browser_user_agent_is_recorded() {
		update_option( 'agentimus_settings', array( 'enable_activity' => true, 'log_page_views' => true, 'verify_bots' => true ) );
		PageViews::reset_cache();
		$_SERVER['HTTP_USER_AGENT'] = self::HUMAN_UA;
		BotSignature::prime_memo( array( 'state' => 'verified', 'signer' => 'https://chatgpt.com', 'reason' => '' ) );

		$this->visit_page();

		global $wpdb;
		$row = (array) $wpdb->get_row( 'SELECT endpoint, signer, verdict FROM ' . Table::name(), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame( PageViews::LABEL, $row['endpoint'] );
		$this->assertSame( 'OpenAI agent', $row['signer'], 'the signature is what identified it, and it is on the row' );
		$this->assertSame( 1, (int) $row['verdict'] );
	}

	/** Without verification armed, that same request is just a reader again. */
	public function test_an_unsigned_browser_stays_a_reader_and_nothing_is_guessed() {
		$_SERVER['HTTP_USER_AGENT'] = self::HUMAN_UA;
		BotSignature::prime_memo( array( 'state' => 'unsigned', 'signer' => '', 'reason' => '' ) );

		$this->visit_page();

		$this->assertSame( 0, $this->rows(), 'an agent that will not identify itself is indistinguishable from a person' );
	}

	/* -- The two streams do not spend each other's budget -------------------- */

	/**
	 * ⛔⛔ THE REGRESSION THIS EXISTS TO PREVENT. A crawl of the CONTENT is orders
	 * of magnitude more requests than a crawl of the generated files — one
	 * llms.txt, then five hundred articles. On one shared bucket the page stream
	 * would spend the whole per-minute budget and the llms.txt and .md hits this
	 * log was BUILT to show would start being sampled away by the very feature
	 * that was meant to add to it.
	 */
	public function test_a_page_flood_cannot_sample_away_the_endpoint_stream() {
		for ( $i = 0; $i < Recorder::FLOOD_THRESHOLD + 40; $i++ ) {
			$this->visit_page();
		}
		$pages = $this->rows( PageViews::LABEL );

		// The endpoint stream has spent nothing: its first hit is its first hit.
		Recorder::record( 'llms.txt' );

		// ⚠️ >=, NOT >. Only the first FLOOD_THRESHOLD hits are guaranteed
		// (survives_flood keeps everything at or below it); the 40 above are a
		// 1-in-20 coin toss each, and all 40 missing is a ~13% event — which is
		// exactly how often this line failed before it was written this way.
		$this->assertGreaterThanOrEqual( Recorder::FLOOD_THRESHOLD, $pages, 'the page stream did fill its own bucket' );
		$this->assertSame( 1, $this->rows( 'llms.txt' ), 'and the file it was crawling alongside is still recorded in full' );
	}

	/**
	 * ⭐⭐ Signed traffic is vanishingly rare and is the entire reason the signer
	 * column exists; losing the one signed request in a million to a 1-in-20 flood
	 * sample would put us straight back to "the maths passed and the log said
	 * nothing happened".
	 *
	 * ⚠️⚠️ ASSERTED OVER MANY REQUESTS, AND THAT IS NOT PADDING. Sampling is a
	 * 1-in-FLOOD_SAMPLE coin toss, so a single signed request survives a spent
	 * bucket ~5% of the time BY CHANCE — this test passed with the exemption
	 * deliberately removed, which is how the flakiness was found. Over 25 of them
	 * the odds of a false pass are 20^-25, so an exact count is now a real claim.
	 */
	public function test_a_verified_signature_is_never_sampled_away_by_a_flood() {
		update_option( 'agentimus_settings', array( 'enable_activity' => true, 'log_page_views' => true, 'verify_bots' => true ) );
		PageViews::reset_cache();
		// Spend the unrecognised page bucket several times over.
		for ( $i = 0; $i < Recorder::FLOOD_THRESHOLD * 3; $i++ ) {
			$this->visit_page();
		}
		$before = $this->rows( PageViews::LABEL );

		BotSignature::prime_memo( array( 'state' => 'verified', 'signer' => 'https://chatgpt.com', 'reason' => '' ) );
		$signed = 25;
		for ( $i = 0; $i < $signed; $i++ ) {
			$this->visit_page();
		}

		$this->assertSame(
			$before + $signed,
			$this->rows( PageViews::LABEL ),
			'every signed request recorded, despite the bucket being long spent'
		);
	}
}
