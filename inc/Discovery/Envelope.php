<?php
/**
 * Envelope — derives the machine-discovery documents from the collected
 * Registry: the master `/.well-known/discovery.json` index plus the generated
 * A2A `agent-card.json`. Nothing here is hand-maintained; every section is a
 * projection of the registry + site identity, cached for an hour.
 *
 * @package Agentify
 */

namespace Agentify\Discovery;

use Agentify\Cache;
use Agentify\Settings;

defined( 'ABSPATH' ) || exit;

final class Envelope {

	/**
	 * Frozen wire-format version of the Discovery Document — major.minor, NOT
	 * semver. A consumer selects its parser on this exact string (spec §02), so it
	 * stays "1.0" for the life of the 1.x wire format; additive, backward-compatible
	 * changes do not bump it. The plugin's own release version is separate (see the
	 * header in agentify.php).
	 */
	const SPEC_VERSION = '1.0';

	/**
	 * Home of the JSON Schema. A `$schema` is fundamentally an identifier, so it
	 * lives on a domain we actually control and that resolves; the bytes can be
	 * served from anywhere (GitHub Pages on the spec repo, a CNAME, the WP host).
	 * Per-site overridable via the `agentify_discovery_schema_url` filter, so a
	 * vendor-neutral home can replace this with no release once one is registered.
	 */
	const SCHEMA_BASE = 'https://heera.github.io/wp-discovery-protocol/schemas';

	/** @var Settings */
	private $settings;

	/** @var Registry */
	private $registry;

	/**
	 * @param Settings $settings Site identity + feature flags.
	 * @param Registry $registry Collected resources.
	 */
	public function __construct( Settings $settings, Registry $registry ) {
		$this->settings = $settings;
		$this->registry = $registry;
	}

