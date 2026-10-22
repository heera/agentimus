<?php
/**
 * Google Analytics is a second, independent grant: a Search Console poll that
 * fails must not stop the analytics poll behind it.
 *
 * ⛔ Found in the 2026-10-23 audit: run_poll() returned early on a Search
 * Console error, before the analytics half ever ran. Removing the key from the
 * Search Console property (or a Search Console outage) froze the Visitors
 * numbers with no error on them at all.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Google\Auth;
use Agentimus\Google\Module;
use Agentimus\Google\Settings;
use PHPUnit\Framework\TestCase;

final class GoogleAnalyticsIndependenceTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		$GLOBALS['_af_transients_on'] = true;
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Connected to both, with a cached token for each scope (no OAuth round-trip). */
	private function connected(): Settings {
		$google = new Settings();
		$google->connect( '{"type":"service_account"}', 'sa@x.iam.gserviceaccount.com', 'sc-domain:example.com' );
		$google->set_ga4_property( '123456' );
		set_transient( Auth::TOKEN_TRANSIENT, 'tok-sc' );
		set_transient( Auth::TOKEN_PREFIX . substr( md5( Auth::SCOPE_ANALYTICS ), 0, 16 ), 'tok-ga' );
		return $google;
	}

	public function test_analytics_still_polls_when_search_console_refuses() {
		$google = $this->connected();
		$GLOBALS['_af_http_queue'] = array(
			array( 'response' => array( 'code' => 403 ), 'body' => '{"error":{"message":"User does not have sufficient permission for site"}}', 'headers' => array() ),
		);

		( new Module( $google ) )->run_poll();

		$after = new Settings();
		$this->assertNotSame( '', (string) $after->get( 'last_error', '' ), 'The Search Console refusal is on record.' );
		$this->assertGreaterThan( 0, (int) $after->get( 'ga4_poll_at', 0 ), 'And analytics was still asked.' );
	}

	public function test_analytics_still_polls_when_search_consoles_token_fails() {
		$google = $this->connected();
		delete_transient( Auth::TOKEN_TRANSIENT ); // Search Console must mint a token — and the key is not a real one.

		( new Module( $google ) )->run_poll();

		$after = new Settings();
		$this->assertNotSame( '', (string) $after->get( 'last_error', '' ) );
		$this->assertGreaterThan( 0, (int) $after->get( 'ga4_poll_at', 0 ) );
	}
}
