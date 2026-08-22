<?php
/**
 * The base for a read-modify-write must never be the filtered read.
 *
 * ⛔ THE BUG THIS PINS. Settings::all() runs through the `agentimus_settings`
 * read filter. Thirteen writers used it as the base for
 * `$all = all(); $all[x] = y; update($all)` — so on a site where a filter forces
 * a value at runtime, the very first save wrote that override into the stored
 * option permanently. The owner never chose it, and removing the filter later
 * does not undo it.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsStoredTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		\remove_all_filters( 'agentimus_settings' );
	}

	protected function tearDown(): void {
		\_af_reset_options();
		\remove_all_filters( 'agentimus_settings' );
	}

	/** Force a runtime override the way a hosting mu-plugin would. */
	private function filterForces( $key, $value ) {
		\add_filter(
			'agentimus_settings',
			static function ( $all ) use ( $key, $value ) {
				$all[ $key ] = $value;
				return $all;
			}
		);
	}

	public function test_all_shows_the_override_and_stored_does_not() {
		$s = new Settings();
		$this->filterForces( 'enable_llms_txt', false );

		$this->assertFalse( $s->all()['enable_llms_txt'], 'all() must reflect the runtime filter.' );
		$this->assertNotSame(
			false,
			$s->stored()['enable_llms_txt'],
			'stored() must ignore the filter — it is what a save writes back.'
		);
	}

	/**
	 * ⛔ THE ACTUAL DAMAGE, not just the difference: base a save on all() and the
	 * override becomes the owner's saved choice for good.
	 */
	public function test_a_save_built_on_stored_never_bakes_the_override_in() {
		$s = new Settings();
		$before = $s->stored()['enable_llms_txt'];

		$this->filterForces( 'enable_llms_txt', false );

		// A writer doing the right thing: read stored, change something else, save.
		$all                 = $s->stored();
		$all['enable_robots'] = false;
		$s->update( $all );

		\remove_all_filters( 'agentimus_settings' );

		$this->assertSame(
			$before,
			$s->stored()['enable_llms_txt'],
			'The filtered value leaked into the saved option — this is the bug.'
		);
	}

	/**
	 * ⚠️ The hand-rolled workaround Fixer and Triage used was a shallow
	 * wp_parse_args, which drops sub-keys a later version adds to a nested
	 * setting. stored() must deep-merge, exactly as all() always did.
	 */
	public function test_stored_deep_merges_nested_settings() {
		$s        = new Settings();
		$defaults = $s->defaults();

		$nested = null;
		foreach ( $defaults as $k => $v ) {
			if ( is_array( $v ) && array_keys( $v ) !== range( 0, count( $v ) - 1 ) && count( $v ) > 1 ) {
				$nested = $k;
				break;
			}
		}
		if ( null === $nested ) {
			$this->markTestSkipped( 'No nested associative default to test.' );
		}

		// Store the nested setting with ONE of its sub-keys, as an older version would have.
		$keys = array_keys( $defaults[ $nested ] );
		\update_option( Settings::OPTION, array( $nested => array( $keys[0] => $defaults[ $nested ][ $keys[0] ] ) ) );

		$this->assertArrayHasKey(
			$keys[1],
			$s->stored()[ $nested ],
			'A sub-key added in a later version was dropped — the shallow-merge bug.'
		);
	}
}
