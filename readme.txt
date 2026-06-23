=== Agentimus ===
Contributors: heera
Tags: ai-agents, ai-crawlers, discovery, schema, llms-txt
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI-discovery layer for your site: a /.well-known discovery document, machine-readable pages, AI-crawl controls, and an agent-activity log.

== Description ==

Agentimus makes your site legible to AI agents and crawlers. At its core it publishes a single, normalized **discovery document** at `/.well-known/discovery.json` — an open, standards-aligned map of your site's identity, capabilities and APIs — and is a reference implementation of that open discovery convention, not a private format. It backs that with the signals agents and search engines read *today*: clean machine-readable pages, JSON-LD, AI-crawl controls, and a first-party log of which agents actually visit. A one-screen readiness report shows how machine-readable your site is and what's still missing.

This is more than an llms.txt generator: llms.txt is one signal among several, sitting under a coherent discovery layer rather than being the whole product.

It makes no outbound requests, collects no analytics, and logs no IP addresses. Everything runs on your own site.

**Control — who may use your content**

* **robots.txt content-signals + AI-training blocklist** — declare your content-usage policy and block named model-training crawlers (GPTBot, CCBot, ClaudeBot, Google-Extended, Bytespider, …) by name, while leaving read/cite bots free.
* **Block scanners & scrapers (opt-in hard block)** — robots rules are a polite request; this enforces them. Turn it on to return 403 to the user-agents on your denylist, and optionally auto-deny agents that disguise themselves as ancient handsets (a classic scanner trick). Protected search engines and anything on your allow-list are never blocked, and `/.well-known/acme-challenge/` (SSL renewal) always stays reachable.

**Visibility — who is reading you**

* **Agent activity log** — a dashboard of which AI crawlers and agents actually fetch your content and endpoints (GPTBot, Claude, Perplexity, Googlebot, …), recorded first-party in your own database, with no IP logging.
* **Activity to review** — a nav-bar queue surfaces the clients worth a second look — new, unusually high-volume, or spoofing what they are — names a recognised crawler where it can, and offers one-click **Block** or **Allow** (trust). Nothing is blocked unless you choose to.

**Content — clean, machine-readable output**

