---
title: Readiness
parent: User Manual
nav_order: 6
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

## How this feeds your AEO/GEO score

These checks are the foundation of the **[AEO/GEO score](dashboard.html)** on your Dashboard. Its first three rungs *are* the three here — Findable, Readable and Trusted — and when all three are clean your site is **fully agent-ready**. The score then extends them with two more rungs: **Optimized** (the per-page work below) and **Cited** (measured on the [AI Visibility](ai-visibility.html) tab, when you turn it on).

On this tab each rung group shows a small tally like `5/5` and takes the colour of its worst check — one `FAIL` turns it red, one `WARN` amber, an all-clear group green. The single most useful **next step** is always named on the Dashboard score card. (For how the 0–100 number is blended and banded — Needs work / Fair / Strong / Excellent — see the [Dashboard page](dashboard.html).)

## Optimize your content

The three rungs above grade your *site's* setup. The fourth score rung, **Optimized**, grades your *pages* — how easy each article is for an AI to read and quote — and its worklist lives right here on the Readiness tab (click the **Optimized** rung on the Dashboard score card to jump to it).

Agentimus reads **every published page** and checks each for what makes content citable:

- **Thin content** — too little text for an agent to work with (under about 100 words).
- **Nothing concrete to quote** — no figures, dates or cited sources; answer engines lift specifics, so give them one.
- **Quotable passages** — an over-long paragraph an engine can't cleanly lift; break it up.
- **Freshness** — a substantial page untouched for a couple of years reads as stale.

Issues are grouped, and each lists the exact posts and pages affected as links straight to their editors — so fixing them is one click away. The same checks appear per-page in the editor's **Readability** panel while you write.

### Your whole site, not a sample of it

Until 1.37.0 this rung read your **25 most recently edited posts** and treated that as the site. It now reads every published page.

If you updated from an earlier version, expect the number to move. Expect the list of pages worth fixing to be longer too — often much longer. Nothing broke, and nothing about your content changed. Those pages were always there; nothing had been looking at them.

The reading happens in the background rather than while you wait, a few pages at a time. On a large site it can take from minutes to hours. While it is still going, the card says how many pages are left to read. That way you can tell "this is the whole site" from "this is what has been read so far". The oldest verdicts are also refreshed quietly over time, so a page graded months ago does not keep an answer nobody has re-checked.

**What is checked and what is graded are not the same set.** Agentimus checks every kind of content you publish — including products — for the searches each page is found for. It grades only writing for citability. The card names the kinds of content behind its numbers. A shop owner is never left wondering why a hundred products are checked and none are graded.

**Articles only.** Optimize grades the content that's *meant* to be cited — posts, pages and doc-like content. It deliberately leaves out **commerce products** (a product page is short by design), **commerce plugins' own pages** — the cart, checkout and account pages a shop plugin designates and renders — and **structural pages** with no real text of their own: your Posts page, a theme-rendered front page, a form or page-builder page, or any page that is just a shortcode or plugin block with next to no authored prose. None of those are articles, so they're never wrongly flagged as "thin".

### Set aside pages that aren't meant to be cited

Some pages simply aren't articles you're optimising for citation — a landing page, a "Thank you" page, an index. Next to any page in the worklist is **Set aside**: mark it *not cited content* and it leaves the score, moving to a visible **"Set aside"** list you can **Restore** from at any time. Nothing is deleted or changed — the page stays published exactly as it is; it just stops counting toward your Optimized rung. The section header always shows how many pages are graded and how many you've set aside, so the number stays honest.

Two conveniences keep the list workable. Each check's section carries an **Ignore All** action that sets aside *every* page that check flags in one confirmed click — including the pages past the "Showing 6 of 8" preview — useful when a whole category of content (say, photo posts with no prose) was never meant to be cited (the per-page button is **Ignore It**). And every entry in the Set-aside list shows **what it was flagged for** (*Thin content · No featured image*), so you can still judge, months later, whether a parked page deserves a second look or was rightly parked — with a **Restore All** on the list's header when you want everything back in one go. A page set aside is skipped by every check, not just the one you clicked from.

### Evergreen categories

Timeless content — references, tutorials, legal pages — doesn't go stale with age, so the freshness check shouldn't nag it. Under **Settings**, mark the categories whose posts are evergreen and their posts are exempt from the freshness check (only that check; nothing else changes).

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

Because it has to grade what an *agent* sees, Verify live fetches **anonymously** — no cookies — so from the server's side it looks like any outside visitor. Those fetches carry a short-lived token that keeps them out of your own visit log, so checking your site never inflates your agent-traffic numbers. The exposed-files scan carries it too: probing your own site for a stray `wp-config.bak` should never read as a scanner in your own records.

A companion **Agent preview** button lets you see the exact JSON-LD and Markdown Agentimus emits for your site or any single post — handy for confirming the structured data behind the Readable and Trusted rungs. It reads that data straight from WordPress, so unlike Verify live it fetches nothing and never appears in your log.

## Per-page Readability (in the editor)

The Readiness report grades the whole *site*. Its per-page companion lives in the post editor: a **Readability** panel (in the "Agentimus" box, alongside the JSON-LD preview) that grades the page you're writing. It flags what makes a single page hard to read, section and quote — thin content, missing headings, no opening summary, a nav-heavy page, or images without alt text (the featured image included) — each as a plain pass or "to improve". It's editor-only (nothing shows to visitors) and can be turned off under Settings → **Discovery** → *Readability tips*.

**It is not only for AI.** Twelve of its fourteen checks are ones a classic SEO tool runs too: thin content, headings and their order, image alt text, reading ease, outbound sources, link density, the featured image, whether that featured image is described, and freshness. An image with no alt text is invisible to an assistant, to a screen reader and to image search alike. Only the quotable-passage length and the video/audio context line are specific to AI. The panel names all three audiences on the checks where all three apply, so nothing here reads as an AI nicety you can skip.

### The featured image

Your featured image is drawn by your **theme**, not by the post content — so for a long time nothing checked it, and a picture with no description passed silently. It now has its own row.

The check asks whether the picture carries a description **of its own**, read from the media library, and it names the file so you know which one to open. It deliberately does not judge what your theme finally renders: doing that would mean fetching the page on every editor load, and it cannot be guessed either — a theme may substitute the post title inline in its template, where no filter can see it. A fallback to the post title also describes the *article*, not the *picture*, so the advice holds regardless.

The reading-ease grade is deliberately fair to real writing. Sentences end at block boundaries, so a bullet list is never scored as one enormous sentence. A page's own recurring subject terms are read as *familiar* words — "security" on a security article isn't charged five syllables every use — while a page dense with many *different* heavy words still grades hard. And code samples are never graded as prose: code still counts toward substance, but identifiers aren't scored as words, and a page that is mostly code skips the grade honestly rather than mis-scoring its few prose lines. The verdicts explain themselves, too: a pass names the terms it treated as familiar, and a warn ends by naming the page's heaviest words — so you know exactly what to simplify.
