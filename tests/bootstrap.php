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
	// A wp-config-style salt so Visibility\Crypto derives its key from the salt path
	// (what production uses) rather than the no-salts option fallback.
	if ( ! defined( 'AUTH_KEY' ) )                 define( 'AUTH_KEY', 'agentimus-test-auth-key-0123456789abcdef' );

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
	if ( ! function_exists( 'is_wp_error' ) )           { function is_wp_error( $t ) { return $t instanceof \WP_Error; } }
	if ( ! function_exists( '__' ) )                    { function __( $s, $d = null ) { return $s; } }
		if ( ! function_exists( 'esc_html' ) )              { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
		if ( ! function_exists( 'esc_attr' ) )              { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
		if ( ! function_exists( 'esc_html__' ) )            { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
		if ( ! function_exists( 'esc_attr__' ) )            { function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
	if ( ! function_exists( 'sanitize_key' ) )          { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }
	if ( ! function_exists( 'sanitize_text_field' ) )   { function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); } }
	if ( ! function_exists( 'wp_unslash' ) )            { function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; } }
	if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_file_name' ) )    { function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $n ); } }
	if ( ! function_exists( 'sanitize_email' ) )        { function sanitize_email( $e ) { return trim( (string) $e ); } }
	if ( ! function_exists( 'esc_url_raw' ) )           { function esc_url_raw( $u, $p = null ) { return trim( (string) $u ); } }
	if ( ! function_exists( 'esc_url' ) )               { function esc_url( $u, $p = null ) { return trim( (string) $u ); } }
	if ( ! function_exists( 'wp_parse_url' ) )          { function wp_parse_url( $u, $c = -1 ) { return parse_url( (string) $u, $c ); } }
	if ( ! function_exists( 'is_email' ) )              { function is_email( $e ) { return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; } }
	if ( ! function_exists( 'wp_normalize_path' ) )     { function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); } }
	if ( ! function_exists( 'wp_strip_all_tags' ) )     { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
	if ( ! function_exists( 'number_format_i18n' ) )    { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }
	if ( ! function_exists( 'plugin_basename' ) )       { function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); } }
	if ( ! function_exists( 'home_url' ) )              { function home_url( $path = '' ) { $b = 'https://example.test'; $path = (string) $path; return '' === $path ? $b . '/' : $b . ( '/' === $path[0] ? $path : '/' . $path ); } }
	if ( ! function_exists( 'admin_url' ) )             { function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); } }
	if ( ! function_exists( 'get_bloginfo' ) )          { function get_bloginfo( $k = '' ) { $m = array( 'name' => 'Test Site', 'description' => 'A test site.', 'language' => 'en-US' ); return isset( $m[ $k ] ) ? $m[ $k ] : ''; } }
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
	if ( ! function_exists( 'add_option' ) )            { function add_option( $k, $v ) { $GLOBALS['_af_options'][ $k ] = $v; return true; } }
	if ( ! function_exists( 'delete_option' ) )         { function delete_option( $k ) { unset( $GLOBALS['_af_options'][ $k ] ); return true; } }
	if ( ! function_exists( 'current_time' ) )          { function current_time( $type, $gmt = 0 ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); } }
	if ( ! function_exists( 'get_post' ) )              { function get_post( $id = 0 ) { if ( is_object( $id ) ) { return $id; } $id = (int) $id; if ( ! $id ) { $id = (int) ( $GLOBALS['_af_current_post_id'] ?? 0 ); } return isset( $GLOBALS['_af_posts'][ $id ] ) ? $GLOBALS['_af_posts'][ $id ] : null; } }
	if ( ! function_exists( 'get_the_title' ) )         { function get_the_title( $p = null ) { $p = is_object( $p ) ? $p : get_post( $p ); return $p ? (string) $p->post_title : ''; } }
	if ( ! function_exists( 'get_permalink' ) )         { function get_permalink( $p = 0 ) { $p = is_object( $p ) ? $p : get_post( $p ); return 'https://example.com/?p=' . ( $p ? (int) $p->ID : 0 ); } }
	// Singular-view surface for the Schema privacy tests (toggle via the globals).
	if ( ! function_exists( 'is_singular' ) )           { function is_singular( $types = '' ) { return ! empty( $GLOBALS['_af_is_singular'] ); } }
	if ( ! function_exists( 'is_front_page' ) )         { function is_front_page() { return ! empty( $GLOBALS['_af_is_front_page'] ); } }
	if ( ! function_exists( 'do_blocks' ) )             { function do_blocks( $content ) { return (string) $content; } }
	if ( ! function_exists( 'get_the_date' ) )          { function get_the_date( $format = '', $p = null ) { return '2026-01-01T00:00:00+00:00'; } }
	if ( ! function_exists( 'get_the_modified_date' ) ) { function get_the_modified_date( $format = '', $p = null ) { return '2026-01-02T00:00:00+00:00'; } }
	if ( ! function_exists( 'get_the_category' ) )      { function get_the_category( $id = false ) { return isset( $GLOBALS['_af_categories'] ) ? (array) $GLOBALS['_af_categories'] : array(); } }
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
	function _af_reset_options() { $GLOBALS['_af_options'] = array(); $GLOBALS['_af_filters'] = array(); $GLOBALS['_af_did_actions'] = array(); $GLOBALS['_af_posts'] = array(); $GLOBALS['_af_postmeta'] = array(); $GLOBALS['_af_terms'] = array(); $GLOBALS['_af_taxonomies'] = array( 'category', 'post_tag' ); $GLOBALS['_af_is_admin'] = false; $GLOBALS['_af_flush_count'] = 0; $GLOBALS['_af_cache'] = array(); $GLOBALS['_af_transients'] = array(); unset( $GLOBALS['_af_transients_on'] ); $GLOBALS['_af_http_queue'] = array(); $GLOBALS['_af_http_last'] = null; unset( $GLOBALS['_af_available_post_types'], $GLOBALS['_af_current_post_id'], $GLOBALS['_af_is_singular'], $GLOBALS['_af_is_front_page'], $GLOBALS['_af_categories'] ); }
	// Transient stubs. Default: always-miss, so cached endpoint bodies (e.g. security.txt)
	// recompute deterministically in tests. A test that needs to exercise real transient
	// state (the bot-verifier budget/circuit-breaker counters) opts in by setting
	// $GLOBALS['_af_transients_on'] = true in its setUp; _af_reset_options() clears both.
	if ( ! function_exists( 'get_transient' ) )         { function get_transient( $k ) { return ! empty( $GLOBALS['_af_transients_on'] ) && array_key_exists( $k, $GLOBALS['_af_transients'] ) ? $GLOBALS['_af_transients'][ $k ] : false; } }
	if ( ! function_exists( 'set_transient' ) )         { function set_transient( $k, $v, $t = 0 ) { if ( ! empty( $GLOBALS['_af_transients_on'] ) ) { $GLOBALS['_af_transients'][ $k ] = $v; } return true; } }
	if ( ! function_exists( 'delete_transient' ) )      { function delete_transient( $k ) { unset( $GLOBALS['_af_transients'][ $k ] ); return true; } }
	// Site-wide term query (used by Topics::suggestions() / LlmsText::topics()); empty
	// unless a test seeds $GLOBALS['_af_get_terms'].
	if ( ! function_exists( 'get_terms' ) )             { function get_terms( $args = array() ) { return isset( $GLOBALS['_af_get_terms'] ) ? $GLOBALS['_af_get_terms'] : array(); } }
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
			public static function post_types() { return self::available(); }
			public static function source( $post_type ) { return ''; }
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
