---
title: Hooks & filters
parent: Developer Reference
nav_order: 3
---

Agentimus exposes its behaviour through WordPress actions and filters. Everything on this page is optional — a stock install works with none of them registered. Add a hook only when you need to register a plugin, shape a machine surface, or tune a default.

Every hook name, signature and default on this page is taken from the plugin source. Copy-paste examples for the most-used ones live in `examples/all-hooks-reference.php` in the plugin (documentation-only; it is not loaded at runtime). The registration schema used with `$registry->register()` and `$registry->add_well_known()` is documented separately in `examples/integrate-your-plugin.php`.

## Stability tiers

Hooks fall into three tiers, mirrored in `examples/all-hooks-reference.php`:

- **Stable** — the public integration API, frozen at WP_Discovery spec 1.0. Safe to build on.
- **Extension** — supported output-shaping filters. Useful for deeper integrations; signatures may evolve between releases, so test against the version you target.
- **Internal** — advanced site-owner and internal-tuning knobs. Not a third-party integration surface; listed for completeness.

**A hook that is not on this page is Internal**, whether or not it looks useful in
the source. It may change signature or disappear in any release, without notice
and without a changelog entry — the tiers above describe what is documented, not
everything that exists. If you need an extension point that is missing here, say
so and it will be added as a supported hook rather than left for you to discover;
that is a smaller cost to us than a broken integration is to you.

In the signatures below, `Registry`, `Settings` and `Plugin` are `Agentimus\Discovery\Registry`, `Agentimus\Settings` and `Agentimus\Plugin`. A `filter` must return a value of the same shape it receives; an `action` returns nothing.

## Discovery & output

### Registration

There is no `agentimus_resources` filter — you register resources imperatively on the `Registry` passed to the registration action, not by filtering an array.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `wpdiscovery_register` | action | `( Registry $registry )` | Register your resources and serve your own `/.well-known` documents. |
| `agentimus_register` | action | `( Registry $registry )` | Product-aliased copy of `wpdiscovery_register`. |

Both actions fire (the `Registry` dispatches `AGENTIMUS_CANONICAL_HOOK` then `AGENTIMUS_ALIAS_HOOK`). Hook **one** of them, not both, or your resources register twice. Inside the callback, call `$registry->register( [...] )` and, optionally, `$registry->add_well_known( [...] )`. The full resource schema (capabilities, endpoints, auth, agent cards, MCP tools) is in `examples/integrate-your-plugin.php`.

```php
add_action( 'wpdiscovery_register', function ( $registry ) {
    $registry->register( array(
        'id'    => 'acme',
        'title' => 'Acme',
        'type'  => 'commerce',
    ) );

    $registry->add_well_known( array(
        'name'     => 'acme.json',
        'callback' => fn() => wp_json_encode( array( 'ok' => true ) ),
    ) );
} );
```

### Discovery document (discovery.json)

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_envelope` | filter | `( array $envelope, Registry $registry ): array` | The whole assembled `discovery.json` envelope — add `x-<vendor>` extension keys. |
| `agentimus_schema_url` | filter | `( string $url ): string` | The `$schema` URL of the discovery document; return `''` to omit it. |
| `agentimus_documents` | filter | `( array $docs, Registry $registry ): array` | The `documents` map (`name => URL`) — add a standard document Agentimus can't auto-detect. |

The unprefixed key namespace in the envelope is reserved for the spec; put vendor extensions under an `x-<vendor>` key.

```php
add_filter( 'agentimus_documents', function ( $docs, $registry ) {
    $docs['acme_openapi'] = home_url( '/wp-json/acme/v1/openapi.json' );
    return $docs;
}, 10, 2 );
```

### .well-known routing & labelling

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_well_known_routed` | filter | `( string[] $names ): string[]` | Route a flat `/.well-known/<name>` you serve so it resolves on every host. |
| `agentimus_well_known_nested` | filter | `( string[] $names ): string[]` | Route an exact-match nested `/.well-known/<dir>/<file>`. |
| `agentimus_well_known_specs` | filter | `( array $specs ): array` | Label a `/.well-known` name with the standard that governs it (`name => label`). |
| `agentimus_signed_surfaces` | filter | `( string[] $surfaces ): string[]` | Which discovery documents a companion signer signs. Default: `discovery.json`, `agent-card.json`, `agent.json`, `mcp.json`. |

