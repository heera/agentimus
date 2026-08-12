<?php
/**
 * The service registry — every connection the SERVICES tab can hold, in the
 * order its cards stand.
 *
 * Each service is a final class answering the same static grammar Webhook laid
 * down in phase one:
 *
 *   ID                                  — its id in settings, state and queue rows.
 *   config( Settings )                  — the stored configuration, full shape.
 *   connected( Settings )               — whether it could deliver at all.
 *   wants( Settings, $event )           — the owner's per-event checkbox.
 *   accepts( Settings, $event, $data )  — a service's own payload gate (Telegram's
 *                                         minimum finding tier); true for most.
 *   deliver( $event, $envelope )        — one send, one verdict; retries are the
 *                                         queue's business, never the service's.
 *   state() / record_success() / record_failure() / forget_state()
 *                                       — its slice of the shared honesty line.
 *
 * The registry is how the pipeline stays ONE pipeline: Events asks it who
 * wants a moment, the Dispatcher asks it who a queued row belongs to, and the
 * inertness law becomes "not one hook until ANY service is connected".
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations;

use Agentimus\Settings;
use Agentimus\Integrations\Services\Slack;
use Agentimus\Integrations\Services\Telegram;
use Agentimus\Integrations\Services\Webhook;

defined( 'ABSPATH' ) || exit;

final class Services {

	/**
	 * Every service, id → class, in card order.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		return array(
			Webhook::ID  => Webhook::class,
			Telegram::ID => Telegram::class,
			Slack::ID    => Slack::class,
		);
	}

	/**
	 * One service's class by id — null for a name the roster never held (a
	 * queue row that survived from a build that knew more services than this
	 * one is dropped by the drain, not guessed at).
	 *
	 * @param string $id Service id.
	 * @return string|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (string) $id ] ) ? $all[ (string) $id ] : null;
	}

	/**
	 * Whether ANY service is connected — the pipeline's arming condition.
	 *
	 * @param Settings $settings Plugin settings.
	 * @return bool
	 */
	public static function any_connected( Settings $settings ) {
		foreach ( self::all() as $class ) {
			if ( $class::connected( $settings ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The ids of every connected service that subscribed to an event AND
	 * accepts this particular payload — who an emit fans out to.
	 *
	 * @param Settings $settings Plugin settings.
	 * @param string   $event    Event name.
	 * @param array    $data     The event's contract-shaped payload.
	 * @return string[]
	 */
	public static function wanting( Settings $settings, $event, array $data ) {
		$ids = array();
		foreach ( self::all() as $id => $class ) {
			if ( $class::wants( $settings, $event ) && $class::accepts( $settings, $event, $data ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
