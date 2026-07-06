<?php
/**
 * Readiness: the debug-logging verdict. The pure decision (status + reason code from the
 * resolved facts) is split out of check_debug_logging() so it tests without touching PHP's
 * WP_DEBUG* constants. Worst-first, and a free pass anywhere debug is off or the site isn't
 * production.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Readiness;
use PHPUnit\Framework\TestCase;

final class ReadinessDebugTest extends TestCase {

	/** Debug off → pass, regardless of the other flags or environment. */
	public function test_debug_off_passes() {
		$this->assertSame( array( 'pass', 'off' ), Readiness::debug_verdict( true, false, true, true, true ) );
		$this->assertSame( array( 'pass', 'off' ), Readiness::debug_verdict( false, false, false, false, false ) );
	}

	/** Debug on but not production → pass (expected on dev / local / staging). */
	public function test_debug_on_outside_production_passes() {
		$this->assertSame( array( 'pass', 'dev' ), Readiness::debug_verdict( false, true, true, true, true ) );
	}

	/** Production + errors rendered on screen → the worst case: fail. */
	public function test_display_in_production_fails() {
		$this->assertSame( array( 'fail', 'display' ), Readiness::debug_verdict( true, true, true, true, true ) );
	}

	/** Production + a web-reachable log (display off) → fail (likely downloadable). */
	public function test_web_reachable_log_fails() {
		$this->assertSame( array( 'fail', 'log_web' ), Readiness::debug_verdict( true, true, false, true, true ) );
	}

	/** Production + logging to a path outside the web root → warn (noisy, not exposed). */
	public function test_private_log_warns() {
		$this->assertSame( array( 'warn', 'log_private' ), Readiness::debug_verdict( true, true, false, true, false ) );
	}

	/** Production + debug on but neither displaying nor logging → warn. */
	public function test_bare_debug_on_in_production_warns() {
		$this->assertSame( array( 'warn', 'on' ), Readiness::debug_verdict( true, true, false, false, false ) );
	}
}
