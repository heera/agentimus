---
title: AI access & crawlers
parent: User Manual
nav_order: 16
---

Agentimus gives you a clear, plain-language way to decide **who gets to read your site, and for what** — search engines, AI assistants, and the crawlers that harvest content to train AI models. It does this in two layers: a set of *polite requests* that well-behaved crawlers honour, and an *optional* hard stop for the ones that don't.

Everything on this page lives under **Agentimus → Settings**. Nothing here changes what a normal human visitor sees, and the defensive controls (the ones that turn crawlers away with an error) are **off until you switch them on**.

## Two layers of control: a polite request and a hard stop

It helps to keep two ideas separate:

- **Advisory signals** — your `robots.txt`, the Content-Signal directive, the AI-usage headers, and the TDM opt-out file. These are the standards-track way to state your policy. A well-behaved crawler reads them and complies, but nothing forces it to. This layer is safe to leave on for everyone, and most of it is on by default.
- **Enforcement** — the optional hard block. When you switch it on, a crawler on your denylist (or one using a known scanner trick) is refused with a `403 Forbidden` at the AI files Agentimus generates, instead of being served. This is the *teeth*, and because a mistake here could turn away something you wanted, it ships **off by default**.

The rest of this page walks through both layers, from the gentlest signal to the hardest block.

## The robots.txt Agentimus writes for you

`robots.txt` is the file at the root of your site (for example `https://example.com/robots.txt`) that crawlers check before they fetch anything. WordPress already generates a basic one; Agentimus **adds to it without overwriting** what WordPress or your SEO plugin already put there. It adds three things.

This is controlled by the **Crawler policy** section and is on by default. It only runs when your site is set to be visible to search engines (WordPress **Settings → Reading**), so a site you've marked "discourage search engines" is never contradicted.

### 1. The Content-Signal line

The Content-Signal is a compact statement of what crawlers may do with your content. It's added inside the existing `User-agent: *` group, using a fixed, three-part vocabulary:

| Signal | What it means | Default |
| --- | --- | --- |
| `search` | Let search engines find and list your pages. | `yes` |
| `ai-input` | Let AI assistants read your content and cite it in their answers. | `yes` |
| `ai-train` | Let your content be used to train AI models. | `no` |

So a fresh install publishes `Content-Signal: search=yes, ai-input=yes, ai-train=no` — the site is fully findable and quotable, but reserved from training. Because the vocabulary is fixed, your `robots.txt` can only ever contain valid, expected values.

### 2. The training-crawler blocklist

The Content-Signal above is a *statement of intent*. `robots.txt` also lets you name specific crawlers and disallow them outright, and Agentimus ships a built-in list of the major AI-training crawlers, blocked by name:

```
User-agent: Amazonbot
User-agent: Applebot-Extended
User-agent: Bytespider
User-agent: CCBot
User-agent: ClaudeBot
User-agent: GPTBot
User-agent: Google-Extended
User-agent: meta-externalagent
Disallow: /
```

This is the *enforcement arm* of the training opt-out. It's independent of the `ai-train` signal and is applied whether training is declared allowed or blocked — because `robots.txt` has no single "all AI trainers" directive, the only way to block them is to list them. You can edit this list under **Blocked AI trainers** (the "Add a known crawler" chips let you re-add any you removed). An empty list blocks no one.

### 3. Your sitemap, advertised

Finally, Agentimus adds a `Sitemap:` line pointing to your XML sitemap — but only if nothing else already declared one. It never generates a competing sitemap: it detects the one your site already serves (WordPress core, or Yoast, Rank Math, All in One SEO, SEOPress, or The SEO Framework) and links that real location. If none of those provide a sitemap and you've opted in, Agentimus serves its own fallback sitemap and links that instead.

Putting it together, a typical Agentimus-augmented `robots.txt` looks like this:

```
User-agent: *
Content-Signal: search=yes, ai-input=yes, ai-train=no
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php

User-agent: Amazonbot
User-agent: Applebot-Extended
User-agent: Bytespider
User-agent: CCBot
User-agent: ClaudeBot
User-agent: GPTBot
User-agent: Google-Extended
User-agent: meta-externalagent
Disallow: /

Sitemap: https://example.com/wp-sitemap.xml
```

