<?php
/**
 * Google service-account auth — the doctrine-compliant way a distributed
 * plugin talks to Google APIs: the OWNER's own service account, its JSON key
 * pasted locally, tokens minted by this site signing a JWT with that key and
 * exchanging it directly at Google's token endpoint.
 *
 * No OAuth consent screen, no client secret a plugin can't keep, and above all
 * NO PROXY: nothing routes through anyone's server but Google's. The plugin
 * never sees a Google account — only a key the owner minted, scoped to
 * webmasters.readonly, revocable in their own Cloud console.
 *
 * PHP 7.4-safe: RS256 via ext-openssl (a WordPress platform staple; its
 * absence is reported honestly, never fatal).
 *
 * @package Agentimus
 */

namespace Agentimus\Google;

defined( 'ABSPATH' ) || exit;

final class Auth {

	/**
	 * @var string Search Console, read-only. The default scope, and the only one
	 * this integration asked for until GA4 arrived.
	 */
	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	/**
	 * @var string Analytics Data (GA4), read-only.
	 *
	 * A SEPARATE scope, deliberately requested separately: a token minted for
	 * Search Console must never carry analytics rights it does not need, and an
	 * owner who connects only one of the two grants only that one. The same
	 * service-account key can serve both — what differs is what each token is
	 * allowed to touch.
	 */
	const SCOPE_ANALYTICS = 'https://www.googleapis.com/auth/analytics.readonly';

	/** @var string Access-token cache slot (the default scope). */
	const TOKEN_TRANSIENT = 'agentimus_google_token';

	/** @var string Cache slot prefix for any non-default scope. */
	const TOKEN_PREFIX = 'agentimus_google_token_';

	/**
	 * Parse and sanity-check a service-account JSON key.
	 *
	 * @param string $json The pasted key file contents.
	 * @return array { email?: string, private_key?: string, token_uri?: string, error?: string }
	 */
	public static function parse_key( $json ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) ) {
			return array( 'error' => __( 'That doesn’t parse as JSON. Paste the whole key file, exactly as downloaded.', 'agentimus' ) );
		}
		if ( ( $data['type'] ?? '' ) !== 'service_account' ) {
			return array( 'error' => __( 'This JSON isn’t a service-account key (its "type" field should say service_account). OAuth client files won’t work here.', 'agentimus' ) );
		}
		$email = (string) ( $data['client_email'] ?? '' );
		$pkey  = (string) ( $data['private_key'] ?? '' );
		if ( '' === $email || '' === $pkey ) {
			return array( 'error' => __( 'The key file is missing client_email or private_key.', 'agentimus' ) );
		}
		return array(
			'email'       => $email,
			'private_key' => $pkey,
			'token_uri'   => (string) ( $data['token_uri'] ?? 'https://oauth2.googleapis.com/token' ),
		);
	}

	/**
	 * An access token for the read-only Search Console scope — cached for its
	 * lifetime, minted fresh when the cache is cold.
	 *
	 * Cached PER SCOPE. One slot for both would hand a Search Console token to a
	 * GA4 request — which fails with an authorisation error that reads like a bad
	 * key, sending the owner off to re-mint a key that was never the problem.
	 *
	 * @param string $sa_json The service-account key JSON (plaintext).
	 * @param string $scope   The scope to mint for; defaults to Search Console.
	 * @return array { token?: string, error?: string }
	 */
	public static function token( $sa_json, $scope = self::SCOPE ) {
		$scope = '' === (string) $scope ? self::SCOPE : (string) $scope;
		$slot  = self::slot( $scope );

		$cached = get_transient( $slot );
		if ( is_string( $cached ) && '' !== $cached ) {
			return array( 'token' => $cached );
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			return array( 'error' => __( 'The OpenSSL PHP extension is required to sign Google API requests, and this server doesn’t have it.', 'agentimus' ) );
		}

		$key = self::parse_key( $sa_json );
		if ( isset( $key['error'] ) ) {
			return array( 'error' => $key['error'] );
		}

		$assertion = self::jwt( $key['email'], $key['private_key'], $key['token_uri'], $scope );
		if ( '' === $assertion ) {
			return array( 'error' => __( 'The private key in the file couldn’t be used to sign — the key may be malformed or truncated.', 'agentimus' ) );
		}

		$response = wp_remote_post( $key['token_uri'], array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $assertion,
			),
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'error' => $response->get_error_message() );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || empty( $json['access_token'] ) ) {
			$detail = is_array( $json ) && isset( $json['error_description'] ) ? (string) $json['error_description'] : '';
			return array( 'error' => '' !== $detail ? $detail : __( 'Google didn’t issue a token for this key.', 'agentimus' ) );
		}

		$token = (string) $json['access_token'];
		$ttl   = max( 60, (int) ( $json['expires_in'] ?? 3600 ) - 300 );
		set_transient( $slot, $token, $ttl );
		return array( 'token' => $token );
	}

	/**
	 * The cache slot for a scope. The default scope keeps its original slot name
	 * so an upgrade doesn't throw away a token that is still valid.
	 *
	 * @param string $scope Full scope URL.
	 * @return string
	 */
	private static function slot( $scope ) {
		if ( self::SCOPE === $scope ) {
			return self::TOKEN_TRANSIENT;
		}
		// Hashed: transient keys are length-limited, and a scope is a URL.
		return self::TOKEN_PREFIX . substr( md5( (string) $scope ), 0, 16 );
	}

	/**
	 * Drop cached tokens — on disconnect, or when a key changes.
	 *
	 * EVERY scope, not just the one that changed: the key underneath them is the
	 * same, so a revoked or replaced key invalidates all of them at once. Leaving
	 * a sibling token behind meant a disconnected integration could keep
	 * answering for up to an hour.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_transient( self::TOKEN_TRANSIENT );
		foreach ( array( self::SCOPE_ANALYTICS ) as $scope ) {
			delete_transient( self::slot( $scope ) );
		}
	}

	/**
	 * A signed RS256 JWT asserting this service account for the one scope.
	 *
	 * @param string $email     Service-account email (the JWT issuer).
	 * @param string $pkey      PEM private key.
	 * @param string $token_uri Audience (Google's token endpoint).
	 * @return string The compact JWT, or '' when signing fails.
	 */
	public static function jwt( $email, $pkey, $token_uri, $scope = self::SCOPE ) {
		$now     = time();
		$header  = self::b64url( (string) wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims  = self::b64url( (string) wp_json_encode( array(
			'iss'   => (string) $email,
			'scope' => '' === (string) $scope ? self::SCOPE : (string) $scope,
			'aud'   => (string) $token_uri,
			'iat'   => $now,
			'exp'   => $now + HOUR_IN_SECONDS,
		) ) );
		$signing = $header . '.' . $claims;

		$signature = '';
		$ok        = @openssl_sign( $signing, $signature, (string) $pkey, OPENSSL_ALGO_SHA256 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed key warns; the false return is the honest signal we act on.
		if ( true !== $ok || '' === $signature ) {
			return '';
		}
		return $signing . '.' . self::b64url( $signature );
	}

	/**
	 * Base64url without padding (RFC 7515).
	 *
	 * @param string $raw Bytes.
	 * @return string
	 */
	private static function b64url( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
