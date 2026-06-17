<?php
/**
 * REST controller backing the Vue admin: read/save settings and fetch the
 * readiness report. All routes require `manage_options` and the standard REST
 * nonce (apiFetch / X-WP-Nonce).
 *
 * @package Agentify
 */

namespace Agentify;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NAMESPACE = 'agentify/v1';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Define the routes.
	 */
	public function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/reset',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reset_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/onboarding',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'complete_onboarding' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/readiness',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_readiness' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permission gate.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response(
			array(
				'settings'  => $this->settings->all(),
				'readiness' => ( new Readiness( $this->settings ) )->report(),
			)
		);
	}

	/**
	 * POST /settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = (array) $request->get_param( 'settings' );
		}
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$input = $input['settings'];
		}

		$saved = $this->settings->update( (array) $input );

		return rest_ensure_response(
			array(
				'settings'  => $saved,
				'readiness' => ( new Readiness( $this->settings ) )->report(),
				'saved'     => true,
			)
		);
	}

	/**
	 * POST /settings/reset — restore factory defaults.
	 *
	 * @return \WP_REST_Response
	 */
	public function reset_settings() {
		$defaults = $this->settings->reset();

		return rest_ensure_response(
			array(
				'settings'  => $defaults,
				'readiness' => ( new Readiness( $this->settings ) )->report(),
				'reset'     => true,
			)
		);
	}

	/**
	 * POST /onboarding — mark the first-run setup wizard complete (or skipped) so
	 * it never shows again. Stored as its own option, not inside settings, so a
	 * factory reset doesn't re-trigger the wizard.
	 *
	 * @return \WP_REST_Response
	 */
	public function complete_onboarding() {
		update_option( 'agentify_onboarded', AGENTIFY_VERSION );
		return rest_ensure_response( array( 'onboarded' => true ) );
	}

	/**
	 * GET /readiness.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_readiness() {
		return rest_ensure_response( ( new Readiness( $this->settings ) )->report() );
	}
}