Because Agentimus *injects* rather than replaces, it co-exists with Yoast, WooCommerce and any other plugin that also writes to `robots.txt`. It skips any Content-Signal, sitemap, or crawler name that's already present, so nothing is duplicated.

## Setting your crawler policy (the Content-Signal)

The three Content-Signal toggles live in the **Crawler policy** section. Think of them as answering three questions about your content:

- **Show in search engines** — leave on so Google and others keep finding your pages.
- **Allow assistants to read and cite** — leave on so ChatGPT, Claude, Perplexity and similar tools can read a page and cite it when it answers a question. This is how your site earns AI citations, so most owners keep it on.
- **Allow training** — off by default. Turning this on says your content may be used to train AI models. Leaving it off keeps it reserved.

An important detail about the training choice: when training is **allowed**, Agentimus publishes *no* opt-out signals at all — on the open web, the absence of a signal already means "allowed", so there's nothing to state. The opt-out signals below only appear when you've chosen to refuse training (which is the default).

## AI-usage signals beyond robots.txt

Not every crawler reads `robots.txt`. So when your policy is "don't train on this," Agentimus asserts that same decision through three additional channels — *one decision, every channel*. These live in the **Published beyond robots.txt** section and are on by default; turn one off only if you don't want to publish through that particular channel.

All three only speak up when training is **blocked**. If you allow training, they stay silent.

### The `tdm-reservation` response header

On every normal content page, Agentimus can attach an invisible `tdm-reservation: 1` header to the response. This is the W3C **Text and Data Mining Reservation Protocol** signal, and it reaches a crawler directly in the HTTP response — even one that never bothered to read your `robots.txt`. If you've entered an **AI-usage policy URL**, a `tdm-policy` header pointing to it is sent alongside.

This header is deliberately *not* sent on the files you *want* agents to ingest — `llms.txt`, `llms-full.txt`, the `.md` versions of your pages, `robots.txt`, feeds, and anything under `/.well-known/` — because marking those "reserved" would contradict the whole point of publishing them.

### `/.well-known/tdmrep.json`

The same reservation is also published as a small, standardised file at `/.well-known/tdmrep.json`. Where the header rides along with each page, this is a single file a crawler can fetch on its own. When your policy is "don't train," it contains:

```json
[
  {
    "location": "/",
    "tdm-reservation": 1,
    "tdm-policy": "https://example.com/ai-policy"
  }
]
```

The `tdm-policy` line only appears if you've set an AI-usage policy URL. This file is **site-wide** — it reserves all of your content and can't single out individual bots. (Per-bot blocking is what the `robots.txt` crawler list and the hard block below are for.) When training is allowed, or you've switched this channel off, no file is served and the URL simply returns a clean `404`.

### The optional "noai" header

There's also an off-by-default extra: an `X-Robots-Tag: noai, noimageai` header asking AI tools not to use your text or images. This one is **not an official standard** — only some platforms honour it — so treat it as a harmless bonus signal on top of the two above, not a primary control. It's appended to any existing `X-Robots-Tag`, never replacing one.

## Agents that are always allowed

Alongside the "keep out" controls, Agentimus keeps a **trusted list** of agents that are *never* blocked and never flagged for review — no matter what your denylist or spoof rules say. This is the safety net that stops an over-broad block rule from accidentally locking out something important. There are two kinds of trusted agent.

### Search engines (recognised automatically)

The major search engines — **Googlebot, Bingbot, DuckDuckBot, Applebot, and Yandex** — are trusted automatically. You'll see them listed in the **Trusted** section as read-only ("recognised by signature and trusted automatically — you don't need to add them"), and you can't remove them.

Crucially, they're matched by *signature*, not by a loose text search. Agentimus checks for the real product token in its genuine form (like `Googlebot/2.1`), so a scanner that simply pastes the word "googlebot" into its user-agent to sneak past earns nothing. A user-agent can always be forged, so this can't *prove* identity — but it does remove the trivial "append the magic word" bypass, and it works the same on any host without needing a network lookup.

#### Verify bot identities (optional)

Signature-matching removes the *easy* spoof, but a determined scanner can still copy a crawler's full user-agent word for word. For the cases where that matters, there's an optional next step under **Settings → Block scanners & scrapers**: **Verify bot identities**.

