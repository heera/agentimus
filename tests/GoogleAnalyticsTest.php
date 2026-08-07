<?php
/**
 * GA4 client — the AI/other split, and the source matching underneath it.
 *
 * The matching is where this can go quietly wrong: a substring test would count
 * `notchatgpt.com.example` as ChatGPT and inflate the one number the Readers
 * screen exists to compare. These lock the boundary.
 *
 * @package Agentimus
 */

use PHPUnit\Framework\TestCase;
use Agentimus\Google\Analytics;

final class GoogleAnalyticsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_af_http_queue'] = array();
	}

	/** Queue a GA4 runReport response built from source => sessions pairs. */
	private function queue_sources( array $pairs ) {
		$rows = array();
		foreach ( $pairs as $source => $sessions ) {
			$rows[] = array(
				'dimensionValues' => array( array( 'value' => (string) $source ) ),
				'metricValues'    => array( array( 'value' => (string) $sessions ) ),
			);
		}
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => $rows ) ),
		);
	}

	public function test_ai_sessions_are_separated_from_everything_else() {
		$this->queue_sources(
			array(
				'google'        => 500,
				'chatgpt.com'   => 40,
				'(direct)'      => 300,
				'perplexity.ai' => 12,
				'facebook.com'  => 20,
			)
		);
		$out = ( new Analytics() )->ai_split( 'tok', '12345', '2026-07-10', '2026-08-08' );

		$this->assertSame( 52, $out['split']['ai'] );
		$this->assertSame( 820, $out['split']['other'] );
		$this->assertSame( 872, $out['split']['total'] );
		$this->assertSame( 40, $out['split']['bySource']['chatgpt.com'] );
	}

	/**
	 * The one that would be silently wrong: a bare substring match counts a
	 * lookalike host as the real thing, and the number it inflates is exactly
	 * the one being compared against our own count.
	 */
	public function test_a_lookalike_host_is_not_an_assistant() {
		$this->assertFalse( Analytics::is_ai_source( 'notchatgpt.com.example' ) );
		$this->assertFalse( Analytics::is_ai_source( 'chatgpt.com.phish.example' ) );
		$this->assertFalse( Analytics::is_ai_source( 'myclaude.aixyz' ) );
	}

	public function test_exact_hosts_and_real_subdomains_match() {
		$this->assertTrue( Analytics::is_ai_source( 'chatgpt.com' ) );
		$this->assertTrue( Analytics::is_ai_source( 'www.perplexity.ai' ) );
		$this->assertTrue( Analytics::is_ai_source( 'gemini.google.com' ) );
		// A subdomain of a listed host.
		$this->assertTrue( Analytics::is_ai_source( 'eu.claude.ai' ) );
	}

	public function test_a_listed_path_prefix_matches_only_at_the_start() {
		$this->assertTrue( Analytics::is_ai_source( 'bing.com/chat' ) );
		$this->assertFalse( Analytics::is_ai_source( 'evil.example/bing.com/chat' ) );
	}

	public function test_the_source_list_is_filterable() {
		$seen = false;
		$f    = function ( $hosts ) use ( &$seen ) {
			$seen     = true;
			$hosts[]  = 'newassistant.example';
			return $hosts;
		};
		add_filter( 'agentimus_ga4_ai_sources', $f );

		$this->assertTrue( Analytics::is_ai_source( 'newassistant.example' ) );
		$this->assertTrue( $seen );

		remove_filter( 'agentimus_ga4_ai_sources', $f );
	}

	public function test_a_property_id_that_is_not_digits_is_refused_before_any_request() {
		$out = ( new Analytics() )->ai_split( 'tok', 'G-ABC123', '2026-07-10', '2026-08-08' );

		$this->assertArrayHasKey( 'error', $out );
		$this->assertStringContainsString( 'numeric ID', $out['error'] );
		$this->assertSame( array(), $GLOBALS['_af_http_queue'], 'nothing should have been sent' );
	}

	public function test_a_permission_failure_says_what_to_do_about_it() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 403 ),
			'body'     => '{"error":{"message":"User does not have sufficient permissions for this property."}}',
		);
		$out = ( new Analytics() )->totals( 'tok', '12345', '2026-07-10', '2026-08-08' );

		$this->assertStringContainsString( 'sufficient permissions', $out['error'] );
	}

	/**
	 * GA4 answers positionally, so the metric list and the reads have to stay in
	 * step. This pins the order: users, newUsers, sessions, views, engaged,
	 * avgSeconds. A reordering that nobody notices silently relabels every
	 * number on the screen.
	 */
	public function test_totals_read_every_metric_in_the_order_they_were_asked_for() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => array(
				array( 'metricValues' => array(
					array( 'value' => '1200' ),  // users
					array( 'value' => '900' ),   // newUsers
					array( 'value' => '1500' ),  // sessions
					array( 'value' => '3000' ),  // views
					array( 'value' => '1050' ),  // engagedSessions
					array( 'value' => '73.4' ),  // averageSessionDuration
				) ),
			) ) ),
		);
		$out = ( new Analytics() )->totals( 'tok', '12345', '2026-07-10', '2026-08-08' );

		$this->assertSame( 1200, $out['totals']['users'] );
		$this->assertSame( 900, $out['totals']['newUsers'] );
		$this->assertSame( 1500, $out['totals']['sessions'] );
		$this->assertSame( 3000, $out['totals']['views'] );
		$this->assertSame( 1050, $out['totals']['engaged'] );
		$this->assertSame( 73, $out['totals']['avgSeconds'] );
	}

	/** Derived from the counts already fetched, so the screen stays self-consistent. */
	public function test_engagement_and_pages_per_visit_are_derived_not_asked_for() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => array(
				array( 'metricValues' => array(
					array( 'value' => '1200' ), array( 'value' => '900' ), array( 'value' => '1500' ),
					array( 'value' => '3000' ), array( 'value' => '1050' ), array( 'value' => '73' ),
				) ),
			) ) ),
		);
		$out = ( new Analytics() )->totals( 'tok', '12345', '2026-07-10', '2026-08-08' );

		$this->assertSame( 70, $out['totals']['engagedPct'], '1050 of 1500' );
		$this->assertSame( 2.0, $out['totals']['perVisit'], '3000 views over 1500 visits' );
	}

	/** A property with no traffic must not divide by zero. */
	public function test_an_empty_window_does_not_divide_by_zero() {
		$GLOBALS['_af_http_queue'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( array( 'rows' => array() ) ),
		);
		$out = ( new Analytics() )->totals( 'tok', '12345', '2026-07-10', '2026-08-08' );

		$this->assertSame( 0, $out['totals']['sessions'] );
		$this->assertSame( 0, $out['totals']['engagedPct'] );
		$this->assertSame( 0.0, $out['totals']['perVisit'] );
	}
}
