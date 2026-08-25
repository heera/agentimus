<?php
/**
 * The write tier is a SECOND deliberate opt-in on top of the MCP server.
 *
 * Locks two promises:
 *   1. With only `enable_mcp_server` on, the MCP tool list carries the nine read
 *      tools and NONE of the write tools — and the server describes itself as
 *      read-only. "Read-only until the owner says otherwise" must survive upgrades.
 *   2. Going live is a THIRD switch: ContentWriter refuses status=publish while
 *      `agent_writes_publish` is off (drafts/pending always work), allows it when
 *      the switch AND the user's own publish capability agree, and never treats
 *      an already-published post's edit as a new publish.
 *
 * @package Agentimus\Tests
 */

namespace {
	// Capability + post-type surface for the publish gate (not in the shared
	// bootstrap; guarded so a future stub there wins). current_user_can() is
	// controllable per-test via $GLOBALS['_af_user_can'] (default: allowed).
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $cap, ...$args ) {
			$can = array_key_exists( '_af_user_can', $GLOBALS ) ? $GLOBALS['_af_user_can'] : true;
			return is_callable( $can ) ? (bool) call_user_func( $can, $cap, $args ) : (bool) $can;
		}
	}
	if ( ! function_exists( 'get_post_type_object' ) ) {
		function get_post_type_object( $type ) {
			return (object) array(
				'cap'    => (object) array(
					'edit_posts'    => 'edit_posts',
					'publish_posts' => 'publish_posts',
				),
				// Both labels, the way core carries them — plural for headings,
				// singular for prose. Without the pair, a test can't catch the
				// classic slip of writing "This posts has more images".
				'labels' => (object) array(
					'name'          => ucfirst( $type ) . 's',
					'singular_name' => ucfirst( $type ),
				),
			);
		}
	}
}

namespace Agentimus\Tests {

	use Agentimus\Abilities\Registrar;
	use Agentimus\Abilities\ContentWriter;
	use Agentimus\Settings;
	use PHPUnit\Framework\TestCase;

	/** A fake McpAdapter capturing create_server() calls (local twin of the gate test's). */
	class WritesFakeAdapter {
		/** @var array[] */
		public $calls = array();
		public function create_server( ...$args ) {
			$this->calls[] = $args;
		}
	}

	final class AgentWritesGateTest extends TestCase {

		protected function setUp(): void {
			\_af_reset_options();
			unset( $GLOBALS['_af_user_can'] );
		}

		protected function tearDown(): void {
			unset( $GLOBALS['_af_user_can'] );
		}

		private const WRITE_TOOLS = array(
			'agentimus/create-content',
			'agentimus/update-content',
			// ⭐ The two that made "fix everything" safe to ask for, 2026-08-25:
			// one anchored passage instead of a whole body, and the alt text that
			// was 54 of one site's 58 content flags with no tool behind it.
			'agentimus/edit-content',
			'agentimus/describe-image',
			'agentimus/write-description',
			'agentimus/write-topics',
			'agentimus/apply-fix',
			'agentimus/set-aside-page',
			// ⚠️ This list had drifted behind Registrar::WRITE_SLUGS — three write
			// tools were registered and never checked here, so the gate they all
			// depend on was proven for six of nine. Completed 2026-08-23.
			'agentimus/write-search-fields',
			'agentimus/retry-announcement',
			'agentimus/review-client',
			'agentimus/recheck-client',
		);

		private function server_args( array $settings ): array {
			update_option( Settings::OPTION, $settings );
			$adapter = new WritesFakeAdapter();
			( new Registrar( new Settings() ) )->register_mcp_server( $adapter );
			$this->assertCount( 1, $adapter->calls );
			return $adapter->calls[0];
		}

		public function test_mcp_server_alone_exposes_no_write_tools() {
			$args = $this->server_args( array( 'enable_mcp_server' => 1 ) );
			foreach ( self::WRITE_TOOLS as $name ) {
				$this->assertNotContains( $name, $args[9], "$name must not exist until enable_agent_writes is on" );
			}
			$this->assertContains( 'agentimus/read-readiness', $args[9] );
			$this->assertStringNotContainsString( 'draft/edit', $args[4], 'the server must describe itself as read-only' );
		}

		public function test_write_switch_adds_the_write_tools_and_the_honest_description() {
			$args = $this->server_args(
				array(
					'enable_mcp_server'   => 1,
					'enable_agent_writes' => 1,
				)
			);
			foreach ( self::WRITE_TOOLS as $name ) {
				$this->assertContains( $name, $args[9] );
			}
			$this->assertContains( 'agentimus/read-readiness', $args[9], 'the read tools stay' );
			$this->assertStringContainsString( 'draft/edit content', $args[4], 'the description must own up to the write tier' );
		}

		public function test_write_tool_descriptions_carry_the_same_readability_rules_the_assistant_writes_to() {
			// One set of drafting bars, two surfaces: the MCP write tools must show
			// external agents the EXACT rules the in-admin assistant's prompts embed,
			// so "generated content follows the readability checks" holds everywhere.
			$m = new \ReflectionMethod( Registrar::class, 'guided' );
			\_af_accessible( $m );
			$out = (string) $m->invoke( null, 'Creates a post.' );

			$rules = \Agentimus\Assistant::readability_rules();
			$this->assertStringContainsString( $rules, $out );
			$this->assertStringContainsString( 'Creates a post.', $out, 'The original description survives.' );
			$this->assertStringContainsString( $rules, \Agentimus\Assistant::system_prompt(), 'Quick-draft prompt embeds the same bars.' );
			$this->assertStringContainsString( $rules, \Agentimus\Assistant::staged_part_system_prompt(), 'Staged long-form prompt embeds the same bars.' );
		}

