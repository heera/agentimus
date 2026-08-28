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

	/**
	 * Run the ability's own handler, exactly as the adapter would.
	 *
	 * ⛔ SKIPS BELOW THE ABILITIES API. WP 6.0 has no wp_get_ability(), and the
	 * whole MCP surface self-gates there — so on that floor this file has nothing
	 * to assert rather than something to fail. The same guard every other ability
	 * test in this directory uses; CI's PHP 7.4 / WP 6.0 job is what it is for,
	 * and it is the job that caught this one missing.
	 */
	private function call( array $input ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+); the feature self-gates below that.' );
		}
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

	private function seed( $agent, $endpoint, $verdict, $refused = 0, $signer = '' ) {
		global $wpdb;
		$wpdb->insert(
			Table::name(),
			array(
				'endpoint' => $endpoint,
				'agent'    => $agent,
				'ua'       => $agent . '/1.0',
				'verdict'  => $verdict,
				'refused'  => $refused,
				'signer'   => $signer,
				'hit_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
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

	/* -- The signature filter, through the ability an assistant actually calls -- */

	/**
	 * ⭐ An agent asked "has any AI signed its requests to this site?" could not ask
	 * it before: the rows carried a `signer`, nothing could filter on one, and no
	 * list of exact names can name an operator nobody has seen yet. `*` asks it.
	 */
	public function test_the_wildcard_returns_only_the_requests_that_carried_a_signature() {
		$this->seed( 'Googlebot', 'sitemap', 1 );
		$this->seed( 'OpenAI agent', 'llms.txt', 1, 0, 'OpenAI agent' );
		$this->seed( 'Stranger', 'llms.txt', 0, 0, 'agents.acme.example' );

		$signed = $this->call( array( 'signer' => '*' ) );

		$names = array_unique( wp_list_pluck( $signed['rows'], 'agent' ) );
		sort( $names );
		$this->assertSame( array( 'OpenAI agent', 'Stranger' ), $names );
	}

	public function test_a_signer_takes_one_value_or_a_list_like_every_other_filter() {
		$this->seed( 'OpenAI agent', 'llms.txt', 1, 0, 'OpenAI agent' );
		$this->seed( 'Stranger', 'llms.txt', 0, 0, 'agents.acme.example' );

		$one = $this->call( array( 'signer' => 'agents.acme.example' ) );
		$this->assertSame( array( 'Stranger' ), array_unique( wp_list_pluck( $one['rows'], 'agent' ) ) );

		$both = $this->call( array( 'signer' => array( 'OpenAI agent', 'agents.acme.example' ) ) );
		$this->assertSame( 2, $both['total'] );
	}

	public function test_an_empty_signer_list_narrows_nothing_and_never_errors() {
		$this->seed( 'Googlebot', 'sitemap', 1 );

		$out = $this->call( array( 'signer' => array() ) );
		$this->assertIsArray( $out );
		$this->assertNotEmpty( $out['rows'], 'A cleared picker is no filter, not `IN ()`.' );
	}

	/**
	 * ⛔ The declared contract is what the adapter validates against, and widening
	 * one without declaring it is how this exact tool broke in 1.30.0 and 1.40.0.
	 */
	public function test_the_signer_filter_is_declared_on_the_ability_not_merely_accepted() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Needs the Abilities API (WP 6.9+).' );
		}
		$schema = wp_get_ability( 'agentimus/read-request-log' )->get_input_schema();
		$this->assertArrayHasKey( 'signer', $schema['properties'] );
	}
}
