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

		// One-shot correction of un-customised defaults we've since lowered (currently the
		// llms-full byte budget, which used to sit exactly at the 1 MB object-cache ceiling). Cheap
		// once the flag is set: the flag is autoloaded, so the guard short-circuits with no extra
		// query before it touches the settings option.
		$this->settings->maybe_migrate_defaults();

		// Record which plugin registered each post type at runtime (a one-time
		// backtrace, no hardcoded plugin list) so Content::source() can name a CPT's
		// vendor — e.g. a generic "Products" type attributed to FluentCart vs
		// WooCommerce. Enabled on our settings screen AND on the Agent Preview REST
		// call that resolves the picker's sources; scoped tight so the backtrace cost
		// is never paid on a normal front-end page load. The REST route is matched on
		// the request URI because REST_REQUEST isn't defined this early (plugins_loaded).
		$on_settings = is_admin() && 'agentimus' === ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no state change.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$on_preview  = false !== strpos( $request_uri, 'agentimus/v1/preview' );
		if ( $on_settings || $on_preview ) {
			Content::watch_origins();
		}

		Cache::register_flush_hooks();
		SeoContext::watch(); // One cache flush per solo/coexist flip — cached surfaces bake the mode in.
		Sitemap::register(); // Core's sitemap stands down while the solo-mode promotion is on.
		CachePurge::boot();
		MarkdownCache::register();
		BotRanges::boot(); // Daily refresh of published bot-IP-range files (self-heals its schedule).
		RouteProbe::watch(); // Async self-check of /llms.txt and the home <head>; re-queued when the plugin/theme mix changes.
		McpToken::watch(); // Bearer connection-token auth, honored only on the MCP route.
		Oauth\Server::watch(); // OAuth access-token auth — same route, next seat in the inspector line.

		( new Endpoints( $this->settings ) )->register();
		( new Oauth\Consent( $this->settings ) )->register(); // The /agentimus/connect consent page.
		( new Tombstones() )->register(); // Records removals for the change feed (self-gates on enable_changes).
		( new Schema( $this->settings ) )->register();
		( new Seo( $this->settings ) )->register(); // Solo-mode head output (per-page SEO title; cards + canonical land here). Stands down at request time when an SEO suite is active.
		( new EditorPanel( $this->settings ) )->register(); // One "Agentimus" editor box: JSON-LD + AI Readability as tabs.
		( new Topics( $this->settings ) )->register();
		( new Description( $this->settings ) )->register();
		( new AskAi( $this->settings ) )->register(); // "Ask AI about this post" bar after singular post content (enable_ask_ai, on by default).
		( new ShareCopy( $this->settings ) )->register(); // REST for the editor panel's Share tab — per-network share drafts; the tab itself is composed by EditorPanel.
		( new Assist( $this->settings ) )->register(); // "Draft with AI" for the description + topics fields (no-ops without a configured AI provider).
		( new InternalLinks( $this->settings ) )->register(); // "Link to your own posts": local-signal suggestions + one optional AI dressing call; Insert is a plain editor edit.
		( new Assistant( $this->settings ) )->register(); // The in-admin writing assistant (nav-bar quill → drawer); gated on enable_agent_writes + a provider.
		( new AssistantEditor( $this->settings ) )->register(); // Its block-editor half: per-Image-block Generate + the featured-image panel.
		( new Rest( $this->settings ) )->register();
		( new Admin( $this->settings ) )->register();
		( new Discovery\Module( $this->settings ) )->register();
		( new Activity\Module( $this->settings ) )->register();
		( new WebMcp( $this->settings ) )->register();
		( new Exposure( $this->settings ) )->register();
		// Agent Access must boot BEFORE the Registrar: the Registrar wraps each ability's execute
		// callback with AgentAccess's observer at registration time, and the ability listener has
		// to be hooked before any ability can run.
		( new AgentAccess\Module( $this->settings ) )->register(); // Records app-password lifecycle + ability invocations. Inert where neither exists.
		( new Digest\Module( $this->settings ) )->register(); // Weekly email digest: the cron handler, the one-click stop endpoint, and a self-healing schedule.
		( new ReviewBadge( $this->settings ) )->register(); // Review-queue count on the admin menu + Heartbeat live updates (admin-only; no-ops on the front end).
		( new Abilities\AdapterBootstrap( $this->settings ) )->register(); // Boots the bundled MCP Adapter when the owner opts in (inert otherwise).
		( new Abilities\Registrar( $this->settings ) )->register(); // Exposes our own read capabilities to the WP admin AI + MCP (no-ops pre-6.9).

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
		Activity\UnknownSources::install();
		AgentAccess\Table::install();
		Activity\Module::schedule();
		Visibility\Table::install();
		Visibility\Module::schedule();
		Digest\Module::schedule();
		Discovery\WellKnown::add_rules();
		self::flush_rewrites_in_context();
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
		// The signature carries the plugin version, so this fires once after an update —
		// which is exactly when a generated document can be stale in a way that MATTERS.
		// (1.21.2: llms.txt used to tell agents they could fetch any page with
		// `Accept: text/markdown`; that route is now off by default, so a cached copy
		// would keep advertising a dead end — and invite the very request that poisons a
		// CDN cache.) Rebuilding the documents on update is cheap and they are rebuilt
		// lazily anyway.
		Cache::flush();
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
			// Run the carried-over config through sanitize() rather than trusting it
			// verbatim: it applies the current allow-lists, escaping and size caps, and
			// drops any retired keys, so an old option can't seed stale/garbage values.
			add_option( Settings::OPTION, ( new Settings() )->sanitize( $legacy ) );
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
		Digest\Module::unschedule();
		BotRanges::clear_schedule();
		RouteProbe::clear_schedule();
		wp_clear_scheduled_hook( 'agentimus_warm_llms_full' );
		wp_clear_scheduled_hook( Visibility\Module::HOOK_ONCE ); // the one-off "run now" event, if queued.
		self::flush_rewrites_in_context();
	}

	/**
	 * Flush rewrite rules correctly whether or not we're inside switch_to_blog().
	 *
	 * flush_rewrite_rules() regenerates from the GLOBAL $wp_rewrite, whose permalink_structure is
	 * cached for the ORIGINATING site and is NOT re-initialised by switch_to_blog(). Called while
	 * switched to a sub-site — the network activation/deactivation loops, and wp_initialize_site for
	 * a new sub-site — it would write the MAIN site's rules (and only the currently-loaded plugins'
	 * rules) into that sub-site, 404ing its post URLs. In a switched context we instead DROP the
	 * cached rules: the sub-site regenerates correct ones (its own permalink structure + its own
	 * active plugins, including our add_rules() at init) on its next request. The real, immediate
	 * flush is right only in the non-switched, single-site path.
	 *
	 * @return void
	 */
	private static function flush_rewrites_in_context() {
		if ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			delete_option( 'rewrite_rules' );
			return;
		}
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
