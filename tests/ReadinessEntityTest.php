<?php
/**
 * Readiness — the entity resolvability checks (Phase 1 · T2).
 *
 * Locks the two new "Trusted" rows: a Person needs a role (schema jobTitle), and the
 * site entity needs an image/logo — graded against exactly what {@see Schema} emits,
 * and standing down when Agentimus isn't the one publishing the schema.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Readiness;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class ReadinessEntityTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
		if ( function_exists( 'remove_all_filters' ) ) {
			remove_all_filters( 'agentimus_entity_image' );
		}
	}

	/** Reflection-call a private check (they read only settings — no DB). */
	private function check( string $method ): array {
		$m = new \ReflectionMethod( Readiness::class, $method );
		\_af_accessible( $m );
		return (array) $m->invoke( new Readiness( new Settings() ) );
	}

	/* -- role / jobTitle -------------------------------------------------- */

	public function test_person_without_role_warns_and_routes_to_the_role_field() {
		// Defaults: entity is a Person with no role. The action must land on the
		// ROLE input itself — ar-about sent owners typing their role into the
		// wrong field (caught live, 2026-08-01).
		$row = $this->check( 'check_entity_role' );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertSame( 'ar-role', $row['action']['anchor'] );
		$this->assertSame( 'AR-CONT-01', $row['ar'], 'Schema-feeding checks cite the JSON-LD requirement.' );
	}

	public function test_person_with_role_passes() {
		\update_option( Settings::OPTION, array( 'identity' => array( 'entity_type' => 'Person', 'role' => 'WordPress developer' ) ) );
		$row = $this->check( 'check_entity_role' );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertSame( '', $row['fix'] );
	}

	public function test_organization_role_is_not_applicable() {
		\update_option( Settings::OPTION, array( 'identity' => array( 'entity_type' => 'Organization' ) ) );
		$this->assertSame( 'pass', $this->check( 'check_entity_role' )['status'] );
	}

	/* -- entity image / logo ---------------------------------------------- */

	public function test_schema_off_stands_down_to_off() {
		// Standing down is neutral, not a pass: with schema off no image is
		// published, so there is nothing to grade — and nothing to earn.
		\update_option( Settings::OPTION, array( 'enable_schema' => false ) );
		$this->assertSame( 'off', $this->check( 'check_entity_image' )['status'] );
	}

	public function test_no_image_warns() {
		// Schema on (default), no Site Icon, no filter → nothing to show as a logo/image.
		$this->assertSame( 'warn', $this->check( 'check_entity_image' )['status'] );
	}

	public function test_filter_supplied_image_passes() {
		\add_filter( 'agentimus_entity_image', function () {
			return 'https://cdn.example/logo.png';
		} );
		$this->assertSame( 'pass', $this->check( 'check_entity_image' )['status'] );
	}
}
