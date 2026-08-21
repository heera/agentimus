<?php
/**
 * set-aside-page — the triage verb, and the bulk it deliberately does not have.
 *
 * `read-content-issues` has named the pages worth fixing since 1.37, and the
 * write tier could fix them — but an agent had no way to say the third thing an
 * owner says constantly: this one is fine as it is. It could fix, never triage.
 *
 * Two things are pinned here, and the second one is the point of the file:
 *
 *  - The registration facts that need no database: which tier the tool is in,
 *    what it accepts, and that its declared output schema still matches what
 *    {@see Triage::result()} actually returns (⛔ DERIVED from the source, never
 *    mirrored — a hand-copied key list only catches the schema falling behind
 *    the copy, which is how the 1.30.0 drift bug shipped twice).
 *  - That it addresses exactly ONE page. The owner's screen has two bulk actions
 *    beside this button, both behind a confirmation dialog that names the count.
 *    A dialog is a human gate and an agent has nobody to show it to, so the
 *    action does not cross. If someone later widens `post_id` to a list, or adds
 *    an `issue` argument, these tests fail and the decision gets made again on
 *    purpose rather than by convenience.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Abilities\Triage;
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
	final class SetAsideAbilityTest extends TestCase {

		protected function setUp(): void {
			\_af_reset_options();
			$GLOBALS['_af_posts'] = array();
		}

		protected function tearDown(): void {
			unset( $GLOBALS['_af_posts'] );
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
			$all['enable_mcp_server']   = true;
			$all['enable_agent_writes'] = $writes;
			$settings->update( $all );

			$GLOBALS['_af_abilities'] = array();
			( new Registrar( new Settings() ) )->register_abilities();
			return $GLOBALS['_af_abilities'];
		}

		/** A published post of a graded type, visible to the get_post() stub. */
		private function post( int $id, string $status = 'publish', string $type = 'post' ): void {
			$GLOBALS['_af_posts'][ $id ] = new \WP_Post(
				array(
					'ID'          => $id,
					'post_title'  => "Page $id",
					'post_status' => $status,
					'post_type'   => $type,
				)
			);
		}

		/** A Triage over a settings store holding exactly this set-aside list. */
		private function triage( array $parked = array() ): Triage {
			$settings = new Settings();
			$all      = $settings->all();
			$all['optimize_ignored'] = $parked;
			$settings->update( $all );
			return new Triage( new Settings() );
		}

		/* -- The tier ------------------------------------------------------- */

		public function test_it_is_a_write_and_exists_only_while_writes_are_allowed() {
			$all = $this->registered( true );
			$this->assertArrayHasKey( 'agentimus/set-aside-page', $all, 'It did not register with writes allowed.' );

			$tool = $all['agentimus/set-aside-page'];
			$this->assertFalse( $tool['meta']['annotations']['readonly'], 'It writes the owner’s set-aside list.' );
			$this->assertFalse( $tool['meta']['annotations']['destructive'], 'Nothing is lost — the page is untouched and the call reverses itself.' );
			$this->assertTrue( Registrar::is_write_tool_name( 'agentimus/set-aside-page' ), 'A read-only key must never be shown it.' );

			// ⛔ Off means it does not exist on ANY surface, not that it refuses.
			$this->assertArrayNotHasKey( 'agentimus/set-aside-page', $this->registered( false ) );
		}

		public function test_it_reaches_the_mcp_server_with_the_rest_of_the_write_tier() {
			$settings = new Settings();
			$all      = $settings->all();
			$all['enable_mcp_server']   = true;
			$all['enable_agent_writes'] = true;
			$settings->update( $all );

			// Registered is not the same as reachable: an ability the MCP list
			// forgets exists only inside wp-admin.
			$this->assertContains( 'agentimus/set-aside-page', ( new Registrar( new Settings() ) )->mcp_abilities() );
		}

		/* -- One page, and only one ----------------------------------------- */

		/**
		 * ⛔ THE LOAD-BEARING TEST. The tool takes a single integer post id and a
		 * direction — nothing that can name a set. Widening `post_id` to a list, or
		 * adding an `issue` argument, hands an agent the bulk action the owner's own
		 * screen puts behind a confirmation dialog.
		 */
		public function test_it_can_only_ever_address_one_page() {
			$schema = $this->registered( true )['agentimus/set-aside-page']['input_schema'];

			$this->assertSame( array( 'post_id', 'aside' ), array_keys( $schema['properties'] ), 'No third argument — a set-naming one least of all.' );
			$this->assertSame( 'integer', $schema['properties']['post_id']['type'], 'One id. Not a list of them.' );
			$this->assertSame( array( 'post_id' ), $schema['required'] );
			$this->assertFalse( $schema['additionalProperties'], 'An undeclared argument must be refused, not ignored.' );

			// Nor may a bulk twin sneak in beside it under another name.
			foreach ( array_keys( $this->registered( true ) ) as $name ) {
				$this->assertStringNotContainsString( 'restore-all', $name );
				$this->assertStringNotContainsString( 'set-aside-issue', $name );
			}
		}

		/** Setting aside is the common ask, so it is what a bare call means. */
		public function test_the_direction_defaults_to_setting_aside() {
			$aside = $this->registered( true )['agentimus/set-aside-page']['input_schema']['properties']['aside'];
			$this->assertSame( 'boolean', $aside['type'] );
			$this->assertTrue( $aside['default'] );
		}

		/* -- The declared shape vs the real one ------------------------------ */

		/**
		 * Ability output validates with additionalProperties:false, so a key the
		 * callback returns and the schema does not declare makes the tool reject its
		 * own honest answer for every MCP client — while the admin screen, same code
		 * and no schema, shows nothing wrong. Read the keys out of Triage::result(),
		 * which is deliberately the ONE return literal every path goes through.
		 */
		public function test_the_output_schema_declares_every_key_the_callback_returns() {
			$src = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/Triage.php' );
			$this->assertNotFalse( $src, 'Could not read Triage.php to derive the result keys.' );

			$body = strstr( $src, 'private function result(' );
			$this->assertNotFalse( $body, 'Triage::result() moved or was renamed — this test cannot see it.' );
			$body = substr( $body, 0, strpos( $body, "\n\t}" ) );

			preg_match_all( "/^\t\t\t'([a-zA-Z]+)'\s*=>/m", $body, $m );
			$returned = array_values( array_unique( $m[1] ) );
			$this->assertNotEmpty( $returned, 'Derived no keys — the return literal changed shape.' );

			$declared = array_keys( $this->registered( true )['agentimus/set-aside-page']['output_schema']['properties'] );
			sort( $returned );
			sort( $declared );
			$this->assertSame( $returned, $declared, 'The output schema and Triage::result() have drifted apart.' );
		}

		/**
		 * …and one level down. `counts` is not assembled here — it is whatever
		 * Grades::counts() returns, reached through Worklist::counts(). A bucket
		 * added there would arrive in this payload undeclared, and strict mode makes
		 * that fatal. Derived from ITS source for the same reason as above.
		 */
		public function test_the_declared_counts_are_the_buckets_grades_actually_returns() {
			$src = file_get_contents( dirname( __DIR__ ) . '/inc/Grades.php' );
			$this->assertNotFalse( $src, 'Could not read Grades.php to derive the bucket names.' );

			$body = strstr( $src, 'public static function counts( array $types, array $aside ) {' );
			$this->assertNotFalse( $body, 'Grades::counts() moved or was renamed — this test cannot see it.' );
			$body = substr( $body, 0, strpos( $body, "\n\t}" ) );

			// The method's FINAL return literal — the one that carries real numbers,
			// not the empty-site early return above it.
			$last = strrpos( $body, 'return array(' );
			$this->assertNotFalse( $last );
			preg_match_all( "/^\t\t\t'([a-zA-Z]+)'\s*=>/m", substr( $body, $last ), $m );
			$buckets = array_values( array_unique( $m[1] ) );
			$this->assertNotEmpty( $buckets, 'Derived no buckets — the return literal changed shape.' );

			$declared = array_keys(
				$this->registered( true )['agentimus/set-aside-page']['output_schema']['properties']['counts']['properties']
			);
			sort( $buckets );
			sort( $declared );
			$this->assertSame( $buckets, $declared, 'The declared counts and Grades::counts() have drifted apart.' );
		}

		/* -- The decision ---------------------------------------------------- */

		public function test_a_published_page_of_a_graded_type_may_be_set_aside() {
			$this->post( 7 );
			$plan = $this->triage()->plan( 7, true );
			$this->assertTrue( $plan['allowed'] );
			$this->assertTrue( $plan['change'] );
		}

		/** Already parked: allowed, but nothing to write — and it must say so. */
		public function test_setting_aside_a_parked_page_changes_nothing() {
			$this->post( 7 );
			$plan = $this->triage( array( 7 ) )->plan( 7, true );
			$this->assertTrue( $plan['allowed'] );
			$this->assertFalse( $plan['change'], 'A no-op must never report as a change.' );
		}

		/**
		 * A draft is already outside grading, so parking it would be a decision with
		 * no effect, recorded as though it had one.
		 */
		public function test_it_refuses_a_page_that_is_not_published() {
			$this->post( 7, 'draft' );
			$plan = $this->triage()->plan( 7, true );
			$this->assertFalse( $plan['allowed'] );
			$this->assertSame( 'agentimus_not_published', $plan['code'] );
		}

		/** A type this site does not grade has nothing to be excused from. */
		public function test_it_refuses_a_type_the_site_does_not_grade() {
			$this->post( 7, 'publish', 'product' );
			$plan = $this->triage()->plan( 7, true );
			$this->assertFalse( $plan['allowed'] );
			$this->assertSame( 'agentimus_not_graded', $plan['code'] );
			$this->assertStringContainsString( 'product', $plan['reason'], 'The refusal names the type it refused.' );
		}

		public function test_it_refuses_an_id_no_post_carries() {
			$plan = $this->triage()->plan( 404, true );
			$this->assertFalse( $plan['allowed'] );
			$this->assertSame( 'agentimus_not_found', $plan['code'] );
		}

		public function test_it_refuses_a_missing_id() {
			$this->assertSame( 'agentimus_bad_post', $this->triage()->plan( 0, true )['code'] );
		}

		/* -- Putting back is judged by different rules ----------------------- */

		/**
		 * ⭐ A page parked months ago may since have been unpublished or deleted.
		 * Judging a restore by the same "must be published" rule would strand the
		 * entry on the list with no call able to clear it.
		 */
		public function test_a_page_can_be_put_back_after_it_stopped_being_publishable() {
			$this->post( 7, 'draft' );
			$plan = $this->triage( array( 7 ) )->plan( 7, false );
			$this->assertTrue( $plan['allowed'], 'A parked entry must always be clearable.' );
			$this->assertTrue( $plan['change'] );

			// Even with the post gone entirely.
			$GLOBALS['_af_posts'] = array();
			$this->assertTrue( $this->triage( array( 7 ) )->plan( 7, false )['allowed'] );
		}

		public function test_putting_back_a_page_that_was_never_parked_changes_nothing() {
			$this->post( 7 );
			$plan = $this->triage()->plan( 7, false );
			$this->assertTrue( $plan['allowed'] );
			$this->assertFalse( $plan['change'] );
		}

		/* -- The cap --------------------------------------------------------- */

		/**
		 * The store keeps the FIRST 1,000 ids, so an id appended past the cap is
		 * dropped by the sanitiser. Refuse out loud rather than answering "saved"
		 * for a write whose effect vanished.
		 */
		public function test_a_full_list_refuses_instead_of_silently_dropping_the_id() {
			$this->post( 5000 );
			$full = range( 1, Triage::MAX_SET_ASIDE );

			$plan = $this->triage( $full )->plan( 5000, true );
			$this->assertFalse( $plan['allowed'] );
			$this->assertSame( 'agentimus_set_aside_full', $plan['code'] );

			// A page ALREADY on the full list is not asking for room.
			$this->post( 1 );
			$this->assertTrue( $this->triage( $full )->plan( 1, true )['allowed'] );
			// And putting one back is how the list gets unstuck, so it never refuses.
			$this->assertTrue( $this->triage( $full )->plan( 1, false )['allowed'] );
		}
	}
}
