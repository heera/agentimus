<?php
/**
 * Discord — the same events, posted to a server the owner runs.
 *
 * The connection mirrors Slack's: a webhook URL minted inside the owner's
 * server (Server Settings → Integrations → Webhooks), pasted here, the URL
 * being the whole credential by Discord's design. Same collapse law: no URL,
 * no connection — and the same proof on connect: the URL takes one real
 * one-line message before anything is stored, so "Connected" is never a claim
 * about a webhook that has been revoked, deleted or mistyped.
 *
 * Delivery is a formatter over the shared pipeline: each envelope becomes one
 * EMBED — a titled card with the event's fact as its description and the site
 * named in the footer. Alarm colours are reserved for the two events that are
 * alarms (an urgent finding, a caught impostor); everything else wears the
 * one neutral tone, so colour keeps meaning something. Discord answers a
 * successful webhook POST with 204 No Content — the shared 2xx check already
 * counts that as delivered.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Services;

use Agentimus\Settings;
use Agentimus\Integrations\DeliveryState;
use Agentimus\Integrations\Events;

defined( 'ABSPATH' ) || exit;

final class Discord {

	/** This connection's id in settings, state and queue rows. */
	const ID = 'discord';

	/** Seconds an outgoing delivery may take — the queue's short leash. */
	const TIMEOUT = 5;

	/** Embed colour for the two alarm events (urgent finding, impostor). */
	const COLOR_ALERT = 0xC0392B;

	/** Embed colour for everything that is news, not an alarm. */
	const COLOR_NEUTRAL = 0x5865F2;

	/** Discord's embed title ceiling. */
	const TITLE_MAX = 256;

	/* -- Configuration ------------------------------------------------------ */

	/**
	 * The stored Discord configuration, always in its full shape.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array{enabled:bool,url:string,events:array<int,string>}
	 */
	public static function config( Settings $settings ) {
		$integrations = (array) $settings->get( 'integrations', array() );
		return array(
			'enabled' => ! empty( $integrations['discord_enabled'] ),
			'url'     => isset( $integrations['discord_url'] ) ? (string) $integrations['discord_url'] : '',
			'events'  => isset( $integrations['discord_events'] ) ? array_values( array_map( 'strval', (array) $integrations['discord_events'] ) ) : array(),
		);
	}

	/**
	 * Whether the connection is live: switched on and holding a URL — the URL
	 * is its own credential, as with Slack.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function connected( Settings $settings ) {
		$config = self::config( $settings );
		return $config['enabled'] && '' !== $config['url'];
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
	 * No per-payload gate: a channel scrolls, so everything the checkbox
	 * subscribed to may arrive.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param string   $event    Event name.
	 * @param array    $data     Contract-shaped payload.
	 * @return bool
	 */
	public static function accepts( Settings $settings, $event, array $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the grammar's full signature.
		return true;
	}

	/* -- The proof ------------------------------------------------------------ */

	/**
	 * Prove the road before the connection claims to exist: one real POST of a
	 * one-line message to the pasted URL. Discord's own answer to a webhook
	 * that no longer exists is a 404 saying "Unknown Webhook" — a sentence the
	 * owner can act on, and one worth hearing at connect time rather than days
	 * later under an event nobody watched arrive.
	 *
	 * @param string $url The pasted webhook URL.
	 * @return true|\WP_Error True when Discord took the message.
	 */
	public static function verify( $url ) {
		return self::post(
			(string) $url,
			array( 'content' => __( 'Agentimus test message — your site can reach this channel. The Integrations screen says whether the connection was saved, and is where you change events or disconnect.', 'agentimus' ) )
		);
	}

	/* -- Delivery ------------------------------------------------------------- */

	/**
	 * Deliver one envelope as one embed. One POST, one verdict — retry policy
	 * belongs to the queue, not here.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope to format.
	 * @return true|\WP_Error True on success; a WP_Error naming what went wrong.
	 */
	public static function deliver( $event, array $envelope ) {
		$config = self::config( new Settings() );
		if ( '' === $config['url'] ) {
			return new \WP_Error( 'agentimus_discord_unconfigured', __( 'Discord is not configured.', 'agentimus' ) );
		}

		return self::post( $config['url'], array( 'embeds' => array( self::embed( (string) $event, $envelope ) ) ) );
	}

	/**
	 * The one POST both the proof and every delivery make: JSON to the pasted
	 * URL, a short leash, no redirects. The proof IS a delivery of a shorter
	 * message, so one call site holds both — and one grammar answers for both.
	 *
	 * @param string $url     The webhook URL.
	 * @param array  $payload The message, already in Discord's shape.
	 * @return true|\WP_Error True on a 2xx; a WP_Error repeating Discord's own words when it gave any.
	 */
	private static function post( $url, array $payload ) {
		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return new \WP_Error( 'agentimus_discord_body', __( 'The message could not be encoded.', 'agentimus' ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'agentimus_discord_unreachable',
				sprintf(
					/* translators: %s: the transport error, e.g. a timeout. */
					__( 'Discord could not be reached: %s', 'agentimus' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true; // 204 No Content is Discord's own yes.
		}

		// Discord's error bodies are JSON with a `message` ("Unknown Webhook")
		// — the sentence the owner can act on.
		$parsed  = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = is_array( $parsed ) && isset( $parsed['message'] ) ? substr( trim( (string) $parsed['message'] ), 0, 140 ) : '';
		return new \WP_Error(
			'agentimus_discord_status',
			'' !== $message
				? sprintf(
					/* translators: 1: the HTTP status Discord answered with, 2: Discord's own error message. */
					__( 'Discord answered %1$d: %2$s', 'agentimus' ),
					$code,
					$message
				)
				: sprintf(
					/* translators: %d: the HTTP status Discord answered with. */
					__( 'Discord answered %d.', 'agentimus' ),
					$code
				)
		);
	}

	/**
	 * The formatter: one envelope, one embed. Pure — the tests hold each
	 * event's exact shape.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope ({ event, site, data, … }).
	 * @return array The embed object.
	 */
	public static function embed( $event, array $envelope ) {
		$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$host = self::host( isset( $envelope['site'] ) ? (string) $envelope['site'] : '' );

		$embed = array_merge(
			self::body( (string) $event, $data ),
			array( 'footer' => array( 'text' => $host ) )
		);

		$embed['title'] = substr( (string) $embed['title'], 0, self::TITLE_MAX );
		return $embed;
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

	/* -- Internals ------------------------------------------------------------ */

	/**
	 * The embed's body — title, description, colour, and (for a finding) the
	 * link — for one event.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Contract-shaped payload.
	 * @return array
	 */
	private static function body( $event, array $data ) {
		switch ( $event ) {
			case Events::FINDING_OPENED:
				$tier = isset( $data['tier'] ) ? (string) $data['tier'] : '';
				$body = array(
					'title'       => sprintf(
						/* translators: %s: the finding's tier in words. */
						__( 'New finding — %s', 'agentimus' ),
						self::tier_words( $tier )
					),
					'description' => isset( $data['title'] ) ? (string) $data['title'] : '',
					'color'       => 'urgent' === $tier ? self::COLOR_ALERT : self::COLOR_NEUTRAL,
				);
				if ( ! empty( $data['url'] ) ) {
					$body['url'] = (string) $data['url'];
				}
				return $body;

			case Events::DIGEST_SENT:
				$label       = isset( $data['period']['label'] ) ? (string) $data['period']['label'] : '';
				$description = sprintf(
					/* translators: 1: agent visits, 2: AI referrals, 3: impostors. */
					__( '%1$d agent visits, %2$d AI referrals, %3$d impostors caught.', 'agentimus' ),
					isset( $data['agents'] ) ? (int) $data['agents'] : 0,
					isset( $data['referrals'] ) ? (int) $data['referrals'] : 0,
					isset( $data['impostors'] ) ? (int) $data['impostors'] : 0
				);
				$now         = isset( $data['score']['now'] ) ? $data['score']['now'] : null;
				$prev        = isset( $data['score']['prev'] ) ? $data['score']['prev'] : null;
				if ( null !== $now ) {
					$description .= "\n" . sprintf(
						/* translators: %d: the current readiness score. */
						__( 'Score %d', 'agentimus' ),
						(int) $now
					);
					if ( null !== $prev && (int) $prev !== (int) $now ) {
						$description .= sprintf(
							/* translators: %d: the previous score. */
							__( ' (was %d)', 'agentimus' ),
							(int) $prev
						);
					}
				}
				return array(
					'title'       => '' !== $label
						? sprintf(
							/* translators: %s: the digest period's label. */
							__( 'Weekly digest — %s', 'agentimus' ),
							$label
						)
						: __( 'Weekly digest', 'agentimus' ),
					'description' => $description,
					'color'       => self::COLOR_NEUTRAL,
				);

			case Events::IMPOSTOR_FLAGGED:
				return array(
					'title'       => __( 'Impostor caught', 'agentimus' ),
					'description' => sprintf(
						/* translators: %s: the client name that failed its check. */
						__( 'A client calling itself “%s” failed its operator’s check.', 'agentimus' ),
						isset( $data['client'] ) ? (string) $data['client'] : ''
					),
					'color'       => self::COLOR_ALERT,
				);

			case Events::ROBOTS_CHANGED:
				$description = '';
				$added       = isset( $data['added'] ) ? array_filter( array_map( 'strval', (array) $data['added'] ), 'strlen' ) : array();
				$removed     = isset( $data['removed'] ) ? array_filter( array_map( 'strval', (array) $data['removed'] ), 'strlen' ) : array();
				if ( array() !== $added ) {
					$description .= __( 'Added:', 'agentimus' ) . "\n" . implode( "\n", $added );
				}
				if ( array() !== $removed ) {
					$description .= ( '' !== $description ? "\n" : '' ) . __( 'Removed:', 'agentimus' ) . "\n" . implode( "\n", $removed );
				}
				return array(
					'title'       => __( 'robots.txt policy changed', 'agentimus' ),
					'description' => $description,
					'color'       => self::COLOR_NEUTRAL,
				);

			case Events::CITATION_RUN_FINISHED:
				$checks      = isset( $data['checks'] ) ? (int) $data['checks'] : 0;
				$description = sprintf(
					/* translators: %d: how many checks the run made. */
					_n( '%d check run.', '%d checks run.', $checks, 'agentimus' ),
					$checks
				);
				if ( ! empty( $data['capped'] ) ) {
					$description .= ' ' . __( 'The run stopped at its cap.', 'agentimus' );
				}
				return array(
					'title'       => __( 'Citation check finished', 'agentimus' ),
					'description' => $description,
					'color'       => self::COLOR_NEUTRAL,
				);

			case Events::AGENT_WROTE_CONTENT:
				$updated     = 'update' === ( isset( $data['action'] ) ? (string) $data['action'] : '' );
				$description = sprintf( '“%s”', isset( $data['title'] ) ? (string) $data['title'] : '' );
				if ( ! empty( $data['ability'] ) ) {
					$description .= sprintf(
						/* translators: %s: the tool (ability) that made the write. */
						__( ' — via %s', 'agentimus' ),
						(string) $data['ability']
					);
				}
				return array(
					'title'       => $updated ? __( 'Content updated by an AI assistant', 'agentimus' ) : __( 'Content created by an AI assistant', 'agentimus' ),
					'description' => $description,
					'color'       => self::COLOR_NEUTRAL,
				);
		}

		// A filter-added event this formatter never learned: name it honestly.
		return array(
			'title'       => $event,
			'description' => '',
			'color'       => self::COLOR_NEUTRAL,
		);
	}

	/**
	 * The finding tier in words (each service holds its own copy — services
	 * never import each other).
	 *
	 * @param string $tier Findings tier id.
	 * @return string
	 */
	private static function tier_words( $tier ) {
		if ( 'urgent' === $tier ) {
			return __( 'urgent', 'agentimus' );
		}
		if ( 'worth' === $tier ) {
			return __( 'worth knowing', 'agentimus' );
		}
		if ( 'later' === $tier ) {
			return __( 'for later', 'agentimus' );
		}
		return $tier;
	}

	/**
	 * A message-sized name for the site: its host.
	 *
	 * @param string $site The envelope's site URL.
	 * @return string
	 */
	private static function host( $site ) {
		$host = wp_parse_url( (string) $site, PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $host : trim( (string) $site, '/' );
	}
}
