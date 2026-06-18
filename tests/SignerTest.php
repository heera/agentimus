<?php
/**
 * Signer — Web Bot Auth / HTTP Message Signatures for the discovery docs.
 *
 * Locks the behaviour that matters: off by default (no keys, no headers), the
 * published key directory is a valid Ed25519 JWKS, and a produced signature
 * actually verifies against that published key (RFC 9421 base reconstructed from
 * the emitted headers).
 *
 * @package Agentomatic\Tests
 */

namespace Agentomatic\Tests;

use Agentomatic\Discovery\Signer;
use Agentomatic\Settings;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase {

	protected function setUp(): void {
		\_af_reset_options();
	}

	protected function tearDown(): void {
		\_af_reset_options();
	}

	private function enabled_signer(): Signer {
		update_option( Settings::OPTION, array( 'enable_signing' => true ) );
		return new Signer( new Settings() );
	}

	public function test_off_by_default_emits_nothing() {
		$signer = new Signer( new Settings() );
		$this->assertFalse( $signer->enabled() );
		$this->assertSame( '', $signer->directory() );
		$this->assertSame( '', $signer->directory_url() );
		$this->assertSame( array(), $signer->sign( 'body', 'https://example.test/x' ) );
	}

	public function test_directory_is_an_ed25519_jwks() {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
		$jwk = json_decode( $this->enabled_signer()->directory(), true )['keys'][0];
		$this->assertSame( 'OKP', $jwk['kty'] );
		$this->assertSame( 'Ed25519', $jwk['crv'] );
		$this->assertNotEmpty( $jwk['kid'] );
		$this->assertNotEmpty( $jwk['x'] );
	}

	public function test_signature_verifies_against_the_published_key() {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
		$signer = $this->enabled_signer();

		$body    = '{"discovery":"doc"}';
		$target  = 'https://example.test/.well-known/discovery.json';
		$headers = $signer->sign( $body, $target );

		$this->assertArrayHasKey( 'Signature', $headers );
		$this->assertArrayHasKey( 'Signature-Input', $headers );
		$this->assertStringStartsWith( 'sha-256=:', $headers['Content-Digest'] );
		$this->assertSame( $headers['Content-Digest'], 'sha-256=:' . base64_encode( hash( 'sha256', $body, true ) ) . ':' );

		// Reconstruct the RFC 9421 signature base from the emitted headers.
		$params = substr( $headers['Signature-Input'], strlen( 'sig1=' ) );
		$base   = '"@target-uri": ' . $target . "\n"
			. '"content-digest": ' . $headers['Content-Digest'] . "\n"
			. '"@signature-params": ' . $params;

		$sig = base64_decode( trim( substr( $headers['Signature'], strlen( 'sig1=:' ) ), ':' ), true );
		$jwk = json_decode( $signer->directory(), true )['keys'][0];
		$pub = base64_decode( strtr( $jwk['x'], '-_', '+/' ) );

		$this->assertTrue( sodium_crypto_sign_verify_detached( $sig, $base, $pub ) );

		// A tampered body must NOT verify against the same signature.
		$tampered = '"@target-uri": ' . $target . "\n"
			. '"content-digest": sha-256=:' . base64_encode( hash( 'sha256', 'tampered', true ) ) . ":\n"
			. '"@signature-params": ' . $params;
		$this->assertFalse( sodium_crypto_sign_verify_detached( $sig, $tampered, $pub ) );
	}

	public function test_keys_persist_across_instances() {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'libsodium not available' );
		}
		$kid1 = json_decode( $this->enabled_signer()->directory(), true )['keys'][0]['kid'];
		$kid2 = json_decode( ( new Signer( new Settings() ) )->directory(), true )['keys'][0]['kid'];
		$this->assertSame( $kid1, $kid2, 'The keypair is generated once and reused.' );
	}
}
