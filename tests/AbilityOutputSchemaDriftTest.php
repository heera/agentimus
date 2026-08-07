<?php
/**
 * An ability must DECLARE every key its callback returns. 1.30.0 shipped the
 * counterexample: Repository::log() grew `verifyOn`/`identifyOn` for the Request
 * Log's Status column, the read-request-log ability's output schema didn't — and
 * because ability output validates with additionalProperties:false, the tool
 * rejected its own honest response for every MCP client while the admin screen
 * (same route, no schema) masked the break.
 *
 * This test pins the two ends that run without a database: the ability's
 * registered output schema, and the pure row-shaper Repository::hit_row(). The
 * top-level key list mirrors Repository::log()'s return literal — when a key is
 * added there, it must be added BOTH to the ability schema and to this list.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Activity\Repository;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	/**
	 * Each test runs in its OWN process: the capture stub below defines a global
	 * wp_register_ability(), and AdapterBootstrapTest's "unit env has no
	 * Abilities API" precondition must keep holding in the main process.
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class AbilityOutputSchemaDriftTest extends TestCase {

		/** Define the capture stub at RUN time (never at file load — see class doc). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		/** Every top-level key Repository::log() returns. Mirror of its return literal. */
		private const LOG_KEYS = array(
			'rows',
			'total',
			'perPage',
			'cursor',
			'hasMore',
			'retentionDays',
			'autoPrune',
			'maxRows',
			'verifyOn',
			'identifyOn',
		);

		private function output_schema(): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey(
				'agentimus/read-request-log',
				$GLOBALS['_af_abilities'],
				'read-request-log did not register — the capture stub or the slug moved.'
			);
			return $GLOBALS['_af_abilities']['agentimus/read-request-log']['output_schema'];
		}

		public function test_every_log_key_is_declared_in_the_output_schema() {
			$schema = $this->output_schema();

			// The failure mode only exists because unlisted keys are rejected; if this
			// flips to true one day, the declarations below become documentation only.
			$this->assertFalse(
				$schema['additionalProperties'],
				'Top-level output allows undeclared keys — this test expects the strict mode that made the 1.30.0 bug possible.'
			);

			foreach ( self::LOG_KEYS as $key ) {
				$this->assertArrayHasKey(
					$key,
					$schema['properties'],
					"Repository::log() returns `$key` but the read-request-log output schema does not declare it — the ability will reject its own response (the 1.30.0 verifyOn bug)."
				);
			}
		}

		public function test_every_row_key_is_declared_for_the_rows_items() {
			$schema = $this->output_schema();

			// hit_row() is pure — run the REAL producer on a full fixture row.
			$m = new \ReflectionMethod( Repository::class, 'hit_row' );
			\_af_accessible( $m );
			$row = $m->invoke(
				null,
				array(
					'endpoint' => 'llms.txt',
					'agent'    => 'GPTBot (OpenAI)',
					'ua'       => 'Mozilla/5.0 (compatible; GPTBot/1.1)',
					'network'  => 'openai.com',
					'verdict'  => '2',
					'signer'   => 'chatgpt.com',
					'refused'  => 1,
					'hit_at'   => '2026-07-27 10:00:00',
				)
			);

			$declared = $schema['properties']['rows']['items']['properties'];
			foreach ( array_keys( $row ) as $key ) {
				$this->assertArrayHasKey(
					$key,
					$declared,
					"hit_row() returns `$key` but the rows item schema does not declare it — invisible to MCP clients."
				);
			}
		}

		public function test_the_1_30_0_regression_keys_are_booleans() {
			$schema = $this->output_schema();
			$this->assertSame( 'boolean', $schema['properties']['verifyOn']['type'] );
			$this->assertSame( 'boolean', $schema['properties']['identifyOn']['type'] );
		}
	}
}
