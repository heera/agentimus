<?php
/**
 * The solo/coexist mode resolver ({@see SeoContext}) — suite detection, priority
 * order, the `agentimus_solo_mode` filter, and that the consumers which predate
 * modes ({@see Schema::seo_plugin_present()}, {@see Sitemap::detect()}) still read
 * the exact same signal after the consolidation.
 *
 * Detection itself keys off plugin constants/classes that cannot be faked (and
 * un-faked) inside one PHP process, so the value-level tests run through the pure
 * seam {@see SeoContext::resolve_from()} over the REAL {@see SeoContext::suites()}
 * table with `active` flags flipped per case — the ids, labels and sitemap paths
 * asserted here are the live table's own.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Schema;
use Agentimus\SeoContext;
use Agentimus\Settings;
use Agentimus\Sitemap;
use PHPUnit\Framework\TestCase;

final class SeoContextTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/**
	 * The live suites table with the given entries switched active.
	 *
	 * @param string ...$ids Suite ids to mark active.
	 * @return array
	 */
	private function table_with_active( ...$ids ) {
		$table = SeoContext::suites();
		foreach ( $table as &$suite ) {
			$suite['active'] = in_array( $suite['id'], $ids, true );
		}
		return $table;
	}

	/* ---- raw detection ---------------------------------------------------- */

	/** In a clean test env (no SEO plugin constants) nothing is detected. */
	public function test_clean_env_detects_nothing() {
		$this->assertNull( SeoContext::detected() );
		$this->assertFalse( SeoContext::plugin_present() );
		$this->assertFalse( Schema::seo_plugin_present() ); // The delegate reads the same table.
	}

	/** Each known suite resolves with its own id, label and sitemap path. */
	public function test_each_suite_detected_with_its_sitemap_path() {
		$expected = array(
			'yoast'        => array( 'Yoast SEO', '/sitemap_index.xml' ),
			'rankmath'     => array( 'Rank Math', '/sitemap_index.xml' ),
			'aioseo'       => array( 'All in One SEO', '/sitemap.xml' ),
			'seopress'     => array( 'SEOPress', '/sitemap.xml' ),
			'seoframework' => array( 'The SEO Framework', '/sitemap.xml' ),
			'thinkrank'    => array( 'ThinkRank', '/sitemap.xml' ),
		);

		foreach ( $expected as $id => $want ) {
			$plugin = SeoContext::detected_from( $this->table_with_active( $id ) );
			$this->assertSame( $id, $plugin['id'] );
			$this->assertSame( $want[0], $plugin['label'] );
			$this->assertSame( $want[1], $plugin['sitemap_path'] );
		}
	}

	/** The table covers exactly the six known suites — a new entry must extend this suite. */
	public function test_table_covers_the_six_known_suites() {
		$this->assertSame(
			array( 'yoast', 'rankmath', 'aioseo', 'seopress', 'seoframework', 'thinkrank' ),
			array_column( SeoContext::suites(), 'id' )
		);
	}

	/** Several suites active mid-migration: the first in priority order wins. */
	public function test_first_active_suite_wins() {
		$plugin = SeoContext::detected_from( $this->table_with_active( 'seopress', 'rankmath' ) );
		$this->assertSame( 'rankmath', $plugin['id'] );
	}

	/* ---- mode resolution -------------------------------------------------- */

	public function test_no_suite_resolves_solo() {
		$resolved = SeoContext::resolve_from( $this->table_with_active() );
		$this->assertSame( 'solo', $resolved['mode'] );
		$this->assertNull( $resolved['plugin'] );
		$this->assertTrue( SeoContext::solo() ); // Clean env, live path.
	}

	public function test_active_suite_resolves_coexist_with_the_plugin_named() {
		$resolved = SeoContext::resolve_from( $this->table_with_active( 'yoast' ) );
		$this->assertSame( 'coexist', $resolved['mode'] );
		$this->assertSame( 'yoast', $resolved['plugin']['id'] );
	}

	/** The filter can force solo alongside a detected suite — which stays reported. */
	public function test_filter_can_force_solo_but_the_suite_stays_reported() {
		\add_filter( 'agentimus_solo_mode', function () {
			return true;
		} );
		$resolved = SeoContext::resolve_from( $this->table_with_active( 'seopress' ) );
		$this->assertSame( 'solo', $resolved['mode'] );
		$this->assertSame( 'seopress', $resolved['plugin']['id'] );
	}

	/** The filter can hold coexist behavior for a suite Agentimus doesn't know. */
	public function test_filter_can_force_coexist_without_a_detected_suite() {
		\add_filter( 'agentimus_solo_mode', function () {
			return false;
		} );
		$resolved = SeoContext::resolve_from( $this->table_with_active() );
		$this->assertSame( 'coexist', $resolved['mode'] );
		$this->assertNull( $resolved['plugin'] );
	}

	/** The filter receives the detected suite as context. */
	public function test_filter_receives_the_detected_suite() {
		$seen = null;
		\add_filter( 'agentimus_solo_mode', function ( $solo, $plugin ) use ( &$seen ) {
			$seen = $plugin;
			return $solo;
		} );
		SeoContext::resolve_from( $this->table_with_active( 'aioseo' ) );
		$this->assertSame( 'aioseo', $seen['id'] );
	}

	/** The raw signal ignores the mode filter — the pre-mode deferral contract. */
	public function test_raw_signal_ignores_the_mode_filter() {
		\add_filter( 'agentimus_solo_mode', function () {
			return true;
		} );
		$this->assertNull( SeoContext::detected_from( $this->table_with_active() ) );
		$this->assertFalse( SeoContext::plugin_present() );
		$this->assertFalse( Schema::seo_plugin_present() );
	}

	/* ---- consumers keep their behavior ------------------------------------ */

	/**
	 * Clean env + the enable_sitemap default: detect() promotes the Agentimus
	 * generator (core absent, no suite) — advertised at core's canonical
	 * /wp-sitemap.xml, the address that never moves out from under a
	 * search-console registration.
	 */
	public function test_sitemap_detect_falls_through_to_agentimus_in_clean_env() {
		\update_option( Settings::OPTION, array() ); // Defaults: enable_sitemap on.
		$detected = Sitemap::detect();
		$this->assertSame( 'agentimus', $detected['source'] );
		$this->assertSame( 'https://example.test' . Sitemap::INDEX_PATH, $detected['url'] );
	}

	/** enable_sitemap off in a clean env: nothing to advertise, url is ''. */
	public function test_sitemap_detect_reports_none_when_generator_opted_out() {
		\update_option( Settings::OPTION, array( 'enable_sitemap' => false ) );
		$detected = Sitemap::detect();
		$this->assertSame( '', $detected['url'] );
		$this->assertSame( '', $detected['source'] );
	}
}
