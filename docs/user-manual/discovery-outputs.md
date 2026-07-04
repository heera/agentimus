---
title: Machine-readable outputs
parent: User Manual
nav_order: 5
---

Everything Agentimus publishes for AI tools lives in a handful of plain files served straight off your own domain. This page lists every one of them, shows you how to open and read each in a browser, and explains when each is served and how to turn it on or off.

None of these files change what your human visitors see. They are parallel, machine-friendly editions of content you already publish — a clean copy for the assistants and agents that read your site on a person's behalf.

## The files at a glance

| File | Address on your site | On by default? | Where to switch it |
|---|---|---|---|
| AI page guide | `/llms.txt` | Yes | Settings → **Discovery** → *AI page guide* |
| Full-text edition | `/llms-full.txt` | Yes | Settings → **Discovery** → *Full text for AI* |
| Per-page Markdown | add `.md` to any page URL | Yes | Settings → **Discovery** → *Plain-text versions* |
| Change feed | `/agentimus-changes.json` | Yes | Settings → **Discovery** → *Change feed* |
| Discovery document | `/.well-known/discovery.json` | Always on | (no toggle — served while the plugin is active) |
| Agent card | `/.well-known/agent-card.json` | Always on | (no toggle) |
| MCP / tools manifest | `/.well-known/mcp.json` | Always on | (no toggle) |
| XML sitemap (fallback) | `/agentimus-sitemap.xml` | Only if nothing else provides one | Settings → **Discovery** → *Sitemap (backup)* |

The three `/.well-known/` documents have no on/off switch: they describe your site's identity and are safe and cheap to serve, so Agentimus always publishes them while it is active. The text files (`llms.txt`, `llms-full.txt`, Markdown) each have a toggle on the **Discovery** tab of the settings screen. The fallback sitemap is special — see its section below.

Only the content types you have chosen appear in these files. By default that's your posts and pages; other content types (products, custom post types) are opt-in on the **Content** settings. Draft, private and password-protected content is never included — Agentimus only ever exposes what your normal site already shows to the public.

## How to view any of these files

**In a browser.** Most of these are just web addresses. Type your site's address followed by the path — for example `https://example.com/llms.txt` — and the file opens as plain text or JSON you can read directly. The settings screen also has a **Live endpoints** box with ready-made links to your `llms.txt`, `llms-full.txt` and `robots.txt`, and the **Discovery** hub lists the `/.well-known/` documents.

**The one exception — the "Accept header" versions.** Some of what Agentimus serves is triggered by a request *header* rather than a distinct URL. A browser's address bar can't send that header, so to see those you use a command-line tool such as `curl`:

```bash
# Ask any page for its Markdown edition using the Accept header
curl -H "Accept: text/markdown" https://example.com/about/
```

If you're not comfortable with the command line, you don't need it — the same Markdown is always available at the plain `.md` address described below, which any browser can open.

## The AI page guide — `/llms.txt`

`/llms.txt` is a one-page map of your site written for AI assistants. It's the first thing many of them look for. Open `https://yoursite.com/llms.txt` and you'll see a tidy, human-readable outline:

