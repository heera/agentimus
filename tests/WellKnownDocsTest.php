<?php
/**
 * The newer /.well-known surfaces derived by the Envelope:
 *   - api-catalog (RFC 9727, served as an RFC 9264 link set),
 *   - the MCP Server Card (gated on a real MCP server), and
 *   - the Agent Skills index (projected from agent.skills[], gated + suppressible).
 *
 * The gating is the load-bearing behaviour: a surface is emitted ONLY when the
 * real thing exists — otherwise '' → a clean 404, never a fabricated stub.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Envelope;
use Agentimus\Discovery\Registry;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class WellKnownDocsTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_registry();
		\_af_reset_options();
	}

	private function envelope(): Envelope {
		return new Envelope( new Settings(), Registry::instance() );
	}

	/* -- api-catalog (RFC 9727 / RFC 9264 link set) ----------------------- */

	public function test_api_catalog_is_a_linkset_listing_discovery_and_apis() {
		Registry::instance()->register(
			array(
				'id'        => 'shop',
				'title'     => 'Shop',
				'type'      => 'commerce',
				'endpoints' => array( array( 'url' => '/wp-json/wc/store/v1', 'type' => 'rest' ) ),
			)
		);

		$doc = json_decode( $this->envelope()->api_catalog_json(), true );
		$this->assertArrayHasKey( 'linkset', $doc );

		$ctx = $doc['linkset'][0];
		$this->assertSame( 'https://example.test/', $ctx['anchor'] );
		$this->assertContains( 'https://example.test/.well-known/discovery.json', array_column( $ctx['service-desc'], 'href' ) );
		$this->assertContains( 'https://example.test/wp-json/wc/store/v1', array_column( $ctx['service'], 'href' ) );
	}

	/* -- MCP Server Card: gated on a real server -------------------------- */

	public function test_mcp_server_card_is_empty_without_a_real_server() {
		// No MCP adapter / mcp_adapter_init in the unit env → mcp.available is false.
		$this->assertSame( '', $this->envelope()->mcp_server_card_json() );
	}

	/* -- Agent Skills index ----------------------------------------------- */

	public function test_agent_skills_index_projects_agent_skills() {
		Registry::instance()->register(
			array(
				'id'    => 'abilities-core',
				'title' => 'Core abilities',
				'type'  => 'agent',
				'agent' => array(
					'name'   => 'Core',
					'skills' => array( array( 'id' => 'get-site-info', 'description' => 'Returns site info.' ) ),
				),
			)
		);

		$doc = json_decode( $this->envelope()->agent_skills_index_json(), true );
		$this->assertSame( '2024-11-05', $doc['schemaVersion'] );
		$this->assertContains( 'get-site-info', array_column( $doc['skills'], 'id' ) );
	}

	public function test_agent_skills_index_is_empty_without_skills() {
		// A plain resource with no agent fragment → no skills → honest '' (404).
		Registry::instance()->register(
			array(
				'id'        => 'plain',
				'title'     => 'Plain',
				'type'      => 'rest',
				'endpoints' => array( array( 'url' => '/wp-json/x/v1', 'type' => 'rest' ) ),
			)
		);
		$this->assertSame( '', $this->envelope()->agent_skills_index_json() );
	}

	public function test_agent_skills_index_respects_owner_suppression() {
		Registry::instance()->register(
			array(
				'id'    => 'abilities-core',
				'title' => 'Core',
				'type'  => 'agent',
				'agent' => array( 'name' => 'Core', 'skills' => array( array( 'id' => 'get-site-info' ) ) ),
			)
		);
		update_option( Settings::OPTION, array( 'suppressed_resources' => array( 'abilities-core' ) ) );
		$this->assertSame( '', $this->envelope()->agent_skills_index_json() );
	}
}
