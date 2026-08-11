=== Agentimus – AI SEO, llms.txt & MCP for AI Agents ===
Contributors: heera
Tags: ai-agents, mcp, agent-readiness, llms-txt, ai-seo
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.36.0-dev60
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site agent-ready: AI SEO (AEO/GEO), llms.txt & bot control — be found, read & cited by AI — and write, edit & publish via MCP.

== Description ==

Agentimus is an all-in-one AI SEO plugin for the age of AI agents — AEO (Answer Engine Optimization) and GEO (Generative Engine Optimization) in one place. It does two things.

**It makes your site legible and citable.** It helps AI assistants like ChatGPT, Claude and Perplexity find your site, read it correctly, and cite it in your own words — and shows you which AI bots are actually visiting. **You don't need to understand AI or web standards to use it:** a setup wizard walks you through everything in about a minute, then it runs on its own.

**And it lets the AI tools you already use operate your site.** Turn on the built-in Model Context Protocol (MCP) server and Claude Code, Claude Desktop, Cursor or Codex can read your reports and — behind two more opt-in switches — **draft, edit and publish posts** for you, every write running as the signed-in user, permission-checked and audited. All three switches are off by default. Prefer to stay in wp-admin? A built-in **writing assistant** drafts and revises posts there.

By default it makes no outbound requests, collects no analytics, and logs no IP addresses — everything runs on your own site. Three optional, off-by-default features change that only when you enable them: **AI Visibility**, **Verify bot identities** and **Store IP addresses** (each disclosed in full under *External services*).

**📖 Full documentation** — a plain-English manual and developer reference, step-by-step guides for every feature: https://heera.github.io/agentimus/

**With an SEO plugin — or instead of one**

* **No SEO plugin? You don't need one.** Agentimus covers the search basics itself: per-page SEO titles, social share cards (Open Graph), canonical links, meta descriptions, and an XML sitemap carrying the last-changed dates core's own leaves out.
* **Already running Yoast, Rank Math, SEOPress, AIOSEO or The SEO Framework?** It detects them and steps aside on every overlapping surface — titles, cards, schema, sitemap. No duplicate tags, ever: it adds only the AI layer your SEO plugin doesn't cover.

**Operate your site from your AI agent (MCP) — opt-in**

* **A Model Context Protocol server on your own site** — one switch (Settings → Discovery) runs an MCP server at `/wp-json/agentimus/v1/mcp`; the whole library ships with the plugin, nothing extra to install.
* **Connect by approving, not by pasting keys** — an assistant asks *you* for permission on a consent page on your own site; you choose **Read only** or **Read and write**, and each approved assistant gets its own key and its own Disconnect. Standards-based (OAuth 2.1 with PKCE), nothing brokered by a third party; a revocable **shared token** covers clients that can't ask.
* **Read your site's data** — connected agents run the read-only tools (readiness/AEO-GEO score, AI traffic, request log, bot identification, page / JSON-LD / Markdown previews) — and your llms.txt, discovery and agent-card documents are offered as readable **resources**, attachable like a file.
* **Draft, edit and publish posts — behind two more switches** — turn on **Let connected agents write** and the agent can create and edit posts and pages fully dressed (categories, tags, featured image, AI topics and descriptions) and apply Readiness fixes; it can search your media library by title or alt text to reuse a picture you already have. Turn on a third switch and it may publish, otherwise it leaves drafts for your review.
* **Safe by construction** — every write runs as the signed-in WordPress user, never exceeding their permissions, and is recorded under **More → Agent Access**. Nothing is public, and with the write switch off the write tools don't exist on any surface.

**Write with AI in wp-admin — the built-in assistant (opt-in)**

* **Idea → draft without leaving wp-admin** — a spark button opens the writing assistant: pick posts or pages, describe what you want, shape the outline it proposes, then preview the complete draft — real editor blocks, AI description, topics, categories, tags. A page is written as a page: no invented sections, no image slots. Nothing is saved until you click **Create draft**, and it never publishes.
* **Ask AI in the editor — one block, a selection, or the whole post** — rewrite or extend the block you're in; select several and change them with one instruction; or ask about the whole post and get back a list of proposed edits — rewrite this, delete that, add a section here — each with its reason, to accept or reject one at a time. Blocks the plan doesn't name are never touched, and undo steps back through everything.
* **Images where you write** — alt-filled placeholders in drafts, **Generate image from the alt text** on every image block, a **Featured image (AI)** sidebar panel — or pick from your library. Runs on WordPress's built-in AI Client (7.0+) — Agentimus never sees your key — and every AI button hides until a provider is set up.

**Control — who may use your content**

