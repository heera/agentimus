# Agentimus

[![PHP compatibility](https://github.com/heera/agentimus/actions/workflows/php-compat.yml/badge.svg?branch=main)](https://github.com/heera/agentimus/actions/workflows/php-compat.yml)
[![WordPress plugin version](https://img.shields.io/wordpress/plugin/v/agentimus?label=wordpress.org)](https://wordpress.org/plugins/agentimus/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/agentimus)](https://wordpress.org/plugins/agentimus/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

Make any WordPress site legible to AI agents and crawlers — `llms.txt`, a full-text
edition, markdown delivery, JSON-LD, and content-signal robots rules. Lightweight,
no SEO bloat, no framework.

**Live on WordPress.org:** <https://wordpress.org/plugins/agentimus/>

**📖 Documentation:** <https://heera.github.io/agentimus/> — full user manual and developer reference.

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
| Topics for AI | Per-page topics → JSON-LD `keywords` + `about` DefinedTerm entities (with optional Wikidata `sameAs` via the `agentimus_topic_links` filter) + a `Topics:` line in `/<slug>.md`; static (editor) or derived from tags & categories |
| XML sitemap | `/agentimus-sitemap.xml` — opt-in fallback, generated **only** when neither WordPress core nor an SEO plugin already provides one (sitemap index + paginated sub-sitemaps) |
| Crawler policy | `robots.txt` content-signal + training-crawler blocklist |
| Discovery layer | `/.well-known/discovery.json` (+ `agent-card.json`, `mcp.json`) |
| Crawl enforcement (opt-in) | hard-block (403) denylisted or spoofed "scanner" user-agents at the generated endpoints — ACME-safe, off by default |

## In the admin

- **Readiness report** — pass/warn/fail checks, each with a plain-English suggestion and a deep link to the fix (including a "sitemap advertised in robots.txt" check).
- **Agent preview** — a modal (from Readiness) showing the exact **JSON-LD** and **Markdown** an agent receives for the site or any page/post, with a grouped site/page/post picker, copy, and validator links. It renders what *would* ship even when schema is disabled or an SEO plugin owns it; password-protected posts stay hidden, and an unpublished draft is shown as a preview of what it will emit once published. A read-only twin also sits in the post editor.
- **AI Visibility** (opt-in, bring-your-own-key) — track each brand, product or person you choose across ChatGPT, Perplexity, Gemini and Claude. For every one it asks the questions your audience types and reports whether it's **mentioned, linked, and how it ranks against its own rivals**, over time. Each item has its own website, competitors, questions and scoreboard; pause any single one or the whole schedule. Results are stored locally; it's the only feature that makes outbound calls — using API keys you provide, off by default.
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

Every hook is optional and falls into one of three tiers. The tables give each hook's **type** (action or filter) and **callback signature** (parameters → return); copy-paste examples are in [`examples/all-hooks-reference.php`](examples/all-hooks-reference.php). In signatures, `Registry`, `Settings` and `Plugin` are the `Agentimus\Discovery\Registry`, `Agentimus\Settings` and `Agentimus\Plugin` classes.

### Stable

Public and frozen at WP_Discovery spec 1.0 — safe to build on.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `wpdiscovery_register` | action | `( Registry $registry )` | Register your resources and serve your own `/.well-known` documents. See [`integrate-your-plugin.php`](examples/integrate-your-plugin.php) for the full schema. |
| `agentimus_entity_types` | filter | `( string[] $types ): string[]` | Add selectable schema.org entity types to Settings → Identity. |
| `agentimus_cache_flushed` | action | `()` | Runs after Agentimus regenerates its documents — purge your CDN / page cache. |
| `agentimus_booted` | action | `( Plugin $plugin )` | Runs after the plugin boots — a companion or Pro add-on registers its features here. |

```php
// Add selectable schema.org entity types to Settings → Identity.
add_filter( 'agentimus_entity_types', function ( $types ) {
    $types[] = 'Restaurant';
    return $types;
} );
```

### Extension

Supported output-shaping filters; signatures may evolve between releases.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_envelope` | filter | `( array $envelope, Registry $registry ): array` | The whole assembled `discovery.json` — add `x-<vendor>` extension keys. |
| `agentimus_documents` | filter | `( array $docs, Registry $registry ): array` | Add a standard document Agentimus can't auto-detect to the `documents` map. |
| `agentimus_schema_url` | filter | `( string $url ): string` | The `$schema` URL of the discovery document; return `''` to omit it. |
| `agentimus_well_known_routed` | filter | `( string[] $names ): string[]` | Route a flat `/.well-known/<name>` you serve so it resolves on every host. |
| `agentimus_well_known_nested` | filter | `( string[] $names ): string[]` | Route an exact-match nested `/.well-known/<dir>/<file>`. |
| `agentimus_well_known_specs` | filter | `( array $specs ): array` | Label a `/.well-known` name with the standard that governs it (`name => label`). |
| `agentimus_signed_surfaces` | filter | `( string[] $surfaces ): string[]` | Which discovery documents your companion signer signs. |
| `agentimus_mcp` | filter | `( array $mcp, array $resources ): array` | The advertised MCP descriptor at `/.well-known/mcp.json`. |
| `agentimus_mcp_card_server` | filter | `( string $id, array $servers ): string` | Pin which server the MCP server card describes (`''` = auto). |
| `agentimus_agent_skills` | filter | `( array $skills, array $resources ): array` | Entries in the Agent Skills index. |
| `agentimus_post_types` | filter | `( string[] $types, string[] $available ): string[]` | Which post types are agent-visible (each gets an llms.txt section). |
| `agentimus_post_type_source` | filter | `( string $source, string $post_type ): string` | Attribute a post type's llms.txt section to your plugin. |
| `agentimus_markdown_source` | filter | `( ?string $html, WP_Post $post ): ?string` | Supply rendered HTML for page-builder content (`null` = render normally). |
| `agentimus_topic_exclude` | filter | `( string[] $slugs ): string[]` | Topic/category slugs to omit from the llms.txt Topics list and per-page derived topics. |
| `agentimus_derive_taxonomies` | filter | `( string[] $taxonomies, WP_Post $post ): string[]` | Which taxonomies auto-fill a post's Topics for AI (default `category`, `post_tag`). A vendor adds e.g. `product_cat`; terms flow through the derive toggle, exclude list and cap. |
| `agentimus_post_topics` | filter | `( string[] $topics, WP_Post $post ): string[]` | Add or refine a post's Topics-for-AI list (→ JSON-LD `keywords` + Markdown). Re-normalised after (deduped, capped). |
| `agentimus_topic_links` | filter | `( string[] $urls, string $topic, WP_Post $post ): string[]` | Reference URLs (Wikidata, Wikipedia…) for a topic → schema.org `about` `sameAs`. Core supplies none (no automatic lookups); you map them. Drop-in example: [`topic-links-wikidata.php`](examples/topic-links-wikidata.php). |
| `agentimus_topic_suggestions` | filter | `( string[] $pool ): string[]` | The autocomplete pool in the editor's Topics-for-AI box (default: used topics + tags/categories + Expertise). |
| `agentimus_topic_meaningful` | filter | `( bool $meaningful, string $name, ?object $term ): bool` | Whether an auto-derived taxonomy term becomes a topic. Default drops purely-numeric names ("67", stray IDs); return `true` to keep a number that really is the subject (e.g. "1984"). |
| `agentimus_llms_full_item_max_bytes` | filter | `( int $bytes ): int` | Per-item byte cap for the llms-full.txt edition. |
| `agentimus_llms_full_avg_item_bytes` | filter | `( int $bytes ): int` | Average item size used to estimate llms-full.txt in the admin. |
| `agentimus_yield_surface` | filter | `( bool $yield, string $surface ): bool` | Cede a surface (`llms_txt`, `robots`, …) to your own producer. |
| `agentimus_defer_schema` | filter | `( bool $active ): bool` | Whether to emit the front-end JSON-LD (stand down for an SEO plugin). |
| `agentimus_schema_for_post` | filter | `( array $node, WP_Post $post ): array` | Replace a post's JSON-LD node (e.g. a `Product`). |
| `agentimus_schema_graph` | filter | `( array $graph ): array` | Last-chance edit of the entire JSON-LD `@graph`. |
| `agentimus_faq_pairs` | filter | `( array $pairs, WP_Post $post ): array` | Contribute extra FAQPage question/answer pairs. |
| `agentimus_sitemap` | filter | `( array $sitemap ): array` | Declare a sitemap Agentimus can't auto-detect. |
| `agentimus_sitemap_max_urls` | filter | `( int $max ): int` | Cap the number of URLs in the generated sitemap. |
| `agentimus_rest_discovery` | filter | `( bool $enabled ): bool` | Master switch for REST namespace auto-discovery. |
| `agentimus_rest_namespaces` | filter | `( string[] $namespaces ): string[]` | REST namespaces to publish in the discovery document. |
| `agentimus_rest_skip_namespaces` | filter | `( string[] $namespaces ): string[]` | REST namespaces to exclude from discovery. |
| `agentimus_discoverable_ability` | filter | `( bool $discoverable, string $name, mixed $ability ): bool` | Include or exclude a single WP ability. |
| `agentimus_serve_security_txt` | filter | `( bool $serve ): bool` | Whether Agentimus generates a `security.txt`. |
| `agentimus_security_txt` | filter | `( string $body ): string` | Edit the final `security.txt` body. |
| `agentimus_security_txt_expires_days` | filter | `( int $days ): int` | The `security.txt` `Expires` window, in days. |
| `agentimus_readiness_checks` | filter | `( array $checks, Settings $settings ): array` | Add or adjust the admin Discovery Hub readiness checks. |
| `agentimus_signing_secret_key` | filter | `( string $key ): string` | Supply the Ed25519 signing key from a constant or vault. |

```php
// Add a vendor extension to the discovery document (the x- namespace is yours).
add_filter( 'agentimus_envelope', function ( $envelope, $registry ) {
    $envelope['x-acme'] = array( 'portal' => 'https://acme.example' );
    return $envelope;
}, 10, 2 );
```

### Internal

Advanced site-owner tuning — not a third-party integration surface.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_deny_request` | filter | `( bool $deny, string $ua ): bool` | The Guard's final say on whether to 403 a request. |
| `agentimus_block_allowlist` | filter | `( string[] $allowed ): string[]` | Clients that must never be hard-blocked (search engines + your list). |
| `agentimus_engine_signatures` | filter | `( array $signatures ): array` | Structured signatures that match real crawlers at a token boundary. |
| `agentimus_generic_ua_tokens` | filter | `( string[] $tokens ): string[]` | Generic user-agent tokens treated as low-signal. |
| `agentimus_agent_map` | filter | `( array $map ): array` | User-agent → friendly label for the activity log. |
| `agentimus_spoof_signatures` | filter | `( string[] $signatures ): string[]` | Platform markers that flag a spoofed/legacy-device scanner. |
| `agentimus_known_agents` | filter | `( array $catalog ): array` | Known-agent catalog (user-agent → label). |
| `agentimus_known_scanners` | filter | `( string[] $known ): string[]` | Scanner user-agents offered as one-click block suggestions. |
| `agentimus_known_trainers` | filter | `( string[] $known ): string[]` | AI-trainer user-agents offered for robots.txt blocking. |
| `agentimus_ai_referral_sources` | filter | `( array $map ): array` | Referrer host → friendly name for "Traffic from AI". |
| `agentimus_activity_skip_self` | filter | `( bool $skip ): bool` | Whether to skip recording hits from logged-in admins. |
| `agentimus_activity_retention_days` | filter | `( int $days ): int` | How long agent hits are retained. |
| `agentimus_new_agent_seconds` | filter | `( int $seconds ): int` | The "new agent" window for the activity-to-review panel. |
| `agentimus_burst_min_hits` | filter | `( int $hits ): int` | Minimum hits to flag a burst. |
| `agentimus_heavy_min_hits` | filter | `( int $hits ): int` | Minimum hits to flag heavy usage. |
| `agentimus_threats_limit` | filter | `( int $limit ): int` | Maximum rows in the "activity to review" panel. |
| `agentimus_default_settings` | filter | `( array $defaults ): array` | The default settings array. |
| `agentimus_settings` | filter | `( array $settings ): array` | The live, merged settings array at read time. |
| `agentimus_sanitize_settings` | filter | `( array $clean, array $input ): array` | Validate/coerce companion-added fields on save. |
| `agentimus_settings_reset` | action | `()` | Runs when the owner resets settings. |

```php
// The Guard's final say on whether to 403 a request.
add_filter( 'agentimus_deny_request', function ( $deny, $ua ) {
    return $deny;
}, 10, 2 );
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

- WordPress 6.9+ (tested up to 7.0)
- PHP 7.4+.

## License

[GPL-2.0-or-later](LICENSE). The admin app is built from Vue source in `resources/` with Vite — no minified-only code ships, so the build is reproducible.
