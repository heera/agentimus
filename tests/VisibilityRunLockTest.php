<?php
/**
 * Visibility run mutex — a run spends real money on every (prompt × provider)
 * check, so two runs must never overlap (a second "Run now", or a scheduled tick
 * firing while a previous run is still going). The lock is durable across the
 * separate cron/loopback processes a run spans (option-backed) and self-heals a
 * lock left by a crashed run.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Visibility\Runner;
use Agentimus\Visibility\Settings;
use PHPUnit\Framework\TestCase;

final class VisibilityRunLockTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	public function test_a_second_overlapping_run_is_blocked_until_release() {
		$this->assertTrue( Runner::acquire_run_lock(), 'The first run should acquire the lock.' );
		$this->assertFalse( Runner::acquire_run_lock(), 'A second, overlapping run must be blocked.' );
		Runner::release_run_lock();
		$this->assertTrue( Runner::acquire_run_lock(), 'After release the next run should proceed.' );
	}

	public function test_a_fresh_lock_held_by_another_process_is_respected() {
		update_option( Runner::LOCK_OPTION, time(), false );
		$this->assertFalse( Runner::acquire_run_lock() );
	}

	public function test_a_stale_lock_from_a_crashed_run_is_stolen() {
		// A lock left behind longer ago than a run could possibly take.
		update_option( Runner::LOCK_OPTION, time() - Runner::LOCK_TTL - 1, false );
		$this->assertTrue( Runner::acquire_run_lock(), 'A stale lock must be stealable so monitoring cannot wedge permanently.' );
	}

	/* -- Per-run check ceiling (spend backstop) --------------------------- */

	public function test_the_check_ceiling_defaults_and_is_filterable() {
		$runner = new Runner( new Settings() );
		$this->assertSame( Runner::DEFAULT_MAX_CHECKS, $runner->max_checks_per_run() );

		add_filter( 'agentimus_visibility_max_checks_per_run', static function () { return 5; } );
		$this->assertSame( 5, $runner->max_checks_per_run() );
	}

	public function test_a_nonpositive_ceiling_filter_falls_back_to_the_default() {
		$runner = new Runner( new Settings() );
		add_filter( 'agentimus_visibility_max_checks_per_run', static function () { return 0; } );
		$this->assertSame( Runner::DEFAULT_MAX_CHECKS, $runner->max_checks_per_run() );
	}
}
