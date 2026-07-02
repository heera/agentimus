<?php
/**
 * Agentimus — complete hook reference for plugin authors.
 *
 * Every hook Agentimus exposes, grouped by stability tier. This file is
 * documentation only; it is NOT loaded by Agentimus. Copy the blocks you need
 * into your own plugin. With no WP_Discovery engine active, all of this is inert.
 *
 * For the full resource SCHEMA used by $registry->register() (capabilities,
 * endpoints, auth, agent cards, MCP tools), see examples/integrate-your-plugin.php.
 *
 * Tiers
 *   [STABLE]    Public integration API, frozen at WP_Discovery spec 1.0. Build on these.
 *   [EXTENSION] Supported output-shaping filters. Useful for deeper integrations;
 *               signatures may evolve between releases — test against the version you target.
 *   [INTERNAL]  Advanced site-owner / internal-tuning knobs. Not a third-party
 *               integration surface; listed here for completeness.
 *
 * @package Agentimus\Examples
 */

defined( 'ABSPATH' ) || exit;

/* =====================================================================
 * [STABLE] Registration & core extension points
 * ===================================================================== */

/**
 * Declare your plugin's resources, and optionally serve your own /.well-known
 * documents. The single integration every provider needs.
 */
add_action(
	'wpdiscovery_register',
	function ( $registry ) {
		// See examples/integrate-your-plugin.php for the full resource schema.
		$registry->register(
			array(
				'id'    => 'acme',
				'title' => 'Acme',
				'type'  => 'commerce',
			)
		);

		// Serve a document at /.well-known/<name> (callback | redirect | file).
		$registry->add_well_known(
			array(
				'name'     => 'acme.json',
				'callback' => function () {
					return wp_json_encode( array( 'ok' => true ) );
				},
			)
		);
	}
);
// `agentimus_register` is a product-aliased copy of the action above — hook either, not both.

/**
 * Add selectable schema.org entity types to Settings → Identity.
 */
add_filter(
	'agentimus_entity_types',
	function ( $types ) {
		$types[] = 'Restaurant';
		return $types;
	}
);

/**
 * Run after Agentimus regenerates its documents — e.g. purge your CDN / page cache.
 */
add_action(
	'agentimus_cache_flushed',
	function () {
		// my_cdn_purge( array( '/llms.txt', '/llms-full.txt', '/.well-known/discovery.json' ) );
	}
);

/**
 * Run after the plugin finishes booting — a companion or Pro add-on registers its
 * own features here against the shared plugin instance.
 *
 * @param \Agentimus\Plugin $plugin The booted plugin instance.
 */
add_action(
	'agentimus_booted',
	function ( $plugin ) {
		// my_addon_boot( $plugin );
	}
);

/* =====================================================================
 * [EXTENSION] Discovery document & .well-known
 * ===================================================================== */

/**
 * The $schema URL of the discovery document. Return '' to omit it entirely.
 */
add_filter( 'agentimus_schema_url', fn( $url ) => $url );

/**
 * The whole assembled discovery.json envelope. Add x-<vendor> keys for vendor
 * extensions — the unprefixed namespace is reserved for the spec.
 *
 * @param array                          $envelope The discovery envelope.
 * @param \Agentimus\Discovery\Registry  $registry The collector.
 */
add_filter(
	'agentimus_envelope',
	function ( $envelope, $registry ) {
		$envelope['x-acme'] = array( 'portal' => 'https://acme.example' );
		return $envelope;
	},
	10,
	2
);

/**
 * The `documents` map — add a standard document Agentimus can't auto-detect.
 *
 * @param array                          $docs     name => URL.
 * @param \Agentimus\Discovery\Registry  $registry The collector.
 */
add_filter(
	'agentimus_documents',
	function ( $docs, $registry ) {
		$docs['acme_openapi'] = home_url( '/wp-json/acme/v1/openapi.json' );
		return $docs;
	},
	10,
	2
);

/**
 * Label a /.well-known name with the standard that governs it.
 */
