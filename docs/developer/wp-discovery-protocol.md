---
title: WP_Discovery Protocol
parent: Developer Reference
nav_order: 2
---

Agentimus is a reference *engine* for an open, vendor-neutral standard called the **WP_Discovery Protocol**. The protocol defines one thing: how any WordPress plugin, theme, or mu-plugin can declare — in a few lines, with no library and no hard dependency — what it does, where its APIs live, and how an AI agent should reach them. Agentimus collects every declaration on a site and projects it into the machine-discovery documents agents actually fetch: `/.well-known/discovery.json`, `agent-card.json`, and `mcp.json`.

This page is the integration contract. Every hook name, method signature, field, and document shape below is drawn from the plugin source (`inc/Discovery/*`, `inc/discovery-api.php`, `agentimus.php`). If something you expect isn't here, it isn't implemented.

## How the pieces fit together

There are two roles:

- **The engine** (Agentimus) owns the collector, the `/.well-known/*` front controller, and the document generators. Exactly one engine runs per site.
- **A provider** (your plugin/theme) registers *resources* against the engine's public hook. A provider carries **no dependency** on Agentimus: if no engine is active, the hook simply never fires and your registration code is inert.

The engine exposes the collector as a `Registry` instance. A provider never constructs it — it receives it as the sole argument to the registration hook, and calls `register()` / `add_well_known()` on it.

## The registration hook

The protocol is built on a single canonical action, defined in `agentimus.php`:

```php
define( 'AGENTIMUS_CANONICAL_HOOK', 'wpdiscovery_register' ); // canonical, vendor-neutral
define( 'AGENTIMUS_ALIAS_HOOK',     'agentimus_register' );   // back-compat alias
```

- **`wpdiscovery_register`** is the canonical, vendor-neutral hook. Providers **SHOULD** hook this name so the same integration keeps working under any future WP_Discovery engine.
- **`agentimus_register`** is a product-branded alias the engine fires immediately after the canonical hook, for back-compat only. Hook one or the other — **never both**, or you register twice.

`Registry::collect()` fires both actions exactly once per request (guarded by an internal `$collected` flag), passing the registry instance:

```php
do_action( AGENTIMUS_CANONICAL_HOOK, $this ); // wpdiscovery_register
do_action( AGENTIMUS_ALIAS_HOOK, $this );     // agentimus_register
```

Collection is lazy and idempotent — it runs the first time any output surface is built (a `/.well-known/*` request, the REST endpoint, or an admin screen) and is a no-op thereafter. You don't have to worry about *when* to register; add your action at any normal point (typically on `plugins_loaded` or `init`) and the engine calls it at the right time.

{: .note }
> Because the hook only fires when an engine is present, you need **no** `class_exists()` guard and **no** `function_exists()` check around an `add_action( 'wpdiscovery_register', … )` call. That's the whole point of hook-based registration — zero coupling.

## Minimal provider registration

The entire integration for making a plugin discoverable:

```php
add_action( 'wpdiscovery_register', function ( $registry ) {
    $registry->register( array(
        'id'    => 'acme-bookings', // unique lowercase slug
        'title' => 'Acme Bookings', // human label
        'type'  => 'scheduling',    // controlled vocabulary
    ) );
} );
```

That alone folds your plugin into the site's `discovery.json`. Everything else is optional enrichment.

## Registering a resource

The collector's public surface (`inc/Discovery/Registry.php`):

| Method | Purpose | Returns |
|---|---|---|
| `$registry->register( array $resource )` | Register one resource. Canonical method named by the spec. | `true` \| `WP_Error` |
| `$registry->add( array $resource )` | Identical alias of `register()`, kept for call-site brevity. | `true` \| `WP_Error` |
| `$registry->add_well_known( array $def )` | Serve a document under `/.well-known/<name>`. | `true` \| `WP_Error` |

Each resource is validated **synchronously** by `Resource::normalize()` the moment you register it. A fatal field (bad `id`, missing `title`, unknown `type`) returns a `WP_Error` *and* records a notice that surfaces in the admin **Discovery Hub → Validation** panel (and in the `/validate` REST endpoint). Unknown keys are silently dropped; a duplicate `id` records a warning and the later registration wins.

### Resource fields

The frozen resource shape (spec 1.0). `id`, `title`, and `type` are required; the rest are optional.

