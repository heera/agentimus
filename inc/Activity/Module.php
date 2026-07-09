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
use Agentimus\BotVerifier;

defined( 'ABSPATH' ) || exit;

final class Module {

	const CRON = 'agentimus_prune_activity';

	/** Site-wide flood cap for the public referral beacon: at most this many hits accepted
	 *  per window (seconds), so a scripted same-origin flood can't inflate a page's count
	 *  without bound. Generous; filter `agentimus_referral_beacon_rate` (0 disables it). */
	const BEACON_RATE_MAX    = 600;
	const BEACON_RATE_WINDOW = 60;

	/** Per-admin "Re-check" rate cap (per minute): a re-check makes a live DNS lookup, so a
	 *  click-storm shouldn't be able to hammer the resolver. Generous for real use. */
	const REVERIFY_RATE_MAX = 20;

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
		// The opt-in flagged-IP table installs regardless of the setting (so it's ready the
		// moment the owner turns capture on); it simply stays empty until then.
		FlaggedIps::maybe_install();
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
		add_action( self::CRON, array( FlaggedIps::class, 'prune' ) );
		// Opting back out of IP storage purges what was kept (Settings::update fires this).
		add_action( 'agentimus_flagged_ips_purge', array( FlaggedIps::class, 'clear' ) );
		// Suggest privacy-policy text for the site owner — but only while the opt-in
		// feature is actually on, so a default install adds no misleading "we store IPs" copy.
		add_action( 'admin_init', array( $this, 'privacy_declaration' ) );
	}

	/**
	 * Feed WordPress's Privacy tool suggested policy text — ONLY when the owner has enabled
	 * flagged-IP capture, so it appears in Settings → Privacy exactly when it's true.
	 */
	public function privacy_declaration() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) || ! $this->settings->enabled( 'store_flagged_ips' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Agentimus',
			wp_kses_post(
				'<p>' . __( 'When "Store IP addresses for flagged clients" is enabled, Agentimus records the IP address of visitors it flags as a spoofed/impersonating crawler, so you can block them. IPs are stored only for these flagged clients, kept for a short retention period, and never sent off your server.', 'agentimus' ) . '</p>'
			)
		);
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
			'/activity/log',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'log' ),
				'args'                => array(
					'from'     => array( 'type' => 'string' ),
					'to'       => array( 'type' => 'string' ),
					'agent'    => array( 'type' => 'string' ),
					'endpoint' => array( 'type' => 'string' ),
					'network'  => array( 'type' => 'string' ),
					// 0 = unchecked/inconclusive, 1 = verified, 2 = spoofed.
					'verdict'  => array( 'type' => 'integer' ),
					// Prefix match only — see Repository::log().
					'ua'       => array( 'type' => 'string' ),
					'before'   => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
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

		register_rest_route(
			'agentimus/v1',
			'/activity/dismiss',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'dismiss' ),
				'args'                => array(
					'ua'   => array( 'type' => 'string' ),
					'hits' => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'agentimus/v1',
			'/activity/reverify',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'reverify' ),
				'args'                => array(
					'ua' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			'agentimus/v1',
			'/activity/check-ip',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'check_ip' ),
				'args'                => array(
					'ip' => array( 'type' => 'string' ),
				),
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
	 * REST: POST /activity/dismiss — the review queue's "Ignore". Files a "not now" for
	 * the client so it drops out of the list WITHOUT being allow- or block-listed (no
	 * policy change), remembering the volume the owner saw so it can reappear if the
	 * client later changes materially. Returns the refreshed stats so the row leaves in
	 * place. No settings are touched, so — unlike block/allow — the response is stats
	 * only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dismiss( \WP_REST_Request $request ) {
		Repository::dismiss(
			(string) $request->get_param( 'ua' ),
			(int) $request->get_param( 'hits' )
		);
		return rest_ensure_response( Repository::stats( $this->settings ) );
	}

	/**
	 * REST: POST /activity/reverify — the review panel's admin "Re-check". On demand, run a
	 * FRESH forward-confirmed reverse-DNS lookup for a flagged client and layer the result over
	 * its stored (ingest-time) verdict, so an impostor can be re-confirmed — or cleared — without
	 * waiting for its next visit.
	 *
	 * Deliberately runs REGARDLESS of the always-on "Verify search engines" setting: this single,
	 * capability-gated, admin-initiated lookup is its own consent (unlike making outbound DNS on
	 * every public bot hit), and it bypasses the serve-path lookup budget for the same reason.
	 * A loose per-user rate cap keeps a click-storm from hammering the resolver.
	 *
	 * Checks the addresses already retained for this client (needs "Store IPs for flagged
	 * clients"); the review UI only offers the button when there's an address to check. With
	 * none, there's nothing to do — answered honestly as 'no-ip', never fabricated. Ad-hoc
	 * checks of an arbitrary address live in {@see check_ip()}.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reverify( \WP_REST_Request $request ) {
		// Loose per-user throttle: a re-check makes a live DNS lookup, so bound the clicks.
		if ( ! $this->spend_dns_budget() ) {
			return new \WP_Error(
				'agentimus_reverify_throttled',
				__( 'Too many re-checks in the last minute — pause a moment and try again.', 'agentimus' ),
				array( 'status' => 429 )
			);
		}

		$ua    = (string) $request->get_param( 'ua' );
		$ua_lc = strtolower( $ua );
		if ( '' === BotVerifier::claimed_engine( $ua_lc ) ) {
			return new \WP_Error(
				'agentimus_not_verifiable',
				__( 'This client doesn’t claim a search engine we can reverse-DNS verify, so there’s nothing to re-check.', 'agentimus' ),
				array( 'status' => 422 )
			);
		}

		// The addresses retained for this client (keyed by its review identity).
		$key  = Repository::dismiss_key( $ua );
		$rows = FlaggedIps::for_keys( array( $key ) );
		$ips  = array();
		foreach ( isset( $rows[ $key ] ) ? $rows[ $key ] : array() as $r ) {
			$ips[] = (string) $r['ip'];
		}

		if ( empty( $ips ) ) {
			return rest_ensure_response(
				array(
					'status'   => 'no-ip',
					'message'  => __( 'No address is on record for this client to re-check.', 'agentimus' ),
					'activity' => Repository::stats( $this->settings ),
				)
			);
		}

		// Fresh FCrDNS for each address; fold to the WORST (spoofed > verified > undetermined),
		// mirroring how the ingest verdict is aggregated across a client's hits.
		$verdict = 0;
		$per_ip  = array();
		foreach ( $ips as $ip ) {
			$r = BotVerifier::reverify_engine( $ua_lc, $ip ); // true | false | null
			$v = ( true === $r ) ? 1 : ( ( false === $r ) ? 2 : 0 );
			if ( $v > $verdict ) {
				$verdict = $v;
			}
			$per_ip[] = array( 'ip' => $ip, 'verdict' => $v );
		}

		// Layer the result onto this client's review identity (never rewrites the hit log).
		// Only a CONCLUSIVE result is persisted: an inconclusive re-check (0 — resolver gave no
		// usable answer) is fail-open — it must not override the client's standing verdict.
		if ( $verdict > 0 ) {
			Repository::record_reverify( $ua, $verdict );
		}

		return rest_ensure_response(
			array(
				'status'   => 'checked',
				'verdict'  => $verdict,
				'perIp'    => $per_ip,
				'activity' => Repository::stats( $this->settings ),
			)
		);
	}

	/**
	 * REST: POST /activity/check-ip — the Settings "Check an IP" tool. An engine-agnostic,
	 * read-only reverse-DNS identity lookup: given any IP, report which verifiable search engine
	 * (if any) it actually belongs to. Touches no stored data and no review row — it just
	 * identifies. Admin-gated, shares the same per-user DNS throttle as the re-check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error {@see BotVerifier::identify_ip()} on success.
	 */
	public function check_ip( \WP_REST_Request $request ) {
		if ( ! $this->spend_dns_budget() ) {
			return new \WP_Error(
				'agentimus_reverify_throttled',
				__( 'Too many lookups in the last minute — pause a moment and try again.', 'agentimus' ),
				array( 'status' => 429 )
			);
		}
		$ip = trim( (string) $request->get_param( 'ip' ) );
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new \WP_Error(
				'agentimus_bad_ip',
				__( 'That doesn’t look like a valid IP address.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}
		return rest_ensure_response( BotVerifier::identify_ip( $ip ) );
	}

	/**
	 * Count one live-DNS admin lookup against a per-user, per-minute cap shared by the re-check
	 * and the IP tool — a click-storm shouldn't be able to hammer the resolver. False once spent.
	 *
	 * @return bool True while the current user is within the cap.
	 */
	private function spend_dns_budget() {
		$key   = 'agentimus_reverify_' . get_current_user_id();
		$spent = (int) get_transient( $key );
		if ( $spent >= self::REVERIFY_RATE_MAX ) {
			return false;
		}
		set_transient( $key, $spent + 1, MINUTE_IN_SECONDS );
		return true;
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
	 * GET /activity/log — the filtered, keyset-paged request log.
	 *
	 * Dates are validated with the same strict pattern /activity/day uses; a malformed one
	 * is a 400 rather than a silently-ignored filter, so a typo can't quietly return the
	 * whole window and read as "no matches for that day".
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function log( \WP_REST_Request $request ) {
		$args = array();

		foreach ( array( 'from', 'to' ) as $key ) {
			$date = (string) $request->get_param( $key );
			if ( '' === $date ) {
				continue;
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return new \WP_Error(
					'agentimus_bad_date',
					__( 'Dates must look like YYYY-MM-DD.', 'agentimus' ),
					array( 'status' => 400 )
				);
			}
			$args[ $key ] = $date;
		}

		if ( isset( $args['from'], $args['to'] ) && $args['from'] > $args['to'] ) {
			return new \WP_Error(
				'agentimus_bad_range',
				__( 'The start date must not be after the end date.', 'agentimus' ),
				array( 'status' => 400 )
			);
		}

		foreach ( array( 'agent', 'endpoint', 'network', 'ua' ) as $key ) {
			$value = sanitize_text_field( (string) $request->get_param( $key ) );
			if ( '' !== $value ) {
				// The columns are varchar(64)/(128)/(255); a longer needle can never match,
				// so clamp rather than hand the database a pointless comparison.
				$args[ $key ] = substr( $value, 0, 255 );
			}
		}

		$verdict = $request->get_param( 'verdict' );
		if ( null !== $verdict && '' !== $verdict ) {
			$verdict = (int) $verdict;
			if ( $verdict < 0 || $verdict > 2 ) {
				return new \WP_Error(
					'agentimus_bad_verdict',
					__( 'Verdict must be 0 (unchecked), 1 (verified) or 2 (spoofed).', 'agentimus' ),
					array( 'status' => 400 )
				);
			}
			$args['verdict'] = $verdict;
		}

		foreach ( array( 'before', 'per_page' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$args[ $key ] = (int) $value; // Repository clamps both.
			}
		}

		return rest_ensure_response( Repository::log( $args ) );
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
