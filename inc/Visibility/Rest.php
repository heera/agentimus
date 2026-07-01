<?php
/**
 * REST controller backing the Pro admin screen: read/save the monitoring config,
 * run a check on demand, fetch the dashboard, and test a provider key. All routes
 * require `manage_options` and the standard REST nonce (X-WP-Nonce), mirroring the
 * free core's controller.
 *
 * @package Agentimus
 */

namespace Agentimus\Visibility;

use Agentimus\Visibility\Demo;
use Agentimus\Visibility\Module;
use Agentimus\Visibility\Runner;
use Agentimus\Visibility\Store;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'agentimus/v1';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Pro settings.
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
			self::NS,
			'/visibility/config',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_config' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/visibility/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/visibility/run',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'run_now' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/visibility/test',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'test_key' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/visibility/demo/(?P<action>seed|clear)',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'demo' ),
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
	 * GET /config.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_config() {
		return rest_ensure_response( $this->config_payload() );
	}

	/**
	 * POST /config — save settings and realign the cron cadence.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_config( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = (array) $request->get_params();
		}

		$this->settings->update( $input );

		// Keep the recurring run aligned with any new frequency.
		( new Module( $this->settings ) )->sync_schedule();

		$payload           = $this->config_payload();
		$payload['saved']  = true;
		return rest_ensure_response( $payload );
	}

	/**
	 * GET /dashboard — the scored latest run, share of voice and trend.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_dashboard() {
		return rest_ensure_response( $this->dashboard_payload() );
	}

	/**
	 * POST /run — perform a monitoring pass now and return fresh results.
	 *
	 * @return \WP_REST_Response
	 */
	public function run_now() {
		$result  = ( new Runner( $this->settings ) )->run();
		$payload = $this->dashboard_payload();
		$payload['run'] = $result;
		return rest_ensure_response( $payload );
	}

	/**
	 * POST /test — verify one provider's key. A blank or masked key falls back to
	 * the stored key, so a user can re-test a saved provider without re-entering it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test_key( \WP_REST_Request $request ) {
		$id    = sanitize_key( (string) $request->get_param( 'provider' ) );
		$key   = trim( (string) $request->get_param( 'key' ) );
		$model = sanitize_text_field( (string) $request->get_param( 'model' ) );

		$all = $this->settings->all();
		$cfg = isset( $all['providers'][ $id ] ) ? $all['providers'][ $id ] : null;

		if ( null === $cfg ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => __( 'Unknown provider.', 'agentimus' ) ) );
		}

		if ( '' === $key || Settings::KEY_MASK === $key ) {
			$key = (string) $cfg['key'];
		}
		if ( '' === $model ) {
			$model = (string) $cfg['model'];
		}

		$result = ( new Runner( $this->settings ) )->test( $id, $key, $model );
		return rest_ensure_response( $result );
	}

	/**
	 * The config payload the UI boots and re-reads after a save.
	 *
	 * @return array
	 */
	private function config_payload() {
		$view = $this->settings->public_view();

		return array(
			'config'         => $view,
			'lastRunAt'      => $this->last_run_at(),
			'activeProviders' => count( $this->settings->active_providers() ),
			'promptCount'    => count( (array) $this->settings->get( 'prompts', array() ) ),
		);
	}

	/**
	 * POST /demo/seed|clear — load or wipe sample data so the Dashboard can be
	 * viewed fully populated before any real key is connected.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function demo( \WP_REST_Request $request ) {
		if ( 'clear' === $request->get_param( 'action' ) ) {
			Demo::clear();
		} else {
			Demo::seed( $this->settings );
		}
		return rest_ensure_response( $this->dashboard_payload() );
	}

	/**
	 * The dashboard payload (results + a little context).
	 *
	 * @return array
	 */
	private function dashboard_payload() {
		return array(
			'dashboard' => Store::dashboard( $this->settings ),
			'lastRunAt' => $this->last_run_at(),
			'isSample'  => Demo::is_active(),
			'config'    => $this->settings->public_view(),
		);
	}

	/**
	 * The last-run time as an ISO 8601 string, or '' if never run.
	 *
	 * @return string
	 */
	private function last_run_at() {
		$ts = (int) get_option( Runner::LAST_RUN_OPTION, 0 );
		return $ts > 0 ? gmdate( 'c', $ts ) : '';
	}
}
