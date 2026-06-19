<?php
/**
 * Signer — optional response signing for the discovery documents (Web Bot Auth /
 * HTTP Message Signatures). Generates an Ed25519 keypair, publishes the public key
 * as a JWKS-style key directory at /.well-known/http-message-signatures-directory,
 * and signs the served discovery JSON with RFC 9421 `Signature`/`Signature-Input`
 * headers over an RFC 9530 `Content-Digest`.
 *
 * Off by default. Uses PHP's bundled libsodium (Ed25519, core since 7.2 — well
 * under the 7.4 floor); no external calls, no extra dependency. Scoped to the
 * low-volume discovery JSON docs ONLY — never the cached HTML / llms.txt hot
 * paths, where an edge-cached body would carry a frozen signature.
 *
 * @package Agentimus
 */

namespace Agentimus\Discovery;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Signer {

	/** Option holding the generated keypair (NON-autoloaded — a secret must not ride every page load). */
	const OPTION = 'agentimus_signing_keys';

	/** The well-known key directory name. */
	const DIRECTORY = 'http-message-signatures-directory';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether signing is switched on AND the runtime can actually sign.
	 *
	 * @return bool
	 */
	public function enabled() {
		return $this->settings->enabled( 'enable_signing' ) && function_exists( 'sodium_crypto_sign_detached' );
	}

	/**
	 * The signing keypair {kid, public (raw 32B), secret (raw 64B)}, generating and
	 * persisting one on first use. A site can supply its own secret key — kept out
	 * of the DB — via the `agentimus_signing_secret_key` filter (base64 of the
	 * 64-byte libsodium secret key, whose last 32 bytes are the public key).
	 *
	 * @return array{kid:string,public:string,secret:string}|null
	 */
	public function keys() {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			return null;
		}

		// External secret-key override (constant/env/file) — never touches the DB.
		$override = (string) apply_filters( 'agentimus_signing_secret_key', '' );
		if ( '' !== $override ) {
			$secret = base64_decode( $override, true );
			if ( false !== $secret && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $secret ) ) {
				$public = substr( $secret, -SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES );
				return array( 'kid' => $this->kid( $public ), 'public' => $public, 'secret' => $secret );
			}
		}

		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && ! empty( $stored['secret'] ) && ! empty( $stored['public'] ) ) {
			$secret = base64_decode( (string) $stored['secret'], true );
			$public = base64_decode( (string) $stored['public'], true );
			if ( false !== $secret && false !== $public ) {
				return array( 'kid' => (string) $stored['kid'], 'public' => $public, 'secret' => $secret );
			}
		}

		// First use → generate and persist (NOT autoloaded).
		$keypair = sodium_crypto_sign_keypair();
		$secret  = sodium_crypto_sign_secretkey( $keypair );
		$public  = sodium_crypto_sign_publickey( $keypair );
		$kid     = $this->kid( $public );
		update_option(
			self::OPTION,
			array(
				'kid'     => $kid,
				'public'  => base64_encode( $public ),
				'secret'  => base64_encode( $secret ),
				'created' => time(),
			),
			false
		);
		return array( 'kid' => $kid, 'public' => $public, 'secret' => $secret );
	}

	/**
	 * The JWKS-style key directory document (the public key as an Ed25519 JWK).
	 * Empty when signing is off — the route then emits a clean 404.
	 *
	 * @return string
	 */
	public function directory() {
		if ( ! $this->enabled() ) {
			return '';
		}
		$keys = $this->keys();
		if ( null === $keys ) {
			return '';
		}
		$doc = array(
			'keys' => array(
				array(
					'kid' => $keys['kid'],
					'kty' => 'OKP',
					'crv' => 'Ed25519',
					'x'   => $this->b64url( $keys['public'] ),
					'alg' => 'EdDSA',
					'use' => 'sig',
				),
			),
		);
		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * The key directory URL, or '' when signing is off.
	 *
	 * @return string
	 */
	public function directory_url() {
		return $this->enabled() ? home_url( '/.well-known/' . self::DIRECTORY ) : '';
	}

	/**
	 * RFC 9421 signature headers for a response body — binds the document URL and an
	 * RFC 9530 Content-Digest, with no `expires` so a cached (body+signature) pair
	 * stays valid until the body changes. Returns name => value pairs, or [] when off.
	 *
	 * @param string $body       The exact response body.
	 * @param string $target_uri The absolute URL of the document being served.
	 * @return array<string,string>
	 */
	public function sign( $body, $target_uri ) {
		if ( ! $this->enabled() ) {
			return array();
		}
		$keys = $this->keys();
		if ( null === $keys ) {
			return array();
		}

		$digest  = 'sha-256=:' . base64_encode( hash( 'sha256', (string) $body, true ) ) . ':';
		$created = time();
		$params  = sprintf( '("@target-uri" "content-digest");created=%d;keyid="%s";alg="ed25519"', $created, $keys['kid'] );

		// RFC 9421 §2.5 signature base — covered components then @signature-params.
		$base = '"@target-uri": ' . $target_uri . "\n"
			. '"content-digest": ' . $digest . "\n"
			. '"@signature-params": ' . $params;

		$sig = sodium_crypto_sign_detached( $base, $keys['secret'] );

		return array(
			'Content-Digest'  => $digest,
			'Signature-Input' => 'sig1=' . $params,
			'Signature'       => 'sig1=:' . base64_encode( $sig ) . ':',
		);
	}

	/**
	 * Stable key id: the first 16 hex chars of the public key's SHA-256.
	 *
	 * @param string $public Raw public key.
	 * @return string
	 */
	private function kid( $public ) {
		return substr( hash( 'sha256', $public ), 0, 16 );
	}

	/**
	 * base64url, unpadded.
	 *
	 * @param string $raw Raw bytes.
	 * @return string
	 */
	private function b64url( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
