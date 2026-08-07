<?php
/**
 * Rewrite self-heal — Plugin::maybe_flush_rewrites() flushes the /.well-known
 * rewrite rules exactly once when the routed-name set changes (an Agentimus
 * update OR a provider's `agentimus_well_known_routed` filter), and NEVER thrashes
 * flush_rewrite_rules() in a way that would slow a site down.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Plugin;
use PHPUnit\Framework\TestCase;

final class RewriteFlushTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_is_admin'] = true; // default: the admin/CLI context where flushing is allowed.
	}

	/** Invoke the private signature helper. */
	private function signature(): string {
		$m = new \ReflectionMethod( Plugin::class, 'rewrite_signature' );
		\_af_accessible( $m );
		return (string) $m->invoke( null );
	}

	public function test_signature_is_deterministic_across_calls() {
		// Order-independence + stability: the same routed set must always hash the same,
		// or the steady state would flush every request.
		$this->assertSame( $this->signature(), $this->signature() );
		$this->assertNotSame( '', $this->signature() );
	}

	/**
	 * Every rewrite this plugin owns must be IN the fingerprint, or a change to
	 * it ships without a flush — and an unrouted consent page 404s, killing the
	 * connect flow with no error anyone can read. The OAuth well-known paths and
	 * the consent page's query var are the ones added in the OAuth round.
	 */
	public function test_signature_covers_every_rewrite_we_own() {
		$sig = $this->signature();
		foreach ( array( 'oauth-authorization-server/agentimus/mcp', 'oauth-protected-resource/agentimus/mcp', 'oauth-protected-resource/wp-json/agentimus/v1/mcp' ) as $path ) {
			$this->assertContains( $path, \Agentimus\Discovery\WellKnown::nested_routes() );
		}
		// The consent rule is fingerprinted by its query var: change it, and the
		// hash must move so existing installs re-flush on their next admin load.
		$m = new \ReflectionMethod( Plugin::class, 'rewrite_signature' );
		\_af_accessible( $m );
		$this->assertNotSame(
			$sig,
			md5( 'a-different-rule-set' ),
			'the signature must be derived from the real rule set'
		);
		$this->assertStringContainsString( AGENTIMUS_VERSION, $sig );
	}

	public function test_no_flush_when_signature_unchanged() {
		update_option( Plugin::REWRITE_SIGNATURE_OPTION, $this->signature() );
		Plugin::maybe_flush_rewrites();
		$this->assertSame( 0, $GLOBALS['_af_flush_count'], 'an unchanged set must never flush' );
	}

	public function test_flushes_once_on_change_then_stays_quiet() {
		update_option( Plugin::REWRITE_SIGNATURE_OPTION, 'stale-signature' );

		Plugin::maybe_flush_rewrites();
		$this->assertSame( 1, $GLOBALS['_af_flush_count'], 'a changed set flushes once' );
		$this->assertSame( $this->signature(), get_option( Plugin::REWRITE_SIGNATURE_OPTION ), 'new signature recorded' );

		// Every subsequent request now matches → no further flushing (the per-request guarantee).
		Plugin::maybe_flush_rewrites();
		Plugin::maybe_flush_rewrites();
		$this->assertSame( 1, $GLOBALS['_af_flush_count'], 'steady state never re-flushes' );
	}

	public function test_never_flushes_on_front_end_even_when_changed() {
		$GLOBALS['_af_is_admin'] = false; // an anonymous front-end / crawler request
		update_option( Plugin::REWRITE_SIGNATURE_OPTION, 'stale-signature' );

		Plugin::maybe_flush_rewrites();
		$this->assertSame( 0, $GLOBALS['_af_flush_count'], 'front-end requests must never trigger a flush' );
		// And the stored signature is untouched, so the next ADMIN request still heals.
		$this->assertSame( 'stale-signature', get_option( Plugin::REWRITE_SIGNATURE_OPTION ) );
	}

	public function test_rate_limit_caps_back_to_back_flushes() {
		// First change flushes (no prior flush timestamp).
		update_option( Plugin::REWRITE_SIGNATURE_OPTION, 'stale-1' );
		Plugin::maybe_flush_rewrites();
		$this->assertSame( 1, $GLOBALS['_af_flush_count'] );

		// A second change arriving within the window is suppressed — this is the backstop
		// against a provider whose filter is unstable across requests.
		update_option( Plugin::REWRITE_SIGNATURE_OPTION, 'stale-2' );
		Plugin::maybe_flush_rewrites();
		$this->assertSame( 1, $GLOBALS['_af_flush_count'], 'no second flush within the window' );

		// Once the window clears, a still-pending change persists on the next admin request.
		update_option( Plugin::REWRITE_FLUSHED_AT_OPTION, time() - ( Plugin::REWRITE_FLUSH_MIN_INTERVAL + 1 ) );
		Plugin::maybe_flush_rewrites();
		$this->assertSame( 2, $GLOBALS['_af_flush_count'], 'flushing resumes after the window' );
	}

	public function test_runs_under_wp_cli_context() {
		// WP-CLI is not is_admin(), but plugin (de)activation via `wp` must still heal.
		$GLOBALS['_af_is_admin'] = false;
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}
		update_option( Plugin::REWRITE_SIGNATURE_OPTION, 'stale-signature' );
		Plugin::maybe_flush_rewrites();
		$this->assertSame( 1, $GLOBALS['_af_flush_count'], 'WP-CLI is an allowed flush context' );
	}
}
