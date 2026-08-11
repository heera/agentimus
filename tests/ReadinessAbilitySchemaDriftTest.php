<?php
/**
 * read-readiness's score object must DECLARE every key Score::report() returns.
 *
 * The score schema shipped for releases with additionalProperties:true and two
 * undeclared keys — `content` (the per-page worklist) and `ignored` (the
 * set-aside ledger). The wildcard meant nothing rejected, but both keys were
 * invisible to any client that trusts the declared contract: the same drift
 * this family of tests exists to catch, hidden by the wildcard instead of by
 * the admin screen. The shape is fully declared and strict now, and this file
 * keeps it that way against the REAL producer.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Score;
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
	final class ReadinessAbilitySchemaDriftTest extends TestCase {

		/** Define the capture stub at RUN time (never at file load — see class doc). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		/**
		 * The two WP link/label functions the worklist rows resolve with — absent
		 * from the unit bootstrap, defined here at RUN time like the capture stub.
		 * A null post-type object exercises the "N items" label fallback.
		 */
		private function ensure_link_stubs(): void {
			if ( ! function_exists( 'get_edit_post_link' ) ) {
				eval( 'function get_edit_post_link( $id, $context = "display" ) { return "https://example.test/wp-admin/post.php?post=" . (int) $id . "&action=edit"; }' );
			}
			if ( ! function_exists( 'get_post_type_object' ) ) {
				eval( 'function get_post_type_object( $t ) { return null; }' );
			}
		}

		/** The score sub-schema inside read-readiness's registered output schema. */
		private function score_schema(): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey(
				'agentimus/read-readiness',
				$GLOBALS['_af_abilities'],
				'read-readiness did not register — the capture stub or the slug moved.'
			);
			return $GLOBALS['_af_abilities']['agentimus/read-readiness']['output_schema']['properties']['score'];
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
					"$where returns `$key` but the score schema does not declare it — with additionalProperties:false the ability rejects its own response."
				);
			}
		}

		public function test_the_score_report_is_fully_declared() {
			$schema = $this->score_schema();

			$this->assertFalse(
				$schema['additionalProperties'],
				'The score object allows undeclared keys — that wildcard is exactly what hid `content` and `ignored` from every schema-trusting client.'
			);

			$report = ( new Score( new Settings() ) )->report( array() );
			$this->assert_declared( $schema['properties'], $report, 'Score::report()' );

			// Every rung, because there are two shapes: check rungs (findable,
			// readable, trusted) and signal rungs, whose `view` key the check
			// rungs never carry.
			$this->assertNotEmpty( $report['rungs'], 'The report built no rungs — the rung drift check would pass vacuously.' );
			foreach ( $report['rungs'] as $rung ) {
				$this->assert_declared( $schema['properties']['rungs']['items']['properties'], $rung, "The `{$rung['key']}` rung" );
			}
		}

		public function test_a_worklist_row_and_a_set_aside_row_are_fully_declared() {
			$schema = $this->score_schema();
			$this->ensure_link_stubs();

			$GLOBALS['_af_posts'][11] = new \WP_Post(
				array(
					'ID'           => 11,
					'post_title'   => 'Hello world',
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_content' => str_repeat( 'A plain sentence about one topic, with something to read. ', 40 ),
				)
			);

			// content_worklist() on a real issues tally — the same reflection
			// pattern SearchAbilitySchemaDriftTest uses on the wire shapers.
			$m = new \ReflectionMethod( Score::class, 'content_worklist' );
			\_af_accessible( $m );
			$rows = $m->invoke(
				new Score( new Settings() ),
				array(
					'score'  => 80,
					'posts'  => 1,
					'issues' => array(
						'summary' => array( 'count' => 1, 'label' => 'Opening summary', 'posts' => array( 11 ), 'types' => array( 'post' => 1 ) ),
					),
				)
			);
			$this->assertNotEmpty( $rows, 'Fixture produced no worklist row — the drift check would pass vacuously.' );
			$declared = $schema['properties']['content']['items']['properties'];
			$this->assert_declared( $declared, $rows[0], 'A worklist row' );
			$this->assertNotEmpty( $rows[0]['pages'] );
			$this->assert_declared( $declared['pages']['items']['properties'], $rows[0]['pages'][0], 'A worklist page' );

			// ignored_list() on a real set-aside id.
			update_option( Settings::OPTION, array( 'optimize_ignored' => array( 11 ) ) );
			$m2 = new \ReflectionMethod( Score::class, 'ignored_list' );
			\_af_accessible( $m2 );
			$ignored = $m2->invoke( new Score( new Settings() ) );
			$this->assertNotEmpty( $ignored, 'Fixture produced no set-aside row — the drift check would pass vacuously.' );
			$this->assert_declared( $schema['properties']['ignored']['items']['properties'], $ignored[0], 'A set-aside row' );
		}
	}
}
