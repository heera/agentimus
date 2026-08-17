---
title: Integrate your plugin
parent: Developer Reference
nav_order: 5
---

This page is for **companion plugin authors**: you ship a plugin, and you want its content, resources, endpoints and tools to show up in the machine-readable surfaces Agentimus already publishes for the site — `/.well-known/discovery.json`, `/.well-known/mcp.json`, `/.well-known/agent-card.json`, `llms.txt`, `llms-full.txt`, per-post Markdown, and the per-post "Topics for AI".

Everything here is a plain WordPress hook. There is **no library to bundle and no hard dependency**: if no `WP_Discovery` engine (such as Agentimus) is active, the action never fires and the filters are never applied, so your integration code is completely inert.

The two runnable references this page is built from ship in the plugin:

- `examples/integrate-your-plugin.php` — the registration path and the full resource schema.
- `examples/all-hooks-reference.php` — every hook, grouped by stability tier.

## How registration works

Agentimus builds a single `Registry` per request and fires two actions during collection:

```php
// inc/Discovery/Registry.php — collect()
do_action( 'wpdiscovery_register', $registry ); // canonical, vendor-neutral
do_action( 'agentimus_register', $registry );   // product-branded back-compat alias
```

Hook **only one** of them — `wpdiscovery_register` is the canonical name to build on. The `$registry` passed to your callback is an instance of `Agentimus\Discovery\Registry`; you call `$registry->register( array $resource )` on it. Collection is idempotent and runs lazily from every output endpoint, so you never worry about timing.

There are two equivalent ways to register, and you can mix them freely:

| Path | When it fires | Guard needed? |
|------|---------------|---------------|
| `add_action( 'wpdiscovery_register', … )` | Only if a discovery engine is active | No — the action simply never fires otherwise |
| `Agentimus_Discovery::register( … )` | Buffered immediately, drained at collection | Yes — wrap in `class_exists( 'Agentimus_Discovery' )` because the call is direct |

The facade (`Agentimus_Discovery`) is a dependency-free static queue. Calls made before the registry has run are buffered and drained during collection, so you can register from anywhere without caring about load order.

### The minimal integration

This is the entire thing — three fields:

```php
add_action(
	'wpdiscovery_register',
	function ( $registry ) {
		$registry->register(
			array(
				'id'    => 'acme-bookings', // unique lowercase slug
				'title' => 'Acme Bookings',
				'type'  => 'scheduling',    // controlled vocabulary
			)
		);
	}
);
```

### The facade alternative

Identical result, direct call — guard it:

```php
if ( class_exists( 'Agentimus_Discovery' ) ) {
	Agentimus_Discovery::register(
		array(
			'id'    => 'acme-bookings',
			'title' => 'Acme Bookings',
			'type'  => 'scheduling',
		)
	);
}
```

## Register your resource

`register()` validates and normalises immediately. It returns `true` on success or a `WP_Error` describing the first fatal field — and any rejection also lands in the admin **Discovery Hub → Validation** panel with the reason, so your integration fails loudly rather than silently. Unknown keys are dropped with a warning.

### Field reference

| Field | Required | Notes |
|-------|----------|-------|
| `id` | Yes | Unique lowercase slug matching `^[a-z0-9](-?[a-z0-9]+)*$`. A duplicate id logs a warning and the later registration wins. |
| `title` | Yes | Human label. |
| `type` | Yes | One of the controlled vocabulary below, an `x-<vendor>-<name>` extension token, or a kind you declared yourself (see below). |
| `description` | | Short string. |
| `version` | | Your plugin version. |
| `capabilities` | | `string[]` of dot-notation **intent** verbs, e.g. `scheduling.booking.create`. Folded into the site-wide capability union. |
| `endpoints` | | Array of `{ url, type, methods[], auth, description }`. Site-relative URLs are fine; they are absolutized on output. |
| `schemas` | | `string[]` of URLs to OpenAPI / JSON-Schema / GraphQL SDL. |
| `auth` | | `{ type, oidc, scopes[], docs }`. |
| `agent` | | A2A agent card `{ name, description, skills[{id,description}], endpoint, auth }` → surfaces in `agent-card.json`. |
| `abilities` | | `string[]` of WP Abilities API names this resource fulfils. |
| `tools` | | Array of MCP tool definitions (see below). |
| `well_known` | | References the envelope lists (serving a doc is a separate call — see further down). |
| `docs` | | A documentation URL. |
| `provider` | | **Auto** — derived from the calling file's location and overwrites anything you pass. Do not set it. |

