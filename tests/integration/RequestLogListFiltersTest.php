<?php
/**
 * read-request-log takes one value or a list — and the scalar form is untouched.
 *
 * ⛔ THE BACKWARD-COMPATIBILITY HALF IS THE POINT. Widening a declared contract is
 * how this exact tool broke twice (1.30.0 and 1.40.0), so every assertion below
 * comes in pairs: the shape an existing caller sends, and the new one.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Activity\Table;

final class RequestLogListFiltersTest extends \WP_UnitTestCase {

	/** Run the ability's own handler, exactly as the adapter would. */
	private function call( array $input ) {
		$ability = wp_get_ability( 'agentimus/read-request-log' );
		$this->assertNotNull( $ability, 'The ability is registered.' );
		return $ability->execute( $input );
	}

	public function set_up() {
		parent::set_up();
		// The ability is behind the manage gate — run it as someone who has it.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Table::name() );
	}

	private function seed( $agent, $endpoint, $verdict, $refused = 0 ) {
		global $wpdb;
		$wpdb->insert(
			Table::name(),
			array(
				'endpoint' => $endpoint,
				'agent'    => $agent,
				'ua'       => $agent . '/1.0',
				'verdict'  => $verdict,
				'refused'  => $refused,
				'hit_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}

	public function test_a_single_value_still_filters_exactly_as_before() {
		$this->seed( 'GPTBot', 'llms.txt', 1 );
		$this->seed( 'ClaudeBot', 'robots.txt', 2 );

		$one = $this->call( array( 'agent' => 'GPTBot' ) );
		$this->assertIsArray( $one );
		$names = wp_list_pluck( $one['rows'], 'agent' );
		$this->assertContains( 'GPTBot', $names );
		$this->assertNotContains( 'ClaudeBot', $names, 'A scalar still narrows to one agent.' );
	}

	public function test_a_list_widens_to_every_value_in_it() {
		$this->seed( 'GPTBot', 'llms.txt', 1 );
		$this->seed( 'ClaudeBot', 'robots.txt', 2 );
		$this->seed( 'Nobody', 'sitemap', 0 );

		$many  = $this->call( array( 'agent' => array( 'GPTBot', 'ClaudeBot' ) ) );
		$names = array_unique( wp_list_pluck( $many['rows'], 'agent' ) );
		sort( $names );
		$this->assertSame( array( 'ClaudeBot', 'GPTBot' ), $names, 'Both asked-for agents, and nothing else.' );
	}

	public function test_an_empty_list_narrows_nothing_and_never_errors() {
		$this->seed( 'GPTBot', 'llms.txt', 1 );
		$this->seed( 'ClaudeBot', 'robots.txt', 2 );

		$all = $this->call( array( 'agent' => array() ) );
		$this->assertIsArray( $all, '`IN ()` is a MySQL syntax error — an empty pick must mean NO filter.' );
		$this->assertGreaterThanOrEqual( 2, count( $all['rows'] ) );
	}

	public function test_the_verdict_takes_an_int_a_list_and_the_refused_outcome() {
		$this->seed( 'GPTBot', 'llms.txt', 1 );
		$this->seed( 'FakeBot', 'llms.txt', 2 );
		$this->seed( 'DoorBot', 'llms.txt', 0, 1 );

		$spoofed = $this->call( array( 'verdict' => 2 ) );
		$this->assertSame( array( 'FakeBot' ), array_unique( wp_list_pluck( $spoofed['rows'], 'agent' ) ),
			'An integer verdict behaves as it always did.' );

		$mixed = $this->call( array( 'verdict' => array( 2, 'refused' ) ) );
		$names = array_unique( wp_list_pluck( $mixed['rows'], 'agent' ) );
		sort( $names );
		$this->assertSame( array( 'DoorBot', 'FakeBot' ), $names,
			'"refused" is an outcome in another column and OR-s with a verdict.' );
	}

	public function test_a_value_outside_the_set_narrows_nothing_rather_than_erroring() {
		$this->seed( 'GPTBot', 'llms.txt', 1 );
		$out = $this->call( array( 'verdict' => 99 ) );
		$this->assertIsArray( $out );
		$this->assertNotEmpty( $out['rows'], 'A junk verdict is dropped, not turned into an impossible query.' );
	}
}
