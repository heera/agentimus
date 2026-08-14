<?php
/**
 * Per-connection delivery state — the card's honesty line, factored once.
 *
 * Every service card carries the same two facts: when a delivery last landed,
 * and what the last failure said. Phase one kept them inside Webhook; the
 * moment a second service existed they became shared vocabulary, so the map
 * lives here and each service holds only its slice, keyed by its ID. The
 * option itself is the one Webhook already wrote ({ id → row }), so nothing
 * an owner's connection remembered is lost to the refactor.
 *
 * The two laws travel with the code: a success CLEARS the standing error (the
 * card shows the connection's present health, not its scars), and the freshest
 * failure wins (it is the one the owner can still act on).
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations;

defined( 'ABSPATH' ) || exit;

final class DeliveryState {

	/** Option: the shared state map ({ id → { lastDeliveredAt, lastError, lastErrorAt } }). */
	const OPTION = 'agentimus_integrations_state';

	/**
	 * One connection's delivery state, always in its full shape.
	 *
	 * @param string $id Connection id (a service's ID const).
	 * @return array{lastDeliveredAt:int,lastError:string,lastErrorAt:int}
	 */
	public static function read( $id ) {
		$all = get_option( self::OPTION, array() );
		$row = is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
		return array(
			'lastDeliveredAt' => isset( $row['lastDeliveredAt'] ) ? (int) $row['lastDeliveredAt'] : 0,
			'lastError'       => isset( $row['lastError'] ) ? (string) $row['lastError'] : '',
			'lastErrorAt'     => isset( $row['lastErrorAt'] ) ? (int) $row['lastErrorAt'] : 0,
		);
	}

	/**
	 * A delivery landed. A success also clears the standing error.
	 *
	 * @param string $id Connection id.
	 */
	public static function success( $id ) {
		self::write(
			$id,
			array(
				'lastDeliveredAt' => time(),
				'lastError'       => '',
				'lastErrorAt'     => 0,
			)
		);
	}

	/**
	 * A delivery failed. The freshest error wins.
	 *
	 * @param string $id      Connection id.
	 * @param string $message Plain-words failure reason.
	 */
	public static function failure( $id, $message ) {
		$state = self::read( $id );
		self::write(
			$id,
			array(
				'lastDeliveredAt' => $state['lastDeliveredAt'],
				'lastError'       => substr( trim( (string) $message ), 0, 300 ),
				'lastErrorAt'     => time(),
			)
		);
	}

	/**
	 * Drop one connection's slice — its disconnect path.
	 *
	 * @param string $id Connection id.
	 */
	public static function forget( $id ) {
		$all = get_option( self::OPTION, array() );
		if ( is_array( $all ) && isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			if ( empty( $all ) ) {
				delete_option( self::OPTION );
			} else {
				update_option( self::OPTION, $all, false );
			}
		}
	}

	/**
	 * Store one connection's slice of the shared map.
	 *
	 * @param string $id  Connection id.
	 * @param array  $row Full state row.
	 */
	private static function write( $id, array $row ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $id ] = $row;
		update_option( self::OPTION, $all, false );
	}
}
