<?php
/**
 * Boots the BUNDLED WordPress MCP Adapter — when, and only when, the owner opts in.
 *
 * {@see Registrar::register_mcp_server()} has always been ready to publish our scoped
 * read-only server the moment something fires `mcp_adapter_init` — but the adapter
 * library isn't on WordPress.org, so on a normal install nothing ever fires it and the
 * server exists only in theory. This class closes that gap: we ship the adapter in
 * vendor/ (Composer: wordpress/mcp-adapter) and load it here behind the
 * `enable_mcp_server` switch.
 *
 * This is the plugin's ONE exception to the no-vendor-autoloader rule declared in
 * agentimus.php: the adapter is a SHARED library (WooCommerce and others bundle it
 * too), and the Jetpack autoloader (`vendor/autoload_packages.php`) is the ecosystem's
 * arbitration mechanism — every bundler registers its copy and the newest version wins,
 * instead of first-plugin-loaded winning with a stale copy.
 *
 * The gate chain, in order, and why each exists:
 *
 *   1. `enable_mcp_server` off (the default) → return. Zero footprint: the autoloader
 *      is never required, the adapter classes never load, nothing changes.
 *   2. No Abilities API (WP < 6.9) → return. There would be nothing to serve.
 *   3. If NOTHING can supply the adapter class, require our vendor autoloader —
 *      guarded by file_exists so a bare git checkout (no `composer install`) degrades
 *      to a silent no-op instead of a fatal.
 *   4. Suppress the adapter's own "default server" (a generic discover/execute-any-
 *      ability endpoint) — but ONLY when no one else had actively booted the adapter
 *      before us. The owner opted into "run the Agentimus server", not "run every
 *      machine surface the library can offer". A plugin that wants the default server
 *      back can `add_filter( 'mcp_adapter_create_default_server', '__return_true', 20 )`.
 *   5. `McpAdapter::instance()` — idempotent singleton; it defers its real work (and
 *      `mcp_adapter_init`, where the Registrar creates our server) to rest_api_init.
 *
 * The "who booted it" probe in step 4 is deliberately `class_exists( ..., false )`:
 * the standalone adapter plugin loads its classes at include time, so by `init` the
 * class is DECLARED and we stand down. A plugin that merely bundles the library (the
 * class is autoloadABLE but untouched) has expressed no opinion yet — and if its copy
 * resolves ahead of ours, that's the Jetpack autoloader doing its job; the filter is
 * still ours to set because the OWNER's toggle is the only opt-in that has happened.
 *
 * @package Agentimus
 */

namespace Agentimus\Abilities;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class AdapterBootstrap {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Shared settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the conditional bootstrap. init:5 — late enough that every plugin file has
	 * loaded (an external bootstrapper has shown its hand, see the class doc), early
	 * enough to beat the adapter's own WP-CLI path (init:20) and any rest_api_init.
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_bootstrap' ), 5 );
	}

	/**
	 * Load and initialise the bundled adapter when the owner opted in. Public so an
	 * integration test can drive the gate chain directly instead of re-running boot.
	 */
	public function maybe_bootstrap() {
		if ( ! $this->settings->enabled( 'enable_mcp_server' ) ) {
			return;
		}
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // WP < 6.9 without the Abilities API plugin — nothing to serve.
		}

		/**
		 * Escape hatch: veto the bundled-adapter bootstrap entirely (e.g. a host that
		 * manages the adapter itself), independent of the owner's setting.
		 *
		 * @param bool $bootstrap Whether Agentimus may boot the bundled MCP Adapter.
		 */
		if ( ! apply_filters( 'agentimus_bootstrap_mcp_adapter', true ) ) {
			return;
		}

		$externally_booted = class_exists( '\WP\MCP\Core\McpAdapter', false );

		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			$autoload = AGENTIMUS_DIR . 'vendor/autoload_packages.php';
			if ( ! file_exists( $autoload ) ) {
				return; // Bare git checkout without `composer install` — never fatal.
			}
			require_once $autoload;
			if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
				return; // Vendor tree present but incomplete — stand down.
			}
		}

		if ( ! $externally_booted ) {
			add_filter( 'mcp_adapter_create_default_server', '__return_false' );
		}

		\WP\MCP\Core\McpAdapter::instance();
	}
}
