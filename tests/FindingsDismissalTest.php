<?php
/**
 * What the Findings screen is allowed to stop saying.
 *
 * ⛔ A hidden ledger is the failure mode here, not a hidden row. The plugin may
 * put a SUGGESTION away when the owner says so, and it must still carry that row
 * in its payload with a visible count and a way back — a screen that quietly
 * stops mentioning something has stopped being trustworthy about everything else
 * it has not mentioned either.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class FindingsDismissalTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	public function test_dismissed_findings_are_stored_as_sanitised_keys() {
		$clean = ( new Settings() )->sanitize(
			array( 'findings_dismissed' => array( 'never_measured', '<script>x</script>', 'Checking_Off' ) )
		);

		$this->assertContains( 'never_measured', $clean['findings_dismissed'] );
		$this->assertContains( 'checking_off', $clean['findings_dismissed'], 'sanitize_key lowercases — the ids are lowercase too.' );
		$this->assertNotContains( '<script>x</script>', $clean['findings_dismissed'] );
	}

	public function test_nothing_is_dismissed_on_a_fresh_site() {
		$this->assertSame(
			array(),
			( new Settings() )->get( 'findings_dismissed', array() ),
			'A new site has put nothing away — every finding it can raise, it raises.'
		);
	}
}
