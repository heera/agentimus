=== Agentimus ===
Contributors: heera
Tags: ai-agents, ai-crawlers, agent-readiness, llms-txt, ai-seo
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.24.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site agent-ready for AI assistants — be found, read & cited, see who visits, and write, edit & publish with AI — in wp-admin or via MCP.

== Description ==

Agentimus does two things for the age of AI agents.

**It makes your site legible and citable.** It helps AI assistants like ChatGPT, Claude and Perplexity find your site, read it correctly, and cite it in your own words — and shows you which AI bots are actually visiting. **You don't need to understand AI or web standards to use it:** a setup wizard walks you through everything in about a minute on your first visit, then it runs on its own.

**And it lets the AI tools you already use operate your site.** Turn on the built-in Model Context Protocol (MCP) server and the AI tools you use — Claude Code, Claude Desktop, Cursor, Codex — can read your reports and, behind two more opt-in switches, **draft, edit and publish posts and pages** for you. Every write runs as the signed-in WordPress user, permission-checked and audited; all three switches are off by default. And when you'd rather stay in wp-admin, a built-in **writing assistant** drafts and revises posts for you — no external tool needed. (Full details below.)

You also get a first-party log of every AI crawler that fetches your content, one-click blocking for bots you don't want, and a dashboard that scores your agent readiness — one AEO/GEO score across five rungs, with per-page tips and always the next thing to improve.

By default it makes no outbound requests, collects no analytics, and logs no IP addresses — everything runs on your own site. Optional, off-by-default features change that only when enabled: **AI Visibility** queries an AI provider you choose (your own key), **Verify bot identities** makes DNS lookups and fetches operators' published crawler-IP lists, and **Store IP addresses** records IPs for flagged crawlers only (see *External services*).

**📖 Full documentation** — a plain-English user manual and a developer reference, with step-by-step guides for every feature: https://heera.github.io/agentimus/

**Operate your site from your AI agent (MCP) — opt-in**

