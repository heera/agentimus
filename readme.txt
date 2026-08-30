=== Agentimus – AI SEO, llms.txt & MCP for AI Agents ===
Contributors: heera
Tags: ai-agents, mcp, agent-readiness, llms-txt, ai-seo
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.50.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site agent-ready: AI SEO (AEO/GEO), llms.txt & bot control — be found, read & cited by AI — and write, edit & publish via MCP.

== Description ==

Agentimus is an all-in-one AI SEO plugin for the age of AI agents — AEO (Answer Engine Optimization) and GEO (Generative Engine Optimization) in one place. It does two things.

**It makes your site legible and citable.** It helps AI assistants like ChatGPT, Claude and Perplexity find your site, read it correctly and cite it in your own words — and shows you which AI bots are visiting. **You don't need to understand AI or web standards:** a setup wizard walks you through it in about a minute, then it runs on its own.

**And it lets the AI tools you already use operate your site.** Turn on the built-in Model Context Protocol (MCP) server and Claude Code, Claude Desktop, Cursor or Codex can read your reports and — behind two more opt-in switches — **draft, edit and publish posts**. All three are off by default. Prefer wp-admin? A built-in **writing assistant** drafts and revises posts there.

By default it makes no outbound requests, collects no analytics and logs no IP addresses — everything runs on your own site. Three optional features change that only when you switch them on: **Citation checks**, **Verify bot identities** and **Store IP addresses**, each disclosed in full under *External services*.

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

* **Reports where you already look (opt-in)** — send Agentimus's reports to Telegram, Slack, Discord, a Google Sheet or any address of your own. Choose the moments that matter: a new finding, an unfamiliar bot, the weekly digest. Every service stays yours, and nothing is sent until you switch it on.
* **Announce a new post (opt-in)** — Agentimus offers the words for X and LinkedIn; you approve them or edit them first. Each connection is an app you own, so the post comes from you, not from us. A ledger shows what went out, what is queued and what failed, with the reason.

**Control — who may use your content**

* **robots.txt content-signals + AI-training blocklist** — declare your content-usage policy and block model-training crawlers (GPTBot, CCBot, ClaudeBot, Google-Extended, Bytespider, …) by name, while leaving read/cite bots free.
* **Block scanners & scrapers (opt-in hard block)** — robots rules are a polite request; this enforces them, returning 403 to the user-agents on your denylist. Your **always-allowed** list is never blocked: pre-trust well-known assistants with one click, search engines are recognised automatically, and SSL renewals stay reachable.
* **Exposure controls (opt-in, all OFF by default)** — switches that close what stock WordPress reveals to anonymous crawlers: username enumeration, author archives, the WordPress version, the auto-generated `<head>` discovery links, and XML-RPC. Signed-in admins and the block editor are never affected. Exposure hygiene, not a firewall.

**Visibility — who is reading you**

* **Report** — what AI did on your site between any two dates: reads, visits from AI answers, and where you stand. Today, a week, or dates you pick; every block says how fresh its numbers are.
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
* **Readability tips** — as you write, a panel flags what makes a page hard to read, section and quote: thin content, missing headings, no opening summary, a nav-heavy page, images without alt text. Most of those serve search engines and screen readers just as much, and the panel says so. Editor-only — nothing shows to visitors.

**Machine discovery (forward-looking)**

Everything above is read by search engines and AI tools **today**. This part is forward-looking — the conventions the agent ecosystem is converging on, putting identity, capabilities and APIs in one predictable place:

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

Yes — as an opt-in, on WordPress 6.9 or newer. Turn on **Settings → Discovery → MCP server** and the AI assistants you already use can talk to your site over the Model Context Protocol and run the same permission-checked tools your admin AI gets — twenty-five read-only ones (the ranked findings list, readiness, the pages worth fixing and what each is flagged for, a page’s stored body, the categories and tags your site already uses, the people-vs-machines audience, AI traffic, the request log, edge traffic, search performance and opportunities, Google index answers, bot identification, per-page readability, internal-link suggestions, media search and previews), plus the write tools if you separately allow those (see the next question). It also offers your site's own documents to be *read* rather than run — llms.txt, llms-full.txt, discovery.json and your agent card are listed as MCP resources, so an assistant attaches them the way it attaches a file, and one you have switched off is never offered. Connecting is usually one approval: give the assistant your server address and it asks you for permission on a page served by your own site, where you choose read-only or read-and-write. Claude, ChatGPT and Cursor work this way today (ChatGPT behind its Developer-mode switch); Codex cannot ask, so it takes a shared token you create on the card and send as a Bearer header. A WordPress application password still works too, for one key per tool tied to a specific user. Nothing becomes public — every request signs in, each tool keeps the same permission checks as the admin screens, and every call is recorded under **More → Agent Access**. Off by default, and everything needed ships with the plugin.

