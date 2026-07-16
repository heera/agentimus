---
title: Home
layout: default
nav_order: 1
description: "Official documentation for Agentimus — the WordPress plugin that makes your site legible to AI assistants and agents."
permalink: /
---

<div class="ag-hero">
  <p class="ag-hero__eyebrow">WordPress plugin · Documentation</p>
  <h1 class="ag-hero__title">Agentimus documentation</h1>
  <p class="ag-hero__lead">Make your WordPress site legible to AI assistants — and let the AI tools you already use operate it over MCP. No theme edits, no SEO bloat.</p>
  <div class="ag-hero__cta">
    <a class="ag-btn ag-btn--primary" href="{{ '/user-manual.html' | relative_url }}">Read the User Manual</a>
    <a class="ag-btn ag-btn--ghost" href="{{ '/developer.html' | relative_url }}">Developer Reference</a>
    <a class="ag-btn ag-btn--ghost" href="https://github.com/heera/agentimus">View on GitHub&nbsp;↗</a>
  </div>
</div>

## What is Agentimus?

Agentimus is a free WordPress plugin that does two things — both on the AI/agent layer, neither touching what your visitors see.

**It makes your site legible and citable to AI.** It publishes **machine-readable versions of your content** — the formats AI assistants and autonomous agents actually read — so they understand, quote, and cite you correctly. And it scores how ready you are: one **AEO/GEO score** across five rungs — Findable, Readable, Trusted, Optimized and Cited — with the single next thing to improve always in view.

**It lets the AI tools you already use operate your site.** An opt-in **[MCP server]({{ '/user-manual/mcp-server.html' | relative_url }})** ships inside the plugin, so Claude Code, Claude Desktop, Cursor or Codex can connect and ask your site questions — readiness, AI traffic, bot activity, per-page readability. Behind two more opt-in switches, the same connection can **draft, edit and publish** posts and pages — categories, tags, featured image, AI topics and descriptions — and apply Readiness fixes. Every write runs as the signed-in WordPress user, checked against that user's own permissions and recorded under Agent access. All three switches are **off by default**.

It runs quietly alongside your theme and your SEO plugin; everything happens on the AI/agent layer, and nothing changes on the pages your visitors see.

## Two ways in

<div class="code-example" markdown="1">

**📖 [User Manual]({{ '/user-manual.html' | relative_url }})** — for site owners.
Install it, understand each feature, and get your site AI-ready: llms.txt, per-page Markdown, structured data, Topics for AI, crawler controls, and the Readiness report.

**🛠️ [Developer Reference]({{ '/developer.html' | relative_url }})** — for plugin authors and integrators.
The architecture, the WP_Discovery Protocol, the full hooks & filters reference, the REST and public endpoints, and how to make your own plugin discoverable.

</div>

## What Agentimus publishes

| Output | What it is |
|:-------|:-----------|
| `/llms.txt` + `/llms-full.txt` | A plain-text guide to your best pages, for AI models |
| Per-page Markdown | A clean `.md` twin of each page, served on request |
| JSON-LD structured data | Schema.org identity, articles, breadcrumbs, FAQ |
| `/.well-known/` discovery docs | `discovery.json`, agent card, MCP descriptor |
| Topics for AI | Per-page topics → `keywords` + `about` entities |
| AI description | Per-page summary → JSON-LD `description`, the `.md` lead + `<meta name="description">` |
| robots.txt + Content-Signal | Clear, honest crawl and AI-use signals |

{: .note }
> Agentimus never invents authority for you. It makes what you already have **easy for machines to find and read** — the rest is your content.

## What connected agents can do

Turn on the built-in MCP server and the AI tools you already use — **Claude Code, Claude Desktop, Cursor, Codex** — can connect to your site and work with it directly. It's the second half of the plugin, and every part of it is opt-in and off by default.

| Ability | What a connected agent can do |
|:--------|:------------------------------|
| Read your reports | Readiness, AI traffic, the request log, bot identification, per-page readability — live, on request |
| Draft & edit content | Create or update posts and pages, with categories, tags, featured image, AI topics and description |
| Publish | Only after a further switch — drafts (or pending) until then |
| Apply Readiness fixes | Enact a check's own safe, documented remediation |

Every write runs as the **signed-in WordPress user**, within that user's own permissions, and is recorded under **Agent access**. Full setup — the three-switch trust ladder and how to connect each tool — is on the **[MCP server]({{ '/user-manual/mcp-server.html' | relative_url }})** page. (Prefer WordPress's own AI to draft a page's description and topics from inside the editor? That's **[Write with AI]({{ '/user-manual/write-with-ai.html' | relative_url }})**.)

## Good to know

- **Free & open source** — GPL-2.0-or-later.
- **Lightweight** — no framework, no tracking, and by default **no outbound requests**.
- **Defers to your SEO plugin** — if another plugin already emits schema or a sitemap, Agentimus stands down.

Ready? Start with the **[User Manual]({{ '/user-manual.html' | relative_url }})**.

---

Agentimus is built and maintained by **[Sheikh Heera](https://heera.it)**.
