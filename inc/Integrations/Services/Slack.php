<?php
/**
 * Slack — events into a channel, where a team already looks.
 *
 * The connection is Slack's own humblest kind: an incoming-webhook URL, minted
 * inside the owner's workspace, pasted here. The URL IS the credential (that
 * is Slack's design, not ours), so it rides in settings the way the outgoing
 * webhook's URL does — there is no second secret to keep. Slack-compatible
 * receivers (Mattermost, Rocket.Chat) speak the same payload, so the URL is
 * validated as a URL, never pinned to Slack's hostname.
 *
 * Connecting proves the road: the pasted URL takes one real one-line message
 * before anything is stored, so "Connected" is never a claim about a webhook
 * that has been revoked, deleted or mistyped.
 *
 * Delivery is a formatter over the shared pipeline: each envelope becomes one
 * Block Kit message — a section that says the thing, and a context line that
 * names the site it came from — plus the plain-text fallback Slack shows in
 * notifications. Anything interpolated from site data rides through mrkdwn
 * escaping, so a title containing an angle bracket can never become a link.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Services;

use Agentimus\Settings;
use Agentimus\Integrations\DeliveryState;
use Agentimus\Integrations\Events;

defined( 'ABSPATH' ) || exit;

final class Slack {

	/** This connection's id in settings, state and queue rows. */
	const ID = 'slack';

	/** Seconds an outgoing delivery may take — the queue's short leash. */
	const TIMEOUT = 5;

	/* -- Configuration ------------------------------------------------------ */

	/**
	 * The stored Slack configuration, always in its full shape.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array{enabled:bool,url:string,events:array<int,string>}
	 */
	public static function config( Settings $settings ) {
		$integrations = (array) $settings->get( 'integrations', array() );
		return array(
			'enabled' => ! empty( $integrations['slack_enabled'] ),
			'url'     => isset( $integrations['slack_url'] ) ? (string) $integrations['slack_url'] : '',
			'events'  => isset( $integrations['slack_events'] ) ? array_values( array_map( 'strval', (array) $integrations['slack_events'] ) ) : array(),
		);
	}

	/**
	 * Whether the connection is live: switched on and holding a URL. Two
	 * conditions, not three — Slack's URL is its own credential.
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
	 * one-line message to the pasted URL. A webhook that was mistyped, revoked
	 * or deleted with its channel answers HERE — not days later, in a failure
	 * line under an event nobody watched arrive. The URL being the whole
	 * credential is exactly why this matters: there is nothing else to check.
	 *
	 * @param string $url The pasted incoming-webhook URL.
	 * @return true|\WP_Error True when Slack took the message.
	 */
	public static function verify( $url ) {
		return self::post(
			(string) $url,
			array( 'text' => __( 'Agentimus test message — your site can reach this channel. The Integrations screen says whether the connection was saved, and is where you change events or disconnect.', 'agentimus' ) )
		);
	}

	/* -- Delivery ------------------------------------------------------------- */

	/**
	 * Deliver one envelope as one Block Kit message. One POST, one verdict —
	 * retry policy belongs to the queue, not here.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope to format.
	 * @return true|\WP_Error True on success; a WP_Error naming what went wrong.
	 */
	public static function deliver( $event, array $envelope ) {
		$config = self::config( new Settings() );
		if ( '' === $config['url'] ) {
			return new \WP_Error( 'agentimus_slack_unconfigured', __( 'Slack is not configured.', 'agentimus' ) );
		}

		return self::post(
			$config['url'],
			array(
				'text'   => self::fallback( (string) $event, $envelope ),
				'blocks' => self::blocks( (string) $event, $envelope ),
			)
		);
	}

	/**
	 * The one POST both the proof and every delivery make: JSON to the pasted
	 * URL, a short leash, no redirects. Telegram splits this (its proof calls
	 * getMe, its deliveries call sendMessage); here the proof IS a delivery of
	 * a shorter message, so one call site holds both — and one grammar answers
	 * for both.
	 *
	 * @param string $url     The webhook URL.
	 * @param array  $payload The message, already in Slack's shape.
	 * @return true|\WP_Error True on a 2xx; a WP_Error repeating Slack's own words when it gave any.
	 */
	private static function post( $url, array $payload ) {
		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return new \WP_Error( 'agentimus_slack_body', __( 'The message could not be encoded.', 'agentimus' ) );
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
				'agentimus_slack_unreachable',
				sprintf(
					/* translators: %s: the transport error, e.g. a timeout. */
					__( 'Slack could not be reached: %s', 'agentimus' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		// Slack's terse verdicts ("no_service", "invalid_payload") are exactly
		// one word — worth repeating when they exist.
		$word = substr( trim( wp_remote_retrieve_body( $response ) ), 0, 40 );
		return new \WP_Error(
			'agentimus_slack_status',
			'' !== $word && preg_match( '/^[a-z0-9_ -]+$/i', $word )
				? sprintf(
					/* translators: 1: the HTTP status Slack answered with, 2: Slack's own one-word verdict. */
					__( 'Slack answered %1$d: %2$s', 'agentimus' ),
					$code,
					$word
				)
				: sprintf(
					/* translators: %d: the HTTP status Slack answered with. */
					__( 'Slack answered %d.', 'agentimus' ),
					$code
				)
		);
	}

	/**
	 * The formatter: one envelope, one Block Kit message — a section that says
	 * the thing, and a context line naming the site. Pure — the tests hold
	 * each event's exact shape.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope ({ event, site, data, … }).
	 * @return array<int,array>
	 */
	public static function blocks( $event, array $envelope ) {
		$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$host = self::host( isset( $envelope['site'] ) ? (string) $envelope['site'] : '' );

		$blocks   = array();
		$blocks[] = self::body_block( (string) $event, $data );
		$blocks[] = array(
			'type'     => 'context',
			'elements' => array(
				array(
					'type' => 'mrkdwn',
					'text' => self::esc( $host ) . ' — Agentimus',
				),
			),
		);
		return $blocks;
	}

	/**
	 * The plain one-liner Slack shows in notifications (and any receiver too
	 * old for blocks falls back to).
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope.
	 * @return string
	 */
	public static function fallback( $event, array $envelope ) {
		$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();

		switch ( (string) $event ) {
			case Events::FINDING_OPENED:
				return sprintf(
					/* translators: 1: the finding's tier, 2: its title. */
					__( 'New finding (%1$s): %2$s', 'agentimus' ),
					isset( $data['tier'] ) ? (string) $data['tier'] : '',
					isset( $data['title'] ) ? (string) $data['title'] : ''
				);
			case Events::DIGEST_SENT:
				return sprintf(
					/* translators: 1: agent visits, 2: AI referrals, 3: impostors. */
					__( 'Weekly digest: %1$d agent visits, %2$d AI referrals, %3$d impostors.', 'agentimus' ),
					isset( $data['agents'] ) ? (int) $data['agents'] : 0,
					isset( $data['referrals'] ) ? (int) $data['referrals'] : 0,
					isset( $data['impostors'] ) ? (int) $data['impostors'] : 0
				);
			case Events::IMPOSTOR_FLAGGED:
				return sprintf(
					/* translators: %s: the client name that failed its check. */
					__( 'Impostor caught: %s', 'agentimus' ),
					isset( $data['client'] ) ? (string) $data['client'] : ''
				);
			case Events::ROBOTS_CHANGED:
				return __( 'robots.txt policy changed', 'agentimus' );
			case Events::CITATION_RUN_FINISHED:
				return sprintf(
					/* translators: %d: how many checks the run made. */
					__( 'Citation check finished: %d checks', 'agentimus' ),
					isset( $data['checks'] ) ? (int) $data['checks'] : 0
				);
			case Events::AGENT_WROTE_CONTENT:
				return sprintf(
					/* translators: 1: created/updated, 2: the post title. */
					__( 'An AI assistant %1$s “%2$s”', 'agentimus' ),
					'update' === ( isset( $data['action'] ) ? (string) $data['action'] : '' ) ? __( 'updated', 'agentimus' ) : __( 'created', 'agentimus' ),
					isset( $data['title'] ) ? (string) $data['title'] : ''
				);
		}
		return (string) $event;
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
	 * The message's body block for one event.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Contract-shaped payload.
	 * @return array
	 */
	private static function body_block( $event, array $data ) {
		switch ( $event ) {
			case Events::FINDING_OPENED:
				$text = '*' . sprintf(
					/* translators: %s: the finding's tier in words. */
					__( 'New finding — %s', 'agentimus' ),
					self::esc( self::tier_words( isset( $data['tier'] ) ? (string) $data['tier'] : '' ) )
				) . '*'
					. "\n" . self::esc( isset( $data['title'] ) ? (string) $data['title'] : '' );
				if ( ! empty( $data['url'] ) ) {
					$text .= "\n<" . self::esc( (string) $data['url'] ) . '|' . __( 'Open the Findings screen', 'agentimus' ) . '>';
				}
				return self::section( $text );

			case Events::DIGEST_SENT:
				$label   = isset( $data['period']['label'] ) ? (string) $data['period']['label'] : '';
				$section = self::section(
					'*' . ( '' !== $label
						? sprintf(
							/* translators: %s: the digest period's label. */
							__( 'Weekly digest — %s', 'agentimus' ),
							self::esc( $label )
						)
						: __( 'Weekly digest', 'agentimus' ) ) . '*'
				);
				$fields  = array(
					self::field( __( 'Agent visits', 'agentimus' ), (string) ( isset( $data['agents'] ) ? (int) $data['agents'] : 0 ) ),
					self::field( __( 'AI referrals', 'agentimus' ), (string) ( isset( $data['referrals'] ) ? (int) $data['referrals'] : 0 ) ),
					self::field( __( 'Impostors caught', 'agentimus' ), (string) ( isset( $data['impostors'] ) ? (int) $data['impostors'] : 0 ) ),
				);
				$now     = isset( $data['score']['now'] ) ? $data['score']['now'] : null;
				$prev    = isset( $data['score']['prev'] ) ? $data['score']['prev'] : null;
				if ( null !== $now ) {
					$score = (string) (int) $now;
					if ( null !== $prev && (int) $prev !== (int) $now ) {
						$score .= sprintf(
							/* translators: %d: the previous score. */
							__( ' (was %d)', 'agentimus' ),
							(int) $prev
						);
					}
					$fields[] = self::field( __( 'Score', 'agentimus' ), $score );
				}
				$section['fields'] = $fields;
				return $section;

			case Events::IMPOSTOR_FLAGGED:
				return self::section(
					'*' . __( 'Impostor caught', 'agentimus' ) . '*' . "\n" . sprintf(
						/* translators: %s: the client name that failed its check. */
						__( 'A client calling itself “%s” failed its operator’s check.', 'agentimus' ),
						self::esc( isset( $data['client'] ) ? (string) $data['client'] : '' )
					)
				);

			case Events::ROBOTS_CHANGED:
				$text    = '*' . __( 'robots.txt policy changed', 'agentimus' ) . '*';
				$added   = isset( $data['added'] ) ? array_filter( array_map( 'strval', (array) $data['added'] ), 'strlen' ) : array();
				$removed = isset( $data['removed'] ) ? array_filter( array_map( 'strval', (array) $data['removed'] ), 'strlen' ) : array();
				if ( array() !== $added ) {
					$text .= "\n" . __( 'Added:', 'agentimus' ) . "\n" . self::code_lines( $added );
				}
				if ( array() !== $removed ) {
					$text .= "\n" . __( 'Removed:', 'agentimus' ) . "\n" . self::code_lines( $removed );
				}
				return self::section( $text );

			case Events::CITATION_RUN_FINISHED:
				$checks = isset( $data['checks'] ) ? (int) $data['checks'] : 0;
				$text   = '*' . __( 'Citation check finished', 'agentimus' ) . '*' . "\n" . sprintf(
					/* translators: %d: how many checks the run made. */
					_n( '%d check run.', '%d checks run.', $checks, 'agentimus' ),
					$checks
				);
				if ( ! empty( $data['capped'] ) ) {
					$text .= ' ' . __( 'The run stopped at its cap.', 'agentimus' );
				}
				return self::section( $text );

			case Events::AGENT_WROTE_CONTENT:
				$updated = 'update' === ( isset( $data['action'] ) ? (string) $data['action'] : '' );
				$text    = '*' . ( $updated ? __( 'Content updated by an AI assistant', 'agentimus' ) : __( 'Content created by an AI assistant', 'agentimus' ) ) . '*'
					. "\n" . sprintf( '“%s”', self::esc( isset( $data['title'] ) ? (string) $data['title'] : '' ) );
				if ( ! empty( $data['ability'] ) ) {
					$text .= sprintf(
						/* translators: %s: the tool (ability) that made the write. */
						__( ' — via %s', 'agentimus' ),
						self::esc( (string) $data['ability'] )
					);
				}
				return self::section( $text );
		}

		// A filter-added event this formatter never learned: name it honestly.
		return self::section( '*' . self::esc( $event ) . '*' );
	}

	/**
	 * A section block around one mrkdwn text.
	 *
	 * @param string $text Escaped mrkdwn.
	 * @return array
	 */
	private static function section( $text ) {
		return array(
			'type' => 'section',
			'text' => array(
				'type' => 'mrkdwn',
				'text' => $text,
			),
		);
	}

	/**
	 * One digest field: a bold label over its number.
	 *
	 * @param string $label Field label (our own copy, not site data).
	 * @param string $value Field value.
	 * @return array
	 */
	private static function field( $label, $value ) {
		return array(
			'type' => 'mrkdwn',
			'text' => '*' . $label . '*' . "\n" . $value,
		);
	}

	/**
	 * Robots lines as mrkdwn code, one per line — policy text must read as
	 * text, never as formatting.
	 *
	 * @param array $lines Changed lines.
	 * @return string
	 */
	private static function code_lines( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			$out[] = '`' . self::esc( str_replace( '`', '', (string) $line ) ) . '`';
		}
		return implode( "\n", $out );
	}

	/**
	 * Slack's mrkdwn escaping: exactly &, < and > — the three characters with
	 * powers. Applied to everything interpolated from site data.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function esc( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
	}

	/**
	 * The finding tier in words (Slack shares Telegram's vocabulary here, each
	 * holding its own copy — services never import each other).
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
