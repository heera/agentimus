<?php
/**
 * Schema — the site entity's image/logo emission (Phase 1 · T2).
 *
 * The entity node gains an image when one is available: a Person carries `image`,
 * an Organization carries `logo`. Absent by default (no Site Icon), and sourced from
 * the `agentimus_entity_image` filter here so the seam is exercised without WP media.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Schema;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class SchemaEntityImageTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		if ( function_exists( 'remove_all_filters' ) ) {
			remove_all_filters( 'agentimus_entity_image' );
		}
	}

	/** The site entity node from a freshly built document (the one @id'd #identity). */
	private function entity_node( array $identity = array() ): array {
		if ( ! empty( $identity ) ) {
			\update_option( Settings::OPTION, array( 'identity' => $identity ) );
		}
		$doc = ( new Schema( new Settings() ) )->build_document();
		foreach ( (array) ( isset( $doc['@graph'] ) ? $doc['@graph'] : array() ) as $node ) {
			if ( isset( $node['@id'] ) && false !== strpos( (string) $node['@id'], '#identity' ) ) {
				return (array) $node;
			}
		}
		return array();
	}

	public function test_no_image_by_default() {
		$this->assertSame( '', ( new Schema( new Settings() ) )->entity_image() );
		$node = $this->entity_node();
		$this->assertArrayNotHasKey( 'image', $node );
		$this->assertArrayNotHasKey( 'logo', $node );
	}

	public function test_person_emits_image() {
		\add_filter( 'agentimus_entity_image', function () {
			return 'https://cdn.example/me.png';
		} );
		$node = $this->entity_node( array( 'entity_type' => 'Person' ) );
		$this->assertSame( 'https://cdn.example/me.png', $node['image'] );
		$this->assertArrayNotHasKey( 'logo', $node );
	}

	public function test_organization_emits_logo() {
		\add_filter( 'agentimus_entity_image', function () {
			return 'https://cdn.example/org.png';
		} );
		$node = $this->entity_node( array( 'entity_type' => 'Organization' ) );
		$this->assertSame( 'https://cdn.example/org.png', $node['logo'] );
		$this->assertArrayNotHasKey( 'image', $node );
	}
}