= Can an AI agent write to my site? =

Only if you say so, twice. The MCP server starts read-only; a second switch — **Let connected agents write** — adds the write tools: draft and edit posts and pages complete with categories, tags and a featured image (searched from your media library by title or alt text, or imported from a URL), change one passage of a page rather than replacing it, describe an image for screen readers and image search — the one in your library, or one sitting inside a page, which is a different fix because the page keeps its own copy of the words — set their AI topics and descriptions, and apply Readiness fixes (a fixed list of safe switches that can only turn documented features on, never loosen a protection). Even then, agents can't publish: they leave drafts and pending posts for your review, unless you flip a third switch that allows going live. Writes are held to the site’s own quality bar too: the write tools show agents the same readability rules the in-admin assistant drafts to, and every create or update returns the post’s AI-readability grade. Every write runs as the signed-in user — an agent can never do more than that user could in the editor: filing under existing categories, creating new ones, and uploading images each follow that user's own permissions — and every call is recorded under **More → Agent Access**, attributed to the key that made it.

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

Yes — there is no minified-only code. Everything in `assets/` is built from source shipped beside it in `resources/`: the admin interface from Vue 3 source with Vite, and the three small front-end scripts from `resources/public/` with esbuild. `vite.config.js` and `package.json` ship too, and all of it also lives in the public repository at https://github.com/heera/agentimus . Run `npm install && npm run build` to regenerate the whole of `assets/` from source.

== Screenshots ==

1. Dashboard — it opens with today: what AI crawlers read, who arrived from an AI answer, and what connected assistants did, each against yesterday, and one sentence naming who did it. Then your AEO/GEO score across five plain rungs (Findable, Readable, Trusted, Optimized, Cited) with the one next step worth taking, and what your site runs: every system's standing in four panels — how AI assistants reach you, what your site tells the engines, what search shows, what your writing holds — each led by one number that links to its full screen. Beneath them, the two audiences counted apart — the people who came to read, and the machines that fetched — then a first-party log of which AI agents and crawlers reached each endpoint, who reads you most, and the real visitors AI assistants sent. Every day bar opens that day's full report.
2. Visibility → In the index — whether the engines' indexes actually hold your pages, Bing's and Google's on one screen. Bing is the index ChatGPT search and Copilot read today: how much of your site it holds, how cleanly its crawler gets in, and a live question you can ask about any single page. Google's is walked in rotation and re-checked daily — healthy pages stay a count, while anything that needs a look earns a row in Google's own words, each with a link straight to that URL in Search Console.
3. Visibility → Search — what people searched, how often you appeared, how often those results were clicked, and the pages that earned them; every number the engine's own. Connect both Google and Bing and a switch appears, because the two count different searchers and are never merged. Directly beneath it, Search Opportunities turns those same numbers into a worklist: pages sitting just off page one, and pages on page one being scrolled past, each wired to the exact field that fixes it. It also names the searches several of your pages are splitting between them — the clicks divide, so each ranks lower than one page would — with the page that earns the click stated, and one decision on every other.
4. Readiness report — a plain-English pass/warn/fail checklist of what's enabled and what's still missing, grouped by rung, each row carrying its own fix in the site's own words. Buttons at the top preview your site the way an agent sees it, re-run every check live, and scan for files that shouldn't be public. Beneath the checklist your content is summarised rather than listed: how many pieces are graded, how many carry something worth fixing, and which issue is the most common — with a way in page by page, a way in by issue, and the set-aside list of everything you have deliberately left out of the score.
5. Crawler policy & bot identity — declare your content-usage signals, block scanners and scrapers by name, turn away spoofed traffic, and verify bots against what each operator publishes: reverse DNS for the search engines, published IP ranges for GPTBot and PerplexityBot. The verified-bots registry is yours to edit.
6. Request Log — every visit a machine made to your site, in one filterable table: which crawler or AI assistant it was, what it fetched — your own pages included, not just the AI files — and whether it was served or turned away. Narrow by client, address, verification verdict, signature, User-Agent or date. A Status column carries one honest mark per request — verified, signed, spoofed, forged, refused or unchecked. Your readers are never logged here.
7. MCP server — one switch runs a Model Context Protocol server on your own site. Your server address leads: give it to an assistant and it asks you for approval — no keys to paste — and every approved assistant is listed with its scope, its last call and its own Disconnect. Above it, the trust ladder: a second switch lets agents write, a third decides whether they may publish or only leave drafts.
8. Data sources — the outside services Agentimus reads from, all optional, each with one key held on your own server and no third-party proxy: Cloudflare for what the edge answered or blocked before your server saw it (and, with an optional extra permission, clearing its cache when you publish), Google Search Console for classic search and index coverage — with Analytics alongside it, on that same key, if you want visitor numbers too — and Bing Webmaster Tools, which can also announce every change you publish through IndexNow. Numbers land in your own database, so your history outlives each service's own reporting window.
9. Visibility → Citations, set up — the opt-in check that asks ChatGPT, Perplexity, Gemini and Claude whether they mention and link each brand, product or person you track. Name the thing, say what kind of thing it is, name the rivals it competes with, and write the questions a real person would type — or have them suggested. Then switch on the assistants that should answer them: each runs on your own API key, kept on your own server and used for nothing but your checks, because a citation check is graded on the sources an assistant actually cited and WordPress's shared connector hands back the answer text without them. Choose how often the checks run and how long the history is kept. Results land on the tab beside this one: seen-in-answers and linked-your-site rates, rank against each item's own rivals, and question-by-question answers with the sources each engine cited.
10. Discovery — everything your site offers AI assistants, in one place and in plain words: the things assistants can read, the things they can do, and what each of those rows actually allows. Beneath them the MCP connection, and every well-known document your site serves with its live status and one line saying what it is for — so none of it is a filename you have to go and look up. Any registration problem is listed with a plain-English fix.
11. The writing assistant — a spark button on every Agentimus screen opens the drawer: choose what you are writing (a post, a page, or one of your own content types — a page gets no invented sections and no tags), describe it in your own words, then draft it straight away or shape the outline first. Preview the fully dressed draft, create it as a draft and land straight in the editor. Nothing is saved until you say so, and it never publishes.
12. In the post editor — the "Agentimus" box, Readability tab: a per-page pass/warn check of what makes the page hard to read, section and quote — for AI assistants, for search engines and for people alike — enough substance, an opening summary, specifics and cited sources, section headings and their order, quotable passages, reading ease, prose vs links, image alt text, video and audio, the featured image and whether it is described, and freshness. The rows an AI can draft a fix for — a thin page, a missing opening summary, an over-long block — carry a "Fix with AI" button once you have set a provider up in WordPress. Two more tabs sit alongside it: JSON-LD and Share.
13. Findings — one front door for everything open across your site, ranked by what each one costs: pages losing a click they already earn, and anything the setup checks caught. Every row says what to do and carries the button that lands on the fix. Beneath it, your content one row at a time — what each page is found for, whether it answers that, and anything else it needs. It covers every kind of content you publish, and the line above the list says which kinds are being checked.

