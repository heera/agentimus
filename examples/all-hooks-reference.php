<?php
/**
 * Agentimus — complete hook reference for plugin authors.
 *
 * Every hook Agentimus exposes, grouped by STABILITY TIER. This file is
 * documentation only; it is NOT loaded by Agentimus. Copy the blocks you need
 * into your own plugin. With no WP_Discovery engine active, all of this is inert.
 *
 * For the full resource SCHEMA used by $registry->register() (capabilities,
 * endpoints, auth, agent cards, MCP tools), see examples/integrate-your-plugin.php.
 *
 * TIERS
 *   [STABLE]    Public integration API, frozen at WP_Discovery spec 1.0. Build on these.
 *   [EXTENSION] Supported output-shaping filters. Useful for deeper integrations;
 *               signatures may evolve between releases — test against the version you target.
 *   [INTERNAL]  Advanced site-owner / internal-tuning knobs. NOT a third-party
 *               integration surface; listed here only for completeness.
 *
 * @package Agentimus\Examples
 */

defined( 'ABSPATH' ) || exit;

/* ===================================================================== *
 *  [STABLE] — Registration & core extension points.
 * ===================================================================== */

// Declare your plugin's resources, and optionally serve your own /.well-known docs.
add_action( 'wpdiscovery_register', function ( $registry ) {
	$registry->register( array(
		'id'    => 'acme',
		'title' => 'Acme',
		'type'  => 'commerce', // full schema: see examples/integrate-your-plugin.php
	) );
	// Serve a document at /.well-known/<name> (callback | redirect | file).
	$registry->add_well_known( array(
		'name'     => 'acme.json',
		'callback' => function () { return wp_json_encode( array( 'ok' => true ) ); },
	) );
} );
// `agentimus_register` is a product-aliased copy of the action above — hook either, not both.

// Offer extra schema.org entity types in Settings → Identity.
add_filter( 'agentimus_entity_types', function ( $types ) {
	$types[] = 'Restaurant';
	return $types;
} );

// React when Agentimus regenerates its documents (e.g. purge your CDN edge).
add_action( 'agentimus_cache_flushed', function () {} );

// After the plugin finishes booting — a companion / Pro add-on hooks its features here.
add_action( 'agentimus_booted', function ( $plugin ) {} );

/* ===================================================================== *
 *  [EXTENSION] — Discovery document shaping.
 * ===================================================================== */

// The $schema URL of the discovery document.
add_filter( 'agentimus_schema_url', function ( $url ) { return $url; } );

// The whole assembled envelope. Add x-<vendor> keys; the unprefixed namespace is the spec's.
add_filter( 'agentimus_envelope', function ( $envelope, $registry ) {
	$envelope['x-acme'] = array( 'portal' => 'https://acme.example' );
	return $envelope;
}, 10, 2 );

// The `documents` map — add a standard doc Agentimus can't auto-detect.
add_filter( 'agentimus_documents', function ( $docs, $registry ) {
	$docs['acme_openapi'] = home_url( '/wp-json/acme/v1/openapi.json' );
	return $docs;
}, 10, 2 );

// Label a /.well-known name with the standard that governs it.
add_filter( 'agentimus_well_known_specs', function ( $specs ) {
	$specs['acme.json'] = 'Acme Manifest';
	return $specs;
} );

// Route your own flat / nested /.well-known names so they resolve on every host.
add_filter( 'agentimus_well_known_routed', function ( $names ) { $names[] = 'acme.json'; return $names; } );
add_filter( 'agentimus_well_known_nested', function ( $names ) { $names[] = 'acme/card.json'; return $names; } );

// Surfaces your companion signer signs (Web Bot Auth / HTTP Message Signatures).
add_filter( 'agentimus_signed_surfaces', function ( $names ) { $names[] = 'acme.json'; return $names; } );

/* ----- MCP & agents ----- */

// Annotate the advertised MCP descriptor (/.well-known/mcp.json).
add_filter( 'agentimus_mcp', function ( $mcp, $resources ) { $mcp['x-acme'] = true; return $mcp; }, 10, 2 );
// Pin which server the MCP server card describes ('' = auto-pick the richest).
add_filter( 'agentimus_mcp_card_server', function ( $id, $servers ) { return $id; }, 10, 2 );
// Append entries to /.well-known/agent-skills/index.json.
add_filter( 'agentimus_agent_skills', function ( $skills, $resources ) {
	$skills[] = array( 'id' => 'acme_do', 'name' => 'Do thing', 'description' => '…', 'resource' => 'acme' );
	return $skills;
}, 10, 2 );

/* ----- Content & llms.txt / llms-full.txt ----- */

