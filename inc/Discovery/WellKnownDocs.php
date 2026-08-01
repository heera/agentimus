<?php
/**
 * WellKnownDocs — the secondary /.well-known JSON documents derived from the
 * discovery manifest: the A2A agent-card, the RFC 9727 API catalog, RFC 9728
 * OAuth Protected Resource Metadata, and the Agent Skills index. Each is a thin
 * projection over {@see Envelope} (the core manifest) + site settings. The master
 * discovery.json itself stays on Envelope; this holds the derived companions so
 * Envelope keeps to one job (assembling the manifest).
 *
 * @package Agentimus
 */

namespace Agentimus\Discovery;

use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class WellKnownDocs {

	/** @var Settings */
	private $settings;

	/** @var Registry */
	private $registry;

	/** @var Envelope The core manifest these documents project from. */
	private $envelope;

	/**
	 * @param Settings $settings Site identity + feature flags.
	 * @param Registry $registry Collected resources.
	 * @param Envelope $envelope The core manifest.
	 */
	public function __construct( Settings $settings, Registry $registry, Envelope $envelope ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->envelope = $envelope;
	}

	/**
	 * The generated A2A agent-card document, JSON-encoded.
	 *
	 * @return string
	 */
	public function agent_card_json() {
		$built = $this->envelope->build();
		$card  = array(
			'name'        => $built['site']['name'],
			'description' => $built['site']['description'],
			'url'         => $built['site']['url'],
			'provider'    => array(
				'organization' => $this->settings->identity( 'name', $built['site']['name'] ),
				'url'          => $built['site']['url'],
			),
			'agents'      => $built['agents'],
		);
		$json = wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * The RFC 9727 API catalog at /.well-known/api-catalog, as an RFC 9264 link set
	 * (`application/linkset+json`). Shaped exactly as RFC 9727 Appendix A.2: the
	 * first linkset entry is anchored at the catalog's own well-known URI and lists
	 * every API as an `item` bookmark; each API then gets its own entry carrying its
	 * `service-desc` (the OpenAPI document for the REST root, provider schemas for
	 * the rest). The document complement to the `rel="api-catalog"` Link header —
	 * same information, fetchable at the standard well-known path some scanners
	 * check directly.
	 *
	 * @return string
	 */
	public function api_catalog_json() {
		$built = $this->envelope->build();
		$rest  = esc_url_raw( rest_url() );

		$item = array(
			array( 'href' => $rest, 'type' => 'application/json', 'title' => 'WordPress REST API' ),
		);
		// Per-API linkset entries: each anchored at its own base with its description
		// document, per RFC 9727 §4 — a client follows an item, then reads its entry.
		$per_api = array(
			array(
				'anchor'       => $rest,
				'service-desc' => array(
					array( 'href' => home_url( '/.well-known/openapi.json' ), 'type' => 'application/json', 'title' => 'OpenAPI 3.1' ),
				),
			),
		);

		$seen = array( $rest => true );
		foreach ( $built['apis'] as $api ) {
			if ( '' === $api['base'] || ! empty( $seen[ $api['base'] ] ) ) {
				continue;
			}
			$seen[ $api['base'] ] = true;
			$item[]               = array( 'href' => $api['base'], 'type' => 'application/json', 'title' => $api['id'] );
			if ( ! empty( $api['schema'] ) ) {
				$per_api[] = array(
					'anchor'       => $api['base'],
					'service-desc' => array( array( 'href' => $api['schema'], 'type' => 'application/json' ) ),
				);
			}
		}

		$linkset = array_merge(
			array(
				array(
					'anchor' => home_url( '/.well-known/api-catalog' ),
					'item'   => $item,
				),
			),
			$per_api,
			array(
				// The site-level entry earlier versions served first: the discovery
				// document as the site's own service description. Kept (last) so a
				// consumer that learned the old shape still finds it.
				array(
					'anchor'       => $built['site']['url'],
					'service-desc' => array(
						array( 'href' => home_url( '/.well-known/discovery.json' ), 'type' => 'application/json', 'title' => 'WP Discovery document' ),
					),
				),
			)
		);

		$json = wp_json_encode( array( 'linkset' => $linkset ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * RFC 9728 OAuth Protected Resource Metadata at the FLAT well-known path.
	 * The owner's declared EXTERNAL authorization server wins (their explicit
	 * statement about the whole site); otherwise, while the built-in OAuth server
	 * is live, the flat path serves the SAME metadata as the MCP endpoint's
	 * path-suffixed forms — because scanners and older clients probe only the
	 * flat form, and a 404 there reads as "no OAuth on this domain" while a
	 * working authorization server sits one path segment away. With neither,
	 * '' → a clean 404. We never fabricate a flat RFC 8414 authorization-server
	 * document — its issuer-match rule (§3.3) would make ours a lie there.
	 *
	 * @return string JSON, or '' when no auth server exists in any form.
	 */
	public function oauth_protected_resource_json() {
		$auth = trim( (string) $this->settings->get( 'oauth_auth_server', '' ) );
		if ( '' !== $auth ) {
			$doc = array(
				'resource'              => home_url( '/' ),
				'authorization_servers' => array( $auth ),
			);
		} elseif ( isset( $this->registry->well_known()['oauth-protected-resource'] ) ) {
			// A provider registered the flat document — theirs serves (the router
			// falls through to it on our ''). Only the owner's explicit external
			// declaration above outranks a provider; the built-in server must not.
			return '';
		} elseif ( $this->settings->enabled( 'enable_mcp_server' ) ) {
			$doc = \Agentimus\Oauth\Server::protected_resource_metadata();
		} else {
			return '';
		}
		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * RFC 9728 metadata for the MCP endpoint itself, protected by the BUILT-IN
	 * authorization server. Path-suffixed under the issuer, so it never
	 * collides with the flat site-wide document above (that one stays the
	 * owner's declared EXTERNAL server) or with another plugin's OAuth. '' →
	 * clean 404 while the MCP server is off.
	 *
	 * @return string JSON, or '' when the MCP server is disabled.
	 */
	public function oauth_mcp_protected_resource_json() {
		if ( ! $this->settings->enabled( 'enable_mcp_server' ) ) {
			return '';
		}
		$json = wp_json_encode( \Agentimus\Oauth\Server::protected_resource_metadata(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * RFC 8414 authorization-server metadata for the built-in OAuth server —
	 * clients derive this path from the issuer identifier. '' → clean 404
	 * while the MCP server is off.
	 *
	 * @return string JSON, or '' when the MCP server is disabled.
	 */
	public function oauth_authorization_server_json() {
		if ( ! $this->settings->enabled( 'enable_mcp_server' ) ) {
			return '';
		}
		$json = wp_json_encode( \Agentimus\Oauth\Server::authorization_server_metadata(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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
		// published_resources(), NOT the raw registry. This is a SERVED, anonymous surface, so it
		// must apply the full publication boundary — owner suppression AND the provider's `public`
		// flag. Reading the registry directly filtered suppression alone and leaked every gated
		// ability's id, name and full description here (scan-exposed-files among them) even after
		// they were correctly removed from discovery.json, mcp.json and agent-card. Every other
		// served surface already sources from this one gate; this method was the sole exception.
		$resources = $this->envelope->published_resources();

		$skills = array();
		foreach ( $resources as $resource ) {
			$agent = ( isset( $resource['agent'] ) && is_array( $resource['agent'] ) ) ? $resource['agent'] : array();
			if ( empty( $agent['skills'] ) || ! is_array( $agent['skills'] ) ) {
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
		$skills = (array) apply_filters( 'agentimus_agent_skills', $skills, $resources );
		if ( empty( $skills ) ) {
			return '';
		}

		$doc  = array(
			'schemaVersion' => '2024-11-05',
			'site'          => $this->envelope->site()['name'],
			'skills'        => array_values( $skills ),
		);
		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}
}