* **robots.txt content-signals + AI-training blocklist** — declare your content-usage policy and block named model-training crawlers (GPTBot, CCBot, ClaudeBot, Google-Extended, Bytespider, …) by name, while leaving read/cite bots free.
* **Block scanners & scrapers (opt-in hard block)** — robots rules are a polite request; this enforces them, returning 403 to the user-agents on your denylist. Your **always-allowed** list is never blocked: pre-trust well-known AI assistants with one click; major search engines are recognised automatically, and SSL-renewal requests always stay reachable.

**Reduce exposure — what your site reveals to bots**

* **Exposure controls (opt-in, all OFF by default)** — switches that quietly close what stock WordPress reveals to anonymous crawlers: username enumeration, author archives, the WordPress version, the auto-generated `<head>` discovery links, and XML-RPC. Signed-in admins and the block editor are never affected. Exposure hygiene, not a firewall.

**Visibility — who is reading you**

* **Agent activity log** — a dashboard of which AI crawlers and agents actually fetch your content and endpoints (GPTBot, Claude, Perplexity, Googlebot, …), recorded first-party in your own database, with no IP logging by default (an optional setting stores IPs for flagged crawlers only).
* **Activity to review** — a nav-bar queue surfaces clients worth a second look — new, unusually high-volume, or spoofing — with one-click **Block** or **Allow**. Nothing is blocked unless you choose to.
* **Request Log** — every recorded request, one row each, under *More → Request Log*. Filter by client, endpoint, network, user-agent and date to see exactly what a single bot fetched.
* **Agent Access** — the other side of the log: who *authenticates to and acts on* the machine surface (*More → Agent Access*): assistants approved, keys created or revoked, abilities run, requests refused. A record, not a guard — it names the key used, never the person.
* **Traffic from AI** — the mirror of the crawler log: the real visitors an AI assistant sent you, day by day, by assistant and by landing page (*More → Readers*) — daily aggregate counts, never a row for one person, no IP. An opt-in **CDN mode** keeps counts accurate behind a full-page cache.
* **Edge traffic (Cloudflare, opt-in)** — what Cloudflare answered or blocked *before your server ever saw the request* — the cache hits and edge blocks no server-side log can see — read with your own token, and it warns when the edge disagrees with your policy.
* **You decide how long it's kept** — retention, nightly auto-delete, and a hard size cap that always applies, so the log can never outgrow your host.
* **AI Visibility (opt-in)** — track **each brand, product or person you choose** across ChatGPT, Perplexity, Gemini and Claude: whether it gets **mentioned, linked, and how it ranks against its rivals** — over time, against the questions your audience actually types. Off by default; **you bring your own API key** (the one feature that makes an outbound request — see *External services*).

**Classic search, measured — Bing & Google (opt-in)**

* **Search Performance & Opportunities** — connect Bing Webmaster Tools and/or Google Search Console (a key held on your own server, no third-party proxy) and see what people searched, how often you appeared, and which pages sit one improvement from page one. Every number is the engine's own — never estimated — and automated probe traffic is named, not blended in.
* **In Google's Index / Found by AI Search** — whether the indexes behind AI Overviews, Gemini, ChatGPT search and Copilot actually hold your pages: the whole site in rotation, every verdict in the engine's own words, problems grouped with deep links to where the fix lives, any page's answer one lookup away or re-checked live on the spot — plus week-on-week trend, Google Discover, and the health of the sitemap you registered.

**Content — clean, machine-readable output**

