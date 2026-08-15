<?php
/**
 * The Bing query poll, run end to end against a real database.
 *
 * ⚠️ WHY THIS EXISTS. Every piece of this path had a test and every piece
 * passed; the PATH had none, and a TypeError sat in the middle of it —
 * `Progress::observe()` was handed the module's own connection store where it
 * demands the plugin's CORE settings. WordPress turned that into "There has
 * been a critical error on this website" on the Bing card, and it only ever
 * fired on the one branch nothing exercised: a poll that actually stored rows.
 *
 * So this test does the whole thing: fake HTTP in, real rows and a real ask
 * ledger out. If any link in the chain throws, this goes red.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Bing\Module;
use Agentimus\Bing\Settings as BingSettings;
use Agentimus\Search\Asks;
use Agentimus\Search\Table;

final class BingQueryPollDbTest extends DbTestCase {

	/** @var callable|null */
	private $http = null;

	public function set_up(): void {
		parent::set_up();
		delete_option( Table::VERSION_OPTION );
		delete_option( Asks::VERSION_OPTION );
		Table::install();
		Asks::install();
		$this->wipe();
		$this->fake_bing();
	}

	public function tear_down(): void {
		if ( $this->http ) {
			remove_filter( 'pre_http_request', $this->http, 10 );
			$this->http = null;
		}
		$this->wipe();
		parent::tear_down();
	}

	/** ⚠️ DELETE, not TRUNCATE — TRUNCATE commits and breaks test isolation. */
	private function wipe() {
		global $wpdb;
		$q = Table::name();
		$a = Asks::name();
		$wpdb->query( "DELETE FROM $q" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM $a" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Bing's WCF-JSON shape, close enough for the real parser to accept: rows
	 * keyed on Query (the page URL travels in that field for page_stats, which
	 * is Bing's own reuse of the DTO, not ours).
	 */
	private function fake_bing() {
		$row = static function ( $key, $clicks, $impressions, $position ) {
			return array(
				'Query'                 => $key,
				'Clicks'                => $clicks,
				'Impressions'           => $impressions,
				'AvgImpressionPosition' => $position,
				// A Friday, in Bing's /Date(ms)/ form — its buckets are weekly.
				'Date'                  => '/Date(' . ( strtotime( 'last friday', time() ) * 1000 ) . ')/',
			);
		};

		$this->http = static function ( $pre, $args, $url ) use ( $row ) {
			$d = array();
			// ⚠️ Order matters: GetPageQueryStats must be matched before the
			// shorter names, or a substring test claims it first.
			if ( false !== strpos( $url, 'GetPageQueryStats' ) ) {
				$d = array( $row( 'agent readiness score', 4, 90, 3.5 ) );
			} elseif ( false !== strpos( $url, 'GetQueryStats' ) ) {
				$d = array( $row( 'llms txt wordpress', 9, 300, 5.1 ) );
			} elseif ( false !== strpos( $url, 'GetPageStats' ) ) {
				$d = array( $row( 'https://example.org/a-page/', 6, 200, 6.2 ) );
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'd' => $d ) ),
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	/**
	 * The whole poll, start to finish. Before the fix this died on the
	 * Progress::observe() call and nothing below it ever ran.
	 */
	public function test_the_query_poll_runs_end_to_end_and_leaves_real_rows() {
		$settings = new BingSettings();

		( new Module( $settings ) )->run_query_poll( 'FAKE-KEY', 'https://example.org/' );

		// It survived — that alone is the regression this pins.
		$this->assertSame( '', (string) $settings->get( 'last_query_error', 'unset' ), 'A clean poll records no error.' );

		$rows = Table::snapshot( 'bing' );
		$this->assertNotEmpty( $rows, 'A poll that answered has to leave rows behind.' );

		// ⭐ Bing's rows are stored as 'all', never 'web' — its API has no
		// surface split, so claiming one would put words in Bing's mouth.
		$this->assertNotEmpty( Table::snapshot( 'bing', Table::TYPE_ALL ) );
		$this->assertEmpty( Table::snapshot( 'bing', Table::TYPE_WEB ) );
	}

	/**
	 * The page it asked about is written down — the record that lets an empty
	 * column say WHICH kind of empty it is.
	 */
	public function test_the_poll_records_what_it_asked_bing_about() {
		( new Module( new BingSettings() ) )->run_query_poll( 'FAKE-KEY', 'https://example.org/' );

		$this->assertGreaterThan( 0, Asks::asked( 'bing' ), 'Every page asked about is written down.' );

		$state = Asks::state( 'bing', 'https://example.org/a-page/', 0 );
		$this->assertNotNull( $state, 'The page Bing named is the first one asked about.' );
		$this->assertSame( Asks::STATUS_OK, $state['status'] );
		$this->assertGreaterThan( 0, $state['found'] );
	}

	/**
	 * A clean run earns a bigger batch. Bing publishes no rate limit, so this
	 * pacing is the only thing standing in for one.
	 */
	public function test_a_clean_run_lets_the_batch_grow() {
		$settings = new BingSettings();
		$before   = (int) $settings->get( 'ask_batch', Module::ASK_BATCH_START );

		( new Module( $settings ) )->run_query_poll( 'FAKE-KEY', 'https://example.org/' );

		$after = (int) ( new BingSettings() )->get( 'ask_batch', 0 );
		$this->assertGreaterThan( $before, $after );
		$this->assertLessThanOrEqual( Module::ASK_BATCH_MAX, $after );
	}

	/**
	 * ⚠️ THE ONE THAT WOULD HAVE CAUGHT IT. The ladder reads "grows slowly on
	 * clean days" but stepped on clean RUNS — and Refresh is a run. Four clicks
	 * took the batch from 10 to 30; a fifth sat it at the ceiling, firing fifty
	 * requests at an API with no published rate limit. Growth is rationed per
	 * calendar day now, however many polls happen inside it.
	 */
	public function test_pressing_refresh_again_does_not_walk_the_batch_up() {
		$settings = new BingSettings();

		( new Module( $settings ) )->run_query_poll( 'FAKE-KEY', 'https://example.org/' );
		$after_first = (int) ( new BingSettings() )->get( 'ask_batch', 0 );

		for ( $i = 0; $i < 3; $i++ ) {
			( new Module( new BingSettings() ) )->run_query_poll( 'FAKE-KEY', 'https://example.org/' );
		}

		$this->assertSame(
			$after_first,
			(int) ( new BingSettings() )->get( 'ask_batch', 0 ),
			'Three more polls the same day must not move it.'
		);
	}

	/**
	 * The other half: backing off is the SAFETY half and must land on the run
	 * that earned it, not wait for tomorrow.
	 */
	public function test_a_refusal_halves_the_batch_the_same_day() {
		$settings = new BingSettings();
		( new Module( $settings ) )->run_query_poll( 'FAKE-KEY', 'https://example.org/' );
		$grown = (int) ( new BingSettings() )->get( 'ask_batch', 0 );

		// Bing starts refusing the per-page question.
		remove_filter( 'pre_http_request', $this->http, 10 );
		$this->http = static function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, 'GetPageQueryStats' ) ) {
				return new \WP_Error( 'http_request_failed', 'too many requests' );
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'd' => array() ) ),
				'headers'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );

		( new Module( new BingSettings() ) )->run_query_poll( 'FAKE-KEY', 'https://example.org/' );

		$this->assertLessThan( $grown, (int) ( new BingSettings() )->get( 'ask_batch', 0 ), 'A refusal backs off immediately.' );
	}

	/**
	 * Carried over from the same file's other half when the two lines of this
	 * test met on main: the per-page breakdown is what the worklist reads, and a
	 * poll that stored only the site-wide totals would pass every test above
	 * while leaving that half of the screen empty.
	 */
	public function test_the_poll_stores_the_page_attributed_rows_too() {
		( new Module( new BingSettings() ) )->run_query_poll( 'FAKE-KEY', 'https://example.org/' );

		$pages = array_filter(
			Table::snapshot( 'bing' ),
			static function ( $row ) {
				return '' !== (string) $row['page_url'];
			}
		);
		$this->assertNotEmpty( $pages, 'The per-page breakdown is the half the worklist reads.' );
	}
}
