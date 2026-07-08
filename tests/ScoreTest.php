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

	/* -- band ------------------------------------------------------------- */

	public function test_band_thresholds() {
		$this->assertSame( 'Excellent', Score::band( 85 ) );
		$this->assertSame( 'Strong', Score::band( 70 ) );
		$this->assertSame( 'Fair', Score::band( 50 ) );
		$this->assertSame( 'Needs work', Score::band( 49 ) );
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
