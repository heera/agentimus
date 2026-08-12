<?php
/**
 * The private findings feed — the one service that never calls anybody.
 *
 * Every other service PUSHES: the pipeline hands an envelope to a URL, a bot,
 * a spreadsheet. The feed inverts the arrow. It keeps a small ring of the same
 * envelopes the Dispatcher carries, and serves them — plus every finding still
 * open right now — as RSS 2.0 and JSON Feed 1.1 at a tokened URL. Readers come
 * to it: a feed reader on a phone, an automation polling on a schedule,
 * anything that speaks the web's oldest subscription format. Zero outbound
 * requests, ever — there is no receiver to be slow, no credential to any other
 * service, nothing to time out.
 *
 * The trust model is the URL itself. The token is minted, stored and shown
 * exactly like the MCP connection token: the plaintext appears ONCE in the
 * response that made it, only a SHA-256 fingerprint is stored, and a lost URL
 * is rotated, never recovered. No token or a wrong one gets a bare 401 —
 * never a hint of whether a feed exists behind the door. Anyone holding the
 * URL can read the feed, which is why the connect copy says to treat it like
 * a key.
 *
 * Content is the standing law's: own-site report data only, never visitor
 * PII. The formatters here are explicit per event — a payload key this class
 * never learned can never ride into an item.
 *
 * Delivery is the shared pipeline's: the Dispatcher drains a feed row by
 * calling deliver(), which appends the envelope to the ring — an option
 * write, not a network call. Inert until connected, like every sibling.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations\Services;

use Agentimus\Findings;
use Agentimus\Settings;
use Agentimus\Integrations\DeliveryState;
use Agentimus\Integrations\Events;

defined( 'ABSPATH' ) || exit;

final class Feed {

	/** This connection's id in settings, state and queue rows. */
	const ID = 'feed';

	/** Option: the token's fingerprint + fetch history, never the plaintext. */
	const TOKEN_OPTION = 'agentimus_integrations_feed_token';

	/** Option: the bounded event ring the feed serves. Not autoloaded. */
	const RING_OPTION = 'agentimus_integrations_feed_ring';

	/** How many events the ring holds — the feed's whole memory. */
	const RING_MAX = 50;

	/** Token shape: agfeed_<40 hex>. The prefix makes leaked-secret scanning possible. */
	const PREFIX = 'agfeed_';

	/* -- Configuration ------------------------------------------------------ */

	/**
	 * The stored feed configuration, always in its full shape.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return array{enabled:bool,events:array<int,string>}
	 */
	public static function config( Settings $settings ) {
		$integrations = (array) $settings->get( 'integrations', array() );
		return array(
			'enabled' => ! empty( $integrations['feed_enabled'] ),
			'events'  => isset( $integrations['feed_events'] ) ? array_values( array_map( 'strval', (array) $integrations['feed_events'] ) ) : array(),
		);
	}

	/**
	 * Whether the feed could answer at all: switched on AND holding a token.
	 * Both, because the token lives outside the settings array (a settings
	 * import can carry the flag without the option) and an "on" feed nobody
	 * could ever read is not a connection.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function connected( Settings $settings ) {
		return self::config( $settings )['enabled'] && self::has_token();
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
		return in_array( (string) $event, self::config( $settings )['events'], true );
	}

	/**
	 * No per-payload gate: a feed is a HISTORY, so everything the checkbox
	 * subscribed to belongs in it (the reader filters, like Sheets' owner sorts).
	 *
	 * @param Settings $settings Plugin settings.
	 * @param string   $event    Event name.
	 * @param array    $data     Contract-shaped payload.
	 * @return bool
	 */
	public static function accepts( Settings $settings, $event, array $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the grammar's full signature.
		return true;
	}

	/* -- The token ----------------------------------------------------------- */

	/**
	 * Mint (and store) a fresh feed token. The RETURN VALUE is the only place
	 * the plaintext ever appears — the caller shows the URL once and lets go.
	 * Only the SHA-256 fingerprint is stored (the McpToken doctrine); rotating
	 * replaces the old token, so every reader holding the old URL is out the
	 * moment this saves.
	 *
	 * @return string The new token.
	 */
	public static function mint_token() {
		$token = self::PREFIX . bin2hex( random_bytes( 20 ) );
		$state = get_option( self::TOKEN_OPTION, array() );

		update_option(
			self::TOKEN_OPTION,
			array(
				'hash'       => hash( 'sha256', $token ),
				'created_at' => time(),
				// Rotation changes the KEY, not the feed's history: "last
				// fetched" stays true of the feed, and a reader still polling
				// the dead URL is exactly what its growing staleness shows.
				'fetched_at' => is_array( $state ) && isset( $state['fetched_at'] ) ? (int) $state['fetched_at'] : 0,
			),
			false
		);

		return $token;
	}

	/**
	 * Whether a token exists — all any status surface may admit.
	 *
	 * @return bool
	 */
	public static function has_token() {
		$state = get_option( self::TOKEN_OPTION, null );
		return is_array( $state ) && ! empty( $state['hash'] );
	}

	/**
	 * Whether a presented token is THE token. Constant-time over the hashes,
	 * like every credential check in this plugin.
	 *
	 * @param string $token The presented plaintext.
	 * @return bool
	 */
	public static function verify_token( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return false;
		}
		$state = get_option( self::TOKEN_OPTION, null );
		if ( ! is_array( $state ) || empty( $state['hash'] ) ) {
			return false;
		}
		return hash_equals( (string) $state['hash'], hash( 'sha256', $token ) );
	}

	/** Forget the token — the disconnect path. Every reader is out at once. */
	public static function forget_token() {
		delete_option( self::TOKEN_OPTION );
	}

	/**
	 * An authorized fetch happened — the card's honesty line. Recorded per
	 * fetch: the option is not autoloaded, and a reader's poll is the one
	 * moment this connection actually does anything.
	 */
	public static function record_fetch() {
		$state = get_option( self::TOKEN_OPTION, null );
		if ( ! is_array( $state ) || empty( $state['hash'] ) ) {
			return;
		}
		$state['fetched_at'] = time();
		update_option( self::TOKEN_OPTION, $state, false );
	}

	/**
	 * When a reader last fetched the feed, 0 for never.
	 *
	 * @return int
	 */
	public static function last_fetched_at() {
		$state = get_option( self::TOKEN_OPTION, null );
		return is_array( $state ) && isset( $state['fetched_at'] ) ? (int) $state['fetched_at'] : 0;
	}

	/**
	 * The feed's URL for a just-minted token — what the owner pastes into a
	 * reader. Built here so the one place the plaintext travels is one line.
	 *
	 * @param string $token The plaintext token (its single appearance).
	 * @return string
	 */
	public static function url( $token ) {
		return add_query_arg( array( 'token' => rawurlencode( (string) $token ) ), rest_url( 'agentimus/v1/integrations/feed' ) );
	}

	/* -- Delivery (the pipeline's arrow, ending at an option) ----------------- */

	/**
	 * Deliver one envelope: append it to the ring. An option write — the whole
	 * point of this service is that this line never talks to the network.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope to remember.
	 * @return true|\WP_Error True when remembered; a WP_Error when disconnected.
	 */
	public static function deliver( $event, array $envelope ) {
		if ( ! self::connected( new Settings() ) ) {
			return new \WP_Error( 'agentimus_feed_unconfigured', __( 'The feed is not connected.', 'agentimus' ) );
		}

		$ring   = self::ring();
		$ring[] = array(
			'id'       => uniqid( 'fev_', true ),
			'event'    => (string) $event,
			'envelope' => $envelope,
		);

		// The bound: over the ceiling, the oldest rows yield to the newest —
		// a feed's front page is its present, same law as the queue's cap.
		if ( count( $ring ) > self::RING_MAX ) {
			$ring = array_slice( $ring, count( $ring ) - self::RING_MAX );
		}

		update_option( self::RING_OPTION, $ring, false );
		return true;
	}

	/**
	 * The stored ring, always as a list (oldest first, as delivered).
	 *
	 * @return array<int,array>
	 */
	public static function ring() {
		$ring = get_option( self::RING_OPTION, array() );
		return is_array( $ring ) ? array_values( $ring ) : array();
	}

	/** Drop the ring — the disconnect path. */
	public static function forget_ring() {
		delete_option( self::RING_OPTION );
	}

	/* -- Items (the neutral rows both formats render) ------------------------- */

	/**
	 * The feed's items: ring events newest-first, then every finding still
	 * open. Pure over its inputs, so the tests hold the exact shapes.
	 *
	 * Each item: { id, title, body, at, link, categories }. Events carry their
	 * envelope's moment; open findings carry NO date (a finding is a state,
	 * not a moment — an invented timestamp would re-mark it new on every
	 * fetch) and a STABLE id, so a reader that has seen one keeps it read.
	 * Waiting rows are excluded, the same law the findings diff follows: that
	 * tier exists to say the owner's work is done.
	 *
	 * @param array $ring     Ring rows ({ id, event, envelope }).
	 * @param array $findings Findings::all()['findings'] rows.
	 * @return array<int,array{id:string,title:string,body:string,at:int,link:string,categories:array<int,string>}>
	 */
	public static function items( array $ring, array $findings ) {
		$items = array();

		foreach ( array_reverse( $ring ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['envelope'] ) || ! is_array( $row['envelope'] ) ) {
				continue;
			}
			$event    = isset( $row['event'] ) ? (string) $row['event'] : '';
			$envelope = $row['envelope'];
			$data     = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();

			$title   = self::summary( $event, $data );
			$items[] = array(
				'id'         => isset( $row['id'] ) ? (string) $row['id'] : '',
				'title'      => $title,
				'body'       => self::detail( $event, $data, $title ),
				'at'         => isset( $envelope['at'] ) ? (int) $envelope['at'] : 0,
				'link'       => self::link( $event, $data, isset( $envelope['site'] ) ? (string) $envelope['site'] : '' ),
				'categories' => self::categories( $event, $data ),
			);
		}

		foreach ( $findings as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$tier = isset( $row['tier'] ) ? (string) $row['tier'] : '';
			if ( Findings::WAITING === $tier ) {
				continue;
			}
			$title   = isset( $row['title'] ) ? (string) $row['title'] : '';
			$why     = isset( $row['why'] ) ? (string) $row['why'] : '';
			$items[] = array(
				'id'         => 'finding:' . Events::finding_identity( $row ),
				'title'      => $title,
				'body'       => '' !== $why ? $why : $title,
				'at'         => 0,
				'link'       => admin_url( 'admin.php?page=agentimus#findings' ),
				'categories' => '' !== $tier ? array( 'finding', $tier ) : array( 'finding' ),
			);
		}

		return $items;
	}

	/* -- The two documents ----------------------------------------------------- */

	/**
	 * The RSS 2.0 document. Every interpolated value rides through XML
	 * escaping — a title holding an angle bracket can never become markup.
	 *
	 * @param array $items Items ({@see items()}).
	 * @return string The XML, ready to serve.
	 */
	public static function rss_document( array $items ) {
		$site = home_url( '/' );

		$out   = array();
		$out[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$out[] = '<rss version="2.0">';
		$out[] = '<channel>';
		$out[] = '<title>' . self::xml( self::channel_title() ) . '</title>';
		$out[] = '<link>' . self::xml( $site ) . '</link>';
		$out[] = '<description>' . self::xml( self::channel_description() ) . '</description>';

		foreach ( $items as $item ) {
			$out[] = '<item>';
			$out[] = '<guid isPermaLink="false">' . self::xml( $item['id'] ) . '</guid>';
			$out[] = '<title>' . self::xml( $item['title'] ) . '</title>';
			$out[] = '<description>' . self::xml( $item['body'] ) . '</description>';
			if ( $item['at'] > 0 ) {
				$out[] = '<pubDate>' . self::xml( gmdate( 'D, d M Y H:i:s +0000', $item['at'] ) ) . '</pubDate>';
			}
			if ( '' !== $item['link'] ) {
				$out[] = '<link>' . self::xml( $item['link'] ) . '</link>';
			}
			foreach ( $item['categories'] as $category ) {
				$out[] = '<category>' . self::xml( $category ) . '</category>';
			}
			$out[] = '</item>';
		}

		$out[] = '</channel>';
		$out[] = '</rss>';
		return implode( "\n", $out );
	}

	/**
	 * The JSON Feed 1.1 document, as the array the REST server encodes.
	 * content_text is required per item by the spec — it carries the body.
	 *
	 * @param array $items Items ({@see items()}).
	 * @return array
	 */
	public static function json_document( array $items ) {
		$rows = array();
		foreach ( $items as $item ) {
			$row = array(
				'id'           => $item['id'],
				'title'        => $item['title'],
				'content_text' => $item['body'],
			);
			if ( '' !== $item['link'] ) {
				$row['url'] = $item['link'];
			}
			if ( $item['at'] > 0 ) {
				$row['date_published'] = gmdate( 'Y-m-d\TH:i:s\Z', $item['at'] );
			}
			if ( array() !== $item['categories'] ) {
				$row['tags'] = $item['categories'];
			}
			$rows[] = $row;
		}

		return array(
			'version'       => 'https://jsonfeed.org/version/1.1',
			'title'         => self::channel_title(),
			'home_page_url' => home_url( '/' ),
			'description'   => self::channel_description(),
			'items'         => $rows,
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
	 * The channel's name: the site, then us — the same order the Slack context
	 * line speaks.
	 *
	 * @return string
	 */
	private static function channel_title() {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return $host . ' — Agentimus';
	}

	/**
	 * The channel's one-line what-this-is.
	 *
	 * @return string
	 */
	private static function channel_description() {
		return __( 'What Agentimus finds on this site — events as they happen, and every finding still open.', 'agentimus' );
	}

	/**
	 * The item title: one plain line per event — the same "What happened"
	 * grammar the Sheets column speaks. Each service holds its own copy of
	 * this grammar; services never import each other. The one departure: an
	 * event this formatter never learned keeps its NAME as the title, because
	 * a feed item, unlike a spreadsheet row, has no Event column beside it.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Contract-shaped payload.
	 * @return string
	 */
	private static function summary( $event, array $data ) {
		switch ( $event ) {
			case Events::FINDING_OPENED:
				$title = isset( $data['title'] ) ? (string) $data['title'] : '';
				$tier  = self::tier_words( isset( $data['tier'] ) ? (string) $data['tier'] : '' );
				return '' !== $tier
					? sprintf(
						/* translators: 1: the finding tier in words, 2: the finding's title. */
						__( 'New finding (%1$s): %2$s', 'agentimus' ),
						$tier,
						$title
					)
					: sprintf(
						/* translators: %s: the finding's title. */
						__( 'New finding: %s', 'agentimus' ),
						$title
					);

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
					? __( 'robots.txt policy changed.', 'agentimus' ) . ' ' . implode( ' ', $parts )
					: __( 'robots.txt policy changed.', 'agentimus' );

			case Events::CITATION_RUN_FINISHED:
				$checks  = isset( $data['checks'] ) ? (int) $data['checks'] : 0;
				$message = sprintf(
					/* translators: %d: how many checks the run made. */
					_n( 'Citation check finished: %d check run.', 'Citation check finished: %d checks run.', $checks, 'agentimus' ),
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

		// A filter-added event this formatter never learned: the name is the
		// only truth on hand, and an empty title would render as a blank row.
		return (string) $event;
	}

	/**
	 * The item body. Most events say everything in one line, so the body
	 * repeats the title (JSON Feed requires content_text; RSS wants a
	 * description). A finding is the exception: its WHY is the line under the
	 * headline, and the feed carries it the way the Findings screen does.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Contract-shaped payload.
	 * @param string $title The already-built summary line.
	 * @return string
	 */
	private static function detail( $event, array $data, $title ) {
		if ( Events::FINDING_OPENED === $event && isset( $data['why'] ) && '' !== (string) $data['why'] ) {
			return (string) $data['why'];
		}
		return $title;
	}

	/**
	 * The item link: where acting starts. Only the events with a real
	 * destination get one — an invented link would be decoration. (The Sheets
	 * Link column's exact law.)
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
	 * The item's categories: the event's wire name (stable, so an automation
	 * filters on it), plus the tier for a finding event.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Contract-shaped payload.
	 * @return array<int,string>
	 */
	private static function categories( $event, array $data ) {
		$categories = array( (string) $event );
		if ( Events::FINDING_OPENED === $event && isset( $data['tier'] ) && '' !== (string) $data['tier'] ) {
			$categories[] = (string) $data['tier'];
		}
		return $categories;
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

	/**
	 * XML escaping for every interpolated value — PHP's own, in XML1 mode, so
	 * site data can never become markup.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private static function xml( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
