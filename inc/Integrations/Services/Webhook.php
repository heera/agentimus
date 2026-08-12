<?php
/**
 * The outgoing webhook — the one service connection phase one ships, and the
 * foundation every later service (Telegram, Slack, Sheets) stands on.
 *
 * A webhook is the humblest integration there is: the owner pastes a URL, and
 * events arrive there as signed JSON. That humility is the point — one URL is
 * a door to Zapier, Make, n8n and every self-hosted relay at once, without
 * this plugin learning any vendor's API.
 *
 * The trust model, in three parts:
 *
 *  - The SECRET lives in its own option, not in the settings array. Settings
 *    are localized into the admin app's boot payload and round-tripped by
 *    every save; a secret in that stream would be a secret on every screen.
 *    Here it is minted server-side, returned in ONE response (the connect or
 *    regenerate that made it), and afterwards only its existence is admitted.
 *    Same shape as the digest's stop key and the MCP connection token.
 *  - Every delivery is SIGNED: X-Agentimus-Signature carries an HMAC-SHA256 of
 *    the exact body, keyed by that secret, so the receiver can prove the event
 *    came from this site and not from anyone who found the URL.
 *  - The payload is own-site report data, never visitor PII — the law the
 *    whole Integrations screen is built under.
 *
 * State (last delivered, last error) is the card's honesty line: a connected
 * webhook that has silently failed for a week must say so where the owner can
 * see it, not in a log nobody reads.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Services;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Webhook {

	/** This connection's id in settings and state. */
	const ID = 'webhook';

	/** Option: the signing secret. Its own option, never in the settings array. */
	const SECRET_OPTION = 'agentimus_integrations_webhook_secret';

	/** Option: per-connection delivery state ({ webhook: { lastDeliveredAt, lastError, lastErrorAt } }). */
	const STATE_OPTION = 'agentimus_integrations_state';

	/** Seconds an outgoing delivery may take. Short on purpose: this runs on cron,
	 *  and a slow receiver must not hold the drain hostage. */
	const TIMEOUT = 5;

	/* -- Configuration ------------------------------------------------------ */

	/**
	 * The stored webhook configuration, always in its full shape.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array{enabled:bool,url:string,events:array<int,string>}
	 */
	public static function config( Settings $settings ) {
		$integrations = (array) $settings->get( 'integrations', array() );
		return array(
			'enabled' => ! empty( $integrations['webhook_enabled'] ),
			'url'     => isset( $integrations['webhook_url'] ) ? (string) $integrations['webhook_url'] : '',
			'events'  => isset( $integrations['webhook_events'] ) ? array_values( array_map( 'strval', (array) $integrations['webhook_events'] ) ) : array(),
		);
	}

	/**
	 * Whether the connection is live: switched on, has a URL, holds a secret.
	 * All three, because each can honestly be absent on its own (a disconnect
	 * that half-failed, an import that carried settings but not options) and a
	 * "connected" claim missing any one of them could never deliver.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function connected( Settings $settings ) {
		$config = self::config( $settings );
		return $config['enabled'] && '' !== $config['url'] && self::has_secret();
	}

	/**
	 * Whether the owner ticked this event's box.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param string   $event    Event name.
	 * @return bool
	 */
	public static function wants( Settings $settings, $event ) {
		if ( ! self::connected( $settings ) ) {
			return false;
		}
		$config = self::config( $settings );
		return in_array( (string) $event, $config['events'], true );
	}

	/* -- The secret ---------------------------------------------------------- */

	/**
	 * Mint (and store) a fresh signing secret. The RETURN VALUE is the only
	 * place the plaintext ever appears — the caller shows it once and lets go.
	 *
	 * @return string The new secret.
	 */
	public static function mint_secret() {
		$secret = wp_generate_password( 48, false, false );
		update_option( self::SECRET_OPTION, $secret, false );
		return $secret;
	}

	/**
	 * Whether a secret exists — all any status surface may admit.
	 *
	 * @return bool
	 */
	public static function has_secret() {
		$secret = get_option( self::SECRET_OPTION, '' );
		return is_string( $secret ) && '' !== $secret;
	}

	/**
	 * The stored secret, for signing. Internal to the delivery path.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( self::SECRET_OPTION, '' );
		return is_string( $secret ) ? $secret : '';
	}

	/** Forget the secret — the disconnect path. */
	public static function forget_secret() {
		delete_option( self::SECRET_OPTION );
	}

	/* -- Signing + delivery -------------------------------------------------- */

	/**
	 * The signature header value for a body: HMAC-SHA256 over the EXACT bytes
	 * sent, hex, prefixed with the algorithm so the scheme can evolve without
	 * a second header.
	 *
	 * @param string $body   The JSON body, byte-for-byte as transmitted.
	 * @param string $secret Signing secret.
	 * @return string E.g. "sha256=3f5a…".
	 */
	public static function sign( $body, $secret ) {
		return 'sha256=' . hash_hmac( 'sha256', (string) $body, (string) $secret );
	}

	/**
	 * Deliver one envelope. One POST, one verdict — retry policy belongs to the
	 * queue (Dispatcher), not here.
	 *
	 * @param string $event    Event name (rides as a header for cheap routing).
	 * @param array  $envelope The versioned envelope to send.
	 * @return true|\WP_Error True on a 2xx; a WP_Error naming what went wrong.
	 */
	public static function deliver( $event, array $envelope ) {
		$config = self::config( new Settings() );
		$secret = self::secret();
		if ( '' === $config['url'] || '' === $secret ) {
			return new \WP_Error( 'agentimus_webhook_unconfigured', __( 'The webhook is not configured.', 'agentimus' ) );
		}

		$body = wp_json_encode( $envelope );
		if ( ! is_string( $body ) ) {
			return new \WP_Error( 'agentimus_webhook_body', __( 'The event could not be encoded.', 'agentimus' ) );
		}

		$response = wp_remote_post(
			$config['url'],
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0, // A webhook endpoint that redirects is misconfigured; following could re-POST the signed body somewhere the owner never named.
				'headers'     => array(
					'Content-Type'          => 'application/json; charset=utf-8',
					'X-Agentimus-Event'     => (string) $event,
					'X-Agentimus-Signature' => self::sign( $body, $secret ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'agentimus_webhook_status',
				sprintf(
					/* translators: %d: the HTTP status the webhook URL answered with. */
					__( 'The webhook URL answered %d.', 'agentimus' ),
					$code
				)
			);
		}
		return true;
	}

	/* -- Delivery state ------------------------------------------------------ */

	/**
	 * This connection's delivery state, always in its full shape.
	 *
	 * @return array{lastDeliveredAt:int,lastError:string,lastErrorAt:int}
	 */
	public static function state() {
		$all = get_option( self::STATE_OPTION, array() );
		$row = is_array( $all ) && isset( $all[ self::ID ] ) && is_array( $all[ self::ID ] ) ? $all[ self::ID ] : array();
		return array(
			'lastDeliveredAt' => isset( $row['lastDeliveredAt'] ) ? (int) $row['lastDeliveredAt'] : 0,
			'lastError'       => isset( $row['lastError'] ) ? (string) $row['lastError'] : '',
			'lastErrorAt'     => isset( $row['lastErrorAt'] ) ? (int) $row['lastErrorAt'] : 0,
		);
	}

	/**
	 * A delivery landed. A success also clears the standing error: the card
	 * shows the connection's PRESENT health, not its scars.
	 */
	public static function record_success() {
		self::write_state(
			array(
				'lastDeliveredAt' => time(),
				'lastError'       => '',
				'lastErrorAt'     => 0,
			)
		);
	}

	/**
	 * A delivery failed. The freshest error wins — it is the one the owner can
	 * still act on.
	 *
	 * @param string $message Plain-words failure reason.
	 */
	public static function record_failure( $message ) {
		$state = self::state();
		self::write_state(
			array(
				'lastDeliveredAt' => $state['lastDeliveredAt'],
				'lastError'       => substr( trim( (string) $message ), 0, 300 ),
				'lastErrorAt'     => time(),
			)
		);
	}

	/** Drop the state — the disconnect path. */
	public static function forget_state() {
		$all = get_option( self::STATE_OPTION, array() );
		if ( is_array( $all ) && isset( $all[ self::ID ] ) ) {
			unset( $all[ self::ID ] );
			if ( empty( $all ) ) {
				delete_option( self::STATE_OPTION );
			} else {
				update_option( self::STATE_OPTION, $all, false );
			}
		}
	}

	/**
	 * Store this connection's slice of the shared state map.
	 *
	 * @param array $row Full state row.
	 */
	private static function write_state( array $row ) {
		$all = get_option( self::STATE_OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ self::ID ] = $row;
		update_option( self::STATE_OPTION, $all, false );
	}
}
