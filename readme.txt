=== Agentimus ===
Contributors: heera
Tags: ai-agents, ai-crawlers, agent-readiness, llms-txt, ai-seo
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.22.0
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
* **Agent access** — the other side of the log: who *authenticates to and acts on* the machine surface Agentimus creates, under *More → Agent access*. It records application passwords being created, first used, renamed or revoked; WordPress abilities being run; and requests that were refused, or that probed for abilities that don't exist. A record, not a guard — it never blocks — with no IP logging, so it names the key that was used, not the person. A brand-new application password is worth a look: it keeps working even after you change your password.
* **Traffic from AI** — the mirror of the crawler log: the real visitors an AI assistant sent you. *More → AI traffic* reports them day by day, by assistant (ChatGPT, Perplexity, Gemini, Claude, Copilot, Grok, DeepSeek and more) and by landing page. Stored as daily aggregate counts — never a row that stands for one person, no IP, no query strings. An opt-in **CDN mode** counts them in the visitor's browser so the number survives a full-page cache, and an opt-in **Find missed AI sources** diagnostic lists the referrers Agentimus couldn't name, so a new assistant never goes uncounted without you knowing.
* **You decide how long it's kept** — a retention period, nightly auto-delete, and a hard size cap that always applies (Settings → Visit log), so the log can never grow without limit on your host.
* **AI Visibility (opt-in)** — track **each brand, product or person you choose** across ChatGPT, Perplexity, Gemini and Claude. For every one, Agentimus asks the questions your audience actually types and reports whether it gets **mentioned, linked, and how it ranks against its own rivals** — over time. Tell it what each thing *is* (*"a WordPress SEO plugin"*) and it will **suggest the questions a buyer really types** — or, if you've set up an AI provider in WordPress, ask that AI for a spread of them. Each thing you track has its own website, category, competitors, questions and scoreboard; pause any single one, or the whole schedule, whenever you like. Off by default; **you bring your own API key** for each engine, and this is the one feature that makes an outbound request (see *External services*).

**Content — clean, machine-readable output**

