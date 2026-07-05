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
			'/visibility/reveal-key',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'reveal_key' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/visibility/clear',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'clear_data' ),
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
	 * POST /run — start a monitoring pass. A run makes many slow HTTP calls (more so
	 * with live web search on) and can outlast the web server's gateway timeout, so
	 * it runs in the background: this returns immediately and the admin polls
	 * /dashboard for `running` to flip false. If nothing could run (no question or no
	 * engine), that's reported right away instead of queuing a no-op.
	 *
	 * @return \WP_REST_Response
	 */
	public function run_now() {
		$reason  = ( new Runner( $this->settings ) )->blocking_reason();
		$payload = $this->dashboard_payload();

		if ( '' !== $reason ) {
			$payload['run'] = array( 'ran' => false, 'reason' => $reason );
			return rest_ensure_response( $payload );
		}

		( new Module( $this->settings ) )->queue_now();
		$payload['run']     = array( 'queued' => true );
		$payload['running'] = true;
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
			$key = $this->settings->provider_key( $id ); // Decrypted stored key.
		}
		if ( '' === $model ) {
			$model = (string) $cfg['model'];
		}

		$result = ( new Runner( $this->settings ) )->test( $id, $key, $model );
		return rest_ensure_response( $result );
	}

	/**
	 * POST /reveal-key — return one provider's full stored key so an admin can view
	 * or copy it. Gated by `manage_options` and the REST nonce; the key is sent only
	 * on this explicit request, never in the config payload (see Settings::public_view).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reveal_key( \WP_REST_Request $request ) {
		$id  = sanitize_key( (string) $request->get_param( 'provider' ) );
		$all = $this->settings->all();
		$cfg = isset( $all['providers'][ $id ] ) ? $all['providers'][ $id ] : null;

		// Decrypt the stored key for display; '' when the provider is unknown.
		return rest_ensure_response( array( 'key' => null === $cfg ? '' : $this->settings->provider_key( $id ) ) );
	}

	/**
	 * The config payload the UI boots and re-reads after a save.
	 *
	 * @return array
	 */
	private function config_payload() {
		$view = $this->settings->public_view();

		$prompt_count = 0;
		foreach ( (array) $this->settings->get( 'targets', array() ) as $t ) {
			$prompt_count += count( (array) ( $t['prompts'] ?? array() ) );
		}

		return array(
			'config'         => $view,
			'lastRunAt'      => $this->last_run_at(),
			'activeProviders' => count( $this->settings->active_providers() ),
			'promptCount'    => $prompt_count,
		);
	}

	/**
	 * POST /clear — wipe all stored monitoring results for this site.
	 *
	 * @return \WP_REST_Response
	 */
	public function clear_data() {
		Store::clear();
		delete_option( Runner::LAST_RUN_OPTION );
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
			'running'   => Module::is_running(),
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
