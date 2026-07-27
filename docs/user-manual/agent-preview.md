---
title: Agent preview
parent: User Manual
nav_order: 15
---

When an AI assistant or agent reads your site, it does not see your theme, your
colours, or your layout. It sees two plain, machine-readable things: a block of
**JSON-LD structured data** (a compact description of who you are and what a page
is about) and a **Markdown** version of the page's text. The Agent preview is a
window that shows you those two things exactly as an agent receives them — for
your whole site, or for any single page or post you choose — without you ever
having to dig through page source or "view source" in your browser.

Think of it as looking over an agent's shoulder. Whatever the preview shows is
what actually ships.

## What the Agent preview shows you

The preview answers one question: **"What does an AI agent get when it looks at
this?"** It has two parts:

- A **modal window**, opened from the Readiness tab, that lets you inspect the
  JSON-LD and the Markdown for the whole site or any page or post, copy either of
  them, and jump straight to online validators.
- A **read-only twin** that lives right inside the post editor, so you can glance
  at a single post's JSON-LD while you are editing it.

Both are built from the exact same code that produces your live output, so the
preview never drifts from reality. If the preview says an agent gets a certain
block of JSON-LD, that is precisely what your pages emit.

## Opening the preview

1. In the Agentimus admin, go to the **Readiness** tab.
2. In the top-right corner of the Readiness report card, click the **Agent
   preview** button. (It sits next to "Verify live" and "Re-run".)
3. A wide dialog opens titled **Agent preview**, with a picker on the left and a
   viewer on the right.

The very first time you open it, it loads the list of your pages and posts and
starts you on the **Site-wide identity** view. Press `Esc`, click the backdrop,
or use the **Close** button to dismiss it.

## Picking what to preview

The left-hand column is the picker. It always starts with one pinned entry at the
top:

- **Site-wide identity** — the schema that describes your site as a whole,
  independent of any single page. Its subtitle lists the pieces it contains:
  `WebSite · Person/Organization · Services`. This is the identity graph that
  travels with your home page.

Below the pinned entry is a **search box** and then your content, **grouped by
type**:

- **Pages** come first, then **Posts**, then any custom post types (such as
  WooCommerce products) in alphabetical order.
- Each group has a heading you can **collapse or expand**, with a count of how
  many items it holds.
- Items are listed **most-recently-edited first**, so whatever you were just
  working on is near the top.

Type in the search box to filter the list by title; the list refreshes shortly
after you stop typing. The picker shows up to 50 matching items at a time, so if
you have a large site, search is the quickest way to find a specific page.

### Status badges in the list

Content that is not publicly published yet is tagged with a small badge so you
know its state at a glance:

| Badge       | What it means                                   |
| ----------- | ----------------------------------------------- |
| `Draft`     | Saved but not published                         |
| `Pending`   | Submitted for review, not published             |
| `Private`   | Published privately (visible to logged-in staff)|
| `Scheduled` | Set to publish automatically at a future date   |

