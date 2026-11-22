<?php
/**
 * The Skills screen's routes: list, add, change, turn on or off, delete.
 *
 * Turning a skill on steers every agent, so only an administrator in the browser
 * reaches these: an application password is refused, an agent's credential is
 * confined to the MCP address, and HardBlocks keeps agent-written skills off during
 * an agent call.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

defined( 'ABSPATH' ) || exit;

final class SkillsRest {

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
			'/skills',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_all' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/skills/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'change' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * GET /skills.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_all() {
		return rest_ensure_response(
			array(
				'skills' => Skills::all(),
				'max'    => Skills::MAX,
			)
		);
	}

	/**
	 * POST /skills: { name, when, steps, on }.
	 *
	 * @param \WP_REST_Request $request The skill.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$on    = null === $request->get_param( 'on' ) ? true : rest_sanitize_boolean( $request->get_param( 'on' ) );
		$saved = Skills::save_from_owner( 0, $request->get_param( 'name' ), $request->get_param( 'when' ), $request->get_param( 'steps' ), $on );
		return is_wp_error( $saved ) ? $saved : $this->list_all();
	}

	/**
	 * PUT /skills/{id}: { on } alone turns it on or off; with name and steps it
	 * changes the text too.
	 *
	 * @param \WP_REST_Request $request The change.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( null === $request->get_param( 'name' ) ) {
			$saved = Skills::set_on( $id, rest_sanitize_boolean( $request->get_param( 'on' ) ) );
		} else {
			$on    = null === $request->get_param( 'on' ) ? true : rest_sanitize_boolean( $request->get_param( 'on' ) );
			$saved = Skills::save_from_owner( $id, $request->get_param( 'name' ), $request->get_param( 'when' ), $request->get_param( 'steps' ), $on );
		}
		return is_wp_error( $saved ) ? $saved : $this->list_all();
	}

	/**
	 * DELETE /skills/{id}.
	 *
	 * @param \WP_REST_Request $request The skill.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove( \WP_REST_Request $request ) {
		if ( ! Skills::delete( (int) $request['id'] ) ) {
			return new \WP_Error( 'agentimus_skill_missing', __( 'That skill no longer exists.', 'agentimus' ), array( 'status' => 404 ) );
		}
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
