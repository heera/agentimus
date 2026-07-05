<?php
/**
 * The API-key encryption boundary, end-to-end through the real REST controller: an
 * admin saves a provider key, it is stored as CIPHERTEXT (never plaintext), and the
 * capability-gated reveal endpoint returns it decrypted. Exercises Crypto + Settings
 * + REST dispatch + the permission gate together — the P1 key-at-rest guarantee.
 *
 * @package Agentimus\Tests\Integration
 */

namespace Agentimus\Tests\Integration;

use Agentimus\Visibility\Crypto;

final class RestKeyEncryptionTest extends RestTestCase {

	public function test_a_saved_key_is_stored_encrypted_and_revealed_decrypted() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'libsodium unavailable' );
		}
		wp_set_current_user( $this->admin );

		// Save a provider key through the real config endpoint.
		$save = new \WP_REST_Request( 'POST', '/agentimus/v1/visibility/config' );
		$save->set_header( 'Content-Type', 'application/json' );
		$save->set_body(
			wp_json_encode(
				array( 'providers' => array( 'openai' => array( 'enabled' => true, 'key' => 'sk-secret-123', 'model' => 'gpt-4o-mini' ) ) )
			)
		);
		$this->assertSame( 200, rest_do_request( $save )->get_status() );

		// On disk it is ciphertext, and the plaintext never appears.
		$stored = get_option( 'agentimus_visibility' );
		$this->assertStringStartsWith( 'enc:v1:', (string) $stored['providers']['openai']['key'] );
		$this->assertStringNotContainsString( 'sk-secret-123', (string) wp_json_encode( $stored ) );

		// The reveal endpoint returns the decrypted key to an admin.
		$reveal = new \WP_REST_Request( 'POST', '/agentimus/v1/visibility/reveal-key' );
		$reveal->set_body_params( array( 'provider' => 'openai' ) );
		$resp = rest_do_request( $reveal );
		$this->assertSame( 200, $resp->get_status() );
		$this->assertSame( 'sk-secret-123', (string) ( (array) $resp->get_data() )['key'] );
	}

	public function test_a_subscriber_cannot_reveal_a_key() {
		wp_set_current_user( $this->subscriber );
		$reveal = new \WP_REST_Request( 'POST', '/agentimus/v1/visibility/reveal-key' );
		$reveal->set_body_params( array( 'provider' => 'openai' ) );
		$this->assertContains( rest_do_request( $reveal )->get_status(), array( 401, 403 ) );
	}
}
