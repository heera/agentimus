<?php
/**
 * Crypto — encrypt the AI-provider API keys at rest, so a database-only exposure (a
 * leaked backup, a read by another plugin, a SQL-injection over wp_options) does not
 * hand an attacker the owner's paid keys.
 *
 * The encryption key is derived from the site's wp-config SALTS, which live in a file
 * — NOT the database — so ciphertext in wp_options is useless without also reading the
 * server's config. Uses libsodium's authenticated secretbox; a value that predates
 * encryption (unprefixed plaintext) is returned unchanged, and if libsodium is
 * unavailable the key is stored as-is rather than being lost. Rotating the site's salts
 * makes stored ciphertext undecryptable (the owner re-enters the key) — an accepted,
 * rare trade for keeping the key off the database.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	/** Marks our own ciphertext, so a legacy plaintext key is recognisable and a
	 *  format bump is possible later. */
	const PREFIX = 'enc:v1:';

	/** Fallback key material option, used ONLY when the site defines no salts. */
	const KEY_OPTION = 'agentimus_visibility_cryptokey';

	/**
	 * Whether authenticated encryption is available (libsodium, bundled with PHP ≥ 7.2).
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& function_exists( 'sodium_crypto_generichash' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Encrypt a secret for storage: PREFIX . base64(nonce . ciphertext). An empty value,
	 * or any value when encryption is unavailable, is returned unchanged so a key is
	 * never lost.
	 *
	 * @param string $plaintext Secret to encrypt.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext || ! self::available() ) {
			return $plaintext;
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return $plaintext; // Never lose the key over a crypto hiccup.
		}
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypt a stored value. A legacy (unprefixed) plaintext key is returned unchanged,
	 * so installs that predate encryption keep working. Returns '' when our own
	 * ciphertext cannot be decrypted (tampering, or the site's salts were rotated) —
	 * treated as "no key" rather than handing a provider garbage.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return $value; // Legacy plaintext (or empty) — pass through.
		}
		if ( ! self::available() ) {
			return '';
		}
		$raw   = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		$nonce = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( false === $raw || strlen( $raw ) <= $nonce ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, $nonce ), substr( $raw, 0, $nonce ), self::key() );
		} catch ( \Throwable $e ) {
			return '';
		}
		return is_string( $plain ) ? $plain : '';
	}

	/**
	 * Encrypt only a value that isn't already our ciphertext (and isn't empty) — the
	 * migrate-on-save path: a plaintext key becomes encrypted while an already-encrypted
	 * one is returned untouched (no needless nonce churn).
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function encrypt_if_needed( $value ) {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, self::PREFIX ) ) {
			return $value;
		}
		return self::encrypt( $value );
	}

	/**
	 * The 32-byte secretbox key, derived from the site's wp-config salts (file, not DB).
	 * Falls back to a per-site random key stored in an option ONLY when no salts are
	 * defined (a non-standard install) — that fallback offers no protection against a
	 * DB-only leak, but is no worse than plaintext and is virtually never reached.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key() {
		$material = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $const ) {
			if ( defined( $const ) && '' !== (string) constant( $const ) ) {
				$material .= (string) constant( $const );
			}
		}
		if ( '' === $material ) {
			$material = (string) get_option( self::KEY_OPTION, '' );
			if ( '' === $material ) {
				$material = self::random_material();
				add_option( self::KEY_OPTION, $material, '', 'no' );
			}
		}
		return sodium_crypto_generichash( 'agentimus-visibility-key|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Random key material for the no-salts fallback.
	 *
	 * @return string
	 */
	private static function random_material() {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 64, true, true );
		}
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			return hash( 'sha256', __FILE__ . implode( '', array_keys( (array) $_SERVER ) ) );
		}
	}
}
