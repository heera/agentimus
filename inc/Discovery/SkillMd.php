<?php
/**
 * SkillMd — the site packaged as an Agent Skill (agentskills.io): YAML
 * frontmatter + markdown instructions telling an agent what this site offers
 * and when to reach for it. Served at /.well-known/agent-skills/{name}/SKILL.md
 * with a small markdown directory listing at /.well-known/agent-skills.
 *
 * Everything is generated from live state — the identity settings, the enabled
 * feature flags, and the detected MCP surface — so the skill never promises an
 * edition or a tool the site doesn't currently serve. The skill NAME is derived
 * from the site's own host (the spec requires lowercase-hyphen, matching the
 * parent directory), so the file an agent saves into its skill library is named
 * after the site it works, not after this plugin.
 *
 * @package Agentimus
 */

namespace Agentimus\Discovery;

use Agentimus\Endpoints;
use Agentimus\Settings;

defined( 'ABSPATH' ) || exit;

final class SkillMd {

	/** @var Settings */
	private $settings;

	/** @var Envelope Site identity + the detected MCP surface. */
	private $envelope;

	/**
	 * @param Settings $settings Feature flags + identity.
	 * @param Envelope $envelope The discovery manifest.
	 */
	public function __construct( Settings $settings, Envelope $envelope ) {
		$this->settings = $settings;
		$this->envelope = $envelope;
	}

