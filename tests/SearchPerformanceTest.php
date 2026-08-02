<?php
/**
 * The performance summary — the same snapshot the worklist is carved from,
 * read for "how am I doing?".
 *
 * The load-bearing rule is DOUBLE-COUNTING: Bing reports the same traffic twice
 * (site-wide query rows AND per-page rows), so totals must count one view, not
 * both — while Google, which reports only query×page, must still total.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Search\Performance;
use PHPUnit\Framework\TestCase;

final class SearchPerformanceTest extends TestCase {

	private function row( $query, $pos, $impr, $clicks, $url = '', $page_id = 0 ) {
		return array(
			'query'       => $query,
			'page_url'    => $url,
			'page_id'     => $page_id,
			'clicks'      => $clicks,
			'impressions' => $impr,
			'position'    => $pos,
			'range_start' => '2026-06-01',
			'range_end'   => '2026-07-27',
		);
	}

	public function test_totals_count_the_site_wide_view_not_both_angles() {
		// Bing's shape: the same query reported site-wide AND against its page.
		$rows = array(
			$this->row( 'alpha', 4.0, 1000, 50 ),                                        // site-wide
			$this->row( 'alpha', 4.0, 1000, 50, 'https://example.test/a/', 7 ),          // same traffic, per page
		);
		$out = Performance::build( $rows );
		$this->assertSame( 1000, $out['totals']['impressions'], 'the same demand must not be counted twice' );
		$this->assertSame( 50, $out['totals']['clicks'] );
		$this->assertSame( 5.0, $out['totals']['ctr'] );
	}

	public function test_google_shape_totals_from_pages_when_no_site_wide_rows_exist() {
		// Google reports ONLY query×page — every row carries a page.
		$rows = array(
			$this->row( 'alpha', 4.0, 600, 30, 'https://example.test/a/', 7 ),
			$this->row( 'beta', 8.0, 400, 10, 'https://example.test/b/', 8 ),
		);
		$out = Performance::build( $rows );
		$this->assertSame( 1000, $out['totals']['impressions'] );
		$this->assertSame( 40, $out['totals']['clicks'] );
		$this->assertSame( 4.0, $out['totals']['ctr'] );
	}

	public function test_average_position_is_impression_weighted() {
		// One heavy query at 2 and one thin at 40: a plain mean would say 21.
		$rows = array(
			$this->row( 'heavy', 2.0, 9000, 900 ),
			$this->row( 'thin', 40.0, 1000, 1 ),
		);
		$out = Performance::build( $rows );
		$this->assertSame( 5.8, $out['totals']['position'], 'weighted by impressions, not a naive mean' );
	}

	public function test_top_lists_rank_by_impressions_and_carry_derived_figures() {
		$rows = array(
			$this->row( 'small', 5.0, 100, 5, 'https://example.test/s/', 1 ),
			$this->row( 'big', 3.0, 5000, 250, 'https://example.test/b/', 2 ),
		);
		$out = Performance::build( $rows );

		$this->assertSame( 'big', $out['top_queries'][0]['query'], 'most impressions first' );
		$this->assertSame( 5.0, $out['top_queries'][0]['ctr'] );
		$this->assertSame( 'https://example.test/b/', $out['top_pages'][0]['page_url'] );
		$this->assertSame( 2, $out['top_pages'][0]['page_id'] );
	}

	public function test_a_page_sums_its_own_queries() {
		$rows = array(
			$this->row( 'q1', 4.0, 600, 30, 'https://example.test/a/', 7 ),
			$this->row( 'q2', 6.0, 400, 10, 'https://example.test/a/', 7 ),
		);
		$out = Performance::build( $rows );
		$this->assertCount( 1, $out['top_pages'] );
		$this->assertSame( 1000, $out['top_pages'][0]['impressions'] );
		$this->assertSame( 40, $out['top_pages'][0]['clicks'] );
		$this->assertSame( 4.8, $out['top_pages'][0]['position'], 'the page position is weighted across its queries' );
	}

	public function test_an_empty_snapshot_answers_in_zeroes_not_errors() {
		$out = Performance::build( array() );
		$this->assertSame( 0, $out['totals']['impressions'] );
		$this->assertSame( 0.0, $out['totals']['ctr'] );
		$this->assertSame( array(), $out['top_queries'] );
		$this->assertSame( array(), $out['top_pages'] );
	}
	public function test_probe_rows_are_named_not_hidden() {
		// This screen is the raw record, so a scraper's `site:` probe stays in the
		// list — but a table headed "what was searched for" must not let it pass
		// for a person's question, and a click rate labelled "of the people who
		// saw you" must admit how much of it wasn't people.
		$rows = array(
			$this->row( 'intext:wp_insert_user site:.it', 4.0, 600, 0 ),
			$this->row( 'php throwable', 5.0, 400, 20 ),
		);

		$out = Performance::build( $rows );
		$this->assertSame( 60, $out['totals']['probeShare'], '600 of 1000 views were machines' );
		$this->assertSame( 1000, $out['totals']['impressions'], 'nothing is subtracted from the raw record' );

		$this->assertTrue( $out['top_queries'][0]['is_probe'], 'the operator row is flagged' );
		$this->assertFalse( $out['top_queries'][1]['is_probe'], 'a real search is not' );
	}

	public function test_a_clean_snapshot_reports_no_probe_share() {
		$out = Performance::build( array( $this->row( 'php throwable', 5.0, 400, 20 ) ) );
		$this->assertSame( 0, $out['totals']['probeShare'] );
		$this->assertFalse( $out['top_queries'][0]['is_probe'] );
	}

	public function test_the_top_lists_report_how_many_they_are_hiding() {
		// TOP is 8. A list of 8 with no total reads as "that is everything", which
		// on a site with hundreds of searches is the silent-cap trap.
		$rows = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$rows[] = $this->row( "q$i", 4.0, 100 - $i, 1, "https://example.test/p$i/", $i );
		}

		$out = Performance::build( $rows );
		$this->assertCount( 8, $out['top_queries'], 'the cap still applies' );
		$this->assertSame( 12, $out['counts']['queries'], 'and the screen can say what it hides' );
		$this->assertSame( 12, $out['counts']['pages'] );
	}

}