14. Integrations → Services — where Agentimus sends its reports. A new finding, a caught impostor, the weekly digest and three more moments can go to Telegram, Slack, Discord, a Google Sheet, or any address of your own as signed JSON. Each service is one you own — your bot, your channel, your sheet — and every connection is tested before it is saved, so a card never claims to be connected when nothing would arrive. All of it is off until you switch it on.
15. Integrations → Plugins — what your other plugins tell AI assistants about your site, on your behalf. A WooCommerce store says it sells products; FluentCart says it has store and checkout pages; FluentCommunity says it holds community spaces. Plugins that keep everything behind a login are listed too, saying exactly that — a plugin with nothing public has nothing to pass on, and naming it is more use than leaving it out. Any plugin can describe itself the same way through one hook, with no dependency on Agentimus.
16. Report — what AI did on your site between any two dates. How many AI crawlers read you and which ones, how many people arrived from an AI answer and from where, what assistants did here, where you stand in search, your score, and the one thing worth doing. Today, yesterday, seven days, thirty, or two dates from a calendar. Every block says how fresh it can be: your own log answers any window to the minute, while Google and Bing publish days behind and name the newest day they have rather than printing a zero that would read as "nobody searched". Your dashboard opens with the same reading for today.

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

= 1.50.1 =
* Fixed: the "Put this away" link is gone from the Findings screen. Putting a suggestion away only moved it into a fold at the foot of the same list — a collapsible in disguise — and only one kind of row ever carried the control, so part of the list arrived offering to be dismissed while the rest did not. Every finding now simply stays in its list, anything you had put away comes back on its own, and a connected assistant no longer sees a put-away ledger either.
* Improved: the Dashboard opens with its four small tiles, and the Today card now sits directly above the full-width cards it belongs with. The tile row used to sit between Today and everything else, cutting the run of cards in two.