### MCP & Agent Skills

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_mcp` | filter | `( array $mcp, array $resources ): array` | Annotate the advertised MCP descriptor served at `/.well-known/mcp.json`. |
| `agentimus_mcp_card_server` | filter | `( string $id, array $servers ): string` | Pin which server the MCP server card describes (`''` = auto-pick the server with the most tools). |
| `agentimus_mcp_server_abilities` | filter | `( string[] $names ): string[]` | The abilities Agentimus exposes over its own scoped MCP server (`/wp-json/agentimus/v1/mcp`) to external agents. Default: its ten read-only `agentimus/read-*` (and per-post) abilities, plus the five write abilities while the write tier is on. Trim to narrow what leaves the site. |
| `agentimus_route_probe` | filter | `( array $summary ): array` | The cached result of the plugin's own self-check of `/llms.txt` and the home `<head>` — what an agent actually receives, used by the honest Readiness rows. Filter it to override a verdict (for example on a host where a loopback request can never reach the site). Note the constant's own warning: WordPress cron hook names and filter tags share one namespace, so never register a cron hook under this name. |
| `agentimus_bootstrap_mcp_adapter` | filter | `( bool $bootstrap ): bool` | Veto loading the **bundled** MCP Adapter library entirely (e.g. a host that manages the adapter itself), independent of the owner's `enable_mcp_server` setting. When Agentimus is the party that boots the adapter, it also disables the adapter's generic default server via `mcp_adapter_create_default_server`; re-enable it with a later-priority filter if you genuinely want the execute-any-ability endpoint. |
| `agentimus_agent_skills` | filter | `( array $skills, array $resources ): array` | Append entries to the Agent Skills index at `/.well-known/agent-skills/index.json`. |
| `agentimus_webmcp_tools` | filter | `( array $tools, Settings $settings ): array` | The WebMCP tools registered with in-browser agents. Each entry needs `name`, `description`, `inputSchema`, `endpoint`, `method`. Expose read-only tools only — `execute()` runs in the visitor's browser session. |

### Post content, llms.txt & yielding

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_post_types` | filter | `( string[] $types, string[] $available ): string[]` | Which post types are agent-visible — each gets its own section in llms.txt. |
| `agentimus_post_type_source` | filter | `( string $source, string $post_type ): string` | Attribute a post type's llms.txt section to your plugin (vendor label; `''` = none). |
| `agentimus_markdown_source` | filter | `( ?string $html, WP_Post $post ): ?string` | Supply rendered HTML for a post (e.g. page-builder content). Return `null` to let Agentimus render it normally. |
| `agentimus_markdown_cache` | filter | `( bool $on ): bool` | Turn the tiered per-post `.md` body cache (object cache → file → regenerate) on or off. Default `true`; return `false` to force fresh rendering. It is self-invalidating on any content/settings change, so you rarely need this. |
| `agentimus_llms_full_item_max_bytes` | filter | `( int $bytes ): int` | Per-item byte cap for the llms-full.txt full-text edition. Default is derived from the budget: `min(256KB, max(32KB, budget/4))`. |
| `agentimus_llms_full_avg_item_bytes` | filter | `( int $bytes ): int` | Average item size (default `4096`) used only to **estimate** the full-text edition size in the admin. |
| `agentimus_negotiate_markdown` | filter | `( bool $enabled ): bool` | Whether a **page URL** may answer with its markdown twin for a client that asks (`Accept: text/markdown`). **Default `false` since 1.21.2.** One URL with two possible bodies is only safe if every cache in front honours the `no-store` the markdown answer carries — a Cloudflare "Cache Everything" rule with an Edge TTL overrides `Cache-Control`, `CDN-Cache-Control` *and* the Cloudflare vendor header, and no mainstream CDN varies on `Vary: Accept`, so the markdown gets stored under the page's URL and served to readers. Re-enable where the caching is sound: `add_filter( 'agentimus_negotiate_markdown', '__return_true' )`. The `.md` twin (a distinct, cache-safe URL) works either way and stays advertised in the `Link` header, llms.txt and the discovery documents. |
| `agentimus_yield_surface` | filter | `( bool $yield, string $surface ): bool` | Cede a surface to your own producer so Agentimus stops emitting it. Surface keys: `llms_txt`, `llms_full`, `markdown`, `link_headers`, `robots`. |

```php
// Hand robots.txt output to your own plugin.
add_filter( 'agentimus_yield_surface', function ( $yield, $surface ) {
    return 'robots' === $surface ? true : $yield;
}, 10, 2 );
```

### Sitemap

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_sitemap` | filter | `( array $sitemap ): array` | The detected sitemap descriptor — declare one Agentimus can't auto-detect. Shape: `array( 'url' => string, 'source' => string, 'label' => string )`. |
| `agentimus_sitemap_max_urls` | filter | `( int $max ): int` | Cap the number of URLs in Agentimus's generated fallback sitemap (default `2000`). |

### Change feed

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_changes_max` | filter | `( int $max ): int` | Size of the change-feed window at `/agentimus-changes.json` — how many newest items it holds (default `200`, clamped to `2000`). |
| `agentimus_tombstone_retain_days` | filter | `( int $days ): int` | How long a deletion (an `action: "deleted"` item) stays in the change feed before it is pruned (default `90`). |

