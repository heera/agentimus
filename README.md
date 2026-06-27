# Agentimus

[![PHP compatibility](https://github.com/heera/agentimus/actions/workflows/php-compat.yml/badge.svg?branch=main)](https://github.com/heera/agentimus/actions/workflows/php-compat.yml)
[![WordPress plugin version](https://img.shields.io/wordpress/plugin/v/agentimus?label=wordpress.org)](https://wordpress.org/plugins/agentimus/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/agentimus)](https://wordpress.org/plugins/agentimus/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

Make any WordPress site legible to AI agents and crawlers — `llms.txt`, a full-text
edition, markdown delivery, JSON-LD, and content-signal robots rules. Lightweight,
no SEO bloat, no framework.

**Live on WordPress.org:** <https://wordpress.org/plugins/agentimus/>

## Install

- **From your dashboard** — Plugins → Add New → search **"Agentimus"** → Install → Activate.
- **From WordPress.org** — <https://wordpress.org/plugins/agentimus/>.
- **From source** — clone this repo, run `npm install && npm run build` (produces `assets/admin/`), then copy or symlink the folder into `wp-content/plugins/`.

## What it does

| Signal | Endpoint / output |
|---|---|
| Link index | `/llms.txt` |
| Full-text edition | `/llms-full.txt` |
| Markdown delivery | `/<slug>.md` or `Accept: text/markdown` |
| Structured data | JSON-LD `WebSite` + `Person`/`Organization` + `BlogPosting` + `BreadcrumbList` (defers to SEO plugins) |
| XML sitemap | `/agentimus-sitemap.xml` — opt-in fallback, generated **only** when neither WordPress core nor an SEO plugin already provides one (sitemap index + paginated sub-sitemaps) |
| Crawler policy | `robots.txt` content-signal + training-crawler blocklist |
| Discovery layer | `/.well-known/discovery.json` (+ `agent-card.json`, `mcp.json`) |
| Crawl enforcement (opt-in) | hard-block (403) denylisted or spoofed "scanner" user-agents at the generated endpoints — ACME-safe, off by default |

## In the admin

- **Readiness report** — pass/warn/fail checks, each with a plain-English suggestion and a deep link to the fix (including a "sitemap advertised in robots.txt" check).
- **Agent activity log** — a local-only dashboard (no IP logged) of which AI agents and crawlers fetch your endpoints; repeat hits are grouped with a count, newest first.
- **Activity to review** — flags new, unusually high-volume, or spoofed/scanner clients in a nav-bar review queue, each with one-click **Block** (or **Allow**/trust). Pairs with the opt-in *Block scanners & scrapers* enforcement in Settings.
- **Factory reset** — one click restores every setting to its recommended defaults, with a preview of exactly what will change.

## Architecture

- **PHP** (`inc/`, namespace `Agentimus\`, PSR-4 autoloaded) — vanilla, no framework.
  - `Plugin` orchestrates; `Settings` is the single option store; `Cache` handles
    transients; `Endpoints` / `Markdown` / `Schema` produce output; `Readiness`
    runs the checks; `Rest` backs the admin; `Admin` mounts the UI.
- **Admin UI** — Vue 3 (Options API), built with Vite into `assets/admin/`.
  Talks to the REST namespace `agentimus/v1` with the standard WP nonce.

### Extending to any site / content type

The free core covers `post` + `page` and **any public post type you opt in**
(Content types card, or the `agentimus_post_types` filter), so products and
CPTs flow into llms.txt, the full-text edition, markdown and schema automatically.
Deeper coverage (a WooCommerce `Product` mapper, page-builder content) is an
add-on that hooks these seams:

- `agentimus_post_types` — add/remove agent-visible post types
- `agentimus_schema_for_post` — return a full node, e.g. `Product` with offers
- `agentimus_markdown_source` — supply rendered HTML for page-builder content

## For developers — make your plugin discoverable

Agentimus exposes a single aggregated discovery layer at
`/.well-known/discovery.json` (plus `agent-card.json` and `mcp.json`). Any plugin
can register itself with **one action and no dependency** — if Agentimus is not
installed, the hook never fires, so the code is inert:

```php
add_action( 'wpdiscovery_register', function ( $registry ) {
    $registry->register( array(
        'id'           => 'acme-bookings',
        'title'        => 'Acme Bookings',
        'type'         => 'scheduling',                 // controlled vocab + x-vendor-name
        'capabilities' => array( 'scheduling.booking.create' ), // dot-notation INTENT
        'endpoints'    => array(                        // WHERE (concrete paths live here)
            array( 'url' => '/wp-json/acme/v1', 'type' => 'rest', 'auth' => 'apikey' ),
        ),
        'auth'         => array( 'type' => 'apikey', 'docs' => 'https://acme.dev/api' ),
        'agent'        => array( 'name' => 'Acme Agent', 'skills' => array(
            array( 'id' => 'create_booking', 'description' => 'Book an appointment.' ),
        ) ),
    ) );
} );
```

A global facade is also available (guard it, since the call is direct):
```php
if ( class_exists( 'Agentimus_Discovery' ) ) {
    Agentimus_Discovery::register( [...] );
}
```

**Resource fields:** `id` (req, slug), `title` (req), `type` (req — `content`,
`commerce`, `scheduling`, `courses`, `forms`, `crm`, `auth`, `search`, `media`,
`messaging`, `analytics`, `payments`, `directory`, `agent`, or `x-vendor-name`),
`description`, `version`, `capabilities[]`, `endpoints[]` (`{url, type, methods[],
auth, description}`), `schemas[]`, `auth` (`{type, oidc, scopes[], docs}`),
`agent` (`{name, description, skills[{id,description}], endpoint, auth}`),
`abilities[]`, `tools[]` (MCP-shaped), `docs`. `provider` is auto-filled — don't set it.

Capabilities describe **intent**; the concrete `/wp-json/...` paths live only in
`endpoints`/`tools`. Invalid entries are rejected and surfaced (with the reason)
in **Discovery Hub → Validation**.

`$registry->add_well_known( [...] )` serves a `/.well-known/<name>` doc
(callback | redirect | file). See **`examples/integrate-your-plugin.php`** for the
full copy-paste reference, and the [**WP_Discovery Protocol**](https://github.com/heera/wp-discovery-protocol) spec for the standard.

## Hooks & filters

The dev-facing subset below; the plugin fires ~55 in all and every one is optional. They fall into three tiers: **Stable** — the `wpdiscovery_register` registration API plus `agentimus_entity_types` and `agentimus_cache_flushed`, frozen at WP_Discovery spec 1.0; **Extension** — the output-shaping filters listed here, supported but with signatures that may evolve between releases; and **Internal** — advanced Guard/Classifier/Activity/Settings tuning, not a third-party integration surface. The complete, tier-annotated catalogue with every signature is in [`examples/all-hooks-reference.php`](examples/all-hooks-reference.php).

**Stable** — public and won't break under you: the registration API above (frozen at WP_Discovery spec 1.0), plus these identity/lifecycle hooks.

```php
add_filter( 'agentimus_entity_types', function ( $types ) { $types[] = 'Restaurant'; return $types; } ); // schema.org subtypes in Settings → Identity
add_action( 'agentimus_cache_flushed', function () {                                                     // after Agentimus regenerates its docs
    my_cdn_purge( array( '/llms.txt', '/llms-full.txt', '/.well-known/discovery.json' ) );               //   → purge your CDN / page cache
} );
add_action( 'agentimus_booted', function ( $plugin ) {} );                                               // after boot (companion / Pro add-on seam)
```

**Extension** — supported output-shaping filters; useful for deeper integrations, but signatures may evolve between releases.

```php
// Discovery document & .well-known
add_filter( 'agentimus_envelope', function ( $envelope, $registry ) { return $envelope; }, 10, 2 );        // the assembled discovery.json
add_filter( 'agentimus_schema_url', function ( $url ) { return $url; } );                                  // the $schema value; '' to omit
add_filter( 'agentimus_well_known_nested', function ( $names ) { return $names; } );                       // extra nested /.well-known/ paths
add_filter( 'agentimus_signed_surfaces', function ( $docs ) { return $docs; } );                           // which discovery docs are signed
add_filter( 'agentimus_signing_secret_key', function ( $key ) { return $key; } );                         // supply the Ed25519 key from a constant/env

// MCP & agents
add_filter( 'agentimus_mcp', function ( $mcp, $resources ) { return $mcp; }, 10, 2 );                      // advertised MCP descriptor
add_filter( 'agentimus_agent_skills', function ( $skills, $resources ) { return $skills; }, 10, 2 );       // Agent Skills index
add_filter( 'agentimus_discoverable_ability', function ( $ok, $name, $ability ) { return $ok; }, 10, 3 );  // include/exclude a WP ability
add_filter( 'agentimus_rest_namespaces', function ( $allowed ) { return $allowed; } );                    // REST namespaces to publish

// Content, llms.txt & markdown
add_filter( 'agentimus_post_types', function ( $types, $available ) { return $types; }, 10, 2 );           // which post types are agent-visible
add_filter( 'agentimus_topic_exclude', function ( $slugs ) { return $slugs; } );                          // omit topic slugs from llms.txt
add_filter( 'agentimus_markdown_source', function ( $html, $post ) { return $html; }, 10, 2 );            // rendered HTML for page-builder content

// schema.org JSON-LD
add_filter( 'agentimus_defer_schema', function ( $active ) { return $active; } );                         // stand JSON-LD down for an SEO plugin
add_filter( 'agentimus_schema_for_post', function ( $node, $post ) { return $node; }, 10, 2 );            // replace a post's node (e.g. Product)
add_filter( 'agentimus_schema_graph', function ( $graph ) { return $graph; } );                          // the whole @graph

// security.txt & readiness
add_filter( 'agentimus_security_txt', function ( $body ) { return $body; } );                            // the /.well-known/security.txt body
add_filter( 'agentimus_readiness_checks', function ( $checks, $settings ) { return $checks; }, 10, 2 );  // add/adjust admin readiness checks
```

**Internal** — advanced Guard / Classifier / Activity / Settings tuning. Not a third-party integration surface; shown for completeness.

```php
// Guard, Classifier & activity log
add_filter( 'agentimus_deny_request', function ( $deny, $ua ) { return $deny; }, 10, 2 );     // the Guard's final say on a 403
add_filter( 'agentimus_block_allowlist', function ( $uas ) { return $uas; } );                // clients that must never be hard-blocked
add_filter( 'agentimus_known_trainers', function ( $uas ) { return $uas; } );                 // AI-trainer UAs offered for robots.txt
add_filter( 'agentimus_known_scanners', function ( $uas ) { return $uas; } );                 // scanner UAs offered as block suggestions
add_filter( 'agentimus_spoof_signatures', function ( $sigs ) { return $sigs; } );            // platform markers that flag a spoofed scanner
add_filter( 'agentimus_agent_map', function ( $map ) { return $map; } );                      // UA → friendly label for the activity log
add_filter( 'agentimus_activity_retention_days', function ( $days ) { return $days; } );      // how long agent hits are kept
add_filter( 'agentimus_heavy_min_hits', function ( $n ) { return $n; } );                     // "activity to review" thresholds:
add_filter( 'agentimus_burst_min_hits', function ( $n ) { return $n; } );
add_filter( 'agentimus_new_agent_seconds', function ( $secs ) { return $secs; } );
add_filter( 'agentimus_threats_limit', function ( $n ) { return $n; } );

// Settings
add_filter( 'agentimus_default_settings', function ( $defaults ) { return $defaults; } );     // default settings array
add_filter( 'agentimus_settings', function ( $all ) { return $all; } );                       // live settings array
```

> The complete, tier-annotated catalogue with every hook is in [`examples/all-hooks-reference.php`](examples/all-hooks-reference.php).

## Development

```bash
npm install
npm run build      # one-off build into assets/admin/
npm run dev        # rebuild on change
```

`assets/admin/` is git-ignored — it's a build artifact. Ship it in the
distributed `.zip` (the `.org` SVN tag), not the repo.

## Requirements

- WordPress 6.9+ (tested up to 7.0)
- PHP 7.4+.

## License

[GPL-2.0-or-later](LICENSE). The admin app is built from Vue source in `resources/` with Vite — no minified-only code ships, so the build is reproducible.
