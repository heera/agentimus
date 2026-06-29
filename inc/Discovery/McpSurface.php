<?php
/**
 * McpSurface — everything MCP, kept out of the frozen discovery.json core. We do
 * NOT run an MCP server; we DISCOVER and advertise the ones a shared mcp-adapter
 * library has registered (WooCommerce, Fluent Cart, the default abilities server,
 * …) and project them into the experimental /.well-known/mcp.json manifest and the
 * SEP-2127 server cards. Reads the live adapter, so it tolerates adapter shape
 * drift and a total absence of MCP alike. Pulls site identity from the Envelope.
 *
 * @package Agentimus
 */

namespace Agentimus\Discovery;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class McpSurface {

	/** @var Settings */
	private $settings;

	/** @var Registry */
	private $registry;

	/** @var Envelope The manifest, for shared site identity. */
	private $envelope;

	/**
	 * @param Settings $settings Site identity + feature flags.
	 * @param Registry $registry Collected resources.
	 * @param Envelope $envelope The manifest (site identity source).
	 */
	public function __construct( Settings $settings, Registry $registry, Envelope $envelope ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->envelope = $envelope;
	}

	/**
	 * The MCP/tools surface — served at /.well-known/mcp.json and shown on the admin
	 * Discovery screen, but intentionally NOT part of the frozen discovery.json core
	 * (MCP discovery is still an unsettled proposal). Computed straight from the
	 * collected registry so callers don't round-trip through the full envelope.
	 *
	 * @return array{tools:array[],mcp:array}
	 */
	public function mcp_surface() {
		$this->registry->collect();
		$resources = array_values( $this->registry->resources() );
		return array(
			'tools' => $this->tools( $resources ),
			'mcp'   => $this->mcp( $resources ),
		);
	}

