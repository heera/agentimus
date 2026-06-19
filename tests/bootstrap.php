<?php
/**
 * PHPUnit bootstrap for Agentimus's pure-logic unit tests.
 *
 * These tests exercise the discovery contract logic (Resource validation,
 * the Registry collector, Envelope derivation) WITHOUT a full WordPress
 * install. We define the minimal WordPress surface the tested classes touch,
 * stub the one in-plugin class with heavy WP dependencies (Content), and
 * autoload the rest of the Agentimus\ classes straight from inc/.
 *
 * @package Agentimus\Tests
 */

namespace {

	error_reporting( E_ALL & ~E_DEPRECATED );

	$plugin_dir = dirname( __DIR__ ); // .../plugins/agentimus

	if ( ! defined( 'ABSPATH' ) )            define( 'ABSPATH', sys_get_temp_dir() . '/agentimus-test-abspath/' );
	if ( ! defined( 'WPINC' ) )              define( 'WPINC', 'wp-includes' );
	if ( ! defined( 'WP_CONTENT_DIR' ) )     define( 'WP_CONTENT_DIR', dirname( dirname( $plugin_dir ) ) );
	if ( ! defined( 'WP_PLUGIN_DIR' ) )      define( 'WP_PLUGIN_DIR', dirname( $plugin_dir ) );
	// FILE is built from the fixed plugin slug (not $plugin_dir) so plugin_basename()
	// is deterministic regardless of the checkout folder name — CI checks the repo out
	// into a folder named after the GitHub repo, which need not equal the slug. DIR
	// stays the real on-disk path the bootstrap autoloader needs to locate inc/.
	if ( ! defined( 'AGENTIMUS_FILE' ) )      define( 'AGENTIMUS_FILE', dirname( $plugin_dir ) . '/agentimus/agentimus.php' );
	if ( ! defined( 'AGENTIMUS_DIR' ) )       define( 'AGENTIMUS_DIR', $plugin_dir . '/' );
	if ( ! defined( 'AGENTIMUS_VERSION' ) )   define( 'AGENTIMUS_VERSION', '1.0.0' );
	if ( ! defined( 'AGENTIMUS_CANONICAL_HOOK' ) )  define( 'AGENTIMUS_CANONICAL_HOOK', 'wpdiscovery_register' );
	if ( ! defined( 'AGENTIMUS_ALIAS_HOOK' ) )  define( 'AGENTIMUS_ALIAS_HOOK', 'agentimus_register' );
	if ( ! defined( 'HOUR_IN_SECONDS' ) )          define( 'HOUR_IN_SECONDS', 3600 );
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );
	if ( ! defined( 'DAY_IN_SECONDS' ) )           define( 'DAY_IN_SECONDS', 86400 );

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code;
			public $message;
			public function __construct( $code = '', $message = '' ) {
				$this->code    = $code;
				$this->message = $message;
			}
			public function get_error_message() { return $this->message; }
			public function get_error_code() { return $this->code; }
		}
	}

	// --- Minimal WordPress function surface (only what the tested code calls). ---
	if ( ! function_exists( 'is_wp_error' ) )           { function is_wp_error( $t ) { return $t instanceof \WP_Error; } }
	if ( ! function_exists( '__' ) )                    { function __( $s, $d = null ) { return $s; } }
	if ( ! function_exists( 'sanitize_key' ) )          { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }
	if ( ! function_exists( 'sanitize_text_field' ) )   { function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); } }
	if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_file_name' ) )    { function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $n ); } }
	if ( ! function_exists( 'sanitize_email' ) )        { function sanitize_email( $e ) { return trim( (string) $e ); } }
	if ( ! function_exists( 'esc_url_raw' ) )           { function esc_url_raw( $u, $p = null ) { return trim( (string) $u ); } }
	if ( ! function_exists( 'esc_url' ) )               { function esc_url( $u, $p = null ) { return trim( (string) $u ); } }
	if ( ! function_exists( 'is_email' ) )              { function is_email( $e ) { return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; } }
	if ( ! function_exists( 'wp_normalize_path' ) )     { function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); } }
	if ( ! function_exists( 'wp_strip_all_tags' ) )     { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'plugin_basename' ) )       { function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); } }
	if ( ! function_exists( 'home_url' ) )              { function home_url( $path = '' ) { $b = 'https://example.test'; $path = (string) $path; return '' === $path ? $b . '/' : $b . ( '/' === $path[0] ? $path : '/' . $path ); } }
	if ( ! function_exists( 'get_bloginfo' ) )          { function get_bloginfo( $k = '' ) { $m = array( 'name' => 'Test Site', 'description' => 'A test site.', 'language' => 'en-US' ); return isset( $m[ $k ] ) ? $m[ $k ] : ''; } }
	if ( ! function_exists( 'get_site_icon_url' ) )     { function get_site_icon_url() { return ''; } }
	if ( ! function_exists( 'get_privacy_policy_url' ) ) { function get_privacy_policy_url() { return ''; } }
	if ( ! function_exists( 'get_feed_link' ) )         { function get_feed_link( $feed = '' ) { return 'https://example.test/feed/'; } }
	if ( ! function_exists( 'apply_filters' ) )         { function apply_filters( $tag, $value = null ) { return $value; } }
	if ( ! function_exists( 'do_action' ) )             { function do_action( $tag ) { /* noop */ } }
	if ( ! function_exists( 'add_action' ) )            { function add_action() { return true; } }
	if ( ! function_exists( 'add_filter' ) )            { function add_filter() { return true; } }
	if ( ! function_exists( 'did_action' ) )            { function did_action( $tag ) { return ! empty( $GLOBALS['_af_did_actions'][ $tag ] ) ? 1 : 0; } }
	// Stateful option store so tests can set values (e.g. suppressed_resources)
	// and read them back. Empty by default, so it behaves exactly like returning
	// the default until a test writes — reset between tests via _af_reset_options().
	$GLOBALS['_af_options'] = array();
	$GLOBALS['_af_did_actions'] = array(); // Tests flip e.g. 'mcp_adapter_init' to exercise the server-present path.
	if ( ! function_exists( 'get_option' ) )            { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['_af_options'] ) ? $GLOBALS['_af_options'][ $k ] : $d; } }
	if ( ! function_exists( 'update_option' ) )         { function update_option( $k, $v ) { $GLOBALS['_af_options'][ $k ] = $v; return true; } }
	if ( ! function_exists( 'add_option' ) )            { function add_option( $k, $v ) { $GLOBALS['_af_options'][ $k ] = $v; return true; } }
	function _af_reset_options() { $GLOBALS['_af_options'] = array(); $GLOBALS['_af_did_actions'] = array(); unset( $GLOBALS['_af_available_post_types'] ); }
	// Always-miss transient stubs so cached endpoint bodies (e.g. security.txt)
	// recompute deterministically in tests.
	if ( ! function_exists( 'get_transient' ) )         { function get_transient( $k ) { return false; } }
	if ( ! function_exists( 'set_transient' ) )         { function set_transient( $k, $v, $t = 0 ) { return true; } }
	if ( ! function_exists( 'delete_transient' ) )      { function delete_transient( $k ) { return true; } }
	if ( ! function_exists( 'wp_parse_args' ) )         { function wp_parse_args( $a, $d = array() ) { if ( is_object( $a ) ) { $a = get_object_vars( $a ); } elseif ( ! is_array( $a ) ) { $a = array(); } return array_merge( $d, $a ); } }
	if ( ! function_exists( 'wp_json_encode' ) )        { function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); } }
	if ( ! function_exists( 'trailingslashit' ) )       { function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; } }
	if ( ! function_exists( 'rest_url' ) )              { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ); } }
	if ( ! function_exists( 'absint' ) )                { function absint( $n ) { return abs( (int) $n ); } }

	// Autoload Agentimus\ classes from inc/ (runtime uses its own loader).
	spl_autoload_register(
		function ( $class ) {
			if ( 0 !== strpos( $class, 'Agentimus\\' ) ) {
				return;
			}
			$rel  = str_replace( '\\', '/', substr( $class, strlen( 'Agentimus\\' ) ) );
			$file = AGENTIMUS_DIR . 'inc/' . $rel . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	);

	// Reset the Registry singleton between tests (it is process-global).
	function _af_reset_registry() {
		if ( ! class_exists( 'Agentimus\\Discovery\\Registry' ) ) {
			return;
		}
		$prop = new \ReflectionProperty( 'Agentimus\\Discovery\\Registry', 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	require __DIR__ . '/../vendor/autoload.php';
}

namespace Agentimus {
	// Stub the one in-plugin dependency that needs the WP post-type registry,
	// so Settings::defaults() works without loading the real Content class. The
	// available() list is overridable via $GLOBALS['_af_available_post_types'] so
	// a test can introduce a third public type (e.g. a CPT) and prove the safe
	// default never widens to "all public types".
	if ( ! class_exists( 'Agentimus\\Content', false ) ) {
		class Content {
			public static function available() {
				return isset( $GLOBALS['_af_available_post_types'] )
					? (array) $GLOBALS['_af_available_post_types']
					: array( 'post', 'page' );
			}
			public static function source( $post_type ) { return ''; }
		}
	}
}
