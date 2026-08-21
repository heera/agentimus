=== Agentimus – AI SEO, llms.txt & MCP for AI Agents ===
Contributors: heera
Tags: ai-agents, mcp, agent-readiness, llms-txt, ai-seo
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.41.0-dev4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site agent-ready: AI SEO (AEO/GEO), llms.txt & bot control — be found, read & cited by AI — and write, edit & publish via MCP.

== Description ==

Agentimus is an all-in-one AI SEO plugin for the age of AI agents — AEO (Answer Engine Optimization) and GEO (Generative Engine Optimization) in one place. It does two things.

**It makes your site legible and citable.** It helps AI assistants like ChatGPT, Claude and Perplexity find your site, read it correctly and cite it in your own words — and shows you which AI bots are visiting. **You don't need to understand AI or web standards:** a setup wizard walks you through it in about a minute, then it runs on its own.

**And it lets the AI tools you already use operate your site.** Turn on the built-in Model Context Protocol (MCP) server and Claude Code, Claude Desktop, Cursor or Codex can read your reports and — behind two more opt-in switches — **draft, edit and publish posts**, every write running as the signed-in user, permission-checked and audited. All three are off by default. Prefer wp-admin? A built-in **writing assistant** drafts and revises posts there.

By default it makes no outbound requests, collects no analytics, and logs no IP addresses — everything runs on your own site. Three optional, off-by-default features change that only when you enable them: **Citation checks**, **Verify bot identities** and **Store IP addresses** (each disclosed in full under *External services*).

**📖 Full documentation** — a plain-English manual and developer reference, step-by-step guides for every feature: https://heera.github.io/agentimus/

**One screen that says what needs you**

* **Findings** — everything open across your site in one ranked list: pages losing a click they already earn, and anything the setup checks caught — ordered by what each costs you, every row carrying the button that lands on the fix. The nav count only measures work you can act on.
* **Your Content** — one row per post, page or product: what it is found for, whether it answers that, and anything else it needs. Set aside anything never meant to be quoted and it stays listed, not silently dropped.

**With an SEO plugin — or instead of one**

* **No SEO plugin? You don't need one.** Agentimus covers the search basics: per-page SEO titles, social share cards (Open Graph), canonical links, meta descriptions, and an XML sitemap carrying the last-changed dates core's own leaves out.
* **Already running Yoast, Rank Math, SEOPress, AIOSEO or The SEO Framework?** It detects them and steps aside on every overlapping surface — titles, cards, schema, sitemap. No duplicate tags: it adds only the AI layer your SEO plugin doesn't cover.

**Operate your site from your AI agent (MCP) — opt-in**

* **A Model Context Protocol server on your own site** — one switch runs an MCP server at `/wp-json/agentimus/v1/mcp`; the library ships with the plugin, nothing extra to install.
* **Connect by approving, not by pasting keys** — an assistant asks *you* for permission on a consent page on your own site; you choose **Read only** or **Read and write**, and each gets its own key and Disconnect. OAuth 2.1 with PKCE, nothing brokered by a third party; a revocable **shared token** covers clients that can't ask.
* **Read your site's data** — connected agents run the read-only tools — score, AI traffic, request log, bot identification, page previews — and your llms.txt, discovery and agent-card documents are offered as readable **resources**.
* **Draft, edit and publish posts — behind two more switches** — turn on **Let connected agents write** and an agent can create and edit posts and pages fully dressed (categories, tags, featured image, AI topics and descriptions), apply Readiness fixes, and reuse pictures from your media library. A third switch lets it publish; otherwise it leaves drafts for review.
* **Safe by construction** — every write runs as the signed-in WordPress user, never exceeding their permissions, and is recorded under **More → Agent Access**. Nothing is public, and with the switch off the write tools do not exist.

**Write with AI in wp-admin — the built-in assistant (opt-in)**

* **Idea → draft without leaving wp-admin** — a spark button opens the writing assistant: describe what you want, shape the outline it proposes, then preview the complete draft — real blocks, AI description, topics, categories, tags. A page is written as a page: no invented sections. Nothing is saved until you click **Create draft**, and it never publishes.
* **Ask AI in the editor — one block, a selection, or the whole post** — rewrite or extend the block you're in, change several with one instruction, or ask about the whole post and get a list of proposed edits, each with its reason, to accept or reject one at a time. Blocks the plan doesn't name are never touched.
* **Images where you write** — alt-filled placeholders in drafts, **Generate image from the alt text** on every image block, a **Featured image (AI)** panel — or pick from your library. Runs on WordPress's built-in AI Client (7.0+); Agentimus never sees your key, and every AI button hides until a provider is set up.

**Tell your team — and announce what you publish**

* **Reports where you already look (opt-in)** — send Agentimus's reports to Telegram, Slack, Discord, a Google Sheet or any address of your own. Choose the moments that matter: a new finding, an unfamiliar bot, the weekly digest. Every service stays yours — your bot, your channel, your sheet — and nothing is sent until you switch it on.
* **Announce a new post (opt-in)** — Agentimus offers the words for X and LinkedIn; you approve them or edit them first. Each connection is an app you own, so the post comes from you, not from us. A ledger shows what went out, what is queued and what failed, with the reason.

**Control — who may use your content**

* **robots.txt content-signals + AI-training blocklist** — declare your content-usage policy and block model-training crawlers (GPTBot, CCBot, ClaudeBot, Google-Extended, Bytespider, …) by name, while leaving read/cite bots free.
* **Block scanners & scrapers (opt-in hard block)** — robots rules are a polite request; this enforces them, returning 403 to the user-agents on your denylist. Your **always-allowed** list is never blocked: pre-trust well-known assistants with one click, search engines are recognised automatically, and SSL renewals stay reachable.

**Reduce exposure — what your site reveals to bots**

* **Exposure controls (opt-in, all OFF by default)** — switches that quietly close what stock WordPress reveals to anonymous crawlers: username enumeration, author archives, the WordPress version, the auto-generated `<head>` discovery links, and XML-RPC. Signed-in admins and the block editor are never affected. Exposure hygiene, not a firewall.

**Visibility — who is reading you**