	/**
	 * The /.well-known/mcp.json manifest: site identity, the MCP descriptor, and
	 * the tool list — what an agent fetches to find this site's MCP surface.
	 *
	 * @return string
	 */
	public function mcp_json() {
		$surface = $this->mcp_surface();
		$site    = $this->envelope->site();
		$doc     = array(
			'name'        => $site['name'],
			'description' => $site['description'],
			'url'         => $site['url'],
			// Cast to object so an empty descriptor encodes as {}, not [].
			'mcp'         => (object) $surface['mcp'],
			'tools'       => $surface['tools'],
		);
		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * The MCP Server Card at /.well-known/mcp/server-card.json — a standard SINGLE-server
	 * card (the shape scanners expect), describing the PRIMARY server (the richest,
	 * filterable via `agentimus_mcp_card_server`) with its real, directly-registered
	 * tools — callable at its endpoint by those exact names, NOT the site-wide ability
	 * registry. There is no standard for listing several servers in one card (SEP-2127
	 * defines no array/index), so additional servers get their own cards at the named
	 * sub-path /.well-known/mcp/{id}/server-card.json. Served ONLY when a real MCP server
	 * is advertised; a content site with none returns '' → a clean 404.
	 *
	 * @return string JSON, or '' when no MCP server is available.
	 */
	public function mcp_server_card_json() {
		$surface = $this->mcp_surface();
		$mcp     = $surface['mcp'];
		if ( empty( $mcp['available'] ) || empty( $mcp['servers'] ) ) {
			return '';
		}
		$server = self::primary_server( $mcp['servers'] );
		if ( empty( $server ) ) {
			return '';
		}
		return $this->encode( $this->build_server_card( $server, $mcp ) );
	}

	/**
	 * The per-server card at /.well-known/mcp/{id}/server-card.json — a single-server
	 * card for one named MCP server. Served only for a tool-bearing server matching
	 * $id; an unknown id (or a server with no visible tools) returns '' → a clean 404.
	 *
	 * @param string $id Server id.
	 * @return string JSON, or '' when no such server.
	 */
	public function mcp_server_card_json_for( $id ) {
		$id = (string) $id;
		if ( '' === $id ) {
			return '';
		}
		$surface = $this->mcp_surface();
		$mcp     = $surface['mcp'];
		if ( empty( $mcp['available'] ) ) {
			return '';
		}
		foreach ( $mcp['servers'] as $s ) {
			if ( isset( $s['id'] ) && (string) $s['id'] === $id && ! empty( $s['tool_list'] ) ) {
				return $this->encode( $this->build_server_card( $s, $mcp ) );
			}
		}
		return '';
	}

	/**
	 * Deduped union of every resource's MCP tools.
	 *
	 * @param array[] $resources Resources.
	 * @return array[]
	 */
	private function tools( $resources ) {
		$tools = array();
		$seen  = array();
		foreach ( $resources as $resource ) {
			foreach ( $resource['tools'] as $tool ) {
				if ( isset( $seen[ $tool['name'] ] ) ) {
					continue;
				}
				$seen[ $tool['name'] ] = true;
				$tools[]               = $tool;
			}
		}
		return $tools;
	}

	/**
	 * The MCP server descriptor. We do NOT run an MCP server — we advertise one.
	 * If an official MCP adapter is installed we point at it; otherwise we signal
	 * that tools exist via the Abilities API but no server fronts them yet.
	 *
	 * @param array[] $resources Resources.
	 * @return array
	 */
	private function mcp( $resources ) {
		$ability_tools = 0;
		foreach ( $resources as $resource ) {
			$ability_tools += count( $resource['tools'] );
		}

		$servers = $this->mcp_servers();

		if ( ! empty( $servers ) ) {
			// A real MCP server is running — point at it.
			$tools = 0;
			foreach ( $servers as $server ) {
				$tools += $server['tools'];
			}
			// The adapter's default auth is application-password; when the owner has
			// declared an OAuth authorization server, the protected resources (this
			// server included) use OAuth — reflect that, and link its metadata below.
			$oauth = trim( (string) $this->settings->get( 'oauth_auth_server', '' ) );
			$mcp   = array(
				'available' => true,
				'source'    => 'wordpress-mcp',
				'endpoint'  => $servers[0]['endpoint'],
				'transport' => $servers[0]['transport'],
				'auth'      => '' !== $oauth ? 'oauth' : 'application-password',
				'tools'     => $tools,
				'servers'   => $servers,
			);
		} elseif ( $ability_tools > 0 ) {
			// Tools exist via the Abilities API, but no MCP server fronts them yet.
			$mcp = array(
				'available' => false,
				'source'    => 'abilities',
				'endpoint'  => '',
				'transport' => '',
				'auth'      => '',
				'tools'     => $ability_tools,
				'servers'   => array(),
			);
		} else {
			$mcp = array(
				'available' => false,
				'source'    => '',
				'endpoint'  => '',
				'transport' => '',
				'auth'      => '',
				'tools'     => 0,
				'servers'   => array(),
			);
		}

		// MCP discovery is still an unsettled area of the ecosystem; flag it so
		// consumers treat this block as provisional, not stable contract.
		$mcp['status'] = 'experimental';

		// RFC 9728: a live MCP server is an OAuth protected resource. When the site
		// actually publishes the standard Protected Resource Metadata, link it so an
		// agent uses the settled auth-discovery handshake rather than the bespoke
		// `auth` hint above. Gated on real presence — never a dead link.
		if ( ! empty( $servers ) ) {
			$prm = $this->oauth_prm_url();
			if ( '' !== $prm ) {
				$mcp['auth_metadata'] = $prm;
			}
		}

		/**
		 * Filter the advertised MCP descriptor.
		 *
		 * @param array   $mcp       The descriptor.
		 * @param array[] $resources Collected resources.
		 */
		return (array) apply_filters( 'agentimus_mcp', $mcp, $resources );
	}

	/**
	 * Resolve live MCP servers registered through the shared mcp-adapter library
	 * (WooCommerce, Fluent Cart, the default abilities server, …). Each server is
	 * created during `mcp_adapter_init` and exposes a REST route, so we can hand
	 * agents a concrete endpoint + transport + tool count instead of just
	 * "available". Readable only once that action has fired (it runs on `init`,
	 * before our front-end/REST output), so the front-controller path sees it too.
	 *
	 * @return array[]
	 */
	private function mcp_servers() {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) || ! did_action( 'mcp_adapter_init' ) ) {
			return array();
		}
		try {
			$adapter = \WP\MCP\Core\McpAdapter::instance();
			if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'get_servers' ) ) {
				return array();
			}
			$out = array();
			foreach ( (array) $adapter->get_servers() as $server ) {
				if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_route_namespace' ) ) {
					continue;
				}
				$namespace = (string) $server->get_server_route_namespace();
				if ( '' === $namespace ) {
					continue;
				}
				$route     = method_exists( $server, 'get_server_route' ) ? (string) $server->get_server_route() : '';
				$tool_list = self::server_tools( $server );
				$id        = method_exists( $server, 'get_server_id' ) ? (string) $server->get_server_id() : '';
				$entry     = array(
					'id'        => $id,
					'name'      => method_exists( $server, 'get_server_name' ) ? (string) $server->get_server_name() : '',
					'version'   => method_exists( $server, 'get_server_version' ) ? (string) $server->get_server_version() : '',
					'endpoint'  => esc_url_raw( rest_url( trailingslashit( $namespace ) . ltrim( $route, '/' ) ) ),
					'transport' => 'streamable-http',
					'tools'     => count( $tool_list ),
					// The server's real, directly-registered tools (name + description) —
					// what's actually callable at this endpoint, by these exact names.
					'tool_list' => $tool_list,
				);
				// Link each tool-bearing server to its own SEP-2127 card, so an agent can
				// enumerate servers here and jump straight to each card without guessing.
				if ( '' !== $id && ! empty( $tool_list ) ) {
					$entry['card'] = esc_url_raw( home_url( '/.well-known/mcp/' . rawurlencode( $id ) . '/server-card.json' ) );
				}
				$out[] = $entry;
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * A server's real, directly-registered MCP tools as `[{name, description}]`.
	 * Read from the live server object (`get_tools()`), so it reflects exactly what
	 * is callable at that server's endpoint — not the ability registry. Tolerant of
	 * adapter shape drift (string entries, missing getters).
	 *
	 * @param object $server An MCP server object.
	 * @return array<int,array{name:string,description:string}>
	 */
	private static function server_tools( $server ) {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_tools' ) ) {
			return array();
		}
		$tools = array();
		foreach ( (array) $server->get_tools() as $tool ) {
			if ( is_object( $tool ) && method_exists( $tool, 'get_name' ) ) {
				$name = (string) $tool->get_name();
				$desc = method_exists( $tool, 'get_description' ) ? (string) $tool->get_description() : '';
			} elseif ( is_string( $tool ) ) {
				$name = $tool;
				$desc = '';
			} else {
				continue;
			}
			if ( '' !== $name ) {
				$tools[] = array( 'name' => $name, 'description' => $desc );
			}
		}
		return $tools;
	}

	/**
	 * Choose which single server the server card represents. The well-known path
	 * holds one card, but a site can run several servers — so pick the one with the
	 * most real tools (the card stays non-empty and useful), tie-broken by detection
	 * order. Filterable so a site can pin a specific server by id.
	 *
	 * @param array[] $servers Detected servers (each from mcp_servers()).
	 * @return array The chosen server, or [] when there are none.
	 */
	private static function primary_server( $servers ) {
		$servers = array_values( (array) $servers );
		if ( empty( $servers ) ) {
			return array();
		}

		/**
		 * Filter the server id the MCP server card should describe.
		 *
		 * @param string  $id      Server id to pin ('' = auto: the one with the most tools).
		 * @param array[] $servers All detected servers.
		 */
		$pinned = (string) apply_filters( 'agentimus_mcp_card_server', '', $servers );
		if ( '' !== $pinned ) {
			foreach ( $servers as $s ) {
				if ( isset( $s['id'] ) && $pinned === $s['id'] ) {
					return $s;
				}
			}
		}

		$best = $servers[0];
		foreach ( $servers as $s ) {
			if ( ( isset( $s['tools'] ) ? (int) $s['tools'] : 0 ) > ( isset( $best['tools'] ) ? (int) $best['tools'] : 0 ) ) {
				$best = $s;
			}
		}
		return $best;
	}

	/**
	 * Project a detected server's real tools into the card's `{name, description}`
	 * shape. Used for both the primary server's `tools` and each entry in `servers[]`.
	 *
	 * @param array $server A server row from mcp_servers().
	 * @return array<int,array{name:string,description:string}>
	 */
	private static function card_tools( $server ) {
		$tools = array();
		foreach ( ( isset( $server['tool_list'] ) ? (array) $server['tool_list'] : array() ) as $tool ) {
			$tools[] = array(
				'name'        => isset( $tool['name'] ) ? $tool['name'] : '',
				'description' => isset( $tool['description'] ) ? $tool['description'] : '',
			);
		}
		return $tools;
	}

	/**
	 * Build a standard single-server card array for one server row.
	 *
	 * @param array $server A server from mcp_servers().
	 * @param array $mcp    The MCP descriptor (for auth).
	 * @return array
	 */
	private function build_server_card( $server, $mcp ) {
		$site = $this->envelope->site();
		$card = array(
			'schemaVersion' => '2024-11-05',
			'serverInfo'    => array(
				'name'    => ( isset( $server['name'] ) && '' !== $server['name'] ) ? $server['name'] : $site['name'],
				'version' => ( isset( $server['version'] ) && '' !== $server['version'] ) ? $server['version'] : '1.0.0',
			),
			'transport'     => array(
				'type' => ( isset( $server['transport'] ) && '' !== $server['transport'] ) ? $server['transport'] : 'http',
				'url'  => isset( $server['endpoint'] ) ? $server['endpoint'] : '',
			),
			'tools'         => self::card_tools( $server ),
		);
		if ( ! empty( $mcp['auth'] ) ) {
			$card['auth'] = array( 'type' => $mcp['auth'] );
			if ( ! empty( $mcp['auth_metadata'] ) ) {
				$card['auth']['metadata'] = $mcp['auth_metadata'];
			}
		}
		return $card;
	}

	/**
	 * The RFC 9728 Protected Resource Metadata URL when this site serves it — by ANY
	 * means: the owner-declared auth server (settings → oauth_auth_server), a real
	 * file on disk, or a registry-registered provider. Lets the MCP block link the
	 * OAuth handshake regardless of HOW the doc is produced. Returns '' — never a
	 * dead link — when nothing serves it.
	 *
	 * @return string Absolute URL, or ''.
	 */
	private function oauth_prm_url() {
		$served = '' !== trim( (string) $this->settings->get( 'oauth_auth_server', '' ) )
			|| file_exists( \Agentimus\Paths::site_root() . '.well-known/oauth-protected-resource' )
			|| isset( $this->registry->well_known()['oauth-protected-resource'] );
		return $served ? home_url( '/.well-known/oauth-protected-resource' ) : '';
	}

	/**
	 * Pretty JSON for a well-known doc (slashes/unicode unescaped), or '' on failure.
	 *
	 * @param mixed $data Data to encode.
	 * @return string
	 */
	private function encode( $data ) {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}
}
