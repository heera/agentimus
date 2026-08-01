<?php
/**
 * SkillMd — the site packaged as an agentskills.io Agent Skill.
 *
 * Locks the spec contract (the name is lowercase-hyphen and MATCHES the parent
 * directory in the served URL; the description states what AND when-to-use and
 * fits the 1024-char cap) and the honesty rule: the MCP section exists only when
 * a real server is detected, and the write-tools line only when writes are on.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

// The adapter stub + FakeMcpServer live in the wiring test's file; loading it
// here (its class_exists guard makes this idempotent) keeps the stub claiming
// the adapter's name even when this file runs standalone — otherwise the REAL
// vendored adapter autoloads and the fake-server wiring below fatals.
require_once __DIR__ . '/McpServerWiringTest.php';

use Agentimus\Discovery\Envelope;
use Agentimus\Discovery\Registry;
use Agentimus\Discovery\SkillMd;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class SkillMdTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_registry();
		\_af_reset_options();
	}

	protected function tearDown(): void {
		if ( class_exists( 'WP\\MCP\\Core\\McpAdapter', false ) ) {
			\WP\MCP\Core\McpAdapter::$servers = array();
		}
		unset( $GLOBALS['_af_did_actions']['mcp_adapter_init'] );
		\_af_reset_options();
	}

	private function skill(): SkillMd {
		$settings = new Settings();
		return new SkillMd( $settings, new Envelope( $settings, Registry::instance() ) );
	}

	public function test_name_is_spec_shaped_and_derived_from_the_host() {
		// https://example.test/ → example-test: lowercase, single hyphens, no
		// leading/trailing hyphen — the agentskills.io name alphabet.
		$this->assertSame( 'example-test', SkillMd::name() );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+(-[a-z0-9]+)*$/', SkillMd::name() );
	}

	public function test_url_directory_matches_the_skill_name() {
		// The spec requires name == parent directory name.
		$this->assertSame( 'https://example.test/.well-known/agent-skills/example-test/SKILL.md', SkillMd::url() );
	}

	public function test_frontmatter_carries_name_and_a_when_to_use_description() {
		$md = $this->skill()->markdown();

		$this->assertStringStartsWith( "---\n", $md );
		$this->assertStringContainsString( 'name: example-test', $md );

		// The description must say when to reach for the skill, not just what it is,
		// and stay inside the spec's 1024-char cap.
		$this->assertMatchesRegularExpression( '/description: "([^"]*Use when[^"]*)"/', $md, $matches = '' );
		preg_match( '/description: "([^"]+)"/', $md, $m );
		$this->assertLessThanOrEqual( 1024, strlen( $m[1] ) );
	}

	public function test_body_lists_only_enabled_read_surfaces() {
		$md = $this->skill()->markdown();

		// Defaults: llms.txt, markdown editions, full text, changes feed are all on.
		$this->assertStringContainsString( 'https://example.test/llms.txt', $md );
		$this->assertStringContainsString( 'append `.md`', $md );
		$this->assertStringContainsString( 'https://example.test/llms-full.txt', $md );
		$this->assertStringContainsString( '/.well-known/discovery.json', $md );

		// Switch the full-text edition off — the skill stops promising it.
		update_option( Settings::OPTION, array( 'enable_llms_full' => false ) );
		$this->assertStringNotContainsString( 'llms-full.txt', $this->skill()->markdown() );
	}

	public function test_no_mcp_section_without_a_real_server() {
		$md = $this->skill()->markdown();
		$this->assertStringNotContainsString( '## Use the MCP server', $md );
		$this->assertStringNotContainsString( 'OAuth', $md );
	}

	public function test_mcp_section_appears_with_a_live_server_and_reflects_the_write_gate() {
		$GLOBALS['_af_did_actions']['mcp_adapter_init'] = 1;
		\WP\MCP\Core\McpAdapter::$servers              = array( new FakeMcpServer() );

		update_option( Settings::OPTION, array( 'enable_mcp_server' => true ) );
		$md = $this->skill()->markdown();
		$this->assertStringContainsString( '## Use the MCP server', $md );
		$this->assertStringContainsString( '/wp-json/acme-mcp/v1/mcp', $md );
		$this->assertStringContainsString( 'oauth-protected-resource/agentimus/mcp', $md );
		$this->assertStringContainsString( '- `search`', $md );
		$this->assertStringContainsString( 'read-only', $md );

		// Writes opt-in flips the read-only line to the consent-framed one.
		update_option( Settings::OPTION, array( 'enable_mcp_server' => true, 'enable_agent_writes' => true ) );
		$md = $this->skill()->markdown();
		$this->assertStringNotContainsString( 'read-only', $md );
		$this->assertStringContainsString( 'the owner approved', $md );
	}

	public function test_directory_listing_points_at_the_skill() {
		$dir = $this->skill()->directory_markdown();
		$this->assertStringContainsString( '`example-test`', $dir );
		$this->assertStringContainsString( 'https://example.test/.well-known/agent-skills/example-test/SKILL.md', $dir );
	}

	public function test_directory_listing_carries_the_when_to_use_guidance() {
		// The bare /agent-skills path is what a probing agent reads first —
		// when-to-use guidance it has to click through for may never be seen.
		$this->assertStringContainsString( 'Use when', $this->skill()->directory_markdown() );
	}
}