Verification is only ever a comparison against something the bot's *operator* publishes — a claim can't vouch for itself. Operators publish two kinds of proof, and Agentimus checks whichever one exists for the bot a visitor claims to be:

- **Reverse DNS** (Googlebot, Bingbot, Applebot, DuckDuckBot, Yandex) — the method Google itself recommends, checked live per visitor:
  1. Look up the name of the visitor's IP address (a reverse, or "PTR", lookup).
  2. Check that name ends in the crawler's official domain (for example `googlebot.com`).
  3. Look that name back up to an IP address and confirm it matches the visitor.
- **Published IP ranges** (GPTBot, OAI-SearchBot, PerplexityBot — and the search engines too, as a backup when DNS can't answer) — the operator publishes the exact address ranges its crawler uses (OpenAI's `gptbot.json`, for example), and the visitor's address either falls inside them or it doesn't. Agentimus refreshes these lists **once a day in the background — never while serving a visitor** — so an unreachable publisher costs your site nothing. The lists age honestly, too: a *match* verifies against an older copy, but only a **fresh** copy may ever call a bot fake — "not in a stale list" might just mean the operator added addresses we haven't fetched yet.

Only a claim that conclusively passes is treated as the real crawler, and only one that conclusively *fails* is treated as an impostor; everything in between stays simply "not checked." Reverse-DNS results are cached briefly, so a busy crawler isn't looked up on every hit.

##### The Verified bots list is yours to edit

Below the toggle, the **Verified bots** list shows every bot Agentimus can verify and *how* (reverse DNS, IP ranges, or both). It's a registry, not a constant:

- **Switch any built-in off** — its bot simply becomes unverifiable again. Nothing is ever flagged by an absence.
- **Add a bot yourself** with **Add a bot** — when a new operator starts publishing verification data (a reverse-DNS domain, an IP-ranges file in the common `prefixes` JSON format), you can trust it the same day, without waiting for a plugin update. You give it a name, the exact token from its user-agent, and at least one source from the operator's own docs.

**It's off by default, on purpose** — the check makes a small outbound DNS lookup, and this plugin makes none unless you ask it to. It does need your visitor's *real* IP address to work, but since 1.15.0 that's handled for you in the common cases:

- **Behind Cloudflare, it just works.** Agentimus reads the real client IP out of the box, so a crawler is checked against its own address, not the proxy's. It only trusts a forwarded IP when the request genuinely arrives from a proven proxy (the direct connection is inside Cloudflare's published ranges), so a visitor can never spoof a source IP by simply sending a header. There's nothing to configure and no reason to leave verification off just because you're on a CDN.
- **Other proxies and load balancers** can be added in code with the `agentimus_trusted_proxies` filter — give it your proxy's IP ranges and the header it forwards the real client IP in, and verification works the same way there.
- **It fails open.** If DNS is slow or unavailable, or the lookup can't reach a conclusion, a legitimate crawler is *never* dropped — it stays trusted. Only a request that conclusively resolves to someone other than the engine it claims loses its always-allow. So turning this on can't accidentally lock out a real search engine.

When it's off, nothing changes: search engines are still trusted by signature exactly as before.

### Your trusted AI assistants

Below the search engines you can add your own trusted agents. The **"Add a trusted AI agent"** chips make this one click for the well-known assistants and answer engines that fetch a page on a user's behalf:

- **ChatGPT-User** and **OAI-SearchBot** (OpenAI)
- **Claude-User** and **Claude-SearchBot** (Anthropic)
- **PerplexityBot** and **Perplexity-User** (Perplexity)
- **DuckAssistBot** (DuckDuckGo)
- **MistralAI-User** (Mistral)
- **Meta-ExternalFetcher** (Meta)

Anything you add here gets the same treatment as Googlebot: never blocked, never flagged again. You can also add any user-agent by hand. Note that the training crawlers (GPTBot, ClaudeBot, and so on) are deliberately *not* offered here — those belong to the training opt-out, and trusting a crawler you may be reserving against would contradict that choice.

## Optional hard blocking (403) — off by default

