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
			array( 'id' => 'public', 'status' => 'pass' ),  // 1.0
			array( 'id' => 'llms', 'status' => 'warn' ),    // 0.5
			array( 'id' => 'schema', 'status' => 'fail' ),  // 0.0
			array( 'id' => 'about', 'status' => 'pass' ),   // not a SERVE id — ignored
		);
		// (1 + 0.5 + 0) / 3 = 0.5 → 50.
		$this->assertSame( 50, Score::rows_score( $rows, Score::SERVE_IDS ) );
	}

	public function test_rows_score_is_null_when_no_ids_present() {
		$this->assertNull( Score::rows_score( array(), Score::SERVE_IDS ) );
		$this->assertNull( Score::rows_score( array( array( 'id' => 'nope', 'status' => 'pass' ) ), Score::SERVE_IDS ) );
	}

	/* -- blend ------------------------------------------------------------ */

	public function test_blend_is_full_weighting_when_all_present() {
		$this->assertSame( 100, Score::blend( array( 'serve' => 100, 'structure' => 100, 'optimize' => 100, 'measure' => 100 ) ) );
	}

	public function test_blend_redistributes_a_missing_pillars_weight() {
		// measure null → normalise over 30+25+30 = 85.
		// (80*30 + 60*25 + 90*30) / 85 = 6600/85 = 77.6 → 78.
		$this->assertSame( 78, Score::blend( array( 'serve' => 80, 'structure' => 60, 'optimize' => 90, 'measure' => null ) ) );
	}

	public function test_blend_is_zero_when_nothing_has_data() {
		$this->assertSame( 0, Score::blend( array( 'serve' => null, 'structure' => null, 'optimize' => null, 'measure' => null ) ) );
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
			array( 'id' => 'a', 'pillar' => 'measure', 'severity' => 'info' ),
			array( 'id' => 'b', 'pillar' => 'serve', 'severity' => 'warn' ),      // warn, weight 30
			array( 'id' => 'c', 'pillar' => 'structure', 'severity' => 'fail' ),  // fail → first
			array( 'id' => 'd', 'pillar' => 'optimize', 'severity' => 'content' ),
			array( 'id' => 'e', 'pillar' => 'optimize', 'severity' => 'warn' ),   // warn, weight 30 → ties b, keeps order
		);
		$ids = array();
		foreach ( Score::rank( $actions ) as $a ) {
			$ids[] = $a['id'];
		}
		$this->assertSame( array( 'c', 'b', 'e', 'd', 'a' ), $ids );
	}
}
