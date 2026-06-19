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
 * @package Agentimus
 */

namespace Agentimus\Discovery;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class WellKnown {

	const PREFIX = '/.well-known/';

	/** @var Settings */
	private $settings;

	/** @var Registry */
	private $registry;

	/** @var Envelope */
	private $envelope;

	/** @var Signer */
	private $signer;

	/**
	 * @param Settings $settings Settings store.
	 * @param Registry $registry Collector.
	 */
	public function __construct( Settings $settings, Registry $registry ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->envelope = new Envelope( $settings, $registry );
		$this->signer   = new Signer( $settings );
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
		// adds its name here via the `agentimus_well_known_routed` filter.
		$names = array( 'discovery.json', 'agent-card.json', 'agent.json', 'mcp.json', 'api-catalog', 'oauth-protected-resource', Signer::DIRECTORY );

		/**
		 * Filter the /.well-known names routed to WordPress by an explicit rule.
		 *
		 * @param string[] $names Doc names (no leading slash).
		 */
		return array_values( array_unique( (array) apply_filters( 'agentimus_well_known_routed', $names ) ) );
	}

	/**
	 * Nested /.well-known docs this plugin serves — names that contain a '/' and so
	 * can't go through the flat router. An EXACT allow-list (never a wildcard), each
	 * backed by a dedicated rewrite rule, so we never capture a nested namespace we
	 * don't own. Currently the MCP Server Card and the Agent Skills index.
	 *
	 * @return string[]
	 */
	public static function nested_routes() {
		$names = array( 'mcp/server-card.json', 'agent-skills/index.json' );

		/**
		 * Filter the nested /.well-known names routed to WordPress.
		 *
		 * @param string[] $names Nested doc paths (no leading slash).
		 */
		return array_values( array_unique( (array) apply_filters( 'agentimus_well_known_nested', $names ) ) );
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

		// Nested docs (mcp/server-card.json, …) get a dedicated EXACT-match rule, so
		// the capture can contain a '/' without opening a wildcard nesting surface.
		$nested = implode(
			'|',
			array_map(
				static function ( $name ) {
					return preg_quote( $name );
				},
				self::nested_routes()
			)
		);
		if ( '' !== $nested ) {
			add_rewrite_rule( '^\.well-known/(' . $nested . ')$', 'index.php?wpd_well_known_nested=$matches[1]', 'top' );
			add_rewrite_tag( '%wpd_well_known_nested%', '(.+)' );
		}
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
		if ( '' === $name || false !== strpos( $name, '..' ) ) {
			return; // Never serve a traversal path.
		}

		// Nested, whitelisted docs (e.g. mcp/server-card.json) contain a '/', which
		// the flat handling below rejects — dispatch them first against an EXACT
		// allow-list. A real on-disk file still wins; an empty generator (the doc is
		// gated off) falls through to a clean 404.
		if ( false !== strpos( $name, '/' ) ) {
			if ( in_array( $name, self::nested_routes(), true ) ) {
				$nested_file = \Agentimus\Paths::site_root() . '.well-known/' . $name;
				if ( is_file( $nested_file ) && 0 === strpos( wp_normalize_path( (string) realpath( $nested_file ) ), wp_normalize_path( \Agentimus\Paths::site_root() . '.well-known/' ) ) ) {
					$this->stream( $nested_file );
				}
				$this->registry->collect();
				$this->route_nested( $name );
			}
			$this->maybe_clean_404();
			return;
		}

		// 1. Real file on disk wins. If the server didn't serve it, stream it.
		$file = \Agentimus\Paths::site_root() . '.well-known/' . $name;
		if ( is_file( $file ) && 0 === strpos( wp_normalize_path( realpath( $file ) ), wp_normalize_path( \Agentimus\Paths::site_root() . '.well-known/' ) ) ) {
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
			case 'api-catalog':
				// RFC 9727 API catalog, as an RFC 9264 link set.
				$this->send( $this->envelope->api_catalog_json(), 'application/linkset+json', 'api-catalog' );
				break;
			case 'oauth-protected-resource':
				// RFC 9728 — served only when the owner declared an auth server.
				$prm = $this->envelope->oauth_protected_resource_json();
				if ( '' !== $prm ) {
					$this->send( $prm, 'application/json', 'oauth-protected-resource' );
				}
				break;
			case Signer::DIRECTORY:
				// The Web Bot Auth key directory — served only when signing is on.
				$directory = $this->signer->directory();
				if ( '' !== $directory ) {
					$this->send( $directory, 'application/json', Signer::DIRECTORY );
				}
				break;
		}

		// 3. Provider-registered documents.
		$providers = $this->registry->well_known();
		if ( isset( $providers[ $name ] ) ) {
			$this->serve_provider( $providers[ $name ] );
		}

		// 4. We produced nothing — force a clean 404 if WE routed this request.
		$this->maybe_clean_404();
	}

