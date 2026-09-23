<?php
/**
 * The edge table's upgrade on a site that already has one.
 *
 * ⭐ WHY THIS EXISTS. An update from WordPress.org never runs the activation
 * hook — the only thing that brings an existing site's table up to date is
 * Table::maybe_install() on load. Version 2 adds `served` (pages served to a
 * crawler, the count the training notice reads); a site upgraded from 1.51.4
 * holds a version-1 table with a week of rows in it, and every poll after the
 * update writes the new column. If the column is not there, every insert
 * fails and the edge numbers silently freeze.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Cloudflare\Table;

class EdgeTableUpgradeDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB

		// The table exactly as 1.51.4 created it, holding one stored hour.
		$wpdb->query( // phpcs:ignore WordPress.DB
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hour_at datetime NOT NULL,
				ua varchar(64) NOT NULL,
				requests int(10) unsigned NOT NULL DEFAULT 0,
				cached int(10) unsigned NOT NULL DEFAULT 0,
				origin int(10) unsigned NOT NULL DEFAULT 0,
				blocked int(10) unsigned NOT NULL DEFAULT 0,
				bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY hour_ua (hour_at,ua),
				KEY hour_at (hour_at)
			) " . $wpdb->get_charset_collate()
		);
		$wpdb->insert( $table, array( // phpcs:ignore WordPress.DB
			'hour_at'  => gmdate( 'Y-m-d H:00:00', time() - 2 * HOUR_IN_SECONDS ),
			'ua'       => 'claudebot',
			'requests' => 20,
			'cached'   => 0,
			'origin'   => 9,
			'blocked'  => 11,
			'bytes'    => 500,
		) );
		update_option( Table::VERSION_OPTION, '1' );
	}

	public function tear_down(): void {
		Table::install();
		parent::tear_down();
	}

	public function test_loading_the_plugin_upgrades_a_1_51_4_table_and_keeps_its_rows() {
		global $wpdb;
		$table = Table::name();

		Table::maybe_install();

		$this->assertContains( 'served', $wpdb->get_col( "SHOW COLUMNS FROM $table" ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( Table::VERSION, get_option( Table::VERSION_OPTION ) );

		// The hour stored before the update survives, and reads as no pages
		// served — the old table never measured it, and nothing invents it.
		$recent = Table::recent( 24 );
		$this->assertSame( 11, $recent['claudebot']['blocked'] );
		$this->assertSame( 9, $recent['claudebot']['passed'] );
		$this->assertSame( 0, $recent['claudebot']['served'] );
	}

	public function test_the_first_poll_after_the_update_stores_and_reads_back_served() {
		Table::maybe_install();

		$hour = gmdate( 'Y-m-d H:00:00', time() - HOUR_IN_SECONDS );
		Table::upsert( array(
			array( 'hour_at' => $hour, 'ua' => 'shapbot', 'requests' => 6, 'cached' => 3, 'origin' => 3, 'blocked' => 0, 'served' => 5, 'bytes' => 900 ),
		) );
		// A re-polled hour replaces its row, `served` included.
		Table::upsert( array(
			array( 'hour_at' => $hour, 'ua' => 'shapbot', 'requests' => 7, 'cached' => 3, 'origin' => 4, 'blocked' => 0, 'served' => 6, 'bytes' => 950 ),
		) );

		$this->assertSame( 6, Table::recent( 24 )['shapbot']['served'] );

		$summary = array_column( Table::summary( 7 ), null, 'ua' );
		$this->assertSame( 6, $summary['shapbot']['served'] );

		$daily = Table::daily( 7 );
		$this->assertSame( 6, $daily[ gmdate( 'Y-m-d', strtotime( $hour . ' UTC' ) ) ]['shapbot']['served'] );
	}
}
