---
title: Dashboard & activity
parent: User Manual
nav_order: 3
---

## The Dashboard opens with today

Above everything else sits one line about the day you are in: **how many AI reads**, **how many people arrived from an AI answer**, and **what connected assistants did** — each against yesterday — followed by one sentence naming who did it ("Bingbot read you most · ChatGPT sent the visits").

Only numbers that can honestly mean *today* appear there. Your score reads the same all week, so it stays in the rail beside the card rather than pretending to be a daily figure. **See any range →** opens the [Report screen](report.html), where the same reading is available for any two dates and every block dates itself.

These counts are UTC calendar days. When that is not the date on your own clock, the line says "UTC day" and explains why — see [Report](report.html#days-here-are-counted-in-utc).

---

## What the Dashboard shows

Below that line, the cards describe what your site **is** rather than what today held. It answers one plain question with two sides:

- **Is AI sending readers *to* you?** The **Traffic from AI** card counts real people who clicked through to your site from an AI assistant like ChatGPT or Perplexity.
- **Is AI reading *from* you?** The **agent activity log** records each time a bot or crawler fetches one of the machine-readable files Agentimus publishes (things like `/llms.txt` or a page's `.md` version).

Alongside these, the sidebar carries your **AEO/GEO score** — a single 0–100 read on how ready your site is for AI, with the next thing to improve always in view.

Everything on this tab is **first-party and local-only**. All of it lives in your own WordPress database, nothing is ever sent to Agentimus or any third party, and — importantly — **by default no IP addresses and no personal data are stored**. (One optional setting, off by default, can store an IP for a flagged impersonating or spoofed crawler so you can block it — never for an ordinary visitor, and never off your own server; see the privacy note below.) The two data stores are pruned and size-capped automatically, so they never grow without bound.

The activity half of the Dashboard is powered by a single setting, **Agent activity log**, which is **on by default**. It covers both directions at once — the traffic-in card and the bots-out log are one feature. If you ever turn it off in Settings, both stop recording.

---

## What your site runs

Every Agentimus system's standing, in one card of four panels — so "what is running, and where does it stand" never needs a tour of the screens:

- **AI access** — what machines can reach and do: the MCP server (on or off, how many tools, whether writes are allowed), how many assistants were active this month, WebMCP, and security.txt.
- **Telling engines** — what your site announces on its own: IndexNow and when it last announced, the sitemap and robots.txt, the weekly digest, and Cloudflare.
- **Search** — what the engines hold: your watched pages in Google's index, which engines are connected, and unknown sources waiting to be named.
- **Content** — what your writing holds: findings open across the site, the worklist, and how many pages carry a focus keyword.

Each panel is led by one number and ends in the link that opens its full screen. The numbers are the same ones the nav and the screens already show, read from the same payloads — the card can never disagree with the report it points into. And a switched-off system is stated in plain faint text rather than hidden, because off is information.

## Who reached your site

Two audiences, counted separately because they are not the same thing and never add up: **humans** who arrived to read (people, visits, where they came from — search clicks and AI referrals), and **machines** that fetched your agent files (how many fetches, how many distinct clients, and how many were caught faking an identity). There is deliberately no combined total — a fetch is not a visit, and adding them would invent a number that means nothing. A "worth knowing" note under the card states what each instrument can and cannot see.

---

## Traffic from AI

The **Traffic from AI** card is the "giving back" side of the story: it shows whether AI tools are actually sending human readers to your pages, and where those readers land.

The card is a summary over the reporting window (30 days by default), counted in **whole calendar days (UTC)** — the same clock as every other number on this screen:

- **Totals** — visits today, visits across the whole window, and how many distinct assistants sent them.
- **A per-day sparkline** — the shape of AI referrals across the window, zero-filled so a quiet day reads as a gap rather than being skipped. **Click any bar** for that day's report — which assistant sent readers to which page — the same detail the full report shows, without leaving the dashboard.
- **Top sources** — which assistants sent the most readers (up to five).
- **Top landing pages** — which of your pages AI readers arrived on most (up to five).

For the day-by-day detail — and for the sources it *couldn't* name — a **See the full report** link opens the dedicated **Readers** screen, described next.

### The Readers report (More → Readers)

The dashboard card is the summary; the full report lives on its own screen, opened from **More → Readers**. It answers the cross-question the card can't — *which pages does Perplexity send readers to?* — and reaches deeper history: the card only ever covers the last 30 days, while this screen can page over everything the visit log still retains.

Three filters narrow it, and no more — the store keeps only a day, a source, a path and a count, so anything finer would imply a per-visit record it doesn't hold:

- a **date range**,
- a **source** (assistant), and
- a **landing-path prefix**.

Above the day list it repeats the summary for the current filter — totals, top sources, top landing pages — then breaks the visits down **by day**. Expand a day to see **which assistant sent a reader to which page**: a list of **source → page** rows — for example, *Perplexity → /pricing* — each with a count, busiest first, with a **"Show all N"** control when a day holds more rows than it shows at once. Days are listed newest-first.

There is no clock time anywhere in this report — **the day is the finest level of "when" the plugin stores**. That is deliberate: a per-visit timestamp starts to look like a trail back to an individual, and the whole feature is built so that no stored row can ever represent a person.

### Find missed AI sources (the diagnostic)

"Traffic from AI" only counts assistants it *recognises*, and a miss leaves no trace — so a brand-new assistant could be sending you readers without ever showing up. The **Unrecognised referrers** diagnostic on the Readers screen closes that blind spot: it lists the referrer hosts and `utm_source` tags that arrived but matched no known assistant, so you can spot one that ought to be counted and add it (with the `agentimus_ai_referral_sources` filter).

It's **opt-in and off by default** — turn on **Find missed AI sources** under **Settings → Visit log**. Unlike the referral counter, it writes a row for *every* externally-referred pageview, so it's meant to be switched on for a week and then off again. It records only the referring site's name and the link's `utm_source` tag — still no IP addresses, still nothing sent anywhere. While it's off, the screen says so and links you straight to the setting.

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
| **Claude** | `claude.ai`, `claude.com` |
| **Grok** | `grok.com` |
| **DeepSeek** | `deepseek.com` (incl. `chat.deepseek.com`) |
| **Meta AI** | `meta.ai` |
| **Mistral (Le Chat)** | `chat.mistral.ai` |
| **DuckDuckGo AI** | `duck.ai` |
| **Phind** | `phind.com` |
| **You.com** | `you.com` |
| **Poe** | `poe.com` |

Several assistants also tag their outbound links with a bare `utm_source` (like `utm_source=perplexity`) rather than a full domain; those are matched too, so a referral still counts when the browser strips the referrer away.

**Search engines that also answer with AI are intentionally left out** — **Google** (AI Overviews / AI Mode), **Bing** (Copilot in search), **DuckDuckGo**, **Kagi**, and **x.com** (Grok). A visit from any of them arrives with a plain search-engine referrer that can't be told apart from an ordinary search click, so counting it would be guesswork — and Agentimus would rather show you nothing than a number it can't stand behind. Their *dedicated* assistant addresses, where one exists (`gemini.google.com`, `copilot.microsoft.com`, `duck.ai`), **are** counted.

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

The plugin's own self-tests are skipped too. **Verify live** and the **exposed-files scan** (both on the Readiness screen) fetch your public endpoints *anonymously* on purpose — they have to grade exactly what an agent receives, so they send no cookies, which means the "skip the owner" rule above can't recognise them by sign-in. Instead they carry a short-lived token, minted by your site and valid for a couple of minutes, that tells the log "this is the site testing itself." An outside crawler can't mint one or reuse a captured one to hide from the log.

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

**Click any bar** and that day's full report opens: the who/what breakdown above, then every individual request recorded on that date with its exact time (UTC), newest first, capped at a generous per-day limit — so you can inspect a spike in detail. Arrows (or the ← → keys) step between days that saw traffic, and a picker jumps straight to one. The report closes with **Esc** or its **Close** button; a stray click outside it won't drop what you were reading.

### Top clients and endpoints — and whether they're trending

Two summary cards rank the whole window (30 days by default). Every window on this screen — today, 7 days, 30 days — is counted in **whole calendar days (UTC)**: today plus the full days before it. Numbers only move when a hit arrives or at midnight UTC, so a counter never appears to *lose* hits while you watch. (Before 1.22.0 the 7- and 30-day tiles were rolling windows ending at the current second — they could genuinely tick down as old hits aged past the edge, which looked like data loss under auto-refresh. It wasn't; but a number that needs that explanation is the wrong number, so the clocks are now aligned.)

- **Top clients** — which agents hit you most (up to eight), and
- **By endpoint** — which of your machine-readable files got fetched most.

Every row is a door: click an endpoint or client to open the **Request Log** pre-filtered to it, and on the Traffic-from-AI card a source or landing page opens the **Readers** report the same way. Hover a row for the numbers behind it — a trend arrow shows the two half-window counts it compares, a referral row its share of the window's visits.

Each row shows the name, a bar for its share, and its total count. Beside the bar is a small **trend** — a green ▲ or a red ▼ with a percentage — telling you whether that client or file is getting **more or less** AI attention across the window. It's the at-a-glance answer to "is GPTBot crawling me more this month?" or "is attention on `llms.txt` slipping?"

Because Agentimus only keeps the last 30 days (see pruning below), there's no earlier month to compare against — so the trend is measured **inside** the window: the **recent half** of the period against the **earlier half**. No outside data, no guesswork.

The arrow only appears when the number can be trusted. A row shows a quiet **"–"** — meaning *no trend to report* — whenever any of these is true:

- it has **fewer than about 20 hits** over the window (too little to read a direction into),
- it has **no activity in the earlier half** to compare against (common on a fresh install, or a client that only just started showing up), or
- it **moved less than about ±10%** (steady, not really up or down).

So a brand-new site — or one with only a few recent days of traffic — will show mostly "–" until enough history builds up. That's the trend staying honest rather than inventing a swing from thin data; once a site has a couple of weeks of steady bot traffic spread across the window, the real movers stand out on their own.

### Activity to review

Sitting alongside the log is a small **review queue** that flags clients worth a second look — ones that are **new**, **unusually high-volume**, **spoofed/scanner** by the definition above, or an **impersonator**. An impersonator is a client claiming to be a bot from the **Verified bots** list — Googlebot, GPTBot, PerplexityBot, and so on — whose network address **conclusively failed that operator's own published check**: a reverse-DNS lookup that lands somewhere else entirely, or an address outside the operator's published IP ranges. A forgery, not the real bot. (Impersonators are caught only when **Verify bot identities** is on, an opt-in setting covered on the **Settings** page; the impostor's card says exactly which check caught it.) Each flagged client comes with a one-click **Block** (or **Allow**/trust) action. This pairs with the optional *Block scanners & scrapers* enforcement covered on the **Settings** page — the review queue and the blocking feature share one definition of who is who, so a decision you make in one is honoured by the other, and with both blocking switches on a proven impersonator is refused at the AI endpoints automatically.

The queue does one piece of housekeeping on its own, and says so rather than leaving you to wonder: a client whose only flag is **New** leaves the queue by itself **48 hours after its first visit** — its chip counts down ("New · leaves in 31h") so the exit is predicted, not discovered. Flagged clients don't leave on a timer: they stay until you Allow, Block or Ignore them. Nothing is ever lost either way — every request stays in the log. The queue's footer states all of this in place, and its **Manage clients** link opens the same decisions dialog described on the Settings page.

Every flagged row's **Details** also gives a plain, honest verdict on that one client. For an impersonator it reads like *"claims to be Googlebot, but its address failed reverse-DNS verification when it visited — a forgery, not the real Googlebot."* Because blocking the *name* would also block the genuine engine, the guidance is to **block the IP** at your host or CDN instead. To see the exact address, turn on the opt-in **Store IP addresses for flagged clients** setting (off by default) — it records forward-only, so it shows the IP of future flagged visits — or find it now in your server's access log by matching the User-Agent shown in the panel. Beside Block and Allow sits an **Ignore** action, which dismisses a row you've judged harmless; it quietly re-surfaces if that client keeps hammering you. Every decision you make here — blocked, allowed or ignored — is listed afterwards under **Settings → AI access → Manage clients**, with the date you decided and a one-click undo. That dialog is the only place an *ignored* client can be seen and brought back.

Where a client is one Agentimus doesn't recognise, Details also shows the **home page it names in its own User-Agent** — the `+https://example.com/bot` convention most well-behaved crawlers follow — as a link you can open, with what was found there beside it. A real operator keeps a page at that address explaining who they are and how to block them, so the answer is worth having:

- **answers** — there's a page there. Read it; it's still the client's own claim, not proof of who it is.
- **nothing there (404)** — the address it gave leads nowhere. That's a reason to look harder before you allow it.
- **couldn't reach it — says nothing** — the request got nowhere conclusive. That may be *your* server's network, a firewall turning it away, or a bad afternoon at the other end, so it's reported as evidence of nothing.

The check runs quietly in the background, at most once a week per site, and never while you're loading a page. It changes no verdict and blocks nothing — it's one more honest fact on the card, and the decision stays yours.

### Flood protection

To keep one abusive burst from drowning out the traffic you care about, the log is flood-aware. A **recognised crawler** (GPTBot, Googlebot, ClaudeBot…) is **always** logged, however fast it hits — that's exactly the signal you want. Everything else (unknown clients, scripts, spoofs) is throttle-eligible: once such hits pass a threshold within a short window, the log keeps only a sample of them. So a stampede of throwaway user-agents (*EvilBot-1, EvilBot-2, …*) can't push real agents out of the log.

---

## The Request Log

Open it from **More → Request Log** in the nav bar. (The occasional screens — AI Visibility, Readers, Request Log and About Agentimus — live behind that one menu, so the bar stays readable on a narrow admin.)

The Dashboard answers *who* visited and *how much*. The Request Log answers **what a particular bot actually fetched** — the question the summary cards can't, because they show clients and endpoints as two separate lists.

Filter by **Client** and **Endpoint** together and you get the intersection: every page GPTBot asked for, or every bot that pulled `llms-full.txt`. You can also filter by **User-Agent** (matches from the *start* of the string), by **Network** once bot identification is on, by **Verification** (verified / spoofed / unchecked / refused), and by a date range.

**Client, Endpoint, Network and Verification each take more than one value.** Tick two clients and you see both — the picker stays open while you choose, says "2 selected" once you pass one, and "Any" clears it. Picking several *widens that one control*: two clients means either client, while every other filter still has to match. Verification can mix a verdict with an outcome — "spoofed **or** refused" is one tick each. **User-Agent** and the dates stay single: the first matches from the start of the string, and a date range is already a range.

**Every column sorts.** Click a header to order the whole filtered set — not just the page on screen — and click again to reverse it. Newest-first is the default; the small stacked arrows on each header show which column is active and which way.

Clicking a client or endpoint in the table filters the whole log onto that value — the quickest route from "that row looks odd" to "show me everything this client did".

Requests marked **spoofed** are tinted, so a crawler that claimed to be Googlebot and failed reverse-DNS verification stands out as you scroll.

Two limits worth knowing, both stated at the foot of the tab:

- The log holds the **last 30 days**. Older requests are *deleted*, not hidden — no screen can show them, and neither can an export.
- On a very busy site the oldest rows *inside* that window may also have been trimmed by the row cap (see [How data is pruned and capped](#how-data-is-pruned-and-capped)). So read a full page as a floor, not a total.

The menu entry disappears when the agent activity log is switched off, since there would be nothing to page through — and if you're looking at the log when you switch it off, Agentimus returns you to the Dashboard rather than leaving you on an empty screen.

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
- **Optimized** — your individual pages are easy to read, quote and credit, for an assistant and for the search engine and screen reader that want the same things (see *[Optimize your content](readiness.html)* on the Readiness page).
- **Cited** — AI engines actually name you in their answers. This rung appears **only when you turn on "Track AI citations"** (see *[AI Visibility](ai-visibility.html)*); until then the score is a clean four-rung ladder.

The three readiness rungs — Findable, Readable, Trusted — are the original **"agent-ready"** milestone: when all three reach 100%, your site is fully agent-ready. Each rung links straight to where you act on it — the readiness rungs and Optimized open their section on the **Readiness** tab; Cited opens **AI Visibility**.

At the top, a gauge shows the blended score and a plain band — **Needs work**, **Fair**, **Strong** or **Excellent** — and a **"Next:"** line names the single highest-impact thing to do next (hover it for the why, and where the click lands). A rung that isn't at 100% also carries a quiet **"n to fix"** count — how many checks (or, for Optimized, page-fixes) stand between it and full marks. A rung with nothing to measure yet says so in words — Cited reads **"not measured yet"** before you've run a check — and is left out of the maths, its weight shared across the other rungs, so you're never penalised for a feature you don't use. (If your site is hidden from crawlers, the whole score is floored until you fix that first.)

The full checks behind Findable / Readable / Trusted, and the per-page work behind Optimized, live on the **Readiness** tab — see the **[Readiness & agent preview](readiness.html)** page.

### When the score updates

Most of what the score measures is your **content**, and content is edited in the post editor — usually in another browser tab. So the score keeps itself current rather than waiting for a page load. It re-reads itself:

- when you **switch back to the Agentimus tab** (so fixing a page in the editor, then returning here, shows the result);
- when you press **Refresh** on the Dashboard;
- when you re-run **Readiness**, or save a setting.

If you've just fixed the page the **"Next:"** line was nagging you about, expect it to move on to the next one — you shouldn't have to reload the page to see that.

---

## Turning the Dashboard on or off

The Dashboard's data collection is controlled by a single **Agent activity log** setting, found under **Settings**. It's **on by default** and governs both sides at once:

- turn it **off** and Agentimus stops recording both AI referrals and agent hits (the tab simply shows nothing new); 
- turn it back **on** and recording resumes from that point.

Turning it off doesn't delete what's already there — use the log's **Clear** action if you want to wipe the history as well.

---

## For developers: adjusting the defaults (optional)

Most owners never need this section — the controls under **Settings → Visit log** cover it:

- **Delete old records automatically** — on by default. Each night, anything older than the period below is removed. Switch it off and records are kept until the log reaches its size cap, then the oldest are dropped. Either way the log cannot grow without limit.
- **Keep records for** — 7 days to 1 year. Default 30.
- **Size cap** — 10,000 to 250,000 rows, with the disk cost of each shown beside it. Default 50,000, roughly 14–33 MB.

**The size cap outranks the period.** If a busy site reaches the cap before records are old enough to expire, the oldest are removed anyway. "Keep records for 90 days" is a ceiling on age, not a guarantee that a row survives 90 days — the size limit is absolute, because filling a shared host's disk is worse than losing old crawler hits. The cap is applied whenever hits arrive, and once a night regardless.

**The Dashboard always reports on at most the last 30 days**, whatever you keep. Keeping 90 days gives the [Request Log](#the-request-log) a deeper history to page through; it does not stretch the Dashboard's cards. Keeping *fewer* than 30 days shortens the Dashboard to match, rather than drawing empty days for records that were deleted.

Flagged crawler IPs are **not** governed by this. They're the only personal data the plugin stores and they're removed on their own, shorter schedule.

If you manage the site's code, a few filters let you tune this further. The filters **override the settings above**, so code always beats the UI.

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
