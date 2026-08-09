<?php
/**
 * Bing daily human traffic — the series parse, the weekly-bucket window, and
 * the totals swap that keeps every door quoting one figure.
 *
 * The context these guard: Bing's query endpoints return WEEKLY buckets
 * spanning ~16 months (a fact the live API proved and the first integration
 * missed), and GetRankAndTrafficStats returns a true DAILY series. Summing
 * buckets whole once put a 16-month figure under a 56-day label; these tests
 * pin the window, the aggregation, and the "sample never outranks the
 * series" rule that fixed it.
 *
 * @package Agentimus\Tests
 */

use Agentimus\Bing\Client;
use Agentimus\Bing\Module;
use Agentimus\Bing\Settings;
use Agentimus\Search\Report;
use PHPUnit\Framework\TestCase;

final class BingTrafficTest extends TestCase {

	protected function setUp(): void {
		_af_reset_options();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	// ── Client::traffic_stats ───────────────────────────────────────────────

	public function test_traffic_stats_parses_wcf_days_and_sorts_oldest_first() {
		// Out of order on purpose, with the two zone suffixes the live API
		// actually sends across a DST boundary — the day comes from UTC.
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'd' => array(
				array( '__type' => 'RankAndTrafficStats:#Microsoft.Bing.Webmaster.Api', 'Clicks' => 1, 'Date' => '/Date(1782950400000-0700)/', 'Impressions' => 3 ),
				array( '__type' => 'RankAndTrafficStats:#Microsoft.Bing.Webmaster.Api', 'Clicks' => 0, 'Date' => '/Date(1782864000000-0800)/', 'Impressions' => 19 ),
				array( '__type' => 'RankAndTrafficStats:#Microsoft.Bing.Webmaster.Api', 'Clicks' => 2, 'Date' => 'garbage', 'Impressions' => 99 ),
			) ) ),
		);

		$out = ( new Client() )->traffic_stats( 'key', 'https://example.com/' );

		$this->assertSame(
			array(
				array( 'date_at' => '2026-07-01', 'clicks' => 0, 'impressions' => 19 ),
				array( 'date_at' => '2026-07-02', 'clicks' => 1, 'impressions' => 3 ),
			),
			$out['rows'],
			'dateless rows are skipped; the rest sort oldest-first'
		);
	}

	// ── Client::url_info ────────────────────────────────────────────────────

	public function test_url_info_parses_a_known_page() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'd' => array(
				'__type'          => 'UrlInfo:#Microsoft.Bing.Webmaster.Api',
				'DiscoveryDate'   => '/Date(1742799600000-0700)/',
				'LastCrawledDate' => '/Date(1786085955000)/',
				'DocumentSize'    => 80022,
				'IsPage'          => true,
			) ) ),
		);

		$out = ( new Client() )->url_info( 'key', 'https://example.com/', 'https://example.com/post' );

		$this->assertTrue( $out['info']['known'] );
		$this->assertSame( '2025-03-24', $out['info']['discoveredAt'] );
		$this->assertSame( '2026-08-07', $out['info']['lastCrawledAt'] );
	}

	/**
	 * Probed live: a page Bing has never seen answers 200 with .NET's zero
	 * date (year 1) in both fields — that is "unknown", and it must never
	 * print as a real date from the year one.
	 */
	public function test_url_info_reads_dotnet_zero_dates_as_unknown() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'd' => array(
				'DiscoveryDate'   => '/Date(-62135568000000-0800)/',
				'LastCrawledDate' => '/Date(-62135568000000-0800)/',
				'DocumentSize'    => 0,
			) ) ),
		);

		$out = ( new Client() )->url_info( 'key', 'https://example.com/', 'https://example.com/new-post' );

		$this->assertFalse( $out['info']['known'] );
		$this->assertSame( '', $out['info']['discoveredAt'] );
		$this->assertSame( '', $out['info']['lastCrawledAt'] );
	}

	public function test_url_info_surfaces_bings_refusal() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 400 ),
			'body'     => '{"ErrorCode":14,"Message":"ERROR!!! NotAuthorized"}',
		);

		$out = ( new Client() )->url_info( 'key', 'https://example.com/', 'https://other-site.example/foo' );

		$this->assertSame( 'ERROR!!! NotAuthorized', $out['error'] );
	}

	public function test_traffic_stats_surfaces_bing_errors() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 400 ),
			'body'     => '{"ErrorCode":3,"Message":"ERROR!!! InvalidApiKey"}',
		);

		$out = ( new Client() )->traffic_stats( 'bad', 'https://example.com/' );

		$this->assertSame( 'ERROR!!! InvalidApiKey', $out['error'] );
	}

	// ── Module::bucket_window ───────────────────────────────────────────────

	public function test_the_window_anchors_at_the_newest_bucket_not_today() {
		$window = Module::bucket_window( array(
			array( 'date_at' => '2026-05-01' ),
			array( 'date_at' => '2026-08-07' ),
			array( 'date_at' => '' ),
		) );

		$this->assertSame( '2026-08-07', $window['end'] );
		// 56 days inclusive — the exact span Google's snapshot covers.
		$this->assertSame( '2026-06-13', $window['start'] );
	}

	public function test_no_dated_buckets_means_no_window_rather_than_a_guess() {
		$this->assertNull( Module::bucket_window( array( array( 'date_at' => '' ), array() ) ) );
		$this->assertNull( Module::bucket_window( array() ) );
	}

	// ── Module::window_aggregate ────────────────────────────────────────────

	public function test_buckets_outside_the_window_are_dropped_and_the_rest_summed() {
		$window = array( 'start' => '2026-06-13', 'end' => '2026-08-07' );
		$rows   = array(
			// Two in-window weeks of the same query…
			array( 'key' => 'php throwable', 'date_at' => '2026-07-31', 'clicks' => 2, 'impressions' => 10, 'position' => 4.0 ),
			array( 'key' => 'php throwable', 'date_at' => '2026-08-07', 'clicks' => 1, 'impressions' => 30, 'position' => 8.0 ),
			// …and a 2025 fossil that once inflated the totals 6× over.
			array( 'key' => 'php throwable', 'date_at' => '2025-05-09', 'clicks' => 9, 'impressions' => 500, 'position' => 1.0 ),
		);

		$out = Module::window_aggregate( $rows, $window );

		$this->assertCount( 1, $out );
		$this->assertSame( 3, $out[0]['clicks'] );
		$this->assertSame( 40, $out[0]['impressions'] );
		// Impression-weighted: (4×10 + 8×30) / 40.
		$this->assertSame( 7.0, $out[0]['position'] );
	}

	public function test_a_dateless_row_is_kept_rather_than_silently_lost() {
		$window = array( 'start' => '2026-06-13', 'end' => '2026-08-07' );
		$out    = Module::window_aggregate(
			array( array( 'key' => 'q', 'date_at' => '', 'clicks' => 1, 'impressions' => 5, 'position' => 2.0 ) ),
			$window
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 5, $out[0]['impressions'] );
	}

	public function test_zero_impression_buckets_average_position_instead_of_dividing_by_zero() {
		$out = Module::window_aggregate(
			array(
				array( 'key' => 'q', 'date_at' => '2026-08-07', 'clicks' => 0, 'impressions' => 0, 'position' => 4.0 ),
				array( 'key' => 'q', 'date_at' => '2026-07-31', 'clicks' => 0, 'impressions' => 0, 'position' => 6.0 ),
			),
			null // And a null window keeps everything — the DTO-drift fallback.
		);

		$this->assertSame( 5.0, $out[0]['position'] );
	}

	// ── Report::window_totals / apply_series_totals ─────────────────────────

	/**
	 * A $wpdb that answers the two queries this path runs: the snapshot sums
	 * (recognised by their range aliases) and the traffic-series sums
	 * (recognised by BETWEEN). The numbers disagree on purpose — the sample
	 * undercounts, exactly as the live API does.
	 *
	 * @param int $series_days Reported days the fake series claims to cover.
	 * @return void
	 */
	private function fake_wpdb( $series_days ) {
		$GLOBALS['wpdb'] = new class( $series_days ) {
			public $prefix = 'wp_';
			private $days;
			public function __construct( $days ) {
				$this->days = $days;
			}
			public function prepare( $sql, ...$args ) {
				return $sql;
			}
			public function get_row( $sql, $output = null ) {
				if ( false !== strpos( $sql, 'BETWEEN' ) ) {
					// Bing\Table::traffic_totals — the site-wide series.
					return array( 'clicks' => 17, 'impressions' => 8047, 'days' => $this->days );
				}
				// Search\Table::totals — the sampled snapshot sums.
				return array( 'clicks' => 12, 'impressions' => 1388, 'rows_held' => 30, 'range_start' => '2026-06-13', 'range_end' => '2026-08-07' );
			}
			public function get_var( $sql ) {
				return 1;
			}
			public function get_results( $sql, $output = null ) {
				return array();
			}
		};
	}

	public function test_bing_totals_come_from_the_series_when_it_covers_the_window() {
		$this->fake_wpdb( 56 );

		$t = Report::window_totals( 'bing' );

		$this->assertTrue( $t['series'] );
		$this->assertSame( 17, $t['clicks'] );
		$this->assertSame( 8047, $t['impressions'] );

		$totals = Report::apply_series_totals(
			array( 'clicks' => 12, 'impressions' => 1388, 'ctr' => 0.9, 'position' => 4.2, 'probeShare' => 40 ),
			'bing'
		);
		$this->assertSame( 8047, $totals['impressions'] );
		$this->assertSame( 0.2, $totals['ctr'], 'the click rate is recomputed against the series' );
		$this->assertSame( 4.2, $totals['position'], 'rank stays the sample\'s — the series carries none' );
	}

	public function test_sample_totals_stand_until_the_series_has_reported() {
		$this->fake_wpdb( 0 ); // Traffic never polled: zero reported days.

		$t = Report::window_totals( 'bing' );

		$this->assertFalse( $t['series'] );
		$this->assertSame( 12, $t['clicks'], 'the sampled sums are a smaller truth, not a zero' );

		$in  = array( 'clicks' => 12, 'impressions' => 1388, 'ctr' => 0.9, 'position' => 4.2, 'probeShare' => 40 );
		$this->assertSame( $in, Report::apply_series_totals( $in, 'bing' ) );
	}

	public function test_google_totals_pass_through_untouched() {
		$this->fake_wpdb( 56 ); // Even with a series on hand…

		$in = array( 'clicks' => 100, 'impressions' => 2000, 'ctr' => 5.0, 'position' => 3.1, 'probeShare' => 0 );
		$this->assertSame( $in, Report::apply_series_totals( $in, 'google' ), '…Google\'s rows ARE the full report' );
	}

	// ── Report::extras ──────────────────────────────────────────────────────

	public function test_bing_extras_ship_the_series_in_googles_shape() {
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';
			public function prepare( $sql, ...$args ) {
				return $sql;
			}
			public function get_results( $sql, $output = null ) {
				$rows = array();
				for ( $i = 20; $i >= 1; $i-- ) {
					$rows[] = array(
						'date_at'     => gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ),
						'clicks'      => 1,
						'impressions' => 10,
					);
				}
				return $rows;
			}
		};
		$GLOBALS['_af_options'][ Settings::OPTION ] = array( 'traffic_at' => 1754600000 );

		$extras = Report::extras( 'bing' );

		$this->assertCount( 20, $extras['daily'] );
		$this->assertArrayHasKey( 'date', $extras['daily'][0], 'the Google trend key, so readers need no second branch' );
		$this->assertTrue( $extras['weekly']['ready'], '20 days of history is enough for a week-on-week claim' );
		$this->assertSame( 7, $extras['weekly']['thisWeek']['clicks'] );
		$this->assertSame( 70, $extras['weekly']['lastWeek']['impressions'] );
		$this->assertSame( 1754600000, $extras['updatedAt'] );
		$this->assertSame( array( 'impressions' => 0, 'clicks' => 0 ), $extras['discover'], 'Discover is Google\'s surface; 0 means exactly that' );
	}
}
