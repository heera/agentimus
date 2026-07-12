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

	/**
	 * THE REGRESSION. The skills index was the ONE served surface that sourced from the raw
	 * registry instead of published_resources(), so it filtered owner-suppression but not the
	 * provider's `public` flag — and leaked every gated ability's id, name and full description
	 * (scan-exposed-files among them) to anonymous callers, even after they were correctly removed
	 * from discovery.json, mcp.json and agent-card. A red-team pass caught it; this pins it.
	 */
	public function test_agent_skills_index_omits_non_public_abilities() {
		Registry::instance()->register(
			array(
				'id'     => 'abilities-agentimus',
				'title'  => 'Agentimus',
				'type'   => 'agent',
				'public' => false, // gated — nobody anonymous can run it
				'agent'  => array(
					'name'   => 'Agentimus',
					'skills' => array(
						array( 'id' => 'scan-exposed-files', 'description' => 'which sensitive paths this site probes for' ),
					),
				),
			)
		);
		// A genuinely public third-party skill, to prove we suppress the gated one WITHOUT nuking
		// the whole surface.
		Registry::instance()->register(
			array(
				'id'    => 'acme-bookings',
				'title' => 'Bookings',
				'type'  => 'agent',
				'agent' => array( 'name' => 'Bookings', 'skills' => array( array( 'id' => 'book-slot' ) ) ),
			)
		);

		$json = $this->envelope()->agent_skills_index_json();

		$this->assertStringNotContainsString( 'scan-exposed-files', $json, 'A gated ability must not appear in the anonymous skills index.' );
		$this->assertStringNotContainsString( 'sensitive paths', $json, 'Nor may its description leak there.' );
		$this->assertStringContainsString( 'book-slot', $json, 'A public third-party skill must still be advertised.' );
	}

	/* -- Owner suppression must reach EVERY served surface, not just some ---- */

	/**
	 * THE REGRESSION. McpSurface::mcp_surface() collected straight from the registry — "so callers
	 * don't round-trip through the full envelope" — which silently bypassed owner suppression. A
	 * Resource the owner had suppressed was still published at the PUBLIC /.well-known/mcp.json,
	 * with every tool's description and full input/output schemas.
	 *
	 * Envelope::build()'s own comment claimed the filter kept suppressed Resources out of "every
	 * served surface". mcp.json is a served surface. It did not. There was a test for the skills
	 * index and none for mcp.json, which is exactly why it survived.
	 *
	 * A provider proposes, the owner disposes — on every surface, or the rule is decoration.
	 */
	public function test_mcp_json_respects_owner_suppression() {
		Registry::instance()->register(
			array(
				'id'    => 'abilities-secret',
				'title' => 'Secret',
				'type'  => 'agent',
				'tools' => array(
					array(
						'name'        => 'secret/do-thing',
						'title'       => 'Do the thing',
						'description' => 'Sensitive internals an owner asked us not to publish.',
						'inputSchema' => array( 'type' => 'object' ),
					),
				),
			)
		);

		// Unsuppressed: published, as expected.
		$this->assertStringContainsString( 'secret/do-thing', $this->envelope()->mcp_json() );

		// The owner says no. It must vanish from mcp.json too — not just discovery.json.
		update_option( Settings::OPTION, array( 'suppressed_resources' => array( 'abilities-secret' ) ) );
		$json = $this->envelope()->mcp_json();

		$this->assertStringNotContainsString( 'secret/do-thing', $json, 'A suppressed Resource must not be published to mcp.json.' );
		$this->assertStringNotContainsString( 'Sensitive internals', $json, 'Nor may its tool schemas leak there.' );
	}

	public function test_the_admin_still_sees_a_suppressed_resource_so_it_can_be_re_enabled() {
		Registry::instance()->register(
			array( 'id' => 'abilities-secret', 'title' => 'Secret', 'type' => 'agent' )
		);
		update_option( Settings::OPTION, array( 'suppressed_resources' => array( 'abilities-secret' ) ) );

		// all_resources() is the admin's view: suppressed Resources are FLAGGED, never dropped, or
		// the owner could never turn one back on.
		$ids = array_column( $this->envelope()->all_resources(), 'id' );
		$this->assertContains( 'abilities-secret', $ids );

		// ...while the published view drops it.
		$published = array_column( $this->envelope()->published_resources(), 'id' );
		$this->assertNotContains( 'abilities-secret', $published );
	}

	/* -- Gated abilities are not advertised to anonymous callers ------------ */

	/**
	 * A Resource nobody anonymous can use must not appear in ANY served document. Advertising it
	 * gives the agent nothing it can call, while handing anyone who asks a map of the site's
	 * tooling — every tool's description and full input/output schemas.
	 */
	public function test_a_non_public_resource_reaches_no_served_surface() {
		Registry::instance()->register(
			array(
				'id'     => 'abilities-locked',
				'title'  => 'Locked',
				'type'   => 'agent',
				'public' => false,
				'tools'  => array(
					array(
						'name'        => 'locked/scan-exposed-files',
						'title'       => 'Scan',
						'description' => 'Which sensitive paths this site probes for.',
						'inputSchema' => array( 'type' => 'object' ),
					),
				),
			)
		);

		$envelope = $this->envelope();

		$ids = array_column( $envelope->build()['resources'], 'id' );
		$this->assertNotContains( 'abilities-locked', $ids, 'discovery.json must not advertise it.' );

		$mcp = $envelope->mcp_json();
		$this->assertStringNotContainsString( 'locked/scan-exposed-files', $mcp, 'mcp.json must not advertise it.' );
		$this->assertStringNotContainsString( 'sensitive paths', $mcp, 'Nor may its schemas/descriptions leak there.' );
	}

	public function test_the_owner_still_sees_a_non_public_resource_in_the_admin() {
		// It is held back from agents, not hidden from the owner — they must be able to see what
		// their own site exposes, and the UI flags it rather than listing it as if it were live.
		Registry::instance()->register(
			array( 'id' => 'abilities-locked', 'title' => 'Locked', 'type' => 'agent', 'public' => false )
		);

		$ids = array_column( $this->envelope()->all_resources(), 'id' );
		$this->assertContains( 'abilities-locked', $ids );
	}

	public function test_public_defaults_to_true_so_existing_providers_are_unaffected() {
		// A provider that says nothing keeps publishing exactly as before. This flag must never
		// silently un-advertise somebody else's resource.
		Registry::instance()->register(
			array( 'id' => 'shop', 'title' => 'Shop', 'type' => 'commerce' )
		);

		$ids = array_column( $this->envelope()->build()['resources'], 'id' );
		$this->assertContains( 'shop', $ids );
	}

	/**
	 * discovery.json ships a canonical JSON Schema, so an extra key on a resource item would make a
	 * strict (additionalProperties:false) consumer reject the whole document. `public` is an
	 * internal publication-boundary flag and must be stripped from the served output — while the
	 * admin path (all_resources) keeps it to flag held-back resources.
	 */
	public function test_served_resources_do_not_leak_the_internal_public_flag() {
		Registry::instance()->register(
			array( 'id' => 'shop', 'title' => 'Shop', 'type' => 'commerce' )
		);
		$resources = $this->envelope()->build()['resources'];
		$this->assertNotEmpty( $resources );
		foreach ( $resources as $r ) {
			$this->assertArrayNotHasKey( 'public', $r, 'The internal publication flag must not reach the wire.' );
		}
	}
}