* **Agent activity log** — which AI crawlers and agents actually fetch your content and endpoints (GPTBot, Claude, Perplexity, Googlebot, …), recorded first-party, with no IP logging by default.
* **Activity to review** — a nav-bar queue surfaces clients worth a second look, with one-click **Block** or **Allow**. Nothing is blocked unless you say so.
* **Request Log** — every recorded request, one row each. Filter by client, endpoint, network, user-agent and date to see what a single bot fetched.
* **Agent Access** — who *authenticates and acts*: assistants approved, keys created or revoked, abilities run, requests refused. A record, not a guard — it names the key, never the person.
* **Traffic from AI** — the real visitors an AI assistant sent you, day by day, by assistant and landing page — aggregate counts only, never a row for one person, no IP. An opt-in **CDN mode** keeps counts accurate behind a cache.
* **Edge traffic (Cloudflare, opt-in)** — what Cloudflare answered or blocked before your server saw the request: cache hits and edge blocks no server-side log can see. It warns when the edge disagrees with your policy.
* **You decide how long it's kept** — retention, nightly auto-delete and a hard size cap, so the log never outgrows your host.
* **Citation checks (opt-in)** — track **each brand, product or person you choose** across ChatGPT, Perplexity, Gemini and Claude: whether it is **mentioned, linked, and how it ranks against rivals**. Off by default; **bring your own API key** (the one feature that makes an outbound request — see *External services*).

**Classic search, measured — Bing & Google (opt-in)**

* **Search Performance & Opportunities** — connect Bing Webmaster Tools and/or Google Search Console (a key held on your own server, no third-party proxy) and see what people searched, how often you appeared, and which pages sit one improvement from page one. Every number is the engine's own, never estimated; probe traffic is named, not blended in.
* **In the index — Google's and Bing's** — whether the indexes behind AI Overviews, Gemini, ChatGPT search and Copilot hold your pages: the whole site in rotation, every verdict in the engine's own words, problems grouped with deep links to the fix, any page re-checked live — plus week-on-week trend, Google Discover, and your registered sitemap's health.

**Content — clean, machine-readable output**

