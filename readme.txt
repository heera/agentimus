=== Agentimus ===
Contributors: heera
Tags: ai-agents, ai-crawlers, agent-readiness, llms-txt, ai-seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.18.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site agent-ready: help AI assistants like ChatGPT and Claude understand and cite it, and see which ones are reading it. No setup needed.

== Description ==

Agentimus makes your site agent-ready: it helps AI assistants like ChatGPT, Claude and Perplexity find it, read it correctly, and cite it in your own words — and shows you which AI bots are actually visiting. **You don't need to understand AI or web standards to use it:** a setup wizard walks you through everything in about a minute on your first visit, then it runs on its own.

Want more control? You also get a first-party log of every AI crawler that fetches your content, one-click blocking for the bots you don't want, and a dashboard that scores your site's agent readiness — one AEO/GEO score across five simple rungs, per-page tips on making your content easier for AI to quote, and always the next thing to improve.

By default it makes no outbound requests, collects no analytics, and logs no IP addresses — everything runs on your own site. Two optional, off-by-default features change that only when you turn them on: **AI Visibility** queries an AI provider you choose (with your own key) to check whether AIs cite you; and **Store IP addresses for flagged clients** records IPs, but only for crawlers flagged as impersonators or spoofs so you can block them (see *External services*).

**📖 Full documentation** — a plain-English user manual and a developer reference, with step-by-step guides for every feature: https://heera.github.io/agentimus/

**Control — who may use your content**

* **robots.txt content-signals + AI-training blocklist** — declare your content-usage policy and block named model-training crawlers (GPTBot, CCBot, ClaudeBot, Google-Extended, Bytespider, …) by name, while leaving read/cite bots free.
* **Block scanners & scrapers (opt-in hard block)** — robots rules are a polite request; this enforces them. Turn it on to return 403 to the user-agents on your denylist, and optionally auto-deny agents that disguise themselves as ancient handsets (a classic scanner trick). Your **always-allowed** list is never blocked — pre-trust well-known AI assistants and answer engines (ChatGPT, Claude, Perplexity, …) with one click, while the major search engines (Googlebot, Bingbot, …) are recognised and trusted automatically. `/.well-known/acme-challenge/` (SSL renewal) always stays reachable.

**Reduce exposure — what your site reveals to bots**

* **Exposure controls (opt-in, all OFF by default)** — a panel of switches that quietly close the things stock WordPress reveals to anonymous crawlers and scanners: stop username enumeration (the `?author=1` and REST `/wp/v2/users` leak, plus the users sitemap and oEmbed author), 404 author-archive pages, hide the WordPress version from the generator tag and asset URLs, drop the rarely-used auto-generated `<head>` discovery links, and neutralise XML-RPC. Nothing changes until you turn a switch on, and signed-in admins and the block editor are never affected. It's exposure hygiene, not a firewall — Agentimus stays a discovery layer, not a security suite.

**Visibility — who is reading you**

* **Agent activity log** — a dashboard of which AI crawlers and agents actually fetch your content and endpoints (GPTBot, Claude, Perplexity, Googlebot, …), recorded first-party in your own database, with no IP logging by default (an optional setting stores IPs for flagged crawlers only).
* **Activity to review** — a nav-bar queue surfaces the clients worth a second look — new, unusually high-volume, or spoofing what they are — names a recognised crawler where it can, and offers one-click **Block** or **Allow** (trust). Nothing is blocked unless you choose to.
* **Request log** — every recorded request, one row each, under *More → Request log*. Filter by client, endpoint, network, user-agent and date to see exactly what a single bot fetched.
* **Traffic from AI** — the mirror of the crawler log: the real visitors an AI assistant sent you. *More → AI traffic* reports them day by day, by assistant (ChatGPT, Perplexity, Gemini, Claude, Copilot, Grok, DeepSeek and more) and by landing page. Stored as daily aggregate counts — never a row that stands for one person, no IP, no query strings. An opt-in **CDN mode** counts them in the visitor's browser so the number survives a full-page cache, and an opt-in **Find missed AI sources** diagnostic lists the referrers Agentimus couldn't name, so a new assistant never goes uncounted without you knowing.
* **You decide how long it's kept** — a retention period, nightly auto-delete, and a hard size cap that always applies (Settings → Visit log), so the log can never grow without limit on your host.
* **AI Visibility (opt-in)** — track **each brand, product or person you choose** across ChatGPT, Perplexity, Gemini and Claude. For every one, Agentimus asks the questions your audience actually types and reports whether it gets **mentioned, linked, and how it ranks against its own rivals** — over time. Each thing you track has its own website, competitors, questions and scoreboard; pause any single one, or the whole schedule, whenever you like. Off by default; **you bring your own API key** for each engine, and this is the one feature that makes an outbound request (see *External services*).

