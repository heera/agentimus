# Heera Discovery

[![PHP compatibility](https://github.com/heera/heera-agent-discovery/actions/workflows/php-compat.yml/badge.svg?branch=main)](https://github.com/heera/heera-agent-discovery/actions/workflows/php-compat.yml)

Make any WordPress site legible to AI agents and crawlers — `llms.txt`, a full-text
edition, markdown delivery, JSON-LD, and content-signal robots rules. Lightweight,
no SEO bloat, no framework.

## What it does

| Signal | Endpoint / output |
|---|---|
| Link index | `/llms.txt` |
| Full-text edition | `/llms-full.txt` |
| Markdown delivery | `/<slug>.md` or `Accept: text/markdown` |
| Structured data | JSON-LD `WebSite` + `Person`/`Organization` + `BlogPosting` + `BreadcrumbList` (defers to SEO plugins) |
| XML sitemap | `/heera-agent-discovery-sitemap.xml` — opt-in fallback, generated **only** when neither WordPress core nor an SEO plugin already provides one (sitemap index + paginated sub-sitemaps) |
| Crawler policy | `robots.txt` content-signal + training-crawler blocklist |
| Discovery layer | `/.well-known/discovery.json` (+ `agent-card.json`, `mcp.json`) |

## In the admin

- **Readiness report** — pass/warn/fail checks, each with a plain-English suggestion and a deep link to the fix (including a "sitemap advertised in robots.txt" check).
- **Agent activity log** — a local-only dashboard (no IP logged) of which AI agents and crawlers fetch your endpoints; repeat hits are grouped with a count, newest first.
- **Factory reset** — one click restores every setting to its recommended defaults, with a preview of exactly what will change.

## Architecture

- **PHP** (`inc/`, namespace `HeeraAgentDiscovery\`, PSR-4 autoloaded) — vanilla, no framework.
  - `Plugin` orchestrates; `Settings` is the single option store; `Cache` handles
    transients; `Endpoints` / `Markdown` / `Schema` produce output; `Readiness`
    runs the checks; `Rest` backs the admin; `Admin` mounts the UI.
- **Admin UI** — Vue 3 (Options API), built with Vite into `assets/admin/`.
  Talks to the REST namespace `heera-agent-discovery/v1` with the standard WP nonce.

### Extending to any site / content type

The free core covers `post` + `page` and **any public post type you opt in**
(Content types card, or the `heera_agent_discovery_post_types` filter), so products and
CPTs flow into llms.txt, the full-text edition, markdown and schema automatically.
Deeper coverage (a WooCommerce `Product` mapper, page-builder content) is an
add-on that hooks these seams:

- `heera_agent_discovery_post_types` — add/remove agent-visible post types
- `heera_agent_discovery_schema_for_post` — return a full node, e.g. `Product` with offers
- `heera_agent_discovery_markdown_source` — supply rendered HTML for page-builder content

## For developers — make your plugin discoverable

Heera Discovery exposes a single aggregated discovery layer at
`/.well-known/discovery.json` (plus `agent-card.json` and `mcp.json`). Any plugin
can register itself with **one action and no dependency** — if Heera Discovery is not
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
`if ( class_exists( 'Heera_Agent_Discovery' ) ) { Heera_Agent_Discovery::register( [...] ); }`.

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
full copy-paste reference, and the **WP_Discovery Protocol** spec for the standard.

## Hooks & filters

The dev-facing subset (the plugin fires ~40 in all; every one is optional).

**Identity**
- `heera_agent_discovery_entity_types` `(array $types)` — selectable schema.org entity types; add subtypes (e.g. `Restaurant`).

**Content, llms.txt & markdown**
- `heera_agent_discovery_post_types` `(array $types, array $available)` — which post types are agent-visible.
- `heera_agent_discovery_topic_exclude` `(array $slugs)` — topic/category slugs to omit from llms.txt (default `['uncategorized']`).
- `heera_agent_discovery_markdown_source` `(?string $html, WP_Post $post)` — supply rendered HTML for page-builder content.
- `heera_agent_discovery_defer_schema` `(bool $active)` — force JSON-LD to stand down for (or override) an SEO plugin.

**Discovery & `.well-known`**
- `heera_agent_discovery_envelope` `(array $envelope, $registry)` — the assembled `discovery.json`.
- `heera_agent_discovery_mcp` `(array $mcp, array $resources)` — the advertised MCP descriptor.
- `heera_agent_discovery_agent_skills` `(array $skills, array $resources)` — the Agent Skills index.
- `heera_agent_discovery_rest_namespaces` `(array $allowed)` — REST namespaces to publish.
- `heera_agent_discoverable_ability` `(bool $ok, string $name, $ability)` — include/exclude a WP ability.
- `heera_agent_discovery_schema_url` `(string $url)` — the `$schema` value; return `''` to omit it.
- `heera_agent_discovery_well_known_nested` `(array $names)` — extra exact-match nested `/.well-known/…` paths.

**Signing / Web Bot Auth**
- `heera_agent_discovery_signed_surfaces` `(array $docs)` — which discovery docs are signed (default the four core docs).
- `heera_agent_discovery_signing_secret_key` `(string '')` — supply the Ed25519 secret key from a constant/env instead of the DB.

**Crawlers & activity log**
- `heera_agent_discovery_known_trainers` `(array $uas)` — AI-trainer user-agents offered for blocking.
- `heera_agent_discovery_agent_map` `(array $map)` — user-agent → friendly label for the activity log.
- `heera_agent_discovery_activity_retention_days` `(int $days)` — how long agent hits are kept.

**Schema (JSON-LD)**
- `heera_agent_discovery_schema_for_post` `(array $node, WP_Post $post)` — replace a post's node (e.g. a `Product`).
- `heera_agent_discovery_schema_graph` `(array $graph)` — the whole `@graph`.

**Security.txt**
- `heera_agent_discovery_security_txt` `(string $body)` — the `/.well-known/security.txt` body.

**Cache / CDN**
- `heera_agent_discovery_cache_flushed` *(action)* — fires after Heera Discovery clears its caches; hook it to purge your CDN / page cache.

**Settings & lifecycle**
- `heera_agent_discovery_default_settings` / `heera_agent_discovery_settings` — the default and live settings arrays.
- `heera_agent_discovery_readiness_checks` `(array $checks, Settings $settings)` — add or adjust readiness checks.
- `heera_agent_discovery_booted` *(action)* `($plugin)` — after the plugin boots.

```php
// Add schema.org entity types (Person, Organization, LocalBusiness, Store ship by default).
add_filter( 'heera_agent_discovery_entity_types', function ( $types ) {
    $types[] = 'Restaurant';
    return $types;
} );

// Purge your CDN whenever Heera Discovery regenerates its documents.
add_action( 'heera_agent_discovery_cache_flushed', function () {
    my_cdn_purge( array( '/llms.txt', '/llms-full.txt', '/.well-known/discovery.json' ) );
} );
```

## Development

```bash
npm install
npm run build      # one-off build into assets/admin/
npm run dev        # rebuild on change
```

`assets/admin/` is git-ignored — it's a build artifact. Ship it in the
distributed `.zip` (the `.org` SVN tag), not the repo.

## Requirements

WordPress 6.9+, PHP 7.4+.