// Which post types are agent-visible (each gets its own section in llms.txt).
add_filter( 'agentimus_post_types', function ( $types, $available ) { return $types; }, 10, 2 );
// Attribute a post type's section.
add_filter( 'agentimus_post_type_source', function ( $source, $post_type ) { return $source; }, 10, 2 );
// Provide a custom markdown body for a post (return null to render it normally).
add_filter( 'agentimus_markdown_source', function ( $html, $post ) { return $html; }, 10, 2 );
// Exclude terms from the llms.txt Topics list.
add_filter( 'agentimus_topic_exclude', function ( $terms ) { $terms[] = 'changelog'; return $terms; } );
// Full-text edition size budgets.
add_filter( 'agentimus_llms_full_item_max_bytes', function ( $bytes ) { return $bytes; } );
add_filter( 'agentimus_llms_full_avg_item_bytes', function ( $bytes ) { return $bytes; } );
// Cede a surface to your own producer: llms_txt | llms_full | markdown | link_headers | robots.
add_filter( 'agentimus_yield_surface', function ( $yield, $surface ) {
	return 'robots' === $surface ? true : $yield;
}, 10, 2 );

/* ----- schema.org JSON-LD ----- */

add_filter( 'agentimus_defer_schema', function ( $active ) { return $active; } );        // emit front-end JSON-LD?
add_filter( 'agentimus_schema_for_post', function ( $node, $post ) { return $node; }, 10, 2 );
add_filter( 'agentimus_schema_graph', function ( $graph ) { return $graph; } );          // edit the whole @graph
add_filter( 'agentimus_faq_pairs', function ( $pairs, $post ) { return $pairs; }, 10, 2 ); // contribute FAQPage Q/A

/* ----- Sitemap ----- */

add_filter( 'agentimus_sitemap', function ( $url ) { return $url; } );           // override the detected sitemap URL
add_filter( 'agentimus_sitemap_max_urls', function ( $n ) { return $n; } );      // cap URLs in the generated sitemap

/* ----- REST auto-discovery ----- */

add_filter( 'agentimus_rest_discovery', function ( $on ) { return $on; } );                  // master switch
add_filter( 'agentimus_rest_namespaces', function ( $allowed ) { $allowed[] = 'acme/v1'; return $allowed; } );
add_filter( 'agentimus_rest_skip_namespaces', function ( $skip ) { return $skip; } );
add_filter( 'agentimus_discoverable_ability', function ( $ok, $name, $ability ) { return $ok; }, 10, 3 );

/* ----- security.txt (RFC 9116) ----- */

add_filter( 'agentimus_serve_security_txt', function ( $on ) { return $on; } );              // let Agentimus generate it
add_filter( 'agentimus_security_txt', function ( $body ) { return $body; } );                // edit the final body
add_filter( 'agentimus_security_txt_expires_days', function ( $days ) { return $days; } );

/* ----- Readiness & signing ----- */

add_filter( 'agentimus_readiness_checks', function ( $checks, $settings ) { return $checks; }, 10, 2 );
add_filter( 'agentimus_signing_secret_key', function ( $key ) { return $key; } );            // supply ed25519 key from a vault

/* ===================================================================== *
 *  [INTERNAL] — Guard / Classifier / Activity / Settings tuning.
 *  Advanced site-owner knobs — NOT a third-party integration surface.
 *  Listed for completeness; prefer the tiers above for plugin integration.
 * ===================================================================== */

/* Guard (opt-in UA blocking) */
add_filter( 'agentimus_deny_request', function ( $deny, $ua_lc ) { return $deny; }, 10, 2 );
add_filter( 'agentimus_block_allowlist', function ( $allowed ) { return $allowed; } );
add_filter( 'agentimus_engine_signatures', function ( $sigs ) { return $sigs; } );
add_filter( 'agentimus_generic_ua_tokens', function ( $generic ) { return $generic; } );

/* Classifier (labelling, not blocking) */
add_filter( 'agentimus_agent_map', function ( $map ) { return $map; } );
add_filter( 'agentimus_spoof_signatures', function ( $sigs ) { return $sigs; } );

/* Activity analytics & catalogs */
add_filter( 'agentimus_known_agents', function ( $catalog ) { return $catalog; } );
add_filter( 'agentimus_known_scanners', function ( $known ) { return $known; } );
add_filter( 'agentimus_known_trainers', function ( $known ) { return $known; } );
add_filter( 'agentimus_ai_referral_sources', function ( $map ) { return $map; } );
add_filter( 'agentimus_activity_skip_self', function ( $skip ) { return $skip; } );
add_filter( 'agentimus_activity_retention_days', function ( $days ) { return $days; } );
add_filter( 'agentimus_new_agent_seconds', function ( $secs ) { return $secs; } );
add_filter( 'agentimus_burst_min_hits', function ( $n ) { return $n; } );
add_filter( 'agentimus_heavy_min_hits', function ( $n ) { return $n; } );
add_filter( 'agentimus_threats_limit', function ( $n ) { return $n; } );

/* Settings internals */
add_filter( 'agentimus_default_settings', function ( $defaults ) { return $defaults; } );
add_filter( 'agentimus_settings', function ( $all ) { return $all; } );
add_filter( 'agentimus_sanitize_settings', function ( $clean, $input ) { return $clean; }, 10, 2 );
add_action( 'agentimus_settings_reset', function () {} );