* **Markdown delivery** — request any page as clean markdown by appending `.md` to its URL. (Answering the page's own URL with markdown via an `Accept: text/markdown` header is also supported, but off by default: one URL with two possible bodies is unsafe behind a CDN that force-caches pages, and readers are the ones who find out. Enable it with a one-line filter where your caching is sound.)
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
* **Write with AI (optional)** — connect an AI provider in WordPress (Settings → Connectors, with your own key) and a **"Draft with AI"** button fills the AI description or Topics for a page from its content, while **"Fix with AI"** drafts a concrete fix for each readability warning — an opening summary you can insert in one click, or a heading outline to copy in. Everything routes through WordPress's built-in AI Client (WordPress 7.0+), so Agentimus never sees your key; every suggestion is editable and nothing is saved for you. The buttons stay hidden until a provider is set up.

**Machine discovery (forward-looking)**

Agentimus also publishes a single, normalized discovery document, built to the conventions the agent ecosystem is converging on (the `.well-known` convention, A2A agent cards, MCP-shaped tools). It puts a site's identity, capabilities and APIs in one predictable place:

* **/.well-known/discovery.json** — an owner-curated document describing the site's identity, capabilities, APIs and agent cards. Other plugins can declare themselves through a single optional hook, so what an agent needs is aggregated in one place.
* **/.well-known/agent-card.json** and **/.well-known/mcp.json** — an A2A agent card and an MCP manifest, generated automatically.
* **Standards-aligned `.well-known` endpoints** — an RFC 9727 `api-catalog`, plus — *only when the capability actually exists* — an MCP server card and an Agent Skills index. Optional **response signing** (Web Bot Auth / HTTP Message Signatures, RFC 9421): sign the discovery documents with an Ed25519 key published at `/.well-known/http-message-signatures-directory`, so agents can verify they came from you. On by default; the private key stays on your server.
* **WordPress Abilities API** — Agentimus registers its own **read-only abilities** (your readiness/AEO-GEO score, AI traffic, request log, bot checks, and page / JSON-LD / Markdown previews), so WordPress's built-in AI — and, with the MCP adapter, external AI agents — can read them, each gated by the same capability as the screen it comes from. It also projects *any* plugin's registered abilities into MCP-shaped tool descriptors and links a running MCP server when one is installed.
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

= Does Agentimus run an MCP server? =

Yes — as an opt-in, on WordPress 6.9 or newer. Turn on **Settings → Discovery → MCP server** and AI tools you already use (Claude Code, Claude Desktop, Codex) can talk to your site over the Model Context Protocol and run the same read-only, permission-checked tools your admin AI gets: readiness, AI traffic, bot identification, per-page readability and previews. The switch card writes the exact setup for your tool — pick it, mint a key with one click, copy the result — then a Test button proves the connection with the same calls the tool will make. (ChatGPT itself can't connect — its connectors only support OAuth sign-ins, which WordPress logins aren't — though Codex inside the ChatGPT desktop app connects fine via the Codex setup.) Nothing becomes public — every request has to sign in with a WordPress login, each tool keeps the same permission checks as the admin screens, and every call is recorded under **More → Agent access**. Off by default, and everything needed ships with the plugin.

= Can AI help me write the description, topics and fixes? =

Yes, if you're on WordPress 7.0 and have set up an AI provider under Settings → AI. Then **Draft with AI** appears on the AI description field, **Suggest with AI** on the Topics field, and **Fix with AI** on any AI Readability row that needs work. Agentimus asks *your* AI through WordPress's shared connectors — it never sees or stores your API key, and nothing is sent anywhere if you haven't set a provider up (the buttons simply don't appear). Every suggestion arrives as ordinary editable text in the field: you read it, change it, and save the post yourself. Nothing is written for you.

= Does AI Visibility use the AI provider I set up in WordPress? =

No — it needs its own API keys, and that's on purpose. A visibility check is graded on the **sources each engine cited**, and WordPress's shared connectors hand back only the answer text; the list of cited sources is dropped before Agentimus could read it. Reading those sources means talking to each engine's own API, so AI Visibility keeps its own keys (Settings → AI Visibility). They stay on your server and are used for nothing else.

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