		public function test_readability_summary_compacts_rows_for_the_write_response() {
			$rows = array(
				array( 'id' => 'words', 'label' => 'Enough substance', 'status' => 'pass', 'detail' => 'plenty' ),
				array( 'id' => 'reading_ease', 'label' => 'Low reading ease', 'status' => 'warn', 'detail' => 'Score 33' ),
				array( 'id' => 'alt_text', 'label' => 'Image alt text', 'status' => 'fail', 'detail' => '2 images missing alt' ),
				array( 'id' => 'mystery', 'label' => 'From a filter', 'status' => 'meh', 'detail' => 'unknown status' ),
			);
			$sum = ContentWriter::readability_summary( $rows );

			$this->assertSame( 1, $sum['pass'] );
			$this->assertSame( 1, $sum['warn'] );
			$this->assertSame( 1, $sum['fail'] );
			$this->assertCount( 2, $sum['attention'], 'Only warn/fail rows need attention; unknown statuses are dropped.' );
			$this->assertSame( 'reading_ease', $sum['attention'][0]['id'] );
			$this->assertSame( 'Score 33', $sum['attention'][0]['detail'] );

			$this->assertSame(
				array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'attention' => array() ),
				ContentWriter::readability_summary( array() ),
				'No rows (missing post) still answers with a well-formed empty grade.'
			);
		}

		public function test_both_write_switches_default_off() {
			$defaults = ( new Settings() )->defaults();
			$this->assertFalse( $defaults['enable_agent_writes'] );
			$this->assertFalse( $defaults['agent_writes_publish'] );
		}

		/** A sub-toggle whose parent is off must not stay armed invisibly. */
		public function test_write_switches_cascade_off_with_their_parent() {
			$s = new Settings();

			$mcp_off = $s->sanitize(
				array(
					'enable_mcp_server'    => 0,
					'enable_agent_writes'  => 1,
					'agent_writes_publish' => 1,
				)
			);
			$this->assertFalse( $mcp_off['enable_agent_writes'], 'MCP off ⇒ writes off, even when sent on' );
			$this->assertFalse( $mcp_off['agent_writes_publish'] );

			$writes_off = $s->sanitize(
				array(
					'enable_mcp_server'    => 1,
					'enable_agent_writes'  => 0,
					'agent_writes_publish' => 1,
				)
			);
			$this->assertFalse( $writes_off['agent_writes_publish'], 'writes off ⇒ publish off, even when sent on' );

			$all_on = $s->sanitize(
				array(
					'enable_mcp_server'    => 1,
					'enable_agent_writes'  => 1,
					'agent_writes_publish' => 1,
				)
			);
			$this->assertTrue( $all_on['enable_agent_writes'], 'the full ladder still saves when every rung is chosen' );
			$this->assertTrue( $all_on['agent_writes_publish'] );
		}

		/* ---- the publish gate (ContentWriter::validate_status) ---------------- */

		private function validate( array $settings, string $status, bool $already_published = false ) {
			update_option( Settings::OPTION, $settings );
			$writer = new ContentWriter( new Settings() );
			$method = new \ReflectionMethod( ContentWriter::class, 'validate_status' );
			\_af_accessible( $method );
			return $method->invoke( $writer, $status, 'post', $already_published );
		}

		public function test_draft_and_pending_need_no_publish_switch() {
			$this->assertSame( 'draft', $this->validate( array(), 'draft' ) );
			$this->assertSame( 'pending', $this->validate( array(), 'pending' ) );
		}

		public function test_publish_is_refused_while_the_switch_is_off() {
			$result = $this->validate( array( 'enable_agent_writes' => 1 ), 'publish' );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'agentimus_publish_off', $result->get_error_code() );
		}

		public function test_publish_needs_the_users_own_capability_too() {
			$settings = array(
				'enable_agent_writes'  => 1,
				'agent_writes_publish' => 1,
			);

			$this->assertSame( 'publish', $this->validate( $settings, 'publish' ), 'switch on + capable user ⇒ allowed' );

			$GLOBALS['_af_user_can'] = false;
			$refused                 = $this->validate( $settings, 'publish' );
			$this->assertTrue( is_wp_error( $refused ) );
			$this->assertSame( 'agentimus_cannot_publish', $refused->get_error_code(), 'the switch never outranks the user\'s capabilities' );
		}

		public function test_keeping_an_already_published_post_published_is_not_a_new_publish() {
			$this->assertSame( 'publish', $this->validate( array(), 'publish', true ) );
		}

		public function test_statuses_outside_the_allowed_set_are_refused() {
			foreach ( array( 'private', 'future', 'trash', 'auto-draft' ) as $status ) {
				$result = $this->validate( array( 'agent_writes_publish' => 1 ), $status );
				$this->assertTrue( is_wp_error( $result ), "$status must be refused" );
				$this->assertSame( 'agentimus_bad_status', $result->get_error_code() );
			}
		}
	}
}