add_filter(
	'agentimus_well_known_specs',
	function ( $specs ) {
		$specs['acme.json'] = 'Acme Manifest';
		return $specs;
	}
);

/**
 * Route your own flat /.well-known name so it resolves on every host.
 */
add_filter(
	'agentimus_well_known_routed',
	function ( $names ) {
		$names[] = 'acme.json';
		return $names;
	}
);

/**
 * Route an exact-match nested /.well-known/<dir>/<file> name.
 */
add_filter(
	'agentimus_well_known_nested',
	function ( $names ) {
		$names[] = 'acme/card.json';
		return $names;
	}
);

/**
 * Add a surface your companion signer signs (Web Bot Auth / HTTP Message Signatures).
 */
add_filter(
	'agentimus_signed_surfaces',
	function ( $names ) {
		$names[] = 'acme.json';
		return $names;
	}
);

/* =====================================================================
 * [EXTENSION] MCP & agents
 * ===================================================================== */

/**
 * Annotate the advertised MCP descriptor served at /.well-known/mcp.json.
 *
 * @param array   $mcp       The MCP descriptor.
 * @param array[] $resources Collected resources.
 */
add_filter(
	'agentimus_mcp',
	function ( $mcp, $resources ) {
		$mcp['x-acme'] = true;
		return $mcp;
	},
	10,
	2
);

/**
 * Pin which server the MCP server card describes. Return '' to auto-pick the
 * server with the most tools.
 *
 * @param string  $id      Server id to pin ('' = auto).
 * @param array[] $servers All detected servers.
 */
add_filter( 'agentimus_mcp_card_server', fn( $id, $servers ) => $id, 10, 2 );

/**
 * Append entries to the Agent Skills index at /.well-known/agent-skills/index.json.
 *
 * @param array[] $skills    Skill entries.
 * @param array[] $resources Collected resources.
 */
add_filter(
	'agentimus_agent_skills',
	function ( $skills, $resources ) {
		$skills[] = array(
			'id'          => 'acme_do',
			'name'        => 'Do thing',
			'description' => 'Perform the Acme action.',
			'resource'    => 'acme',
		);
		return $skills;
	},
	10,
	2
);

/* =====================================================================
 * [EXTENSION] Content, llms.txt & markdown
 * ===================================================================== */

/**
 * Which post types are agent-visible — each gets its own section in llms.txt.
 *
 * @param string[] $types     Selected post types.
 * @param string[] $available All public post types.
 */
add_filter(
	'agentimus_post_types',
	function ( $types, $available ) {
		if ( in_array( 'acme_product', $available, true ) ) {
			$types[] = 'acme_product';
		}
		return $types;
	},
	10,
	2
);

/**
 * Attribute a post type's llms.txt section to your plugin.
 *
 * @param string $source    Vendor label ('' = none).
 * @param string $post_type Post type slug.
 */
add_filter(
	'agentimus_post_type_source',
	fn( $source, $post_type ) => 'acme_product' === $post_type ? 'Acme' : $source,
	10,
	2
);

/**
 * Supply rendered HTML for a post (e.g. page-builder content). Return null to
 * let Agentimus render it the normal way.
 *
 * @param string|null $html Pre-rendered HTML, or null.
 * @param \WP_Post     $post The post.
 */
add_filter( 'agentimus_markdown_source', fn( $html, $post ) => $html, 10, 2 );

/**
 * Topic/category slugs to omit from the llms.txt Topics list AND from a post's
 * auto-derived "Topics for AI". The default already excludes 'uncategorized'.
 */
add_filter(
	'agentimus_topic_exclude',
	function ( $slugs ) {
		$slugs[] = 'changelog';
		return $slugs;
	}
);

