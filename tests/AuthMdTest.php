<?php
/**
 * AuthMd — the /auth.md document.
 *
 * Locks the honesty contract: the public-read story is always told; the OAuth
 * walkthrough exists ONLY while the MCP server is on, and every URL in it comes
 * from the OAuth server's own live metadata (so the doc cannot drift from the
 * implementation); the write-scope line follows the agent-writes gate.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\AuthMd;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class AuthMdTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	private function md( array $settings = array() ): string {
		update_option( Settings::OPTION, $settings );
		return ( new AuthMd( new Settings() ) )->markdown();
	}

	public function test_public_read_story_is_always_told() {
		$md = $this->md();
		$this->assertStringContainsString( '## Public content — no credentials', $md );
		$this->assertStringContainsString( 'without authentication', $md );
	}

	public function test_no_oauth_walkthrough_while_the_mcp_server_is_off() {
		$md = $this->md();
		$this->assertStringNotContainsString( 'OAuth 2.0', $md );
		$this->assertStringContainsString( 'No authenticated agent endpoint is currently enabled', $md );
	}

	public function test_oauth_walkthrough_mirrors_the_live_server_metadata() {
		$md   = $this->md( array( 'enable_mcp_server' => true ) );
		$meta = \Agentimus\Oauth\Server::authorization_server_metadata();

		// Every load-bearing URL and constraint comes from the metadata the server
		// itself publishes at the RFC 8414 well-known.
		$this->assertStringContainsString( $meta['registration_endpoint'], $md );
		$this->assertStringContainsString( $meta['authorization_endpoint'], $md );
		$this->assertStringContainsString( $meta['token_endpoint'], $md );
		$this->assertStringContainsString( 'PKCE (S256 only)', $md );
		$this->assertStringContainsString( '/.well-known/oauth-authorization-server/agentimus/mcp', $md );
		$this->assertStringContainsString( '/.well-known/oauth-protected-resource/agentimus/mcp', $md );
		$this->assertStringContainsString( 'Authorization: Bearer', $md );
		$this->assertStringContainsString( 'application password', $md );
		// A plain OAuth 2.0 server is NOT an OIDC provider — say so, because
		// scanners probe /.well-known/openid-configuration and read silence as "no auth".
		$this->assertStringContainsString( 'Not OpenID Connect', $md );
	}

	public function test_write_scope_line_follows_the_agent_writes_gate() {
		$off = $this->md( array( 'enable_mcp_server' => true ) );
		$this->assertStringContainsString( 'grants nothing until the owner switches agent writes on', $off );

		$on = $this->md( array( 'enable_mcp_server' => true, 'enable_agent_writes' => true ) );
		$this->assertStringContainsString( 'write tools are on', $on );
		$this->assertStringContainsString( 'runs as the user who approved the connection', $on );
	}

	public function test_declared_external_auth_server_is_mentioned_when_mcp_is_off() {
		$md = $this->md( array( 'oauth_auth_server' => 'https://auth.example.com' ) );
		$this->assertStringContainsString( '/.well-known/oauth-protected-resource', $md );
	}

	public function test_walkthrough_carries_the_seven_authmd_sections() {
		// The auth.md walkthrough shape (workos.com/auth-md): an agent reads
		// Discover → Pick a method → Register → Claim → Use the credential →
		// Errors → Revocation, in that order, and each section must exist.
		$md   = $this->md( array( 'enable_mcp_server' => true ) );
		$last = -1;
		foreach ( array( '### Discover', '### Pick a method', '### Register', '### Claim', '### Use the credential', '### Errors', '### Revocation' ) as $section ) {
			$pos = strpos( $md, $section );
			$this->assertNotFalse( $pos, "auth.md must carry the section: $section" );
			$this->assertGreaterThan( $last, $pos, "sections must appear in walkthrough order: $section" );
			$last = $pos;
		}
		// The honest method statement: anonymous only, no identity assertions.
		$this->assertStringContainsString( '`anonymous`', $md );
		$this->assertStringContainsString( 'no ID-JAG', $md );
		// Revocation tells the truth: no RFC 7009 endpoint, owner-side disconnect.
		$this->assertStringContainsString( 'no RFC 7009 revocation endpoint', $md );
	}

	public function test_registration_state_is_stated_outright() {
		// Agents guess /register, /signup, /account before finding the answer at
		// wp-login.php — one live-read sentence ends the guessing.
		$this->assertStringContainsString( 'registration is disabled', $this->md() );

		update_option( 'users_can_register', 1 );
		$md = ( new AuthMd( new Settings() ) )->markdown();
		$this->assertStringContainsString( 'registration is open', $md );
		update_option( 'users_can_register', 0 );
	}

	public function test_claim_section_names_the_login_first_behaviour() {
		// The consent page redirects to wp-login first; a journey agent called that
		// behaviour something it "had to infer" — now the doc says it.
		$md = $this->md( array( 'enable_mcp_server' => true ) );
		$this->assertStringContainsString( 'Expect a WordPress login first', $md );
	}
}
