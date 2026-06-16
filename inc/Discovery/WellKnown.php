<?php
/**
 * WellKnown — the single front controller for /.well-known/*.
 *
 * One router for the docs we own: this plugin's generated docs (discovery.json,
 * agent-card.json, agent.json, mcp.json) and any document a provider registered
 * via Registry::add_well_known(). A real file on disk always wins — if the web
 * server didn't already serve it, we stream it ourselves rather than shadow it.
 *
 * Deliberately NOT greedy: we only route the names we recognise. Anything else
 * under /.well-known/ (ACME challenges, other plugins' docs, unknown names) is
 * left entirely alone and falls through to WordPress's normal handling/404 — so
 * we never hijack a namespace we don't own.
 *
 * @package Agentify
 */

namespace Agentify\Discovery;

use Agentify\Settings;

defined( 'ABSPATH' ) || exit;

final class WellKnown {

	const PREFIX = '/.well-known/';

	/** @var Settings */
	private $settings;

	/** @var Registry */
	private $registry;

	/** @var Envelope */
	private $envelope;

	/**
	 * @param Settings $settings Settings store.
	 * @param Registry $registry Collector.
	 */
	public function __construct( Settings $settings, Registry $registry ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->envelope = new Envelope( $settings, $registry );
	}

	/**
	 * Hook the (narrow) rewrite rule and the front-controller route.
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
	}

	/**
	 * The well-known names this plugin is authoritative for. Providers serving a
	 * doc on a server that won't front-controller /.well-known to PHP can add
	 * their name here (and flush rewrites) to guarantee it routes.
	 *
	 * @return string[]
	 */
	public static function routed_names() {
		// ONLY names this plugin actually serves. Routing a name we don't serve is
		// harmful: with our rewrite tag set but no body produced, WordPress's
		// canonical redirect resolves it to the homepage (a 200, not a 404). So
		// security.txt etc. are intentionally absent — a provider that serves one
		// adds its name here via the `agentify_well_known_routed` filter.
		$names = array( 'discovery.json', 'agent-card.json', 'agent.json', 'mcp.json' );

		/**
		 * Filter the /.well-known names routed to WordPress by an explicit rule.
		 *
		 * @param string[] $names Doc names (no leading slash).
		 */
		return array_values( array_unique( (array) apply_filters( 'agentify_well_known_routed', $names ) ) );
	}

	/**
	 * Route ONLY our own names to WordPress, so requests reach template_redirect
	 * even on servers that 404 the path at disk level. A narrow alternation (not
	 * a catch-all) means we never capture — and therefore never have to 404 —
	 * paths that belong to someone else. Flushed on activation.
	 */
	public static function add_rules() {
		$alt = implode(
			'|',
			array_map(
				static function ( $name ) {
					return preg_quote( $name );
				},
				self::routed_names()
			)
		);
		if ( '' === $alt ) {
			return;
		}
		add_rewrite_rule( '^\.well-known/(' . $alt . ')$', 'index.php?wpd_well_known=$matches[1]', 'top' );
		add_rewrite_tag( '%wpd_well_known%', '([^/]+)' );
	}

	/**
	 * Resolve a /.well-known/* request. Runs before the template at priority 0.
	 */
	public function route() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = '/' . ltrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		if ( 0 !== strpos( $path, self::PREFIX ) ) {
			return;
		}

		$name = trim( substr( $path, strlen( self::PREFIX ) ), '/' );
		if ( '' === $name || false !== strpos( $name, '/' ) || false !== strpos( $name, '..' ) ) {
			return; // No nested or traversal names.
		}

		// 1. Real file on disk wins. If the server didn't serve it, stream it.
		$file = ABSPATH . '.well-known/' . $name;
		if ( is_file( $file ) && 0 === strpos( wp_normalize_path( realpath( $file ) ), wp_normalize_path( ABSPATH . '.well-known/' ) ) ) {
			$this->stream( $file );
		}

		$this->registry->collect();

		// 2. Documents this plugin generates.
		switch ( $name ) {
			case 'discovery.json':
				$this->send( $this->envelope->discovery_json(), 'application/json', 'discovery.json' );
				break;
			case 'agent-card.json':
			case 'agent.json': // Alias for agents that look here first.
				$this->send( $this->envelope->agent_card_json(), 'application/json', $name );
				break;
			case 'mcp.json':
				$this->send( $this->envelope->mcp_json(), 'application/json', 'mcp.json' );
				break;
		}

		// 3. Provider-registered documents.
		$providers = $this->registry->well_known();
		if ( isset( $providers[ $name ] ) ) {
			$this->serve_provider( $providers[ $name ] );
		}

		// 4. We produced nothing. If WE routed this request (our rewrite tag is set)
		// it would otherwise resolve to the homepage via WordPress's canonical
		// redirect — so force a clean 404 instead. A name we never routed is left
		// entirely untouched for WordPress (or another plugin) to handle.
		if ( '' !== (string) get_query_var( 'wpd_well_known' ) ) {
			global $wp_query;
			if ( $wp_query instanceof \WP_Query ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			nocache_headers();
		}
	}

	/* ---------------------------------------------------------------------- *
	 *  Emitters
	 * ---------------------------------------------------------------------- */

	/**
	 * Serve a provider-registered well-known doc (redirect | file | callback).
	 *
	 * @param array $def Provider definition.
	 */
	private function serve_provider( $def ) {
		if ( ! empty( $def['redirect'] ) ) {
			$target = '/' === $def['redirect'][0] ? home_url( $def['redirect'] ) : $def['redirect'];
			wp_safe_redirect( $target, 302 );
			exit;
		}
		if ( ! empty( $def['file'] ) && is_file( $def['file'] ) ) {
			$this->stream( $def['file'], $def['content_type'] );
		}
		if ( ! empty( $def['callback'] ) && is_callable( $def['callback'] ) ) {
			$body = (string) call_user_func( $def['callback'] );
			$this->send( $body, $def['content_type'] );
		}
	}

	/**
	 * Emit a generated body with cache-friendly headers, then stop.
	 *
	 * @param string $body         Response body.
	 * @param string $content_type MIME type.
	 * @param string $label        Activity-log endpoint label (empty = no log).
	 */
	private function send( $body, $content_type, $label = '' ) {
		if ( '' !== $label ) {
			\Agentify\Activity\Recorder::record( $label );
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Access-Control-Allow-Origin: *' ); // Discovery docs are public by design.
			header( 'Cache-Control: public, max-age=3600' );
		}
		if ( ! $this->is_head() ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON/plain payload.
		}
		exit;
	}

	/**
	 * Stream a static file from disk with a sane MIME type, then stop.
	 *
	 * @param string $file         Absolute path.
	 * @param string $content_type Optional override.
	 */
	private function stream( $file, $content_type = '' ) {
		if ( '' === $content_type ) {
			$content_type = 'json' === strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) ) ? 'application/json' : 'text/plain';
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Cache-Control: public, max-age=3600' );
		}
		if ( ! $this->is_head() ) {
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a discovery doc.
		}
		exit;
	}

	/**
	 * @return bool Whether this is a HEAD request.
	 */
	private function is_head() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}
}
