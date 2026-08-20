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

		/**
		 * Every top-level key Repository::log() returns — READ OUT OF THE SOURCE,
		 * never listed here.
		 *
		 * ⛔ THIS USED TO BE A HAND-WRITTEN CONSTANT "mirroring the return literal",
		 * and that is precisely how the bug this file exists to prevent shipped a
		 * SECOND time. A mirror only catches the schema falling behind the mirror;
		 * nothing caught the mirror falling behind the code. 1.30.0 added
		 * verifyOn/identifyOn, 1.40.0 added sort/offset, and both times these tests
		 * stayed green while the ability rejected its own output in the field.
		 * Deriving the list means a key added to log() is a key this test demands.
		 */
		private function log_keys(): array {
			$src = file_get_contents( dirname( __DIR__ ) . '/inc/Activity/Repository.php' );
			$this->assertNotFalse( $src, 'Could not read Repository.php to derive the log keys.' );

			// The body of log(), then its return literal, then the keys at that level.
			$body = strstr( $src, 'public static function log( array $args = array() ) {' );
			$this->assertNotFalse( $body, 'Repository::log() moved or was renamed — this test cannot see it.' );
			$ret = strstr( $body, "\n\t\treturn array(" );
			$this->assertNotFalse( $ret, 'Repository::log() no longer ends in a `return array(` literal.' );
			$ret = substr( $ret, 0, strpos( $ret, "\n\t\t);" ) );

			preg_match_all( "/^\t\t\t'([a-zA-Z]+)'\s*=>/m", $ret, $m );
			$keys = $m[1];

			// A parse that finds nothing must FAIL, not quietly pass everything.
			$this->assertGreaterThan(
				6,
				count( $keys ),
				'Derived suspiciously few keys from Repository::log() — the literal was reformatted and this test is no longer reading it.'
			);
			return $keys;
		}

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

			foreach ( $this->log_keys() as $key ) {
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
