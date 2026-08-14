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
		register_rest_route( self::NS, '/google/index/check', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'index_check' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'url' => array( 'type' => 'string', 'required' => true ),
				),
			),
		) );
		register_rest_route( self::NS, '/google/index/opened', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'index_opened' ),
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

		// Ask Search Console again, now. ⚠️ This door was missing entirely: the
		// performance snapshot could only be refreshed by the daily cron or by
		// re-connecting the key, so the card's own control could re-READ the
		// stored numbers and nothing more. Bing has had this since it shipped;
		// Google never did, and nobody noticed because the cron usually made it
		// look fine.
		register_rest_route( self::NS, '/google/refresh', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'refresh' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );

		// GA4 is its own route because it is its own grant: the key may already be
		// stored and working for Search Console while the service account has not
		// been added to any Analytics property at all.
		register_rest_route( self::NS, '/google/analytics', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect_analytics' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					// Validated in the callback, not here: schema validation runs
					// BEFORE the permission callback.
					'property' => array( 'type' => 'string', 'required' => true ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'disconnect_analytics' ),
				'permission_callback' => array( $this, 'can_manage' ),
			),
		) );

		// The fetch-now door: a connected-but-never-polled state must be
		// leavable with one click on the screen that shows it — never by
		// disconnect-and-reconnect gymnastics.
		register_rest_route( self::NS, '/google/analytics/refresh', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'refresh_analytics' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
	}

	/**
	 * POST /google/analytics — verify a GA4 property ID, then store it.
	 *
	 * Verified against the live API before it persists, for the same reason the
	 * Search Console key is: a property the service account cannot read fails on
	 * a cron hours later, where nobody sees the error and the card just stays
	 * empty. The failure that matters is the common one — the key exists, but
	 * nobody has added its email to the property yet — so that answer carries the
	 * email, because granting it IS the fix.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connect_analytics( \WP_REST_Request $request ) {
		$property = Analytics::clean_property( (string) $request->get_param( 'property' ) );
		if ( '' === $property ) {
			return new \WP_Error(
				'agentimus_ga4_property',
				__( 'That doesn’t look like a GA4 property ID. It’s the number in Admin → Property details — not the measurement ID beginning with G-.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$sa_json = $this->google->sa_json();
		if ( '' === $sa_json ) {
			return new \WP_Error(
				'agentimus_ga4_no_key',
				__( 'Connect the Google key first — Analytics uses the same service account as Search Console.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		$auth = Auth::token( $sa_json, Auth::SCOPE_ANALYTICS );
		if ( isset( $auth['error'] ) ) {
			return new \WP_Error( 'agentimus_ga4_auth', (string) $auth['error'], array( 'status' => 400 ) );
		}

		$check = ( new Analytics() )->verify( $auth['token'], $property );
		if ( isset( $check['error'] ) ) {
			$email = (string) $this->google->get( 'sa_email', '' );
			$msg   = (string) $check['error'];
			if ( '' !== $email ) {
				$msg .= ' ' . sprintf(
					/* translators: %s: the service-account email address. */
					__( 'Add %s to the property as a Viewer in GA4 → Admin → Property access management, then try again.', 'agentimus' ),
					$email
				);
			}
			return new \WP_Error( 'agentimus_ga4_denied', $msg, array( 'status' => 400 ) );
		}

		$this->google->set_ga4_property( $property );

		// First numbers now, not tomorrow — the key connect and the Bing
		// connect both extend this courtesy, and a Visitors screen that just
		// verified fine must not sit empty until the next daily cron. Only
		// the GA4 half: the search snapshot wasn't touched by this consent.
		( new Module( $this->google, $this->client ) )->run_analytics_poll();

		return rest_ensure_response( $this->google->public_view() );
	}

	/**
	 * POST /google/analytics/refresh — run the GA4 poll now.
	 *
	 * The Visitors screen's fetch button. A failure comes back as the error the
	 * poll recorded — Google's own words — so the invitation on screen can say
	 * what went wrong instead of quietly staying empty.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refresh_analytics() {
		if ( ! $this->google->analytics_connected() ) {
			return new \WP_Error(
				'agentimus_ga4_off',
				__( 'Analytics isn’t connected — connect it under Settings → Data Sources first.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		( new Module( $this->google, $this->client ) )->run_analytics_poll();

		$error = (string) $this->google->get( 'ga4_error', '' );
		if ( '' !== $error ) {
			return new \WP_Error( 'agentimus_ga4_poll', $error, array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * DELETE /google/analytics — forget the property, keep the key.
	 *
	 * Analytics off must never take Search Console with it: they are two grants
	 * on one key, and an owner turning off one has said nothing about the other.
	 *
	 * @return \WP_REST_Response
	 */
	public function disconnect_analytics() {
		$this->google->set_ga4_property( '' );
		delete_option( Module::GA4_OPTION );
		return rest_ensure_response( $this->google->public_view() );
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
	 * POST /google/refresh — poll Search Console now, and answer with the
	 * connection's own state so the caller can see how it went.
	 *
	 * The snapshot itself is read back through /search/performance, the way the
	 * card already reads it; this route's job is only to make the numbers fresh
	 * first. Its answer carries lastError, so a poll that failed says so instead
	 * of leaving yesterday's numbers looking like today's.
	 *
	 * ⚠️ Slower than a read — it is real API calls, and on a big property the
	 * walk pages through Search Console. That is the honest cost of asking
	 * again, and the button that calls it says so.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function refresh() {
		if ( ! $this->google->connected() ) {
			return new \WP_Error(
				'agentimus_google_off',
				__( 'Connect Google Search Console first.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		( new Module( $this->google, $this->client ) )->poll_now();

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
		( new Module( $this->google, $this->client ) )->poll_now();

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
	 * POST /google/index/check?url= — inspect ONE url live, right now.
	 *
	 * The lookup above answers from storage and costs nothing; this asks Google
	 * and spends one of the day's 2,000 inspections, so it only ever runs on an
	 * explicit click. The fresh answer is stored where that URL already lives,
	 * so the card can't end up showing yesterday's verdict beside today's.
	 *
	 * Every outcome is named rather than flattened into a failure: a foreign URL,
	 * a spent daily budget and a dead connection are three different things, and
	 * only one of them is about the page.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function index_check( \WP_REST_Request $request ) {
		if ( ! $this->google->connected() ) {
			return new \WP_Error( 'agentimus_google_off', __( 'Google Search Console is not connected.', 'agentimus' ), array( 'status' => 400 ) );
		}

		$out = ( new Module( $this->google, $this->client ) )->inspect_one( (string) $request->get_param( 'url' ) );

		if ( 'foreign' === $out['status'] ) {
			return new \WP_Error(
				'agentimus_google_foreign_url',
				__( 'That address isn’t on this site, so Google can’t be asked about it here.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		if ( 'quota' === $out['status'] ) {
			return new \WP_Error(
				'agentimus_google_quota',
				__( 'Google’s daily inspection budget is spent — this page keeps its last answer until tomorrow.', 'agentimus' ),
				array( 'status' => 429 )
			);
		}
		if ( 'error' === $out['status'] ) {
			return new \WP_Error( 'agentimus_google_check_failed', $out['error'], array( 'status' => 502 ) );
		}

		// The whole view rides back, not just the row: one fresh answer moves the
		// site counts and can empty a problem group, and a card left showing its
		// old totals beside a new verdict is the same lie in a different lane.
		return rest_ensure_response( array(
			'row'  => $out['row'],
			'view' => Index::view( $this->google ),
		) );
	}

	/**
	 * POST /google/index/opened — remember that the owner opened this row in
	 * Search Console.
	 *
	 * Writes nothing to Google and asks it nothing. Google keeps no memory of
	 * "indexing requested" and exposes no pending state through any API, so the
	 * only honest record is ours: what the owner did, and when. The row then
	 * says "console opened {date}" — a true statement about a click — instead of
	 * "indexing requested", which nobody here is in a position to claim.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function index_opened( \WP_REST_Request $request ) {
		$at = Index::mark_opened( (string) $request->get_param( 'url' ) );
		return rest_ensure_response( array( 'openedAt' => $at ) );
	}

	/**
	 * DELETE /google — forget the key; stored aggregates stay (the owner's history).
	 *
	 * @return \WP_REST_Response
	 */
	public function disconnect() {
		$this->google->disconnect();
		// The progress ledger describes THIS engine's reports. Kept across a
		// disconnect, a reconnection weeks later would compare today's numbers
		// to a world that no longer exists and announce "improvements" nobody
		// was waiting for.
		\Agentimus\Search\Progress::forget( 'google' );
		return rest_ensure_response( $this->google->public_view() );
	}
}