= 1.50.0 =
* New: you can write your own robots.txt rules from the Crawler Policy card. A plain text box is appended to the end of the generated file, exactly as written — appended, never replacing, so an edit cannot break the Content-Signal line or the crawler blocks the plugin writes, and clearing the box is the whole reset. The live robots.txt is shown right above the box, fetched from your site exactly as a crawler receives it — which also honestly shows when a robots.txt file on disk or an SEO plugin owns the job instead (then the extra rules do not apply, and the box says so), and warns when your server wraps the file in an error code, which a strict crawler may ignore. Write your own Sitemap line and the plugin stops adding its own rather than printing two.
* New: the review bell's cards now say why a visitor is unchecked, instead of one blank "not verified" covering every case. Each card names its own cause — identity checking is turned off, this bot cannot be checked because its operator publishes nothing to check against, its visits gave no clear answer yet, or it carries a real cryptographic signature from an operator this site does not know. Each cause reads differently on the card, so you can tell at a glance which unchecked rows are waiting for an answer and which will never have one — and the small info icon carries the full reason.
* New: each recommendation on a review card is now a small labelled chip that names the kind of action it asks for — nothing left to do, one plugin setting, or a rule at your host or CDN — with the full advice shown on hover. Three cards used to read as three similar sentences; now you can see before reading a word that one asks nothing of you, one is a click in Settings, and one needs your hosting panel. The cards are several lines shorter, and on a proven impostor the genuine engine's verified traffic is counted right in the stats line so the fake never wears the real crawler's numbers.
* New: the Client Decisions dialog opens each list with a plain notice saying what your decision actually reaches. A block is enforced at this plugin's machine files — llms.txt, the markdown pages, discovery — not across your whole site, and it is separate from robots.txt, which is only a request a bot may ignore. Trusting a client sends nothing to the bot and changes nothing in robots.txt. When blocking is switched off in Settings, the Blocked list says so instead of quietly presenting rules that are not being enforced. And a trusted client whose name is also on the robots.txt block list now says so on its row — trusted at the door but publicly asked to stay away can be exactly what you meant, but the two may only disagree out loud.
* Fixed: the review panel could advise turning on "Verify bot identities" when it was already on — the nudge showed for any unchecked row that claimed a checkable bot, whether or not the setting was enabled. It now knows the setting's live state and only suggests what would actually change something. The same fault was in the forged-signature card, which could recommend "turn on blocking" while blocking was already on; it now says the next attempt will be refused automatically.
* Fixed: a valid signature from an operator this plugin does not recognise displayed as "Passed" in a card's details, indistinguishable from a recognised operator's. Proof of identity is not a reason to trust someone: it now reads "Not confirmed", names the signer, and says the site cannot vouch for them.
* Fixed: an entire explanation paragraph on review cards showed the "help" cursor as if it were hoverable — a style collision, and the question-mark cursor now appears only on the icons that actually carry a bubble.
* Fixed: the readiness card said "Everything looks good" when its advisor simply had no setup step left to suggest — a verdict it could not make, since the Cited figure above it can sit at 38% with every setup box ticked. It now says what it measured: "Nothing left to set up."
* Fixed: the Report's Citations card said "checks run on a schedule" to owners whose schedule is switched off. It now reads the schedule's real state: off means "a check runs only when you start one." And its "Open Citations" link landed on the Search tab; it now opens the Citations results it names.
* Improved: the Client Decisions rows read cleanly — a known crawler's name is no longer printed twice, the decision verb no longer repeats the tab's own name on every row, columns line up, long names shorten instead of pushing the date out of line, and on a phone each client folds to two tidy lines with a proper thumb-sized button.
* Improved: plain words on the undo buttons — "Stop trusting" and "Stop ignoring" replace the made-up "Un-trust" and "Un-ignore" — and the buttons look like buttons before you hover them, without turning red for what is a routine, reversible act.

= 1.49.0 =
* New: a warning that your CDN is blocking an AI company is now verified before it may keep saying so. Cloudflare counts crawlers by their User-Agent string, and anyone can wear one — on the site this was built for, a vulnerability scanner wore OpenAI's crawler name through a thousand blocked requests a day, and the warning read "Cloudflare is blocking OpenAI" about a company that never visited. While such a warning is live, the blocked traffic's source addresses are now checked hourly against what the company publishes. When everything checked proves to be an impostor, the warning stands down to a note that your edge is doing its job — nothing needs allowing. One verified request keeps the warning, and so does an inconclusive check: "could not say" is never read as "not them".
* Improved: the demoted notice keeps everything the warning had — the date the blocking started, and your fold if you had made one — and it becomes a warning again by itself the moment verified traffic starts being refused.
* Improved: a connected assistant reading the edge summary sees the same verdict: how many of the blocked requests proved to be the company, how many proved to be impostors, and how many could not be determined.

