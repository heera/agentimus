<?php
/**
 * Visits — grouping the request log into visits, and paging without splitting one.
 *
 * Rows go in newest first, as the store reads them. A visitor is agent + network +
 * user-agent; a visit ends after more than {@see Visits::GAP} seconds of silence.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Visits;
use PHPUnit\Framework\TestCase;

final class VisitsTest extends TestCase {

	/** @var int Next row id; rows are numbered oldest = lowest, like the table. */
	private $id = 0;

	/** A raw log row $seconds after a fixed start. */
	private function row( string $agent, int $seconds, string $network = 'example.net' ): array {
		return array(
			'id'       => ++$this->id,
			'agent'    => $agent,
			'network'  => $network,
			'ua'       => $agent . '/1.0',
			'endpoint' => 'page',
			'path'     => '/p' . $this->id . '/',
			'hit_at'   => gmdate( 'Y-m-d H:i:s', 1790000000 + $seconds ),
		);
	}

	/**
	 * Group a log (given oldest first, as written) one page at a time, the way
	 * Repository::visits() does: seed from the rows above the cursor, then add.
	 *
	 * @return array<int,array<int,array>> Pages of visits; each visit as its row ids.
	 */
	private function pages( array $log, int $per_page ): array {
		$desc   = array_reverse( $log );
		$pages  = array();
		$before = 0;
		do {
			$pager = new Visits( $per_page );
			if ( $before > 0 ) {
				foreach ( $desc as $row ) {
					if ( $row['id'] >= $before ) {
						$pager->seed( $row );
					}
				}
			}
			foreach ( $desc as $row ) {
				if ( $before > 0 && $row['id'] >= $before ) {
					continue;
				}
				if ( $pager->add( $row ) ) {
					break;
				}
			}
			$result  = $pager->result();
			$pages[] = array_map(
				static function ( $visit ) {
					return array_column( $visit['rows'], 'id' );
				},
				$result['visits']
			);
			$before = null !== $result['next'] ? $result['next'] + 1 : 0;
		} while ( $before > 0 );
		return $pages;
	}

	public function test_requests_within_the_gap_are_one_visit() {
		$log = array(
			$this->row( 'Orbbot', 0 ),
			$this->row( 'Orbbot', 10 ),
			$this->row( 'Orbbot', 40 ),
		);
		$this->assertSame( array( array( array( 3, 2, 1 ) ) ), $this->pages( $log, 10 ) );
	}

	public function test_a_silence_longer_than_the_gap_starts_a_new_visit() {
		$log = array(
			$this->row( 'Orbbot', 0 ),
			$this->row( 'Orbbot', Visits::GAP ), // Exactly the gap: still the same visit.
			$this->row( 'Orbbot', 2 * Visits::GAP + 1 ), // One second more: a new one.
		);
		$this->assertSame( array( array( array( 3 ), array( 2, 1 ) ) ), $this->pages( $log, 10 ) );
	}

	public function test_different_visitors_interleaved_stay_apart() {
		$log = array(
			$this->row( 'Orbbot', 0 ),
			$this->row( 'Bingbot', 5 ),
			$this->row( 'Orbbot', 10 ),
			$this->row( 'Orbbot', 20, 'other.net' ), // Same name, other network: another visitor.
		);
		$this->assertSame(
			array( array( array( 4 ), array( 3, 1 ), array( 2 ) ) ),
			$this->pages( $log, 10 )
		);
	}

	public function test_a_single_request_is_a_visit_of_one() {
		$this->assertSame( array( array( array( 1 ) ) ), $this->pages( array( $this->row( 'Googlebot', 0 ) ), 10 ) );
	}

	public function test_visits_are_ordered_by_their_newest_request() {
		$log = array(
			$this->row( 'A', 0 ),
			$this->row( 'B', 30 ),
			$this->row( 'A', 60 ), // A's visit ends after B's began, so A comes first.
		);
		$this->assertSame( array( array( array( 3, 1 ), array( 2 ) ) ), $this->pages( $log, 10 ) );
	}

	public function test_a_visit_is_never_split_across_pages() {
		// A long visit interleaved with short ones. One visit per page forces a page
		// boundary through the middle of the long one's rows, in id order.
		$log = array(
			$this->row( 'Long', 0 ),   // 1
			$this->row( 'B', 10 ),     // 2
			$this->row( 'Long', 60 ),  // 3
			$this->row( 'C', 70 ),     // 4
			$this->row( 'Long', 120 ), // 5
		);
		$pages = $this->pages( $log, 1 );
		$this->assertSame(
			array( array( array( 5, 3, 1 ) ), array( array( 4 ) ), array( array( 2 ) ) ),
			$pages,
			'Each visit appears once, whole, and no row is shown twice or lost.'
		);
	}

	public function test_paging_shows_every_row_exactly_once() {
		$log = array();
		foreach ( range( 0, 59 ) as $i ) {
			// Three visitors, irregular spacing, gaps above and below the limit.
			$log[] = $this->row( array( 'A', 'B', 'C' )[ $i % 3 ], $i * ( 0 === $i % 7 ? 200 : 45 ) );
		}
		usort(
			$log,
			static function ( $a, $b ) {
				return strcmp( $a['hit_at'], $b['hit_at'] ) ?: $a['id'] <=> $b['id'];
			}
		);
		// Renumber so ids follow time, as the recorder writes them.
		foreach ( $log as $i => &$row ) {
			$row['id'] = $i + 1;
		}
		unset( $row );

		$whole = $this->pages( $log, 1000 )[0];
		$paged = array_merge( ...$this->pages( $log, 2 ) );
		$this->assertSame( $whole, $paged, 'Paging two at a time gives the same visits as one big page.' );

		$ids = array_merge( ...$paged );
		sort( $ids );
		$this->assertSame( range( 1, 60 ), $ids );
	}

	public function test_counting_mode_finds_the_same_visits_as_the_full_grouping() {
		$log = array(
			$this->row( 'Long', 0 ),   // 1
			$this->row( 'B', 10 ),     // 2
			$this->row( 'Long', 60 ),  // 3
			$this->row( 'C', 70 ),     // 4
			$this->row( 'Long', 120 ), // 5
			$this->row( 'B', 400 ),    // 6
		);
		$full  = new Visits( 100 );
		$count = new Visits( 1, false );
		foreach ( array_reverse( $log ) as $row ) {
			$full->add( $row );
			$count->add( $row );
		}
		$heads = array_map(
			static function ( $visit ) {
				return (int) $visit['head']['id'];
			},
			$full->result()['visits']
		);
		$this->assertSame( array( 6, 5, 4, 2 ), $heads );
		$this->assertSame( $heads, $count->result()['visits'], 'Counting keeps only the heads, in the same order.' );
	}

	public function test_a_long_visit_lists_its_first_rows_and_counts_the_rest() {
		$log = array();
		foreach ( range( 0, Visits::ROW_CAP + 4 ) as $i ) {
			$log[] = $this->row( 'Googlebot', $i * 10 );
		}
		$pager = new Visits( 10 );
		foreach ( array_reverse( $log ) as $row ) {
			$pager->add( $row );
		}
		$visit = $pager->result()['visits'][0];
		$this->assertSame( Visits::ROW_CAP + 5, $visit['count'] );
		$this->assertCount( Visits::ROW_CAP, $visit['rows'] );
	}
}