### REST auto-discovery

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_rest_discovery` | filter | `( bool $enabled ): bool` | Master switch for REST namespace auto-discovery. |
| `agentimus_rest_namespaces` | filter | `( string[] $namespaces ): string[]` | REST namespaces to publish in the discovery document. |
| `agentimus_rest_skip_namespaces` | filter | `( string[] $namespaces ): string[]` | REST namespaces to exclude from discovery. |
| `agentimus_discoverable_ability` | filter | `( bool $discoverable, string $name, mixed $ability ): bool` | Include or exclude a single WP ability from discovery. |

### robots.txt

Agentimus does **not** declare its own robots.txt filter. It appends AI-crawler rules to robots.txt through WordPress core's own `robots_txt` filter, and reconstructs the served file (mirroring `do_robots()`) via that same core filter when building the admin readiness preview. To hook robots.txt yourself, use WordPress core's `robots_txt` filter; to take the surface over entirely so Agentimus stops writing to it, use `agentimus_yield_surface` with the `robots` surface key (see above).

## Structured data & topics

### JSON-LD schema

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_defer_schema` | filter | `( bool $active ): bool` | Whether to emit the front-end JSON-LD. Return `false` to stand down for an SEO plugin. |
| `agentimus_schema_for_post` | filter | `( array $node, WP_Post $post ): array` | Replace a single post's JSON-LD node (e.g. a `Product` or `Service`). |
| `agentimus_schema_type_map` | filter | `( array $map ): array` | The post-type → schema `@type` map. Default: `array( 'post' => 'BlogPosting', 'page' => 'WebPage' )`; anything unmapped falls back to `Article`. |
| `agentimus_schema_graph` | filter | `( array $graph ): array` | Last-chance edit of the entire JSON-LD `@graph` before output. |
| `agentimus_faq_pairs` | filter | `( array $pairs, WP_Post $post ): array` | Contribute extra question/answer pairs to the `FAQPage` schema. |
| `agentimus_faq_max_bytes` | filter | `( int $bytes ): int` | Byte ceiling above which `FAQPage` extraction is skipped for a post — a very large (page-builder) body is block-rendered and DOM-parsed on every front-end view and is rarely a clean FAQ anyway. Default `256 KB`; `0` disables the ceiling. |

```php
add_filter( 'agentimus_schema_for_post', function ( $node, $post ) {
    if ( 'acme_product' === $post->post_type ) {
        $node['@type'] = 'Product';
    }
    return $node;
}, 10, 2 );
```

