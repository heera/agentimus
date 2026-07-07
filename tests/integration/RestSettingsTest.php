<?php
/**
 * Settings save → read through the real REST controller: an admin's POST is
 * sanitised (the P2 size caps apply) and persisted, and reads back capped. Proves
 * the write path end-to-end against real WordPress, not the stubbed sanitizers.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

final class RestSettingsTest extends RestTestCase {

	public function test_saving_settings_applies_size_caps_and_round_trips() {
		wp_set_current_user( $this->admin );

		$many = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$many[] = 'agent-' . $i;
		}

		$save = new \WP_REST_Request( 'POST', '/agentimus/v1/settings' );
		$save->set_header( 'Content-Type', 'application/json' );
		$save->set_body( wp_json_encode( array( 'block_agents' => true, 'blocked_agents' => $many ) ) );
		$this->assertSame( 200, rest_do_request( $save )->get_status() );

		// Read it back through the GET endpoint: the list is capped (sanitize ran for real).
		$get  = rest_do_request( new \WP_REST_Request( 'GET', '/agentimus/v1/settings' ) );
		$this->assertSame( 200, $get->get_status() );
		$data = (array) $get->get_data();

		$this->assertLessThanOrEqual( 200, count( $data['settings']['blocked_agents'] ) );
		$this->assertTrue( (bool) $data['settings']['block_agents'] );
	}

	public function test_save_returns_the_freshly_expanded_exposed_paths() {
		wp_set_current_user( $this->admin );

		$save = new \WP_REST_Request( 'POST', '/agentimus/v1/settings' );
		$save->set_header( 'Content-Type', 'application/json' );
		$save->set_body( wp_json_encode( array( 'exposed_extra_paths' => array( 'secret.env' ) ) ) );
		$res = rest_do_request( $save );
		$this->assertSame( 200, $res->get_status() );
		$data = (array) $res->get_data();

		// The save response carries the freshly-expanded exposed-files probe list, so the
		// admin's browser-side scan picks up a just-added custom path with no page reload.
		$this->assertArrayHasKey( 'exposedPaths', $data );
		$this->assertContains( '/secret.env', $data['exposedPaths'] );
		$this->assertContains( '/wp-content/uploads/secret.env', $data['exposedPaths'] );
	}
}
