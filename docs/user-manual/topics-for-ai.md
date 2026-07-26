---
title: Topics for AI
parent: User Manual
nav_order: 11
---

**Topics for AI** lets you say, in plain words, what each post or page is *about* — for example *llms.txt, AI visibility, structured data*. Those words are added to your content's machine-readable data so AI assistants understand the subject of the page and cite it correctly. Nothing appears on the visible page; this is purely for the AI and agent layer.

Think of it as writing a short list of the key ideas on a page and handing that list directly to the assistants that read your site. You can type the topics yourself, or let Agentimus fill them in from the post's own tags and categories.

## Why topics matter

When an AI assistant reads a web page, it has to guess what the page is really about from the words on it. That guess is often close, but not always exact. A short, deliberate list of topics removes the guesswork: you are telling the assistant, directly, "this page is about these things."

That helps in two ways:

- **Understanding** — the assistant knows the subject even before it finishes reading the body.
- **Citation** — when someone asks the assistant a question your page answers, clear topics make it more likely your page is the one that gets surfaced and named.

## What Topics for AI emits

Your topics flow into two of Agentimus's machine-readable surfaces. You never edit these by hand — Agentimus builds them for you from the topics you set.

### JSON-LD `keywords` and `about` entities

On each post, the page's structured data (JSON-LD, emitted in the page `<head>`) gains a `keywords` list of your topics, plus a set of `about` entities — one per topic — that name each topic as a proper thing rather than just a loose word. For a post with the topics *llms.txt, AI visibility, structured data*, the structured data looks like this:

```json
{
  "@type": "Article",
  "headline": "Getting started with llms.txt",
  "keywords": ["llms.txt", "AI visibility", "structured data"],
  "about": [
    { "@type": "DefinedTerm", "name": "llms.txt" },
    { "@type": "DefinedTerm", "name": "AI visibility" },
    { "@type": "DefinedTerm", "name": "structured data" }
  ]
}
```

