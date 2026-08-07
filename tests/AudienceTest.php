<?php
/**
 * Audience — the people/machines split.
 *
 * The claims worth locking down are not arithmetic, they are honesty claims:
 * that the two halves are never summed into one meaningless total, and that a
 * half which is EMPTY because nothing is switched on says so instead of
 * reporting a confident zero.
 *
 * @package Agentimus
 */

use PHPUnit\Framework\TestCase;
use Agentimus\Audience;

final class AudienceTest extends TestCase {

	/** A stats payload shaped like Activity\Repository::stats(). */
	private function stats( array $over = array() ) {
		return array_merge(
			array(
				'enabled'   => true,
				'window'    => 30,
				'totals'    => array( 'today' => 2, 'week' => 19, 'month' => 166, 'agents' => 18 ),
				'threats'   => array( 'counts' => array( 'new' => 1, 'heavy' => 0, 'spoof' => 3 ) ),
				'referrals' => array(
					'enabled'     => true,
					'sourceCount' => 4,
					'totals'      => array( 'today' => 1, 'window' => 59 ),
					'bySource'    => array(
						array( 'source' => 'ChatGPT', 'hits' => 40 ),
						array( 'source' => 'Perplexity', 'hits' => 12 ),
						array( 'source' => 'Claude', 'hits' => 5 ),
						array( 'source' => 'Copilot', 'hits' => 2 ),
					),
				),
			),
			$over
		);
	}

	public function test_the_two_halves_are_never_added_together() {
		$out = Audience::from_stats( $this->stats() );

		$this->assertSame( 166, $out['machines']['fetches'] );
		$this->assertSame( 59, $out['people']['ai']['visits'] );
		// A fetch is not a visit. Nothing in the payload may offer their sum.
		$this->assertArrayNotHasKey( 'total', $out );
		$this->assertNotContains( 225, array_map( 'intval', array_filter( $out['people'], 'is_numeric' ) ) );
	}

	public function test_people_arrived_counts_search_clicks_plus_readers_ai_sent() {
		$out = Audience::from_stats( $this->stats() );
		// No search source is connected in the test environment, so `arrived` is
		// the referral half alone — and that is the point: it is a SUM OF PEOPLE,
		// never people plus machines.
		$this->assertSame( $out['people']['search']['clicks'] + 59, $out['people']['arrived'] );
	}

	public function test_an_unconnected_search_source_reads_as_empty_not_zero() {
		$out  = Audience::from_stats( $this->stats() );
		$keys = array_column( $out['limits'], 'key' );

		$this->assertFalse( $out['people']['search']['connected'] );
		$this->assertContains( 'search-missing', $keys, 'an empty half has to say WHY it is empty' );
		$this->assertNotContains( 'search-blended', $keys );
	}

	public function test_logging_off_says_so_rather_than_reporting_no_machines() {
		$out  = Audience::from_stats( $this->stats( array( 'enabled' => false ) ) );
		$keys = array_column( $out['limits'], 'key' );

		$this->assertFalse( $out['machines']['enabled'] );
		$this->assertContains( 'machines-off', $keys );
		$this->assertNotContains( 'machines-endpoints', $keys );
	}

	/**
	 * The endpoint limit is the one an owner is most likely to misread — "166
	 * fetches" looks like page reads. It must be stated whenever logging is on.
	 */
	public function test_the_endpoint_limit_is_stated_whenever_machines_are_counted() {
		$out = Audience::from_stats( $this->stats() );
		$this->assertContains( 'machines-endpoints', array_column( $out['limits'], 'key' ) );
	}

	public function test_impostors_come_from_the_queue_tally() {
		$out = Audience::from_stats( $this->stats() );
		$this->assertSame( 3, $out['machines']['impostors'] );
	}

	public function test_impostors_fall_back_to_counting_verdicts() {
		$out = Audience::from_stats(
			$this->stats(
				array(
					'threats' => array(
						'sources' => array(
							array( 'verdict' => 'spoofed' ),
							array( 'verdict' => 'heavy' ),
							array( 'verdict' => 'spoofed' ),
						),
					),
				)
			)
		);
		$this->assertSame( 2, $out['machines']['impostors'] );
	}

	public function test_the_ai_leaderboard_is_capped_and_ordered() {
		$out = Audience::from_stats( $this->stats() );

		$this->assertCount( 3, $out['people']['ai']['top'] );
		$this->assertSame( 'ChatGPT', $out['people']['ai']['top'][0]['source'] );
		// …but the honest count of sources is the full one, not the slice.
		$this->assertSame( 4, $out['people']['ai']['sources'] );
	}

	public function test_a_referral_free_site_still_returns_a_whole_shape() {
		$out = Audience::from_stats( array( 'enabled' => true, 'window' => 7, 'totals' => array() ) );

		$this->assertSame( 7, $out['window'] );
		$this->assertSame( 0, $out['people']['ai']['visits'] );
		$this->assertSame( 0, $out['machines']['fetches'] );
		$this->assertIsArray( $out['limits'] );
	}
}
