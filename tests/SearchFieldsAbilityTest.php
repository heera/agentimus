<?php
/**
 * write-search-fields and read-terms — the two ends of the job the write path
 * used to stop one step short of.
 *
 * An agent could draft a post, dress it, and grade it to full marks without
 * reaching the two fields that decide how it appears in a search result. And to
 * file it correctly it had to type category names from memory, because nothing
 * would list the ones the site already uses — which is how "New Features"
 * becomes a second category beside "New features".
 *
 * This file pins the registration facts that need no database: which tier each
 * tool is in, what it accepts, and that the read half stays available to a
 * read-only connection.
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
	final class SearchFieldsAbilityTest extends TestCase {

		protected function setUp(): void {
			\_af_reset_options();
		}

		/** Define the capture stub at RUN time (never at file load — see class doc). */
		private function ensure_capture_stub(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				eval( 'function wp_register_ability( $name, array $args = array() ) { $GLOBALS["_af_abilities"][ $name ] = $args; return true; }' );
			}
		}

		/**
		 * Register everything and hand back the capture.
		 *
		 * @param bool $writes Whether the owner has allowed agent writes.
		 * @return array<string,array>
		 */
		private function registered( bool $writes ): array {
			$this->ensure_capture_stub();
			$settings = new Settings();
			$all      = $settings->all(); // Partial updates reset unset settings — merge into all().
			// ⚠️ BOTH switches: the write tier exists only where the MCP server is
			// on AND the owner allowed writes. Setting one is the state an owner
			// who has not finished deciding is in.
			$all['enable_mcp_server']   = true;
			$all['enable_agent_writes'] = $writes;
			$settings->update( $all );

			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			return $GLOBALS['_af_abilities'];
		}

		public function test_the_search_fields_tool_is_a_write_and_takes_both_fields() {
			$all = $this->registered( true );
			$this->assertArrayHasKey( 'agentimus/write-search-fields', $all, 'It did not register with writes allowed.' );

			$tool  = $all['agentimus/write-search-fields'];
			$props = $tool['input_schema']['properties'];
			$this->assertSame( array( 'post_id' ), $tool['input_schema']['required'], 'Either field alone is a valid call.' );
			$this->assertArrayHasKey( 'focus', $props );
			$this->assertArrayHasKey( 'seo_title', $props );

			$this->assertFalse( $tool['meta']['annotations']['readonly'], 'It writes post meta.' );
			$this->assertTrue( Registrar::is_write_tool_name( 'agentimus/write-search-fields' ), 'A read-only key must never be shown it.' );
		}

		/** ⛔ Off by default, and off means the ability does not exist on any surface. */
		public function test_it_does_not_exist_while_the_owner_has_writes_switched_off() {
			$this->assertArrayNotHasKey( 'agentimus/write-search-fields', $this->registered( false ) );
		}

		/**
		 * ⭐ The term list is a READ, and stays available to a read-only
		 * connection — knowing what a site already calls things is not a write,
		 * and an agent that cannot see the list is the one that mints duplicates.
		 */
		public function test_the_term_list_is_a_read_and_survives_the_write_switch() {
			foreach ( array( true, false ) as $writes ) {
				$all = $this->registered( $writes );
				$this->assertArrayHasKey( 'agentimus/read-terms', $all );
				$this->assertTrue( $all['agentimus/read-terms']['meta']['annotations']['readonly'] );
			}
			$this->assertFalse( Registrar::is_write_tool_name( 'agentimus/read-terms' ) );
		}

		public function test_the_term_list_answers_a_bare_call() {
			// ⚠️ Every argument is optional, which makes "no arguments" the natural
			// ask — and a read ability runs over REST as a GET whose query string
			// cannot express an empty object. Without the default, that call is
			// refused at the input gate.
			$schema = $this->registered( false )['agentimus/read-terms']['input_schema'];
			$this->assertArrayHasKey( 'default', $schema );
			$this->assertSame( array(), (array) $schema['default'] );
			$this->assertSame( array( 'post_type', 'search', 'per' ), array_keys( $schema['properties'] ) );
		}

		public function test_both_tools_reach_the_mcp_server() {
			$settings = new Settings();
			$all      = $settings->all();
			$all['enable_mcp_server']   = true;
			$all['enable_agent_writes'] = true;
			$settings->update( $all );

			// Registered is not the same as reachable: an ability the MCP list
			// forgets exists only inside wp-admin.
			$tools = ( new Registrar( new Settings() ) )->mcp_abilities();
			$this->assertContains( 'agentimus/read-terms', $tools );
			$this->assertContains( 'agentimus/write-search-fields', $tools );
		}
	}
}
