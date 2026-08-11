<?php
/**
 * read-findings must DECLARE every key its callback returns.
 *
 * Ability output validates with additionalProperties:false, so an undeclared key
 * doesn't get quietly dropped — the tool rejects its own honest response for
 * every MCP client, while the Findings screen (same aggregator, no schema)
 * keeps working and hides the break. 1.30.0 shipped exactly that bug with
 * read-request-log's verifyOn ({@see AbilityOutputSchemaDriftTest}); this file
 * is the same guard for the front door's own tool.
 *
 * It runs the REAL producers — Findings::all() and, through reflection, the one
 * row builder every source funnels through — rather than mirroring their key
 * lists by hand, so a new key in Findings cannot pass by being copied here too.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Findings;
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
	final class FindingsAbilitySchemaDriftTest extends TestCase {

		/** Define the capture stub at RUN time (never at file load — see class doc). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		/** The registered output schema for read-findings. */
		private function output_schema(): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey(
				'agentimus/read-findings',
				$GLOBALS['_af_abilities'],
				'read-findings did not register — the capture stub or the slug moved.'
			);
			return $GLOBALS['_af_abilities']['agentimus/read-findings']['output_schema'];
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

		public function test_the_findings_payload_is_fully_declared() {
			$schema = $this->output_schema();

			$this->assertFalse(
				$schema['additionalProperties'],
				'Top-level output allows undeclared keys — this test expects the strict mode that makes drift fatal.'
			);

			// The REAL aggregator, in the unit env: sources that cannot run here
			// land in `failed` (that resilience has its own tests in FindingsTest)
			// — the top-level shape is the same either way.
			$payload = ( new Findings( new Settings() ) )->all();
			$this->assert_declared( $schema['properties'], $payload, 'Findings::all()' );
			$this->assert_declared( $schema['properties']['counts']['properties'], $payload['counts'], 'The tier counts' );
		}

		public function test_a_finding_row_is_fully_declared() {
			$schema   = $this->output_schema();
			$declared = $schema['properties']['findings']['items']['properties'];

			// row() is the ONE builder every source funnels through — run the real
			// thing rather than mirroring its key list here.
			$m = new \ReflectionMethod( Findings::class, 'row' );
			\_af_accessible( $m );
			$row = $m->invoke(
				new Findings( new Settings() ),
				'near_page_one',
				Findings::WORTH,
				'2 pages are one push from page one',
				'Already ranking, just below the first page.',
				array( '3 searches', '600 shown' ),
				array( 'label' => 'Show me those 2 pages', 'tab' => 'visibility', 'view' => 'performance', 'anchor' => 'ar-group-search' ),
				array( 'Use the words people typed in the title they see.' )
			);
			$this->assert_declared( $declared, $row, 'A finding row' );

			// config_gaps() decorates its rows with the readiness check id AFTER
			// row() returns — the one row key the shared builder never sees.
			$this->assertArrayHasKey(
				'check',
				$declared,
				'config_gaps() appends `check` to its rows; undeclared, every configuration finding rejects itself.'
			);
		}

		public function test_the_resolved_row_shape_is_declared() {
			$schema   = $this->output_schema();
			$resolved = $schema['properties']['resolved'];

			$this->assertContains( 'null', (array) $resolved['type'], 'no news is null, never an empty object' );
			$this->assertFalse( $resolved['additionalProperties'] );

			// Mirror of resolved()'s two return literals — the single-win sentence
			// and the rollup carry the SAME four keys, and a new key there must be
			// added both to the ability schema and to this list.
			foreach ( array( 'id', 'title', 'evidence', 'at' ) as $key ) {
				$this->assertArrayHasKey(
					$key,
					$resolved['properties'],
					"Findings::resolved() returns `$key` but the resolved schema does not declare it."
				);
			}
		}

		public function test_the_tool_is_exposed_on_the_mcp_server() {
			// Registered is not the same as reachable: an ability the MCP list
			// forgets exists only inside wp-admin (see the mcp_abilities() comment).
			$tools = ( new Registrar( new Settings() ) )->mcp_abilities();
			$this->assertContains( 'agentimus/read-findings', $tools );
		}
	}
}
