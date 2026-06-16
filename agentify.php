<?php
/**
 * Plugin Name:       Agentify
 * Plugin URI:        https://github.com/heera/agentify
 * Description:       Make any WordPress site legible to AI agents and crawlers — llms.txt, a full-text edition, markdown delivery, JSON-LD, and content-signal robots rules. Lightweight, no SEO bloat.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Sheikh Heera
 * Author URI:        https://heera.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentify
 * Domain Path:       /languages
 *
 * @package Agentify
 */

namespace Agentify;

defined( 'ABSPATH' ) || exit;

define( 'AGENTIFY_VERSION', '1.0.0' );
define( 'AGENTIFY_FILE', __FILE__ );
define( 'AGENTIFY_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTIFY_URL', plugin_dir_url( __FILE__ ) );

/**
 * The public registration actions the discovery standard is built on.
 * AGENTIFY_CANONICAL_HOOK is the canonical, vendor-neutral hook of the WP_Discovery
 * protocol; AGENTIFY_DISCOVERY_HOOK is a back-compat alias the engine also fires.
 * Providers SHOULD hook the canonical name.
 */
define( 'AGENTIFY_CANONICAL_HOOK', 'wpdiscovery_register' );
define( 'AGENTIFY_DISCOVERY_HOOK', 'agentify_discovery_register' );

/**
 * Minimal PSR-4 autoloader: Agentify\Foo\Bar → inc/Foo/Bar.php.
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
		$path     = AGENTIFY_DIR . 'inc/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

// The discovery facade is a global (un-namespaced) class, so it can't go through
// the autoloader above — load it eagerly for third-party plugins.
require AGENTIFY_DIR . 'inc/discovery-api.php';

add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->boot();
	}
);

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'deactivate' ) );
