<?php
/**
 * read-content-issues — the tool that lets an agent FIND what needs changing.
 *
 * Until it existed, an agent connected over MCP could rewrite a page but never
 * learn which page to rewrite: read-findings said "36 Posts and Pages are worth
 * fixing" and handed back a screen anchor with no ids in it, and check-page
 * wanted a post id nothing on the server would give it. Worklist and Grades —
 * the two classes that know the answer — appeared nowhere in the registrar.
 *
 * This file pins the parts that need no database: that the tool registers, that
 * its input says what it accepts, that it is read-only, and that it is on the
 * MCP server's list. Registered is not reachable — an ability missing from
 * {@see Registrar::mcp_abilities()} exists only inside wp-admin, which is the
 * exact shape of the gap this tool closes. The payload-vs-schema drift check
 * needs real rows and lives in the integration suite.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
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
	final class ContentIssuesAbilityTest extends TestCase {

		private const NAME = 'agentimus/read-content-issues';

		/** Define the capture stub at RUN time (never at file load — see class doc). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		private function registered(): array {
			$this->ensure_capture_stub();
			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			$this->assertArrayHasKey(
				self::NAME,
				$GLOBALS['_af_abilities'],
				'read-content-issues did not register — the capture stub or the slug moved.'
			);
			return $GLOBALS['_af_abilities'][ self::NAME ];
		}

		public function test_it_takes_a_bucket_and_a_page() {
			$input = $this->registered()['input_schema'];
			$props = $input['properties'];

			$this->assertSame(
				array( 'fixable', 'clear', 'setAside' ),
				$props['filter']['enum'],
				'The three buckets are exclusive and are the screen’s own — a fourth here would describe a list nobody keeps.'
			);
			// ⚠️ A default, not just an enum: over REST a read ability is a GET whose
			// input arrives as a query param, and an agent that sends nothing must
			// get the useful bucket rather than an input error.
			$this->assertSame( 'fixable', $props['filter']['default'] );
			$this->assertSame( 'integer', $props['page']['type'] );
			$this->assertSame( 'integer', $props['per']['type'] );
		}

		public function test_it_is_read_only_and_never_destructive() {
			$meta = $this->registered()['meta'];

			$this->assertTrue( $meta['annotations']['readonly'], 'It reads stored verdicts; it changes nothing.' );
			$this->assertFalse( $meta['annotations']['destructive'] );
			// A read-only key must still see it — the scope filter narrows to the
			// read tier, and a finder hidden from a reader would leave that
			// connection able to see a count and never the pages behind it.
			$this->assertFalse( Registrar::is_write_tool_name( self::NAME ) );
		}

		public function test_the_tool_is_exposed_on_the_mcp_server() {
			// Registered is not the same as reachable: an ability the MCP list
			// forgets exists only inside wp-admin (see the mcp_abilities() comment).
			$tools = ( new Registrar( new Settings() ) )->mcp_abilities();
			$this->assertContains( self::NAME, $tools );
			// It aims check-page, so a server carrying one without the other is
			// half a hand-off.
			$this->assertContains( 'agentimus/check-page', $tools );
		}

		public function test_the_output_declares_the_numbers_that_bound_the_claim() {
			$props = $this->registered()['output_schema']['properties'];

			// ⛔ Not decoration. `grading` and `rechecking` are how a payload says
			// which part of the site it cannot speak for, and `stale` is how one
			// ROW says its verdict predates the owner's last save. Drop any of the
			// three and the tool starts reporting a partial reading as the site.
			foreach ( array( 'items', 'counts', 'total', 'grading', 'rechecking', 'noSearchData', 'types', 'engine' ) as $key ) {
				$this->assertArrayHasKey( $key, $props, "The payload must declare `$key`." );
			}
			$row = $props['items']['items']['properties'];
			foreach ( array( 'id', 'issues', 'coverage', 'search', 'stale', 'readAt', 'setAside', 'postType' ) as $key ) {
				$this->assertArrayHasKey( $key, $row, "A row must declare `$key`." );
			}
			$this->assertSame(
				array( 'answered', 'scattered', 'barely', 'missing', '' ),
				$row['coverage']['enum'],
				'The coverage states are Search\Coverage’s own, plus the empty one that means “no search to judge it against”.'
			);
		}
	}
}
