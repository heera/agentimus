<?php
/**
 * Recorder flood policy — the pure decision that keeps a burst of disposable
 * user-agents from drowning the bounded activity log, while never throttling a
 * genuinely-recognised crawler. The transient counter and RNG plumbing live in
 * record(); the policy itself is isolated in survives_flood() so it can be
 * exercised deterministically (the roll is injected).
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Recorder;
use PHPUnit\Framework\TestCase;

final class RecorderFloodTest extends TestCase {

	/** Below its applicable threshold, traffic is kept in full — sampling only ever
	 *  engages once a source's window count exceeds its budget. */
	public function test_traffic_at_or_below_its_threshold_is_kept() {
		$this->assertTrue( Recorder::survives_flood( 1, Recorder::FLOOD_THRESHOLD, 2 ) );
		$this->assertTrue( Recorder::survives_flood( Recorder::FLOOD_THRESHOLD, Recorder::FLOOD_THRESHOLD, 7 ) );
	}

	/** Over the threshold, a hit survives only on a winning roll (≈ 1 in FLOOD_SAMPLE)
	 *  — so the flood is sampled, not fully dropped. */
	public function test_a_flood_over_threshold_is_sampled_not_silenced() {
		$over = Recorder::FLOOD_THRESHOLD + 1;
		$this->assertTrue( Recorder::survives_flood( $over, Recorder::FLOOD_THRESHOLD, 1 ), 'winning roll keeps a sample' );
		$this->assertFalse( Recorder::survives_flood( $over, Recorder::FLOOD_THRESHOLD, 2 ), 'losing roll is dropped' );
		$this->assertFalse( Recorder::survives_flood( $over, Recorder::FLOOD_THRESHOLD, Recorder::FLOOD_SAMPLE ), 'losing roll is dropped' );
	}

	/**
	 * A recognised crawler gets a GENEROUS budget — its normal burst is kept in full —
	 * but it is NOT unlimited: past that budget it is sampled too, so a flood that
	 * pastes a known bot's name into a forged UA can no longer buy an unconditional
	 * INSERT-per-hit. This is the write-amplification fix.
	 */
	public function test_recognised_crawler_is_generous_but_not_unlimited() {
		// A real crawler's burst, right at the recognised budget: kept, whatever the roll.
		$this->assertTrue( Recorder::survives_flood( Recorder::RECOGNISED_THRESHOLD, Recorder::RECOGNISED_THRESHOLD, 2 ) );

		// A flood far past even the recognised budget is sampled, not unconditional.
		$huge = Recorder::RECOGNISED_THRESHOLD + 10_000;
		$this->assertTrue( Recorder::survives_flood( $huge, Recorder::RECOGNISED_THRESHOLD, 1 ), 'winning roll keeps a sample' );
		$this->assertFalse( Recorder::survives_flood( $huge, Recorder::RECOGNISED_THRESHOLD, 2 ), 'a spoofed-bot flood is now sampled, not unbounded' );

		// The recognised budget is meaningfully more generous than the default one.
		$this->assertGreaterThan( Recorder::FLOOD_THRESHOLD, Recorder::RECOGNISED_THRESHOLD );
	}
}
