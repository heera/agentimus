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
				'organization' => $this->settings->display_name(),
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
	 * The Agent Skills index at /.well-known/agent-skills/index.json, in the
	 * agentskills.io discovery format (0.2.0): `$schema` + `skills[]`, each entry a
	 * name / type / description / url / digest. It lists the site's own SKILL.md —
	 * always served, so the index always has an entry — with the description its
	 * frontmatter carries and a sha256 over the exact bytes served there.
	 *
	 * Ability "skills" (`agent.skills[]`) are NOT listed: they have no SKILL.md or
	 * archive behind them, and the spec has no entry type for them. They stay on
	 * the agent card, which applies the publication boundary.
	 *
	 * @return string JSON.
	 */
	public function agent_skills_index_json() {
		$skill_md = new SkillMd( $this->settings, $this->envelope );

		$skills = array(
			array(
				'name'        => SkillMd::name(),
				'type'        => 'skill-md',
				'description' => $skill_md->summary(),
				'url'         => SkillMd::url(),
				'digest'      => 'sha256:' . hash( 'sha256', $skill_md->markdown() ),
			),
		);

		/**
		 * Filter the Agent Skills index entries. Each entry follows the discovery
		 * spec — name (lowercase-hyphen, ≤ 64), type (`skill-md` or `archive`),
		 * description (≤ 1024), url, and digest (`sha256:` + 64 hex over the
		 * artifact's bytes). An entry missing any of them is left out.
		 *
		 * @param array[] $skills    Skill entries.
		 * @param array[] $resources Published resources.
		 */
		$skills = (array) apply_filters( 'agentimus_agent_skills', $skills, $this->envelope->published_resources() );
		$skills = array_values( array_filter( $skills, array( __CLASS__, 'is_skill_entry' ) ) );

		$doc  = array(
			'$schema' => 'https://schemas.agentskills.io/discovery/0.2.0/schema.json',
			'skills'  => array_map(
				function ( $skill ) {
					return array(
						'name'        => $skill['name'],
						'type'        => $skill['type'],
						'description' => $skill['description'],
						'url'         => $skill['url'],
						'digest'      => $skill['digest'],
					);
				},
				$skills
			),
		);
		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * Whether an index entry carries every field the discovery spec requires, in
	 * the form it requires — a client skips an entry it cannot use, so we never
	 * publish one.
	 *
	 * @param mixed $skill Candidate entry.
	 * @return bool
	 */
	private static function is_skill_entry( $skill ) {
		if ( ! is_array( $skill ) ) {
			return false;
		}
		foreach ( array( 'name', 'type', 'description', 'url', 'digest' ) as $key ) {
			if ( ! isset( $skill[ $key ] ) || ! is_string( $skill[ $key ] ) || '' === $skill[ $key ] ) {
				return false;
			}
		}
		return strlen( $skill['name'] ) <= 64
			&& 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $skill['name'] )
			&& in_array( $skill['type'], array( 'skill-md', 'archive' ), true )
			&& mb_strlen( $skill['description'] ) <= 1024
			&& 1 === preg_match( '/^sha256:[0-9a-f]{64}$/', $skill['digest'] );
	}
}