Everything so far is a polite request. The **Block scanners & scrapers** section is the hard stop: when you switch it on, listed or suspicious crawlers are turned away with a `403 Forbidden` at the AI files Agentimus generates, instead of being served them.

A few things to understand before you turn it on:

- **It's off by default**, by design. A fresh install never silently blocks anyone.
- **It only guards the AI files Agentimus generates** — `llms.txt`, `llms-full.txt`, the Agentimus fallback sitemap, the `.md` Markdown versions of your pages, and the discovery documents under `/.well-known/` (`discovery.json`, `agent-card.json`, and so on). It is **not** a site-wide firewall: your normal HTML pages are untouched, and your `robots.txt` is always served so bots can still read your polite rules.
- **It's ACME-safe.** Real files sitting on disk under `/.well-known/` — such as the ACME HTTP-01 challenges that issue your HTTPS certificate, or a hand-placed `security.txt` — are streamed straight through and are *never* guarded. Turning on blocking can't break certificate issuance.

### Turning enforcement on

Enforcement has a master switch — **Deny blocked agents** — which "blocks (403) denylisted or spoofed agents at the documents above." Until this is on, everything below it is inert: your denylist can have entries and they simply do nothing.

### Blocking specific crawlers (the denylist)

With enforcement on, you list the user-agents you want refused under **Blocked user-agents**. The **"Add a known scanner"** chips pre-fill the aggressive SEO and scraper crawlers that most often ignore `robots.txt`:

> AhrefsBot, SemrushBot, DotBot, MJ12bot, BLEXBot, PetalBot, DataForSeoBot, SeekportBot, serpstatbot, ZoominfoBot

These are only *suggestions* — nothing is denied until you add it **and** enforcement is on. Each denylist entry is read in one of three ways:

- **Plain text** (the safe default) — a case-insensitive substring match. `semrushbot` matches any user-agent containing that word.
- **Glob** — an entry containing `*` or `?`, where `*` matches any run of characters and `?` matches any single one. For example `*scan*`.
- **Regex** — an entry wrapped in slashes, like `/semrushbot\/\d+/`, for precise matching.

Two built-in guards keep a slip from doing harm: an all-wildcard entry (just `*`) matches nothing rather than blocking everyone, and a regex that doesn't compile quietly falls back to a plain-text match so a typo can never break the endpoint. If a rule you enter would be broad enough to also catch real browsers or AI crawlers you want, the admin warns you and suggests something more specific.

### Auto-denying spoofed / legacy-device agents — and proven impostors

Separately, the **Auto-deny spoofed / legacy-device agents** toggle turns away bots caught lying about who they are. It covers two kinds of deception:

- **Legacy-device spoofs** — bots disguised as ancient phones: old Nokia, BlackBerry, Symbian, Java ME, Windows CE or Palm handsets. No real visitor fetches a machine endpoint from a 2004 feature phone, so these are almost always scanners hiding behind a "harmless" user-agent. They show up as **"Likely spoof/scanner"** in your activity log, and this toggle refuses exactly what the log names — one definition, so blocking and reporting never disagree.
- **Proven impostors** *(needs **Verify bot identities** on)* — a client claiming a bot from the Verified bots list whose address **conclusively failed** that operator's own published check: a fake "GPTBot" arriving from outside OpenAI's published ranges, a forged "Googlebot" whose address reverse-resolves to someone else entirely. A legacy-device spoof is *inferred*; an impostor is *proven* — same kind of deception, stronger evidence, same switch.

Impostor denial is as careful as verification itself: anything inconclusive — slow DNS, a stale or unfetched range list, no address to check — is served, never guessed at. And your own rules always outrank it: an agent on your **Always allowed** list is never denied, and a bot on your denylist stays blocked even when it verifies as genuine (verification never quietly undoes a choice you made).

This is on by the setting default, but like the denylist it has *no effect* until the master **Deny blocked agents** switch is on. It's careful, too: any user-agent that mentions "android" is treated as a modern device and never caught, so a current Nokia Android phone is never mistaken for a spoof.

### Block and allow in one click

If you keep the optional activity log on, each recorded agent has one-click **Block this client** and **Allow** buttons. "Block" adds a safe, specific user-agent token to your denylist *and* arms enforcement for you, so the "see it → block it" loop actually bites. It's careful about what it proposes: it will never offer to block a protected search engine, a generic browser, or a plain scripting tool like `curl` — only the crawler's own product name. "Allow" adds the agent to your trusted list.

