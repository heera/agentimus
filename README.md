# Agentimus

[![PHP compatibility](https://github.com/heera/agentimus/actions/workflows/php-compat.yml/badge.svg?branch=main)](https://github.com/heera/agentimus/actions/workflows/php-compat.yml)
[![WordPress plugin version](https://img.shields.io/wordpress/plugin/v/agentimus?label=wordpress.org)](https://wordpress.org/plugins/agentimus/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/agentimus)](https://wordpress.org/plugins/agentimus/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

Two things for the age of AI agents. **(1)** Make any WordPress site legible and citable —
`llms.txt`, a full-text edition, markdown delivery, JSON-LD, content-signal robots rules —
then score how AEO/GEO-ready it is and what to fix next. **(2)** Let the AI tools you already
use **operate the site over MCP**: a Model Context Protocol server ships inside the plugin, so
Claude Code, Claude Desktop, Cursor or Codex can read your readiness/traffic/bot data and —
behind two more opt-in switches — draft, edit and publish posts and pages, each write running
as the signed-in user, permission-checked and audited. Lightweight, no SEO bloat, no framework.

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
| AI description | Per-page one-line summary → JSON-LD `description` + the `/<slug>.md` lead + an authoritative `<meta name="description">` (replaces the theme's, defers to an SEO plugin); editor-set, falling back to the excerpt or a ~30-word content summary. A settings sub-toggle can limit it to the JSON-LD + `.md` surfaces only |
| Writing assistant (opt-in) | The quill button on every Agentimus screen opens a drawer: brief → editable outline → the article writes **every section in parallel** (`/assistant/compose-section` per part + one dressing call; long posts aren't capped by a single response, a failed part retries alone, and a mid-write reload resumes) → full preview (title, real blocks, AI description, topics, suggested categories/tags, draft/pending choice) → **Create draft** lands straight in the editor. Drafts are written to the same bar the **AI Readability** panel grades — a liftable opening summary, quotable paragraph lengths, plain sentences, cited sources (`readability_rules()`, its paragraph cap derived from `PageCheck`'s own threshold). Edits existing posts too — content only, status never changes, WordPress revisions as the net; unusual block layouts are declined by a safe-block gate rather than mangled. Images arrive as alt-filled placeholder blocks: **Generate image from the alt text** on every image block, a **Featured image (AI)** sidebar panel, and per-block **Ask AI** (rewrite / split / insert, native undo). Server-side it's the same audited write path as MCP (`Assistant.php` → `ContentWriter`), gated by `enable_agent_writes` + the user's own caps, with every generation routed through `Assist::generate()` (rate-limited, Content-Guidelines-aware) and status clamped to `draft\|pending` — the assistant can never publish |
| Write with AI (opt-in) | Editor **"Draft with AI"** writes the AI description and **"Suggest with AI"** fills Topics, both from the page's own content; **"Fix with AI"** drafts a fix per AI-Readability warning (opening summary inserts in one click, the rest copy-only). Routes through the WordPress 7.0 AI Client — your own provider key, never handled by the plugin; hidden until a provider is configured under Settings → Connectors |
| AI abilities | Two tiers via `wp_register_ability`, each ability permission-gated like its source screen. **Read (always registered):** `agentimus/read-readiness`, `read-ai-visibility`, `read-ai-traffic`, `read-request-log`, `identify-bot`, `check-page`, `preview-schema`/`-markdown`, `scan-exposed-files`. **Write (registered only while `enable_agent_writes` is on — off by default, requires the MCP server switch, and off means the abilities don't exist on any surface):** `create-content`/`update-content` (draft-first; categories & tags by name with wp-admin's own creation rules, featured image by attachment ID or sideloaded URL, AI description/topics in the same call), `write-description`, `write-topics`, and `apply-fix` (enacts a readiness check's own remediation from a closed vocabulary that can only enable documented features). `status=publish` needs a third switch (`agent_writes_publish`) plus the user's own publish cap |
| MCP server (opt-in) | `/wp-json/agentimus/v1/mcp` — the WordPress MCP Adapter ships **inside the plugin** (Composer-vendored, Jetpack-autoloader arbitrated) behind the `enable_mcp_server` switch (Settings → Discovery, off by default; WP 6.9+). External MCP clients (Claude Code, Claude Desktop, Codex) authenticate with an application password and get exactly the abilities above — nine read-only tools, plus the five write tools only while the write switch is on (the server's self-description says which tier it's running) — the adapter's generic execute-any-ability default server stays suppressed, every call is audited in Agent Access, and toggling off hard-disconnects live clients. With the switch off, the library is never loaded and no endpoint exists |
| XML sitemap | `/agentimus-sitemap.xml` — opt-in fallback, generated **only** when neither WordPress core nor an SEO plugin already provides one (sitemap index + paginated sub-sitemaps) |
| Crawler policy | `robots.txt` content-signal + training-crawler blocklist |
| Discovery layer | `/.well-known/discovery.json` (+ `agent-card.json`, `mcp.json`) |
| Change feed | `/agentimus-changes.json?since=` — recently added, updated and removed content as JSON, so an agent fetches only the delta (advertised in `discovery.json`; on by default) |
| Crawl enforcement (opt-in) | hard-block (403) denylisted, spoofed-"scanner", or proven-impostor user-agents at the generated endpoints — ACME-safe, off by default; optional bot-identity verification (forward-confirmed reverse DNS + operators' published IP ranges, via an owner-editable Verified-bots registry) so a spoofed bot name can't earn the allowlist and a proven fake is refused |

## In the admin

- **AEO/GEO score** — one 0–100 score on the dashboard across five rungs (Findable / Readable / Trusted / Optimized / Cited), with the single most useful next step; each rung deep-links to where you act on it. Cited is opt-in (see AI Visibility) and its weight redistributes across the others when off.
- **Optimize your content** — per-page citability checks (thin content, something concrete to quote, quotable passages, freshness) over your **articles**, with a worklist of which pages to improve and a matching read-only panel in the editor. Commerce products, commerce plugins' designated pages (cart/checkout/account — WooCommerce options and FluentCart settings both read), and shortcode/block container pages with no authored prose are excluded; **set aside** any page not meant to be cited (it stays published, just leaves the score); mark categories **evergreen** to exempt timeless content from the freshness check.
- **Readiness report** — pass/warn/fail checks, each with a plain-English suggestion and a deep link to the fix (including a "sitemap advertised in robots.txt" check).
- **Agent preview** — a modal (from Readiness) showing the exact **JSON-LD** and **Markdown** an agent receives for the site or any page/post, with a grouped site/page/post picker, copy, and validator links. It renders what *would* ship even when schema is disabled or an SEO plugin owns it; password-protected posts stay hidden, and an unpublished draft is shown as a preview of what it will emit once published. A read-only twin also sits in the post editor.
- **AI Visibility** (opt-in, bring-your-own-key — the **Track AI citations** switch in Settings; adds the **Cited** rung to your score, and a reading expires after 90 days so a stale one never counts) — track each brand, product or person you choose across ChatGPT, Perplexity, Gemini and Claude. For every one it asks the questions your audience types and reports whether it's **mentioned, linked, and how it ranks against its own rivals**, over time. Give an item a **category** (*"WordPress SEO plugin"*) and Agentimus suggests the questions a buyer actually types — or, where an AI provider is configured in WordPress, asks that AI for a spread of unbranded buyer questions (`POST /visibility/suggest` and `/visibility/suggest-ai`). Each item has its own website, category, competitors, questions and scoreboard; pause any single one or the whole schedule, and every setting on the screen saves itself. Results are stored locally; it's the only feature that makes outbound calls — using API keys you provide (stored **encrypted at rest**), off by default. It keeps its own keys rather than reusing WordPress's shared AI connectors, because a visibility check is graded on the sources an engine **cited** and the shared client drops them.
- **Agent activity log** — a local-only dashboard (no IP logged by default; an optional setting stores IPs for flagged crawlers only) of which AI agents and crawlers fetch your endpoints; repeat hits are grouped with a count, newest first. Click any day's bar for that day's full report — clients, endpoints, and every request. Your own logged-in visits are skipped, and so are the readiness screen's own Verify-live/exposure-scan fetches (they carry a short-lived `X-Agentimus-Selfcheck` token, since they're deliberately anonymous).
- **Request Log** (*More → Request Log*) — the raw log behind that dashboard: one row per request, keyset-paged, filterable by client, endpoint, network, user-agent and date range. The filters AND together, giving the agent × endpoint cross-tab the summary rollups structurally cannot show.
- **Agent Access** (*More → Agent Access*) — the other side of the log: who *authenticates to and acts on* the machine surface Agentimus creates. Records application passwords being created/first-used/renamed/revoked, WordPress abilities being run, and requests that were refused or probed for abilities that don't exist — and every row says **who**: the user and the named application password behind it, resolved live (a renamed key shows its current label, a revoked one reads "since revoked"). A record, not a guard (it never blocks); no IP logged, so it names the credential, not the person. Rides `rest_request_after_callbacks` and the app-password hooks; the credential half needs no Abilities API, the ability half auto-detects it.
- **Traffic from AI** — a private, first-party count of which AI assistants send *visitors* to you (per-day tallies of assistant → landing page; no IP, no per-visitor data). The dashboard shows a summary — click any day's bar for that day's assistant → landing-page report; *More → AI Traffic* is the full report, with the same per-day drill-down. An opt-in **CDN mode** counts these in the browser so the numbers survive a full-page cache. Because recognition is a finite list, an opt-in **Find missed AI sources** diagnostic records the referrer hosts and `utm_source` tags it *couldn't* attribute — the only way to see a blind spot that otherwise leaves no trace.
- **Visit-log retention** (*Settings → Visit log*) — a retention period, nightly auto-delete, and a hard row cap. The cap outranks the retention: "keep for 90 days" is a ceiling on age, not a promise a row survives 90 days. Turning auto-delete off does not mean "keep forever" — the cap still collects, oldest first.
- **Keep AI endpoints out of your cache** (*Settings → Caching & CDN*, opt-in) — if a cache or CDN in front of your site serves stored copies of the AI endpoints (`llms.txt`, the `.well-known` docs, the change feed), those agent fetches never reach WordPress: the log under-counts them and the change feed can go stale. Turn this on and the endpoints send `Cache-Control: no-store`, so any cache that respects it lets each fetch through. The readiness report links to it when it detects a cache in front of you.
- **Refresh AI files when content changes** (*Settings → Caching & CDN*, on by default) — on every content change, asks each detected page cache (WP Rocket, Nginx Helper, W3 Total Cache, LiteSpeed, WP Super Cache, Cache Enabler, or any `agentimus_purge_url` listener) to drop the AI files — `llms.txt`, `llms-full.txt`, `robots.txt`, the change feed, the `.well-known` docs — plus the edited post's own `.md` twin, which a cache plugin otherwise never knows to refresh. Feature-detected, so it's a no-op with no cache present.
- **Activity to review** — a nav-bar review queue flags new, unusually high-volume, spoofed/scanner, or **impersonating** clients (one that claims a bot from the editable Verified-bots registry — Googlebot, GPTBot, PerplexityBot, … — but conclusively fails that operator's published check: reverse DNS or its published IP ranges), each with a plain "Check this bot" panel and one-click **Block** / **Allow** / **Ignore**. Pairs with the opt-in *Block scanners & scrapers* enforcement; an optional *Store IP addresses for flagged clients* setting shows the exact address to block. Every standing decision — blocked, trusted, ignored — is managed later under *Settings → AI access → Manage clients*: identity, decision date, and one-click undo per row.
- **Exposure** — opt-in controls that limit what anonymous crawlers can read (username enumeration, author archives, the WP version, `<head>` links, XML-RPC), plus a one-click **exposed-files scan** that checks whether risky files (backups, `.env`, `debug.log`, keys) are publicly downloadable and tells you how to block them. Guidance, not a firewall.
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
| `agentimus_mcp_server_abilities` | filter | `( string[] $names ): string[]` | The abilities Agentimus exposes over its own MCP server to external agents (default: its nine read-only abilities). Trim to narrow what leaves the site. |
| `agentimus_bootstrap_mcp_adapter` | filter | `( bool $bootstrap ): bool` | Veto loading the bundled MCP Adapter library entirely (e.g. a host that manages the adapter itself), independent of the owner's `enable_mcp_server` setting. |
| `agentimus_agent_skills` | filter | `( array $skills, array $resources ): array` | Entries in the Agent Skills index. |
| `agentimus_post_types` | filter | `( string[] $types, string[] $available ): string[]` | Which post types are agent-visible (each gets an llms.txt section). |
| `agentimus_post_type_source` | filter | `( string $source, string $post_type ): string` | Attribute a post type's llms.txt section (and the Agent preview picker) to your plugin. |
| `agentimus_changes_max` | filter | `( int $max ): int` | Size of the change-feed window — newest items it can hold (default 200, max 2000). |
| `agentimus_tombstone_retain_days` | filter | `( int $days ): int` | How long a deletion stays in the change feed before it's pruned (default 90). |
| `agentimus_page_checks` | filter | `( array $checks, array $stats, WP_Post $post ): array` | Add, retune or drop the per-page "AI Readability" checks shown in the editor. |
| `agentimus_ai_assist_enabled` | filter | `( bool $enabled ): bool` | Whether the editor "Draft with AI" / "Fix with AI" assist is offered (default: on when a text-capable AI provider is configured in WordPress). |
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
| `agentimus_post_description` | filter | `( string $desc, WP_Post $post ): string` | The last word on a post's AI description (editor value → excerpt/summary fallback). Feeds the JSON-LD `description`, the `.md` lead and the meta tag; re-cleaned (tags stripped, capped) after. |
| `agentimus_emit_meta_description` | filter | `( bool $emit, WP_Post $post ): bool` | Whether Agentimus manages the page `<meta name="description">` on this request. Return `false` to leave the `<head>` to your theme. (Already stands down for a dedicated SEO plugin.) |
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
| `agentimus_exposed_paths` | filter | `( string[] $paths, Settings $settings ): string[]` | The sensitive-path list the exposed-files scan probes for. |
| `agentimus_verified_bot_domains` | filter | `( array $map ): array` | The verifiable-bot rDNS map (registry token → reverse-DNS domain suffixes), sourced from the owner-editable registry. |
| `agentimus_verifier_registry` | filter | `( array $entries ): array` | The effective Verified-bots registry after the owner's edits — the source for both verification methods (rDNS domains and published IP-range files). |
| `agentimus_trusted_proxies` | filter | `( string[] $cidrs ): string[]` | Trusted proxy/CDN CIDRs for real-client-IP resolution. A forwarded header is only honoured when the direct peer is a proven proxy — it can't be used to spoof a source IP. |
| `agentimus_referral_beacon` | filter | `( bool $enabled ): bool` | Master switch for the opt-in "CDN mode" referral beacon (count AI visits in the browser). |
| `agentimus_markdown_cache` | filter | `( bool $cache ): bool` | Enable or disable the per-post `.md` cache (on by default). |
| `agentimus_flagged_ip_retention_days` | filter | `( int $days ): int` | Retention window for the opt-in flagged-IP store (default ~14). |

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
| `agentimus_verify_bots` | filter | `( bool $on ): bool` | Force bot-identity verification (reverse DNS + published IP ranges) on/off, overriding the setting. |
| `agentimus_reverse_dns` | filter | `( ?string $host, string $ip ): ?string` | Override the PTR lookup in the reverse-DNS check — return a hostname (`''` for none), or `null` to use the native resolver. |
| `agentimus_forward_dns` | filter | `( ?array $ips, string $host ): ?array` | Override the forward A/AAAA lookup — return an array of IP strings, or `null` to use the native resolver. |
| `agentimus_engine_signatures` | filter | `( array $signatures ): array` | Structured signatures that match real crawlers at a token boundary. |
| `agentimus_generic_ua_tokens` | filter | `( string[] $tokens ): string[]` | Generic user-agent tokens treated as low-signal. |
| `agentimus_agent_map` | filter | `( array $map ): array` | User-agent → friendly label for the activity log. |
| `agentimus_spoof_signatures` | filter | `( string[] $signatures ): string[]` | Platform markers that flag a spoofed/legacy-device scanner. |
| `agentimus_known_agents` | filter | `( array $catalog ): array` | Known-agent catalog (user-agent → label). |
| `agentimus_known_scanners` | filter | `( string[] $known ): string[]` | Scanner user-agents offered as one-click block suggestions. |
| `agentimus_known_trainers` | filter | `( string[] $known ): string[]` | AI-trainer user-agents offered for robots.txt blocking. |
| `agentimus_ai_referral_sources` | filter | `( array $map ): array` | Needle → friendly name for "Traffic from AI". A needle containing a dot matches the referrer host (and any subdomain of it) as well as a `utm_source` carrying that host; a dotless needle can never equal a host, so it matches `utm_source` only — which is how an assistant that tags `utm_source=perplexity` gets counted. |
| `agentimus_unknown_sources_max_rows` | filter | `( int $max ): int` | Row cap on the opt-in "Find missed AI sources" table. `0` disables the cap. |
| `agentimus_activity_skip_self` | filter | `( bool $skip ): bool` | Whether to skip recording hits from logged-in admins. Note this is evaluated from the logged-in cookie, not the current user, so it holds on REST routes that carry no nonce. |
| `agentimus_activity_retention_days` | filter | `( int $days ): int` | How long agent hits are kept. Overrides the Settings → Visit log value. The Dashboard still reports on at most 30 days. Raise `agentimus_activity_max_rows` alongside it, or the row cap discards the extra days anyway. |
| `agentimus_bypass_shared_cache` | filter | `( bool $on ): bool` | Force the AI endpoints uncacheable (send `Cache-Control: no-store`) so a shared cache/CDN can't hide agent fetches from the activity log. Overrides the *Settings → Caching & CDN* switch. |
| `agentimus_purge_on_change` | filter | `( bool $on ): bool` | Turn the automatic cache purge of the AI files on a content change on/off. Overrides the *Settings → Caching & CDN* switch. |
| `agentimus_purge_urls` | filter | `( string[] $urls ): string[]` | The exact URLs Agentimus asks the page cache to drop on a content change (add your own, or trim the set). |
| `agentimus_purge_url` | action | `( string $url )` | Fires per URL when purging — hook it to support a page cache Agentimus doesn't detect natively. (`agentimus_purged` fires once with the whole list.) |
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
