<?php
/**
 * The conflict-honesty readiness rows — {@see Readiness::check_llms_txt()} now
 * judges the ROUTE (static file, foreign server, HTTP failure) from RouteProbe
 * data instead of reporting its own setting as fact, and the new
 * {@see Readiness::check_head_conflict()} row surfaces duplicate head tags and
 * names the other party. Both fail open: no probe data, no accusations.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Readiness;
use Agentimus\RouteProbe;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ReadinessConflictTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Reflection-call a private check (report() touches WP-heavy deps). */
	private function invoke( $method ) {
		$m = new \ReflectionMethod( Readiness::class, $method );
		\_af_accessible( $m );
		return $m->invoke( new Readiness( new Settings() ) );
	}

	/** Inject a stored probe summary through the filter seam. */
	private function probe( $llms, $head = null ) {
		\add_filter( 'agentimus_route_probe', function () use ( $llms, $head ) {
			return array( 'checked_at' => time(), 'error' => '', 'llms' => $llms, 'head' => $head );
		} );
	}

	/* ---- /llms.txt route ownership ---------------------------------------- */

	/** No probe yet: the plain pass of old — never a verdict without data. */
	public function test_llms_without_probe_is_a_plain_pass() {
		$row = $this->invoke( 'check_llms_txt' );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringNotContainsString( 'self-check', $row['detail'] );
	}

	/** Probe confirmed our index: the pass says so. */
	public function test_llms_verified_pass_mentions_the_self_check() {
		$this->probe( array( 'http' => 200, 'ours' => true, 'first_line' => '# Site' ) );
		$row = $this->invoke( 'check_llms_txt' );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'confirmed', $row['detail'] );
	}

	/** Probe saw a foreign file: fail, plain words, a link to look at it. */
	public function test_llms_foreign_route_fails() {
		$this->probe( array( 'http' => 200, 'ours' => false, 'first_line' => '# Someone Else' ) );
		$row = $this->invoke( 'check_llms_txt' );
		$this->assertSame( 'fail', $row['status'] );
		$this->assertStringContainsString( 'something else answers', $row['detail'] );
		$this->assertSame( 'https://example.test/llms.txt', $row['action']['href'] );
	}

	/** Probe could not load the route at all: fail naming the HTTP code. */
	public function test_llms_http_error_fails_with_the_code() {
		$this->probe( array( 'http' => 404, 'ours' => false, 'first_line' => '' ) );
		$row = $this->invoke( 'check_llms_txt' );
		$this->assertSame( 'fail', $row['status'] );
		$this->assertStringContainsString( 'HTTP 404', $row['detail'] );
	}

	/** Feature off: the original warn, untouched by probe data. */
	public function test_llms_disabled_still_warns_regardless_of_probe() {
		\update_option( Settings::OPTION, array( 'enable_llms_txt' => false ) );
		$this->probe( array( 'http' => 200, 'ours' => false, 'first_line' => '' ) );
		$row = $this->invoke( 'check_llms_txt' );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'Off', $row['detail'] );
	}

	/** A static llms.txt file at the web root wins over everything: warn. */
	public function test_llms_static_file_warns() {
		$root = \Agentimus\Paths::site_root();
		if ( ! is_dir( $root ) ) {
			mkdir( $root, 0777, true );
		}
		file_put_contents( $root . 'llms.txt', '# hand-made' );
		try {
			$row = $this->invoke( 'check_llms_txt' );
			$this->assertSame( 'warn', $row['status'] );
			$this->assertStringContainsString( 'site root folder', $row['detail'] );
		} finally {
			unlink( $root . 'llms.txt' );
		}
	}

	/* ---- duplicate head tags ---------------------------------------------- */

	/** No probe data: no row at all — report() drops the null silently. */
	public function test_head_conflict_absent_without_probe() {
		$this->assertNull( $this->invoke( 'check_head_conflict' ) );
	}

	/** One of each: a clean pass. */
	public function test_head_conflict_single_tags_pass() {
		$this->probe( null, array( 'descriptions' => 1, 'og_titles' => 1, 'others' => array() ) );
		$row = $this->invoke( 'check_head_conflict' );
		$this->assertSame( 'pass', $row['status'] );
	}

	/** Doubled tags: warn with the counts and the other party's name. */
	public function test_head_conflict_duplicates_warn_and_name_the_other_party() {
		$this->probe( null, array( 'descriptions' => 2, 'og_titles' => 2, 'others' => array( 'ThinkRank 1.22.0' ) ) );
		$row = $this->invoke( 'check_head_conflict' );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( '2 description tags', $row['detail'] );
		$this->assertStringContainsString( 'ThinkRank 1.22.0', $row['detail'] );
		$this->assertSame( 'head_dupes', $row['id'] );
	}

	/** Doubled descriptions alone are enough to warn — no name available is fine. */
	public function test_head_conflict_description_dupes_alone_warn() {
		$this->probe( null, array( 'descriptions' => 3, 'og_titles' => 1, 'others' => array() ) );
		$row = $this->invoke( 'check_head_conflict' );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( '3 description tags', $row['detail'] );
		$this->assertStringNotContainsString( 'social card', $row['detail'] ); // Only the repeated kind is named.
		$this->assertStringNotContainsString( 'Also printing', $row['detail'] );
	}
}
