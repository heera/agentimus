<?php
/**
 * AEO/GEO Score — the pure aggregation seams (Phase 1 · T3).
 *
 * Locks the maths that turns signals into one number and one ordered plan: per-pillar
 * pass/warn/fail averaging, the weight-redistributing blend when a pillar has no data,
 * the score bands, and the impact-then-ease ranking of actions.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Score;
use PHPUnit\Framework\TestCase;

final class ScoreTest extends TestCase {

	/* -- rows_score ------------------------------------------------------- */

	public function test_rows_score_averages_pass_warn_fail() {
		$rows = array(
			array( 'id' => 'public', 'status' => 'pass' ),      // 1.0
			array( 'id' => 'permalinks', 'status' => 'warn' ),  // 0.5
			array( 'id' => 'robots', 'status' => 'fail' ),      // 0.0
			array( 'id' => 'about', 'status' => 'pass' ),       // Trusted — ignored for Findable
		);
		// (1 + 0.5 + 0) / 3 = 0.5 → 50.
		$this->assertSame( 50, Score::rows_score( $rows, Score::FINDABLE_IDS ) );
	}

	public function test_rows_score_is_null_when_no_ids_present() {
		$this->assertNull( Score::rows_score( array(), Score::FINDABLE_IDS ) );
		$this->assertNull( Score::rows_score( array( array( 'id' => 'nope', 'status' => 'pass' ) ), Score::FINDABLE_IDS ) );
	}

	/* -- blend ------------------------------------------------------------ */

	public function test_blend_is_full_weighting_when_all_present() {
		$this->assertSame( 100, Score::blend( array( 'findable' => 100, 'readable' => 100, 'trusted' => 100, 'optimized' => 100, 'cited' => 100 ) ) );
	}

	public function test_blend_redistributes_a_missing_rungs_weight() {
		// cited null → normalise over 15+15+25+30 = 85.
		// (100*15 + 100*15 + 100*25 + 50*30) / 85 = 7000/85 = 82.35 → 82.
		$this->assertSame( 82, Score::blend( array( 'findable' => 100, 'readable' => 100, 'trusted' => 100, 'optimized' => 50, 'cited' => null ) ) );
	}

	public function test_blend_is_zero_when_nothing_has_data() {
		$this->assertSame( 0, Score::blend( array( 'findable' => null, 'readable' => null, 'trusted' => null, 'optimized' => null, 'cited' => null ) ) );
	}

	/**
	 * 100 is EARNED, never rounded into. heera.it's live card: three perfect
	 * pillars + Optimized 99 = 99.65, which round() called 100 — beside a rung
	 * column reading "4 to fix". A composite may only say 100 when every
	 * pillar it counted actually is.
	 */
	public function test_blend_never_rounds_up_to_a_hundred() {
		// (100*15 + 100*15 + 100*25 + 99*30) / 85 = 99.65 → 99, not 100.
		$this->assertSame( 99, Score::blend( array( 'findable' => 100, 'readable' => 100, 'trusted' => 100, 'optimized' => 99, 'cited' => null ) ) );
		// Ordinary rounding below the ceiling is untouched: 82.35 → 82 stays.
		$this->assertSame( 82, Score::blend( array( 'findable' => 100, 'readable' => 100, 'trusted' => 100, 'optimized' => 50, 'cited' => null ) ) );
	}

	/* -- band ------------------------------------------------------------- */

	public function test_band_thresholds() {
		$this->assertSame( 'Excellent', Score::band( 85 ) );
		$this->assertSame( 'Strong', Score::band( 70 ) );
		$this->assertSame( 'Fair', Score::band( 50 ) );
		$this->assertSame( 'Needs work', Score::band( 49 ) );
	}

	/* -- cited_state ------------------------------------------------------ */

	public function test_cited_state_is_unset_without_an_active_provider() {
		// No provider trumps everything — even a completed, successful run reads as unset,
		// because the number is stale and can no longer update.
		$this->assertSame( 'unset', Score::cited_state( false, 0, 0, 0, false ) );
		$this->assertSame( 'unset', Score::cited_state( false, 5000, 3, 0, false ) );
	}

	public function test_cited_state_is_never_before_a_run_or_on_an_empty_run() {
		// Set up but not yet run...
		$this->assertSame( 'never', Score::cited_state( true, 0, 0, 0, false ) );
		// ...and a run that produced no rows at all (no successes, no errors) never measured.
		$this->assertSame( 'never', Score::cited_state( true, 5000, 0, 0, false ) );
	}

	public function test_cited_state_is_failed_when_every_check_errored() {
		// A run happened, zero successes, something errored → a real failure, NOT "never".
		$this->assertSame( 'failed', Score::cited_state( true, 5000, 0, 3, false ) );
		// Failure outranks staleness — a broken key is the actionable fact regardless of age.
		$this->assertSame( 'failed', Score::cited_state( true, 5000, 0, 3, true ) );
	}

	public function test_cited_state_is_stale_or_ok_for_a_measured_run() {
		$this->assertSame( 'ok', Score::cited_state( true, 5000, 3, 0, false ) );
		$this->assertSame( 'stale', Score::cited_state( true, 5000, 3, 0, true ) );
		// A partial run (some checks errored) still counts as measured — 'ok', not 'failed'.
		$this->assertSame( 'ok', Score::cited_state( true, 5000, 2, 1, false ) );
		$this->assertSame( 'stale', Score::cited_state( true, 5000, 2, 1, true ) );
	}

	/* -- rank ------------------------------------------------------------- */

	public function test_rank_orders_by_severity_then_weight_then_stable() {
		$actions = array(
			array( 'id' => 'a', 'pillar' => 'cited', 'severity' => 'info' ),
			array( 'id' => 'b', 'pillar' => 'findable', 'severity' => 'warn' ),   // warn, weight 15
			array( 'id' => 'c', 'pillar' => 'trusted', 'severity' => 'fail' ),    // fail → first
			array( 'id' => 'd', 'pillar' => 'optimized', 'severity' => 'content' ),
			array( 'id' => 'e', 'pillar' => 'optimized', 'severity' => 'warn' ),  // warn, weight 30 → outranks b
		);
		$ids = array();
		foreach ( Score::rank( $actions ) as $a ) {
			$ids[] = $a['id'];
		}
		// fail (c); then warns by weight desc — e (optimized 30) before b (findable 15);
		// then content (d); then info (a).
		$this->assertSame( array( 'c', 'e', 'b', 'd', 'a' ), $ids );
	}
}
