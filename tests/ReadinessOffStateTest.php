<?php
/**
 * Readiness — the neutral 'off' state.
 *
 * A switched-off feature's shadow-row ("the map is off, so there is nothing to
 * measure") used to say 'pass' — a green tick and a full score point for a
 * feature that isn't running, on a score where 100 is earned, never rounded
 * into. These lock the fourth state end to end: the full-size shadow-row reads
 * 'off' (its words/entity-image siblings are locked in their own files), and
 * normalize() must let 'off' through — it coerces unknown statuses to 'warn',
 * so without the whitelist entry every off row would surface as a false alarm.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Readiness;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ReadinessOffStateTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Reflection-call a private check (the off branches read only settings). */
	private function check( string $method ): array {
		$m = new \ReflectionMethod( Readiness::class, $method );
		\_af_accessible( $m );
		return (array) $m->invoke( new Readiness( new Settings() ) );
	}

	public function test_full_size_stands_down_to_off_when_the_full_text_file_is_off() {
		// check_llms_full() already warns about the switch — this row is its
		// measured shadow, and with nothing measured, nothing may be earned.
		\update_option( Settings::OPTION, array( 'enable_llms_full' => false ) );
		$row = $this->check( 'check_llms_full_size' );
		$this->assertSame( 'off', $row['status'] );
		$this->assertSame( '', $row['fix'] );
	}

	public function test_normalize_preserves_off_instead_of_coercing_it_to_warn() {
		$m = new \ReflectionMethod( Readiness::class, 'normalize' );
		\_af_accessible( $m );
		$rows = $m->invoke(
			new Readiness( new Settings() ),
			array(
				array( 'id' => 'llms_words', 'label' => 'Substance', 'status' => 'off', 'detail' => 'Off.' ),
				array( 'id' => 'mystery', 'label' => 'Mystery', 'status' => 'sideways', 'detail' => '' ),
			)
		);
		$this->assertSame( 'off', $rows[0]['status'], "'off' is a first-class status, not an unknown" );
		$this->assertSame( 'warn', $rows[1]['status'], 'a truly unknown status still surfaces for attention' );
	}
}
