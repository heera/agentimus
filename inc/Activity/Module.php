<?php
/**
 * Activity module — wires the agent-activity log: ensures the table exists,
 * exposes the admin REST surface (read + clear), and runs the daily prune cron.
 * Recording itself is done at the endpoint serve-paths via Recorder::record().
 *
 * @package Agentimus
 */

namespace Agentimus\Activity;

use Agentimus\Settings;
use Agentimus\Guard;

defined( 'ABSPATH' ) || exit;

final class Module {

	const CRON = 'agentimus_prune_activity';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the table check, REST routes and prune handler.
	 */
	public function register() {
		Table::maybe_install();
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( self::CRON, array( Repository::class, 'prune' ) );
	}

	/**
	 * REST: GET /activity (stats) and DELETE /activity (clear). Admin-only.
	 */
	public function routes() {
		register_rest_route(
			'agentimus/v1',
			'/activity',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => function () {
						return rest_ensure_response( Repository::stats( $this->settings ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => function () {
						Repository::clear();
						return rest_ensure_response( Repository::stats( $this->settings ) );
					},
				),
			)
		);

		register_rest_route(
			'agentimus/v1',
			'/activity/block',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'block' ),
				'args'                => array(
					'ua'      => array( 'type' => 'string' ),
					'spoofed' => array( 'type' => 'boolean' ),
				),
			)
		);
	}

	/**
	 * REST: POST /activity/block — the activity panel's one-click "Block this".
	 * Either arms the spoofed/scanner class (spoofed=true) or appends a safe,
	 * UA-derived token to the denylist; both turn enforcement on. Returns the
	 * refreshed stats so the panel updates the flag / blocked states in place.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function block( \WP_REST_Request $request ) {
		if ( $request->get_param( 'spoofed' ) ) {
			$this->settings->block_spoofed_class();
			return rest_ensure_response( $this->block_payload() );
		}

		$ua    = (string) $request->get_param( 'ua' );
		$token = Guard::suggest_token( $ua );
		if ( '' === $token ) {
			return new \WP_Error(
				'agentimus_no_safe_token',
				__( 'No safe block rule could be derived for this client. Add one under Settings → Block scanners & scrapers.', 'agentimus' ),
				array( 'status' => 422 )
			);
		}
		$this->settings->block_agent( $token );
		return rest_ensure_response( $this->block_payload() );
	}

	/**
	 * Block response: refreshed activity stats PLUS the updated settings — the block
	 * writes to the same blocked_agents / block_agents the Settings tab shows, so
	 * returning them lets the admin reflect the new denylist entry there immediately,
	 * with no reload and no second request.
	 *
	 * @return array{activity:array,settings:array}
	 */
	private function block_payload() {
		return array(
			'activity' => Repository::stats( $this->settings ),
			'settings' => $this->settings->all(),
		);
	}

	/**
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Schedule the daily prune (activation).
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Clear the prune schedule (deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}
}
