<?php
/**
 * Envelope — derives the machine-discovery documents from the collected
 * Registry: the master `/.well-known/discovery.json` index plus the generated
 * A2A `agent-card.json`. Nothing here is hand-maintained; every section is a
 * projection of the registry + site identity, cached for an hour.
 *
 * @package Agentomatic
 */

namespace Agentomatic\Discovery;

use Agentomatic\Cache;
use Agentomatic\Settings;

defined( 'ABSPATH' ) || exit;

final class Envelope {

	/**
	 * Frozen wire-format version of the Discovery Document — major.minor, NOT
	 * semver. A consumer selects its parser on this exact string (spec §02), so it
	 * stays "1.0" for the life of the 1.x wire format; additive, backward-compatible
	 * changes do not bump it. The plugin's own release version is separate (see the
	 * header in agentomatic.php).
	 */
	const SPEC_VERSION = '1.0';

	/**
	 * Home of the JSON Schema. A `$schema` is fundamentally an identifier, so it
	 * lives on a domain we actually control and that resolves; the bytes can be
	 * served from anywhere (GitHub Pages on the spec repo, a CNAME, the WP host).
	 * Per-site overridable via the `agentomatic_schema_url` filter, so a
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

	/**
	 * The RFC 9727 API catalog at /.well-known/api-catalog, as an RFC 9264 link set
	 * (`application/linkset+json`). Points agents at this site's API descriptions:
	 * the discovery document (service-desc), the WordPress REST root, and every API
	 * base already derived for the discovery envelope. The document complement to
	 * the `rel="api-catalog"` Link header — same information, fetchable at the
	 * standard well-known path some scanners check directly.
	 *
	 * @return string
	 */
	public function api_catalog_json() {
		$envelope = $this->build();
		$rest     = esc_url_raw( rest_url() );

		$service_desc = array(
			array( 'href' => home_url( '/.well-known/discovery.json' ), 'type' => 'application/json', 'title' => 'WP Discovery document' ),
		);
		$service = array(
			array( 'href' => $rest, 'type' => 'application/json', 'title' => 'WordPress REST API' ),
		);

		$seen = array( $rest => true );
		foreach ( $envelope['apis'] as $api ) {
			if ( '' !== $api['base'] && empty( $seen[ $api['base'] ] ) ) {
				$service[]            = array( 'href' => $api['base'], 'type' => 'application/json', 'title' => $api['id'] );
				$seen[ $api['base'] ] = true;
			}
			if ( ! empty( $api['schema'] ) ) {
				$service_desc[] = array( 'href' => $api['schema'], 'type' => 'application/json' );
			}
		}

		$doc = array(
			'linkset' => array(
				array(
					'anchor'       => $envelope['site']['url'],
					'service-desc' => $service_desc,
					'service'      => $service,
				),
			),
		);

		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
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
		// `agentomatic_envelope` filter; the unprefixed namespace is the spec's.
		$envelope = array(
			/**
			 * The JSON Schema URL. Filter to point at a different (e.g. GitHub-
			 * hosted) home until the canonical schema is published.
			 *
			 * @param string $url Schema URL.
			 */
			'$schema'      => apply_filters( 'agentomatic_schema_url', self::SCHEMA_BASE . '/discovery/' . self::SPEC_VERSION . '/discovery.schema.json' ),
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
		return apply_filters( 'agentomatic_envelope', $envelope, $this->registry );
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
			'type'         => 'Person' === $type ? 'person' : 'organization',
			'name'         => $this->settings->identity( 'name', get_bloginfo( 'name' ) ),
			'role'         => (string) $this->settings->identity( 'role', '' ),
			'about'        => (string) $this->settings->identity( 'about', '' ),
			'url'          => home_url( '/' ),
			'same_as'      => array_values( (array) $this->settings->identity( 'same_as', array() ) ),
			'contacts'     => $contacts,
		);
	}

