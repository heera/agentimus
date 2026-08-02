<?php
/**
 * The Google trend series and Discover totals: the client's parsing of the
 * date-dimension and discover reports, the poll's merge-don't-replace history
 * rule (the series grows where Google's own window ends), and the honest
 * week-on-week gate (under 14 days, `ready` stays false — zeros that mean
 * "unknown" must never print as zeros that mean "nothing happened").
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Google\Client;
use Agentimus\Google\Module;
use Agentimus\Search\Report;
use PHPUnit\Framework\TestCase;

final class GoogleTrendTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_http_queue'] = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		$GLOBALS['_af_http_queue'] = array();
	}

	/* -- the client ---------------------------------------------------------- */

	public function test_daily_report_parses_one_row_per_date() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => array(
				array( 'keys' => array( '2026-08-01' ), 'clicks' => 3.0, 'impressions' => 120.0 ),
				array( 'keys' => array( '2026-08-02' ), 'clicks' => 0.0, 'impressions' => 88.0 ),
				array( 'keys' => array(), 'clicks' => 9.0, 'impressions' => 9.0 ), // malformed: dropped
			) ) ),
			'headers'  => array(),
		);

		$out = ( new Client() )->search_analytics_daily( 'tok', 'p', '2026-08-01', '2026-08-02' );
		$this->assertCount( 2, $out['days'] );
		$this->assertSame( array( 'date' => '2026-08-01', 'clicks' => 3, 'impressions' => 120 ), $out['days'][0] );
	}

	public function test_discover_totals_parse_and_default_to_zero() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => array( array( 'clicks' => 4.0, 'impressions' => 903.0 ) ) ) ),
			'headers'  => array(),
		);
		$out = ( new Client() )->discover_totals( 'tok', 'p', 'a', 'b' );
		$this->assertSame( array( 'impressions' => 903, 'clicks' => 4 ), $out['totals'] );

		// No rows at all — a site Discover never picked up — is an honest zero.
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
			'headers'  => array(),
		);
		$out = ( new Client() )->discover_totals( 'tok', 'p', 'a', 'b' );
		$this->assertSame( array( 'impressions' => 0, 'clicks' => 0 ), $out['totals'] );
	}

	/* -- the merge ------------------------------------------------------------ */

	public function test_merge_replaces_overlap_and_keeps_older_history() {
		$stored = array(
			array( 'date' => '2026-07-01', 'clicks' => 1, 'impressions' => 10 ),
			array( 'date' => '2026-07-02', 'clicks' => 2, 'impressions' => 20 ),
		);
		// Google revised the 2nd and added the 3rd.
		$fresh = array(
			array( 'date' => '2026-07-02', 'clicks' => 5, 'impressions' => 50 ),
			array( 'date' => '2026-07-03', 'clicks' => 3, 'impressions' => 30 ),
		);
		$merged = Module::merge_daily( $stored, $fresh, 400 );
		$this->assertCount( 3, $merged );
		$this->assertSame( '2026-07-01', $merged[0]['date'] ); // history kept
		$this->assertSame( 50, $merged[1]['impressions'] );    // revision won
		$this->assertSame( '2026-07-03', $merged[2]['date'] );
	}

	public function test_merge_caps_from_the_old_end() {
		$stored = array();
		for ( $d = 1; $d <= 9; $d++ ) {
			$stored[] = array( 'date' => sprintf( '2026-07-%02d', $d ), 'clicks' => $d, 'impressions' => $d );
		}
		$merged = Module::merge_daily( $stored, array(), 5 );
		$this->assertCount( 5, $merged );
		$this->assertSame( '2026-07-05', $merged[0]['date'] ); // oldest dropped, newest kept
		$this->assertSame( '2026-07-09', $merged[4]['date'] );
	}

	/* -- the week-on-week gate ------------------------------------------------ */

	public function test_weekly_stays_unready_under_fourteen_days() {
		$daily = array();
		for ( $d = 1; $d <= 13; $d++ ) {
			$daily[] = array( 'date' => sprintf( '2026-07-%02d', $d ), 'clicks' => 1, 'impressions' => 1 );
		}
		$weekly = Report::weekly_from_daily( $daily );
		$this->assertFalse( $weekly['ready'] );
		$this->assertSame( 0, $weekly['thisWeek']['impressions'] );
	}

	public function test_weekly_splits_the_last_fourteen_days_correctly() {
		$daily = array();
		for ( $d = 1; $d <= 20; $d++ ) {
			// Days 7..13 carry 10 impressions each (the "last week" 7-pack),
			// days 14..20 carry 100 each (the "this week" 7-pack).
			$imp     = $d >= 14 ? 100 : ( $d >= 7 ? 10 : 1 );
			$daily[] = array( 'date' => sprintf( '2026-07-%02d', $d ), 'clicks' => 1, 'impressions' => $imp );
		}
		$weekly = Report::weekly_from_daily( $daily );
		$this->assertTrue( $weekly['ready'] );
		$this->assertSame( 700, $weekly['thisWeek']['impressions'] );
		$this->assertSame( 70, $weekly['lastWeek']['impressions'] );
		$this->assertSame( 7, $weekly['thisWeek']['clicks'] );
	}
}