	/**
	 * Dispatch a whitelisted nested /.well-known doc. Sends (and exits) on a
	 * non-empty body; an empty body means the doc is gated off (no MCP server, no
	 * abilities), so we return and let route() emit a clean 404 — never a stub.
	 *
	 * @param string $name Nested doc path (already allow-list checked).
	 */
	private function route_nested( $name ) {
		switch ( $name ) {
			case 'mcp/server-card.json':
				$body = $this->envelope->mcp_server_card_json();
				if ( '' !== $body ) {
					$this->send( $body, 'application/json', 'mcp/server-card.json' );
				}
				break;
			case 'agent-skills/index.json':
				$body = $this->envelope->agent_skills_index_json();
				if ( '' !== $body ) {
					$this->send( $body, 'application/json', 'agent-skills/index.json' );
				}
				break;
		}
	}

	/**
	 * If WE routed this request (either rewrite tag is set) and produced nothing,
	 * WordPress's canonical redirect would resolve it to the homepage — force a
	 * clean 404 instead. A name we never routed is left entirely untouched.
	 */
	private function maybe_clean_404() {
		if ( '' === (string) get_query_var( 'wpd_well_known' ) && '' === (string) get_query_var( 'wpd_well_known_nested' ) ) {
			return;
		}
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
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
			\Agentimus\Activity\Recorder::record( $label );
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			// A name reached via the 404-intercept path (anything not in our rewrite,
			// e.g. security.txt) inherits WordPress's 404 no-cache headers; clear them
			// so our Cache-Control governs and the doc stays edge-cacheable.
			header_remove( 'Expires' );
			header_remove( 'Pragma' );
			header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Access-Control-Allow-Origin: *' ); // Discovery docs are public by design.
			header( 'Cache-Control: public, max-age=3600' );

			// Web Bot Auth: sign the low-volume discovery JSON docs when enabled.
			if ( in_array( $label, $this->signed_surfaces(), true ) && $this->signer->enabled() ) {
				foreach ( $this->signer->sign( $body, $this->current_url() ) as $sig_header => $sig_value ) {
					header( $sig_header . ': ' . $sig_value, false );
				}
			}
		}
		if ( ! $this->is_head() ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON/plain payload.
		}
		exit;
	}

	/**
	 * The discovery docs that receive RFC 9421 response signatures when signing is
	 * on. Filterable — but broadening to cached HTML / llms.txt would mean an
	 * edge-cached body carrying a frozen signature, so keep it to the JSON docs.
	 *
	 * @return string[]
	 */
	private function signed_surfaces() {
		return (array) apply_filters( 'agentimus_signed_surfaces', array( 'discovery.json', 'agent-card.json', 'agent.json', 'mcp.json' ) );
	}

	/**
	 * The absolute URL of the current request, for the signature's @target-uri.
	 *
	 * @return string
	 */
	private function current_url() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = '/' . ltrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		return home_url( $path );
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
			header_remove( 'Expires' ); // Drop WP's 404-path no-cache headers (see send()).
			header_remove( 'Pragma' );
			header( 'Content-Type: ' . $content_type . '; charset=UTF-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Access-Control-Allow-Origin: *' ); // Public docs — match send() so on-disk files are cross-origin fetchable too.
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
