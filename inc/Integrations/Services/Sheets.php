<?php
/**
 * Google Sheets — events appended as rows to a spreadsheet the owner names:
 * a history that outlives the 30-day request log, in a file the owner can
 * sort, chart and keep forever.
 *
 * The shape of the trust: there is NO credential of its own. The service
 * reuses the Google service-account key the Search Console connection already
 * stores (Google\Settings, encrypted at rest), minting a token for the Sheets
 * scope on the same JWT flow Search Console uses — never a second key, never
 * a new OAuth dance. What the owner grants instead is ACCESS: the spreadsheet
 * must be shared with the service account's email, exactly like sharing with
 * a collaborator, and every error that means "you didn't" says that email out
 * loud so the fix is never a hunt.
 *
 * Connect proves the whole road before anything is stored: one append call
 * carrying the header row and a test row. Its two failure modes fail with two
 * different sentences — a spreadsheet ID Google can't find (404), and a sheet
 * that exists but was never shared (403) — because a vague "connection
 * failed" would leave the owner rechecking the wrong thing.
 *
 * Delivery is a formatter over the shared pipeline: one envelope, one row of
 * five flat columns in a stable order — When (site time) · Event · Level ·
 * What happened · Link — documented in the card's help. Values ride with
 * valueInputOption=RAW, so a title that begins with "=" lands as text, never
 * as a formula a spreadsheet would execute.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Services;

use Agentimus\Findings;
use Agentimus\Google\Auth;
use Agentimus\Google\Settings as GoogleSettings;
use Agentimus\Settings;
use Agentimus\Integrations\DeliveryState;
use Agentimus\Integrations\Events;

defined( 'ABSPATH' ) || exit;

final class Sheets {

	/** This connection's id in settings, state and queue rows. */
	const ID = 'sheets';

	/** The Sheets API root. */
	const API = 'https://sheets.googleapis.com/v4/spreadsheets/';

	/** Seconds one append may take — the queue's short leash. */
	const TIMEOUT = 5;

	/** The header row a connect writes, in the columns' one stable order. */
	const HEADER = array( 'When', 'Event', 'Level', 'What happened', 'Link' );

	/* -- Configuration ------------------------------------------------------ */

	/**
	 * The stored Sheets configuration, always in its full shape.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array{enabled:bool,spreadsheet:string,events:array<int,string>}
	 */
	public static function config( Settings $settings ) {
		$integrations = (array) $settings->get( 'integrations', array() );
		return array(
			'enabled'     => ! empty( $integrations['sheets_enabled'] ),
			'spreadsheet' => isset( $integrations['sheets_spreadsheet'] ) ? (string) $integrations['sheets_spreadsheet'] : '',
			'events'      => isset( $integrations['sheets_events'] ) ? array_values( array_map( 'strval', (array) $integrations['sheets_events'] ) ) : array(),
		);
	}

	/**
	 * Whether the connection could deliver at all: switched on, a spreadsheet
	 * named, and the Google connection's key still stored — all three, because
	 * the credential is borrowed and can leave on its own (a Google disconnect
	 * must stand this service down with it, not leave it erroring).
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function connected( Settings $settings ) {
		$config = self::config( $settings );
		return $config['enabled'] && '' !== $config['spreadsheet'] && self::has_key();
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
	 * No per-payload gate: a spreadsheet is a HISTORY, so everything the
	 * checkbox subscribed to belongs in it.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param string   $event    Event name.
	 * @param array    $data     Contract-shaped payload.
	 * @return bool
	 */
	public static function accepts( Settings $settings, $event, array $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the grammar's full signature.
		return true;
	}

	/* -- The borrowed key ----------------------------------------------------- */

	/**
	 * Whether the Google connection holds a service-account key. Presence of
	 * the ciphertext only — nothing is decrypted to answer a yes/no that runs
	 * on every boot.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== (string) ( new GoogleSettings() )->get( 'sa_json', '' );
	}

	/**
	 * The service account's EMAIL — what the owner shares the sheet with, so
	 * the connect help and the no-access error can say it out loud. Plaintext
	 * in the Google store by design (granting it access IS the setup step).
	 *
	 * @return string
	 */
	public static function sa_email() {
		return (string) ( new GoogleSettings() )->get( 'sa_email', '' );
	}

	/**
	 * A spreadsheet ID as Google will take it: the long /d/… code — pasted
	 * bare, or extracted from the sheet's whole URL (the kinder paste). Anything
	 * else normalizes to '' — stored garbage would fail on every delivery.
	 *
	 * @param string $raw Raw input: an ID, or the spreadsheet's URL.
	 * @return string The bare spreadsheet ID, or ''.
	 */
	public static function normalize_spreadsheet_id( $raw ) {
		$raw = trim( (string) $raw );
		if ( preg_match( '#/d/([A-Za-z0-9_-]{15,})#', $raw, $m ) ) {
			return $m[1];
		}
		return preg_match( '/^[A-Za-z0-9_-]{15,}$/', $raw ) ? $raw : '';
	}

	/* -- Connect-time proof --------------------------------------------------- */

	/**
	 * Prove the road end to end: one append carrying the header row and a test
	 * row. A success means the ID names a real spreadsheet, the sheet is shared
	 * with the service account, and rows actually land — the whole promise, in
	 * one call. Failures keep their two distinct sentences ({@see map_error}).
	 *
	 * @param string $spreadsheet The normalized spreadsheet ID.
	 * @return true|\WP_Error True, or an error whose code names the bad thing
	 *                        (agentimus_sheets_id / agentimus_sheets_access / …).
	 */
	public static function verify( $spreadsheet ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return self::append(
			$spreadsheet,
			$token,
			array(
				self::HEADER,
				array(
					self::when( time() ),
					'connected',
					'',
					__( 'Agentimus test row — your site can reach this sheet. The Integrations screen says whether the connection was saved. New rows append below as events happen.', 'agentimus' ),
					home_url( '/' ),
				),
			)
		);
	}

	/* -- Delivery ------------------------------------------------------------- */

	/**
	 * Deliver one envelope as one appended row. One append, one verdict —
	 * retry policy belongs to the queue, not here.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope to format.
	 * @return true|\WP_Error True on success; a WP_Error naming what went wrong.
	 */
	public static function deliver( $event, array $envelope ) {
		$config = self::config( new Settings() );
		if ( '' === $config['spreadsheet'] || ! self::has_key() ) {
			return new \WP_Error( 'agentimus_sheets_unconfigured', __( 'Google Sheets is not configured.', 'agentimus' ) );
		}
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return self::append( $config['spreadsheet'], $token, array( self::row( (string) $event, $envelope ) ) );
	}

	/**
	 * The formatter: one envelope, one row of five flat columns in the stable
	 * order the header names — When · Event · Level · What happened · Link.
	 * Pure — the tests hold each event's exact shape.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope ({ event, site, data, at, … }).
	 * @return array<int,string>
	 */
	public static function row( $event, array $envelope ) {
		$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();
		return array(
			self::when( isset( $envelope['at'] ) ? (int) $envelope['at'] : time() ),
			(string) $event,
			self::level( (string) $event, $data ),
			self::summary( (string) $event, $data ),
			self::link( (string) $event, $data, isset( $envelope['site'] ) ? (string) $envelope['site'] : '' ),
		);
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
	 * A Sheets-scope token from the borrowed key — the same JWT flow Search
	 * Console rides, a scope of its own (a Search Console token must never
	 * carry write rights to spreadsheets, and vice versa).
	 *
	 * @return string|\WP_Error The bearer token, or the failure in Google's words.
	 */
	private static function token() {
		$minted = Auth::token( ( new GoogleSettings() )->sa_json(), Auth::SCOPE_SHEETS );
		if ( isset( $minted['error'] ) || empty( $minted['token'] ) ) {
			return new \WP_Error(
				'agentimus_sheets_auth',
				sprintf(
					/* translators: %s: what went wrong minting the Google token. */
					__( 'Google didn’t accept the stored service-account key: %s', 'agentimus' ),
					isset( $minted['error'] ) ? (string) $minted['error'] : __( 'no token was issued.', 'agentimus' )
				)
			);
		}
		return (string) $minted['token'];
	}

	/**
	 * One append call: rows onto the end of the first sheet's table.
	 *
	 * valueInputOption=RAW on purpose — every value lands as the literal
	 * string, so site data beginning with "=" (or "+", "@") can never become a
	 * formula the spreadsheet executes.
	 *
	 * @param string $spreadsheet Spreadsheet ID.
	 * @param string $token       Bearer token.
	 * @param array  $rows        Rows of column strings.
	 * @return true|\WP_Error
	 */
	private static function append( $spreadsheet, $token, array $rows ) {
		$response = wp_remote_post(
			self::API . rawurlencode( (string) $spreadsheet ) . '/values/A1:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . (string) $token,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode( array( 'values' => $rows ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		return self::map_error( $code, (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Google's status codes, translated into the sentence the owner can act
	 * on. The two that matter get their own words: 404 is the ID (no such
	 * spreadsheet), 403 is the sharing (the sheet exists, the service account
	 * was never let in — and the email to share with is said OUT LOUD).
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Google's response body.
	 * @return \WP_Error
	 */
	private static function map_error( $code, $body ) {
		if ( 404 === $code ) {
			return new \WP_Error(
				'agentimus_sheets_id',
				__( 'Google can’t find a spreadsheet with that ID. Check the long code in the sheet’s URL — it sits between /d/ and /edit.', 'agentimus' )
			);
		}
		if ( 403 === $code ) {
			$email = self::sa_email();
			return new \WP_Error(
				'agentimus_sheets_access',
				'' !== $email
					? sprintf(
						/* translators: %s: the service account's email address. */
						__( 'The spreadsheet exists, but it isn’t shared with the service account. In Google Sheets, hit Share and add %s as an Editor — like any collaborator.', 'agentimus' ),
						$email
					)
					: __( 'The spreadsheet exists, but it isn’t shared with the service account. In Google Sheets, hit Share and add the service account’s email (shown on the Integrations card) as an Editor.', 'agentimus' )
			);
		}

		$json    = json_decode( $body, true );
		$message = is_array( $json ) && isset( $json['error']['message'] ) ? substr( trim( (string) $json['error']['message'] ), 0, 140 ) : '';
		return new \WP_Error(
			'agentimus_sheets_status',
			'' !== $message
				? sprintf(
					/* translators: 1: the HTTP status Google answered with, 2: Google's own error message. */
					__( 'Google Sheets answered %1$d: %2$s', 'agentimus' ),
					$code,
					$message
				)
				: sprintf(
					/* translators: %d: the HTTP status Google answered with. */
					__( 'Google Sheets answered %d.', 'agentimus' ),
					$code
				)
		);
	}

	/**
	 * The When column: site-local, sortable, one fixed shape — a history is
	 * for sorting and filtering, so the format never follows display settings.
	 *
	 * @param int $at Unix timestamp.
	 * @return string
	 */
	private static function when( $at ) {
		return wp_date( 'Y-m-d H:i:s', (int) $at );
	}

	/**
	 * The Level column: the finding's tier in words; every other event leaves
	 * it empty — the tier is a findings word, and inventing one would be a lie.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Contract-shaped payload.
	 * @return string
	 */
	private static function level( $event, array $data ) {
		if ( Events::FINDING_OPENED !== $event ) {
			return '';
		}
		return self::tier_words( isset( $data['tier'] ) ? (string) $data['tier'] : '' );
	}

	/**
	 * The What-happened column: one plain line per event. Each service holds
	 * its own copy of this grammar — services never import each other.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Contract-shaped payload.
	 * @return string
	 */
	private static function summary( $event, array $data ) {
		switch ( $event ) {
			case Events::FINDING_OPENED:
				return isset( $data['title'] ) ? (string) $data['title'] : '';

			case Events::DIGEST_SENT:
				$label   = isset( $data['period']['label'] ) ? (string) $data['period']['label'] : '';
				$message = sprintf(
					/* translators: 1: agent visits, 2: AI referrals, 3: impostors. */
					__( '%1$d agent visits, %2$d AI referrals, %3$d impostors caught.', 'agentimus' ),
					isset( $data['agents'] ) ? (int) $data['agents'] : 0,
					isset( $data['referrals'] ) ? (int) $data['referrals'] : 0,
					isset( $data['impostors'] ) ? (int) $data['impostors'] : 0
				);
				if ( '' !== $label ) {
					$message = $label . ' — ' . $message;
				}
				$now  = isset( $data['score']['now'] ) ? $data['score']['now'] : null;
				$prev = isset( $data['score']['prev'] ) ? $data['score']['prev'] : null;
				if ( null !== $now ) {
					$message .= ' ' . sprintf(
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
					$message .= '.';
				}
				return $message;

			case Events::IMPOSTOR_FLAGGED:
				return sprintf(
					/* translators: %s: the client name that failed its check. */
					__( 'A client calling itself “%s” failed its operator’s check.', 'agentimus' ),
					isset( $data['client'] ) ? (string) $data['client'] : ''
				);

			case Events::ROBOTS_CHANGED:
				$added   = isset( $data['added'] ) ? array_filter( array_map( 'strval', (array) $data['added'] ), 'strlen' ) : array();
				$removed = isset( $data['removed'] ) ? array_filter( array_map( 'strval', (array) $data['removed'] ), 'strlen' ) : array();
				$parts   = array();
				if ( array() !== $added ) {
					/* translators: %s: the added robots.txt lines, joined. */
					$parts[] = sprintf( __( 'Added: %s.', 'agentimus' ), implode( '; ', $added ) );
				}
				if ( array() !== $removed ) {
					/* translators: %s: the removed robots.txt lines, joined. */
					$parts[] = sprintf( __( 'Removed: %s.', 'agentimus' ), implode( '; ', $removed ) );
				}
				return array() !== $parts
					? implode( ' ', $parts )
					: __( 'robots.txt policy changed.', 'agentimus' );

			case Events::CITATION_RUN_FINISHED:
				$checks  = isset( $data['checks'] ) ? (int) $data['checks'] : 0;
				$message = sprintf(
					/* translators: %d: how many checks the run made. */
					_n( '%d check run.', '%d checks run.', $checks, 'agentimus' ),
					$checks
				);
				if ( ! empty( $data['capped'] ) ) {
					$message .= ' ' . __( 'The run stopped at its cap.', 'agentimus' );
				}
				return $message;

			case Events::AGENT_WROTE_CONTENT:
				$message = sprintf(
					/* translators: 1: the post title, 2: created/updated. */
					__( '“%1$s” %2$s by an AI assistant.', 'agentimus' ),
					isset( $data['title'] ) ? (string) $data['title'] : '',
					'update' === ( isset( $data['action'] ) ? (string) $data['action'] : '' ) ? __( 'updated', 'agentimus' ) : __( 'created', 'agentimus' )
				);
				if ( ! empty( $data['ability'] ) ) {
					$message .= ' ' . sprintf(
						/* translators: %s: the tool (ability) that made the write. */
						__( 'Via %s.', 'agentimus' ),
						(string) $data['ability']
					);
				}
				return $message;
		}

		// A filter-added event this formatter never learned: the Event column
		// already names it, so the summary stays honestly empty.
		return '';
	}

	/**
	 * The Link column: where acting on the row starts. Only the events with a
	 * real destination get one — an invented link would be decoration.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Contract-shaped payload.
	 * @param string $site  The envelope's site URL (ends in /).
	 * @return string
	 */
	private static function link( $event, array $data, $site ) {
		if ( Events::FINDING_OPENED === $event ) {
			return isset( $data['url'] ) ? (string) $data['url'] : '';
		}
		if ( Events::ROBOTS_CHANGED === $event && '' !== $site ) {
			return rtrim( $site, '/' ) . '/robots.txt';
		}
		return '';
	}

	/**
	 * The finding tier in words (each service holds its own copy — services
	 * never import each other).
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
}
