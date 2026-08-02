---
title: Search basics
parent: User Manual
nav_order: 9
---

**Search basics** is the part of Agentimus that covers what most sites install an SEO plugin for: the page title search results show, the preview card a shared link gets, the canonical address of each page, and the sitemap. With these, a site that runs no SEO plugin isn't missing anything essential — and a site that runs one loses nothing, because Agentimus steps aside for it automatically.

Everything on this page follows one rule, checked on every page load:

- **No SEO plugin installed** → Agentimus does the job.
- **An SEO plugin active** (Yoast, Rank Math, SEOPress, All in One SEO or The SEO Framework) → Agentimus stands down on every surface the plugin owns, instantly and automatically. Nothing is ever emitted twice.

The switch works in both directions with no setting to change: activate an SEO plugin and Agentimus steps aside on the next page load; deactivate it and Agentimus takes the job back. Your per-page values survive the round trip.

## The SEO title

Every post and page gets an **SEO title** field in the editor (the top of the *Search & AI* box in the sidebar). It exists because the title of a post and the title of a search result do different jobs: your post title is written for readers already on your site, while the search title is read cold, out of context, in a ~60-character window. A clever headline on the page often deserves a plainer, more descriptive twin in search.

When you fill it in, it replaces that page's title in exactly three places — all *outside* your site:

1. **The browser tab** — the page's `<title>`, with your site name still appended.
2. **Search results** — engines show the headline they find in that same `<title>`.
3. **Link previews** — the shared-link card on X, Facebook, Slack, WhatsApp and the rest.

Leave it blank and the post's own title is used everywhere, exactly as before. The main title always stays what it was on the page itself — the H1, your menus, archives and feeds never change.

## Social share cards

When someone shares a link to your site, the receiving app builds a preview from **Open Graph** tags in the page's head. Agentimus emits them for your front page and every post and page: the title (your SEO title when set), the description (your [AI description](structured-data.html), so one field feeds search, AI and social consistently), and an image.

The image is chosen by a simple chain — first match wins:

1. The page's own **featured image**.
2. Your site-wide **default share image** — pick one under Settings → **Discovery** → *Search basics* (used when a page has no featured image).
3. Your **Site Icon**.

One honest detail: many themes and plugins (Jetpack among them) already print their own Open Graph tags. Agentimus checks the page head and **prints cards only when nobody else has** — so whatever your site already does for social previews keeps working, and Agentimus fills the gap only where there is one.

## Canonical links

A canonical link tells search engines which address is the official one for a page, so `?utm_source=...` variants and other URL noise don't get treated as duplicate pages. WordPress core already handles this for single posts and pages; Agentimus fills in the views core leaves bare — your front page and your archive pages (categories, tags, authors and custom types). Like the cards, it's gap-aware: if your theme already prints a canonical on those views, Agentimus stays silent.

## The sitemap

With no SEO plugin, Agentimus serves your sitemap at WordPress's own standard address, **`/wp-sitemap.xml`** — including per-URL **last-changed dates**, which core's sitemap leaves out — and advertises it in `robots.txt`, `llms.txt` and the discovery document. With an SEO plugin, it links the plugin's sitemap instead. The full story is on the [discovery outputs](discovery-outputs.html) page.

Two older addresses, `/sitemap.xml` and `/agentimus-sitemap.xml`, redirect permanently to it. That matters more than it sounds: search consoles remember the address you registered, and a sitemap that quietly moves keeps failing there — silently, sometimes for months — while the file itself is perfectly healthy. Serving at the address the world already registers means a registration made years ago keeps working. If you connect Google Search Console, the [In Google's Index](google-index.html) card also reports when Google last managed to read the sitemap you registered.

## When an SEO plugin is active

With one of the five suites running, this whole page effectively hands over:

- The **SEO title field disappears** from the editor (the suite has its own — two competing fields on one screen would help nobody). Values you set earlier are kept, not deleted.
- **Cards, canonicals, meta descriptions, structured data and the sitemap** all stand down.
- A quiet card on the **Dashboard** names the division of labour, and the **Readiness** report's *Search SEO coverage* row credits the plugin by name — so you can always see who owns what.

## Settings

Everything lives in one card: Settings → **Discovery** → *Search basics*.

| Switch | What it controls |
|---|---|
| Per-page SEO title | The editor field and the title replacement |
| Social share cards | The Open Graph/X tags, plus the default share image picker |
| Canonical links | The front-page and archive canonicals |

All three ship on — safe defaults, because each one changes nothing until a page sets a value or a gap actually exists — and all three are inert while an SEO plugin runs.
