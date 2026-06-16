# Agentify

[![PHP compatibility](https://github.com/heera/agentify/actions/workflows/php-compat.yml/badge.svg?branch=main)](https://github.com/heera/agentify/actions/workflows/php-compat.yml)

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
| Crawler policy | `robots.txt` content-signal + training-crawler blocklist |
| Readiness report | Admin screen, pass/warn/fail checks |

## Architecture

- **PHP** (`inc/`, namespace `Agentify\`, PSR-4 autoloaded) — vanilla, no framework.
  - `Plugin` orchestrates; `Settings` is the single option store; `Cache` handles
    transients; `Endpoints` / `Markdown` / `Schema` produce output; `Readiness`
    runs the checks; `Rest` backs the admin; `Admin` mounts the UI.
- **Admin UI** — Vue 3 (Options API), built with Vite into `assets/admin/`.
  Talks to the REST namespace `agentify/v1` with the standard WP nonce.

### Extending to any site / content type

The free core covers `post` + `page` and **any public post type you opt in**
(Content types card, or the `agentify_post_types` filter), so products and
CPTs flow into llms.txt, the full-text edition, markdown and schema automatically.
Deeper coverage (a WooCommerce `Product` mapper, page-builder content) is an
add-on that hooks these seams:

- `agentify_post_types` — add/remove agent-visible post types
- `agentify_schema_type_map` — post_type → schema @type (light)
- `agentify_schema_for_post` — return a full node, e.g. `Product` with offers (heavy)
- `agentify_markdown_source` — supply rendered HTML for page-builder content

### Other extension seams

- `agentify_booted` action (passes the `Plugin` instance)
- `agentify_settings` / `agentify_default_settings` / `agentify_sanitize_settings`
- `agentify_schema_graph`, `agentify_defer_schema`
- `agentify_readiness_checks`
- `agentify_topic_exclude`
- `agentify_post_type_source` — source label for a post type in the admin

## For developers — make your plugin discoverable

Agentify exposes a single aggregated discovery layer at
`/.well-known/discovery.json` (plus `agent-card.json` and `mcp.json`). Any plugin
can register itself with **one action and no dependency** — if Agentify is not
installed, the hook never fires, so the code is inert:

```php
add_action( 'agentify_discovery_register', function ( $registry ) {
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
`if ( class_exists( 'Agentify_Discovery' ) ) { Agentify_Discovery::register( [...] ); }`.

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

Other discovery seams:

- `$registry->add_well_known( [...] )` — serve a `/.well-known/<name>` doc (callback | redirect | file)
- `agentify_discovery_envelope` — filter the assembled discovery document
- `agentify_discovery_mcp` — filter the advertised MCP descriptor
- `agentify_woocommerce_capabilities`, `agentify_fluent_cart_capabilities`,
  `agentify_discoverable_ability` — tune the built-in adapters

See **`examples/integrate-your-plugin.php`** for a full, copy-paste reference, and
the **WP_Discovery Protocol** spec for the standard itself.

## Development

```bash
npm install
npm run build      # one-off build into assets/admin/
npm run dev        # rebuild on change
```

`assets/admin/` is git-ignored — it's a build artifact. Ship it in the
distributed `.zip` (the `.org` SVN tag), not the repo.

## Requirements

WordPress 6.3+, PHP 7.4+.