**`type` controlled vocabulary:** `content`, `commerce`, `scheduling`, `courses`, `forms`, `crm`, `auth`, `search`, `media`, `messaging`, `analytics`, `payments`, `directory`, `agent`.

**Need a kind that isn't there?** Declare it, and it is published exactly as you wrote it:

```php
add_filter( 'agentimus_resource_types', function ( $types ) {
	$types[] = 'loyalty';
	return $types;
} );
```

Or use an `x-<vendor>-<name>` token (e.g. `x-acme-loyalty`) without declaring anything.

If you use a word nobody declared, **your resource is still published** — the word is marked as an extension (`community` becomes `x-community`) and a warning on **Discovery Hub → Registration Status** names the word, what it became, and this filter. You never lose an entry over one word.

**`endpoints[].type`:** `rest`, `graphql`, `mcp`, `openapi`, `a2a`, `soap`, `rpc`.

**`auth.type`:** `none`, `apikey`, `basic`, `oauth2`, `oidc`, `custom`.

### A realistic registration

Capabilities describe **what** you can do; the concrete paths live only in `endpoints` and `tools`.

```php
add_action(
	'wpdiscovery_register',
	function ( $registry ) {
		$registry->register(
			array(
				'id'           => 'acme-bookings',
				'title'        => 'Acme Bookings',
				'type'         => 'scheduling',
				'description'  => 'Appointment booking, availability and calendars.',
				'version'      => defined( 'ACME_VERSION' ) ? ACME_VERSION : '',

				// Dot-notation INTENT — folded into the site-wide capability union.
				'capabilities' => array(
					'scheduling.availability.read',
					'scheduling.booking.create',
					'scheduling.booking.cancel',
				),

				// WHERE. type: rest | graphql | mcp | openapi | a2a | soap | rpc
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

				// HOW to authenticate. type: none | apikey | basic | oauth2 | oidc | custom
				'auth'         => array(
					'type'   => 'apikey',
					'docs'   => 'https://example.com/api/auth',
					'scopes' => array( 'bookings:write' ),
				),

				// Optional A2A agent card → /.well-known/agent-card.json.
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
			)
		);
	}
);
```

### Declarative MCP tools

The `tools` array on a resource describes MCP-shaped tool **definitions**. They flatten into the site's `tools[]` and `/.well-known/mcp.json` so an agent learns the tools exist and how they are shaped — this is advertising, not execution. Each entry mirrors the MCP `tools/list` shape:

```php
$registry->register(
	array(
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
					'properties' => array(
						'service_id' => array( 'type' => 'integer' ),
					),
				),
				'annotations' => array( 'readOnlyHint' => true ),
				'auth'        => 'apikey',
			),
		),
	)
);
```

`name` must match `^[a-z0-9][a-z0-9_.-]*(/[a-z0-9][a-z0-9_.-]*)?$`. `annotations` accepts the MCP behaviour hints (`readOnlyHint`, `destructiveHint`, `idempotentHint`…) coerced to booleans. `auth` defaults to `none`.

{: .note }
> These declarative MCP tools are different from the **live, in-browser WebMCP tools** covered later on this page. Declarative tools describe a server-side capability in the discovery documents; WebMCP tools are actually callable from a browsing agent.

## Add your post type to llms.txt

By default Agentimus exposes only posts and pages — never every public custom post type, which would leak content. Add your own type so it gets its own section in `llms.txt` (and, when enabled, `llms-full.txt`).

Filter `agentimus_post_types` with the resolved list and the full set of available public types:

```php
/**
 * @param string[] $types     Selected post types.
 * @param string[] $available All public post types.
 */
add_filter(
	'agentimus_post_types',
	function ( $types, $available ) {
		if ( in_array( 'acme_product', $available, true ) ) {
			$types[] = 'acme_product';
		}
		return $types;
	},
	10,
	2
);
```

Guarding on `$available` matters: only public post types can be exposed, and the resolved list is de-duplicated and emptied of blanks after your filter runs.

### Attribute the section to your plugin

