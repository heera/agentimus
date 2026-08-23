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

		$this->forget_connection_caches();
	}

	/**
	 * ⚠️ A REST controller's connection store OUTLIVES the test that used it.
	 * Plugin::boot() builds ONE Google/Bing/Cloudflare Settings each and hands
	 * them to the controllers for the life of the PROCESS, while
	 * {@see \Agentimus\ConnectionStore::all()} caches per instance — right in
	 * production, where an instance lives for one request, and a trap here: the
	 * first test to read a store leaves its answer behind for every test after
	 * it, however many options they write or roll back.
	 *
	 * It bites in both directions. A test that reads while disconnected makes
	 * later tests "not connected" no matter what they store; a test that
	 * connects makes a later "it says so when nothing is connected" test pass a
	 * 200. Both have happened here. The fix is one truthful read per test, not
	 * an ordering convention nobody can see.
	 *
	 * @return void
	 */
	protected function forget_connection_caches(): void {
		$hook = $GLOBALS['wp_filter']['rest_api_init'] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			return;
		}
		foreach ( $hook->callbacks as $priority ) {
			foreach ( (array) $priority as $entry ) {
				$owner = is_array( $entry['function'] ?? null ) ? $entry['function'][0] : null;
				if ( ! is_object( $owner ) ) {
					continue;
				}
				foreach ( ( new \ReflectionObject( $owner ) )->getProperties() as $property ) {
					\_af_accessible( $property );
					$store = $property->getValue( $owner );
					if ( ! is_object( $store ) || ! in_array( \Agentimus\ConnectionStore::class, class_uses( $store ), true ) ) {
						continue;
					}
					$cache = new \ReflectionProperty( $store, 'cache' );
					\_af_accessible( $cache );
					$cache->setValue( $store, null );
				}
			}
		}
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
