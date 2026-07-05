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

	private function hit( $endpoint, $agent, $ua, $at = null, $post_id = 0 ) {
		global $wpdb;
		$wpdb->insert(
			Table::name(),
			array( 'endpoint' => $endpoint, 'agent' => $agent, 'ua' => $ua, 'post_id' => (int) $post_id, 'hit_at' => $at ?: gmdate( 'Y-m-d H:i:s' ) ),
			array( '%s', '%s', '%s', '%d', '%s' )
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

	/* -- Per-page report (top_pages) ------------------------------------- */

	public function test_top_pages_ranks_pages_by_hits_with_their_busiest_agent() {
		$a = self::factory()->post->create( array( 'post_title' => 'Popular Guide', 'post_status' => 'publish' ) );
		$b = self::factory()->post->create( array( 'post_title' => 'Second Page', 'post_status' => 'publish' ) );

		// Page A: 3 GPTBot + 1 Googlebot = 4 hits (busiest = GPTBot, 3).
		$this->hit( 'markdown', 'GPTBot', 'GPTBot/1.0', null, $a );
		$this->hit( 'markdown', 'GPTBot', 'GPTBot/1.0', null, $a );
		$this->hit( 'markdown', 'GPTBot', 'GPTBot/1.0', null, $a );
		$this->hit( 'markdown', 'Googlebot', 'Googlebot/2.1', null, $a );
		// Page B: 2 ClaudeBot.
		$this->hit( 'markdown', 'ClaudeBot', 'ClaudeBot/1.0', null, $b );
		$this->hit( 'markdown', 'ClaudeBot', 'ClaudeBot/1.0', null, $b );
		// A non-page endpoint (post_id 0) — MUST be excluded from the per-page report.
		$this->hit( 'llms.txt', 'GPTBot', 'GPTBot/1.0' );

		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$pages = Repository::top_pages( $since, 12 );

		$this->assertCount( 2, $pages, 'only the two pages, not the post_id=0 llms.txt hit' );

		$this->assertSame( $a, $pages[0]['id'], 'busiest page ranks first' );
		$this->assertSame( 4, $pages[0]['hits'] );
		$this->assertSame( 'Popular Guide', $pages[0]['title'] );
		$this->assertNotNull( $pages[0]['url'] );
		$this->assertSame( 'GPTBot', $pages[0]['topAgent']['label'] );
		$this->assertSame( 3, $pages[0]['topAgent']['hits'] );

		$this->assertSame( $b, $pages[1]['id'] );
		$this->assertSame( 2, $pages[1]['hits'] );
	}

	/** A hit for a since-deleted post is kept as history, labelled removed, URL null. */
	public function test_top_pages_labels_a_removed_post() {
		$this->hit( 'markdown', 'GPTBot', 'GPTBot/1.0', null, 999999 );
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$pages = Repository::top_pages( $since, 12 );

		$this->assertCount( 1, $pages );
		$this->assertSame( 999999, $pages[0]['id'] );
		$this->assertStringContainsString( 'removed', $pages[0]['title'] );
		$this->assertNull( $pages[0]['url'] );
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
