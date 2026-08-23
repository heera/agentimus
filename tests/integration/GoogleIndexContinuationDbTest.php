<?php
/**
 * The index sweep's continuation event: the safety net that finishes a run
 * nobody is watching.
 *
 * ⛔ THE LINE THIS PINS. {@see Index::sweep()} reads an EMPTY queue as "start
 * today's run". So an event that outlives the run it was armed for does not
 * resume anything — it STARTS a whole-site sweep, arms itself again, and the
 * site inspects every page it owns for as long as anyone keeps the panel open.
 * It shipped that way: an owner pressed Check Now once and watched the card
 * restart itself every few minutes, spending Google's daily budget each lap.
 *
 * These need real WordPress: the cron array is an option and scheduling is
 * core's own bookkeeping — a stub would be testing the stub.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Google\Auth;
use Agentimus\Google\Index;
use Agentimus\Google\Module;
use Agentimus\Google\Settings as GoogleSettings;
use Agentimus\Search\Table;

final class GoogleIndexContinuationDbTest extends DbTestCase {

	/** @var callable|null */
	private $http = null;

	/** @var GoogleSettings */
	private $settings;

	/** @var int How many URL inspections Google was asked for. */
	private $inspections = 0;

	public function set_up(): void {
		parent::set_up();
		delete_option( Table::VERSION_OPTION );
		Table::install();
		delete_option( Index::OPTION );
		_set_cron_array( array() );

		// A connection, without a real key: Auth::token() checks its cache
		// FIRST, so a primed transient means no JWT is signed and no token
		// endpoint is called.
		$this->settings = new GoogleSettings();
		$this->settings->connect( '{"type":"service_account"}', 'bot@example.iam.gserviceaccount.com', 'sc-domain:example.org' );
		set_transient( Auth::TOKEN_TRANSIENT, 'fake-token', HOUR_IN_SECONDS );

		// The fake goes up BEFORE the posts exist: publishing one can send the
		// plugin's own pings, and a test must never reach the real network.
		$this->fake_google();

		// Two posts plus the homepage: three targets, so a run has a middle.
		self::factory()->post->create( array( 'post_title' => 'One', 'post_status' => 'publish' ) );
		self::factory()->post->create( array( 'post_title' => 'Two', 'post_status' => 'publish' ) );

		$this->inspections = 0;
	}

	public function tear_down(): void {
		if ( $this->http ) {
			remove_filter( 'pre_http_request', $this->http, 10 );
			$this->http = null;
		}
		delete_transient( Auth::TOKEN_TRANSIENT );
		delete_option( Index::OPTION );
		_set_cron_array( array() );
		parent::tear_down();
	}

	/** Every URL comes back indexed; the sitemaps call answers with nothing. */
	private function fake_google() {
		$this->http = function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, 'urlInspection' ) ) {
				++$this->inspections;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) wp_json_encode( array(
						'inspectionResult' => array(
							'indexStatusResult' => array(
								'verdict'        => 'PASS',
								'coverageState'  => 'Submitted and indexed',
								'robotsTxtState' => 'ALLOWED',
								'indexingState'  => 'INDEXING_ALLOWED',
								'pageFetchState' => 'SUCCESSFUL',
							),
						),
					) ),
					'headers'  => array(),
				);
			}
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	/** One URL per call: a zero budget ends the chunk after the first answer. */
	private function chunk() {
		return ( new Module( $this->settings ) )->run_index_sweep( 0.0 );
	}

	/* ----------------------------------------------------------------- tests */

	/**
	 * The net is armed while a run has a tail, and stands down the moment that
	 * tail is gone. An armed event with nothing left to continue is the bug.
	 */
	public function test_the_net_stands_down_when_the_run_it_was_netting_ends() {
		$out = $this->chunk();
		$this->assertNotEmpty( $out['queue'], 'A budgeted chunk leaves a tail.' );
		$this->assertNotFalse(
			wp_next_scheduled( Module::CRON_INDEX ),
			'A run with a tail keeps a net under it — the owner may close the tab.'
		);

		$guard = 20;
		while ( ! empty( $out['queue'] ) && --$guard > 0 ) {
			$out = $this->chunk();
		}
		$this->assertEmpty( $out['queue'], 'The run finished inside the guard.' );

		$this->assertFalse(
			wp_next_scheduled( Module::CRON_INDEX ),
			'The run is over: an event left behind here would start a new one.'
		);
	}

	/**
	 * And if one is left behind anyway — armed by a chunk, then outrun to the
	 * finish by the panel's own loop — firing it must not start a run.
	 */
	public function test_a_stray_continuation_resumes_nothing_and_starts_nothing() {
		$guard = 20;
		do {
			$out = $this->chunk();
		} while ( ! empty( $out['queue'] ) && --$guard > 0 );
		$this->assertEmpty( $out['queue'] );

		$done   = Index::stored();
		$before = $this->inspections;

		// The stray event, fired by hand exactly as cron would fire it.
		( new Module( $this->settings ) )->index_chunk();

		$this->assertSame( $before, $this->inspections, 'Not one inspection was spent.' );
		$this->assertEmpty( Index::stored()['queue'], 'No run was started.' );
		$this->assertSame(
			$done['checked_at'],
			Index::stored()['checked_at'],
			'Nothing was rewritten — the card still reads the run that finished.'
		);
		$this->assertFalse( wp_next_scheduled( Module::CRON_INDEX ), 'And it armed nothing on its way out.' );
	}

	/* ------------------------------------------------------- the owner's stop */

	/**
	 * ⛔ WHAT CANCEL HAS TO MEAN. Before this, pressing it ended the loop in one
	 * browser tab and nothing else: the continuation event finished the run
	 * within minutes anyway, and a page refresh set the loop going again — while
	 * the card promised the rest would wait for the next press or the daily
	 * check.
	 */
	public function test_cancel_takes_the_net_down_and_the_event_does_nothing() {
		$out = $this->chunk();
		$this->assertNotEmpty( $out['queue'] );
		$this->assertNotFalse( wp_next_scheduled( Module::CRON_INDEX ) );

		$this->assertTrue( ( new Module( $this->settings ) )->pause_index_sweep() );
		$this->assertFalse( wp_next_scheduled( Module::CRON_INDEX ), 'The net comes down with the run.' );

		$before = $this->inspections;
		( new Module( $this->settings ) )->index_chunk();
		( new Module( $this->settings ) )->run_index_sweep( 0.0 );

		$this->assertSame( $before, $this->inspections, 'Neither the event nor a stray sweep spent an inspection.' );
		$this->assertNotEmpty( Index::stored()['queue'], 'The queue is stopped, not thrown away.' );
		$this->assertFalse( wp_next_scheduled( Module::CRON_INDEX ), 'And nothing re-armed the net behind the owner.' );
	}

	/** The queue survives to be finished later — by a press, or by the daily check. */
	public function test_a_press_resumes_the_same_run_and_the_daily_check_lifts_the_pause() {
		$this->chunk();
		( new Module( $this->settings ) )->pause_index_sweep();
		$left = count( Index::stored()['queue'] );
		$this->assertSame( 2, $left );

		// The press: exactly what POST /google/index does.
		Index::resume();
		$this->chunk();
		$this->assertSame( 1, count( Index::stored()['queue'] ), 'It carried on from where it stopped — not a new run.' );

		( new Module( $this->settings ) )->pause_index_sweep();
		$this->assertTrue( Index::is_paused() );

		// The daily check: the promise the card makes when it says the rest can wait.
		Index::resume();
		$this->chunk();
		$this->assertEmpty( Index::stored()['queue'] );
		$this->assertSame( 3, $this->inspections, 'Three pages, each asked about exactly once across the whole stop-start.' );
	}

	/**
	 * The net still does its own job: a run abandoned mid-flight is picked up
	 * and finished by the event, not left for the owner to press again.
	 */
	public function test_a_run_left_mid_flight_is_still_carried_by_the_event() {
		$out = $this->chunk();
		$this->assertNotEmpty( $out['queue'] );

		$guard = 20;
		while ( ! empty( Index::stored()['queue'] ) && --$guard > 0 ) {
			$this->assertNotFalse( wp_next_scheduled( Module::CRON_INDEX ), 'The net stays armed until the tail is gone.' );
			( new Module( $this->settings ) )->index_chunk();
		}

		$this->assertEmpty( Index::stored()['queue'], 'The event carried the run to the end on its own.' );
		$this->assertSame( 3, $this->inspections, 'The homepage and both posts, once each.' );
	}
}