The `keywords` line is the quick, flat list. The `about` entities are the sharper signal: each one is a named entity an assistant can reason about, and each can optionally be linked to an authoritative reference (see [Linking a topic to Wikidata](#linking-a-topic-to-wikidata) below).

### The `Topics:` line in the page's Markdown

Agentimus also publishes a plain-text `.md` version of each page (the Markdown edition an agent can fetch in a single request). Your topics appear there as a `Topics:` line near the top, right under the title and byline:

```markdown
# Getting started with llms.txt

*by Jane Doe · in Guides · <https://example.com/getting-started-llms-txt>*

Topics: llms.txt, AI visibility, structured data

...the rest of the page as clean Markdown...
```

This matters because the `.md` line reaches an agent even in cases where the JSON-LD steps aside for an SEO plugin (see [What visitors and SEO plugins see](#what-visitors-and-seo-plugins-see)). Both surfaces are built from the same single list of topics, so they can never disagree about what a page is about.

## Turning it on and choosing defaults

The feature is controlled site-wide under **Agentimus → Settings → Topics for AI**. Three controls:

| Setting | What it does | Default |
| --- | --- | --- |
| **Add topics to your content's AI data** | The master switch. When on, the "Topics for AI" box appears in the editor and topics are emitted. When off, the box disappears and no topics are emitted anywhere. | On |
| **Use tags & categories by default** | For *new* content, this decides whether topics are auto-filled from the post's own tags and categories. Anything you type in the editor always overrides this. | On |
| **Most topics per item** | A cap on how many topics are emitted per page, to keep the machine-readable data lean. Any whole number from 1 to 50. | 12 |

A note on **Use tags & categories by default**: it only sets the starting behaviour for content you haven't touched yet. Each post has its own "Also include all tags & categories" checkbox (below), and once you save a post, that per-post choice is what's used from then on — changing the site default later won't override a decision you've already made on an individual post.

## Adding topics to a post

When the feature is on, every post, page, and other AI-visible content type shows a **Topics for AI** box in the editor sidebar. Here's how it works.

### Type your own topics as chips

Start typing a topic and press **Enter** or **comma** to turn it into a chip — a small rounded tag. Each chip you added yourself has a **×** button; click it (or press **Backspace** with the input empty) to remove it. Separate several at once with commas and they all become chips.

These typed-in chips are your *manual* topics. They are explicit and they always win: if a manual topic and an auto topic have the same text, only the manual one is kept.

### Auto chips from your tags and categories

If the **Also include all tags & categories** checkbox is ticked, the box also shows the post's tags and categories as extra chips, each marked with a small **auto** label. These have no **×** button — they follow the post's tags and categories rather than being edited here. To change them, change the post's actual tags or categories.

In the block editor, these **auto** chips update *live*: add or remove a tag or category in the usual WordPress panel and the auto chips in the Topics box adjust immediately, with no save or refresh needed. So what will be emitted is always in front of you as you work. (In the older classic editor, the auto chips show the snapshot from when the editor loaded and refresh when you save.)

The **Also include all tags & categories** checkbox only appears on content types that actually have tags or categories. On a plain Page, which has none, the checkbox is hidden rather than shown as a control that would do nothing.

### Suggestions as you type

As you type in the topics box, WordPress offers autocomplete suggestions drawn from a consistent vocabulary, so your wording stays uniform instead of drifting (for example *WP* one day and *WordPress* the next). The suggestion pool is built from:

- Topics you've already used on other posts (most-used first).
- Your site's own tags and categories.
- Your declared **Expertise** topics from the Identity settings.

Reusing the same wording across pages gives assistants a cleaner, more coherent picture of what your whole site covers.

## How topics are kept clean

Agentimus tidies your topics automatically so the machine-readable data stays lean and useful. You don't have to police any of this by hand.

- **Purely-numeric junk is dropped.** A tag or category whose name is just a number or separators — like *67*, a stray ID, or a bare year used as a filing bucket — is never turned into an AI topic and never offered as a suggestion. A number like that tells an assistant nothing, and a wrong keyword is worse than a missing one. This filtering applies only to the *auto* (tag/category-derived) topics and the suggestions; a topic you type yourself is treated as deliberate and is always kept, even if it's a number.
- **Duplicates are merged.** The list is de-duplicated case-insensitively, so *Structured Data* and *structured data* won't both appear. The first spelling you used is the one kept.
- **The count is capped** at your **Most topics per item** setting (default 12). Manual topics come first, so if you fill the cap with typed topics, no auto topics are added beyond it.
- **Very long entries are trimmed** to 60 characters each, so one runaway value can't bloat the output.

## What visitors and SEO plugins see

Two things worth being clear about:

- **Nothing shows on the visible page.** Topics for AI never adds anything a human visitor sees. It writes only to the AI and agent layer — the JSON-LD and the `.md` edition.
- **It steps aside for your SEO plugin, just like the rest of Agentimus.** If an SEO plugin (Yoast, Rank Math via the SEO Framework, SEOPress, All in One SEO, and similar) is managing your site's structured data, Agentimus stands down and does not emit its own JSON-LD on the live page — so there is never a clash. Your topics still reach agents through the `.md` edition, which keeps carrying the `Topics:` line regardless. That's one reason the two surfaces exist: the plain-text edition is a reliable fallback for the subject of a page even when the JSON-LD defers.

## Linking a topic to Wikidata

The `about` entities Agentimus emits can each carry a `sameAs` link to an authoritative reference — its Wikidata or Wikipedia entry, or an official page. This is the difference between *Mercury* the planet and *Mercury* the element, or *Java* the language and *Java* the island: the link tells an assistant the exact thing you mean.

There is **no built-in Wikidata lookup or UI** in Agentimus, and that's deliberate — the plugin never guesses these links itself, because a wrong automatic match is worse than none and it makes no outside network calls. Instead, you supply a small, curated map of topic → URL.

That's a **developer / code option**: it's done with a one-line filter, not a settings screen. See the **Developer** reference for the `agentimus_topic_links` filter, and the ready-to-use drop-in at `examples/topic-links-wikidata.php` in the plugin, which you can copy into `wp-content/mu-plugins/` and edit to your own topics. If you don't set any links, topics still work fully — you simply get the topic names as entities without the extra `sameAs` reference.

## Checking your coverage

The **Readiness** report (on the Agentimus dashboard) tracks how well your published content is covered by topics — how many posts carry their own topics and whether auto-fill is on. If you'd like more of your key pages to carry topics, add a few in the editor's Topics for AI box, or turn on **Use tags & categories by default** to have new content fill them in from its tags and categories automatically.

## Quick reference

| Question | Answer |
| --- | --- |
| Where do I set topics? | In the **Topics for AI** box in the editor sidebar, per post/page. |
| Where do I turn the feature on or off? | **Agentimus → Settings → Topics for AI.** |
| Do topics appear on the visible page? | No — only in the JSON-LD and the `.md` edition. |
| What if I use an SEO plugin? | The JSON-LD defers to it; the `.md` still carries your topics. |
| Can I mix typed and auto topics? | Yes — typed topics always win over an auto topic with the same text. |
| Are number-only tags emitted? | No, auto number-only tags are filtered out; typed ones are kept. |
| How do I link a topic to Wikidata? | With the `agentimus_topic_links` filter — a developer/code option, no UI. |
