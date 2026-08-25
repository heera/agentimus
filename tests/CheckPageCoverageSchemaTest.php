<?php
/**
 * check-page grew a SECOND verdict, and a second verdict is a second chance to
 * ship the 1.30.0 bug: ability output validates with `additionalProperties:false`,
 * so a key the callback returns and the schema does not declare makes the tool
 * reject its own honest response for every MCP client — while the admin screens,
 * which have no schema, keep working and hide it.
 *
 * ⭐ The key list is DERIVED FROM THE SOURCE, never written out here. A
 * hand-kept mirror only catches the schema falling behind the mirror; nothing
 * catches the mirror falling behind the code, which is exactly how that bug
 * shipped twice. A field added to Worklist::coverage_for() is a field this test
 * demands a declaration for.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Search\Coverage;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	/**
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class CheckPageCoverageSchemaTest extends TestCase {

		/** Define the capture stub at RUN time (never at file load). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		private function output_schema( string $slug = 'agentimus/check-page' ): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey(
				$slug,
				$GLOBALS['_af_abilities'],
				"$slug did not register — the capture stub or the slug moved."
			);
			return $GLOBALS['_af_abilities'][ $slug ]['output_schema'];
		}

		/** Every key of the `$out` literal Worklist::coverage_for() returns. */
		private function coverage_keys(): array {
			$src = file_get_contents( dirname( __DIR__ ) . '/inc/Worklist.php' );
			$this->assertNotFalse( $src, 'Could not read Worklist.php to derive the coverage keys.' );

			$body = strstr( $src, 'public function coverage_for( \WP_Post $post ) {' );
			$this->assertNotFalse( $body, 'Worklist::coverage_for() moved or was renamed — this test cannot see it.' );
			$lit = strstr( $body, "\n\t\t\$out = array(" );
			$this->assertNotFalse( $lit, 'coverage_for() no longer opens with an `$out = array(` literal.' );
			$lit = substr( $lit, 0, strpos( $lit, "\n\t\t);" ) );

			preg_match_all( "/^\t\t\t'([a-zA-Z]+)'\s*=>/m", $lit, $m );
			$keys = $m[1];

			// A parse that finds nothing must FAIL, not quietly pass everything.
			$this->assertGreaterThan(
				10,
				count( $keys ),
				'Derived suspiciously few keys from coverage_for() — the literal was reformatted and this test is no longer reading it.'
			);
			return $keys;
		}

		public function test_check_page_declares_both_verdicts_and_the_one_field_that_joins_them() {
			$schema = $this->output_schema();

			$this->assertFalse(
				$schema['additionalProperties'],
				'Output allows undeclared keys — this test expects the strict mode that makes an undeclared key fatal.'
			);
			foreach ( array( 'summary', 'checks', 'coverage', 'needsWork' ) as $key ) {
				$this->assertArrayHasKey(
					$key,
					$schema['properties'],
					"check-page returns `$key` but does not declare it."
				);
			}
			$this->assertSame(
				'boolean',
				$schema['properties']['needsWork']['type'],
				'needsWork is the one field that answers "is there work here?" for both halves at once.'
			);
		}

		public function test_every_coverage_field_is_declared() {
			$declared = $this->output_schema()['properties']['coverage']['properties'];

			foreach ( $this->coverage_keys() as $key ) {
				$this->assertArrayHasKey(
					$key,
					$declared,
					"Worklist::coverage_for() returns `$key` but the check-page coverage schema does not declare it — the ability will reject its own response."
				);
			}
		}

		/**
		 * ⛔ The field that says "this is not a measurement" has to exist, and the
		 * one that says which words are missing has to carry its shape — those two
		 * are the entire reason an agent can act on this verdict.
		 */
		public function test_the_unmeasured_flag_and_the_missing_words_carry_their_shape() {
			$cov = $this->output_schema()['properties']['coverage'];

			$this->assertSame( 'boolean', $cov['properties']['measured']['type'] );
			$this->assertSame( 'array', $cov['properties']['terms']['type'] );

			$term = $cov['properties']['terms']['items']['properties'];
			foreach ( array( 'word' => 'string', 'onPage' => 'boolean', 'inPassage' => 'boolean' ) as $key => $type ) {
				$this->assertArrayHasKey( $key, $term, "A coverage term must carry `$key`." );
				$this->assertSame( $type, $term[ $key ]['type'] );
			}
		}

		/**
		 * ⛔⛔ EVERY STATE Coverage CAN EMIT HAS TO BE IN read-content-issues'
		 * ENUM. That field is enum-constrained and ability output validates, so a
		 * state the schema does not list makes the tool reject its own honest
		 * response — the 1.30.0 bug, and it would have fired on exactly the sites
		 * UNREADABLE was added to help: every non-Latin-script install.
		 *
		 * ⭐ The list is DERIVED from the class, never written out here, so adding
		 * a state is a change this test demands a declaration for.
		 */
		public function test_the_worklist_enum_lists_every_state_coverage_can_emit() {
			$schema = $this->output_schema( 'agentimus/read-content-issues' );
			$enum   = $schema['properties']['items']['items']['properties']['coverage']['enum'];

			$states = array();
			foreach ( ( new \ReflectionClass( Coverage::class ) )->getConstants() as $name => $value ) {
				// The states are the string-valued ones; the rest are thresholds.
				if ( is_string( $value ) ) {
					$states[ $name ] = $value;
				}
			}
			$this->assertGreaterThanOrEqual(
				5,
				count( $states ),
				'Derived suspiciously few states from Coverage — this test is no longer reading it.'
			);

			foreach ( $states as $name => $value ) {
				$this->assertContains(
					$value,
					$enum,
					"Coverage::$name is '$value' and the read-content-issues enum does not list it — the ability will reject its own response."
				);
			}
			$this->assertContains( '', $enum, 'Empty stays legal: it means no search to judge against.' );
		}

		/* ------------------------------------------------- the words themselves */

		/**
		 * ⭐ ONE OWNER FOR THE SENTENCE. These readings used to live inline in a
		 * rendering method, so only the owner's editor could explain a verdict and
		 * every other surface had the bare state word to repeat.
		 */
		public function test_each_state_explains_itself_in_words_a_caller_can_repeat() {
			$barely = Coverage::explain( array( 'state' => Coverage::BARELY, 'words' => 5, 'on_page' => 2 ) );
			$this->assertSame( 'Barely', $barely['label'] );
			$this->assertStringContainsString( '2 of 5', $barely['why'], 'A reading has to carry its own numbers.' );

			$scattered = Coverage::explain( array( 'state' => Coverage::SCATTERED, 'words' => 4 ) );
			$this->assertSame( 'Scattered', $scattered['label'] );
			$this->assertStringContainsString( '4', $scattered['why'] );

			$answered = Coverage::explain( array( 'state' => Coverage::ANSWERED, 'words' => 3, 'heading' => 'The WordPress Solution' ) );
			$this->assertSame( 'Answered', $answered['label'] );
			$this->assertStringContainsString( 'The WordPress Solution', $answered['why'], 'It names the passage it found.' );

			// The same state with nothing to point at must not print an empty quote.
			$bare = Coverage::explain( array( 'state' => Coverage::ANSWERED, 'words' => 3, 'heading' => '' ) );
			$this->assertSame( 'Answered', $bare['label'] );
			$this->assertStringNotContainsString( '““', $bare['why'] );
			$this->assertStringNotContainsString( '“”', $bare['why'], '⛔ Never a heading-shaped hole where no heading was found.' );

			$missing = Coverage::explain( array( 'state' => Coverage::MISSING, 'words' => 3 ) );
			$this->assertSame( 'Missing', $missing['label'] );
		}

		/**
		 * ⚠️ measure() is filterable, so a replacement measurement can hand back a
		 * state this class never named. The old renderer indexed a map blind (fatal)
		 * and fell through a switch default to the "Missing" wording — which is a
		 * reading of the page, invented for a word we do not understand.
		 */
		public function test_an_unknown_state_names_itself_rather_than_guessing() {
			$said = Coverage::explain( array( 'state' => 'partial', 'words' => 3 ) );
			$this->assertSame(
				'partial',
				$said['label'],
				'⭐ Named with its own word — the rule PageCheck::issue_label() already settled: silence reads as "no verdict", the raw word at least names something.'
			);
			$this->assertSame( '', $said['why'], '⛔ …but it must NOT borrow another verdict’s sentence to explain a word we cannot read.' );

			// Nothing measured at all is the one case with genuinely nothing to say.
			$empty = Coverage::explain( array() );
			$this->assertSame( '', $empty['label'] );
			$this->assertSame( '', $empty['why'] );
		}
	}
}
