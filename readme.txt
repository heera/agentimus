=== Agentify ===
Contributors: heera
Tags: llms-txt, ai-crawlers, schema, robots-txt, ai-agents
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Control which AIs may train on your content, see which AI bots read your site, and serve clean machine-readable pages. Lightweight, no SEO bloat.

== Description ==

Agentify is a lightweight toolkit for the AI era. **Control** which AI crawlers may train on your content, **see** which AI agents are actually reading your machine-readable endpoints, and **serve** clean, machine-readable versions of your pages — with no heavy SEO suite. A one-screen readiness report shows how machine-readable your site is and what's still missing.

It makes no outbound requests, collects no analytics, and logs no IP addresses. Everything runs on your own site.

**Control — who may use your content**

* **robots.txt content-signals + AI-training blocklist** — declare your content-usage policy and block named model-training crawlers (GPTBot, CCBot, ClaudeBot, Google-Extended, Bytespider, …) by name, while leaving read/cite bots free.

**Visibility — who is reading you**

* **Agent activity log** — a dashboard of which AI crawlers and agents actually fetch your content and endpoints (GPTBot, Claude, Perplexity, Googlebot, …), recorded first-party in your own database, with no IP logging.

**Content — clean, machine-readable output**

* **Markdown delivery** — request any page as clean markdown by appending `.md` to its URL (or, where your server allows it, with an `Accept: text/markdown` header).
* **/llms.txt** & **/llms-full.txt** — an [llmstxt.org](https://llmstxt.org) index of your pages, topics and recent posts, plus a full-text edition an agent can ingest in a single request.
* **JSON-LD** — WebSite + Person/Organization, plus BlogPosting and BreadcrumbList on posts. Automatically **defers to Yoast, Rank Math, SEOPress, AIOSEO and The SEO Framework** so you never ship duplicate schema.

**Readiness report**

* A one-screen score of how machine-readable your site is, with a plain-English checklist of what's enabled and what's still missing.

**Machine discovery (forward-looking)**

Agentify also publishes a single, normalized discovery document, built to the conventions the agent ecosystem is converging on (the `.well-known` convention, A2A agent cards, MCP-shaped tools). It puts a site's identity, capabilities and APIs in one predictable place:

* **/.well-known/discovery.json** — an owner-curated document describing the site's identity, capabilities, APIs and agent cards. Other plugins can declare themselves through a single optional hook, so what an agent needs is aggregated in one place.
* **/.well-known/agent-card.json** and **/.well-known/mcp.json** — an A2A agent card and an MCP manifest, generated automatically.
* **WordPress Abilities API → MCP tools** — registered abilities are projected into MCP-shaped tool descriptors, and a running MCP server (if one is installed) is detected and linked. Agentify advertises tools; it does not execute them.
* **Zero-config auto-discovery** — reads your registered REST API namespaces, public post types and the WordPress Abilities API, so a site is described even when no plugin declares itself. A **Discovery Hub** admin screen shows what an agent can see, and you decide what is published.

**What's read today vs. what it readies you for**

Honest framing: the content signals above (JSON-LD, robots, llms.txt, markdown) are read by search engines and AI tools **today**. The discovery document is **forward-looking and standards-aligned** — it prepares your site for AI agents as they adopt these conventions, rather than claiming every agent already reads it. The discovery format is an open, openly-licensed convention with a public reference, not a private one, and the plugin works fully whether or not anything consumes that document.

**Why it's useful**

Most tools cover one slice — an llms.txt file, an AI-bot blocker, or structured data. Agentify brings content control, agent-traffic visibility, clean machine-readable output and a forward-looking discovery document together in one coherent, lightweight package — and tells you what's still missing.

== Installation ==

1. Upload the `agentify` folder to `/wp-content/plugins/`, or install via Plugins → Add New.
2. Activate the plugin.
3. Open **Agentify** in the admin menu, fill in your Identity (name, profile sentence, expertise, profile links) and review the readiness report.

== Frequently Asked Questions ==

= Does Agentify make external requests or send my data anywhere? =

No. Agentify makes no outbound HTTP requests — nothing is sent to any external service, and no analytics or telemetry are collected. The agent-activity log is stored in your own database with no IP addresses. The discovery document includes a `$schema` value that *identifies* the document format (the same way a schema.org URL identifies a vocabulary); it is a label in the output, never fetched.

= Does this conflict with my SEO plugin? =

No. JSON-LD output automatically stands down when Yoast, Rank Math, SEOPress, AIOSEO or The SEO Framework is active, so structured data is never duplicated. The other endpoints (llms.txt, markdown) don't overlap with SEO plugins.

= My robots.txt rules aren't showing. =

If a static `robots.txt` file exists at your site root, or your CDN serves its own, it overrides WordPress's virtual robots.txt. The readiness report flags this. Remove the static file to let Agentify manage the rules.

= Will it slow my site down? =

No. The text endpoints are cached and CDN-friendly; there is no front-end JavaScript or CSS. The admin app loads only on the plugin's own screen.

= Does it expose anything private, or let agents change my site? =

No. Agentify only describes what your site already makes public; it grants no new access. Removing or suppressing an item changes what is *advertised*, not what is reachable — the underlying endpoints behave exactly as before, behind their own authentication.

= How do I make my plugin appear in the discovery document? =

Add a single optional action — no dependency, no library. If Agentify isn't installed the hook simply never fires:

`add_action( 'wpdiscovery_register', function ( $registry ) {`
`    $registry->register( array( 'id' => 'acme', 'title' => 'Acme', 'type' => 'commerce' ) );`
`} );`

Agentify also fires the product-aliased `agentify_discovery_register`; you may hook either. See `examples/integrate-your-plugin.php` for the full resource schema (capabilities, endpoints, auth, agent cards, MCP tools).

== Screenshots ==

1. The Agentify dashboard with the readiness score and agent-activity log.
2. The settings screen — identity, features, crawler policy and content types.
3. The readiness report.
4. The Discovery Hub — what an agent can see, and what you publish.

== Source & build ==

There is no minified-only code. The admin interface is built from Vue 3 source in `resources/` with Vite; the source and `vite.config.js` ship in this package and also live in the public repository at https://github.com/heera/agentify . Run `npm install && npm run build` to regenerate `assets/admin/` from source.

== Changelog ==

= 1.0.0 =
* /llms.txt, /llms-full.txt, markdown delivery, JSON-LD, robots content-signals, and a readiness report.
* Agent-activity log — first-party, no IP logging.
* Machine discovery document at /.well-known/discovery.json, with an optional registration hook (`wpdiscovery_register`) for plugins to declare capabilities, APIs and agent cards. You control what is published.
* MCP & tools: projects the WordPress Abilities API into MCP-shaped tool descriptors, plus /.well-known/mcp.json and agent-card.json. Zero-config auto-discovery of REST namespaces and public post types.
* Admin Discovery Hub for inspecting what agents can see, with per-item publish/suppress control.
