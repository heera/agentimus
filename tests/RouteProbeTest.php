<?php
/**
 * The route probe ({@see RouteProbe}) — head parsing, served-vs-generated
 * llms.txt comparison, and the refresh cycle's fail-open behavior: a run that
 * fetches nothing keeps the last good summary instead of replacing truth with
 * a blank.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\LlmsText;
use Agentimus\RouteProbe;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class RouteProbeTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/**
	 * WordPress actions and filters share ONE hook namespace. If the cron hook
	 * ever equals the data() filter tag again, apply_filters() calls the
	 * cron-registered refresh(), refresh() calls data(), and the recursion
	 * kills the process with no error output. This happened; keep them apart.
	 */
	public function test_cron_hook_and_filter_tag_never_collide() {
		$this->assertNotSame( RouteProbe::FILTER, RouteProbe::CRON );
	}

	/* ---- head_summary ----------------------------------------------------- */

	/** A normal head: one description, one og:title, no foreign generator. */
	public function test_clean_head_counts_one_of_each() {
		$html = '<html><head>'
			. '<meta name="description" content="One site, one voice.">'
			. '<meta property="og:title" content="Hello">'
			. '<meta name="generator" content="WordPress 7.1">'
			. '</head><body><meta name="description" content="body copies do not count"></body></html>';
		$s    = RouteProbe::head_summary( $html );
		$this->assertSame( 1, $s['descriptions'] );
		$this->assertSame( 1, $s['og_titles'] );
		$this->assertSame( array(), $s['others'] );
	}

	/** The ThinkRank-shaped conflict: doubled tags, and the other party named. */
	public function test_duplicate_head_counts_and_names_the_other_generator() {
		$html = '<head>'
			. '<meta name="description" content="From the theme" />'
			. '<meta name="generator" content="WordPress 7.1" />'
			. '<meta name="generator" content="ThinkRank 1.22.0" />'
			. '<meta name="description" content="From the other plugin">'
			. '<meta property="og:title" content="A"><meta property="og:title" content="B">'
			. '</head>';
		$s    = RouteProbe::head_summary( $html );
		$this->assertSame( 2, $s['descriptions'] );
		$this->assertSame( 2, $s['og_titles'] );
		$this->assertSame( array( 'ThinkRank 1.22.0' ), $s['others'] );
	}

	/** Generator with content= before name= is still read; duplicates dedupe. */
	public function test_generator_attribute_order_and_dedupe() {
		$html = '<head>'
			. '<meta content="OtherTool 2.0" name="generator">'
			. '<meta name="generator" content="OtherTool 2.0">'
			. '</head>';
		$this->assertSame( array( 'OtherTool 2.0' ), RouteProbe::head_summary( $html )['others'] );
	}

	/** No </head> at all: the capped prefix is scanned, nothing fatals. */
	public function test_headless_and_malformed_html_do_not_break() {
		$s = RouteProbe::head_summary( '<meta name="description" content="a"><meta property="og:title"' );
		$this->assertSame( 1, $s['descriptions'] );
		$this->assertSame( array(), RouteProbe::head_summary( '' )['others'] );
	}

	/** Tags past the byte cap are simply not seen — the scan stays bounded. */
	public function test_scan_respects_the_byte_cap() {
		$html = str_repeat( ' ', RouteProbe::MAX_BYTES )
			. '<meta name="description" content="beyond the cap">';
		$this->assertSame( 0, RouteProbe::head_summary( $html )['descriptions'] );
	}

	/* ---- is_ours ---------------------------------------------------------- */

	public function test_exact_match_is_ours() {
		$this->assertTrue( RouteProbe::is_ours( "# Site\n\nBody.", "# Site\n\nBody." ) );
	}

	/** A BOM or a harmless trailing addition still reads as our file. */
	public function test_bom_and_trailing_addition_still_ours() {
		$expected = str_repeat( '# Site header line. ', 20 ); // > 200 chars so the prefix rule has teeth.
		$this->assertTrue( RouteProbe::is_ours( "\xEF\xBB\xBF" . $expected, $expected ) );
		$this->assertTrue( RouteProbe::is_ours( $expected . "\n\n<!-- cache footer -->", $expected ) );
	}

	public function test_foreign_body_is_not_ours() {
		$this->assertFalse( RouteProbe::is_ours( "# Their Site\n\nTheir index.", "# Our Site\n\nOur index." ) );
		$this->assertFalse( RouteProbe::is_ours( 'anything', '' ) );
	}

	/* ---- refresh + data --------------------------------------------------- */

	/** Our own generated body served back: ours=true, head summarized. */
	public function test_refresh_confirms_our_llms_and_summarizes_head() {
		$expected                    = ( new LlmsText( new Settings() ) )->llms_txt();
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => $expected, 'headers' => array() );
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => '<head><meta name="description" content="x"></head>', 'headers' => array() );

		RouteProbe::refresh();
		$data = RouteProbe::data();

		$this->assertTrue( $data['llms']['ours'] );
		$this->assertSame( 200, $data['llms']['http'] );
		$this->assertSame( 1, $data['head']['descriptions'] );
		$this->assertSame( '', $data['error'] );
	}

	/** A foreign file on the route: ours=false and its first line is kept. */
	public function test_refresh_flags_a_foreign_llms() {
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => "# Someone Else\nTheir map.", 'headers' => array() );
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => '<head></head>', 'headers' => array() );

		RouteProbe::refresh();
		$data = RouteProbe::data();

		$this->assertFalse( $data['llms']['ours'] );
		$this->assertSame( '# Someone Else', $data['llms']['first_line'] );
	}

	/** Fail-open: a run of pure fetch errors keeps the previous good summary. */
	public function test_refresh_keeps_previous_summary_when_fetches_fail() {
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => ( new LlmsText( new Settings() ) )->llms_txt(), 'headers' => array() );
		$GLOBALS['_af_http_queue'][] = array( 'response' => array( 'code' => 200 ), 'body' => '<head><meta property="og:title" content="t"></head>', 'headers' => array() );
		RouteProbe::refresh();

		$GLOBALS['_af_http_queue'][] = new \WP_Error( 'http_request_failed', 'cURL error 28' );
		$GLOBALS['_af_http_queue'][] = new \WP_Error( 'http_request_failed', 'cURL error 28' );
		RouteProbe::refresh();

		$data = RouteProbe::data();
		$this->assertSame( 1, $data['head']['og_titles'] ); // The good summary survived.
		$this->assertNotSame( '', $data['error'] );          // And the failure is on record.
	}

	/** The filter seam: tests and site owners can override the stored summary. */
	public function test_data_filter_overrides_and_garbage_reads_null() {
		\update_option( RouteProbe::OPTION, 'not-an-array' );
		$this->assertNull( RouteProbe::data() );

		\add_filter( 'agentimus_route_probe', function () {
			return array( 'checked_at' => 1, 'error' => '', 'llms' => null, 'head' => null );
		} );
		$this->assertSame( 1, RouteProbe::data()['checked_at'] );
	}
}