= 1.48.0 =
* New: the Request Log records what crawlers read on your pages, not only which AI files they fetched. Until now an AI crawler could read every article on the site and the log showed nothing but its llms.txt fetch. Only machines are recorded — your readers are never logged here, they are counted separately and without a User-Agent — and a switch in Settings turns the whole stream off if you would rather keep a longer history of the files alone. A page served from a CDN or a caching plugin never reaches WordPress, so on a cached site these are a floor rather than a total, and the screen says so.
* New: a request that arrives cryptographically signed is recorded as signed even when the signature belongs to an operator this plugin does not recognise. The check was already running, and a valid signature from anyone other than Google or OpenAI proved who sent it and then left no trace at all — so the first company to sign your site would have been invisible. It is written down now, without granting anyone standing they have not earned: proof of identity is not a reason to trust someone.
* New: a Signature filter on the Request Log, including "signed by anyone" — the question an owner asks first, and one no list of names can express, because you cannot pick the name of a company you have never seen. A connected assistant can ask the same question.
* New: a conflict between your CDN and your own AI policy now appears on the Findings list, dated. A CDN in front of your site can turn an assistant away before the request reaches WordPress, and Agentimus could already see that happening — but only said so on the Request Log, so an owner found out by going and looking. One had been running for weeks unseen. It is a finding now, urgent when the edge is refusing an assistant your policy welcomes, and it carries the date it started rather than only the window it was measured over.
* Improved: a CDN conflict says when it actually began, worked out from the hourly figures already kept rather than from the moment Agentimus first looked. Installing this feature today would otherwise date a problem that started last week to today. Where the figures cannot answer, the wording changes rather than the date being guessed at: "Started the 26th" when it was derived, "Running since at least the 30th" when it has held for as long as anything was kept, and "First seen today" when there is no history to read.
* Fixed: the Findings list said "Nothing is blocking AI assistants" whenever every setup check passed. That is a promise it could not keep: the checks measure this plugin's own configuration, and an assistant can be turned away by a CDN sitting in front of the site with every one of them green. It now says what it actually checked — "All N setup checks pass" — and the conflict above it carries the rest.
* Improved: the weekly email names any live conflict at your CDN, so the one channel that reaches you when you are not in wp-admin says it too.
* Improved: the conflict notice on the Request Log folds now instead of hiding. Only your CDN changing its behaviour, or your own policy changing, actually ends a conflict — so the notice collapses to one line you can reopen, and the Findings list and the weekly email keep counting it while it lasts. A notice you had hidden before comes back collapsed, not gone.
* Improved: the three small scripts the plugin adds to your pages are compressed when built, cutting them by about two thirds. Their source ships beside them, as it always has.
* Fixed: the address filter above the Request Log offered internal names — "markdown", "rest:discovery" — while the table beside it showed the plain ones. One screen, one vocabulary now.
* Fixed: a crawler could name your own site as its home page and have the plugin fetch you on its behalf, then record that as a visit. It never proved anything — of course your own front page answers — and it is refused outright now.
* Fixed: where a fake crawler borrows a real engine's name, the review card blended their numbers — it could say the forgeries were seen five hours ago when that request was the real engine's verified visit and the last actual forgery was days old. The failing requests now carry their own count and dates, and the real engine's verified traffic is noted separately.
* Fixed: a connected assistant's count of clients awaiting review included ones you had already blocked, so it could disagree with the bell in your admin bar; they read one count now. Each client it lists also carries the recorded IP addresses the review advice tells you to block.
* Fixed: on some hosts — macOS, mainly — text in Cyrillic, Arabic, Greek or Devanagari was corrupted on its way into the search-coverage check, logging PHP warnings and letting a page that contains such text be marked as not answering a search it answers.
* Improved: a post's structured data now carries its featured image. Machines reading the page's JSON-LD were told the headline, the author and the dates, but not the one picture the author chose for the piece — the field Google lists as recommended for articles. Emitted only when a featured image is set; nothing is borrowed from the body or the site identity to fill the gap.

= 1.47.0 =
* Fixed: on a site not written in the Latin alphabet, every page carrying a reported search was told its words answer nothing. The part that pulls the meaningful words out of a search reads Latin letters and numbers, so a search in Cyrillic, Chinese, Japanese, Arabic, Greek or Devanagari yielded nothing to look for — and that came out the far end as "none of it is on the page". The whole site sat on the list permanently and no amount of writing could clear it. A search the check cannot read is now reported as exactly that: not measured, not a failure.
* Fixed: a URL somebody pasted into a search box, and somebody's prompt pasted in whole, were judged against your pages like real searches. Both make rows no edit can ever close. Web addresses and anything far longer than a person types are now left out, alongside the machine probes that already were.
* New: you can set a search aside, not just a page. When a page does not answer the search it is found for, the page may be lacking — or the search was never a question your site should answer. Until now the only lever was to set the page aside, which takes good writing off the list to quiet a bad query. The row offers both now, and anything you set aside can be put back from the Search screen.
* New: an assistant asked to check one page gets the whole verdict for it. It graded only how readable a page was, so a page flagged for not answering its search came back spotless and an assistant read it as finished. It now answers both halves and says which of the searcher's words the page never uses.
* New: a connected assistant can set a search aside too, one at a time. It refuses any search no engine has actually reported for your site, and putting one back is always allowed.
* Improved: the list of searches left out of the Search screen now says why each one was left out, and the figure reading "weren't people" counts machine probes alone rather than everything excluded for any reason.
* Note: your site reads its pages once after updating, so a finding that was true all along may appear on your list.

