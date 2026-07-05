<?php
/**
 * Base for REST integration tests: a fresh WP_REST_Server with the plugin's routes
 * registered, and three users (subscriber / editor / administrator) so a test can
 * dispatch REAL requests as each role and assert the real permission behaviour.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use WP_UnitTestCase;

abstract class RestTestCase extends WP_UnitTestCase {

	/** @var int */
	protected $subscriber;
	/** @var int */
	protected $editor;
	/** @var int */
	protected $admin;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' ); // registers the plugin's routes on the fresh server.

		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Every registered route under the plugin's namespace, mapped to its endpoints. */
	protected function agentimus_routes() {
		$out = array();
		foreach ( rest_get_server()->get_routes() as $route => $endpoints ) {
			if ( 0 === strpos( $route, '/agentimus/v1' ) ) {
				$out[ $route ] = $endpoints;
			}
		}
		return $out;
	}
}
