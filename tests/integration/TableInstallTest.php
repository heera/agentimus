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

	public function test_dbdelta_creates_the_post_id_column_and_index() {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
		delete_option( Table::VERSION_OPTION );
		Table::install();

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'post_id', $cols, 'the schema v3 post_id column must exist' );

		$idx = $wpdb->get_results( "SHOW INDEX FROM `$table` WHERE Key_name = 'post_id'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertNotEmpty( $idx, 'the post_id index must exist' );
		$this->assertSame( 'post_id', $idx[0]['Column_name'] );
	}

	/** An existing pre-v3 install (no post_id) self-heals: dbDelta ADDs the column,
	 *  and old rows take the default 0 — no data loss, no manual migration. */
	public function test_upgrade_adds_post_id_to_a_pre_existing_table() {
		global $wpdb;
		$table   = Table::name();
		$collate = $wpdb->get_charset_collate();
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
		// The REAL v2 table: no post_id, but WITH the secondary indexes — including the
		// prefixed `ua(191)` that makes dbDelta silently skip the v3 ADD COLUMN. (A
		// PRIMARY-KEY-only table doesn't reproduce the bug, so it must carry these.)
		$wpdb->query( "CREATE TABLE `$table` (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  endpoint varchar(64) NOT NULL DEFAULT '',\n  agent varchar(64) NOT NULL DEFAULT '',\n  ua varchar(255) NOT NULL DEFAULT '',\n  hit_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  KEY hit_at (hit_at),\n  KEY endpoint (endpoint),\n  KEY agent (agent),\n  KEY ua (ua(191))\n) $collate" ); // phpcs:ignore WordPress.DB
		$wpdb->insert( $table, array( 'endpoint' => 'llms.txt', 'agent' => 'GPTBot', 'ua' => 'x', 'hit_at' => current_time( 'mysql', true ) ), array( '%s', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB

		$this->assertNotContains( 'post_id', $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ) ); // phpcs:ignore WordPress.DB

		Table::install(); // the real upgrade path

		$this->assertContains( 'post_id', $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ), 'dbDelta must add post_id' ); // phpcs:ignore WordPress.DB
		$this->assertSame( '0', $wpdb->get_var( "SELECT post_id FROM `$table` ORDER BY id ASC LIMIT 1" ), 'existing rows default to 0' ); // phpcs:ignore WordPress.DB
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