- Your site's **name and tagline** at the top.
- A one-line note telling agents they can fetch any page as Markdown (by adding `.md` or sending the Accept header).
- A pointer to your **discovery document** (`/.well-known/discovery.json`), so an agent arriving here can find your structured machine profile.
- An **About** block drawn from your Identity settings — who you are, who the site is for, what it is *not*, and your areas of expertise.
- A **section for each content type** you publish (Pages, Posts, and any others you've enabled), listing each item as a link with a short excerpt.
- A **Topics** section built from your categories.
- An **Optional** section linking the full-text edition (if it's on), your RSS feed, and your sitemap (if one is detected).

**When it's served:** on every request to `/llms.txt` while the *AI page guide* toggle is on. It's built once and cached for about an hour, and rebuilt automatically whenever you change your content, so it's always current without slowing anything down.

**Turn it off:** Settings → **Discovery** → untick *AI page guide*.

## The full-text edition — `/llms-full.txt`

Where `/llms.txt` is a list of *links*, `/llms-full.txt` is the actual *text*: your profile followed by the complete content of each page and recent post, all concatenated into one document an assistant can read in a single pass. Open `https://yoursite.com/llms-full.txt` to read it.

Because this file can get large, and because a crawler can ask for it at any moment, Agentimus builds it under a strict **size budget** so it never strains your server or becomes too big to ingest:

- A total byte budget — **about 1 MB by default**. Generation stops cleanly at that ceiling.
- A per-item cap, so one enormous page can't dominate the file (it's skipped and stays reachable at its own `.md` address).
- A short time limit on how long the build may run.

When any of these limits is reached, Agentimus stops at a clean page boundary, appends a short note explaining the file was truncated, and points the reader back to the `/llms.txt` index and the per-page `.md` addresses for anything left out. The document is always valid — it's never cut off mid-sentence.

You control how much goes in with **Posts in /llms-full.txt** on the **Discovery** tab (default 50; this caps posts and other content types — your pages are always included up to 50). As you change that number the screen shows a live estimate of the resulting file size and warns you if it would exceed the budget. If the estimate is over budget, lower the post count or simply rely on the `/llms.txt` index instead.

**When it's served:** on every request to `/llms-full.txt` while *Full text for AI* is on. It's cached, and after you edit content Agentimus quietly regenerates it in the background so visitors rarely wait for a fresh build.

**Turn it off:** Settings → **Discovery** → untick *Full text for AI*.

## Per-page Markdown — the `.md` address and the Accept twin

For any single page or post you've included, Agentimus can hand an assistant a clean, text-only Markdown version — just the words, with none of your theme's menus, sidebars or widgets. There are two ways to reach the exact same content:

1. **Add `.md` to the page's address.** A page at `https://example.com/about/` is also available at `https://example.com/about.md`. This works in any browser.
2. **Send the `Accept: text/markdown` header** to the normal page URL. An agent that prefers Markdown gets the Markdown edition back from the same address a human would get the HTML page from (see the `curl` example above).

The home page and archives have a Markdown twin too: `https://example.com/index.md` (or the Accept header on your front page) returns the same site map as `/llms.txt`.

Each page's Markdown starts with a small header — the title, and for posts the date, author and categories — followed by the page's canonical link, a **Topics** line (if you've set topics for that page), and then the body converted faithfully from HTML to Markdown, preserving headings, lists, links, quotes and code.

Agentimus also advertises the current page's `.md` twin in the page's response, so a well-behaved agent can discover it without guessing.

**When it's served:** whenever the *Plain-text versions* toggle is on and the request targets an included page — either via its `.md` address or the Accept header. Negotiated (Accept-header) responses are never cached, since they share a URL with the normal HTML page.

**Turn it off:** Settings → **Discovery** → untick *Plain-text versions*.

## The change feed — `/agentimus-changes.json`

Assistants shouldn't have to re-read your whole site to notice one edit. The change feed is a compact JSON list of your **recently added, updated and removed** pages, so an agent can ask "what's new since last time?" and fetch only that.

Open `https://yoursite.com/agentimus-changes.json` to see it. Each item carries the page's title and URL, when it changed, whether it was `created`, `updated` or `deleted`, a link to its Markdown twin, and a link to its canonical REST resource. An agent that already has a copy of your site can add `?since=` with a date or timestamp — for example `/agentimus-changes.json?since=2026-07-01` — to receive only what changed after that moment.

Removals are included too: unpublish, trash or delete a page and it appears once as a `deleted` item (for about 90 days) so an assistant knows to drop it from its own copy — something a plain sitemap can't tell it.

It is **on by default** and advertised in your discovery document, so agents find it automatically. Turn it off under Settings → **Discovery** → *Change feed*.

## The discovery documents — `/.well-known/`

The `/.well-known/` folder is a web-standard location for machine-readable metadata. Agentimus serves its discovery documents from there, and they're **always published while the plugin is active** — there's no toggle to turn them off. You can open each in a browser; they're JSON.

### `discovery.json`

`https://yoursite.com/.well-known/discovery.json` is the single, predictable entry point that ties everything together. It's a structured summary of your site that names:

- your **site identity** (name, URL, description, language, logo);
- the **person or organisation** behind it, from your Identity settings;
- links to your other machine documents (sitemap, robots, feed, `llms.txt` and `llms-full.txt` when enabled, and more);
- an index of every `/.well-known/` document your site serves;
- any **APIs, agents, capabilities and resources** that Agentimus or another plugin has registered;
- a small **trust** block (whether the documents are signed, links to your security and privacy policies).

Nothing here is typed by hand — every field is generated from your settings and from what's actually running on your site, then cached for about an hour. Agentimus also advertises this document in every front-end response so agents can find it from a single link.

### `agent-card.json` (and `agent.json`)

`https://yoursite.com/.well-known/agent-card.json` is an **A2A ("agent-to-agent") card** — a standard description of your site and any agents it exposes, with your name and organisation as the provider. The same document is also served at `/.well-known/agent.json` for agents that look there first. On a typical content site the card describes your identity; it grows richer automatically if a plugin registers agent capabilities.

### `mcp.json`

`https://yoursite.com/.well-known/mcp.json` is an **MCP (Model Context Protocol) manifest** — the experimental, forward-looking description of any *tools* an AI agent could use on your site. Agentimus does not run an MCP server itself; it *discovers and advertises* servers and tools that compatible plugins (for example a WooCommerce or Fluent Cart MCP adapter) have registered. On a site with no such tools, the document is still served but simply reports that none are available yet. Because MCP discovery is still an evolving standard, this document is marked `experimental` and is kept deliberately separate from the stable `discovery.json`.

### The other `/.well-known/` documents

For completeness, Agentimus also serves `openapi.json` (a machine description of WordPress's public read-only REST API) and `api-catalog` (a standard index pointing agents at your API descriptions). Like the three above, these are served automatically while the plugin is active. A few more documents appear only when you opt into the feature that produces them — for example `security.txt` and `tdmrep.json` (covered on the crawler-policy page).

## The XML sitemap — an opt-in fallback

A sitemap lists your URLs for crawlers. Agentimus deliberately **does not compete** for this job. It checks, in order:

1. Does **WordPress core** provide a sitemap? (On by default since WordPress 5.5, at `/wp-sitemap.xml`.)
2. Does a major **SEO plugin** provide one? (Yoast, Rank Math, All in One SEO, SEOPress or The SEO Framework.)
3. Only if **neither** does, and you've left the *Sitemap (backup)* toggle on, Agentimus generates its own at `/agentimus-sitemap.xml`.

This means Agentimus never produces a duplicate or conflicting sitemap. On most sites something else already owns the sitemap, so Agentimus simply *links* to that existing file from `llms.txt`, `robots.txt` and the discovery document rather than making its own. Its fallback only fills a genuine gap.

When Agentimus's own fallback is active, it's a proper sitemap *index* at `/agentimus-sitemap.xml` with paginated sub-sitemaps — the same shape core and the SEO plugins use, so even large sites are covered. Password-protected posts are excluded, matching how core's sitemap behaves.

**When it's served:** only when nothing else provides a sitemap *and* the toggle is on. If you activate an SEO plugin later, Agentimus automatically steps aside and its fallback address stops responding.

**Turn it off:** Settings → **Discovery** → untick *Sitemap (backup)*.

## A note on privacy and what's never exposed

Across every file on this page, Agentimus applies the same rule: it only ever exposes what your public HTML site already shows. Draft, pending, private and password-protected content is always excluded, and only the content types you've selected on the **Content** settings appear. Turning a content type off removes it from `llms.txt`, the full-text edition, the Markdown addresses, the sitemap and the discovery document all at once.

## Letting another plugin take over

If your theme or another plugin already produces one of these surfaces — its own `llms.txt`, its own Markdown delivery, and so on — Agentimus is built to step aside cleanly rather than fight it, and a developer can hand any individual surface over with a single line of code. For most site owners this is automatic and invisible; you'll only notice that Agentimus never duplicates a file something else is already serving.
