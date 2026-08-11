<?php
/**
 * read-audience must DECLARE every key its callback returns.
 *
 * Ability output validates with additionalProperties:false, so an undeclared key
 * doesn't get quietly dropped — the tool rejects its own honest response for
 * every MCP client, while the dashboard's audience card (same producer, no
 * schema) keeps working and hides the break (the 1.30.0 verifyOn lesson,
 * {@see AbilityOutputSchemaDriftTest}).
 *
 * It runs the REAL producer — Audience::from_stats() on a stats fixture shaped
 * like Activity\Repository::stats() — rather than mirroring its key lists by
 * hand, so a new key in Audience cannot pass by being copied here too. The
 * disconnected analytics half deliberately carries its FULL key set (the class
 * promises one shape either way), so no GA4 fixture is needed to cover it.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Audience;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	/**
	 * Each test runs in its OWN process: the capture stub defines a global
	 * wp_register_ability(), and AdapterBootstrapTest's "unit env has no
	 * Abilities API" precondition must keep holding in the main process.
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class AudienceAbilitySchemaDriftTest extends TestCase {

		/** Define the capture stub at RUN time (never at file load — see class doc). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		/** The registered output schema for read-audience. */
		private function output_schema(): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey(
				'agentimus/read-audience',
				$GLOBALS['_af_abilities'],
				'read-audience did not register — the capture stub or the slug moved.'
			);
			return $GLOBALS['_af_abilities']['agentimus/read-audience']['output_schema'];
		}

		/**
		 * Assert every key an actual payload carries is declared.
		 *
		 * @param array  $declared Schema properties map.
		 * @param array  $actual   A real payload (or row).
		 * @param string $where    Human name for the failure message.
		 * @return void
		 */
		private function assert_declared( array $declared, array $actual, string $where ): void {
			foreach ( array_keys( $actual ) as $key ) {
				$this->assertArrayHasKey(
					$key,
					$declared,
					"$where returns `$key` but the output schema does not declare it — with additionalProperties:false the ability rejects its own response."
				);
			}
		}

		/** A stats payload shaped like Activity\Repository::stats(), rich enough that every branch emits its rows. */
		private function stats(): array {
			return array(
				'enabled'   => true,
				'window'    => 30,
				'totals'    => array( 'today' => 2, 'week' => 19, 'month' => 166, 'agents' => 18 ),
				'threats'   => array( 'counts' => array( 'new' => 1, 'heavy' => 0, 'spoof' => 3 ) ),
				'referrals' => array(
					'enabled'     => true,
					'sourceCount' => 2,
					'totals'      => array( 'today' => 1, 'window' => 59 ),
					'bySource'    => array(
						array( 'source' => 'ChatGPT', 'hits' => 40 ),
						array( 'source' => 'Perplexity', 'hits' => 19 ),
					),
					'topPages'    => array(
						array( 'path' => '/blog/hello/', 'hits' => 12 ),
					),
				),
			);
		}

		public function test_the_audience_payload_is_fully_declared() {
			$schema = $this->output_schema();

			$this->assertFalse(
				$schema['additionalProperties'],
				'Top-level output allows undeclared keys — this test expects the strict mode that makes drift fatal.'
			);

			$out   = Audience::from_stats( $this->stats() );
			$props = $schema['properties'];

			$this->assert_declared( $props, $out, 'Audience::from_stats()' );
			$this->assert_declared( $props['people']['properties'], $out['people'], 'The people half' );
			$this->assert_declared( $props['people']['properties']['all']['properties'], $out['people']['all'], 'The analytics half' );
			$this->assert_declared( $props['people']['properties']['search']['properties'], $out['people']['search'], 'The search half' );
			$this->assert_declared( $props['people']['properties']['ai']['properties'], $out['people']['ai'], 'The AI-referral half' );
			$this->assert_declared( $props['machines']['properties'], $out['machines'], 'The machines half' );

			// The row shapes the fixture actually produced — vacuous checks lie.
			$this->assertNotEmpty( $out['people']['ai']['top'] );
			$this->assert_declared( $props['people']['properties']['ai']['properties']['top']['items']['properties'], $out['people']['ai']['top'][0], 'An AI leaderboard row' );
			$this->assertNotEmpty( $out['people']['ai']['pages'] );
			$this->assert_declared( $props['people']['properties']['ai']['properties']['pages']['items']['properties'], $out['people']['ai']['pages'][0], 'An AI landing-page row' );
			$this->assertNotEmpty( $out['limits'], 'Fixture produced no limits — the caveat drift check would pass vacuously.' );
			$this->assert_declared( $props['limits']['items']['properties'], $out['limits'][0], 'A limits row' );
		}

		public function test_the_tool_is_exposed_on_the_mcp_server() {
			// Registered is not the same as reachable: an ability the MCP list
			// forgets exists only inside wp-admin (see the mcp_abilities() comment).
			$tools = ( new Registrar( new Settings() ) )->mcp_abilities();
			$this->assertContains( 'agentimus/read-audience', $tools );
		}
	}
}
