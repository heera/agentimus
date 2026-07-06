<?php
/**
 * Exposure — the opt-in controls that limit what an anonymous visitor can read.
 * The registered callbacks are thin WordPress adapters; the decisions live in
 * pure static helpers, exercised directly here. Also pins the three settings as
 * OFF by default and boolean-clean through sanitize().
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Exposure;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ExposureTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/* -- Settings: opt-in, OFF by default, boolean-clean ----------------- */

	/** Every Exposure control ships OFF. */
	public function test_exposure_controls_default_off() {
		$d = ( new Settings() )->defaults();
		foreach ( array( 'hide_user_enumeration', 'disable_author_archives', 'hide_wp_version', 'tidy_head_links', 'disable_xmlrpc' ) as $key ) {
			$this->assertFalse( $d[ $key ], $key );
		}
	}

	public function test_sanitize_keeps_them_boolean() {
		$clean = ( new Settings() )->sanitize(
			array(
				'hide_user_enumeration'   => '1',
				'disable_author_archives' => true,
				'hide_wp_version'         => 0,
				'tidy_head_links'         => '1',
				'disable_xmlrpc'          => true,
			)
		);
		$this->assertTrue( $clean['hide_user_enumeration'] );
		$this->assertTrue( $clean['disable_author_archives'] );
		$this->assertFalse( $clean['hide_wp_version'] );
		$this->assertTrue( $clean['tidy_head_links'] );
		$this->assertTrue( $clean['disable_xmlrpc'] );
	}

	public function test_sanitize_absent_keys_fall_to_off() {
		$clean = ( new Settings() )->sanitize( array() );
		foreach ( array( 'hide_user_enumeration', 'disable_author_archives', 'hide_wp_version', 'tidy_head_links', 'disable_xmlrpc' ) as $key ) {
			$this->assertFalse( $clean[ $key ], $key );
		}
	}

	/* -- Exposed-files self-check: the sensitive-path list --------------- */

	/** The built-in list is non-empty and every entry is a root-relative path. */
	public function test_sensitive_paths_returns_root_relative_builtins() {
		$paths = Exposure::sensitive_paths( new Settings() );
		$this->assertNotEmpty( $paths );
		$this->assertContains( '/.env', $paths );
		$this->assertContains( '/wp-config.php.bak', $paths );
		foreach ( $paths as $p ) {
			$this->assertSame( '/', $p[0], "root-relative: $p" );
		}
	}

	/** Owner extras merge in — a bare name gets a slash, a full URL keeps only its path,
	 *  and a duplicate of a built-in is not listed twice. */
	public function test_sensitive_paths_merges_owner_extras_normalised_and_deduped() {
		update_option(
			Settings::OPTION,
			array(
				'exposed_extra_paths' => array( 'my-export.csv', 'https://example.com/secret.json', '/.env' ),
			)
		);
		$paths = Exposure::sensitive_paths( new Settings() );

		$this->assertContains( '/my-export.csv', $paths );
		$this->assertContains( '/secret.json', $paths );
		$this->assertCount( 1, array_keys( $paths, '/.env', true ), '/.env must not be duplicated' );
	}

	/** The list is filterable — a plugin can replace it wholesale. */
	public function test_exposed_paths_filter_replaces_the_list() {
		add_filter( 'agentimus_exposed_paths', static function () { return array( '/only-this' ); } );
		$this->assertSame( array( '/only-this' ), Exposure::sensitive_paths( new Settings() ) );
	}

	/** Extra paths are sanitised on save: junk chars stripped, a single leading slash forced. */
	public function test_sanitize_cleans_extra_paths() {
		$clean = ( new Settings() )->sanitize(
			array( 'exposed_extra_paths' => array( 'foo/bar', '/keep-me', '  ', 'has space/x' ) )
		);
		$this->assertContains( '/foo/bar', $clean['exposed_extra_paths'] );
		$this->assertContains( '/keep-me', $clean['exposed_extra_paths'] );
		$this->assertContains( '/hasspace/x', $clean['exposed_extra_paths'] );
		$this->assertNotContains( '', $clean['exposed_extra_paths'] );
	}

	/* -- User enumeration ------------------------------------------------ */

	public function test_users_routes_removed_for_anonymous() {
		$endpoints = array(
			'/wp/v2/users'                 => 'collection',
			'/wp/v2/users/(?P<id>[\d]+)'   => 'single',
			'/wp/v2/posts'                 => 'keep-me',
		);
		$out = Exposure::strip_users_from_endpoints( $endpoints, false );
		$this->assertArrayNotHasKey( '/wp/v2/users', $out );
		$this->assertArrayNotHasKey( '/wp/v2/users/(?P<id>[\d]+)', $out );
		$this->assertArrayHasKey( '/wp/v2/posts', $out, 'unrelated routes are untouched' );
	}

	public function test_users_routes_kept_for_logged_in() {
		$endpoints = array( '/wp/v2/users' => 'collection' );
		$this->assertSame( $endpoints, Exposure::strip_users_from_endpoints( $endpoints, true ) );
	}

	public function test_users_sitemap_provider_dropped_only_for_users() {
		$this->assertFalse( Exposure::drop_users_sitemap( 'provider', 'users' ) );
		$this->assertSame( 'provider', Exposure::drop_users_sitemap( 'provider', 'posts' ) );
	}

	public function test_oembed_author_fields_stripped() {
		$out = Exposure::strip_oembed_author(
			array( 'title' => 'A post', 'author_name' => 'Jane', 'author_url' => 'https://x.test' )
		);
		$this->assertArrayHasKey( 'title', $out );
		$this->assertArrayNotHasKey( 'author_name', $out );
		$this->assertArrayNotHasKey( 'author_url', $out );
	}

	/**
	 * Only a bare numeric ?author= is an enumeration probe; a slug archive is left.
	 *
	 * @dataProvider author_values
	 */
	public function test_author_enumeration_detection( $value, $expected ) {
		$this->assertSame( $expected, Exposure::is_author_enumeration( $value ) );
	}

	public function author_values() {
		return array(
			'numeric id'   => array( '1', true ),
			'numeric id 2' => array( '42', true ),
			'slug archive' => array( 'jane-doe', false ),
			'empty'        => array( '', false ),
			'mixed'        => array( '1x', false ),
		);
	}

	/* -- XML-RPC --------------------------------------------------------- */

	public function test_xmlrpc_methods_emptied() {
		$out = Exposure::no_xmlrpc_methods(
			array( 'pingback.ping' => 'cb', 'system.multicall' => 'cb', 'wp.getUsersBlogs' => 'cb' )
		);
		$this->assertSame( array(), $out );
	}

	public function test_pingback_header_dropped() {
		$out = Exposure::drop_pingback_header( array( 'X-Pingback' => 'https://x.test/xmlrpc.php', 'Vary' => 'Accept' ) );
		$this->assertArrayNotHasKey( 'X-Pingback', $out );
		$this->assertArrayHasKey( 'Vary', $out, 'other headers untouched' );
	}

	/* -- WP version fingerprint ------------------------------------------ */

	public function test_core_version_stripped_when_it_matches() {
		$this->assertSame( 'https://x.test/wp-includes/css/dashicons.css', Exposure::strip_core_version( 'https://x.test/wp-includes/css/dashicons.css?ver=6.9', '6.9' ) );
	}

	public function test_other_query_args_preserved() {
		$this->assertSame( 'https://x.test/a.js?foo=bar', Exposure::strip_core_version( 'https://x.test/a.js?ver=6.9&foo=bar', '6.9' ) );
	}

	public function test_non_core_version_left_alone() {
		// A plugin asset's own cache-buster must survive.
		$src = 'https://x.test/wp-content/plugins/acme/a.js?ver=2.3.1';
		$this->assertSame( $src, Exposure::strip_core_version( $src, '6.9' ) );
	}

	public function test_url_without_version_untouched() {
		$src = 'https://x.test/a.js';
		$this->assertSame( $src, Exposure::strip_core_version( $src, '6.9' ) );
	}

	/* -- Debug-logging verdict (Exposure tab's status card) -------------- */

	/** Debug off → pass, regardless of the other flags or environment. */
	public function test_debug_off_passes() {
		$this->assertSame( array( 'pass', 'off' ), Exposure::debug_verdict( true, false, true, true, true ) );
		$this->assertSame( array( 'pass', 'off' ), Exposure::debug_verdict( false, false, false, false, false ) );
	}

	/** Debug on but not production → pass (expected on dev / local / staging). */
	public function test_debug_on_outside_production_passes() {
		$this->assertSame( array( 'pass', 'dev' ), Exposure::debug_verdict( false, true, true, true, true ) );
	}

	/** Production + errors rendered on screen → the worst case: fail. */
	public function test_display_in_production_fails() {
		$this->assertSame( array( 'fail', 'display' ), Exposure::debug_verdict( true, true, true, true, true ) );
	}

	/** Production + a web-reachable log (display off) → fail (likely downloadable). */
	public function test_web_reachable_log_fails() {
		$this->assertSame( array( 'fail', 'log_web' ), Exposure::debug_verdict( true, true, false, true, true ) );
	}

	/** Production + logging to a path outside the web root → warn (noisy, not exposed). */
	public function test_private_log_warns() {
		$this->assertSame( array( 'warn', 'log_private' ), Exposure::debug_verdict( true, true, false, true, false ) );
	}

	/** Production + debug on but neither displaying nor logging → warn. */
	public function test_bare_debug_on_in_production_warns() {
		$this->assertSame( array( 'warn', 'on' ), Exposure::debug_verdict( true, true, false, false, false ) );
	}

	/* -- Environment auto-detect (host heuristic) ------------------------ */

	/** Loopback, RFC-reserved / mDNS / dev-tool TLDs, and private IPs → local. */
	public function test_host_is_local_true_for_reserved_and_private() {
		$local = array(
			'localhost', '127.0.0.1', '::1', '[::1]',
			'mysite.test', 'app.localhost', 'shop.local', 'x.example', 'y.invalid',
			'proj.ddev.site', 'proj.lndo.site',
			'192.168.1.10', '10.0.0.5', '172.16.9.9', 'fc00::1', 'MyProject.Test',
		);
		foreach ( $local as $h ) {
			$this->assertTrue( Exposure::host_is_local( $h ), $h );
		}
	}

	/**
	 * The safety-critical direction: a public production host must NEVER read as local, or the
	 * debug warning would be silenced. Note `.dev` is a REAL public TLD (Google) — not local.
	 */
	public function test_host_is_local_false_for_public_hosts() {
		$public = array(
			'heera.it', 'example.com', 'foo.dev', 'my-site.dev', 'staging.example.com',
			'sub.wpengine.com', '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111',
			'notlocalhost.com', 'localhostx.io', 'test.com', '',
		);
		foreach ( $public as $h ) {
			$this->assertFalse( Exposure::host_is_local( $h ), $h );
		}
	}
}
