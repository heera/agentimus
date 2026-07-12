<?php
/**
 * /llms-full.txt size/perf guardrails — the pure logic that protects a large
 * site from an unbounded cold-cache generation:
 *   - the llms_full_max_kb clamp (Settings), and
 *   - the wall-clock deadline derivation (LlmsText).
 *
 * The byte-budget loop, per-item cap and truncation note are exercised end-to-end
 * against a real multi-type site (curl /llms-full.txt with a tiny budget); they
 * need the post + markdown stack and aren't unit-isolated here.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\LlmsText;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class LlmsFullBudgetTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* -- The byte-budget clamp (Settings::sanitize) ----------------------- */

	public function test_max_kb_defaults_under_the_object_cache_item_ceiling() {
		// Deliberately UNDER 1024, not at it: a ~1 MB body sits exactly at the common memcached item
		// ceiling, so with key + serialization overhead the object cache silently rejects it and
		// every request re-runs the full build. The default must leave headroom so the doc caches.
		$clean = ( new Settings() )->sanitize( array() );
		$this->assertSame( 900, $clean['llms_full_max_kb'] );
		$this->assertLessThan( 1024, $clean['llms_full_max_kb'], 'The default budget must stay under the 1 MB object-cache item limit.' );
	}

	public function test_max_kb_clamps_to_floor_and_ceiling() {
		$s = new Settings();
		$this->assertSame( 64, $s->sanitize( array( 'llms_full_max_kb' => 1 ) )['llms_full_max_kb'] );
		$this->assertSame( 64, $s->sanitize( array( 'llms_full_max_kb' => -999 ) )['llms_full_max_kb'] );
		$this->assertSame( 20480, $s->sanitize( array( 'llms_full_max_kb' => 999999 ) )['llms_full_max_kb'] );
	}

	public function test_max_kb_passes_a_value_within_range() {
		$this->assertSame( 2048, ( new Settings() )->sanitize( array( 'llms_full_max_kb' => 2048 ) )['llms_full_max_kb'] );
	}

	/* -- The wall-clock deadline (LlmsText::generation_deadline) ---------- */

	/**
	 * @dataProvider deadlines
	 */
	public function test_generation_deadline( $max_execution_time, $expected ) {
		$orig = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', (string) $max_execution_time );

		$llms   = new LlmsText( new Settings() );
		$method = new \ReflectionMethod( LlmsText::class, 'generation_deadline' );
		$method->setAccessible( true );

		$this->assertSame( (float) $expected, $method->invoke( $llms ) );

		ini_set( 'max_execution_time', (string) $orig );
	}

	public function deadlines(): array {
		return array(
			'unlimited (0) caps at 20s' => array( 0, 20.0 ),
			'tiny limit floors at 5s'   => array( 4, 5.0 ),
			'typical 30s -> 15s'        => array( 30, 15.0 ),
			'large 120s ceils at 20s'   => array( 120, 20.0 ),
		);
	}

	/* -- The one-shot default migration ------------------------------------ */

	public function test_migration_nudges_the_uncustomised_old_default() {
		// An existing install persisted the old 1024 default; ensure_defaults() never overwrites it.
		update_option( Settings::OPTION, array( 'llms_full_max_kb' => 1024 ) );
		( new Settings() )->maybe_migrate_defaults();
		$this->assertSame( 900, ( new Settings() )->get( 'llms_full_max_kb' ), 'The un-customised old default must be lowered under the object-cache ceiling.' );
	}

	public function test_migration_leaves_a_user_chosen_value_alone() {
		update_option( Settings::OPTION, array( 'llms_full_max_kb' => 2048 ) );
		( new Settings() )->maybe_migrate_defaults();
		$this->assertSame( 2048, ( new Settings() )->get( 'llms_full_max_kb' ), 'A value the owner chose must never be migrated.' );
	}

	public function test_migration_does_not_fight_a_deliberate_re_choice() {
		// Once the flag is set, re-choosing the old number must stick — we do not re-nudge on boot.
		$s = new Settings();
		update_option( Settings::OPTION, array( 'llms_full_max_kb' => 1024 ) );
		$s->maybe_migrate_defaults();                 // 1024 -> 900, flag set
		update_option( Settings::OPTION, array( 'llms_full_max_kb' => 1024 ) ); // owner re-chooses 1024
		$s->maybe_migrate_defaults();                 // must NOT touch it again
		$this->assertSame( 1024, ( new Settings() )->get( 'llms_full_max_kb' ), 'A deliberate re-choice of the old value must be respected.' );
	}
}
