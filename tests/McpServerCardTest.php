<?php
/**
 * MCP server-card helpers: the card must describe ONE real server using that
 * server's real tools, so these lock the two pure pieces — extracting a server's
 * tools from the live object, and picking which server the single card represents.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\McpSurface;
use PHPUnit\Framework\TestCase;

final class McpServerCardTest extends TestCase {

	/** Reflection-call a private static method on McpSurface (server_tools/primary_server/card_tools moved there). */
	private function call( string $method, ...$args ) {
		$m = new \ReflectionMethod( McpSurface::class, $method );
		$m->setAccessible( true );
		return $m->invoke( null, ...$args );
	}

	/** A stand-in MCP server exposing get_tools() like \WP\MCP — tools may be objects or strings. */
	private function server( array $tools ) {
		return new class( $tools ) {
			private $t;
			public function __construct( array $t ) {
				$this->t = $t;
			}
			public function get_tools() {
				return $this->t;
			}
		};
	}

	/** A stand-in MCP tool object with get_name()/get_description(). */
	private function tool( string $name, string $desc = '' ) {
		return new class( $name, $desc ) {
			private $n;
			private $d;
			public function __construct( $n, $d ) {
				$this->n = $n;
				$this->d = $d;
			}
			public function get_name() {
				return $this->n;
			}
			public function get_description() {
				return $this->d;
			}
		};
	}

	/* -- server_tools(): real tools from the live server -------------------- */

	public function test_server_tools_reads_name_and_description() {
		$server = $this->server( array( $this->tool( 'fluentcart-get-orders', 'Retrieve orders.' ) ) );
		$tools  = $this->call( 'server_tools', $server );
		$this->assertSame( array( array( 'name' => 'fluentcart-get-orders', 'description' => 'Retrieve orders.' ) ), $tools );
	}

	public function test_server_tools_tolerates_string_entries_and_skips_nameless() {
		$server = $this->server( array( 'bare-tool', $this->tool( '', 'no name' ), 42 ) );
		$tools  = $this->call( 'server_tools', $server );
		$this->assertSame( array( array( 'name' => 'bare-tool', 'description' => '' ) ), $tools );
	}

	public function test_server_tools_empty_when_no_getter() {
		$this->assertSame( array(), $this->call( 'server_tools', new \stdClass() ) );
	}

	/* -- primary_server(): which server the single card represents ---------- */

	public function test_primary_server_picks_the_one_with_most_tools() {
		$servers = array(
			array( 'id' => 'default', 'tools' => 0 ),
			array( 'id' => 'fluentcart', 'tools' => 3 ),
			array( 'id' => 'other', 'tools' => 1 ),
		);
		$this->assertSame( 'fluentcart', $this->call( 'primary_server', $servers )['id'] );
	}

	public function test_primary_server_tie_breaks_by_order() {
		$servers = array(
			array( 'id' => 'a', 'tools' => 2 ),
			array( 'id' => 'b', 'tools' => 2 ),
		);
		$this->assertSame( 'a', $this->call( 'primary_server', $servers )['id'] );
	}

	public function test_primary_server_empty_when_none() {
		$this->assertSame( array(), $this->call( 'primary_server', array() ) );
	}

	/* -- card_tools(): tool_list -> card {name, description} ---------------- */

	public function test_card_tools_maps_tool_list() {
		$server = array(
			'tool_list' => array(
				array( 'name' => 'demo-store-list-products', 'description' => 'Lists store products.' ),
				array( 'name' => 'x' ), // missing description defaults to ''
			),
		);
		$this->assertSame(
			array(
				array( 'name' => 'demo-store-list-products', 'description' => 'Lists store products.' ),
				array( 'name' => 'x', 'description' => '' ),
			),
			$this->call( 'card_tools', $server )
		);
	}

	public function test_card_tools_empty_without_a_list() {
		$this->assertSame( array(), $this->call( 'card_tools', array() ) );
	}
}
