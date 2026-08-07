<?php
/**
 * Recorder flood control against a real DB + real transients — the abuse-ceiling behaviour that
 * the pure survives_flood() unit tests cannot see, because the amplification lived in the BUCKETING
 * (note_hit), not the sampling policy.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Activity\Recorder;
use Agentimus\Activity\Table;

final class RecorderFloodDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		Table::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() ); // phpcs:ignore WordPress.DB
		update_option( 'agentimus_settings', array( 'enable_activity' => true ) );
		$this->reset_buckets();
	}

	public function tear_down(): void {
		$this->reset_buckets();
		unset( $_SERVER['HTTP_USER_AGENT'] );
		parent::tear_down();
	}

	/** Clear the two shared flood buckets for the current window. */
	private function reset_buckets(): void {
		$win = (int) floor( time() / Recorder::FLOOD_WINDOW );
		foreach ( array( 'a', 'u' ) as $bucket ) {
			delete_transient( Recorder::RATE_PREFIX . md5( $bucket ) . '_' . $win );
		}
	}

	private function rows(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
	}

	public function test_rotating_known_bot_names_cannot_multiply_the_write_budget() {
		// THE fix. A per-name bucket handed each forgeable known-bot string its own 300-budget, so an
		// attacker rotating ~22 names multiplied it to thousands of guaranteed INSERTs/min. All
		// recognised traffic now shares ONE budget, so rotating names buys nothing: the total stays
		// near RECOGNISED_THRESHOLD plus a ~1-in-FLOOD_SAMPLE tail.
		$names = array( 'GPTBot', 'ClaudeBot', 'Googlebot', 'PerplexityBot', 'Bytespider', 'CCBot', 'Amazonbot', 'Bingbot', 'Applebot', 'YandexBot' );

		// The budget is per FLOOD_WINDOW, so a run slow enough to cross a window
		// boundary legitimately gets a second one. That is the product working —
		// but a fixed ceiling of 2x the threshold sits exactly ON that boundary,
		// so the test failed on CI's slowest job (multisite) whenever 1000 real
		// inserts straddled a minute: 300 + 300 + tail = 630 against a 600 bar.
		// Count the windows this run actually spanned and scale with them; the
		// claim being tested is that ten NAMES do not buy ten budgets, which is
		// independent of how many minutes the loop took.
		$first_window = (int) floor( time() / Recorder::FLOOD_WINDOW );
		foreach ( $names as $name ) {
			$_SERVER['HTTP_USER_AGENT'] = $name . '/1.0 (compatible)';
			for ( $i = 0; $i < 100; $i++ ) {
				Recorder::record( 'llms.txt' );
			}
		}
		$windows = (int) floor( time() / Recorder::FLOOD_WINDOW ) - $first_window + 1;

		$logged = $this->rows();
		// Per window: the full budget, plus a generous allowance for the
		// ~1-in-FLOOD_SAMPLE tail. Ten names sharing one budget lands near 335;
		// ten names each holding their own would be thousands.
		$ceiling = $windows * Recorder::RECOGNISED_THRESHOLD * 2;
		$this->assertLessThan(
			$ceiling,
			$logged,
			"1000 rotating-name requests logged $logged rows across $windows flood window(s); a shared budget must keep this near RECOGNISED_THRESHOLD per window, not multiply per name."
		);
	}

	public function test_the_flood_counter_freezes_so_writes_are_bounded() {
		// On a site with no persistent object cache each set_transient is a wp_options write. Past the
		// threshold the exact count changes no decision (everything is sampled), so the stored counter
		// FREEZES — otherwise a sustained flood wrote an ever-larger integer on every request.
		$_SERVER['HTTP_USER_AGENT'] = 'GPTBot/1.0 (compatible)';
		for ( $i = 0; $i < 2000; $i++ ) {
			Recorder::record( 'llms.txt' );
		}

		$win    = (int) floor( time() / Recorder::FLOOD_WINDOW );
		$stored = (int) get_transient( Recorder::RATE_PREFIX . md5( 'a' ) . '_' . $win );
		$this->assertLessThanOrEqual(
			Recorder::RECOGNISED_THRESHOLD + 1,
			$stored,
			"The stored counter reached $stored after a 2000-hit flood; it must freeze at RECOGNISED_THRESHOLD+1 so the per-request write pressure is bounded."
		);
	}

	public function test_a_single_legitimate_crawl_under_budget_is_logged_in_full() {
		// The fix must not throttle a real crawler. A single recognised crawler doing a modest crawl
		// (250 < 300) on a clean window logs every hit — no regression.
		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
		for ( $i = 0; $i < 250; $i++ ) {
			Recorder::record( 'post.md' );
		}

		$this->assertSame( 250, $this->rows(), 'A legit crawl under the recognised budget must be logged in full.' );
	}
}
