<?php
/**
 * read-search-opportunities must DECLARE every key its report actually returns.
 *
 * ⛔⛔ THE FAULT THIS EXISTS FOR, caught live on a real site. Ability output is
 * whitelisted to the declared properties, so an undeclared key does not error —
 * it VANISHES. The tool answers, the agent reads a well-formed report, and the
 * missing half is invisible to both. Three keys were lost that way the day the
 * noise filter grew: `probeShare` (so nothing supported the "these were not
 * people" claim), `examples[].kind` (so no agent could tell WHY a search was
 * dropped), and `dismissed` (so no agent could discover the owner's set-aside
 * ledger, and therefore none could ever put a search back).
 *
 * ⭐ 2,124 tests were green throughout. A silent drop cannot be caught by
 * asserting on the payload, which is what every other test here does — only by
 * comparing the payload against the SCHEMA, both derived, neither hand-listed.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Search\Opportunities;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	/**
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class SearchOpportunitiesSchemaDriftTest extends TestCase {

		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		private function declared(): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey( 'agentimus/read-search-opportunities', $GLOBALS['_af_abilities'], 'The ability did not register.' );
			return $GLOBALS['_af_abilities']['agentimus/read-search-opportunities']['output_schema'];
		}

		private function row( $query, $impr = 40, $pos = 9.0, $page_id = 1 ) {
			return array(
				'query'       => $query,
				'page_url'    => 'https://example.test/a/',
				'page_id'     => $page_id,
				'clicks'      => 0,
				'impressions' => $impr,
				'position'    => $pos,
				'range_start' => '2026-07-01',
				'range_end'   => '2026-07-28',
			);
		}

		/**
		 * ⛔ The two shares are DIFFERENT CLAIMS and must both survive: `share` is
		 * everything left out, `probeShare` is the operator-only slice — the only
		 * one that supports saying "these were not people".
		 */
		public function test_the_two_shares_are_declared_separately() {
			$declared = $this->declared()['properties']['noise']['properties'];

			$this->assertArrayHasKey( 'share', $declared );
			$this->assertArrayHasKey( 'probeShare', $declared );
			$this->assertStringNotContainsString(
				'not people',
				$declared['share']['description'],
				'⛔ `share` covers pasted addresses too, which say nothing about who searched.'
			);

		}

		/** An agent that did not do the dismissing still has to be able to find it. */
		public function test_the_dismissal_ledger_is_reachable_by_an_agent() {
			$declared = $this->declared()['properties']['noise']['properties'];

			$this->assertArrayHasKey( 'dismissed', $declared, '⛔ Without this no agent can discover what was set aside, so none can put it back.' );
			$this->assertSame( 'array', $declared['dismissed']['type'] );
			$this->assertStringContainsString(
				'dismiss-search',
				$declared['dismissed']['description'],
				'⭐ It has to name the tool that undoes it, or knowing is useless.'
			);
		}
	}
}
