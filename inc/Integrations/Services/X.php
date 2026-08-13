<?php
/**
 * X (Twitter) — announcements posted through an app the OWNER made, so the
 * only credentials involved are theirs, and the only voice is theirs.
 *
 * The shape of the trust: the owner creates a free app on X's developer
 * platform and pastes its CLIENT ID here — a public identifier, not a
 * secret; the connection is OAuth 2.0 with PKCE, the flow X built for
 * clients that hold no secret at all. Authorize sends the owner to X, X
 * sends them back to this site's callback, and the grant that returns lives
 * in the shared connection store: access token, refresh token, and the
 * @handle it speaks as.
 *
 * Unlike every neighbour on the Services tab, X receives NO events — an
 * announcement is the owner's public voice, and telemetry does not belong in
 * it. This class therefore wears only the connection and the announcing use;
 * it deliberately has no deliver(), no wants(), and no seat in the events
 * roster.
 *
 * Access tokens live two hours; the refresh token renews them silently, and
 * X ROTATES the refresh token on every use — the new one must always be
 * stored or the connection dies on the second renewal. When a renewal is
 * refused (a revoked app, a password change), the refusal is written on the
 * connection and announcing pauses honestly: queued posts park as "Didn't
 * go" rather than vanishing.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Services;

use Agentimus\Settings;
use Agentimus\Integrations\Connections;

defined( 'ABSPATH' ) || exit;

final class X {

	/** This connection's id in the store, settings and queue rows. */
	const ID = 'x';

	/** X's OAuth endpoints and API root. */
	const AUTHORIZE = 'https://x.com/i/oauth2/authorize';
	const TOKEN     = 'https://api.x.com/2/oauth2/token';
	const REVOKE    = 'https://api.x.com/2/oauth2/revoke';
	const API       = 'https://api.x.com/2';

	/** The whole ask: post, know who we are, renew — nothing more. */
	const SCOPES = 'tweet.read tweet.write users.read offline.access';

	/** Transient prefix for one authorize round-trip's state + verifier. */
	const STATE_TRANSIENT = 'agentimus_x_oauth_';

	/** Seconds an authorize round-trip may take before its state expires. */
	const STATE_TTL = 600;

	/** Seconds an outgoing call may take — the queue's short leash. */
	const TIMEOUT = 10;

	/** X's hard ceiling per post, in its own weighted counting. */
	const LIMIT = 280;

	/** What a URL weighs in that counting, however long it really is. */
	const URL_WEIGHT = 23;

	/* -- The connection -------------------------------------------------------- */

	/**
	 * The stored connection row, always in its full shape.
	 *
	 * @return array{client_id:string,access_token:string,refresh_token:string,expires_at:int,handle:string,refresh_error:string}
	 */
	public static function connection() {
		$row = Connections::read( self::ID );
		return array(
			'client_id'     => isset( $row['client_id'] ) ? (string) $row['client_id'] : '',
			'access_token'  => isset( $row['access_token'] ) ? (string) $row['access_token'] : '',
			'refresh_token' => isset( $row['refresh_token'] ) ? (string) $row['refresh_token'] : '',
			'expires_at'    => isset( $row['expires_at'] ) ? (int) $row['expires_at'] : 0,
			'handle'        => isset( $row['handle'] ) ? (string) $row['handle'] : '',
			'refresh_error' => isset( $row['refresh_error'] ) ? (string) $row['refresh_error'] : '',
			// A failed authorize round-trip, written by the callback for the
			// panel to show — cleared by the next begin() or a success.
			'connect_error' => isset( $row['connect_error'] ) ? (string) $row['connect_error'] : '',
		);
	}

	/**
	 * Whether a usable grant exists — a refresh token is the durable half;
	 * the access token is just its two-hour shadow.
	 *
	 * @return bool
	 */
	public static function connected() {
		$c = self::connection();
		return '' !== $c['refresh_token'] && '' !== $c['client_id'];
	}

	/** Forget the whole grant — the disconnect path for every use of it. */
	public static function forget() {
		Connections::forget( self::ID );
	}

	/* -- The authorize round-trip ------------------------------------------------ */

	/**
	 * The callback address the owner pastes into their X app — one fixed
	 * REST route, stated by the site so it can never be mistyped here.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return rest_url( 'agentimus/v1/x/callback' );
	}

	/**
	 * Begin the round-trip: store the client id, mint state + PKCE verifier,
	 * and return the address on X where the owner says yes.
	 *
	 * @param string $client_id The app's public client id.
	 * @return string|\WP_Error The authorize URL, or the refusal.
	 */
	public static function begin( $client_id ) {
		$client_id = trim( (string) $client_id );
		if ( '' === $client_id ) {
			return new \WP_Error( 'agentimus_x_client', __( 'Paste your X app’s Client ID first — it’s on the app’s Keys and tokens page.', 'agentimus' ) );
		}

		// The grant that may already exist survives an aborted re-authorize;
		// only a COMPLETED round-trip replaces it. A fresh attempt clears the
		// last one's failure note.
		$row              = Connections::read( self::ID );
		$row['client_id'] = $client_id;
		unset( $row['connect_error'] );
		Connections::store( self::ID, $row );

		$state    = wp_generate_password( 32, false );
		$verifier = wp_generate_password( 64, false );
		set_transient( self::STATE_TRANSIENT . $state, array( 'verifier' => $verifier ), self::STATE_TTL );

		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		return add_query_arg(
			array(
				'response_type'         => 'code',
				'client_id'             => rawurlencode( $client_id ),
				'redirect_uri'          => rawurlencode( self::callback_url() ),
				'scope'                 => rawurlencode( self::SCOPES ),
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			self::AUTHORIZE
		);
	}

	/**
	 * Complete the round-trip: prove the state (single-use, ten minutes),
	 * trade the code for tokens, and ask X who the grant speaks as. Only a
	 * fully successful exchange touches the stored grant.
	 *
	 * @param string $code  The authorization code X sent back.
	 * @param string $state The state it echoed.
	 * @return true|\WP_Error
	 */
	public static function complete( $code, $state ) {
		$stash = get_transient( self::STATE_TRANSIENT . $state );
		delete_transient( self::STATE_TRANSIENT . $state ); // Single-use, success or not.
		if ( ! is_array( $stash ) || empty( $stash['verifier'] ) ) {
			return new \WP_Error( 'agentimus_x_state', __( 'That authorize link had expired or was already used — start again from the X panel.', 'agentimus' ) );
		}

		$c     = self::connection();
		$grant = self::token_call(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => (string) $code,
				'client_id'     => $c['client_id'],
				'redirect_uri'  => self::callback_url(),
				'code_verifier' => (string) $stash['verifier'],
			)
		);
		if ( is_wp_error( $grant ) ) {
			return $grant;
		}

		// Who does this grant speak as? The card must be able to say.
		$me = wp_remote_get(
			self::API . '/users/me',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Authorization' => 'Bearer ' . $grant['access_token'] ),
			)
		);
		$handle = '';
		if ( ! is_wp_error( $me ) ) {
			$body   = json_decode( wp_remote_retrieve_body( $me ), true );
			$handle = isset( $body['data']['username'] ) ? (string) $body['data']['username'] : '';
		}

		Connections::store(
			self::ID,
			array(
				'client_id'     => $c['client_id'],
				'access_token'  => $grant['access_token'],
				'refresh_token' => $grant['refresh_token'],
				'expires_at'    => time() + $grant['expires_in'],
				'handle'        => $handle,
				'refresh_error' => '',
			)
		);
		return true;
	}

	/* -- Staying connected ---------------------------------------------------------- */

	/**
	 * A currently valid access token — renewed first when its two hours are
	 * nearly up. A refused renewal is written on the connection (the card's
	 * honesty line) and returned as the error the caller can act on.
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token() {
		$c = self::connection();
		if ( '' === $c['refresh_token'] ) {
			return new \WP_Error( 'agentimus_x_disconnected', __( 'X is not connected.', 'agentimus' ) );
		}
		if ( $c['expires_at'] > time() + 60 && '' !== $c['access_token'] ) {
			return $c['access_token'];
		}

		$grant = self::token_call(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $c['refresh_token'],
				'client_id'     => $c['client_id'],
			)
		);
		if ( is_wp_error( $grant ) ) {
			// The refusal lives on the row until a renewal succeeds or the
			// owner reconnects — announcing pauses, nothing vanishes.
			$row                  = Connections::read( self::ID );
			$row['refresh_error'] = substr( $grant->get_error_message(), 0, 300 );
			Connections::store( self::ID, $row );
			return $grant;
		}

		Connections::store(
			self::ID,
			array(
				'client_id'     => $c['client_id'],
				'access_token'  => $grant['access_token'],
				// X rotates the refresh token on every renewal: the new one is
				// the connection now. Missing in the answer = keep the old.
				'refresh_token' => '' !== $grant['refresh_token'] ? $grant['refresh_token'] : $c['refresh_token'],
				'expires_at'    => time() + $grant['expires_in'],
				'handle'        => $c['handle'],
				'refresh_error' => '',
			)
		);
		return $grant['access_token'];
	}

	/**
	 * Best-effort revocation at X on disconnect — deleting our copy does not
	 * kill the grant out there, and an owner disconnecting for a security
	 * reason deserves the real thing. Failures are swallowed: the disconnect
	 * itself must never be blocked by X being unreachable.
	 */
	public static function revoke() {
		$c = self::connection();
		foreach ( array( 'refresh_token' => $c['refresh_token'], 'access_token' => $c['access_token'] ) as $hint => $token ) {
			if ( '' === $token || '' === $c['client_id'] ) {
				continue;
			}
			wp_remote_post(
				self::REVOKE,
				array(
					'timeout' => self::TIMEOUT,
					'body'    => array(
						'token'           => $token,
						'client_id'       => $c['client_id'],
						'token_type_hint' => $hint,
					),
				)
			);
		}
	}

	/* -- The sharing use -------------------------------------------------------------- */

	/**
	 * The stored SHARING use — a switch and nothing else: the destination IS
	 * the connected account, so there is nothing to point at.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array{enabled:bool}
	 */
	public static function sharing_config( Settings $settings ) {
		$integrations = (array) $settings->get( 'integrations', array() );
		return array(
			'enabled' => ! empty( $integrations['x_share_enabled'] ),
		);
	}

	/**
	 * Whether the sharing use could deliver: switched on, holding a grant.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function sharing_active( Settings $settings ) {
		return self::sharing_config( $settings )['enabled'] && self::connected();
	}

	/**
	 * Post one announcement to the connected account's timeline — verbatim,
	 * one send, one verdict. What happens to a failed row is the queue's
	 * business, and the queue's law there is the owner's hand.
	 *
	 * @param string $text The announcement.
	 * @return true|\WP_Error
	 */
	public static function announce( $text ) {
		if ( ! self::sharing_active( new Settings() ) ) {
			return new \WP_Error( 'agentimus_x_sharing_off', __( 'Announcing on X is not set up.', 'agentimus' ) );
		}

		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_post(
			self::API . '/tweets',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode( array( 'text' => (string) $text ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return true;
		}

		// X's own words when it gave any — the sentence the owner can act on.
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$detail = '';
		if ( is_array( $body ) ) {
			$detail = isset( $body['detail'] ) ? (string) $body['detail'] : ( isset( $body['title'] ) ? (string) $body['title'] : '' );
		}
		return new \WP_Error(
			'agentimus_x_status',
			'' !== $detail
				? sprintf(
					/* translators: 1: the HTTP status X answered with, 2: X's own error description. */
					__( 'X answered %1$d: %2$s', 'agentimus' ),
					$status,
					substr( trim( $detail ), 0, 140 )
				)
				: sprintf(
					/* translators: %d: the HTTP status X answered with. */
					__( 'X answered %d.', 'agentimus' ),
					$status
				)
		);
	}

	/**
	 * A draft's length as X will weigh it: every URL counts URL_WEIGHT
	 * however long it really is, everything else counts itself. Close enough
	 * to t.co arithmetic for an honest refusal at the queue — where the
	 * owner can still fix the draft — instead of a bounce at X.
	 *
	 * @param string $text The draft.
	 * @return int
	 */
	public static function tweet_length( $text ) {
		$text   = (string) $text;
		$length = 0;
		$parts  = preg_split( '#(https?://\S+)#i', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( (array) $parts as $part ) {
			$length += preg_match( '#^https?://#i', (string) $part ) ? self::URL_WEIGHT : mb_strlen( (string) $part );
		}
		return $length;
	}

	/* -- Internals ------------------------------------------------------------------------ */

	/**
	 * One call to the token endpoint, both grant kinds — a public client, so
	 * the client id rides in the body and no secret exists anywhere.
	 *
	 * @param array $body Form fields.
	 * @return array{access_token:string,refresh_token:string,expires_in:int}|\WP_Error
	 */
	private static function token_call( array $body ) {
		$response = wp_remote_post(
			self::TOKEN,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'agentimus_x_unreachable',
				sprintf(
					/* translators: %s: the transport error, e.g. a timeout. */
					__( 'X could not be reached: %s', 'agentimus' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$why = is_array( $data ) && isset( $data['error_description'] ) ? (string) $data['error_description'] : ( is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : '' );
			return new \WP_Error(
				'agentimus_x_token',
				'' !== $why
					? sprintf(
						/* translators: %s: X's own error description. */
						__( 'X refused the exchange: %s', 'agentimus' ),
						substr( trim( $why ), 0, 140 )
					)
					: sprintf(
						/* translators: %d: the HTTP status X answered with. */
						__( 'X refused the exchange (status %d).', 'agentimus' ),
						$status
					)
			);
		}

		return array(
			'access_token'  => (string) $data['access_token'],
			'refresh_token' => isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '',
			'expires_in'    => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 7200,
		);
	}
}
