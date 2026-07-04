---
title: Exposure & hardening
parent: User Manual
nav_order: 11
---

Most of Agentimus is about *helping* AI agents and crawlers understand your site: publishing clean discovery files, describing your content, and telling assistants who you are. The **Exposure** tab is the mirror image of that. Instead of adding helpful signals, it quietly *removes* the stock WordPress details that an anonymous crawler, bot, or scanner can read but that you never chose to share — things like your list of usernames, your exact WordPress version, and a handful of legacy links most sites never use.

Think of Discovery as "here is what I want machines to know," and Exposure as "and here is what they don't need to know." Closing those low-value leaks keeps the machine-readable picture of your site tidy and deliberate.

## What the Exposure tab does

The Exposure tab gives you five independent on/off controls. Each one switches off a stock WordPress behaviour that tends to over-share with automated visitors:

| Control | Plain-language name | What it stops leaking |
|---|---|---|
| Hide your users & authors | Username enumeration | Your usernames via the REST API, the `?author=1` trick, the users sitemap, and oEmbed |
| Disable author archive pages | Author archives | The `/author/…` pages that expose a username as a URL slug |
| Hide your WordPress version | Version fingerprint | The exact WordPress version in your page source, feed, and core file links |
| Tidy page-head links | Head-link clutter | Rarely-used auto-generated links (short-link, oEmbed, RSD, Windows Live Writer) |
| Disable XML-RPC | Legacy XML-RPC | The old `xmlrpc.php` endpoint used for pingback spam and password-guessing |

You turn on only the ones you want. They do not depend on each other, and turning on more than one never causes a conflict.

## Nothing changes until you opt in

This is the most important thing to understand: **every control on the Exposure tab is OFF by default, and a fresh install changes nothing.** Agentimus does not silently alter how your site behaves. Each control only starts doing anything the moment *you* switch it on, and it only wires itself into WordPress when it's on — so a site that never visits this tab behaves exactly like stock WordPress.

Settings are read once when your site loads a page. If you flip a toggle, the change applies from the next visitor's request onward, the same as every other Agentimus setting.

## Only anonymous visitors are affected

Every Exposure control is **scoped to logged-out requests**. That means:

- A signed-in administrator (you) is never restricted.
- The block editor keeps full access — for example, the author picker in the editor still works, because it runs as a logged-in request.
- Legitimate authenticated tools that sign in to the REST API keep working.

The controls only take effect for visitors who are *not* logged in — the crawlers, bots, and scanners you're actually trying to give less to. So you can enable these confidently without worrying that you'll lock yourself, your editors, or your own site tools out of anything.

## Where to find it

1. In your WordPress dashboard, open **Agentimus → Settings**.
2. In the row of section links near the top, click **Exposure** ("Limit what your site reveals to bots & scanners").
3. Flip on the controls you want. The toggles **save automatically** — there's no separate Save button on this tab.

## The five controls, explained

### Hide your users & authors

**What it does:** stops anonymous visitors from getting a list of your account usernames. Out of the box, WordPress will happily hand your usernames to anyone who asks, through several different doors — this control closes all of them at once:

- **The REST users list.** WordPress publishes your accounts at `/wp-json/wp/v2/users` (and `/wp-json/wp/v2/users/<id>` for a single account). This control removes those two routes for anonymous callers, so an unauthenticated request no longer returns your author list. Signed-in users still get them, so the editor's author picker and any authenticated API client are untouched.
- **The `?author=N` trick.** Visiting `yoursite.com/?author=1` normally makes WordPress redirect to `/author/<username>/`, revealing the username slug. This control turns a bare numeric `?author=` request into a clean "not found" (404) *before* that redirect can fire, so the slug never leaks. A normal author archive requested by its real name is left alone.
- **The users sitemap.** WordPress's built-in sitemap includes a `wp-sitemap-users-*.xml` file listing every author's archive URL. This control drops the users section from the sitemap (its entry disappears from the sitemap index) and returns a 404 for the now-orphaned users-sitemap URL.
- **The oEmbed author.** When another site embeds one of your posts, WordPress's oEmbed response includes the author's name and profile URL. This control blanks those two fields.

**Why it matters:** knowing a valid username is the first half of a password-guessing attack. Not publishing your usernames removes an easy piece of reconnaissance. It's the strongest single control on this tab for most sites.

### Disable author archive pages

**What it does:** turns your author archive pages — the `yoursite.com/author/…` URLs — into "not found" (404) for anonymous visitors. It also catches the `?author=N` form before WordPress can redirect it to a slug.

