<?php
/**
 * refresh-data fetches a source's numbers, behind its own switch and a limit, and
 * never spends the index inspection quota.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\DataRefresh;
use Agentimus\Abilities\Registrar;
use Agentimus\Google\Auth;
use Agentimus\Google\Settings as GoogleSettings;
use Agentimus\Settings;

final class DataRefreshDbTest extends DbTestCase {

	/** @var Settings */
	private $settings;

	/** @var string[] Every URL the code tried to reach. */
	private $urls = array();

	/** @var int Status the fake answers with. */
	private $status = 200;

	/** @var callable|null */
	private $http;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings = new Settings();
		$this->switches( true );

		( new GoogleSettings() )->connect( '{"type":"service_account"}', 'bot@example.iam.gserviceaccount.com', 'sc-domain:example.org' );
		set_transient( Auth::TOKEN_TRANSIENT, 'fake-token', HOUR_IN_SECONDS );

		$this->http = function ( $pre, $args, $url ) {
			$this->urls[] = (string) $url;
			return array(
				'response' => array( 'code' => $this->status, 'message' => '' ),
				'headers'  => array(),
				'cookies'  => array(),
				'body'     => 200 === $this->status ? '{"rows":[],"sitemap":[]}' : '{"error":{"message":"The key lost its access."}}',
			);
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->http, 10 );
		delete_transient( Auth::TOKEN_TRANSIENT );
		parent::tear_down();
	}

	private function switches( $on ) {
		$all                             = $this->settings->stored();
		$all['enable_mcp_server']        = true;
		$all['enable_agent_writes']      = true;
		$all['agent_writes_maintenance'] = $on;
		$this->settings->update( $all );
	}

	private function refresh( $source ) {
		return ( new DataRefresh( $this->settings ) )->refresh( $source );
	}

	public function test_it_is_off_until_the_owner_turns_it_on() {
		$this->assertFalse( $this->settings->defaults()['agent_writes_maintenance'] );

		$this->switches( false );
		$result = $this->refresh( 'google' );

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_maintenance_off', $result->get_error_code() );
		$this->assertSame( array(), $this->urls, 'Off must reach nobody.' );
		$this->assertNotContains( 'agentimus/refresh-data', ( new Registrar( $this->settings ) )->mcp_abilities() );

		$this->switches( true );
		$this->assertContains( 'agentimus/refresh-data', ( new Registrar( $this->settings ) )->mcp_abilities() );
		$this->assertTrue( Registrar::is_write_tool_name( 'agentimus/refresh-data' ) );
	}

	public function test_turning_writes_off_turns_it_off() {
		$all                        = $this->settings->stored();
		$all['enable_agent_writes'] = false;
		$this->settings->update( $all );
		$this->assertFalse( $this->settings->stored()['agent_writes_maintenance'] );
	}

	public function test_it_fetches_and_says_when() {
		$result = $this->refresh( 'google' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['refreshed'] );
		$this->assertGreaterThan( 0, $result['lastPollAt'] );
		$this->assertNotEmpty( $this->urls );
	}

	/** ⛔ Google's scheduled poll also sweeps the index. A refresh must never spend that quota. */
	public function test_a_google_refresh_spends_no_inspection_quota() {
		$this->refresh( 'google' );

		$this->assertNotEmpty( $this->urls );
		foreach ( $this->urls as $url ) {
			$this->assertStringNotContainsString( 'urlInspection', $url );
		}
	}

	public function test_a_failed_fetch_is_reported_in_the_sources_words() {
		$this->status = 403;
		$result       = $this->refresh( 'google' );

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_refresh_failed', $result->get_error_code() );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	/** @return array<string,array{string}> */
	public function not_connected() {
		return array(
			'cloudflare' => array( 'cloudflare' ),
			'bing'       => array( 'bing' ),
			'analytics'  => array( 'analytics' ),
		);
	}

	/** @dataProvider not_connected */
	public function test_a_source_that_is_not_connected_is_refused( $source ) {
		$result = $this->refresh( $source );
		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_refresh_not_connected', $result->get_error_code() );
		$this->assertSame( array(), $this->urls );
	}

	public function test_an_unknown_source_is_refused() {
		$result = $this->refresh( 'everything' );
		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_refresh_source', $result->get_error_code() );
	}

	public function test_refreshes_are_limited_per_source_and_connection() {
		for ( $i = 0; $i < DataRefresh::HOURLY_LIMIT; $i++ ) {
			$this->assertIsArray( $this->refresh( 'google' ) );
		}
		$this->urls = array();

		$result = $this->refresh( 'google' );

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_refresh_rate_limited', $result->get_error_code() );
		$this->assertSame( array(), $this->urls, 'Over the limit must reach nobody.' );
	}
}
