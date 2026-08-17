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
use Agentimus\Integrations\DeliveryState;
use Agentimus\Integrations\Events;

defined( 'ABSPATH' ) || exit;

final class Webhook {

	/** This connection's id in settings and state. */
	const ID = 'webhook';

	/**
	 * The event the connect proof sends. ⛔ Deliberately NOT in
	 * Events::catalog(): the catalog is the list of things the owner can
	 * subscribe to, and nobody subscribes to a handshake. It travels in the
	 * same versioned envelope as everything else so a receiver written against
	 * the real shape can take it without a special case.
	 */
	const TEST_EVENT = 'connection_test';

	/** Option: the signing secret. Its own option, never in the settings array. */
	const SECRET_OPTION = 'agentimus_integrations_webhook_secret';

	/** Option: per-connection delivery state — DeliveryState's shared map. */
	const STATE_OPTION = DeliveryState::OPTION;

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

	/**
	 * The per-payload gate the service grammar reserves (Telegram's minimum
	 * finding tier lives there). The webhook forwards everything its checkbox
	 * subscribed to — the receiver is a machine; filtering is its job.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param string   $event    Event name.
	 * @param array    $data     Contract-shaped payload.
	 * @return bool
	 */
	public static function accepts( Settings $settings, $event, array $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the grammar's full signature.
		return true;
	}

	/* -- The secret ---------------------------------------------------------- */

	/**
	 * Mint (and store) a fresh signing secret. The RETURN VALUE is the only
	 * place the plaintext ever appears — the caller shows it once and lets go.
	 *
	 * @return string The new secret.
	 */
	public static function mint_secret() {
		$secret = self::new_secret();
		self::keep_secret( $secret );
		return $secret;
	}

	/**
	 * A candidate secret, generated and NOT stored.
	 *
	 * The proof needs something to sign with before the connection is allowed
	 * to exist, and minting into the option first would replace a working
	 * connection's secret with one that may never be kept — a failed connect
	 * would leave the previous receiver unable to check a signature it had
	 * already been given. So the two halves are separate: generate, prove,
	 * then keep.
	 *
	 * @return string
	 */
	public static function new_secret() {
		return wp_generate_password( 48, false, false );
	}

	/**
	 * Store a secret the proof has already vouched for.
	 *
	 * @param string $secret The secret to keep.
	 */
	public static function keep_secret( $secret ) {
		update_option( self::SECRET_OPTION, (string) $secret, false );
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
	 * Prove the road before the connection claims to exist: one real signed
	 * POST of a test event to the pasted URL.
	 *
	 * This service went longest without one, and it is the service that needed
	 * it most. Slack and Discord mint their URL inside a vendor's console, so a
	 * wrong one is rare; here the owner types an address by hand into a field
	 * next to no validation but "it looks like a URL", and the receiver is
	 * arbitrary — a relay that was never switched on, a Zapier hook whose zap
	 * was deleted, a path with a typo. Every one of those used to read
	 * CONNECTED while nothing could ever arrive.
	 *
	 * ⭐ The secret is PASSED IN, never read from storage: on a connect the
	 * secret this proof signs with does not exist yet, and must not be written
	 * until the receiver has taken it.
	 *
	 * @param string $url    The pasted receiver URL.
	 * @param string $secret The secret this connection will sign with.
	 * @return true|\WP_Error True when the receiver took the event.
	 */
	public static function verify( $url, $secret ) {
		return self::post(
			(string) $url,
			(string) $secret,
			self::TEST_EVENT,
			Events::envelope(
				self::TEST_EVENT,
				array(
					'message' => __( 'Agentimus test event — your site can reach this URL. The Integrations screen says whether the connection was saved. The events you picked arrive here as signed JSON.', 'agentimus' ),
				)
			)
		);
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

		return self::post( $config['url'], $secret, (string) $event, $envelope );
	}

	/**
	 * The one POST both the proof and every delivery make: the signed envelope,
	 * a short leash, no redirects. Written once so the proof can never travel
	 * on gentler terms than the deliveries it vouches for — a test that
	 * followed a redirect, or waited longer, would prove a road the queue
	 * cannot use.
	 *
	 * @param string $url      The receiver URL.
	 * @param string $secret   The signing secret.
	 * @param string $event    Event name (rides as a header for cheap routing).
	 * @param array  $envelope The versioned envelope to send.
	 * @return true|\WP_Error True on a 2xx; a WP_Error naming what went wrong.
	 */
	private static function post( $url, $secret, $event, array $envelope ) {
		$body = wp_json_encode( $envelope );
		if ( ! is_string( $body ) ) {
			return new \WP_Error( 'agentimus_webhook_body', __( 'The event could not be encoded.', 'agentimus' ) );
		}

		$response = wp_remote_post(
			$url,
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
			// The transport's own words are the useful half, but alone they are
			// a bare string ("cURL error 28") sitting under a card with no
			// subject. Name what could not be reached.
			return new \WP_Error(
				'agentimus_webhook_unreachable',
				sprintf(
					/* translators: %s: the transport error, e.g. a timeout. */
					__( 'The webhook URL could not be reached: %s', 'agentimus' ),
					$response->get_error_message()
				)
			);
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

	/* -- Delivery state (this service's slice of the shared honesty line) ---- */

	/**
	 * This connection's delivery state, always in its full shape.
	 *
	 * @return array{lastDeliveredAt:int,lastError:string,lastErrorAt:int}
	 */
	public static function state() {
		return DeliveryState::read( self::ID );
	}

	/** A delivery landed (a success also clears the standing error). */
	public static function record_success() {
		DeliveryState::success( self::ID );
	}

	/**
	 * A delivery failed; the freshest error wins.
	 *
	 * @param string $message Plain-words failure reason.
	 */
	public static function record_failure( $message ) {
		DeliveryState::failure( self::ID, $message );
	}

	/** Drop the state — the disconnect path. */
	public static function forget_state() {
		DeliveryState::forget( self::ID );
	}
}
