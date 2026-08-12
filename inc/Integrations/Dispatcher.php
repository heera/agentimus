<?php
/**
 * The delivery queue — how an event leaves the site without ever making a
 * visitor (or an admin click) wait for someone else's server.
 *
 * The one law here: NOTHING IS EVER DELIVERED INLINE. An event's emitter runs
 * on whatever request happened to witness the moment — a front-end hit for an
 * impostor, a cron tick for the digest, an authenticated MCP call for a
 * content write — and every one of those has its own caller to answer to. So
 * an emit only appends a row to a bounded queue option and makes sure a cron
 * tick is coming; the tick does the talking to the outside world, where a
 * slow receiver costs nobody but the queue.
 *
 * The queue is FIFO and capped at MAX_QUEUE rows. When it is full, the OLDEST
 * row is dropped to admit the newest: a webhook that has been down for a week
 * should come back to the latest news, not to day-one's — stale events age
 * into noise, and the freshest are the ones the owner can still act on.
 *
 * Retries: each row gets MAX_ATTEMPTS tries, spaced by a growing backoff, and
 * is then dropped with its failure written to the connection's state — where
 * the card shows it. A drain processes at most BATCH deliveries per tick
 * (each can hold a 5s timeout), so one tick's worst case stays bounded; the
 * remainder reschedules itself. The schedule self-heals in register(), both
 * directions, the same way Digest\Module's does.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations;

use Agentimus\Settings;
use Agentimus\Integrations\Services\Webhook;

defined( 'ABSPATH' ) || exit;

final class Dispatcher {

	/** The single cron hook every delivery happens under. */
	const CRON = 'agentimus_integrations_deliver';

	/** Option: the queued envelopes. Not autoloaded — read on enqueue and drain only. */
	const QUEUE_OPTION = 'agentimus_integrations_queue';

	/** Hard ceiling on queued rows. Full = drop the oldest, keep the newest. */
	const MAX_QUEUE = 200;

	/** Tries per row: the first delivery plus three retries. */
	const MAX_ATTEMPTS = 4;

	/** Seconds until retry N (1-indexed): one minute, five, fifteen. */
	const BACKOFF = array( 60, 300, 900 );

	/** Deliveries attempted per tick. Each may hold a full timeout, so this
	 *  bounds one tick's worst case to ~2 minutes rather than the whole queue. */
	const BATCH = 25;

	/** Seconds between an enqueue and the tick that delivers it. */
	const DELAY = 30;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Arm the drain — or stand down and take any stale schedule with you.
	 *
	 * Disconnected means no hook is registered at all (the WebMCP doctrine);
	 * the queue itself is cleared by the explicit disconnect action, not here,
	 * so a transient misconfiguration never silently discards pending events.
	 *
	 * @return bool True when the drain was armed.
	 */
	public function register() {
		if ( ! Webhook::connected( $this->settings ) ) {
			if ( wp_next_scheduled( self::CRON ) ) {
				wp_clear_scheduled_hook( self::CRON );
			}
			return false;
		}

		add_action( self::CRON, array( $this, 'drain' ) );
		// Self-heal: rows that survived a deactivation (or a tick WP-cron lost)
		// must not wait for the next enqueue to find a schedule.
		if ( array() !== $this->queue() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::CRON );
		}
		return true;
	}

	/**
	 * Append one envelope to the queue and make sure a tick is coming. This is
	 * the WHOLE of what an emitter pays — two option writes, no network.
	 *
	 * @param string $event    Event name.
	 * @param array  $envelope The versioned envelope (Events::envelope()).
	 * @return bool Whether the event was queued.
	 */
	public function enqueue( $event, array $envelope ) {
		if ( ! Webhook::connected( $this->settings ) ) {
			return false;
		}

		$queue   = $this->queue();
		$queue[] = array(
			'id'       => uniqid( 'ev_', true ),
			'event'    => (string) $event,
			'envelope' => $envelope,
			'attempts' => 0,
			'next_at'  => 0,
		);

		// FIFO cap: over the ceiling, the oldest rows yield to the newest.
		if ( count( $queue ) > self::MAX_QUEUE ) {
			$queue = array_slice( $queue, count( $queue ) - self::MAX_QUEUE );
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::CRON );
		}
		return true;
	}

	/**
	 * The cron tick: deliver every due row (bounded by BATCH), keep the rest.
	 *
	 * A row that fails keeps its place in line but gains a next_at in the
	 * future, so one dead receiver never blocks the drain from ending — the
	 * tick walks past it and the backoff decides when it is asked again.
	 */
	public function drain() {
		if ( ! Webhook::connected( $this->settings ) ) {
			return; // Disconnected between the schedule and the tick.
		}

		$queue = $this->queue();
		if ( array() === $queue ) {
			return;
		}

		$now       = time();
		$kept      = array();
		$attempted = 0;

		foreach ( $queue as $i => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['envelope'] ) || ! is_array( $row['envelope'] ) ) {
				continue; // A corrupt row is dropped, never retried forever.
			}

			$due = ( ! isset( $row['next_at'] ) || (int) $row['next_at'] <= $now );
			if ( ! $due || $attempted >= self::BATCH ) {
				$kept[] = $row;
				continue;
			}

			++$attempted;
			$verdict = Webhook::deliver( isset( $row['event'] ) ? (string) $row['event'] : '', $row['envelope'] );

			if ( true === $verdict ) {
				Webhook::record_success();
				continue; // Delivered — the row leaves the queue.
			}

			$row['attempts'] = ( isset( $row['attempts'] ) ? (int) $row['attempts'] : 0 ) + 1;
			$message         = is_wp_error( $verdict ) ? (string) $verdict->get_error_message() : __( 'Delivery failed.', 'agentimus' );
			Webhook::record_failure( $message );

			if ( $row['attempts'] >= self::MAX_ATTEMPTS ) {
				continue; // Out of tries — dropped, with the failure on record.
			}

			$row['next_at'] = $now + self::backoff( $row['attempts'] );
			$kept[]         = $row;
		}

		if ( array() === $kept ) {
			delete_option( self::QUEUE_OPTION );
			return;
		}

		update_option( self::QUEUE_OPTION, $kept, false );

		// Something remains (backed off, or beyond this tick's batch): book the
		// next tick ourselves — single events don't recur, and a queue with no
		// future tick would strand these rows until the next enqueue.
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( $now + $this->next_delay( $kept, $now ), self::CRON );
		}
	}

	/**
	 * Seconds to wait before retry N — the last backoff stretches to fit any N,
	 * so a config bump to MAX_ATTEMPTS can never index past the table.
	 *
	 * @param int $attempt How many tries the row has now had (1-based).
	 * @return int
	 */
	public static function backoff( $attempt ) {
		$steps = self::BACKOFF;
		$index = max( 0, min( count( $steps ) - 1, (int) $attempt - 1 ) );
		return (int) $steps[ $index ];
	}

	/**
	 * When the next tick should come for what is left: as soon as the earliest
	 * backed-off row is due, floored at DELAY so two ticks never stack up.
	 *
	 * @param array $kept The rows staying queued.
	 * @param int   $now  The tick's clock.
	 * @return int Seconds until the next tick.
	 */
	private function next_delay( array $kept, $now ) {
		$soonest = null;
		foreach ( $kept as $row ) {
			$at      = isset( $row['next_at'] ) ? (int) $row['next_at'] : 0;
			$soonest = ( null === $soonest ) ? $at : min( $soonest, $at );
		}
		if ( null === $soonest || $soonest <= $now ) {
			return self::DELAY; // Undelivered same-tick leftovers (the batch cap).
		}
		return max( self::DELAY, $soonest - $now );
	}

	/**
	 * The stored queue, always as a list.
	 *
	 * @return array<int,array>
	 */
	public function queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		return is_array( $queue ) ? array_values( $queue ) : array();
	}

	/**
	 * How many rows wait — the status surface's number.
	 *
	 * @return int
	 */
	public function depth() {
		return count( $this->queue() );
	}

	/**
	 * Drop everything and cancel the tick — the disconnect path.
	 */
	public static function flush() {
		delete_option( self::QUEUE_OPTION );
		wp_clear_scheduled_hook( self::CRON );
	}
}