* **Markdown delivery** — request any page as clean markdown by appending `.md` to its URL (or, where your server allows it, with an `Accept: text/markdown` header).
* **/llms.txt** & **/llms-full.txt** — an [llmstxt.org](https://llmstxt.org) index of your pages, topics and recent posts, plus a full-text edition an agent can ingest in a single request.
* **JSON-LD** — WebSite + Person/Organization, plus BlogPosting and BreadcrumbList on posts. Automatically **defers to Yoast, Rank Math, SEOPress, AIOSEO and The SEO Framework** so you never ship duplicate schema.
* **XML sitemap** — an opt-in fallback sitemap (index + paginated sub-sitemaps), generated only when neither WordPress core nor an SEO plugin already provides one, and advertised in robots.txt and llms.txt.

**Identity & contact**

* **Author / site identity** — a profile sentence, expertise topics and linked profiles (`sameAs`) feed llms.txt and JSON-LD — the highest-signal lines for agent retrieval.
* **security.txt** — optionally publish an RFC 9116 disclosure contact at `/.well-known/security.txt`, so researchers and agents have a machine-readable way to report an issue.

**Readiness report**

* A one-screen score of how machine-readable your site is, with a plain-English checklist of what's enabled and what's still missing.

**Machine discovery (forward-looking)**

Agentimus also publishes a single, normalized discovery document, built to the conventions the agent ecosystem is converging on (the `.well-known` convention, A2A agent cards, MCP-shaped tools). It puts a site's identity, capabilities and APIs in one predictable place:

* **/.well-known/discovery.json** — an owner-curated document describing the site's identity, capabilities, APIs and agent cards. Other plugins can declare themselves through a single optional hook, so what an agent needs is aggregated in one place.
* **/.well-known/agent-card.json** and **/.well-known/mcp.json** — an A2A agent card and an MCP manifest, generated automatically.
* **Standards-aligned `.well-known` endpoints** — an RFC 9727 `api-catalog`, plus — *only when the capability actually exists* — an MCP server card and an Agent Skills index. Optional **response signing** (Web Bot Auth / HTTP Message Signatures, RFC 9421): sign the discovery documents with an Ed25519 key published at `/.well-known/http-message-signatures-directory`, so agents can verify they came from you. Off by default.
* **WordPress Abilities API → MCP tools** — registered abilities are projected into MCP-shaped tool descriptors, and a running MCP server (if one is installed) is detected and linked. Agentimus advertises tools; it does not execute them.
* **Zero-config auto-discovery** — reads your registered REST API namespaces, public post types and the WordPress Abilities API, so a site is described even when no plugin declares itself. A **Discovery Hub** admin screen shows what an agent can see, and you decide what is published.

**What's read today vs. what it readies you for**

Honest framing: the content signals above (JSON-LD, robots, llms.txt, markdown) are read by search engines and AI tools **today**. The discovery document is **forward-looking and standards-aligned** — it prepares your site for AI agents as they adopt these conventions, rather than claiming every agent already reads it. The discovery format is an open, openly-licensed convention with a public reference, not a private one, and the plugin works fully whether or not anything consumes that document.

**Why it's useful**

Most tools cover one slice — an llms.txt file, an AI-bot blocker, or structured data. Agentimus brings content control, agent-traffic visibility, clean machine-readable output and a forward-looking discovery document together in one coherent, lightweight package — and tells you what's still missing.

== Installation ==

1. Upload the `agentimus` folder to `/wp-content/plugins/`, or install via Plugins → Add New.
2. Activate the plugin.
3. Open **Agentimus** in the admin menu, fill in your Identity (name, profile sentence, expertise, profile links) and review the readiness report.

== Frequently Asked Questions ==

= Does Agentimus make external requests or send my data anywhere? =

No. Agentimus makes no outbound HTTP requests — nothing is sent to any external service, and no analytics or telemetry are collected. The agent-activity log is stored in your own database with no IP addresses. The discovery document includes a `$schema` value that *identifies* the document format (the same way a schema.org URL identifies a vocabulary); it is a label in the output, never fetched.

= Does this conflict with my SEO plugin? =

No. JSON-LD output automatically stands down when Yoast, Rank Math, SEOPress, AIOSEO or The SEO Framework is active, so structured data is never duplicated. The other endpoints (llms.txt, markdown) don't overlap with SEO plugins.

= My robots.txt rules aren't showing. =

If a static `robots.txt` file exists at your site root, or your CDN serves its own, it overrides WordPress's virtual robots.txt. The readiness report flags this. Remove the static file to let Agentimus manage the rules.

= How do I tell AI not to train on my content? =

Set **Allow AI training** to off under Settings → Crawler policy. That one switch publishes your choice in three places at once, so a crawler that ignores one still sees the others:

1. **robots.txt** — a `Content-Signal: … ai-train=no` line (advisory).
2. **A response header** on your pages — `tdm-reservation: 1` (the W3C TDM Reservation Protocol), which reaches bots that never read robots.txt.
3. **An opt-out file** at `/.well-known/tdmrep.json` — the recognized, machine-readable reservation, relevant under EU text-and-data-mining rules.

The header and file are on by default and can be toggled per channel under "Published beyond robots.txt". You can optionally also send the non-standard `X-Robots-Tag: noai, noimageai` (off by default, honored by some platforms) and link an AI-usage policy URL.

**Important — these are signals, not a wall.** robots.txt, the header and tdmrep.json are standardized *requests* that compliant crawlers honor; they do not forcibly stop a bot. To actually refuse a crawler with a `403`, add it to the crawler list or use scanner blocking (Crawler policy → Block specific crawlers / Block scanners), which Agentimus enforces at its generated endpoints.

= Can I block only specific AI bots? =

Yes — list them under **Block specific crawlers**. That writes a per-name `Disallow: /` to robots.txt for each. The `/.well-known/tdmrep.json` opt-out file and the `tdm-reservation` header are **site-wide** — the standard has no per-bot dial — so per-bot blocking lives in robots.txt (and in scanner blocking for a hard 403), while the file and header carry your overall site-wide choice. (Those site-wide signals are published only when you block AI training; an open site publishes none.)

= Can I see if AI is sending me visitors? =

Yes — the dashboard's "Traffic from AI" card counts real people who landed on your site from an AI assistant (ChatGPT, Perplexity, Gemini, …), detected from the visit's referrer and the `utm_source` tag some AI tools add to their links. It's the mirror of the activity log: that shows bots *reading* your content; this shows AI *bringing you readers*, with a by-source and top-landing-pages breakdown. Like the rest of the log it's first-party and aggregate-only — no IP, no per-visitor records, nothing sent anywhere. Some AI visits can't be detected (stripped referrers, Google's AI Overviews, cached pages), so read the figure as a floor: at least this many.

= Will it slow my site down? =

No. The text endpoints are cached and CDN-friendly; there is no front-end JavaScript or CSS. The admin app loads only on the plugin's own screen.

= Does it expose anything private, or let agents change my site? =

No. Agentimus only describes what your site already makes public; it grants no new access. Removing or suppressing an item changes what is *advertised*, not what is reachable — the underlying endpoints behave exactly as before, behind their own authentication.

= How do I make my plugin appear in the discovery document? =

Add a single optional action — no dependency, no library. If Agentimus isn't installed the hook simply never fires:

`add_action( 'wpdiscovery_register', function ( $registry ) {`
`    $registry->register( array( 'id' => 'acme', 'title' => 'Acme', 'type' => 'commerce' ) );`
`} );`

Agentimus also fires the product-aliased `agentimus_register`; you may hook either. See `examples/integrate-your-plugin.php` for the full resource schema (capabilities, endpoints, auth, agent cards, MCP tools).

