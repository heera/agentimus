<?php
/**
 * purge-edge-cache empties the Cloudflare cache, behind the maintenance switch and
 * a limit that counts the whole site.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Abilities\EdgePurge;
use Agentimus\Abilities\Registrar;
use Agentimus\Cloudflare\Settings as CloudflareSettings;
use Agentimus\Settings;

final class EdgePurgeDbTest extends DbTestCase {

	/** @var Settings */
	private $settings;

	/** @var array<int,array{url:string,body:string}> */
	private $calls = array();

	/** @var int */
	private $status = 200;

	/** @var callable|null */
	private $http;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->settings = new Settings();
		$this->maintenance( true );
		delete_transient( EdgePurge::COUNT_TRANSIENT );

		( new CloudflareSettings() )->connect( 'test-token', 'zone123', 'example.org' );

		$this->http = function ( $pre, $args, $url ) {
			$this->calls[] = array(
				'url'  => (string) $url,
				'body' => isset( $args['body'] ) ? (string) $args['body'] : '',
			);
			return array(
				'response' => array( 'code' => $this->status, 'message' => '' ),
				'headers'  => array(),
				'cookies'  => array(),
				'body'     => 200 === $this->status ? '{"success":true,"errors":[],"result":{"id":"x"}}' : '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}',
			);
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->http, 10 );
		delete_transient( EdgePurge::COUNT_TRANSIENT );
		( new CloudflareSettings() )->disconnect();
		parent::tear_down();
	}

	private function maintenance( $on ) {
		$all                             = $this->settings->stored();
		$all['enable_mcp_server']        = true;
		$all['enable_agent_writes']      = true;
		$all['agent_writes_maintenance'] = $on;
		$this->settings->update( $all );
	}

	private function purge() {
		return ( new EdgePurge( $this->settings ) )->purge();
	}

	public function test_it_is_off_until_the_owner_turns_it_on() {
		$this->maintenance( false );
		$result = $this->purge();

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_maintenance_off', $result->get_error_code() );
		$this->assertSame( array(), $this->calls, 'Off must reach nobody.' );
		$this->assertNotContains( 'agentimus/purge-edge-cache', ( new Registrar( $this->settings ) )->mcp_abilities() );

		$this->maintenance( true );
		$this->assertContains( 'agentimus/purge-edge-cache', ( new Registrar( $this->settings ) )->mcp_abilities() );
		$this->assertTrue( Registrar::is_write_tool_name( 'agentimus/purge-edge-cache' ) );
	}

	public function test_it_purges_the_zone_and_says_so() {
		$result = $this->purge();

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['purged'] );
		$this->assertSame( 'example.org', $result['zone'] );
		$this->assertGreaterThan( 0, $result['purgedAt'] );
		$this->assertCount( 1, $this->calls );
		$this->assertStringContainsString( 'zones/zone123/purge_cache', $this->calls[0]['url'] );
		$this->assertStringContainsString( 'purge_everything', $this->calls[0]['body'] );
	}

	public function test_a_refusal_is_reported_and_never_reads_as_purged() {
		$this->status = 403;
		$result       = $this->purge();

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_purge_failed', $result->get_error_code() );
	}

	public function test_it_does_not_answer_with_the_token() {
		$result = $this->purge();
		$this->assertStringNotContainsString( 'test-token', (string) wp_json_encode( $result ) );
	}

	public function test_nothing_happens_when_cloudflare_is_not_connected() {
		( new CloudflareSettings() )->disconnect();
		$result = $this->purge();

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_refresh_not_connected', $result->get_error_code() );
		$this->assertSame( array(), $this->calls );
	}

	/** ⛔ Another connection must not get a fresh allowance: the site is what goes cold. */
	public function test_the_limit_counts_the_whole_site() {
		for ( $i = 0; $i < EdgePurge::HOURLY_LIMIT; $i++ ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$this->assertIsArray( $this->purge() );
		}
		$this->calls = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = $this->purge();

		$this->assertWPError( $result );
		$this->assertSame( 'agentimus_purge_rate_limited', $result->get_error_code() );
		$this->assertSame( array(), $this->calls, 'Over the limit must reach nobody.' );
	}
}
