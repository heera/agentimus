<?php
/**
 * Telegram — events as plain messages from a bot the owner made, so a phone
 * learns what the site saw without any vendor between them.
 *
 * The shape of the trust: the owner mints a bot with @BotFather and pastes its
 * TOKEN here; the token is the credential, so it lives in the shared
 * connection store — never in the settings array the admin app round-trips,
 * and no status surface ever prints it back. The store is what lets a second
 * use (announcing posts) ride the same bot without a second token form. The
 * CHAT ID is an address, not a secret, and it belongs to THIS use — events to
 * the owner's chat and announcements to a public channel are different rooms
 * for different audiences — so it rides in settings like the webhook's URL.
 *
 * Connect proves the whole road before it stores anything, in two steps that
 * fail with two different sentences: getMe proves the TOKEN is a bot Telegram
 * recognises, then one test message proves the bot can actually reach the
 * CHAT (Telegram only lets a bot write where it has been let in). A vague
 * "connection failed" would leave the owner rechecking the wrong field.
 *
 * Delivery is a formatter over the shared pipeline: the queue and its cron do
 * the timing and the retries; this class only turns one envelope into one
 * readable message. The minimum-tier setting is this service's own payload
 * gate — a phone that buzzes for every "worth knowing" stops being looked at,
 * so the owner can keep Telegram for urgent findings while the webhook still
 * receives everything.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Services;

use Agentimus\Findings;
use Agentimus\Settings;
use Agentimus\Integrations\Connections;
use Agentimus\Integrations\DeliveryState;
use Agentimus\Integrations\Events;

defined( 'ABSPATH' ) || exit;

final class Telegram {

	/** This connection's id in settings, state and queue rows. */
	const ID = 'telegram';

	/**
	 * Option: where the token lived before the shared connection store. Reads
	 * still adopt it so an install that connected under the old build keeps
	 * its bot without anyone retyping anything.
	 */
	const TOKEN_OPTION = 'agentimus_integrations_telegram_token';

	/** Telegram's Bot API root. */
	const API = 'https://api.telegram.org';

	/** Seconds an outgoing call may take — the queue's short leash. */
	const TIMEOUT = 5;

	/* -- Configuration ------------------------------------------------------ */

	/**
	 * The stored Telegram configuration, always in its full shape.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array{enabled:bool,chat:string,events:array<int,string>,tier:string}
	 */
	public static function config( Settings $settings ) {
		$integrations = (array) $settings->get( 'integrations', array() );
		return array(
			'enabled' => ! empty( $integrations['telegram_enabled'] ),
			'chat'    => isset( $integrations['telegram_chat'] ) ? (string) $integrations['telegram_chat'] : '',
			'events'  => isset( $integrations['telegram_events'] ) ? array_values( array_map( 'strval', (array) $integrations['telegram_events'] ) ) : array(),
			'tier'    => isset( $integrations['telegram_tier'] ) && 'urgent' === $integrations['telegram_tier'] ? 'urgent' : 'all',
		);
	}

	/**
	 * Whether the connection is live: switched on, has a chat, holds a token —
	 * all three, for the same reason the webhook demands all of its three.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function connected( Settings $settings ) {
		$config = self::config( $settings );
		return $config['enabled'] && '' !== $config['chat'] && self::has_token();
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
	 * The minimum-tier gate: with "urgent only" set, a finding below urgent
	 * stays off the phone. Every other event passes untouched — the tier is a
	 * findings word, and inventing one for the rest would be a lie.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param string   $event    Event name.
	 * @param array    $data     Contract-shaped payload.
	 * @return bool
	 */
	public static function accepts( Settings $settings, $event, array $data ) {
		if ( Events::FINDING_OPENED !== (string) $event ) {
			return true;
		}
		if ( 'urgent' !== self::config( $settings )['tier'] ) {
			return true;
		}
		return isset( $data['tier'] ) && Findings::URGENT === (string) $data['tier'];
	}

	/**
	 * A chat id as Telegram will take it: a number (negative for groups and
	 * channels), or an @name for a public channel the owner runs. Anything
	 * else normalizes to '' — stored garbage would fail on every delivery.
	 *
	 * @param string $chat Raw input.
	 * @return string The normalized chat id, or ''.
	 */
	public static function normalize_chat( $chat ) {
		$chat = trim( (string) $chat );
		return preg_match( '/^(@[A-Za-z0-9_]{1,64}|-?[0-9]{1,32})$/', $chat ) ? $chat : '';
	}

	/* -- The sharing use ------------------------------------------------------ */

	/**
	 * The stored SHARING use of this bot — announcing posts to a channel. Its
	 * own switch and its own destination, deliberately apart from the events
	 * use above: events tell the OWNER (a private chat), announcements tell
	 * the READERS (a public channel), and one shared field would quietly
	 * invite both mistakes.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array{enabled:bool,channel:string}
	 */
	public static function sharing_config( Settings $settings ) {
		$integrations = (array) $settings->get( 'integrations', array() );
		return array(
			'enabled' => ! empty( $integrations['telegram_share_enabled'] ),
			'channel' => isset( $integrations['telegram_share_channel'] ) ? (string) $integrations['telegram_share_channel'] : '',
		);
	}

	/**
	 * Whether the sharing use could deliver: switched on, has a channel, and
	 * the shared connection holds the bot. The token check is what makes
	 * "connected in Services" count here too — one credential, two uses.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function sharing_active( Settings $settings ) {
		$config = self::sharing_config( $settings );
		return $config['enabled'] && '' !== $config['channel'] && self::has_token();
	}

	/**
	 * Post one announcement to the sharing channel — the queue's send path.
	 * One send, one verdict; what happens to a failed row is the queue's
	 * business, and the queue's law there is the OWNER's hand, never a retry.
	 *
	 * @param string $text The announcement, posted verbatim.
	 * @return true|\WP_Error True, or Telegram's own words on what went wrong.
	 */
	public static function announce( $text ) {
		$settings = new Settings();
		if ( ! self::sharing_active( $settings ) ) {
			return new \WP_Error( 'agentimus_telegram_sharing_off', __( 'Announcing by Telegram is not set up.', 'agentimus' ) );
		}
		return self::send( self::token(), self::sharing_config( $settings )['channel'], (string) $text );
	}

	/**
	 * Prove the announcement road: one test post to the channel, in the
	 * sharing use's own words — the events connect-sentence would promise the
	 * wrong thing in a room readers can see.
	 *
	 * @param string $channel The channel to prove.
	 * @return true|\WP_Error True, or the sentence naming what to fix.
	 */
	public static function prove_channel( $channel ) {
		$verdict = self::send(
			self::token(),
			$channel,
			__( 'Agentimus connected. Announcements you schedule will be posted here.', 'agentimus' )
		);
		if ( is_wp_error( $verdict ) ) {
			return new \WP_Error(
				'agentimus_telegram_channel',
				__( 'The bot couldn’t post to that channel. Check the @name — and make sure the bot has been added to the channel as an admin, because Telegram only lets admins post.', 'agentimus' )
			);
		}
		return true;
	}

	/* -- The token ----------------------------------------------------------- */

	/**
	 * Store the owner-supplied bot token — the credential BotFather minted,
	 * never one of ours. It lands in the shared connection store, the one
	 * home every use of this bot reads.
	 *
	 * @param string $token The bot token.
	 */
	public static function store_token( $token ) {
		Connections::store( self::ID, array( 'token' => (string) $token ) );
		delete_option( self::TOKEN_OPTION );
	}

	/**
	 * Whether a token exists — all any status surface may admit.
	 *
	 * @return bool
	 */
	public static function has_token() {
		return '' !== self::token();
	}

	/**
	 * The stored token, for the delivery path. A token stored before the
	 * shared store existed is adopted on first read — moved, not copied, so
	 * the credential keeps exactly one home.
	 *
	 * @return string
	 */
	public static function token() {
		$row = Connections::read( self::ID );
		if ( isset( $row['token'] ) && is_string( $row['token'] ) && '' !== $row['token'] ) {
			return $row['token'];
		}

		$legacy = get_option( self::TOKEN_OPTION, '' );
		if ( is_string( $legacy ) && '' !== $legacy ) {
			Connections::store( self::ID, array( 'token' => $legacy ) );
			delete_option( self::TOKEN_OPTION );
			return $legacy;
		}
		return '';
	}

	/**
	 * Forget the token — the disconnect path for EVERY use of this bot, and
	 * the surface that offers the button owes the owner that sentence.
	 */
	public static function forget_token() {
		Connections::forget( self::ID );
		delete_option( self::TOKEN_OPTION );
	}

	/* -- Connect-time proof --------------------------------------------------- */

	/**
	 * Prove the road end to end: the token names a real bot (getMe), and that
	 * bot can reach the chat (one test message). Two steps, two distinct
	 * errors — the owner must always know WHICH field to recheck.
	 *
	 * @param string $token Bot token to prove.
	 * @param string $chat  Chat id to prove.
	 * @return true|\WP_Error True, or an error whose code names the bad field
	 *                        (agentimus_telegram_token / agentimus_telegram_chat).
	 */
	public static function verify( $token, $chat ) {
		$response = wp_remote_get(
			self::API . '/bot' . self::url_token( $token ) . '/getMe',
			array( 'timeout' => self::TIMEOUT )
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'agentimus_telegram_unreachable',
				sprintf(
					/* translators: %s: the transport error, e.g. a timeout. */
					__( 'Telegram could not be reached: %s', 'agentimus' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['ok'] ) ) {
			return new \WP_Error(
				'agentimus_telegram_token',
				__( 'Telegram didn’t recognise that bot token. Check it against what @BotFather sent you — the long code that looks like 123456789:ABC…', 'agentimus' )
			);
		}

		$verdict = self::send(
			$token,
			$chat,
			__( 'Agentimus connected. This chat now receives the events you picked — change them, or disconnect, any time on the Integrations screen.', 'agentimus' )
		);
		if ( is_wp_error( $verdict ) ) {
			return new \WP_Error(
				'agentimus_telegram_chat',
				__( 'The token works, but the bot couldn’t post to that chat. Check the chat id — and make sure the chat has let the bot in first (message the bot once, or add it to the group or channel).', 'agentimus' )
			);
		}
		return true;
	}

	/* -- Delivery ------------------------------------------------------------- */

	/**
	 * Deliver one envelope as one plain message. One send, one verdict —
	 * retry policy belongs to the queue, not here.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope to format.
	 * @return true|\WP_Error True on success; a WP_Error naming what went wrong.
	 */
	public static function deliver( $event, array $envelope ) {
		$config = self::config( new Settings() );
		$token  = self::token();
		if ( '' === $config['chat'] || '' === $token ) {
			return new \WP_Error( 'agentimus_telegram_unconfigured', __( 'Telegram is not configured.', 'agentimus' ) );
		}
		return self::send( $token, $config['chat'], self::format( (string) $event, $envelope ) );
	}

	/**
	 * The formatter: one envelope, one plain readable message. Pure — the
	 * tests hold each event's exact shape.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope ({ event, site, data, … }).
	 * @return string
	 */
	public static function format( $event, array $envelope ) {
		$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		$host = self::host( isset( $envelope['site'] ) ? (string) $envelope['site'] : '' );

		switch ( (string) $event ) {
			case Events::FINDING_OPENED:
				$lines = array(
					sprintf(
						/* translators: 1: site host, 2: the finding's tier in words (urgent / worth knowing / for later). */
						__( 'New finding on %1$s — %2$s', 'agentimus' ),
						$host,
						self::tier_words( isset( $data['tier'] ) ? (string) $data['tier'] : '' )
					),
					isset( $data['title'] ) ? (string) $data['title'] : '',
				);
				if ( ! empty( $data['url'] ) ) {
					$lines[] = (string) $data['url'];
				}
				return implode( "\n", array_filter( $lines, 'strlen' ) );

			case Events::DIGEST_SENT:
				$label   = isset( $data['period']['label'] ) ? (string) $data['period']['label'] : '';
				$agents  = isset( $data['agents'] ) ? (int) $data['agents'] : 0;
				$refs    = isset( $data['referrals'] ) ? (int) $data['referrals'] : 0;
				$imps    = isset( $data['impostors'] ) ? (int) $data['impostors'] : 0;
				$message = sprintf(
					/* translators: 1: site host, 2: period label (may be empty), 3: agent visits, 4: AI referrals, 5: impostors. */
					__( 'Weekly digest for %1$s%2$s: %3$d agent visits, %4$d AI referrals, %5$d impostors caught.', 'agentimus' ),
					$host,
					'' !== $label ? ' (' . $label . ')' : '',
					$agents,
					$refs,
					$imps
				);
				$now  = isset( $data['score']['now'] ) ? $data['score']['now'] : null;
				$prev = isset( $data['score']['prev'] ) ? $data['score']['prev'] : null;
				if ( null !== $now ) {
					$message .= "\n" . sprintf(
						/* translators: %d: the current readiness score. */
						__( 'Score %d', 'agentimus' ),
						(int) $now
					);
					if ( null !== $prev && (int) $prev !== (int) $now ) {
						$message .= sprintf(
							/* translators: %d: the previous score. */
							__( ' (was %d)', 'agentimus' ),
							(int) $prev
						);
					}
				}
				return $message;

			case Events::IMPOSTOR_FLAGGED:
				return sprintf(
					/* translators: 1: site host, 2: the client name that failed its check. */
					__( 'Impostor caught on %1$s — a client calling itself “%2$s” failed its operator’s check.', 'agentimus' ),
					$host,
					isset( $data['client'] ) ? (string) $data['client'] : ''
				);

			case Events::ROBOTS_CHANGED:
				$message = sprintf(
					/* translators: %s: site host. */
					__( 'robots.txt policy changed on %s', 'agentimus' ),
					$host
				);
				$added   = isset( $data['added'] ) ? array_filter( array_map( 'strval', (array) $data['added'] ), 'strlen' ) : array();
				$removed = isset( $data['removed'] ) ? array_filter( array_map( 'strval', (array) $data['removed'] ), 'strlen' ) : array();
				if ( array() !== $added ) {
					$message .= "\n" . __( 'Added:', 'agentimus' ) . "\n  " . implode( "\n  ", $added );
				}
				if ( array() !== $removed ) {
					$message .= "\n" . __( 'Removed:', 'agentimus' ) . "\n  " . implode( "\n  ", $removed );
				}
				return $message;

			case Events::CITATION_RUN_FINISHED:
				$checks  = isset( $data['checks'] ) ? (int) $data['checks'] : 0;
				$message = sprintf(
					/* translators: 1: site host, 2: how many checks the run made. */
					_n( 'Citation check finished on %1$s — %2$d check run.', 'Citation check finished on %1$s — %2$d checks run.', $checks, 'agentimus' ),
					$host,
					$checks
				);
				if ( ! empty( $data['capped'] ) ) {
					$message .= ' ' . __( 'The run stopped at its cap.', 'agentimus' );
				}
				return $message;

			case Events::AGENT_WROTE_CONTENT:
				$message = sprintf(
					/* translators: 1: created/updated, 2: the post title, 3: site host. */
					__( 'An AI assistant %1$s “%2$s” on %3$s.', 'agentimus' ),
					'update' === ( isset( $data['action'] ) ? (string) $data['action'] : '' ) ? __( 'updated', 'agentimus' ) : __( 'created', 'agentimus' ),
					isset( $data['title'] ) ? (string) $data['title'] : '',
					$host
				);
				if ( ! empty( $data['ability'] ) ) {
					$message .= "\n" . sprintf(
						/* translators: %s: the tool (ability) that made the write. */
						__( 'Via %s.', 'agentimus' ),
						(string) $data['ability']
					);
				}
				return $message;
		}

		// A filter-added event this formatter never learned: name it honestly
		// rather than dropping it — the checkbox existed, so the message must.
		return sprintf( '%1$s on %2$s.', (string) $event, $host );
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
	 * One sendMessage call. The token rides only in the URL Telegram's API
	 * demands; it never appears in any error this returns.
	 *
	 * @param string $token Bot token.
	 * @param string $chat  Chat id.
	 * @param string $text  The message.
	 * @return true|\WP_Error
	 */
	private static function send( $token, $chat, $text ) {
		$response = wp_remote_post(
			self::API . '/bot' . self::url_token( $token ) . '/sendMessage',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode(
					array(
						'chat_id'                  => (string) $chat,
						'text'                     => (string) $text,
						'disable_web_page_preview' => true,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		// Telegram's own words when it gave any ("Bad Request: chat not found",
		// "Unauthorized") — the sentence the owner can act on.
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
		$description = is_array( $body ) && isset( $body['description'] ) ? substr( trim( (string) $body['description'] ), 0, 140 ) : '';
		return new \WP_Error(
			'agentimus_telegram_status',
			'' !== $description
				? sprintf(
					/* translators: 1: the HTTP status Telegram answered with, 2: Telegram's own error description. */
					__( 'Telegram answered %1$d: %2$s', 'agentimus' ),
					$code,
					$description
				)
				: sprintf(
					/* translators: %d: the HTTP status Telegram answered with. */
					__( 'Telegram answered %d.', 'agentimus' ),
					$code
				)
		);
	}

	/**
	 * The finding tier in words a phone can read.
	 *
	 * @param string $tier Findings tier id.
	 * @return string
	 */
	private static function tier_words( $tier ) {
		if ( Findings::URGENT === $tier ) {
			return __( 'urgent', 'agentimus' );
		}
		if ( Findings::WORTH === $tier ) {
			return __( 'worth knowing', 'agentimus' );
		}
		if ( Findings::LATER === $tier ) {
			return __( 'for later', 'agentimus' );
		}
		return $tier;
	}

	/**
	 * The token as it may ride in the API path: Telegram's own token alphabet
	 * (digits, a colon, letters, underscore, minus) and nothing else — a
	 * stray slash or query character could never redirect the call.
	 *
	 * @param string $token Bot token.
	 * @return string
	 */
	private static function url_token( $token ) {
		return preg_replace( '/[^A-Za-z0-9:_\-]/', '', (string) $token );
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
