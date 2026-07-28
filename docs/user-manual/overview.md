---
title: Overview
parent: User Manual
nav_order: 1
---

## What Agentimus is

Agentimus is an **AI-discovery layer for your WordPress site**. It publishes clean, machine-readable versions of your content so that AI assistants and agents — ChatGPT, Claude, Perplexity, Gemini and the crawlers behind them — can find your site, read it correctly, and describe or cite it in your own words.

It sits quietly alongside your existing setup. Your visitors see nothing new: no extra scripts, no styling, no layout changes on the pages people actually view. Everything Agentimus adds is written for the machines that read your site on a person's behalf — the AI tools more and more of your audience now ask instead of typing a search into Google.

You do not need to understand AI, web standards, or any of the file formats involved to use it. A setup wizard opens automatically the first time you visit the admin and walks you through your identity and content choices in about a minute. After that, Agentimus runs on its own.

And it doesn't just publish — it **scores** how ready you are. The dashboard rolls everything into one **AEO/GEO score** (0–100) across five plain rungs — Findable, Readable, Trusted, Optimized and Cited — and always names the single next thing to improve, so you're never guessing where you stand. (AEO is Answer Engine Optimization; GEO is Generative Engine Optimization — the practice of being found, read and cited by AI answer engines.)

There's a second half to Agentimus, pointing the other way. The same AI tools you already use — Claude, Claude Code, Cursor, ChatGPT, Codex — can **connect to your site and operate it**, through an opt-in **MCP server** that ships inside the plugin. You connect one by approving it: you hand the assistant your server address, it asks you for permission on a page on your own site, and you choose read-only or read-and-write. Once connected it can read your reports on request; and behind two further opt-in switches it can draft, edit and publish posts and pages, and apply Readiness fixes, for you. Every write runs as the signed-in WordPress user, within that user's own permissions, and is recorded under Agent Access — and all three switches are off by default, so nothing here happens until you turn it on. The [MCP server]({% link user-manual/mcp-server.md %}) page covers it in full.

## The problem it solves

For twenty years, "getting found" online meant getting found by a search engine, which sent a person to your page. That is changing. People increasingly ask an AI assistant a question and read the answer it composes — an answer stitched together from many sites, often without the person ever clicking through.

That shift creates two new problems for a site owner:

- **Being read correctly.** An AI assistant reading a page of WordPress HTML has to wade through menus, sidebars, widgets, cookie banners and theme markup to find the actual content. It can misread who you are, what you do, or what a page is about — and then repeat that mistake to everyone who asks about you.
- **Being represented on your terms.** When an assistant summarises your site, you want it working from an accurate, clean version you control — not a garbled guess. And you may want a say in whether AI companies are allowed to train on your content at all.

Agentimus addresses both. It gives AI tools a tidy, unambiguous version of your site to read, lets you state your identity and expertise in the exact words you'd choose, and gives you controls over who may crawl your content and what they may do with it.

## Who it's for

Agentimus is built for **WordPress site owners**, not developers. It's a good fit if you are any of these:

- **A consultant, freelancer or personal brand** who wants AI assistants to describe you and your expertise accurately when someone asks about your field.
- **A business or agency** that wants AI tools to understand your services and point enquiries the right way.
- **A blogger or publisher** whose posts and pages should flow into AI-readable form automatically, so your writing is available to be quoted correctly.
- **Anyone** who simply wants to see which AI bots are visiting their site, and to decide who is welcome.

If you can write a sentence about who you are and tick a few boxes, you can run Agentimus. Developers get a rich set of hooks and an open discovery standard to build on too, but none of that is required for a site owner to get the full benefit.

## The core idea: machine-readable versions of your site

A web page is built for human eyes. The same information, laid out for a machine, is far easier for an AI tool to read accurately. That is the heart of what Agentimus does: for the content you already publish, it produces **parallel, machine-friendly editions** and points AI tools at them.

Concretely, that means:

