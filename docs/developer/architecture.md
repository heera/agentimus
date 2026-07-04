---
title: Architecture
parent: Developer Reference
nav_order: 1
---

Agentimus is a single-purpose plugin: it publishes machine-readable versions of a WordPress site (llms.txt, per-page Markdown, JSON-LD, a `/.well-known` discovery layer) and gives the owner honest crawl controls and a first-party agent-activity log. This page is the map of how the code is put together, so you can find the seam you need before you extend it.

The guiding constraint is **stay lightweight**. There is no framework, no service container, no Composer-loaded runtime dependency, and by default no outbound HTTP. What follows is a deliberately small set of plain PHP classes wired together at `plugins_loaded`, plus a Vue 3 admin whose source ships in the plugin but whose build output is generated locally.

## Design philosophy

- **Framework-free by choice.** A DI container or micro-framework would undercut the whole point of a "make my site legible to machines" utility that should add near-zero overhead. Agentimus ships a hand-rolled autoloader and nothing else.
- **Nothing runs eagerly at boot.** `Plugin::boot()` only *instantiates* modules and lets each register its own WordPress hooks. Work (content assembly, header emission, REST handling) happens later, on the request that actually needs it.
- **Off-by-default for anything with side effects.** Hard blocking (`Guard`), exposure hardening (`Exposure`), WebMCP, and the AI-visibility monitor all register their hooks only when their toggle is on, so a fresh install changes nothing on the front end.
- **Defer to what already exists.** If an SEO plugin emits schema or owns the sitemap, Agentimus stands down (`Schema`, `Sitemap`); if a real file sits on disk under `/.well-known`, the router streams it rather than shadowing it (`Discovery\WellKnown`).
- **Pure logic, thin adapters.** Decisions (`Guard::denies()`, `Classifier::is_spoof()`, `Faq::extract()`, most `Exposure` helpers) are pure static methods that are unit-tested in isolation; the registered callbacks just feed them live WordPress state.

## The PSR-4 autoloader

The autoloader is nine lines in `agentimus.php`. It maps the plugin namespace onto the `inc/` directory one-to-one: `Agentimus\Foo\Bar` resolves to `inc/Foo/Bar.php`.

```php
spl_autoload_register(
    static function ( $class ) {
        if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
            return; // Not ours — let another autoloader handle it.
        }
        $relative = substr( $class, strlen( __NAMESPACE__ . '\\' ) );
        $path     = AGENTIMUS_DIR . 'inc/' . str_replace( '\\', '/', $relative ) . '.php';
        if ( is_readable( $path ) ) {
            require $path;
        }
    }
);
```

So `Agentimus\Discovery\WellKnown` lives at `inc/Discovery/WellKnown.php`, `Agentimus\Activity\Recorder` at `inc/Activity/Recorder.php`, and so on. Sub-namespaces are just subdirectories.

