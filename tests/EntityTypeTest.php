<?php
/**
 * Entity-type model: the filterable schema.org type list and its sanitisation.
 *
 * Locks: the default list covers a person + the common Organization subtypes
 * (so a shop = Store is selectable), 'Person' is always present, a valid subtype
 * survives sanitise, and anything off-list falls back to 'Person'.
 *
 * @package Agentomatic\Tests
 */

namespace Agentomatic\Tests;

use Agentomatic\Settings;
use PHPUnit\Framework\TestCase;

final class EntityTypeTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	public function test_default_list_covers_person_and_org_subtypes() {
		$types = ( new Settings() )->entity_types();
		$this->assertContains( 'Person', $types );
		$this->assertContains( 'Organization', $types );
		$this->assertContains( 'LocalBusiness', $types );
		$this->assertContains( 'Store', $types ); // a shop
	}

	public function test_person_is_always_present() {
		// Even if a (stubbed) filter returned junk, the fallback must stay valid.
		$this->assertContains( 'Person', ( new Settings() )->entity_types() );
	}

	public function test_sanitise_accepts_a_valid_subtype() {
		$clean = ( new Settings() )->sanitize( array( 'identity' => array( 'entity_type' => 'Store' ) ) );
		$this->assertSame( 'Store', $clean['identity']['entity_type'] );
	}

	public function test_sanitise_rejects_an_unknown_type() {
		$clean = ( new Settings() )->sanitize( array( 'identity' => array( 'entity_type' => 'Spaceship' ) ) );
		$this->assertSame( 'Person', $clean['identity']['entity_type'] );
	}

	public function test_sanitise_defaults_to_person_when_absent() {
		$clean = ( new Settings() )->sanitize( array( 'identity' => array() ) );
		$this->assertSame( 'Person', $clean['identity']['entity_type'] );
	}
}
