=== Agentimus ===
Contributors: heera
Tags: ai-agents, ai-crawlers, agent-readiness, llms-txt, ai-seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which AI agents read your site and block the bad ones — plus llms.txt, crawl controls and a /.well-known discovery layer. AI agent readiness.

== Description ==

Agentimus makes your site legible to AI agents and crawlers — and shows you which ones actually read it. You get a first-party log of every AI crawler that fetches your content, one-click blocking for the bots you don't want, and the machine-readable signals search engines and AI tools read *today*: a clean llms.txt, JSON-LD, markdown delivery, and AI-crawl controls. A one-screen readiness report scores how machine-readable your site is and what's still missing.

This is **AI readiness** — sometimes called AI SEO, GEO (Generative Engine Optimization), or AEO (Answer Engine Optimization): publishing the machine-readable signals AI systems need to find, read, and correctly represent your site.

Underneath, it ties these together with a single, normalized **discovery document** at `/.well-known/discovery.json` — an open, standards-aligned map of your site's identity, capabilities and APIs, and a reference implementation of that open convention, not a private format. This is more than an llms.txt generator: llms.txt is one signal among several, sitting under a coherent discovery layer rather than being the whole product.

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
* **Standards-aligned `.well-known` endpoints** — an RFC 9727 `api-catalog`, plus — *only when the capability actually exists* — an MCP server card and an Agent Skills index. Optional **response signing** (Web Bot Auth / HTTP Message Signatures, RFC 9421): sign the discovery documents with an Ed25519 key published at `/.well-known/http-message-signatures-directory`, so agents can verify they came from you. On by default; the private key stays on your server.
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

No. Agentimus makes no outbound HTTP requests — nothing is sent to any external service, and no analytics or telemetry are collected. The agent-activity log is stored in your own database with no IP addresses. The discovery document includes a `$schema` value that *identifies* the document format (the same way a schema.org URL identifies a vocabulary); it is a label in the output, never fetched. The one place a request is made is the optional "Verify live" self-check on the readiness report — and that runs in *your browser*, fetching your own public URLs only when you click it; the server itself still makes no request.

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
7. About — a plain-English account of every feature and what it publishes, a privacy & data section (no outbound calls, no IP/PII, signing key stays on your server), the open WP_Discovery Protocol it implements, and an FAQ.

== External services ==

Agentimus does not use, connect to, or send any data to any external or third-party service. Everything runs on your own site: it makes no outbound HTTP requests, loads no remote scripts, fonts or analytics, and stores the agent-activity log in your own database with no IP addresses.

The generated discovery documents contain a `$schema` value that *names* the document format (in the same way a schema.org URL identifies a vocabulary). It is a label inside the output only — it is never fetched.

The example URLs in `examples/integrate-your-plugin.php` (on `example.com`) are placeholders for documentation; they are not requested by the plugin.

== Source & build ==

There is no minified-only code. The admin interface is built from Vue 3 source in `resources/` with Vite; the source and `vite.config.js` ship in this package and also live in the public repository at https://github.com/heera/agentimus . Run `npm install && npm run build` to regenerate `assets/admin/` from source.

== Changelog ==

