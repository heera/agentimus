<?php
/**
 * Plugin orchestrator — wires the modules together and owns shared services.
 *
 * @package HeeraAgentDiscovery
 */

namespace HeeraAgentDiscovery;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	public $settings;

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
		if ( is_admin() && 'heera-agent-discovery' === ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin screen check, no state change.
			Content::watch_origins();
		}

		Cache::register_flush_hooks();

		( new Endpoints( $this->settings ) )->register();
		( new Schema( $this->settings ) )->register();
		( new Rest( $this->settings ) )->register();
		( new Admin( $this->settings ) )->register();
		( new Discovery\Module( $this->settings ) )->register();
		( new Activity\Module( $this->settings ) )->register();

		/**
		 * Fires after Heera Discovery has booted. The seam a Pro add-on hooks to
		 * register its own features against the shared Settings instance.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'heera_agent_discovery_booted', $this );
	}

	/**
	 * Activation: seed defaults and register the /.well-known rewrite rule before
	 * flushing, so the discovery endpoints resolve on the very first request.
	 */
	public static function activate() {
		// Detect a truly fresh install BEFORE seeding anything, so the onboarding
		// wizard shows only for new users — never for an upgrade or a migrant from
		// the pre-rename "Agent Ready" option.
		$had_settings = ( false !== get_option( Settings::OPTION, false ) )
			|| ( false !== get_option( 'agent_ready_settings', false ) );

		self::migrate_legacy_option();
		( new Settings() )->ensure_defaults();
		Activity\Table::install();
		Activity\Module::schedule();
		Discovery\WellKnown::add_rules();
		flush_rewrite_rules();
		self::seed_onboarding_state( $had_settings );
	}

	/**
	 * Carry settings over from the pre-rename "Agent Ready" option key, so an
	 * existing install keeps its configuration after the rename to Heera Discovery.
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
			add_option( 'heera_agent_discovery_onboarded', HEERA_AGENT_DISCOVERY_VERSION ); // No-op if already present.
			return;
		}
		set_transient( 'heera_agent_discovery_activation_redirect', 1, 30 );
	}

	/**
	 * Deactivation: drop generated caches and the rewrite rule (options are kept;
	 * uninstall removes them).
	 */
	public static function deactivate() {
		Cache::flush();
		Activity\Module::unschedule();
		wp_clear_scheduled_hook( 'heera_agent_discovery_warm_llms_full' );
		flush_rewrite_rules();
	}
}
