<?php
/**
 * The announcements queue — scheduled public speech, and the ledger of it.
 *
 * This is NOT the events queue, on purpose, three times over. An event row is
 * telemetry: it goes out as soon as a tick can carry it, retries itself on a
 * backoff, and is forgotten once delivered. An announcement is the owner's
 * voice in front of readers, so every law inverts:
 *
 *   AT ITS TIME, not ASAP — a row holds the moment the owner chose, and the
 *   drain sends only what is due. WP-cron fires an overdue event on the next
 *   visit, so a site asleep at the chosen minute posts when it next wakes —
 *   and the ledger's sent_at says when it really went, not when it was meant.
 *
 *   A LEDGER, not a conveyor — sent and failed rows stay readable (the
 *   Announcements screen is these rows), capped at HISTORY_MAX with the
 *   oldest finished rows leaving first. A QUEUED row is never dropped by the
 *   cap: a promise is not history.
 *
 *   NO RETRY WITHOUT A HAND — one scheduled send gets one attempt. A failure
 *   parks the row as `failed` with the network's own words and waits for the
 *   owner's Try again: telemetry arriving twice is noise, an announcement
 *   arriving twice is spam, and late-but-deliberate beats doubled.
 *
 * The body is posted VERBATIM as queued — the draft the owner edited and
 * approved is the contract; nothing re-renders at send time. Delivery
 * verdicts live in the row alone, never in the Services card's DeliveryState:
 * that line belongs to the events use, and the two rooms stay separate in
 * failure exactly as they do in destination.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations;

use Agentimus\Settings;
use Agentimus\Integrations\Services\Telegram;

defined( 'ABSPATH' ) || exit;

final class Announcements {

	/** The cron hook a due sweep runs under. */
	const CRON = 'agentimus_announce';

	/** Option: the rows ({ id → row }). Not autoloaded — read on queue, drain and screen only. */
	const OPTION = 'agentimus_announcements';

	/** Finished rows kept (sent + failed). Queued rows never count against it. */
	const HISTORY_MAX = 200;

	/** The networks announcing knows today. Each entry is a sharing use that can send. */
	const NETWORKS = array( 'telegram', 'x', 'linkedin' );

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Arm the drain — and self-heal a schedule WP-cron lost. Inert while no
	 * row is queued: not one hook, the standing law.
	 */
	public function register() {
		$rows = self::rows_raw();
		if ( array() === self::queued( $rows ) ) {
			return;
		}
		add_action( self::CRON, array( $this, 'dispatch' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( $this->earliest_due( $rows ), self::CRON );
		}
	}

	/* -- The owner's verbs ----------------------------------------------------- */

	/**
	 * Queue one announcement: one network, one body, one moment. Refused whole
	 * when the network's sharing use could not deliver — a row that can only
	 * fail is a promise the queue must not accept.
	 *
	 * @param array $args { network: string, body: string, post_id: int, at: int (unix, UTC) }.
	 * @return int|\WP_Error The new row's id, or the sentence naming the refusal.
	 */
	public function queue( array $args ) {
		$network = isset( $args['network'] ) ? (string) $args['network'] : '';
		if ( ! in_array( $network, self::NETWORKS, true ) ) {
			return new \WP_Error( 'agentimus_announce_network', __( 'That network can’t be announced to.', 'agentimus' ) );
		}
		if ( 'telegram' === $network && ! Telegram::sharing_active( $this->settings ) ) {
			return new \WP_Error( 'agentimus_announce_off', __( 'Announcing by Telegram is not set up — turn it on under Integrations → Sharing first.', 'agentimus' ) );
		}
		if ( 'x' === $network && ! Services\X::sharing_active( $this->settings ) ) {
			return new \WP_Error( 'agentimus_announce_off', __( 'Announcing on X is not set up — turn it on under Integrations → Sharing first.', 'agentimus' ) );
		}
		if ( 'linkedin' === $network && ! Services\LinkedIn::sharing_active( $this->settings ) ) {
			return new \WP_Error(
				'agentimus_announce_off',
				Services\LinkedIn::expired()
					? __( 'LinkedIn’s sixty days ran out — reconnect under Integrations → Services first.', 'agentimus' )
					: __( 'Announcing on LinkedIn is not set up — turn it on under Integrations → Sharing first.', 'agentimus' )
			);
		}

		$body = trim( (string) ( isset( $args['body'] ) ? $args['body'] : '' ) );
		if ( '' === $body ) {
			return new \WP_Error( 'agentimus_announce_empty', __( 'There is nothing to post — the draft is empty.', 'agentimus' ) );
		}
		if ( 'linkedin' === $network && mb_strlen( $body ) > Services\LinkedIn::LIMIT ) {
			return new \WP_Error(
				'agentimus_announce_long',
				sprintf(
					/* translators: 1: LinkedIn's limit, 2: the draft's length. */
					__( 'Longer than LinkedIn allows — the limit is %1$d and this draft is %2$d. Trim it before it’s promised.', 'agentimus' ),
					Services\LinkedIn::LIMIT,
					mb_strlen( $body )
				)
			);
		}
		if ( 'x' === $network && Services\X::tweet_length( $body ) > Services\X::LIMIT ) {
			return new \WP_Error(
				'agentimus_announce_long',
				sprintf(
					/* translators: 1: X's limit, 2: the draft's weighted length. */
					__( 'Longer than X allows — the limit is %1$d and this draft weighs %2$d. Trim it before it’s promised.', 'agentimus' ),
					Services\X::LIMIT,
					Services\X::tweet_length( $body )
				)
			);
		}

		// A moment already behind us means NOW — "send it" is a valid schedule,
		// and a clock skewed a minute back must not park a row forever.
		$at = max( (int) ( isset( $args['at'] ) ? $args['at'] : 0 ), time() );

		$rows = self::rows_raw();
		$id   = $rows ? max( array_keys( $rows ) ) + 1 : 1;

		$rows[ $id ] = array(
			'id'           => $id,
			'post_id'      => (int) ( isset( $args['post_id'] ) ? $args['post_id'] : 0 ),
			'network'      => $network,
			'body'         => $body,
			'scheduled_at' => $at,
			'created_at'   => time(),
			'status'       => 'queued',
			'sent_at'      => 0,
			'error'        => '',
		);
		self::store_rows( $rows );

		add_action( self::CRON, array( $this, 'dispatch' ) );
		wp_schedule_single_event( $at, self::CRON );

		return $id;
	}

	/**
	 * Cancel a QUEUED row — it never happened, so nothing is kept. Any cron
	 * event already booked fires into a sweep that finds nothing due, which
	 * is a no-op, not a bug.
	 *
	 * @param int $id Row id.
	 * @return true|\WP_Error
	 */
	public function cancel( $id ) {
		return $this->drop( $id, 'queued', __( 'Only a queued announcement can be cancelled.', 'agentimus' ) );
	}

	/**
	 * Try a FAILED row again — the owner's hand, the only retry there is.
	 * The row goes back to queued, aimed at now; its old error leaves with
	 * the new promise.
	 *
	 * @param int $id Row id.
	 * @return true|\WP_Error
	 */
	public function retry( $id ) {
		$rows = self::rows_raw();
		if ( ! isset( $rows[ $id ] ) || 'failed' !== $rows[ $id ]['status'] ) {
			return new \WP_Error( 'agentimus_announce_row', __( 'Only a failed announcement can be tried again.', 'agentimus' ) );
		}

		$rows[ $id ]['status']       = 'queued';
		$rows[ $id ]['scheduled_at'] = time();
		$rows[ $id ]['error']        = '';
		self::store_rows( $rows );

		add_action( self::CRON, array( $this, 'dispatch' ) );
		wp_schedule_single_event( time(), self::CRON );

		return true;
	}

	/**
	 * Remove a FINISHED row from the ledger. A queued row is not removable —
	 * that is cancel's word, and the two must not blur.
	 *
	 * @param int $id Row id.
	 * @return true|\WP_Error
	 */
	public function remove( $id ) {
		$rows = self::rows_raw();
		if ( ! isset( $rows[ $id ] ) || 'queued' === $rows[ $id ]['status'] ) {
			return new \WP_Error( 'agentimus_announce_row', __( 'That row isn’t one that can be removed.', 'agentimus' ) );
		}
		unset( $rows[ $id ] );
		self::store_rows( $rows );
		return true;
	}

	/* -- The drain --------------------------------------------------------------- */

	/**
	 * The cron sweep: send EVERY due row, one attempt each. Sweeping (rather
	 * than carrying a row id in the event) is the self-heal — whichever tick
	 * fires next delivers everything the lost ones owed.
	 */
	public function dispatch() {
		$rows = self::rows_raw();
		$now  = time();

		foreach ( $rows as $id => $row ) {
			if ( 'queued' !== $row['status'] || $row['scheduled_at'] > $now ) {
				continue;
			}

			$verdict = $this->send( $row );
			if ( is_wp_error( $verdict ) ) {
				$rows[ $id ]['status'] = 'failed';
				$rows[ $id ]['error']  = substr( trim( $verdict->get_error_message() ), 0, 300 );
			} else {
				$rows[ $id ]['status']  = 'sent';
				$rows[ $id ]['sent_at'] = time();
			}
		}

		self::store_rows( self::capped( $rows ) );

		// Rows still waiting on a future moment: make sure a tick is coming.
		$queued = self::queued( self::rows_raw() );
		if ( array() !== $queued && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( $this->earliest_due( self::rows_raw() ), self::CRON );
		}
	}

	/* -- The screen's read --------------------------------------------------------- */

	/**
	 * One page of the ledger, newest promise first (queued by soonest, then
	 * finished by most recent), plus the total the pagination states.
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Rows per page.
	 * @return array{rows:array<int,array>,total:int}
	 */
	public function rows( $page = 1, $per_page = 20 ) {
		$rows = array_values( self::rows_raw() );

		// Queued rows first, soonest promise on top; then the finished, the
		// freshest outcome first — the order the owner can still act in.
		usort(
			$rows,
			static function ( $a, $b ) {
				$a_queued = 'queued' === $a['status'];
				$b_queued = 'queued' === $b['status'];
				if ( $a_queued !== $b_queued ) {
					return $a_queued ? -1 : 1;
				}
				if ( $a_queued ) {
					return $a['scheduled_at'] <=> $b['scheduled_at'];
				}
				$a_done = $a['sent_at'] > 0 ? $a['sent_at'] : $a['scheduled_at'];
				$b_done = $b['sent_at'] > 0 ? $b['sent_at'] : $b['scheduled_at'];
				return $b_done <=> $a_done;
			}
		);

		$page     = max( 1, (int) $page );
		$per_page = max( 1, (int) $per_page );

		return array(
			'rows'  => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'total' => count( $rows ),
		);
	}

	/**
	 * The ledger at a glance — what the Sharing tab's roll-up line and each
	 * network card's state line speak from. One walk, both answers.
	 *
	 * @return array{total:int,queued:int,sentWeek:int,failed:int,networks:array<string,array{queued:int,lastSentAt:int}>}
	 */
	public function summary() {
		$rows  = self::rows_raw();
		$week  = time() - 604800;
		$out   = array(
			'total'    => count( $rows ),
			'queued'   => 0,
			'sentWeek' => 0,
			'failed'   => 0,
			'networks' => array(),
		);

		foreach ( $rows as $row ) {
			$network = (string) $row['network'];
			if ( ! isset( $out['networks'][ $network ] ) ) {
				$out['networks'][ $network ] = array(
					'queued'     => 0,
					'lastSentAt' => 0,
				);
			}
			if ( 'queued' === $row['status'] ) {
				$out['queued']++;
				$out['networks'][ $network ]['queued']++;
			} elseif ( 'failed' === $row['status'] ) {
				$out['failed']++;
			} elseif ( 'sent' === $row['status'] ) {
				if ( $row['sent_at'] >= $week ) {
					$out['sentWeek']++;
				}
				$out['networks'][ $network ]['lastSentAt'] = max( $out['networks'][ $network ]['lastSentAt'], (int) $row['sent_at'] );
			}
		}
		return $out;
	}

	/**
	 * One post's standing with one network — the Share card's memory. The
	 * card tells the post's future (the schedule row); this is how it also
	 * tells the past: a queued promise, the freshest send, a parked failure.
	 * A cancelled row is absent by law — it never happened.
	 *
	 * @param int    $post_id Post id.
	 * @param string $network Network id.
	 * @return array{queuedAt:int,lastSentAt:int,failed:bool}
	 */
	public function post_network_state( $post_id, $network ) {
		$out = array(
			'queuedAt'   => 0,
			'lastSentAt' => 0,
			'failed'     => false,
		);
		foreach ( self::rows_raw() as $row ) {
			if ( (int) $row['post_id'] !== (int) $post_id || $row['network'] !== $network ) {
				continue;
			}
			if ( 'queued' === $row['status'] ) {
				$out['queuedAt'] = 0 === $out['queuedAt'] ? (int) $row['scheduled_at'] : min( $out['queuedAt'], (int) $row['scheduled_at'] );
			} elseif ( 'sent' === $row['status'] ) {
				$out['lastSentAt'] = max( $out['lastSentAt'], (int) $row['sent_at'] );
			} elseif ( 'failed' === $row['status'] ) {
				$out['failed'] = true;
			}
		}
		return $out;
	}

	/* -- Internals -------------------------------------------------------------------- */

	/**
	 * One row, one send, one verdict — routed to its network's sharing use.
	 *
	 * @param array $row The queued row.
	 * @return true|\WP_Error
	 */
	private function send( array $row ) {
		if ( 'telegram' === $row['network'] ) {
			return Telegram::announce( $row['body'] );
		}
		if ( 'x' === $row['network'] ) {
			return Services\X::announce( $row['body'] );
		}
		if ( 'linkedin' === $row['network'] ) {
			return Services\LinkedIn::announce( $row['body'] );
		}
		// A row from a build that knew more networks than this one: parked as
		// failed with an honest sentence, never guessed at.
		return new \WP_Error( 'agentimus_announce_network', __( 'This build doesn’t know that network.', 'agentimus' ) );
	}

	/**
	 * Drop one row after checking it holds the required status.
	 *
	 * @param int    $id       Row id.
	 * @param string $status   The status the verb applies to.
	 * @param string $refusal  The sentence when it doesn't.
	 * @return true|\WP_Error
	 */
	private function drop( $id, $status, $refusal ) {
		$rows = self::rows_raw();
		if ( ! isset( $rows[ $id ] ) || $status !== $rows[ $id ]['status'] ) {
			return new \WP_Error( 'agentimus_announce_row', $refusal );
		}
		unset( $rows[ $id ] );
		self::store_rows( $rows );
		return true;
	}

	/**
	 * The finished-rows cap: oldest finished out first, queued rows untouchable.
	 *
	 * @param array $rows All rows.
	 * @return array
	 */
	private static function capped( array $rows ) {
		$finished = array();
		foreach ( $rows as $id => $row ) {
			if ( 'queued' !== $row['status'] ) {
				$finished[ $id ] = $row['sent_at'] > 0 ? $row['sent_at'] : $row['scheduled_at'];
			}
		}
		if ( count( $finished ) <= self::HISTORY_MAX ) {
			return $rows;
		}
		asort( $finished );
		$excess = array_slice( array_keys( $finished ), 0, count( $finished ) - self::HISTORY_MAX );
		foreach ( $excess as $id ) {
			unset( $rows[ $id ] );
		}
		return $rows;
	}

	/**
	 * The queued subset.
	 *
	 * @param array $rows All rows.
	 * @return array
	 */
	private static function queued( array $rows ) {
		return array_filter(
			$rows,
			static function ( $row ) {
				return 'queued' === $row['status'];
			}
		);
	}

	/**
	 * The soonest moment a queued row is owed — never in the past for the
	 * scheduler's sake (an overdue row is due NOW).
	 *
	 * @param array $rows All rows.
	 * @return int
	 */
	private function earliest_due( array $rows ) {
		$soonest = PHP_INT_MAX;
		foreach ( self::queued( $rows ) as $row ) {
			$soonest = min( $soonest, (int) $row['scheduled_at'] );
		}
		return max( time(), PHP_INT_MAX === $soonest ? time() : $soonest );
	}

	/**
	 * The stored rows, always an array.
	 *
	 * @return array<int,array>
	 */
	private static function rows_raw() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Store the rows; the option leaves with its last row.
	 *
	 * @param array $rows All rows.
	 */
	private static function store_rows( array $rows ) {
		if ( array() === $rows ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $rows, false );
	}
}