- A plain **Markdown** version of any page — just the words, no theme clutter — available by adding `.md` to the page's URL.
- An **index file** (`/llms.txt`) that lists your pages, topics and recent posts in one place, plus a **full-text edition** (`/llms-full.txt`) an agent can read in a single request.
- **Structured data** (JSON-LD) that states, in a format search engines and AI tools already understand, who you are and what each post is about.
- A single, forward-looking **discovery document** at `/.well-known/discovery.json` that describes your site's identity, capabilities and APIs in one predictable place.

None of this is guesswork on your part. You supply a short profile and your content choices; Agentimus generates and keeps these files up to date, and caches them so they load fast and never slow your site.

## What it publishes at a glance

Here is the machine-readable output Agentimus can generate. Some is on by default; some is opt-in. Later pages in this manual cover each one in detail.

| Signal | Where it lives | What it's for |
|---|---|---|
| Markdown delivery | Add `.md` to any page URL | A clean, text-only version of the page for AI tools to read |
| Link index | `/llms.txt` | A one-page directory of your site's pages, topics and recent posts |
| Full-text edition | `/llms-full.txt` | Your content in full, in one file an agent can read in a single request |
| Structured data | JSON-LD in the page `<head>` | `WebSite` + `Person`/`Organization`, plus `BlogPosting` and `BreadcrumbList` on posts |
| Topics for AI | JSON-LD `keywords` + a line in each page's `.md` | Says, in plain words, what each post is about |
| AI description | JSON-LD `description`, the `.md` lead + the page's `<meta name="description">` | One editor-set line that summarises each post, kept the same everywhere |
| Crawler policy | `robots.txt` | Declares your content-usage signals and can block named AI-training crawlers |
| AI-training opt-out | `robots.txt`, a response header, and `/.well-known/tdmrep.json` | Publishes your "don't train on this" choice in three places at once |
| Search basics | page `<head>` | With no SEO plugin: per-page SEO titles, Open Graph/X share cards and canonical links |
| XML sitemap | `/agentimus-sitemap.xml` | Your sitemap when no SEO plugin is installed (with last-changed dates); a gap-only fallback beside one |
| Discovery document | `/.well-known/discovery.json` | One predictable place describing your identity, capabilities and APIs |
| Agent & tool cards | `/.well-known/agent-card.json`, `/.well-known/mcp.json` | Machine descriptions of what an agent can do with your site |
| security.txt | `/.well-known/security.txt` | An optional, standard way for researchers and agents to report an issue |