= 1.5.0 =
* New About tab: a plain-English account of everything Agentimus does (each capability, what it publishes, and whether it ships on), a Privacy & data section grounded in the code (no server-side outbound calls, no IP or other PII, local-only activity, and a signing key that never leaves your server), the WP_Discovery Protocol it implements (with links to the spec and JSON Schema and a one-hook snippet so other plugins can extend the discovery output), and an operational FAQ.
* Verified responses (Ed25519 / RFC 9421 signing of the discovery documents) now ship **on by default**. The keypair is generated on your server, the private key is never autoloaded and never leaves the site, and the public key directory is published at /.well-known/http-message-signatures-directory so agents can confirm the documents are really yours and unaltered. It's feature-detected (silently skipped if libsodium isn't available) and still toggleable under Settings.
* Privacy fix: password-protected posts and pages no longer leak their title, dates or Q&A into the JSON-LD schema in your page head or into the XML sitemap. Only published, publicly-visible content is described — matching how llms.txt and markdown already behaved.
* Hardened agent blocking: closed a bypass where appending a known crawler token to a User-Agent could dodge the denylist. Real search engines and AI crawlers are now matched by structured signatures at a token boundary, so a spoofed string earns no trust while genuine bots (and their variants) still match.
* Friendlier first run: a proper welcome on first activation, mode-aware copy, and a brief celebration when onboarding completes; the setup wizard's "Skip" is now instant and its fields are full-width.
* Clearer Settings: the Authenticated API field is plain-language with a one-click same-origin check, Setup-guide and Reset are grouped into one block with equal-width actions, and the security-contact and signing copy were reworded so every option explains itself.
* Discovery tab: "Well-known documents" now spans the full width, and validation moved to a compact "Registration status" card in the sidebar that expands to a full list only when there's something to fix.
* Admin nav keeps all tabs on a single row at narrow widths, and the notification bell drops its dropdown caret.
* Admin polish: a quiet maker credit in the sidebar (linking to the author's site) and a small, optional review link in the footer — both shown only on Agentimus's own screens, never elsewhere in wp-admin.

= 1.4.3 =
* The MCP server card at `/.well-known/mcp/server-card.json` now describes a real MCP server using that server's own tools — exactly what's callable at its endpoint — instead of the site-wide ability list (which could list tools that weren't actually exposed there). On a site running more than one MCP server, the card represents the richest server, and every other server gets its own card at `/.well-known/mcp/{server}/server-card.json`; a site with no MCP server returns a clean 404 as before.
* The `/.well-known/mcp.json` manifest now links each server to its own card, so an agent can enumerate the servers a site exposes and jump straight to each card without guessing the URL.
* Admin: when a real MCP server is detected, the Discovery-docs rail lists the server card alongside discovery.json, agent-card.json and mcp.json (hidden on sites with no server, so there's never a dead link).

= 1.4.2 =
* Fixed the `readOnlyHint` on discovered MCP tools. It was guessed from the tool name's verb, which mislabeled read-only resources whose names lack a read verb (e.g. a "contribution-guide" resource showed read-only as false) and could even mark a mutating "get-and-delete"-style tool as read-only. It now follows the ability's declared annotation, then its type (a resource is read-only by definition), and only then a guarded name heuristic that marks a tool read-only solely when its name leads with a read verb and contains no mutation token.

= 1.4.1 =
* Lowered the minimum WordPress version from 6.9 to 6.0. The plugin already ran fine on older cores — 6.9 was needlessly blocking updates and fresh installs. The optional WordPress Abilities API integration is feature-detected (`wp_get_abilities`), so it activates wherever that API is present — in core from WordPress 6.9, or via the Abilities API plugin on older versions — and is simply skipped where it isn't. No other behaviour changes.
* Reworded the "MCP & tools" empty state: it no longer suggests "installing" the Abilities API, and points to WordPress 6.9+ (or the Abilities API plugin) instead.

= 1.4.0 =
* OpenAPI 3.1 description of your site's existing public read API, served at `/.well-known/openapi.json` and advertised from discovery.json. It documents the WordPress REST endpoints for your agent-indexed content types (list + single, with parameters and a content schema) so agent tooling gets a typed contract — it describes the REST you already have and adds no new endpoints.
* FAQPage schema: when a page clearly is an FAQ (Details/disclosure blocks, or headings that ask a question with their answer below), Agentimus publishes FAQPage JSON-LD so agents can lift the Q&A. Only fires with two or more pairs, so it never emits guessed markup; defers to your SEO plugin like the rest of the schema.
* Service schema (opt-in): a Services list under Identity (name + description + optional link). Each becomes a Schema.org `Service` linked to you as the provider, so agents can answer "what does this site offer?". Left empty by default — never guessed from your content.
* New readiness check, "/llms.txt substance": warns when your generated llms.txt is thin (under the ~200-word minimum agents expect) and a sparse index gives them little to read or cite. Rather than padding the file with filler, it points you at Identity to add a profile and expertise — real content that lifts the file over the line. Sits on the Readable rung.

= 1.3.0 =
* Readiness report reorganised into a Findable → Readable → Trusted ladder: each rung groups its checks under a status-coloured heading, and the dashboard rail shows which rung you've reached plus a one-line next step that jumps straight to the check to fix.
* "Verify live" on the readiness report: a one-click self-check that fetches your own agent endpoints **from your browser** (through the real public URL, so it sees what an agent gets — including anything a CDN serves) and shows what actually comes back. The server still makes no request; the check runs in your browser, only when you click it.
* Agent endpoints (/llms.txt, /llms-full.txt, markdown, the fallback sitemap) now send `Access-Control-Allow-Origin: *`, so browser-based agents can read them cross-origin — matching the discovery documents.
* HTML pages now advertise their markdown twin with a `Link: …; rel="alternate"; type="text/markdown"` header, so an agent can discover the `.md` URL instead of guessing it.
* Two optional Identity fields that sharpen how agents represent you: **"What you're not"** (an explicit exclusion, e.g. "this is not a personal blog" — published as Schema.org `disambiguatingDescription`) and **"Audience"** (who the site is for — Schema.org `audience`). Both also flow into llms.txt and the discovery document.

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

= 1.5.0 =
Adds an About tab (capabilities, a code-grounded privacy account, and the WP_Discovery Protocol it implements). Response signing (RFC 9421) is now ON by default — the key is generated on your server, never autoloaded, and never leaves the site; it's feature-detected and still toggleable. Also fixes a privacy leak (password-protected content no longer appears in JSON-LD schema or the sitemap) and closes a User-Agent blocking bypass.

= 1.4.3 =
The MCP server card now describes a real MCP server and its actual tools instead of the site-wide ability list. Sites running several MCP servers get one card each at /.well-known/mcp/{server}/server-card.json, and mcp.json now links to every server's card. No change for sites without an MCP server.

= 1.4.2 =
Corrects the read-only hint on discovered MCP tools — it now follows the ability's declared annotation and type (resources are read-only) instead of guessing from the name, so a read-only resource isn't mislabeled and a mutating tool is never marked "safe". No other changes.

= 1.4.1 =
Compatibility fix: minimum WordPress lowered from 6.9 to 6.0, so the plugin updates and installs on more sites. No feature changes; the Abilities API integration still activates wherever that API is available (core 6.9+, or the Abilities API plugin on older versions).

= 1.4.0 =
Three new machine-readable surfaces: an OpenAPI 3.1 description of your existing REST at /.well-known/openapi.json, automatic FAQPage schema on pages that are clearly FAQs, and an opt-in Services list that publishes Schema.org Service. Plus a readiness check that flags a too-thin llms.txt.

= 1.3.0 =
The readiness report is now a Findable → Readable → Trusted ladder with a one-click "Verify live" self-check (runs in your browser; the server still makes no outbound request). Agent endpoints now allow cross-origin reads and advertise each page's markdown twin, and two optional Identity fields ("What you're not", "Audience") sharpen how agents represent you.

= 1.2.0 =
Your "Allow AI training" choice now reaches crawlers that ignore robots.txt, via the standard tdm-reservation header and /.well-known/tdmrep.json (both opt-out-only, on by default). Adds a "Traffic from AI" dashboard card and a one-click admin-bar shortcut.

= 1.1.0 =
Richer activity dashboard: click a day for a full per-day report with exact times. Plus guidance for unrecognised crawlers and refreshed crawler info links.
