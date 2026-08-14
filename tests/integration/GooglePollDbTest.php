<?php
/**
 * The Google Search Console poll, run end to end against a real database.
 *
 * ⚠️ WHY THIS EXISTS. The same TypeError that crashed the Bing card sat here
 * too, and here it was quieter and worse. `Progress::observe()` demands the
 * plugin's CORE settings and was handed Google\Settings — but the fatal lands
 * AFTER the poll stamps itself clean, so the snapshot saved and the card looked
 * fine while everything BELOW that line silently stopped running: the daily
 * trend series, the Discover totals, the analytics poll. The card said "recent
 * daily polls couldn't update it" for three days and nothing said why.
 *
 * A test per piece cannot catch that. Only running the path can, so this runs
 * it: a primed token, faked HTTP in, real rows out — and the proof it reached
 * the END of the poll, not just the middle.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Google\Auth;
use Agentimus\Google\Module;
use Agentimus\Google\Settings as GoogleSettings;
use Agentimus\Search\Table;

final class GooglePollDbTest extends DbTestCase {

	/** @var callable|null */
	private $http = null;

	/** @var GoogleSettings */
	private $settings;

	public function set_up(): void {
		parent::set_up();
		delete_option( Table::VERSION_OPTION );
		Table::install();
		$this->wipe();

		// A connection, without a real key: Auth::token() checks its cache
		// FIRST, so a primed transient means no JWT is signed and no token
		// endpoint is called. The key text is never parsed on this path.
		$this->settings = new GoogleSettings();
		$this->settings->connect( '{"type":"service_account"}', 'bot@example.iam.gserviceaccount.com', 'sc-domain:example.org' );
		set_transient( Auth::TOKEN_TRANSIENT, 'fake-token', HOUR_IN_SECONDS );

		$this->fake_google();
	}

	public function tear_down(): void {
		if ( $this->http ) {
			remove_filter( 'pre_http_request', $this->http, 10 );
			$this->http = null;
		}
		delete_transient( Auth::TOKEN_TRANSIENT );
		$this->wipe();
		parent::tear_down();
	}

	/** ⚠️ DELETE, not TRUNCATE — TRUNCATE commits and breaks test isolation. */
	private function wipe() {
		global $wpdb;
		$table = Table::name();
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Search Console's own shapes. The poll makes several different calls and
	 * every one of them has to answer, or a failure downstream would be
	 * indistinguishable from the crash this pins.
	 */
	private function fake_google() {
		$this->http = static function ( $pre, $args, $url ) {
			$body = isset( $args['body'] ) ? (string) $args['body'] : '';
			$sent = json_decode( $body, true );
			$dims = isset( $sent['dimensions'] ) ? (array) $sent['dimensions'] : array();

			if ( false === strpos( $url, 'searchAnalytics/query' ) ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
			}

			// One call per shape, told apart by what it asks to group by.
			if ( in_array( 'date', $dims, true ) ) {
				$rows = array(
					array( 'keys' => array( gmdate( 'Y-m-d', time() - 4 * DAY_IN_SECONDS ) ), 'clicks' => 3, 'impressions' => 120 ),
					array( 'keys' => array( gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ) ), 'clicks' => 5, 'impressions' => 140 ),
				);
			} elseif ( in_array( 'query', $dims, true ) && in_array( 'page', $dims, true ) ) {
				$rows = array(
					array( 'keys' => array( 'https://example.org/a-page/', 'llms txt wordpress' ), 'clicks' => 9, 'impressions' => 300, 'position' => 5.1 ),
					array( 'keys' => array( 'https://example.org/b-page/', 'agent readiness' ), 'clicks' => 2, 'impressions' => 80, 'position' => 12.4 ),
				);
			} else {
				// Discover and anything else: a single totals row.
				$rows = array( array( 'clicks' => 11, 'impressions' => 900 ) );
			}

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'rows' => $rows ) ),
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	/**
	 * The poll survives and stores what it fetched.
	 */
	public function test_the_poll_runs_end_to_end_and_leaves_real_rows() {
		( new Module( $this->settings ) )->run_poll();

		$this->assertSame( '', (string) $this->settings->get( 'last_error', 'unset' ), 'A clean poll records no error.' );
		$this->assertNotEmpty( Table::snapshot( 'google' ), 'A poll that answered has to leave rows behind.' );
	}

	/**
	 * ⚠️ THE ONE THAT MATTERS. Rows landing proves the poll reached the middle.
	 * The crash was AFTER that, so the only honest proof is something written by
	 * a line BELOW it — the daily trend, which is what an owner actually watched
	 * go stale for three days.
	 */
	public function test_the_poll_reaches_the_work_that_comes_after_the_snapshot() {
		( new Module( $this->settings ) )->run_poll();

		$trend = get_option( Module::TREND_OPTION, array() );

		$this->assertNotEmpty( $trend['daily'] ?? array(), 'The daily series is written after the snapshot — if it is empty, the poll died in the middle again.' );
		$this->assertNotEmpty( $trend['discover'] ?? array(), 'Discover totals are fetched after the daily series — the tail of the poll has to run too.' );
	}
}
