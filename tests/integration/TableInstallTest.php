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

	/** The real upgrade with existing history: the pre-v3 table (no post_id, WITH the
	 *  prefixed ua(191) index that trips dbDelta) self-heals via ensure_column(), and
	 *  EVERY existing row survives with its values intact — no data loss — and the
	 *  upgrade is idempotent (safe to run again). This is the migration heera.it runs. */
	public function test_upgrade_adds_post_id_preserves_all_data_and_is_idempotent() {
		global $wpdb;
		$table   = Table::name();
		$collate = $wpdb->get_charset_collate();
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB
		// The REAL v2 table: no post_id, WITH the secondary indexes — including the
		// prefixed `ua(191)` that makes dbDelta silently skip the v3 ADD COLUMN.
		$wpdb->query( "CREATE TABLE `$table` (\n  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  endpoint varchar(64) NOT NULL DEFAULT '',\n  agent varchar(64) NOT NULL DEFAULT '',\n  ua varchar(255) NOT NULL DEFAULT '',\n  hit_at datetime NOT NULL,\n  PRIMARY KEY  (id),\n  KEY hit_at (hit_at),\n  KEY endpoint (endpoint),\n  KEY agent (agent),\n  KEY ua (ua(191))\n) $collate" ); // phpcs:ignore WordPress.DB

		// Pre-upgrade history, with distinct values we can prove survive verbatim.
		$at = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $table, array( 'endpoint' => 'llms.txt', 'agent' => 'GPTBot', 'ua' => 'GPTBot/1.0', 'hit_at' => $at ), array( '%s', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB
		$wpdb->insert( $table, array( 'endpoint' => 'discovery.json', 'agent' => 'Googlebot', 'ua' => 'Googlebot/2.1', 'hit_at' => $at ), array( '%s', '%s', '%s', '%s' ) ); // phpcs:ignore WordPress.DB

		$this->assertNotContains( 'post_id', $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ) ); // phpcs:ignore WordPress.DB

		Table::install(); // the real upgrade

		// Column + index landed (via dbDelta or the ensure_column backstop).
		$this->assertContains( 'post_id', $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ), 'post_id must be added' ); // phpcs:ignore WordPress.DB
		$this->assertNotEmpty( $wpdb->get_results( "SHOW INDEX FROM `$table` WHERE Key_name = 'post_id'", ARRAY_A ), 'post_id index must exist' ); // phpcs:ignore WordPress.DB

		// ZERO data loss: both rows intact, values verbatim, new column defaulted to 0.
		$rows = $wpdb->get_results( "SELECT endpoint, agent, ua, post_id FROM `$table` ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertCount( 2, $rows, 'no rows lost in the migration' );
		$this->assertSame( array( 'endpoint' => 'llms.txt', 'agent' => 'GPTBot', 'ua' => 'GPTBot/1.0', 'post_id' => '0' ), $rows[0] );
		$this->assertSame( array( 'endpoint' => 'discovery.json', 'agent' => 'Googlebot', 'ua' => 'Googlebot/2.1', 'post_id' => '0' ), $rows[1] );

		// Idempotent: running it again is a safe no-op — same rows, same schema.
		Table::install();
		$this->assertCount( 2, $wpdb->get_results( "SELECT id FROM `$table`", ARRAY_A ), 're-running install must not lose or duplicate data' ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'post_id', $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ) ); // phpcs:ignore WordPress.DB
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