= 1.46.0 =
* New: a connected assistant can read a page and change one passage of it instead of replacing the whole thing. It quotes the passage back exactly and that passage has to appear once — not found and found-twice are both refusals that change nothing, because a fix applied to a guess is how content gets mangled. The previous version is kept as a revision, and the saved page is read back and compared.
* New: an assistant can describe a picture that has no description — the commonest thing wrong with content on most sites, and the one thing it previously could not do.
* New: and it can describe the pictures inside a page, which is a different job. WordPress copies an image's description into the page when you insert it and never looks at it again, so the words a reader gets from a published page are the ones written into that page — describing the library copy changes nothing a reader sees. There is a tool for each now, and each says which copy it writes.
* New: a way to list the pictures in your media library that carry no description at all. Search matches words, and the images needing description are exactly the ones with no words on them, so that question could not be asked before. A description that is only the file name counts as none, the same way the page checks count it.
* Fixed: the tool for describing a picture refused the exact pages the checks were flagging. Where a description was only the file name, the check asked for it to be replaced and the tool refused it as already described — and told the assistant the checks were satisfied. Those pages could be found and never closed. The checks and the tools that fix them now share one definition of what a description is.
* Fixed: an empty description on a captioned picture is now a finding. An empty description means "this is decoration" and that is still respected, but WordPress leaves the field empty by default, so a captioned screenshot could reach every screen reader undescribed while the row said every image had one. A caption is evidence the picture means something.
* Fixed: that new check would never have run on anything already published — a page is read again only when the set of checks changes, and a new rule inside an existing check did not register as one. Your site re-reads its pages once after updating.
* Fixed: captions come in three shapes and only one was understood — the block editor's, the classic editor's, and the way the same classic caption is drawn on any theme that has not opted into HTML5 markup. All three are read now.
* Fixed: an assistant was told "this page has no featured image, add one" even on kinds of content that cannot have one. The two absences are told apart now.
* Fixed: the two counts of your worklist could disagree, and the probe that reads what your theme does with a picture's description had a gap in its filter.
* Improved: on the dashboard's Today line, how each figure compares with yesterday is a small arrow at the end of its label rather than a coloured sentence beneath it. Hover it for the number; a figure that did not move says nothing at all.

= 1.45.0 =
* Fixed: a page could be told it does not answer a search it is no longer found for. That reading is measured in the background against the search the engines showed the page for at the time, and the engines move that search without anybody touching the page — so the reading beside it became an answer to a different question. Pages are read again when their search changes, and the old reading stays on screen, marked, until the new one replaces it.
* Fixed: a day Bing reported nothing about was drawn as a drop to zero pages in the index. Bing answers for every date in its window whether or not it holds numbers for that date, so a date with nothing in it was stored and drawn as a measurement — a fall to zero and back, on a site that never left the index. Those days now keep their place in the chart as a gap you can hover, and say what they are when opened.
* Fixed: for the same reason the "Pages in Bing's index" figure above that chart could have read zero, and the warning about Bing's crawler hitting errors could have gone quiet on a site that was still erroring. Both now read the most recent day Bing actually reported.
* Fixed: fresh numbers from Bing could be overwritten by an empty answer, because Bing re-sends its whole window on every poll. An empty answer can no longer overwrite a reading.
* Fixed: "Report an Issue" in the More menu had no icon, and the Report screen wore the flag drawn for it. Both have their own mark again.
* Improved: where two searches on a page are tied for busiest, the same one wins every time — the order could vary between readings, which meant the search a page was measured against could change on its own.

