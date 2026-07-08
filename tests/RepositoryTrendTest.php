<?php
/**
 * Repository::trend_pct — the within-window volume trend behind the breakdown sparklines'
 * growth/down arrow. Retention == reporting window, so there is no prior period: the trend
 * compares the recent half of a daily series against the earlier half. Pure (no DB/DNS),
 * so it is exercised directly.
 *
 * Pins: rise, fall, flat, the "new" signal (no earlier baseline), the odd-length split,
 * the ±999 cap, and the too-short guard.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Repository;
use PHPUnit\Framework\TestCase;

final class RepositoryTrendTest extends TestCase {

	public function test_doubling_recent_half_reads_as_growth() {
		// earlier = 4, recent = 8 → +100%.
		$this->assertSame( 100, Repository::trend_pct( array( 1, 1, 1, 1, 2, 2, 2, 2 ) ) );
	}

	public function test_falling_recent_half_reads_as_decline() {
		// earlier = 8, recent = 4 → -50%.
		$this->assertSame( -50, Repository::trend_pct( array( 2, 2, 2, 2, 1, 1, 1, 1 ) ) );
	}

	public function test_steady_series_is_zero() {
		$this->assertSame( 0, Repository::trend_pct( array( 3, 3, 3, 3, 3, 3 ) ) );
	}

	public function test_no_earlier_baseline_is_new() {
		// Nothing in the earlier half, activity in the recent half → null ("new").
		$this->assertNull( Repository::trend_pct( array( 0, 0, 0, 5, 6, 7 ) ) );
	}

	public function test_all_earlier_no_recent_is_full_decline() {
		$this->assertSame( -100, Repository::trend_pct( array( 5, 5, 5, 0, 0, 0 ) ) );
	}

	public function test_empty_and_all_zero_are_zero_not_new() {
		$this->assertSame( 0, Repository::trend_pct( array() ) );
		$this->assertSame( 0, Repository::trend_pct( array( 0 ) ) );
		$this->assertSame( 0, Repository::trend_pct( array( 0, 0, 0, 0 ) ) );
	}

	public function test_odd_length_drops_the_middle_day() {
		// n = 5, half = 2: earlier = [1,1] = 2, recent = [3,3] = 6, middle (9) dropped → +200%.
		$this->assertSame( 200, Repository::trend_pct( array( 1, 1, 9, 3, 3 ) ) );
	}

	public function test_extreme_jump_is_capped() {
		// earlier = 1, recent = 1000 → +99900%, capped to +999.
		$this->assertSame( 999, Repository::trend_pct( array( 1, 1000 ) ) );
	}
}
