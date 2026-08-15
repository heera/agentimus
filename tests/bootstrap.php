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

	error_reporting( E_ALL );

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
	if ( ! defined( 'YEAR_IN_SECONDS' ) )          define( 'YEAR_IN_SECONDS', 365 * 86400 );
	// wpdb's output-format constants. Only reached by tests that stand up a fake
	// $wpdb, but undefined they degrade to the bare string in PHP 7 and to a
	// fatal in PHP 8 — a difference that would only ever show up in CI.
	if ( ! defined( 'OBJECT' ) )                   define( 'OBJECT', 'OBJECT' );
	if ( ! defined( 'ARRAY_A' ) )                  define( 'ARRAY_A', 'ARRAY_A' );
	if ( ! defined( 'ARRAY_N' ) )                  define( 'ARRAY_N', 'ARRAY_N' );
	// A wp-config-style salt so Visibility\Crypto derives its key from the salt path
	// (what production uses) rather than the no-salts option fallback.
	if ( ! defined( 'AUTH_KEY' ) )                 define( 'AUTH_KEY', 'agentimus-test-auth-key-0123456789abcdef' );

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code;
			public $message;
			public $data;
			public function __construct( $code = '', $message = '', $data = '' ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}
			public function get_error_message() { return $this->message; }
			public function get_error_code() { return $this->code; }
			public function get_error_data() { return $this->data; }
		}
	}

	if ( ! class_exists( 'WP_User' ) ) {
		class WP_User {
			public $ID = 1;
			public $user_login = 'admin';
		}
	}

	// Minimal REST request/response pair — just the surface the OAuth
	// advertise filter and route callbacks touch (status, headers, route).
	if ( ! class_exists( 'WP_REST_Response' ) ) {
		class WP_REST_Response {
			public $data;
			private $status;
			private $headers = array();
			public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = (int) $status; }
			public function get_status() { return $this->status; }
			public function set_status( $s ) { $this->status = (int) $s; }
			public function header( $key, $value ) { $this->headers[ $key ] = $value; }
			public function get_headers() { return $this->headers; }
		}
	}
	if ( ! class_exists( 'WP_REST_Request' ) ) {
		class WP_REST_Request {
			private $route;
			private $params = array();
			public function __construct( $method = '', $route = '' ) { $this->route = (string) $route; }
			public function get_route() { return $this->route; }
			public function set_param( $k, $v ) { $this->params[ $k ] = $v; }
			public function get_param( $k ) { return isset( $this->params[ $k ] ) ? $this->params[ $k ] : null; }
			public function get_params() { return $this->params; }
		}
	}

	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public $ID = 0;
			public $post_content = '';
			public $post_excerpt = '';
			public $post_title = '';
			public $post_status = 'publish';
			public $post_type = 'post';
			public $post_password = '';
			public $post_author = 1;
			public function __construct( array $d = array() ) {
				foreach ( $d as $k => $v ) { $this->$k = $v; }
			}
		}
	}

	// --- Minimal WordPress function surface (only what the tested code calls). ---
	/**
	 * Reflection accessor for tests, version-aware.
	 *
	 * setAccessible() is REQUIRED on PHP 7.4 (which production still runs) to reach
	 * a private method, has been a no-op since 8.1, and is deprecated as of 8.5 —
	 * so calling it unconditionally makes the suite noisy on a modern local PHP
	 * while removing it breaks the version we actually ship against.
	 *
	 * @param \ReflectionMethod|\ReflectionProperty $ref Reflection object.
	 * @return \ReflectionMethod|\ReflectionProperty
	 */
	function _af_accessible( $ref ) {
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true ); // phpcs:ignore -- the one place this call belongs.
		}
		return $ref;
	}

	if ( ! function_exists( 'is_wp_error' ) )           { function is_wp_error( $t ) { return $t instanceof \WP_Error; } }
	if ( ! function_exists( '__' ) )                    { function __( $s, $d = null ) { return $s; } }
		if ( ! function_exists( 'esc_html' ) )              { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
		if ( ! function_exists( 'esc_attr' ) )              { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
		if ( ! function_exists( 'esc_html__' ) )            { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
		if ( ! function_exists( 'esc_attr__' ) )            { function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
	if ( ! function_exists( 'sanitize_key' ) )          { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }
	if ( ! function_exists( 'sanitize_text_field' ) )   { function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) ); } }
	if ( ! function_exists( 'wp_unslash' ) )            { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }
	if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( wp_strip_all_tags( (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_file_name' ) )    { function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $n ); } }
	if ( ! function_exists( 'sanitize_email' ) )        { function sanitize_email( $e ) { $e = trim( (string) $e ); return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : ''; } }
	if ( ! function_exists( 'esc_url_raw' ) )           { function esc_url_raw( $u, $p = null ) { return trim( (string) $u ); } }
	if ( ! function_exists( 'esc_url' ) )               { function esc_url( $u, $p = null ) { return trim( (string) $u ); } }
	if ( ! function_exists( 'wp_parse_url' ) )          { function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); } }
	if ( ! function_exists( 'is_email' ) )              { function is_email( $e ) { return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; } }
	if ( ! function_exists( 'wp_normalize_path' ) )     { function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); } }
	// Core removes <script>/<style> CONTENTS before stripping tags — without that the
	// stub is weaker than production and would let a test pass on markup WordPress
	// would have neutered. sanitize_text_field()/sanitize_textarea_field() both route
	// through it, so they inherit the same behaviour.
	if ( ! function_exists( 'wp_strip_all_tags' ) )     { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); } }
	if ( ! function_exists( 'get_locale' ) )            { function get_locale() { return 'en_US'; } }
	// Post-body sanitiser stub: keeps the tags the Assistant's preview allows, drops
	// script/style etc. — close enough to assert "dangerous input doesn't survive".
	if ( ! function_exists( 'wp_kses_post' ) )          { function wp_kses_post( $s ) { $s = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $s ); return strip_tags( $s, '<h2><h3><p><ul><ol><li><strong><em><a><blockquote><code><pre>' ); } }
	if ( ! function_exists( 'number_format_i18n' ) )    { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }
	if ( ! function_exists( 'wp_date' ) )               { function wp_date( $format, $timestamp = null ) { return gmdate( $format, null === $timestamp ? time() : (int) $timestamp ); } }
	if ( ! function_exists( 'plugin_basename' ) )       { function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); } }
	if ( ! function_exists( 'home_url' ) )              { function home_url( $path = '' ) { $b = 'https://example.test'; $path = (string) $path; return '' === $path ? $b . '/' : $b . ( '/' === $path[0] ? $path : '/' . $path ); } }
	if ( ! function_exists( 'admin_url' ) )             { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); } }
	if ( ! function_exists( 'get_bloginfo' ) )          { function get_bloginfo( $k = '' ) { $m = array( 'name' => 'Test Site', 'description' => 'A test site.', 'language' => 'en-US' ); return isset( $m[ $k ] ) ? $m[ $k ] : ''; } }
	if ( ! function_exists( 'wp_get_current_user' ) )   { function wp_get_current_user() { return new WP_User(); } }
	if ( ! function_exists( 'wp_nonce_field' ) )        { function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) { $f = '<input type="hidden" name="' . $name . '" value="test-nonce-' . $action . '">'; if ( $display ) { echo $f; } return $f; } }
	if ( ! function_exists( 'get_site_icon_url' ) )     { function get_site_icon_url() { return ''; } }
	if ( ! function_exists( 'get_privacy_policy_url' ) ) { function get_privacy_policy_url() { return ''; } }
	if ( ! function_exists( 'get_feed_link' ) )         { function get_feed_link( $feed = '' ) { return 'https://example.test/feed/'; } }
	// Minimal-but-functional filter registry so tests can simulate a third-party
	// plugin hooking a filter (e.g. the robustness suite feeds malformed values).
	// With no filters registered it behaves exactly like the old passthrough.
	$GLOBALS['_af_filters'] = array();
	if ( ! function_exists( 'apply_filters' ) )         { function apply_filters( $tag, $value = null ) { $extra = array_slice( func_get_args(), 2 ); if ( ! empty( $GLOBALS['_af_filters'][ $tag ] ) ) { foreach ( $GLOBALS['_af_filters'][ $tag ] as $cb ) { $value = call_user_func_array( $cb, array_merge( array( $value ), $extra ) ); } } return $value; } }
	if ( ! function_exists( 'do_action' ) )             { function do_action( $tag ) { /* noop */ } }
	if ( ! function_exists( 'add_action' ) )            { function add_action() { return true; } }
	if ( ! function_exists( 'add_filter' ) )            { function add_filter( $tag, $cb, $priority = 10, $args = 1 ) { $GLOBALS['_af_filters'][ $tag ][] = $cb; return true; } }
	if ( ! function_exists( 'remove_all_filters' ) )    { function remove_all_filters( $tag = false ) { if ( false === $tag ) { $GLOBALS['_af_filters'] = array(); } else { unset( $GLOBALS['_af_filters'][ $tag ] ); } return true; } }
	// Without this a test that adds a filter can never take it back, and it leaks
	// into every test that runs after it — a whole-suite hazard, not a local one.
	if ( ! function_exists( 'remove_filter' ) )         { function remove_filter( $tag, $cb, $priority = 10 ) { if ( empty( $GLOBALS['_af_filters'][ $tag ] ) ) { return false; } foreach ( $GLOBALS['_af_filters'][ $tag ] as $i => $registered ) { if ( $registered === $cb ) { unset( $GLOBALS['_af_filters'][ $tag ][ $i ] ); return true; } } return false; } }
	if ( ! function_exists( 'did_action' ) )            { function did_action( $tag ) { return ! empty( $GLOBALS['_af_did_actions'][ $tag ] ) ? 1 : 0; } }
	// Minimal object-cache surface with faithful add-semantics, so the build-lock
	// mutex (Cache::acquire_lock/release_lock) can be exercised: wp_cache_add writes
	// only when the key is absent. Reset between tests via _af_reset_options().
	if ( ! function_exists( 'wp_cache_add' ) )          { function wp_cache_add( $key, $data, $group = '', $ttl = 0 ) { $k = $group . ':' . $key; if ( isset( $GLOBALS['_af_cache'][ $k ] ) ) { return false; } $GLOBALS['_af_cache'][ $k ] = $data; return true; } }
	if ( ! function_exists( 'wp_cache_set' ) )          { function wp_cache_set( $key, $data, $group = '', $ttl = 0 ) { $GLOBALS['_af_cache'][ $group . ':' . $key ] = $data; return true; } }
	if ( ! function_exists( 'wp_cache_get' ) )          { function wp_cache_get( $key, $group = '' ) { $k = $group . ':' . $key; return isset( $GLOBALS['_af_cache'][ $k ] ) ? $GLOBALS['_af_cache'][ $k ] : false; } }
	if ( ! function_exists( 'wp_cache_delete' ) )       { function wp_cache_delete( $key, $group = '' ) { unset( $GLOBALS['_af_cache'][ $group . ':' . $key ] ); return true; } }
	// HTTP round-trip stubs so the Visibility provider layer (token caps, response
	// parsing, error/shape handling) is testable without a network. wp_remote_post
	// records the last request in $_af_http_last and returns the next queued response
	// from $_af_http_queue (a WP_Error or a { response:{code}, body, headers } array).
	if ( ! function_exists( 'wp_remote_post' ) )        { function wp_remote_post( $url, $args = array() ) { $GLOBALS['_af_http_last'] = array( 'url' => $url, 'args' => $args ); $q = &$GLOBALS['_af_http_queue']; return ! empty( $q ) ? array_shift( $q ) : array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ); } }
	if ( ! function_exists( 'wp_remote_get' ) )         { function wp_remote_get( $url, $args = array() ) { $GLOBALS['_af_http_last'] = array( 'url' => $url, 'args' => $args ); $q = &$GLOBALS['_af_http_queue']; return ! empty( $q ) ? array_shift( $q ) : array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ); } }
	if ( ! function_exists( 'wp_remote_request' ) )     { function wp_remote_request( $url, $args = array() ) { $GLOBALS['_af_http_last'] = array( 'url' => $url, 'args' => $args ); $q = &$GLOBALS['_af_http_queue']; return ! empty( $q ) ? array_shift( $q ) : array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ); } }
	// Same queue as the others. Core's real safe_ variant additionally rejects
	// private ranges and odd ports; that guard is core's job and is not modelled
	// here — these tests exercise what the caller does with the RESPONSE.
	if ( ! function_exists( 'wp_safe_remote_get' ) )    { function wp_safe_remote_get( $url, $args = array() ) { $GLOBALS['_af_http_last'] = array( 'url' => $url, 'args' => $args ); $GLOBALS['_af_http_calls'][] = array( 'url' => $url, 'args' => $args ); $q = &$GLOBALS['_af_http_queue']; return ! empty( $q ) ? array_shift( $q ) : array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() ); } }
	if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $h ) { return true; } }
	// Mail capture, mirroring the HTTP stubs above: wp_mail records every send into
	// $GLOBALS['_af_mail'] (to/subject/message/headers) and returns the queued verdict
	// from $_af_mail_ok (default true) — so the digest's send flow is testable without
	// a mailer. Reset between tests via _af_reset_options().
	if ( ! function_exists( 'wp_mail' ) )               { function wp_mail( $to, $subject, $message, $headers = array(), $attachments = array() ) { $GLOBALS['_af_mail'][] = array( 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers ); return ! isset( $GLOBALS['_af_mail_ok'] ) || (bool) $GLOBALS['_af_mail_ok']; } }
	if ( ! function_exists( '_n' ) )                    { function _n( $single, $plural, $number, $d = null ) { return 1 === (int) $number ? $single : $plural; } }
	if ( ! function_exists( 'date_i18n' ) )             { function date_i18n( $format, $ts = null ) { return gmdate( (string) $format, null === $ts ? time() : (int) $ts ); } }
	if ( ! function_exists( 'wp_specialchars_decode' ) ) { function wp_specialchars_decode( $s, $q = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $s, $q ); } }
	if ( ! function_exists( 'wp_generate_password' ) )  { function wp_generate_password( $length = 12, $special = true, $extra = false ) { return substr( str_repeat( 'abcdef0123456789', 4 ), 0, (int) $length ); } }
	if ( ! function_exists( 'add_query_arg' ) )         { function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args ); } }
	if ( ! function_exists( 'wp_timezone' ) )           { function wp_timezone() { return new DateTimeZone( 'UTC' ); } }
	if ( ! function_exists( 'current_user_can' ) )      { function current_user_can( $cap ) { return ! isset( $GLOBALS['_af_user_can'] ) || (bool) $GLOBALS['_af_user_can']; } }
	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && isset( $r['response']['code'] ) ? (int) $r['response']['code'] : 0; } }
	if ( ! function_exists( 'wp_remote_retrieve_body' ) )          { function wp_remote_retrieve_body( $r ) { return is_array( $r ) && isset( $r['body'] ) ? (string) $r['body'] : ''; } }
	if ( ! function_exists( 'wp_remote_retrieve_header' ) )        { function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) && isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; } }
	// Stateful option store so tests can set values (e.g. suppressed_resources)
	// and read them back. Empty by default, so it behaves exactly like returning
	// the default until a test writes — reset between tests via _af_reset_options().
	$GLOBALS['_af_options'] = array();
	$GLOBALS['_af_transients'] = array();
	$GLOBALS['_af_did_actions'] = array(); // Tests flip e.g. 'mcp_adapter_init' to exercise the server-present path.
	$GLOBALS['_af_posts'] = array(); // id => post object fixtures for the Markdown privacy tests.
	if ( ! function_exists( 'get_option' ) )            { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['_af_options'] ) ? $GLOBALS['_af_options'][ $k ] : $d; } }
	if ( ! function_exists( 'update_option' ) )         { function update_option( $k, $v, $autoload = null ) { $GLOBALS['_af_options'][ $k ] = $v; return true; } }
	// Rewrite-flush surface: is_admin() is toggleable per test, flush_rewrite_rules()
	// just counts calls so a test can assert exactly when (and how often) we flush.
	$GLOBALS['_af_is_admin']    = false;
	$GLOBALS['_af_flush_count'] = 0;
	if ( ! function_exists( 'is_admin' ) )              { function is_admin() { return ! empty( $GLOBALS['_af_is_admin'] ); } }
	if ( ! function_exists( 'flush_rewrite_rules' ) )   { function flush_rewrite_rules( $hard = true ) { $GLOBALS['_af_flush_count'] = (int) ( $GLOBALS['_af_flush_count'] ?? 0 ) + 1; return true; } }
	// The admin colour scheme the user picked — read by SchemeInk::active_surface().
	$GLOBALS['_af_user_options'] = array();
	if ( ! function_exists( 'get_user_option' ) )       { function get_user_option( $k, $user = 0 ) { return array_key_exists( $k, $GLOBALS['_af_user_options'] ) ? $GLOBALS['_af_user_options'][ $k ] : false; } }
	if ( ! function_exists( 'add_option' ) )            { function add_option( $k, $v ) { $GLOBALS['_af_options'][ $k ] = $v; return true; } }
	if ( ! function_exists( 'delete_option' ) )         { function delete_option( $k ) { unset( $GLOBALS['_af_options'][ $k ] ); return true; } }
	// Users exist when listed in $_af_users (id => object|true); unknown ids resolve false like core.
	if ( ! function_exists( 'get_userdata' ) )          { function get_userdata( $id ) { return isset( $GLOBALS['_af_users'][ (int) $id ] ) ? (object) array( 'ID' => (int) $id ) : false; } }
	if ( ! function_exists( 'current_time' ) )          { function current_time( $type, $gmt = 0 ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); } }
	// The "any post changed" clock. Cache keys hang on it, so a test that wants a
	// recomputation sets $_af_lastpostmodified to something new.
	if ( ! function_exists( 'get_lastpostmodified' ) )  { function get_lastpostmodified( $tz = 'gmt' ) { return (string) ( $GLOBALS['_af_lastpostmodified'] ?? '2026-01-01 00:00:00' ); } }
	if ( ! function_exists( 'get_post' ) )              { function get_post( $id = 0 ) { if ( is_object( $id ) ) { return $id; } $id = (int) $id; if ( ! $id ) { $id = (int) ( $GLOBALS['_af_current_post_id'] ?? 0 ); } return isset( $GLOBALS['_af_posts'][ $id ] ) ? $GLOBALS['_af_posts'][ $id ] : null; } }
	if ( ! function_exists( 'post_type_exists' ) )      { function post_type_exists( $t ) { return in_array( (string) $t, (array) ( $GLOBALS['_af_post_types_exist'] ?? array() ), true ); } }
	// A registered type answers as public + REST by default; a test that cares about
	// rest_base, `public` or `show_in_rest` declares the object itself.
	if ( ! function_exists( 'get_post_type_object' ) ) { function get_post_type_object( $t ) { $reg = (array) ( $GLOBALS['_af_post_type_objects'] ?? array() ); if ( isset( $reg[ (string) $t ] ) ) { return (object) $reg[ (string) $t ]; } return post_type_exists( $t ) ? (object) array( 'public' => true, 'show_in_rest' => true, 'rest_base' => (string) $t ) : null; } }
	// Keyed by name like WP's own 'names' output, so a caller can unset() one.
	if ( ! function_exists( 'get_post_types' ) )        { function get_post_types( $args = array(), $output = 'names' ) { $all = (array) ( $GLOBALS['_af_public_post_types'] ?? array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment' ) ); return $all; } }
	// Records every call's args, and can answer a QUEUE when a test needs two
	// different results from two different queries (Media::search runs a title
	// pass then an alt pass). Falls back to the single-value form every other
	// test already uses.
	if ( ! function_exists( 'get_posts' ) )             { function get_posts( $args = array() ) { $GLOBALS['_af_get_posts_calls'][] = (array) $args; if ( ! empty( $GLOBALS['_af_get_posts_queue'] ) ) { return (array) array_shift( $GLOBALS['_af_get_posts_queue'] ); } return (array) ( $GLOBALS['_af_get_posts'] ?? array() ); } }
	if ( ! function_exists( 'wp_get_attachment_url' ) ) { function wp_get_attachment_url( $id = 0 ) { return isset( $GLOBALS['_af_attachments'][ (int) $id ]['url'] ) ? (string) $GLOBALS['_af_attachments'][ (int) $id ]['url'] : 'https://example.com/wp-content/uploads/' . (int) $id . '.jpg'; } }
	if ( ! function_exists( 'get_post_mime_type' ) )    { function get_post_mime_type( $id = 0 ) { return isset( $GLOBALS['_af_attachments'][ (int) $id ]['mime'] ) ? (string) $GLOBALS['_af_attachments'][ (int) $id ]['mime'] : 'image/jpeg'; } }
	if ( ! function_exists( 'wp_get_attachment_metadata' ) ) { function wp_get_attachment_metadata( $id = 0 ) { return isset( $GLOBALS['_af_attachments'][ (int) $id ]['meta'] ) ? (array) $GLOBALS['_af_attachments'][ (int) $id ]['meta'] : array(); } }
	if ( ! function_exists( 'get_the_title' ) )         { function get_the_title( $p = null ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? (string) $p->post_title : ''; } }
	if ( ! function_exists( 'get_permalink' ) )         { function get_permalink( $p = 0 ) { $p = is_object( $p ) ? $p : get_post( $p ); return 'https://example.com/?p=' . ( $p ? (int) $p->ID : 0 ); } }
	// Singular-view surface for the Schema privacy tests (toggle via the globals).
	if ( ! function_exists( 'is_singular' ) )           { function is_singular( $types = '' ) { return ! empty( $GLOBALS['_af_is_singular'] ); } }
	if ( ! function_exists( 'is_front_page' ) )         { function is_front_page() { return ! empty( $GLOBALS['_af_is_front_page'] ); } }
	if ( ! function_exists( 'do_blocks' ) )             { function do_blocks( $content ) { return (string) $content; } }
	if ( ! function_exists( 'get_the_date' ) )          { function get_the_date( $format = '', $p = null ) { return '2026-01-01T00:00:00+00:00'; } }
	if ( ! function_exists( 'get_the_modified_date' ) ) { function get_the_modified_date( $format = '', $p = null ) { return '2026-01-02T00:00:00+00:00'; } }
	// Featured-image surface for the PageCheck featured row (toggle via the globals;
	// defaults mimic a standard site: posts/pages support thumbnails, none set).
	if ( ! function_exists( 'has_post_thumbnail' ) )    { function has_post_thumbnail( $post = null ) { $id = is_object( $post ) ? (int) $post->ID : (int) $post; return ! empty( $GLOBALS['_af_thumbnails'][ $id ] ); } }
	if ( ! function_exists( 'post_type_supports' ) )    { function post_type_supports( $type, $feature ) { $k = $type . ':' . $feature; if ( isset( $GLOBALS['_af_type_supports'][ $k ] ) ) { return (bool) $GLOBALS['_af_type_supports'][ $k ]; } return in_array( $feature, array( 'thumbnail', 'editor' ), true ) && in_array( (string) $type, array( 'post', 'page' ), true ); } }
	// Core's own answer for the two built-ins; $GLOBALS['_af_hierarchical_types'] lets a
	// test register a page-like CPT, which is what Assistant::shape_for() reads.
	if ( ! function_exists( 'is_post_type_hierarchical' ) ) { function is_post_type_hierarchical( $type ) { $extra = (array) ( $GLOBALS['_af_hierarchical_types'] ?? array() ); return 'page' === (string) $type || in_array( (string) $type, $extra, true ); } }
	// Share-card + canonical surface for the Seo tests. _af_thumbnails doubles as the
	// post-ID → attachment-ID map (has_post_thumbnail above only cares that it's truthy).
	if ( ! function_exists( 'get_post_thumbnail_id' ) )        { function get_post_thumbnail_id( $post = null ) { $id = is_object( $post ) ? (int) $post->ID : (int) $post; return (int) ( $GLOBALS['_af_thumbnails'][ $id ] ?? 0 ); } }
	if ( ! function_exists( 'wp_get_attachment_image_src' ) )  { function wp_get_attachment_image_src( $id, $size = 'thumbnail' ) { return $GLOBALS['_af_attachments'][ (int) $id ] ?? false; } }
	// Resolves the featured image straight to a URL — Schema's VideoObject uses it
	// for thumbnailUrl. Returns false with no thumbnail set, exactly as core does.
	if ( ! function_exists( 'get_the_post_thumbnail_url' ) )    { function get_the_post_thumbnail_url( $post = null, $size = 'post-thumbnail' ) { $src = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), $size ); return ( is_array( $src ) && ! empty( $src[0] ) ) ? $src[0] : false; } }
	if ( ! function_exists( 'is_paged' ) )                     { function is_paged() { return ! empty( $GLOBALS['_af_is_paged'] ); } }
	if ( ! function_exists( 'is_category' ) )                  { function is_category( $c = '' ) { return ! empty( $GLOBALS['_af_is_category'] ); } }
	if ( ! function_exists( 'is_tag' ) )                       { function is_tag( $t = '' ) { return ! empty( $GLOBALS['_af_is_tag'] ); } }
	if ( ! function_exists( 'is_tax' ) )                       { function is_tax( $t = '' ) { return ! empty( $GLOBALS['_af_is_tax'] ); } }
	if ( ! function_exists( 'is_post_type_archive' ) )         { function is_post_type_archive( $t = '' ) { return ! empty( $GLOBALS['_af_is_post_type_archive'] ); } }
	if ( ! function_exists( 'is_author' ) )                    { function is_author( $a = '' ) { return ! empty( $GLOBALS['_af_is_author'] ); } }
	if ( ! function_exists( 'get_queried_object' ) )           { function get_queried_object() { return $GLOBALS['_af_queried_object'] ?? null; } }
	if ( ! function_exists( 'get_queried_object_id' ) )        { function get_queried_object_id() { return (int) ( $GLOBALS['_af_queried_object_id'] ?? 0 ); } }
	if ( ! function_exists( 'get_term_link' ) )                { function get_term_link( $term, $tax = '' ) { return $GLOBALS['_af_term_link'] ?? ''; } }
	if ( ! function_exists( 'get_query_var' ) )                { function get_query_var( $k, $default = '' ) { return $GLOBALS['_af_query_vars'][ $k ] ?? $default; } }
	if ( ! function_exists( 'get_post_type_archive_link' ) )   { function get_post_type_archive_link( $t ) { return 'https://example.test/' . $t . '/'; } }
	if ( ! function_exists( 'get_author_posts_url' ) )         { function get_author_posts_url( $id ) { return 'https://example.test/author/' . (int) $id . '/'; } }
	// Core sitemap server for the promotion tests: mirrors the real coupling — its
	// sitemaps_enabled() verdict runs through the wp_sitemaps_enabled filter, which is
	// the exact seam Sitemap::register() uses to stand core down. Absent global = off,
	// so pre-existing detect() tests keep their clean-env cascade.
	if ( ! function_exists( 'wp_sitemaps_get_server' ) )       { function wp_sitemaps_get_server() { return new class() { public function sitemaps_enabled() { return (bool) apply_filters( 'wp_sitemaps_enabled', ! empty( $GLOBALS['_af_core_sitemaps'] ) ); } }; } }
	if ( ! function_exists( 'current_theme_supports' ) ) { function current_theme_supports( $feature ) { return isset( $GLOBALS['_af_theme_supports'][ $feature ] ) ? (bool) $GLOBALS['_af_theme_supports'][ $feature ] : true; } }
	if ( ! function_exists( 'get_the_category' ) )      { function get_the_category( $id = false ) { return isset( $GLOBALS['_af_categories'] ) ? (array) $GLOBALS['_af_categories'] : array(); } }
	if ( ! function_exists( 'get_categories' ) )        { function get_categories( $args = array() ) { return isset( $GLOBALS['_af_categories'] ) ? (array) $GLOBALS['_af_categories'] : array(); } }
	if ( ! function_exists( 'get_category_link' ) )     { function get_category_link( $cat ) { return 'https://example.com/cat/'; } }
	// Post-meta + taxonomy surface for the Topics tests. Meta is a stateful id→[key→value]
	// store; terms are id→[taxonomy→names]; is_object_in_taxonomy() knows the two core taxonomies.
	$GLOBALS['_af_postmeta'] = array();
	$GLOBALS['_af_terms']    = array();
	if ( ! function_exists( 'get_post_meta' ) )         { function get_post_meta( $id, $key = '', $single = false ) { $all = isset( $GLOBALS['_af_postmeta'][ $id ] ) ? $GLOBALS['_af_postmeta'][ $id ] : array(); if ( '' === $key ) { return $all; } if ( ! array_key_exists( $key, $all ) ) { return $single ? '' : array(); } return $single ? $all[ $key ] : array( $all[ $key ] ); } }
	if ( ! function_exists( 'update_post_meta' ) )      { function update_post_meta( $id, $key, $value ) { $GLOBALS['_af_postmeta'][ $id ][ $key ] = $value; return true; } }
	if ( ! function_exists( 'delete_post_meta' ) )      { function delete_post_meta( $id, $key ) { unset( $GLOBALS['_af_postmeta'][ $id ][ $key ] ); return true; } }
	// Terms are stored as name strings (a slug is synthesised) or as objects; returns
	// name strings when fields=names is asked, otherwise term-like {name, slug} objects.
	if ( ! function_exists( 'wp_get_post_terms' ) )     { function wp_get_post_terms( $id, $tax, $args = array() ) { $stored = isset( $GLOBALS['_af_terms'][ $id ][ $tax ] ) ? (array) $GLOBALS['_af_terms'][ $id ][ $tax ] : array(); $names_only = isset( $args['fields'] ) && 'names' === $args['fields']; $out = array(); foreach ( $stored as $entry ) { if ( is_object( $entry ) ) { $name = isset( $entry->name ) ? (string) $entry->name : ''; $slug = isset( $entry->slug ) ? (string) $entry->slug : ''; } else { $name = (string) $entry; $slug = ''; } if ( '' === $slug ) { $slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ), '-' ); } $out[] = $names_only ? $name : (object) array( 'name' => $name, 'slug' => $slug ); } return $out; } }
	// The taxonomies that "exist" for object types; a test can add a vendor one (e.g. product_cat).
	$GLOBALS['_af_taxonomies'] = array( 'category', 'post_tag' );
	if ( ! function_exists( 'is_object_in_taxonomy' ) ) { function is_object_in_taxonomy( $type, $tax ) { return in_array( $tax, (array) $GLOBALS['_af_taxonomies'], true ); } }
	// Content-summary surface for the Description excerpt fallback: strip shortcodes and
	// trim to a word count. Deliberately simple — the resolver's logic (not WP's exact
	// trimming) is under test. excerpt_remove_blocks() is left unstubbed so the resolver's
	// function_exists() guard is exercised on the no-blocks path.
	if ( ! function_exists( 'strip_shortcodes' ) )      { function strip_shortcodes( $content ) { return preg_replace( '/\[[^\]]*\]/', '', (string) $content ); } }
	if ( ! function_exists( 'wp_trim_words' ) )         { function wp_trim_words( $text, $num_words = 55, $more = null ) { $words = preg_split( '/\s+/', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY ); if ( count( $words ) > $num_words ) { $words = array_slice( $words, 0, $num_words ); $text = implode( ' ', $words ) . ( null === $more ? '…' : $more ); } else { $text = implode( ' ', $words ); } return $text; } }
	function _af_reset_options() { $GLOBALS['_af_options'] = array(); $GLOBALS['_af_filters'] = array(); $GLOBALS['_af_did_actions'] = array(); $GLOBALS['_af_posts'] = array(); $GLOBALS['_af_postmeta'] = array(); $GLOBALS['_af_terms'] = array(); $GLOBALS['_af_taxonomies'] = array( 'category', 'post_tag' ); $GLOBALS['_af_is_admin'] = false; $GLOBALS['_af_flush_count'] = 0; $GLOBALS['_af_cache'] = array(); $GLOBALS['_af_transients'] = array(); unset( $GLOBALS['_af_transients_on'] ); $GLOBALS['_af_http_queue'] = array(); $GLOBALS['_af_http_last'] = null; $GLOBALS['_af_http_calls'] = array(); $GLOBALS['_af_mail'] = array(); unset( $GLOBALS['_af_mail_ok'] ); unset( $GLOBALS['_af_user_can'] ); unset( $GLOBALS['_af_available_post_types'], $GLOBALS['_af_current_post_id'], $GLOBALS['_af_is_singular'], $GLOBALS['_af_is_front_page'], $GLOBALS['_af_categories'], $GLOBALS['_af_post_types_exist'], $GLOBALS['_af_post_type_objects'], $GLOBALS['_af_get_posts'], $GLOBALS['_af_get_posts_queue'], $GLOBALS['_af_get_posts_calls'] ); unset( $GLOBALS['_af_attachments'], $GLOBALS['_af_is_paged'], $GLOBALS['_af_is_category'], $GLOBALS['_af_is_tag'], $GLOBALS['_af_is_tax'], $GLOBALS['_af_is_post_type_archive'], $GLOBALS['_af_is_author'], $GLOBALS['_af_queried_object'], $GLOBALS['_af_queried_object_id'], $GLOBALS['_af_term_link'], $GLOBALS['_af_query_vars'], $GLOBALS['_af_core_sitemaps'] ); }
	// Transient stubs. Default: always-miss, so cached endpoint bodies (e.g. security.txt)
	// recompute deterministically in tests. A test that needs to exercise real transient
	// state (the bot-verifier budget/circuit-breaker counters) opts in by setting
	// $GLOBALS['_af_transients_on'] = true in its setUp; _af_reset_options() clears both.
	if ( ! function_exists( 'get_transient' ) )         { function get_transient( $k ) { return ! empty( $GLOBALS['_af_transients_on'] ) && array_key_exists( $k, $GLOBALS['_af_transients'] ) ? $GLOBALS['_af_transients'][ $k ] : false; } }
	if ( ! function_exists( 'set_transient' ) )         { function set_transient( $k, $v, $t = 0 ) { if ( ! empty( $GLOBALS['_af_transients_on'] ) ) { $GLOBALS['_af_transients'][ $k ] = $v; } return true; } }
	if ( ! function_exists( 'delete_transient' ) )      { function delete_transient( $k ) { unset( $GLOBALS['_af_transients'][ $k ] ); return true; } }
	// Cron stubs (BotRanges' catch-up scheduling): nothing is ever scheduled in tests.
	if ( ! function_exists( 'wp_next_scheduled' ) )     { function wp_next_scheduled( $h ) { return false; } }
	if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $t, $h, $args = array() ) { if ( isset( $GLOBALS['_af_cron_events'] ) ) { $GLOBALS['_af_cron_events'][] = array( 'at' => (int) $t, 'hook' => (string) $h, 'single' => true ); } return true; } }
	// Site-wide term query (used by Topics::suggestions() / LlmsText::topics()); empty
	// unless a test seeds $GLOBALS['_af_get_terms'].
	if ( ! function_exists( 'get_terms' ) )             { function get_terms( $args = array() ) { return isset( $GLOBALS['_af_get_terms'] ) ? $GLOBALS['_af_get_terms'] : array(); } }
	if ( ! function_exists( 'wp_parse_args' ) )         { function wp_parse_args( $a, $d = array() ) { if ( is_object( $a ) ) { $a = get_object_vars( $a ); } elseif ( ! is_array( $a ) ) { $a = array(); } return array_merge( $d, $a ); } }
	if ( ! function_exists( 'wp_json_encode' ) )        { function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); } }
	if ( ! function_exists( 'untrailingslashit' ) )    { function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); } }
	if ( ! function_exists( 'trailingslashit' ) )       { function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; } }
	if ( ! function_exists( 'rest_url' ) )              { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ); } }
	if ( ! function_exists( 'rest_get_url_prefix' ) )   { function rest_get_url_prefix() { return 'wp-json'; } }
	if ( ! function_exists( 'rest_ensure_response' ) )  { function rest_ensure_response( $r ) { return $r; } }
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

	// The theme-facing template tags are global functions, so the loader above
	// never sees them — the runtime requires the file eagerly and so do we.
	require_once AGENTIMUS_DIR . 'inc/template-tags.php';

	// Reset the Registry singleton between tests (it is process-global).
	function _af_reset_registry() {
		if ( ! class_exists( 'Agentimus\\Discovery\\Registry' ) ) {
			return;
		}
		$prop = new \ReflectionProperty( 'Agentimus\\Discovery\\Registry', 'instance' );
		\_af_accessible( $prop );
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
			public static function post_types() { return self::available(); }
			public static function source( $post_type ) { return ''; }
			// The llms.txt index walks these three; empty sections keep the generated
			// index to its header + about block, which is all the probe tests need.
			public static function index_sections() { return self::post_types(); }
			public static function query( $post_type, $limit = 50 ) { return array(); }
			// Plural for headings and chips, singular for prose — the real class
			// reads both off the post-type object, and code that writes sentences
			// has to be able to tell them apart here too.
			public static function label( $post_type ) { return ucfirst( (string) $post_type ) . 's'; }
			public static function singular( $post_type ) { return ucfirst( (string) $post_type ); }
			// Mirrors the real Content::markdown_source closely enough for the Markdown
			// privacy tests: run the (mocked) the_content filter over the stored body,
			// with the same blank-render fallback to the post's own blocks.
			public static function markdown_source( $post ) {
				$html = (string) apply_filters( 'the_content', $post->post_content );
				if ( '' === trim( wp_strip_all_tags( $html ) ) && '' !== trim( (string) $post->post_content ) ) {
					$html = do_blocks( $post->post_content );
				}
				return $html;
			}
		}
	}
}