= 1.44.0 =
* New: a Report screen, under More. What AI did on your site between two dates — how many AI crawlers read you and which ones, how many people arrived from an AI answer, what assistants did here, where you stand in search, whether a citation check ran, your score and the one thing worth doing. Today, yesterday, seven days, thirty, or any two dates from a calendar that names both ends and lets you change either one. It is built by the same code as your weekly email, so the page and that email can never tell you two different things, and every block links to the screen that owns its detail.
* New: the dashboard opens with today — what AI read, who arrived from an AI answer, and what acted here since midnight, each against yesterday. Only the numbers that can honestly mean "today" appear there; the ones that cannot say so on the Report screen, where each block dates itself. Days are counted in UTC — the same clock as the daily chart and your weekly email — and the label says "UTC day" whenever that isn't the date on your own clock.
* New: click a citation verdict and read the whole answer — what was asked, which assistant answered, what it said, and every site it cited, as text you can select. Answers are kept in full from now on; checks from before this release show their opening and say that is what was stored.
* Fixed: the sites listed under a citation answer were Google's, not the ones cited. A grounded Gemini answer hands back a redirect address for every source it used, and names the real site in a field beside it. Agentimus kept only the address, so every source row named Google. It keeps the site now, and lists it by name.
* Fixed: "never linked its site" was arithmetic, not a reading. Because those addresses were all Google's, a Gemini answer could never be counted as linking to you. It is a real measurement now, and on your first run after this release it may go up: nothing changed on your site, it simply became possible to see.
* Fixed: a heading could be black on black. Six headings never named their own colour and inherited WordPress's near-black, invisible on every dark admin scheme — "No Results Yet" on the citations screen was the one you would meet first.
* Fixed: the refresh mark in the citations bar sat in the middle of the row, belonging to nothing, and kept offering itself while a check was running. It sits with the reading it re-reads, and no disabled control in the plugin wears a tooltip now.
* Fixed: a stored answer stopped mid-word, cut at 600 bytes with nothing to say it had been cut. It ends on a full sentence now and says there was more; a provider's error message was cut the same way and now says so too.
* Improved: each tracked product's citation card folds — the verdict stays in view, the detail folds away — so tracking a few products no longer makes one very long page. Under each, a line says what moved since the run before.
* Improved: the verdict chip is a button now, shaped like one, and the engine's own words arrive as prose rather than raw Markdown.
* Improved: two panels on the dashboard say what they hold in plainer words. "Doors" is now "AI access" — the same words as the button beneath it — and "Signals" is "Telling engines", which is what its own line underneath already said. Nothing moved; they are the same four panels.
* Improved: a citation check you start yourself no longer messages your channels. Telegram, Slack, Discord and your sheet exist to carry what happened while nobody was looking; you pressed the button and the screen is already filling in. Scheduled runs announce themselves exactly as before.
* Improved: the dashboard counted today twice; the machines tile now says what it was there to say without repeating the number.

= 1.43.0 =
* Fixed: the Google index check would not stay finished. It ran to the end, said so, and then started over a few minutes later — again and again, spending a slice of Google's daily allowance on every lap. The check leaves a safety net behind it in case you close the tab mid-run, and that net was never taken down when the run ended; an empty queue is how the check is told to begin a new one. It now stands down with the run it was watching.
* Fixed: Cancel on that check stopped the loop in your browser and nothing else. The queue lives on your site, so the run carried on without you — the safety net finished it within minutes, and refreshing the page set your browser going again. Cancel is a decision your site keeps now: the remaining pages wait for your next press or for tomorrow's daily check, which is what the card always said would happen. It also says "Stopped" while they wait, instead of looking like work in progress.
* Fixed: publishing did not clear your listing pages from Cloudflare. It cleared the post, your home page and your AI files — but not the page your articles are listed on, nor your category and tag pages, your feed or your sitemap, every one of which changes the moment you publish and every one of which Cloudflare had been holding for hours. If your articles live on their own page rather than your home page, that is the one you would have noticed.
* Fixed: your public files were naming every tool your MCP server holds, with its description, when your own dashboard correctly said none of them were listed. Agentimus marks its own tools as needing a sign-in, precisely so their names stay off public surfaces — one file was built a different way and never got the message. The files now say how many tools the server has without naming them, which is the same rule Agentimus already applied to every other plugin's tools. An assistant that connects still sees all of them.
* Fixed: on the dashboard, you could not tell a link from a status word. Both were the same green — the two colours Agentimus uses for “this is healthy” and “you can click this” resolve to the same value on most admin colour schemes — and the words you cannot click were the bolder of the two, so the strongest-looking green on the screen was the one that did nothing. Links that sit in a row or a sentence now carry an underline; the ones that stand alone with an arrow already announced themselves and are unchanged. Nothing relies on colour alone any more, which matters for anyone who does not see these two greens apart.
* Fixed: the agent preview could contradict itself. On a published page with an empty body it said “This is served at the page’s .md URL right now” directly above “No Markdown is served for this page”. Both were reachable at once; the reassuring line now stands down when there is nothing to serve.
* New: a connected assistant can see the clients your site is waiting on a verdict about, and answer them — block, allow, set aside for now, or undo a decision you made earlier. It can also re-run the identity check on a single client. It reads the same evidence you do: how much each one fetched, whether the identity check believed it, and the exact rule a block would match on. Search engines are refused here just as they are on your own screen, and clearing the log stays yours alone.
* New: an assistant can also read what has been done on your site through a key — keys created and used, abilities run, requests refused — including whether this WordPress can see ability runs at all, so an empty list is never mistaken for a quiet week.
* Improved: a cache purge that only half finished says so. The pages go to Cloudflare in batches, and a batch that fails leaves everything after it still serving old copies — the panel now says how many of how many were cleared, rather than reporting one flat failure over a job that partly worked.
* Improved: three screens that said "there is nothing here" now say what would put something there — the agent access record, and the two previews of what an assistant would be served for a page.
* Improved: the About screen matches the plugin again. It had been naming a tool count from two releases ago, listing the write tools without the four added since, and describing what a cache purge clears before this release widened it. It also now says plainly that an assistant with write access can decide about a client in your review queue — that changes what your site refuses at the door, and a page promising an exact account of what Agentimus touches has to say so.

