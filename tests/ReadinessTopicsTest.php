<?php
/**
 * Readiness — the "Topics for AI" coverage check.
 *
 * Locks the grading seam (topics_row): auto-fill ON is always a pass (every
 * tagged post carries topics), auto-fill OFF grades on manual coverage against a
 * 50% floor, no content is a clean pass, and the whole feature being OFF warns
 * with a route to enable it.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Readiness;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ReadinessTopicsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Reflection-call the pure grading seam with known counts (skips the DB). */
	private function grade( int $total, int $with, bool $derive ): array {
		$m = new \ReflectionMethod( Readiness::class, 'topics_row' );
		\_af_accessible( $m );
		return $m->invoke( new Readiness( new Settings() ), $total, $with, $derive );
	}

	/** Reflection-call check_topics (its OFF path takes no DB). */
	private function check(): array {
		$m = new \ReflectionMethod( Readiness::class, 'check_topics' );
		\_af_accessible( $m );
		return $m->invoke( new Readiness( new Settings() ) );
	}

	public function test_feature_off_warns_and_routes_to_the_toggle() {
		\update_option( Settings::OPTION, array( 'enable_topics' => false ) );
		$row = $this->check();
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'ar-feat-enable_topics', $row['action']['anchor'] );
	}

	public function test_autofill_on_is_always_a_pass() {
		// Even with 0 manual topics, every tagged post already carries derived ones.
		$row = $this->grade( 10, 0, true );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertSame( '', $row['fix'] );
	}

	public function test_no_content_is_a_clean_pass() {
		$this->assertSame( 'pass', $this->grade( 0, 0, false )['status'] );
	}

	public function test_autofill_off_and_thin_manual_coverage_warns() {
		// 1 of 10 with auto-fill off → most posts say nothing about their subject.
		$row = $this->grade( 10, 1, false );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'ar-sec-topics', $row['action']['anchor'] );
	}

	public function test_autofill_off_but_good_manual_coverage_passes() {
		$row = $this->grade( 10, 6, false );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertSame( '', $row['fix'] );
	}
}
