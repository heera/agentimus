<?php
/**
 * Every key read-search-opportunities RETURNS must be one it DECLARES.
 *
 * ⛔⛔ CAUGHT LIVE, ON A REAL SITE, WITH 2,124 TESTS GREEN. Ability output is
 * whitelisted to the declared properties, so an undeclared key does not raise an
 * error — it VANISHES on the way to the client. The tool answers, the report
 * looks whole, and neither the agent nor the owner can tell anything is missing.
 *
 * Three keys were lost that way the day the noise filter grew:
 *   `noise.probeShare`        — so nothing supported the "these were not people" claim
 *   `noise.examples[].kind`   — so no agent could tell WHY a search was dropped
 *   `noise.dismissed`         — so no agent could discover the owner's set-aside
 *                               ledger, and therefore none could ever put one back
 *
 * ⚠️ THE STRIPPING ITSELF CANNOT BE REPRODUCED HERE: it happens in the MCP
 * adapter, past `execute()`. What CAN be pinned — and is the whole cause — is
 * the disagreement between what the callback returns and what the ability
 * declares. Both derived from the code, neither hand-listed, because a
 * hand-kept list only ever catches the schema falling behind the list.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Search\Noise;
use Agentimus\Search\Table;
use Agentimus\Settings;

final class SearchOpportunitiesPayloadDbTest extends DbTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Table::VERSION_OPTION );
		Table::install();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		( new \Agentimus\Google\Settings() )->connect( '{"type":"service_account"}', 'bot@example.com', 'sc-domain:example.com' );
		// ⚠️ These abilities gate on manage_options — the same capability the
		// owner's own click needs. Without a user holding it, execute() answers a
		// permission WP_Error and the test proves nothing about the payload.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Noise::flush();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Table::name() ); // phpcs:ignore WordPress.DB
		$s   = new Settings();
		$all = $s->stored();
		$all['search_dismissed'] = array();
		$s->update( $all );
		Noise::flush();
		parent::tear_down();
	}

	private function row( $query, $impr = 40, $pos = 9.0 ) {
		return array(
			'query'       => $query,
			'page_url'    => get_permalink( 1 ),
			'page_id'     => 1,
			'clicks'      => 0,
			'impressions' => $impr,
			'position'    => $pos,
			'range_start' => '2026-07-01',
			'range_end'   => '2026-07-28',
		);
	}

	/**
	 * A site carrying one of EVERY kind, so no branch of the noise block is left
	 * unbuilt — an empty branch declares nothing and proves nothing.
	 */
	private function seed_every_kind() {
		Table::replace( 'google', array(
			$this->row( 'llms txt wordpress plugin', 400, 6.0 ),
			$this->row( 'site:example.test allowed_classes', 30 ),
			$this->row( 'https://spam.example/thing', 20 ),
			$this->row( str_repeat( 'a very long pasted prompt ', 12 ), 10 ),
			$this->row( 'yes or no', 15 ),
		) );
		// …and one the OWNER set aside, which no rule would have caught.
		$s   = new Settings();
		$all = $s->stored();
		$all['search_dismissed'] = array( 'yes or no' );
		$s->update( $all );
		Noise::flush();
	}

	private function ability() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'No Abilities API on this core.' );
		}
		$ability = wp_get_ability( 'agentimus/read-search-opportunities' );
		if ( ! $ability ) {
			$this->markTestSkipped( 'read-search-opportunities is not registered on this core.' );
		}
		return $ability;
	}

	public function test_every_key_the_ability_returns_is_one_it_declares() {
		$this->seed_every_kind();
		$ability = $this->ability();
		$schema  = $ability->get_output_schema();
		$out     = $ability->execute( array() );

		$this->assertIsArray( $out );
		$this->assertFalse( $schema['additionalProperties'], 'This test assumes the strict mode that makes an undeclared key vanish.' );

		foreach ( array_keys( $out ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$schema['properties'],
				"read-search-opportunities returns `$key` and does not declare it — it is silently dropped before any agent sees it."
			);
		}
	}

	/** ⛔ Nested keys are where a whitelist bites unnoticed: the object still arrives. */
	public function test_every_key_inside_the_noise_block_is_declared() {
		$this->seed_every_kind();
		$ability  = $this->ability();
		$declared = $ability->get_output_schema()['properties']['noise']['properties'];
		$noise    = $ability->execute( array() )['noise'];

		$this->assertNotSame( array(), $noise['examples'], 'FIXTURE: the noise block must be populated to be worth testing.' );

		foreach ( array_keys( $noise ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$declared,
				"The noise block returns `$key` and the ability does not declare it — agents will never see it."
			);
		}
		foreach ( array_keys( $noise['examples'][0] ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$declared['examples']['items']['properties'],
				"A noise example carries `$key` and the ability does not declare it."
			);
		}
	}

	/**
	 * ⭐ THE THREE THAT WERE ACTUALLY LOST, by name — and with real values behind
	 * them, so a declaration that exists but is never populated cannot pass.
	 */
	public function test_an_agent_can_see_why_each_search_was_dropped_and_what_it_can_undo() {
		$this->seed_every_kind();
		$noise = $this->ability()->execute( array() )['noise'];

		$kinds = array();
		foreach ( $noise['examples'] as $ex ) {
			$this->assertArrayHasKey( 'kind', $ex, '⛔ Without this an agent cannot tell an operator probe from the owner’s own decision.' );
			$kinds[ $ex['kind'] ] = true;
		}
		foreach ( array( Noise::OPERATOR, Noise::ADDRESS, Noise::PASTE, Noise::DISMISSED ) as $kind ) {
			$this->assertArrayHasKey( $kind, $kinds, "No example carried kind `$kind` — the fixture or the reason is missing." );
		}

		$this->assertArrayHasKey( 'probeShare', $noise );
		$this->assertLessThanOrEqual(
			$noise['share'],
			$noise['probeShare'],
			'⛔ The operator slice can never exceed everything left out — and only IT may be called machine traffic.'
		);

		$this->assertSame(
			array( 'yes or no' ),
			array_column( $noise['dismissed'], 'query' ),
			'⛔ An agent that did not do the dismissing must still be able to find it, or nobody can put it back.'
		);
	}
}
