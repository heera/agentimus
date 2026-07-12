<?php
/**
 * Agent Access — visibility into who authenticates to, and acts on, the machine surface
 * Agentimus creates. The Activity log answers "who READ my agent surface"; this answers
 * "who ACTED on it".
 *
 * Two things are watched:
 *   1. Application passwords — the credential an AI agent (or an attacker) uses to reach
 *      this site's REST API as a real user. Minting one is the classic way a compromise
 *      OUTLIVES the victim changing their password, and stock WordPress announces it to
 *      nobody.
 *   2. Ability invocations — the WP 6.9+ Abilities API is how an AI assistant actually runs
 *      something on the site. Nothing anywhere records that an ability ran. We do.
 *
 * DOCTRINE — we observe and guide, we NEVER enforce. Deliberately NOT here: login lockout,
 * rate limiting, IP blocking, 2FA, failed-login monitoring, malware scanning. That is a
 * security plugin; this is a discovery layer telling the truth about its own surface. This
 * is a camera, not a lock: nothing is ever blocked, and a stolen credential still works —
 * the owner simply finds out.
 *
 * HONESTY — a security-flavoured feature that overstates itself is worse than none. Two
 * limits are structural and the UI must say so rather than implying "all clear":
 *   - `wp_before_execute_ability` fires only AFTER the permission check passes, so a DENIED
 *     probe is invisible to us. A quiet screen means "nothing succeeded", not "nobody tried".
 *   - We record no IP and no location, so we can name the CREDENTIAL but never the human.
 *
 * @package Agentimus
 */

namespace Agentimus\AgentAccess;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class Module {

	/** Stores what we have PROVEN about execute-hook support: '1' | '0' (absent = unknown). */
	const HOOKS_OPTION = 'agentimus_agent_access_hooks';

	/**
	 * Ability names for which a global execute hook has fired during THIS request. Written by
	 * {@see on_before_execute_ability()}, read by {@see observe_own_ability()} — that pairing
	 * is the capability probe (see the class notes on `coverage()`).
	 *
	 * @var array<string,bool>
	 */
	private static $hooked = array();

