<?php
/**
 * Visibility\Crypto — API keys are encrypted at rest so a database-only exposure can't
 * hand over the owner's paid provider keys. Covers the round-trip, the legacy-plaintext
 * passthrough (installs that predate encryption), migrate-on-save idempotence, and that
 * tampered ciphertext fails closed to '' rather than yielding garbage.
 *
 * @package Agentimus\Tests
 */

namespace Agentimus\Tests;

use Agentimus\Visibility\Crypto;
use Agentimus\Visibility\Settings;
use PHPUnit\Framework\TestCase;

final class VisibilityCryptoTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	public function test_round_trip_recovers_the_secret_and_the_ciphertext_hides_it() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
		$secret = 'sk-ant-api03-THIS-IS-SECRET-xyz';
		$enc    = Crypto::encrypt( $secret );

		$this->assertStringStartsWith( 'enc:v1:', $enc );
		$this->assertStringNotContainsString( $secret, $enc, 'the plaintext must not appear in the ciphertext' );
		$this->assertSame( $secret, Crypto::decrypt( $enc ) );
	}

	public function test_empty_stays_empty_both_ways() {
		$this->assertSame( '', Crypto::encrypt( '' ) );
		$this->assertSame( '', Crypto::decrypt( '' ) );
	}

	public function test_a_legacy_plaintext_key_is_passed_through_on_decrypt() {
		// A key stored before encryption existed has no prefix → returned unchanged, so
		// an upgraded install keeps working until its next save re-encrypts it.
		$this->assertSame( 'sk-legacy-PLAINTEXT', Crypto::decrypt( 'sk-legacy-PLAINTEXT' ) );
	}

	public function test_encrypt_if_needed_migrates_plaintext_but_leaves_ciphertext_untouched() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
		$enc = Crypto::encrypt_if_needed( 'sk-plaintext-key' );
		$this->assertStringStartsWith( 'enc:v1:', $enc );
		$this->assertSame( 'sk-plaintext-key', Crypto::decrypt( $enc ) );

		// Already encrypted → returned byte-for-byte (no re-encryption / nonce churn).
		$this->assertSame( $enc, Crypto::encrypt_if_needed( $enc ) );
	}

	public function test_tampered_ciphertext_fails_closed_to_empty() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
		$enc      = Crypto::encrypt( 'sk-secret-value' );
		$tampered = 'enc:v1:' . strrev( substr( $enc, strlen( 'enc:v1:' ) ) );
		$this->assertSame( '', Crypto::decrypt( $tampered ), 'authenticated decryption must reject tampering' );
	}

	/* -- Settings integration: stored encrypted, read back decrypted ------- */

	public function test_settings_store_the_key_encrypted_and_read_it_back_decrypted() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
		( new Settings() )->update(
			array( 'providers' => array( 'openai' => array( 'enabled' => true, 'key' => 'sk-PLAINTEXT-123', 'model' => 'gpt-4o-mini' ) ) )
		);

		// On disk it is ciphertext, never the plaintext.
		$stored = get_option( Settings::OPTION );
		$this->assertStringStartsWith( 'enc:v1:', (string) $stored['providers']['openai']['key'] );
		$this->assertStringNotContainsString( 'sk-PLAINTEXT-123', wp_json_encode( $stored ) );

		// A fresh read decrypts for use — both entry points.
		$fresh = new Settings();
		$this->assertSame( 'sk-PLAINTEXT-123', $fresh->provider_key( 'openai' ) );
		$active = $fresh->active_providers();
		$this->assertSame( 'sk-PLAINTEXT-123', $active['openai']['key'] );
	}

	public function test_saving_with_a_masked_key_preserves_the_stored_encrypted_key() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
		( new Settings() )->update(
			array( 'providers' => array( 'openai' => array( 'enabled' => true, 'key' => 'sk-KEEPME', 'model' => 'gpt-4o-mini' ) ) )
		);
		// Re-save with the mask (what an untouched password field sends): the key must survive.
		( new Settings() )->update(
			array( 'providers' => array( 'openai' => array( 'enabled' => true, 'key' => Settings::KEY_MASK, 'model' => 'gpt-4o-mini' ) ) )
		);

		$this->assertSame( 'sk-KEEPME', ( new Settings() )->provider_key( 'openai' ) );
	}
}