| Field | Type | Notes |
|---|---|---|
| `id` | string (required) | Unique slug, `^[a-z0-9](-?[a-z0-9]+)*$`. |
| `title` | string (required) | Human label. |
| `type` | string (required) | Controlled vocabulary (below) or an `x-<vendor>-<name>` extension token. |
| `description` | string | Short summary. |
| `version` | string | Your plugin/API version. |
| `capabilities` | string[] | Dot-notation **intent** verbs, e.g. `scheduling.booking.create`. Folded into the site-wide capability union. |
| `abilities` | string[] | Names of [WP Abilities API](https://developer.wordpress.org/) units this resource fulfils — the executable bridge for the intent strings above. |
| `tools` | array | MCP-shaped tool definitions (see the MCP section). |
| `endpoints` | array | Where the API lives: `{ url, type, methods[], auth, description }`. |
| `schemas` | string[] | URLs to OpenAPI / JSON-Schema / GraphQL SDL. |
| `auth` | array | `{ type, oidc, scopes[], docs }`. |
| `agent` | array | A2A agent-card fragment: `{ name, description, skills[{id,description}], endpoint, auth }`. |
| `well_known` | array | Pointers the envelope lists (to *serve* a doc use `add_well_known()`). |
| `docs` | string (URL) | Human documentation. |
| `provider` | — | **Auto-derived; never set it.** Overwritten with the real registrant, detected by stack walk. |

Site-relative URLs (`/wp-json/acme/v1`) are accepted everywhere a URL is expected and are absolutized against the site on output, so you don't hard-code the host.

{: .warning }
> `provider` is always computed from the call stack (`Resource::detect_provider()`) and overwrites anything you pass. It attributes a registration to the plugin, mu-plugin, or theme it came from, so a site owner can curate per-source. You cannot spoof it.

### Controlled vocabularies

`type` — one of these, or an `x-<vendor>-<name>` extension:

```
content   commerce   scheduling   courses   forms   crm      auth
search    media      messaging    analytics payments directory agent
```

`endpoints[].type` — the transport. `rest` is the default when omitted:

```
rest   graphql   mcp   openapi   a2a   soap   rpc
```

Only `rest`, `graphql`, `openapi`, `soap`, and `rpc` endpoints flatten into the envelope's `apis[]` array. `mcp` and `a2a` endpoints are carried on the resource but not treated as generic REST-style APIs.

`auth.type` and `endpoints[].auth` — the scheme. `none` is the default:

```
none   apikey   basic   oauth2   oidc   custom
```

### A richer example

```php
add_action( 'wpdiscovery_register', function ( $registry ) {
    $registry->register( array(
        'id'           => 'acme-bookings',
        'title'        => 'Acme Bookings',
        'type'         => 'scheduling',
        'description'  => 'Appointment booking, availability and calendars.',
        'version'      => defined( 'ACME_VERSION' ) ? ACME_VERSION : '',

        // Dot-notation INTENT — what you can do (not where).
        'capabilities' => array(
            'scheduling.availability.read',
            'scheduling.booking.create',
            'scheduling.booking.cancel',
        ),

        // WHERE. Site-relative URLs are absolutized on output.
        'endpoints'    => array(
            array(
                'url'         => '/wp-json/acme/v1',
                'type'        => 'rest',
                'methods'     => array( 'GET', 'POST' ),
                'auth'        => 'apikey',
                'description' => 'Public booking API.',
            ),
        ),

        'schemas'      => array( '/wp-json/acme/v1/openapi.json' ),

        // HOW to authenticate.
        'auth'         => array(
            'type'   => 'apikey',
            'docs'   => 'https://example.com/api/auth',
            'scopes' => array( 'bookings:write' ),
        ),

        // Optional A2A agent card → surfaces in agent-card.json.
        'agent'        => array(
            'name'        => 'Acme Booking Agent',
            'description' => 'Check availability and book appointments.',
            'endpoint'    => '/wp-json/acme/v1/agent',
            'auth'        => 'apikey',
            'skills'      => array(
                array( 'id' => 'check_availability', 'description' => 'List open slots.' ),
                array( 'id' => 'create_booking', 'description' => 'Book an appointment.' ),
            ),
        ),

        'docs'         => 'https://example.com/docs',
    ) );
} );
```

A runnable copy of both the minimal and rich forms ships in `examples/integrate-your-plugin.php`; the complete hook catalog is in `examples/all-hooks-reference.php`.

## The global facade

For code that can't cleanly hook an action (a one-shot bootstrap, a template), the engine also ships a global convenience class, `Agentimus_Discovery`, loaded eagerly so it's available regardless of load order. Because the call is **direct**, guard it with `class_exists()`:

```php
if ( class_exists( 'Agentimus_Discovery' ) ) {
    Agentimus_Discovery::register( array(
        'id'    => 'acme-bookings',
        'title' => 'Acme Bookings',
        'type'  => 'scheduling',
    ) );
}
```

Facade calls are buffered in a static queue and drained by the registry **before** the hook fires during collection, so hook-registered and facade-registered resources end up in the same place no matter the order. The facade is Agentimus-specific; the `wpdiscovery_register` hook is the vendor-neutral path and should be preferred.

## Serving your own `/.well-known` document

`add_well_known()` lets a provider serve a document under the site's `/.well-known/` namespace. Supply exactly one source — `callback`, `redirect`, or `file`:

```php
add_action( 'wpdiscovery_register', function ( $registry ) {
    $registry->add_well_known( array(
        'name'         => 'security.txt',   // → /.well-known/security.txt
        'content_type' => 'text/plain',     // default: text/plain
        'callback'     => function () {
            return "Contact: mailto:security@example.com\n";
        },
    ) );
} );
```

| Key | Meaning |
|---|---|
| `name` | Doc name, no leading slash (`sanitize_file_name`'d). Required. |
| `content_type` | MIME type. Default `text/plain`. |
| `callback` | `callable` returning the body string. |
| `redirect` | A path/URL to `302` to. |
| `file` | Absolute path to a static file to stream. |

Rules: a **real file on disk always wins** over any registered source; the first provider to claim a `name` keeps it (a later claim returns a `WP_Error` and records a warning); and a provider-served doc appears in the envelope's `well_known` index with `source: "managed"`.

{: .note }
> On servers that don't front-controller `/.well-known/` to PHP, add your name to the routing allow-list via the `agentimus_well_known_routed` filter (or `agentimus_well_known_nested` for a `dir/file.json` path) and flush rewrites, so the request reaches WordPress.

## The discovery.json envelope

`/.well-known/discovery.json` is the master index — a projection of the collected registry plus site identity, assembled by `Envelope::build()` and cached for an hour. Nothing in it is hand-maintained. The wire format is versioned by `spec_version` (currently **`1.0`** — a `major.minor` selector, *not* semver, and separate from the plugin's release version).

The frozen core is exactly these eleven top-level keys, in order:

| Key | Contents |
|---|---|
| `$schema` | URL of the JSON Schema (see below). |
| `spec_version` | Wire-format version, `"1.0"`. |
| `site` | `{ name, url, description, lang, logo }`. |
| `identity` | The "whoami" — `{ type, name, role, about, url, same_as[], contacts[] }`, plus optional `not_description` / `audience`. |
| `documents` | Name → URL map of standard docs: `sitemap`, `robots`, `feed`, `openapi`, and `llms` / `llms_full` / `humans` / `security` when present. |
| `well_known` | Every `/.well-known/*` resource the site exposes, each `{ name, url, source, spec? }` where `source` is `generated` \| `managed` \| `file`. |
| `apis` | Flattened endpoints: `{ id, type, base, schema, auth:{ type, docs } }`. |
| `agents` | Resources that carried an `agent` fragment, each with an `id`, absolutized `endpoint`, and a `card` deep link. |
| `resources` | Every registered resource, absolutized. |
| `capabilities` | Deduped union of all resources' `capabilities`. |
| `trust` | `{ signed }` — plus `signature_alg` / `jwks_uri` when response signing is on, and `security_txt` / `policy` when present. |

Experimental surfaces (the MCP descriptor, the tool list) are deliberately **not** baked into this frozen core — they're served separately so they can track unsettled ecosystem proposals without a wire bump. A consumer needing inline extras can add `x-`-prefixed keys via the `agentimus_envelope` filter; the unprefixed namespace is reserved for the spec.

## agent-card.json (and agent.json)

`/.well-known/agent-card.json` is the generated A2A card, projected from the envelope by `WellKnownDocs::agent_card_json()`:

```json
{
  "name":        "…site name…",
  "description": "…site description…",
  "url":         "https://example.com/",
  "provider":    { "organization": "…", "url": "https://example.com/" },
  "agents":      [ /* every resource that declared an `agent` fragment */ ]
}
```

`/.well-known/agent.json` is a byte-identical **alias** for agents that look there first. Both are served by the same route.

The **Agent Skills** index at `/.well-known/agent-skills/index.json` lists the executable skills projected from each resource's `agent.skills[]`. It is served **only** when real skills exist (otherwise a clean 404), and can be extended with the `agentimus_agent_skills` filter.

## mcp.json and the MCP surface

Agentimus does **not** run an MCP server — it *discovers and advertises* MCP servers a shared adapter library has registered, and projects your registered `tools` into an experimental manifest. `/.well-known/mcp.json` (`McpSurface::mcp_json()`) looks like:

```json
{
  "name":        "…site name…",
  "description": "…",
  "url":         "https://example.com/",
  "mcp":  { "available": true, "source": "wordpress-mcp", "endpoint": "…",
            "transport": "streamable-http", "auth": "…", "tools": 3,
            "servers": [ … ], "status": "experimental" },
  "tools": [ … ]
}
```

Register MCP-shaped tools on any resource; they mirror the MCP `tools/list` shape and are deduped into the site-wide `tools[]`:

```php
$registry->register( array(
    'id'    => 'acme-tools',
    'title' => 'Acme Tools',
    'type'  => 'agent',
    'tools' => array(
        array(
            'name'        => 'acme/check-availability', // "name" or "namespace/name"
            'title'       => 'Check availability',
            'description' => 'Return open slots for a service.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array( 'service_id' => array( 'type' => 'integer' ) ),
            ),
            'annotations' => array( 'readOnlyHint' => true ),
            'auth'        => 'apikey',
        ),
    ),
) );
```

When a live MCP server *is* present (detected via `WP\MCP\Core\McpAdapter` after `mcp_adapter_init`), the engine also serves SEP-2127 server cards at `/.well-known/mcp/server-card.json` and `/.well-known/mcp/{id}/server-card.json`. The MCP block is flagged `status: "experimental"` and is shaped by the `agentimus_mcp` and `agentimus_mcp_card_server` filters.

## The `/.well-known` routing model

`inc/Discovery/WellKnown.php` is the single front controller for `/.well-known/*`, hooked on `template_redirect` at priority 0. It is **deliberately not greedy**: it routes only the exact names it recognises and leaves everything else (ACME challenges, other plugins' docs, unknown names) untouched to fall through to WordPress's normal handling.

The authoritative flat names it routes (`WellKnown::routed_names()`):

```
discovery.json   agent-card.json   agent.json   mcp.json   openapi.json
api-catalog      oauth-protected-resource        tdmrep.json
http-message-signatures-directory
```

Plus an exact allow-list of nested names (`nested_routes()`): `mcp/server-card.json` and `agent-skills/index.json`, and the controlled per-server pattern `mcp/{id}/server-card.json`.

Resolution order for a routed request:

1. **A real file on disk wins.** If the web server didn't already serve it, the controller streams it (path-traversal guarded).
2. **Generated documents** — `discovery.json`, `agent-card.json` / `agent.json`, `mcp.json`, `openapi.json`, `api-catalog`, `oauth-protected-resource`, the signing-key directory, `tdmrep.json`.
3. **Provider-registered documents** from `add_well_known()`.
4. If Agentimus routed the request but produced nothing (a gated-off doc), it forces a **clean 404** rather than let WordPress canonical-redirect to the homepage.

Gated documents that have nothing to say return an empty body → clean 404 (e.g. `oauth-protected-resource` with no auth server, `mcp/server-card.json` with no MCP server). Cross-origin agents get `Access-Control-Allow-Origin: *` on every served doc and a scoped `OPTIONS` preflight answer. The rewrite rules are registered on `init` and flushed on activation.

## How agents find the entry point

Agentimus advertises the discovery document without an agent having to guess the path:

- **HTTP `Link` headers** on every front-end response (`Discovery\Module::link_header()`), using a registered relation plus the protocol's own rel:
  - `rel="service-desc"; type="application/json"` → `/.well-known/discovery.json`
  - `rel="discovery"; type="application/json"` → `/.well-known/discovery.json`
- `Endpoints` adds `rel="api-catalog"` (the REST root), `rel="describedby"` (`/llms.txt`), and a per-page `rel="alternate"; type="text/markdown"` link header.
- Two of these are mirrored into the HTML `<head>` (`rel="describedby"` for `/llms.txt`, `rel="service-desc"` for `/.well-known/openapi.json`) for crawlers that read markup but not headers.

The `/.well-known/api-catalog` document itself is an RFC 9727 API catalog serialized as an RFC 9264 link set (`application/linkset+json`), pointing at the discovery document, the WordPress REST root, and every derived API base.

## REST endpoints for tooling and CI

`Discovery\Module::rest_routes()` registers three read-only routes under `agentimus/v1`:

| Route | Auth | Returns |
|---|---|---|
| `GET /wp-json/agentimus/v1/discovery` | public | The live envelope (same as `discovery.json`, as a REST response). |
| `GET /wp-json/agentimus/v1/validate` | `manage_options` | `{ ok, resources, notices }` — the validation result, ideal for CI. |
| `GET /wp-json/agentimus/v1/discovery/hub` | `manage_options` | Admin Discovery Hub data. |

Use `/validate` in a build step to fail fast when a registration is malformed:

```bash
curl -s -u admin:app-password \
  "https://example.com/wp-json/agentimus/v1/validate" | jq '.ok, .notices'
```

`ok` is `false` whenever any notice is an `error`-level rejection.

## Zero-config auto-discovery

A site is discoverable even when **no** plugin hooks `wpdiscovery_register`. Two built-in adapters read WordPress's own registries and register through the same public hook a third party would, at **priority 99** so every explicit provider (default priority 10) is already in place and never shadowed:

- **`RestApi`** — emits one `wordpress-core` content resource (from `wp/v2`, public post types and taxonomies) and one lightweight resource per third-party REST namespace that never described itself, so its API still appears under `apis[]`.
- **`AbilitiesApi`** — projects the WP Abilities API into `abilities` / `tools`.

They index only what `/wp-json/` already makes public — no AI, no external calls. Providers that hook in later *enrich* this baseline with intent, agent cards, and tools. Auto-discovery is tunable via `agentimus_rest_discovery`, `agentimus_rest_namespaces`, `agentimus_rest_skip_namespaces`, and `agentimus_discoverable_ability`.

## Owner authority

Registration is a *proposal*, not a guarantee of publication. A site owner can suppress any resource id; suppressed ids are filtered out at the very top of `Envelope::build()`, so they never appear in `apis[]`, `agents[]`, `capabilities`, **or** `resources[]` — every served surface. This publication boundary is the "a provider proposes, the owner disposes" rule of the spec. Design your integration so a suppressed resource degrades cleanly.

## Key extension filters

Beyond registration, the engine's output is shaped by filters (all documented with signatures in `examples/all-hooks-reference.php`). The most relevant to protocol integration:

| Filter | Shapes |
|---|---|
| `agentimus_envelope` | The whole assembled `discovery.json` (add `x-<vendor>` keys). |
| `agentimus_schema_url` | The `$schema` URL. |
| `agentimus_documents` | The `documents` map. |
| `agentimus_well_known_specs` | Label a `/.well-known` name with its governing standard. |
| `agentimus_well_known_routed` / `agentimus_well_known_nested` | Route your own flat / nested well-known names. |
| `agentimus_mcp` / `agentimus_mcp_card_server` | The MCP descriptor and which server the card describes. |
| `agentimus_agent_skills` | The Agent Skills index. |
| `agentimus_signed_surfaces` | Which docs the Web Bot Auth signer signs. |

See the **Hooks & filters reference** page for the complete surface.

## The spec and JSON Schema

The wire format is described by a published JSON Schema. Agentimus stamps its URL into every `discovery.json` as `$schema`:

```
https://heera.github.io/wp-discovery-protocol/schemas/discovery/1.0/discovery.schema.json
```

The base (`Envelope::SCHEMA_BASE`) is `https://heera.github.io/wp-discovery-protocol/schemas`, and the value is overridable per-site via the `agentimus_schema_url` filter (return `''` to omit it), so a future vendor-neutral home can replace it with no release. The `spec_version` string a document carries (`"1.0"`) is what a consumer selects its parser on; additive, backward-compatible changes never bump it.

{: .tip }
> Validate a live document against the schema in CI: fetch `/.well-known/discovery.json`, resolve its `$schema`, and run any JSON Schema validator. Pair that with the `/wp-json/agentimus/v1/validate` endpoint to catch both malformed registrations and envelope drift.