Alongside what it *publishes*, Agentimus also gives you a private, first-party **log of which AI crawlers actually visit** your site (by default no IP addresses recorded; one optional, off-by-default setting can store a flagged impersonating or spoofed crawler's IP so you can block it), a one-click way to **block** bots you don't want, and a one-screen **readiness report** that scores how AI-ready your site is and names the single next thing worth improving.

## Lightweight by design — no SEO bloat

Most tools in this space cover one narrow slice: just an llms.txt file, or just an AI-bot blocker, or just structured data. Agentimus brings content control, agent-traffic visibility, clean machine-readable output and a forward-looking discovery document together in one coherent package — and stays deliberately small doing it.

What that means in practice:

- **No front-end JavaScript or CSS for your visitors.** A default install ships nothing to the browsers of the people reading your site.
- **No performance cost.** The text files are cached and CDN-friendly. The admin interface loads only on Agentimus's own screen, never across the rest of your dashboard.
- **No framework.** The plugin is plain PHP with a small, hand-rolled loader — the codebase is intentionally lean so it can't become the heavy dependency it's meant to help you avoid.
- **No phoning home.** Out of the box Agentimus makes no outbound requests, collects no analytics or telemetry, and by default logs no IP addresses. Everything runs on your own site. (One optional, off-by-default setting can store a flagged impersonating or spoofed crawler's IP — kept on your own server so you can block it, never sent anywhere. And the one feature that reaches an outside service is the optional AI Visibility monitor, which is off by default and only calls an AI provider you choose after you add your own API key.)

## With an SEO plugin — or instead of one

Agentimus adapts to what your site already runs, automatically:

**No SEO plugin? You don't need one.** Agentimus covers the search essentials itself: per-page SEO titles, Open Graph/X share cards, canonical links, meta descriptions, structured data, and an XML sitemap that carries the last-changed dates WordPress core's own leaves out. See the [Search basics](search-basics.html) page for each one.

**Already running Yoast, Rank Math, SEOPress, All in One SEO (AIOSEO) or The SEO Framework?** You don't have to choose. Agentimus detects the plugin and **stands down on every overlapping surface** — titles, share cards, canonicals, schema, descriptions and the sitemap — so your site never ships a duplicate tag. A card on the dashboard names the division of labour. The rest of what Agentimus produces — llms.txt, the full-text edition, Markdown delivery, the discovery document — doesn't overlap with SEO plugins at all, so those keep working the same either way.

The switch between the two is automatic and instant, in both directions: activate an SEO plugin and Agentimus steps aside on the next page load; remove it and Agentimus takes the job back. Your per-page values are kept through the change. And whichever mode you're in, the **Agent preview** in the admin still shows you exactly what an AI agent receives.

## What Agentimus is NOT

Being clear about the limits matters as much as the features. Agentimus **cannot manufacture authority**.

Agentimus makes your site *discoverable and correctly understood* — when an AI assistant looks at your site, it can find your content, read a clean version, and describe you accurately. That is what the plugin controls, and it does it well.

But whether an AI *spontaneously mentions you* when someone asks a broad question ("best resources for X") is a matter of **authority and reputation** — earned over time through genuinely useful content that other people reference. No plugin, llms.txt file, or piece of schema can conjure that. Any tool promising "instant AI visibility" is overselling.

Think of it this way: Agentimus makes sure that when your authority *does* bring an AI agent to your door, nothing is lost in translation. It readies the site; it doesn't fake the reputation.

A few other things Agentimus is deliberately not:

- **It is not a security suite.** The optional Exposure controls tidy up what stock WordPress reveals to anonymous crawlers, but Agentimus stays a discovery layer, not a firewall. It describes what your site already makes public; it grants no new access and exposes nothing private.
- **It doesn't let agents change your site until you decide otherwise.** The discovery documents Agentimus publishes only *advertise* your tools and capabilities — advertising executes nothing, and suppressing or removing an item changes what is *advertised*, not what is reachable. Writing is a separate, opt-in tier: the built-in MCP server ships off, and only after you turn it on — then flip two further switches — can a connected agent draft, edit or publish for you, always as the signed-in WordPress user, within that user's own permissions, and recorded under Agent Access. See [MCP server]({% link user-manual/mcp-server.md %}).
- **It is not a magic switch for AI rankings.** The content signals it publishes (JSON-LD, robots, llms.txt, Markdown) are read by AI tools today; the discovery document is forward-looking and prepares you for agents as they adopt these conventions. The plugin works fully whether or not any given agent consumes that document yet.

## Where to go next

Now that you know what Agentimus is for, the rest of this manual walks through using it:

- **Getting started** — installing, activating, and the one-minute setup wizard.
- **Identity** — the profile, expertise and linked-profile fields that are the highest-signal information an AI assistant uses to describe you.
- **The readiness report** — reading your score and the plain-English checklist of what's still worth improving.
- **Crawler policy and AI access** — declaring your content-usage signals and deciding which bots are welcome.
- **The activity log** — seeing which AI crawlers and agents actually fetch your content.
- **The MCP server** — approving Claude, Claude Code, Cursor, ChatGPT or Codex to read your reports and, if you opt in, draft, edit and publish for you.

If you take just one action after reading this page, run the setup wizard and add a sentence about who you are — that single line is the most valuable thing an AI assistant reads about your site.
