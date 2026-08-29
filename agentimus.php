<?php
/**
 * Plugin Name:       Agentimus
 * Plugin URI:        https://github.com/heera/agentimus
 * Description:       Make your site agent-ready (llms.txt, markdown, JSON-LD, a /.well-known discovery document, AI-crawl controls, agent-activity log) AND let the AI tools you use operate it over MCP — read your data and, opt-in, draft/edit/publish posts. Lightweight, no SEO bloat.
 * Version:           1.48.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Sheikh Heera
 * Author URI:        https://heera.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentimus
 *
 * @package Agentimus
 */

namespace Agentimus;

defined( 'ABSPATH' ) || exit;

define( 'AGENTIMUS_VERSION', '1.48.0' );
define( 'AGENTIMUS_FILE', __FILE__ );
define( 'AGENTIMUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTIMUS_URL', plugin_dir_url( __FILE__ ) );

/**
 * The public registration actions the discovery standard is built on.
 * AGENTIMUS_CANONICAL_HOOK is the canonical, vendor-neutral hook of the WP_Discovery
 * protocol; AGENTIMUS_ALIAS_HOOK is a back-compat alias the engine also fires.
 * Providers SHOULD hook the canonical name.
 */
define( 'AGENTIMUS_CANONICAL_HOOK', 'wpdiscovery_register' );
define( 'AGENTIMUS_ALIAS_HOOK', 'agentimus_register' );

/**
 * Minimal PSR-4 autoloader: Agentimus\Foo\Bar → inc/Foo/Bar.php.
 *
 * A whole framework would undercut the point of this plugin (lightweight
 * machine-readability), so we ship a hand-rolled loader and nothing else.
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( __NAMESPACE__ . '\\' ) );
		$path     = AGENTIMUS_DIR . 'inc/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// The discovery facade is a global (un-namespaced) class, so it can't go through
// the autoloader above — load it eagerly for third-party plugins.
require AGENTIMUS_DIR . 'inc/discovery-api.php';

// Theme-facing template tags. Global functions, so they bypass the autoloader
// too — and eager, so a theme may call them at any point in the load order.
require AGENTIMUS_DIR . 'inc/template-tags.php';

add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->boot();
	}
);

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'deactivate' ) );

// Multisite: when a new site is created while Agentimus is network-active, give it
// the same per-site setup (tables, defaults, prune cron, rewrite rules) at once,
// rather than waiting for that site's first boot to self-heal.
add_action(
	'wp_initialize_site',
	static function ( $new_site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
			return;
		}
		switch_to_blog( (int) $new_site->blog_id );
		Plugin::install_site();
		restore_current_blog();
	},
	100
);

// Multisite: when a SUB-SITE is deleted, core drops only its stock tables. Add ours so they don't
// survive forever as orphans (uninstall can't reach a site that no longer exists). Keep this list
// in sync with the DROP TABLE calls in uninstall.php.
add_filter(
	'wpmu_drop_tables',
	static function ( $tables, $blog_id ) {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( (int) $blog_id );
		foreach ( array( 'agentimus_agent_hits', 'agentimus_ai_referrals', 'agentimus_flagged_ips', 'agentimus_unknown_sources', 'agentimus_agent_events', 'agentimus_visibility', 'agentimus_edge_hourly', 'agentimus_bing_daily', 'agentimus_search_queries' ) as $agentimus_table ) {
			$tables[] = $prefix . $agentimus_table;
		}
		return $tables;
	},
	10,
	2
);
