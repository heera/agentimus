=== Agentimus ===
Contributors: heera
Tags: ai-agents, ai-crawlers, agent-readiness, llms-txt, ai-seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.10.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Help AI assistants like ChatGPT and Claude understand and cite your site correctly — and see which ones are reading it. No tech setup needed.

== Description ==

Agentimus helps AI assistants like ChatGPT, Claude and Perplexity find your site, read it correctly, and cite it in your own words — and shows you which AI bots are actually visiting. **You don't need to understand AI or web standards to use it:** a setup wizard walks you through everything in about a minute on your first visit, then it runs on its own.

Want more control? You also get a first-party log of every AI crawler that fetches your content, one-click blocking for the bots you don't want, and a one-screen readiness report that scores how AI-ready your site is — and tells you the next thing to improve.

By default it makes no outbound requests, collects no analytics, and logs no IP addresses — everything runs on your own site. The optional **AI Visibility** feature is the one exception: turn it on and add your own AI provider API key, and it queries that provider to check whether AIs cite you (see *External services*).

**Control — who may use your content**

* **robots.txt content-signals + AI-training blocklist** — declare your content-usage policy and block named model-training crawlers (GPTBot, CCBot, ClaudeBot, Google-Extended, Bytespider, …) by name, while leaving read/cite bots free.
* **Block scanners & scrapers (opt-in hard block)** — robots rules are a polite request; this enforces them. Turn it on to return 403 to the user-agents on your denylist, and optionally auto-deny agents that disguise themselves as ancient handsets (a classic scanner trick). Your **always-allowed** list is never blocked — pre-trust well-known AI assistants and answer engines (ChatGPT, Claude, Perplexity, …) with one click, while the major search engines (Googlebot, Bingbot, …) are recognised and trusted automatically. `/.well-known/acme-challenge/` (SSL renewal) always stays reachable.

**Reduce exposure — what your site reveals to bots**

* **Exposure controls (opt-in, all OFF by default)** — a panel of switches that quietly close the things stock WordPress reveals to anonymous crawlers and scanners: stop username enumeration (the `?author=1` and REST `/wp/v2/users` leak, plus the users sitemap and oEmbed author), 404 author-archive pages, hide the WordPress version from the generator tag and asset URLs, drop the rarely-used auto-generated `<head>` discovery links, and neutralise XML-RPC. Nothing changes until you turn a switch on, and signed-in admins and the block editor are never affected. It's exposure hygiene, not a firewall — Agentimus stays a discovery layer, not a security suite.

**Visibility — who is reading you**

* **Agent activity log** — a dashboard of which AI crawlers and agents actually fetch your content and endpoints (GPTBot, Claude, Perplexity, Googlebot, …), recorded first-party in your own database, with no IP logging.
* **Activity to review** — a nav-bar queue surfaces the clients worth a second look — new, unusually high-volume, or spoofing what they are — names a recognised crawler where it can, and offers one-click **Block** or **Allow** (trust). Nothing is blocked unless you choose to.
* **AI Visibility (opt-in)** — ask ChatGPT, Perplexity, Gemini and Claude the questions your audience asks and track whether they actually **mention and cite you**, over time: a visibility score, citation rate, share-of-voice against competitors, and a per-prompt breakdown. Off by default; **you bring your own API key** for each engine, and this is the one feature that makes an outbound request (see *External services*).

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

*AI readiness is also called AI SEO, GEO (Generative Engine Optimization) and AEO (Answer Engine Optimization) — publishing the machine-readable signals AI systems need to find, read and correctly represent your site.*

== Installation ==

1. Upload the `agentimus` folder to `/wp-content/plugins/`, or install via Plugins → Add New.
2. Activate the plugin.
3. A setup wizard opens automatically on your first visit to the admin and walks you through your identity and content choices in about a minute. After that everything runs on its own — open **Agentimus** any time to review the readiness report or adjust settings.

== Frequently Asked Questions ==

= Do I need to be technical to use this? =