* **Markdown delivery** — request any page as clean markdown by appending `.md` to its URL. (An `Accept: text/markdown` mode also exists, off by default.)
* **/llms.txt** & **/llms-full.txt** — an [llmstxt.org](https://llmstxt.org) index of your pages, topics and recent posts, plus a full-text edition an agent can ingest in a single request.
* **JSON-LD** — WebSite + Person/Organization, plus BlogPosting and BreadcrumbList on posts. Automatically **defers to Yoast, Rank Math, SEOPress, AIOSEO and The SEO Framework** so you never ship duplicate schema.
* **Topics for AI** — say what each post is about in plain words, right in the editor; those topics become the JSON-LD `keywords` and a line in the page's `.md`, so assistants understand each page's subject. Type your own, or let Agentimus fill them in from the post's own tags and categories. Nothing shows on the visible page.
* **AI description** — write a one-line summary of each post in the editor; it becomes the JSON-LD `description`, the lead of the page's `.md`, and the page's `<meta name="description">` (replacing your theme's, unless an SEO plugin owns it). Blank falls back to the excerpt. A sub-switch can keep it out of your `<head>`.
* **XML sitemap** — with no SEO plugin, Agentimus serves your sitemap (index + paginated sub-sitemaps, with last-changed dates) at WordPress's standard `/wp-sitemap.xml` address, and advertises it in robots.txt and llms.txt; with one installed, it links theirs instead.
* **Change feed** — a JSON feed at `/agentimus-changes.json` lists recently added, updated and removed pages (with a `?since=` filter), so an assistant re-checks only what changed. On by default, advertised in discovery.

**Identity & contact**

* **Author / site identity** — a profile sentence, expertise topics and linked profiles (`sameAs`) feed llms.txt and JSON-LD — the highest-signal lines for agent retrieval.
* **security.txt** — optionally publish an RFC 9116 disclosure contact at `/.well-known/security.txt`.

**Readiness report**

* A one-screen score of how machine-readable your site is, with a plain-English checklist of what's enabled and what's still missing.
* **Agent preview** — open it from the Readiness tab to see the exact JSON-LD *and* Markdown an AI agent receives for the whole site or any page, then copy it. It shows what would ship even when the feature is off, and a matching read-only preview sits in the post editor.
* **AI Readability tips** — as you write, a panel flags what makes a page hard for an assistant to read and cite: thin content, missing headings, no opening summary, a nav-heavy page, images without alt text. Editor-only — nothing shows to visitors.

**Machine discovery (forward-looking)**

Everything above is read by search engines and AI tools **today**. This part is forward-looking — the conventions the agent ecosystem is converging on (`.well-known`, A2A agent cards, MCP-shaped tools), putting identity, capabilities and APIs in one predictable place:

* **/.well-known/discovery.json** — an owner-curated document describing the site's identity, capabilities, APIs and agent cards. Other plugins can declare themselves through one optional hook.
* **/.well-known/agent-card.json** and **/.well-known/mcp.json** — an A2A agent card and an MCP manifest, generated automatically.
* **Standards-aligned `.well-known` endpoints** — an RFC 9727 `api-catalog`, plus — *only when the capability actually exists* — an MCP server card and an Agent Skills index. **Response signing** (Web Bot Auth / HTTP Message Signatures, RFC 9421) signs them with an Ed25519 key that never leaves your server, so agents can verify they came from you; on by default.
* **WordPress Abilities API** — the same read-only tools are registered as WordPress abilities, so its built-in AI — and, with the MCP adapter, external agents — can read them, each gated by the same capability as its screen. A separate, off-by-default switch adds the write abilities above.
* **Zero-config auto-discovery** — reads your REST namespaces, public post types and the Abilities API, so a site is described even when no plugin declares itself; the **Discovery Hub** screen shows what an agent sees, and you decide what is published.

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

By default, no — Agentimus makes no outbound HTTP requests out of the box, sends nothing to any external service, collects no analytics or telemetry, and stores the agent-activity log in your own database with no IP addresses. (One opt-in setting, *Store IP addresses for flagged clients*, can store IPs locally for flagged crawlers only — off by default; see *External services*.) Two opt-in features go outbound. **Verify bot identities** makes DNS lookups and downloads, once a day, the crawler-IP lists that bot operators publish (Google, OpenAI, Perplexity, …) so impostors can be caught; when a request arrives carrying a Web Bot Auth signature, it also fetches that operator's public key file so the signature can be checked on your own server. Only those public files are fetched, and nothing about your site is sent. **The other is the optional AI Visibility feature:** if you enable it and add your own API key, Agentimus queries the AI provider(s) you chose (OpenAI, Perplexity, Gemini and/or Anthropic) to check whether they mention and cite you — only for the engines you turn on, and only when a check runs (on demand or on your schedule). Your keys stay on your server and nothing else is sent anywhere. See *External services* for the full disclosure. The discovery document includes a `$schema` value that *identifies* the document format (the same way a schema.org URL identifies a vocabulary); it is a label in the output, never fetched. The one place a request is made is the optional "Verify live" self-check on the readiness report — and that runs in *your browser*, fetching your own public URLs only when you click it; the server itself still makes no request.

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

Yes — as an opt-in, on WordPress 6.9 or newer. Turn on **Settings → Discovery → MCP server** and the AI assistants you already use can talk to your site over the Model Context Protocol and run the same permission-checked tools your admin AI gets — sixteen read-only ones (readiness, AI traffic, the request log, edge traffic, search performance and opportunities, Google index answers, bot identification, per-page readability, internal-link suggestions, media search and previews), plus the write tools if you separately allow those (see the next question). It also offers your site's own documents to be *read* rather than run — llms.txt, llms-full.txt, discovery.json and your agent card are listed as MCP resources, so an assistant attaches them the way it attaches a file, and one you have switched off is never offered. Connecting is usually one approval: give the assistant your server address and it asks you for permission on a page served by your own site, where you choose read-only or read-and-write. Claude, ChatGPT and Cursor work this way today (ChatGPT behind its Developer-mode switch); Codex cannot ask, so it takes a shared token you create on the card and send as a Bearer header. A WordPress application password still works too, for one key per tool tied to a specific user. Nothing becomes public — every request signs in, each tool keeps the same permission checks as the admin screens, and every call is recorded under **More → Agent Access**. Off by default, and everything needed ships with the plugin.

= Can an AI agent write to my site? =

Only if you say so, twice. The MCP server starts read-only; a second switch — **Let connected agents write** — adds the write tools: draft and edit posts and pages complete with categories, tags and a featured image (searched from your media library by title or alt text, or imported from a URL), set their AI topics and descriptions, and apply Readiness fixes (a fixed list of safe switches that can only turn documented features on, never loosen a protection). Even then, agents can't publish: they leave drafts and pending posts for your review, unless you flip a third switch that allows going live. Writes are held to the site’s own quality bar too: the write tools show agents the same readability rules the in-admin assistant drafts to, and every create or update returns the post’s AI-readability grade. Every write runs as the signed-in user — an agent can never do more than that user could in the editor: filing under existing categories, creating new ones, and uploading images each follow that user's own permissions — and every call is recorded under **More → Agent Access**, attributed to the key that made it.

= Can AI help me write the description, topics and fixes? =

Yes, if you're on WordPress 7.0 and have set up an AI provider under Settings → AI. Then **Draft with AI** appears on the AI description field, **Suggest with AI** on the Topics field, and **Fix with AI** on any AI Readability row that needs work. Agentimus asks *your* AI through WordPress's shared connectors — it never sees or stores your API key, and nothing is sent anywhere if you haven't set a provider up (the buttons simply don't appear). Every suggestion arrives as ordinary editable text in the field: you read it, change it, and save the post yourself. Nothing is written for you.

= Can AI write a whole post for me? =

Yes — the writing assistant (the spark button on Agentimus's own screens) turns a described idea into a complete draft. It proposes an outline you can edit first, then writes the title, body, AI description and topics with suggested categories and tags, and shows you everything before a single thing is saved. **Create draft** opens the post in the editor, where image placeholders arrive with their alt text ready — fill them from your library, or generate them with AI. Revising an existing post happens in the editor instead, where **Ask AI** works at three scopes: the block you are in, several blocks you select, or the whole post — the last returning a list of proposed edits, each with its reason, that you accept or reject one at a time. It needs the **Let connected agents write** switch on and an AI provider under Settings → AI, and it never publishes: drafts and pending review only.

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

1. Dashboard — your AEO/GEO score across five plain rungs (Findable, Readable, Trusted, Optimized, Cited) with the one next step worth taking, alongside a first-party log of which AI agents and crawlers fetched your endpoints, who reads you most, and the real visitors AI assistants sent. Every day bar opens that day's full report.
2. In Google's Index — whether Google's index actually holds your pages, counted across the whole site and walked in rotation, with pages Google left out re-checked daily until they get in. Healthy pages stay a count; what earns a row is a page that needs a look, grouped by state in Google's own words, each with a link straight to that URL in Search Console. The index behind classic Google Search and AI Overviews, AI Mode and Gemini alike.
3. Search Performance — what people searched, how often you appeared, how often those results were clicked, and the pages that earned them. Every number is the engine's own; connect both Google and Bing and a switch appears, because the two count different searchers and are never merged. Searches that are really scraper probes are marked, not hidden.
4. Readiness report — a plain-English pass/warn checklist of what's enabled and what's still missing, and beneath it the Optimize worklist: exactly which posts and pages an answer engine would struggle to quote, and why. Set aside anything not meant to be cited, page by page or a whole check at once.
5. Crawler policy & bot identity — declare your content-usage signals, block AI-training crawlers by name, turn away spoofed traffic, and verify bots against what each operator publishes: reverse DNS for the search engines, published IP ranges for GPTBot and PerplexityBot, cryptographic signatures for agents that sign. The verified-bots registry is yours to edit.
6. Request Log — every request an agent made, in one filterable table: narrow by client, endpoint, network, verification verdict, User-Agent or date to see exactly what a single bot fetched. A Status column carries one honest mark per request — verified, signed, spoofed, forged, refused or unchecked. Your own logged-in visits are never recorded.
7. MCP server — one switch runs a Model Context Protocol server on your own site. Your server address leads: give it to an assistant and it asks you for approval — no keys to paste — and every approved assistant is listed with its scope, its last call and its own Disconnect. Above it, the trust ladder: a second switch lets agents write, a third decides whether they may publish or only leave drafts.
8. Data sources — the outside services Agentimus reads from, all optional, each with one key held on your own server and no third-party proxy: Cloudflare for what the edge answered or blocked before your server saw it (and, with an optional extra permission, clearing its cache when you publish), Google Search Console and Bing Webmaster Tools for classic search and index coverage. Numbers land in your own database, so your history outlives each service's own reporting window.
9. AI Visibility — an opt-in, bring-your-own-key scoreboard showing whether ChatGPT, Perplexity, Gemini and Claude mention and link each brand, product or person you track: seen-in-answers and linked-your-site rates, rank against each item's own rivals, and question-by-question results with the sources each engine cited.
10. Discovery Hub — everything your site tells AI agents, in one place: the providers describing it, the read capabilities they expose, the public APIs, and the MCP & tools surface — your own MCP server alongside the WordPress Abilities API, with the tools each carries. Each summary tile jumps to its own countable list, and any registration problem is listed with a plain-English fix.
11. The writing assistant — a spark button on every Agentimus screen opens the drawer: describe the post you want, edit the outline it proposes, preview the fully dressed draft, then create it as a draft and land straight in the editor. Nothing is saved until you say so, and it never publishes.
12. In the post editor — the "Agentimus" box, AI Readability tab: a per-page pass/warn check of what makes the page hard for an assistant to read and cite — enough substance, an opening summary, section headings, heading order, prose vs links, and image alt text. Each row that needs work offers "Fix with AI", which drafts a concrete fix using the AI provider you set up in WordPress.

== External services ==

Agentimus makes no outbound requests by default: no remote scripts, fonts or analytics, and the agent-activity log stays in your own database with no IP addresses. (IP storage is optional, off by default — see the FAQ.)

**Every outbound feature is opt-in and off by default.**

**Data sources & IndexNow:** connecting Cloudflare, Google Search Console or Bing Webmaster Tools polls that service with your own key. The optional IndexNow switch announces published and removed URLs to search engines via api.indexnow.org (https://www.indexnow.org/) — only the changed URL list is sent.

**Verify bot identities** makes DNS lookups and, once a day, downloads the IP-range files bot operators publish to verify their crawlers (Google, Microsoft, DuckDuckGo, Apple, OpenAI, Perplexity — or a URL you add yourself). Only those files are fetched; nothing about your site is sent.

The same setting verifies **Web Bot Auth** signatures — the standard where an AI agent signs its request cryptographically. To check one, Agentimus fetches (and caches) the signing operator's **public key directory** at `/.well-known/http-message-signatures-directory`, only when a signed request arrives, and sends nothing about your site with it. Operators publishing one today:

* **OpenAI** — https://chatgpt.com/.well-known/http-message-signatures-directory · https://openai.com/policies/terms-of-use · https://openai.com/policies/privacy-policy
* **Google** — https://agent.bot.goog/.well-known/http-message-signatures-directory · https://policies.google.com/terms · https://policies.google.com/privacy

**AI Visibility:** when you enable it and add your own API key for a provider, Agentimus sends the prompts you configured to that provider to check whether it mentions and cites your site — only for the engines you turn on, and only when a check runs. Your keys are stored on your own site and used solely for these calls. The providers, with their terms and privacy policies:

* **OpenAI (ChatGPT)** — https://openai.com/policies/terms-of-use · https://openai.com/policies/privacy-policy
* **Perplexity** — https://www.perplexity.ai/hub/legal/terms-of-service · https://www.perplexity.ai/hub/legal/privacy-policy
* **Google (Gemini)** — https://ai.google.dev/gemini-api/terms · https://policies.google.com/privacy
* **Anthropic (Claude)** — https://www.anthropic.com/legal/consumer-terms · https://www.anthropic.com/legal/privacy

URL-like strings in the plugin's output are labels, not requests — the discovery documents' `$schema` value names the format (never fetched), and the `example.com` URLs in `examples/` are documentation placeholders.

== Changelog ==

= 1.35.0 =
* New: Ask AI in the post editor, at three scopes. Rewrite or extend the block you are in; select several blocks and change them all with one instruction; or ask about the whole post and get back a list of proposed edits — rewrite this paragraph, delete that one, add a section here — each with the reason it was proposed, to accept or reject one at a time. Blocks the plan does not name are never touched, and every change steps back through the editor's own undo.
* Changed: the writing assistant creates, the editor revises. Editing an existing post inside the assistant's drawer is gone: that drawer could only safely handle the blocks it had written itself, so any post with a real-world layout was declined rather than mangled. Create draft now opens the post in the editor, where every block type is at home and Ask AI does the revising. Nothing you have written is affected — this removes a screen, not a capability.
* New: a page is not an article, and the assistant now knows the difference. It writes posts, pages and your own content types, and a page gets no invented sections, no image slots, and no tags or categories — where only you know a fact (an address, an opening time, a policy) it leaves a marked placeholder instead of writing something plausible. Choose the type on the brief screen and filter the list by it. Agents creating content over MCP follow the same rules, so a page drafted from your assistant reads like one drafted here.
* New: ask Google about one page, right now. Every problem row, and the page lookup, gains a Re-check that inspects that single URL there and then — one of the day's 2,000 inspections, stored like any other — so a page you just fixed is never judged by yesterday's answer. Connected agents can ask the cheap way too: read-google-index now takes a URL and answers from the stored checks, spending nothing.
* New: your site's own documents, offered to be read rather than run. llms.txt, llms-full.txt, discovery.json and your agent card are now listed as MCP resources, so a connected assistant can attach them the way it attaches a file, instead of being told to fetch a URL. A document you have switched off is never offered — an agent should not be handed a promise that 404s.
* New: an agent can finally name a picture you already have. A search-media tool finds images by title and by alt text — the place a photograph is actually described — so an assistant setting a featured image can choose one from your library instead of inventing one. Gated by the same permission as uploading.
* New: cancel a check you started. A whole-site sweep runs as a chain of short requests and there was no way out of one but closing the tab. Cancel stops it after the answer already in flight (that inspection is spent either way), and the rest of the queue waits for your next press or for the daily check — it is not thrown away.
* Improved: the page lookup understands what a person types. "privacy-policy" and "yoursite.com/terms" mean the same page as "/terms/"; only genuinely foreign addresses are refused. Answering your own URL with "that address is not on this site" was the tool being pedantic about its own input format.
* Improved: images are suggested to fit the post. A short draft no longer gets the same number of image slots as a long one, and revision in the editor never caps the images a post already has.
* Improved: the AI Readability panel stopped scolding. "Thin content" is now "Not enough substance yet", "Hard to read" is "Low reading ease", and "Nothing concrete to quote" is "Short on specifics" — the same measurements, named as a stage rather than a verdict.
* Improved: text buttons across the admin stop underlining themselves — a light pill on hover, room reserved so nothing shifts under the cursor, and a real focus ring for keyboards.

= 1.34.0 =
* New: Clear Cloudflare's cache from Agentimus — publishing or editing a post now clears its stale copies from Cloudflare's edge (the one cache no caching plugin purges for you), along with the front page and the machine files agents read, and the edge panel gains a Purge button for everything else. Needs one extra permission on your token — Zone → Cache Purge → Purge; without it everything stays read-only, and the panel says so in words.
* Improved: In Google's Index now reports your site, not a sample of it. The headline numbers cover every page checked — on a 200-page site with 100 pages out of the index, the first number you read says so — and the summary accounts for every page, so the healthy majority is quiet rather than unmentioned. The standing list of watched pages is gone: those green rows never changed, and which pages get asked about first is a schedule, which the card now states in a sentence instead of a list. What you see is what needs a look, or news.
* New: a page that Google has left out is now re-checked every day until it gets in — up to 20 a day, the one waiting longest first, rather than waiting its turn in the whole-site rotation. And when one finally gets in, the card says so for about two days instead of the problem quietly disappearing, which is the moment you were waiting for. Every problem page keeps its own door into Search Console, however many there are.
* Improved: a network blip during the index check no longer ends the run — a slow or missing answer from Google rejoins the back of the line and the check carries on, reporting a failure only after three misses in a row. The run resumes where it stopped either way, and finishes on its own if you close the tab mid-check, so one slow answer never costs a whole run's inspection budget or asks for another press of Check now. Google's inspection also gets the time it actually takes to answer.
* Improved: the robots.txt check now opens the file as each engine last read it — a door to Google's robots report and Bing's robots tester, one per connected source, available any day on demand.
* Fixed: the XML-sitemap readiness check still named the pre-1.33 legacy address while robots.txt advertised the standard one — it now names, and opens, the sitemap actually served.
* Improved: the transient "robots.txt changed" note no longer renders as a readiness row — a notice among checks read as a failing check and changed the report's count. The readiness count stays fixed now; the change itself still reaches you in the weekly email.
* Improved: the automatic Cloudflare purge is polite about everything — a switch on the Cloudflare card turns it off, a permission refusal stands it down silently until a reconnect or a successful manual purge re-arms it, and where the server supports it the save's response is closed before the edge is called, so publishing never waits on Cloudflare.
* Fixed: the page lookup on In Google's Index kept showing its previous answer after the box was cleared or edited — an answer now clears with the question that produced it.
* Fixed: the sitemap-health line sat below the page lookup, where it read as a fact about the page you had just looked up. It now sits with the whole-site section it describes.
* Improved: the lookup hint says what the answer is, not only what it isn't — your own record of what Google said, rather than a new question to Google.
* Improved: the markdown edition's "never store this" cache instruction now speaks nginx's own dialect too, closing the one cache layer that ignores the standard headers.
* Improved: Search Opportunities cards for pages with no post behind them — the homepage on many sites, an archive — can now be set aside like every other card. They are held by address, listed in the same visible set-aside list, restorable in a click, and the card says why it offers no editor buttons.
* Improved: a search card's readability flag no longer waits for the page to be recently edited — every card's page is graded the moment the card renders, so "Also in Optimize" and its Check readability door appear the first time you see the card.
* Improved: the search worklist's fine print now names its terms — which title it means (the SEO title in the "Search & AI" box, or the post title when that field is empty), that set-aside covers both engines, and that a fixed page's card clears when later reports improve, not when you press Save.
* New: IndexNow — publishing, editing or removing a post can announce the changed address to search engines the moment it happens: one standard ping that Bing (the index ChatGPT search and Copilot read), Yandex and other participating engines all listen to. Off by default, one switch on the Bing card; only the changed addresses are sent, and a key file at /<key>.txt proves the pings are yours. Works for every public post type — and content that lives outside posts (a plugin's own catalog tables) can join with one line: do_action( 'agentimus_announce_url', $url ).
* New: the Found by AI Search card answers "does Bing know my sitemap?" — Bing's own record of your registered sitemaps, when it last read them and how many URLs they carried, in Bing's own dates. The IndexNow status gets its own honest line there too.

= 1.33.0 =
* New: Classic search, measured — Search Performance and Search Opportunities screens for Bing Webmaster Tools and Google Search Console: what people searched, how often you appeared, and which pages sit one improvement from page one. Every number is the engine's own, and automated probe traffic is named, never blended in.
* New: In Google's Index — a daily watchlist plus a whole-site rotation through Google's URL Inspection: every verdict in Google's own words, problems grouped with Search Console deep links, any page's answer one lookup away — plus week-on-week trend, Google Discover, and the health of the sitemap you registered.
* New: Found by AI Search — how much of your site Bing's index holds and how cleanly its crawler gets in; ChatGPT search and Copilot read this index today.
* New: Cloudflare edge panel (opt-in, your own token) — what the edge answered or blocked before your server ever saw the request, with a warning when the edge disagrees with your crawler policy.
* New: a setup wizard on first visit, and an agent-ready front door — SKILL.md, auth.md, and an MCP handshake that answers strangers politely.
* Fixed: the promoted XML sitemap now serves at WordPress's standard /wp-sitemap.xml, and the old addresses redirect to it — a moved sitemap had been silently failing search-console registrations.
* Improved: every data screen names whose behavior it counts — people or machines — and every external data pull is chunked or budgeted, resumes after failure, and reports what happened in words.

= 1.32.0 =
* New — **Connect an assistant by approval, not by key.** Give an AI assistant your MCP address and it asks *you* for permission: a consent page on your own site names what it calls itself, where it will return, and lets you choose **Read only** or **Read and write** — you can grant less than it asked for. Approving gives that one assistant its own key; every connection is listed under **Connected assistants** with its own Disconnect, so cutting one off leaves the others working. Standards-based (OAuth 2.1 with PKCE, dynamic client registration), all on your own site — nothing is brokered by anyone else. Verified with Claude, Cursor and ChatGPT (ChatGPT connects behind its Developer-mode switch).
* New — **A shared token for assistants that can't ask.** One revocable secret, created in a click, for clients that don't support the approval flow (Codex among them). Read-only or read-and-write, shown once, rotated or revoked whenever you like — Agentimus stores only a fingerprint, never the secret.
* New — **A read-only key is shown read-only tools.** An assistant you granted read access no longer sees the five write tools at all, so it can't discover the boundary by bumping into refusals. Whatever the key, an agent still can never exceed your write settings — and publishing stays off unless you allow it.
* Fixed — **Discovery for clients that compute the address themselves.** The standard lets a client derive the OAuth discovery address from the MCP endpoint's own URL instead of reading the hint the server sends on a 401 — ChatGPT does exactly that, found a 404 there, and concluded the server "does not implement OAuth". The same discovery document is now served at both addresses.
* Improved — **Agent Access grew a proper table.** The events feed is a real table with column headings on wide screens — same discs, pills and stamps, without repeating "First seen" on every row — and it re-stacks into the familiar cards on smaller screens. The Request Log and the dashboard's Recent Requests gained the same card treatment on phones, so nothing scrolls sideways any more.
* Improved — **The roster says how each key got in.** Every Connected-assistants row carries a small "approval" or "token" chip, the setup guides became boxed sections with real weight, and the card's notes now distinguish Revoke — which ends every connection using the shared token — from rotating it for a fresh secret.
* Improved — **The MCP card, rebuilt around the address.** One status line (running, tool count, last call), your server address up front with a Copy button — that address *is* the setup now — then Connected assistants, then setup steps folded away and shown for the one assistant you pick. Everything the old card did, in a screen you can take in at once.
* New — **Conflict honesty in Readiness.** Agentimus checks its own `/llms.txt` and home page the way an agent would, and says plainly when another plugin is serving them instead of taking credit for it. A new scoreless row names any plugin emitting duplicate description or social-card tags on your home page.
* New — **Robots watch.** If your robots.txt changes — by another plugin, a host setting, or your own hand — Readiness and the weekly email say what changed, once, in plain words, and the notice retires itself after two weeks. Reconstructed locally: no extra requests to your own site.
* Fixed — **The internal-link tool reaches agents at last.** 1.30.0 promised connected agents the same "link to your own posts" suggestions through a read-only MCP tool; the tool was built but never added to the server's list, so it wasn't there. It is now.
* Fixed — **Assistants that use their own callback address can connect.** Desktop clients register a private return address (`claude://…`); these were being silently discarded, so registration failed with a misleading error. Executable and malformed addresses are still refused.
* Fixed — **Screens stop waiting to be reloaded.** Approving a connection happens in another window, so the settings card now refreshes itself when you come back to it, and Agent Access re-reads its list whenever you arrive instead of showing you an older page with a "new" badge above it.
* Improved — **Agent Access names the door.** A call made with the shared token reads "via connection token", and one made by an approved assistant reads "via Claude's connection" — the identity you approved, not a name the caller claimed for itself.

= 1.31.0 =
* New — **Share drafts, written from the post.** The editor panel grows a **Share** tab: ready-to-post drafts for X, Facebook, LinkedIn, WhatsApp, Telegram and Reddit, each written locally from the post's own title, description and topics, with the link preview beside them. With an AI provider connected, a per-card polish rewrites any one draft on request. Nothing is ever posted for you — you copy, or click through to the network's own composer (and where a network refuses prefilled text, the card copies first and says so). Editor-only; switch it off under Settings.
* New — **Ask AI about this post.** A small row after each post lets a reader open ChatGPT, Claude, Perplexity, Google AI Mode or Grok pre-filled with the post's address — one click turns a reader into an assistant visit your own Request Log can see. Plain links, no script, nothing sent anywhere until a reader clicks, and off in one switch under Settings. The row also respects your own bot policy: a button is hidden when your blocklists forbid that assistant from reading the page (notably Google, whose single Google-Extended token governs both AI training and AI-Mode reading), and the Settings toggle names any hidden button and why.
* Improved — **Screens load like screens.** The Request Log, AI Traffic, AI Visibility and Agent Access screens show the same quiet loading placeholder the dashboard uses while their data is on its way, instead of a bare "Loading…" — or, on Agent Access, nothing at all.
* Fixed — **The request-log tool answers MCP clients again.** Its response had outgrown its declared schema (the Status column's two switch states arrived in 1.30.0 without being declared), so the tool rejected its own output. The schema now declares everything the log returns, and a test holds the two ends together.
* New — **Signed agents, verified mathematically.** Some AI agents now cryptographically sign their requests — Google's agent and OpenAI's already do. With **Verify bot identities** on, Agentimus checks the signature right on your own server (Web Bot Auth, the emerging open standard): a genuine agent earns a **signed** mark in the Request Log, and a forged signature — someone pretending to be a signer — is flagged for review and, with blocking on, refused. Unsigned crawlers lose nothing: signing is extra proof, never a requirement. The only outbound request is fetching the operator's public key file (see *External services*).
* New — **Link to your own posts.** A new editor box suggests which of your own posts the one you're writing should link to — found from your topics, categories and text, instantly, no AI needed — and inserts the link at a real phrase in your prose with one click, as an ordinary edit you can undo. With an AI provider connected, one optional call picks nicer anchor phrases and explains each suggestion. Connected agents get the same suggestions through a read-only MCP tool that never spends your AI budget.
* New — **A refusal is never silent.** Requests turned away at your AI endpoints — proven impostors, forged signatures — are now recorded: they show in the Request Log as **refused** and count toward the review queue and the weekly email's impostors, but toward none of your read totals, because a refusal is not a read.
* Improved — **Agent Access reads as a feed.** Connected-agent activity became a card feed with the subject up front, and its header now tells you what the watching has seen: how many events, how many of the available abilities have actually been used, and when the last call landed.
* Improved — **The Request Log says more per row.** A new Status column carries one honest mark per request — verified, signed, spoofed, forged, refused — with the explanation on hover, plus a "Requested at" column; and every timestamp in the admin now follows your site's own date and time format settings.
* Improved — **The Optimize worklist works in bigger strokes.** Each check's section gains a one-click **Ignore All** — every page that check flags, including the ones past the "Showing 6 of 8" preview — the aside list gains a matching **Restore All**, and each set-aside page is now its own card showing what it was flagged for, so a parked page still tells you why it was parked.
* Fixed — **One broken discovery extension no longer silences the rest.** If another plugin extending Agentimus's discovery documents fails, its entry is skipped and named in an admin notice — everyone else's still ships.

Earlier releases — the full history, in the same words — live in [CHANGELOG.md](https://github.com/heera/agentimus/blob/main/CHANGELOG.md) in the plugin's source repository.

== Upgrade Notice ==

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

