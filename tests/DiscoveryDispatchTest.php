<?php
/**
 * Discovery registration dispatch — PER-CALLBACK fault isolation (the fix for
 * the coarse per-hook guard, where one throwing provider aborted `do_action()`
 * and silently wiped every provider registered at a later priority).
 *
 * Registry::fire() dispatches the hook's callbacks itself, so these tests drive
 * `$GLOBALS['wp_filter']` directly in WP_Hook shape (an object with a
 * `callbacks` array-by-priority) and in the legacy plain-array shape, and
 * assert the do_action() semantics providers can observe survive: priority
 * order, mid-run adds and removes, accepted_args 0, current_action() and
 * did_action() bookkeeping.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Registry;
use PHPUnit\Framework\TestCase;

final class DiscoveryDispatchTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		\_af_reset_registry();
		unset( $GLOBALS['wp_filter'], $GLOBALS['wp_actions'], $GLOBALS['wp_current_filter'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_filter'], $GLOBALS['wp_actions'], $GLOBALS['wp_current_filter'] );
		\_af_reset_registry();
		\_af_reset_options();
	}

	/**
	 * Register callbacks on the canonical hook in WP_Hook shape.
	 *
	 * @param array $by_priority priority => list of callables (or [callable, accepted_args]).
	 */
	private function hook( array $by_priority ) {
		$callbacks = array();
		foreach ( $by_priority as $priority => $list ) {
			foreach ( $list as $i => $cb ) {
				$accepted = 1;
				if ( is_array( $cb ) && isset( $cb['function'] ) ) {
					$accepted = $cb['accepted_args'];
					$cb       = $cb['function'];
				}
				$callbacks[ $priority ][ 'cb' . $priority . '_' . $i ] = array(
					'function'      => $cb,
					'accepted_args' => $accepted,
				);
			}
		}
		$hook_obj            = new \stdClass();
		$hook_obj->callbacks = $callbacks;

		$GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ] = $hook_obj;
	}

	public function test_a_thrower_no_longer_wipes_later_priority_providers() {
		// The real incident: a provider throwing at priority 6 used to abort the
		// dispatch, so the site's own priority-10 providers registered NOTHING.
		$this->hook(
			array(
				6  => array(
					static function () {
						throw new \RuntimeException( 'demo provider exploded' );
					},
				),
				10 => array(
					static function ( Registry $registry ) {
						$registry->register( array( 'id' => 'shop', 'title' => 'Shop', 'type' => 'commerce' ) );
					},
				),
			)
		);

		$reg = Registry::instance()->collect();

		$this->assertArrayHasKey( 'shop', $reg->resources(), 'the healthy later provider must still register' );
		$errors = array_values( array_filter( $reg->notices(), static function ( $n ) { return 'error' === $n['level']; } ) );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'demo provider exploded', $errors[0]['message'] );
	}

	public function test_a_thrower_does_not_stop_its_own_priority_bucket_either() {
		$this->hook(
			array(
				10 => array(
					static function () {
						throw new \RuntimeException( 'first in bucket dies' );
					},
					static function ( Registry $registry ) {
						$registry->register( array( 'id' => 'docs', 'title' => 'Docs', 'type' => 'content' ) );
					},
				),
			)
		);

		$reg = Registry::instance()->collect();

		$this->assertArrayHasKey( 'docs', $reg->resources() );
	}

	public function test_every_thrower_is_reported_individually() {
		$this->hook(
			array(
				5  => array(
					static function () {
						throw new \RuntimeException( 'first' );
					},
				),
				10 => array(
					static function () {
						throw new \RuntimeException( 'second' );
					},
					static function ( Registry $registry ) {
						$registry->register( array( 'id' => 'shop', 'title' => 'Shop', 'type' => 'commerce' ) );
					},
				),
			)
		);

		$reg    = Registry::instance()->collect();
		$errors = array_values( array_filter( $reg->notices(), static function ( $n ) { return 'error' === $n['level']; } ) );

		$this->assertCount( 2, $errors, 'one notice per failing provider, not one for the hook' );
		$this->assertArrayHasKey( 'shop', $reg->resources() );
	}

	public function test_failure_notice_names_the_callable() {
		$GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ] = new \stdClass();
		$GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ]->callbacks = array(
			10 => array(
				'named' => array(
					'function'      => array( Broken_Provider::class, 'explode' ),
					'accepted_args' => 1,
				),
			),
		);

		$reg    = Registry::instance()->collect();
		$errors = array_values( array_filter( $reg->notices(), static function ( $n ) { return 'error' === $n['level']; } ) );

		$this->assertStringContainsString( 'Broken_Provider::explode', $errors[0]['message'], 'the owner must learn WHICH provider broke' );
	}

	public function test_a_provider_added_during_the_run_at_a_later_priority_still_runs() {
		// Core behaves this way, so ours must too: registering a provider from
		// inside a provider is legitimate (a library lazy-hooking its modules).
		$this->hook(
			array(
				5 => array(
					static function () {
						$GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ]->callbacks[20]['late'] = array(
							'function'      => static function ( Registry $registry ) {
								$registry->register( array( 'id' => 'late', 'title' => 'Late', 'type' => 'content' ) );
							},
							'accepted_args' => 1,
						);
					},
				),
			)
		);

		$reg = Registry::instance()->collect();

		$this->assertArrayHasKey( 'late', $reg->resources() );
	}

	public function test_a_provider_added_at_an_already_passed_priority_does_not_run() {
		// Also core behavior: once the dispatcher moved past priority 5, an add
		// at priority 2 is too late for THIS firing.
		$this->hook(
			array(
				5 => array(
					static function () {
						$GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ]->callbacks[2]['early'] = array(
							'function'      => static function ( Registry $registry ) {
								$registry->register( array( 'id' => 'early', 'title' => 'Early', 'type' => 'content' ) );
							},
							'accepted_args' => 1,
						);
					},
				),
			)
		);

		$reg = Registry::instance()->collect();

		$this->assertArrayNotHasKey( 'early', $reg->resources() );
	}

	public function test_a_provider_removed_during_the_run_does_not_run() {
		$this->hook(
			array(
				5  => array(
					static function () {
						unset( $GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ]->callbacks[10] );
					},
				),
				10 => array(
					static function ( Registry $registry ) {
						$registry->register( array( 'id' => 'gone', 'title' => 'Gone', 'type' => 'content' ) );
					},
				),
			)
		);

		$reg = Registry::instance()->collect();

		$this->assertArrayNotHasKey( 'gone', $reg->resources(), 'the snapshot must not resurrect a removed callback' );
	}

	public function test_accepted_args_zero_is_called_without_the_registry() {
		$seen = 'untouched';
		$this->hook(
			array(
				10 => array(
					array(
						'function'      => static function () use ( &$seen ) {
							$seen = func_num_args();
						},
						'accepted_args' => 0,
					),
				),
			)
		);

		Registry::instance()->collect();

		$this->assertSame( 0, $seen, 'accepted_args 0 means zero args, exactly like do_action()' );
	}

	public function test_did_action_and_current_filter_bookkeeping_survive() {
		$during = null;
		$this->hook(
			array(
				10 => array(
					static function () use ( &$during ) {
						$during = end( $GLOBALS['wp_current_filter'] );
					},
				),
			)
		);

		Registry::instance()->collect();

		$this->assertSame( AGENTIMUS_CANONICAL_HOOK, $during, 'current_action() must name the hook while providers run' );
		$this->assertSame( 1, $GLOBALS['wp_actions'][ AGENTIMUS_CANONICAL_HOOK ], 'did_action() must count the firing' );
		$this->assertSame( array(), $GLOBALS['wp_current_filter'], 'the filter stack must be popped afterwards' );
	}

	public function test_legacy_plain_array_filter_shape_is_dispatched_too() {
		$GLOBALS['wp_filter'][ AGENTIMUS_CANONICAL_HOOK ] = array(
			6  => array(
				'boom' => array(
					'function'      => static function () {
						throw new \RuntimeException( 'legacy boom' );
					},
					'accepted_args' => 1,
				),
			),
			10 => array(
				'ok' => array(
					'function'      => static function ( Registry $registry ) {
						$registry->register( array( 'id' => 'legacy', 'title' => 'Legacy', 'type' => 'content' ) );
					},
					'accepted_args' => 1,
				),
			),
		);

		$reg = Registry::instance()->collect();

		$this->assertArrayHasKey( 'legacy', $reg->resources() );
	}

	public function test_no_registered_providers_is_a_clean_noop() {
		$reg = Registry::instance()->collect();

		$this->assertSame( array(), $reg->resources() );
		$this->assertSame( array(), $reg->notices() );
	}
}

/** A named class so the failure notice has something to name. */
final class Broken_Provider {
	public static function explode() {
		throw new \RuntimeException( 'named provider exploded' );
	}
}
