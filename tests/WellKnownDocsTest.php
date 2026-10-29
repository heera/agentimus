<?php
/**
 * The newer /.well-known surfaces derived by the Envelope:
 *   - api-catalog (RFC 9727, served as an RFC 9264 link set),
 *   - the MCP Server Card (gated on a real MCP server), and
 *   - the Agent Skills index (agentskills.io discovery: the site's own SKILL.md).
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

		// RFC 9727 Appendix A.2: the FIRST entry is anchored at the catalog's own
		// well-known URI and lists every API as an `item` bookmark — the shape
		// catalog validators check (linkset[0].item[] non-empty).
		$ctx = $doc['linkset'][0];
		$this->assertSame( 'https://example.test/.well-known/api-catalog', $ctx['anchor'] );
		$items = array_column( $ctx['item'], 'href' );
		$this->assertContains( 'https://example.test/wp-json/', $items );
		$this->assertContains( 'https://example.test/wp-json/wc/store/v1', $items );

		// The REST root's own entry carries its service description (the OpenAPI doc).
		$anchors = array_column( $doc['linkset'], 'anchor' );
		$rest    = $doc['linkset'][ array_search( 'https://example.test/wp-json/', $anchors, true ) ];
		$this->assertContains( 'https://example.test/.well-known/openapi.json', array_column( $rest['service-desc'], 'href' ) );

		// The site-level entry (kept for consumers of the previous shape) still
		// links the discovery document.
		$site = $doc['linkset'][ array_search( 'https://example.test/', $anchors, true ) ];
		$this->assertContains( 'https://example.test/.well-known/discovery.json', array_column( $site['service-desc'], 'href' ) );
	}

	/* -- MCP Server Card: gated on a real server -------------------------- */

	public function test_mcp_server_card_is_empty_without_a_real_server() {
		// No MCP adapter / mcp_adapter_init in the unit env → mcp.available is false.
		$this->assertSame( '', $this->envelope()->mcp_server_card_json() );
	}

	/* -- Flat RFC 9728 protected-resource metadata ------------------------- */

	public function test_flat_prm_serves_the_builtin_server_when_no_external_is_declared() {
		// Scanners probe only the flat /.well-known/oauth-protected-resource; while
		// the built-in OAuth server is live, a 404 there reads as "no OAuth on this
		// domain" with a working authorization server one path segment away.
		update_option( \Agentimus\Settings::OPTION, array( 'enable_mcp_server' => true ) );
		$doc = json_decode( $this->envelope()->oauth_protected_resource_json(), true );
		$this->assertSame( 'https://example.test/wp-json/agentimus/v1/mcp', $doc['resource'] );
		$this->assertSame( array( 'https://example.test/agentimus/mcp' ), $doc['authorization_servers'] );
	}

	public function test_flat_prm_prefers_the_owner_declared_external_server() {
		// The owner's explicit statement about the whole site outranks the built-in.
		update_option( \Agentimus\Settings::OPTION, array( 'enable_mcp_server' => true, 'oauth_auth_server' => 'https://auth.example.com' ) );
		$doc = json_decode( $this->envelope()->oauth_protected_resource_json(), true );
		$this->assertSame( array( 'https://auth.example.com' ), $doc['authorization_servers'] );
	}

	public function test_flat_prm_stays_a_404_with_neither_server() {
		$this->assertSame( '', $this->envelope()->oauth_protected_resource_json() );
	}

	public function test_flat_prm_defers_to_a_provider_registered_document() {
		// A provider proposes, the owner disposes — and the built-in server must
		// not shadow a provider's own flat PRM. Only the owner's explicit external
		// declaration outranks it.
		Registry::instance()->add_well_known(
			array(
				'name'     => 'oauth-protected-resource',
				'callback' => static function () {
					return '{"resource":"https://example.test/","authorization_servers":["https://sso.acme.example"]}';
				},
			)
		);
		update_option( \Agentimus\Settings::OPTION, array( 'enable_mcp_server' => true ) );
		$this->assertSame( '', $this->envelope()->oauth_protected_resource_json(), 'the built-in doc must yield so the router falls through to the provider' );

		// The owner's declared external server still wins over everything.
		update_option( \Agentimus\Settings::OPTION, array( 'enable_mcp_server' => true, 'oauth_auth_server' => 'https://auth.example.com' ) );
		$doc = json_decode( $this->envelope()->oauth_protected_resource_json(), true );
		$this->assertSame( array( 'https://auth.example.com' ), $doc['authorization_servers'] );
	}

	/* -- Agent Skills index (agentskills.io discovery 0.2.0) -------------- */

	/**
	 * The index lists the site's own SKILL.md in the discovery spec's shape:
	 * `$schema` + `skills[]` of name / type / description / url / digest, the
	 * description matching the SKILL.md frontmatter and the digest taken over the
	 * exact bytes served there. On heera.it it answered 404 while the SKILL.md it
	 * should have listed was live.
	 */
	public function test_agent_skills_index_lists_the_site_skill_md() {
		$doc = json_decode( $this->envelope()->agent_skills_index_json(), true );

		$this->assertSame( 'https://schemas.agentskills.io/discovery/0.2.0/schema.json', $doc['$schema'] );
		$this->assertSame( array( '$schema', 'skills' ), array_keys( $doc ) );
		$this->assertCount( 1, $doc['skills'] );

		$skill = $doc['skills'][0];
		$this->assertSame( array( 'name', 'type', 'description', 'url', 'digest' ), array_keys( $skill ) );
		$this->assertSame( 'example-test', $skill['name'] );
		$this->assertSame( 'skill-md', $skill['type'] );
		$this->assertSame( 'https://example.test/.well-known/agent-skills/example-test/SKILL.md', $skill['url'] );

		$md = $this->envelope()->skill_md();
		$this->assertSame( 'sha256:' . hash( 'sha256', $md ), $skill['digest'] );
		$this->assertStringContainsString( 'description: "' . $skill['description'] . '"', $md, 'the index description must match the SKILL.md frontmatter' );
	}

	/**
	 * Ability "skills" have no SKILL.md behind them, so the spec has no entry type
	 * for them — they stay on the agent card. Neither a public nor a gated one may
	 * reach this index (the gated one once leaked here; this keeps it pinned).
	 */
	public function test_agent_skills_index_carries_no_ability_entries() {
		Registry::instance()->register(
			array(
				'id'     => 'abilities-agentimus',
				'title'  => 'Agentimus',
				'type'   => 'agent',
				'public' => false,
				'agent'  => array(
					'name'   => 'Agentimus',
					'skills' => array(
						array( 'id' => 'scan-exposed-files', 'description' => 'which sensitive paths this site probes for' ),
					),
				),
			)
		);
		Registry::instance()->register(
			array(
				'id'    => 'acme-bookings',
				'title' => 'Bookings',
				'type'  => 'agent',
				'agent' => array( 'name' => 'Bookings', 'skills' => array( array( 'id' => 'book-slot' ) ) ),
			)
		);

		$json = $this->envelope()->agent_skills_index_json();

		$this->assertStringNotContainsString( 'scan-exposed-files', $json );
		$this->assertStringNotContainsString( 'sensitive paths', $json );
		$this->assertStringNotContainsString( 'book-slot', $json );
		$this->assertSame( array( 'example-test' ), array_column( json_decode( $json, true )['skills'], 'name' ) );
	}

	/**
	 * The filter takes spec entries. One a client could not use — a bad name, an
	 * unknown type, no digest — is left out rather than published.
	 */
	public function test_agent_skills_filter_keeps_only_spec_entries() {
		$good = array(
			'name'        => 'bookings',
			'type'        => 'archive',
			'description' => 'Book a slot.',
			'url'         => '/.well-known/agent-skills/bookings.tar.gz',
			'digest'      => 'sha256:' . str_repeat( 'a', 64 ),
		);
		$bad = array(
			array_merge( $good, array( 'name' => 'Bad_Name' ) ),
			array_merge( $good, array( 'type' => 'executable' ) ),
			array_merge( $good, array( 'digest' => 'md5:abc' ) ),
			array( 'id' => 'book-slot', 'name' => 'Book slot' ),
		);
		add_filter(
			'agentimus_agent_skills',
			function ( $skills ) use ( $good, $bad ) {
				return array_merge( $skills, array( $good ), $bad );
			}
		);

		$doc = json_decode( $this->envelope()->agent_skills_index_json(), true );
		$this->assertSame( array( 'example-test', 'bookings' ), array_column( $doc['skills'], 'name' ) );
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

	/* -- The publication boundary is drawn per TOOL, on every surface ------- */

	/**
	 * A vendor with a MIXED hand — some abilities marked `mcp.public`, some not —
	 * publishes exactly the hand they dealt. discovery.json already draws that line
	 * per tool ({@see Envelope::wire_resource()}); mcp.json must draw the same one.
	 *
	 * The group as a whole IS published, because at least one tool is advertised,
	 * so a resource-level check can never catch this: the kept-back tool rides in
	 * on a published resource, carrying its full description and input schema.
	 */
	public function test_mcp_json_does_not_publish_a_tool_the_vendor_kept_back() {
		Registry::instance()->register(
			array(
				'id'    => 'abilities-mixed',
				'title' => 'Mixed',
				'type'  => 'agent',
				'tools' => array(
					array(
						'name'        => 'mixed/list-products',
						'title'       => 'List products',
						'description' => 'The vendor asked us to advertise this one.',
						'public'      => true,
						'inputSchema' => array( 'type' => 'object' ),
					),
					array(
						'name'        => 'mixed/delete-everything',
						'title'       => 'Delete everything',
						'description' => 'Internals the vendor deliberately kept back.',
						'public'      => false,
						'inputSchema' => array( 'type' => 'object' ),
					),
				),
			)
		);

		$mcp = $this->envelope()->mcp_json();

		$this->assertStringContainsString( 'mixed/list-products', $mcp, 'What the vendor advertised must be published.' );
		$this->assertStringNotContainsString( 'mixed/delete-everything', $mcp, 'What the vendor kept back must not be.' );
		$this->assertStringNotContainsString( 'deliberately kept back', $mcp, 'Nor may its description leak there.' );
	}

	/**
	 * `public` and `uri` are the admin's keys, for the same reason `kind` is — and
	 * `public` is the dangerous one to leak. Printed beside `"auth": "wp"` on the
	 * same tool, the word says "anyone may run this" to a machine reading the
	 * document, which is the exact misreading that once shipped 32 tools as "no
	 * sign-in needed" ({@see \Agentimus\Discovery\Adapters\AbilitiesApi}).
	 */
	public function test_served_tools_do_not_leak_the_admin_only_keys() {
		Registry::instance()->register(
			array(
				'id'    => 'abilities-shop',
				'title' => 'Shop jobs',
				'type'  => 'agent',
				'tools' => array(
					array(
						'name'        => 'shop/list-orders',
						'title'       => 'List orders',
						'description' => 'Reads the order list.',
						'inputSchema' => array( 'type' => 'object' ),
					),
				),
			)
		);

		$doc = json_decode( $this->envelope()->mcp_json(), true );
		$this->assertNotEmpty( $doc['tools'] );
		foreach ( $doc['tools'] as $tool ) {
			$this->assertArrayNotHasKey( 'public', $tool, 'The internal publication flag must not reach the wire.' );
			$this->assertArrayNotHasKey( 'uri', $tool, 'The admin-only document link must not reach the wire.' );
			$this->assertArrayNotHasKey( 'kind', $tool, 'Already stripped — kept here so it stays stripped.' );
			$this->assertArrayHasKey( 'auth', $tool, 'What running it takes is the honest answer, and it stays.' );
		}
	}
}
