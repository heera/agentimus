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
 * credential that was promised to appear once. Telegram's bot token is
 * stricter still: the owner already holds it, so no response ever carries it
 * — the status only admits one is stored.
 *
 * @package Agentimus
 */

namespace Agentimus\Integrations;

use Agentimus\Settings;
use Agentimus\Integrations\Services\Slack;
use Agentimus\Integrations\Services\Telegram;
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
	 * POST /integrations — { service?: webhook | telegram, action: connect |
	 * save | disconnect | regenerate }. No service named means the webhook,
	 * phase one's whole roster.
	 *
	 * Every branch goes through Settings::update() with the FULL resolved
	 * settings (read, amend, write back): a partial array would reset every
	 * omitted flag to its default — the trap this codebase has already paid for.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function act( $request ) {
		$service = (string) ( $request->get_param( 'service' ) ? $request->get_param( 'service' ) : Webhook::ID );
		$action  = (string) $request->get_param( 'action' );

		if ( Webhook::ID === $service ) {
			if ( 'connect' === $action || 'save' === $action ) {
				return $this->save( $request, 'connect' === $action );
			}
			if ( 'disconnect' === $action ) {
				return $this->disconnect_webhook();
			}
			if ( 'regenerate' === $action ) {
				return $this->regenerate();
			}
		}
		if ( Telegram::ID === $service ) {
			if ( 'connect' === $action || 'save' === $action ) {
				return $this->save_telegram( $request, 'connect' === $action );
			}
			if ( 'disconnect' === $action ) {
				return $this->disconnect_telegram();
			}
		}
		if ( Slack::ID === $service ) {
			if ( 'connect' === $action || 'save' === $action ) {
				return $this->save_url_service(
					$request,
					'slack',
					__( 'That doesn’t look like a Slack webhook URL. In Slack, add the “Incoming Webhooks” app to a channel and paste the https:// URL it gives you.', 'agentimus' )
				);
			}
			if ( 'disconnect' === $action ) {
				return $this->disconnect_url_service( 'slack', Slack::ID, array( Slack::class, 'forget_state' ) );
			}
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

		$events = $this->events_param( $request );
		if ( is_wp_error( $events ) ) {
			return $events;
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
	 * Disconnect the webhook: switch off, forget the URL and the choices,
	 * delete the secret, the state and its queue rows. A disconnect is a full
	 * goodbye — what remains could only masquerade as a half-working
	 * connection.
	 *
	 * @return \WP_REST_Response
	 */
	private function disconnect_webhook() {
		$this->store(
			array(
				'webhook_enabled' => false,
				'webhook_url'     => '',
				'webhook_events'  => array(),
			)
		);

		Webhook::forget_secret();
		Webhook::forget_state();
		$this->after_disconnect( Webhook::ID );

		return rest_ensure_response( $this->payload() );
	}

	/* -- Telegram ------------------------------------------------------------ */

	/**
	 * Connect (or save) Telegram. A connect proves the road first — getMe for
	 * the token, one test message for the chat — so a stored connection has
	 * already delivered once. A save re-proves only what changed: a new token
	 * is fully verified; a changed chat gets the test message; a mere
	 * checkbox edit sends nothing at all.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param bool             $connect Whether this is the connect action.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function save_telegram( $request, $connect ) {
		$chat = Telegram::normalize_chat( (string) $request->get_param( 'chat' ) );
		if ( '' === $chat ) {
			return new \WP_Error(
				'agentimus_telegram_chat',
				__( 'That doesn’t look like a chat id. It’s a number like 123456789 (a group’s starts with a minus), or @name for a public channel you run.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$events = $this->events_param( $request );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$tier = 'urgent' === (string) $request->get_param( 'tier' ) ? 'urgent' : 'all';

		$token = trim( (string) $request->get_param( 'token' ) );
		if ( '' === $token && ! Telegram::has_token() ) {
			return new \WP_Error(
				'agentimus_telegram_token',
				__( 'Paste the bot token @BotFather sent you — the long code that looks like 123456789:ABC…', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		// What must be proved: a fresh connect, a new token, or a chat the
		// stored connection has never delivered to.
		$stored_chat = Telegram::config( $this->settings )['chat'];
		if ( $connect || '' !== $token ) {
			$verdict = Telegram::verify( '' !== $token ? $token : Telegram::token(), $chat );
		} elseif ( $chat !== $stored_chat ) {
			$verdict = Telegram::verify( Telegram::token(), $chat );
		} else {
			$verdict = true;
		}
		if ( is_wp_error( $verdict ) ) {
			return new \WP_Error( $verdict->get_error_code(), $verdict->get_error_message(), array( 'status' => 400 ) );
		}

		if ( '' !== $token ) {
			Telegram::store_token( $token );
		}
		$this->store(
			array(
				'telegram_enabled' => true,
				'telegram_chat'    => $chat,
				'telegram_events'  => $events,
				'telegram_tier'    => $tier,
			)
		);

		( new Events( $this->settings ) )->sync_findings_schedule();

		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Disconnect Telegram: switch off, forget the chat, the choices, the
	 * token, the state and its queue rows.
	 *
	 * @return \WP_REST_Response
	 */
	private function disconnect_telegram() {
		$this->store(
			array(
				'telegram_enabled' => false,
				'telegram_chat'    => '',
				'telegram_events'  => array(),
				'telegram_tier'    => 'all',
			)
		);

		Telegram::forget_token();
		Telegram::forget_state();
		$this->after_disconnect( Telegram::ID );

		return rest_ensure_response( $this->payload() );
	}

	/* -- The URL-shaped services (Slack, Discord) ----------------------------- */

	/**
	 * Connect or save a service whose whole credential is a pasted URL. No
	 * secret to mint, no proof call to make — the URL either answers the first
	 * delivery or the card's honesty line says it didn't.
	 *
	 * @param \WP_REST_Request $request   The request.
	 * @param string           $prefix    The service's settings key prefix (= its id).
	 * @param string           $bad_url   The plain-words error for a URL that isn't one.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function save_url_service( $request, $prefix, $bad_url ) {
		$url = esc_url_raw( trim( (string) $request->get_param( 'url' ) ), array( 'https', 'http' ) );
		if ( '' === $url ) {
			return new \WP_Error( 'agentimus_bad_url', $bad_url, array( 'status' => 400 ) );
		}

		$events = $this->events_param( $request );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$this->store(
			array(
				$prefix . '_enabled' => true,
				$prefix . '_url'     => $url,
				$prefix . '_events'  => $events,
			)
		);

		( new Events( $this->settings ) )->sync_findings_schedule();

		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Disconnect a URL-shaped service: switch off, forget the URL and the
	 * choices, drop its state and its queue rows.
	 *
	 * @param string   $prefix       The service's settings key prefix.
	 * @param string   $id           The service's id.
	 * @param callable $forget_state The service's forget_state.
	 * @return \WP_REST_Response
	 */
	private function disconnect_url_service( $prefix, $id, $forget_state ) {
		$this->store(
			array(
				$prefix . '_enabled' => false,
				$prefix . '_url'     => '',
				$prefix . '_events'  => array(),
			)
		);

		call_user_func( $forget_state );
		$this->after_disconnect( $id );

		return rest_ensure_response( $this->payload() );
	}

	/* -- Shared plumbing ------------------------------------------------------ */

	/**
	 * The event choices from a request, validated against the catalog and
	 * refused when empty — a connection subscribed to nothing would never ring.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return string[]|\WP_Error
	 */
	private function events_param( $request ) {
		$events = array_values(
			array_intersect(
				array_map( 'strval', (array) $request->get_param( 'events' ) ),
				Events::names()
			)
		);
		if ( array() === $events ) {
			return new \WP_Error(
				'agentimus_no_events',
				__( 'Pick at least one event — a connection subscribed to nothing would never ring.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		return $events;
	}

	/**
	 * Merge one service's keys into the stored integrations block — always the
	 * FULL resolved settings, never a partial (the reset trap).
	 *
	 * @param array $keys The service's settings keys.
	 */
	private function store( array $keys ) {
		$all                 = $this->settings->all();
		$all['integrations'] = array_merge(
			is_array( $all['integrations'] ) ? $all['integrations'] : array(),
			$keys
		);
		$this->settings->update( $all );
	}

	/**
	 * The cleanup every disconnect shares. Only the departing service's queue
	 * rows go (another connection's pending events are not ours to discard);
	 * the shared memories fall only when the last subscriber that needed them
	 * is gone.
	 *
	 * @param string $service The disconnecting service's id.
	 */
	private function after_disconnect( $service ) {
		Dispatcher::flush_service( $service );

		( new Events( $this->settings ) )->sync_findings_schedule();
		if ( ! Events::wants_findings_cron( $this->settings ) ) {
			// Nobody subscribes to findings any more: drop the baseline, so a
			// future subscriber gets a fresh silent seeding, not a stale diff.
			delete_option( Events::FINDINGS_SEEN_OPTION );
		}
		if ( ! Services::any_connected( $this->settings ) ) {
			// The last connection is gone — the queue and its tick go with it.
			Dispatcher::flush();
			delete_option( Events::IMPOSTOR_SEEN_OPTION );
		}
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

		$dispatcher = new Dispatcher( $this->settings );
		$telegram   = Telegram::config( $this->settings );
		$slack      = Slack::config( $this->settings );

		return array(
			'webhook'  => array(
				'enabled'   => $config['enabled'],
				'url'       => $config['url'],
				'events'    => $config['events'],
				'hasSecret' => Webhook::has_secret(),
				'queued'    => $dispatcher->depth_for( Webhook::ID ),
				'state'     => Webhook::state(),
			),
			'telegram' => array(
				'enabled'  => $telegram['enabled'],
				'chat'     => $telegram['chat'],
				'events'   => $telegram['events'],
				'tier'     => $telegram['tier'],
				'hasToken' => Telegram::has_token(),
				'queued'   => $dispatcher->depth_for( Telegram::ID ),
				'state'    => Telegram::state(),
			),
			'slack'    => array(
				'enabled' => $slack['enabled'],
				'url'     => $slack['url'],
				'events'  => $slack['events'],
				'queued'  => $dispatcher->depth_for( Slack::ID ),
				'state'   => Slack::state(),
			),
			'events'   => $catalog,
			'plugins'  => $plugins,
		);
	}
}
