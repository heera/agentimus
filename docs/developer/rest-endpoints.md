---
title: REST & endpoints
parent: Developer Reference
nav_order: 4
---

Agentimus exposes two distinct HTTP surfaces:

1. A **private admin REST API** under the `agentimus/v1` namespace, used by the Vue admin. Every route requires the `manage_options` capability and the standard WordPress REST nonce (`X-WP-Nonce` / `apiFetch`).
2. A **public, unauthenticated discovery surface** — the generated `/.well-known/*` documents plus the front-end agent files (`/llms.txt`, `.md` twins, the fallback sitemap, `security.txt`). These are served for anonymous agents and crawlers, with permissive CORS.

This page documents both, with exact routes, parameters, response shapes, content types, and CORS behaviour. Everything below is derived from `inc/Rest.php`, `inc/Endpoints.php`, `inc/Discovery/*` and `inc/Sitemap.php`.

## Admin REST API (`agentimus/v1`)

### Base URL, auth and registration

All admin routes live under the `agentimus/v1` namespace (`Agentimus\Rest::NAMESPACE`), so the base is:

```
/wp-json/agentimus/v1/<route>
```

Routes are registered on `rest_api_init` by `Agentimus\Rest::register()`. Every callback is gated by `permission_callback => can_manage()`, which is simply `current_user_can( 'manage_options' )`. Because the admin uses cookie authentication, requests must also carry a valid REST nonce. From the admin bundle this is transparent — `apiFetch` sends `X-WP-Nonce` automatically. From an external script you must send the nonce yourself:

```bash
curl -H "X-WP-Nonce: <nonce>" \
     --cookie "<wordpress-admin-cookies>" \
     https://example.com/wp-json/agentimus/v1/settings
```

The nonce is minted with `wp_create_nonce( 'wp_rest' )`.

### GET / POST `/settings`

Read or persist the plugin settings.

**`GET /settings`** takes no parameters and returns both the current settings and a fresh readiness report:

```json
{
  "settings":  { "enable_llms_txt": true, "enable_schema": true, "...": "..." },
  "readiness": [ { "id": "...", "label": "...", "status": "pass", "detail": "...", "fix": "...", "action": null } ]
}
```

`settings` is the full sanitized option array (`Settings::all()`). `readiness` is the same array returned by `GET /readiness` (see below).

**`POST /settings`** (`WP_REST_Server::EDITABLE`, i.e. POST/PUT/PATCH) saves settings. The handler is deliberately lenient about the request envelope — it accepts any of these bodies:

- a bare JSON object of settings: `{ "enable_schema": false }`
- a wrapped object: `{ "settings": { "enable_schema": false } }`
- form params under a `settings` key