1. Dashboard — your AEO/GEO score across five plain rungs (Findable, Readable, Trusted, Optimized, Cited) with the one next step worth taking, alongside a first-party log of which AI agents and crawlers fetched your endpoints (no IP logging) and whether each client's volume is trending up or down. Every day bar on both charts opens that day's full report.
2. Settings — a tidy, tabbed control panel; the Discovery section gives you a toggle for each agent-readiness signal, cards for Topics for AI and the per-page AI description, plus experimental browser tools (WebMCP) that let an in-browser AI agent call your site search.
3. Readiness report — a plain-English pass/warn checklist of what's enabled and what's still missing, and beneath it the Optimize worklist: exactly which pages an answer engine would struggle to quote, and why. Set aside anything that isn't meant to be cited.
4. Discovery Hub — every plugin's capabilities aggregated into one document, with per-item publish/suppress control.
5. Crawler policy & scanner blocking — declare your content-usage signals, block AI-training crawlers by name, turn away spoofed or scanner traffic, and keep an always-allowed list of trusted agents — with one-click suggestions for well-known AI assistants, the search engines trusted automatically, and a "Manage clients" dialog that holds every standing decision with its date and a one-click undo.
6. Activity to review — a nav-bar alert surfaces new, high-volume or spoofed clients from any screen. Genuine ones you Allow or Block by name in one click; a crawler that failed reverse-DNS verification (an impersonator) can't be trusted by name, so you see its verdict and how to block it at your host or CDN — or Ignore to dismiss. No IP logging by default; an optional setting can store IPs for flagged crawlers only.
7. About — a plain-English account of every feature and what it publishes, a privacy & data section (no outbound calls, no IP/PII by default, signing key stays on your server), the open WP_Discovery Protocol it implements, and an FAQ.
8. Exposure controls — opt-in, off-by-default switches that limit what anonymous crawlers can read about your site: username enumeration, author archives, the WordPress version, auto-generated head links, and XML-RPC.
9. AI Visibility — an opt-in, bring-your-own-key scoreboard showing whether ChatGPT, Perplexity, Gemini and Claude mention and link each brand, product or person you track: seen-in-answers and linked-your-site rates, rank against each item's own rivals, and question-by-question results with the sources each engine cited. Off by default; you bring your own API key and nothing runs until you enable it.
10. In the post editor — the "Topics for AI" panel: say in plain words what a page is about, one chip at a time, or leave it blank and let Agentimus fill them in from the post's tags and categories (those arrive marked *auto*); either way the topics flow into the page's JSON-LD keywords and its .md edition. Where you've set up an AI provider in WordPress, "Suggest with AI" drafts them from the page itself. Nothing shows to visitors.
11. In the post editor — the "Agentimus" box, AI Readability tab: a per-page pass/warn check of what makes the page hard for an assistant to read and cite — enough substance, an opening summary, section headings, heading order, prose vs links, and image alt text. Each row that needs work offers "Fix with AI", which drafts a concrete fix using the AI provider you set up in WordPress (nothing is saved for you, and without a provider the button simply isn't there).
12. In the post editor — the "Agentimus" box, JSON-LD tab: the exact structured data the page emits in its `<head>`, with a copy button and Google Rich Results / Schema.org validator links.
13. In the post editor — the "AI description" panel: a one-line summary of the page for AI assistants. It feeds the page's structured data and its .md edition, and becomes the page's meta description unless a dedicated SEO plugin manages that. Leave it blank and Agentimus falls back to the excerpt — or click "Draft with AI" to have your own AI provider write it from the page.
14. Request log — every request an agent made, in one filterable table: narrow by client, endpoint, network, verification verdict, User-Agent or date to see exactly what a single bot fetched. Repeat hits are grouped, and your own logged-in visits are never recorded. Records are kept for the last 30 days (or until the size cap), then trimmed — so read a full page as a floor, not a total.
15. The More menu — the occasional screens (AI Visibility, AI traffic, the request log, Agent access and About) fold behind one control, so the main navigation stays short. AI Visibility appears disabled with "Turn on in Settings" rather than hidden, so you always know it's there to enable.
16. AI Visibility settings — each thing you track gets a name, a category ("what kind of thing is it?"), its website, its rivals and the questions to ask. Tell Agentimus the category and it suggests the questions a buyer really types — or, where you've set up an AI provider in WordPress, "Suggest with AI" asks it for a wider spread. Suggestions are only ever offered; you pick which to keep, and every setting on the screen saves as you change it.
17. Agent access — the other side of the log: who authenticates to, and *acts* on, the machine surface Agentimus creates. Application passwords being created (a brand-new one is worth a second look — it keeps working even after you change your password), first used, renamed or revoked; WordPress abilities being run; and requests that were refused, or that probed for abilities that don't exist. A record, not a guard — it never blocks — and with no IP logging, it names the key that was used, not the person.
18. Client decisions — everything you've decided about visiting bots and agents in one dialog: Blocked, Allowed and Ignored tabs, each row with the crawler's identity, the date you decided, and an instant undo. The only place to see (and reverse) clients you ignored from the review queue.
19. A day in your AI traffic — click any bar on the dashboard's Traffic-from-AI chart and see exactly which assistant sent visitors to which page that day. Days are the finest "when" stored, so there are no per-visit times — by design.

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

= 1.22.0 =
* New — **An MCP server, one switch, nothing extra to install.** Turn on Settings → Discovery → MCP server and AI tools you already use — Claude Code, Claude Desktop, Cursor, Codex — can talk to your site over the Model Context Protocol and run Agentimus's nine read-only tools: readiness, AI traffic, request log, bot identification, and the page/JSON-LD/Markdown previews. The WordPress MCP Adapter library now ships inside the plugin and is loaded only when you opt in — with the switch off, nothing is loaded and no endpoint exists. Nothing becomes public: every call must sign in with a WordPress login (an application password works), every tool keeps the same permission checks as the admin screen it comes from, every call is recorded under More → Agent access — and switching it off disconnects connected tools immediately. To keep the surface exactly what you opted into, Agentimus also keeps the library's generic "run any ability" server switched off. Needs WordPress 6.9+ (the Abilities API); off by default. Full transparency: the settings card also tells you what an application password really is — a login for your whole REST API, not just this server — and recommends one password per tool, on a user with only the permissions it needs.
* Fixed — **The MCP wiring had quietly gone stale — in two places.** The adapter library renamed its transport class some versions ago; Agentimus still asked for the old name, so even a site that had installed the adapter by hand got no server at all, silently. And the mcp.json manifest read each server's tool list with equally outdated accessors, so a running server was advertised as having "0 tools" and its per-server card refused to serve. Both fixed: the manifest now lists every server's real tools and links a card for each.
* New — **Agent access now says who.** Every row carries the user and the named application password behind it — "by anna · app password "zapier"" — resolved live from your own users and keys, so a renamed key shows its current name, a revoked one says "since revoked", and a deleted user's row says that too instead of a bare number. Password-lifecycle rows say "on anna's account", because that's what is actually recorded — the key's owner, not necessarily who clicked. Nothing new is stored: no IP addresses, no identities; the names are looked up at view time.
* Changed — **The dashboard's 7- and 30-day numbers are whole calendar days now.** They used to be rolling windows ending at the current second, which meant they could visibly shrink between midnights as week-old hits aged out — watched live under auto-refresh, that read as data loss. Every window is now counted in whole calendar days (UTC), the same clock as the Today tile and the daily chart beneath them; numbers move only when a hit arrives or at midnight UTC. The tiles may read slightly higher after updating — the window now includes its first day's early hours, which rolling had already dropped.

= 1.21.2 =
* Changed — **Answering a page's own URL with Markdown is now off by default.** Agentimus could hand back the Markdown edition of a page from the page's own address when a client asked for it (`Accept: text/markdown`). One address with two possible answers is only safe if every cache in front of your site respects "never store this" — and a common CDN setup (Cloudflare "Cache Everything" with an Edge TTL) overrides that instruction, stores the Markdown under the page's address, and then serves it to **human visitors**. It hit this plugin's own author: an AI crawler found a post seconds after publication, asked for Markdown, and readers got raw Markdown until the cache expired. No header an origin can send prevents that, and the person who finds out is your reader — so the convenience is now opt-in. **Nothing is lost:** every page still has its Markdown twin at `/its-slug.md`, a separate address a cache can never confuse with your article, and agents are still pointed to it from the page's `Link` header, from llms.txt and from the discovery documents. If your caching is sound (no CDN, or one that honours `no-store`), turn it back on with one line: `add_filter( 'agentimus_negotiate_markdown', '__return_true' );`

= 1.21.1 =
* Fixed — **A CDN could serve the Markdown copy of a page to human visitors.** Agentimus can answer a page's own URL with its Markdown twin when a client asks for it (`Accept: text/markdown`), and marks that answer "never cache me". A CDN configured to override origin cache headers (Cloudflare "Cache Everything" with an Edge TTL, and the equivalent elsewhere) ignored that, stored the Markdown under the page's URL, and served it to everyone — so a freshly published post, fetched first by an AI crawler, could render as raw Markdown for readers until the cache expired. The no-store instruction is now sent in the CDN-specific headers an edge honours in preference to `Cache-Control`, so the Markdown answer can't be stored. **If your CDN caches it anyway**, the new `agentimus_negotiate_markdown` filter turns page-URL negotiation off entirely; the `.md` address of every page keeps working, and agents find it exactly as before (it's advertised in the page's `Link` header, in llms.txt and in the discovery documents).
* Fixed — **Markdown is no longer served to clients that prefer HTML.** The `Accept` header was matched with a plain substring test, so a request saying "HTML first, Markdown if you must" (`text/html;q=0.9, text/markdown;q=0.8`) was answered with Markdown. Quality values are now honoured as the standard requires: Markdown is served only when the client actually ranks it above HTML, and a tie goes to HTML. No browser sends `text/markdown` at all, so no browser can be answered with it.

= 1.21.0 =
* New — **Manage every client decision in one place.** Settings → AI access gains a "Manage clients" dialog: three tabs — Blocked, Allowed, Ignored — showing each client's identity (for known crawlers), when you decided, and a one-click undo (Unblock, Un-trust, Un-ignore). It's also the first UI over the review queue's "Ignore", which previously could not be seen or reversed anywhere. Decisions made from this release on carry their date; older entries simply show none rather than an invented one.
* New — **Click any day's bar for that day's report.** Both dashboard charts now open a day report. Endpoint activity had one already — it gains a fixed size (no growing mid-load), a clear loading state, and day-to-day arrows. Traffic from AI gets a brand-new one: click a day and see which assistant sent visitors to which page, with the same styled tooltips and navigation. Dialogs close on Esc or the Close button only, so a stray click can't silently drop the report you were reading.
* New — **The admin dresses to match your colour scheme.** If your WordPress admin runs Coffee, Ectoplasm, Midnight or any other colour scheme, Agentimus's score card, buttons and chips now wear that scheme's colour — hand-tuned per scheme so the text always stays readable. The default scheme keeps the design you know; a filter (`agentimus_match_admin_scheme`) turns matching off.
* Fixed — **Your own verification clicks no longer count as agent traffic.** "Verify live" and the exposed-files scan fetch your public endpoints anonymously on purpose — they grade what an agent receives — so each run used to log a handful of "Browser" hits against your own site. Those fetches now carry a short-lived, server-minted token that keeps them out of the visit log; a crawler can't mint or reuse one to hide itself.
* Improved — **Unknown crawlers are named more honestly.** A client that declares its own name ("ethicrawl/0.1 …") now appears in the activity feed under that name instead of a vague "Other bot", and a home-page URL declared in its User-Agent is recognised whether or not it uses the "+https://" convention — so the review queue can show you where a new crawler leads instead of claiming it declares nothing.
* Improved — **A heads-up when blocking is on but verification is off.** Blocking matches names, and real search engines are always let through — so a blocked bot could dodge every rule by calling itself "Googlebot". The blocking section now says so, and points at the reverse-DNS verification toggle that closes the loophole.
* Fixed — **Esc now closes every dialog, every time.** Clicking outside a dialog used to leave Escape unresponsive in six of them (Agent preview among others); all dialogs now listen for Esc for as long as they're open.
* Fixed — the admin footer's version line now aligns exactly with the content edges, and the plugin's uninstall cleans up the new decision-dates option along with everything else.

= 1.20.1 =
* Fixed — **Three read-only abilities can now be run by an outside AI agent, not just listed.** Agentimus's readiness score, AI Visibility results and exposed-files check take no arguments — and a no-argument call through WordPress's abilities REST endpoint was rejected before it ran, so an assistant could *see* these abilities but never actually *use* them. They now run as intended. Your own admin screens were never affected; this only touched external agents calling in.

= 1.20.0 =
* New — **Agent access.** A new screen (More → Agent access) records who authenticates to, and *acts* on, the machine surface Agentimus creates — the other half of the activity log, which shows who *reads* it. It notes when an application password (the key a program uses to reach WordPress as you) is created, first used, renamed or revoked; when one of WordPress's abilities is run; and when a request is refused, or probes for abilities that don't exist. It's a record, not a guard — it never blocks anything — and it keeps Agentimus's no-personal-data promise: no IP addresses, so it names the key that was used, not the person, and it sees machine logins only (a normal password sign-in never appears). A brand-new application password is the one worth a second look — it keeps working even after you change your password. On by default; nothing to configure.
* Security — **The discovery documents now tell agents the truth about your abilities.** The nine read-only abilities Agentimus registers require a signed-in administrator, yet the public discovery file described them as needing no authentication and published their full descriptions and input/output schemas to anyone who asked. Sign-in-only abilities are no longer advertised to anonymous callers (an agent holding real credentials still discovers them the proper way), every document now reports the correct authentication, and turning a resource off now removes it from *every* served file, including mcp.json.
* Security — **A firmer cap on what a spoofed crawler can log.** A flood pretending to be a known crawler — a forgeable name — could write far more to the activity log than intended, and on sites without a persistent object cache, a database write on every request. Recognised crawlers now share one generous budget instead of a budget per name, so faking names no longer multiplies it, and the write pressure is bounded.
* Security — **"Draft with AI" and "Fix with AI" are now rate-limited per user.** These buttons make a paid AI call, so a per-minute cap stops a runaway script (or a compromised account) from running up your AI bill. A person clicking the buttons never notices it.
* Fixed — **Machine-readable output stays clean.** A line break in a page title could forge a stray entry in llms.txt; titles and other values are now kept to a single line. The full-text file (llms-full.txt) had a size budget sitting exactly on the common object-cache limit, so on some hosts it silently never cached and was rebuilt on every request — the budget now leaves headroom.
* Improved — **Multisite reliability.** On a network install, activating no longer risks writing one site's page-address rules into another (which could 404 a sub-site's posts); deleting a sub-site now removes Agentimus's tables with it; and uninstalling cleans up every site, not just the first thousand.
* Fixed — a malformed `?author[]=` request no longer triggers a PHP notice, and several small internal flags are now loaded more efficiently on every request.