* **Markdown delivery** — request any page as clean markdown by appending `.md` to its URL. An `Accept: text/markdown` mode also exists.
* **/llms.txt** & **/llms-full.txt** — an [llmstxt.org](https://llmstxt.org) index of your pages, topics and recent posts, plus a full-text edition an agent ingests in one request.
* **JSON-LD** — WebSite + Person/Organization, plus BlogPosting and BreadcrumbList on posts. **Defers to Yoast, Rank Math, SEOPress, AIOSEO and The SEO Framework**, so never duplicate schema.
* **Topics for AI** — say what each post is about in plain words; they become the JSON-LD `keywords` and a line in the page's `.md`. Type your own or fill them from tags and categories. Nothing shows on the page.
* **AI description** — a one-line summary per post; it becomes the JSON-LD `description`, the lead of its `.md`, and its `<meta name="description">` unless an SEO plugin owns it. Blank uses the excerpt; a sub-switch keeps it out of your `<head>`.
* **XML sitemap** — with no SEO plugin, Agentimus serves your sitemap at `/wp-sitemap.xml` and advertises it in robots.txt and llms.txt; with one, it links theirs.
* **Change feed** — `/agentimus-changes.json` lists added, updated and removed pages (with `?since=`), so an assistant re-checks only what changed.

**Identity & contact**

* **Author / site identity** — a profile sentence, expertise topics and linked profiles (`sameAs`) feed llms.txt and JSON-LD — the highest-signal lines for agent retrieval.
* **security.txt** — optionally publish an RFC 9116 disclosure contact at `/.well-known/security.txt`.

**Readiness report**

* A one-screen score of how machine-readable your site is, with a plain-English checklist of what's enabled and what's still missing.
* **Agent preview** — see the exact JSON-LD *and* Markdown an AI agent receives for the whole site or any page, then copy it. It shows what would ship even when the feature is off, and a matching preview sits in the post editor.
* **Readability tips** — as you write, a panel flags what makes a page hard to read, section and quote: thin content, missing headings, no opening summary, a nav-heavy page, images without alt text. Most of those serve search engines and screen readers exactly as much as AI assistants, and the panel says so. Editor-only — nothing shows to visitors.

**Machine discovery (forward-looking)**

Everything above is read by search engines and AI tools **today**. This part is forward-looking — the conventions the agent ecosystem is converging on (`.well-known`, A2A agent cards, MCP-shaped tools), putting identity, capabilities and APIs in one predictable place:

* **/.well-known/discovery.json** — an owner-curated document describing the site's identity, capabilities, APIs and agent cards. Other plugins declare themselves through one hook.
* **/.well-known/agent-card.json** and **/.well-known/mcp.json** — an A2A agent card and an MCP manifest, generated for you.
* **Standards-aligned `.well-known` endpoints** — an RFC 9727 `api-catalog`, plus — *only when the capability exists* — an MCP server card and an Agent Skills index. **Response signing** (RFC 9421 / Web Bot Auth) uses an Ed25519 key that never leaves your server.
* **WordPress Abilities API** — the same read-only tools are registered as abilities, each gated by the capability of its screen, so WordPress's built-in AI can read them. An off-by-default switch adds the write abilities.
* **The plugins you run, described** — a WooCommerce store says it sells products; FluentCart, FluentCommunity and Fluent Support say what they hold. Plugins that keep everything behind a login are named as such. Switch off anything you would rather not announce.
* **Zero-config auto-discovery** — reads your REST namespaces, public post types and the Abilities API, so a site is described even when no plugin declares itself. The **Discovery Hub** shows what an agent sees.

**Why it's useful**

Most tools cover one slice — an llms.txt file, a bot blocker, or structured data. Agentimus brings the whole job together in one lightweight package — and tells you what's still missing.

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

By default, no — Agentimus makes no outbound HTTP requests out of the box, sends nothing to any external service, collects no analytics or telemetry, and stores the agent-activity log in your own database with no IP addresses. (One opt-in setting, *Store IP addresses for flagged clients*, can store IPs locally for flagged crawlers only — off by default; see *External services*.) Two opt-in features go outbound. **Verify bot identities** makes DNS lookups and downloads, once a day, the crawler-IP lists that bot operators publish (Google, OpenAI, Perplexity, …) so impostors can be caught; when a request arrives carrying a Web Bot Auth signature, it also fetches that operator's public key file so the signature can be checked on your own server. Only those public files are fetched, and nothing about your site is sent. **The other is the optional citation checks feature:** if you enable it and add your own API key, Agentimus queries the AI provider(s) you chose (OpenAI, Perplexity, Gemini and/or Anthropic) to check whether they mention and cite you — only for the engines you turn on, and only when a check runs (on demand or on your schedule). Your keys stay on your server and nothing else is sent anywhere. See *External services* for the full disclosure. The discovery document includes a `$schema` value that *identifies* the document format (the same way a schema.org URL identifies a vocabulary); it is a label in the output, never fetched. The one place a request is made is the optional "Verify live" self-check on the readiness report — and that runs in *your browser*, fetching your own public URLs only when you click it; the server itself still makes no request.

= Do I need an SEO plugin alongside Agentimus? =

No. With no SEO plugin installed, Agentimus covers the search essentials itself: per-page SEO titles (an "SEO title" field in the editor), Open Graph/X share cards, canonical links, meta descriptions, and an XML sitemap with last-changed dates. Each has its own switch under Settings → Discovery → Search basics. If you install an SEO plugin later, Agentimus notices on the next page load and steps aside on everything the plugin owns — your per-page values are kept, and everything returns if that plugin leaves.

= Does this conflict with my SEO plugin? =

No. When Yoast, Rank Math, SEOPress, AIOSEO or The SEO Framework is active, Agentimus stands down on every overlapping surface — JSON-LD, SEO titles, share cards, canonicals, meta descriptions and the sitemap — automatically, so nothing is ever emitted twice. A card on the dashboard names the division of labour. The AI-facing endpoints (llms.txt, markdown twins, the discovery documents) don't overlap with SEO plugins and keep working the same either way.

= My robots.txt rules aren't showing. =

If a static `robots.txt` file exists at your site root, or your CDN serves its own, it overrides WordPress's virtual robots.txt. The readiness report flags this. Remove the static file to let Agentimus manage the rules.

= An SEO audit tool says my robots.txt is invalid. =

Some audit tools flag the `Content-Signal:` line Agentimus adds. The line is valid: the robots.txt standard (RFC 9309) tells parsers to ignore any field they don't recognise, so every crawler still reads the rest of your file exactly as written. The tool is behind the standard, not your site. Nothing to fix.

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

Yes — as an opt-in, on WordPress 6.9 or newer. Turn on **Settings → Discovery → MCP server** and the AI assistants you already use can talk to your site over the Model Context Protocol and run the same permission-checked tools your admin AI gets — twenty read-only ones (the ranked findings list, readiness, the pages worth fixing and what each is flagged for, the categories and tags your site already uses, the people-vs-machines audience, AI traffic, the request log, edge traffic, search performance and opportunities, Google index answers, bot identification, per-page readability, internal-link suggestions, media search and previews), plus the write tools if you separately allow those (see the next question). It also offers your site's own documents to be *read* rather than run — llms.txt, llms-full.txt, discovery.json and your agent card are listed as MCP resources, so an assistant attaches them the way it attaches a file, and one you have switched off is never offered. Connecting is usually one approval: give the assistant your server address and it asks you for permission on a page served by your own site, where you choose read-only or read-and-write. Claude, ChatGPT and Cursor work this way today (ChatGPT behind its Developer-mode switch); Codex cannot ask, so it takes a shared token you create on the card and send as a Bearer header. A WordPress application password still works too, for one key per tool tied to a specific user. Nothing becomes public — every request signs in, each tool keeps the same permission checks as the admin screens, and every call is recorded under **More → Agent Access**. Off by default, and everything needed ships with the plugin.

= Can an AI agent write to my site? =

Only if you say so, twice. The MCP server starts read-only; a second switch — **Let connected agents write** — adds the write tools: draft and edit posts and pages complete with categories, tags and a featured image (searched from your media library by title or alt text, or imported from a URL), set their AI topics and descriptions, and apply Readiness fixes (a fixed list of safe switches that can only turn documented features on, never loosen a protection). Even then, agents can't publish: they leave drafts and pending posts for your review, unless you flip a third switch that allows going live. Writes are held to the site’s own quality bar too: the write tools show agents the same readability rules the in-admin assistant drafts to, and every create or update returns the post’s AI-readability grade. Every write runs as the signed-in user — an agent can never do more than that user could in the editor: filing under existing categories, creating new ones, and uploading images each follow that user's own permissions — and every call is recorded under **More → Agent Access**, attributed to the key that made it.

= Can AI help me write the description, topics and fixes? =

Yes, if you're on WordPress 7.0 and have set up an AI provider under Settings → AI. Then **Draft with AI** appears on the AI description field, **Suggest with AI** on the Topics field, and **Fix with AI** on any Readability row that needs work. Agentimus asks *your* AI through WordPress's shared connectors — it never sees or stores your API key, and nothing is sent anywhere if you haven't set a provider up (the buttons simply don't appear). Every suggestion arrives as ordinary editable text in the field: you read it, change it, and save the post yourself. Nothing is written for you.

= Can AI write a whole post for me? =

