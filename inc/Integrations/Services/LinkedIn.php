<?php
/**
 * LinkedIn — announcements posted through an app the OWNER made on
 * LinkedIn's developer platform, speaking as their own member profile.
 *
 * The shape of the trust differs from X in two honest ways. LinkedIn issues
 * no public-client flow: the app carries a CLIENT SECRET, so the secret
 * lives the way the bot token does — in the shared connection store, pasted
 * in once, never echoed back to any surface. And LinkedIn grants standard
 * apps no refresh tokens: the access token lives about sixty days and then
 * simply ends. This class therefore does not pretend to renew — it stores
 * the expiry, the card counts down to it ("Reconnect by …"), and when the
 * day comes announcing PAUSES honestly: queued posts park as "Didn't go"
 * until the owner reconnects. A pause stated is better than a renewal
 * promised that the platform never offered.
 *
 * The owner's app needs LinkedIn's two self-serve products: "Share on
 * LinkedIn" (the posting scope) and "Sign In with LinkedIn using OpenID
 * Connect" (the userinfo call that names the member the grant speaks as).
 *
 * Like X, LinkedIn receives NO events — announcements are the owner's
 * public voice; telemetry does not belong in it.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Services;

use Agentimus\Settings;
use Agentimus\Integrations\Connections;

defined( 'ABSPATH' ) || exit;

final class LinkedIn {

	/** This connection's id in the store, settings and queue rows. */
	const ID = 'linkedin';

	/** LinkedIn's OAuth endpoints and API root. */
	const AUTHORIZE = 'https://www.linkedin.com/oauth/v2/authorization';
	const TOKEN     = 'https://www.linkedin.com/oauth/v2/accessToken';
	const API       = 'https://api.linkedin.com';

	/** The whole ask: post as the member, know who they are. */
	const SCOPES = 'openid profile w_member_social';

	/** Transient prefix for one authorize round-trip's state. */
	const STATE_TRANSIENT = 'agentimus_linkedin_oauth_';

	/** Seconds an authorize round-trip may take before its state expires. */
	const STATE_TTL = 600;

	/** Seconds an outgoing call may take — the queue's short leash. */
	const TIMEOUT = 10;

	/** LinkedIn's ceiling per post. */
	const LIMIT = 3000;

	/** Days of warning the card gives before the grant ends. */
	const RECONNECT_WARN_DAYS = 14;

	/* -- The connection -------------------------------------------------------- */

	/**
	 * The stored connection row, always in its full shape. The secret is
	 * INSIDE this row but never leaves the server — status surfaces read
	 * everything else.
	 *
	 * @return array{client_id:string,client_secret:string,access_token:string,expires_at:int,member_urn:string,name:string,connect_error:string}
	 */
	public static function connection() {
		$row = Connections::read( self::ID );
		return array(
			'client_id'     => isset( $row['client_id'] ) ? (string) $row['client_id'] : '',
			'client_secret' => isset( $row['client_secret'] ) ? (string) $row['client_secret'] : '',
			'access_token'  => isset( $row['access_token'] ) ? (string) $row['access_token'] : '',
			'expires_at'    => isset( $row['expires_at'] ) ? (int) $row['expires_at'] : 0,
			'member_urn'    => isset( $row['member_urn'] ) ? (string) $row['member_urn'] : '',
			'name'          => isset( $row['name'] ) ? (string) $row['name'] : '',
			'connect_error' => isset( $row['connect_error'] ) ? (string) $row['connect_error'] : '',
		);
	}

	/**
	 * Whether a grant exists at all — expired or not. Expiry is its own
	 * question ({@see expired()}), because the card must say "reconnect",
	 * not "not connected", to an owner whose sixty days ran out.
	 *
	 * @return bool
	 */
	public static function connected() {
		$c = self::connection();
		return '' !== $c['access_token'] && '' !== $c['member_urn'];
	}

	/**
	 * Whether the grant's sixty days have run out.
	 *
	 * @return bool
	 */
	public static function expired() {
		$c = self::connection();
		return '' !== $c['access_token'] && $c['expires_at'] <= time();
	}

	/** Forget the whole grant — the disconnect path for every use of it. */
	public static function forget() {
		Connections::forget( self::ID );
	}

	/* -- The authorize round-trip ------------------------------------------------ */

	/**
	 * The callback address the owner pastes into their LinkedIn app.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return rest_url( 'agentimus/v1/linkedin/callback' );
	}

	/**
	 * Begin the round-trip: store the app credentials, mint the state, and
	 * return the address on LinkedIn where the owner says yes.
	 *
	 * @param string $client_id     The app's client id.
	 * @param string $client_secret The app's client secret ('' keeps a stored one).
	 * @return string|\WP_Error The authorize URL, or the refusal.
	 */
	public static function begin( $client_id, $client_secret ) {
		$client_id     = trim( (string) $client_id );
		$client_secret = trim( (string) $client_secret );
		$stored        = self::connection();

		if ( '' === $client_id ) {
			return new \WP_Error( 'agentimus_linkedin_client', __( 'Paste your LinkedIn app’s Client ID first — it’s on the app’s Auth page.', 'agentimus' ) );
		}
		if ( '' === $client_secret && '' === $stored['client_secret'] ) {
			return new \WP_Error( 'agentimus_linkedin_secret', __( 'Paste the app’s Client Secret too — LinkedIn’s flow requires one. It stays on your server and is never shown back.', 'agentimus' ) );
		}

		// Credentials survive an aborted round-trip; only a completed one
		// replaces the grant. A fresh attempt clears the last failure note.
		$row                  = Connections::read( self::ID );
		$row['client_id']     = $client_id;
		$row['client_secret'] = '' !== $client_secret ? $client_secret : $stored['client_secret'];
		unset( $row['connect_error'] );
		Connections::store( self::ID, $row );

		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT . $state, array( 'ok' => true ), self::STATE_TTL );

		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => rawurlencode( $client_id ),
				'redirect_uri'  => rawurlencode( self::callback_url() ),
				'scope'         => rawurlencode( self::SCOPES ),
				'state'         => $state,
			),
			self::AUTHORIZE
		);
	}

	/**
	 * Complete the round-trip: prove the single-use state, trade the code
	 * for the sixty-day token, and ask LinkedIn who the grant speaks as.
	 * Only a fully successful exchange touches the stored grant.
	 *
	 * @param string $code  The authorization code LinkedIn sent back.
	 * @param string $state The state it echoed.
	 * @return true|\WP_Error
	 */
	public static function complete( $code, $state ) {
		$stash = get_transient( self::STATE_TRANSIENT . $state );
		delete_transient( self::STATE_TRANSIENT . $state ); // Single-use, success or not.
		if ( ! is_array( $stash ) ) {
			return new \WP_Error( 'agentimus_linkedin_state', __( 'That authorize link had expired or was already used — start again from the LinkedIn panel.', 'agentimus' ) );
		}

		$c        = self::connection();
		$response = wp_remote_post(
			self::TOKEN,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => (string) $code,
					'client_id'     => $c['client_id'],
					'client_secret' => $c['client_secret'],
					'redirect_uri'  => self::callback_url(),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'agentimus_linkedin_unreachable',
				sprintf(
					/* translators: %s: the transport error, e.g. a timeout. */
					__( 'LinkedIn could not be reached: %s', 'agentimus' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$why = is_array( $data ) && isset( $data['error_description'] ) ? (string) $data['error_description'] : '';
			return new \WP_Error(
				'agentimus_linkedin_token',
				'' !== $why
					? sprintf(
						/* translators: %s: LinkedIn's own error description. */
						__( 'LinkedIn refused the exchange: %s', 'agentimus' ),
						substr( trim( $why ), 0, 140 )
					)
					: sprintf(
						/* translators: %d: the HTTP status LinkedIn answered with. */
						__( 'LinkedIn refused the exchange (status %d).', 'agentimus' ),
						$status
					)
			);
		}

		// Who does this grant speak as? userinfo (OpenID) answers with the
		// member id the posts will be authored under, and a display name for
		// the card.
		$me = wp_remote_get(
			self::API . '/v2/userinfo',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Authorization' => 'Bearer ' . (string) $data['access_token'] ),
			)
		);
		$sub  = '';
		$name = '';
		if ( ! is_wp_error( $me ) ) {
			$body = json_decode( wp_remote_retrieve_body( $me ), true );
			$sub  = isset( $body['sub'] ) ? (string) $body['sub'] : '';
			$name = isset( $body['name'] ) ? (string) $body['name'] : '';
		}
		if ( '' === $sub ) {
			return new \WP_Error( 'agentimus_linkedin_identity', __( 'LinkedIn granted access but wouldn’t say who for — check that “Sign In with LinkedIn using OpenID Connect” is added to the app’s products.', 'agentimus' ) );
		}

		Connections::store(
			self::ID,
			array(
				'client_id'     => $c['client_id'],
				'client_secret' => $c['client_secret'],
				'access_token'  => (string) $data['access_token'],
				'expires_at'    => time() + ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 60 * DAY_IN_SECONDS ),
				'member_urn'    => 'urn:li:person:' . $sub,
				'name'          => $name,
			)
		);
		return true;
	}

	/* -- The sharing use -------------------------------------------------------------- */

	/**
	 * The stored SHARING use — a switch and nothing else: the member's own
	 * feed is the destination.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array{enabled:bool}
	 */
	public static function sharing_config( Settings $settings ) {
		$integrations = (array) $settings->get( 'integrations', array() );
		return array(
			'enabled' => ! empty( $integrations['linkedin_share_enabled'] ),
		);
	}

	/**
	 * Whether the sharing use could deliver: switched on, holding a grant
	 * whose sixty days still stand.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function sharing_active( Settings $settings ) {
		return self::sharing_config( $settings )['enabled'] && self::connected() && ! self::expired();
	}

	/**
	 * Post one announcement to the member's own feed — verbatim, one send,
	 * one verdict. An expired grant refuses with the reconnect sentence, and
	 * the queue parks the row for the owner's hand.
	 *
	 * @param string $text The announcement.
	 * @return true|\WP_Error
	 */
	public static function announce( $text ) {
		if ( ! self::sharing_active( new Settings() ) ) {
			return self::expired()
				? new \WP_Error( 'agentimus_linkedin_expired', __( 'LinkedIn’s sixty days ran out — reconnect under Integrations → Services, then try this again.', 'agentimus' ) )
				: new \WP_Error( 'agentimus_linkedin_sharing_off', __( 'Announcing on LinkedIn is not set up.', 'agentimus' ) );
		}

		$c        = self::connection();
		$response = wp_remote_post(
			self::API . '/v2/ugcPosts',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization'             => 'Bearer ' . $c['access_token'],
					'Content-Type'              => 'application/json; charset=utf-8',
					'X-Restli-Protocol-Version' => '2.0.0',
				),
				'body'    => wp_json_encode(
					array(
						'author'          => $c['member_urn'],
						'lifecycleState'  => 'PUBLISHED',
						'specificContent' => array(
							'com.linkedin.ugc.ShareContent' => array(
								'shareCommentary'    => array( 'text' => (string) $text ),
								'shareMediaCategory' => 'NONE',
							),
						),
						'visibility'      => array(
							'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
						),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return true;
		}

		// LinkedIn's own words when it gave any — the sentence the owner can act on.
		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = is_array( $body ) && isset( $body['message'] ) ? (string) $body['message'] : '';
		return new \WP_Error(
			'agentimus_linkedin_status',
			'' !== $message
				? sprintf(
					/* translators: 1: the HTTP status LinkedIn answered with, 2: LinkedIn's own error description. */
					__( 'LinkedIn answered %1$d: %2$s', 'agentimus' ),
					$status,
					substr( trim( $message ), 0, 140 )
				)
				: sprintf(
					/* translators: %d: the HTTP status LinkedIn answered with. */
					__( 'LinkedIn answered %d.', 'agentimus' ),
					$status
				)
		);
	}
}
