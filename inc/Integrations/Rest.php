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
use Agentimus\Integrations\Services\Discord;
use Agentimus\Integrations\Services\Sheets;
use Agentimus\Integrations\Services\Slack;
use Agentimus\Integrations\Services\Telegram;
use Agentimus\Integrations\Services\Webhook;

defined( 'ABSPATH' ) || exit;

final class Rest {

	/** Ledger rows per page on the Announcements screen. */
	const ANNOUNCEMENTS_PER_PAGE = 20;

	/**
	 * The provider cards, in the order the PLUGINS tab shows them. Each class
	 * answers present() + describe(); adding a provider is adding a line here.
	 *
	 * The order is deliberate (his call, 2026-08-15): the Fluent family first,
	 * then WooCommerce, then Easy Digital Downloads, then everything added
	 * later. A new provider joins the end unless it belongs to one of those
	 * groups — the roster is a running order, not an alphabet.
	 */
	const PLUGINS = array(
		Plugins\FluentCart::class,
		Plugins\FluentForms::class,
		Plugins\FluentCrm::class,
		Plugins\FluentBooking::class,
		Plugins\FluentCommunity::class,
		Plugins\FluentSupport::class,
		Plugins\WooCommerce::class,
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

		// The Announcements screen — the ledger's read and its three verbs.
		// A sibling of the Integrations screen (More → Announcements), riding
		// this class because it wears the same gate and speaks to the same
		// machinery.
		register_rest_route(
			'agentimus/v1',
			'/announcements',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'announcements' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'announcements_act' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		// X's OAuth callback — the address the owner pastes into their X app.
		// Public by nature (X's redirect arrives unauthenticated); its gates
		// are the single-use state and the PKCE verifier behind it.
		register_rest_route(
			'agentimus/v1',
			'/x/callback',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'x_callback' ),
				'permission_callback' => '__return_true',
			)
		);

		// LinkedIn's OAuth callback — same public nature and gates as X's.
		register_rest_route(
			'agentimus/v1',
			'/linkedin/callback',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'linkedin_callback' ),
				'permission_callback' => '__return_true',
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

	/* -- The Announcements screen ---------------------------------------------- */

