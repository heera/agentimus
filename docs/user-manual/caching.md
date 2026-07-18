---
title: Caching & CDNs
parent: User Manual
nav_order: 17
---

If your site sits behind a **full-page cache or a CDN** (Cloudflare, a caching plugin's page cache, Nginx FastCGI cache, Varnish, LiteSpeed…), it can quietly get in the way of two Agentimus features. The good news: it's a one-time fix, and Agentimus now *warns* you when it detects it.

## The problem, in one sentence

When a cache serves a **stored copy** of your AI endpoints, those requests never reach WordPress — so Agentimus never sees them.

A cache's whole job is to answer requests *without* bothering WordPress. That's great for your normal HTML pages. But your AI endpoints (`.md` page twins, `llms.txt`, the discovery documents, the change feed) are different: Agentimus needs the request to actually reach the plugin. When the cache answers instead, two things happen:

1. **Your Activity log under-counts.** A crawler or assistant fetches `/your-page.md`, the cache hands back a saved copy, WordPress never runs, and the hit is **never recorded**. Your "Top clients" and endpoint numbers become a *floor*, not a true total.
2. **Freshness-sensitive endpoints go stale.** The **change feed** (`/agentimus-changes.json`) is meant to always be current so an assistant can fetch just what changed. If it's cached for hours, agents get an old delta. Your page `.md` twins can also lag behind edits.

> **Freshness is largely handled for you.** Whenever your content changes, Agentimus asks every page cache it can detect to drop these files — including the edited post's own `.md` twin (*Settings → Caching & CDN → "Refresh AI files when content changes,"* on by default). So a cached `llms.txt` or change feed refreshes as soon as you publish. That leaves the **under-count** (problem 1) as the thing the fixes below address — because a cache still answers a *fetch* without WordPress ever seeing it, even when the copy it hands back is fresh.

By default these endpoints are **edge-cacheable** (`Cache-Control: public, max-age`) — bots fetch them a lot, so a little caching spares your origin. When a cache in front of you gets in the way, there are two ways to fix it: a single switch inside Agentimus (the easiest), or a rule at the cache itself (needed only for a cache told to *ignore* origin headers, most commonly **Cloudflare's "Cache Everything"**). Both are below.

## How to tell if it's happening

Open **Agentimus → Readiness** and click **Verify live**. This fetches your real endpoints *from your browser, through the public URL* — so it sees exactly what an agent (and any CDN in front of you) sees. If a cache is serving stored copies, you'll get a clear amber warning naming the cache (e.g. *"Cloudflare returned a stored copy of…"*) and the exact paths to fix.

*(Prefer the command line? `curl -I https://your-site.com/llms.txt` and look for `cf-cache-status: HIT` or an `age:` header — both mean a cache answered, not WordPress.)*

## The easiest fix: the built-in switch

**Settings → Caching & CDN → "Keep AI endpoints out of your cache."** Turn it on and Agentimus makes those endpoints send `Cache-Control: no-store` — asking every cache not to store them, so each fetch reaches WordPress (counted and current). No CDN dashboard, no server access.

It works with any cache that **respects `Cache-Control`** — most do, including a default Nginx FastCGI cache (the plugin's own `.md` twins already rely on this). It does **not** beat a cache told to ignore origin headers or "cache everything" (most often **Cloudflare's "Cache Everything"** rule) — for those, set a rule at the cache itself, below. When the readiness report detects a cache in front of you, its warning links straight to this switch.

## The complete fix: a rule at your cache

If your cache ignores `Cache-Control` (or you'd rather be explicit at the edge), tell it to **never store** these paths so every fetch reaches WordPress (and stays fresh):

| Path | What it is |
|---|---|
| `*.md` | Every page's Markdown twin |
| `/llms.txt`, `/llms-full.txt` | The plain-text indexes |
| `/.well-known/*` | discovery.json, agent-card.json, mcp.json, openapi.json, … |
| `/agentimus-changes.json` | The change feed (freshness matters most here) |
| `/api-catalog` | The REST catalog |

### Cloudflare

Use a **Cache Rule** (Caching → Cache Rules → *Create rule*):

- **When incoming requests match**: *URI Path* `ends with` `.md` — *OR* `starts with` `/.well-known/` — *OR* `equals` `/llms.txt` — *OR* `equals` `/llms-full.txt` — *OR* `equals` `/agentimus-changes.json` — *OR* `equals` `/api-catalog`
- **Then**: *Cache eligibility* → **Bypass cache**

On the legacy **Page Rules** system, add e.g. `your-site.com/*.md` → *Cache Level: Bypass* (repeat for the other paths).

### Nginx FastCGI cache

In your cache config, skip these paths (use whatever your setup's bypass variable is — often `$skip_cache` or `$no_cache`):

```nginx
if ($request_uri ~* "(\.md($|\?)|/llms(-full)?\.txt|/\.well-known/|/agentimus-changes\.json|/api-catalog)") {
    set $skip_cache 1;
}
```

### A WordPress caching plugin (WP Rocket, W3 Total Cache, LiteSpeed, WP Super Cache…)

Add the same paths to the plugin's **"Never cache these URLs" / cache-exclusion** list.

## After you've done it

Run **Verify live** again — the warning should be gone, and `curl -I` on those paths should show `cf-cache-status: DYNAMIC`/`BYPASS` (or no `age:` header). From then on, every real AI fetch reaches WordPress, so your Activity numbers are accurate and your change feed and `.md` twins are always current.

> **Is bypassing the cache a performance problem?** No. These endpoints are fetched by bots at low volume, not by your human visitors — so excluding them from the page cache has a negligible effect on your site's speed, while making your AI tracking accurate and your discovery data fresh.

## Alternative for the Traffic-from-AI count: CDN mode

Bypassing the cache (above) is the clean, complete fix. But if you'd rather leave your cache untouched, there's a narrower **opt-in** option that rescues just the **Traffic from AI** count: **CDN mode**.

When a full-page cache serves your pages, a reader who clicks through from ChatGPT or Perplexity gets a stored copy — WordPress never runs, so that AI-referral visit is never counted (the same physics as the under-counted Activity log above). CDN mode moves the counting into the **visitor's browser**: it adds one tiny script that reports the referral after the page loads, so the count survives the cache.

Find it under **Settings → Caching & CDN → "CDN mode — count AI visits in the browser."** It's **off by default** — only turn it on if a full-page cache/CDN actually fronts your site. Three things to know:

- **Still no IP.** It stores only a per-day *(assistant → page)* tally — no IP address, no User-Agent, no query string, exactly like the server-side count. The server (not the browser) decides which assistant a visit came from, so a page can't fake its way into your numbers.
- **Read the total as a floor.** Visitors using an ad-blocker, or a script-blocking privacy browser, won't be counted — so the number is a minimum, never an over-count.
- **The server-side counter stands down.** With CDN mode on, Agentimus counts referrals *only* in the browser. A cache-served hit and a browser-beacon hit can't be told apart to de-duplicate, so running both would double some visits — one counter at a time keeps the number honest.

CDN mode only helps the Traffic-from-AI count; it does nothing for the Activity log or endpoint freshness. For those, bypass the cache as described above.