	/**
	 * The master discovery.json, JSON-encoded (cached).
	 *
	 * @return string
	 */
	public function discovery_json() {
		$cached = Cache::get( Cache::DISCOVERY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$json = wp_json_encode( $this->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = is_string( $json ) ? $json : '{}';
		Cache::set( Cache::DISCOVERY, $json );
		return $json;
	}

	/**
	 * The generated A2A agent-card document, JSON-encoded.
	 *
	 * @return string
	 */
	public function agent_card_json() {
		$envelope = $this->build();
		$card     = array(
			'name'        => $envelope['site']['name'],
			'description' => $envelope['site']['description'],
			'url'         => $envelope['site']['url'],
			'provider'    => array(
				'organization' => $this->settings->identity( 'name', $envelope['site']['name'] ),
				'url'          => $envelope['site']['url'],
			),
			'agents'      => $envelope['agents'],
		);
		$json = wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}

	/* ---------------------------------------------------------------------- *
	 *  Build
	 * ---------------------------------------------------------------------- */

	/**
	 * Assemble the full envelope array.
	 *
	 * @return array
	 */
	public function build() {
		$this->registry->collect();
		$resources = array_values( $this->registry->resources() );

		// Owner authority / the publication boundary (spec §04, M14): drop any
		// Resource the owner suppressed. A provider proposes; the owner disposes.
		// Filtering here, before any derivation, keeps suppressed Resources out of
		// apis[], agents[], capabilities AND resources[] — every served surface.
		$suppressed = $this->suppressed_ids();
		if ( ! empty( $suppressed ) ) {
			$resources = array_values(
				array_filter(
					$resources,
					static function ( $r ) use ( $suppressed ) {
						return ! in_array( $r['id'], $suppressed, true );
					}
				)
			);
		}

		// The frozen wire-format core: exactly the eleven top-level keys the spec
		// (§02 / M2) defines, in order. Experimental surfaces — the MCP descriptor
		// and the tool list — are deliberately NOT baked into the stable contract;
		// they are served at /.well-known/mcp.json (linked from `well_known`) so they
		// can track the still-unsettled MCP discovery proposal without a wire bump.
		// A consumer needing extras inline can add `x-`-prefixed keys via the
		// `agentify_discovery_envelope` filter; the unprefixed namespace is the spec's.
		$envelope = array(
			/**
			 * The JSON Schema URL. Filter to point at a different (e.g. GitHub-
			 * hosted) home until the canonical schema is published.
			 *
			 * @param string $url Schema URL.
			 */
			'$schema'      => apply_filters( 'agentify_discovery_schema_url', self::SCHEMA_BASE . '/discovery/' . self::SPEC_VERSION . '/discovery.schema.json' ),
			'spec_version' => self::SPEC_VERSION,
			'site'         => $this->site(),
			'identity'     => $this->identity(),
			'documents'    => $this->documents(),
			'well_known'   => $this->well_known_index(),
			'apis'         => $this->apis( $resources ),
			'agents'       => $this->agents( $resources ),
			'resources'    => array_map( array( $this, 'absolutize_resource' ), $resources ),
			'capabilities' => $this->capabilities( $resources ),
			// Cast to object so an empty trust block encodes as {}, not [].
			'trust'        => (object) $this->trust(),
		);

		/**
		 * Filter the assembled discovery envelope before encoding.
		 *
		 * @param array    $envelope  The envelope.
		 * @param Registry $registry  The collector.
		 */
		return apply_filters( 'agentify_discovery_envelope', $envelope, $this->registry );
	}

	/**
	 * Every collected Resource, absolutized, BEFORE owner suppression is applied.
	 * For admin curation UIs that must show suppressed Resources so the owner can
	 * re-enable them — NOT for serving (the served document uses build()).
	 *
	 * @return array[]
	 */
	public function all_resources() {
		$this->registry->collect();
		return array_map( array( $this, 'absolutize_resource' ), array_values( $this->registry->resources() ) );
	}

	/**
	 * Resource ids the site owner has suppressed from all served output — the
	 * publication boundary defined in spec §04 ("Owner authority").
	 *
	 * @return string[]
	 */
	public function suppressed_ids() {
		return array_values( (array) $this->settings->get( 'suppressed_resources', array() ) );
	}

	/* ---------------------------------------------------------------------- *
	 *  Sections
	 * ---------------------------------------------------------------------- */

	/** @return array */
	private function site() {
		return array(
			'name'        => $this->settings->identity( 'name', get_bloginfo( 'name' ) ),
			'url'         => home_url( '/' ),
			'description' => get_bloginfo( 'description' ),
			'lang'        => get_bloginfo( 'language' ),
			'logo'        => (string) get_site_icon_url(),
		);
	}

	/** @return array The "whoami" — the person/org behind the site. */
	private function identity() {
		$type     = (string) $this->settings->identity( 'entity_type', 'Person' );
		$contacts = array();
		// Opt-in only — never leak the site's admin_email into a public document.
		$email = (string) $this->settings->identity( 'contact_email', '' );
		if ( '' !== $email && is_email( $email ) ) {
			$contacts[] = array( 'type' => 'email', 'value' => 'mailto:' . $email );
		}
		return array(
			'type'         => 'Organization' === $type ? 'organization' : 'person',
			'name'         => $this->settings->identity( 'name', get_bloginfo( 'name' ) ),
			'role'         => (string) $this->settings->identity( 'role', '' ),
			'about'        => (string) $this->settings->identity( 'about', '' ),
			'url'          => home_url( '/' ),
			'same_as'      => array_values( (array) $this->settings->identity( 'same_as', array() ) ),
			'contacts'     => $contacts,
		);
	}

	/** @return array Generated/owned content mirrors, gated by feature flags. */
	private function documents() {
		$docs = array( 'sitemap' => $this->sitemap_url(), 'robots' => home_url( '/robots.txt' ) );
		if ( $this->settings->enabled( 'enable_llms_txt' ) ) {
			$docs['llms'] = home_url( '/llms.txt' );
		}
		if ( $this->settings->enabled( 'enable_llms_full' ) ) {
			$docs['llms_full'] = home_url( '/llms-full.txt' );
		}
		if ( file_exists( ABSPATH . '.well-known/security.txt' ) || isset( $this->registry->well_known()['security.txt'] ) ) {
			$docs['security'] = home_url( '/.well-known/security.txt' );
		}
		return array_filter( $docs );
	}

	/**
	 * Every `/.well-known/*` resource this site exposes: real files on disk,
	 * plugin-managed docs, and the ones this plugin generates.
	 *
	 * @return array[]
	 */
	private function well_known_index() {
		$index = array();

		// 1. Generated by this plugin.
		foreach ( array( 'discovery.json', 'agent-card.json', 'agent.json', 'mcp.json' ) as $name ) {
			$index[ $name ] = array( 'name' => $name, 'url' => home_url( '/.well-known/' . $name ), 'source' => 'generated' );
		}

		// 2. Plugin-managed providers.
		foreach ( $this->registry->well_known() as $name => $def ) {
			$index[ $name ] = array( 'name' => $name, 'url' => home_url( '/.well-known/' . $name ), 'source' => 'managed' );
		}

		// 3. Real files on disk (authoritative — server serves these directly).
		$dir = ABSPATH . '.well-known/';
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '*' ) as $path ) {
				if ( is_file( $path ) ) {
					$name           = basename( $path );
					$index[ $name ] = array( 'name' => $name, 'url' => home_url( '/.well-known/' . $name ), 'source' => 'file' );
				}
			}
		}

