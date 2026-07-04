---
title: Dashboard & activity
parent: User Manual
nav_order: 3
---

## What the Dashboard shows

The **Dashboard** is the first tab you land on when you open Agentimus. It answers one plain question with two sides:

- **Is AI sending readers *to* you?** The **Traffic from AI** card counts real people who clicked through to your site from an AI assistant like ChatGPT or Perplexity.
- **Is AI reading *from* you?** The **agent activity log** records each time a bot or crawler fetches one of the machine-readable files Agentimus publishes (things like `/llms.txt` or a page's `.md` version).

Alongside these sits a **Readiness summary** — an at-a-glance health check of how well your site is set up for AI.

Everything on this tab is **first-party and local-only**. All of it lives in your own WordPress database, nothing is ever sent to Agentimus or any third party, and — importantly — **no IP addresses and no personal data are stored**. The two data stores are pruned and size-capped automatically, so they never grow without bound.

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

That's it. **No IP address is ever recorded** — so there is no personal-data or GDPR footprint by default — and the log is never transmitted anywhere. Repeat hits from the same client are grouped with a count, newest first, so the view stays readable.

As with the traffic card, **you are skipped**: a logged-in administrator opening `discovery.json` in a browser to check it isn't agent traffic, so it doesn't clutter the log.

### How agents are identified

Raw User-Agent strings are noisy, so Agentimus translates each one into a **plain-English label**. Recognised crawlers get their real name — for example **GPTBot (OpenAI)**, **ClaudeBot (Anthropic)**, **PerplexityBot**, **Googlebot**, **Bingbot**, **Bytespider (ByteDance)**, **CCBot (Common Crawl)**. Where a client isn't specifically recognised, it falls into a clear generic bucket instead:

- **Other bot** — self-declares as a crawler, but isn't one Agentimus has a name for.
- **Script/tool** — an HTTP library or command-line tool (curl, wget, Python requests, and the like).
- **Likely spoof/scanner** — a client pretending to be a long-dead phone or embedded device (Symbian, Java ME, old BlackBerry, Windows CE…). No real reader fetches a machine endpoint from a 2004 feature phone, so these are almost always scanners hiding behind a harmless-looking string.
- **Browser** — a normal web browser.
- **No user-agent** — the client sent no User-Agent at all.
- **Unidentified** — none of the above.

These same labels feed the review queue, so the naming never disagrees with itself.

### The daily chart and per-day requests

The log includes a **per-day chart** spanning the retention window, with every day present even if it saw no traffic. Each day also carries a compact breakdown of **who** hit you (its top clients) and **what** they fetched (its top endpoints), capped at five of each with a distinct-count so a day with fifty different endpoints still shows five rows and a "+50" — the card never grows with your traffic.

Click into a day and the **"View requests"** view shows every hit recorded on that date, newest first (capped at a generous per-day limit), so you can inspect a spike in detail.

### Activity to review

Sitting alongside the log is a small **review queue** that flags clients worth a second look — ones that are **new**, **unusually high-volume**, or **spoofed/scanner** by the definition above. Each flagged client comes with a one-click **Block** (or **Allow**/trust) action. This pairs with the optional *Block scanners & scrapers* enforcement covered on the **Settings** page — the review queue and the blocking feature share one definition of who is who, so a decision you make in one is honoured by the other.

### Flood protection

To keep one abusive burst from drowning out the traffic you care about, the log is flood-aware. A **recognised crawler** (GPTBot, Googlebot, ClaudeBot…) is **always** logged, however fast it hits — that's exactly the signal you want. Everything else (unknown clients, scripts, spoofs) is throttle-eligible: once such hits pass a threshold within a short window, the log keeps only a sample of them. So a stampede of throwaway user-agents (*EvilBot-1, EvilBot-2, …*) can't push real agents out of the log.

---

## Your privacy: local-only, no IPs, no personal data

This is the point worth repeating, because it shapes every design choice on this tab:

- **Local-only.** Both the traffic card and the activity log live entirely in your WordPress database. Nothing is sent to Agentimus, and the plugin makes **no outbound calls** to gather any of it.
- **No IP addresses.** Neither store records an IP, ever — so there's no personal-data trail to manage.
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

## The Readiness summary in the sidebar

The Dashboard sidebar carries a **condensed view of your Readiness report** — the same pass / warn / fail checks shown in full on the **Readiness** tab, boiled down to an at-a-glance signal of how AI-ready your site is.

These checks look at the things that decide whether agents can find and read you well, such as:

- whether your site is public and using pretty permalinks,
- whether `/llms.txt` (and the full-text edition) are enabled and have real substance,
- whether you've set an author/entity **profile** and expertise topics,
- whether **Topics for AI** are in use,
- whether a sitemap exists and is advertised in `robots.txt`, and
- whether your AI-usage and security signals are in place.

The sidebar summary is a jumping-off point: use it to spot that something needs attention, then open the **Readiness** tab for the full breakdown, where each item comes with a plain-English explanation and a deep link straight to the fix. The **Readiness & agent preview** page in this manual covers those checks in detail.

---

## Turning the Dashboard on or off

The Dashboard's data collection is controlled by a single **Agent activity log** setting, found under **Settings**. It's **on by default** and governs both sides at once:

- turn it **off** and Agentimus stops recording both AI referrals and agent hits (the tab simply shows nothing new); 
- turn it back **on** and recording resumes from that point.

Turning it off doesn't delete what's already there — use the log's **Clear** action if you want to wipe the history as well.

---

## For developers: adjusting the defaults (optional)

If you manage the site's code, a few filters let you tune this feature. Site owners can safely ignore this section.

```php
// Change how long activity + referrals are kept and reported on (days). Default 30.
add_filter( 'agentimus_activity_retention_days', fn() => 90 );

// Change the hard row cap on the activity log (0 disables the cap). Default 50000.
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