	/**
	 * GET /announcements — one page of the ledger, promises first. Titles are
	 * resolved here, at read time: the row stores only the post's id, so a
	 * retitled post reads by its current name.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function announcements( $request ) {
		return rest_ensure_response( $this->announcements_page( max( 1, (int) $request->get_param( 'page' ) ) ) );
	}

	/**
	 * POST /announcements — { action: cancel | retry | remove, id }. Each verb
	 * keeps its lane (the engine refuses the wrong one), and the answer is the
	 * same page the owner was looking at, re-read.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function announcements_act( $request ) {
		$engine = new Announcements( $this->settings );
		$id     = (int) $request->get_param( 'id' );
		$action = (string) $request->get_param( 'action' );

		if ( 'queue' === $action ) {
			// The Share tab's door: the approved draft, the chosen network,
			// the chosen moment. The engine refuses whole anything that could
			// only fail.
			$verdict = $engine->queue(
				array(
					'network' => (string) $request->get_param( 'network' ),
					'body'    => (string) $request->get_param( 'body' ),
					'post_id' => (int) $request->get_param( 'post_id' ),
					'at'      => (int) $request->get_param( 'at' ),
				)
			);
		} elseif ( 'cancel' === $action ) {
			$verdict = $engine->cancel( $id );
		} elseif ( 'send' === $action ) {
			// The owner overruling their own clock. One row, this minute.
			$verdict = $engine->send_now( $id );
		} elseif ( 'retry' === $action ) {
			$verdict = $engine->retry( $id );
		} elseif ( 'remove' === $action ) {
			$verdict = $engine->remove( $id );
		} else {
			$verdict = new \WP_Error( 'agentimus_announce_action', __( 'Unknown action.', 'agentimus' ) );
		}

		if ( is_wp_error( $verdict ) ) {
			return new \WP_Error( $verdict->get_error_code(), $verdict->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $this->announcements_page( max( 1, (int) $request->get_param( 'page' ) ) ) );
	}

	/**
	 * One page of the ledger in the screen's vocabulary.
	 *
	 * @param int $page 1-based page.
	 * @return array
	 */
	private function announcements_page( $page ) {
		$read = ( new Announcements( $this->settings ) )->rows( $page, self::ANNOUNCEMENTS_PER_PAGE );
		// The same answer the editor's Share tab shows as its link preview —
		// featured image → the site-wide default → the entity image. One
		// resolver, so the preview here can never disagree with that one.
		$seo = new \Agentimus\Seo( $this->settings );

		$rows = array();
		foreach ( $read['rows'] as $row ) {
			// The column names content, not posts — a page or any CPT can be
			// announced. And an absence names itself: a deleted entry is not
			// an untitled one, and neither is an announcement never tied to
			// one at all.
			$entry = $row['post_id'] ? get_post( (int) $row['post_id'] ) : null;
			$title = $entry ? (string) get_the_title( $entry ) : '';
			if ( '' === $title ) {
				if ( $entry ) {
					$title = __( '(untitled)', 'agentimus' );
				} else {
					$title = $row['post_id'] ? __( '(deleted)', 'agentimus' ) : __( '(no content)', 'agentimus' );
				}
			}
			$image = $entry ? $seo->social_image( $entry ) : null;

			$rows[] = array(
				'id'          => (int) $row['id'],
				'network'     => (string) $row['network'],
				'postId'      => (int) $row['post_id'],
				'postTitle'   => $title,
				'postUrl'     => $entry ? (string) get_permalink( $entry ) : '',
				'image'       => $image ? (string) $image['url'] : '',
				'imageAlt'    => $image ? (string) $image['alt'] : '',
				'body'        => (string) $row['body'],
				'scheduledAt' => (int) $row['scheduled_at'],
				'sentAt'      => (int) $row['sent_at'],
				// 0 on rows that failed before this was recorded — the screen
				// then says it didn't go without inventing a minute for it.
				'failedAt'    => isset( $row['failed_at'] ) ? (int) $row['failed_at'] : 0,
				'status'      => (string) $row['status'],
				'error'       => (string) $row['error'],
			);
		}

		return array(
			'rows'    => $rows,
			'total'   => (int) $read['total'],
			'page'    => (int) $page,
			'perPage' => self::ANNOUNCEMENTS_PER_PAGE,
		);
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
	 * POST /integrations — { service?: webhook | telegram | slack | discord |
	 * sheets, action: connect | save | disconnect | regenerate }. No service
	 * named means the webhook, phase one's whole roster.
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
			if ( 'share' === $action ) {
				return $this->save_telegram_sharing( $request );
			}
			if ( 'share-test' === $action ) {
				return $this->test_telegram_sharing();
			}
			if ( 'disconnect' === $action ) {
				return $this->disconnect_telegram();
			}
		}
		if ( Services\X::ID === $service ) {
			if ( 'authorize' === $action ) {
				$url = Services\X::begin( (string) $request->get_param( 'client_id' ) );
				if ( is_wp_error( $url ) ) {
					return new \WP_Error( $url->get_error_code(), $url->get_error_message(), array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'authorizeUrl' => $url ) );
			}
			if ( 'share' === $action ) {
				return $this->save_x_sharing( $request );
			}
			if ( 'share-test' === $action ) {
				return $this->test_x_sharing();
			}
			if ( 'disconnect' === $action ) {
				return $this->disconnect_x();
			}
		}
		if ( Services\LinkedIn::ID === $service ) {
			if ( 'authorize' === $action ) {
				$url = Services\LinkedIn::begin( (string) $request->get_param( 'client_id' ), (string) $request->get_param( 'client_secret' ) );
				if ( is_wp_error( $url ) ) {
					return new \WP_Error( $url->get_error_code(), $url->get_error_message(), array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'authorizeUrl' => $url ) );
			}
			if ( 'share' === $action ) {
				return $this->save_linkedin_sharing( $request );
			}
			if ( 'share-test' === $action ) {
				return $this->test_linkedin_sharing();
			}
			if ( 'disconnect' === $action ) {
				return $this->disconnect_linkedin();
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
		if ( Discord::ID === $service ) {
			if ( 'connect' === $action || 'save' === $action ) {
				return $this->save_url_service(
					$request,
					'discord',
					__( 'That doesn’t look like a Discord webhook URL. In your server: Server Settings → Integrations → Webhooks → New Webhook, pick the channel, and paste the https:// URL it gives you.', 'agentimus' )
				);
			}
			if ( 'disconnect' === $action ) {
				return $this->disconnect_url_service( 'discord', Discord::ID, array( Discord::class, 'forget_state' ) );
			}
		}
		if ( Sheets::ID === $service ) {
			if ( 'connect' === $action || 'save' === $action ) {
				return $this->save_sheets( $request, 'connect' === $action );
			}
			if ( 'disconnect' === $action ) {
				return $this->disconnect_sheets();
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
		$url = self::clean_url( $request->get_param( 'url' ) );
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
	 * Save the SHARING use of the bot — the switch and the channel, nothing
	 * else. Enabling with a channel this use has never proved sends one test
	 * post; switching off keeps the channel, so coming back is one click.
	 * There is no credential here to take or forget — that is Services'
	 * business, and the shared store's whole point.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function save_telegram_sharing( $request ) {
		$enabled = ! empty( $request->get_param( 'enabled' ) );

		if ( ! $enabled ) {
			$this->store( array( 'telegram_share_enabled' => false ) );
			return rest_ensure_response( $this->payload() );
		}

		if ( ! Telegram::has_token() ) {
			return new \WP_Error(
				'agentimus_telegram_token',
				__( 'Connect the Telegram bot first — on the Services tab. Announcing uses the same bot.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$channel = Telegram::normalize_chat( (string) $request->get_param( 'channel' ) );
		if ( '' === $channel ) {
			return new \WP_Error(
				'agentimus_telegram_channel',
				__( 'That doesn’t look like a channel. It’s @name for a public channel you run, or the -100… number of a private one.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$stored = Telegram::sharing_config( $this->settings )['channel'];
		if ( $channel !== $stored ) {
			$verdict = Telegram::prove_channel( $channel );
			if ( is_wp_error( $verdict ) ) {
				return new \WP_Error( $verdict->get_error_code(), $verdict->get_error_message(), array( 'status' => 400 ) );
			}
		}

		$this->store(
			array(
				'telegram_share_enabled' => true,
				'telegram_share_channel' => $channel,
			)
		);

		return rest_ensure_response( $this->payload() );
	}

	/**
	 * One labelled test announcement to the sharing channel — proof on demand,
	 * in words a reader who sees it can place. Refused while announcing is
	 * off: a test with nowhere to land would be a lie about readiness.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function test_telegram_sharing() {
		if ( ! Telegram::sharing_active( $this->settings ) ) {
			return new \WP_Error(
				'agentimus_telegram_sharing_off',
				__( 'Turn announcing on first — the test posts to the channel you picked.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$verdict = Telegram::announce( __( 'A test from Agentimus — announcements you schedule will appear here like this.', 'agentimus' ) );
		if ( is_wp_error( $verdict ) ) {
			return new \WP_Error( $verdict->get_error_code(), $verdict->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $this->payload() );
	}

	/* -- X (Twitter) ---------------------------------------------------------- */

	/**
	 * X's redirect lands here. A completed exchange goes home to the
	 * Integrations screen; a refusal states itself plainly with the road
	 * back — an OAuth dead-end page with no exit is a trap.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function x_callback( $request ) {
		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );

		// Everything goes home — a REST 302, never wp_redirect (the server
		// sends the headers; tests dispatch without emitting any). A refusal
		// is WRITTEN ON THE CONNECTION for the panel to show on arrival: an
		// OAuth dead-end page with no exit is a trap.
		if ( '' !== $code && '' !== $state ) {
			$verdict = Services\X::complete( $code, $state );
			if ( is_wp_error( $verdict ) ) {
				$row                  = \Agentimus\Integrations\Connections::read( Services\X::ID );
				$row['connect_error'] = substr( $verdict->get_error_message(), 0, 300 );
				\Agentimus\Integrations\Connections::store( Services\X::ID, $row );
			}
		}

		return new \WP_REST_Response(
			null,
			302,
			array( 'Location' => admin_url( 'admin.php?page=agentimus#integrations' ) )
		);
	}

	/**
	 * Save the SHARING use of X — one switch, no destination: the connected
	 * account IS the destination. Off keeps nothing because there is nothing
	 * to keep.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function save_x_sharing( $request ) {
		$enabled = ! empty( $request->get_param( 'enabled' ) );

		if ( $enabled && ! Services\X::connected() ) {
			return new \WP_Error(
				'agentimus_x_disconnected',
				__( 'Connect X first — on the Services tab. Announcing posts through your own app.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$this->store( array( 'x_share_enabled' => $enabled ) );
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * One labelled test post to the connected account's own timeline — a REAL
	 * public post, said plainly by the button that asks for it. Refused while
	 * announcing is off.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function test_x_sharing() {
		if ( ! Services\X::sharing_active( $this->settings ) ) {
			return new \WP_Error(
				'agentimus_x_sharing_off',
				__( 'Turn announcing on first — the test posts to the connected account.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$verdict = Services\X::announce( __( 'A test from Agentimus — announcements this site schedules will appear here like this.', 'agentimus' ) );
		if ( is_wp_error( $verdict ) ) {
			return new \WP_Error( $verdict->get_error_code(), $verdict->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Disconnect X: revoke the grant AT X (best effort — deleting our copy
	 * alone would leave it alive out there), forget the row, and halt the
	 * sharing use with it.
	 *
	 * @return \WP_REST_Response
	 */
	private function disconnect_x() {
		Services\X::revoke();
		Services\X::forget();
		$this->store( array( 'x_share_enabled' => false ) );
		return rest_ensure_response( $this->payload() );
	}

	/* -- LinkedIn ------------------------------------------------------------- */

	/**
	 * LinkedIn's redirect lands here — the same going-home law as X's: a
	 * REST 302, refusals written on the connection for the panel to show.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function linkedin_callback( $request ) {
		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );

		if ( '' !== $code && '' !== $state ) {
			$verdict = Services\LinkedIn::complete( $code, $state );
			if ( is_wp_error( $verdict ) ) {
				$row                  = \Agentimus\Integrations\Connections::read( Services\LinkedIn::ID );
				$row['connect_error'] = substr( $verdict->get_error_message(), 0, 300 );
				\Agentimus\Integrations\Connections::store( Services\LinkedIn::ID, $row );
			}
		}

		return new \WP_REST_Response(
			null,
			302,
			array( 'Location' => admin_url( 'admin.php?page=agentimus#integrations' ) )
		);
	}

	/**
	 * Save the SHARING use of LinkedIn — one switch; the member's own feed
	 * is the destination.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function save_linkedin_sharing( $request ) {
		$enabled = ! empty( $request->get_param( 'enabled' ) );

		if ( $enabled && ! Services\LinkedIn::connected() ) {
			return new \WP_Error(
				'agentimus_linkedin_disconnected',
				__( 'Connect LinkedIn first — on the Services tab. Announcing posts through your own app.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		if ( $enabled && Services\LinkedIn::expired() ) {
			return new \WP_Error(
				'agentimus_linkedin_expired',
				__( 'LinkedIn’s sixty days ran out — reconnect on the Services tab first.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$this->store( array( 'linkedin_share_enabled' => $enabled ) );
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * One labelled test post to the member's own feed — a REAL public post.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function test_linkedin_sharing() {
		if ( ! Services\LinkedIn::sharing_active( $this->settings ) ) {
			return new \WP_Error(
				'agentimus_linkedin_sharing_off',
				__( 'Turn announcing on first — the test posts to your own feed.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$verdict = Services\LinkedIn::announce( __( 'A test from Agentimus — announcements this site schedules will appear here like this.', 'agentimus' ) );
		if ( is_wp_error( $verdict ) ) {
			return new \WP_Error( $verdict->get_error_code(), $verdict->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Disconnect LinkedIn: forget the grant (LinkedIn offers no revocation
	 * endpoint for member tokens — the sixty-day clock is the reaper) and
	 * halt the sharing use with it.
	 *
	 * @return \WP_REST_Response
	 */
	private function disconnect_linkedin() {
		Services\LinkedIn::forget();
		$this->store( array( 'linkedin_share_enabled' => false ) );
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Disconnect Telegram: switch off, forget the chat, the choices, the
	 * token, the state and its queue rows — and the sharing use with them,
	 * because forgetting the bot is the disconnect for EVERY use it powers.
	 *
	 * @return \WP_REST_Response
	 */
	private function disconnect_telegram() {
		$this->store(
			array(
				'telegram_enabled'       => false,
				'telegram_chat'          => '',
				'telegram_events'        => array(),
				'telegram_tier'          => 'all',
				'telegram_share_enabled' => false,
				'telegram_share_channel' => '',
			)
		);

		Telegram::forget_token();
		Telegram::forget_state();
		$this->after_disconnect( Telegram::ID );

		return rest_ensure_response( $this->payload() );
	}

	/* -- Google Sheets -------------------------------------------------------- */

	/**
	 * Connect (or save) Google Sheets. There is no credential to take — the
	 * Google connection's service-account key is borrowed — so the gate here
	 * is threefold: the ID must look like one, the key must exist (and when it
	 * doesn't, the error points at Settings → Data Sources rather than asking
	 * for a second credential), and a connect proves the road by appending the
	 * header row and one test row. A save re-proves only a CHANGED
	 * spreadsheet; a mere checkbox edit appends nothing at all.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param bool             $connect Whether this is the connect action.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function save_sheets( $request, $connect ) {
		$spreadsheet = Sheets::normalize_spreadsheet_id( (string) $request->get_param( 'spreadsheet' ) );
		if ( '' === $spreadsheet ) {
			return new \WP_Error(
				'agentimus_sheets_id',
				__( 'That doesn’t look like a spreadsheet ID. It’s the long code in the sheet’s URL, between /d/ and /edit — or just paste the sheet’s whole URL.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$events = $this->events_param( $request );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		if ( ! Sheets::has_key() ) {
			return new \WP_Error(
				'agentimus_sheets_nokey',
				__( 'Google Sheets uses the service-account key from your Google connection, and none is stored. Add it under Settings → Data Sources first — the same key Search Console reads with.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		// What must be proved: a fresh connect, or a spreadsheet this
		// connection has never appended to.
		if ( $connect || $spreadsheet !== Sheets::config( $this->settings )['spreadsheet'] ) {
			$verdict = Sheets::verify( $spreadsheet );
			if ( is_wp_error( $verdict ) ) {
				return new \WP_Error( $verdict->get_error_code(), $verdict->get_error_message(), array( 'status' => 400 ) );
			}
		}

		$this->store(
			array(
				'sheets_enabled'     => true,
				'sheets_spreadsheet' => $spreadsheet,
				'sheets_events'      => $events,
			)
		);

		( new Events( $this->settings ) )->sync_findings_schedule();

		return rest_ensure_response( $this->payload() );
	}

	/**
	 * Disconnect Sheets: switch off, forget the spreadsheet and the choices,
	 * drop its state and its queue rows. The rows already appended STAY — the
	 * spreadsheet is the owner's file; only the appending stops.
	 *
	 * @return \WP_REST_Response
	 */
	private function disconnect_sheets() {
		$this->store(
			array(
				'sheets_enabled'     => false,
				'sheets_spreadsheet' => '',
				'sheets_events'      => array(),
			)
		);

		Sheets::forget_state();
		$this->after_disconnect( Sheets::ID );

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
		$url = self::clean_url( $request->get_param( 'url' ) );
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
	 * A pasted URL, or '' when it could never be one. esc_url_raw alone is too
	 * forgiving here — it happily turns "not a url" into http://not%20a%20url,
	 * and a connection that LOOKS made but could never deliver is exactly what
	 * this screen must not manufacture. So the host must also look like a
	 * host: a dot in it, or localhost (the "http:// for a local test" promise
	 * the webhook's error copy makes).
	 *
	 * @param mixed $raw The url request param.
	 * @return string
	 */
	public static function clean_url( $raw ) {
		$url = esc_url_raw( trim( (string) $raw ), array( 'https', 'http' ) );
		if ( '' === $url ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || ( false === strpos( $host, '.' ) && 'localhost' !== $host ) ) {
			return '';
		}
		return $url;
	}

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
		$tg_sharing = Telegram::sharing_config( $this->settings );
		$announce   = ( new Announcements( $this->settings ) )->summary();
		$slack      = Slack::config( $this->settings );
		$discord    = Discord::config( $this->settings );
		$sheets     = Sheets::config( $this->settings );

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
			'discord'  => array(
				'enabled' => $discord['enabled'],
				'url'     => $discord['url'],
				'events'  => $discord['events'],
				'queued'  => $dispatcher->depth_for( Discord::ID ),
				'state'   => Discord::state(),
			),
			'sheets'   => array(
				'enabled'     => $sheets['enabled'],
				'spreadsheet' => $sheets['spreadsheet'],
				'events'      => $sheets['events'],
				// The borrowed credential's presence, and the email the sheet
				// must be shared with — the card's no-Google state and the
				// connect help both speak from these.
				'hasKey'      => Sheets::has_key(),
				'saEmail'     => Sheets::sa_email(),
				'queued'      => $dispatcher->depth_for( Sheets::ID ),
				'state'       => Sheets::state(),
			),
			'events'   => $catalog,
			'plugins'  => $plugins,
			// The SHARING tab's read: each network's use of its shared
			// connection (hasToken is what makes "connected in Services"
			// visibly true here too — one credential, two uses), plus the
			// ledger at a glance for the roll-up line.
			'sharing'  => array(
				'telegram' => array(
					'enabled'    => $tg_sharing['enabled'],
					'channel'    => $tg_sharing['channel'],
					'hasToken'   => Telegram::has_token(),
					'active'     => Telegram::sharing_active( $this->settings ),
					'queued'     => isset( $announce['networks']['telegram'] ) ? $announce['networks']['telegram']['queued'] : 0,
					'lastSentAt' => isset( $announce['networks']['telegram'] ) ? $announce['networks']['telegram']['lastSentAt'] : 0,
				),
				'x'        => array(
					'enabled'      => Services\X::sharing_config( $this->settings )['enabled'],
					'connected'    => Services\X::connected(),
					'active'       => Services\X::sharing_active( $this->settings ),
					'handle'       => Services\X::connection()['handle'],
					'refreshError' => Services\X::connection()['refresh_error'],
					'connectError' => Services\X::connection()['connect_error'],
					'callbackUrl'  => Services\X::callback_url(),
					'hasClientId'  => '' !== Services\X::connection()['client_id'],
					'queued'       => isset( $announce['networks']['x'] ) ? $announce['networks']['x']['queued'] : 0,
					'lastSentAt'   => isset( $announce['networks']['x'] ) ? $announce['networks']['x']['lastSentAt'] : 0,
				),
				'linkedin' => array(
					'enabled'      => Services\LinkedIn::sharing_config( $this->settings )['enabled'],
					'connected'    => Services\LinkedIn::connected(),
					'expired'      => Services\LinkedIn::expired(),
					'expiresAt'    => Services\LinkedIn::connection()['expires_at'],
					'active'       => Services\LinkedIn::sharing_active( $this->settings ),
					'name'         => Services\LinkedIn::connection()['name'],
					'connectError' => Services\LinkedIn::connection()['connect_error'],
					'callbackUrl'  => Services\LinkedIn::callback_url(),
					'hasSecret'    => '' !== Services\LinkedIn::connection()['client_secret'],
					'clientId'     => Services\LinkedIn::connection()['client_id'],
					'queued'       => isset( $announce['networks']['linkedin'] ) ? $announce['networks']['linkedin']['queued'] : 0,
					'lastSentAt'   => isset( $announce['networks']['linkedin'] ) ? $announce['networks']['linkedin']['lastSentAt'] : 0,
				),
				'ledger'   => array(
					'total'    => $announce['total'],
					'queued'   => $announce['queued'],
					'sentWeek' => $announce['sentWeek'],
					'failed'   => $announce['failed'],
				),
			),
		);
	}
}
