<?php
/**
 * The progress ledger ({@see Progress}) — what counts as a page having improved,
 * and what a resolution is allowed to say.
 *
 * The rule under test is the one the feature stands on: LEAVING A WORKLIST IS
 * NOT RESOLVING. A page falls off that list by sinking, by losing its traffic
 * and by being set aside, and every one of those would otherwise arrive as
 * "well done" — the single failure mode that would make an owner stop believing
 * the good news entirely.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Search\Opportunities;
use Agentimus\Search\Progress;
use PHPUnit\Framework\TestCase;

final class SearchProgressTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** A baseline as the ledger stores it. */
	private function baseline( $position, $impressions = 2000, $clicks = 0 ) {
		return array(
			'kind'        => Opportunities::KIND_NEAR,
			'page_key'    => 'p7',
			'page_id'     => 7,
			'page_url'    => 'https://example.test/okf/',
			'query'       => 'okf bundle',
			'position'    => $position,
			'impressions' => $impressions,
			'clicks'      => $clicks,
			'since'       => 1000,
		);
	}

	/** A page as it stands in a later snapshot. */
	private function now( $position, $impressions = 2000, $clicks = 0 ) {
		return array(
			'page_id'     => 7,
			'page_url'    => 'https://example.test/okf/',
			'impressions' => $impressions,
			'clicks'      => $clicks,
			// The index stores the weighted sum; position is derived from it, so
			// the test builds it the same way the grouper does.
			'pos_weight'  => $position * $impressions,
			'rows'        => array(),
		);
	}

	/* ---- almost on page one ------------------------------------------------ */

	public function test_climbing_onto_page_one_is_a_resolution() {
		$win = Progress::improvement( Opportunities::KIND_NEAR, $this->baseline( 8.2 ), $this->now( 5.1 ) );

		$this->assertIsArray( $win, 'rank 8.2 → 5.1 is the move this group exists for' );
		$this->assertSame( 8.2, $win['from'] );
		$this->assertSame( 5.1, $win['to'] );
	}

	/**
	 * The page sank out of the 8–20 band. It has left the worklist exactly as a
	 * fixed page does — and telling the owner it resolved would be a lie in the
	 * one direction they would never think to check.
	 */
	public function test_sinking_out_of_the_band_is_not_a_resolution() {
		$this->assertNull( Progress::improvement( Opportunities::KIND_NEAR, $this->baseline( 12.0 ), $this->now( 24.0 ) ) );
	}

	/**
	 * Better, but still page two. The group is "one push from page one", so the
	 * push has to have landed — 14th up from 18th is progress nobody can see in
	 * a result page, and announcing it would spend the word "resolved" on it.
	 */
	public function test_improving_within_page_two_is_not_yet_a_resolution() {
		$this->assertNull( Progress::improvement( Opportunities::KIND_NEAR, $this->baseline( 18.0 ), $this->now( 14.0 ) ) );
	}

	/**
	 * A card whose PAGE average was already on page one — it joined the group on
	 * one bad search, because the worklist judges searches before pages. "It
	 * reached page one" was true the day it appeared, so a later drift in the
	 * average must not be announced as a climb that never happened.
	 */
	public function test_a_page_that_was_never_in_the_band_cannot_leave_it() {
		$this->assertNull( Progress::improvement( Opportunities::KIND_NEAR, $this->baseline( 5.0 ), $this->now( 4.6 ) ) );
	}

	/** No impressions left to weigh: the page has gone quiet, not gone up. */
	public function test_a_page_with_no_impressions_resolves_nothing() {
		$this->assertNull( Progress::improvement( Opportunities::KIND_NEAR, $this->baseline( 9.0 ), $this->now( 0.0, 0 ) ) );
	}

	/* ---- seen, not chosen -------------------------------------------------- */

	public function test_clicks_arriving_resolves_a_passed_over_page() {
		$base = array( 'kind' => Opportunities::KIND_SEEN, 'position' => 3.0, 'impressions' => 1000, 'clicks' => 0 ) + $this->baseline( 3.0 );
		$win  = Progress::improvement( Opportunities::KIND_SEEN, $base, $this->now( 3.0, 1000, 40 ) );

		$this->assertIsArray( $win );
		$this->assertSame( 0.0, $win['from'], 'it was earning nothing' );
		$this->assertSame( 4.0, $win['to'], '40 of 1,000 is 4%' );
	}

	/**
	 * Still nobody clicking. The page may have left the group because the site's
	 * own median moved — a fact about every OTHER page, not about this one.
	 */
	public function test_still_unclicked_is_not_a_resolution() {
		$base = array( 'kind' => Opportunities::KIND_SEEN ) + $this->baseline( 3.0, 1000, 0 );
		$this->assertNull( Progress::improvement( Opportunities::KIND_SEEN, $base, $this->now( 3.0, 1200, 0 ) ) );
	}

	/** Fewer clicks per view than before is not a win, whatever the raw count. */
	public function test_a_falling_click_rate_is_not_a_resolution() {
		$base = array( 'kind' => Opportunities::KIND_SEEN ) + $this->baseline( 3.0, 1000, 50 );
		$this->assertNull( Progress::improvement( Opportunities::KIND_SEEN, $base, $this->now( 3.0, 5000, 60 ) ) );
	}

	/* ---- storage ----------------------------------------------------------- */

	/** An empty ledger answers in shape, so no caller has to guard the read. */
	public function test_an_empty_ledger_reports_nothing_rather_than_breaking() {
		$this->assertSame( array(), Progress::resolved() );
		$this->assertSame( 0, Progress::since( 'google', Opportunities::KIND_NEAR, 7, 'https://example.test/okf/' ) );
	}

	/** News expires on its own — there is no dismiss button to forget to press. */
	public function test_resolutions_older_than_the_window_stop_being_news() {
		$now = time();
		update_option(
			Progress::OPTION,
			array(
				'baselines' => array(),
				'resolved'  => array(
					array( 'source' => 'google', 'kind' => Opportunities::KIND_NEAR, 'page_id' => 7, 'page_url' => '', 'query' => 'okf', 'from' => 9.0, 'to' => 4.0, 'shown' => 10, 'at' => $now - 3600 ),
					array( 'source' => 'google', 'kind' => Opportunities::KIND_NEAR, 'page_id' => 8, 'page_url' => '', 'query' => 'old', 'from' => 9.0, 'to' => 4.0, 'shown' => 10, 'at' => $now - ( ( Progress::KEEP_DAYS + 2 ) * DAY_IN_SECONDS ) ),
				),
			)
		);

		$out = Progress::resolved();
		$this->assertCount( 1, $out, 'the stale one is gone; the fresh one stays' );
		$this->assertSame( 7, $out[0]['page_id'] );
	}

	/** Disconnecting a source drops its ledger without touching the other's. */
	public function test_forgetting_one_source_leaves_the_other_intact() {
		update_option(
			Progress::OPTION,
			array(
				'baselines' => array(
					'google|almost|p7' => $this->baseline( 9.0 ),
					'bing|almost|p9'   => $this->baseline( 9.0 ),
				),
				'resolved'  => array(
					array( 'source' => 'google', 'kind' => Opportunities::KIND_NEAR, 'page_id' => 7, 'page_url' => '', 'query' => 'a', 'from' => 9.0, 'to' => 4.0, 'shown' => 1, 'at' => time() ),
					array( 'source' => 'bing', 'kind' => Opportunities::KIND_NEAR, 'page_id' => 9, 'page_url' => '', 'query' => 'b', 'from' => 9.0, 'to' => 4.0, 'shown' => 1, 'at' => time() ),
				),
			)
		);

		Progress::forget( 'google' );

		$state = get_option( Progress::OPTION );
		$this->assertArrayNotHasKey( 'google|almost|p7', $state['baselines'] );
		$this->assertArrayHasKey( 'bing|almost|p9', $state['baselines'] );
		$this->assertCount( 1, Progress::resolved() );
		$this->assertSame( 'bing', Progress::resolved()[0]['source'] );
	}

	/* ---- the page index the ledger measures against ------------------------ */

	/**
	 * The ledger and the cards must mean the same thing by "where this page
	 * ranks", so both read one grouper. A second implementation would drift, and
	 * the drift would show up as resolutions that never happened.
	 */
	public function test_the_page_index_weights_rank_by_impressions() {
		$rows  = array(
			array( 'query' => 'a', 'page_url' => 'https://example.test/x/', 'page_id' => 3, 'clicks' => 5, 'impressions' => 900, 'position' => 10.0 ),
			array( 'query' => 'b', 'page_url' => 'https://example.test/x/', 'page_id' => 3, 'clicks' => 1, 'impressions' => 100, 'position' => 20.0 ),
		);
		$index = Opportunities::page_index( $rows );

		$this->assertArrayHasKey( 'p3', $index );
		$this->assertSame( 1000, $index['p3']['impressions'] );
		// 900 views at 10th and 100 at 20th is 11th, not 15th: the big search
		// decides where the page really sits.
		$this->assertSame( 11.0, Opportunities::page_position( $index['p3'] ) );
	}

	/** A row with no page identity informs the median but can never be a page. */
	public function test_rows_with_no_page_are_not_indexed() {
		$index = Opportunities::page_index( array(
			array( 'query' => 'a', 'page_url' => '', 'page_id' => 0, 'clicks' => 1, 'impressions' => 10, 'position' => 5.0 ),
		) );
		$this->assertSame( array(), $index );
	}

	/** One page keys the same way across polls, mapped or not. */
	public function test_page_keys_are_stable() {
		$this->assertSame( 'p7', Opportunities::page_key( 7, 'https://example.test/a/' ) );
		$this->assertSame(
			Opportunities::page_key( 0, 'https://example.test/a/' ),
			Opportunities::page_key( 0, 'https://example.test/a/' )
		);
		$this->assertNotSame(
			Opportunities::page_key( 0, 'https://example.test/a/' ),
			Opportunities::page_key( 0, 'https://example.test/b/' )
		);
	}
}