Agentimus figures out a post type's source label at runtime from the registering plugin's own header `Name`, so nothing is hardcoded. When that detection can't identify you — or you want a specific label to disambiguate two plugins that both call their type "Products" — set it explicitly with `agentimus_post_type_source`:

```php
/**
 * @param string $source    Vendor label ('' = none).
 * @param string $post_type Post type slug.
 */
add_filter(
	'agentimus_post_type_source',
	fn( $source, $post_type ) => 'acme_product' === $post_type ? 'Acme' : $source,
	10,
	2
);
```

## Supply rendered HTML for page-builder content

Agentimus renders a post to Markdown by running its `post_content` through WordPress core's `the_content` filter. Page builders (Elementor, Beaver Builder, Bricks…) and other custom renderers often keep their real content out of `post_content`, so that default yields an empty or stub body.

Short-circuit it with `agentimus_markdown_source`. Return a rendered HTML string to override; return `null` to let Agentimus render the post the normal way.

**Elementor and Beaver Builder need no filter** — Agentimus detects a page those builders own and asks the builder's own render API for the real body, on this same seam at priority 20. An explicit provider you register at the default priority (10) always wins over the built-in one. To teach Agentimus a builder it doesn't know (detection plus, optionally, a render door), add an entry to the `agentimus_page_builders` table — see the hooks reference.

```php
/**
 * @param string|null $html Pre-rendered HTML, or null.
 * @param \WP_Post     $post The post.
 */
add_filter(
	'agentimus_markdown_source',
	function ( $html, $post ) {
		if ( 'acme_page' === $post->post_type ) {
			return acme_builder_render( $post->ID ); // your builder's HTML
		}
		return $html; // null → Agentimus uses the_content
	},
	10,
	2
);
```

This filter feeds both the per-post Markdown output and the `llms-full.txt` full-text edition. It is also consulted to decide whether a post *has* a body at all — so returning HTML here keeps a builder-only post from becoming a title-only stub in the full-text edition, instead of being dropped.

## Add your taxonomy to topic derivation

A post's auto-derived "Topics for AI" become schema.org `keywords` and `about` entries and appear in `llms.txt`. Core reads only the two WordPress built-ins — `category` and `post_tag`. Declare your own taxonomies with `agentimus_derive_taxonomies` so their terms are offered as topics wherever your content type is agent-visible:

```php
/**
 * @param string[] $taxonomies Taxonomy slugs.
 * @param \WP_Post  $post       The post being described.
 */
add_filter(
	'agentimus_derive_taxonomies',
	function ( $taxonomies, $post ) {
		if ( 'product' === $post->post_type ) {
			$taxonomies[] = 'product_cat';
			$taxonomies[] = 'product_tag';
			$taxonomies[] = 'pa_brand'; // a global product attribute
		}
		return $taxonomies;
	},
	10,
	2
);
```

Two things to know:

- Each taxonomy you add is intersected against the post type (`is_object_in_taxonomy`), so listing a taxonomy a post type doesn't have is harmless — it's ignored.
- Your terms flow through the **exact same path** as the core ones: the editor's per-post derive toggle, the `agentimus_topic_exclude` deny-list, case-insensitive de-duplication, and the per-item cap. That's the point of the filter — you hand Agentimus your data without any vendor-specific code living in the plugin.

If your topics are **not** taxonomy terms (e.g. computed from post meta), skip this filter and use `agentimus_post_topics` instead, which lets you add or refine the final resolved list directly. Both are documented in full on the *Customizing topics & schema* page.

## Register read-only WebMCP tools

WebMCP is an experimental, opt-in bridge that registers the site's tools with **in-browser** AI agents via the `navigator.modelContext` browser API. It is the client-side companion to the server-side discovery documents: the same capabilities, but exposed where a *browsing* agent can call them live.

It is off unless the owner enables it (the `enable_webmcp` setting). When enabled, Agentimus ships a tiny footer script that is inert in any browser without `navigator.modelContext` (nearly all of them today), so human visitors see and pay nothing.

Add your tools with `agentimus_webmcp_tools`:

```php
/**
 * @param array<int,array> $tools    Tool definitions.
 * @param \Agentimus\Settings $settings Settings store.
 */
add_filter(
	'agentimus_webmcp_tools',
	function ( $tools, $settings ) {
		$tools[] = array(
			'name'        => 'acme_list_services',
			'description' => 'List bookable services on this site.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'category' => array(
						'type'        => 'string',
						'description' => 'Optional category slug to filter by.',
					),
				),
			),
			'endpoint'    => rest_url( 'acme/v1/services' ),
			'method'      => 'GET',
		);
		return $tools;
	},
	10,
	2
);
```

Each WebMCP entry needs: `name`, `description`, `inputSchema` (JSON Schema), `endpoint` (a URL), and `method` (`GET` or `POST`). Entries missing `name` or `endpoint` are dropped so a malformed tool can never reach the page.

{: .warning }
> **Anonymous visitors get READ-ONLY tools only.** `execute()` runs in the visitor's own browser session, so a write tool would act as whoever is logged in. Only expose safe, read-only operations here (the built-in baseline is a `search_site` tool over the core search REST route).

The owner curates which tools are exposed per-tool in Settings (the `webmcp_hidden_tools` deny-list); tools are exposed by default and stay exposed unless explicitly turned off.

## Cede a surface with yield_surface

If your plugin or theme already emits one of the agent-readiness surfaces itself — its own `llms.txt`, Markdown, robots rules or `Link` headers — tell Agentimus to stand down for that surface rather than have both of you produce it. This is the documented way to coexist: one line, using the public API instead of sniffing for the plugin.

```php
/**
 * @param bool   $yield   Whether Agentimus should stand down. Default false.
 * @param string $surface One of: llms_txt | llms_full | markdown | link_headers | robots.
 */
add_filter(
	'agentimus_yield_surface',
	function ( $yield, $surface ) {
		// My theme already serves these — let it.
		return in_array( $surface, array( 'llms_txt', 'markdown' ), true ) ? true : $yield;
	},
	10,
	2
);
```

The five surface keys:

| Surface | What Agentimus stops emitting |
|---------|-------------------------------|
| `llms_txt` | `/llms.txt` |
| `llms_full` | `/llms-full.txt` |
| `markdown` | `.md` URLs, the `Accept: text/markdown` responses, and the Markdown `Link` alternate |
| `link_headers` | The `Link:` HTTP headers (api-catalog, etc.) |
| `robots` | Agentimus's additions to `robots.txt` |

Return `true` for the surfaces you own; return the incoming `$yield` unchanged for the rest.

## Serve your own .well-known document

Beyond registering a resource, you can have Agentimus serve a document under `/.well-known/` on your behalf — routed so it resolves on every host — via `$registry->add_well_known()`. Supply exactly one of `callback`, `redirect` or `file` (a real on-disk file always wins over any of these):

```php
add_action(
	'wpdiscovery_register',
	function ( $registry ) {
		$registry->add_well_known(
			array(
				'name'         => 'security.txt', // → /.well-known/security.txt
				'content_type' => 'text/plain',
				'callback'     => function () {
					return "Contact: mailto:security@example.com\n";
				},
			)
		);
	}
);
```

The first provider to claim a name keeps it; a later conflicting claim is rejected with a validation warning.

## Reacting to Agentimus lifecycle events

Two more hooks help a companion or Pro add-on sit alongside the core cleanly:

```php
// Run after the plugin finishes booting — register your own features against
// the shared instance ($plugin is \Agentimus\Plugin).
add_action( 'agentimus_booted', function ( $plugin ) { /* … */ } );

// Run after Agentimus regenerates its documents (llms.txt, discovery.json, …) —
// e.g. purge your CDN / page cache. Fires once per flush, debounced.
add_action( 'agentimus_cache_flushed', function () { /* my_cdn_purge( … ); */ } );
```

## Where to go next

- **WP_Discovery Protocol** — the open registration contract and the full resource schema semantics.
- **Hooks & filters reference** — every extension point, grouped by stability tier (`[STABLE]`, `[EXTENSION]`, `[INTERNAL]`).
- **Customizing topics & schema** — `agentimus_post_topics`, `agentimus_topic_links`, `agentimus_topic_suggestions`, and the JSON-LD filters.

And the two runnable files that ship in the plugin: `examples/integrate-your-plugin.php` and `examples/all-hooks-reference.php`. Copy the blocks you need; with no discovery engine active, all of it is inert.
