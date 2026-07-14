<?php
/**
 * The MCP server is OPT-IN — Registrar::register_mcp_server() must be inert
 * until the owner flips `enable_mcp_server`, no matter who fired
 * mcp_adapter_init (another plugin booting the adapter for its own reasons
 * must not stand up OUR server).
 *
 * Also locks the transport-class resolution against the REAL bundled adapter
 * (vendor/wordpress/mcp-adapter): 0.3+ ships Transport\HttpTransport — the
 * pre-1.22 code asked for the long-gone Transport\Http\RestTransport and
 * silently created nothing.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Abilities\Registrar;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

/** A fake McpAdapter capturing create_server() calls (the real one wants to talk to WP). */
class FakeCreateServerAdapter {
	/** @var array[] One entry of positional args per create_server() call. */
	public $calls = array();
	public function create_server( ...$args ) {
		$this->calls[] = $args;
	}
}

final class McpServerGateTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	private function registrar( array $settings = array() ): Registrar {
		update_option( Settings::OPTION, $settings );
		return new Registrar( new Settings() );
	}

	public function test_server_is_not_created_when_the_toggle_is_off() {
		$adapter = new FakeCreateServerAdapter();
		$this->registrar()->register_mcp_server( $adapter ); // defaults: enable_mcp_server = false
		$this->assertSame( array(), $adapter->calls, 'The default (off) must never create a server, even when the adapter init fires.' );
	}

	public function test_server_is_created_once_with_the_scoped_route_when_enabled() {
		$adapter = new FakeCreateServerAdapter();
		$this->registrar( array( 'enable_mcp_server' => 1 ) )->register_mcp_server( $adapter );

		$this->assertCount( 1, $adapter->calls );
		$args = $adapter->calls[0];
		$this->assertSame( 'agentimus', $args[0], 'server id' );
		$this->assertSame( 'agentimus/v1', $args[1], 'REST namespace' );
		$this->assertSame( 'mcp', $args[2], 'REST route' );
		$this->assertContains( '\WP\MCP\Transport\HttpTransport', $args[6], 'must resolve the 0.3+ transport class from the bundled adapter' );
		$this->assertContains( 'agentimus/read-readiness', $args[9], 'tool list carries the read abilities' );
	}

	public function test_enabled_but_unrecognisable_adapter_is_a_clean_no_op() {
		$adapter = new \stdClass(); // no create_server() at all
		$this->registrar( array( 'enable_mcp_server' => 1 ) )->register_mcp_server( $adapter );
		$this->addToAssertionCount( 1 ); // reaching here without a fatal is the assertion
	}

	public function test_owner_can_trim_the_exposed_tools_by_filter() {
		add_filter(
			'agentimus_mcp_server_abilities',
			static function ( $names ) {
				return array( 'agentimus/read-readiness' );
			}
		);
		$adapter = new FakeCreateServerAdapter();
		$this->registrar( array( 'enable_mcp_server' => 1 ) )->register_mcp_server( $adapter );
		$this->assertSame( array( 'agentimus/read-readiness' ), $adapter->calls[0][9] );
	}
}