The input is passed through `Settings::update()`, which sanitizes and merges against defaults, then the response echoes the **saved** (re-read) values plus a fresh readiness report and the **recomputed AEO/GEO score** (so the admin's score card refreshes live on save without a reload):

```json
{ "settings": { "...": "..." }, "readiness": [ ... ], "score": { "score": 99, "band": "Excellent", "rungs": [ ... ], "content": [ ... ], "...": "..." }, "saved": true }
```

The `score` object is `Agentimus\Score::report()` — the blended 0–100 `score`, its `band`, the `rungs` array (Findable/Readable/Trusted/Optimized, plus Cited when `enable_visibility` is on), the per-page `content` worklist and the `ignored` (set-aside) list. It is `null` if the score can't be computed.

Partial updates are supported for most flags, but note that boolean feature toggles have specific merge semantics in `Settings::update()` (some absent keys reset to default, others are treated as "off"); send the full settings object if you need deterministic results.

### POST `/settings/reset`

Restores factory defaults via `Settings::reset()`. No parameters. The onboarding flag lives in its own option, so a reset does **not** re-trigger the setup wizard.

```json
{ "settings": { "...factory defaults..." }, "readiness": [ ... ], "reset": true }
```

### POST `/onboarding`

Marks the first-run setup wizard complete (or skipped) so it never shows again. It writes `update_option( 'agentimus_onboarded', AGENTIMUS_VERSION )` — a separate option from the settings, so a factory reset will not resurrect the wizard. No parameters.

```json
{ "onboarded": true }
```

### GET `/readiness`

Returns the readiness report (`Agentimus\Readiness::report()`) — the same array embedded in the `/settings` responses. It is a flat list of check rows, each normalized to:

| Field    | Type                          | Meaning                                                        |
|----------|-------------------------------|---------------------------------------------------------------|
| `id`     | string                        | Stable check id (also used as a UI anchor).                   |
| `label`  | string                        | Human label.                                                  |
| `status` | `"pass"` \| `"warn"` \| `"fail"` | Outcome.                                                    |
| `detail` | string                        | One-line explanation of the current state.                   |
| `fix`    | string                        | Guidance on how to resolve a `warn`/`fail` (may be empty).   |
| `action` | object \| null                | Optional UI action descriptor (e.g. a deep link to Settings). |

The set of checks is filterable via `agentimus_readiness_checks` (a Pro add-on can append its own rows), so integrators should treat the list as open-ended and key off `id`.

### GET `/score`

Returns the composite AEO/GEO score (`Agentimus\Score::report()`), recomputed on request:

```json
{ "score": { "score": 92, "rungs": [ … ], "next": { … }, "actions": [ … ] } }
```

Requires `manage_options`. This is the same object the admin page embeds in its bootstrap and returns from a settings save — the route exists so the score can be re-read **without a page load**.

That matters more than it sounds. The score is computed partly from your *content*, and content is edited in the post editor — usually another browser tab. Before this route, an open admin screen had no way to hear about it: the score card kept showing a stale figure and a "next step" naming a page the owner had already fixed, and only a full page reload cured it. The admin now re-reads it when the tab regains focus (throttled), when Refresh is pressed on the dashboard, and when Readiness is re-run.

The per-page sample behind the *Optimized* rung is cached (`Cache::OPTIMIZE`) and busted on content change, so calling this route repeatedly is cheap — a request after a content edit pays for one recompute, the rest are served from cache.

### GET `/preview/schema`

Returns the exact JSON-LD `@graph` the front end would emit for the site, or for a chosen post — regardless of whether schema output is currently active. This is a preview tool: it shows what **would** ship even when schema is disabled or ceded to an SEO plugin.

**Parameters**

| Name   | In    | Type    | Default | Notes                                                        |
|--------|-------|---------|---------|--------------------------------------------------------------|
| `post` | query | integer | `0`     | `absint`-sanitized. `0`/absent → the site-wide identity graph. A non-zero id previews that post's node. |

A post id that does not exist, or whose post type is not in the agent-visible selection (`Content::post_types()`), returns `WP_Error` `agentimus_preview_not_found` with HTTP 404.

**Response shape**

```json
{
  "active":       false,
  "reason":       "seo_plugin",
  "seoPlugin":    true,
  "target":       { "type": "post", "id": 42, "label": "Hello world", "url": "https://example.com/hello-world/" },
  "postIncluded": true,
  "postNote":     "",
  "livePublic":   true,
  "graph":        { "@context": "https://schema.org", "@graph": [ ... ] },
  "json":         "{ pretty, slash-unescaped JSON string }"
}
```

Key fields:

- **`active`** — `true` only when `enable_schema` is on **and** no SEO plugin owns schema output.
- **`reason`** — `"disabled"`, `"seo_plugin"`, or `"ok"`; lets the UI explain an empty live `<head>`.
- **`target`** — `type` is `"site"` or `"post"`; carries the label and public URL.
- **`postIncluded`** — `false` for a password-protected post (its body is never exposed as schema — only the site-level nodes ship).
- **`postNote`** — human note for draft/pending/scheduled ("not yet live") or password-protected targets.
- **`livePublic`** — whether the target is reachable at a public URL right now (published and non-gated), which gates URL-based validators.
- **`graph`** — the JSON-LD document array (or `null` when there is nothing to emit).
- **`json`** — the same graph as a pretty, slash-unescaped string for reading/validating (the live `<head>` escapes slashes for safe embedding; the data is identical).

An unpublished post is previewed with its *would-be* per-post node (built via `Schema::build_document( $post, false, true )`), flagged by `postNote`.

### GET `/preview/markdown`

Returns the Markdown a page/post is served as — the `.md` twin / the `Accept: text/markdown` response. The site target (`post = 0`) returns the **site index** Markdown (`LlmsText::index_markdown()`) — the same document served at the home URL (`/index.md`) and at `/llms.txt`.

**Parameters**

| Name   | In    | Type    | Default | Notes                                                       |
|--------|-------|---------|---------|-------------------------------------------------------------|
| `post` | query | integer | `0`     | `absint`-sanitized. `0`/absent returns the site-index Markdown. A non-zero id returns that post's Markdown. |

A non-existent or out-of-scope post id returns `WP_Error` `agentimus_preview_not_found` (HTTP 404), same as the schema preview.

**Response shape**

```json
{
  "active":       true,
  "reason":       "ok",
  "target":       { "type": "post", "id": 42, "label": "Hello world", "url": "https://example.com/hello-world/" },
  "postIncluded": true,
  "postNote":     "",
  "livePublic":   true,
  "markdown":     "# Hello world\n\n...",
  "mdUrl":        "https://example.com/hello-world.md"
}
```

- **`active`** / **`reason`** — whether Markdown delivery (`enable_markdown`) is switched on (`"ok"` or `"disabled"`).
- Markdown is genuine served content (no "would-be" preview): a draft yields `postIncluded: false` and a `postNote` saying nothing is served yet; a password-protected post yields `postIncluded: false` and is never served as Markdown.
- **`mdUrl`** mirrors the front-end resolution — the permalink with `.md` appended (`untrailingslashit( $permalink ) . '.md'`).
- For `post = 0`, the response returns the site target with `postIncluded: true`, the index Markdown in `markdown`, and `mdUrl` set to the home URL with `/index.md` appended (`home_url( '/index.md' )`).

### GET `/preview/targets`

Returns the pages and posts the preview can describe — the in-scope content, most-recently-modified first, optionally filtered by title.

**Parameters**

| Name     | In    | Type   | Default | Notes                                              |
|----------|-------|--------|---------|----------------------------------------------------|
| `search` | query | string | `""`    | `sanitize_text_field`; passed to `WP_Query`'s `s`. |

The query is limited to `Content::post_types()`, statuses `publish, private, draft, pending, future`, 50 rows, ordered by `modified DESC`.

**Response shape**

```json
{
  "targets": [
    {
      "id": 42,
      "type": "post",
      "typeLabel": "Posts",
      "label": "Hello world",
      "status": "publish",
      "url": "https://example.com/hello-world/"
    }
  ]
}
```

### AI writing assist (`/suggest`, `/suggest-fix`)

Registered in `inc/Assist.php` under `agentimus/v1`. They back the editor's "Draft with AI" / "Fix with AI" buttons by routing a prompt through WordPress's AI Client (`wp_ai_client_prompt()`, WordPress 7.0+) — the plugin never handles the provider key. Both are `POST`, gated per post by `current_user_can( 'edit_post', $post )`, and return `503` when no AI provider is configured.

| Route          | Method | Body                                          | Returns                                                                 |
|----------------|--------|-----------------------------------------------|-------------------------------------------------------------------------|
| `/suggest`     | POST   | `{ post:int, field:"description"\|"topics" }`  | `{ field, text }` (description) or `{ field, topics:string[] }` (topics) |
| `/suggest-fix` | POST   | `{ post:int, check:string }`                  | `{ check, suggestion, apply }` — `apply` is `{ type:"prependParagraph", content }` for the opening-summary fix, otherwise `null` (copy-only) |

`check` is a per-page readability check id; the AI-fixable set is `summary`, `headings`, `passages`, `evidence`, `words`. `field`/`check` are validated in the callback (not as a REST `enum`) so a subscriber is denied by the permission gate before validation. The `agentimus_ai_assist_enabled` filter forces the whole feature off. See the [Write with AI]({% link user-manual/write-with-ai.md %}) guide.

### Abilities & the WordPress admin AI

On WordPress 6.9+ (Abilities API) Agentimus registers its abilities via `wp_register_ability` (in `inc/Abilities/Registrar.php`), so WordPress's built-in AI — and, with the [MCP adapter](https://github.com/wordpress/mcp-adapter) installed, external agents — can use them. There are two tiers:

**Read (always registered), annotated read-only.** Each carries the same capability check as the screen it comes from (`manage_options`, or `edit_post` for the per-post ones):

`agentimus/read-readiness`, `read-findings`, `read-audience`, `read-ai-visibility`, `read-ai-traffic`, `read-request-log`, `read-edge-traffic`, `read-search-visibility`, `read-google-index`, `read-search-performance`, `read-search-opportunities`, `identify-bot`, `check-page`, `preview-schema`, `preview-markdown`, `scan-exposed-files`, `suggest-internal-links`, `search-media`.

**Write (registered only while BOTH `enable_mcp_server` and `enable_agent_writes` are on — off by default; off means the abilities don't exist on any surface):**

`agentimus/create-content`, `update-content`, `write-description`, `write-topics`, `apply-fix`.

The write tools run as the authenticated user through core's own write paths — `create-content` gates on the post type's `create_posts` capability, the per-post tools on `edit_post`, `apply-fix` on `manage_options`; categories/tags follow the taxonomy's `assign_terms`/`edit_terms` caps (free-form tags creatable with assign, new categories need manage — wp-admin's own distinction) and a featured-image URL import needs `upload_files`. `status=publish` additionally requires the `agent_writes_publish` setting plus the user's own publish cap; otherwise writes are draft-first. `update-content` is annotated destructive (a body replacement on a post type without revision support is unrecoverable); `apply-fix` enacts a readiness check's own remediation from a closed vocabulary that can only enable documented features — it will never flip `blog_public` or loosen a protection.

They're reachable over the core Abilities REST API (`/wp-json/wp-abilities/v1/abilities/{name}/run` — GET for read-only abilities), and Agentimus registers a scoped MCP server at `/wp-json/agentimus/v1/mcp` that exposes them when the MCP adapter is present. Trim that set with the `agentimus_mcp_server_abilities` filter.

### Discovery read & validation routes

Registered separately in `inc/Discovery/Module.php`, still under `agentimus/v1`:

| Route              | Method | Auth              | Purpose                                                                 |
|--------------------|--------|-------------------|-------------------------------------------------------------------------|
| `/discovery`       | GET    | **Public** (`__return_true`) | The live discovery envelope — the same array serialized to `/.well-known/discovery.json`. Records an activity hit (`rest:discovery`). |
| `/discovery/hub`   | GET    | `manage_options`  | The admin Discovery Hub payload (`Hub::data()`): resources, adapters, MCP surface, validation notices, counts. |
| `/validate`        | GET    | `manage_options`  | CI-friendly validation: `{ ok, resources, notices }` where `ok` is `false` if any notice has level `error`. |

`GET /wp-json/agentimus/v1/discovery` is the JSON-over-REST equivalent of the well-known document, useful for agents that prefer the WP REST root over `/.well-known/`.

### Other admin route groups

Further groups register under `agentimus/v1`; all require `manage_options` and the REST nonce. They are outside the scope of this page but listed for completeness:

- **Activity** (`inc/Activity/Module.php`): `GET`/`DELETE /activity`, `GET /activity/day` (requires a `date` param, `YYYY-MM-DD`), `GET /activity/ai-day` (same, returning the AI-referral `source → landing page` rows behind the Traffic-from-AI day report), `GET /activity/log`, `POST /activity/block`, `POST /activity/allow`, `POST /activity/dismiss`.
- **Client decisions** (same module, 1.21.0): `GET /activity/clients` returns every standing decision in one payload — `{ blocked: [{token, at, known}], allowed: [...], ignored: [{key, label, at, hits}], settings: {…} }`, where `at` is a unix time (`0` when the decision predates date tracking) and `known` is the recognition catalog's identity for that token. `POST /activity/undismiss` (`key`) forgets one review-queue dismissal; `POST /activity/client-remove` (`token`, `list`: `blocked_agents` or `allowed_agents`) takes a token off a list. Both mutators return the same refreshed payload, so a single response re-renders the whole view.
- **Self-check token** (same module, 1.21.0): `POST /activity/selfcheck-token` mints the short-lived token the readiness screen's own live checks carry in an `X-Agentimus-Selfcheck` header. Those checks fetch the public endpoints with `credentials: 'omit'` — they must grade what an *agent* receives — so the owner-skip can't recognise them by cookie; the token does it instead. Only its SHA-256 hash is stored (a transient, two-minute TTL) and `Activity\Owner::skip()` compares in constant time, so an outsider can neither mint nor replay one to keep itself out of the visit log.

### Connecting an assistant (`/oauth/*`, `/mcp-token`)

Added in 1.32.0. Two of these are **public by protocol** — an unknown client has to be able to introduce itself, and the token exchange authenticates with a code plus a PKCE verifier rather than a session. Both return `404` while the MCP server switch is off, exactly like every other MCP surface.

| Route | Auth | What it does |
|---|---|---|
| `POST /oauth/register` | public (RFC 7591) | Registers a public client from its `client_name` + `redirect_uris`, returns a `client_id` (`agoc_…`) and no secret — PKCE is the proof of possession. A redirect URI must be `scheme://something`; executable schemes (`javascript:`, `data:`, `vbscript:`, `file:`, `blob:`) and anything carrying whitespace or control bytes are refused. Custom schemes (`claude://…`) and loopback callbacks are accepted, because desktop clients register those. |
| `POST /oauth/token` | public (code + PKCE, or refresh) | `grant_type=authorization_code` verifies a single-use 60-second code against its client, redirect URI and S256 challenge, then mints an access token (`agoa_…`, 1 hour) and a refresh token (`agor_…`, 30 days). `grant_type=refresh_token` rotates: the old refresh token and its access token die as the new pair is issued. Errors use the RFC 6749 shape (`{"error": …}`), and the response is `Cache-Control: no-store`. |
| `GET /oauth/grants` | `manage_options` | The Connected-assistants rows: `clientId`, `name`, `host`, `scope`, `approved`, `lastUsed`. Names and timestamps only — never a fingerprint. |
| `DELETE /oauth/grants` | `manage_options` | Revokes ONE client (`clientId`): its registration, codes, access and refresh tokens. Every other connection is untouched. Returns the refreshed list. |
| `GET /mcp-token` | `manage_options` | The shared token's metadata — scope, created, last used, last user agent. Never the secret. |
| `POST /mcp-token` | `manage_options` | Creates or rotates the shared token; the plaintext (`agmcp_r_…` / `agmcp_w_…`) rides this one response and is never retrievable again. Requesting `scope=write` while `enable_agent_writes` is off returns `409` rather than minting a key that could not open anything. |
| `DELETE /mcp-token` | `manage_options` | Revokes it. Every assistant holding it is disconnected at once. |

The browser half is not a REST route: **`/agentimus/connect`** is a rewrite (`Oauth\Consent`), served outside the REST API so a plain cookie session works after the wp-login round-trip. It requires `manage_options`, re-validates the authorize request on POST rather than trusting its own form, and renders validation failures as a page — a request whose `redirect_uri` failed validation is never redirected to.

**How a client finds all this.** An unauthenticated call to `/wp-json/agentimus/v1/mcp` returns `401` with `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource/agentimus/mcp"`. That document names the issuer, whose RFC 8414 metadata carries the authorize, token and registration endpoints. The issuer is path-based (`{site}/agentimus/mcp`) so the well-known documents stay under a path this plugin owns and cannot collide with another plugin's OAuth server on the same site. Not every client reads the `WWW-Authenticate` hint: some (ChatGPT, verified July 2026) derive the metadata URL themselves per RFC 9728 §3.1 — inserting `/.well-known/oauth-protected-resource` between the host and the endpoint's own path — so the SAME document is also served at `…/.well-known/oauth-protected-resource/wp-json/agentimus/v1/mcp`, and a 404 there would read as "does not implement OAuth".

**Scope is enforced twice.** `Registrar::filter_tools_for_scope()` narrows the advertised tool list per request (a read-only key is shown the eighteen read tools, never the five write ones), and each write ability's permission callback is wrapped by both `McpToken::gate_write_permission()` and `Oauth\Server::gate_write_permission()`. Hiding the tools is a courtesy; the gate is the boundary. Neither can exceed the site's own write settings.

### `GET /activity/log`

The filtered, paged request log — the per-request view the dashboard's rollups can't give you (for example: *which endpoints did GPTBot actually fetch?*). All params optional; with none, it returns the newest page of the whole retained window.

| Param | Type | Notes |
|---|---|---|
| `from`, `to` | `YYYY-MM-DD` | Inclusive of both days. A malformed date is a `400`, never a silently-ignored filter. `from` is clamped to the retention floor. |
| `agent`, `endpoint`, `network` | string | Exact match. Combine freely — they AND together. |
| `verdict` | `0`\|`1`\|`2` | unchecked / verified / spoofed. Anything else is a `400`. |
| `ua` | string | **Prefix** match. `KEY ua(191)` can serve `LIKE 'x%'` but never `LIKE '%x%'`, so a contains-search isn't offered rather than offered slowly. Wildcards in the needle are escaped to literals. |
| `before` | int | Keyset cursor — pass the previous response's `cursor`. |
| `per_page` | int | Clamped to 1–200. Default 50. |

Returns `{ rows, total, perPage, cursor, hasMore, retentionDays }`. `total` is the size of the whole filtered set, not the page. `cursor` is `null` on the last page. `retentionDays` is included so a UI can state what it *cannot* show: nothing older than the retained window exists, and on a busy site `agentimus_activity_max_rows` may have discarded rows inside it.

Paging is keyset, not offset: an offset walk costs more on every page and can skip or repeat a row when a crawler inserts mid-walk.

### `GET /activity/log/facets`

Returns `{ agents, endpoints, networks }` — the distinct values present in the retained window, ordered by how often each appears, capped at 200 per list. It exists so the log's filters can be dropdowns: nobody should have to type `Bytespider (ByteDance)` exactly. Values outside retention are omitted, because filtering for one could only ever return nothing.
- **AI Visibility** (`inc/Visibility/Rest.php`, the opt-in monitoring add-on): `GET`/`POST /visibility/config`, `GET /visibility/dashboard`, `POST /visibility/run`, `POST /visibility/test`, `POST /visibility/suggest`, `POST /visibility/suggest-ai`, `POST /visibility/reveal-key`, `POST /visibility/clear`.
- **Optimize** (`inc/Rest.php`): `POST /optimize/ignore` — body `{ post: int, ignored: bool }` sets a page aside from (or restores it to) the Optimized rung's citability grading. Returns the recomputed `score` so the admin updates live.

### Suggesting tracking questions (`/visibility/suggest`, `/visibility/suggest-ai`)

Two routes back the **Suggest questions** / **✦ Suggest with AI** buttons under a tracked item's question list. Both are `POST`, both require `manage_options`, and both take the *unsaved* product row in the body — the owner is still typing, so the row rides in the request rather than being looked up by index. The work is done by `Agentimus\Visibility\Suggest`.

**Body** (both routes):

```json
{
  "name":        "Agentimus",
  "category":    "WordPress SEO plugin",
  "domain":      "agentimus.com",
  "competitors": [ "Yoast", "Rank Math" ],
  "prompts":     [ "best llms.txt plugin for WordPress" ]
}
```

`prompts` is the questions already tracked, sent so the suggestions can be de-duplicated against them. **Response** (both routes): `{ "questions": [ "…", "…" ] }`.

| Route                    | Source                                                      | Cost                          |
|--------------------------|-------------------------------------------------------------|-------------------------------|
| `/visibility/suggest`    | Built-in templates. Pure, no network call, no AI.           | None.                         |
| `/visibility/suggest-ai` | The site's configured AI, via WordPress's AI Client.        | One prompt against the owner's own provider quota. |

`/visibility/suggest-ai` is a separate route rather than a silent upgrade of `/suggest` precisely because it spends AI budget — the caller must ask for it. Its errors:

| Status | Code                       | When                                                                 |
|--------|----------------------------|----------------------------------------------------------------------|
| `400`  | `agentimus_ai_no_category` | No `category` was given. Buyer-intent questions are built *from* the category, so without one the model would invent a market. |
| `503`  | `agentimus_ai_unavailable` | No AI provider is configured (or the prompt failed).                 |

The AI is asked for **unbranded** questions, and any that name the brand are filtered out before the response: a question containing the product's own name is guaranteed to get that name back in the answer, so it measures nothing. If filtering leaves nothing usable, the route **falls back to the template questions** rather than returning an empty list.

`GET /visibility/config` carries a top-level **`aiAvailable`** (bool) so the admin knows whether to render the "✦ Suggest with AI" button at all. It is `Agentimus\Assist::ai_available()` — true when a text-capable AI provider is configured for WordPress's AI Client.

## Public discovery endpoints (`/.well-known/*`)

`inc/Discovery/WellKnown.php` is the single front controller for `/.well-known/*`. It is deliberately **not greedy**: it only routes the names it recognises. Anything else under `/.well-known/` (ACME challenges, other plugins' docs, unknown names) falls through to WordPress's normal handling and 404s untouched.

### How routing works

Two mechanisms bring a request to the router:

1. **Narrow rewrite rules** (registered on `init` by `WellKnown::add_rules()`, flushed on activation) route only the exact names the plugin serves, so requests reach `template_redirect` even on servers that 404 the path at disk level. The routed names come from `WellKnown::routed_names()` — filterable via `agentimus_well_known_routed`.
2. **The 404-intercept path** — a name that is *not* in the rewrite set (e.g. `security.txt`) still reaches `WellKnown::route()` through WordPress's normal 404 template load, and the router serves it if it owns it.

Precedence inside `route()`:

- **A real file on disk always wins.** If `<site-root>/.well-known/<name>` exists (and resolves safely inside the `.well-known` directory), it is streamed as-is via `readfile()` — the plugin never shadows a real file.
- Otherwise, one of the plugin's **generated** documents is emitted.
- Otherwise, a **provider-registered** document (via `Registry::add_well_known()`) is served — as a redirect, a streamed file, or a callback body.
- If the plugin routed the request but produced nothing, `maybe_clean_404()` forces a clean 404 (rather than WordPress's canonical redirect to the homepage).

Site-root resolution is handled by `Agentimus\Paths::site_root()` (the public document root that maps to `home_url()`, which is not always `ABSPATH`).

### The routed documents

All generated documents are served by `WellKnown::send()`, which sets:

- `Content-Type: <type>; charset=UTF-8`
- `X-Content-Type-Options: nosniff`
- `Access-Control-Allow-Origin: *`
- `Cache-Control: public, max-age=3600`

| Path (`/.well-known/…`)                         | Content type                 | Served when                                                     |
|-------------------------------------------------|------------------------------|----------------------------------------------------------------|
| `discovery.json`                                | `application/json`           | Always (cached 1h).                                            |
| `agent-card.json`                               | `application/json`           | Always — the generated A2A agent card.                        |
| `agent.json`                                    | `application/json`           | Always — alias of `agent-card.json`.                          |
| `mcp.json`                                       | `application/json`           | Always — the experimental MCP/tools manifest.                 |
| `openapi.json`                                  | `application/json`           | Always — OpenAPI 3.1 description of the existing public REST read API. |
| `api-catalog`                                   | `application/linkset+json`   | Always — RFC 9727 API catalog as an RFC 9264 link set.        |
| `mcp/server-card.json`                          | `application/json`           | Only when a real MCP server is detected; otherwise 404.       |
| `mcp/{id}/server-card.json`                     | `application/json`           | Per-server card for a tool-bearing server matching `{id}`; else 404. |
| `agent-skills/index.json`                       | `application/json`           | Only when executable skills exist; otherwise 404.             |
| `oauth-protected-resource`                      | `application/json`           | Only when an OAuth authorization server is configured (RFC 9728); else 404. |
| `oauth-authorization-server/agentimus/mcp`      | `application/json`           | Only when the MCP server is on — RFC 8414 metadata for the plugin's OWN authorization server; else 404. |
| `oauth-protected-resource/agentimus/mcp`        | `application/json`           | Only when the MCP server is on — RFC 9728 metadata naming that server as the MCP endpoint's authority; else 404. |
| `oauth-protected-resource/wp-json/agentimus/v1/mcp` | `application/json`       | The same document at the RFC 9728 §3.1 path a client derives from the MCP endpoint URL itself (the REST prefix follows `rest_get_url_prefix()`); clients that skip the `WWW-Authenticate` hint look only here. |
| `tdmrep.json`                                   | `application/json`           | Only when `enable_tdmrep` is on **and** AI training is reserved; else 404. |
| `http-message-signatures-directory`             | `application/json`           | Only when response signing is enabled (the Web Bot Auth key directory); else 404. |
| `security.txt`                                  | `text/plain`                 | Only when opted in, a Contact is configured, and no real file exists (see below). |

### discovery.json

The master index, built by `Agentimus\Discovery\Envelope::build()` and cached for an hour. It is a projection of the registry plus site identity — nothing is hand-maintained. The wire format is frozen at `spec_version` `"1.0"` and carries exactly these top-level keys, in order: `$schema`, `spec_version`, `site`, `identity`, `documents`, `well_known`, `apis`, `agents`, `resources`, `capabilities`, `trust`.

- `documents` maps standard site documents (sitemap, robots, feed, `openapi`, and — when enabled — `llms`/`llms_full` and `changes` (the change feed), plus `humans`/`security` when present) to URLs. Filterable via `agentimus_documents`.
- `well_known` indexes every `/.well-known/*` resource the site exposes (generated, provider-managed, and real files on disk), annotated with the governing spec label.
- The whole envelope is filterable via `agentimus_envelope`; the JSON Schema URL via `agentimus_schema_url`.

Experimental surfaces (the MCP descriptor and tool list) are intentionally **not** in the frozen core — they are served at `/.well-known/mcp.json` and linked from `well_known`.

### Change feed (`/agentimus-changes.json`)

A public JSON feed of recently changed content, served by the front controller (`inc/Endpoints.php`, not `WellKnown`) and advertised as `documents.changes`. Built by `Agentimus\Changes`, gated by the `enable_changes` setting (on by default), with a short `Cache-Control: max-age=300`.

- `GET /agentimus-changes.json` returns the newest window of items (default 200, `agentimus_changes_max`), newest change first.
- `?since=<ISO-8601 or Unix timestamp>` filters to items changed strictly after that instant.
- Each item is `{ id, type, title, url, modified, published, action, markdown, _links }`, where `action` is `created`, `updated` or `deleted` and `_links.rest` points at the canonical `wp/v2` resource. A `deleted` item — a tombstone recorded on unpublish/trash/delete and kept ~90 days (`agentimus_tombstone_retain_days`) — carries just the URL to evict and when.

### agent-card.json / agent.json

The generated A2A agent card (`Envelope::agent_card_json()`, delegated to `WellKnownDocs`). `agent.json` is a straight alias for agents that look there first.

### mcp.json and MCP server cards

This surface does **not** run an MCP server — it discovers and advertises the ones a shared MCP adapter library has registered (`inc/Discovery/McpSurface.php`), Agentimus's own opt-in server at `/wp-json/agentimus/v1/mcp` included. `mcp.json` carries the site identity, the MCP descriptor (`available`, `source`, `endpoint`, `transport`, `auth`, `tools`, `servers`, `status: "experimental"`) and the deduped tool list.

When a live server is detected, standard single-server cards are served at `mcp/server-card.json` (the richest server, pinnable via `agentimus_mcp_card_server`) and, per server, at `mcp/{id}/server-card.json`. With no server, both return an empty body → a clean 404. The MCP descriptor is filterable via `agentimus_mcp`.

### openapi.json

An OpenAPI 3.1 document **describing** the site's existing public read API — WordPress's REST API for the agent-indexed content types (`inc/Discovery/OpenApi.php`). It adds no endpoints and changes no behaviour; it is a machine-readable contract pointing at REST that already exists. One list + single-item read collection is generated per content type that is both agent-indexed and `show_in_rest`. It is also advertised from the HTML `<head>` as `rel="service-desc"`.

### api-catalog, oauth-protected-resource, tdmrep.json

- **`api-catalog`** (RFC 9727, `application/linkset+json`) — the document complement to the `rel="api-catalog"` Link header; points agents at the site's API descriptions.
- **`oauth-protected-resource`** (RFC 9728) — the flat path describes the site as a whole and is served only when the owner has declared an EXTERNAL auth server (`oauth_auth_server`); otherwise absent. It is distinct from the path-suffixed `oauth-protected-resource/agentimus/mcp`, which describes the MCP endpoint and points at the plugin's own built-in server.
- **`tdmrep.json`** — the W3C TDM Reservation Protocol opt-out file (`inc/Tdmrep.php`). Served only when `enable_tdmrep` is on **and** the site reserves its content from AI training (`content_signal.ai_train` is off). An "allow" site serves no file — absence already means "not reserved".

### Response signing (Web Bot Auth)

When signing is enabled (`enable_signing`, on by default but feature-detected against libsodium), the low-volume discovery JSON docs are signed with RFC 9421 `Signature`/`Signature-Input` headers over an RFC 9530 `Content-Digest` (`inc/Discovery/Signer.php`). The Ed25519 public key is published as a JWKS-style directory at `/.well-known/http-message-signatures-directory`. The signed surfaces default to `discovery.json`, `agent-card.json`, `agent.json`, `mcp.json` and are filterable via `agentimus_signed_surfaces` (keep it to JSON docs — never cached HTML/`llms.txt`).

### security.txt

`inc/Discovery/SecurityTxt.php` is the reference example of the "extend, never override" contract. It generates an RFC 9116 `security.txt` (served `text/plain`) **only** when all of the following hold: the feature is opted in (`enable_security_txt`, off by default), at least one Contact is configured, no real `/.well-known/security.txt` file exists, and no other plugin already claimed the slot in the registry. It registers through the public `wpdiscovery_register` action at a late priority (99), so a first-class provider always wins. Because `security.txt` is intentionally absent from `routed_names()`, it is served via the 404-intercept path rather than a rewrite rule — which also means the CORS preflight (below) does **not** answer `OPTIONS` for it, though the actual `GET` still carries `Access-Control-Allow-Origin: *`.

### CORS and preflight

Every generated document (and every streamed on-disk file) carries `Access-Control-Allow-Origin: *` on the `GET`/`HEAD` response — these docs are public by design, so browser-based agents can fetch them cross-origin.

For a strict cross-origin agent that sends a preflight, `WellKnown::route()` answers `OPTIONS` — **but only for names the plugin actually serves** (`serves()` = the routed names, the nested allow-list, or a per-server MCP card path). The preflight (`WellKnown::preflight()`) returns:

```
HTTP/1.1 204 No Content
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, HEAD, OPTIONS
Access-Control-Allow-Headers: *
Access-Control-Max-Age: 86400
Content-Length: 0
```

An `OPTIONS` for a name the plugin does not own is left entirely alone (never shadowed). Only `GET`/`HEAD` are otherwise handled; other methods return without action.

### Advertising the discovery index

`inc/Discovery/Module.php` emits two `Link` headers on every front-end response so an agent can find the entry point from a single header:

```
Link: <https://example.com/.well-known/discovery.json>; rel="service-desc"; type="application/json"
Link: <https://example.com/.well-known/discovery.json>; rel="discovery"; type="application/json"
```

## Front-end agent files

`inc/Endpoints.php` is the front controller for the non-`.well-known` agent files. It routes on `template_redirect` (priority 0), only for `GET`/`HEAD` and never for admin, feed, embed, REST, AJAX or XML-RPC requests. Bodies are emitted by `Endpoints::send()`, which sets `X-Content-Type-Options: nosniff`, `Access-Control-Allow-Origin: *`, and `Vary: Accept`.

### llms.txt and llms-full.txt

| Path             | Content type | Gated by            |
|------------------|--------------|---------------------|
| `/llms.txt`      | `text/plain` | `enable_llms_txt`   |
| `/llms-full.txt` | `text/plain` | `enable_llms_full`  |

Both are stable URLs, so `send()` sets `Cache-Control: public, max-age=3600`. Content is assembled by `Agentimus\LlmsText`. The heavy full-text edition is re-warmed out-of-band after content changes via a debounced WP-Cron event (`agentimus_warm_llms_full`).

### Markdown twins (`.md` and content negotiation)

Agentimus serves a Markdown representation of content two ways, both gated by `enable_markdown` and served as `text/markdown`:

- **Explicit `.md` URLs** — `/<slug>.md`, and `/index.md` (or a bare `/.md`) for the site index. The path is resolved back to a post via `url_to_postid()`; only posts whose type is in scope (`Content::post_types()`) are served, otherwise WordPress 404s normally.
- **`Accept: text/markdown` negotiation** — on a resolved singular view (or the front page/archive/search, which map to the site index).

Because negotiated Markdown shares a URL with the HTML page, **all** Markdown responses are sent with `Cache-Control: no-store, max-age=0` and `Vary: Accept`. The current page's Markdown twin is advertised on singular, in-scope views as a Link header:

```
Link: <https://example.com/hello-world.md>; rel="alternate"; type="text/markdown"
```

### Fallback sitemap

Agentimus never competes for the sitemap — `inc/Sitemap.php` detects the existing one (WordPress core, or a known SEO plugin) and links it. Only when nobody else provides one **and** `enable_sitemap` is on does it serve its own gap-filling sitemap:

| Path                                   | Content type      | Notes                                             |
|----------------------------------------|-------------------|---------------------------------------------------|
| `/agentimus-sitemap.xml`               | `application/xml` | The sitemap index.                                |
| `/agentimus-sitemap-{type}-{page}.xml` | `application/xml` | One sub-sitemap per content type, per page.       |

These are routed by `Endpoints::route()` and served only while `Sitemap::detect()['source'] === 'agentimus'`; any other `/agentimus-sitemap*` path 404s. As stable URLs they are cached (`public, max-age=3600`) and served with the standard `Access-Control-Allow-Origin: *`. Published, password-protected posts are excluded.

### robots.txt, AI-usage signals and head links

`Endpoints` also augments other standard surfaces without clobbering them:

- **`robots.txt`** (via the `robots_txt` filter, priority 20) — injects a `Content-Signal:` directive into the existing `User-agent: *` group, appends a named model-training crawler blocklist, and adds a `Sitemap:` line if none is present. Gated by site visibility + `enable_robots`.
- **AI-usage headers** (`send_headers`, priority 99) — when `enable_ai_header` is on and training is reserved, emits `tdm-reservation: 1` (plus optional `tdm-policy`, and `X-Robots-Tag: noai, noimageai` when `ai_noai_header` is on). These are skipped on the "please read me" surfaces (`llms.txt`, `llms-full.txt`, `robots.txt`, `.md`, `/.well-known/`, feeds).
- **HTML `<head>` links** (`wp_head`, priority 2) — mirrors `rel="describedby"` (`/llms.txt`) and `rel="service-desc"` (`/.well-known/openapi.json`) into the markup for crawlers that read HTML but not headers.
- **Discovery Link headers** (`send_headers`, priority 99) — advertises `rel="api-catalog"` (the REST root), `rel="describedby"` (`/llms.txt`), and the current page's `rel="alternate"` Markdown twin, each de-duplicated against any header a theme already set.

## Coexistence: ceding a surface

Any of the front-end surfaces can be handed to another producer (a theme or plugin that emits its own `llms.txt`, Markdown, robots rules or Link headers) with a single filter, rather than sniffing for the plugin:

```php
add_filter( 'agentimus_yield_surface', function ( $yield, $surface ) {
    // My theme already serves these — let it.
    return in_array( $surface, array( 'llms_txt', 'markdown' ), true ) ? true : $yield;
}, 10, 2 );
```

Valid `$surface` keys are `llms_txt`, `llms_full`, `markdown`, `link_headers`, and `robots`. When a surface is ceded, Agentimus stands down entirely for it — it neither serves the file nor advertises it.