/**
 * Which taxonomies feed a post's auto-derived "Topics for AI". Core reads only the
 * WordPress built-ins (category, post_tag); declare your own so its terms are
 * offered as topics wherever your content type is agent-visible. The terms flow
 * through the SAME path as the core ones — the editor's derive toggle, the exclude
 * list above, de-duplication and the per-item cap — so vendors give their data
 * WITHOUT any vendor-specific code living in Agentimus.
 *
 * Example: a WooCommerce store surfacing product categories, tags and a brand
 * attribute as topics on its products.
 *
 * @param string[] $taxonomies Taxonomy slugs.
 * @param \WP_Post  $post       The post being described.
 */
add_filter(
	'agentimus_derive_taxonomies',
	function ( $taxonomies, $post ) {
		if ( 'product' === $post->post_type ) {
			$taxonomies[] = 'product_cat';
			$taxonomies[] = 'product_tag';
			$taxonomies[] = 'pa_brand'; // a global product attribute
		}
		return $taxonomies;
	},
	10,
	2
);

/**
 * Last word on a post's "Topics for AI": add or refine the final list directly.
 * Use this when your topics are NOT taxonomy terms (e.g. computed from post meta).
 * Whatever you return is re-normalised — trimmed, de-duplicated case-insensitively
 * and capped — so a filter can never overflow or corrupt a machine surface.
 *
 * @param string[] $topics Resolved topics (manual + derived).
 * @param \WP_Post  $post   The post.
 */
add_filter(
	'agentimus_post_topics',
	function ( $topics, $post ) {
		return $topics;
	},
	10,
	2
);

/**
 * Authoritative reference URLs for a topic — emitted as schema.org `sameAs` on
 * its `about` DefinedTerm so an assistant resolves the exact entity (e.g. the
 * planet Mercury, not the element). Agentimus never looks these up itself — no
 * front-end calls and no risky auto-matching — so you supply them, e.g. from a
 * small topic → Wikidata map.
 *
 * @param string[] $urls  Reference URLs (default none).
 * @param string   $topic The topic text.
 * @param \WP_Post  $post  The post.
 */
add_filter(
	'agentimus_topic_links',
	function ( $urls, $topic ) {
		$map = array(
			'WordPress' => 'https://www.wikidata.org/wiki/Q13166',
			'PHP'       => 'https://www.wikidata.org/wiki/Q59',
		);
		if ( isset( $map[ $topic ] ) ) {
			$urls[] = $map[ $topic ];
		}
		return $urls;
	},
	10,
	2
);

/**
 * The suggestions offered (as you type) in the editor's "Topics for AI" box.
 * Defaults to topics already used on the site, its tags & categories, and your
 * declared Expertise — add your own controlled vocabulary here.
 *
 * @param string[] $pool Suggested topic strings.
 */
add_filter(
	'agentimus_topic_suggestions',
	function ( $pool ) {
		$pool[] = 'llms.txt';
		$pool[] = 'AI visibility';
		return $pool;
	}
);

/**
 * Per-item byte cap for the llms-full.txt full-text edition.
 */
add_filter( 'agentimus_llms_full_item_max_bytes', fn( $bytes ) => $bytes );

/**
 * Average item size used only to ESTIMATE the full-text edition size in the admin.
 */
add_filter( 'agentimus_llms_full_avg_item_bytes', fn( $bytes ) => 4096 );

/**
 * Cede a surface to your own producer so Agentimus stops emitting it.
 * Surfaces: llms_txt | llms_full | markdown | link_headers | robots.
 *
 * @param bool   $yield   Whether to yield.
 * @param string $surface The surface key.
 */
add_filter( 'agentimus_yield_surface', fn( $yield, $surface ) => 'robots' === $surface ? true : $yield, 10, 2 );

/* =====================================================================
 * [EXTENSION] schema.org JSON-LD
 * ===================================================================== */

/**
 * Whether to emit the front-end JSON-LD. Return false to stand down for an SEO plugin.
 */
add_filter( 'agentimus_defer_schema', fn( $active ) => $active );

/**
 * Replace a single post's JSON-LD node (e.g. a Product or Service).
 *
 * @param array    $node The node.
 * @param \WP_Post $post The post.
 */