For a full node — offers, price ranges on variable products, SKU, GTIN/MPN,
brand and ratings — see the [worked WooCommerce example]({% link developer/topics-and-schema.md %}#a-worked-example-woocommerce-products).
Agentimus ships no commerce knowledge of its own, so this filter is how a store's
products stop being described as `Article`.

### Video & audio context

The per-item notes that describe a page's media, and the `VideoObject` /
`AudioObject` nodes they feed. See [Topics & schema]({% link developer/topics-and-schema.md %})
for what the nodes contain.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_video_hosts` | filter | `( string[] $hosts ): string[]` | Hosts recognised as video players, matched by suffix (`youtube.com` covers `player.youtube.com`). Only consulted for embeds WordPress has not classified itself. |
| `agentimus_media_context_blocks` | filter | `( string[] $blocks ): string[]` | Block types offered a "Context for AI" field. Default: `core/embed`, `core/video`, `core/audio`. |
| `agentimus_video_node` | filter | `( array\|null $node, WP_Post $post, array $item, int $index ): array\|null` | One media node before it joins the graph — add `duration`, a real `uploadDate` from a provider API, or a per-item transcript. Return `null` to omit it. |
| `agentimus_video_max_nodes` | filter | `( int $max ): int` | Most media nodes one page may emit. Default `10`. |
| `agentimus_defer_video_schema` | filter | `( bool $defer, WP_Post $post ): bool` | Stand down on media schema. Defaults to whether the rendered content already contains a `VideoObject` — detected by the symptom, never by a list of plugin names. Emitters running in `wp_head`/`wp_footer` execute after Agentimus and cannot be detected, so a plugin that knows it emits its own should return `true` here. |
| `agentimus_media_key_skip_segments` | filter | `( string[] $words ): string[]` | Path segments treated as scaffolding rather than identity when keying a media item (`embed`, `iframe`, `watch`…). |
| `agentimus_media_max_bytes` | filter | `( int $bytes ): int` | Byte ceiling above which media detection is skipped for a post. Default `256 KB`. |
| `agentimus_transcript_label_pattern` | filter | `( string $pattern ): string` | The pattern that recognises a "Transcript" heading or `<summary>`, for sites writing it in another language. |

Agentimus never stores or renders a transcript: it detects one already published
on the page — by any plugin — and credits it. See the
[user manual]({% link user-manual.md %}) for the authoring side.

### Topics for AI

Per-page topics become JSON-LD `keywords` plus `about` `DefinedTerm` entities, and appear in the Markdown output. These filters shape how topics are derived, cleaned, suggested and linked.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_derive_taxonomies` | filter | `( string[] $taxonomies, WP_Post $post ): string[]` | Which taxonomies auto-fill a post's Topics for AI. Default: `category`, `post_tag`. Added taxonomies (e.g. `product_cat`) flow through the same derive toggle, exclude list, dedupe and cap. |
| `agentimus_post_topics` | filter | `( string[] $topics, WP_Post $post ): string[]` | The last word on a post's resolved topics (manual + derived). Use it when topics are not taxonomy terms. The return value is re-normalised — trimmed, case-insensitively deduped and capped. |
| `agentimus_topic_exclude` | filter | `( string[] $slugs ): string[]` | Topic/category slugs omitted from the llms.txt Topics list and from a post's auto-derived topics. Default: `array( 'uncategorized' )`. |
| `agentimus_topic_meaningful` | filter | `( bool $meaningful, string $name, ?object $term ): bool` | Whether an auto-derived taxonomy term becomes a topic. Default is `false` for a purely-numeric name (junk/placeholder categories, stray IDs); return `true` to keep a number that really is the subject, e.g. "1984". |
| `agentimus_topic_suggestions` | filter | `( string[] $pool ): string[]` | The autocomplete pool in the editor's Topics-for-AI box. Default: topics already used on the site, its tags and categories, and declared Expertise. |
| `agentimus_topic_links` | filter | `( string[] $urls, string $topic, WP_Post $post ): string[]` | Authoritative reference URLs for a topic, emitted as schema.org `sameAs` on its `about` `DefinedTerm` so an assistant resolves the exact entity. Core supplies **none** — no front-end lookups and no risky auto-matching; you map them. The result is sanitised (`esc_url_raw`) and de-duplicated. |

```php
// Disambiguate topics with Wikidata IDs. Core never looks these up itself.
add_filter( 'agentimus_topic_links', function ( $urls, $topic ) {
    $map = array(
        'WordPress' => 'https://www.wikidata.org/wiki/Q13166',
        'PHP'       => 'https://www.wikidata.org/wiki/Q59',
    );
    if ( isset( $map[ $topic ] ) ) {
        $urls[] = $map[ $topic ];
    }
    return $urls;
}, 10, 2 );
```

### AI description

Each post's one-line description — the editor's **AI description** value, or an excerpt/body-summary fallback — is resolved once in `Description::for_post()` and feeds the JSON-LD `description`, the `.md` lead, and (unless a dedicated SEO plugin owns it) the page's `<meta name="description">`. These two filters shape it.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_post_description` | filter | `( string $desc, WP_Post $post ): string` | The last word on a post's resolved AI description (editor value → excerpt/summary fallback). Feeds the JSON-LD `description`, the `.md` lead and the meta tag; the return is re-cleaned (tags stripped, whitespace collapsed, capped to 300 chars). The hook for supplying your own auto-summary logic. |
| `agentimus_emit_meta_description` | filter | `( bool $emit, WP_Post $post ): bool` | Whether Agentimus manages the page `<meta name="description">` on this request. Return `false` to leave the `<head>` to your theme. (It already stands down for a dedicated SEO plugin and when the `ai_description_meta_tag` sub-toggle is off.) |

### AI writing assist

The editor's **"Draft with AI"** (description/topics) and **"Fix with AI"** (readability) buttons route a prompt through WordPress's AI Client (`wp_ai_client_prompt()`, WordPress 7.0+) — Agentimus never handles the provider key. The buttons appear only when a text-capable provider is configured under **Settings → Connectors**.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_ai_assist_enabled` | filter | `( bool $enabled ): bool` | Whether the assist is offered at all. Defaults to on when a provider is configured; return `false` to hide the buttons regardless. |

The routes behind the buttons (`POST /suggest`, `POST /suggest-fix`) are documented in the [REST endpoints]({% link developer/rest-endpoints.md %}) reference.

## Crawl & security

These are the Guard (opt-in UA blocking), the activity Classifier (labelling, not blocking) and the suggestion catalogues behind the admin allow/deny lists. They are internal-tier tuning knobs, not a third-party integration surface. Nothing is blocked until the owner adds a client to a list **and** turns blocking on.

### Guard (blocking)

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_deny_request` | filter | `( bool $deny, string $ua_lc ): bool` | The Guard's final say on whether to 403 a request. `$ua_lc` is the lower-cased user agent. |
| `agentimus_block_allowlist` | filter | `( string[] $allowed ): string[]` | Clients that must never be hard-blocked (search engines plus the owner's allow-list). |
| `agentimus_default_allowed` | filter | `( string[] $engines ): string[]` | The built-in always-allowed engine **display names** shown in the admin. Default: `Googlebot`, `Bingbot`, `DuckDuckBot`, `Applebot`, `Yandex`. Display-only — the actual matcher is `engine_signatures()`, so keep the two in step. |
| `agentimus_engine_signatures` | filter | `( array $signatures ): array` | Structured signatures used to match real crawlers at a token boundary. |
| `agentimus_generic_ua_tokens` | filter | `( string[] $tokens ): string[]` | Generic user-agent tokens treated as low-signal. |
| `agentimus_verify_bots` | filter | `( bool $on ): bool` | Force bot-identity verification (reverse DNS + published IP ranges) on or off, overriding the **Verify bot identities** setting. |
| `agentimus_reverse_dns` | filter | `( ?string $host, string $ip ): ?string` | Override the reverse (PTR) lookup used by the verifier — return a hostname string (`''` for none) to inject a resolver or cache, or `null` to fall through to the built-in lookup. |
| `agentimus_forward_dns` | filter | `( ?array $ips, string $host ): ?array` | Override the forward (A/AAAA) lookup used by the verifier — return an array of IP strings, or `null` to fall through to the built-in lookup. |

### Crawler verification & client IP

The reverse-DNS crawler verifier (whose on/off and lookup hooks — `agentimus_verify_bots`, `agentimus_reverse_dns`, `agentimus_forward_dns` — are listed under Guard above) and the real-client-IP resolver that runs before it. The verifier **fails open**: a slow, budget-exhausted or tripped lookup returns no verdict rather than a wrong one. These knobs tune that circuit breaker and the proxy handling.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_verified_bot_domains` | filter | `( array $map ): array` | The verifiable-bot rDNS map: registry token → the reverse-DNS domain suffixes an IP claiming it must resolve into (e.g. `googlebot` → `.googlebot.com`, `.google.com`). Sourced from the owner-editable Verified-bots registry (built-ins minus disabled, plus custom entries); this filter still runs last, so code can add a CDN's own verified crawler or tighten the set. |
| `agentimus_verifier_registry` | filter | `( array $entries ): array` | The effective Verified-bots registry (token → `{ token, label, ua, domains[], url, builtin }`) after the owner's edits are applied — the source for both verification methods. Add or adjust entries in code; an entry's `url` is its operator's published IP-ranges file. |
| `agentimus_verify_slow_ms` | filter | `( int $ms ): int` | Milliseconds beyond which a single DNS lookup counts as "slow" — one circuit-breaker strike, and a fail-open (null) verdict for that request. Default `900`. |
| `agentimus_verify_trip_strikes` | filter | `( int $strikes ): int` | How many slow-lookup strikes (within the strike window) open the verifier's circuit breaker, after which DNS is skipped entirely for a cooldown. Default `2`. |
| `agentimus_verify_lookup_budget` | filter | `( int $max ): int` | Maximum reverse-DNS lookups per rolling 60-second window; once spent, verification stands down (fail-open) until the window rolls. Default `30`. |
| `agentimus_trusted_proxies` | filter | `( array $proxies ): array` | Trusted proxy/CDN definitions used to resolve the real client IP, each `array( 'header' => string, 'ranges' => string[] )` of CIDRs. Ships with Cloudflare's ranges + `CF-Connecting-IP`. A forwarded header is honoured **only** when the direct peer (`REMOTE_ADDR`) falls inside that proxy's ranges, so it can't be used to spoof a source IP from the open internet. |
| `agentimus_client_ip` | filter | `( string $ip ): string` | Final override of the resolved client IP used for bot verification. It already resolves the real client behind a trusted proxy; reach for this only for an unusual proxy header or to pin a value in tests. |

### Self-declared identity check

Many crawlers put a home page in their own User-Agent (`+https://example.com/bot`) and keep a page there explaining who they are. `Activity\IdentityProbe` looks at whether that page answers — in a one-off cron event, at most one request per host per week, never on a render path, and always through `wp_safe_remote_get()` (the URL comes from a stranger's header). It reports three states: **answers**, **missing** (a 404/410, or a host that resolves to nothing) and **unreached** — everything inconclusive, including a 403 that may be a firewall turning *this* site away. It changes no verdict and blocks nothing.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_identity_probes` | filter | `( array $results ): array` | The stored map of declared URL → `{ state, code, at, seen }`. Filter to pin results (tests) or to silence the check by returning `array()`. Same warning as `agentimus_route_probe`: cron hook names and filter tags share one namespace, so never register a cron hook under this name. |
| `agentimus_identity_probe_resolves` | filter | `( ?bool $resolves, string $host ): ?bool` | Whether a hostname exists in DNS. Consulted **only** after a failed request, to tell "there is no such host" (conclusive) from "we couldn't get there" (not). `null` — the default — looks it up with `checkdnsrr()`. Return a bool on a host whose PHP cannot resolve names. |

### Classifier (labelling)

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_agent_map` | filter | `( array $map ): array` | User-agent → friendly label for the activity log. |
| `agentimus_spoof_signatures` | filter | `( string[] $signatures ): string[]` | Platform markers that flag a spoofed/legacy-device "scanner". |

### Suggestion catalogues

These power the one-click "add a known …" chips in the admin. They are suggestions only.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_known_agents` | filter | `( array $catalog ): array` | Known-agent catalog (`user-agent => label`) for the activity log. |
| `agentimus_known_trainers` | filter | `( string[] $known ): string[]` | AI-trainer user-agents offered for robots.txt blocking (e.g. GPTBot, ClaudeBot, CCBot). |
| `agentimus_known_scanners` | filter | `( string[] $known ): string[]` | Aggressive SEO/scraper user-agents offered as one-click hard-block suggestions. |
| `agentimus_known_allowed` | filter | `( string[] $known ): string[]` | On-behalf-of-user AI agents offered for the always-allow trust-list (e.g. ChatGPT-User, PerplexityBot). Deliberately excludes training crawlers. |

### security.txt, readiness & signing

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_serve_security_txt` | filter | `( bool $serve ): bool` | Whether Agentimus generates a `/.well-known/security.txt`. |
| `agentimus_security_txt` | filter | `( string $body ): string` | Edit the final `security.txt` body. |
| `agentimus_security_txt_expires_days` | filter | `( int $days ): int` | The `security.txt` `Expires` window, in days. |
| `agentimus_readiness_checks` | filter | `( array $checks, Settings $settings ): array` | Add or adjust the admin Discovery Hub readiness checks. |
| `agentimus_page_checks` | filter | `( array $checks, array $stats, WP_Post $post ): array` | Add, retune or drop the per-page "AI Readability" checks shown in the editor — the per-post sibling of `agentimus_readiness_checks`. `$stats` carries the parsed page (words and code-free `prose_words`, headings, links, images, sentence/syllable counts plus the familiarity-adjusted `familiar_syllables`, and the page's `familiar_terms` / `heavy_words`). |
| `agentimus_signed_surfaces` | filter | `( string[] $surfaces ): string[]` | Which discovery documents a companion signer signs (also listed under Discovery & output). |
| `agentimus_signing_secret_key` | filter | `( string $key ): string` | Supply the Ed25519 signing secret key from a constant or vault instead of the database (default `''`). |

```php
add_filter( 'agentimus_deny_request', function ( $deny, $ua_lc ) {
    return $deny;
}, 10, 2 );
```

### AEO/GEO score & citability

The dashboard score's **Optimized** (per-page citability) and **Cited** (AI-visibility) rungs. See the [Readiness](../user-manual/readiness.html) and [AI Visibility](../user-manual/ai-visibility.html) manual pages.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_citability_post_types` | filter | `( string[] $types ): string[]` | The exact post types graded for content citability (the Optimized rung), after commerce types are removed. |
| `agentimus_commerce_post_types` | filter | `( string[] $slugs ): string[]` | Post-type slugs treated as commerce and left out of citability grading. Default: `product`, `product_variation`, `download`, `shop_order`, `shop_coupon`, `fluent-products`. |
| `agentimus_gradeable_post` | filter | `( bool $gradeable, WP_Post $post ): bool` | Whether one page is graded for citability. Return `false` to exclude it — the admin **Set aside** action is the UI for this. |
| `agentimus_evergreen_categories` | filter | `( int[] $term_ids, WP_Post $post ): int[]` | Category term IDs whose posts are exempt from the **freshness** check (timeless content). Defaults to the owner's *Evergreen content* setting. |
| `agentimus_cited_stale_days` | filter | `( int $days ): int` | How old (in days) an AI-Visibility reading may be before the **Cited** rung stops counting it toward the score (default `90`). |

### Exposure & environment

The exposed-files self-check (Readiness → **Scan for exposed files**) and the local-vs-production detection behind the debug-config warning. Agentimus only points at a risk here — it never reads, edits or deletes a file; the probing happens same-origin from the admin browser.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_exposed_paths` | filter | `( string[] $paths, Settings $settings ): string[]` | The sensitive, root-relative path list the scan probes for (e.g. `/.env`, `/.git/config`, `/wp-config.php.bak`, `/composer.lock`). The owner's extra paths from Settings are merged on top of the returned list. |
| `agentimus_is_local_env` | filter | `( bool $local, string $host ): bool` | Override whether this counts as a local/development environment — consulted **only** when `WP_ENVIRONMENT_TYPE` isn't explicitly declared. `$host` is the site's canonical `home_url()` host. |
| `agentimus_local_host_suffixes` | filter | `( string[] $suffixes ): string[]` | Host suffixes treated as LOCAL (each begins with a dot; defaults include `.test`, `.localhost`, `.local`, `.ddev.site`, `.lndo.site`). Add only suffixes a public production site can never use, or the debug warning may be wrongly silenced. |

## Admin UI

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_match_admin_scheme` | filter | `( bool $match ): bool` | Whether the plugin's dark surfaces (the AEO/GEO score card, buttons and editable chips) adopt the user's WordPress admin colour scheme. Default `true`. Return `false` to keep the designed palette on every scheme. Core schemes use a hand-tuned ink per scheme (`Admin::SCHEME_INKS`); a third-party scheme's registered base colour is derived down to card depth instead. |

## Activity & analytics

Tuning knobs for the activity log, its retention, AI-referral tracking and the "activity to review" panel.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_activity_skip_self` | filter | `( bool $skip ): bool` | Whether to skip recording this hit as owner traffic. Default: the request carries an admin's cookie **or** a valid self-check token (the `X-Agentimus-Selfcheck` header the readiness screen's own anonymous live checks send — see `Activity\Owner`). Return `false` to log every request regardless. |
| `agentimus_activity_retention_days` | filter | `( int $days ): int` | How long agent hits + AI-referral counts are **kept**, in days. Receives the stored setting (Settings → Visit log, default `30`) and overrides it. Governs the prune cutoff and how far back the Request Log can page. It does **not** govern what the Dashboard reports on — that is `min(30, retention)` (`Repository::report_days()`). Raising it without also raising `agentimus_activity_max_rows` will not give you more days: the row cap trims oldest-first regardless of age. |
| `agentimus_flagged_ip_retention_days` | filter | `( int $days ): int` | Retention, in days, for the opt-in flagged-IP store (the only PII the plugin ever keeps, and only when the owner turns IP capture on for flagged clients). Default `14`. |
| `agentimus_activity_max_rows` | filter | `( int $max ): int` | Hard cap on rows in the activity table. Receives the stored setting (default `50000`) and overrides it. Not merely a backstop: with **Delete old records automatically** switched off it is the *only* thing that removes anything. A cap of `0` disables it entirely — reachable from code only, never from the settings form, because an unbounded table is how a shared host fills its disk. |
| `agentimus_activity_clients_limit` | filter | `( int $limit ): int` | Number of rows in the dashboard's "top clients" (by-agent) breakdown. Default `8`, clamped to `1–200`. |
| `agentimus_activity_endpoints_limit` | filter | `( int $limit ): int` | Number of rows in the dashboard's "top endpoints" breakdown. Default `12`, clamped to `1–200`. |
| `agentimus_ai_referral_sources` | filter | `( array $map ): array` | Referrer host → friendly name for "Traffic from AI" attribution. |
| `agentimus_referrals_max_rows` | filter | `( int $max ): int` | Hard cap on stored AI-referral rows — a backstop to age-based pruning that keeps the busiest `(day, source, path)` rows and drops the long tail (where a spoofed-referrer flood lands). Default `50000`; `0` disables it. |
| `agentimus_referral_beacon` | filter | `( bool $on ): bool` | Force "CDN mode": count AI referrals from a same-domain browser beacon instead of server-side, so the counts survive a full-page CDN/edge cache. Opt-in, default off (mirrors the `enable_referral_beacon` setting); the two sources can't be deduped, so it is deliberately one **or** the other. |
| `agentimus_referral_beacon_rate` | filter | `( int $max ): int` | Site-wide, PII-free flood cap on the public `/ai-hit` beacon — hits accepted per rolling 60-second window (no IP is read or stored). Default `600`; `0` disables the cap. |
| `agentimus_new_agent_seconds` | filter | `( int $seconds ): int` | The "new agent" window for the activity-to-review panel, in seconds. |
| `agentimus_burst_min_hits` | filter | `( int $hits ): int` | Minimum hits to flag a burst. |
| `agentimus_heavy_min_hits` | filter | `( int $hits ): int` | Minimum hits to flag heavy usage. |
| `agentimus_threats_limit` | filter | `( int $limit ): int` | Maximum rows in the "activity to review" panel. |

### Agent Access

Filters for the **Agent Access** log (More → Agent Access) — application-password lifecycle, ability invocations, and refused/probed requests. No IP, no personal data.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_agent_access_enabled` | filter | `( bool $on ): bool` | Whether Agent Access records at all. Mirrors the `agent_access_events` setting (default on). |
| `agentimus_agent_access_event` | filter | `( array $event ): array` | Each event just before it is stored (`kind`, `user_id`, `cred`, `subject`, `detail`). Return an empty value to drop it. `cred` is the application-password UUID, never the password. |
| `agentimus_agent_access_retention_days` | filter | `( int $days ): int` | How long an Agent Access event is kept, in days. Default `90`. |

### AI-visibility monitor

The opt-in AI-visibility monitor polls AI providers on a schedule to see whether they mention and cite you; it keeps its own settings and tables, separate from the activity log above. This knob bounds a single run.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_visibility_max_checks_per_run` | filter | `( int $max ): int` | Hard ceiling on `(tracked prompt × active provider)` checks in one monitoring run — a spend backstop above the structural product/prompt/provider caps. Default `1000`; lower it to cap monitoring spend more tightly. |

## Caching

When a page cache or CDN sits in front of your site, these tune how Agentimus keeps its agent files reachable and fresh (see the **Caching & CDNs** guide).

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_bypass_shared_cache` | filter | `( bool $on ): bool` | Force the AI endpoints uncacheable (send `Cache-Control: no-store` + `CDN-Cache-Control: no-store`) so a shared cache/CDN can't serve stored copies that hide agent fetches from the activity log. Overrides the *Settings → Caching & CDN* switch; only affects a cache that respects the header. |
| `agentimus_purge_on_change` | filter | `( bool $on ): bool` | Turn the automatic purge of the AI files (on a content change) on/off. Overrides the *Settings → Caching & CDN* switch. |
| `agentimus_purge_urls` | filter | `( string[] $urls ): string[]` | The exact absolute URLs Agentimus asks the page cache to drop on a content change — add your own, or trim the set. |
| `agentimus_purge_url` | action | `( string $url )` | Fires once per URL during a purge — hook it to support a page cache Agentimus doesn't detect natively. (`agentimus_purged` fires once with the whole list.) |

## Settings & lifecycle

Stable extension points for companions and Pro add-ons, plus the settings pipeline.

| Hook | Type | Signature | Purpose |
| --- | --- | --- | --- |
| `agentimus_entity_types` | filter | `( string[] $types ): string[]` | Add selectable schema.org entity types to Settings → Identity. |
| `agentimus_default_settings` | filter | `( array $defaults ): array` | The default settings array — seed your own companion defaults. |
| `agentimus_settings` | filter | `( array $settings ): array` | The live, merged settings array at read time. |
| `agentimus_sanitize_settings` | filter | `( array $clean, array $input ): array` | Validate/coerce companion-added fields when settings are saved. |
| `agentimus_settings_reset` | action | `()` | Runs when the owner resets settings — clear your own caches. |
| `agentimus_cache_flushed` | action | `()` | Runs after Agentimus regenerates its documents — purge your CDN / page cache. |
| `agentimus_booted` | action | `( Plugin $plugin )` | Runs after the plugin finishes booting — a companion or Pro add-on registers its features here against the shared instance. |

```php
add_filter( 'agentimus_entity_types', function ( $types ) {
    $types[] = 'Restaurant';
    return $types;
} );

add_action( 'agentimus_booted', function ( $plugin ) {
    // my_addon_boot( $plugin );
} );
```

## See also

- `examples/all-hooks-reference.php` — copy-paste blocks for the most-used hooks on this page, grouped by the same stability tiers.
- `examples/integrate-your-plugin.php` — the full `Registry::register()` / `add_well_known()` schema used inside the registration action.
- The **Registering your plugin** and **Topics for AI** pages walk through the two most common integrations end to end.

## Internal knobs

Listed so the boundary is explicit rather than accidental. These exist in the
source and are **Internal** by the rule above: they are site-owner escape
hatches and internal tuning, not an integration surface, and they may change
without notice. If you have a real use for one, ask for it to be promoted —
that is how it becomes safe to depend on.

| Hook | Area | Tunes |
| --- | --- | --- |
| `agentimus_announce_url` | IndexNow | Whether a URL is announced on publish. |
| `agentimus_ask_ai_enabled` | Ask AI | Force the Ask-AI bar on or off for a request. |
| `agentimus_ask_ai_networks` | Ask AI | Which assistants the bar offers. |
| `agentimus_share_copy_enabled` | Share | Force the Share tab on or off. |
| `agentimus_share_copy_networks` | Share | Which networks Share drafts for. |
| `agentimus_internal_links_enabled` | Internal links | Force the suggester on or off. |
| `agentimus_assist_rate_max` | Assist | Rate ceiling on AI drafting calls. |
| `agentimus_assistant_shape` | Assistant | The writing assistant's request shape. |
| `agentimus_assistant_image_models` | Assistant | Image models offered for featured images. |
| `agentimus_canonical_url` | Solo SEO | The canonical URL in solo mode. |
| `agentimus_emit_social_cards` | Solo SEO | Whether OG/X card tags are printed. |
| `agentimus_social_card_tags` | Solo SEO | The card tags themselves. |
| `agentimus_solo_mode` | Solo SEO | Whether Agentimus owns the search basics. |
| `agentimus_entity_image` | Schema | The site entity's image. |
| `agentimus_guidelines` | Guidelines | The published agent guidelines. |
| `agentimus_bing_position_scale` | Bing | Position scaling in the Bing data source. |
| `agentimus_known_signature_agents` | Web Bot Auth | Agents recognised by signature. |
| `agentimus_wba_fetch_budget` | Web Bot Auth | Key-fetch budget per request. |
| `agentimus_flagged_ips_purge` | Activity | Retention for flagged IPs. |
| `agentimus_unknown_sources_max_rows` | Activity | Row cap on the unknown-referrer diagnostic. |
| `agentimus_mcp_server_resources` | MCP | Which documents are offered as MCP resources. |
| `agentimus_publish_gated_abilities` | Abilities | Whether gated abilities are advertised. |

Agentimus also *consumes* third-party and core hooks (`the_content`,
`robots_txt`, `litespeed_purge_url`); those belong to WordPress and their
respective plugins, not to this API.
