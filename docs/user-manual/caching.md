---
title: Caching & CDNs
parent: User Manual
nav_order: 12
---

If your site sits behind a **full-page cache or a CDN** (Cloudflare, a caching plugin's page cache, Nginx FastCGI cache, Varnish, LiteSpeed…), it can quietly get in the way of two Agentimus features. The good news: it's a one-time fix, and Agentimus now *warns* you when it detects it.

## The problem, in one sentence

When a cache serves a **stored copy** of your AI endpoints, those requests never reach WordPress — so Agentimus never sees them.

A cache's whole job is to answer requests *without* bothering WordPress. That's great for your normal HTML pages. But your AI endpoints (`.md` page twins, `llms.txt`, the discovery documents, the change feed) are different: Agentimus needs the request to actually reach the plugin. When the cache answers instead, two things happen:

1. **Your Activity log under-counts.** A crawler or assistant fetches `/your-page.md`, the cache hands back a saved copy, WordPress never runs, and the hit is **never recorded**. Your "Top pages by AI hits" and "Top clients" numbers become a *floor*, not a true total.
2. **Freshness-sensitive endpoints go stale.** The **change feed** (`/agentimus-changes.json`) is meant to always be current so an assistant can fetch just what changed. If it's cached for hours, agents get an old delta. Your page `.md` twins can also lag behind edits.

Agentimus already tells caches *not* to store these responses (it sends `Cache-Control: no-store`). But an aggressive CDN setting — most commonly **Cloudflare's "Cache Everything"** — overrides that and caches them anyway. That override can only be undone at the cache itself.

## How to tell if it's happening

Open **Agentimus → Readiness** and click **Verify live**. This fetches your real endpoints *from your browser, through the public URL* — so it sees exactly what an agent (and any CDN in front of you) sees. If a cache is serving stored copies, you'll get a clear amber warning naming the cache (e.g. *"Cloudflare returned a stored copy of…"*) and the exact paths to fix.

*(Prefer the command line? `curl -I https://your-site.com/llms.txt` and look for `cf-cache-status: HIT` or an `age:` header — both mean a cache answered, not WordPress.)*

## The fix: bypass the cache for your AI endpoints

Tell your cache to **never store** these paths so every fetch reaches WordPress (and stays fresh):

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
