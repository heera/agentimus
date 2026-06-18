<?php
/**
 * Plugin Name:       Heera Discovery
 * Plugin URI:        https://github.com/heera/heera-agent-discovery
 * Description:       An AI-discovery layer for your site: a /.well-known discovery document, machine-readable pages (llms.txt, markdown, JSON-LD), AI-crawl controls, and a first-party agent-activity log. Lightweight, no SEO bloat.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Sheikh Heera
 * Author URI:        https://heera.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       heera-agent-discovery
 *
 * @package HeeraAgentDiscovery
 */

namespace HeeraAgentDiscovery;

defined( 'ABSPATH' ) || exit;

define( 'HEERA_AGENT_DISCOVERY_VERSION', '1.0.0' );
define( 'HEERA_AGENT_DISCOVERY_FILE', __FILE__ );
define( 'HEERA_AGENT_DISCOVERY_DIR', plugin_dir_path( __FILE__ ) );
define( 'HEERA_AGENT_DISCOVERY_URL', plugin_dir_url( __FILE__ ) );

/**
 * The public registration actions the discovery standard is built on.
 * HEERA_AGENT_DISCOVERY_CANONICAL_HOOK is the canonical, vendor-neutral hook of the WP_Discovery
 * protocol; HEERA_AGENT_DISCOVERY_ALIAS_HOOK is a back-compat alias the engine also fires.
 * Providers SHOULD hook the canonical name.
 */
define( 'HEERA_AGENT_DISCOVERY_CANONICAL_HOOK', 'wpdiscovery_register' );
define( 'HEERA_AGENT_DISCOVERY_ALIAS_HOOK', 'heera_agent_discovery_register' );

/**
 * Minimal PSR-4 autoloader: HeeraAgentDiscovery\Foo\Bar → inc/Foo/Bar.php.
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
		$path     = HEERA_AGENT_DISCOVERY_DIR . 'inc/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// The discovery facade is a global (un-namespaced) class, so it can't go through
// the autoloader above — load it eagerly for third-party plugins.
require HEERA_AGENT_DISCOVERY_DIR . 'inc/discovery-api.php';

add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->boot();
	}
);

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'deactivate' ) );
