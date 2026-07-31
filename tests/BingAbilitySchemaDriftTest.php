<?php
/**
 * The read-search-visibility ability must DECLARE every key Summary::build()
 * can return — ability output validates with additionalProperties:false, so
 * an undeclared key makes the tool reject its own honest response (the
 * 1.30.0 verifyOn lesson, guarded the same way for the edge and log twins).
 *
 * The connected payload needs a database, so the key lists here are MIRRORS
 * of Summary::build()'s return literals.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	/**
	 * Separate processes for the same reason as the other drift tests: the
	 * capture stub defines a global wp_register_ability().
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class BingAbilitySchemaDriftTest extends TestCase {

		/** Define the capture stub at RUN time (never at file load). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		/** Mirror of Summary::build()'s connected return literal. */
		private const SUMMARY_KEYS = array(
			'connected',
			'siteUrl',
			'hasMsvalidate',
			'connectedAt',
			'lastPollAt',
			'lastError',
			'days',
			'totals',
			'trend',
			'conflicts',
		);

		/** Mirror of the totals literal. */
		private const TOTAL_KEYS = array( 'inIndex', 'crawledLatest', 'crawlErrors', 'blockedByRobots' );

		/** Mirror of one trend row. */
		private const TREND_KEYS = array( 'date', 'inIndex', 'crawled', 'ok', 'redirects', 'clientErrors', 'serverErrors', 'blockedByRobots' );

		/** Mirror of one conflict row (after Summary attaches url). */
		private const CONFLICT_KEYS = array( 'id', 'level', 'title', 'body', 'url' );

		private function registered(): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey(
				'agentimus/read-search-visibility',
				$GLOBALS['_af_abilities'],
				'read-search-visibility did not register — the capture stub or the slug moved.'
			);
			return $GLOBALS['_af_abilities']['agentimus/read-search-visibility'];
		}

		public function test_the_tool_is_on_the_mcp_roster() {
			// The 1.30.0 suggest-internal-links lesson: a registered ability the
			// roster never lists is a tool the release notes describe but the
			// server does not serve.
			$this->assertContains(
				'agentimus/read-search-visibility',
				( new Registrar( new Settings() ) )->mcp_abilities()
			);
		}

		public function test_every_summary_key_is_declared_in_the_output_schema() {
			$schema = $this->registered()['output_schema'];

			$this->assertFalse(
				$schema['additionalProperties'],
				'Top-level output allows undeclared keys — this test expects the strict mode that made the 1.30.0 bug possible.'
			);
			foreach ( self::SUMMARY_KEYS as $key ) {
				$this->assertArrayHasKey(
					$key,
					$schema['properties'],
					"Summary::build() returns `$key` but the output schema does not declare it — the ability will reject its own response."
				);
			}
		}

		public function test_totals_trend_and_conflict_keys_are_declared() {
			$schema = $this->registered()['output_schema'];

			$totals = $schema['properties']['totals']['properties'];
			foreach ( self::TOTAL_KEYS as $key ) {
				$this->assertArrayHasKey( $key, $totals, "totals carry `$key` but the schema does not declare it." );
			}

			$trend = $schema['properties']['trend']['items']['properties'];
			foreach ( self::TREND_KEYS as $key ) {
				$this->assertArrayHasKey( $key, $trend, "trend rows carry `$key` but the schema does not declare it." );
			}

			$conflict = $schema['properties']['conflicts']['items']['properties'];
			foreach ( self::CONFLICT_KEYS as $key ) {
				$this->assertArrayHasKey( $key, $conflict, "conflicts carry `$key` but the schema does not declare it." );
			}
		}
	}
}