One class is loaded outside the autoloader. `inc/discovery-api.php` defines the **global, un-namespaced** class `Agentimus_Discovery`, and it is `require`d eagerly from `agentimus.php` so third-party plugins can call it regardless of load order. The autoloader only handles names under the `Agentimus\` namespace, and a global class name can't go through it — hence the eager require.

## Boot flow

`agentimus.php` is the bootstrap. It declares the plugin header, defines the constants everything else reads, registers the autoloader, and hangs the lifecycle callbacks off WordPress.

Constants defined here:

| Constant | Value / meaning |
|:---------|:----------------|
| `AGENTIMUS_VERSION` | The running version string (e.g. `1.12.4`). |
| `AGENTIMUS_FILE` | Absolute path to the main plugin file. |
| `AGENTIMUS_DIR` | `plugin_dir_path()` of the plugin root. |
| `AGENTIMUS_URL` | `plugin_dir_url()` of the plugin root. |
| `AGENTIMUS_CANONICAL_HOOK` | `wpdiscovery_register` — the canonical, vendor-neutral registration action of the WP_Discovery protocol. |
| `AGENTIMUS_ALIAS_HOOK` | `agentimus_register` — a back-compat alias the registry also fires. |

The runtime entry point is a single `plugins_loaded` callback:

```php
add_action(
    'plugins_loaded',
    static function () {
        Plugin::instance()->boot();
    }
);
```

`Plugin` (`inc/Plugin.php`) is a `final` singleton that acts as the orchestrator. Its `boot()` method is the one place the module graph is wired. In order, it:

1. Constructs the shared `Settings` instance (`$this->settings`) — the single option-array store every module reads from.
2. On the Agentimus admin screen only, calls `Content::watch_origins()` so post-type vendor labels can be attributed at runtime (the per-registration backtrace cost is never paid on a normal page load).
3. Registers the cache-busting hooks via `Cache::register_flush_hooks()`.
4. Instantiates and `register()`s each module, handing every one the shared `Settings`:

   ```php
   ( new Endpoints( $this->settings ) )->register();
   ( new Schema( $this->settings ) )->register();
   ( new SchemaMetaBox( $this->settings ) )->register();
   ( new Topics( $this->settings ) )->register();
   ( new Rest( $this->settings ) )->register();
   ( new Admin( $this->settings ) )->register();
   ( new Discovery\Module( $this->settings ) )->register();
   ( new Activity\Module( $this->settings ) )->register();
   ( new WebMcp( $this->settings ) )->register();
   ( new Exposure( $this->settings ) )->register();
   ```

5. Boots the opt-in AI-visibility monitor, which keeps its own settings/tables independent of the core option: `Visibility\Settings`, then `Visibility\Module`, `Visibility\Rest`, and `Visibility\Network` on multisite.
6. Registers the self-healing rewrite flush on `init` at priority 20 (`Plugin::maybe_flush_rewrites`, described below).
7. Fires `do_action( 'agentimus_booted', $this )` — the documented seam a Pro add-on hooks to register its own features against the shared `Settings`.

Every `register()` method only *adds hooks*. No front-end output, REST route, or admin screen does work during `boot()` itself.

## Activation, deactivation and multisite

`register_activation_hook` and `register_deactivation_hook` point at `Plugin::activate` / `Plugin::deactivate`. Both are multisite-aware: a network-wide activation loops the existing sites (`get_sites()`, capped at 500 to avoid an activation timeout) and runs the per-site setup inside `switch_to_blog()`. Sites beyond the cap, and any site created *after* activation, self-heal — a new site fires `wp_initialize_site`, whose bootstrap callback runs `Plugin::install_site()` when the plugin is network-active.

Per-site activation (`activate_site()`) does, in order:

- Detect a truly fresh install *before* seeding anything (so the onboarding wizard only shows for genuinely new users, never on upgrade).
- `migrate_legacy_option()` — carry settings over from the pre-rename `agent_ready_settings` key one time.
- `( new Settings() )->ensure_defaults()`.
- Install the custom tables and cron: `Activity\Table::install()`, `Activity\Referrals::install()`, `Activity\Module::schedule()`, `Visibility\Table::install()`, `Visibility\Module::schedule()`.
- `Discovery\WellKnown::add_rules()` then `flush_rewrite_rules()`, so the discovery endpoints resolve on the very first request.
- Record the rewrite signature and seed the onboarding state.

Deactivation (`deactivate_site()`) drops the generated caches (`Cache::flush()`), unschedules the cron events, and flushes rewrite rules. Options are intentionally kept; `uninstall.php` is what removes them.

### Rewrite self-healing

The `/.well-known` routes depend on rewrite rules, and `flush_rewrite_rules()` is expensive, so Agentimus flushes exactly once whenever the routed name set changes — whether from an Agentimus update that adds a built-in name or from a provider opting a name in through the `agentimus_well_known_routed` filter. `Plugin::maybe_flush_rewrites()` (hooked to `init` at 20, after `WellKnown::add_rules()`) has three guards:

1. **Never on the front end** — only in the admin or WP-CLI, so an anonymous crawler hitting `/.well-known/*` can't trigger a flush.
2. **Unchanged-signature fast path** — the steady state costs one `get_option()` plus a string compare. The signature (`rewrite_signature()`) is the version plus an md5 of the *sorted* routed + nested name sets, so provider registration order never thrashes it.
3. **A rate-limit backstop** — real flushes are capped to one per `REWRITE_FLUSH_MIN_INTERVAL` (60s), recorded only on an actual flush.

The two options involved are `agentimus_rewrite_version` (the signature) and `agentimus_rewrite_flushed_at` (the last auto-flush time).

## The `inc/` class map

### Bootstrap and shared services

| Class | Responsibility |
|:------|:---------------|
| `Plugin` | Singleton orchestrator: `boot()`, activation/deactivation, multisite loops, the rewrite self-heal. |
| `Settings` | The single `agentimus_settings` option array — defaults, typed getters, sanitisation. Shared by every module and the REST API. |
| `Content` | Registry of *which* post types are agent-visible and *how* each body is sourced. The seam that lets the plugin cover CPTs, WooCommerce products, and page-builder content. |
| `Cache` | Transient cache for the generated text endpoints, plus the content hooks that bust it. Uses the Transients API, so an object cache is respected automatically. |
| `Paths` | Resolves the *public site root* (where `/robots.txt` and `/.well-known/` actually live) — deliberately not `ABSPATH`, which can differ on a "WordPress in its own directory" install. |

### Front-end machine output

| Class | Responsibility |
|:------|:---------------|
| `Endpoints` | Front controller for `/llms.txt`, `/llms-full.txt`, Markdown delivery (`.md` URLs and `Accept: text/markdown`), the robots.txt content-signal rules, AI-usage (TDM) headers, and discovery `Link` headers. Owns routing, response headers, and caching policy. |
| `LlmsText` | Pure content assembly for the llms.txt index and the llms-full.txt full-text edition (and the home/archive Markdown view). `Endpoints` owns an instance and serves what it produces. |
| `Markdown` | HTML → Markdown rendering by walking the DOM (so nested lists, links inside emphasis, and code blocks survive) for the per-page `.md` twins. |
| `Schema` | JSON-LD output: a sitewide WebSite + Person/Organization entity, plus BlogPosting + BreadcrumbList (and FAQPage) on singular views. Defers entirely when a schema-emitting SEO plugin is active. |
| `SchemaMetaBox` | Read-only editor meta box previewing the exact JSON-LD Agentimus would emit for the post being edited (same `Schema::build_document()` the front end uses). |
| `Faq` | Pure Q&A-pair extraction from rendered HTML (details/summary blocks and question-headings) so `Schema` can publish a FAQPage. |
| `Topics` | Per-page topic keywords → JSON-LD `keywords` + per-page Markdown front matter. The single source of truth `Schema` and `Markdown` both call, so the two surfaces can never disagree. |
| `Sitemap` | Detection (not generation) of which XML sitemap the site serves and who owns it — core, an SEO plugin, or Agentimus's own opt-in gap-filler. |
| `Tdmrep` | `/.well-known/tdmrep.json` — the W3C TDM Reservation Protocol opt-out, mirroring the robots.txt content-signal `ai_train` decision. |

### Protection and exposure

| Class | Responsibility |
|:------|:---------------|
| `Guard` | Optional hard enforcement: a 403 at the agent endpoints for denylisted or spoofed/legacy-device User-Agents. Off by default. `denies()` is pure and tested; `maybe_block()` is the thin emit-and-exit wrapper. Real on-disk `/.well-known` files are never guarded. |
| `Exposure` | Opt-in hardening for anonymous visitors: user-enumeration blocking, author-archive suppression, hiding the WP version, tidying auto-generated head links, disabling XML-RPC. Every control is off by default and only registers its hooks when enabled. |
| `WebMcp` | Experimental, opt-in bridge that registers the site's read-only tools with in-browser agents via `navigator.modelContext` (WebMCP). Inert in browsers without the API; anonymous visitors get read-only tools only. |

### Admin and REST

| Class | Responsibility |
|:------|:---------------|
| `Admin` | The top-level admin menu that mounts the Vue app, the asset enqueue, and the bootstrap-data payload (`AgentimusData`). |
| `Rest` | REST controller backing the Vue admin — read/save settings, reset, onboarding, readiness, and the schema/markdown previews. All routes require `manage_options` and the standard REST nonce. |
| `Readiness` | The pass/warn/fail check list the admin Readiness panel renders. Each check is cheap and side-effect-free. |

### The `Discovery\` subsystem (`/.well-known` + the registry)

| Class | Responsibility |
|:------|:---------------|
| `Discovery\Module` | Wires the registry, the `WellKnown` front controller, the built-in adapters, the `SecurityTxt` generator, the advertising `Link` header, and the CI-friendly REST routes. The single seam `Plugin::boot()` turns on. |
| `Discovery\Registry` | The collector every provider registers with. Fires `wpdiscovery_register` (and the `agentimus_register` alias) once per request, drains the `Agentimus_Discovery` facade queue, normalizes resources, and records validation notices. |
| `Discovery\Resource` | Normalizer/validator for one registered discovery entry — the frozen 1.0 shape a third-party plugin hard-codes when it registers. |
| `Discovery\Envelope` | Derives the machine docs from the collected registry: the master `discovery.json` index plus the generated A2A `agent-card.json`. Exposes `SPEC_VERSION`. |
| `Discovery\WellKnown` | The single front controller for `/.well-known/*` — routes only the names it owns, streams a real on-disk file when one exists, and otherwise leaves the namespace alone. |
| `Discovery\WellKnownDocs` | The secondary JSON docs derived from the manifest: the agent-card, the RFC 9727 API catalog, RFC 9728 OAuth protected-resource metadata, and the Agent Skills index. |
| `Discovery\McpSurface` | Everything MCP — discovers and advertises the MCP servers a shared adapter library has registered (`mcp.json`, the server card). Agentimus does not itself run an MCP server. |
| `Discovery\OpenApi` | Emits an OpenAPI 3.1 document *describing* the site's existing public REST read API for the indexed content types. Adds no endpoints. |
| `Discovery\SecurityTxt` | Opt-in, gap-filling RFC 9116 `security.txt` generator — the reference example of the "extend, never override" contract. |
| `Discovery\Signer` | Optional Ed25519 response signing (Web Bot Auth / RFC 9421 HTTP Message Signatures) and the published key directory. Feature-detected on libsodium. |
| `Discovery\Hub` | Assembles the data the admin Discovery Hub screen renders (the live envelope, adapter status, validation notices). |
| `Discovery\Adapters\RestApi` | Zero-config auto-discovery: reads WordPress's own REST namespace map so a site is discoverable even when no plugin hooks the registration action. |
| `Discovery\Adapters\AbilitiesApi` | Bridges the WordPress Abilities API into the MCP layer, registering through the same public hook a third-party would. |

### The `Activity\` subsystem (agent-activity log)

| Class | Responsibility |
|:------|:---------------|
| `Activity\Module` | Wires the log: ensures the table exists, exposes the admin REST surface (read/clear/day/block/allow), records human referrals, and runs the daily prune cron. |
| `Activity\Table` | Schema and install for the dedicated, append-only activity table (one INSERT per hit — no option read-modify-write race). |
| `Activity\Recorder` | Logs one agent hit on a discovery/llms endpoint. First-party and local-only, storing the endpoint, the classified agent, and a truncated UA — deliberately no IP. |
| `Activity\Classifier` | Turns a raw User-Agent into a friendly agent label ("Claude", "GPTBot", …) and provides the shared `is_spoof()` heuristic that `Guard` reuses. |
| `Activity\Catalog` | Recognition layer that maps a known crawler UA to an identity card: who runs it, what kind it is (AI/SEO/search/social), and a docs link. |
| `Activity\Repository` | The read/maintenance side — dashboard stats, retention pruning, and clearing. All timestamps are stored/queried in GMT. |
| `Activity\Referrals` | The mirror of `Recorder`: counts real human visits that arrive *from* an AI assistant. |

### The `Visibility\` subsystem (opt-in AI-visibility monitor)

This is the monetizable monitoring layer. It is BYOK (bring-your-own-key), off until configured, and keeps its config, keys, and results in their own option and table, independent of the core settings.

| Class | Responsibility |
|:------|:---------------|
| `Visibility\Settings` | The Pro config store — brand/competitors to watch, tracked prompts, per-provider keys and models, run cadence, and history retention. |
| `Visibility\Module` | Cron wiring: the recurring run event, the one-off background "run now", and keeping the schedule in sync with the chosen frequency. |
| `Visibility\Runner` | Executes a monitoring pass — asks every active provider each tracked prompt, analyzes the answers, and stores results. Also powers the "test this key" check. |
| `Visibility\Analyzer` | Turns one provider answer into the stored signals: brand mention, citation, ranking against competitors, and which competitors appeared. |
| `Visibility\Store` | Read/write of the results table plus the aggregation into dashboard numbers (visibility score, citation rate, share-of-voice, trend). |
| `Visibility\Table` | The results table — one row per (prompt × provider) check within a run. |
| `Visibility\Rest` | The Pro admin REST controller (config, run-now, dashboard, key test). Requires `manage_options`. |
| `Visibility\Network` | The multisite Network-Admin dashboard rolling every site's latest numbers into one table. |
| `Visibility\Providers\Provider` | Base class handling the HTTP round-trip through `wp_remote_post`. |
| `Visibility\Providers\{OpenAI,Anthropic,Gemini,Perplexity}` | Per-engine providers, each knowing its own endpoint, auth header, and response shape. |

## Where things hook into WordPress

Every module registers its own hooks in `register()`. The important attachment points:

| WordPress hook | What Agentimus does |
|:---------------|:--------------------|
| `plugins_loaded` | `Plugin::instance()->boot()` — the single runtime entry point. |
| `register_activation_hook` / `register_deactivation_hook` | `Plugin::activate` / `Plugin::deactivate`. |
| `wp_initialize_site` | `Plugin::install_site()` for a new multisite site while network-active. |
| `init` | `WellKnown::add_rules()` (default priority), `Plugin::maybe_flush_rewrites` (20), `Topics::register_meta`. |
| `template_redirect` (priority 0) | `Endpoints::route()` and `WellKnown::route()` intercept the agent endpoints before the theme loads; `Exposure` block callbacks; `Activity\Referrals::maybe_record` (30). |
| `send_headers` (priority 99) | `Endpoints::link_headers()` and `ai_signal_headers()`; `Discovery\Module::link_header()` advertises `discovery.json` via `rel="service-desc"` and `rel="discovery"`. |
| `wp_head` | `Schema::output()` (1) prints the JSON-LD; `Endpoints::head_links()` (2) mirrors the two most useful discovery links into the markup. |
| `robots_txt` (filter, priority 20) | `Endpoints::robots_txt()` injects the content-signal rules and sitemap reference. |
| `rest_api_init` | `Rest::routes`, `Discovery\Module::rest_routes`, `Activity\Module::routes`, and `Visibility\Rest`. |
| `admin_menu` / `admin_enqueue_scripts` | `Admin::menu` mounts the app; `Admin::assets` enqueues the built bundle. |
| `add_meta_boxes` | `Topics` and `SchemaMetaBox` add their editor meta boxes. |
| `save_post`, `deleted_post`, `trashed_post`, `created_term`, `edited_term`, `delete_term`, `update_option_blogname`, `update_option_blogdescription` | `Cache::flush()` — a content or identity change rebuilds everything. |
| `activated_plugin` / `deactivated_plugin` | `Cache::flush_discovery()` — only the discovery doc changes when a provider comes or goes, so the heavy content caches are left intact. |
| `agentimus_booted` | The seam a Pro add-on hooks to register against the shared `Settings`. |
| `agentimus_cache_flushed` | Fired after the generated caches drop; `Endpoints::schedule_warm()` debounces an out-of-band re-warm of `/llms-full.txt`. |

### The `/.well-known` router in detail

`WellKnown::add_rules()` registers a **narrow** rewrite rule — an alternation of only the names it serves, never a catch-all — so it never captures a namespace it doesn't own:

```php
add_rewrite_rule( '^\.well-known/(' . $alt . ')$', 'index.php?wpd_well_known=$matches[1]', 'top' );
add_rewrite_tag( '%wpd_well_known%', '([^/]+)' );
```

The flat, routed names are returned by `WellKnown::routed_names()` — currently `discovery.json`, `agent-card.json`, `agent.json`, `mcp.json`, `openapi.json`, `api-catalog`, `oauth-protected-resource`, `tdmrep.json`, and the signer's key directory — filterable through `agentimus_well_known_routed`. Nested docs that contain a `/` (`mcp/server-card.json`, `agent-skills/index.json`) get their own rules and the `wpd_well_known_nested` query var, filterable through `agentimus_well_known_nested`. `route()` runs on `template_redirect` at priority 0, reads those query vars, and streams the right document (or a real on-disk file when one exists).

## The discovery registry and public facade

The discovery layer is an open registration standard, not an Agentimus-only feature. Providers have two equivalent ways in, both documented in `inc/discovery-api.php`:

```php
// 1. The action hook — no hard dependency. If no engine is active, it never fires.
add_action( 'wpdiscovery_register', function ( $registry ) {
    $registry->register( [ 'id' => 'acme', 'title' => 'Acme', 'type' => 'commerce' ] );
} );

// 2. The global facade — guard with class_exists() since the call is direct.
if ( class_exists( 'Agentimus_Discovery' ) ) {
    Agentimus_Discovery::register( [ 'id' => 'acme', 'title' => 'Acme', 'type' => 'commerce' ] );
}
```

`Agentimus_Discovery` is a dependency-free queue: calls made before the registry runs are buffered and drained during collection, so authors can register from anywhere without worrying about timing. `Discovery\Registry` fires the canonical `wpdiscovery_register` action plus the `agentimus_register` back-compat alias exactly once per request, drains the facade queue, and hands each entry to `Discovery\Resource` for normalization and validation. `Discovery\Envelope` then projects the collected resources into `discovery.json` and the agent-card. See the *Integrate your plugin* and *WP_Discovery Protocol* pages for the full field reference, and `examples/integrate-your-plugin.php` for a runnable sample.

## The Vue 3 + Vite admin

The admin screen is a Vue 3 single-page app. The **source ships** with the plugin under `resources/admin/`; the **build output does not** — `assets/admin/` is in `.gitignore` and is regenerated by the build.

- `package.json` declares `vue ^3.4`, with `@vitejs/plugin-vue` and `vite` as dev dependencies, and two scripts: `npm run dev` (`vite build --watch`) and `npm run build` (`vite build`).
- `vite.config.js` builds `resources/admin/main.js` into `assets/admin/` with **stable filenames** — `app.js` and `app.css` — so PHP can enqueue them without reading a manifest (`manifest: false`, `cssCodeSplit: false`).
- The output format is **IIFE**. The bundle is enqueued as a classic script, so it must not leak top-level bindings into the global scope where a minifier-named `wp`/`lodash` would collide with WordPress's own globals; wrapping everything in a function scope prevents "Identifier 'wp' has already been declared".

The entry point mounts onto a single element:

```js
// resources/admin/main.js
import { createApp } from 'vue';
import App from './App.vue';
import './app.css';

const mount = document.getElementById('agentimus-app');
if (mount) {
  const boot = window.AgentimusData || {};   // injected by wp_localize_script
  createApp(App, { boot }).mount(mount);
}
```

The Vue tree lives in `resources/admin/components/` — `SettingsForm.vue`, `ReadinessPanel.vue`, `ActivityPanel.vue`, `DiscoveryHub.vue`, `SchemaPreview.vue`, `VisibilityPanel.vue`, `OnboardingWizard.vue`, `AboutPanel.vue`, and smaller shared inputs (`TagInput.vue`, `SelectMenu.vue`, `ConfirmDialog.vue`, `ProviderRow.vue`, `ReviewMenu.vue`) — plus helpers `api.js`, `livecheck.js`, `tiers.js`, and `confirm.js`.

On the PHP side, `Admin` enqueues the bundle only on its own screen (hook `toplevel_page_agentimus`), and only if `assets/admin/app.js` is readable — otherwise `render()` prints a graceful "run `npm install && npm run build`" notice. Cache-busting uses the asset's own `filemtime()`, so a rebuild is served fresh with no version bump. The app is handed its data through `wp_localize_script` as the `AgentimusData` global, built by `Admin::bootstrap_data()`:

```php
wp_localize_script( self::HANDLE, 'AgentimusData', $this->bootstrap_data() );
```

That payload includes the REST root (`agentimus/v1`) and nonce, the current settings and their defaults (for the reset preview), the readiness report, the discovery hub data, detected REST namespaces, entity/post-type lists, the known crawler dictionaries, the public endpoint URLs, the protocol facts (name, spec version, canonical hook), the registered WebMCP tools, and the onboarding state.

## Caching

`Cache` is a thin wrapper over the Transients API, so an external object cache is respected for free. It holds the generated text and discovery documents under fixed keys (`agentimus_llms_txt`, `agentimus_llms_full`, `agentimus`, `agentimus_security_txt`, and a sitemap generation token), with a one-hour default TTL and a shorter 15-minute TTL for a truncated full-text body.

Two invalidation paths exist so unrelated activity never regenerates the heavy full-text edition:

- **Content or identity change** (`save_post`, term edits, blog name/description) → `Cache::flush()` drops everything and fires `agentimus_cache_flushed`, which schedules the debounced `/llms-full.txt` re-warm.
- **A provider plugin toggled** (`activated_plugin` / `deactivated_plugin`) → `Cache::flush_discovery()` drops *only* the discovery document, deliberately not firing `agentimus_cache_flushed`.

## Where to go next

- To make your own plugin discoverable, see *Integrate your plugin* and the *WP_Discovery Protocol* page (and `examples/integrate-your-plugin.php`).
- For the complete extension surface, see the *Hooks & filters reference* (and `examples/all-hooks-reference.php`).
- For the admin API and the public machine files, see *REST & public endpoints*.
- To shape the structured data by code, see *Customizing topics & schema* (and `examples/topic-links-wikidata.php`).
