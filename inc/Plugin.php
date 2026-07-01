<?php
/**
 * Plugin orchestrator — wires the modules together and owns shared services.
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	public $settings;

	/**
	 * Option storing the signature of the rewrite-rule set we last flushed for.
	 * Keeps the historical key name (its value used to be a bare version string;
	 * it is now a richer signature — see rewrite_signature()), so an upgrade from
	 * the old format simply mismatches once and self-heals with a single flush.
	 *
	 * @var string
	 */
	const REWRITE_SIGNATURE_OPTION = 'agentimus_rewrite_version';

	/** @var string Option storing the unix time of the last AUTO rewrite flush. */
	const REWRITE_FLUSHED_AT_OPTION = 'agentimus_rewrite_flushed_at';

	/**
	 * Minimum seconds between two automatic rewrite flushes. NOT a throttle on the
	 * normal path (an unchanged signature no-ops for free); purely a backstop so a
	 * misbehaving provider whose `agentimus_well_known_routed` filter is unstable
	 * across requests can never make us flush more than once per window.
	 *
	 * @var int
	 */
	const REWRITE_FLUSH_MIN_INTERVAL = 60;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Boot every module. Front-end output, REST and admin all register their
	 * own hooks; nothing runs work eagerly here.
	 */
	public function boot() {
		// Translations are loaded automatically by WordPress for plugins hosted on
		// wordpress.org (since 4.6) — no manual load_plugin_textdomain() needed.

		$this->settings = new Settings();

		// Figure out post-type vendor labels at runtime (no hardcoded plugin list).
		// Only on our settings screen, so the per-registration backtrace cost is
		// never paid on a normal page load.
		if ( is_admin() && 'agentimus' === ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin screen check, no state change.
			Content::watch_origins();
		}

		Cache::register_flush_hooks();

		( new Endpoints( $this->settings ) )->register();
		( new Schema( $this->settings ) )->register();
		( new Rest( $this->settings ) )->register();
		( new Admin( $this->settings ) )->register();
		( new Discovery\Module( $this->settings ) )->register();
		( new Activity\Module( $this->settings ) )->register();
		( new WebMcp( $this->settings ) )->register();
		( new Exposure( $this->settings ) )->register();

		// AI Visibility monitoring (opt-in, BYOK). Its config, keys and results live
		// in their own option/table, independent of the core settings above.
		$visibility = new Visibility\Settings();
		( new Visibility\Module( $visibility ) )->register();
		( new Visibility\Rest( $visibility ) )->register();
		if ( is_multisite() ) {
			( new Visibility\Network() )->register();
		}

		// Self-heal the /.well-known rewrite rules: flush ONCE whenever the routed-name
		// set changes — an Agentimus update that adds a built-in name (e.g. tdmrep.json)
		// OR a provider plugin that opts a name in via the `agentimus_well_known_routed`
		// filter. Runs after WellKnown::add_rules() (init, default priority) so the rules
		// are already registered. maybe_flush_rewrites() skips front-end requests and
		// no-ops on the unchanged-signature common case, so this stays off the hot path.
		add_action( 'init', array( self::class, 'maybe_flush_rewrites' ), 20 );

		/**
		 * Fires after Agentimus has booted. The seam a Pro add-on hooks to
		 * register its own features against the shared Settings instance.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'agentimus_booted', $this );
	}

	/**
	 * Activation entry point. On multisite a network-wide activation fires this
	 * hook ONCE (with $network_wide true), NOT per site — so we loop the existing
	 * sites and set each up. A normal single-site activation just sets up the one
	 * current site.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( self::network_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}

		self::activate_site();
	}

	/**
	 * Run the per-site setup for a site created AFTER a network-wide activation
	 * (hooked to wp_initialize_site in the bootstrap). Identical to what every site
	 * receives at activation time.
	 */
	public static function install_site() {
		self::activate_site();
	}

	/**
	 * Per-site setup, run in the context of the current blog: seed defaults and
	 * register the /.well-known rewrite rule before flushing, so the discovery
	 * endpoints resolve on the very first request.
	 */
	private static function activate_site() {
		// Detect a truly fresh install BEFORE seeding anything, so the onboarding
		// wizard shows only for new users — never for an upgrade or a migrant from
		// the pre-rename "Agent Ready" option.
		$had_settings = ( false !== get_option( Settings::OPTION, false ) )
			|| ( false !== get_option( 'agent_ready_settings', false ) );

		self::migrate_legacy_option();
		( new Settings() )->ensure_defaults();
		Activity\Table::install();
		Activity\Referrals::install();
		Activity\Module::schedule();
		Visibility\Table::install();
		Visibility\Module::schedule();
		Discovery\WellKnown::add_rules();
		flush_rewrite_rules();
		// Record the signature we just flushed for, so maybe_flush_rewrites() no-ops on
		// the next request rather than flushing again. We intentionally do NOT seed the
		// last-flush timestamp here: that belongs to the auto-flush path, so the first
		// genuine post-activation change (e.g. a provider activated moments later) still
		// flushes promptly instead of being caught by the thrash backstop's window.
		update_option( self::REWRITE_SIGNATURE_OPTION, self::rewrite_signature() );
		self::seed_onboarding_state( $had_settings );
	}

	/**
	 * Self-healing, one-time rewrite flush when the routed /.well-known name set
	 * changes — whether from an Agentimus update or a provider opting a name in via
	 * the `agentimus_well_known_routed` filter. Hooked late on `init` (priority 20),
	 * after WellKnown::add_rules() has registered the current rule set.
	 *
	 * flush_rewrite_rules() is expensive, so three guards keep it off the hot path
	 * and make a runaway flush impossible:
	 *   1. Front-end requests never flush — only the admin (or WP-CLI), where plugins
	 *      are activated/updated. An anonymous crawler hitting /.well-known/* therefore
	 *      cannot trigger a flush, and the signature is only ever evaluated in one
	 *      consistent context (it can't flip between admin/front-end filter results).
	 *   2. The unchanged-signature case — every steady-state request — costs just a
	 *      get_option() + string compare and returns.
	 *   3. A rate-limit backstop caps real flushes to one per REWRITE_FLUSH_MIN_INTERVAL,
	 *      so even a provider whose filter is non-deterministic within the admin context
	 *      can't thrash flushes; the timestamp is recorded only on an actual flush, so a
	 *      genuine change still persists on the next admin request once the window clears.
	 */
	public static function maybe_flush_rewrites() {
		// Guard 1 — never on the front end.
		if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Guard 2 — unchanged set, the common case.
		$signature = self::rewrite_signature();
		if ( $signature === get_option( self::REWRITE_SIGNATURE_OPTION ) ) {
			return;
		}

		// Guard 3 — thrash backstop.
		$last = (int) get_option( self::REWRITE_FLUSHED_AT_OPTION, 0 );
		$now  = time();
		if ( $last > 0 && ( $now - $last ) < self::REWRITE_FLUSH_MIN_INTERVAL ) {
			return;
		}

		flush_rewrite_rules();
		update_option( self::REWRITE_FLUSHED_AT_OPTION, $now );
		update_option( self::REWRITE_SIGNATURE_OPTION, $signature );
	}

	/**
	 * A stable fingerprint of everything that determines the /.well-known rewrite
	 * rules: the running plugin version plus the SORTED routed + nested name sets.
	 * Sorting makes the value order-independent, so providers registering names in a
	 * different order across requests never yield a different signature (which would
	 * thrash flush_rewrite_rules()). Including the version preserves the "flush once
	 * after an Agentimus update" behaviour even when a release changes how the rules
	 * are built without changing the name list itself.
	 *
	 * @return string
	 */
	private static function rewrite_signature() {
		$routed = Discovery\WellKnown::routed_names();
		$nested = Discovery\WellKnown::nested_routes();
		sort( $routed );
		sort( $nested );
		return AGENTIMUS_VERSION . ':' . md5( implode( ',', $routed ) . '|' . implode( ',', $nested ) );
	}

	/**
	 * Carry settings over from the pre-rename "Agent Ready" option key, so an
	 * existing install keeps its configuration after the rename to Agentimus.
	 * One-time: runs only when the new key is absent.
	 */
	private static function migrate_legacy_option() {
		if ( false !== get_option( Settings::OPTION, false ) ) {
			return;
		}
		$legacy = get_option( 'agent_ready_settings', false ); // Old key — intentionally literal.
		if ( is_array( $legacy ) ) {
			add_option( Settings::OPTION, $legacy );
		}
	}

	/**
	 * Decide whether the first-run setup wizard should appear. A fresh install
	 * leaves the flag unset (wizard shows) and queues a one-time redirect to the
	 * plugin screen; an install that already had settings is marked onboarded so
	 * an existing user never sees the wizard.
	 *
	 * @param bool $had_settings Whether configuration existed before activation.
	 */
	private static function seed_onboarding_state( $had_settings ) {
		if ( $had_settings ) {
			add_option( 'agentimus_onboarded', AGENTIMUS_VERSION ); // No-op if already present.
			return;
		}
		set_transient( 'agentimus_activation_redirect', 1, 30 );
	}

	/**
	 * Deactivation: drop generated caches and the rewrite rule (options are kept;
	 * uninstall removes them). Network-aware, so a network deactivation clears each
	 * site's scheduled events and caches rather than only the current one.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( self::network_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Per-site deactivation cleanup, run in the context of the current blog.
	 */
	private static function deactivate_site() {
		Cache::flush();
		Activity\Module::unschedule();
		Visibility\Module::unschedule();
		wp_clear_scheduled_hook( 'agentimus_warm_llms_full' );
		flush_rewrite_rules();
	}

	/**
	 * Site IDs on the network, capped so a very large network cannot time out the
	 * activation request. Beyond the cap each site still self-heals on first boot —
	 * tables via Activity\Module::register(), rewrite rules via maybe_flush_rewrites(),
	 * and the prune cron via Activity\Module::register() → schedule().
	 *
	 * @return int[]
	 */
	private static function network_site_ids() {
		if ( ! is_multisite() ) {
			return array( get_current_blog_id() );
		}
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 500 ) );
		return array_map( 'intval', (array) $sites );
	}
}