		return array_values( $index );
	}

	/**
	 * Flatten resource endpoints into the API index.
	 *
	 * @param array[] $resources Resources.
	 * @return array[]
	 */
	private function apis( $resources ) {
		$apis = array();
		foreach ( $resources as $resource ) {
			foreach ( $resource['endpoints'] as $endpoint ) {
				if ( ! in_array( $endpoint['type'], Resource::API_TYPES, true ) ) {
					continue;
				}
				// Per-endpoint auth wins when declared (e.g. a public Store API
				// alongside an authenticated admin API on the same resource);
				// otherwise fall back to the resource-level scheme.
				$auth_type = '' !== $endpoint['auth'] ? $endpoint['auth'] : $resource['auth']['type'];
				$apis[]    = array(
					'id'     => $resource['id'],
					'type'   => $endpoint['type'],
					'base'   => $this->absolute( $endpoint['url'] ),
					'schema' => isset( $resource['schemas'][0] ) ? $this->absolute( $resource['schemas'][0] ) : '',
					'auth'   => array(
						'type' => $auth_type,
						'docs' => $this->absolute( $resource['auth']['docs'] ),
					),
				);
			}
		}
		return $apis;
	}

	/**
	 * Resources that carried an agent-card fragment.
	 *
	 * @param array[] $resources Resources.
	 * @return array[]
	 */
	private function agents( $resources ) {
		$agents = array();
		foreach ( $resources as $resource ) {
			if ( empty( $resource['agent'] ) ) {
				continue;
			}
			$agent          = $resource['agent'];
			$agent['id']    = $resource['id'];
			$agent['card']  = home_url( '/.well-known/agent-card.json#' . $resource['id'] );
			$agent['endpoint'] = $this->absolute( isset( $agent['endpoint'] ) ? $agent['endpoint'] : '' );
			$agents[]       = $agent;
		}
		return $agents;
	}

	/**
	 * Deduped union of every resource's capabilities.
	 *
	 * @param array[] $resources Resources.
	 * @return string[]
	 */
	private function capabilities( $resources ) {
		$caps = array();
		foreach ( $resources as $resource ) {
			$caps = array_merge( $caps, $resource['capabilities'] );
		}
		return array_values( array_unique( $caps ) );
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
			$mcp = array(
				'available' => true,
				'source'    => 'wordpress-mcp',
				'endpoint'  => $servers[0]['endpoint'],
				'transport' => $servers[0]['transport'],
				'auth'      => 'application-password',
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

		/**
		 * Filter the advertised MCP descriptor.
		 *
		 * @param array   $mcp       The descriptor.
		 * @param array[] $resources Collected resources.
		 */
		return (array) apply_filters( 'agentify_discovery_mcp', $mcp, $resources );
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
				$route   = method_exists( $server, 'get_server_route' ) ? (string) $server->get_server_route() : '';
				$out[]   = array(
					'id'        => method_exists( $server, 'get_server_id' ) ? (string) $server->get_server_id() : '',
					'name'      => method_exists( $server, 'get_server_name' ) ? (string) $server->get_server_name() : '',
					'version'   => method_exists( $server, 'get_server_version' ) ? (string) $server->get_server_version() : '',
					'endpoint'  => esc_url_raw( rest_url( trailingslashit( $namespace ) . ltrim( $route, '/' ) ) ),
					'transport' => 'streamable-http',
					'tools'     => method_exists( $server, 'get_tools' ) ? count( (array) $server->get_tools() ) : 0,
				);
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
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
		$site    = $this->site();
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

	/** @return array Minimal trust surface (v2: jwks_uri, signed cards, DID). */
	private function trust() {
		// Always state authenticity explicitly. Wire-format 1.0 documents are NOT
		// cryptographically signed, so a consumer MUST NOT assume this document is
		// verified — say so rather than leaving an empty {} that implies nothing.
		// (Signing — jwks_uri / signed cards / DID — is the deferred v2 surface.)
		$trust = array( 'signed' => false );
		if ( file_exists( ABSPATH . '.well-known/security.txt' ) || isset( $this->registry->well_known()['security.txt'] ) ) {
			$trust['security_txt'] = home_url( '/.well-known/security.txt' );
		}
		$policy = get_privacy_policy_url();
		if ( $policy ) {
			$trust['policy'] = $policy;
		}
		return $trust;
	}

	/* ---------------------------------------------------------------------- *
	 *  Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Absolutize a stored resource's URLs against the site for output.
	 *
	 * @param array $resource Normalized resource.
	 * @return array
	 */
	private function absolutize_resource( $resource ) {
		foreach ( $resource['endpoints'] as &$endpoint ) {
			$endpoint['url'] = $this->absolute( $endpoint['url'] );
		}
		unset( $endpoint );
		$resource['schemas']    = array_map( array( $this, 'absolute' ), $resource['schemas'] );
		$resource['docs']       = $this->absolute( $resource['docs'] );
		$resource['auth']['oidc'] = $this->absolute( $resource['auth']['oidc'] );
		$resource['auth']['docs'] = $this->absolute( $resource['auth']['docs'] );
		return $resource;
	}

	/**
	 * Resolve a possibly-relative URL against the site root.
	 *
	 * @param string $url URL or "/path".
	 * @return string
	 */
	private function absolute( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		if ( '/' === $url[0] ) {
			return home_url( $url );
		}
		return $url;
	}

	/**
	 * Best-effort sitemap URL (mirrors Endpoints).
	 *
	 * @return string
	 */
	private function sitemap_url() {
		if ( function_exists( 'wp_sitemaps_get_server' ) ) {
			$server = wp_sitemaps_get_server();
			if ( $server && $server->sitemaps_enabled() ) {
				return home_url( '/wp-sitemap.xml' );
			}
		}
		return home_url( '/sitemap_index.xml' );
	}
}
