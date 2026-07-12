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
	 * (`application/linkset+json`). Points agents at this site's API descriptions:
	 * the discovery document (service-desc), the WordPress REST root, and every API
	 * base already derived for the discovery envelope. The document complement to
	 * the `rel="api-catalog"` Link header — same information, fetchable at the
	 * standard well-known path some scanners check directly.
	 *
	 * @return string
	 */
	public function api_catalog_json() {
		$built = $this->envelope->build();
		$rest  = esc_url_raw( rest_url() );

		$service_desc = array(
			array( 'href' => home_url( '/.well-known/discovery.json' ), 'type' => 'application/json', 'title' => 'WP Discovery document' ),
		);
		$service = array(
			array( 'href' => $rest, 'type' => 'application/json', 'title' => 'WordPress REST API' ),
		);

		$seen = array( $rest => true );
		foreach ( $built['apis'] as $api ) {
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
					'anchor'       => $built['site']['url'],
					'service-desc' => $service_desc,
					'service'      => $service,
				),
			),
		);

		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
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
