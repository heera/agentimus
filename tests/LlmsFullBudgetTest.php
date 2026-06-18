<?php
/**
 * /llms-full.txt size/perf guardrails — the pure logic that protects a large
 * site from an unbounded cold-cache generation:
 *   - the llms_full_max_kb clamp (Settings), and
 *   - the wall-clock deadline derivation (Endpoints).
 *
 * The byte-budget loop, per-item cap and truncation note are exercised end-to-end
 * against a real multi-type site (curl /llms-full.txt with a tiny budget); they
 * need the post + markdown stack and aren't unit-isolated here.
 *
 * @package HeeraAgentDiscovery\Tests
 */

namespace HeeraAgentDiscovery\Tests;

use HeeraAgentDiscovery\Endpoints;
use HeeraAgentDiscovery\Settings;
use PHPUnit\Framework\TestCase;

final class LlmsFullBudgetTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* -- The byte-budget clamp (Settings::sanitize) ----------------------- */

	public function test_max_kb_defaults_to_one_megabyte() {
		$clean = ( new Settings() )->sanitize( array() );
		$this->assertSame( 1024, $clean['llms_full_max_kb'] );
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

	/* -- The wall-clock deadline (Endpoints::generation_deadline) --------- */

	/**
	 * @dataProvider deadlines
	 */
	public function test_generation_deadline( $max_execution_time, $expected ) {
		$orig = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', (string) $max_execution_time );

		$endpoints = new Endpoints( new Settings() );
		$method    = new \ReflectionMethod( Endpoints::class, 'generation_deadline' );
		$method->setAccessible( true );

		$this->assertSame( (float) $expected, $method->invoke( $endpoints ) );

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
}
