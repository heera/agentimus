<?php
/**
 * Google REST controller — connect / status / disconnect for the Search
 * Console data source.
 *
 * Everything is manage_options-gated. The service-account key goes IN through
 * the connect call and never comes back out in any response; connect verifies
 * end-to-end (token minted, properties listed, this site matched) before
 * anything is stored, and answers with the words to fix whatever failed.
 *
 * @package Agentimus
 */

namespace Agentimus\Google;

defined( 'ABSPATH' ) || exit;

final class Rest {

	/** @var string REST namespace. */
	const NS = 'agentimus/v1';

	/** @var Settings */
	private $google;

	/** @var Client */
	private $client;

	/**
	 * @param Settings    $google The Google connection store.
	 * @param Client|null $client Injectable for tests.
	 */
	public function __construct( Settings $google, Client $client = null ) {
		$this->google = $google;
		$this->client = $client ? $client : new Client();
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
		register_rest_route( self::NS, '/google/index', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'index_refresh' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		) );
		register_rest_route( self::NS, '/google/index/lookup', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index_lookup' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'url' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );
		register_rest_route( self::NS, '/google/index/problems', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index_problems' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					// No enum here: schema validation runs BEFORE the permission
					// callback. The state's value check lives in the callback.
					'state' => array( 'type' => 'string', 'required' => true ),
					'page'  => array( 'type' => 'integer', 'required' => false ),
				),
			),
		) );
		register_rest_route( self::NS, '/google', array(
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
					// No format validation: schema validation runs BEFORE the
					// permission callback. Value checks live in the callback.
					'key_json' => array( 'type' => 'string', 'required' => true ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		) );
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
	 * GET /google — the connection state, never the key.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		return rest_ensure_response( $this->google->public_view() );
	}

	/**
	 * POST /google — verify a pasted key end-to-end, then store it.
	 *
	 * The whole chain must work before anything persists: parse the key, mint
	 * a token, list the account's properties, match this site. Each failure
	 * answers with what to do about it — and the no-match answer includes the
	 * service-account email, because granting that email access IS the fix.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect( \WP_REST_Request $request ) {
		$key_json = (string) $request->get_param( 'key_json' );

		$parsed = Auth::parse_key( $key_json );
		if ( isset( $parsed['error'] ) ) {
			return new \WP_Error( 'agentimus_google_key', $parsed['error'], array( 'status' => 400 ) );
		}

		Auth::forget(); // A fresh key must never ride a previous key's cached token.
		$auth = Auth::token( $key_json );
		if ( isset( $auth['error'] ) ) {
			return new \WP_Error( 'agentimus_google_token', $auth['error'], array( 'status' => 400 ) );
		}

		$sites = $this->client->sites( $auth['token'] );
		if ( isset( $sites['error'] ) ) {
			return new \WP_Error( 'agentimus_google_sites', $sites['error'], array( 'status' => 400 ) );
		}

		$property = Module::match_property( (array) $sites['sites'] );
		if ( '' === $property ) {
			return new \WP_Error(
				'agentimus_google_no_property',
				sprintf(
					/* translators: %s: the service-account email address. */
					__( 'The key works, but this account can’t see a Search Console property for this site yet. In Search Console → Settings → Users and permissions, add %s as a user (Full or Restricted both work), then connect again.', 'agentimus' ),
					$parsed['email']
				),
				array( 'status' => 400, 'sa_email' => $parsed['email'] )
			);
		}

		$this->google->connect( $key_json, $parsed['email'], $property );

		// First numbers now, not tomorrow — same courtesy as the Bing connect.
		( new Module( $this->google, $this->client ) )->run_poll();

		// The index sweep is ~20 sequential inspections — too slow to sit
		// inside this request. A single cron event moments from now gets the
		// card its first answers without making the connect click hang.
		if ( ! wp_next_scheduled( Module::CRON ) || wp_next_scheduled( Module::CRON ) > time() + MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( time() + 15, Module::CRON );
		}

		return rest_ensure_response( $this->google->public_view() );
	}

	/**
	 * GET /google/index — the stored index-watch answers.
	 *
	 * @return \WP_REST_Response
	 */
	public function index_status() {
		return rest_ensure_response( Index::view( $this->google ) );
	}

	/**
	 * POST /google/index — inspect the watchlist now and answer with the result.
	 * The owner clicked "Check now": ~20 sequential API calls, a few seconds.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function index_refresh() {
		if ( ! $this->google->connected() ) {
			return new \WP_Error( 'agentimus_google_off', __( 'Google Search Console is not connected.', 'agentimus' ), array( 'status' => 400 ) );
		}
		( new Module( $this->google, $this->client ) )->run_index_sweep();
		return rest_ensure_response( Index::view( $this->google ) );
	}

	/**
	 * GET /google/index/problems?state=&page= — one page of one state's
	 * problem rows, from stored data alone. The card ships every group's true
	 * count; this serves the rows when a group is opened or its page turns.
	 * No live call, no quota.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function index_problems( \WP_REST_Request $request ) {
		if ( ! $this->google->connected() ) {
			return new \WP_Error( 'agentimus_google_off', __( 'Google Search Console is not connected.', 'agentimus' ), array( 'status' => 400 ) );
		}
		$state = sanitize_key( (string) $request->get_param( 'state' ) );
		if ( ! in_array( $state, Index::state_keys(), true ) ) {
			return new \WP_Error( 'agentimus_google_bad_state', __( 'Unknown problem state.', 'agentimus' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( Index::problems_page( $state, (int) $request->get_param( 'page' ) ) );
	}

	/**
	 * GET /google/index/lookup?url= — one page's STORED answer, from the rows
	 * and the rotation's coverage map. No live call, no quota: the honest
	 * answer for "is this specific page in?" including the healthy pages that
	 * never render as rows. `cycleDays` rides along so "not checked yet" can
	 * name when the rotation reaches it.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function index_lookup( \WP_REST_Request $request ) {
		$out              = Index::lookup( (string) $request->get_param( 'url' ) );
		$view             = Index::view( $this->google );
		$out['cycleDays'] = (int) $view['site']['cycleDays'];
		return rest_ensure_response( $out );
	}

	/**
	 * DELETE /google — forget the key; stored aggregates stay (the owner's history).
	 *
	 * @return \WP_REST_Response
	 */
	public function disconnect() {
		$this->google->disconnect();
		return rest_ensure_response( $this->google->public_view() );
	}
}