### Managing your decisions ("Manage clients")

Every one of those choices — blocked, trusted, or ignored from the review queue — is a standing decision, and they're all managed in one place. Next to both the **Blocked user-agents** and **Always allowed** lists sits a **Manage clients** link; it opens a dialog with three tabs:

- **Blocked** — every denylist token, with the crawler's identity where it's a known one (*SemrushBot · Semrush*) and an **Unblock** button.
- **Allowed** — your trusted list, same treatment, with **Un-trust**.
- **Ignored** — the clients you dismissed from the review bell. This is the only place they can be seen or reversed: **Un-ignore** lets a client back into the review queue if it's still visiting.

Each row shows *when* you decided. Dates are recorded from 1.21.0 on; decisions made before that simply show none, rather than an invented one. Undoing applies immediately — there is no separate save step — and the settings lists on the screen behind update in step.

Two details worth knowing about **Ignore**: it's a "not now", not a "never" — an ignored client returns to the review bell on its own if its traffic materially grows (its volume must both double *and* grow by a burst's worth since you waved it off). And it's stored without personal data: a name token where the client declares one, otherwise a one-way hash of its user-agent string.

### What can never be blocked

To make enforcement safe to leave on, several things are *never* denied, whatever your rules say:

- **Trusted agents** — the recognised search engines and anything on your allow-list.
- **Requests with no user-agent** — too blunt a thing to block, and trivially spoofed anyway. These are still recorded as "No user-agent."
- **Real files on disk** under `/.well-known/` — ACME challenges, a hand-placed `security.txt`, and the like.

## How it all fits together

| Control | Layer | On by default? | What it does |
| --- | --- | --- | --- |
| Content-Signal (`robots.txt`) | Advisory | Yes | States your search / cite / train policy to well-behaved crawlers |
| Training-crawler blocklist (`robots.txt`) | Advisory | Yes (8 crawlers) | Names the major AI-training crawlers and disallows them |
| Sitemap advertisement | Advisory | Yes | Links your existing sitemap so agents can find your pages |
| `tdm-reservation` header | Advisory | Yes (when training blocked) | Sends the no-training signal in the HTTP response |
| `/.well-known/tdmrep.json` | Advisory | Yes (when training blocked) | Publishes the no-training reservation as a standard file |
| "noai" header | Advisory | No | Non-standard extra "don't use my text/images" hint |
| Trusted agents list | Safety net | Yes (search engines) | Names agents that are never blocked or flagged |
| Deny blocked agents (403) | Enforcement | **No** | Refuses denylisted crawlers at your AI files |
| Auto-deny spoofed agents (403) | Enforcement | **No** (needs the switch above) | Refuses bots faking obsolete devices |

The short version: leave the advisory layer on to *state* your policy clearly, and turn on the enforcement layer only when you have crawlers that ignore it and you want them actually stopped.

## For developers: filters and hooks

Every list on this page is filterable, so a theme or add-on can extend it in one line. The most useful hooks:

| Filter | Purpose |
| --- | --- |
| `agentimus_known_trainers` | The catalogue of training crawlers seeding the `robots.txt` blocklist |
| `agentimus_known_scanners` | The suggested scanner denylist chips |
| `agentimus_known_allowed` | The suggested trusted-AI-agent chips |
| `agentimus_engine_signatures` | The structured signatures for always-allowed search engines |
| `agentimus_default_allowed` | The display names of built-in trusted search engines |
| `agentimus_block_allowlist` | Extra always-allow user-agent substrings |
| `agentimus_deny_request` | The final say on whether a request is denied (layer your own policy on top) |
| `agentimus_spoof_signatures` | The legacy-device patterns used by the spoof heuristic |
| `agentimus_sitemap` | Declare a sitemap Agentimus can't detect on its own |
| `agentimus_yield_surface` | Cede a whole surface (`robots`, `llms_txt`, `markdown`, `link_headers`, …) to another producer |

For the discovery documents these controls protect — `llms.txt`, the Markdown editions, and the `/.well-known/` files — see the other pages in this User Manual.
