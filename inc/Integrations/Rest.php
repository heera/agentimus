<?php
/**
 * REST for the Integrations screen — one status read, one action door.
 *
 * These routes are registered UNCONDITIONALLY, unlike the pipeline they
 * manage: a screen whose whole job is turning the webhook on cannot live
 * behind the switch it flips. Same manage_options + REST-nonce gate as every
 * other admin route.
 *
 * The secret's one rule is enforced here: connect and regenerate are the only
 * two responses that ever carry the plaintext. The status read admits only
 * that a secret exists — an admin screen reload must never re-print a
 * credential that was promised to appear once.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations;

use Agentimus\Settings;
use Agentimus\Integrations\Services\Webhook;

defined( 'ABSPATH' ) || exit;

final class Rest {

	/**
	 * The provider cards, in the order the PLUGINS tab shows them. Each class
	 * answers present() + describe(); adding a provider is adding a line here.
	 */
	const PLUGINS = array(
		Plugins\WooCommerce::class,
		Plugins\FluentCart::class,
		Plugins\FluentForms::class,
		Plugins\FluentCrm::class,
		Plugins\FluentBooking::class,
		Plugins\FluentCommunity::class,
		Plugins\Edd::class,
	);

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/** Hook route registration. */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/** Define the routes. */
	public function routes() {
		register_rest_route(
			'agentimus/v1',
			'/integrations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'act' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Route gate — the same one every admin route wears.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /integrations — everything the screen needs in one read.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * POST /integrations — { action: connect | save | disconnect | regenerate }.
	 *
	 * Every branch goes through Settings::update() with the FULL resolved
	 * settings (read, amend, write back): a partial array would reset every
	 * omitted flag to its default — the trap this codebase has already paid for.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function act( $request ) {
		$action = (string) $request->get_param( 'action' );

		if ( 'connect' === $action || 'save' === $action ) {
			return $this->save( $request, 'connect' === $action );
		}
		if ( 'disconnect' === $action ) {
			return $this->disconnect();
		}
		if ( 'regenerate' === $action ) {
			return $this->regenerate();
		}
		return new \WP_Error( 'agentimus_bad_action', __( 'Unknown action.', 'agentimus' ), array( 'status' => 400 ) );
	}

	/**
	 * Connect (mint the secret) or save (keep it): store the URL + event
	 * choices, switch the connection on.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param bool             $mint    Whether to mint a fresh secret (connect).
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function save( $request, $mint ) {
		$url = esc_url_raw( trim( (string) $request->get_param( 'url' ) ), array( 'https', 'http' ) );
		if ( '' === $url ) {
			return new \WP_Error(
				'agentimus_bad_url',
				__( 'That doesn’t look like a URL the webhook can post to. It needs to start with https:// (or http:// for a local test).', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$events = array_values(
			array_intersect(
				array_map( 'strval', (array) $request->get_param( 'events' ) ),
				Events::names()
			)
		);
		if ( array() === $events ) {
			return new \WP_Error(
				'agentimus_no_events',
				__( 'Pick at least one event — a webhook subscribed to nothing would never ring.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		// Saving without ever having connected is a connect in disguise: no
		// secret exists yet, so deliveries could never be signed.
		if ( ! $mint && ! Webhook::has_secret() ) {
			$mint = true;
		}

		$all                 = $this->settings->all();
		$all['integrations'] = array_merge(
			is_array( $all['integrations'] ) ? $all['integrations'] : array(),
			array(
				'webhook_enabled' => true,
				'webhook_url'     => $url,
				'webhook_events'  => $events,
			)
		);
		$this->settings->update( $all );

		$secret = $mint ? Webhook::mint_secret() : '';

		// The pipeline registered (or stood down) at boot from the OLD state;
		// bring the schedules in line with the state that now holds.
		( new Events( $this->settings ) )->sync_findings_schedule();

		$payload = $this->payload();
		if ( '' !== $secret ) {
			// The one appearance the plaintext ever makes.
			$payload['secret'] = $secret;
		}
		return rest_ensure_response( $payload );
	}

	/**
	 * Disconnect: switch off, forget the URL and the choices, delete the
	 * secret, the state and the queue. A disconnect is a full goodbye — what
	 * remains could only masquerade as a half-working connection.
	 *
	 * @return \WP_REST_Response
	 */
	private function disconnect() {
		$all                 = $this->settings->all();
		$all['integrations'] = array_merge(
			is_array( $all['integrations'] ) ? $all['integrations'] : array(),
			array(
				'webhook_enabled' => false,
				'webhook_url'     => '',
				'webhook_events'  => array(),
			)
		);
		$this->settings->update( $all );

		Webhook::forget_secret();
		Webhook::forget_state();
		Dispatcher::flush();
		wp_clear_scheduled_hook( Events::CRON_FINDINGS );
		delete_option( Events::FINDINGS_SEEN_OPTION );
		delete_option( Events::IMPOSTOR_SEEN_OPTION );

		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Regenerate: a fresh secret for a standing connection — the receiver's
	 * rotation path. Returned once, like connect's.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function regenerate() {
		if ( ! Webhook::config( $this->settings )['enabled'] ) {
			return new \WP_Error( 'agentimus_not_connected', __( 'Connect the webhook first.', 'agentimus' ), array( 'status' => 400 ) );
		}
		$payload           = $this->payload();
		$payload['secret'] = Webhook::mint_secret();
		return rest_ensure_response( $payload );
	}

	/**
	 * The status payload both verbs answer with.
	 *
	 * @return array
	 */
	private function payload() {
		$config  = Webhook::config( $this->settings );
		$catalog = array();
		foreach ( Events::catalog() as $name => $row ) {
			$catalog[] = array(
				'name'        => (string) $name,
				'label'       => isset( $row['label'] ) ? (string) $row['label'] : (string) $name,
				'description' => isset( $row['description'] ) ? (string) $row['description'] : '',
			);
		}

		$plugins = array();
		foreach ( self::PLUGINS as $class ) {
			$plugins[] = $class::describe();
		}

		return array(
			'webhook' => array(
				'enabled'   => $config['enabled'],
				'url'       => $config['url'],
				'events'    => $config['events'],
				'hasSecret' => Webhook::has_secret(),
				'queued'    => ( new Dispatcher( $this->settings ) )->depth(),
				'state'     => Webhook::state(),
			),
			'events'  => $catalog,
			'plugins' => $plugins,
		);
	}
}
