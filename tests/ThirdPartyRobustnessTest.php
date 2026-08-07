<?php
/**
 * Third-party robustness. Agentimus is an aggregator: other plugins feed it via
 * filters and the discovery registry. A BUGGY third party must never be able to
 * make Agentimus fatal, blank its admin UI, or emit a corrupt endpoint. Each test
 * here registers a hostile filter (or malformed data) and proves the output stays
 * well-formed.
 *
 * Regression guard for the 2026-06-28 hardening pass (Settings/Envelope/Schema/
 * Readiness boundaries). The bug that started it: a third-party readiness check
 * with no `status` blanked the Readiness tab.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\Readiness;
use Agentimus\Schema;
use Agentimus\Discovery\Envelope;
use Agentimus\Discovery\Registry;
use PHPUnit\Framework\TestCase;

final class ThirdPartyRobustnessTest extends TestCase {

	protected function setUp(): void {
		_af_reset_options();   // also clears the filter registry
		_af_reset_registry();
	}

	protected function tearDown(): void {
		_af_reset_options();
		_af_reset_registry();
	}

	/** A non-array `agentimus_default_settings` return can't break config resolution. */
	public function test_hostile_default_settings_filter_keeps_array() {
		add_filter( 'agentimus_default_settings', static function () { return 'boom'; } );
		$this->assertIsArray( ( new Settings() )->defaults() );
	}

	/** A non-array `agentimus_settings` return can't corrupt the admin boot payload. */
	public function test_hostile_settings_filter_keeps_resolved_array() {
		add_filter( 'agentimus_settings', static function () { return 42; } );
		$all = ( new Settings() )->all();
		$this->assertIsArray( $all );
		$this->assertIsArray( $all['identity'] ?? null, 'identity sub-array survives' );
	}

	/** A non-array `agentimus_sanitize_settings` return can't be persisted as the option. */
	public function test_hostile_sanitize_filter_never_yields_non_array() {
		add_filter( 'agentimus_sanitize_settings', static function () { return null; } );
		$this->assertIsArray( ( new Settings() )->sanitize( array() ) );
	}

	/** A non-array `agentimus_envelope` return can't corrupt discovery.json / Discovery tab. */
	public function test_hostile_envelope_filter_keeps_valid_document() {
		add_filter( 'agentimus_envelope', static function () { return 'corrupted'; } );

		$env = ( new Envelope( new Settings(), Registry::instance() ) )->build();
		$this->assertIsArray( $env );
		$this->assertArrayHasKey( 'spec_version', $env, 'core envelope keys survive' );

		$decoded = json_decode( (string) ( new Envelope( new Settings(), Registry::instance() ) )->discovery_json(), true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), 'discovery.json is valid JSON' );
		$this->assertIsArray( $decoded );
	}

	/** A null/empty `agentimus_schema_url` return falls back to a real URL, never blank. */
	public function test_hostile_schema_url_filter_falls_back_to_real_url() {
		add_filter( 'agentimus_schema_url', static function () { return null; } );
		$url = Envelope::schema_url();
		$this->assertNotSame( '', $url );
		$this->assertStringStartsWith( 'http', $url );
	}

	/** Malformed third-party readiness checks are normalised or dropped — never crash. */
	public function test_malformed_readiness_checks_are_normalised() {
		$raw = array(
			array( 'id' => 'ok', 'label' => 'Fine', 'status' => 'pass' ),
			array( 'id' => 'evil', 'label' => 'Boolean convention', 'pass' => true ), // no `status`
			'a bare string',                                                           // not an array
			array( 'no_id' => true ),                                                  // missing id
		);

		$m = new \ReflectionMethod( Readiness::class, 'normalize' );
		\_af_accessible( $m );
		$out = $m->invoke( new Readiness( new Settings() ), $raw );

		$this->assertIsArray( $out );
		foreach ( $out as $c ) {
			$this->assertIsArray( $c );
			$this->assertNotEmpty( $c['id'], 'every kept check has an id' );
			$this->assertContains( $c['status'], array( 'pass', 'warn', 'fail' ), 'status always valid' );
		}
		$ids = array_column( $out, 'id' );
		$this->assertContains( 'evil', $ids, 'boolean-pass check is kept' );
		$this->assertNotContains( '', $ids, 'id-less check is dropped' );
		$evil = $out[ array_search( 'evil', $ids, true ) ];
		$this->assertSame( 'pass', $evil['status'], 'pass:true mapped to status:pass' );
	}

	/** A scalar `agentimus_schema_for_post` / `agentimus_schema_graph` return can't break JSON-LD. */
	public function test_hostile_schema_filters_keep_valid_jsonld() {
		$GLOBALS['_af_posts'][5]        = (object) array(
			'ID'            => 5,
			'post_status'   => 'publish',
			'post_password' => '',
			'post_type'     => 'post',
			'post_title'    => 'Hello',
			'post_content'  => 'Body text.',
		);
		$GLOBALS['_af_current_post_id'] = 5;
		$GLOBALS['_af_is_singular']     = true;

		add_filter( 'agentimus_schema_for_post', static function () { return 'scalar-node'; } );
		add_filter( 'agentimus_schema_graph', static function () { return 'not-a-graph'; } );

		ob_start();
		( new Schema( new Settings() ) )->output();
		$out = (string) ob_get_clean();

		// Scalar garbage must not leak into the document…
		$this->assertStringNotContainsString( 'scalar-node', $out );
		$this->assertStringNotContainsString( 'not-a-graph', $out );
		// …and the site-level JSON-LD still renders and parses.
		$this->assertStringContainsString( 'WebSite', $out );
		$this->assertSame( 1, preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $out, $mm ), 'JSON-LD block emitted' );
		json_decode( $mm[1], true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), 'emitted JSON-LD is valid JSON' );
	}
}
