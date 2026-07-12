<?php
/**
 * The step-2 autoload migration against a real DB: an install upgraded from a release that wrote
 * the per-boot flags autoload=off must have them flipped IN PLACE, since update_option() won't do
 * it — otherwise a no-object-cache site keeps paying one query per flag on every request. Needs a
 * real options table (and WP 6.4+ wp_set_option_autoload), so it lives in the integration suite.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Settings;

final class DefaultsMigrationDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_set_option_autoload' ) ) {
			$this->markTestSkipped( 'Autoload flip needs WP 6.4+ (wp_set_option_autoload).' );
		}
	}

	private function autoload_of( $name ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB
	}

	private function is_on( $name ): bool {
		// WP has used both 'yes'/'no' and 'on'/'off' for the autoload column across versions.
		return in_array( $this->autoload_of( $name ), array( 'yes', 'on', 'auto' ), true );
	}

	public function test_step2_flips_our_per_boot_flags_to_autoloaded_in_place() {
		global $wpdb;

		// An install upgraded from the old code: the flags exist, autoload OFF, migration at step 1.
		update_option( 'agentimus_activity_db_version', '4' );
		update_option( 'agentimus_agent_access_db_version', '1' );
		wp_set_option_autoload( 'agentimus_activity_db_version', false );
		wp_set_option_autoload( 'agentimus_agent_access_db_version', false );
		update_option( Settings::DEFAULTS_MIGRATED_OPTION, 1 );
		wp_set_option_autoload( Settings::DEFAULTS_MIGRATED_OPTION, false );

		// A LOOK-ALIKE that is NOT ours (e.g. the separate Pro add-on) must be left untouched.
		update_option( 'agentimus_pro_db_version', '1' );
		wp_set_option_autoload( 'agentimus_pro_db_version', false );

		$this->assertFalse( $this->is_on( 'agentimus_activity_db_version' ), 'precondition: off' );

		( new Settings() )->maybe_migrate_defaults();

		$this->assertTrue( $this->is_on( 'agentimus_activity_db_version' ), 'our version stamp must become autoloaded' );
		$this->assertTrue( $this->is_on( 'agentimus_agent_access_db_version' ), 'our version stamp must become autoloaded' );
		$this->assertTrue( $this->is_on( Settings::DEFAULTS_MIGRATED_OPTION ), 'the migration flag itself must be autoloaded' );
		$this->assertFalse( $this->is_on( 'agentimus_pro_db_version' ), 'a non-ours look-alike option must NOT be touched' );
		$this->assertSame( Settings::DEFAULTS_MIGRATION, (int) get_option( Settings::DEFAULTS_MIGRATED_OPTION ), 'flag advances to the current step' );
	}

	public function test_step1_is_not_re_run_by_the_step2_bump() {
		// An install that already did step 1 and whose owner then re-chose the old 1024 must not be
		// nudged again just because the migration version advanced to 2.
		update_option( Settings::OPTION, array( 'llms_full_max_kb' => 1024 ) );
		update_option( Settings::DEFAULTS_MIGRATED_OPTION, 1 );

		( new Settings() )->maybe_migrate_defaults();

		$this->assertSame( 1024, ( new Settings() )->get( 'llms_full_max_kb' ), 'A deliberate re-choice survives a later migration bump.' );
	}
}
