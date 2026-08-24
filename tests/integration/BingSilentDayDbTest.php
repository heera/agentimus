<?php
/**
 * A day Bing said nothing about is not a day the site scored zero.
 *
 * ⭐⭐ WHAT THIS PINS. Bing's GetCrawlStats answers with a row per date in its
 * window whether or not it holds numbers for that date, and every absent field
 * arrives as 0 ({@see \Agentimus\Bing\Client::crawl_stats()}). Stored and read
 * as a measurement, that becomes "0 pages in Bing's index" — printed under a
 * heading that means exactly what it says, on a site holding 229.
 *
 * Found on his own install: August 21 sat between 230 and 229 with every counter
 * at zero, drawn as a collapse to nothing on the day-by-day chart.
 *
 * ⛔ THE DISCRIMINATOR IS "EVERY COUNTER ZERO", NOT `in_index === 0`. A quiet
 * day — nothing crawled, no errors — is a real day, and its index count still
 * stands. Only a row with nothing at all in it cannot be something Bing
 * measured. {@see \Agentimus\Bing\Table::reported()}
 *
 * ⛔ AND THE DAY IS KEPT, NOT DROPPED. Closing the gap would make the days
 * either side read as consecutive. It is stored, marked, and drawn as a gap the
 * screen can name.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Bing\Settings;
use Agentimus\Bing\Summary;
use Agentimus\Bing\Table;

final class BingSilentDayDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Table::VERSION_OPTION );
		Table::install();
		$this->reset();
	}

	public function tear_down(): void {
		$this->reset();
		parent::tear_down();
	}

	/** ⚠️ DELETE, never TRUNCATE — TRUNCATE commits and ends the test's transaction. */
	private function reset() {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
	}

	/** A day Bing reported properly. */
	private function full( $date, $in_index = 229, $crawled = 49 ) {
		return array(
			'date_at'        => $date,
			'crawled'        => $crawled,
			'in_index'       => $in_index,
			'in_links'       => 12,
			'blocked_robots' => 0,
			'code_2xx'       => 260,
			'code_301'       => 60,
			'code_302'       => 0,
			'code_4xx'       => 0,
			'code_5xx'       => 0,
		);
	}

	/** A date Bing answered for and said nothing about. */
	private function silent( $date ) {
		return array(
			'date_at'        => $date,
			'crawled'        => 0,
			'in_index'       => 0,
			'in_links'       => 0,
			'blocked_robots' => 0,
			'code_2xx'       => 0,
			'code_301'       => 0,
			'code_302'       => 0,
			'code_4xx'       => 0,
			'code_5xx'       => 0,
		);
	}

	private function row_for( $date ) {
		foreach ( Table::window( 90 ) as $row ) {
			if ( $row['date_at'] === $date ) {
				return $row;
			}
		}
		return null;
	}

	/* ------------------------------------------------------- the reading */

	public function test_a_row_with_nothing_in_it_is_not_a_reading() {
		$this->assertTrue( Table::reported( $this->full( '2026-08-22' ) ) );
		$this->assertFalse( Table::reported( $this->silent( '2026-08-21' ) ) );
	}

	/**
	 * ⛔ A quiet day is a REAL day. Nothing crawled and no errors, but the index
	 * still holds 229 — dropping this would delete true readings.
	 */
	public function test_a_quiet_crawl_day_is_still_a_reading() {
		$quiet                   = $this->silent( '2026-08-20' );
		$quiet['in_index']       = 229;
		$this->assertTrue( Table::reported( $quiet ), 'The index count alone makes it a measurement.' );
	}

	/* ------------------------------------------------------- the storing */

	/**
	 * ⭐⭐ THE DATA-LOSS GUARD. Bing re-sends its whole window on every poll, so a
	 * date it reported properly yesterday can come back today carrying nothing.
	 * ON DUPLICATE KEY UPDATE would replace good numbers with zeros.
	 */
	public function test_a_silent_row_never_overwrites_numbers_already_stored() {
		Table::upsert( array( $this->full( '2026-08-22', 230, 64 ) ) );
		$this->assertSame( 230, (int) $this->row_for( '2026-08-22' )['in_index'] );

		// The next poll answers for the same date with nothing in it.
		Table::upsert( array( $this->silent( '2026-08-22' ) ) );

		$kept = $this->row_for( '2026-08-22' );
		$this->assertSame( 230, (int) $kept['in_index'], '⛔ A non-answer must never erase a reading.' );
		$this->assertSame( 64, (int) $kept['crawled'] );
		$this->assertTrue( Table::reported( $kept ) );
	}

	/**
	 * ⭐ But a NEW silent date is still worth keeping: it is how the screen can
	 * show a day Bing answered for and said nothing about, instead of closing
	 * the gap and letting the days either side read as consecutive.
	 */
	public function test_a_silent_day_is_kept_so_the_gap_can_be_seen() {
		Table::upsert( array(
			$this->full( '2026-08-20', 228 ),
			$this->silent( '2026-08-21' ),
			$this->full( '2026-08-22', 230 ),
		) );

		$dates = array_column( Table::window( 90 ), 'date_at' );
		$this->assertSame( array( '2026-08-20', '2026-08-21', '2026-08-22' ), $dates, 'The day keeps its place in the series.' );
		$this->assertFalse( Table::reported( $this->row_for( '2026-08-21' ) ) );
	}

	/* -------------------------------------------------------- the screen */

	private function summary() {
		$bing = new Settings();
		$bing->connect( 'a-read-only-key', 'https://example.com' );
		return Summary::build( $bing, new \Agentimus\Settings(), 30 );
	}

	/**
	 * ⭐⭐ THE ONE THAT WOULD HAVE BEEN WORST ON SCREEN. The headline tiles read
	 * the most recent row. A window ending on a silent day printed
	 * "0 pages in Bing's index" — the card telling an owner their site had left
	 * the index, because Bing had not spoken that day.
	 */
	public function test_the_headline_tiles_skip_a_day_bing_said_nothing_about() {
		Table::upsert( array(
			$this->full( '2026-08-22', 230, 64 ),
			$this->silent( '2026-08-23' ),
		) );

		$out = $this->summary();
		$this->assertSame( 230, (int) $out['totals']['inIndex'], '⛔ Never 0 — that is Bing’s silence, not the site’s index.' );
		$this->assertSame( 64, (int) $out['totals']['crawledLatest'] );
	}

	/** Every day travels with a flag saying whether it is a reading at all. */
	public function test_each_day_says_whether_it_is_a_reading() {
		Table::upsert( array(
			$this->full( '2026-08-22', 230 ),
			$this->silent( '2026-08-23' ),
		) );

		$trend = $this->summary()['trend'];
		$this->assertCount( 2, $trend );
		$this->assertTrue( $trend[0]['reported'] );
		$this->assertFalse( $trend[1]['reported'], 'The screen needs this to draw the gap rather than a collapse.' );
		$this->assertSame( 0, (int) $trend[1]['inIndex'], 'The zero is still carried — it is simply not a measurement.' );
	}
}