The most recent releases are listed here, back to 1.43.0. Every earlier one, back to 1.22.0, is kept in the full history in the same detail: https://github.com/heera/agentimus/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 1.50.1 =
The Findings screen drops its "Put this away" link — it only moved a suggestion into a fold on the same screen, and only some rows had it. Every finding now stays in its list, and anything you had put away comes back. The Dashboard's Today card moves down beside the full-width cards it belongs with, with the four small tiles leading the page. No breaking changes.

= 1.50.0 =
You can now write your own robots.txt rules from the Crawler Policy card — appended to the generated file, never replacing it, with a live view of the real file and a reset that is just clearing the box. The review bell's cards say why a visitor is unchecked, and every recommendation names the kind of action it asks for before you read a word. Fixes: the panel no longer advises turning on a setting that is already on, and a real-but-unrecognised signature no longer displays as a full pass. No breaking changes.

= 1.49.0 =
A warning that your CDN is blocking an AI company is now verified before it may keep saying so: the blocked traffic's addresses are checked against what the company publishes, and when it all proves to be impostors wearing the company's name, the warning stands down to a note that your edge is doing its job. Found live the day it was built: a scanner wearing OpenAI's crawler name, a thousand blocked requests a day, and the real OpenAI never visiting. No breaking changes.

= 1.48.0 =
The Request Log now records what crawlers read on your pages, not only which AI files they fetched — so an AI reading every article on your site is no longer invisible. Only machines are recorded; your readers are never logged there. A conflict between your CDN and your own AI policy now reaches the Findings list, dated, instead of waiting on one screen for you to find it — and that list no longer says "nothing is blocking AI assistants" when all it checked was this plugin's own setup. A post's structured data now carries its featured image. No breaking changes.

= 1.47.0 =
If your site is not written in the Latin alphabet, Agentimus has been telling you every page answers nothing — a search it could not read came out as a page that failed. That is fixed, and those pages ask nothing of you now. You can also set a search aside instead of the page it landed on, for the times a reported search was never a question your site should answer. Your site re-reads its pages once after updating, so a finding that was true all along may appear. No breaking changes.

= 1.46.0 =
A connected assistant can now maintain a site rather than only rewrite it: read a page, change one passage, and describe pictures that have none. Two alt-text faults are fixed — the tool that describes a picture used to refuse the exact pages the checks flagged, and an empty description on a captioned picture now counts as a gap. Your site re-reads its pages once after updating, so a finding that was true all along may appear. No breaking changes.

= 1.45.0 =
Two readings that could be quietly wrong are fixed. A page could be listed as not answering a search it is no longer found for; those pages are read again now when their search changes. And a day Bing reported nothing about was drawn as a fall to zero pages in the index — those days now show as a gap that says so, and the index figure above the chart can no longer read zero because Bing stayed quiet. No breaking changes.

= 1.44.0 =
A new Report screen under More says what AI did on your site between any two dates, and the dashboard now opens with today. Citation sources name the site that was actually cited instead of Google's redirect, so "linked your site" is a real reading at last — it may go up on your first run without anything changing on your site. You can also click a verdict and read the whole answer. A heading that was invisible on dark admin schemes is readable again. No breaking changes.

= 1.43.0 =
The Google index check no longer restarts itself after finishing, and Cancel really stops it now. Publishing also clears your listing pages from Cloudflare — the page your articles are listed on, category and tag pages, your feed and your sitemap. Links on the dashboard no longer look identical to status text. A connected assistant can see the clients waiting on a verdict and answer them.

= 1.42.0 =
Your dashboard no longer shows Agentimus's internal names for the files AI assistants fetch, and the sidebar's two lists say what they hold. Plugin cards and open Discovery addresses are now links you can follow. A connected assistant can read your announcement ledger and see what you are connected to — without ever being handed an address or a key. Also fixes a case where another plugin's runtime change could be saved into your own settings. No breaking changes.

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

