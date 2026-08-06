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

	/** sitemaps.list → normalized rows; the registration bookkeeping only
	 * Google has (a moved live file keeps its stale lastRead forever). */
	public function test_sitemaps_list_normalizes_registrations() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'sitemap' => array(
				array(
					'path'           => 'https://example.test/wp-sitemap.xml',
					'lastDownloaded' => '2026-07-19T00:00:00Z',
					'isPending'      => false,
					'errors'         => '0',
					'warnings'       => '2',
					'contents'       => array(
						array( 'type' => 'web', 'submitted' => '150', 'indexed' => '0' ),
						array( 'type' => 'web', 'submitted' => '24', 'indexed' => '0' ),
					),
				),
				array( 'path' => 'https://example.test/new.xml', 'isPending' => true ),
			) ) ),
			'headers'  => array(),
		);

		$out = ( new Client() )->sitemaps( 'tok', 'sc-domain:example.test' );
		$this->assertCount( 2, $out['sitemaps'] );
		$this->assertSame( 'https://example.test/wp-sitemap.xml', $out['sitemaps'][0]['path'] );
		$this->assertSame( (int) strtotime( '2026-07-19T00:00:00Z' ), $out['sitemaps'][0]['last_downloaded'] );
		$this->assertSame( 2, $out['sitemaps'][0]['warnings'] );
		$this->assertSame( 174, $out['sitemaps'][0]['submitted'] );
		$this->assertTrue( $out['sitemaps'][1]['pending'] );
		$this->assertSame( 0, $out['sitemaps'][1]['last_downloaded'] );
	}

	/** store_sitemaps: success stores + stamps; an error keeps the last good
	 * snapshot AND its stamp (a blip must not fake "never looked"). */
	public function test_store_sitemaps_keeps_last_good_on_error() {
		Index::store_sitemaps( array( 'sitemaps' => array( array( 'path' => 'a' ) ) ) );
		$first = Index::stored();
		$this->assertSame( 'a', $first['sitemaps'][0]['path'] );
		$this->assertGreaterThan( 0, $first['sitemaps_at'] );

		Index::store_sitemaps( array( 'error' => 'transport' ) );
		$after = Index::stored();
		$this->assertSame( 'a', $after['sitemaps'][0]['path'] );
		$this->assertSame( $first['sitemaps_at'], $after['sitemaps_at'] );
	}

	/** The snapshot survives a sweep write — sweep() rebuilds the whole option. */
	public function test_sweep_carries_the_sitemaps_snapshot() {
		Index::store_sitemaps( array( 'sitemaps' => array( array( 'path' => 'kept' ) ) ) );
		$this->queue_inspection( $this->pass_row() );
		Index::sweep( new Client(), 'tok', 'p', array( array( 'url' => 'https://example.test/', 'post_id' => 0, 'reason' => 'home' ) ) );
		$stored = Index::stored();
		$this->assertSame( 'kept', $stored['sitemaps'][0]['path'] );
		$this->assertGreaterThan( 0, $stored['sitemaps_at'] );
	}

	/** The view separates "never looked" (checkedAt 0) from "looked, none
	 * registered" — the second is a finding, the first is silence. */
	public function test_view_reports_sitemap_registrations() {
		$settings = $this->connect();
		$view     = Index::view( $settings );
		$this->assertSame( 0, $view['sitemaps']['checkedAt'] );
		$this->assertSame( array(), $view['sitemaps']['registered'] );

		Index::store_sitemaps( array( 'sitemaps' => array( array(
			'path'            => 'https://example.test/old.xml',
			'pending'         => false,
			'last_downloaded' => 123,
			'errors'          => 1,
			'warnings'        => 0,
			'submitted'       => 9,
		) ) ) );
		$view = Index::view( $settings );
		$this->assertGreaterThan( 0, $view['sitemaps']['checkedAt'] );
		$reg = $view['sitemaps']['registered'][0];
		$this->assertSame( 'https://example.test/old.xml', $reg['path'] );
		$this->assertSame( 123, $reg['lastRead'] );
		$this->assertSame( 1, $reg['errors'] );
		$this->assertSame( 9, $reg['submitted'] );
	}

	/** state_key: the bucket totals and the client's groups must share one
	 * derivation — this pins the precedence (error > canonical > states). */
	public function test_state_key_buckets_by_precedence() {
		$this->assertSame( 'error', Index::state_key( array( 'error' => 'x', 'google_canonical' => 'https://a.test/b' ) ) );
		$this->assertSame( 'canonical', Index::state_key( array( 'url' => 'https://a.test/x', 'google_canonical' => 'https://a.test/y' ) ) );
		$this->assertSame( 'unknown', Index::state_key( array( 'coverage_state' => 'URL is unknown to Google' ) ) );
		$this->assertSame( 'discovered', Index::state_key( array( 'coverage_state' => 'Discovered - currently not indexed' ) ) );
		$this->assertSame( 'crawled', Index::state_key( array( 'coverage_state' => 'Crawled - currently not indexed' ) ) );
		$this->assertSame( 'blocked', Index::state_key( array( 'robots_state' => 'DISALLOWED' ) ) );
		$this->assertSame( 'other', Index::state_key( array( 'coverage_state' => 'Weird new state' ) ) );
	}

	/** problemStates counts BEFORE the row cap — counts are truth, rows are
	 * the slice; a pill reading 4 when the state holds 40 is the silent lie. */
	public function test_problem_state_totals_survive_the_row_cap() {
		$cov = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$cov[ "https://example.test/p$i" ] = array(
				'url'            => "https://example.test/p$i",
				'post_id'        => $i,
				'verdict'        => 'NEUTRAL',
				'coverage_state' => 'Crawled - currently not indexed',
				'inspected_at'   => 100 + $i,
			);
		}
		$settings = $this->connect( array( 'cov' => $cov, 'site_total' => 80 ) );
		$view     = Index::view( $settings );
		$this->assertSame( 60, $view['site']['problemsTotal'] );
		$this->assertCount( 50, $view['site']['problems'] );
		$this->assertSame( 60, $view['site']['problemStates']['crawled'] );
	}

	public function test_the_row_cap_keeps_a_share_from_every_bucket() {
		// 96 discovered rows AHEAD of 4 blocked ones: a head-slice would ship
		// 50 discovered and zero blocked — and a group with no rows never
		// renders, no matter what its count says. The cap must seat everyone.
		$cov = array();
		for ( $i = 1; $i <= 96; $i++ ) {
			$cov[ "https://example.test/d$i" ] = array(
				'url'            => "https://example.test/d$i",
				'post_id'        => $i,
				'verdict'        => 'NEUTRAL',
				'coverage_state' => 'Discovered - currently not indexed',
				'inspected_at'   => 100 + $i,
			);
		}
		for ( $i = 1; $i <= 4; $i++ ) {
			$cov[ "https://example.test/b$i" ] = array(
				'url'            => "https://example.test/b$i",
				'post_id'        => 900 + $i,
				'verdict'        => 'NEUTRAL',
				'coverage_state' => 'Blocked',
				'robots_state'   => 'DISALLOWED',
				'inspected_at'   => 200 + $i,
			);
		}
		$view    = Index::view( $this->connect( array( 'cov' => $cov, 'site_total' => 120 ) ) );
		$shipped = array_count_values( array_column( $view['site']['problems'], 'stateKey' ) );

		$this->assertCount( 50, $view['site']['problems'] );
		$this->assertSame( 4, $shipped['blocked'], 'the small bucket ships whole — no invisible category of trouble' );
		$this->assertSame( 46, $shipped['discovered'], 'the big bucket takes the leftover seats' );
		$this->assertSame( 96, $view['site']['problemStates']['discovered'], 'counts stay the uncapped truth' );
		$this->assertSame( 4, $view['site']['problemStates']['blocked'] );
	}

	public function test_problems_page_filters_orders_and_pages() {
		// A watched problem (leads the list), 120 rotation 'crawled' problems,
		// and one 'discovered' page that must never leak into this listing.
		$rows = array(
			array( 'url' => 'https://example.test/w/', 'post_id' => 1, 'reason' => 'busy', 'verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed', 'inspected_at' => 50, 'error' => '' ),
		);
		$cov  = array(
			'https://example.test/d1' => array( 'url' => 'https://example.test/d1', 'post_id' => 500, 'verdict' => 'NEUTRAL', 'coverage_state' => 'Discovered - currently not indexed', 'inspected_at' => 60 ),
		);
		for ( $i = 1; $i <= 120; $i++ ) {
			$cov[ "https://example.test/c$i" ] = array( 'url' => "https://example.test/c$i", 'post_id' => $i, 'verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed', 'inspected_at' => 100 + $i );
		}
		$GLOBALS['_af_options'][ Index::OPTION ] = array( 'rows' => $rows, 'cov' => $cov );

		$one = Index::problems_page( 'crawled', 1 );
		$this->assertSame( 121, $one['total'] );
		$this->assertSame( 3, $one['pages'] );
		$this->assertSame( 50, $one['perPage'] );
		$this->assertCount( 50, $one['rows'] );
		$this->assertSame( 'https://example.test/w/', $one['rows'][0]['url'], 'the watched problem leads the listing' );
		foreach ( $one['rows'] as $row ) {
			$this->assertSame( 'crawled', $row['stateKey'], 'one state per listing — nothing leaks in' );
		}

		$three = Index::problems_page( 'crawled', 3 );
		$this->assertCount( 21, $three['rows'], '121 rows page as 50 + 50 + 21' );
		$this->assertSame( 'https://example.test/c120', end( $three['rows'] )['url'], 'stable order across pages' );

		$beyond = Index::problems_page( 'crawled', 9 );
		$this->assertSame( array(), $beyond['rows'], 'past the end is empty, not an error' );
		$this->assertSame( 121, $beyond['total'] );
	}

	public function test_problems_page_skips_the_unasked_and_answers_unknown_states_empty() {
		$GLOBALS['_af_options'][ Index::OPTION ] = array(
			'rows' => array(
				array( 'url' => 'https://example.test/u/', 'post_id' => 2, 'reason' => 'new', 'verdict' => '', 'inspected_at' => 0, 'error' => '' ),
			),
		);
		$this->assertSame( 0, Index::problems_page( 'other', 1 )['total'], 'a never-asked row is not a problem' );
		$this->assertSame( 0, Index::problems_page( 'nonsense', 1 )['total'], 'an unknown state matches nothing' );
	}

	/** lookup answers from stored data: watch rows, the coverage map's healthy
	 * entries, honest "unchecked", and "foreign" for another site's URL. */
	public function test_lookup_reads_rows_cov_and_says_the_rest_honestly() {
		$this->connect( array(
			'rows' => array( array(
				'url'            => 'https://example.test/watched/',
				'post_id'        => 5,
				'reason'         => 'busy',
				'verdict'        => 'PASS',
				'coverage_state' => 'Submitted and indexed',
				'inspected_at'   => 111,
			) ),
			'cov'  => array(
				'https://example.test/healthy' => array(
					'url'          => 'https://example.test/healthy/',
					'post_id'      => 7,
					'verdict'      => 'PASS',
					'inspected_at' => 222,
				),
			),
		) );

		$out = Index::lookup( 'https://example.test/watched' );
		$this->assertSame( 'found', $out['status'] );
		$this->assertSame( 'pass', $out['row']['verdict'] );

		$out = Index::lookup( '/healthy/' ); // path form resolves against home.
		$this->assertSame( 'found', $out['status'] );
		$this->assertSame( 222, $out['row']['inspectedAt'] );

		$out = Index::lookup( 'https://example.test/never-checked/' );
		$this->assertSame( 'unchecked', $out['status'] );
		$this->assertNull( $out['row'] );

		$out = Index::lookup( 'https://elsewhere.test/watched/' );
		$this->assertSame( 'foreign', $out['status'] );
	}

	/** referringUrls → a count; absent → 0 (the "nothing points here" signal). */
	public function test_inspect_url_counts_referring_pages() {
		$this->queue_inspection( array_merge( $this->pass_row(), array(
			'referringUrls' => array( 'https://example.test/one/', 'https://example.test/two/' ),
		) ) );
		$out = ( new Client() )->inspect_url( 'tok', 'p', 'https://example.test/a/' );
		$this->assertSame( 2, $out['result']['referrers'] );

		$this->queue_inspection( array( 'verdict' => 'NEUTRAL' ) );
		$out = ( new Client() )->inspect_url( 'tok', 'p', 'https://example.test/b/' );
		$this->assertSame( 0, $out['result']['referrers'] );
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

		// The stop is a PAUSE, not an abort: nothing was inspected, so nothing
		// may leave the queue — the run resumes here instead of restarting from
		// scratch (and re-spending the watchlist's quota) on the next call.
		$this->assertCount( 2, $out['queue'], 'the un-inspected URLs survive the failure' );
		$this->assertSame(
			'https://example.test/a/',
			end( $out['queue'] )['url'],
			'the URL whose call failed rejoined at the tail, so it cannot block the line'
		);
	}

	public function test_a_transport_blip_pauses_the_run_silently_and_the_next_call_resumes_it() {
		// Chunk one: /a/'s call brings back no answer at all (a timeout, a
		// reset) — the run pauses with NOTHING on record: one blip is not
		// worth a banner, and the panel's loop reads no error and carries on.
		$GLOBALS['_af_http_queue'][] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$paused = Index::sweep( new Client(), 'tok', 'p', $this->targets( 'https://example.test/a/', 'https://example.test/b/' ) );
		$this->assertSame( '', $paused['error'], 'a single blip stays off the record' );
		$this->assertCount( 2, $paused['queue'] );
		$this->assertSame(
			'https://example.test/a/',
			end( $paused['queue'] )['url'],
			'the blipped URL rejoined at the tail'
		);

		// The next call resumes the SAME run — its own persisted queue, not the
		// fresh targets it was handed — and the blip has fully healed.
		$this->queue_inspection( $this->pass_row() );
		$this->queue_inspection( $this->pass_row() );
		$out = Index::sweep( new Client(), 'tok', 'p', $this->targets( 'https://example.test/intruder/' ) );

		$this->assertSame( '', $out['error'], 'the recovered run does not keep wearing the old failure' );
		$this->assertSame( array(), $out['queue'], 'the run finished' );
		$this->assertCount( 2, $out['rows'] );
		$this->assertSame(
			array( 'https://example.test/a/', 'https://example.test/b/' ),
			array_column( $out['rows'], 'url' ),
			'the paused run finished its own list — the new target never cut in'
		);
		foreach ( $out['rows'] as $row ) {
			$this->assertSame( 'PASS', $row['verdict'] );
		}
	}

	public function test_blip_limit_in_a_row_pauses_the_run_loudly() {
		$targets = $this->targets( 'https://example.test/a/', 'https://example.test/b/' );

		// Two silent chunks: a no-answer failure and Google's own 5xx both
		// count as blips — either way the next call may well succeed.
		$GLOBALS['_af_http_queue'][] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertSame( '', Index::sweep( new Client(), 'tok', 'p', $targets )['error'] );
		$this->queue_error( 502, 'Bad gateway' );
		$this->assertSame( '', Index::sweep( new Client(), 'tok', 'p', $targets )['error'] );

		$GLOBALS['_af_http_queue'][] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$out = Index::sweep( new Client(), 'tok', 'p', $targets );
		$this->assertSame( 'cURL error 28: Operation timed out', $out['error'], 'the third in a row is a failure, not a blip' );
		$this->assertCount( 2, $out['queue'], 'even the loud pause keeps the queue' );
	}

	public function test_an_answer_between_blips_starts_the_count_over() {
		$targets = $this->targets( 'https://example.test/a/', 'https://example.test/b/' );

		$GLOBALS['_af_http_queue'][] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		Index::sweep( new Client(), 'tok', 'p', $targets ); // blip one, /a/ to the tail

		// /b/ answers — the line is alive — then /a/ blips again. Without the
		// reset that second blip would be number two and the NEXT chunk's
		// would pause loudly; with it, the next one is still only number two.
		$this->queue_inspection( $this->pass_row() );
		$GLOBALS['_af_http_queue'][] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertSame( '', Index::sweep( new Client(), 'tok', 'p', $targets )['error'] );

		$GLOBALS['_af_http_queue'][] = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertSame(
			'',
			Index::sweep( new Client(), 'tok', 'p', $targets )['error'],
			'still only two in a row since the last answer'
		);
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

	/* -- promoted problems + healing ---------------------------------------- */
	// The third tier: a problem page is exactly the page the owner is waiting
	// on, so it joins the daily check until it heals — and a healed page
	// announces itself instead of vanishing.

	public function test_promoted_targets_are_problems_only_stalest_first() {
		$GLOBALS['_af_options'][ Index::OPTION ] = array(
			'cov' => array(
				'https://example.test/p1' => array( 'url' => 'https://example.test/p1/', 'post_id' => 11, 'verdict' => 'NEUTRAL', 'inspected_at' => 300 ),
				'https://example.test/p2' => array( 'url' => 'https://example.test/p2/', 'post_id' => 12, 'verdict' => 'NEUTRAL', 'inspected_at' => 100 ),
				'https://example.test/ok' => array( 'url' => 'https://example.test/ok/', 'post_id' => 13, 'verdict' => 'PASS', 'inspected_at' => 50 ),
				'https://example.test/w'  => array( 'url' => 'https://example.test/w/', 'post_id' => 14, 'verdict' => 'NEUTRAL', 'inspected_at' => 10 ),
			),
		);
		$out = Index::promoted_targets( $this->targets( 'https://example.test/w/' ) );
		$this->assertSame(
			array( 'https://example.test/p2/', 'https://example.test/p1/' ),
			array_column( $out, 'url' ),
			'problems only, the already-watched page excluded, the stalest answer first'
		);
		$this->assertSame( array( 'problem', 'problem' ), array_column( $out, 'reason' ) );
	}

	public function test_promotion_is_capped_at_the_stated_daily_allowance() {
		$cov = array();
		for ( $i = 1; $i <= Index::PROMOTED_DAILY + 5; $i++ ) {
			$cov[ "https://example.test/p$i" ] = array( 'url' => "https://example.test/p$i/", 'post_id' => $i, 'verdict' => 'NEUTRAL', 'inspected_at' => $i );
		}
		$GLOBALS['_af_options'][ Index::OPTION ] = array( 'cov' => $cov );
		$this->assertCount(
			Index::PROMOTED_DAILY,
			Index::promoted_targets( array() ),
			'the cap holds — a sick site cannot spend the whole budget on its wounds'
		);
	}

	public function test_a_promoted_answer_updates_coverage_and_leaves_the_cursor() {
		$GLOBALS['_af_options'][ Index::OPTION ] = array(
			'cov'        => array(
				'https://example.test/p1' => array( 'url' => 'https://example.test/p1/', 'post_id' => 7, 'verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed', 'inspected_at' => 100 ),
			),
			'rot_cursor' => 42,
		);
		$this->queue_inspection( array( 'verdict' => 'PASS' ) );
		$out = Index::sweep( new Client(), 'tok', 'p', array( array( 'url' => 'https://example.test/p1/', 'post_id' => 7, 'reason' => 'problem' ) ) );

		$this->assertSame( array(), $out['rows'], 'a promoted answer is coverage, not a card row' );
		$this->assertSame( 42, $out['rot_cursor'], 'an out-of-band re-check must not move the rotation cursor' );
		$this->assertSame( 'PASS', $out['cov']['https://example.test/p1']['verdict'] );
	}

	public function test_a_healed_page_takes_the_stamp_and_announces_in_the_view() {
		$GLOBALS['_af_options'][ Index::OPTION ] = array(
			'cov' => array(
				'https://example.test/p1' => array( 'url' => 'https://example.test/p1/', 'post_id' => 7, 'verdict' => 'NEUTRAL', 'coverage_state' => 'Discovered - currently not indexed', 'inspected_at' => 100 ),
			),
		);
		$this->queue_inspection( array( 'verdict' => 'PASS' ) );
		Index::sweep( new Client(), 'tok', 'p', array( array( 'url' => 'https://example.test/p1/', 'post_id' => 7, 'reason' => 'problem' ) ) );

		$entry = Index::stored()['cov']['https://example.test/p1'];
		$this->assertGreaterThan( 0, $entry['healed_at'], 'the healing moment is stamped' );
		$this->assertSame( 'discovered', $entry['healed_from'], 'and remembers what it healed FROM' );

		$view = Index::view( $this->connect() );
		$this->assertSame( array(), $view['site']['problems'], 'healed means no longer a problem' );
		$this->assertCount( 1, $view['site']['healed'] );
		$this->assertSame( 'https://example.test/p1/', $view['site']['healed'][0]['url'] );
		$this->assertSame( 'discovered', $view['site']['healed'][0]['healedFrom'] );
		$this->assertSame( 1, $view['site']['healedTotal'] );
	}

	public function test_the_healed_stamp_carries_inside_its_window_then_expires() {
		$stamp = time() - 100;
		$GLOBALS['_af_options'][ Index::OPTION ] = array(
			'cov' => array(
				'https://example.test/h' => array( 'url' => 'https://example.test/h/', 'post_id' => 3, 'verdict' => 'PASS', 'inspected_at' => 100, 'healed_at' => $stamp, 'healed_from' => 'crawled' ),
			),
		);
		$this->queue_inspection( array( 'verdict' => 'PASS' ) );
		Index::sweep( new Client(), 'tok', 'p', array( array( 'url' => 'https://example.test/h/', 'post_id' => 3, 'reason' => 'site' ) ) );
		$entry = Index::stored()['cov']['https://example.test/h'];
		$this->assertSame( $stamp, $entry['healed_at'], 'the stamp carries, never renews — an announcement that resets daily never ends' );
		$this->assertSame( 'crawled', $entry['healed_from'] );

		$GLOBALS['_af_options'][ Index::OPTION ] = array(
			'cov' => array(
				'https://example.test/h' => array( 'url' => 'https://example.test/h/', 'post_id' => 3, 'verdict' => 'PASS', 'inspected_at' => 100, 'healed_at' => time() - Index::HEALED_KEEP - 10, 'healed_from' => 'crawled' ),
			),
		);
		$this->queue_inspection( array( 'verdict' => 'PASS' ) );
		Index::sweep( new Client(), 'tok', 'p', array( array( 'url' => 'https://example.test/h/', 'post_id' => 3, 'reason' => 'site' ) ) );
		$this->assertArrayNotHasKey(
			'healed_at',
			Index::stored()['cov']['https://example.test/h'],
			'past its window the stamp is dropped and the page goes quiet like the rest'
		);
	}

	public function test_watch_problems_stand_with_every_other_problem_in_the_view() {
		$view = Index::view( $this->connect( array(
			'rows' => array(
				array( 'url' => 'https://example.test/red/', 'post_id' => 1, 'reason' => 'busy', 'verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed', 'inspected_at' => 100, 'error' => '' ),
				array( 'url' => 'https://example.test/green/', 'post_id' => 2, 'reason' => 'new', 'verdict' => 'PASS', 'inspected_at' => 100, 'error' => '' ),
				array( 'url' => 'https://example.test/unasked/', 'post_id' => 3, 'reason' => 'new', 'verdict' => '', 'inspected_at' => 0, 'error' => '' ),
			),
			'cov'  => array(
				'https://example.test/s' => array( 'url' => 'https://example.test/s/', 'post_id' => 9, 'verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed', 'inspected_at' => 90 ),
			),
		) ) );

		$urls = array_column( $view['site']['problems'], 'url' );
		$this->assertSame(
			array( 'https://example.test/red/', 'https://example.test/s/' ),
			$urls,
			'a watched problem is a problem — and it leads the list; healthy and never-asked rows stay out'
		);
		$this->assertSame( 2, $view['site']['problemsTotal'] );
		$this->assertSame( 2, $view['site']['problemStates']['crawled'], 'bucket totals count the watched problem too' );
	}

	public function test_a_watch_row_heals_and_announces() {
		$GLOBALS['_af_options'][ Index::OPTION ] = array(
			'rows' => array(
				array( 'url' => 'https://example.test/a/', 'post_id' => 1, 'reason' => 'busy', 'verdict' => 'NEUTRAL', 'coverage_state' => 'Discovered - currently not indexed', 'inspected_at' => 100, 'error' => '' ),
			),
		);
		$this->queue_inspection( array( 'verdict' => 'PASS' ) );
		$out = Index::sweep( new Client(), 'tok', 'p', $this->targets( 'https://example.test/a/' ) );
		$this->assertSame( 'PASS', $out['rows'][0]['verdict'] );
		$this->assertGreaterThan( 0, $out['rows'][0]['healed_at'], 'watch rows heal too — against their own previous answer' );

		$view = Index::view( $this->connect() );
		$this->assertCount( 1, $view['site']['healed'] );
		$this->assertSame( 'discovered', $view['site']['healed'][0]['healedFrom'] );
		$this->assertSame( array(), $view['site']['problems'] );
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

	/* -- The console-opened note ---------------------------------------------- */

	public function test_marking_a_row_opened_records_the_owners_click_and_nothing_else() {
		update_option( Index::OPTION, array(
			'rows' => array(
				array( 'url' => 'https://example.test/alpha/', 'post_id' => 7, 'verdict' => 'FAIL', 'inspected_at' => 1753900000 ),
			),
			'cov'  => array(),
		) );

		$at = Index::mark_opened( 'https://example.test/alpha/' );
		$this->assertGreaterThan( 0, $at, 'A URL this card holds records the click.' );

		$stored = Index::stored();
		$this->assertSame( $at, $stored['opened']['https://example.test/alpha'], 'Keyed by the normalised URL.' );

		// A URL nobody here holds is refused: otherwise a hand-crafted request
		// could grow the option without bound and pin a note on a row that will
		// never render.
		$this->assertSame( 0, Index::mark_opened( 'https://example.test/never-heard-of-it/' ) );
		$this->assertSame( 0, Index::mark_opened( '' ) );
		$this->assertCount( 1, Index::stored()['opened'] );
	}

	public function test_the_opened_note_survives_a_sweep_because_it_is_not_googles_answer() {
		update_option( Index::OPTION, array(
			'rows'   => array( array( 'url' => 'https://example.test/alpha/', 'post_id' => 7, 'verdict' => 'FAIL' ) ),
			'cov'    => array(),
			'opened' => array( 'https://example.test/alpha' => 1753900000 ),
		) );

		// stored() is what every sweep reads its carry-forward state from; the
		// note has to be in there or the next sweep silently erases the record.
		$this->assertSame( 1753900000, Index::stored()['opened']['https://example.test/alpha'] );
	}

	/* -- Check it now: one live inspection on an explicit click ---------------- */

	public function test_a_live_check_stores_its_answer_where_that_url_already_lives() {
		update_option( Index::OPTION, array(
			'rows' => array(
				array( 'url' => 'https://example.test/watched/', 'post_id' => 3, 'reason' => 'busy', 'verdict' => 'FAIL', 'coverage_state' => 'Crawled – currently not indexed', 'inspected_at' => 100 ),
			),
			'cov'  => array(),
		) );
		$this->queue_inspection( array( 'verdict' => 'PASS', 'coverageState' => 'Submitted and indexed' ) );

		$out = Index::inspect_now( new Client(), 'tok', 'sc-domain:example.test', 'https://example.test/watched/' );

		$this->assertSame( 'checked', $out['status'] );
		$this->assertSame( 'pass', $out['row']['verdict'] );

		$stored = Index::stored();
		$this->assertCount( 1, $stored['rows'], 'A watch row is REPLACED, never duplicated into coverage.' );
		$this->assertSame( 'PASS', $stored['rows'][0]['verdict'] );
		$this->assertSame( 'busy', $stored['rows'][0]['reason'], 'An out-of-band check says nothing about why the page is watched.' );
		$this->assertNotEmpty( $stored['rows'][0]['healed_at'], 'A watch row that just turned healthy announces it, exactly as a sweep would.' );
	}

	public function test_a_live_check_on_an_unwatched_page_becomes_a_coverage_entry() {
		update_option( Index::OPTION, array( 'rows' => array(), 'cov' => array() ) );
		$this->queue_inspection( array( 'verdict' => 'PASS', 'coverageState' => 'Submitted and indexed' ) );

		Index::inspect_now( new Client(), 'tok', 'sc-domain:example.test', 'https://example.test/quiet/' );

		$cov = Index::stored()['cov'];
		$this->assertArrayHasKey( 'https://example.test/quiet', $cov );
		// Healthy pages shrink to a count the same way the rotation shrinks them,
		// or a manual check would leave a page stored unlike its neighbours.
		$this->assertSame( 'PASS', $cov['https://example.test/quiet']['verdict'] );
		$this->assertArrayNotHasKey( 'coverage_state', $cov['https://example.test/quiet'] );
		$this->assertSame( array(), Index::stored()['rows'], 'It is not promoted to a row.' );
	}

	public function test_a_foreign_url_is_refused_before_an_inspection_is_spent() {
		update_option( Index::OPTION, array( 'rows' => array(), 'cov' => array() ) );
		$GLOBALS['_af_http_queue'] = array();

		$out = Index::inspect_now( new Client(), 't', 'p', 'https://elsewhere.test/x/' );

		$this->assertSame( 'foreign', $out['status'] );
		// Nothing was dequeued: Google is never asked about a URL it could not
		// answer for, and the day's budget is not spent learning that.
		$this->assertSame( array(), $GLOBALS['_af_http_queue'] );
	}

	public function test_the_three_failure_lanes_stay_apart() {
		update_option( Index::OPTION, array( 'rows' => array(), 'cov' => array() ) );

		// Quota is the DAY's problem, not the page's — nothing is written down.
		$this->queue_error( 429, 'rate limited' );
		$this->assertSame( 'quota', Index::inspect_now( new Client(), 't', 'p', 'https://example.test/a/' )['status'] );
		$this->assertSame( array(), Index::stored()['cov'] );

		// Transport/token says nothing about the page, so no verdict is stored.
		$this->queue_error( 401, 'token expired' );
		$this->assertSame( 'error', Index::inspect_now( new Client(), 't', 'p', 'https://example.test/a/' )['status'] );
		$this->assertSame( array(), Index::stored()['cov'] );

		// A 400 IS about this URL, so it becomes this URL's answer.
		$this->queue_error( 400, 'not in property' );
		$out = Index::inspect_now( new Client(), 't', 'p', 'https://example.test/a/' );
		$this->assertSame( 'checked', $out['status'] );
		$this->assertStringContainsString( 'not in property', $out['row']['error'] );
		$this->assertArrayHasKey( 'https://example.test/a', Index::stored()['cov'] );
	}

	/* -- What a person types --------------------------------------------------- */

	public function test_a_lookup_understands_what_a_person_actually_types() {
		update_option( Index::OPTION, array(
			'rows' => array(),
			'cov'  => array( 'https://example.test/terms' => array( 'url' => 'https://example.test/terms/', 'post_id' => 7, 'verdict' => 'PASS', 'inspected_at' => 100 ) ),
		) );

		// Four spellings of one page. Refusing three of them as "not on this
		// site" was the tool being pedantic about its own input format.
		foreach ( array(
			'https://example.test/terms/',
			'/terms/',
			'terms/',
			'example.test/terms/',
		) as $typed ) {
			$this->assertSame( 'found', Index::lookup( $typed )['status'], "\"$typed\" means the same page." );
		}
	}

	public function test_a_named_foreign_host_is_still_refused() {
		update_option( Index::OPTION, array( 'rows' => array(), 'cov' => array() ) );

		// A URL that NAMES another host is refused, however it is written.
		foreach ( array( 'https://elsewhere.test/x/', '//elsewhere.test/x', '' ) as $foreign ) {
			$this->assertSame( 'foreign', Index::lookup( $foreign )['status'], "\"$foreign\" names somewhere else." );
		}

		// But a bare "elsewhere.test/x" names nothing parse_url can see — it has
		// the same shape as "terms/". It is read as a path here and answered
		// "not checked yet", which is true, rather than refused as foreign. The
		// alternative rule ("contains a dot, must be a domain") would refuse
		// "sitemap.xml" as another website, and being wrong about someone's own
		// page is the worse mistake.
		$this->assertSame( 'unchecked', Index::lookup( 'elsewhere.test/x' )['status'] );
	}
}
