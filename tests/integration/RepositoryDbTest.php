<?php
/**
 * The agent-hit log's read + maintenance SQL against real MySQL: the dashboard
 * aggregates (GROUP BY agent/endpoint, and the ua-indexed threats GROUP BY added in
 * P1), the row-cap trim (OFFSET), the day-requests half-open range, and retention
 * prune. None of this runs in the unit suite (no $wpdb, no MySQL).
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Repository;
use Agentimus\Activity\Table;
use Agentimus\Settings;

final class RepositoryDbTest extends DbTestCase {

	const NOKIA = 'Nokia6630/1.0 (2.3.129) SymbianOS/8.0 Series60/2.6 Profile/MIDP-2.0 Configuration/CLDC-1.1';

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
	}

	private function hit( $endpoint, $agent, $ua, $at = null ) {
		global $wpdb;
		$wpdb->insert(
			Table::name(),
			array( 'endpoint' => $endpoint, 'agent' => $agent, 'ua' => $ua, 'hit_at' => $at ?: gmdate( 'Y-m-d H:i:s' ) ),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	private function row_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Table::name() );
	}

	/* -- Dashboard aggregates -------------------------------------------- */

	public function test_stats_totals_and_group_counts() {
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0' );
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->hit( 'llms.txt', 'Googlebot', 'Googlebot/2.1' );

		$stats = Repository::stats( new Settings() );
		$this->assertSame( 3, $stats['totals']['all'] );

		$agents = array();
		foreach ( $stats['byAgent'] as $r ) {
			$agents[ $r['label'] ] = $r['hits'];
		}
		$this->assertSame( 2, $agents['GPTBot'] );
		$this->assertSame( 1, $agents['Googlebot'] );

		$endpoints = array();
		foreach ( $stats['byEndpoint'] as $r ) {
			$endpoints[ $r['label'] ] = $r['hits'];
		}
		$this->assertSame( 2, $endpoints['discovery.json'] );
	}

	/** The ua-indexed threats GROUP BY, plus is_spoof flagging, end-to-end. */
	public function test_threats_surface_a_spoofed_legacy_device() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->hit( 'discovery.json', 'Likely spoof/scanner', self::NOKIA );
		}
		$threats = Repository::threats( new Settings() );
		$this->assertIsArray( $threats['sources'] );

		$uas = array_map( static function ( $s ) { return $s['ua']; }, $threats['sources'] );
		$this->assertContains( self::NOKIA, $uas, 'a spoofed legacy-device UA must be flagged' );
	}

	/* -- Configurable breakdown limits ----------------------------------- */

	public function test_breakdown_row_limits_are_filterable() {
		foreach ( array( 'A', 'B', 'C', 'D', 'E' ) as $a ) {
			$this->hit( 'discovery.json', $a, $a . '/1.0' );
		}
		// Default cap (8) shows all 5 distinct clients.
		$this->assertCount( 5, Repository::stats( new Settings() )['byAgent'] );

		// A filter tightens it — and is clamped to at least 1.
		add_filter( 'agentimus_activity_clients_limit', static function () { return 2; } );
		$this->assertCount( 2, Repository::stats( new Settings() )['byAgent'], 'the clients limit filter must apply' );
	}

	/* -- Maintenance: OFFSET trim, day range, prune ---------------------- */

	public function test_trim_to_cap_keeps_only_the_newest_rows() {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->hit( 'discovery.json', 'Bot', "Bot/$i" );
		}
		add_filter( 'agentimus_activity_max_rows', static function () { return 4; } );
		Repository::trim_to_cap();
		$this->assertSame( 4, $this->row_count(), 'the table is capped to the newest N rows' );
	}

	public function test_day_requests_returns_that_days_hits() {
		$noon = gmdate( 'Y-m-d' ) . ' 12:00:00';
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0', $noon );
		$this->hit( 'llms.txt', 'Googlebot', 'Googlebot/2.1', $noon );

		$day = Repository::day_requests( gmdate( 'Y-m-d' ), 100 );
		$this->assertSame( gmdate( 'Y-m-d' ), $day['date'] );
		$this->assertSame( 2, $day['total'] );
		$this->assertCount( 2, $day['rows'] );
	}

	public function test_prune_drops_hits_older_than_retention() {
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0', gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) );
		$this->hit( 'llms.txt', 'Googlebot', 'Googlebot/2.1' );
		Repository::prune();
		$this->assertSame( 1, $this->row_count() );
	}

	public function test_clear_empties_the_table() {
		$this->hit( 'discovery.json', 'GPTBot', 'GPTBot/1.0' );
		Repository::clear();
		$this->assertSame( 0, $this->row_count() );
	}
}