Published items carry no badge. You can still preview any of the badged items —
see [Drafts and scheduled posts](#drafts-and-scheduled-posts-a-preview-of-whats-coming)
below for what you get.

## The two formats: JSON-LD and Markdown

Above the viewer are two tabs — **JSON-LD** and **Markdown** — because those are
the two things an agent actually receives for a page.

- **JSON-LD** is the structured-data block Agentimus places in the page's HTML
  `<head>`. For a page or post it also shows small **type chips** (for example
  `WebSite`, `Article`, `Person`) so you can read the shape of the data at a
  glance before scrolling through it.
- **Markdown** is the clean text twin of the page — what an agent gets when it
  asks for the page as Markdown, served at the page's address with `.md` on the
  end.

The **Site-wide identity** target has a Markdown twin too, but a different kind:
switch to the Markdown tab while the site is selected and you get the **site
index** — the same document Agentimus serves at your home address (add `.md` to
it) and at `/llms.txt`. It is built from your site identity plus the list of your
pages and posts, not from any single page's body, so it reads as a map of the
site rather than one page's text. Every other target shows that page's own
Markdown.

## Reading the status banner

Just above the code, a coloured banner tells you whether what you are looking at
is actually live right now, and if not, why. There are three cases:

- **Green ("live") banner.** For JSON-LD it reads *"This is live in your page's
  HTML head right now."* For Markdown it reads *"This is served at the page's .md
  URL right now."* This is the reassuring case: the preview matches what visitors
  and agents get today.
- **Amber ("turned off") banner.** The relevant feature is switched off under
  **Settings → Features**. The preview still shows you the full output as a
  *preview of what would ship if you switched it on*.
- **Blue ("SEO plugin") banner.** An SEO plugin currently owns your site's
  schema, so Agentimus deliberately stands down on the live page to avoid
  emitting duplicate structured data. The banner explains this, and the preview
  shows *what Agentimus would emit instead* if it were in charge.

This is the whole point of a preview: it answers "what **would** I emit?", not
just "what am I emitting this second?" (More on that in the next section.)

## Copying and validating

Every view has a **Copy** button in the top-right of the viewer — labelled
**Copy JSON** or **Copy Markdown** depending on the active tab. Click it and the
whole block is copied to your clipboard; the button briefly changes to
**Copied ✓** to confirm. (If your site runs on plain `http://` rather than
`https://`, copying falls back to an older method automatically, so it still
works.)

Below the code you may see one or more links, depending on the target:

- **Open live page ↗** — opens the actual page in a new tab. Shown whenever the
  target has a public address (the site, or a published, non-protected post).
- **Open live .md ↗** — on the Markdown tab, opens the served `.md` version of a
  published page.
- **Google Rich Results test ↗** and **Schema.org validator ↗** — on the JSON-LD
  tab, these send the page's public URL to the two industry-standard validators.
  They only appear when Agentimus is actually emitting the schema on a public,
  published page, because a validator that reads a URL needs something live to
  read.

When those URL-based validators would not work — for example the feature is off,
an SEO plugin owns the schema, or you are looking at a draft that has no public
URL yet — the preview instead shows a short hint: **copy the JSON and paste it
into the Schema.org validator's "Code" tab.** That way you can always validate
the exact structured data, even before it is live.

## It shows what *would* ship — even when output is off

This is the feature that surprises people, so it is worth stating plainly:

> The Agent preview always shows you the full output, **regardless of whether
> your live pages are currently emitting it.**

That covers two situations:

- **The feature is switched off.** If JSON-LD or Markdown delivery is turned off
  under Settings → Features, your live pages emit nothing — but the preview still
  builds and displays the complete output, clearly marked as a preview. This lets
  you see exactly what you would gain before you flip the switch on.
- **An SEO plugin owns your schema.** Many sites already run an SEO plugin that
  outputs its own structured data. Agentimus detects this and steps aside on the
  live page so there are no duplicates. The preview still shows you what
  Agentimus *would* contribute, so you can compare and decide.

In both cases the banner tells you the live page is empty and why, so you are
never confused about what is actually happening versus what is possible.

## Password-protected content stays hidden

If a page or post is **password-protected**, its body is never exposed to agents
— not as JSON-LD and not as Markdown — and the preview honours that exactly:

- On the **JSON-LD** tab you see only the **site-wide identity** nodes, never the
  page's own content, with a note explaining: *"This is password-protected, so
  its content is never exposed as schema — only the site-wide identity below."*
- On the **Markdown** tab you get nothing at all, with a note saying the content
  is never served as Markdown.

This holds true even if the post is otherwise published. A protected body stays
private, and the preview proves it. This is a privacy guarantee, not a setting
you can toggle.

## Drafts and scheduled posts: a preview of what's coming

You can select a draft, pending, private, or scheduled post from the picker, and
the two formats behave a little differently — for good reason.

- **JSON-LD** shows you the per-post node the post **will emit once it is
  published**. It is clearly flagged as a look-ahead, with a note: *"Not
  published yet — this is a preview of the per-post schema that will ship once you
  publish. It isn't in your live page's `<head>` yet."* So you can check and
  refine a post's structured data before it ever goes live.
- **Markdown**, by contrast, is genuine served content rather than a look-ahead,
  so an unpublished post has none yet. The Markdown tab tells you plainly:
  *"This isn't published, so no Markdown is served for it yet."*

Because a draft has no public web address, the URL-based validators and the
"Open live page" link are hidden for it — but you can still copy the JSON and
validate it by pasting, exactly as described above.

## The matching preview in the post editor

You do not have to open the Readiness tab every time. When you are editing a page
or post, Agentimus adds a read-only box lower down the editing screen titled
**JSON-LD Preview (Agentimus)**. It is the in-context twin of the modal, focused
on the one post you are working on.

It shows:

- A **status line** telling you what the live page is doing — either *"This is
  emitted in the page's `<head>`."* or, if an SEO plugin owns your schema, a note
  that Agentimus stands down and *"This is what Agentimus would emit instead."*
- The same **notes** for password-protected and not-yet-published content that
  the modal uses.
- The **pretty-printed JSON-LD** graph itself.
- A **Copy JSON** button.
- **Google Rich Results test** and **Schema.org validator** links — shown only
  when the post is published, not password-protected, and Agentimus (not an SEO
  plugin) is the one emitting the schema.

A few things to know about the editor box:

- It is **read-only** — it reports, it does not let you edit the schema directly.
- It **reflects the saved version** of the post. A small line reminds you:
  *"Reflects the saved version — save to refresh."* So if you have just changed
  the title or content, save the post to see the box update.
- It appears **only when the JSON-LD feature is switched on**, and only on the
  post types Agentimus is set to cover, so it never advertises output that is not
  happening.
- It shows JSON-LD only. For the Markdown twin and the whole-site view, use the
  Agent preview modal on the Readiness tab.

## Agent preview vs. Verify live

The Readiness tab has two buttons that sound similar but do different jobs:

| | Agent preview | Verify live |
| --- | --- | --- |
| **What it shows** | What Agentimus *would* build and ship | What your public URLs *actually* return right now |
| **Where it's built** | On the server, from your current settings and content | Fetched from your browser through the real public URLs |
| **Sees a CDN / caching layer?** | No — it shows the source output | Yes — it reflects whatever is actually served, including a CDN |
| **Best for** | Inspecting and copying exact JSON-LD / Markdown, checking drafts | Confirming the live site really serves what you expect |

Use **Agent preview** to read and copy the precise output, including for content
that is off, owned by an SEO plugin, or still a draft. Use **Verify live** to
confirm that your published site is genuinely delivering it end to end.

## Quick reference

| Situation | JSON-LD tab | Markdown tab |
| --- | --- | --- |
| Site-wide identity | Full identity graph, live if schema is on | Site-index Markdown (served at `/index.md` & `/llms.txt`) |
| Published, normal post | Full per-post graph, marked live | Served `.md` text, marked live |
| Feature turned off | Full preview, amber "turned off" banner | Full preview, amber "turned off" banner |
| SEO plugin owns schema | What Agentimus would emit, blue banner | Not affected (Markdown is separate) |
| Password-protected | Site identity only, note explains | Nothing served, note explains |
| Draft / scheduled | Preview of the future per-post node | Nothing yet — not published |

Everything the preview shows is generated by the very same code that produces
your live output, so you can trust it as an exact mirror — before, during, and
after you publish.