No. A setup wizard opens automatically the first time you visit the admin and walks you through everything in about a minute — you write a sentence about who you are and tick what AI assistants may read. Everything else runs on its own, and you can change any of it later.

= What does Agentimus change on my site? Will my visitors notice? =

Nothing your visitors see changes — there's no new front-end script, style or layout. Behind the scenes it publishes machine-readable files and signals (like llms.txt and a discovery document) that only AI assistants and crawlers read. It also stands down automatically next to SEO plugins, so it won't duplicate or fight your existing setup.

= What's the quickest way to set this up for my site? =

Activate Agentimus and run the one-minute setup wizard — that covers most sites. Then, depending on what you do:

* **Consultant, freelancer or personal brand:** fill in your Identity — your name, a one-sentence bio, your expertise topics, and links to your other profiles. That's the highest-signal information an AI assistant uses to describe and cite you correctly.
* **Business or agency:** set the entity type to Organization, list the services you offer, and add a contact email so an agent can point enquiries the right way.
* **Blog or publisher:** the defaults are already right — your posts and pages flow into llms.txt automatically. Just add a profile sentence so an assistant knows whose site it is.

Whatever your case, the Readiness report always tells you the single next thing worth improving.

= Does Agentimus make external requests or send my data anywhere? =

By default, no — Agentimus makes no outbound HTTP requests out of the box, sends nothing to any external service, collects no analytics or telemetry, and stores the agent-activity log in your own database with no IP addresses. **The one exception is the optional AI Visibility feature:** if you enable it and add your own API key, Agentimus queries the AI provider(s) you chose (OpenAI, Perplexity, Gemini and/or Anthropic) to check whether they mention and cite you — only for the engines you turn on, and only when a check runs (on demand or on your schedule). Your keys stay on your server and nothing else is sent anywhere. See *External services* for the full disclosure. The discovery document includes a `$schema` value that *identifies* the document format (the same way a schema.org URL identifies a vocabulary); it is a label in the output, never fetched. The one place a request is made is the optional "Verify live" self-check on the readiness report — and that runs in *your browser*, fetching your own public URLs only when you click it; the server itself still makes no request.

= Does this conflict with my SEO plugin? =

No. JSON-LD output automatically stands down when Yoast, Rank Math, SEOPress, AIOSEO or The SEO Framework is active, so structured data is never duplicated. The other endpoints (llms.txt, markdown) don't overlap with SEO plugins.

= My robots.txt rules aren't showing. =

If a static `robots.txt` file exists at your site root, or your CDN serves its own, it overrides WordPress's virtual robots.txt. The readiness report flags this. Remove the static file to let Agentimus manage the rules.

= I turned something on but nothing seems to happen — is it broken? =

Almost always it's working — here's how to confirm. The generated AI files are cached for up to an hour, so a change may not show instantly: open the file directly (for example `yoursite.com/llms.txt`) and refresh. The Readiness report's **Verify live** button fetches your real URLs from your browser and shows exactly what an agent receives — including anything your CDN is caching. If a file still isn't appearing, check that a static file or your CDN isn't overriding it (the report flags a static robots.txt, for instance).

= How do I tell AI not to train on my content? =

Set **Allow AI training** to off under Settings → Crawler policy. That one switch publishes your choice in three places at once, so a crawler that ignores one still sees the others:

1. **robots.txt** — a `Content-Signal: … ai-train=no` line (advisory).
2. **A response header** on your pages — `tdm-reservation: 1` (the W3C TDM Reservation Protocol), which reaches bots that never read robots.txt.
3. **An opt-out file** at `/.well-known/tdmrep.json` — the recognized, machine-readable reservation, relevant under EU text-and-data-mining rules.

The header and file are on by default and can be toggled per channel under "Published beyond robots.txt". You can optionally also send the non-standard `X-Robots-Tag: noai, noimageai` (off by default, honored by some platforms) and link an AI-usage policy URL.

