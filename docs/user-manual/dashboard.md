---
title: Dashboard & activity
parent: User Manual
nav_order: 3
---

## What the Dashboard shows

The **Dashboard** is the first tab you land on when you open Agentimus. It answers one plain question with two sides:

- **Is AI sending readers *to* you?** The **Traffic from AI** card counts real people who clicked through to your site from an AI assistant like ChatGPT or Perplexity.
- **Is AI reading *from* you?** The **agent activity log** records each time a bot or crawler fetches one of the machine-readable files Agentimus publishes (things like `/llms.txt` or a page's `.md` version).

Alongside these, the sidebar carries your **AEO/GEO score** — a single 0–100 read on how ready your site is for AI, with the next thing to improve always in view.

Everything on this tab is **first-party and local-only**. All of it lives in your own WordPress database, nothing is ever sent to Agentimus or any third party, and — importantly — **by default no IP addresses and no personal data are stored**. (One optional setting, off by default, can store an IP for a flagged impersonating or spoofed crawler so you can block it — never for an ordinary visitor, and never off your own server; see the privacy note below.) The two data stores are pruned and size-capped automatically, so they never grow without bound.

The whole Dashboard is powered by a single setting, **Agent activity log**, which is **on by default**. It covers both directions at once — the traffic-in card and the bots-out log are one feature. If you ever turn it off in Settings, both stop recording.

---

## Traffic from AI

The **Traffic from AI** card is the "giving back" side of the story: it shows whether AI tools are actually sending human readers to your pages, and where those readers land.

It reports three things over a rolling window (30 days by default):

- **Totals** — visits today, and visits across the whole window.
- **Top sources** — which assistants sent the most readers (up to eight).
- **Top landing pages** — which of your pages AI readers arrived on most (up to eight).

Below those is the **by-day breakdown**, which is where the detail lives.

### The by-day breakdown

For each day in the window, the card shows **which assistant sent a reader to which page**. Each day carries:

- the **date**,
- the **total** AI-referred visits that day, and
- a list of **source → page** rows — for example, *ChatGPT → /pricing* with a count.

To keep a busy day from ballooning the card, each day keeps its **12 busiest** source-and-page pairings and rolls the rest into a **"+N more"** note. Days are listed newest-first, and within a day the busiest pairing comes first.

There is no clock time anywhere in this breakdown — **the day is the finest level of "when" the plugin stores**. That is deliberate: a per-visit timestamp starts to look like a trail back to an individual, and this card is built so that no stored row can ever represent a person.

### How AI referrals are detected

Detection is simple, private, and makes **no outbound calls**. It reads two signals that are already attached to the incoming page request:

1. **The referrer host** — the address of the page the reader clicked *from*. When someone follows a link inside a Perplexity, Gemini, Claude, Copilot, You.com or Poe answer, their browser reports that assistant's domain as the referrer. Subdomains count too (for example, a link from `something.chatgpt.com` still resolves to ChatGPT).
2. **The `utm_source` tag** — a marker some assistants stamp onto the links they hand out. ChatGPT, for instance, adds `utm_source=chatgpt.com`. This is a belt-and-braces signal: it catches a referral even when the browser strips the referrer away.

Both signals are lowercased, have any leading `www.` removed, and are matched against a **fixed list of known AI sources**. If neither signal points at a known assistant, nothing is recorded — the vast majority of visits (direct traffic, internal clicks, ordinary search) exit instantly without ever touching the database.

One more gate: the visitor must be using a **real web browser**. A reader clicking out of an AI answer is a person on a browser; a bot that happens to arrive carrying a referrer is not "a visitor AI sent", so it is not counted here.

If your site sits behind a full-page cache or CDN, these referrals can be served from cache and never reach WordPress to be counted — see **Caching & CDNs** for the fix, including an opt-in **CDN mode** that counts them in the visitor's browser instead.

### Which assistants are recognised

| Assistant | Detected from |
| --- | --- |
| **ChatGPT** | `chatgpt.com`, `chat.openai.com` (and the `utm_source=chatgpt.com` tag) |
| **Perplexity** | `perplexity.ai` |
| **Gemini** | `gemini.google.com`, `bard.google.com` |
| **Copilot** | `copilot.microsoft.com` |
| **Claude** | `claude.ai` |
| **You.com** | `you.com` |
| **Poe** | `poe.com` |

**Google "AI Overviews" is intentionally left out.** Those visits arrive with a plain `google.com` referrer that is indistinguishable from an ordinary Google search, so counting them would be guesswork — and Agentimus would rather show you nothing than show you a number it can't stand behind.

### What gets counted, and what doesn't

A visit is recorded only when **all** of these are true:

- it's a normal front-end page view (a `GET` request — not the admin area, a REST/AJAX call, a feed, a 404, a trackback, or `robots.txt`);
- the visitor is a real browser;
- the referrer or `utm_source` matches a known AI assistant; and
- the visitor is **not you** — a logged-in administrator browsing their own site is skipped, so your own clicks never inflate the numbers.

When a visit does count, the plugin stores exactly one thing: a **per-day tally of (assistant, landing page)**. It keeps **no IP address, no User-Agent, no query string** — the landing page's query string (including any tracking tags) is stripped off before the path is saved, and the path itself is length-limited. There is nothing in a stored row that could identify a reader.

---

## The agent activity log

The activity log is the mirror image of the Traffic-from-AI card: instead of humans arriving *from* AI, it records **bots and crawlers fetching your content** — the AI tools reading your site so they can train on it or answer questions about it.

A hit is logged each time an agent fetches one of the machine-readable documents Agentimus serves, for example:

| Endpoint | What it is |
| --- | --- |
| `llms.txt` | Your site's content map for AI |
| `llms-full.txt` | The full-text edition of your content |
| `markdown` | A page or post fetched as clean `.md` |
| `discovery.json` | The machine-readable discovery document |
| `mcp.json`, `openapi.json`, `api-catalog` | Tool/API descriptions for agents |
| `sitemap`, `tdmrep.json`, `oauth-protected-resource` | Supporting discovery files |

### What a "hit" records

Each logged hit stores just four small facts:

- the **endpoint** that was fetched (a short label like `llms.txt`),
- a **friendly agent name** worked out from the request (see below),
- a **truncated copy of the User-Agent string** the client sent, and
- the **time**, stored in UTC.

That's it — the log row itself records **no IP address**. By default the plugin keeps no IP at all; the sole exception is an optional setting (**Store IP addresses for flagged clients**, off by default) that stores a flagged impersonating or spoofed crawler's IP in a *separate* store so you can block it — never an ordinary visitor's, and covered in the privacy note below. The log is never transmitted anywhere, and repeat hits from the same client are grouped with a count, newest first, so the view stays readable.

As with the traffic card, **you are skipped**: a logged-in administrator opening `discovery.json` in a browser to check it isn't agent traffic, so it doesn't clutter the log.

### How agents are identified

Raw User-Agent strings are noisy, so Agentimus translates each one into a **plain-English label**. Recognised crawlers get their real name — for example **GPTBot (OpenAI)**, **ClaudeBot (Anthropic)**, **PerplexityBot**, **Googlebot**, **Bingbot**, **Bytespider (ByteDance)**, **CCBot (Common Crawl)**. A client that isn't in that catalog but which **declares its own product name** — for example `TheWebReport/1.0` — is shown by that name (**TheWebReport**), the very name the review queue uses, so the two never disagree. Only when there's no name to show at all does a client fall into a clear generic bucket:

- **Other bot** — self-declares as a crawler, but isn't one Agentimus has a name for.
- **Script/tool** — an HTTP library or command-line tool (curl, wget, Python requests, and the like).
- **Likely spoof/scanner** — a client pretending to be a long-dead phone or embedded device (Symbian, Java ME, old BlackBerry, Windows CE…). No real reader fetches a machine endpoint from a 2004 feature phone, so these are almost always scanners hiding behind a harmless-looking string.
- **Browser** — a normal web browser.
- **No user-agent** — the client sent no User-Agent at all.
- **Unrecognized** — the client sent a User-Agent, but nothing Agentimus can name it by.

These same labels feed the review queue, so the naming never disagrees with itself.

### The daily chart and per-day requests

The log includes a **per-day chart** spanning the retention window, with every day present even if it saw no traffic. Each day also carries a compact breakdown of **who** hit you (its top clients) and **what** they fetched (its top endpoints), capped at five of each with a distinct-count so a day with fifty different endpoints still shows five rows and a "+50" — the card never grows with your traffic.

Click into a day and the **"View requests"** view shows every hit recorded on that date, newest first (capped at a generous per-day limit), so you can inspect a spike in detail.

### Top clients and endpoints — and whether they're trending

Two summary cards rank the whole window (30 days by default):

- **Top clients** — which agents hit you most (up to eight), and
- **By endpoint** — which of your machine-readable files got fetched most.

Each row shows the name, a bar for its share, and its total count. Beside the bar is a small **trend** — a green ▲ or a red ▼ with a percentage — telling you whether that client or file is getting **more or less** AI attention across the window. It's the at-a-glance answer to "is GPTBot crawling me more this month?" or "is attention on `llms.txt` slipping?"

Because Agentimus only keeps the last 30 days (see pruning below), there's no earlier month to compare against — so the trend is measured **inside** the window: the **recent half** of the period against the **earlier half**. No outside data, no guesswork.

The arrow only appears when the number can be trusted. A row shows a quiet **"–"** — meaning *no trend to report* — whenever any of these is true:

- it has **fewer than about 20 hits** over the window (too little to read a direction into),
- it has **no activity in the earlier half** to compare against (common on a fresh install, or a client that only just started showing up), or
- it **moved less than about ±10%** (steady, not really up or down).

So a brand-new site — or one with only a few recent days of traffic — will show mostly "–" until enough history builds up. That's the trend staying honest rather than inventing a swing from thin data; once a site has a couple of weeks of steady bot traffic spread across the window, the real movers stand out on their own.

### Activity to review

Sitting alongside the log is a small **review queue** that flags clients worth a second look — ones that are **new**, **unusually high-volume**, **spoofed/scanner** by the definition above, or an **impersonator**. An impersonator is a client claiming to be a *verifiable* search engine — Googlebot, Bingbot, Applebot, DuckDuckBot or Yandex — whose network address **fails a reverse-DNS check**: a forgery, not the real engine. (Impersonators are caught only when **Verify search engines by reverse DNS** is on, an opt-in setting covered on the **Settings** page.) Each flagged client comes with a one-click **Block** (or **Allow**/trust) action. This pairs with the optional *Block scanners & scrapers* enforcement covered on the **Settings** page — the review queue and the blocking feature share one definition of who is who, so a decision you make in one is honoured by the other.

Every flagged row's **Details** also gives a plain, honest verdict on that one client. For an impersonator it reads like *"claims to be Googlebot, but its address failed reverse-DNS verification when it visited — a forgery, not the real Googlebot."* Because blocking the *name* would also block the genuine engine, the guidance is to **block the IP** at your host or CDN instead. To see the exact address, turn on the opt-in **Store IP addresses for flagged clients** setting (off by default) — it records forward-only, so it shows the IP of future flagged visits — or find it now in your server's access log by matching the User-Agent shown in the panel. Beside Block and Allow sits an **Ignore** action, which dismisses a row you've judged harmless; it quietly re-surfaces if that client keeps hammering you.

### Flood protection

To keep one abusive burst from drowning out the traffic you care about, the log is flood-aware. A **recognised crawler** (GPTBot, Googlebot, ClaudeBot…) is **always** logged, however fast it hits — that's exactly the signal you want. Everything else (unknown clients, scripts, spoofs) is throttle-eligible: once such hits pass a threshold within a short window, the log keeps only a sample of them. So a stampede of throwaway user-agents (*EvilBot-1, EvilBot-2, …*) can't push real agents out of the log.

---

## Your privacy: local-only, no IPs by default

This is the point worth repeating, because it shapes every design choice on this tab:

- **Local-only.** Both the traffic card and the activity log live entirely in your WordPress database. Nothing is sent to Agentimus, and the plugin makes **no outbound calls** to gather any of it.
- **No IP addresses by default.** Out of the box neither the traffic card nor the activity-log row records an IP. The one exception is an optional setting — **Store IP addresses for flagged clients** (off by default) — which records an IP *only* for a client flagged as an impersonating crawler (one that claims to be Googlebot, Bingbot and the like but fails a reverse-DNS check) or a legacy-device spoof/scanner, and never for an ordinary visitor. When it's on, those IPs are kept in a separate store on **your own server only**, for a short retention (about 14 days), cleared when you clear the log, and deleted entirely if you switch the setting back off — nothing is ever sent off your server. It exists so you can see the exact addresses to block at your host or CDN; if you enable it, disclose it in your site's privacy policy.
- **The traffic card stores no person-level data at all.** Its rows are pure per-day tallies of *(assistant → page)* with no IP, no User-Agent, and no query string.
- **The activity log keeps only technical request facts** — the endpoint, a friendly agent label, a truncated User-Agent, and a UTC time. A User-Agent is a self-declared software string, not a person.
- **You're excluded.** Your own logged-in admin visits are skipped in both directions, so your browsing never skews the data.

If you ever want a clean slate, the log offers a **Clear** action that empties it in one step.

---

## How data is pruned and capped

Nothing here accumulates forever. Both stores are tied to the same housekeeping:

- **Retention window.** By default, Agentimus keeps (and reports on) the last **30 days**. What you see always equals what's retained — the daily chart span, the reporting window, and the cleanup cutoff are all the same number.
- **Daily prune.** A once-a-day background task deletes anything older than the retention window, for both the activity log and the traffic tallies.
- **Hard row cap.** As a backstop for an extreme-traffic day (so the table can't bank a huge number of rows before the daily prune runs), the activity log is also capped at a maximum row count and trimmed opportunistically as new hits come in.

For most site owners the defaults are exactly right and need no attention. Developers who want a longer or shorter memory can adjust the retention and the cap with filters (below).

---

## The AEO/GEO score in the sidebar

The Dashboard sidebar carries your **AEO/GEO score** — a single 0–100 measure of how ready your site is for AI answer engines (AEO = Answer Engine Optimization, GEO = Generative Engine Optimization), with the one most useful next step always in view. It rolls everything Agentimus does into one number, so you don't have to read a checklist to know where you stand.

The score is a ladder of **five rungs**, each shown as a percentage:

- **Findable** — an agent can reach and crawl your site (public, pretty permalinks, `robots.txt`, sitemap).
- **Readable** — what it crawls comes back clean and structured (`llms.txt`, the full-text edition, schema, Topics for AI).
- **Trusted** — it can identify you and trust the source (your profile, expertise, an entity image/logo, security and AI-usage signals).
- **Optimized** — your individual pages are easy for an AI to read and quote (see *[Optimize your content](readiness.html)* on the Readiness page).
- **Cited** — AI engines actually name you in their answers. This rung appears **only when you turn on "Track AI citations"** (see *[AI Visibility](ai-visibility.html)*); until then the score is a clean four-rung ladder.

The three readiness rungs — Findable, Readable, Trusted — are the original **"agent-ready"** milestone: when all three reach 100%, your site is fully agent-ready. Each rung links straight to where you act on it — the readiness rungs and Optimized open their section on the **Readiness** tab; Cited opens **AI Visibility**.

At the top, a gauge shows the blended score and a plain band — **Needs work**, **Fair**, **Strong** or **Excellent** — and a **"Next:"** line names the single highest-impact thing to do next. A rung with nothing to measure yet (for example Cited before you've run a check) shows a grey **"—"** and is left out of the maths, its weight shared across the other rungs — so you're never penalised for a feature you don't use. (If your site is hidden from crawlers, the whole score is floored until you fix that first.)

The full checks behind Findable / Readable / Trusted, and the per-page work behind Optimized, live on the **Readiness** tab — see the **[Readiness & agent preview](readiness.html)** page.

---

## Turning the Dashboard on or off

The Dashboard's data collection is controlled by a single **Agent activity log** setting, found under **Settings**. It's **on by default** and governs both sides at once:

- turn it **off** and Agentimus stops recording both AI referrals and agent hits (the tab simply shows nothing new); 
- turn it back **on** and recording resumes from that point.

Turning it off doesn't delete what's already there — use the log's **Clear** action if you want to wipe the history as well.

---

## For developers: adjusting the defaults (optional)

If you manage the site's code, a few filters let you tune this feature. Site owners can safely ignore this section.

> **Keeping more than 30 days? Raise the row cap too.** Retention is only one of the two
> limits on the log. Independently of age, the plugin trims the table back to
> `agentimus_activity_max_rows` (50,000) so a traffic spike can't grow it without bound. On
> a busy site, asking for 90 days while leaving the cap at its default means the oldest rows
> are still discarded — you'd get fewer days than you asked for, and nothing would say so.
> Raise both filters together, and keep an eye on the size of the `agentimus_activity`
> table: rows are small, but 90 days of a heavily-crawled site is a lot of them.

```php
// Change how long activity + referrals are kept and reported on (days). Default 30.
add_filter( 'agentimus_activity_retention_days', fn() => 90 );

// Change the hard row cap on the activity log (0 disables the cap). Default 50000.
// Raise this alongside the retention above — otherwise the cap silently wins.
add_filter( 'agentimus_activity_max_rows', fn() => 100000 );

// Add your own AI-referral sources (referrer host / utm_source => display label).
add_filter( 'agentimus_ai_referral_sources', function ( $map ) {
	$map['mistral.ai'] = 'Le Chat';
	return $map;
} );

// Add or rename entries in the agent-name map used by the activity log.
add_filter( 'agentimus_agent_map', function ( $map ) {
	$map['mynewbot'] = 'My New Bot';
	return $map;
} );

// Log your own admin visits too (default is to skip logged-in administrators).
add_filter( 'agentimus_activity_skip_self', '__return_false' );
```
