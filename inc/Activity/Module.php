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
use Agentimus\BotRanges;
use Agentimus\VerifierRegistry;

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
		// The unrecognised-referrer diagnostic installs regardless of its setting (so it's
		// ready the moment the owner switches it on); it simply stays empty until then.
		UnknownSources::maybe_install();
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
		// The machine half of the same moment: Referrals counts the humans an AI sent,
		// this records the machines that read the page themselves. One hook, one
		// priority, two mutually exclusive tests — see PageViews::is_machine().
		add_action( 'template_redirect', array( PageViews::class, 'maybe_record' ), 30 );
		// Opt-in "CDN mode": when on, the server-side recorder above stands down and a tiny
		// front-end beacon counts instead, so referrals survive a full-page cache. A default
		// install adds no front-end script at all.
		if ( Referrals::beacon_enabled() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_beacon' ) );
		}
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( self::CRON, array( Repository::class, 'prune' ) );
		add_action( self::CRON, array( Referrals::class, 'prune' ) );
		add_action( self::CRON, array( UnknownSources::class, 'prune' ) );
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
						return rest_ensure_response( $this->stats_payload() );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => array( $this, 'can_manage' ),
					'callback'            => function () {
						Repository::clear();
						return rest_ensure_response( $this->stats_payload() );
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

		// The AI-traffic screen's own report: an arbitrary day range (bounded by retention,
		// not by the dashboard's 30-day window) optionally narrowed to one assistant and/or a
		// landing-path prefix. Separate from /activity because the dashboard live-polls that
		// one and must not carry a filtered view of somebody else's screen.
		register_rest_route(
			'agentimus/v1',
			'/activity/ai-traffic',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => function ( \WP_REST_Request $request ) {
					return rest_ensure_response(
						Referrals::report(
							array(
								'from'   => (string) $request->get_param( 'from' ),
								'to'     => (string) $request->get_param( 'to' ),
								'source' => (string) $request->get_param( 'source' ),
								'path'   => (string) $request->get_param( 'path' ),
							)
						)
					);
				},
			)
		);

		// The assistants worth offering in that filter — a dropdown, so nobody has to type
		// "DuckDuckGo AI" exactly.
		register_rest_route(
			'agentimus/v1',
			'/activity/ai-traffic/facets',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => function () {
					return rest_ensure_response( array( 'sources' => Referrals::facets() ) );
				},
			)
		);

		// The per-day drill-down, fetched when a day is opened. The day TOTALS ride along with
		// the report; these rows do not, because a busy day can hold thousands of
		// (source → page) pairings and only twelve are shown until you ask for the rest.
		register_rest_route(
			'agentimus/v1',
			'/activity/ai-day',
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
					return rest_ensure_response(
						Referrals::day_rows(
							$date,
							array(
								'source' => (string) $request->get_param( 'source' ),
								'path'   => (string) $request->get_param( 'path' ),
								'full'   => (bool) $request->get_param( 'full' ),
							)
						)
					);
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
					// 0 = unchecked/inconclusive, 1 = verified, 2 = spoofed, or the
					// outcome "refused" — and now a comma-separated list of them.
					// ⛔ BOTH TYPES, and the integer is not vestigial. A query string
					// can only carry text, so the screen sends `2,refused`; but a
					// caller inside PHP passes a real int, and WP validates the
					// declared type BEFORE this route's handler runs — so declaring
					// string alone turned `verdict => 0` into a 400 with
					// rest_invalid_param, which is a rejection the handler never saw
					// and could not explain. Caught by ActivityLogRestTest on 2026-08-21.
					// ⚠️ The handler casts to string and validates per token, so both
					// shapes land on the same check.
					'verdict'  => array( 'type' => array( 'string', 'integer' ) ),
					// Web Bot Auth. A list of exact signers, and/or the wildcard `*`
					// meaning "carried a signature at all" — the only way to ask the
					// question an owner asks first. {@see Repository::log()}
					'signer'   => array( 'type' => 'string' ),
					// Prefix match only — see Repository::log().
					'ua'       => array( 'type' => 'string' ),
					'before'   => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
					// Sorting. 'at' (newest first) is the default and the only one
					// that pages by cursor; the rest page by offset. {@see Repository::log()}
					'orderby'  => array( 'type' => 'string' ),
					'order'    => array( 'type' => 'string' ),
					'offset'   => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			'agentimus/v1',
			'/activity/log/facets',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => function () {
					return rest_ensure_response( Repository::log_facets() );
				},
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

		// A short-lived token for the readiness screen's own live checks: those fetches
		// are deliberately anonymous (they grade what an agent receives), so they carry
		// this token in a header to keep the owner's self-tests out of the visit log.
		register_rest_route(
			'agentimus/v1',
			'/activity/selfcheck-token',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => static function () {
					return rest_ensure_response(
						array(
							'token' => Owner::mint_self_check_token(),
							'ttl'   => Owner::SELFCHECK_TTL,
						)
					);
				},
			)
		);

		// The client manager (Settings → AI Access): every standing decision about a
		// client — blocked, trusted, ignored — in one payload, with dates and the
		// recognition catalog's identity where a token is a known crawler.
		register_rest_route(
			'agentimus/v1',
			'/activity/clients',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'clients' ),
			)
		);

		register_rest_route(
			'agentimus/v1',
			'/activity/undismiss',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'undismiss' ),
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'agentimus/v1',
			'/activity/client-remove',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'can_manage' ),
				'callback'            => array( $this, 'client_remove' ),
				// No enum on `list`: WP validates args BEFORE the permission callback,
				// so an unauthenticated probe with a junk value would get 400 where the
				// gate must answer 401/403 first (the RestPermissionTest sweep enforces
				// this). Settings::remove_agent_token() constrains the value instead.
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
					'list'  => array(
						'type'     => 'string',
						'required' => true,
					),
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
	 * A best-effort NOISE FILTER, not a security boundary. The beacon fires from our own pages, so
	 * a matching Origin/Referer is what a genuine arrival looks like — but both headers are
	 * client-supplied and trivially forgeable, so this cannot AUTHENTICATE a request. It is not
	 * meant to: the counts it feeds are a public, client-side analytics signal (like any
	 * page-view beacon), inherently spoofable, and CDN mode makes that unavoidable — the page is
	 * edge-cached, so the server never sees the real request and any token we embedded would be
	 * baked into the cached HTML for everyone. The AI-traffic screen says as much ("indicative, not
	 * exact"). This check keeps out casual cross-site noise and origin-less requests; the rate cap
	 * and row cap bound the rest. Do NOT rely on it to prove a request is genuine.
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
		// The same "this isn't a content view" guards the server-side recorder applies, so
		// the two modes count the same arrivals. Deciding it HERE (per render) rather than
		// in the endpoint is what keeps it cache-correct: a cached 404 carries no beacon,
		// while a cached article carries its own. Without this, CDN mode logged AI-referred
		// hits on dead URLs as "top landing pages" and server-side mode did not.
		if ( is_admin() || is_404() || is_feed() || is_trackback() || is_robots() ) {
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
	 * place. No settings are touched, so — unlike block/allow — the response carries
	 * no settings block.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dismiss( \WP_REST_Request $request ) {
		Repository::dismiss(
			(string) $request->get_param( 'ua' ),
			(int) $request->get_param( 'hits' )
		);
		return rest_ensure_response( $this->stats_payload() );
	}

	/**
	 * REST: GET /activity/clients — the client manager's payload: the block and
	 * trust lists (token, decision date where recorded, catalog identity where
	 * recognised) plus every review-queue dismissal.
	 *
	 * @return \WP_REST_Response
	 */
	public function clients() {
		$decisions = Settings::decisions();
		// The robots.txt trainer-block names, lowercased once. A TRUSTED client
		// whose name is also on that list is a contradiction the owner may well
		// mean — public "stay away", private "never refused" — but the two
		// surfaces may only disagree OUT LOUD, so the Allowed row must say it.
		// Exact name match (case-insensitive): the robots.txt line is per exact
		// User-agent name, and a looser match would claim a line that isn't there.
		$trainers = array_map( 'strtolower', array_map( 'trim', array_map( 'strval', (array) $this->settings->get( 'blocked_trainers', array() ) ) ) );
		$describe = static function ( $tokens, $dates, array $robots_blocked = array() ) {
			$rows = array();
			foreach ( (array) $tokens as $token ) {
				$token = (string) $token;
				$known = Catalog::identify( $token );
				$rows[] = array(
					'token'  => $token,
					'at'     => isset( $dates[ strtolower( $token ) ] ) ? (int) $dates[ strtolower( $token ) ] : 0,
					'known'  => $known,
					// True when this same name is on the robots.txt trainer list.
					'robots' => in_array( strtolower( $token ), $robots_blocked, true ),
				);
			}
			return $rows;
		};

		return rest_ensure_response(
			array(
				'blocked'  => $describe( $this->settings->get( 'blocked_agents', array() ), $decisions['block'] ),
				'allowed'  => $describe( $this->settings->get( 'allowed_agents', array() ), $decisions['allow'], $trainers ),
				'ignored'  => Repository::dismissals(),
				// Settings-shaped mirror of the two lists, so the app can hand it to
				// the same syncBlockSettings() the review queue's Allow/Block use —
				// keeping the open Settings form and its saved snapshot in step
				// (else a later Save would resurrect a token removed here).
				'settings' => array(
					'blocked_agents' => (array) $this->settings->get( 'blocked_agents', array() ),
					'allowed_agents' => (array) $this->settings->get( 'allowed_agents', array() ),
					'block_agents'   => (bool) $this->settings->enabled( 'block_agents' ),
					'block_spoofed'  => (bool) $this->settings->enabled( 'block_spoofed' ),
				),
			)
		);
	}

	/**
	 * REST: POST /activity/undismiss — the client manager's "Un-ignore". Returns
	 * the refreshed manager payload so the dialog re-renders from one truth.
	 *
	 * @param \WP_REST_Request $request Request with `key`.
	 * @return \WP_REST_Response
	 */
	public function undismiss( \WP_REST_Request $request ) {
		Repository::undismiss( (string) $request->get_param( 'key' ) );
		return $this->clients();
	}

	/**
	 * REST: POST /activity/client-remove — take one token off the block or trust
	 * list (the manager's "Unblock" / "Un-trust"). Writes through Settings, so
	 * the removal also drops the token's decision date.
	 *
	 * @param \WP_REST_Request $request Request with `token` and `list`.
	 * @return \WP_REST_Response
	 */
	public function client_remove( \WP_REST_Request $request ) {
		$this->settings->remove_agent_token(
			(string) $request->get_param( 'token' ),
			(string) $request->get_param( 'list' )
		);
		return $this->clients();
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
		$token = VerifierRegistry::claimed( $ua_lc );
		if ( '' === $token ) {
			return new \WP_Error(
				'agentimus_not_verifiable',
				__( 'This client doesn’t claim a bot in the verified-bots list, so there’s nothing to re-check.', 'agentimus' ),
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
					'activity' => $this->stats_payload(),
				)
			);
		}

		// Fresh FCrDNS for each address, with the published-IP-range check as fallback when
		// rDNS is inapplicable (a range-only operator like GPTBot) or inconclusive. Fold to
		// the WORST (spoofed > verified > undetermined), mirroring the ingest aggregation.
		$verdict = 0;
		$per_ip  = array();
		foreach ( $ips as $ip ) {
			// The shared cascade, fresh variant — same definition the ingest and the
			// Guard read, just past the lookup budget and allowed to refetch ranges.
			$v = BotVerifier::claim_verdict( $ua_lc, $ip, true );
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
				'activity' => $this->stats_payload(),
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
			'activity' => $this->stats_payload(),
			'settings' => $this->settings->all(),
		);
	}

	/**
	 * The activity payload, assembled ONE way for every route that returns one.
	 *
	 * The admin app replaces its whole `activity` object with whatever an activity
	 * route hands back, and the audience / systems cards gate their skeletons on
	 * those blocks being present — so a route answering with bare Repository::stats()
	 * silently strips them and both cards flash their first-load loading state on a
	 * one-row action (that is exactly how the review queue's Ignore made "Who Reached
	 * Your Site" reload). Same drift shape as the MCP projections: two surfaces, one
	 * payload — never a slimmer copy per route.
	 *
	 * @return array Stats plus the blocks the dashboard screens ride on:
	 *               agentAccessUnseen, audience, systems.
	 */
	private function stats_payload() {
		$stats = Repository::stats( $this->settings );
		// The Agent Access nav badge rides THIS payload — the one the dashboard
		// already polls for the review bell — rather than polling its own endpoint
		// every tick. One COUNT query on a request that was happening anyway, versus
		// a second HTTP round-trip that would also drag back up to 100 event rows
		// just to render a number.
		$stats['agentAccessUnseen'] = \Agentimus\AgentAccess\Store::unseen_count();
		// People and machines, side by side, from the payload above
		// plus one indexed aggregate per connected search source.
		// Assembled server-side so the two halves can never drift
		// apart between the screens that show them.
		$stats['audience'] = \Agentimus\Audience::from_stats( $stats );
		// The systems roll-up card rides here too: option reads, two
		// indexed COUNTs and two file_exists — see Systems for why the
		// expensive truths it points at are NOT recomputed on this poll.
		$stats['systems'] = \Agentimus\Systems::summary( $this->settings, $stats );
		return $stats;
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

		// ⭐ THE VALUE FILTERS TAKE A LIST — "ChatGPT,Cursor" narrows to either.
		// ⛔ `ua` is NOT one of them: it is a prefix match, and a list of prefixes
		// is a different question ("starts with any of these") that the index
		// cannot answer the same way. One needle, as before.
		foreach ( array( 'agent', 'endpoint', 'network', 'signer' ) as $key ) {
			$raw = (string) $request->get_param( $key );
			if ( '' === $raw ) {
				continue;
			}
			$parts = array();
			foreach ( explode( ',', $raw ) as $part ) {
				$part = sanitize_text_field( $part );
				if ( '' !== $part ) {
					// The columns are varchar(64)/(128)/(255); a longer needle can never match,
					// so clamp rather than hand the database a pointless comparison.
					$parts[] = substr( $part, 0, 255 );
				}
			}
			if ( $parts ) {
				$args[ $key ] = $parts;
			}
		}

		$ua = sanitize_text_field( (string) $request->get_param( 'ua' ) );
		if ( '' !== $ua ) {
			$args['ua'] = substr( $ua, 0, 255 );
		}

		// ⚠️ Validated per TOKEN, not on the joined string: one bad entry in a
		// list is still a typo the owner should be told about, and a silent drop
		// would quietly widen the result set instead of narrowing it.
		$verdict = (string) $request->get_param( 'verdict' );
		if ( '' !== $verdict ) {
			$picked = array();
			foreach ( explode( ',', $verdict ) as $token ) {
				$token = trim( $token );
				if ( '' === $token ) {
					continue;
				}
				if ( 'refused' === $token ) {
					$picked[] = 'refused';
					continue;
				}
				if ( ! preg_match( '/^[0-2]$/', $token ) ) {
					return new \WP_Error(
						'agentimus_bad_verdict',
						__( 'Verdict must be 0 (unchecked), 1 (verified), 2 (spoofed) or "refused".', 'agentimus' ),
						array( 'status' => 400 )
					);
				}
				$picked[] = (int) $token;
			}
			if ( $picked ) {
				$args['verdict'] = $picked;
			}
		}

		// ⛔ Whitelisted, not passed through: an ORDER BY is an SQL identifier and
		// can never be bound as a value. An unknown column is not an error the
		// owner can act on, so it falls back to the default rather than 400ing —
		// but a MISSPELLED order ('ascending') would silently reverse the list,
		// so that one is refused.
		$orderby = strtolower( trim( (string) $request->get_param( 'orderby' ) ) );
		if ( '' !== $orderby && in_array( $orderby, array( 'at', 'client', 'endpoint', 'ua', 'status', 'network' ), true ) ) {
			$args['orderby'] = $orderby;
		}
		$order = strtolower( trim( (string) $request->get_param( 'order' ) ) );
		if ( '' !== $order ) {
			if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
				return new \WP_Error(
					'agentimus_bad_order',
					__( 'Order must be "asc" or "desc".', 'agentimus' ),
					array( 'status' => 400 )
				);
			}
			$args['order'] = $order;
		}

		foreach ( array( 'before', 'per_page', 'offset' ) as $key ) {
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
