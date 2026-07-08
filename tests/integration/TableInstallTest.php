<?php
/**
 * The Phase 0 proof-of-concept that only real WordPress can give: dbDelta actually
 * creates the activity table, with the columns and indexes the schema declares —
 * including the `ua` index added in the v2 bump, which the unit suite (no MySQL,
 * no dbDelta) can never verify. This is the template every Phase 1 DB test follows.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Table;

final class TableInstallTest extends DbTestCase {

	public function test_dbdelta_creates_the_activity_table_with_the_ua_index() {
		global $wpdb;
		$table = Table::name();

		// Install through the plugin's real dbDelta path (idempotent; drop first so the
		// assertion reflects a fresh create rather than a pre-existing table).
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
		delete_option( Table::VERSION_OPTION );
		Table::install();

		// The table exists...
		$this->assertSame(
			$table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
			'dbDelta must create the table'
		);

		// ...carrying the ua index (schema v2) that speeds the threats / dashboard
		// GROUP BY ua — the exact change the unit suite cannot exercise.
		$ua_index = $wpdb->get_results( "SHOW INDEX FROM `$table` WHERE Key_name = 'ua'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertNotEmpty( $ua_index, 'the ua index must exist' );
		$this->assertSame( 'ua', $ua_index[0]['Column_name'] );

		// ...and the schema version flag was recorded, so maybe_install() no-ops next boot.
		$this->assertSame( Table::VERSION, get_option( Table::VERSION_OPTION ) );
	}

	public function test_install_creates_the_verdict_and_network_columns() {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
		delete_option( Table::VERSION_OPTION );
		Table::install();

		foreach ( array( 'verdict', 'network' ) as $col ) {
			$this->assertNotEmpty(
				$wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $col ) ), // phpcs:ignore WordPress.DB
				"the $col column must exist after install"
			);
		}
	}

	public function test_install_restores_a_column_missing_on_a_drifted_table() {
		// The exact failure that shipped mid-development: a table whose schema had drifted lost
		// (or never gained) the `network` column, yet the version was stamped migrated — so every
		// query selecting it failed. install() must GUARANTEE the column exists (dbDelta, or the
		// ensure_column guard), regardless of the table's prior state.
		global $wpdb;
		$table = Table::name();
		Table::install();
		$wpdb->query( "ALTER TABLE `$table` DROP COLUMN network" ); // phpcs:ignore WordPress.DB
		$this->assertEmpty(
			$wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'network' ) ), // phpcs:ignore WordPress.DB
			'precondition: network column dropped'
		);

		delete_option( Table::VERSION_OPTION );
		Table::install();

		$this->assertNotEmpty(
			$wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'network' ) ), // phpcs:ignore WordPress.DB
			'install() must restore the network column on a drifted table'
		);
	}

	public function test_a_row_round_trips_through_the_real_table() {
		global $wpdb;
		Table::install();
		$table = Table::name();

		$wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array( 'endpoint' => 'discovery.json', 'agent' => 'GPTBot', 'ua' => 'GPTBot/1.0', 'hit_at' => current_time( 'mysql', true ) ),
			array( '%s', '%s', '%s', '%s' )
		);

		$this->assertSame(
			'GPTBot',
			$wpdb->get_var( $wpdb->prepare( "SELECT agent FROM `$table` WHERE endpoint = %s ORDER BY id DESC LIMIT 1", 'discovery.json' ) ) // phpcs:ignore WordPress.DB
		);
	}
}
