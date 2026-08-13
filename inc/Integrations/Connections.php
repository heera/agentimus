<?php
/**
 * The shared connection store — one credential per outside service, held once.
 *
 * The model it enforces: a CONNECTION is the credential that lets this site
 * speak to an outside service (a bot token today; an OAuth grant when X and
 * LinkedIn arrive). A USE is what a part of the plugin does with it — the
 * Services tab sending events, the Sharing tab announcing posts. The credential
 * is stored HERE, exactly once; every use keeps its own switch and its own
 * destination in settings. That split is what makes "connect from either tab"
 * true without a second token form: both tabs read the same row, so a service
 * connected anywhere shows connected everywhere, and a use can be silent while
 * its neighbour is busy.
 *
 * Two laws travel with the store. Credentials never ride in the settings array
 * the admin app round-trips — the same law the webhook's secret laid down — so
 * no status surface can print one back. And forgetting a connection is the
 * disconnect for EVERY use it powers; the surfaces that offer the button owe
 * the owner that sentence before they call it.
 *
 * A service with a single use (the webhook's secret, the feed's token) may
 * keep its own option until a second use actually wants it — moving a
 * credential nobody shares would be churn, not architecture.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations;

defined( 'ABSPATH' ) || exit;

final class Connections {

	/** Option: the shared credential map ({ id → { field → value } }). */
	const OPTION = 'agentimus_integrations_connections';

	/**
	 * One connection's credential row, or an empty array when the service was
	 * never connected. The row's fields are the service's own vocabulary
	 * (Telegram stores { token }; an OAuth service will store more).
	 *
	 * @param string $id Connection id (a service's ID const).
	 * @return array<string,mixed>
	 */
	public static function read( $id ) {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array();
	}

	/**
	 * Store one connection's credentials — the FULL row, replaced whole. A
	 * partial write would leave a caller guessing which fields survived; the
	 * connect path always holds the complete grant, so it always writes it.
	 *
	 * @param string $id     Connection id.
	 * @param array  $fields The complete credential row.
	 */
	public static function store( $id, array $fields ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $id ] = $fields;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Whether a connection exists — all any status surface may admit about a
	 * credential.
	 *
	 * @param string $id Connection id.
	 * @return bool
	 */
	public static function exists( $id ) {
		return array() !== self::read( $id );
	}

	/**
	 * Drop one connection — the disconnect path for every use it powers. The
	 * neighbours' rows stand; the option itself leaves with its last row.
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
}