**Why it matters:** on a lot of sites, author pages aren't really a feature — nobody browses "all posts by Jane." But the URL itself still contains the author's username slug, which is another place that name can be scraped. If you don't rely on author archives, switching them off removes that surface entirely.

This control works independently of "Hide your users & authors." You can enable either one on its own, and enabling both together is completely harmless.

**Leave this OFF if** your theme or workflow genuinely uses author archive pages (for example, a multi-author magazine where readers browse by contributor).

### Hide your WordPress version

**What it does:** removes the exact WordPress version number that stock WordPress prints in a few places:

- The `<meta name="generator" content="WordPress X.Y">` tag in your page's HTML source.
- The generator line in your RSS/Atom feeds.
- The `?ver=X.Y` cache-busting number that WordPress appends to *core* stylesheet and script URLs.

Only the value that matches your running core version is stripped from asset links. Version numbers that your **plugins and themes** add to their own files are left intact, because those are legitimate cache-busters — removing them could break caching.

**Why it matters:** vulnerability scanners often start by reading your version number, then look up which known issues affect that exact release. Not advertising the version makes that automated targeting a little harder. (This is a modest, "why hand it over" measure, not a substitute for keeping WordPress updated — which you should always do.)

### Tidy page-head links

**What it does:** removes a handful of rarely-used, auto-generated links that WordPress injects into every page's `<head>` (and into the HTTP `Link` header):

- The **short-link** (`?p=123`) link.
- The **oEmbed discovery** links.
- The **RSD** (Really Simple Discovery) link.
- The **Windows Live Writer** manifest link.

These are leftovers from old blogging tools and embed features that most modern sites simply don't use. Removing them trims the technical footprint a scraper reads from your pages.

**What it does *not* touch:** it deliberately keeps the links that *are* useful — the REST API link (`api.w.org`) and Agentimus's own discovery links, which are there on purpose. This control has **no effect on how your pages look or how they rank in search.**

### Disable XML-RPC

**What it does:** switches off the legacy `xmlrpc.php` endpoint. Specifically, it tells WordPress XML-RPC is disabled, empties out every XML-RPC method (including `pingback.ping`, `system.multicall`, and `wp.getUsersBlogs`) so the file responds but can do nothing, and removes the `X-Pingback` header that would otherwise advertise the endpoint. You don't need to touch any server configuration for this to work.

**Why it matters:** XML-RPC is an old remote-publishing interface that's rarely used today (the modern REST API replaced it). It's a well-known target for pingback-spam, denial-of-service amplification, and password-guessing attacks that try many credentials in a single request. Turning it off closes that door.

**Leave this ON (i.e. don't disable) only if** you rely on something that still needs XML-RPC — for example, an older desktop or mobile publishing app, or a specific Jetpack feature. Most sites can safely disable it.

## What the Exposure tab is *not*

Agentimus is a discovery layer, not a security plugin, and the Exposure tab is intentionally narrow. It reduces what gets *over-shared* with anonymous machines. It deliberately does **not** include:

- Login lockout or limiting failed sign-in attempts
- Two-factor authentication (2FA)
- Malware scanning
- A firewall

If you need those, use a dedicated security plugin alongside Agentimus — the two are complementary, and Exposure is happy to run next to one.

## Suggested settings for most sites

If you just want a sensible starting point and don't have special requirements:

- **Hide your users & authors** — recommended for almost every site.
- **Hide your WordPress version** — safe and recommended.
- **Tidy page-head links** — safe and recommended; no visible effect.
- **Disable XML-RPC** — recommended unless you know an app or Jetpack feature needs it.
- **Disable author archive pages** — turn on if you don't use author archives; leave off if you do.

Because everything auto-saves and applies from the next request, you can turn a control on, glance at your live site (logged out, or in a private/incognito window) to confirm all is well, and turn it back off in seconds if anything you rely on depended on that behaviour.

## Troubleshooting

**I turned something on but I still see the old behaviour.** Settings are read when a page loads, and you're likely still viewing as a logged-in admin — the controls only affect logged-out visitors. Check in a private/incognito browser window, or on a page cache that has refreshed.

**My author pages returned 404 and I wanted them.** Turn **Disable author archive pages** back off. The change applies immediately for new requests.

**A plugin or app stopped connecting after I disabled XML-RPC.** Some older publishing apps and certain Jetpack features still use XML-RPC. Turn **Disable XML-RPC** back off if you depend on one of them.

**Did this change my SEO or how my pages look?** No. Tidying head links and hiding the version number don't affect your visible pages or your search ranking — they only remove machine-facing details that most sites never use.
