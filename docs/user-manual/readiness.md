---
title: Readiness report
parent: User Manual
nav_order: 4
---

The **Readiness report** is Agentimus's checklist for one simple question: *if an AI agent visited your site right now, could it find you, read you, and trust you enough to cite you?* It runs a set of quick checks against your settings and content, grades each one, and — crucially — puts a button next to anything that needs attention that jumps you straight to the exact setting that fixes it.

You'll find it on the **Readiness** tab of the Agentimus admin screen. Nothing here changes your site; the report only *reads* your current setup and tells you where you stand.

## What the report actually does

Each check is fast and side-effect free — it looks at your settings and content, never at the outside world, and never makes a network request. That means opening the Readiness tab is safe to do as often as you like, and the results reflect exactly how Agentimus is configured this moment.

Every check produces one row with four parts:

- **A label** — the plain-English name of the thing being checked, e.g. "/llms.txt index".
- **A status** — `PASS`, `WARN`, or `FAIL` (explained below).
- **A detail line** — what the check *found* right now (the current state).
- **A fix** — shown only when something needs attention: a short, concrete instruction telling you what to change, usually with a button that takes you there.

## Reading a check: PASS, WARN, FAIL

| Status | Meaning | What to do |
|--------|---------|-----------|
| **PASS** | This part is set up correctly. Nothing for you to do. | Nothing — enjoy it. |
| **WARN** | Not broken, but you're leaving value on the table. Agents will still work, just with a weaker signal. | Worth fixing when you can. |
| **FAIL** | A real blocker. Something important is off or missing, and it undermines everything below it. | Fix this first. |

Only two checks can ever reach **FAIL** — because only two things can genuinely stop an agent cold: your site being hidden from search engines, and having no profile at all describing who owns the site. Everything else is a `WARN` at worst: a missed opportunity rather than a dead end.

Within each section, checks that still need attention float to the top, so the things worth acting on are always the first thing you see.

## The three rungs: Findable, Readable, Trusted

Agentimus groups the same checks into three plain-English **rungs** — the stages an agent climbs, in order, when it encounters your site. The rungs don't add any checks; they're just a friendlier way to frame them.

1. **Findable** — can an agent crawl and navigate your site at all?
2. **Readable** — does what it crawls come back clean, structured, and worth reading?
3. **Trusted** — can it work out who you are, trust the source, and attribute it with confidence?

The order matters: there's no point having beautiful, well-structured content (Readable) if crawlers can't reach your site in the first place (Findable). You climb from the bottom up.

Each rung shows a small tally, like `4/5`, and takes on the colour of its **worst** check — one `FAIL` turns the whole rung red, one `WARN` turns it amber, and only an all-clear rung shows green.

### Findable — can an agent reach you?

These checks confirm that crawlers and agents can actually get in and move around.

| Check | What it verifies | If it needs attention |
|-------|------------------|-----------------------|
| **Search engine visibility** | Your site is public — not set to "Discourage search engines" in Settings → Reading. | `FAIL`. This is the master switch. Until it's on, nothing else matters — agents are simply blocked. Links straight to WordPress's Reading settings. |
| **Pretty permalinks** | You're using a permalink structure (e.g. "Post name"), not plain `?p=123` links. | `WARN`. Plain links mean the tidy `/your-slug.md` markdown URLs agents prefer can't resolve. Links to WordPress's Permalinks settings. |
| **robots.txt control** | WordPress is serving a virtual `robots.txt` that Agentimus manages. | `WARN` if a static `robots.txt` file exists at your web root and overrides the plugin. Either delete it to let Agentimus manage it, or maintain it by hand. |
| **XML sitemap** | Some sitemap exists — WordPress core, a major SEO plugin, or Agentimus's own generator. | `WARN` only if *nothing* serves one. Turning on "XML sitemap" under Settings → Features generates one; Agentimus auto-detects and links a core or SEO-plugin sitemap instead of duplicating it. |
| **Sitemap in robots.txt** | Your `robots.txt` includes a `Sitemap:` line, so crawlers discover the sitemap automatically. | `WARN`. Usually resolved by enabling the sitemap and robots.txt features; a hand-maintained static `robots.txt` needs the `Sitemap:` line added yourself. |

### Readable — does your content come back clean?

These checks are about the quality of what an agent receives once it's inside.

| Check | What it verifies | If it needs attention |
|-------|------------------|-----------------------|
| **/llms.txt index** | The `/llms.txt` file is turned on — a single map of your site that crawlers and agents check first. | `WARN`. Enable "/llms.txt index" under Settings → Features. |
| **/llms.txt substance** | Your generated `/llms.txt` clears the roughly **200-word** floor agents expect — enough to actually read and cite. | `WARN` if it's thin. Add a profile sentence and 3–5 expertise topics under Identity, and publish a few pages; each flows into the file. (URLs are excluded from the word count, so links don't pad the total.) |
| **/llms-full.txt full text** | The full-text edition is on, letting an agent pull your whole corpus in one request instead of crawling page by page. | `WARN`. Enable "/llms-full.txt full text" under Settings → Features. |
| **Full-text file size** | The full-text file fits within its size budget and isn't being truncated. | `WARN` if it would spill over. Lower "Posts in /llms-full.txt" under Features, or rely on the index — agents can still fetch any page by adding `.md` to its URL. |
| **JSON-LD structured data** | Structured data (schema) is being emitted — or deliberately deferred to an SEO plugin that already does it. | `WARN` only if schema is off *and* no SEO plugin covers it. Enabling it emits WebSite, entity, and article schema. |
| **Content coverage** | Lists which content types (posts, pages, and any custom types) are being indexed. | Always `PASS` — this one is purely informational. |
| **Topics for AI** | Your published posts carry topics that tell assistants what each page is specifically about. | `WARN` if the feature is off, or if too few posts have topics and auto-fill is off. Add topics in the editor's "Topics for AI" box, or turn on "Use tags & categories by default". |

