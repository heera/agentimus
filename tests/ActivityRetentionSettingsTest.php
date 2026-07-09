<?php
/**
 * The Visit-log retention settings: `activity_retention_days`, `activity_auto_prune` and
 * `activity_max_rows`.
 *
 * These three decide how much of a site's disk the activity log may occupy, so the
 * sanitiser SNAPS an unexpected value back to the default rather than clamping it into
 * range. Clamping 9,999,999 rows to 250,000 would be defensible; clamping 37 days to 30
 * would leave the dropdown showing "30 days" for a value nobody chose. Snapping means the
 * stored value is always one the UI can actually display.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ActivityRetentionSettingsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function clean( array $input ) {
		return ( new Settings() )->sanitize( $input );
	}

	public function test_defaults_preserve_todays_behaviour() {
		$d = ( new Settings() )->defaults();

		$this->assertSame( 30, $d['activity_retention_days'] );
		$this->assertTrue( $d['activity_auto_prune'] );
		$this->assertSame( 50000, $d['activity_max_rows'] );
	}

	/** Every offered choice survives a round-trip. */
	public function test_each_offered_retention_is_accepted() {
		foreach ( Settings::RETENTION_CHOICES as $days ) {
			$this->assertSame( $days, $this->clean( array( 'activity_retention_days' => $days ) )['activity_retention_days'] );
		}
	}

	public function test_each_offered_cap_is_accepted() {
		foreach ( Settings::MAX_ROWS_CHOICES as $rows ) {
			$this->assertSame( $rows, $this->clean( array( 'activity_max_rows' => $rows ) )['activity_max_rows'] );
		}
	}

	/**
	 * A value the dropdown never offers snaps to the default. Note 0 and -1 in particular:
	 * a cap of 0 disables the trim entirely, and must NOT be reachable by posting it.
	 */
	public function test_an_unoffered_value_snaps_to_the_default() {
		foreach ( array( 37, 0, -1, 9999, 'thirty' ) as $bad ) {
			$this->assertSame( 30, $this->clean( array( 'activity_retention_days' => $bad ) )['activity_retention_days'], "retention `$bad`" );
		}
		foreach ( array( 1, 0, -5, 9999999, 'lots' ) as $bad ) {
			$this->assertSame( 50000, $this->clean( array( 'activity_max_rows' => $bad ) )['activity_max_rows'], "cap `$bad`" );
		}
	}

	/** The cap can only be disabled from code, never from a posted form. */
	public function test_the_cap_cannot_be_disabled_through_settings() {
		$this->assertNotSame( 0, $this->clean( array( 'activity_max_rows' => 0 ) )['activity_max_rows'] );
		$this->assertContains( 50000, Settings::MAX_ROWS_CHOICES );
		$this->assertNotContains( 0, Settings::MAX_ROWS_CHOICES, 'no "unlimited" option — that is how a shared host fills its disk' );
	}

	/** auto_prune rides the generic boolean loop; a partial save must not flip it off. */
	public function test_auto_prune_survives_a_partial_save() {
		$clean = $this->clean( array( 'enable_activity' => true ) ); // no auto_prune key at all

		$this->assertTrue( $clean['activity_auto_prune'], 'an omitted switch keeps its default, never silently turns off' );
	}

	public function test_auto_prune_can_be_turned_off() {
		$this->assertFalse( $this->clean( array( 'activity_auto_prune' => false ) )['activity_auto_prune'] );
	}
}
