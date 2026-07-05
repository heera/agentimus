<?php
/**
 * Lifecycle against real WordPress: activation seeds settings and creates ALL three
 * custom tables via dbDelta, and the on-boot maybe_install() self-heals a missing
 * table (the mechanism a multisite sub-site relies on). Only a real WP + MySQL can
 * prove dbDelta actually ran.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Plugin;
use Agentimus\Settings;
use Agentimus\Activity\Table as ActivityTable;
use Agentimus\Activity\Referrals;
use Agentimus\Visibility\Table as VisibilityTable;

final class LifecycleDbTest extends DbTestCase {

	private function tables() {
		return array( ActivityTable::name(), Referrals::name(), VisibilityTable::name() );
	}

	private function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function test_activation_creates_all_three_tables_and_seeds_settings() {
		global $wpdb;
		foreach ( $this->tables() as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$t`" ); // phpcs:ignore WordPress.DB
		}
		delete_option( ActivityTable::VERSION_OPTION );
		delete_option( Settings::OPTION );

		Plugin::activate();

		foreach ( $this->tables() as $t ) {
			$this->assertTrue( $this->table_exists( $t ), "activation must create $t" );
		}
		$this->assertSame( ActivityTable::VERSION, get_option( ActivityTable::VERSION_OPTION ), 'the schema version is recorded' );
		$this->assertIsArray( get_option( Settings::OPTION ), 'the settings defaults are seeded' );
	}

	public function test_maybe_install_self_heals_a_dropped_table() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS `' . ActivityTable::name() . '`' ); // phpcs:ignore WordPress.DB
		delete_option( ActivityTable::VERSION_OPTION );
		$this->assertFalse( $this->table_exists( ActivityTable::name() ) );

		// What Activity\Module::register() runs on every boot.
		ActivityTable::maybe_install();

		$this->assertTrue( $this->table_exists( ActivityTable::name() ), 'a missing table is recreated on boot' );
	}
}