* **A Model Context Protocol server on your own site** — one switch (Settings → Discovery) runs an MCP server at `/wp-json/agentimus/v1/mcp`; the whole library ships with the plugin, nothing extra to install. The AI tools you already use — **Claude Code, Claude Desktop, Cursor, Codex** — connect to it, and the card writes the exact setup for your tool: pick it, mint a key, copy the config, and a Test button proves the connection.
* **Read your site's data** — connected agents can run the read-only tools (readiness/AEO-GEO score, AI traffic, request log, bot identification, and page / JSON-LD / Markdown previews) — the same ones WordPress's built-in AI gets.
* **Draft, edit and publish posts — behind two more switches** — turn on **Let connected agents write** and the agent can create and edit posts and pages fully dressed (categories, tags, featured image, AI topics and descriptions) and apply Readiness fixes; turn on a third switch and it may publish, otherwise it leaves drafts for your review.
* **Safe by construction** — every write runs as the signed-in WordPress user, never exceeding their permissions, and is recorded under **More → Agent Access**, attributed to the key. Nothing is public (each call signs in and keeps its screen's permission check), and with the write switch off the write tools don't exist on any surface. All three switches are off by default.

**Write with AI in wp-admin — the built-in assistant (opt-in)**

* **Idea → draft without leaving wp-admin** — a quill button opens the writing assistant: describe the post, edit the outline it proposes, and preview the complete draft — real editor blocks, AI description, topics, categories, tags. Nothing is saved until you click **Create draft**, and it never publishes.
* **Edit existing posts** — describe the change and it revises the content; a post's status never changes, and WordPress revisions keep every prior version.
* **Images where you write** — drafts arrive with alt-filled image placeholders; every image block gains **Generate image from the alt text**, the sidebar a **Featured image (AI)** panel — or pick from your library.
* **Ask AI on any block** — rewrite, shorten or extend a paragraph, heading, list or quote with one instruction; normal undo brings anything back. Everything runs on WordPress's built-in AI Client (7.0+, Settings → AI — Agentimus never sees your key), the same connection behind **Draft with AI** and **Fix with AI**, and every AI button hides until a provider is set up.

**Control — who may use your content**

* **robots.txt content-signals + AI-training blocklist** — declare your content-usage policy and block named model-training crawlers (GPTBot, CCBot, ClaudeBot, Google-Extended, Bytespider, …) by name, while leaving read/cite bots free.
* **Block scanners & scrapers (opt-in hard block)** — robots rules are a polite request; this enforces them, returning 403 to the user-agents on your denylist (and, optionally, agents disguised as ancient handsets — a classic scanner trick). Your **always-allowed** list is never blocked: pre-trust well-known AI assistants with one click; major search engines are recognised automatically, and SSL-renewal requests always stay reachable.

**Reduce exposure — what your site reveals to bots**

* **Exposure controls (opt-in, all OFF by default)** — switches that quietly close what stock WordPress reveals to anonymous crawlers: username enumeration, author archives, the WordPress version, the auto-generated `<head>` discovery links, and XML-RPC. Nothing changes until you turn one on, and signed-in admins and the block editor are never affected. Exposure hygiene, not a firewall.

**Visibility — who is reading you**

* **Agent activity log** — a dashboard of which AI crawlers and agents actually fetch your content and endpoints (GPTBot, Claude, Perplexity, Googlebot, …), recorded first-party in your own database, with no IP logging by default (an optional setting stores IPs for flagged crawlers only).
* **Activity to review** — a nav-bar queue surfaces the clients worth a second look — new, unusually high-volume, or spoofing what they are — names a recognised crawler where it can, and offers one-click **Block** or **Allow** (trust). Nothing is blocked unless you choose to.
* **Request Log** — every recorded request, one row each, under *More → Request Log*. Filter by client, endpoint, network, user-agent and date to see exactly what a single bot fetched.
* **Agent Access** — the other side of the log: who *authenticates to and acts on* the machine surface Agentimus creates (*More → Agent Access*): application passwords created, used, renamed or revoked; abilities run; requests refused. A record, not a guard — no IP logging, it names the key used, not the person. A brand-new application password is worth a look: it keeps working even after you change your password.
* **Traffic from AI** — the mirror of the crawler log: the real visitors an AI assistant sent you, day by day, by assistant and by landing page (*More → AI Traffic*) — daily aggregate counts, never a row for one person, no IP. An opt-in **CDN mode** keeps counts accurate behind a full-page cache, and a **Find missed AI sources** diagnostic surfaces referrers Agentimus couldn't name.
* **You decide how long it's kept** — a retention period, nightly auto-delete, and a hard size cap that always applies (Settings → Visit log), so the log can never grow without limit on your host.
* **AI Visibility (opt-in)** — track **each brand, product or person you choose** across ChatGPT, Perplexity, Gemini and Claude. For every one, Agentimus asks the questions your audience actually types and reports whether it gets **mentioned, linked, and how it ranks against its rivals** — over time. Tell it what each thing *is* (*"a WordPress SEO plugin"*) and it suggests the questions a buyer really types. Off by default; **you bring your own API key**, and this is the one feature that makes an outbound request (see *External services*).

**Content — clean, machine-readable output**

* **Markdown delivery** — request any page as clean markdown by appending `.md` to its URL. (Answering the page's own URL with markdown via an `Accept: text/markdown` header is also supported, but off by default — one URL with two possible bodies is unsafe behind a force-caching CDN; enable it with a one-line filter where your caching is sound.)
* **/llms.txt** & **/llms-full.txt** — an [llmstxt.org](https://llmstxt.org) index of your pages, topics and recent posts, plus a full-text edition an agent can ingest in a single request.
* **JSON-LD** — WebSite + Person/Organization, plus BlogPosting and BreadcrumbList on posts. Automatically **defers to Yoast, Rank Math, SEOPress, AIOSEO and The SEO Framework** so you never ship duplicate schema.
* **Topics for AI** — say what each post is about in plain words, right in the editor; those topics become the JSON-LD `keywords` and a line in the page's `.md`, so assistants understand each page's subject. Type your own, or let Agentimus fill them in from the post's own tags and categories. Nothing shows on the visible page.
* **AI description** — write a one-line summary of each post in the editor; it becomes the JSON-LD `description`, the lead of the page's `.md`, and the page's `<meta name="description">` (replacing your theme's, unless an SEO plugin owns it). Blank falls back to the excerpt. A sub-switch can keep it out of your `<head>`.
* **XML sitemap** — an opt-in fallback sitemap (index + paginated sub-sitemaps), generated only when neither WordPress core nor an SEO plugin already provides one, and advertised in robots.txt and llms.txt.
* **Change feed** — a JSON feed at `/agentimus-changes.json` lists your recently added, updated and removed pages, with a `?since=` filter, so an assistant re-checks only what changed instead of re-reading your whole site. On by default and advertised in your discovery document.

**Identity & contact**

* **Author / site identity** — a profile sentence, expertise topics and linked profiles (`sameAs`) feed llms.txt and JSON-LD — the highest-signal lines for agent retrieval.
* **security.txt** — optionally publish an RFC 9116 disclosure contact at `/.well-known/security.txt`, so researchers and agents have a machine-readable way to report an issue.

**Readiness report**

* A one-screen score of how machine-readable your site is, with a plain-English checklist of what's enabled and what's still missing.
* **Agent preview** — open it from the Readiness tab to see the exact JSON-LD *and* Markdown an AI agent receives for the whole site or any page, then copy it. It shows what would ship even when the feature is off or an SEO plugin owns your schema, and a matching read-only preview sits in the post editor — so you never view page source to check what agents read.
* **AI Readability tips** — as you write, an "AI Readability" panel flags what makes a page hard for an assistant to read and cite: thin content, missing headings, no opening summary, a nav-heavy page, or images without alt text. It sits in the same "Agentimus" box as the per-page Agent preview, so you check what an agent receives *and* how readable it is in one place. Editor-only — nothing shows to visitors.

**Machine discovery (forward-looking)**

Agentimus also publishes a single, normalized discovery document, built to the conventions the agent ecosystem is converging on (`.well-known`, A2A agent cards, MCP-shaped tools). It puts a site's identity, capabilities and APIs in one predictable place:

* **/.well-known/discovery.json** — an owner-curated document describing the site's identity, capabilities, APIs and agent cards. Other plugins can declare themselves through a single optional hook, so what an agent needs is aggregated in one place.
* **/.well-known/agent-card.json** and **/.well-known/mcp.json** — an A2A agent card and an MCP manifest, generated automatically.
* **Standards-aligned `.well-known` endpoints** — an RFC 9727 `api-catalog`, plus — *only when the capability actually exists* — an MCP server card and an Agent Skills index. Optional **response signing** (Web Bot Auth / HTTP Message Signatures, RFC 9421) signs the discovery documents with an Ed25519 key so agents can verify they came from you; on by default, and the private key stays on your server.
* **WordPress Abilities API** — Agentimus registers its own **read-only abilities** (readiness/AEO-GEO score, AI traffic, request log, bot checks, and page / JSON-LD / Markdown previews), so WordPress's built-in AI — and, with the MCP adapter, external agents — can read them, each gated by the same capability as its screen. A separate, off-by-default switch adds the write abilities above. It also projects *any* plugin's abilities into MCP-shaped tool descriptors, and links a running MCP server when one is installed.
* **Zero-config auto-discovery** — reads your registered REST API namespaces, public post types and the WordPress Abilities API, so a site is described even when no plugin declares itself. A **Discovery Hub** admin screen shows what an agent can see, and you decide what is published.

**What's read today vs. what it readies you for**

The content signals above (JSON-LD, robots, llms.txt, markdown) are read by search engines and AI tools **today**; the discovery document is **forward-looking and standards-aligned**, preparing your site for agents as they adopt these conventions. It's an open convention with a public reference, and the plugin works fully whether or not anything consumes it.

**Why it's useful**

Most tools cover one slice — an llms.txt file, a bot blocker, or structured data. Agentimus brings content control, agent-traffic visibility, machine-readable output, in-admin AI writing and a forward-looking discovery document together in one lightweight package — and tells you what's still missing.

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

By default, no — Agentimus makes no outbound HTTP requests out of the box, sends nothing to any external service, collects no analytics or telemetry, and stores the agent-activity log in your own database with no IP addresses. (One opt-in setting, *Store IP addresses for flagged clients*, can store IPs locally for flagged crawlers only — off by default; see *External services*.) Two opt-in features go outbound. **Verify bot identities** makes DNS lookups and downloads, once a day, the crawler-IP lists that bot operators publish (Google, OpenAI, Perplexity, …) so impostors can be caught — only those public files are fetched, and nothing about your site is sent. **The other is the optional AI Visibility feature:** if you enable it and add your own API key, Agentimus queries the AI provider(s) you chose (OpenAI, Perplexity, Gemini and/or Anthropic) to check whether they mention and cite you — only for the engines you turn on, and only when a check runs (on demand or on your schedule). Your keys stay on your server and nothing else is sent anywhere. See *External services* for the full disclosure. The discovery document includes a `$schema` value that *identifies* the document format (the same way a schema.org URL identifies a vocabulary); it is a label in the output, never fetched. The one place a request is made is the optional "Verify live" self-check on the readiness report — and that runs in *your browser*, fetching your own public URLs only when you click it; the server itself still makes no request.

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

Yes — as an opt-in, on WordPress 6.9 or newer. Turn on **Settings → Discovery → MCP server** and AI tools you already use (Claude Code, Claude Desktop, Codex) can talk to your site over the Model Context Protocol and run the same permission-checked tools your admin AI gets — nine read-only ones (readiness, AI traffic, bot identification, per-page readability and previews), plus the write tools if you separately allow those (see the next question). The switch card writes the exact setup for your tool — pick it, mint a key with one click, copy the result — then a Test button proves the connection with the same calls the tool will make. (ChatGPT itself can't connect — its connectors only support OAuth sign-ins, which WordPress logins aren't — though Codex inside the ChatGPT desktop app connects fine via the Codex setup.) Nothing becomes public — every request has to sign in with a WordPress login, each tool keeps the same permission checks as the admin screens, and every call is recorded under **More → Agent Access**. Off by default, and everything needed ships with the plugin.

= Can an AI agent write to my site? =

Only if you say so, twice. The MCP server starts read-only; a second switch — **Let connected agents write** — adds the write tools: draft and edit posts and pages complete with categories, tags and a featured image (from your media library, or imported from a URL), set their AI topics and descriptions, and apply Readiness fixes (a fixed list of safe switches that can only turn documented features on, never loosen a protection). Even then, agents can't publish: they leave drafts and pending posts for your review, unless you flip a third switch that allows going live. Every write runs as the signed-in user — an agent can never do more than that user could in the editor: filing under existing categories, creating new ones, and uploading images each follow that user's own permissions — and every call is recorded under **More → Agent Access**, attributed to the key that made it.

= Can AI help me write the description, topics and fixes? =

Yes, if you're on WordPress 7.0 and have set up an AI provider under Settings → AI. Then **Draft with AI** appears on the AI description field, **Suggest with AI** on the Topics field, and **Fix with AI** on any AI Readability row that needs work. Agentimus asks *your* AI through WordPress's shared connectors — it never sees or stores your API key, and nothing is sent anywhere if you haven't set a provider up (the buttons simply don't appear). Every suggestion arrives as ordinary editable text in the field: you read it, change it, and save the post yourself. Nothing is written for you.

= Can AI write a whole post for me? =

Yes — the writing assistant (the quill button on Agentimus's own screens) turns a described idea into a complete draft. It proposes an outline you can edit first, then writes the title, body, AI description and topics with suggested categories and tags, and shows you everything before a single thing is saved. **Create draft** opens the post in the editor, where image placeholders arrive with their alt text ready — fill them from your library, or generate them with AI. It can also revise an existing post you pick, without ever changing its status. It needs the **Let connected agents write** switch on and an AI provider under Settings → AI, and it never publishes: drafts and pending review only.

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

= Does Agentimus store IP addresses? =

Not by default. The agent-activity log records who fetched your endpoints with no IP addresses at all. One opt-in setting — *Store IP addresses for flagged clients* — records the IP only of clients flagged as impersonating or spoofed crawlers (never ordinary traffic), so you can block them at your host or CDN. When on, those IPs are personal data: stored on your own site only, kept for a short retention period, cleared when you clear the activity log, and deleted if you turn the setting back off. Nothing is ever sent off your server. If you enable it, disclose it in your privacy policy (Agentimus adds suggested text at Settings → Privacy).

= Is the admin interface built from source I can inspect? =

Yes — there is no minified-only code. The admin interface is built from Vue 3 source in `resources/` with Vite; the source and `vite.config.js` ship in this package and also live in the public repository at https://github.com/heera/agentimus . Run `npm install && npm run build` to regenerate `assets/admin/` from source.

== Screenshots ==

1. Dashboard — your AEO/GEO score across five plain rungs (Findable, Readable, Trusted, Optimized, Cited) with the one next step worth taking, alongside a first-party log of which AI agents and crawlers fetched your endpoints (no IP logging) and whether each client's volume is trending up or down. Every day bar on both charts opens that day's full report.
2. Settings — a tidy, tabbed control panel; the Discovery section gives you a toggle for each agent-readiness signal, cards for Topics for AI and the per-page AI description, plus experimental browser tools (WebMCP) that let an in-browser AI agent call your site search.
3. Readiness report — a plain-English pass/warn checklist of what's enabled and what's still missing, and beneath it the Optimize worklist: exactly which pages an answer engine would struggle to quote, and why. Set aside anything that isn't meant to be cited.
4. Discovery Hub — everything your site tells AI agents, in one place: the providers describing it, the read capabilities they expose, the public APIs, and the MCP & tools surface — your own Model Context Protocol server (when you've turned it on) alongside the WordPress Abilities API, with the tools each carries. Each summary tile jumps to its own countable list, and any registration problem is listed with a plain-English fix.
5. Crawler policy & scanner blocking — declare your content-usage signals, block AI-training crawlers by name, turn away spoofed traffic and proven impostors, and verify bot identities against what each operator publishes: reverse DNS for the search engines, published IP-range lists for GPTBot, OAI-SearchBot and PerplexityBot. The Verified bots registry is yours to edit — switch any bot off, or add a new operator yourself — and a "Manage clients" dialog holds every standing decision with its date and a one-click undo.
6. Activity to review — a nav-bar alert surfaces new, high-volume or spoofed clients from any screen. Genuine ones you Allow or Block by name in one click; a client that conclusively failed its operator's own published check (an impersonator) can't be trusted by name, so its card says exactly how it was caught — and, with blocking on, that it's already refused at the AI endpoints. New clients leave the queue by themselves after 48 hours; flagged ones stay until you decide. No IP logging by default; an optional setting can store IPs for flagged crawlers only.
7. About — a plain-English account of every feature and what it publishes, a privacy & data section (no outbound calls, no IP/PII by default, signing key stays on your server), the open WP_Discovery Protocol it implements, and an FAQ.
8. Exposure controls — opt-in, off-by-default switches that limit what anonymous crawlers can read about your site: username enumeration, author archives, the WordPress version, auto-generated head links, and XML-RPC.
9. AI Visibility — an opt-in, bring-your-own-key scoreboard showing whether ChatGPT, Perplexity, Gemini and Claude mention and link each brand, product or person you track: seen-in-answers and linked-your-site rates, rank against each item's own rivals, and question-by-question results with the sources each engine cited. Off by default; you bring your own API key and nothing runs until you enable it.
10. In the post editor — the "Topics for AI" panel: say in plain words what a page is about, one chip at a time, or leave it blank and let Agentimus fill them in from the post's tags and categories (those arrive marked *auto*); either way the topics flow into the page's JSON-LD keywords and its .md edition. Where you've set up an AI provider in WordPress, "Suggest with AI" drafts them from the page itself. Nothing shows to visitors.
11. In the post editor — the "Agentimus" box, AI Readability tab: a per-page pass/warn check of what makes the page hard for an assistant to read and cite — enough substance, an opening summary, section headings, heading order, prose vs links, and image alt text. Each row that needs work offers "Fix with AI", which drafts a concrete fix using the AI provider you set up in WordPress (nothing is saved for you, and without a provider the button simply isn't there).
12. In the post editor — the "Agentimus" box, JSON-LD tab: the exact structured data the page emits in its `<head>`, with a copy button and Google Rich Results / Schema.org validator links.
13. In the post editor — the "AI description" panel: a one-line summary of the page for AI assistants. It feeds the page's structured data and its .md edition, and becomes the page's meta description unless a dedicated SEO plugin manages that. Leave it blank and Agentimus falls back to the excerpt — or click "Draft with AI" to have your own AI provider write it from the page.
14. Request Log — every request an agent made, in one filterable table: narrow by client, endpoint, network, verification verdict, User-Agent or date to see exactly what a single bot fetched. Repeat hits are grouped, and your own logged-in visits are never recorded. Records are kept for the last 30 days (or until the size cap), then trimmed — so read a full page as a floor, not a total.
15. The More menu — the occasional screens (AI Visibility, AI Traffic, the Request Log, Agent Access and About Agentimus) fold behind one control, so the main navigation stays short. Anything not yet enabled appears as "Turn on in Settings" rather than hidden, so you always know it's there.
16. AI Visibility settings — each thing you track gets a name, a category ("what kind of thing is it?"), its website, its rivals and the questions to ask. Tell Agentimus the category and it suggests the questions a buyer really types — or, where you've set up an AI provider in WordPress, "Suggest with AI" asks it for a wider spread. Suggestions are only ever offered; you pick which to keep, and every setting on the screen saves as you change it.
17. Agent Access — the other side of the log: who authenticates to, and *acts* on, the machine surface Agentimus creates. Application passwords being created (a brand-new one is worth a second look — it keeps working even after you change your password), first used, renamed or revoked; WordPress abilities being run; and requests that were refused, or that probed for abilities that don't exist. A record, not a guard — it never blocks — and with no IP logging, it names the key that was used, not the person.
18. Client decisions — everything you've decided about visiting bots and agents in one dialog: Blocked, Allowed and Ignored tabs, each row with the crawler's identity, the date you decided, and an instant undo. The only place to see (and reverse) clients you ignored from the review queue.
19. A day in your AI traffic — click any bar on the dashboard's Traffic-from-AI chart and see exactly which assistant sent visitors to which page that day. Days are the finest "when" stored, so there are no per-visit times — by design.
20. MCP server — one switch runs a Model Context Protocol server on your own site, and the card connects the AI tool you already use: pick it, mint a key in one click, copy the finished setup, then prove the connection with the built-in test. Below it, the trust ladder: a second switch lets connected agents write (drafts, edits, categories, tags, featured images, AI topics and descriptions, Readiness fixes), and a third decides whether they may publish or only leave drafts for your review.
21. The writing assistant — a quill button on every Agentimus screen opens the drawer: describe the post you want, edit the outline it proposes, preview the fully dressed draft, then create it as a draft and land straight in the editor. Nothing is saved until you say so, and it never publishes.
22. AI in the editor — drafts arrive with alt-filled image placeholders and a one-click "Generate image from the alt text" button on every image block; "Ask AI" rewrites, shortens or extends any text block; and a "Featured image (AI)" panel drafts the hero from the title.

== External services ==

Agentimus makes no outbound requests by default: no remote scripts, fonts or analytics, and the agent-activity log stays in your own database with no IP addresses. (IP storage is optional, off by default — see the FAQ.)

**Two opt-in features go outbound; both are off by default.**

**Verify bot identities** makes DNS lookups and, once a day, downloads the IP-range files bot operators publish to verify their crawlers (Google, Microsoft, DuckDuckGo, Apple, OpenAI, Perplexity — or a URL you add yourself). Only those files are fetched; nothing about your site is sent.

**AI Visibility:** when you enable it and add your own API key for a provider, Agentimus sends the prompts you configured to that provider to check whether it mentions and cites your site — only for the engines you turn on, and only when a check runs. Your keys are stored on your own site and used solely for these calls. The providers, with their terms and privacy policies:

* **OpenAI (ChatGPT)** — https://openai.com/policies/terms-of-use · https://openai.com/policies/privacy-policy
* **Perplexity** — https://www.perplexity.ai/hub/legal/terms-of-service · https://www.perplexity.ai/hub/legal/privacy-policy
* **Google (Gemini)** — https://ai.google.dev/gemini-api/terms · https://policies.google.com/privacy
* **Anthropic (Claude)** — https://www.anthropic.com/legal/consumer-terms · https://www.anthropic.com/legal/privacy

URL-like strings in the plugin's output are labels, not requests — the discovery documents' `$schema` value names the format (never fetched), and the `example.com` URLs in `examples/` are documentation placeholders.

== Changelog ==

= 1.25.0 =
* New — **A writing assistant inside wp-admin.** A quill button opens a drawer on every Agentimus screen: describe the post you want, edit the outline it proposes, and preview the complete draft — real editor blocks, AI description, topics, suggested categories and tags — before anything is saved. Create draft opens the post in the editor; the assistant never publishes. It needs the agent-writes switch and a WordPress AI provider (7.0+), and your Content Guidelines steer its voice automatically.
* New — **It edits existing posts too.** Pick a post, describe the change, and review the revision before applying it. A post's status is never touched — published stays published — WordPress revisions keep every prior version, and unusual block layouts are declined honestly instead of mangled.
* New — **Images live where you write.** Drafts arrive with alt-filled image placeholders; every image block gets **Generate image from the alt text**, and a **Featured image (AI)** sidebar panel drafts a hero from the title — or pick from your library as always.
* New — **Ask AI on any block.** Select a paragraph, heading, list or quote and say what to change — rewrite, shorten, or "add a conclusion after this". Unchanged text is preserved word for word, and normal undo brings anything back.
* New — **A featured-image check, with the fix one click away.** AI Readability now checks that a post has its featured image — the picture link previews and embeds show — and skips honestly where a content type offers none. In the block editor the warning carries **Generate with AI**: the same title-seeded featured-image generation, offered exactly where the gap is reported.
* New — **Every dashboard number is a door.** The By-endpoint and Top-clients rows open the Request Log pre-filtered to that endpoint or client, and the Traffic-from-AI sources and landing pages open the AI Traffic report the same way. Hovering any row shows the numbers behind it: a trend arrow reveals the two half-window counts it was computed from, a referral row its share of the window — no arrow or percentage asks to be taken on faith.
* Improved — **One Agentimus box in the editor.** The AI description and Topics panels merged into the branded Agentimus box, and AI Readability gained two checks: cited sources and reading ease.
* Improved — **The admin wears its marks.** Every navigation tab and dashboard tile carries a small icon in the house style — the Discovery-listed APIs join the tiles as a fourth count — and the masthead, gold-gem arrow included, is a real link back to the plugin page. Report screens now read as card stacks: AI Traffic splits into overview, top sources & landing pages, and a by-day timeline; the dashboard's endpoint and client lists become cards of their own with plain-language leads; scanner blocking hands its bot-identity half a card of its own; and the Advanced tab's developer & maintenance zone sits behind a labelled rule instead of an amber tint.
* Improved — **The assistant's drawer is easier to leave and harder to mis-click.** A ← Back beside the Close walks you up a level, the revise bar lives in the pinned foot so it's always in reach, editing a draft says **Update draft** while a published post keeps its stays-published warning, and every consequential act — revising, updating, creating, drafting over held work — states its consequence in a small confirm first. The drawer is a little wider, and the post picker explains its list: your ten most recently edited, with search reaching every post.
* Improved — **Charts you can tap on a phone.** The daily bars keep a touch-friendly width and scroll sideways, opening at the newest days; on the AI-traffic chart even a quiet day is a full-height tap target now.
* Improved — **AI errors in plain language.** A quota wall now names the fix (pick from the library, or check the provider's plan), a provider error that arrives without details still becomes a human sentence, and no AI failure returns a bare 502 — a status some CDNs replace with their own error page.
* Improved — **The Cited rung updates itself.** The AEO/GEO score rail refreshes the moment an AI Visibility check finishes or its results are cleared — no more waiting for the next visit to notice.
* Fixed — **Agent field edits refresh the public files.** When a connected agent set a page's AI description or topics over MCP (a meta-only write), the database changed but cached public surfaces — the page's .md twin, llms.txt — could keep serving the old text until the next real save. Those writes now fire the same cache flush a normal save does.

= 1.24.0 =
* New — **Verify more bots — and edit the list yourself.** Verification used to cover the five search engines that publish reverse DNS. Agentimus now also checks a bot against the IP ranges its operator officially publishes, which adds GPTBot, OAI-SearchBot and PerplexityBot — and the whole list is yours: Settings shows every verified bot with how it's checked, any built-in can be switched off, and when a new operator starts publishing verification data you can add it yourself instead of waiting for a plugin update. The range lists refresh once a day in the background, never while serving a visitor. An unreachable publisher just means "not checked": a match can verify against an older copy, but only a fresh copy may ever call a bot fake.
* New — **A proven impostor is refused, not just flagged.** With blocking and verification both on, a client caught lying about who it is — a fake "GPTBot", a forged "Googlebot" — is turned away from the AI endpoints with a 403, as part of the auto-deny-spoofed switch (an identity forgery is the same kind of deception, just proven). Your rules still outrank everything: an agent on your Allow list is never denied, a bot on your Block list stays blocked even when it verifies as genuine, and anything unclear — slow DNS, a stale range list — is served, never guessed at.
* Improved — **The review queue explains its own housekeeping.** A client that's merely new leaves the queue by itself 48 hours after its first visit; that always happened silently, and looked like a bug. Its chip now counts down ("New · leaves in 31h"), and a fixed footer says who leaves on their own, that flagged clients stay until you act, and that every request stays in the log — with a Manage clients link right there. The queue's title and filter tabs stay put while the list scrolls, and an impostor's card now says exactly how it was caught ("its address isn't in GPTBot's published IP ranges") and notes when it's already being refused.
* Fixed — **The admin fits your phone.** No more sideways panning on narrow screens, dialogs size themselves to the real visible area (the Close button can no longer land beyond the screen's edge), and the About page no longer clips its text.
* Changed — screen names are consistently title-cased: AI Traffic, Request Log, Agent Access, About Agentimus.

= 1.23.0 =
* New — **Agent access alerts on every visit.** The nav pill now re-lights whenever an agent authenticates or acts — not only the first time ever — and the log lists the newest activity first. The screen also gained its proper page header.
* New — **Your voice, everywhere AI writes.** If your WordPress defines Content Guidelines (the experimental brand-voice and copy-rules feature), Agentimus honors them automatically: Draft, Suggest and Fix with AI follow them, and AI agents connected over MCP see them right on the draft/edit tools — so agents write in your voice too. Nothing to configure; your guidelines never appear on any public endpoint.
* Improved — **Markdown twins ready for WordPress 7.1.** The new Tabs block converts with each tab clearly labelled, Playlists become a readable track list, tables come out as real Markdown tables instead of run-together text, and preformatted blocks keep their line breaks — diagrams built in the visual editor no longer flatten to one line.
* Fixed — **Full description on WordPress.org.** The plugin directory folds extra readme sections into the description before applying its word limit, so the listing was still truncated; two sections moved into the FAQ and everything shows again.

= 1.22.2 =
* Fixed — **Clicking a summary tile now lands the section in view.** On the Discovery screen, jumping to Providers, Capabilities, APIs or Tools scrolled the section's heading up under the pinned header; it now lands just below it, so you see the heading you asked for.
* Fixed — **No more "401" in the browser console.** The MCP server settings card checked whether the server was answering by pinging its sign-in-only endpoint without credentials — a harmless 401 that the browser nonetheless logged as a red error on every admin page. It now asks over an authenticated route instead: same "Running" status, quiet console.
* Improved — **A tidier Discovery screen.** The discovery documents were listed in three places at once; the endpoint card no longer repeats them. The always-visible rail keeps its quick links, and the "Well-known documents" section keeps the full inventory with each file's generated/managed status — each with its own job.
* Improved — **AI Visibility explains its own keys.** The settings now say, in place, why AI Visibility connects to each engine directly instead of using WordPress's shared AI connector: a visibility check is graded on the sources each engine *cited* and compares several engines at once — neither of which the shared connector can provide.

= 1.22.1 =
* New — **Connected AI tools can write now — each step behind its own switch.** The MCP server card grows a trust ladder. A second switch (off by default) gives connected agents five write tools: draft a new post or page, edit an existing one, set a page's AI description and its Topics for AI, and apply a Readiness fix — where "fix" means exactly what the readiness check itself recommends, and only ever *enabling* a plugin feature; a fix that needs your judgement or a server change refuses honestly and names the manual step instead. A third switch decides publishing: while it's off, an agent can only leave drafts and pending posts for your review. Every write runs as the WordPress user behind the key — an author's key can't do editor things, a new category still needs the category permission, exactly as in wp-admin — and every write is recorded under More → Agent access, attributed to the key that made it. With the writes switch off the tools aren't just refused, they don't exist on any surface — MCP, the abilities endpoint, discovery — so "read-only" stays literally true; and switching the MCP server off switches writes (and publishing) off with it, so the ladder can never disagree with itself.
* New — **An agent can dress a post completely.** Creating and editing now also carry categories and tags (by name, with wp-admin's exact permission rules: any author can create a tag, only category managers can create a category) and the featured image — an attachment you already have, or an image URL the plugin imports into your media library, alt text included, gated on the user's upload permission and your site's file-type and size rules. Draft it, dress it, leave it for review: no wp-admin visit required.
* New — **The MCP card connects your AI tool for you.** Pick the tool you use — Claude Desktop, ChatGPT/Codex, Claude Code — and the card mints a named application password in one click (or takes one you saved), hands you a finished setup with the endpoint and login already woven in, and proves the connection with a built-in test.
* Improved — **The score card says how much, not just what's next.** Each rung now carries a quiet "n to fix" count, an unmeasured Cited rung says "not measured yet" instead of a dash that read as broken, and the "Next:" line explains *why* in a tooltip. Grading also stops nagging about pages nobody authored — a shop's cart and checkout pages, and container pages whose only content is a plugin's shortcode — so the Optimize rung measures your writing, not your plugins'.
* Fixed — **The Discovery hub explains its own numbers.** Its tiles reconcile what your site has with what anonymous agents are shown — "3 providers — 1 public · 2 sign-in only" instead of a bare "1" above a three-row list. The "MCP & tools" card now states one total and splits it by provider — "Agentimus abilities: 14 · Core abilities: 2", adding up in plain sight — with the doors that serve each group (the MCP server, the Abilities API route) named on the group itself and their endpoints listed without counts, so there are no two overlapping numbers left to wrongly add together. And the card no longer claims "no agent tools yet" when every tool is simply sign-in-only and deliberately kept out of the public documents. One genuine counting bug fell out of the recheck too: an ability hidden from REST — which no agent can run remotely — no longer inflates any tool count, so the numbers shown are the numbers the endpoints actually answer. And the Content types setting now previews, live, exactly which read capabilities your selection advertises — a ticked type brings its public taxonomies along (posts add categories and tags) — so the capabilities number finally traces back to the switch that produces it.
* Fixed — **WebMCP follows the browser's move.** Chrome relocated the WebMCP entry point (navigator.modelContext → document.modelContext); the search tool now registers on the current name and still recognises the old one, so it keeps working as browsers retire the alias.

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

= 1.25.0 =
Write with AI without leaving wp-admin: a built-in assistant drafts new posts (outline first, preview always, never publishes), revises existing ones without touching their status, and generates images right in the editor. Plus plain-language AI errors and a self-updating Cited rung. No breaking changes.

= 1.24.0 =
Bot verification grows: bots are also checked against their operator's published IP ranges (GPTBot, PerplexityBot, …), the verified list is now yours to edit, and with blocking on a proven impostor is refused — not just flagged. Plus a clearer review queue and a phone-friendly admin. No breaking changes.

= 1.23.0 =
Agent-access alerts now fire on every visit, Markdown output is ready for WordPress 7.1's new blocks, and your Content Guidelines steer every AI draft — in the editor and over MCP. No breaking changes.

= 1.22.2 =
Polish and fixes: clicking a Discovery tile now lands the section in view, the MCP settings card no longer logs a 401 in the browser console, the Discovery layout is tidier, and AI Visibility explains why it uses its own keys. No breaking changes.

= 1.22.1 =
New opt-in write tier: connected AI tools can draft, edit and fully dress posts — categories, tags, featured image — and, behind a separate third switch, publish. Off by default, permission-checked per key, every write audited. Plus: the MCP card now connects your AI tool for you, and the score card counts what's left to fix.

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

