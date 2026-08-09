<?php
/**
 * Cloudflare REST controller — connect / status / disconnect on one route,
 * plus the summary read that feeds the Request Log screen's edge cards.
 *
 * Everything is manage_options-gated: edge numbers are the owner's operational
 * data, not something a subscriber (or the public) can read. The token goes IN
 * through the connect call and never comes back out in any response.
 *
 * @package Agentimus
 */

namespace Agentimus\Cloudflare;

defined( 'ABSPATH' ) || exit;

final class Rest {

	/** @var string REST namespace. */
	const NS = 'agentimus/v1';

	/** @var int Longest summary window the UI may ask for (days). */
	const MAX_DAYS = 30;

	/** @var Settings */
	private $cloudflare;

	/** @var \Agentimus\Settings Core settings — the declared policy the conflicts compare against. */
	private $core;

	/** @var Client */
	private $client;

	/**
	 * @param Settings           $cloudflare The Cloudflare connection store.
	 * @param \Agentimus\Settings $core       The core settings instance.
	 * @param Client|null        $client     Injectable for tests.
	 */
	public function __construct( Settings $cloudflare, \Agentimus\Settings $core, Client $client = null ) {
		$this->cloudflare = $cloudflare;
		$this->core       = $core;
		$this->client     = $client ? $client : new Client();
	}

	/**
	 * Hooks only.
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
		register_rest_route( self::NS, '/cloudflare', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					// No format/enum validation here: schema validation runs BEFORE the
					// permission callback, so it would answer 400 to an anonymous probe
					// before the gate gets to say 403. Value checks live in the callback.
					'token' => array( 'type' => 'string', 'required' => true ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		) );

		register_rest_route( self::NS, '/cloudflare/summary', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'summary' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'required' => false ),
			),
		) );

		register_rest_route( self::NS, '/cloudflare/refresh', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'refresh' ),
			'permission_callback' => array( $this, 'can_manage' ),
			'args'                => array(
				'days' => array( 'type' => 'integer', 'required' => false ),
			),
		) );

		register_rest_route( self::NS, '/cloudflare/purge', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'purge' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );

		register_rest_route( self::NS, '/cloudflare/dismiss', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					// No enum: schema validation runs BEFORE the permission callback.
					'id' => array( 'type' => 'string', 'required' => true ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'undismiss' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );
	}

	/**
	 * Hide one conflict pin. It stays hidden while that situation persists and
	 * returns only if the conflict ends and later starts again.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array
	 */
	public function dismiss( $request ) {
		$this->cloudflare->dismiss( (string) $request->get_param( 'id' ) );
		return array( 'ok' => true );
	}

	/**
	 * Bring one hidden conflict back — its pin returns to the Request Log
	 * screen immediately.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array
	 */
	public function undismiss( $request ) {
		$this->cloudflare->undismiss( (string) $request->get_param( 'id' ) );
		return array( 'ok' => true );
	}

	/**
	 * Gate: site owners only.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The connection state, token excluded.
	 *
	 * @return array
	 */
	public function status() {
		return $this->cloudflare->public_view();
	}

	/**
	 * Verify the pasted token, find this site's zone, store the connection,
	 * then run a first poll inline so the panel has numbers right away instead
	 * of "come back in an hour".
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array|\WP_Error
	 */
	public function connect( $request ) {
		$token = trim( (string) $request->get_param( 'token' ) );
		if ( '' === $token ) {
			return new \WP_Error( 'agentimus_cf_token', __( 'Paste a Cloudflare API token first.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$verified = $this->client->verify_token( $token );
		if ( isset( $verified['error'] ) ) {
			return new \WP_Error( 'agentimus_cf_verify', (string) $verified['error'], array( 'status' => 400 ) );
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$zone = $this->client->find_zone( $token, $host );
		if ( isset( $zone['error'] ) ) {
			return new \WP_Error( 'agentimus_cf_zone', (string) $zone['error'], array( 'status' => 400 ) );
		}

		$this->cloudflare->connect( $token, $zone['id'], $zone['name'] );
		Module::schedule();

		// First numbers now, not next hour. A failure here is recorded on the
		// connection (last_error) rather than failing the connect — the token
		// and zone are already proven good.
		( new Module( $this->cloudflare, $this->client ) )->poll_now();

		return $this->status();
	}

	/**
	 * Forget the token and stand the cron down. Stored aggregates are kept —
	 * they're the owner's history.
	 *
	 * @return array
	 */
	public function disconnect() {
		$this->cloudflare->disconnect();
		Module::unschedule();
		return $this->status();
	}

	/**
	 * The edge summary the Request Log screen renders: totals, per-crawler
	 * rows, per-company rollups, and the conflicts.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array
	 */
	public function summary( $request ) {
		$days = (int) $request->get_param( 'days' );
		$days = ( $days >= 1 && $days <= self::MAX_DAYS ) ? $days : 7;

		return Summary::build( $this->cloudflare, $this->core, $days );
	}

	/**
	 * Purge everything Cloudflare holds for the zone — the edge panel's button.
	 * Safe for data (the cache rebuilds as visitors return); the outcome is
	 * recorded on the connection either way, so the answer carries both the
	 * ok flag for the button's own line and the refreshed purge bookkeeping
	 * for the rail.
	 *
	 * @return array|\WP_Error
	 */
	public function purge() {
		if ( ! $this->cloudflare->connected() ) {
			return new \WP_Error( 'agentimus_cf_off', __( 'Connect Cloudflare first.', 'agentimus' ), array( 'status' => 400 ) );
		}
		$out = Purge::purge_all( $this->cloudflare, $this->client );
		return array_merge( $this->cloudflare->public_view(), array( 'ok' => ! empty( $out['ok'] ) ) );
	}

	/**
	 * Fetch now: run one poll inline — the same read the hourly cron does —
	 * then hand back the fresh summary so the panel updates in one round trip.
	 * A poll failure is recorded on the connection (last_error, shown in the
	 * panel's rail) rather than failing the request: the panel still has its
	 * last good numbers to show.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array|\WP_Error
	 */
	public function refresh( $request ) {
		if ( ! $this->cloudflare->connected() ) {
			return new \WP_Error( 'agentimus_cf_off', __( 'Connect Cloudflare first.', 'agentimus' ), array( 'status' => 400 ) );
		}

		( new Module( $this->cloudflare, $this->client ) )->poll_now();

		return $this->summary( $request );
	}
}
