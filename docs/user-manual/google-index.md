---
title: Classic search & index coverage
parent: User Manual
nav_order: 26
---

Everything else in Agentimus is about what your site *publishes*. This part is about what the search engines *report back* — and it exists because the same indexes now feed two audiences at once. Google's index answers classic Google Search **and** AI Overviews, AI Mode and Gemini. Bing's index answers Bing **and** ChatGPT search and Microsoft Copilot. A page missing from one of those indexes is missing from both audiences, and no amount of on-site readiness can tell you that — only the engine can.

Everything here is **optional and read-only**. You connect a key that lives on your own server, nothing is brokered by a third party, and every number lands in your own database — so your history keeps growing where the engines' own reporting windows end.

---

## Connecting a source

**Settings → Data sources** holds all three connectors, each with a short walkthrough:

- **Google Search Console** — a service-account key file you create in Google Cloud (five minutes, once; nothing costs money, nothing touches your site). Agentimus reads the key in your browser to show you the address to grant in Search Console, then stores it encrypted.
- **Bing Webmaster Tools** — one API key. If your site isn't verified in Bing yet, Agentimus prints the verification tag for you.
- **Cloudflare** — one scoped, analytics-read token, for the [edge traffic](../user-manual/dashboard.html) view: what Cloudflare answered or blocked before your server ever saw the request.

Disconnecting forgets the key. The numbers already stored stay — they're your history, not the service's.

---

## Search Performance — how did search go?

The plain answer, for the trailing window each engine reports as final: **times shown**, **visits**, **click rate**, **average rank**, then your top searches and the pages that earned them.

This screen counts **people** using classic search. The machines reading your site live on the AI Traffic and Request Log screens — no number here is ever a blend of the two.

Three honesty rules run through it:

- **Nothing is estimated.** Every figure is what the engine itself reported.
- **Engines are never merged.** Connect both and a switch appears; with one connected, the screen names which. Google and Bing count different searchers, so a combined figure would mean nothing.
- **Scraper probes are named, not hidden.** Searches using operators (`site:`, `intext:`, …) are real results the engine reported, but nobody was ever going to click them. They stay in the record, marked — and when they're a large share of your views, the screen says so beside the click rate they drag down.

On Google, once fourteen days of history exist, a line compares the last seven reported days with the seven before. Before that it stays silent, because a comparison without history is a guess. A **Google Discover** line appears only if Discover actually showed your pages.

## Search Opportunities

The same numbers, turned into a to-do list ([Readiness](readiness.html) → Opportunities): pages **ranking 8–20** — one improvement from page one — and pages **already on page one being scrolled past**, judged against *your own* site's median click rate, never an industry benchmark. Each card names the fix and links to the field that makes it.

When there isn't enough traffic to judge honestly, the screen says that instead of inventing advice.

---

## In Google's Index

Google publishes no bulk index report and allows 2,000 URL inspections a day, so no tool can snapshot a large site in one go, whatever it claims. Agentimus does the honest version in two tiers:

- **A daily watchlist** — your homepage, up to ten busiest pages, up to ten newest posts. Every answer is shown, every day. This is where "is it indexed yet?" actually lives.
- **A whole-site rotation** — every published URL, walked in daily slices. Small sites are covered completely every day; larger ones get a stated cadence ("every page gets re-checked about every N days").

Healthy rotation pages become a **count**; only problems become **rows**. Five hundred green rows would be noise — one number isn't.

### Reading the problems

Problems are grouped by state, with Google's own phrase quoted under each heading, because the wording matters when you go looking in Search Console:

| Group | Google's wording | What it means |
|---|---|---|
| Never seen by Google | *URL is unknown to Google* | Not discovered at all — usually nothing links there and no sitemap lists it |
| Discovered, not yet crawled | *Discovered – currently not indexed* | Known, waiting for a visit |
| Crawled, but left out | *Crawled – currently not indexed* | Visited, then not added |
| Google chose a different address | — | Known, but filed under another URL; the row names which |
| Blocked by this site | — | robots.txt or a noindex tag asks Google to stay out |

Counts always tell the whole truth even when the list of rows is bounded: a group's count is every page in that state site-wide, not just the rows on screen. Where a group is long, it unfolds in place; where the whole list is very long, the card says so and links to Search Console's own Pages report, which has no cap.

### Looking up one page

A lookup box at the foot of the card answers for any single URL from the stored daily checks — no live call, no quota spent. A page that hasn't been checked yet says exactly that, and names when the rotation will reach it.

### Why there is no "Request indexing" button

Google's Indexing API accepts only job-posting pages, and the console's own *Request indexing* button has no API at all. A button here would either do nothing or break Google's terms. Instead, **every checked row deep-links to that URL's inspection in Search Console** — one click from the real button, where it actually works.

### The sitemap Google actually knows

The card reports when Google last managed to read the sitemap you registered — and says so plainly when the registration points at an address your site no longer serves. This is the failure no on-site check can see: your sitemap file can be perfect while the registration quietly fails for months. (See [search basics](search-basics.html) for why Agentimus serves the sitemap at WordPress's standard address.)

---

## Found by AI Search — the Bing side

How much of your site Bing's index holds, and how cleanly Bingbot gets in: pages in the index, pages crawled, crawl errors and blocked requests, day by day. ChatGPT search and Copilot read this index today.

Crawl errors say **what kind** they are. A pile of 404s is a dead-links problem — the card says to fix the links or add redirects, and never sends you to check a server that's working fine.

No people are counted on this card; it measures the machinery that serves them. Searchers' clicks live in Search Performance.

---

## Reading it from an assistant

If you run the [MCP server](mcp-server.html), a connected assistant can read all of this: `read-search-performance`, `read-search-opportunities`, `read-google-index`, `read-search-visibility` and `read-edge-traffic`. They return exactly what the screens show — the same builder serves both — so an assistant can answer "which pages aren't in Google's index?" from your real numbers, not a guess.