= Earlier versions =
The full changelog for every release lives in the plugin repository: https://github.com/heera/agentimus/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 1.22.0 =
One switch now runs an MCP server on your own site — AI tools like Claude Code can use Agentimus's read-only tools, authenticated, permission-checked and fully audited. Off by default. Also: Agent access rows now name the user and key behind every event, and the dashboard's 7/30-day counters count whole calendar days, so they no longer appear to lose hits.

= 1.21.2 =
Important if your site is behind a CDN: answering a page's own URL with its Markdown edition is now off by default, because a common Cloudflare setup could cache that Markdown and serve it to your readers. Every page keeps its Markdown twin at /its-slug.md, and agents are still pointed to it. Recommended for everyone.

= 1.21.1 =
Fixes a bug where a CDN could serve a page's Markdown copy to human visitors (most likely on a freshly published post), and stops Markdown being sent to clients that prefer HTML. Recommended for every site behind a CDN.

= 1.21.0 =
New: manage every client decision (blocked, allowed, ignored) in one dialog with dates and one-click undo; click any day on both dashboard charts for that day's report; the admin matches your colour scheme. Fixes: your own "Verify live" clicks no longer count as agent traffic, and Esc reliably closes every dialog. No breaking changes.

= 1.20.1 =
Fixes three read-only abilities (readiness, AI Visibility, exposed-files check) that an external AI agent could list but not run. No breaking changes.

= 1.20.0 =
New: Agent access — a log of who authenticates to and acts on your site's machine surface (application passwords, abilities, refused probes). Plus security hardening in the discovery documents, the activity log, and the AI-draft buttons. No breaking changes.

