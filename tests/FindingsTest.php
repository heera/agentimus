<?php
/**
 * The front-door findings aggregator ({@see Findings}).
 *
 * What matters here is not that each source produces the right sentence — each
 * subsystem has its own tests for that — but that the SCREEN survives. This is
 * the plugin's first screen, so a single broken subsystem must cost its own
 * finding and nothing else, an empty list must be distinguishable from a broken
 * one, and the ranking must be a stated contract rather than an accident of
 * which source ran first.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Findings;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class FindingsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function payload() {
		return ( new Findings( new Settings() ) )->all();
	}

	/* ---- the screen always renders ----------------------------------------- */

	/**
	 * No database, no tables, no search connection — every source throws. The
	 * payload must still come back whole, because a front door that a single bad
	 * query can blank is worse than no front door.
	 */
	public function test_the_payload_survives_every_source_failing() {
		$out = $this->payload();

		$this->assertArrayHasKey( 'findings', $out );
		$this->assertArrayHasKey( 'clear', $out );
		$this->assertArrayHasKey( 'failed', $out );
		$this->assertArrayHasKey( 'counts', $out );
		$this->assertIsArray( $out['findings'] );
		$this->assertIsArray( $out['failed'] );
	}

	/** A source that blew up is NAMED, so a missing finding is visible not silent. */
	public function test_a_broken_source_is_reported_rather_than_hidden() {
		$out = $this->payload();

		// In unit land there is no wpdb, so the review-queue sources cannot run.
		$this->assertNotEmpty( $out['failed'], 'a source that throws must be listed' );
		$this->assertContainsOnly( 'string', $out['failed'] );
	}

	/** Tier counts describe the rows actually returned, never a source's ambition. */
	public function test_counts_match_the_rows_returned() {
		$out    = $this->payload();
		$counts = array( Findings::URGENT => 0, Findings::WORTH => 0, Findings::LATER => 0 );
		foreach ( $out['findings'] as $row ) {
			++$counts[ $row['tier'] ];
		}
		$this->assertSame( $counts, $out['counts'] );
	}

	/* ---- the ranking is a contract ----------------------------------------- */

	/**
	 * The order the front door argues for: a decision only the owner can make
	 * outranks a chore, and traffic already being earned and lost outranks a
	 * site-wide clean-up. Locked here so re-ranking is a deliberate edit.
	 */
	public function test_the_ranking_contract_holds() {
		$w = Findings::WEIGHTS;

		$this->assertGreaterThan( $w['content_issues'], $w['config_gap'], 'a broken setup outranks a chore' );
		$this->assertGreaterThan( $w['content_issues'], $w['near_page_one'], 'traffic already earned outranks clean-up' );
		$this->assertGreaterThan( $w['never_measured'], $w['seen_not_chosen'], 'a lost click outranks an unrun report' );
	}

	/**
	 * The review queue is NOT a finding at all (his call, 2026-08-09). The nav
	 * bell already carries its live count and opens the queue itself, so a row
	 * on the front door was the same information twice — each copy pointing at
	 * the other. Same reasoning that earlier collapsed three rows into one.
	 */
	public function test_the_review_queue_is_not_a_finding() {
		foreach ( array( 'review_queue', 'forged_identity', 'waiting_clients', 'dead_identity_url' ) as $gone ) {
			$this->assertArrayNotHasKey( $gone, Findings::WEIGHTS, "$gone must not be a front-door row" );
		}
	}

	/** Every weight is distinct, so the sort can never be decided by source order. */
	public function test_no_two_findings_share_a_weight() {
		$w = array_values( Findings::WEIGHTS );
		$this->assertSame( count( $w ), count( array_unique( $w ) ) );
	}

	/** Sorted highest-weight first, whatever order the sources ran in. */
	public function test_findings_come_back_ranked() {
		\add_filter( 'agentimus_findings', function ( $payload ) {
			return $payload;
		} );
		$rows    = $this->payload()['findings'];
		$weights = array_map( function ( $r ) { return (int) $r['weight']; }, $rows );
		$sorted  = $weights;
		rsort( $sorted );
		$this->assertSame( $sorted, $weights );
	}

	/* ---- shape of a row ---------------------------------------------------- */

	/** Every row a screen has to render carries the fields the screen needs. */
	public function test_every_row_is_renderable() {
		\add_filter( 'agentimus_findings', function ( $p ) {
			$p['findings'][] = array(
				'id'       => 'x',
				'tier'     => Findings::WORTH,
				'weight'   => 1,
				'title'    => 'A thing',
				'why'      => 'Because.',
				'evidence' => array( 'a' ),
				'action'   => array( 'label' => 'Go', 'tab' => 'settings', 'view' => '' ),
			);
			return $p;
		} );
		foreach ( $this->payload()['findings'] as $row ) {
			$this->assertArrayHasKey( 'id', $row );
			$this->assertArrayHasKey( 'tier', $row );
			$this->assertArrayHasKey( 'title', $row );
			$this->assertArrayHasKey( 'why', $row );
			$this->assertIsArray( $row['evidence'] );
			$this->assertContains( $row['tier'], array( Findings::URGENT, Findings::WORTH, Findings::LATER ) );
		}
	}

	/* ---- the filter -------------------------------------------------------- */

	/** An add-on can contribute a finding to the front door. */
	public function test_the_filter_can_add_a_finding() {
		\add_filter( 'agentimus_findings', function ( $p ) {
			$p['findings'][] = array(
				'id'     => 'acme_thing',
				'tier'   => Findings::URGENT,
				'weight' => 999,
				'title'  => 'Acme needs a look',
				'why'    => 'It does.',
				'evidence' => array(),
				'action' => null,
			);
			return $p;
		} );

		$ids = array_map( function ( $r ) { return $r['id']; }, $this->payload()['findings'] );
		$this->assertContains( 'acme_thing', $ids );
	}

	/** Silencing the front door entirely is a supported thing to want. */
	public function test_the_filter_can_empty_the_list() {
		\add_filter( 'agentimus_findings', function ( $p ) {
			$p['findings'] = array();
			return $p;
		} );
		$this->assertSame( array(), $this->payload()['findings'] );
	}

	/** A filter returning nonsense falls back to an empty payload, never a fatal. */
	public function test_a_filter_returning_garbage_cannot_break_the_screen() {
		\add_filter( 'agentimus_findings', function () {
			return 'not-an-array';
		} );
		$out = $this->payload();
		$this->assertIsArray( $out );
		$this->assertSame( array(), $out['findings'] );
	}

	/* ---- caps -------------------------------------------------------------- */

	/** The front door is a front door, not the whole worklist. */
	public function test_the_list_is_capped() {
		$this->assertLessThanOrEqual( Findings::MAX, count( $this->payload()['findings'] ) );
		$this->assertGreaterThan( 0, Findings::MAX );
	}
}
