<?php
/**
 * MCP server auto-detection + OAuth wiring.
 *
 * Exercises the server-PRESENT path of Envelope::mcp() by stubbing the official
 * WordPress MCP adapter (\WP\MCP\Core\McpAdapter) and flipping mcp_adapter_init.
 * Locks: a server is detected generically (no per-plugin code); without OAuth the
 * auth is the adapter default; when the owner declares an auth server, the MCP
 * block AND the server card reflect oauth + link the RFC 9728 metadata.
 *
 * @package Agentomatic\Tests
 */

namespace WP\MCP\Core {
	// Minimal stand-in for the official mcp-adapter library, so the detection path
	// (McpAdapter::instance()->get_servers()) runs without the real dependency.
	if ( ! class_exists( 'WP\\MCP\\Core\\McpAdapter' ) ) {
		class McpAdapter {
			/** @var object[] */
			public static $servers = array();
			public static function instance() {
				return new self();
			}
			public function get_servers() {
				return self::$servers;
			}
		}
	}
}

namespace Agentomatic\Tests {

	use Agentomatic\Discovery\Envelope;
	use Agentomatic\Discovery\Registry;
	use Agentomatic\Settings;
	use PHPUnit\Framework\TestCase;

	/** A fake adapter server exposing the getters Envelope::mcp_servers() reads. */
	class FakeMcpServer {
		public function get_server_route_namespace() { return 'acme-mcp/v1'; }
		public function get_server_route() { return 'mcp'; }
		public function get_server_id() { return 'acme'; }
		public function get_server_name() { return 'Acme MCP'; }
		public function get_server_version() { return '2.0.0'; }
		public function get_tools() { return array( 'search', 'book' ); }
	}

	final class McpServerWiringTest extends TestCase {

		protected function setUp(): void {
			\_af_reset_registry();
			\_af_reset_options();
			$GLOBALS['_af_did_actions']['mcp_adapter_init'] = 1; // pretend the adapter booted
			\WP\MCP\Core\McpAdapter::$servers              = array( new FakeMcpServer() );
		}

		protected function tearDown(): void {
			\WP\MCP\Core\McpAdapter::$servers = array();
			\_af_reset_options();
		}

		private function mcp( array $settings = array() ): array {
			update_option( Settings::OPTION, $settings );
			return ( new Envelope( new Settings(), Registry::instance() ) )->mcp_surface()['mcp'];
		}

		public function test_adapter_server_is_detected_generically() {
			$mcp = $this->mcp();
			$this->assertTrue( $mcp['available'] );
			$this->assertSame( 'wordpress-mcp', $mcp['source'] );
			$this->assertSame( 2, $mcp['tools'] );
			$this->assertStringContainsString( '/wp-json/acme-mcp/v1/mcp', $mcp['endpoint'] );
		}

		public function test_auth_defaults_to_application_password_without_oauth() {
			$mcp = $this->mcp();
			$this->assertSame( 'application-password', $mcp['auth'] );
			$this->assertArrayNotHasKey( 'auth_metadata', $mcp );
		}

		public function test_declared_oauth_server_wires_auth_and_metadata() {
			$mcp = $this->mcp( array( 'oauth_auth_server' => 'https://auth.example.com' ) );
			$this->assertSame( 'oauth', $mcp['auth'] );
			$this->assertSame( 'https://example.test/.well-known/oauth-protected-resource', $mcp['auth_metadata'] );
		}

		public function test_server_card_reflects_oauth_end_to_end() {
			update_option( Settings::OPTION, array( 'oauth_auth_server' => 'https://auth.example.com' ) );
			$card = json_decode( ( new Envelope( new Settings(), Registry::instance() ) )->mcp_server_card_json(), true );

			$this->assertSame( '2.0.0', $card['serverInfo']['version'] ); // version read from the real server
			$this->assertNotEmpty( $card['transport']['url'] );
			$this->assertSame( 'oauth', $card['auth']['type'] );
			$this->assertSame( 'https://example.test/.well-known/oauth-protected-resource', $card['auth']['metadata'] );
		}
	}
}
