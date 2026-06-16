<?php
/**
 * Public global facade for the discovery standard.
 *
 * This file is required eagerly (not via the namespaced autoloader) so the
 * `Agentify_Discovery` class name is available to third-party plugins regardless of
 * load order. It is a thin, dependency-free queue: calls made before the
 * registry has run are buffered and drained during collection, so authors can
 * register from anywhere without worrying about timing.
 *
 * Authors have two equivalent options:
 *
 *   // 1. The action hook — zero hard dependency, no guard needed. If no
 *   //    WP_Discovery engine is active the action simply never fires.
 *   add_action( 'wp_discovery_register', function ( $registry ) {
 *       $registry->register( [ 'id' => 'acme', 'title' => 'Acme', 'type' => 'commerce' ] );
 *   } );
 *
 *   // 2. The facade — guard with class_exists() since the call is direct.
 *   if ( class_exists( 'Agentify_Discovery' ) ) {
 *       Agentify_Discovery::register( [ 'id' => 'acme', 'title' => 'Acme', 'type' => 'commerce' ] );
 *   }
 *
 * @package Agentify
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Agentify_Discovery' ) ) {

	/**
	 * Convenience facade over the Agentify discovery registry. Named without
	 * the reserved `WP_` prefix so it cannot collide with a future core class.
	 */
	final class Agentify_Discovery {

		/** @var array<int,array> Buffered registrations awaiting collection. */
		private static $queue = array();

		/**
		 * Register a discovery resource. Buffered until the registry collects.
		 *
		 * @param array $resource Resource definition (see the spec / Resource).
		 * @return void
		 */
		public static function register( $resource ) {
			if ( is_array( $resource ) ) {
				self::$queue[] = $resource;
			}
		}

		/**
		 * Drain and return the queued registrations. Called once by the Registry
		 * during collection; not part of the public author API.
		 *
		 * @return array<int,array>
		 */
		public static function drain() {
			$queued        = self::$queue;
			self::$queue = array();
			return $queued;
		}
	}
}
