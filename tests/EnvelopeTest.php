<?php
/**
 * Envelope — derivation of discovery.json from the Registry (spec §02).
 *
 * Covers the conformance-critical derivation: the eleven-key core (M2),
 * per-endpoint auth precedence (M11), deduplicated capabilities (M7), agent
 * derivation, and that the MCP/tools surface is kept OUT of the core.
 *
 * @package HeeraAgentDiscovery\Tests
 */

namespace HeeraAgentDiscovery\Tests;

use HeeraAgentDiscovery\Discovery\Envelope;
use HeeraAgentDiscovery\Discovery\Registry;
use HeeraAgentDiscovery\Settings;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase {

	private const CORE = array(
		'$schema', 'spec_version', 'site', 'identity', 'documents',
		'well_known', 'apis', 'agents', 'resources', 'capabilities', 'trust',
	);

	protected function setUp(): void {
		\_af_reset_registry();
		\_af_reset_options();
		Registry::instance()->register(
			array(
				'id'           => 'shop',
				'title'        => 'Shop',
				'type'         => 'commerce',
				// Duplicate token on purpose — the envelope union must dedupe it.
				'capabilities' => array( 'commerce.products.read', 'commerce.products.read', 'commerce.cart.write' ),
				'endpoints'    => array(
					array( 'url' => '/wp-json/wc/store/v1', 'type' => 'rest', 'auth' => 'none' ),
					array( 'url' => '/wp-json/wc/v3', 'type' => 'rest', 'auth' => 'apikey' ),
				),
				'agent'        => array( 'name' => 'Store Agent', 'skills' => array( array( 'id' => 'search' ) ) ),
			)
		);
	}

	private function build(): array {
		return ( new Envelope( new Settings(), Registry::instance() ) )->build();
	}

	public function test_envelope_has_exactly_the_eleven_core_keys_in_order() {
		$this->assertSame( self::CORE, array_keys( $this->build() ) );
	}

	public function test_per_endpoint_auth_wins_yielding_two_api_entries() {
		$apis = $this->build()['apis'];
		$this->assertCount( 2, $apis );
		$auths = array_map( static function ( $a ) { return $a['auth']['type']; }, $apis );
		sort( $auths );
		$this->assertSame( array( 'apikey', 'none' ), $auths );
	}

	public function test_capabilities_are_a_deduplicated_union() {
		$this->assertSame(
			array( 'commerce.products.read', 'commerce.cart.write' ),
			$this->build()['capabilities']
		);
	}

	public function test_agents_are_derived_from_the_agent_fragment() {
		$this->assertCount( 1, $this->build()['agents'] );
	}

	public function test_mcp_and_tools_are_not_in_the_core_but_are_in_mcp_surface() {
		$env = $this->build();
		$this->assertArrayNotHasKey( 'mcp', $env );
		$this->assertArrayNotHasKey( 'tools', $env );
		$this->assertArrayNotHasKey( 'spec', $env );

		$surface = ( new Envelope( new Settings(), Registry::instance() ) )->mcp_surface();
		$this->assertArrayHasKey( 'tools', $surface );
		$this->assertArrayHasKey( 'mcp', $surface );
	}

	/* -- Owner authority / publication boundary (spec §04, M14) ----------- */

	public function test_owner_suppression_removes_resource_and_its_derived_surface() {
		// A second Resource so suppression is demonstrably selective.
		Registry::instance()->register(
			array(
				'id'           => 'bookings',
				'title'        => 'Bookings',
				'type'         => 'scheduling',
				'capabilities' => array( 'scheduling.booking.create' ),
				'endpoints'    => array( array( 'url' => '/wp-json/acme/v1', 'type' => 'rest' ) ),
			)
		);
		update_option( Settings::OPTION, array( 'suppressed_resources' => array( 'shop' ) ) );

		$env = $this->build();
		$ids = array_map( static function ( $r ) { return $r['id']; }, $env['resources'] );

		// The suppressed Resource is gone everywhere it would otherwise surface.
		$this->assertNotContains( 'shop', $ids );
		$this->assertContains( 'bookings', $ids );
		$this->assertNotContains( 'commerce.products.read', $env['capabilities'] );
		$this->assertContains( 'scheduling.booking.create', $env['capabilities'] );
		$this->assertCount( 0, $env['agents'], 'The Store Agent rode on the suppressed Resource.' );
		foreach ( $env['apis'] as $api ) {
			$this->assertStringNotContainsString( '/wc/', $api['base'] );
		}
	}

	public function test_unsuppressed_resource_is_published_by_default() {
		// No suppression set — a declared Resource publishes (default-ON, spec S7).
		$ids = array_map( static function ( $r ) { return $r['id']; }, $this->build()['resources'] );
		$this->assertContains( 'shop', $ids );
	}

	/* -- Standards-aware auth docs (RFC 8414 / OIDC Discovery) ------------- */

	public function test_oauth_api_auth_docs_falls_back_to_the_standard_well_known() {
		$reg = Registry::instance();
		$reg->register(
			array(
				'id'        => 'sso',
				'title'     => 'SSO',
				'type'      => 'auth',
				'endpoints' => array( array( 'url' => '/wp-json/acme-oidc/v1', 'type' => 'rest', 'auth' => 'oidc' ) ),
			)
		);
		// The site publishes the standard OIDC discovery document (registered well-known).
		$reg->add_well_known( array( 'name' => 'openid-configuration', 'callback' => static function () { return '{}'; } ) );

		$sso = $this->api_by_id( ( new Envelope( new Settings(), $reg ) )->build()['apis'], 'sso' );
		$this->assertNotNull( $sso );
		$this->assertSame( 'oidc', $sso['auth']['type'] );
		$this->assertSame( 'https://example.test/.well-known/openid-configuration', $sso['auth']['docs'] );
	}

	public function test_auth_docs_is_empty_when_no_standard_well_known_is_served() {
		$reg = Registry::instance();
		$reg->register(
			array(
				'id'        => 'sso2',
				'title'     => 'SSO2',
				'type'      => 'auth',
				'endpoints' => array( array( 'url' => '/wp-json/acme-oidc2/v1', 'type' => 'rest', 'auth' => 'oidc' ) ),
			)
		);
		// Nothing publishes openid-configuration here → no dead link.
		$sso = $this->api_by_id( ( new Envelope( new Settings(), $reg ) )->build()['apis'], 'sso2' );
		$this->assertNotNull( $sso );
		$this->assertSame( '', $sso['auth']['docs'] );
	}

	/* -- documents{} surface ---------------------------------------------- */

	public function test_documents_lists_the_core_content_docs() {
		$docs = $this->build()['documents'];
		foreach ( array( 'sitemap', 'robots', 'feed' ) as $key ) {
			$this->assertArrayHasKey( $key, $docs );
		}
		$this->assertSame( 'https://example.test/feed/', $docs['feed'] );
		// humans.txt isn't present in the test root → not listed (no dead link).
		$this->assertArrayNotHasKey( 'humans', $docs );
	}

	/* -- well_known[] spec annotation ------------------------------------- */

	public function test_well_known_entries_are_annotated_with_their_spec() {
		$reg = Registry::instance();
		$reg->add_well_known( array( 'name' => 'security.txt', 'callback' => static function () { return ''; } ) );

		$by = array();
		foreach ( ( new Envelope( new Settings(), $reg ) )->build()['well_known'] as $entry ) {
			$by[ $entry['name'] ] = $entry;
		}

		$this->assertSame( 'WP Discovery', $by['discovery.json']['spec'] );
		$this->assertSame( 'A2A', $by['agent-card.json']['spec'] );
		$this->assertSame( 'RFC 9116', $by['security.txt']['spec'] );
	}

	private function api_by_id( array $apis, string $id ) {
		foreach ( $apis as $api ) {
			if ( $id === $api['id'] ) {
				return $api;
			}
		}
		return null;
	}
}
