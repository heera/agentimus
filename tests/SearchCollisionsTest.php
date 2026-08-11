<?php
/**
 * The collision detector — searches several pages are splitting.
 *
 * Locks the honesty rules: a collision is only asserted from the engine's own
 * rows (thin data never accuses a page); one page owning a query is a result,
 * not a problem; the winner is picked by clicks then position, the same maths
 * the screen states; a page set aside stops being counted against a search;
 * operator probes never form a collision.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Search\Collisions;
use PHPUnit\Framework\TestCase;

final class SearchCollisionsTest extends TestCase {

	private function row( $query, $url, $page_id, $impr, $clicks, $pos ) {
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

	/** Two pages splitting one real query — the canonical case. */
	private function split_bed() {
		return array(
			$this->row( 'wordpress llms txt', 'https://example.test/guide/', 1, 120, 7, 4.0 ),
			$this->row( 'wordpress llms txt', 'https://example.test/notes/', 2, 60, 2, 9.0 ),
		);
	}

	public function test_two_pages_on_one_query_form_a_collision() {
		$out = Collisions::build( $this->split_bed() );

		$this->assertCount( 1, $out );
		$this->assertSame( 'wordpress llms txt', $out[0]['query'] );
		$this->assertSame( 180, $out[0]['shown'] );
		$this->assertSame( 9, $out[0]['clicks'] );
		$this->assertCount( 2, $out[0]['pages'] );
	}

	public function test_the_winner_is_most_clicks_then_better_position() {
		$out = Collisions::build( $this->split_bed() );

		$this->assertTrue( $out[0]['pages'][0]['winner'], 'most clicks wins' );
		$this->assertSame( 1, $out[0]['pages'][0]['page_id'] );
		$this->assertFalse( $out[0]['pages'][1]['winner'] );

		// Clicks tied → the better (lower) position takes it.
		$tied = Collisions::build( array(
			$this->row( 'tied query', 'https://example.test/a/', 1, 100, 3, 12.0 ),
			$this->row( 'tied query', 'https://example.test/b/', 2, 100, 3, 5.0 ),
		) );
		$this->assertSame( 2, $tied[0]['pages'][0]['page_id'], 'position breaks the tie' );
	}

	public function test_thin_data_never_accuses_a_page() {
		// Same split shape, but under the MIN_SHOWN floor in total.
		$out = Collisions::build( array(
			$this->row( 'tiny query', 'https://example.test/a/', 1, 30, 1, 4.0 ),
			$this->row( 'tiny query', 'https://example.test/b/', 2, 15, 0, 9.0 ),
		) );
		$this->assertSame( array(), $out );
	}

	public function test_a_page_owning_the_query_is_a_result_not_a_problem() {
		$out = Collisions::build( array(
			$this->row( 'owned query', 'https://example.test/a/', 1, 900, 40, 2.0 ),
			$this->row( 'owned query', 'https://example.test/b/', 2, 100, 1, 30.0 ),
		) );
		$this->assertSame( array(), $out, 'the top page holds 90% — nothing to fix' );
	}

	public function test_a_stray_row_below_the_share_floor_does_not_compete() {
		// Third page holds ~3% of the showings: it must neither form the
		// collision nor appear in it.
		$rows   = $this->split_bed();
		$rows[] = $this->row( 'wordpress llms txt', 'https://example.test/stray/', 3, 6, 0, 40.0 );

		$out = Collisions::build( $rows );
		$this->assertCount( 1, $out );
		$this->assertCount( 2, $out[0]['pages'] );
	}

	public function test_a_set_aside_page_stops_being_counted_against_the_search() {
		// Parking the loser dissolves the collision entirely.
		$out = Collisions::build( $this->split_bed(), array( 2 ) );
		$this->assertSame( array(), $out );

		// By URL key too, for pages that never resolved to a post.
		$by_url = Collisions::build( $this->split_bed(), array(), array( 'https://example.test/notes' ) );
		$this->assertSame( array(), $by_url );
	}

	public function test_operator_probes_never_form_a_collision() {
		$out = Collisions::build( array(
			$this->row( 'site:example.test llms', 'https://example.test/a/', 1, 500, 0, 1.0 ),
			$this->row( 'site:example.test llms', 'https://example.test/b/', 2, 400, 0, 2.0 ),
		) );
		$this->assertSame( array(), $out );
	}

	public function test_site_wide_rows_without_a_page_are_ignored() {
		$rows   = $this->split_bed();
		$rows[] = $this->row( 'wordpress llms txt', '', 0, 5000, 100, 3.0 );

		$out = Collisions::build( $rows );
		$this->assertSame( 180, $out[0]['shown'], 'the site-wide row describes the same traffic without a page' );
	}

	public function test_heaviest_split_first() {
		$rows = array_merge(
			$this->split_bed(),
			array(
				$this->row( 'bigger split', 'https://example.test/x/', 5, 400, 10, 5.0 ),
				$this->row( 'bigger split', 'https://example.test/y/', 6, 300, 4, 8.0 ),
			)
		);

		$out = Collisions::build( $rows );
		$this->assertSame( 'bigger split', $out[0]['query'] );
		$this->assertSame( 'wordpress llms txt', $out[1]['query'] );
	}

	public function test_shares_sum_the_query_and_positions_carry_through() {
		$out  = Collisions::build( $this->split_bed() );
		$page = $out[0]['pages'][0];

		$this->assertEqualsWithDelta( 0.667, $page['share'], 0.001 );
		$this->assertSame( 4.0, $page['position'] );
		$this->assertSame( 4.0, $out[0]['best'] );
		$this->assertSame( 9.0, $out[0]['worst'] );
	}
}