== Screenshots ==

1. Dashboard — your readiness score plus a first-party log of which AI agents and crawlers fetched your endpoints (no IP logging).
2. Settings — your public identity, a security.txt contact, and one toggle per agent-readiness signal.
3. Readiness report — a plain-English pass/warn checklist of what's enabled and what's still missing.
4. Discovery Hub — every plugin's capabilities aggregated into one document, with per-item publish/suppress control.
5. Crawler policy & scanner blocking — declare your content-usage signals, block AI-training crawlers by name, and turn away spoofed or scanner traffic.
6. Activity to review — a nav-bar alert surfaces new, high-volume or spoofed clients from any screen, with one-click Block or Allow (no IP logging).

== External services ==

Agentimus does not use, connect to, or send any data to any external or third-party service. Everything runs on your own site: it makes no outbound HTTP requests, loads no remote scripts, fonts or analytics, and stores the agent-activity log in your own database with no IP addresses.

The generated discovery documents contain a `$schema` value that *names* the document format (in the same way a schema.org URL identifies a vocabulary). It is a label inside the output only — it is never fetched.

The example URLs in `examples/integrate-your-plugin.php` (on `example.com`) are placeholders for documentation; they are not requested by the plugin.

== Source & build ==

There is no minified-only code. The admin interface is built from Vue 3 source in `resources/` with Vite; the source and `vite.config.js` ship in this package and also live in the public repository at https://github.com/heera/agentimus . Run `npm install && npm run build` to regenerate `assets/admin/` from source.

== Changelog ==

= Unreleased =
* _Nothing yet — entries for the next release accumulate here, then this heading is renamed to the version number at publish time._

= 1.2.0 =
* AI-usage signals beyond robots.txt: when you block AI training, the "Allow AI training" switch now also publishes a standardized TDM Reservation Protocol response header (`tdm-reservation: 1`) and an opt-out file at `/.well-known/tdmrep.json` — one decision, every channel, so a crawler that ignores robots.txt still sees your choice. Both are on by default and individually toggleable. An open site publishes neither (on the web, no signal already means "allowed").
* Optional extras under Crawler policy: the non-standard `X-Robots-Tag: noai, noimageai` header (off by default) and an AI-usage policy URL surfaced as `tdm-policy`.
* New readiness check: warns when you reserve AI training in robots.txt but haven't backed it with the stronger header/file signals.
* Admin toolbar shortcut: a one-click "Agentimus" item beside "Howdy" on every screen (hidden on the plugin's own page), gated to administrators.
* "Traffic from AI" on the dashboard: counts real visitors who arrive from AI assistants (ChatGPT, Perplexity, Gemini, …), with a by-source and top-landing-pages breakdown — the mirror of the activity log (bots taking content) showing AI bringing you readers. First-party and aggregate-only: no IP, no per-visitor data, nothing sent anywhere. Part of the activity log; read the number as a floor (some AI visits can't be detected).
* These remain advisory signals honored by compliant crawlers — for a hard 403, use the crawler/scanner blocking, which is unchanged.

= 1.1.0 =
* Endpoint-activity dashboard: click any day on the chart to open a per-day report — that day's top clients and endpoints, plus its full request log with exact times.
* The activity chart now spans your whole retention window (default 30 days), and the dashboard states how long records are kept before they are removed.
* "Activity to review" now helps with unrecognised crawlers — it surfaces the client's own self-declared info link (clearly marked "not verified", with the destination shown) or a one-click web search, and names the row by the crawler's own token.
* Fixed several outdated "what is this?" crawler links (Barkrowler, Omgili, YouBot, Diffbot, BLEXBot).

= 1.0.0 =
* /llms.txt, /llms-full.txt, markdown delivery, JSON-LD, robots content-signals, and a readiness report.
* Agent-activity log — first-party, no IP logging.
* Machine discovery document at /.well-known/discovery.json, with an optional registration hook (`wpdiscovery_register`) for plugins to declare capabilities, APIs and agent cards. You control what is published.
* MCP & tools: projects the WordPress Abilities API into MCP-shaped tool descriptors, plus /.well-known/mcp.json and agent-card.json. Zero-config auto-discovery of REST namespaces and public post types.
* Standards `.well-known` endpoints: api-catalog (RFC 9727); an MCP server card and an Agent Skills index when applicable; optional Ed25519 response signing (Web Bot Auth, RFC 9421) for the discovery documents, off by default.
* Admin Discovery Hub for inspecting what agents can see, with per-item publish/suppress control.

== Upgrade Notice ==

= 1.2.0 =
Your "Allow AI training" choice now reaches crawlers that ignore robots.txt, via the standard tdm-reservation header and /.well-known/tdmrep.json (both opt-out-only, on by default). Adds a "Traffic from AI" dashboard card and a one-click admin-bar shortcut.

= 1.1.0 =
Richer activity dashboard: click a day for a full per-day report with exact times. Plus guidance for unrecognised crawlers and refreshed crawler info links.