	/**
	 * Site-wide content/document links an agent can fetch: the sitemap, robots, the
	 * RSS feed, the llms.txt pair, and humans.txt / security.txt when present. This
	 * is the "map, not territory" surface — every entry is a link to a standard,
	 * separately-served document — and it's filterable so a site can add ones the
	 * plugin can't auto-detect (OpenAPI, a web-app manifest, …) without a code change.
	 *
	 * @return array name => URL.
	 */
	private function documents() {
		$docs = array(
			'sitemap' => $this->sitemap_url(),
			'robots'  => home_url( '/robots.txt' ),
			'feed'    => get_feed_link(),
		);
		if ( $this->settings->enabled( 'enable_llms_txt' ) ) {
			$docs['llms'] = home_url( '/llms.txt' );
		}
		if ( $this->settings->enabled( 'enable_llms_full' ) ) {
			$docs['llms_full'] = home_url( '/llms-full.txt' );
		}
		if ( file_exists( \Agentomatic\Paths::site_root() . 'humans.txt' ) ) {
			$docs['humans'] = home_url( '/humans.txt' );
		}
		if ( file_exists( \Agentomatic\Paths::site_root() . '.well-known/security.txt' ) || isset( $this->registry->well_known()['security.txt'] ) ) {
			$docs['security'] = home_url( '/.well-known/security.txt' );
		}

		/**
		 * Filter the discovery `documents` map. Add a standard document the plugin
		 * can't auto-detect — e.g. an OpenAPI description or a web-app manifest:
		 *
		 *     add_filter( 'agentomatic_documents', function ( $docs ) {
		 *         $docs['openapi'] = home_url( '/wp-json/myplugin/v1/openapi.json' );
		 *         return $docs;
		 *     } );
		 *
		 * Empty values are dropped after the filter.
		 *
		 * @param array    $docs     name => URL.
		 * @param Registry $registry The collector.
		 */
		$docs = (array) apply_filters( 'agentomatic_documents', $docs, $this->registry );

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
		foreach ( array( 'discovery.json', 'agent-card.json', 'agent.json', 'mcp.json', 'api-catalog' ) as $name ) {
			$index[ $name ] = array( 'name' => $name, 'url' => home_url( '/.well-known/' . $name ), 'source' => 'generated' );
		}

		// The signing key directory is listed only when signing is actually on.
		$signer = new Signer( $this->settings );
		if ( $signer->enabled() ) {
			$index[ Signer::DIRECTORY ] = array( 'name' => Signer::DIRECTORY, 'url' => home_url( '/.well-known/' . Signer::DIRECTORY ), 'source' => 'generated' );
		}

		// RFC 9728 protected-resource metadata is listed only when an auth server is set.
		if ( '' !== trim( (string) $this->settings->get( 'oauth_auth_server', '' ) ) ) {
			$index['oauth-protected-resource'] = array( 'name' => 'oauth-protected-resource', 'url' => home_url( '/.well-known/oauth-protected-resource' ), 'source' => 'generated' );
		}

		// 2. Plugin-managed providers.
		foreach ( $this->registry->well_known() as $name => $def ) {
			$index[ $name ] = array( 'name' => $name, 'url' => home_url( '/.well-known/' . $name ), 'source' => 'managed' );
		}

		// 3. Real files on disk (authoritative — server serves these directly).
		$dir = \Agentomatic\Paths::site_root() . '.well-known/';
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '*' ) as $path ) {
				if ( is_file( $path ) ) {
					$name           = basename( $path );
					$index[ $name ] = array( 'name' => $name, 'url' => home_url( '/.well-known/' . $name ), 'source' => 'file' );
				}
			}
		}

		// Annotate each recognised name with the standard that governs it, so a
		// consumer knows what the document IS — not just that it exists. Unknown
		// names get no `spec` rather than a fabricated one.
		$specs = $this->well_known_specs();
		foreach ( $index as $name => &$entry ) {
			if ( isset( $specs[ $name ] ) ) {
				$entry['spec'] = $specs[ $name ];
			}
		}
		unset( $entry );

		return array_values( $index );
	}

	/**
	 * Recognised /.well-known names → the standard that governs each, so the index
	 * can tell a consumer what every document IS. Filterable, so a provider can
	 * label a well-known it serves; unknown names are simply absent (no fabrication).
	 *
	 * @return array<string,string> name => spec label.
	 */
	private function well_known_specs() {
		$specs = array(
			// This protocol's own documents.
			'discovery.json'             => 'WP Discovery',
			'agent-card.json'            => 'A2A',
			'agent.json'                 => 'A2A (legacy)',
			'mcp.json'                   => 'MCP (experimental)',
			'mcp/server-card.json'       => 'MCP Server Card',
			'agent-skills/index.json'    => 'Agent Skills',
			'api-catalog'                => 'RFC 9727',
			'http-message-signatures-directory' => 'Web Bot Auth (HTTP Message Signatures)',
			// Security.
			'security.txt'               => 'RFC 9116',
			'mta-sts.txt'                => 'RFC 8461',
			// Identity, auth & federation.
			'openid-configuration'       => 'OpenID Connect Discovery',
			'oauth-authorization-server' => 'RFC 8414',
			'oauth-protected-resource'   => 'RFC 9728',
			'jwks.json'                  => 'RFC 7517',
			'webfinger'                  => 'RFC 7033',
			'host-meta'                  => 'RFC 6415',
			'openid-federation'          => 'OpenID Federation',
			'nodeinfo'                   => 'NodeInfo',
			// Apps & deep linking.
			'assetlinks.json'            => 'Digital Asset Links',
			'apple-app-site-association' => 'Apple Universal Links',
			// Web platform & privacy.
			'change-password'            => 'W3C Change Password URL',
			'gpc.json'                   => 'Global Privacy Control',
			'dnt-policy.txt'             => 'EFF Do Not Track',
			'traffic-advice'             => 'Private Prefetch Proxy',
			// Calendars & contacts.
			'caldav'                     => 'RFC 6764',
			'carddav'                    => 'RFC 6764',
			// Legacy.
			'ai-plugin.json'             => 'OpenAI Plugin (legacy)',
		);

		/**
		 * Filter the /.well-known name → governing-spec labels.
		 *
		 * @param array<string,string> $specs name => spec label.
		 */
		return (array) apply_filters( 'agentomatic_well_known_specs', $specs );
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
						'docs' => $this->auth_docs( $auth_type, $resource['auth'] ),
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
		return (array) apply_filters( 'agentomatic_mcp', $mcp, $resources );
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

	/**
	 * The MCP Server Card at /.well-known/mcp/server-card.json — the same MCP surface
	 * as mcp.json, projected into the schema scanners look for at that path. Served
	 * ONLY when a real MCP server is advertised (mcp.available); a content site with
	 * no server returns '' → a clean 404 (honest, never a stub).
	 *
	 * @return string JSON, or '' when no MCP server is available.
	 */
	public function mcp_server_card_json() {
		$surface = $this->mcp_surface();
		$mcp     = $surface['mcp'];
		if ( empty( $mcp['available'] ) ) {
			return '';
		}

		$site   = $this->site();
		$server = ! empty( $mcp['servers'][0] ) ? $mcp['servers'][0] : array();

		$tools = array();
		foreach ( $surface['tools'] as $tool ) {
			$tools[] = array(
				'name'        => isset( $tool['name'] ) ? $tool['name'] : '',
				'description' => isset( $tool['description'] ) ? $tool['description'] : '',
			);
		}

		$card = array(
			'schemaVersion' => '2024-11-05',
			'serverInfo'    => array(
				'name'    => $site['name'],
				'version' => ( isset( $server['version'] ) && '' !== $server['version'] ) ? $server['version'] : '1.0.0',
			),
			'transport'     => array(
				'type' => ( isset( $mcp['transport'] ) && '' !== $mcp['transport'] ) ? $mcp['transport'] : 'http',
				'url'  => isset( $mcp['endpoint'] ) ? $mcp['endpoint'] : '',
			),
			'tools'         => $tools,
		);
		if ( ! empty( $mcp['auth'] ) ) {
			$card['auth'] = array( 'type' => $mcp['auth'] );
			if ( ! empty( $mcp['auth_metadata'] ) ) {
				$card['auth']['metadata'] = $mcp['auth_metadata'];
			}
		}

		$json = wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * The Agent Skills index at /.well-known/agent-skills/index.json — the executable
	 * skills agents can invoke, projected from the per-namespace `agent.skills[]` the
	 * Abilities adapter already builds (respecting owner suppression). Served ONLY
	 * when real skills exist; otherwise '' → a clean 404.
	 *
	 * @return string JSON, or '' when no skills are exposed.
	 */
	public function agent_skills_index_json() {
		$this->registry->collect();
		$resources  = array_values( $this->registry->resources() );
		$suppressed = $this->suppressed_ids();

		$skills = array();
		foreach ( $resources as $resource ) {
			$agent = ( isset( $resource['agent'] ) && is_array( $resource['agent'] ) ) ? $resource['agent'] : array();
			if ( in_array( $resource['id'], $suppressed, true ) || empty( $agent['skills'] ) || ! is_array( $agent['skills'] ) ) {
				continue;
			}
			foreach ( $agent['skills'] as $skill ) {
				$id = isset( $skill['id'] ) ? (string) $skill['id'] : '';
				if ( '' === $id ) {
					continue;
				}
				$skills[] = array(
					'id'          => $id,
					'name'        => ( isset( $skill['name'] ) && '' !== $skill['name'] ) ? $skill['name'] : $id,
					'description' => isset( $skill['description'] ) ? (string) $skill['description'] : '',
					'resource'    => $resource['id'],
				);
			}
		}

		/**
		 * Filter the Agent Skills index entries.
		 *
		 * @param array[] $skills    Skill entries.
		 * @param array[] $resources Collected resources.
		 */
		$skills = (array) apply_filters( 'agentomatic_agent_skills', $skills, $resources );
		if ( empty( $skills ) ) {
			return '';
		}

		$doc  = array(
			'schemaVersion' => '2024-11-05',
			'site'          => $this->site()['name'],
			'skills'        => array_values( $skills ),
		);
		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * RFC 9728 OAuth Protected Resource Metadata — served only when the owner has
	 * declared an authorization server (settings → oauth_auth_server). For a site
	 * with no authenticated API this is '' → a clean 404. We never fabricate an
	 * RFC 8414 authorization-server document (WordPress is not one).
	 *
	 * @return string JSON, or '' when no auth server is configured.
	 */
	public function oauth_protected_resource_json() {
		$auth = trim( (string) $this->settings->get( 'oauth_auth_server', '' ) );
		if ( '' === $auth ) {
			return '';
		}
		$doc  = array(
			'resource'              => home_url( '/' ),
			'authorization_servers' => array( $auth ),
		);
		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '';
	}

	/** @return array Minimal trust surface (v2: jwks_uri, signed cards, DID). */
	private function trust() {
		// State authenticity explicitly. By default a 1.0 document is NOT signed, so
		// say so rather than leave an empty {}. When response signing is enabled
		// (Web Bot Auth), advertise that the discovery docs are signed and where the
		// verification keys live.
		$trust  = array( 'signed' => false );
		$signer = new Signer( $this->settings );
		if ( $signer->enabled() ) {
			$trust['signed']        = true;
			$trust['signature_alg'] = 'ed25519';
			$trust['jwks_uri']      = $signer->directory_url();
		}
		if ( file_exists( \Agentomatic\Paths::site_root() . '.well-known/security.txt' ) || isset( $this->registry->well_known()['security.txt'] ) ) {
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
	 * The public URL of a /.well-known doc, but ONLY when the site actually serves
	 * it (a real file on disk or a registered provider) — so the discovery document
	 * never advertises a dead link.
	 *
	 * @param string $name Doc name, e.g. "oauth-protected-resource".
	 * @return string Absolute URL, or '' if not served here.
	 */
	private function well_known_if_present( $name ) {
		if ( file_exists( \Agentomatic\Paths::site_root() . '.well-known/' . $name ) || isset( $this->registry->well_known()[ $name ] ) ) {
			return home_url( '/.well-known/' . $name );
		}
		return '';
	}

	/**
	 * The RFC 9728 Protected Resource Metadata URL when this site serves it — by ANY
	 * means: the owner-declared auth server (settings → oauth_auth_server), a real
	 * file on disk, or a registry-registered provider. Lets the MCP block link the
	 * OAuth handshake regardless of HOW the doc is produced (well_known_if_present
	 * alone misses the settings+route path that Envelope::oauth_protected_resource_json
	 * serves). Returns '' — never a dead link — when nothing serves it.
	 *
	 * @return string Absolute URL, or ''.
	 */
	private function oauth_prm_url() {
		$served = '' !== trim( (string) $this->settings->get( 'oauth_auth_server', '' ) )
			|| file_exists( \Agentomatic\Paths::site_root() . '.well-known/oauth-protected-resource' )
			|| isset( $this->registry->well_known()['oauth-protected-resource'] );
		return $served ? home_url( '/.well-known/oauth-protected-resource' ) : '';
	}

	/**
	 * The best "how to authenticate" link for an API endpoint, preferring standards
	 * over bespoke strings:
	 *   1. the provider's own auth docs, else
	 *   2. a provider-declared OpenID Connect configuration, else
	 *   3. the site's standard auth-metadata well-known for the scheme — OAuth 2.0
	 *      Authorization Server Metadata (RFC 8414) or OpenID Connect Discovery —
	 *      but only when it is actually published here.
	 *
	 * @param string $type Auth scheme (e.g. oauth, oidc).
	 * @param array  $auth The resource's raw (relative) auth block.
	 * @return string Absolute URL, or ''.
	 */
	private function auth_docs( $type, $auth ) {
		$docs = $this->absolute( isset( $auth['docs'] ) ? $auth['docs'] : '' );
		if ( '' !== $docs ) {
			return $docs;
		}
		$oidc = $this->absolute( isset( $auth['oidc'] ) ? $auth['oidc'] : '' );
		if ( '' !== $oidc ) {
			return $oidc;
		}
		$map = array(
			'oauth'  => 'oauth-authorization-server',
			'oauth2' => 'oauth-authorization-server',
			'oidc'   => 'openid-configuration',
		);
		$key = strtolower( (string) $type );
		return isset( $map[ $key ] ) ? $this->well_known_if_present( $map[ $key ] ) : '';
	}

	/**
	 * The detected sitemap URL (core or a known SEO plugin), or '' if none.
	 *
	 * @return string
	 */
	private function sitemap_url() {
		return \Agentomatic\Sitemap::url();
	}
}
