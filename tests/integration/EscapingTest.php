<?php
/**
 * Escaping against REAL WordPress functions. The unit suite stubs esc_url_raw as a
 * bare trim and wp_json_encode as plain json_encode, so the security-relevant
 * behaviour — dropping a javascript: URL, slash-escaping a </script> so it can't
 * break out of the JSON-LD <script> tag — is only verifiable here.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Schema;
use Agentimus\Settings;
use WP_UnitTestCase;

final class EscapingTest extends WP_UnitTestCase {

	public function test_real_esc_url_drops_a_javascript_same_as_url() {
		$clean = ( new Settings() )->sanitize(
			array(
				'identity' => array(
					'same_as' => array( 'javascript:alert(document.cookie)', 'https://mastodon.example/@me' ),
				),
			)
		);
		$this->assertNotContains( 'javascript:alert(document.cookie)', $clean['identity']['same_as'], 'the javascript: URL is dropped' );
		$this->assertContains( 'https://mastodon.example/@me', $clean['identity']['same_as'], 'a legitimate profile URL survives' );
	}

	public function test_a_script_breakout_in_a_value_cannot_escape_the_jsonld_tag() {
		// Store a hostile identity name directly (bypassing sanitize), to prove the
		// OUTPUT layer is safe on its own: whether by tag-stripping or slash-escaping,
		// the emitted JSON-LD must carry no raw </script> that could close the
		// <script type="application/ld+json"> tag Schema::output() prints it in.
		update_option( 'agentimus_settings', array( 'identity' => array( 'name' => 'Acme </script><script>alert(1)</script>' ) ) );

		$doc  = ( new Schema( new Settings() ) )->build_document( null, true );
		$json = wp_json_encode( $doc, JSON_UNESCAPED_UNICODE ); // the exact flags Schema::output() uses.

		$this->assertStringNotContainsString( '</script>', $json );
		$this->assertStringNotContainsString( '</', $json, 'no raw closing-tag sequence survives encoding' );
	}
}