**Important — these are signals, not a wall.** robots.txt, the header and tdmrep.json are standardized *requests* that compliant crawlers honor; they do not forcibly stop a bot. To actually refuse a crawler with a `403`, add it to the crawler list or use scanner blocking (Crawler policy → Block specific crawlers / Block scanners), which Agentimus enforces at its generated endpoints.

= Can I block only specific AI bots? =

Yes — list them under **Block specific crawlers**. That writes a per-name `Disallow: /` to robots.txt for each. The `/.well-known/tdmrep.json` opt-out file and the `tdm-reservation` header are **site-wide** — the standard has no per-bot dial — so per-bot blocking lives in robots.txt (and in scanner blocking for a hard 403), while the file and header carry your overall site-wide choice. (Those site-wide signals are published only when you block AI training; an open site publishes none.)

= Which AI agents are allowed by default? =

Out of the box Agentimus blocks nothing — it's a discovery layer, so every agent is served until you turn on the optional scanner blocking. Even then, an **always-allowed** list keeps trusted clients flowing: the major search engines (Googlebot, Bingbot, DuckDuckBot, Applebot, Yandex) are recognised automatically and never blocked or flagged, and the *AI access* tab shows them read-only so you know exactly what's trusted. You can add well-known AI assistants and answer engines (ChatGPT, Claude, Perplexity, …) with one click, or mark any client **Allow** from the activity review queue. Training crawlers (GPTBot, ClaudeBot, …) are deliberately not on the trust list — those belong to your separate AI-training choice, so trusting them here wouldn't quietly undo an opt-out you may have set.

= Can I see if AI is sending me visitors? =

Yes — the dashboard's "Traffic from AI" card counts real people who landed on your site from an AI assistant (ChatGPT, Perplexity, Gemini, …), detected from the visit's referrer and the `utm_source` tag some AI tools add to their links. It's the mirror of the activity log: that shows bots *reading* your content; this shows AI *bringing you readers*, with a by-source and top-landing-pages breakdown. Like the rest of the log it's first-party and aggregate-only — no IP, no per-visitor records, nothing sent anywhere. Some AI visits can't be detected (stripped referrers, Google's AI Overviews, cached pages), so read the figure as a floor: at least this many.

= Will Agentimus get my site mentioned by ChatGPT or improve my AI rankings? =

Honestly: it helps with one half of that, not the other. Agentimus makes your site **discoverable and correctly understood** — when an AI assistant looks at your site, it can find your content, read a clean version, and describe you accurately. That is what the plugin controls, and it does it well. But whether an AI **spontaneously mentions you** when someone asks a broad question ("best resources for X") is a matter of **authority and reputation** — earned over time through genuinely notable content that others reference. No plugin, llms.txt, or schema can manufacture that, and any tool promising "instant AI visibility" is overselling. Agentimus makes sure that when authority does bring an agent to your door, nothing is lost in translation.

= Will it slow my site down? =

No. The text endpoints are cached and CDN-friendly; there is no front-end JavaScript or CSS for your visitors (the optional, off-by-default WebMCP bridge adds a tiny script only when you enable it, and it stays inert in browsers without the API). The admin app loads only on the plugin's own screen.

= Does it expose anything private, or let agents change my site? =

No. Agentimus only describes what your site already makes public; it grants no new access. Removing or suppressing an item changes what is *advertised*, not what is reachable — the underlying endpoints behave exactly as before, behind their own authentication.

= How do I make my plugin appear in the discovery document? =

Add a single optional action — no dependency, no library. If Agentimus isn't installed the hook simply never fires:

`add_action( 'wpdiscovery_register', function ( $registry ) {`
`    $registry->register( array( 'id' => 'acme', 'title' => 'Acme', 'type' => 'commerce' ) );`
`} );`

Agentimus also fires the product-aliased `agentimus_register`; you may hook either. See `examples/integrate-your-plugin.php` for the full resource schema (capabilities, endpoints, auth, agent cards, MCP tools).

= Which hooks can my plugin use? =

Registration is a single action, but Agentimus exposes more for deeper integrations, grouped by stability:

* **Stable** — frozen at WP_Discovery spec 1.0; build on these: the `wpdiscovery_register` action with its `$registry->register()` / `add_well_known()` API, plus `agentimus_entity_types` and the `agentimus_cache_flushed` action.
* **Extension** — supported output-shaping filters (signatures may evolve between releases): tune the discovery document, MCP/agent surfaces, llms.txt, schema.org, sitemap, REST discovery and security.txt — e.g. `agentimus_envelope`, `agentimus_documents`, `agentimus_mcp`, `agentimus_agent_skills`, `agentimus_well_known_routed`, `agentimus_post_types`, `agentimus_security_txt`.
* **Internal** — advanced site-owner tuning (Guard, Classifier, Activity, Settings); not a third-party integration surface.

Every hook, with its signature and tier, is catalogued in `examples/all-hooks-reference.php`.

= Is the discovery format an open standard I can read? =

Yes. The discovery document implements the **WP_Discovery Protocol**, an openly-licensed (CC BY 4.0) specification — not a format private to this plugin. Read the spec, the 1.0 JSON Schema and worked examples at https://heera.github.io/wp-discovery-protocol/ (source and conformance tests: https://github.com/heera/wp-discovery-protocol). Agentimus is its reference implementation.

== Screenshots ==

1. Dashboard — your readiness score plus a first-party log of which AI agents and crawlers fetched your endpoints (no IP logging).
2. Settings — a tidy, tabbed control panel; the Discovery section gives you a toggle for each agent-readiness signal, plus experimental browser tools (WebMCP) that let an in-browser AI agent call your site search.
3. Readiness report — a plain-English pass/warn checklist of what's enabled and what's still missing.
4. Discovery Hub — every plugin's capabilities aggregated into one document, with per-item publish/suppress control.
5. Crawler policy & scanner blocking — declare your content-usage signals, block AI-training crawlers by name, turn away spoofed or scanner traffic, and keep an always-allowed list of trusted agents — with one-click suggestions for well-known AI assistants and the search engines trusted automatically.
6. Activity to review — a nav-bar alert surfaces new, high-volume or spoofed clients from any screen, with one-click Block or Allow (no IP logging).
7. About — a plain-English account of every feature and what it publishes, a privacy & data section (no outbound calls, no IP/PII, signing key stays on your server), the open WP_Discovery Protocol it implements, and an FAQ.
8. Exposure controls — opt-in, off-by-default switches that limit what anonymous crawlers can read about your site: username enumeration, author archives, the WordPress version, auto-generated head links, and XML-RPC.

== External services ==

By default, Agentimus does not connect to or send any data to any external service: it makes no outbound HTTP requests, loads no remote scripts, fonts or analytics, and stores the agent-activity log in your own database with no IP addresses.

**The optional AI Visibility feature is the only part that calls an external service, and it is off by default.** When you enable it and add your own API key for one or more AI providers, Agentimus sends the prompts you configured to those providers to check whether they mention and cite your site. This happens only for the engines you turn on, and only when a check runs — either when you click "Run check now" or on the schedule you set. Your API keys are stored on your own site and are used solely to make these calls; nothing else is sent anywhere. The providers you can enable — and their terms and privacy policies — are:

* **OpenAI (ChatGPT)** — https://openai.com/policies/terms-of-use · https://openai.com/policies/privacy-policy
* **Perplexity** — https://www.perplexity.ai/hub/legal/terms-of-service · https://www.perplexity.ai/hub/legal/privacy-policy
* **Google (Gemini)** — https://ai.google.dev/gemini-api/terms · https://policies.google.com/privacy
* **Anthropic (Claude)** — https://www.anthropic.com/legal/consumer-terms · https://www.anthropic.com/legal/privacy

The generated discovery documents contain a `$schema` value that *names* the document format (in the same way a schema.org URL identifies a vocabulary). It is a label inside the output only — it is never fetched.

The example URLs in `examples/integrate-your-plugin.php` (on `example.com`) are placeholders for documentation; they are not requested by the plugin.