add_filter(
	'agentimus_schema_for_post',
	function ( $node, $post ) {
		if ( 'acme_product' === $post->post_type ) {
			$node['@type'] = 'Product';
		}
		return $node;
	},
	10,
	2
);

/**
 * Last-chance edit of the entire JSON-LD @graph before output.
 */
add_filter( 'agentimus_schema_graph', fn( $graph ) => $graph );

/**
 * Contribute extra question/answer pairs to the FAQPage schema.
 *
 * @param array[]  $pairs FAQ pairs.
 * @param \WP_Post $post  The post.
 */
add_filter( 'agentimus_faq_pairs', fn( $pairs, $post ) => $pairs, 10, 2 );

/* =====================================================================
 * [EXTENSION] Sitemap
 * ===================================================================== */

/**
 * Override the detected sitemap URL (return the fallback unchanged to keep it).
 */
add_filter( 'agentimus_sitemap', fn( $url ) => $url );

/**
 * Cap the number of URLs in the generated sitemap.
 */
add_filter( 'agentimus_sitemap_max_urls', fn( $n ) => 2000 );

/* =====================================================================
 * [EXTENSION] REST auto-discovery
 * ===================================================================== */

/**
 * Master switch for REST namespace auto-discovery.
 */
add_filter( 'agentimus_rest_discovery', fn( $on ) => $on );

/**
 * REST namespaces to publish in the discovery document.
 */
add_filter(
	'agentimus_rest_namespaces',
	function ( $allowed ) {
		$allowed[] = 'acme/v1';
		return $allowed;
	}
);

/**
 * REST namespaces to exclude from discovery.
 */
add_filter(
	'agentimus_rest_skip_namespaces',
	function ( $skip ) {
		$skip[] = 'acme-internal/v1';
		return $skip;
	}
);

/**
 * Include or exclude a single WP ability from discovery.
 *
 * @param bool   $ok      Whether to include it.
 * @param string $name    Ability name.
 * @param mixed  $ability The ability object.
 */
add_filter( 'agentimus_discoverable_ability', fn( $ok, $name, $ability ) => $ok, 10, 3 );

/* =====================================================================
 * [EXTENSION] security.txt, readiness & signing
 * ===================================================================== */

/**
 * Whether Agentimus should generate a /.well-known/security.txt.
 */
add_filter( 'agentimus_serve_security_txt', fn( $on ) => $on );

/**
 * Edit the final security.txt body.
 */
add_filter(
	'agentimus_security_txt',
	function ( $body ) {
		return rtrim( $body ) . "\nAcknowledgments: https://acme.example/security/thanks\n";
	}
);

/**
 * The security.txt Expires window, in days.
 */
add_filter( 'agentimus_security_txt_expires_days', fn( $days ) => 180 );

/**
 * Add or adjust the admin Discovery Hub readiness checks.
 *
 * @param array[]             $checks   Readiness checks.
 * @param \Agentimus\Settings $settings Settings.
 */
add_filter(
	'agentimus_readiness_checks',
	function ( $checks, $settings ) {
		$checks[] = array(
			'id'    => 'acme_api_reachable',
			'label' => 'Acme API reachable',
			'pass'  => true,
		);
		return $checks;
	},
	10,
	2
);

/**
 * Supply the Ed25519 signing secret key from a constant/vault instead of the DB.
 */
add_filter( 'agentimus_signing_secret_key', fn( $key ) => $key );

/* =====================================================================
 * [INTERNAL] Guard (opt-in UA blocking)
 * Advanced site-owner knobs — not a third-party integration surface.
 * ===================================================================== */

/**
 * The Guard's final say on whether to 403 a request.
 *
 * @param bool   $deny  Whether to deny.
 * @param string $ua_lc Lower-cased user agent.
 */
add_filter( 'agentimus_deny_request', fn( $deny, $ua_lc ) => $deny, 10, 2 );

/**
 * Clients that must never be hard-blocked (search engines + your allow-list).
 */