The **Topics for AI** check is a little smarter than a simple on/off. If auto-fill from tags and categories is enabled, every tagged post already carries topics, so it passes — adding your own sharper, post-specific topics just improves it. If auto-fill is off, it wants at least half your published posts to have topics set before it passes.

### Trusted — can an agent believe and attribute you?

These checks establish identity and trust — the difference between an agent quoting you confidently and ignoring you because it can't tell who you are.

| Check | What it verifies | If it needs attention |
|-------|------------------|-----------------------|
| **Author / entity profile** | You've written a profile sentence under Identity — the single highest-signal line an agent quotes when it cites you. | `FAIL`. Write one plain sentence: who you are, your role, and what the site is about. |
| **Expertise topics** | You've declared a few expertise topics that establish what you're an authority on. | `WARN`. Add 3–5 topics (e.g. "WordPress development", "API design"); they feed `/llms.txt` and the schema `knowsAbout` list. |
| **sameAs profiles** | You've linked known profiles (GitHub, LinkedIn, X…) so an agent can resolve your identity instead of guessing. | `WARN`. Add your profile URLs under Identity. |
| **security.txt contact** | A machine-readable security contact is published at `/.well-known/security.txt`. | `WARN` if it's off, or if it's on but has no contact (which makes the file invalid and nothing is served). Enable "Generate security.txt" and add a contact — your Identity email is reused automatically. |
| **AI usage policy** | If you've reserved your content from AI training, that preference is published as a hard-to-ignore signal — a tdm-reservation header and/or `/.well-known/tdmrep.json` — not just an advisory line in `robots.txt`. | `WARN` if you've reserved training but only `robots.txt` carries it. Turn on the header and `tdmrep.json` under Settings → Crawler policy. (If you *allow* training, this simply passes — there's nothing to reserve.) |

### A "not reachable" floor

If the **Search engine visibility** check fails, your site is hidden from crawlers entirely and none of the rungs above can do anything. In that case the report treats you as sitting on a floor *below* Findable and labels your standing "Not reachable" — a clear signal that making the site public is the one and only thing to fix first.

### "More checks"

If you install a Pro add-on that contributes its own checks, any that don't map to one of the three rungs appear in a trailing **More checks** group rather than disappearing. For most sites this group won't exist.

## How the score works

The report gives you your standing in two complementary ways.

### The percentage gauge

The circular gauge in the sidebar is simply **the share of all checks that pass**, rounded to a whole percent. If 14 of 17 checks pass, you're at 82%. Its colour is a quick health read:

- **80% or above** — green ("good").
- **50% to 79%** — amber ("ok").
- **Below 50%** — red ("low").

Because `WARN` and `FAIL` both count as "not passing", the percentage is deliberately strict: it nudges you toward a fully clean report, not just an absence of hard errors.

### The rung ladder

Alongside the gauge, Agentimus shows which **rung** you've reached. This is stricter still, and it's the more meaningful measure:

- A rung counts as **reached** only when *every* check in it passes.
- You climb from the bottom, so a gap low down caps you there — a complete Readable rung doesn't count if Findable still has a warning, because an agent never gets far enough to benefit.
- Your headline standing is the highest rung that's complete with every rung below it complete too.
- When all three rungs are clean, you're **fully agent-ready** ("topped").
- The sidebar also surfaces the single **next step** — the first outstanding item on the next incomplete rung — so there's always one obvious thing to do.

In short: the **percentage** tells you how tidy things are overall, and the **rung** tells you how far an agent can actually get.

## Every failing check links straight to its fix

This is the part that makes the report worth acting on rather than just reading. When a check needs attention, its fix comes with a button, and that button takes you to the precise control that resolves it — no hunting.

There are two kinds of button:

- **In-app jumps** (shown with a `→`) switch you to the right Settings section, open any collapsed panels along the way, scroll the exact field into view, and briefly highlight it so your eye lands on the control to change. For example, the **/llms.txt index** warning drops you on the "/llms.txt index" toggle under Features; the **Author / entity profile** failure drops you on the profile box under Identity.
- **External links** (shown with a `↗`) open a core WordPress screen in a new tab when the fix lives outside Agentimus — for instance, the **Search engine visibility** and **Pretty permalinks** checks link to WordPress's own Reading and Permalinks settings pages.

So the loop is always the same: read the check, click its button, change the one thing it points at, and re-run.

## Re-running and keeping it current

The report reflects your settings at the moment it's generated. A few things keep it fresh:

- **Re-run** — the button at the top of the Readiness tab regenerates the whole report on demand and reports how many checks pass.
- Saving your Agentimus settings refreshes the report automatically, so a fix you apply shows up right away.
- Because the checks are inferred from your configuration, most changes (turning a feature on, adding a profile sentence) are reflected instantly.

## Verify live: what agents actually receive

The Readiness checks reason from your *settings*. The **Verify live** button (also on the Readiness tab) does something different and complementary: it fetches your real public endpoints — `robots.txt`, `/llms.txt`, the discovery documents, a sample page's `.md`, and so on — **from your own browser**, through the public URL, and grades what actually comes back.

This catches things settings alone can't see, such as a CDN or caching layer sitting in front of your site and altering a response. It runs only when you click it, entirely in your browser (the server makes no outbound request), and shows a tally like `9/9 OK` with a line per endpoint. Think of the Readiness report as "is it configured right?" and Verify live as "is it truly being served right?"

A companion **Agent preview** button lets you see the exact JSON-LD and Markdown Agentimus emits for your site or any single post — handy for confirming the structured data behind the Readable and Trusted rungs.