	/**
	 * The skill name, derived from the site's own address: host + any subpath,
	 * lowered to the spec's alphabet (a-z, 0-9, single hyphens; no leading/
	 * trailing hyphen). "https://heera.it/" → "heera-it"; a subdirectory site
	 * keeps its path so two sites on one host get distinct names.
	 *
	 * @return string
	 */
	public static function name() {
		$home = (array) wp_parse_url( home_url( '/' ) );
		$raw  = ( isset( $home['host'] ) ? $home['host'] : 'site' ) . ( isset( $home['path'] ) ? $home['path'] : '' );
		$slug = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $raw ) );
		$slug = trim( (string) preg_replace( '/-{2,}/', '-', $slug ), '-' );
		return '' !== $slug ? $slug : 'site';
	}

	/**
	 * The public URL of the SKILL.md file.
	 *
	 * @return string
	 */
	public static function url() {
		return home_url( '/.well-known/agent-skills/' . self::name() . '/SKILL.md' );
	}

	/**
	 * The SKILL.md document: agentskills.io frontmatter (name, description with
	 * explicit when-to-use guidance, compatibility, metadata) followed by markdown
	 * instructions assembled from what the site actually serves right now.
	 *
	 * @return string
	 */
	public function markdown() {
		$site    = $this->envelope->site();
		$name    = '' !== $site['name'] ? $site['name'] : wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$mcp     = $this->envelope->mcp_surface()['mcp'];
		$has_mcp = ! empty( $mcp['available'] ) && ! empty( $mcp['servers'] );

		$out  = "---\n";
		$out .= 'name: ' . self::name() . "\n";
		$out .= 'description: ' . $this->yaml_text( $this->description( $name, $has_mcp ) ) . "\n";
		$out .= 'compatibility: ' . $this->yaml_text(
			$has_mcp
				? 'Any agent with HTTP access; the MCP tools need an MCP client with OAuth 2.0 support (or a WordPress application password).'
				: 'Any agent with HTTP access.'
		) . "\n";
		$out .= "metadata:\n";
		$out .= '  site: ' . home_url( '/' ) . "\n";
		$out .= '  generator: Agentimus ' . ( defined( 'AGENTIMUS_VERSION' ) ? AGENTIMUS_VERSION : '' ) . "\n";
		$out .= "---\n\n";

		$out .= '# ' . $name . "\n\n";
		if ( '' !== $site['description'] ) {
			$out .= $site['description'] . "\n\n";
		}

		$out .= "## Read this site\n\n";
		if ( $this->settings->enabled( 'enable_llms_txt' ) ) {
			$out .= '- Overview and page index for agents: ' . home_url( '/llms.txt' ) . "\n";
		}
		if ( $this->settings->enabled( 'enable_markdown' ) ) {
			$out .= '- Any page as markdown: append `.md` to its URL'
				. ( Endpoints::negotiates_markdown() ? ', or request it with `Accept: text/markdown`' : '' ) . "\n";
		}
		if ( $this->settings->enabled( 'enable_llms_full' ) ) {
			$out .= '- Full text in one document: ' . home_url( '/llms-full.txt' ) . "\n";
		}
		if ( $this->settings->enabled( 'enable_changes' ) ) {
			$out .= '- What changed recently (supports `?since=`): ' . home_url( '/agentimus-changes.json' ) . "\n";
		}
		$out .= '- Every machine surface, described: ' . home_url( '/.well-known/discovery.json' ) . "\n";

		if ( $has_mcp ) {
			$out .= "\n## Use the MCP server\n\n";
			$out .= 'Endpoint: `' . $mcp['endpoint'] . '` (' . $mcp['transport'] . ").\n";
			if ( ! empty( $mcp['auth_metadata'] ) ) {
				$out .= 'Auth: OAuth 2.0 — protected-resource metadata at ' . $mcp['auth_metadata']
					. '; the full walkthrough is at ' . home_url( '/auth.md' ) . ".\n";
			}
			$tools = isset( $mcp['servers'][0]['tool_list'] ) ? (array) $mcp['servers'][0]['tool_list'] : array();
			if ( ! empty( $tools ) ) {
				$out .= "\nTools:\n\n";
				foreach ( $tools as $tool ) {
					$out .= '- `' . $tool['name'] . '`'
						. ( '' !== $tool['description'] ? ' — ' . $this->first_sentence( $tool['description'] ) : '' ) . "\n";
				}
			}
		}

		$out .= "\n## When to use\n\n";
		$out .= '- To answer questions about ' . $name . ' or its content, fetch the page and read its markdown edition.' . "\n";
		if ( $has_mcp ) {
			$out .= '- To measure or improve how AI systems find, read and cite this site, call the MCP tools above.' . "\n";
			if ( $this->settings->enabled( 'enable_agent_writes' ) ) {
				$out .= '- Content changes (drafts, topics, descriptions, readiness fixes) go through the write tools; every write runs as an authenticated user the owner approved.' . "\n";
			} else {
				$out .= '- The MCP tools are read-only; content changes stay with the site owner.' . "\n";
			}
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * The markdown directory listing at /.well-known/agent-skills — what a probe
	 * of the bare path learns: which skills are packaged here and where the
	 * machine index of executable skills lives (linked only when it is served).
	 *
	 * @return string
	 */
	public function directory_markdown() {
		$site    = $this->envelope->site();
		$name    = '' !== $site['name'] ? $site['name'] : wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$mcp     = $this->envelope->mcp_surface()['mcp'];
		$has_mcp = ! empty( $mcp['available'] ) && ! empty( $mcp['servers'] );

		$out  = "# Agent skills\n\n";
		$out .= "Packaged in the agentskills.io SKILL.md format:\n\n";
		// The full what + when-to-use sentence lives here too — this listing is the
		// page a probing agent reads first, and guidance it has to click through
		// for is guidance it may never see.
		$out .= '- `' . self::name() . '` — ' . $this->description( $name, $has_mcp ) . "\n  " . self::url() . "\n";
		if ( '' !== $this->envelope->agent_skills_index_json() ) {
			$out .= "\nMachine index of executable skills: " . home_url( '/.well-known/agent-skills/index.json' ) . "\n";
		}
		return $out;
	}

	/**
	 * The frontmatter description: what the skill does AND when to use it, per
	 * the spec's guidance, composed only from capabilities that are actually on.
	 *
	 * @param string $name    Site display name.
	 * @param bool   $has_mcp Whether a live MCP server was detected.
	 * @return string
	 */
	private function description( $name, $has_mcp ) {
		$doing = $has_mcp
			? 'read its content in markdown, and use its MCP server to check AI readiness, AI traffic and citations'
			: 'and read its content in markdown';
		return sprintf(
			'Work with %1$s (%2$s) as an AI agent: fetch its machine editions, %3$s. Use when a task involves %1$s, its content, or its AI visibility.',
			$name,
			wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			$doing
		);
	}

	/**
	 * A string made safe for a single-line YAML scalar: quoted, quotes doubled.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function yaml_text( $text ) {
		return '"' . str_replace( '"', '\\"', (string) $text ) . '"';
	}

	/**
	 * The first sentence of a tool description — the skill lists tools as a map,
	 * not a manual; the full description lives on the server itself.
	 *
	 * @param string $text Full description.
	 * @return string
	 */
	private function first_sentence( $text ) {
		$text = trim( (string) $text );
		// A sentence ends at ". " only when a capital follows — "e.g. per-crawler"
		// is an abbreviation mid-sentence, not a boundary.
		if ( preg_match( '/^.*?\.(?=\s+[A-Z])/s', $text, $m ) ) {
			return $m[0];
		}
		return $text;
	}
}
