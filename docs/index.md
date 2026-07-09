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
  <p class="ag-hero__lead">Make your WordPress site legible to AI assistants and agents — without touching a line of your theme, and without the SEO bloat.</p>
  <div class="ag-hero__cta">
    <a class="ag-btn ag-btn--primary" href="{{ '/user-manual.html' | relative_url }}">Read the User Manual</a>
    <a class="ag-btn ag-btn--ghost" href="{{ '/developer.html' | relative_url }}">Developer Reference</a>
    <a class="ag-btn ag-btn--ghost" href="https://github.com/heera/agentimus">View on GitHub&nbsp;↗</a>
  </div>
</div>

## What is Agentimus?

Agentimus is a free WordPress plugin that publishes **machine-readable versions of your site** — the formats AI assistants and autonomous agents actually read — so they understand, quote, and cite your content correctly.

It runs quietly alongside your theme and your SEO plugin. Nothing changes on your visible pages; everything happens on the AI/agent layer.

And it scores how ready you are: one **AEO/GEO score** across five rungs — Findable, Readable, Trusted, Optimized and Cited — with the single next thing to improve always in view.

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

## Good to know

- **Free & open source** — GPL-2.0-or-later.
- **Lightweight** — no framework, no tracking, and by default **no outbound requests**.
- **Defers to your SEO plugin** — if another plugin already emits schema or a sitemap, Agentimus stands down.

Ready? Start with the **[User Manual]({{ '/user-manual.html' | relative_url }})**.

---

Agentimus is built and maintained by **[Sheikh Heera](https://heera.it)**.
