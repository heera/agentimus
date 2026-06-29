<?php
/**
 * WebMcp — the opt-in, experimental browser-tool bridge.
 *
 * Two surfaces are tested. registered_tools() (public) is every tool the site
 * registers — the built-in read-only site search plus anything a provider adds
 * via the `agentimus_webmcp_tools` filter; it backs the admin panel. tools()
 * (private) is what's actually exposed to browser agents: the registered set
 * minus the owner's per-tool hide list (`webmcp_hidden_tools`). Also locks the
 * default-OFF guarantee — a fresh install adds no front-end script.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Settings;
use Agentimus\WebMcp;
use PHPUnit\Framework\TestCase;

final class WebMcpTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	/** Every tool the site registers (baseline + filter), before the hide list. */
	private function registered(): array {
		return ( new WebMcp( new Settings() ) )->registered_tools();
	}

	/** The tools actually exposed to agents — registered minus the owner's hide list. */
	private function exposed(): array {
		$method = new \ReflectionMethod( WebMcp::class, 'tools' );
		$method->setAccessible( true );
		return (array) $method->invoke( new WebMcp( new Settings() ) );
	}

	/** Find a tool by name in a manifest. */
	private function find( array $tools, string $name ) {
		foreach ( $tools as $tool ) {
			if ( isset( $tool['name'] ) && $name === $tool['name'] ) {
				return $tool;
			}
		}
		return null;
	}

	/* -- The default tool ------------------------------------------------- */

	public function test_ships_a_callable_read_only_site_search_tool() {
		$search = $this->find( $this->registered(), 'search_site' );

		$this->assertIsArray( $search, 'A default install must register site search.' );
		$this->assertSame( 'GET', $search['method'], 'Search must be a read-only GET.' );
		$this->assertStringContainsString( 'wp/v2/search', $search['endpoint'], 'Search must hit the real core REST endpoint, not a dead URL.' );
		$this->assertSame( array( 'search' ), $search['inputSchema']['required'] );
		$this->assertArrayHasKey( 'search', $search['inputSchema']['properties'] );
	}

	/* -- Provider extension via the filter -------------------------------- */

	public function test_filter_can_add_a_provider_tool() {
		add_filter(
			'agentimus_webmcp_tools',
			static function ( $tools ) {
				$tools[] = array(
					'name'        => 'check_availability',
					'description' => 'List open slots.',
					'inputSchema' => array( 'type' => 'object', 'properties' => array() ),
					'endpoint'    => 'https://example.test/wp-json/orbit/v1/availability',
					'method'      => 'GET',
				);
				return $tools;
			}
		);

		$this->assertNotNull( $this->find( $this->registered(), 'check_availability' ) );
	}

	public function test_malformed_filter_entries_are_dropped() {
		add_filter(
			'agentimus_webmcp_tools',
			static function ( $tools ) {
				$tools[] = array( 'description' => 'no name, no endpoint' ); // missing name + endpoint
				$tools[] = array( 'name' => 'no_endpoint' );                  // missing endpoint
				$tools[] = 'not-an-array';                                    // wrong type
				return $tools;
			}
		);

		$tools = $this->registered();
		foreach ( $tools as $tool ) {
			$this->assertIsArray( $tool );
			$this->assertNotEmpty( $tool['name'] );
			$this->assertNotEmpty( $tool['endpoint'] );
		}
		$this->assertNotNull( $this->find( $tools, 'search_site' ), 'The valid baseline tool survives the cull.' );
	}

	public function test_filter_returning_a_non_array_is_handled_safely() {
		add_filter( 'agentimus_webmcp_tools', static function () { return 'boom'; } );
		$this->assertSame( array(), $this->registered(), 'A hostile filter return must not fatal — just yield no tools.' );
	}

	/* -- Per-tool hide list ----------------------------------------------- */

	public function test_hidden_tool_is_excluded_from_exposed_output_but_still_registered() {
		update_option( Settings::OPTION, array( 'webmcp_hidden_tools' => array( 'search_site' ) ) );

		$this->assertNotNull( $this->find( $this->registered(), 'search_site' ), 'A hidden tool is still REGISTERED (the admin can re-expose it).' );
		$this->assertNull( $this->find( $this->exposed(), 'search_site' ), 'A hidden tool must NOT be exposed to browser agents.' );
	}

	public function test_tools_are_exposed_by_default() {
		// Empty hide list (the default) → everything registered is exposed.
		$this->assertNotNull( $this->find( $this->exposed(), 'search_site' ) );
		$this->assertSame( array(), ( new Settings() )->defaults()['webmcp_hidden_tools'] );
	}

	public function test_hide_list_is_sanitised_to_tool_name_safe_strings() {
		$clean = ( new Settings() )->sanitize( array( 'webmcp_hidden_tools' => array( 'search_site', 'bad name!<script>', 'search_site' ) ) );
		$this->assertContains( 'search_site', $clean['webmcp_hidden_tools'] );
		$this->assertNotContains( 'bad name!<script>', $clean['webmcp_hidden_tools'], 'Unsafe characters must be stripped.' );
		$this->assertSame( count( $clean['webmcp_hidden_tools'] ), count( array_unique( $clean['webmcp_hidden_tools'] ) ), 'No duplicates.' );
	}

	/* -- Default-OFF guarantee -------------------------------------------- */

	public function test_feature_is_off_by_default() {
		$this->assertFalse( ( new Settings() )->defaults()['enable_webmcp'] );
		$this->assertFalse( ( new Settings() )->sanitize( array() )['enable_webmcp'] );
		$this->assertTrue( ( new Settings() )->sanitize( array( 'enable_webmcp' => '1' ) )['enable_webmcp'] );
	}
}