**Content — clean, machine-readable output**

* **Markdown delivery** — request any page as clean markdown by appending `.md` to its URL (or, where your server allows it, with an `Accept: text/markdown` header).
* **/llms.txt** & **/llms-full.txt** — an [llmstxt.org](https://llmstxt.org) index of your pages, topics and recent posts, plus a full-text edition an agent can ingest in a single request.
* **JSON-LD** — WebSite + Person/Organization, plus BlogPosting and BreadcrumbList on posts. Automatically **defers to Yoast, Rank Math, SEOPress, AIOSEO and The SEO Framework** so you never ship duplicate schema.
* **Topics for AI** — say what each post is about in plain words, right in the editor; those topics become the JSON-LD `keywords` and a line in the page's `.md`, so assistants understand each page's subject. Type your own, or let Agentimus fill them in from the post's own tags and categories. Nothing shows on the visible page.
* **AI description** — write a one-line summary of each post, right in the editor; it becomes the JSON-LD `description`, the lead of the page's `.md`, and the page's `<meta name="description">` — replacing your theme's auto-generated one, while still standing aside for a dedicated SEO plugin. Leave it blank and Agentimus falls back to the excerpt, or a short summary of the page. A sub-switch keeps it out of your `<head>` if you'd rather it enrich only the AI data.
* **XML sitemap** — an opt-in fallback sitemap (index + paginated sub-sitemaps), generated only when neither WordPress core nor an SEO plugin already provides one, and advertised in robots.txt and llms.txt.
* **Change feed** — a JSON feed at `/agentimus-changes.json` lists your recently added, updated and removed pages, with a `?since=` filter, so an assistant re-checks only what changed instead of re-reading your whole site. On by default and advertised in your discovery document.

**Identity & contact**

* **Author / site identity** — a profile sentence, expertise topics and linked profiles (`sameAs`) feed llms.txt and JSON-LD — the highest-signal lines for agent retrieval.
* **security.txt** — optionally publish an RFC 9116 disclosure contact at `/.well-known/security.txt`, so researchers and agents have a machine-readable way to report an issue.

**Readiness report**

* A one-screen score of how machine-readable your site is, with a plain-English checklist of what's enabled and what's still missing.
* **Agent preview** — open it from the Readiness tab to see the exact JSON-LD *and* Markdown an AI agent receives for the whole site or any page or post, then copy it. It shows what would ship even when the feature is off or an SEO plugin owns your schema, and a matching read-only preview also sits right in the post editor — so you never have to view page source to check what agents read.
* **AI Readability tips** — as you write, an "AI Readability" panel in the post editor flags what makes a page hard for an assistant to read and cite: thin content, missing headings, no opening summary, a nav-heavy page, or images without alt text. It sits in the same "Agentimus" box as the per-page Agent preview, so you check what an agent receives *and* how readable it is in one place. Editor-only — nothing shows to visitors.

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

= Where is the documentation? =

The full documentation — a plain-English user manual and a developer reference — is at https://heera.github.io/agentimus/. It has step-by-step guides for every feature, plus the hooks, filters and endpoints for developers.

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

By default, no — Agentimus makes no outbound HTTP requests out of the box, sends nothing to any external service, collects no analytics or telemetry, and stores the agent-activity log in your own database with no IP addresses. (One opt-in setting, *Store IP addresses for flagged clients*, can store IPs locally for flagged crawlers only — off by default; see *External services*.) **The one external-service exception is the optional AI Visibility feature:** if you enable it and add your own API key, Agentimus queries the AI provider(s) you chose (OpenAI, Perplexity, Gemini and/or Anthropic) to check whether they mention and cite you — only for the engines you turn on, and only when a check runs (on demand or on your schedule). Your keys stay on your server and nothing else is sent anywhere. See *External services* for the full disclosure. The discovery document includes a `$schema` value that *identifies* the document format (the same way a schema.org URL identifies a vocabulary); it is a label in the output, never fetched. The one place a request is made is the optional "Verify live" self-check on the readiness report — and that runs in *your browser*, fetching your own public URLs only when you click it; the server itself still makes no request.

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

1. Dashboard — your AEO/GEO score across five plain rungs (Findable, Readable, Trusted, Optimized, Cited) with the one next step worth taking, alongside a first-party log of which AI agents and crawlers fetched your endpoints (no IP logging) and whether each client's volume is trending up or down.
2. Settings — a tidy, tabbed control panel; the Discovery section gives you a toggle for each agent-readiness signal, cards for Topics for AI and the per-page AI description, plus experimental browser tools (WebMCP) that let an in-browser AI agent call your site search.
3. Readiness report — a plain-English pass/warn checklist of what's enabled and what's still missing, and beneath it the Optimize worklist: exactly which pages an answer engine would struggle to quote, and why. Set aside anything that isn't meant to be cited.
4. Discovery Hub — every plugin's capabilities aggregated into one document, with per-item publish/suppress control.
5. Crawler policy & scanner blocking — declare your content-usage signals, block AI-training crawlers by name, turn away spoofed or scanner traffic, and keep an always-allowed list of trusted agents — with one-click suggestions for well-known AI assistants and the search engines trusted automatically.
6. Activity to review — a nav-bar alert surfaces new, high-volume or spoofed clients from any screen. Genuine ones you Allow or Block by name in one click; a crawler that failed reverse-DNS verification (an impersonator) can't be trusted by name, so you see its verdict and how to block it at your host or CDN — or Ignore to dismiss. No IP logging by default; an optional setting can store IPs for flagged crawlers only.
7. About — a plain-English account of every feature and what it publishes, a privacy & data section (no outbound calls, no IP/PII by default, signing key stays on your server), the open WP_Discovery Protocol it implements, and an FAQ.
8. Exposure controls — opt-in, off-by-default switches that limit what anonymous crawlers can read about your site: username enumeration, author archives, the WordPress version, auto-generated head links, and XML-RPC.
9. AI Visibility — an opt-in, bring-your-own-key scoreboard showing whether ChatGPT, Perplexity, Gemini and Claude mention and link each brand, product or person you track: seen-in-answers and linked-your-site rates, rank against each item's own rivals, and question-by-question results with the sources each engine cited. Off by default; you bring your own API key and nothing runs until you enable it.
10. In the post editor — the "Topics for AI" panel: say in plain words what a page is about, one chip at a time, or leave it blank and let Agentimus fill them in from the post's tags and categories (those arrive marked *auto*); either way the topics flow into the page's JSON-LD keywords and its .md edition. Nothing shows to visitors.
11. In the post editor — the "Agentimus" box, AI Readability tab: a per-page pass/warn check of what makes the page hard for an assistant to read and cite — enough substance, an opening summary, section headings, heading order, prose vs links, and image alt text.
12. In the post editor — the "Agentimus" box, JSON-LD tab: the exact structured data the page emits in its `<head>`, with a copy button and Google Rich Results / Schema.org validator links.
13. In the post editor — the "AI description" panel: a one-line summary of the page for AI assistants. It feeds the page's structured data and its .md edition, and becomes the page's meta description unless a dedicated SEO plugin manages that. Leave it blank and Agentimus falls back to the excerpt.
14. Request log — every request an agent made, in one filterable table: narrow by client, endpoint, network, verification verdict, User-Agent or date to see exactly what a single bot fetched. Repeat hits are grouped, and your own logged-in visits are never recorded. Records are kept for the last 30 days (or until the size cap), then trimmed — so read a full page as a floor, not a total.
15. The More menu — the occasional screens (AI Visibility, AI traffic, the request log and About) fold behind one control, so the main navigation stays short. AI Visibility appears disabled with "Turn on in Settings" rather than hidden, so you always know it's there to enable.

== External services ==

By default, Agentimus does not connect to or send any data to any external service: it makes no outbound HTTP requests, loads no remote scripts, fonts or analytics, and stores the agent-activity log in your own database with no IP addresses.

**Storing IP addresses is optional and off by default.** One opt-in setting — *Store IP addresses for flagged clients* — records the IP only of clients flagged as an impersonating or spoofed crawler (never ordinary traffic), so you can block them at your host or CDN. When on, those IPs are personal data: they are stored on your own site only, kept for a short retention period, cleared when you clear the activity log, and deleted if you switch the setting back off. Nothing is ever sent off your server. If you enable it, disclose it in your site's privacy policy (Agentimus adds suggested text to Settings → Privacy).

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

= 1.18.0 =
* New — **Keep AI endpoints out of your cache.** If a cache or CDN sits in front of your site, it can serve stored copies of your AI files (llms.txt, the `.well-known` docs, the change feed) — so those agent fetches never reach WordPress, the activity log under-counts them, and the change feed can go stale. A new opt-in switch (Settings → Visit log, off by default) asks caches not to store those files, so each fetch reaches WordPress and is counted and current. It works with any cache that respects the standard `no-store` header; the readiness report links straight to the switch whenever it detects a cache sitting in front of your endpoints.

= 1.17.0 =
* New — **The request log.** Every recorded request, one row each, under *More → Request log*. Filter by client, endpoint, network, user-agent and date to answer the question the dashboard's summaries structurally can't: exactly what did this one bot fetch, and when?
* New — **A full report on the readers AI sends you.** *More → AI traffic* takes "Traffic from AI" apart day by day — open a day to see which assistant landed on which page. The dashboard keeps the summary (today, the month, your top assistants and landing pages) and links through.
* New — **A "More" menu.** The nav bar is now Dashboard · Settings · Readiness · Discovery · More, with the occasional screens — AI Visibility, AI traffic, Request log and About — tucked behind it.
* New — **You decide how long records are kept.** Choose a retention period, whether old records are deleted each night, and a hard size cap (Settings → Visit log). The cap always applies, so the log can never grow without limit on your host — and the settings say plainly which rule collects first.
* New — **Find missed AI sources (optional, off by default).** "Traffic from AI" only counts assistants it recognises, and a miss leaves no trace. Switch this on for a week and Agentimus lists the referrers it *couldn't* name, so you can see whether a new assistant is going uncounted. It records the referring site's name and the link's `utm_source` tag only — still no IPs, still nothing sent anywhere.
* Improved — **"Traffic from AI" recognises more assistants**: Grok, DeepSeek, Meta AI, Mistral's Le Chat, DuckDuckGo's duck.ai, Phind, and Claude's newer address. It also catches an assistant that tags its links without passing a referrer, which some now do. Search engines that also answer with AI — Google, Bing, DuckDuckGo, Kagi — stay uncounted on purpose: their referrer can't be told apart from an ordinary search click, and a guess dressed up as data is worse than a blind spot you know about.
* Fixed — **"Last 30 days" counted thirty-one.** The AI-traffic window included one extra day, so every total on the card read slightly high against its own label.
* Fixed — **your own visits were counted.** With CDN mode on, an administrator arriving from an AI assistant was recorded as a visitor. Your visits are skipped in both counting modes now, as they always were meant to be.
* Fixed — **AI-referred visits to pages that don't exist** were counted as landing pages in CDN mode, but not on the server. Neither counts them now.
* Fixed — **a busy site's dashboard is much faster.** The activity data no longer carries every day's breakdown on every load; a day's detail is fetched when you open it. On a large log this cut the work behind the dashboard by more than half.
* Improved — admin polish: the More menu opens on hover and reads as a menu rather than a stack of tabs; deep links to a screen survive a page reload; and the AI-traffic drill-down, the menu and the day rows no longer overflow on a small screen.

= 1.16.0 =
* New — **Your AI-readiness, as one score.** The dashboard now rolls everything up into a single AEO/GEO score (0–100) across five plain rungs — Findable, Readable, Trusted, Optimized and Cited — and always shows the one most useful next step. Each rung links straight to where you act on it.
* New — **Optimize your content for citation.** A per-page check of how easy each article is for an AI to read and quote — enough substance, a concrete fact or source to quote, quotable passages, and freshness — with a worklist of exactly which pages to improve, plus the same tips in an "AI Readability" panel in the editor while you write. It grades articles only (commerce products and structural pages like your Posts and blog-index pages are left out), you can **set aside** any page that isn't meant to be cited (it stays published — it just leaves the score), and you can mark whole categories **evergreen** to exempt timeless content from the freshness check.
* New — **AI description.** Write a one-line summary for each page — the sibling of Topics for AI — and Agentimus feeds it to your structured data (the JSON-LD `description`) and the plain-text (`.md`) version, and makes it your page's meta description, replacing your theme's weaker auto-generated one while still standing aside for a dedicated SEO plugin. Leave it blank and it falls back to the page's excerpt — or a short summary drawn from the page when you haven't written one; a sub-switch on the new "AI description" settings card keeps it out of your page `<head>` if you'd rather it enrich only the AI data.
* New — **Track AI citations (optional, off by default).** The AI Visibility feature — asking ChatGPT, Perplexity, Gemini and Claude, with your own keys, whether they actually name your site — is now an explicit opt-in switch. Turn it on and it adds the **Cited** rung to your score; leave it off and the score is a clean four-rung ladder. A reading it gives you is dated and expires after 90 days, so an old check never quietly stands in for a current one.
* New — **Agentimus serves your robots.txt.** A managed robots.txt on a guaranteed 200 that advertises your sitemap and states your crawler preferences, so AI files and rules are always discoverable.
* New — **See which network every bot really comes from.** Every visit in the activity log is now traced back to the network that owns it — Google, Amazon, a hosting company — rather than left as a name a crawler chose for itself. A client that only *claims* to be a known engine is marked as self-declared, so you can tell a verified crawler from an unverified one at a glance.
* New — **Re-check a crawler, and check any IP.** A flagged client can be re-checked on demand, and a "Check an IP" tool tells you who an address really belongs to. Useful when you want to confirm a verdict before you block anything.
* New — **Suggest questions to check.** When AI Visibility is on, Agentimus can propose the questions worth asking the AIs about your site, so you don't have to invent them yourself.
* Improved — the *Top clients* and *By endpoint* breakdowns now show whether each row is trending up or down, and the score's rung numbers read as percentages. Plus admin polish: the sticky header lifts with a shadow as you scroll, switching tabs returns you to the top, and several layout fixes stop wide rows from overflowing on smaller screens.
* Fixed — **a misbehaving plugin or theme can no longer take a page down with it.** Agentimus runs other people's code when it builds a page's structured data and description; if any of that threw an error, the page failed to load. Now the page simply goes without that data. Its `robots.txt` route is protected the same way.
* Fixed — the citability grade no longer counts structural or empty pages, no longer treats a purely decorative image as a missing alt text, and reports the **Cited** rung as *not measured* (rather than zero) when AI Visibility has no provider set up. The dashboard's score card also refreshes as soon as you save your settings.

= 1.15.0 =
* New — **Spot impostor crawlers.** The activity review queue now verifies a visitor that claims to be Googlebot, Bingbot, Applebot, DuckDuckBot or Yandex by its network address (reverse DNS), and flags a forgery as an **impersonator** — a scanner can't inherit a real crawler's trust just by copying its name. Each flagged client gets a plain "Check this bot" panel and one-click Block / Allow / Ignore. Optional **Store IP addresses for flagged clients** (off by default) records the address of an impersonator or spoof — only those, never ordinary visitors — for a short time, so you can see exactly which IP to block at your host or CDN. Nothing is ever sent off your server.
* New — **Exposed-files check.** A one-click scan (Readiness → *Scan for exposed files*) asks your own public URL for the risky files that should never be reachable — backups, `.env`, database dumps, keys, a stray `debug.log` — and flags any that are actually downloadable, reading only whether each responds, never its contents. It's environment-aware (on a local site a finding reads as a heads-up "on deploy"), you can add your own paths under Settings → Exposure, and a companion card warns when WordPress debug logging is left on in production. Guidance, not a firewall — it tells you what to delete or block, with copy-paste Nginx, Apache and Cloudflare rules.
* New — **CDN mode.** If a full-page cache (Cloudflare, a caching plugin) serves your pages, AI-referral visits never reach WordPress to be counted. Turn on CDN mode and Agentimus counts them in the browser instead, so your *Traffic from AI* numbers survive the cache. Off by default; no IP address and no per-visitor data.
* New — **Set your AI stance during setup.** The setup wizard now lets you choose, in one step, whether AI may show you in search, read and cite you, and train on your content — and points you to the newer tabs (Dashboard, Readiness, Discovery) when you finish.
* New — **Faster Markdown.** Per-page `.md` versions are now cached in tiers so a crawl doesn't rebuild them each time, and your home URL's site-index Markdown now previews in Agent preview alongside its JSON-LD.
* Improved — **Reverse-DNS verification fails open**, so a slow DNS resolver can no longer make a real crawler lose its trusted status. When you've told Agentimus your CDN is trusted, it now reads the **real visitor IP behind the proxy** (for example Cloudflare's) so verification and flagged-IP capture are accurate. And the **Readiness report warns** when a shared cache is serving your AI endpoints, which would otherwise make the activity log under-count.
* Improved — admin polish: the nav bar and page header now stay put as you scroll, the Readiness actions stack neatly on small screens, and there are several spacing and copy fixes across the dashboard.
* Fixed — the exposed-files scan now picks up a path you just added without a page reload, and no longer mistakes a redirect or a soft-404 page for a downloadable file. A page's body is recovered for its Markdown and word count when another plugin's `the_content` filter blanks it outside the main loop. And a settings row now toggles only from its switch, not from a click anywhere on the row.

