<?php
/**
 * The Report collector against real data: what a window means to each kind of
 * number, and the promise that the screen and the weekly email never tell the
 * owner two different things.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Referrals;
use Agentimus\Activity\Table as ActivityTable;
use Agentimus\Digest\Data as DigestData;
use Agentimus\Report\Data as ReportData;
use Agentimus\Settings;

final class ReportDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		ActivityTable::install();
		Referrals::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . ActivityTable::name() );
		$wpdb->query( 'TRUNCATE TABLE ' . Referrals::name() );
	}

	/** One recorded arrival from an AI answer, on a given GMT day. */
	private function visit( $day, $source = 'ChatGPT' ) {
		global $wpdb;
		$wpdb->insert(
			Referrals::name(),
			array(
				'day'    => $day,
				'source' => $source,
				'path'   => '/hello/',
				'hits'   => 1,
			)
		);
	}

	/** One recorded agent read, on a given GMT day. */
	private function read( $day, $agent = 'ChatGPT', $verdict = 0 ) {
		global $wpdb;
		$wpdb->insert(
			ActivityTable::name(),
			array(
				'hit_at'   => $day . ' 12:00:00',
				'endpoint' => 'llms',
				'agent'    => $agent,
				'ua'       => $agent . '/1.0',
				'verdict'  => $verdict,
			)
		);
	}

	private function report( $from, $to ) {
		return ReportData::collect( new Settings(), $from, $to );
	}

	public function test_a_single_day_counts_only_that_day() {
		$this->read( gmdate( 'Y-m-d' ) );
		$this->read( gmdate( 'Y-m-d' ) );
		$this->read( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );

		$today = $this->report( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );
		$this->assertSame( 2, $today['reads']['total'] );
		$this->assertSame( 1, $today['range']['days'] );
		$this->assertTrue( $today['range']['open'], 'a window ending today is still being written' );

		$yesterday = $this->report( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );
		$this->assertSame( 1, $yesterday['reads']['total'] );
		$this->assertFalse( $yesterday['range']['open'], 'a window that ended is closed' );
	}

	public function test_a_span_counts_every_day_in_it() {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->read( gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) );
		}
		$window = $this->report( gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ), gmdate( 'Y-m-d' ) );
		$this->assertSame( 4, $window['reads']['total'] );
		$this->assertSame( 4, $window['range']['days'] );
	}

	/** Dates the wrong way round are a slip, not an empty window. */
	public function test_a_reversed_window_is_read_forwards() {
		$this->read( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );
		$window = $this->report( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );
		$this->assertSame( 1, $window['reads']['total'] );
	}

	/** The window before this one, same length — what "up from" compares to. */
	public function test_the_window_before_is_counted_for_comparison() {
		$this->read( gmdate( 'Y-m-d' ) );
		$this->read( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );
		$this->read( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );

		$today = $this->report( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );
		$this->assertSame( 1, $today['reads']['total'] );
		$this->assertSame( 2, $today['reads']['prev'], 'yesterday, for the comparison' );
	}

	/**
	 * ⭐ THE PIN. The email and the screen must read the same producers: for the
	 * digest's own window, both must report the same number of reads. If this
	 * ever fails, one of the two has started counting on its own.
	 */
	public function test_the_screen_and_the_weekly_email_count_the_same_week() {
		for ( $i = 1; $i <= 7; $i++ ) {
			$this->read( gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) );
		}

		$digest = DigestData::collect( new Settings(), null );
		$screen = $this->report(
			gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d', time() - DAY_IN_SECONDS )
		);

		$this->assertSame( $digest['agents']['total'], $screen['reads']['total'], 'the email and the screen must count one thing once' );
	}

	/** Each block says which kind of number it is — the screen's whole honesty. */
	public function test_every_block_declares_how_fresh_it_can_be() {
		$window = $this->report( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );

		$this->assertSame( 'live', $window['reads']['freshness'] );
		$this->assertSame( 'live', $window['visits']['freshness'] );
		$this->assertSame( 'live', $window['access']['freshness'] );
		$this->assertSame( 'lagging', $window['search']['freshness'] );
		$this->assertSame( 'lagging', $window['citations']['freshness'] );
		$this->assertSame( 'state', $window['score']['freshness'] );
		$this->assertSame( 'state', $window['nudge']['freshness'] );
	}

	/** An engine nobody connected contributes nothing rather than a row of zeros. */
	public function test_search_lists_only_connected_engines() {
		$this->assertSame( array(), $this->report( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) )['search']['engines'] );
	}

	/** Citations off is a state the block names, not an empty result. */
	public function test_citations_say_when_the_feature_is_off() {
		$citations = $this->report( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) )['citations'];
		$this->assertFalse( $citations['enabled'] );
		$this->assertSame( 0, $citations['runs'] );
	}

	/**
	 * ⛔⛔ TODAY IS THE GMT DAY, because that is the day this data is stamped in.
	 *
	 * The rows carry GMT timestamps and the referral days are GMT days. Naming
	 * "today" with the SITE's local date mixed two clocks: on a site six hours
	 * ahead of UTC, the small hours of the local day asked for a GMT day that
	 * had not started, and a morning with dozens of reads behind it reported
	 * nothing at all. Found on his live site, 2026-08-25.
	 */
	public function test_today_is_the_gmt_day_even_on_a_site_hours_ahead_of_it() {
		update_option( 'timezone_string', 'Asia/Dhaka' ); // +06:00
		$this->read( gmdate( 'Y-m-d' ) );

		$today = ReportData::today( new Settings() );
		$this->assertSame( gmdate( 'Y-m-d' ), $today['range']['from'], 'the window is the GMT day' );
		$this->assertSame( 1, $today['reads']['total'], 'and it finds the reads that are actually in it' );

		delete_option( 'timezone_string' );
	}

	/**
	 * ⛔⛔ A WINDOW CANNOT RUN PAST TODAY — and the reason is not tidiness.
	 *
	 * The producers disagree about a day in the future: the activity store
	 * answers zero, and the referral store clamps the range it is handed and
	 * answers with TODAY's rows. On his site a browser six hours ahead asked
	 * for a GMT day that had not started, and one screen reported "no crawler
	 * fetched anything" beside "1 visit an AI assistant sent you" — the same
	 * card, two different days. 2026-08-25.
	 */
	public function test_a_window_cannot_run_past_today_so_every_block_reads_one_window() {
		$this->read( gmdate( 'Y-m-d' ) );
		$this->visit( gmdate( 'Y-m-d' ) );

		$tomorrow = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
		$window   = $this->report( $tomorrow, $tomorrow );

		$this->assertSame( gmdate( 'Y-m-d' ), $window['range']['to'], 'the window it actually read is today' );
		$this->assertSame( 1, $window['reads']['total'] );
		$this->assertSame( 1, $window['visits']['total'], 'reads and visits must answer the same days' );
	}

	/** The answer carries the day the server calls today, whatever was asked. */
	public function test_the_answer_names_the_servers_today_even_for_an_old_window() {
		$old    = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		$window = $this->report( $old, $old );

		$this->assertSame( gmdate( 'Y-m-d' ), $window['range']['today'], 'the presets count back from this, not from the window' );
		$this->assertSame( $old, $window['range']['to'] );
	}

	/**
	 * ⭐ A PIN, not a fix: the label names the UTC day it reports on, whatever
	 * clock the site keeps. A site behind UTC is where a day-off-by-one would
	 * show, and no test held that shut — the old line only landed right because
	 * `date_i18n()` re-reads a passed timestamp as local wall-clock. This holds
	 * the outcome still while the line under it is free to be written plainly.
	 */
	public function test_the_label_names_the_utc_day_on_a_site_behind_utc() {
		update_option( 'timezone_string', 'America/Los_Angeles' ); // -07:00
		update_option( 'date_format', 'Y-m-d' );

		$window = $this->report( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );
		$this->assertSame( gmdate( 'Y-m-d' ), $window['range']['label'] );

		delete_option( 'timezone_string' );
		update_option( 'date_format', 'F j, Y' );
	}

	/** Today's helper is the same collector, aimed at today. */
	public function test_today_is_the_same_read_as_a_one_day_window() {
		$this->read( gmdate( 'Y-m-d' ) );
		$this->assertSame(
			ReportData::today( new Settings() )['reads']['total'],
			$this->report( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) )['reads']['total']
		);
	}

	/**
	 * ⭐ THE SLICE IS A SLICE. The dashboard's Today line polls `live()` every
	 * 15 seconds instead of the full report, and the whole point of the card is
	 * that it cannot disagree with the Report screen — so for one window the
	 * live blocks must be the SAME blocks, value for value. The day this
	 * diverges, the line has quietly become a second collector.
	 */
	public function test_the_live_slice_reports_exactly_what_the_full_report_does() {
		$this->read( gmdate( 'Y-m-d' ) );
		$this->read( gmdate( 'Y-m-d' ), 'PerplexityBot' );
		$this->visit( gmdate( 'Y-m-d' ) );
		$this->read( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );

		$full = $this->report( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );
		$live = ReportData::live( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );

		foreach ( array( 'range', 'reads', 'visits', 'impostors', 'access' ) as $block ) {
			$this->assertSame( $full[ $block ], $live[ $block ], "the $block block must be one block, not two" );
		}
	}

	/**
	 * ⛔ And it carries nothing it cannot answer cheaply. The score re-runs the
	 * whole readiness report and search/citations read two more stores — work a
	 * 15-second poll must not do, for figures that cannot move between ticks.
	 */
	public function test_the_live_slice_leaves_out_the_blocks_it_does_not_show() {
		$live = ReportData::live( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );

		foreach ( array( 'score', 'nudge', 'search', 'citations', 'robots' ) as $block ) {
			$this->assertArrayNotHasKey( $block, $live );
		}
	}

	/** The clamp is the shared window's, so the slice cannot read a future day either. */
	public function test_the_live_slice_clamps_a_future_window_the_same_way() {
		$this->read( gmdate( 'Y-m-d' ) );
		$this->visit( gmdate( 'Y-m-d' ) );

		$tomorrow = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
		$live     = ReportData::live( $tomorrow, $tomorrow );

		$this->assertSame( gmdate( 'Y-m-d' ), $live['range']['to'] );
		$this->assertSame( 1, $live['reads']['total'] );
		$this->assertSame( 1, $live['visits']['total'] );
	}
}
