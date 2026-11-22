<?php
/**
 * The Abilities screen's routes: list other plugins' abilities with their
 * switches, and turn them on or off.
 *
 * Turning one on changes what agents may do, so only an administrator in the
 * browser reaches these: an application password is refused, an agent's credential
 * is confined to the MCP address, and HardBlocks keeps the switches during an agent
 * call.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

defined( 'ABSPATH' ) || exit;

final class OtherAbilitiesRest {

	const NS = 'agentimus/v1';

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/abilities',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_all' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'switch_abilities' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * GET /abilities: every other plugin's ability, grouped by plugin, with its switch.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_all() {
		return rest_ensure_response(
			array(
				'available' => function_exists( 'wp_get_abilities' ),
				'plugins'   => OtherAbilities::catalog(),
			)
		);
	}

	/**
	 * PUT /abilities: { names: string[], on: bool }.
	 *
	 * @param \WP_REST_Request $request The change.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function switch_abilities( \WP_REST_Request $request ) {
		$names = $request->get_param( 'names' );
		if ( ! is_array( $names ) ) {
			return new \WP_Error( 'agentimus_bad_abilities', __( 'Name the abilities to turn on or off.', 'agentimus' ), array( 'status' => 400 ) );
		}
		OtherAbilities::set( $names, rest_sanitize_boolean( $request->get_param( 'on' ) ) );
		return $this->list_all();
	}

	/**
	 * An administrator in the browser, never through an application password.
	 *
	 * @return bool
	 */
	public function can_manage() {
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return false;
		}
		return current_user_can( 'manage_options' );
	}
}
