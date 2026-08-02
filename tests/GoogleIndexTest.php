<?php
/**
 * The Google index watch: the inspection client's response normalization and
 * failure classification, the watchlist selection rules, the sweep's three
 * failure lanes (quota / one bad URL / dead token), and the view every
 * surface reads.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Google\Client;
use Agentimus\Google\Index;
use Agentimus\Google\Settings;
use PHPUnit\Framework\TestCase;

final class GoogleIndexTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_http_queue'] = array();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		$GLOBALS['_af_http_queue'] = array();
		unset( $GLOBALS['_af_http_last'] );
	}

	/* -- helpers ------------------------------------------------------------ */

	private function queue_inspection( array $isr, array $extra = array() ): void {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'inspectionResult' => array_merge( array( 'indexStatusResult' => $isr ), $extra ) ) ),
			'headers'  => array(),
		);
	}

	private function queue_error( int $code, string $message = 'nope' ): void {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( array( 'error' => array( 'message' => $message ) ) ),
			'headers'  => array(),
		);
	}

	private function pass_row(): array {
		return array(
			'verdict'        => 'PASS',
			'coverageState'  => 'Submitted and indexed',
			'robotsTxtState' => 'ALLOWED',
			'indexingState'  => 'INDEXING_ALLOWED',
			'pageFetchState' => 'SUCCESSFUL',
			'lastCrawlTime'  => '2026-07-30T02:00:00Z',
			'googleCanonical' => 'https://example.test/a/',
		);
	}

	private function connect( array $index_payload = null ): Settings {
		$GLOBALS['_af_options']['agentimus_google'] = array(
			'sa_json'  => 'ciphertext-not-empty',
			'property' => 'sc-domain:example.test',
		);
		if ( null !== $index_payload ) {
			$GLOBALS['_af_options'][ Index::OPTION ] = $index_payload;
		}
		return new Settings();
	}

	/* -- the client --------------------------------------------------------- */

	public function test_inspect_url_normalizes_a_full_answer() {
		$this->queue_inspection( $this->pass_row() );

		$out = ( new Client() )->inspect_url( 'tok', 'sc-domain:example.test', 'https://example.test/a/' );
		$this->assertSame( 'PASS', $out['result']['verdict'] );
		$this->assertSame( 'Submitted and indexed', $out['result']['coverage_state'] );
		$this->assertSame( 'ALLOWED', $out['result']['robots_state'] );
		$this->assertSame( strtotime( '2026-07-30T02:00:00Z' ), $out['result']['last_crawl'] );
		$this->assertSame( 'https://example.test/a/', $out['result']['google_canonical'] );

		$sent = json_decode( (string) $GLOBALS['_af_http_last']['args']['body'], true );
		$this->assertSame( 'https://example.test/a/', $sent['inspectionUrl'] );
		$this->assertSame( 'sc-domain:example.test', $sent['siteUrl'] );
	}

	public function test_inspect_url_carries_the_insight_fields() {
		$this->queue_inspection(
			array_merge( $this->pass_row(), array(
				'crawledAs' => 'MOBILE',
				'sitemap'   => array( 'https://example.test/agentimus-sitemap.xml' ),
			) ),
			array(
				'inspectionResultLink' => 'https://search.google.com/search-console/inspect?resource_id=x',
				'richResultsResult'    => array(
					'verdict'       => 'PASS',
					'detectedItems' => array(
						array(
							'richResultType' => 'Article',
							'items'          => array( array( 'name' => 'a', 'issues' => array( array( 'issueMessage' => 'x' ), array( 'issueMessage' => 'y' ) ) ) ),
						),
					),
				),
			)
		);

		$out = ( new Client() )->inspect_url( 'tok', 'p', 'https://example.test/a/' );
		$this->assertTrue( $out['result']['in_sitemap'] );
		$this->assertSame( 'MOBILE', $out['result']['crawled_as'] );
		$this->assertSame( 'https://search.google.com/search-console/inspect?resource_id=x', $out['result']['gsc_link'] );
		$this->assertSame( 'Article', $out['result']['rich_types'] );
		$this->assertSame( 2, $out['result']['rich_issues'] );
	}

	public function test_inspect_url_reports_never_crawled_as_zero() {
		// Google encodes "never" as the epoch — that must not surface as 1970.
		$this->queue_inspection( array( 'verdict' => 'NEUTRAL', 'lastCrawlTime' => '1970-01-01T00:00:00Z' ) );
		$out = ( new Client() )->inspect_url( 'tok', 'p', 'https://example.test/x/' );
		$this->assertSame( 0, $out['result']['last_crawl'] );
	}

	public function test_inspect_url_classifies_quota_and_per_url_failures() {
		$this->queue_error( 429, 'Quota exceeded' );
		$out = ( new Client() )->inspect_url( 'tok', 'p', 'https://example.test/x/' );
		$this->assertTrue( $out['quota'] );

		$this->queue_error( 400, 'URL is not in property' );
		$out = ( new Client() )->inspect_url( 'tok', 'p', 'https://elsewhere.test/x/' );
		$this->assertFalse( $out['quota'] );
		$this->assertSame( 400, $out['status'] );
	}

	/* -- watchlist selection ------------------------------------------------ */

	public function test_select_dedupes_and_lets_the_first_reason_win() {
		$home = array( array( 'url' => 'https://example.test/', 'post_id' => 0, 'reason' => 'home' ) );
		// The same page as newest AND busiest, in both trailing-slash forms.
		$newest  = array( array( 'url' => 'https://example.test/fresh/', 'post_id' => 9, 'reason' => 'new' ) );
		$busiest = array(
			array( 'url' => 'https://example.test/fresh', 'post_id' => 9, 'reason' => 'busy' ),
			array( 'url' => 'https://example.test/hit/', 'post_id' => 5, 'reason' => 'busy' ),
		);

		$out = Index::select( $home, $newest, $busiest );
		$this->assertCount( 3, $out );
		$this->assertSame( array( 'home', 'new', 'busy' ), array_column( $out, 'reason' ) );
		$this->assertSame( 'https://example.test/fresh/', $out[1]['url'], 'the new-post mention won the dedup' );
	}

	public function test_select_caps_the_watchlist() {
		$busiest = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$busiest[] = array( 'url' => "https://example.test/p$i/", 'post_id' => $i, 'reason' => 'busy' );
		}
		$out = Index::select( array(), array(), $busiest );
		$this->assertCount( Index::BUSIEST, $out, 'without home/newest rows the busiest list still honors its own cap…' );

		$newest = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$newest[] = array( 'url' => "https://example.test/n$i/", 'post_id' => 100 + $i, 'reason' => 'new' );
		}
		$out = Index::select( array(), $newest, $busiest );
		$this->assertLessThanOrEqual( 1 + Index::NEWEST + Index::BUSIEST, count( $out ), '…and the total never exceeds the stated budget' );
	}

	/* -- the sweep's failure lanes ------------------------------------------ */

	private function targets( ...$urls ): array {
		$out = array();
		foreach ( $urls as $i => $url ) {
			$out[] = array( 'url' => $url, 'post_id' => $i, 'reason' => 'busy' );
		}
		return $out;
	}

	public function test_sweep_stores_rows_in_watchlist_order() {
		$this->queue_inspection( $this->pass_row() );
		$this->queue_inspection( array( 'verdict' => 'NEUTRAL', 'coverageState' => 'Crawled - currently not indexed' ) );

		$out = Index::sweep( new Client(), 'tok', 'p', $this->targets( 'https://example.test/a/', 'https://example.test/b/' ) );
		$this->assertSame( '', $out['error'] );
		$this->assertFalse( $out['quota'] );
		$this->assertCount( 2, $out['rows'] );
		$this->assertSame( 'PASS', $out['rows'][0]['verdict'] );
		$this->assertSame( 'Crawled - currently not indexed', $out['rows'][1]['coverage_state'] );
		$this->assertGreaterThan( 0, $out['rows'][0]['inspected_at'] );
	}

	public function test_sweep_quota_keeps_last_good_answers_for_the_rest() {
		// Yesterday's sweep answered both pages.
		$this->queue_inspection( $this->pass_row() );
		$this->queue_inspection( $this->pass_row() );
		$targets = $this->targets( 'https://example.test/a/', 'https://example.test/b/' );
		Index::sweep( new Client(), 'tok', 'p', $targets );
		$yesterday = Index::stored();

		// Today the budget dies after the first page.
		$this->queue_inspection( array( 'verdict' => 'NEUTRAL', 'coverageState' => 'Discovered - currently not indexed' ) );
		$this->queue_error( 429, 'Quota exceeded' );
		$out = Index::sweep( new Client(), 'tok', 'p', $targets );

		$this->assertTrue( $out['quota'], 'the spent budget is a named state' );
		$this->assertCount( 2, $out['rows'], 'the unreached page did not vanish' );
		$this->assertSame( 'NEUTRAL', $out['rows'][0]['verdict'], 'the reached page carries today\'s answer' );
		$this->assertSame( 'PASS', $out['rows'][1]['verdict'], 'the unreached page keeps yesterday\'s' );
		$this->assertSame(
			$yesterday['rows'][1]['inspected_at'],
			$out['rows'][1]['inspected_at'],
			'the kept answer keeps its own timestamp — it must not claim today'
		);
	}

	public function test_sweep_stops_on_a_dead_token_and_keeps_everything() {
		$this->queue_inspection( $this->pass_row() );
		$this->queue_inspection( $this->pass_row() );
		$targets = $this->targets( 'https://example.test/a/', 'https://example.test/b/' );
		Index::sweep( new Client(), 'tok', 'p', $targets );

		$this->queue_error( 403, 'Permission denied' );
		// A poisoned second answer: if the sweep wrongly kept going, page B
		// would swallow this and the kept-answer assertion below would fail.
		$this->queue_inspection( array( 'verdict' => 'NEUTRAL' ) );
		$out = Index::sweep( new Client(), 'tok', 'p', $targets );

		$this->assertSame( 'Permission denied', $out['error'] );
		$this->assertCount( 2, $out['rows'], 'both pages keep their last good answers' );
		$this->assertSame( 'PASS', $out['rows'][0]['verdict'] );
		$this->assertSame( 'PASS', $out['rows'][1]['verdict'], 'a dead token stops the sweep — no per-URL retries' );
	}

	public function test_sweep_records_a_single_refused_url_and_keeps_going() {
		$this->queue_error( 400, 'URL is not in property' );
		$this->queue_inspection( $this->pass_row() );

		$out = Index::sweep( new Client(), 'tok', 'p', $this->targets( 'https://elsewhere.test/x/', 'https://example.test/a/' ) );
		$this->assertSame( '', $out['error'], 'one refused URL is not a sweep failure' );
		$this->assertSame( 'URL is not in property', $out['rows'][0]['error'] );
		$this->assertSame( 'PASS', $out['rows'][1]['verdict'], 'the sweep went on past it' );
	}

	/* -- chunking: the 502 lesson ------------------------------------------- */
	// A full watchlist in one request held an FPM worker past the gateway
	// timeout. These pin the budgeted-chunk contract: every call makes at
	// least one inspection, persists the rest as a queue, and the next call
	// continues that queue — never re-inspecting, never re-ordering.

	public function test_a_zero_budget_chunk_inspects_exactly_one_and_keeps_the_rest_queued() {
		$this->queue_inspection( $this->pass_row() );
		$targets = $this->targets( 'https://example.test/a/', 'https://example.test/b/', 'https://example.test/c/' );

		$out = Index::sweep( new Client(), 'tok', 'p', $targets, 0 );
		$this->assertCount( 2, $out['queue'], 'two URLs wait for the next chunk' );
		$this->assertCount( 1, $out['rows'], 'the inspected URL is already visible' );
		$this->assertSame( 'PASS', $out['rows'][0]['verdict'] );
		$this->assertEmpty( $GLOBALS['_af_http_queue'], 'exactly one call went out' );
	}

	public function test_the_next_chunk_continues_the_queue_without_reinspecting() {
		$this->queue_inspection( $this->pass_row() );
		$targets = $this->targets( 'https://example.test/a/', 'https://example.test/b/' );
		Index::sweep( new Client(), 'tok', 'p', $targets, 0 );

		// Chunk two: one queued answer for /b/ — if /a/ were re-inspected it
		// would swallow this and /b/ would surface empty.
		$this->queue_inspection( array( 'verdict' => 'NEUTRAL', 'coverageState' => 'Crawled - currently not indexed' ) );
		$out = Index::sweep( new Client(), 'tok', 'p', $targets, 0 );

		$this->assertSame( array(), $out['queue'], 'the run is finished' );
		$this->assertCount( 2, $out['rows'] );
		$this->assertSame( 'PASS', $out['rows'][0]['verdict'], '/a/ kept chunk one\'s answer' );
		$this->assertSame( 'NEUTRAL', $out['rows'][1]['verdict'], '/b/ got chunk two\'s' );
	}

	public function test_a_run_in_flight_finishes_its_own_watchlist() {
		$this->queue_inspection( $this->pass_row() );
		Index::sweep( new Client(), 'tok', 'p', $this->targets( 'https://example.test/a/', 'https://example.test/b/' ), 0 );

		// Between chunks the watchlist shifts — the queued /b/ must still be
		// what gets inspected, not the newcomer.
		$this->queue_inspection( array( 'verdict' => 'NEUTRAL' ) );
		$out = Index::sweep( new Client(), 'tok', 'p', $this->targets( 'https://example.test/new-comer/' ), 0 );

		$this->assertSame( array(), $out['queue'] );
		$urls = array_column( $out['rows'], 'url' );
		$this->assertContains( 'https://example.test/b/', $urls, 'the in-flight run kept its own list' );
		$this->assertNotContains( 'https://example.test/new-comer/', $urls, 'the newcomer waits for the next run' );
	}

	/* -- the whole-site rotation -------------------------------------------- */

	private function seed_posts( array $ids ): void {
		$GLOBALS['_af_get_posts'] = $ids;
		foreach ( $ids as $id ) {
			$GLOBALS['_af_posts'][ $id ] = (object) array( 'ID' => $id, 'post_title' => "Post $id" );
		}
	}

	public function test_rotation_walks_from_the_cursor_and_wraps() {
		$this->seed_posts( array( 1, 2, 3, 4, 5 ) );
		$GLOBALS['_af_options'][ Index::OPTION ] = array( 'rot_cursor' => 3 );

		$out = Index::rotation_targets( array(), new \Agentimus\Settings() );
		$this->assertSame(
			array( 'https://example.com/?p=4', 'https://example.com/?p=5', 'https://example.com/?p=1', 'https://example.com/?p=2', 'https://example.com/?p=3' ),
			array_column( $out, 'url' ),
			'after the cursor first, then wrap from the start'
		);
		$this->assertSame( array( 'site' ), array_unique( array_column( $out, 'reason' ) ) );
		$this->assertSame( 6, Index::stored()['site_total'], 'published URLs plus the homepage' );
	}

	public function test_rotation_skips_what_the_watchlist_already_covers_today() {
		$this->seed_posts( array( 1, 2 ) );
		$watch = array( array( 'url' => 'https://example.com/?p=1', 'post_id' => 1, 'reason' => 'new' ) );
		$out   = Index::rotation_targets( $watch, new \Agentimus\Settings() );
		$this->assertSame( array( 'https://example.com/?p=2' ), array_column( $out, 'url' ) );
	}

	public function test_sweep_routes_site_answers_into_coverage_not_rows() {
		$this->queue_inspection( $this->pass_row() );                                  // the watch row
		$this->queue_inspection( array( 'verdict' => 'PASS', 'googleCanonical' => 'https://example.test/s1/' ) ); // healthy site page
		$this->queue_inspection( array( 'verdict' => 'NEUTRAL', 'coverageState' => 'Crawled - currently not indexed' ) ); // a site problem

		$targets = array(
			array( 'url' => 'https://example.test/a/', 'post_id' => 1, 'reason' => 'busy' ),
			array( 'url' => 'https://example.test/s1/', 'post_id' => 8, 'reason' => 'site' ),
			array( 'url' => 'https://example.test/s2/', 'post_id' => 9, 'reason' => 'site' ),
		);
		$out = Index::sweep( new Client(), 'tok', 'p', $targets );

		$this->assertCount( 1, $out['rows'], 'only the watchlist row is a card row' );
		$this->assertCount( 2, $out['cov'], 'both site answers folded into coverage' );
		$this->assertSame( 9, $out['rot_cursor'], 'the cursor advanced to the last inspected site page' );

		$healthy = $out['cov']['https://example.test/s1'];
		$this->assertArrayNotHasKey( 'coverage_state', $healthy, 'a healthy page shrinks to a count, not a story' );

		$google = $this->connect();
		$view   = Index::view( $google );
		$this->assertSame( 3, $view['site']['checked'], 'watchlist pages count toward the site picture too' );
		$this->assertSame( 2, $view['site']['onGoogle'] );
		$this->assertCount( 1, $view['site']['problems'], 'only the problem surfaces as a row' );
		$this->assertSame( 'https://example.test/s2/', $view['site']['problems'][0]['url'] );
	}

	/* -- the view ----------------------------------------------------------- */

	public function test_view_reports_a_run_in_flight() {
		$this->queue_inspection( $this->pass_row() );
		$google = $this->connect();
		Index::sweep( new Client(), 'tok', 'p', $this->targets( 'https://example.test/a/', 'https://example.test/b/' ), 0 );
		$this->assertSame( 1, Index::view( $google )['pending'], 'one page still waits — the card can say so' );
	}

	public function test_view_disconnected_still_carries_every_key() {
		$view = Index::view( new Settings() );
		$this->assertFalse( $view['connected'] );
		foreach ( array( 'property', 'checkedAt', 'lastError', 'quotaHit', 'pending', 'watched', 'counts', 'rows' ) as $key ) {
			$this->assertArrayHasKey( $key, $view, "disconnected view must still carry `$key` — absent keys break the ability schema" );
		}
		$this->assertSame( array(), $view['rows'] );
	}

	public function test_view_maps_rows_and_counts() {
		$google = $this->connect( array(
			'rows'       => array(
				array(
					'url'              => 'https://example.test/',
					'post_id'          => 0,
					'reason'           => 'home',
					'verdict'          => 'PASS',
					'coverage_state'   => 'Submitted and indexed',
					'robots_state'     => 'ALLOWED',
					'indexing_state'   => 'INDEXING_ALLOWED',
					'fetch_state'      => 'SUCCESSFUL',
					'last_crawl'       => 1753840000,
					'google_canonical' => 'https://example.test/',
					'error'            => '',
					'inspected_at'     => 1753900000,
				),
				array(
					// Indexed under a DIFFERENT address — the silent killer the
					// canonical flag exists for.
					'url'              => 'https://example.test/dup/',
					'post_id'          => 0,
					'reason'           => 'busy',
					'verdict'          => 'PASS',
					'coverage_state'   => 'Indexed, not submitted in sitemap',
					'robots_state'     => 'ALLOWED',
					'indexing_state'   => 'INDEXING_ALLOWED',
					'fetch_state'      => 'SUCCESSFUL',
					'last_crawl'       => 0,
					'google_canonical' => 'https://example.test/original/',
					'error'            => '',
					'inspected_at'     => 1753900000,
				),
				array(
					'url'              => 'https://example.test/hidden/',
					'post_id'          => 0,
					'reason'           => 'new',
					'verdict'          => 'NEUTRAL',
					'coverage_state'   => 'Excluded by robots.txt',
					'robots_state'     => 'DISALLOWED',
					'indexing_state'   => 'BLOCKED_BY_META_TAG',
					'fetch_state'      => 'BLOCKED_ROBOTS_TXT',
					'last_crawl'       => 0,
					'google_canonical' => '',
					'error'            => '',
					'inspected_at'     => 1753900000,
				),
			),
			'checked_at' => 1753900000,
			'error'      => '',
			'quota'      => false,
		) );

		$view = Index::view( $google );
		$this->assertTrue( $view['connected'] );
		$this->assertSame( 3, $view['counts']['checked'] );
		$this->assertSame( 2, $view['counts']['onGoogle'] );
		$this->assertSame( 1, $view['counts']['notOnGoogle'] );
		$this->assertSame( 1, $view['counts']['canonicalDiffers'] );

		$this->assertSame( 'pass', $view['rows'][0]['verdict'] );
		$this->assertSame( 'Homepage', $view['rows'][0]['title'] );
		$this->assertFalse( $view['rows'][0]['canonicalDiffers'], 'matching canonical must not flag' );

		$this->assertTrue( $view['rows'][1]['canonicalDiffers'] );
		$this->assertSame( 'https://example.test/original/', $view['rows'][1]['googleCanonical'] );

		$this->assertTrue( $view['rows'][2]['robotsBlocked'] );
		$this->assertTrue( $view['rows'][2]['noindex'] );
		$this->assertSame( '/hidden/', $view['rows'][2]['title'], 'no post, no home — the path stands in for a title' );
	}

	public function test_view_decodes_title_entities() {
		// get_the_title() returns HTML entities and the card renders TEXT —
		// undecoded, "WP-CLI &#8211; …" appears verbatim (shipped exactly so).
		$GLOBALS['_af_posts'][7] = (object) array( 'ID' => 7, 'post_title' => 'WP-CLI &#8211; The Command Line Tool' );
		$google                  = $this->connect( array(
			'rows'       => array(
				array(
					'url'          => 'https://example.test/wp-cli/',
					'post_id'      => 7,
					'reason'       => 'busy',
					'verdict'      => 'PASS',
					'inspected_at' => 1753900000,
				),
			),
			'checked_at' => 1753900000,
			'error'      => '',
			'quota'      => false,
		) );
		$this->assertSame( 'WP-CLI – The Command Line Tool', Index::view( $google )['rows'][0]['title'] );
	}
}