== Source & build ==

There is no minified-only code. The admin interface is built from Vue 3 source in `resources/` with Vite; the source and `vite.config.js` ship in this package and also live in the public repository at https://github.com/heera/agentimus . Run `npm install && npm run build` to regenerate `assets/admin/` from source.

== Changelog ==

= 1.10.1 =
* Fix: the Exposure tab now saves. The five Exposure toggles weren't wired into the admin's auto-save, so flipping one looked like it did nothing and reverted on reload. The settings were always handled correctly on the server — only the admin screen's save trigger was missing. No data or settings were lost.
* Auto-save feedback is clearer: the switch or card you just changed dims and ignores further clicks while its save is in flight — exactly like a button mid-action — and a result toast in the top corner confirms "Saved" or, if something went wrong, shows the error and rolls that control back to its previous state. Only the control you touched locks; the rest of the panel stays live.
* Each Features signal now links the open standard it implements — llms.txt, robots.txt, JSON-LD, the sitemap protocol and Markdown — right from its description, so the spec behind any toggle is one click away. The "Plain-text versions" description now says "any included page" to make clear that .md delivery follows your Content types selection.

= 1.10.0 =
* New "Exposure" settings tab — opt-in, off-by-default controls that limit what an anonymous visitor can read about your site, the defensive counterpart to the Discovery tab. Hide username enumeration (the REST `/wp/v2/users` and `?author=N` leak, the users sitemap, and oEmbed author fields), 404 author-archive pages, hide the WordPress version (generator tag + core asset `?ver=`), drop the rarely-used auto-generated `<head>` discovery links (shortlink, RSD, Windows Live Writer, oEmbed), and disable XML-RPC (renders its methods inert and drops the X-Pingback header). Every control ships OFF, is scoped to logged-out requests so signed-in admins and the block editor keep full access, and a fresh install changes nothing until you opt in.
* Trusted-agents list, easier to use — the "Always allowed" list (clients that are never blocked and never flagged) no longer hides itself when it's empty, so you can trust an agent up front instead of waiting for one to turn up in the review queue. It now offers one-click chips for well-known AI assistants and answer engines (ChatGPT, Claude, Perplexity, DuckDuckGo, Mistral, …), and shows — read-only — the major search engines (Googlebot, Bingbot, DuckDuckBot, Applebot, Yandex) that are already trusted automatically, so you can see exactly what's allowed without adding anything. New `agentimus_known_allowed` and `agentimus_default_allowed` filters let companion plugins extend both lists.

= 1.9.0 =
* WebMCP bridge (experimental, opt-in, OFF by default): registers your site's read-only tools — starting with site search — with AI agents working inside a browser, via the emerging WebMCP standard (navigator.modelContext). Adds a tiny front-end script only when you enable it, and it stays completely inert in browsers without the API, so a default install still ships no front-end JavaScript. Companion plugins can add their own read-only tools with the `agentimus_webmcp_tools` filter.
* Discovery links in the HTML <head>: the llms.txt and OpenAPI links are now mirrored into the page markup as well as the HTTP Link header, so crawlers and readiness scanners that read the HTML — not the headers — find them too.
* CORS preflight: the /.well-known discovery docs now answer an OPTIONS preflight (scoped strictly to the names this plugin serves), so strict cross-origin agent clients aren't blocked. Simple GET access was already cross-origin-enabled.
* About tab + FAQ: an honest note on what a discovery layer can and can't do — it makes your site discoverable and correctly understood, but it can't manufacture the authority that gets you spontaneously cited. No tool can; anything promising "instant AI visibility" is overselling.
* Settings, redesigned: the page is now split into four focused sections — Discovery, Identity, AI access and Advanced — shown one at a time via a sub-navigation, instead of one long stack of cards. The experimental Browser-tools toggle and its per-tool list are unified into a single card. Readiness "fix this" links still jump straight to the right field, now opening the section that holds it.

