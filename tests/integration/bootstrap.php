<?php
/**
 * Integration-test bootstrap: boot Agentimus inside a REAL WordPress install and a
 * real database via the WordPress PHPUnit test library, so the seams the fast unit
 * suite has to stub — dbDelta, raw $wpdb queries, REST dispatch, rewrite routing,
 * real escaping, cron, activation — are exercised for real.
 *
 * Requires the WP test library installed by bin/install-wp-tests.sh. Run with:
 *   composer test:integration
 *
 * @package Agentimus\Tests\Integration
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	fwrite(
		STDERR,
		"Could not find {$_tests_dir}/includes/functions.php.\n"
		. "Install the WordPress test library first, e.g.:\n"
		. "  bin/install-wp-tests.sh wordpress_test root root 127.0.0.1:3306 latest\n"
	);
	exit( 1 );
}

// WordPress >= 5.9 loads its assertions through the Yoast PHPUnit polyfills; point the
// test suite at our composer copy so it doesn't have to be installed globally.
$_polyfills = dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills';
if ( is_dir( $_polyfills ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills );
}

require_once "{$_tests_dir}/includes/functions.php";

// Load the plugin as early as a mu-plugin, so it is active for the whole run and its
// own plugins_loaded → boot() fires exactly as in production.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/agentimus.php';
	}
);

require "{$_tests_dir}/includes/bootstrap.php";
