<?php
/**
 * uninstall.php against real WordPress: after seeding the plugin's footprint, running
 * the real teardown must drop EVERY custom table and delete its options — the
 * "leave nothing orphaned" contract, verifiable only against a real DB.
 *
 * Add every new table here as it is introduced. A table missing from this list is a table
 * whose teardown nobody is checking, and an orphaned table is invisible until someone
 * uninstalls and finds it still sitting in their database.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Referrals;
use Agentimus\AgentAccess\Table as AgentAccessTable;
use Agentimus\Activity\Table as ActivityTable;
use Agentimus\Visibility\Table as VisibilityTable;

final class UninstallDbTest extends DbTestCase {

	private function has_table( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function test_uninstall_drops_every_table_and_option() {
		global $wpdb;

		// Seed the full footprint: three tables + a few options.
		ActivityTable::install();
		Referrals::install();
		VisibilityTable::install();
		AgentAccessTable::install();
		update_option( 'agentimus_settings', array( 'seeded' => true ) );
		update_option( 'agentimus_agent_access_hooks', '1' );
		update_option( 'agentimus_visibility', array( 'seeded' => true ) );
		update_option( 'agentimus_signing_keys', array( 'k' => 'v' ) );

		$tables = array( ActivityTable::name(), Referrals::name(), VisibilityTable::name(), AgentAccessTable::name() );
		foreach ( $tables as $t ) {
			$this->assertTrue( $this->has_table( $t ), "precondition: $t exists" );
		}

		// Run the plugin's real uninstall routine.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'agentimus/agentimus.php' );
		}
		require_once dirname( __DIR__, 2 ) . '/uninstall.php';

		foreach ( $tables as $t ) {
			$this->assertFalse( $this->has_table( $t ), "$t must be dropped" );
		}
		$this->assertFalse( get_option( 'agentimus_settings', false ), 'settings option deleted' );
		$this->assertFalse( get_option( 'agentimus_visibility', false ), 'visibility option deleted' );
		$this->assertFalse( get_option( 'agentimus_signing_keys', false ), 'signing keys deleted' );
		$this->assertFalse( get_option( 'agentimus_agent_access_hooks', false ), 'agent-access hook verdict deleted' );
	}
}
