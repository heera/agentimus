<?php
/**
 * A blank Name in Identity falls back to the site's own name — everywhere.
 *
 * ⛔ Found in the 2026-10-23 audit: the settings form always stores the
 * `name` key, so a field left blank is stored as '' — and identity( 'name',
 * get_bloginfo( 'name' ) ) only falls back when the key is MISSING. llms.txt
 * then headed itself with the site URL and discovery.json published an empty
 * name, while the JSON-LD on the same site (which re-checked for '') said the
 * right one. One helper now answers for every surface.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Discovery\Envelope;
use Agentimus\Discovery\Registry;
use Agentimus\LlmsText;
use Agentimus\Settings;
use PHPUnit\Framework\TestCase;

final class BlankIdentityNameTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
		\_af_reset_registry();
		$GLOBALS['_af_options'][ Settings::OPTION ] = array( 'identity' => array( 'name' => '   ' ) );
	}

	protected function tearDown(): void {
		\_af_reset_options();
		\_af_reset_registry();
	}

	public function test_display_name_falls_back_to_the_site_name() {
		$this->assertSame( 'Test Site', ( new Settings() )->display_name() );
	}

	public function test_display_name_keeps_a_name_that_was_given() {
		$GLOBALS['_af_options'][ Settings::OPTION ] = array( 'identity' => array( 'name' => 'Sheikh Heera' ) );
		$this->assertSame( 'Sheikh Heera', ( new Settings() )->display_name() );
	}

	public function test_llms_txt_is_headed_with_the_site_name_not_its_url() {
		$first = strtok( ( new LlmsText( new Settings() ) )->llms_txt(), "\n" );
		$this->assertSame( '# Test Site', $first );
	}

	public function test_discovery_names_the_site_and_the_owner() {
		$doc = ( new Envelope( new Settings(), Registry::instance() ) )->build();
		$this->assertSame( 'Test Site', $doc['site']['name'] );
		$this->assertSame( 'Test Site', $doc['identity']['name'] );
	}
}