Yes — the writing assistant (the spark button on Agentimus's own screens) turns a described idea into a complete draft. It proposes an outline you can edit first, then writes the title, body, AI description and topics with suggested categories and tags, and shows you everything before a single thing is saved. **Create draft** opens the post in the editor, where image placeholders arrive with their alt text ready — fill them from your library, or generate them with AI. Revising an existing post happens in the editor instead, where **Ask AI** works at three scopes: the block you are in, several blocks you select, or the whole post — the last returning a list of proposed edits, each with its reason, that you accept or reject one at a time. It needs the **Let connected agents write** switch on and an AI provider under Settings → AI, and it never publishes: drafts and pending review only.

= Do citation checks use the AI provider I set up in WordPress? =

No — it needs its own API keys, and that's on purpose. A visibility check is graded on the **sources each engine cited**, and WordPress's shared connectors hand back only the answer text; the list of cited sources is dropped before Agentimus could read it. Reading those sources means talking to each engine's own API, so citation checks keep their own keys (Visibility → Citations). They stay on your server and are used for nothing else.

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

1. Dashboard — your AEO/GEO score across five plain rungs (Findable, Readable, Trusted, Optimized, Cited) with the one next step worth taking, then what your site runs: every system's standing in four panels — the doors agents use, the signals you announce, what search shows, what your writing holds — each led by one number that links to its full screen. Beneath them, the two audiences counted apart — the people who came to read, and the machines that fetched — then a first-party log of which AI agents and crawlers reached each endpoint, who reads you most, and the real visitors AI assistants sent. Every day bar opens that day's full report.
2. Visibility → In the index — whether the engines' indexes actually hold your pages, Bing's and Google's on one screen. Bing is the index ChatGPT search and Copilot read today: how much of your site it holds, how cleanly its crawler gets in, and a live question you can ask about any single page. Google's is walked in rotation and re-checked daily — healthy pages stay a count, while anything that needs a look earns a row in Google's own words, each with a link straight to that URL in Search Console.
3. Visibility → Search — what people searched, how often you appeared, how often those results were clicked, and the pages that earned them; every number the engine's own. Connect both Google and Bing and a switch appears, because the two count different searchers and are never merged. Directly beneath it, Search Opportunities turns those same numbers into a worklist: pages sitting just off page one, and pages on page one being scrolled past, each wired to the exact field that fixes it. It also names the searches several of your pages are splitting between them — the clicks divide, so each ranks lower than one page would — with the page that earns the click stated, and one decision on every other.
4. Readiness report — a plain-English pass/warn/fail checklist of what's enabled and what's still missing, grouped by rung, and beneath it the Optimize worklist: exactly which posts and pages an answer engine would struggle to quote, and why. Set aside anything not meant to be cited, page by page or a whole check at once.
5. Crawler policy & bot identity — declare your content-usage signals, block AI-training crawlers by name, turn away spoofed traffic, and verify bots against what each operator publishes: reverse DNS for the search engines, published IP ranges for GPTBot and PerplexityBot, cryptographic signatures for agents that sign. The verified-bots registry is yours to edit.
6. Request Log — every request an agent made, in one filterable table: narrow by client, endpoint, network, verification verdict, User-Agent or date to see exactly what a single bot fetched. A Status column carries one honest mark per request — verified, signed, spoofed, forged, refused or unchecked. Your own logged-in visits are never recorded.
7. MCP server — one switch runs a Model Context Protocol server on your own site. Your server address leads: give it to an assistant and it asks you for approval — no keys to paste — and every approved assistant is listed with its scope, its last call and its own Disconnect. Above it, the trust ladder: a second switch lets agents write, a third decides whether they may publish or only leave drafts.
8. Data sources — the outside services Agentimus reads from, all optional, each with one key held on your own server and no third-party proxy: Cloudflare for what the edge answered or blocked before your server saw it (and, with an optional extra permission, clearing its cache when you publish), Google Search Console for classic search and index coverage — with Analytics alongside it, on that same key, if you want visitor numbers too — and Bing Webmaster Tools, which can also announce every change you publish through IndexNow. Numbers land in your own database, so your history outlives each service's own reporting window.
9. Visibility → Citations — an opt-in, bring-your-own-key scoreboard showing whether ChatGPT, Perplexity, Gemini and Claude mention and link each brand, product or person you track: seen-in-answers and linked-your-site rates, rank against each item's own rivals, and question-by-question results with the sources each engine cited.
10. Discovery Hub — everything your site tells AI agents, in one place: the providers describing it, the read capabilities they expose, the public APIs, and the MCP & tools surface — your own MCP server alongside the WordPress Abilities API, with the tools each carries. Each summary tile jumps to its own countable list, and any registration problem is listed with a plain-English fix.
11. The writing assistant — a spark button on every Agentimus screen opens the drawer: choose what you are writing (a post, a page, or one of your own content types — a page gets no invented sections and no tags), describe it in your own words, then draft it straight away or shape the outline first. Preview the fully dressed draft, create it as a draft and land straight in the editor. Nothing is saved until you say so, and it never publishes.
12. In the post editor — the "Agentimus" box, Readability tab: a per-page pass/warn check of what makes the page hard to read, section and quote — for AI assistants, for search engines and for people alike — enough substance, an opening summary, specifics and cited sources, section headings and their order, quotable passages, reading ease, prose vs links, image alt text, video and audio, the featured image and whether it is described, and freshness. The rows an AI can draft a fix for — a thin page, a missing opening summary, an over-long block — carry a "Fix with AI" button once you have set a provider up in WordPress. Two more tabs sit alongside it: JSON-LD and Share.
13. Findings — one front door for everything open across your site, ranked by what each one costs: pages losing a click they already earn, and anything the setup checks caught. Every row says what to do and carries the button that lands on the fix, and anything filed under “when you have time” can be put away with a count of what you put away. Beneath it, your content one row at a time — what each page is found for, whether it answers that, and anything else it needs. It covers every kind of content you publish, and the line above the list says which kinds are being checked.

14. Integrations → Services — where Agentimus sends its reports. A new finding, a caught impostor, the weekly digest and three more moments can go to Telegram, Slack, Discord, a Google Sheet, or any address of your own as signed JSON. Each service is one you own — your bot, your channel, your sheet — and every connection is tested before it is saved, so a card never claims to be connected when nothing would arrive. All of it is off until you switch it on.
15. Integrations → Plugins — what your other plugins tell AI assistants about your site, on your behalf. A WooCommerce store says it sells products; FluentCart says it has store and checkout pages; FluentCommunity says it holds community spaces. Plugins that keep everything behind a login are listed too, saying exactly that — a plugin with nothing public has nothing to pass on, and naming it is more use than leaving it out. Any plugin can describe itself the same way through one hook, with no dependency on Agentimus.

== External services ==

Agentimus makes no outbound requests by default: no remote scripts, fonts or analytics, and the agent-activity log stays in your own database with no IP addresses. (IP storage is optional, off by default — see the FAQ.)

**Every outbound feature is opt-in and off by default.**

**Data sources & IndexNow:** connecting Cloudflare, Google Search Console or Bing Webmaster Tools polls that service with your own key. The optional IndexNow switch announces published and removed URLs to search engines via api.indexnow.org (https://www.indexnow.org/) — only the changed URL list is sent.

**Verify bot identities** makes DNS lookups and, once a day, downloads the IP-range files bot operators publish to verify their crawlers (Google, Microsoft, DuckDuckGo, Apple, OpenAI, Perplexity — or a URL you add yourself). Only those files are fetched; nothing about your site is sent.

The same setting verifies **Web Bot Auth** signatures — the standard where an AI agent signs its request cryptographically. To check one, Agentimus fetches (and caches) the signing operator's **public key directory** at `/.well-known/http-message-signatures-directory`, only when a signed request arrives, and sends nothing about your site with it. Operators publishing one today:

* **OpenAI** — https://chatgpt.com/.well-known/http-message-signatures-directory · https://openai.com/policies/terms-of-use · https://openai.com/policies/privacy-policy
* **Google** — https://agent.bot.goog/.well-known/http-message-signatures-directory · https://policies.google.com/terms · https://policies.google.com/privacy

**Citation checks:** when you enable them and add your own API key for a provider, Agentimus sends the prompts you configured to that provider to check whether it mentions and cites your site — only for the engines you turn on, and only when a check runs. Your keys are stored on your own site and used solely for these calls. The providers, with their terms and privacy policies:

* **OpenAI (ChatGPT)** — https://openai.com/policies/terms-of-use · https://openai.com/policies/privacy-policy
* **Perplexity** — https://www.perplexity.ai/hub/legal/terms-of-service · https://www.perplexity.ai/hub/legal/privacy-policy
* **Google (Gemini)** — https://ai.google.dev/gemini-api/terms · https://policies.google.com/privacy
* **Anthropic (Claude)** — https://www.anthropic.com/legal/consumer-terms · https://www.anthropic.com/legal/privacy

URL-like strings in the plugin's output are labels, not requests — the discovery documents' `$schema` value names the format (never fetched), and the `example.com` URLs in `examples/` are documentation placeholders.

== Changelog ==

= 1.41.0 =
* Fixed: on WordPress 7.1's default admin colour scheme, Agentimus lost its colour — chart bars, links, tab underlines and numbers all turned black, and the readiness ring washed out to near-white. 7.1 made “Default” a new scheme whose highlight is too pale to read as text, so Agentimus fell back to that scheme's near-black ink — which passed the check for being readable precisely because there is no colour in it. Readable and coloured are two questions now, and a scheme with no usable colour keeps Agentimus's own.
* New: a connected assistant can set a page aside, the same as you can. It could already tell you which pages need work and fix them, but not say the third thing you say constantly — that a page is fine as it is, because it was never meant to be quoted. A contact page, a landing page, an index. Set aside by an assistant behaves exactly as it does from your screen: the page is untouched, it stays published, and it lands in your Set Aside list with the count beside it. One page per call, in both directions — setting aside everything a check flags is still yours alone, behind the confirmation that names the number.
* New: an assistant can filter your request log the way your own screen does — several crawlers at once, or everything spoofed and everything refused together. The Client, Endpoint, Network and Verification filters each took a single value, so an assistant could not ask a question your screen already answers.
* Fixed: a page title reached a connected assistant with its punctuation still written as code — an ampersand you typed arriving as `&#038;`. An assistant does not draw a web page, it repeats what it is handed, so that title could come back to you inside something it wrote.
* Fixed: the in-admin assistant no longer recapitalises a title you typed yourself. It title-cases the ones it writes, which is the rule — but that had leaked onto titles arriving from you, so something you named in sentence case saved with capitals you never asked for.
* Fixed: on the Sunrise scheme, a failing check no longer reads as a lighter shade of the card it sits on. That card is a deep red by day and the failure mark it inherited was almost the same red — visible, but not an alarm. Its chart bars take that red now too, instead of the green they shared with every other scheme.
* Improved: each admin colour scheme carries one colour of its own through Agentimus, in light and in dark, and the dashboard's two columns start level with each other.

= 1.40.1 =
* Fixed: a bug regarding the request log as a connected assistant reads it. 1.40.0 taught that screen to sort, and its answer grew two new fields — which order the rows are in, and how far down the list this page starts. The assistant-facing tool had not been told to expect them, so it refused its own reply and an assistant asking about your crawlers got an error instead of your log. Your own screens were never affected, which is why it went unnoticed. If you use Claude, ChatGPT or another MCP client with this site, it reads again.

= 1.40.0 =
* Fixed: bugs regarding the impostor count — your dashboard could report "0 caught faking an identity" while your site was catching crawlers forging an identity and turning them away. That figure counted the review queue, which is a list you can dismiss from, rather than the log that records what actually happened, so it fell back to zero as soon as a report was cleared. It reads the log now, over the same days the card counts.
* Fixed: bugs regarding the dashboard's drill-downs — clicking a number opened the Request Log with the filters shown but not applied, so "4 caught faking an identity" arrived as all 165 requests. It affected the first visit to that screen in a session, which is why it was easy to miss. Those rows now open exactly what they counted, over the same window.
* Fixed: on WordPress's Light admin colour scheme, Agentimus coloured its links, tab underlines, numbers and chart bars with that scheme's orange highlight — and on WordPress before 7.1, with a grey so pale nothing could be read against the page. It keeps its own colour there now.
* Fixed: on the Light scheme, hovering a line on the readiness card turned it white on an almost-white card and it disappeared. The lift was drawn for the dark card and had never been mirrored for the light one.
* New: the Request Log's Client, Endpoint, Network and Verification filters each take more than one value — two crawlers at once, or everything spoofed or refused — and every column sorts the whole filtered set rather than the page on screen. The Visitors report's "Sent by" takes several assistants too, so two can be read side by side.
* Improved: every number on the dashboard's Humans and Machines cards is now a link, and each one lands where it is answered. "Clicks from Google + Bing" opens what people actually searched for; "referred by AI answers" opens which assistants sent them and where they landed.
* Improved: Agentimus wears one colour per admin colour scheme, whichever WordPress you run. Blue, Ocean and Sunrise each kept a second set of colours for the palette WordPress shipped before 7.1; each is now a single colour taken from that scheme's own bar, in light and dark alike.
* Improved: buttons and cards answer the pointer the same way across the plugin — the surface lifts, nothing casts a shadow, and the Request Log's header holds still instead of resizing the table under your cursor as you sort.
* Fixed: your content summary no longer says "the most common is X" when there is only one kind of issue to name.
* Improved: "This site represents" and "Entity type" read as plain English — "A person", "An organization", "Blog Posting" — instead of the raw values written into your structured data.

= 1.39.0 =
* Fixed: the score card's “N to fix” chip said a number nobody could reconcile with the screen. It added up the issue groups, so a page carrying three issues counted three times — 119 on a site where 82 pages needed work. It counts pages now, the same number the Optimize card names.
* New: your content gets read even when your host never runs WordPress's scheduled jobs. WordPress asks itself for a background run on each page view, and on many hosts that ask never arrives — a blocked internal request, or a cached page that never runs PHP — so the reading never happened and nothing on any screen said so. Agentimus now notices when its own jobs are overdue and runs them from the screen you are already on. Nothing else on the page slows down, a site whose jobs run normally loads nothing at all, and a run stands down the moment WordPress starts one of its own.
* Improved: working through one issue at a time now leads somewhere. "Featured image not described · 60 Posts" used to show six of those sixty pages and stop. It hands the whole set to Your Content instead, ranked, twenty at a time, with the search each page is found for — and a connected assistant can ask for one check's pages the same way.
* Fixed: fix the thing a filtered list was opened for, and the page says so instead of sitting there as though nothing had happened. Come back from the editor and the row keeps its place — a row that vanishes the moment you fix it is a fix you never got to see — but it is marked as done, and the heading admits its count was taken before you started.
* Fixed: a page WordPress builds for you — your blog index, a cart, a form page — is no longer told it is too short. Those pages take their words from whatever fills them, so "not enough substance yet" was the one piece of advice their owner could not act on. They are still read, and still listed for anything else they need.
* Fixed: a page is no longer told to climb a search it is losing to another of your own pages. One row could say "one push from page one" while the row below it said to point that page at the sibling beating it — and following the first made the second worse.
* Fixed: the Optimize card counted the wrong thing. "Up to 65 of your 103 graded pieces have something worth fixing" named the biggest single issue, which is the smallest that number can be, not the largest. On a site where 84 pages carried something, it said 65. It counts pages now.
* Fixed: one click no longer breaks the three counts above your content list. Setting a page aside made them count only the twenty rows on screen instead of your whole site — "94 · 18 · 18" became "19 · 0 · 1". They stay site-wide, and the page you set aside moves from one count to the other.
* Fixed: opening Your Content no longer leaves the finding above it out of date. That screen reads a few pages before it counts, so its numbers were newer than the sentence above them — 69 in one place, 68 in the other, on the same screen.
* Fixed: setting a page aside no longer leaves the count above it stale. The list and the sentence that summarises it share one screen, and both move together now.
* Improved: the front door says when its numbers are moving. It already said how many pages had never been read; it now also says how many are being read again after a change.
* Improved: Agentimus sits more comfortably in whichever WordPress admin colour scheme you use, in light and in dark.
* Fixed: blocks inside a card no longer sit flush against one another, so three separate things stop reading as one.

= 1.38.0 =
* New: an assistant you have connected can now find what needs fixing, instead of being told a number. It could already rewrite a page, but nothing would tell it which page: the findings tool said "36 posts and pages are worth fixing" and handed back a link to a screen. There is a tool for the list itself now — which pages need work, everything each one is flagged for, whether it answers the search it is found for, and how old that reading is. It reads no pages to answer, so an assistant can walk a whole site without slowing it down, and each row carries the page's id, which is what the checking and editing tools always needed.
* New: an assistant can now finish a post, not just write one. Two fields decide how a page appears in a search result — the search it should answer, and the title a result shows — and nothing connected could reach either of them. Both are settable now, on their own or in the same call that writes the page. Changing either one sends the page back to be read, because both change what it is measured against without changing a word of it.
* New: an assistant can see the categories and tags your site already uses. It had to type them from memory, and a name typed from memory does not match one that exists — "New Features" and "New features" are two categories, and the second one gets created. It now reads the real list, with the names the write tools expect, how many pages carry each, and whether it is even allowed to create a new one. Taxonomies it cannot set are named rather than left out.
* Fixed: fixing one thing on a page no longer makes the whole page disappear from Optimize Your Content. Saving a post used to erase what Agentimus knew about it, so a page you had half-fixed left the card as though nothing were wrong with it — and turned up again later with the untouched issues still on it. The page keeps its place now and says it is being read again, the card counts how many are, and the reading happens within about a minute of your save instead of whenever the next sweep came round. Your Optimized score also stops moving for the wrong reason: a page you edited never drops out of the average while it waits.
* Fixed: when a release changes what the checks look for, your content is read again. A grade is an answer to a question, and Agentimus was keeping the answer while forgetting the question — so a page graded under an older version of the checks kept that verdict for good, and nothing on any screen said the reading was out of date. Every verdict now records which checks produced it. When those change, the pages go back in the queue, keep showing what they last said while they wait, and are re-read quietly in the background. Expect your counts to move once after this upgrade — pages that read as clear were answered by checks the plugin no longer runs, and the change is that old answer being corrected — not new problems appearing.
* Fixed: upgrading no longer empties your lists while the re-reading happens. The last upgrade cleared every stored verdict outright, so an owner with 36 pages to fix opened Agentimus and saw none of them. A reading that is out of date is still the last thing anybody measured; it stays on screen, marked, until the new one replaces it.
* Improved: the featured-image check now judges what your pages actually serve. That picture is drawn by your theme, so nothing Agentimus could read told it how the picture reaches a reader — the check could only say the image had no description of its own, and left it there. Agentimus now reads a couple of your own pages in the background, once per theme, and says what it found. An image served with no description at all is named as the real failure it is. A theme that stands the post title in for a missing description says so, and points at the media library. And the case nobody could see before: a theme that ignores the description you wrote is named as the thing to fix, instead of your content being blamed for a picture you had already described. If the pages cannot be read, the check says only what it could prove before — it never gets worse on a reading that did not happen.
* Fixed: a few pages could be listed as worth fixing without saying why. They were graded by an older version of the checks, and the upgrade that should have re-read them had not reached every site. Anything that read the stored grade — including the new tool above — got a page that needed work for no reason it could name. Those pages are read again automatically now, and until they are, nothing repeats what they said. On sites where this happened the Optimized score was also leaving those pages out of the average entirely.
* Changed: the content checks say who they are for. Twelve of the fourteen are the classic on-page work a search engine and a screen reader need — headings, alt text, thin content, reading ease, freshness — and only the editor panel said so. Readiness, your score and the manual now say it too. Naming one audience invited you to weigh the work against your opinion of AI, when most of it pays either way.
* Fixed, from the same work and worth naming: the Optimize card could read "0 graded" for an hour after an update while your content list was perfectly healthy; pages that were being read again were not always marked as such, so a row could look current when it was not; a connected assistant calling the new content list with no arguments was refused instead of getting the useful answer; and the featured-image check could misread a theme that serves the post title, because WordPress typesets the words it prints and the comparison did not allow for it. Each is corrected on update — none of them needs anything changed on your side.
* Fixed: an update no longer leaves yesterday's numbers on screen. Some of what Agentimus shows is generated and cached for up to an hour, and nothing noticed when an update replaced the code that produced it — so a screen could keep answering with the previous version's reading. Everything generated is now rebuilt once after an update.
* Fixed: on a site that updated into the content grading (rather than installing it fresh), the background reading had no schedule at all — so the queue only moved when you opened Your Content, and the count of pages still to be read could sit still for days. The schedule now repairs itself on the next page load.
* Fixed: an issue on the Readiness card named the wrong number of pages. "Featured image not described · 6 Posts" could sit directly above "Showing 6 of 22" — the label counted the handful of pages shown rather than every page the check flags. It counts them all now.
* Improved: the reading-ease row says which half of the problem is yours. It used to advise "shorter sentences and plainer words" whatever the page looked like — so a technical article whose sentences already averaged eleven words was asked to shorten them, which is advice with nothing behind it. The row now names the half actually holding the score down, and says so when the sentences are already short and it is the vocabulary carrying it.
* Improved: the editor's Agentimus box keeps a readable line length. On a wide screen its verdicts ran the full width of the display in a single line, which is past the point where a line can be read at all. Both tabs now hold the same measure.

= 1.37.0 =
* New: Agentimus can tell you when something happens. Send its reports to Telegram, Slack, Discord, a Google Sheet, or any address of your own. Choose the moments you care about — a new finding, a bot you have not seen before, the weekly digest. They arrive where you already look. Every service stays yours: your bot, your channel, your sheet. Agentimus keeps no account of its own, and nothing is sent until you switch it on.
* New: announce a new post by itself, to X and to LinkedIn. Write the post, and Agentimus offers the words. You approve them, or you edit them first. Each connection is an app you own, so the post comes from you and not from us. A ledger shows what went out, what is still queued, and what failed. Anything that failed says why.
* New: the plugins you run now describe themselves to AI assistants. A WooCommerce store says it sells products. FluentCart, FluentCommunity and Fluent Support say what they hold, and Easy Digital Downloads joins them. The screen also names the plugins that keep everything behind a login. Those have nothing to pass on, and saying so is more use than leaving them off the list. You can switch off anything you would rather not announce, even when the plugin that added it wants it on.
* Changed: Your Content checks every kind of content you publish. It used to read posts and pages and nothing else, whatever your site was made of. A shop's products were never looked at. Now it covers everything you publish, and a gear on Your Content says which kinds. You can change that there. Products are checked for the searches they are found for. They are never graded as writing. A product page is short on purpose, so "this needs more substance" would be the wrong advice on one.
* Changed: the Optimized score reads your whole site. It used to read your 25 most recently edited posts and call that the site. On most sites this number will move when you update. The list of pages worth fixing will also be longer. The pages were always there; nothing was looking at them.
* Fixed: two screens counted the same work differently. Your Content said 36 pages were worth fixing while the front door said nothing needed attention. One counted the whole site, the other a sample. They read one count now.
* Improved: every page gets read, not only the recent ones. Editing a page used to push it ahead of pages nobody had ever looked at. On a busy site the oldest content could wait forever. First reads come first now. Once everything has been read, the oldest verdicts are refreshed quietly in the background — a few an hour, so a small host never feels it.
* New: suggestions you can put away. Anything filed under "when you have time" can be dismissed. You get a count of what you put away, and a way to bring each one back. Nothing that costs you something can be hidden.
* New: Get Help and Report an Issue, in the More menu. Get Help opens the WordPress support forum. Report an Issue opens the right form on GitHub with your setup already filled in — versions, theme, cache. The first reply is then an answer instead of a question. Both open in a new tab, and neither sends anything from your site.
* New: What's New, whenever you want it. The release notes used to disappear the moment you dismissed the card, and the changelog went with them. They now live in the More menu for good, with every earlier release underneath.
* New: the Agentimus box in the editor introduces itself. With all three of its panels switched off the box used to disappear entirely, so a first-timer never learned the editor could offer any of it. It now names the three things it adds and the switch that turns each one on.
* Fixed: the Agentimus box in the editor says what is switched off. With only one of its three panels enabled it rendered no tabs at all, so it looked broken rather than configured. It now names the missing tabs and the switch that brings each one back.
* New: the featured image is checked for a description of its own. It is drawn by your theme, so it never appeared in the content Agentimus reads — a picture with no alt text sailed past every check. It is read from the media library now, and named by file so you know which one to open.
* Changed: the editor panel is called Readability, not AI Readability. Twelve of its fourteen checks serve search engines and screen readers as much as AI — alt text, headings, thin content, freshness — and it says so now.
* Improved: the page checks say which part of the page they mean. "One long block" named only the longest paragraph, so a page with three of them warned once, you fixed one, and the warning came back with a different number. It now says how many run long and quotes the opening words of each. A missing image description names the file. A skipped heading level names the heading it happens at. And alt text that is only the file name — "screen-shot-2016-09-15-at-5-00-13-am" — no longer counts as a description of the picture.
* Improved: Discovery says only what is true. A job that needs a sign-in is no longer described as needing none. A capability with three owners names all three. A group of jobs wears the name its vendor gave it. Where a vendor holds something back, Agentimus holds it back too. The screen also asks each door as a stranger would, before calling it open.
* Improved: the search worklist reads both engines honestly. Every page is asked about, not a busy handful. Long lists are paged instead of cut off. Each row shows what Google says and what Bing says, side by side. The two are never merged into one number that belongs to neither.
* Improved: Agentimus sits at the top of your admin menu, above Posts. Its mark now takes the colour of your admin colour scheme. It used to be a fixed grey that suited only one of them.
* Fixed: a connection proves itself before it claims to be connected. A test message could post before the connection was stored, and report success on a connection that was never saved. A refusal is also named: a 403 from Google does not always mean "you did not share the sheet". A webhook proves the road before it says the road is open.
* Fixed: a stalled background job looked healthy. If WordPress stopped running scheduled work, the queue simply appeared quiet. It says so now.

For older releases, see the full history: https://github.com/heera/agentimus/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 1.41.0 =
Agentimus keeps its own colour on WordPress 7.1's default admin scheme — 1.40.0 turned its charts, links and numbers black there. A connected AI assistant can now set a page aside as one you never meant to be quoted, one page at a time, and can filter your request log by several crawlers at once. Titles you type yourself are no longer recapitalised. No breaking changes.

= 1.40.1 =
Fixed a bug regarding the request log as a connected AI assistant reads it: 1.40.0's new sorting added two fields the assistant-facing tool had not been told about, so it refused its own reply and an assistant got an error instead of your log. Nothing you see in the browser was affected. No breaking changes.

= 1.40.0 =
Fixed bugs regarding the impostor count and the dashboard's drill-downs: your site could report "0 caught faking an identity" while it was catching and refusing them, and clicking a number opened an unfiltered list. Two Light-scheme faults are fixed too — an accent colour nothing could read, and a row that vanished when you hovered it. The Request Log's filters now take several values at once and every column sorts. No breaking changes.

= 1.39.0 =
Your content now gets read even if your host never runs WordPress's scheduled jobs — many never do, and nothing used to say so. Working through one issue now opens every page that check flags rather than six of them, and a page you fix says so instead of vanishing from the list. Several counts that disagreed from one screen to the next read the same measure now, so a few of your numbers will move once. No breaking changes.

= 1.38.0 =
Your content is read again after this update — a verdict now records which checks made it, so expect your counts to move once: pages that read as clear were answered by checks the plugin no longer runs. Your lists stay on screen, marked, while that happens, and a page you half-fix no longer vanishes from Optimize Your Content. The featured-image check now reads a page you actually serve. Connected assistants gain three tools: the pages worth fixing, your real categories and tags, and the two fields behind a search result. No breaking changes.

= 1.37.0 =
Your Optimized score now reads your whole site, not its 25 most recent edits — on most sites the number will move, and more pages will be listed as worth fixing. Two new checks can add warnings too: alt text that is only a file name, and a featured image with no description. Nothing broke; nothing was looking at any of it before. Also: reports to Telegram, Slack, Discord, Sheets or your own address; announcing to X and LinkedIn; your plugins described to AI assistants; the editor's AI Readability panel is now Readability. No breaking changes.

= 1.36.0 =
Findings — one front door for everything open across your site, ranked by what each thing costs you, every row carrying the button that lands on the fix. Light and dark, hand-tuned and keyed to your admin colour scheme, following your device unless you say otherwise. Search Opportunities moves under the search report it reads from, on Visibility → Search, and Readiness keeps a pointer to it. Bing gains a daily traffic series and a live per-page question; Analytics can ride the Search Console key you already connected. Every data screen re-reads when you come back to it. No breaking changes.

= 1.35.0 =
Ask AI in the post editor — one block, a selection, or the whole post as a list of proposed edits you accept one at a time. The writing assistant now writes pages and custom types as pages, not as articles. In Google's Index gains a live Re-check for a single page and a Cancel for a running check. Your llms.txt, discovery and agent-card documents are offered to connected assistants as readable resources, and an agent can search your media library. One change to know about: editing an existing post inside the assistant's drawer is gone — revision happens in the editor now. No content is affected.

= 1.34.0 =
IndexNow announcements the moment you publish (off by default), Cloudflare cache purge on publish and on demand (one optional token permission), and an In Google's Index card that reports the whole site rather than a watchlist — pages Google left out are re-checked daily until they get in, and it says so when they do. Plus Bing's own record of your sitemap, and every search card can now be set aside, even the homepage. No breaking changes.

= 1.33.0 =
Classic search, measured: Search Performance and Opportunities for Bing and Google, an In Google's Index card with whole-site coverage and page lookup, a Cloudflare edge view, and a setup wizard. The sitemap returns to WordPress's standard /wp-sitemap.xml — old addresses redirect, so registrations heal on their own. No breaking changes.

= 1.32.0 =
Connect an AI assistant by approving it, not by pasting keys: give it your MCP address and it asks you for permission on your own site, where you choose read-only or read-and-write. Each assistant gets its own key and its own Disconnect. Plus a shared token for clients that can't ask, honest reporting when another plugin serves your llms.txt, and a robots.txt change watch. No breaking changes — existing keys keep working.

= 1.31.0 =
Share drafts written from the post (six networks, editor-only, nothing auto-posted) and an "Ask AI about this post" row whose assistant visits show in your own request log — both with an off switch. Plus consistent loading screens and an MCP request-log fix. No breaking changes.

= 1.30.0 =
Agents that cryptographically sign their requests (Google's agent, OpenAI) are now verified on your own server — forgeries flagged, unsigned crawlers never penalised. Plus internal-link suggestions in the editor, refused impostors on the record, and a clearer Request Log. No breaking changes.

= 1.29.0 =
Agentimus now covers the search basics — titles, share cards, canonicals, sitemap — when no SEO plugin is active, and keeps deferring when one is. Plus: pick the weekly email’s day and time.

= 1.28.0 =
A short weekly email about what AI did on your site — quiet weeks send nothing, one-click stop inside every email — and the review queue’s count now shows on the admin menu from every screen.

= 1.27.1 =
Small ordering fix on the new review-ask endpoint (deny before validate). No security impact, no breaking changes.

= 1.27.0 =
A quiet, one-time review ask that never nags (and retires the footer's rating line once answered), a first-run finish that shows what just went live, and standard auth vocabulary on the MCP server card. No breaking changes.

= 1.26.0 =
Fairer AI-readability grading — no more penalty for your topic’s vocabulary or your code samples — and AI agents now draft to your readability rules, graded on every write.

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