= 1.14.1 =
* Improved — the admin footer on the Agentimus screen now shows both the Agentimus version and your WordPress version, with a subtly engraved separator that reads correctly on light or dark admin surfaces.
* Improved — the About tab now lists the optional "verify search engines by reverse DNS" control and notes that your AI Visibility API keys are stored encrypted at rest.

= 1.14.0 =
* New — **Verify search engines (optional).** Turn on "Verify search engines by reverse DNS" under Settings → Block scanners, and Agentimus confirms a visitor claiming to be Googlebot, Bingbot, Applebot, DuckDuckBot or Yandex really is — by checking its network address — before trusting it, so a scanner can't slip past your block just by copying a crawler's name. Off by default (it's the one feature that makes a small, cached DNS lookup); leave it off behind a proxy/CDN unless you've supplied the real visitor IP.
* New — **Copy a User-Agent in one click.** In the activity log and the day report, hovering a visitor's User-Agent now shows the full text in a tidy tooltip, and clicking it copies the whole string — no more fighting the cut-off text.
* Security — **Your AI Visibility API keys are now encrypted at rest.** Provider keys used to be stored as plain text; they're now encrypted in the database using your site's own secret keys, so a leaked backup or a stray database read can't hand them over.
* Improved — **Steadier under heavy crawler traffic.** The generated files (llms.txt, llms-full.txt, discovery.json, the fallback sitemap) are hardened so one misbehaving page or add-on can't take them down, and a burst of bots on a cold cache no longer makes the site rebuild the same large file many times at once.
* Improved — **Safer AI Visibility spend.** Two checks can no longer run at the same time (which would double your API bill), each answer's length is capped, and a run has a hard ceiling — so monitoring can't run away with your budget. A garbled provider response is now recorded as an error instead of a fake "not mentioned".
* Improved — **A flood-resistant activity log.** A burst of fake bots — even ones pretending to be a known crawler — can no longer swamp the log or push out your real history, and the AI-referrals table is now capped so it can't grow without bound.
* Improved — the Settings and Readiness screens load faster, and the day-report's date picker now matches the rest of the interface.
* Fixed — **Settings switches always save.** A newly added on/off switch could silently fail to save; every switch now saves reliably, with a safeguard so it can't happen again.
* Fixed — upgrading no longer risks quietly changing your AI-training preference; a stored setting keeps all of its parts across an update.

= 1.13.0 =
* New — **Change feed.** A single JSON feed at `/agentimus-changes.json` lists your recently added, updated and removed pages, with a `?since=` filter — so an AI assistant can re-check just what changed instead of re-reading your whole site. It's advertised in your discovery document and on by default; each item links to its Markdown twin and its canonical REST resource.
* New — **AI Readability tips.** The post editor now shows an "AI Readability" panel — tucked into one tidy "Agentimus" box alongside the JSON-LD preview — that flags what makes a page hard for an AI to read as you write it: thin content, missing headings, no opening summary, a nav-heavy page, or images without alt text, each with a plain pass or "to improve". Editor-only; nothing is shown to visitors.
* New — **Plugin attribution.** When another plugin adds its own content type (for example FluentCart or WooCommerce products), Agentimus now names the source next to it — so a generic "Products" group reads as "Products · FluentCart" and is never ambiguous.
* New — **Agent preview.** See exactly what an AI agent receives for any page — its JSON-LD structured data *and* its Markdown twin — without leaving the admin or viewing page source. Open it from the Readiness tab, pick the whole site or any page or post (grouped by type), and read or copy the output; a matching read-only preview also appears right in the post editor. It shows what *would* ship even when a feature is off or an SEO plugin owns your schema. Password-protected posts never expose their content, and an unpublished draft is clearly marked as a preview of what it will emit once you publish it.
* New — **Topics for AI.** Each post gets a "Topics for AI" box in the editor where you say, in plain words, what it's about (for example: *llms.txt, AI visibility, structured data*). Those topics are added to the page's machine-readable data — the JSON-LD `keywords` and the page's `.md` version — so AI assistants understand and cite it correctly. Leave it blank to have Agentimus use the post's own tags and categories automatically, or type your own to take control. Your topics show as tidy chips — the ones pulled from the post's tags and categories are labelled *auto* and update live as you add or remove tags and categories, so what will be emitted is always in front of you. Turn the whole feature on or off, and choose how topics are picked, under Agentimus → Settings → Topics for AI. Nothing shows on the visible page; this is only for the AI/agent layer, and it steps aside for your SEO plugin's structured data just like the rest of Agentimus.
* New — the Topics for AI box **suggests topics as you type** — drawn from ones you've already used, your tags and categories, and your expertise — so your wording stays consistent instead of drifting ("WP" vs "WordPress"). The **Readiness report** now tracks how well your content is covered by topics.
* New — **sharper structured data.** Each topic is also published as a proper entity (schema.org `about`), and can be linked to its Wikidata or Wikipedia entry so assistants identify the exact thing you mean — the difference between "Mercury" the planet and the element. Agentimus never looks these up itself (a wrong match is worse than none); you supply a small topic → URL map with a one-line filter — see the drop-in `examples/topic-links-wikidata.php`.
* Improved — **no junk topics.** A tag or category that's just a number (like "67") is never turned into an AI topic or offered as a suggestion, so your machine-readable data stays clean with nothing for you to tidy up. Topics you type yourself are always kept.
* Fixed — the activity log now names a crawler by the name it **declares in its own User-Agent** (for example a `TheWebReport/1.0` bot you've allowed) — the same name shown in the review queue — instead of the vague "Unidentified". The remaining catch-all bucket, for a client that sends no readable name at all, is now labelled "Unrecognized".
* Fixed — the admin tabs now work with the browser's Back and Forward buttons, and the Agent preview opens fresh every time (it no longer keeps your last selection).

= Earlier versions =

Older releases (1.12.4 and earlier) are in the full changelog: https://github.com/heera/agentimus/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 1.18.0 =
New: an opt-in switch (Settings → Visit log) that keeps your AI endpoints out of a CDN/proxy cache, so agent fetches are counted in your activity log and freshness-sensitive endpoints stay current. Off by default; no breaking changes.

= 1.17.0 =
A filterable request log, a full day-by-day report on the readers AI sends you, and control over how long the log is kept. Also fixes three counting bugs in "Traffic from AI" — a 30-day window that spanned 31 days, your own visits being counted behind a CDN, and hits on pages that don't exist. All new features are opt-in; no breaking changes.

= 1.16.0 =
Your AI-readiness as one AEO/GEO score, with per-page tips on making content easier for AI to quote, a per-page AI description, a managed robots.txt, and an opt-in "Cited" check. Also fixes a bug where another plugin or theme misbehaving could stop a page loading. All new features are opt-in; no breaking changes.

= 1.15.0 =
Spot impostor crawlers, scan your site for exposed files (backups, .env, debug.log), and count AI visits even behind a CDN — plus faster Markdown, a friendlier setup, and a sticky admin header. All new features are opt-in; no breaking changes.

= 1.14.1 =
Small admin-UI polish: the footer shows both the Agentimus and WordPress versions, and the About tab documents the reverse-DNS verification and encrypted keys. No functional changes.

= 1.14.0 =
Encrypts your AI Visibility API keys at rest, adds optional reverse-DNS verification of search engines and one-click copying of a visitor's User-Agent, plus a lot of behind-the-scenes hardening — resilience under heavy crawler load, safer AI-check spending, and a flood-resistant activity log. Also fixes a bug where a settings switch could silently fail to save. No breaking changes.

= 1.13.0 =
Adds a change feed (`/agentimus-changes.json`) so agents fetch only what changed, per-page "AI Readability" tips in the editor, an in-admin Agent preview, Topics for AI, and plugin attribution for third-party content types. On-by-default additions are read-only and safe — no breaking changes.

= 1.12.4 =
Fixes live web-search checks timing out on slower questions, and shows check errors inline. No breaking changes.

= 1.12.3 =
Clearer wording on the AI Visibility results summary. No breaking changes.

= 1.12.2 =
Fixes the AI Visibility scoreboard flickering while a background check runs, and hardens question auto-save. No breaking changes.

= 1.12.1 =
Minor fixes to the AI Visibility results and engine settings display. No breaking changes.

= 1.12.0 =
Adds live web search for Claude (opt-in, like ChatGPT and Gemini) and shows the source links each engine cited. Checks now run in the background so they no longer time out on slow runs. Your existing results are kept — the results table upgrades automatically on load.

= 1.11.0 =
Adds AI Visibility monitoring — an opt-in, bring-your-own-key tool that tracks whether ChatGPT, Perplexity, Gemini and Claude mention, link and rank each brand or product you track. Off by default, no outbound calls until you enable it; nothing else changes.

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
