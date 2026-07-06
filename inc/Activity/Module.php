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

	/** Site-wide flood cap for the public referral beacon: at most this many hits accepted
	 *  per window (seconds), so a scripted same-origin flood can't inflate a page's count
	 *  without bound. Generous; filter `agentimus_referral_beacon_rate` (0 disables it). */
	const BEACON_RATE_MAX    = 600;
	const BEACON_RATE_WINDOW = 60;

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
		Referrals::maybe_install();
		// Ensure the prune cron exists on THIS site. Scheduling only at activation
		// misses network sub-sites, because a network activation does not run the
		// activation hook per site; this self-heals them (and any site created
		// before this safeguard existed). Cheap: schedule() is a single
		// wp_next_scheduled read once the event is already present.
		self::schedule();
		// Count human visits referred from AI assistants (the mirror of the bot log).
		add_action( 'template_redirect', array( Referrals::class, 'maybe_record' ), 30 );
		// Opt-in "CDN mode": when on, the server-side recorder above stands down and a tiny
		// front-end beacon counts instead, so referrals survive a full-page cache. A default
		// install adds no front-end script at all.
		if ( Referrals::beacon_enabled() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_beacon' ) );
		}
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( self::CRON, array( Repository::class, 'prune' ) );
		add_action( self::CRON, array( Referrals::class, 'prune' ) );
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
			'/activity/day',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => function ( \WP_REST_Request $request ) {
					$date = (string) $request->get_param( 'date' );
					if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
						return new \WP_Error(
							'agentimus_bad_date',
							__( 'A valid date (YYYY-MM-DD) is required.', 'agentimus' ),
							array( 'status' => 400 )
						);
					}
					return rest_ensure_response( Repository::day_requests( $date ) );
				},
				'args'                => array(
					'date' => array(
						'type'     => 'string',
						'required' => true,
					),
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

		register_rest_route(
			'agentimus/v1',
			'/activity/allow',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'allow' ),
				'args'                => array( 'ua' => array( 'type' => 'string' ) ),
			)
		);

		// Public, unauthenticated: the front-end AI-referral beacon ("CDN mode"). Same-
		// origin + rate-limited; the server re-derives the source, stores no IP/UA, and
		// always answers 204 (never revealing whether a hit counted, so a spoofer has no
		// signal to tune against). Registered regardless of the setting so a stale cached
		// page's beacon still resolves cleanly; the handler no-ops when the mode is off.
		register_rest_route(
			'agentimus/v1',
			'/ai-hit',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'ai_hit' ),
			)
		);
	}

	/**
	 * REST: POST /ai-hit — the public front-end AI-referral beacon ("CDN mode"). A
	 * fire-and-forget counter: it ALWAYS answers 204 (the caller is navigator.sendBeacon,
	 * which ignores the body, and a uniform reply denies a spoofer any success/fail
	 * oracle). Guards, in order: same-origin (the beacon fires from our own pages) and a
	 * coarse, IP-free site-wide rate cap. The source is re-derived server-side by
	 * {@see Referrals::record_from_client()} — a client-sent label is never trusted — and
	 * nothing identifying is stored.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response Always 204.
	 */
	public function ai_hit( \WP_REST_Request $request ) {
		$noop = new \WP_REST_Response( null, 204 );

		if ( ! $this->beacon_same_origin( $request ) || ! $this->beacon_within_rate() ) {
			return $noop;
		}

		$body = (array) $request->get_json_params();
		Referrals::record_from_client(
			isset( $body['ref'] ) ? (string) $body['ref'] : '',
			isset( $body['utm'] ) ? (string) $body['utm'] : '',
			isset( $body['path'] ) ? (string) $body['path'] : ''
		);
		return $noop;
	}

	/**
	 * Whether a beacon POST originated from this very site — the beacon only ever fires
	 * from our own pages, so a cross-site (or origin-less) request is not a real arrival.
	 * Prefers the Origin header, falling back to Referer for the browsers that omit Origin
	 * on a same-origin beacon.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function beacon_same_origin( \WP_REST_Request $request ) {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}
		$req_host  = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return '' !== $req_host && $req_host === $site_host;
	}

	/**
	 * A coarse, PII-free flood cap for the public beacon: bound how many hits the whole
	 * site accepts per window with a single transient counter (no IP is read or stored).
	 * Under normal traffic it never trips; only a flood is clipped — where dropping is the
	 * goal. Site-wide by design; a very high-traffic site can raise it via the filter.
	 *
	 * @return bool True if this hit is within the cap.
	 */
	private function beacon_within_rate() {
		$max = (int) apply_filters( 'agentimus_referral_beacon_rate', self::BEACON_RATE_MAX );
		if ( $max < 1 ) {
			return true; // Cap disabled.
		}
		$key   = 'agentimus_beacon_rate';
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, self::BEACON_RATE_WINDOW );
		return true;
	}

	/**
	 * Enqueue the tiny front-end beacon (footer, non-blocking) and hand it the endpoint
	 * URL. Only wired when CDN mode is on, so a default install adds no front-end script.
	 */
	public function enqueue_beacon() {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_script(
			'agentimus-referral',
			AGENTIMUS_URL . 'assets/referral-beacon.js',
			array(),
			AGENTIMUS_VERSION,
			true
		);
		wp_localize_script(
			'agentimus-referral',
			'AgentimusReferral',
			array( 'endpoint' => esc_url_raw( rest_url( 'agentimus/v1/ai-hit' ) ) )
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
	 * REST: POST /activity/allow — the panel's "Allow" / trust action. Adds the
	 * derived token to the owner's allowlist (never blocked, never flagged again),
	 * then returns the refreshed payload so the panel + Settings update in place.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function allow( \WP_REST_Request $request ) {
		$ua    = (string) $request->get_param( 'ua' );
		$token = Guard::suggest_token( $ua );
		if ( '' === $token ) {
			return new \WP_Error(
				'agentimus_no_safe_token',
				__( 'No safe allow rule could be derived for this client.', 'agentimus' ),
				array( 'status' => 422 )
			);
		}
		$this->settings->allow_agent( $token );
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