= 1.8.0 =
* Onboarding & listing: plain-language wording throughout — what Agentimus does, who it's for, and that it needs no technical setup — so a non-technical site owner can tell at a glance whether it's for them.
* Readiness: every check that needs attention now links straight to the exact setting that fixes it — switching to the right tab, scrolling the field into view, highlighting it and focusing it, so the next step is one click away.
* Settings: simplified several feature hints (dropped low-value cryptographic acronyms) while keeping the underlying file and standard names you can search for.
* Dashboard: a short, plain line making clear the activity log is aggregate and private — no IP addresses, no personal data, nothing sent anywhere.
* Hardened: the agent-activity table is now capped to a generous, filterable maximum as a backstop to daily pruning, so an extreme-traffic day can't bank unbounded rows; activating an unrelated plugin no longer regenerates the heavy /llms-full.txt; and an invalid /…/ block pattern is flagged in Settings instead of silently matching as plain text.

= 1.7.0 =
* Dashboard: "Traffic from AI" is now a single, clearer card with a new by-day breakdown — expand a day to see which assistant (ChatGPT, Perplexity, …) sent a reader to which page. Counts only; no IPs or exact times are stored.
* Dashboard: the Readiness summary in the sidebar is now a clean, clickable overview — the score and each rung jump straight to the relevant section of the full report.
* Admin: replaced the browser's native confirmation pop-ups with a styled in-app dialog.
* Fixed: the Readiness screen could go blank if another plugin registered a malformed readiness check; it now recovers gracefully and shows the offending check.
* Hardened: Agentimus is now resilient to malformed data from other plugins across every extension point (settings, discovery envelope, schema, readiness). A buggy third-party plugin can no longer blank the admin or corrupt a published discovery/schema document — backed by a new robustness test suite.

= 1.6.0 =
* Companion plugins now "just work": when another plugin registers a discovery resource or serves its own /.well-known document, Agentimus refreshes its rewrite rules automatically — no re-activation or manual permalink flush. The refresh is keyed to the actual set of routed documents, never runs on front-end requests, and is rate-limited, so it stays off the hot path and won't slow your site.
* New developer reference: examples/all-hooks-reference.php catalogues every extension point Agentimus exposes, grouped by stability — Stable (the registration API to build on), Extension (output-shaping filters) and Internal (advanced tuning) — with a matching "Which hooks can my plugin use?" entry in the FAQ, so plugin authors can see at a glance what's safe to depend on.

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

= 1.10.1 =
Fixes the Exposure tab not saving (the new toggles weren't wired into auto-save). Recommended for anyone on 1.10.0. No breaking changes.

= 1.10.0 =
Adds a new "Exposure" tab — opt-in, off-by-default controls that limit what anonymous crawlers can read about your site (username enumeration, author archives, WP version, head-link clutter, XML-RPC). Also makes the "Always allowed" trusted-agents list easier to use, with one-click chips for well-known AI assistants and a read-only view of the search engines trusted automatically. Everything new ships off/unchanged by default; no breaking changes.

= 1.9.0 =
Adds an optional, off-by-default experimental WebMCP bridge (browser tools for AI agents), mirrors the key discovery links into the HTML head, answers CORS preflights on the discovery docs, and an honest note on what a discovery layer can and can't do. No breaking changes.

= 1.8.0 =
Friendlier, plainer wording for non-technical owners; Readiness checks now jump to and highlight the exact setting that fixes them; plus activity-table, cache and block-pattern hardening. No breaking changes.

= 1.7.0 =
Clearer "Traffic from AI" card with a per-day breakdown, a tidier clickable Readiness summary, styled confirm dialogs, and major hardening so a buggy third-party plugin can never blank the admin or corrupt your published discovery/schema documents.

= 1.6.0 =
Companion plugins that register discovery resources or serve their own /.well-known documents now self-heal Agentimus's rewrite rules — no re-activation or manual flush needed, and front-end-safe + rate-limited so there's no performance cost. Adds a complete, tier-grouped developer hook reference.

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