add_filter(
	'agentimus_block_allowlist',
	function ( $allowed ) {
		$allowed[] = 'acme-monitor';
		return $allowed;
	}
);

/**
 * Structured engine signatures used to match real crawlers at a token boundary.
 */
add_filter(
	'agentimus_engine_signatures',
	function ( $sigs ) {
		$sigs[] = 'acme-crawler';
		return $sigs;
	}
);

/**
 * Generic user-agent tokens treated as low-signal.
 */
add_filter(
	'agentimus_generic_ua_tokens',
	function ( $generic ) {
		$generic[] = 'acme-genericbot';
		return $generic;
	}
);

/* =====================================================================
 * [INTERNAL] Classifier (labelling, not blocking)
 * ===================================================================== */

/**
 * User-agent → friendly label for the activity log.
 */
add_filter(
	'agentimus_agent_map',
	function ( $map ) {
		$map['acme-research-agent'] = 'Acme Research Agent';
		return $map;
	}
);

/**
 * Platform markers that flag a spoofed/legacy-device "scanner".
 */
add_filter(
	'agentimus_spoof_signatures',
	function ( $sigs ) {
		$sigs[] = 'mozilla/5.0 acme-spoof';
		return $sigs;
	}
);

/* =====================================================================
 * [INTERNAL] Activity analytics & catalogs
 * ===================================================================== */

/**
 * Known-agent catalog (user-agent => label) for the activity log.
 */
add_filter(
	'agentimus_known_agents',
	function ( $catalog ) {
		$catalog['acme-research-agent'] = 'Acme Research Agent';
		return $catalog;
	}
);

/**
 * Scanner user-agents offered as one-click block suggestions.
 */
add_filter(
	'agentimus_known_scanners',
	function ( $known ) {
		$known[] = 'acme-scanner';
		return $known;
	}
);

/**
 * AI-trainer user-agents offered for robots.txt blocking.
 */
add_filter(
	'agentimus_known_trainers',
	function ( $known ) {
		$known[] = 'acme-trainer';
		return $known;
	}
);

/**
 * Referrer host => friendly name for "Traffic from AI" attribution.
 */
add_filter(
	'agentimus_ai_referral_sources',
	function ( $map ) {
		$map['acme.example'] = 'Acme';
		return $map;
	}
);

/**
 * Whether to skip recording hits from logged-in admins.
 */
add_filter( 'agentimus_activity_skip_self', fn( $skip ) => $skip );

/**
 * How long agent hits are retained, in days.
 */
add_filter( 'agentimus_activity_retention_days', fn( $days ) => $days );

/**
 * The "new agent" window for the activity-to-review panel, in seconds.
 */
add_filter( 'agentimus_new_agent_seconds', fn( $secs ) => $secs );

/**
 * Minimum hits to flag a burst.
 */
add_filter( 'agentimus_burst_min_hits', fn( $n ) => $n );

/**
 * Minimum hits to flag heavy usage.
 */
add_filter( 'agentimus_heavy_min_hits', fn( $n ) => $n );

/**
 * Maximum rows in the "activity to review" panel.
 */
add_filter( 'agentimus_threats_limit', fn( $n ) => $n );

/* =====================================================================
 * [INTERNAL] Settings
 * ===================================================================== */

/**
 * The default settings array (seed your own companion defaults).
 */
add_filter( 'agentimus_default_settings', fn( $defaults ) => $defaults );

/**
 * The live, merged settings array at read time.
 */
add_filter( 'agentimus_settings', fn( $all ) => $all );

/**
 * Validate/coerce companion-added fields when settings are saved.
 *
 * @param array $clean Sanitized settings.
 * @param array $input Raw input.
 */
add_filter( 'agentimus_sanitize_settings', fn( $clean, $input ) => $clean, 10, 2 );

/**
 * Run when the owner resets settings (e.g. clear your own caches).
 */
add_action(
	'agentimus_settings_reset',
	function () {
		// my_addon_clear_caches();
	}
);