	/**
	 * Whether recording is live this request. observe_own_ability() is called from a wrapper that
	 * Abilities\Registrar installs UNCONDITIONALLY (it wraps every ability's execute callback at
	 * registration time, before this module's own enabled() gate runs), so without this flag a
	 * disabled feature would still write an `ability_used` row — and corrupt the hook verdict — the
	 * moment an admin or an authenticated MCP client ran one of our abilities. register() sets it.
	 *
	 * @var bool
	 */
	private static $active = false;

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Shared settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook everything. Note the ability listener is registered UNCONDITIONALLY: adding an action
	 * for a hook that never fires costs nothing, and doing it this way means the feature
	 * auto-lights-up the moment the owner upgrades WordPress or installs the Abilities API
	 * plugin — no version logic, no migration, no dormant code path.
	 */
	public function register() {
		Table::maybe_install();

		add_action( self::cron_hook(), array( Store::class, 'prune' ) );

		// Gates observe_own_ability(), which the Registrar calls whether or not this ran the rest of
		// register(). Set before the early return so a disabled feature records nothing.
		self::$active = self::enabled( $this->settings );

		if ( ! self::$active ) {
			return;
		}

		// Credential lifecycle. Available on every WordPress we support (5.6+), though core
		// only offers application passwords at all over HTTPS — see https_required().
		add_action( 'wp_create_application_password', array( $this, 'on_password_created' ), 10, 2 );
		add_action( 'wp_update_application_password', array( $this, 'on_password_updated' ), 10, 2 );
		add_action( 'wp_delete_application_password', array( $this, 'on_password_deleted' ), 10, 2 );
		add_action( 'application_password_did_authenticate', array( $this, 'on_password_used' ), 10, 2 );

		// Ability invocations from ANY plugin — only fired by core 6.9+ / abilities-api v0.4.0+.
		add_action( 'wp_before_execute_ability', array( $this, 'on_before_execute_ability' ), 10, 1 );

		// Requests to the abilities surface that FAILED. This is what closes the gap the empty state
		// used to have to confess: wp_before_execute_ability only fires after WordPress has already
		// ALLOWED the call, so a refused one left no trace at all.
		//
		// `rest_request_after_callbacks`, NOT `rest_post_dispatch`. Both fire after the permission
		// check, but rest_post_dispatch is applied inside WP_REST_Server::serve_request(), while
		// rest_do_request() — which the entire integration suite uses — calls dispatch() directly and
		// never reaches it. Choosing it would have given us a listener that worked against real HTTP
		// attacks and was completely untestable. This one lives in respond_to_request(), which
		// dispatch() does call.
		add_filter( 'rest_request_after_callbacks', array( $this, 'on_rest_after_callbacks' ), 10, 3 );

		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/* ---------------------------------------------------------------------- *
	 *  Availability
	 * ---------------------------------------------------------------------- */

	/**
	 * Whether event recording is on. Default ON: it costs nothing on the hot path and stores
	 * no personal data, so there is nothing to opt out of on privacy grounds.
	 *
	 * @param Settings $settings Settings store.
	 * @return bool
	 */
	public static function enabled( Settings $settings ) {
		/**
		 * Filter whether Agent Access records events at all.
		 *
		 * @param bool $enabled Whether recording is enabled.
		 */
		return (bool) apply_filters( 'agentimus_agent_access_enabled', $settings->enabled( 'agent_access_events' ) );
	}

	/**
	 * Whether this site can have application passwords at all. Core disables them outright
	 * without TLS — `wp_is_application_passwords_supported()` is literally
	 * `is_ssl() || 'local' === wp_get_environment_type()`. On a plain-HTTP production site the
	 * credential half of this feature is therefore INERT, and the UI must say so rather than
	 * render an empty panel that reads as "all clear".
	 *
	 * @return bool
	 */
	public static function passwords_available() {
		return function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
	}

	/**
	 * What we can honestly claim to see of ability invocations on this site.
	 *
	 * The Abilities API arrives by two roads that do NOT behave alike. Core has shipped it
	 * since 6.9 and always emitted the execute hooks. The standalone feature plugin (how a 6.8
	 * site gets abilities) only began emitting them in v0.4.0 — v0.1 to v0.3 register abilities
	 * and announce nothing, and the plugin exposes no version constant to interrogate.
	 *
	 * So `function_exists( 'wp_register_ability' )` proves abilities can be REGISTERED, not that
	 * invocations are OBSERVABLE. Trusting it would show a v0.3.0 site "monitoring active" over a
	 * permanently empty feed — false reassurance, the worst failure this feature could have.
	 *
	 * We therefore PROVE hook support instead of inferring it: {@see observe_own_ability()} watches
	 * whether the global hook fired for an ability we know just ran, and records the verdict. Until
	 * an ability has run we answer PENDING rather than guess.
	 *
	 * @return string An Events::COVERAGE_* constant.
	 */
	public static function coverage() {
		return Events::ability_coverage(
			function_exists( 'wp_register_ability' ),
			get_bloginfo( 'version' ),
			self::hooks_seen()
		);
	}

	/**
	 * What we have PROVEN about execute-hook support. TRUE/FALSE once observed, NULL until then.
	 *
	 * @return bool|null
	 */
	private static function hooks_seen() {
		$stored = get_option( self::HOOKS_OPTION, null );
		if ( null === $stored || '' === $stored ) {
			return null;
		}
		return '1' === (string) $stored;
	}

	/**
	 * Persist the hook-support verdict — only on a change, so this never writes on the hot path.
	 *
	 * @param bool $supported Whether the installed Abilities API emits execute hooks.
	 * @return void
	 */
	private static function remember_hooks( $supported ) {
		$supported = $supported ? '1' : '0';
		if ( (string) get_option( self::HOOKS_OPTION, '' ) === $supported ) {
			return;
		}
		update_option( self::HOOKS_OPTION, $supported, false );
	}

	/* ---------------------------------------------------------------------- *
	 *  Credential lifecycle
	 * ---------------------------------------------------------------------- */

	/**
	 * An application password was minted. THE event worth catching: an app password keeps
	 * working after the user changes their real password, so it is how an intruder persists.
	 *
	 * Core also passes the plaintext password to this hook. We do not accept that argument
	 * (the callback is registered with 2 args) and it must never be stored, logged or
	 * transmitted — we keep only the UUID, which identifies the key without being it.
	 *
	 * @param int   $user_id  User the password belongs to.
	 * @param array $new_item The new application password record.
	 * @return void
	 */
	public function on_password_created( $user_id, $new_item ) {
		self::record(
			Events::KIND_APPPW_CREATED,
			$user_id,
			isset( $new_item['uuid'] ) ? $new_item['uuid'] : '',
			isset( $new_item['name'] ) ? $new_item['name'] : ''
		);
	}

	/**
	 * An application password record changed (typically renamed).
	 *
	 * @param int   $user_id User ID.
	 * @param array $item    The application password.
	 * @return void
	 */
	public function on_password_updated( $user_id, $item ) {
		self::record(
			Events::KIND_APPPW_RENAMED,
			$user_id,
			isset( $item['uuid'] ) ? $item['uuid'] : '',
			isset( $item['name'] ) ? $item['name'] : ''
		);
	}

	/**
	 * An application password was revoked. Fires for both a single revoke and a revoke-all.
	 *
	 * @param int   $user_id User ID.
	 * @param array $item    The application password.
	 * @return void
	 */
	public function on_password_deleted( $user_id, $item ) {
		self::record(
			Events::KIND_APPPW_DELETED,
			$user_id,
			isset( $item['uuid'] ) ? $item['uuid'] : '',
			isset( $item['name'] ) ? $item['name'] : ''
		);
	}

	/**
	 * An application password authenticated a request. THIS IS THE HOT PATH — it fires on every
	 * successful app-password REST call, so an agent making 200 calls fires it 200 times. Only
	 * the FIRST use is news ("a key that has never been used just woke up"); the other 199 must
	 * cost nothing, or we have quietly added a database write to every authenticated request an
	 * agent makes.
	 *
	 * We get that for free from core's own bookkeeping rather than inventing our own. Core calls
	 * record_application_password_usage() immediately BEFORE firing this hook, but that reloads
	 * the passwords from user meta and never mutates the local $item — so $item['last_used'] here
	 * is still the value from BEFORE this request. It is null on a freshly created password
	 * (class-wp-application-passwords.php:107), so empty() means "has never authenticated".
	 *
	 * NOT an object-cache key, which is what this used to be and was a real bug: WordPress's
	 * default object cache is NON-persistent (no Redis/Memcached drop-in), so the guard started
	 * empty on every request and every authenticated call fell through to a write.
	 *
	 * @param \WP_User $user The authenticated user.
	 * @param array    $item The application password used.
	 * @return void
	 */
	public function on_password_used( $user, $item ) {
		$uuid = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';
		if ( '' === $uuid || ! is_object( $user ) ) {
			return;
		}

		// The steady state for a busy agent: one array lookup, no cache, no query, no write.
		if ( ! empty( $item['last_used'] ) ) {
			return;
		}

		self::record(
			Events::KIND_APPPW_USED,
			isset( $user->ID ) ? $user->ID : 0,
			$uuid,
			isset( $item['name'] ) ? $item['name'] : ''
		);
	}

	/* ---------------------------------------------------------------------- *
	 *  Ability invocations
	 * ---------------------------------------------------------------------- */

	/**
	 * The global "an ability is about to run" hook — fires for EVERY ability on the site,
	 * including other plugins'. Present only on core 6.9+ / abilities-api v0.4.0+.
	 *
	 * Note this fires AFTER the permission check, so it never sees a denied attempt. That gap
	 * is structural and is surfaced honestly in the UI rather than papered over.
	 *
	 * @param string $ability_name The ability being executed.
	 * @return void
	 */
	public function on_before_execute_ability( $ability_name ) {
		$ability_name = (string) $ability_name;

		// Half of the capability probe: mark that the hook DID fire for this ability, so that
		// observe_own_ability() — which runs a moment later for our own abilities — can tell
		// the difference between "the hook works" and "this API never announces anything".
		self::$hooked[ $ability_name ] = true;
		self::remember_hooks( true );

		self::record( Events::KIND_ABILITY_USED, get_current_user_id(), self::current_credential(), $ability_name, 'hook' );
	}

	/**
	 * Called from {@see \Agentimus\Abilities\Registrar::add()} whenever one of OUR abilities
	 * executes. Two jobs:
	 *
	 *   1. VERSION-PROOF COVERAGE. We own these execute callbacks, so this path works on any
	 *      Abilities API — core or plugin, hooks or no hooks. Whatever else is true of a site,
	 *      we can always honestly say: we monitor what we exposed.
	 *
	 *   2. THE PROBE. The global hook fires immediately BEFORE the execute callback. So if we
	 *      are running and the mark is absent, the installed API demonstrably does not emit
	 *      execute hooks — which means third-party abilities are invisible to us, and the UI
	 *      must say so. This proves the capability instead of sniffing a version number, works
	 *      identically for core and plugin, and self-corrects the moment the owner upgrades.
	 *
	 * @param string $ability_name The ability that is executing.
	 * @return void
	 */
	public static function observe_own_ability( $ability_name ) {
		// The Registrar wraps every ability's execute callback with this, unconditionally, so a
		// disabled feature reaches here too. Record nothing — and, crucially, do NOT fall through to
		// remember_hooks() below, which would corrupt the coverage verdict while the feature is off.
		if ( ! self::$active ) {
			return;
		}

		$ability_name = (string) $ability_name;

		if ( isset( self::$hooked[ $ability_name ] ) ) {
			// The hook fired, so on_before_execute_ability() already recorded this invocation.
			// Recording it again here would double-count every ability on a healthy site.
			return;
		}

		self::remember_hooks( false );
		self::record( Events::KIND_ABILITY_USED, get_current_user_id(), self::current_credential(), $ability_name, 'own' );
	}

	/**
	 * A request to the abilities REST surface came back an error — record what was turned away.
	 *
	 * THIS IS A HOT PATH. The filter fires on EVERY REST request the site serves: the block editor,
	 * media, every plugin's routes. The overwhelming majority succeed, so the first line has to be
	 * the exit. Do not add work above it.
	 *
	 * @param \WP_REST_Response|\WP_Error $response The response (or error) being returned.
	 * @param array                       $handler  The matched route handler.
	 * @param \WP_REST_Request            $request  The request.
	 * @return \WP_REST_Response|\WP_Error Unchanged — we only observe.
	 */
	public function on_rest_after_callbacks( $response, $handler, $request ) {
		// ~Every request stops here.
		if ( ! is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		// ONLY the /run sub-route. classify() depends on the run controller's gate order —
		// existence (404) is checked BEFORE permission (401/403) — so on /run a 401/403 implies a
		// real, registered ability and a 404 implies a guess. The sibling routes (GET .../abilities
		// [list], GET .../abilities/<name> [item], GET .../categories) gate on a plain
		// current_user_can('read') permission_callback, which core runs BEFORE the callback, so an
		// anonymous request returns 401 for ANY `name` — including a free-form query param. Matching
		// the whole /wp-abilities/ namespace let an unauthenticated attacker write unbounded rows
		// with an attacker-controlled `subject`, defeating the aggregation this feature is built on.
		if ( ! preg_match( '#^/wp-abilities/v\d+/abilities/.+/run$#', (string) $request->get_route() ) ) {
			return $response;
		}

		$data    = $response->get_error_data();
		$status  = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;
		$user_id = get_current_user_id();
		$kind    = Events::classify( $status, $user_id );

		if ( null === $kind ) {
			return $response; // A logged-in caller's malformed input: their bug, not our signal.
		}

		// CARDINALITY. A probe's name is attacker-supplied and must NEVER reach `subject` — ten
		// thousand guesses would mint ten thousand rows; they aggregate into one row whose `hits`
		// counts the campaign. A refusal names a real ability (the 404 gate fired first), so the set
		// is bounded — but we still confirm the ability is genuinely registered before storing its
		// name, rather than trusting the gate order alone. Belt and suspenders: assuming a gate
		// order is exactly what let the bug above through, so a REFUSED that somehow names nothing
		// real degrades to an aggregated probe rather than a free write.
		$subject = '';
		if ( Events::names_a_real_ability( $kind ) ) {
			$name = (string) $request->get_param( 'name' );
			if ( function_exists( 'wp_get_ability' ) && wp_get_ability( $name ) ) {
				$subject = $name;
			}
		}

		self::record( $kind, $user_id, self::current_credential(), $subject, (string) $status );

		return $response;
	}

	/**
	 * The application password behind the current request, when there is one. Lets an ability
	 * event name the credential that ran it rather than just the user. Empty for a normal
	 * cookie-authenticated admin clicking around in the dashboard.
	 *
	 * @return string
	 */
	private static function current_credential() {
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return '';
		}
		$uuid = rest_get_authenticated_app_password();
		return is_string( $uuid ) ? $uuid : '';
	}

	/* ---------------------------------------------------------------------- *
	 *  Recording
	 * ---------------------------------------------------------------------- */

	/**
	 * Write one event, with the owner's kill-switch and a filter seam for add-ons.
	 *
	 * @param string $kind    An Events::KIND_* constant.
	 * @param int    $user_id User the credential belongs to.
	 * @param string $cred    Application-password UUID (never the password).
	 * @param string $subject Ability name or password label.
	 * @param string $detail  Optional short note.
	 * @return void
	 */
	private static function record( $kind, $user_id, $cred = '', $subject = '', $detail = '' ) {
		$event = array(
			'kind'    => $kind,
			'user_id' => (int) $user_id,
			'cred'    => (string) $cred,
			'subject' => (string) $subject,
			'detail'  => (string) $detail,
		);

		/**
		 * Filter an Agent Access event before it is stored. Return an empty value to drop it.
		 *
		 * @param array $event The event about to be recorded.
		 */
		$event = apply_filters( 'agentimus_agent_access_event', $event );
		if ( empty( $event ) || empty( $event['kind'] ) ) {
			return;
		}

		Store::record( $event['kind'], $event['user_id'], $event['cred'], $event['subject'], $event['detail'] );
	}

	/* ---------------------------------------------------------------------- *
	 *  REST
	 * ---------------------------------------------------------------------- */

	/**
	 * REST: GET /agent-access (feed + coverage), POST /agent-access/seen, DELETE /agent-access.
	 * Admin-only, mirroring every other Agentimus admin route.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			'agentimus/v1',
			'/agent-access',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'before' => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'clear' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			'agentimus/v1',
			'/agent-access/seen',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'seen' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * The feed, plus everything the UI needs to describe honestly what it is and is not showing.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function read( $request = null ) {
		$coverage = self::coverage();
		$before   = ( $request instanceof \WP_REST_Request ) ? (int) $request->get_param( 'before' ) : 0;

		// A cursor walk, the same shape the request log uses — so every row is reachable, however
		// many there are. A "show more" that stops at 500 would leave the rest of a 5,000-row table
		// permanently unreachable, which on a flood is exactly when the owner most needs to look.
		$page = Store::page( Store::DEFAULT_LIMIT, null, $before );

		return rest_ensure_response(
			array(
				'events'       => $page['events'],
				'hasMore'      => $page['hasMore'],
				'cursor'       => $page['cursor'],
				'perPage'      => Store::DEFAULT_LIMIT,
				'unseen'       => Store::unseen_count(),
				// `total` is what lets the footer SAY the view is windowed. A log that quietly stops
				// short reads as "that's everything" — and on a screen whose whole premise is honesty
				// about its blind spots, that is the worst possible place to hide one. See
				// RequestLog's footer, which makes the same promise.
				'total'        => Store::total(),
				'retention'    => (int) apply_filters( 'agentimus_agent_access_retention_days', Store::RETENTION_DAYS ),
				'maxRows'      => Store::MAX_ROWS,
				// The four-state ladder. The UI renders a different, ACTIONABLE message for each —
				// never a bare empty table, which would read as "all clear" when the truth may be
				// "we cannot see anything here".
				'coverage'     => $coverage,
				'hasAbilities' => Events::has_abilities( $coverage ),
				'thirdParty'   => Events::covers_third_party( $coverage ),
				// FALSE on a plain-HTTP site: core disables application passwords without TLS, so
				// the credential half of this feature cannot fire at all.
				'passwords'    => self::passwords_available(),
				// So the "too old for abilities" state can name the version the owner is actually on
				// rather than talk in the abstract.
				'wpVersion'    => get_bloginfo( 'version' ),
			)
		);
	}

	/**
	 * Mark the feed read.
	 *
	 * @return \WP_REST_Response
	 */
	public function seen() {
		Store::mark_seen();
		return rest_ensure_response( array( 'ok' => true, 'unseen' => 0 ) );
	}

	/**
	 * Empty the feed.
	 *
	 * @return \WP_REST_Response
	 */
	public function clear() {
		Store::clear();
		return rest_ensure_response( array( 'ok' => true, 'total' => 0 ) );
	}

	/**
	 * Site-admin gate — mirrors every Agentimus admin REST route.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/* ---------------------------------------------------------------------- *
	 *  Cron
	 * ---------------------------------------------------------------------- */

	/**
	 * We ride the existing daily activity prune rather than scheduling a second event — one
	 * fewer cron entry, and the two logs age out together.
	 *
	 * @return string
	 */
	private static function cron_hook() {
		return \Agentimus\Activity\Module::CRON;
	}
}
