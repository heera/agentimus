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
 * @package Agentimus\Tests
 */

namespace WP\MCP\Core {
	// Minimal stand-in for the official mcp-adapter library, so the detection path
	// (McpAdapter::instance()->get_servers()) runs without the real dependency.
	// The second class_exists arg (autoload: FALSE) is load-bearing: the REAL
	// adapter now lives in vendor/ (bundled since 1.22), so an autoloading probe
	// would pull it in, skip this stub, and the `$servers` static below would
	// fatal against the real class. PHPUnit includes every test file before
	// running, so the stub claims the name first and the real class never loads
	// in the unit suite.
	if ( ! class_exists( 'WP\\MCP\\Core\\McpAdapter', false ) ) {
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

namespace Agentimus\Tests {

	use Agentimus\Discovery\Envelope;
	use Agentimus\Discovery\Registry;
	use Agentimus\Settings;
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

	/** A 0.5-era tool DTO: camelCase accessors (php-mcp-schema), nullable description. */
	class FakeDtoTool {
		public function getName() { return 'acme-lookup'; }
		public function getDescription() { return null; }
	}

	/** A server whose get_tools() returns 0.5-shaped DTOs instead of strings. */
	class FakeDtoMcpServer extends FakeMcpServer {
		public function get_tools() { return array( new FakeDtoTool() ); }
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

		public function test_dto_shaped_tools_are_read_not_dropped() {
			// Adapter 0.5 returns php-mcp-schema DTOs (getName/getDescription) from
			// get_tools(); the projection must read them, or every server reads as
			// tool-less — tools:0 in mcp.json and a 404 on its per-id server card.
			\WP\MCP\Core\McpAdapter::$servers = array( new FakeDtoMcpServer() );
			$mcp = $this->mcp();
			$this->assertSame( 1, $mcp['tools'] );
			$this->assertSame( 'acme-lookup', $mcp['servers'][0]['tool_list'][0]['name'] );
			$this->assertSame( '', $mcp['servers'][0]['tool_list'][0]['description'], 'null description reads as empty string' );
			$this->assertArrayHasKey( 'card', $mcp['servers'][0], 'a tool-bearing server links its per-id card' );
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

		public function test_server_card_translates_application_password_to_http_basic() {
			// The card must be readable by clients that never heard of WordPress:
			// an application password is HTTP Basic (RFC 7617), so that is what
			// the card says — with the WordPress name kept as the description.
			$card = json_decode( ( new Envelope( new Settings(), Registry::instance() ) )->mcp_server_card_json(), true );
			$this->assertSame( 'http', $card['auth']['type'] );
			$this->assertSame( 'basic', $card['auth']['scheme'] );
			$this->assertSame( 'WordPress application password', $card['auth']['description'] );
		}

		public function test_server_card_reflects_oauth_end_to_end() {
			update_option( Settings::OPTION, array( 'oauth_auth_server' => 'https://auth.example.com' ) );
			$card = json_decode( ( new Envelope( new Settings(), Registry::instance() ) )->mcp_server_card_json(), true );

			$this->assertSame( '2.0.0', $card['serverInfo']['version'] ); // version read from the real server
			$this->assertNotEmpty( $card['transport']['url'] );
			// The descriptor says 'oauth'; the card translates to the standard name.
			$this->assertSame( 'oauth2', $card['auth']['type'] );
			$this->assertSame( 'https://example.test/.well-known/oauth-protected-resource', $card['auth']['metadata'] );
		}
	}
}
