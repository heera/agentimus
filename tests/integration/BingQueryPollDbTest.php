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
 * The same fault sat on the Google path, where it was quieter and worse: the
 * fatal lands after the poll marks itself clean, so the snapshot saved and the
 * trend series, the Discover totals and the analytics poll silently stopped.
 *
 * So this runs the whole thing: fake HTTP in, real rows out. If any link in the
 * chain throws, this goes red.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Bing\Module;
use Agentimus\Bing\Settings as BingSettings;
use Agentimus\Search\Table;

final class BingQueryPollDbTest extends DbTestCase {

	/** @var callable|null */
	private $http = null;

	public function set_up(): void {
		parent::set_up();
		delete_option( Table::VERSION_OPTION );
		Table::install();
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
		$table = Table::name();
		$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Bing's WCF-JSON shape, close enough for the real parser to accept. Its
	 * page rows reuse the Query field for the page URL — Bing's own reuse of
	 * the DTO, not ours.
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
		$this->assertNotEmpty( Table::snapshot( 'bing' ), 'A poll that answered has to leave rows behind.' );
	}

	/**
	 * The page-attributed half specifically: those rows are what every
	 * page-level screen reads, and they are written after the point the fatal
	 * used to land.
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
