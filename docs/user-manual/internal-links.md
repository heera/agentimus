---
title: Link to your own posts
parent: User Manual
nav_order: 14
---

Posts that link to each other help everyone who reads your site: a human finds the next thing worth reading, a search engine understands which pages matter and how they relate, and an AI assistant traversing your site discovers pages it would otherwise miss. Internal links are one of the oldest, cheapest wins in publishing — and one of the easiest to forget while writing.

The **Link to your own posts** box does the remembering for you. While you edit a post, it suggests which of your *own* published posts this one should link to, shows you why, and inserts the link with one click — as an ordinary edit you can undo.

## Where to find it

In the post editor, look at the sidebar: the box sits directly beneath Agentimus's Search & AI box, so the two read as one group. Open it and click **Suggest links**. (On a brand-new post, save a draft first — the suggestions work from your saved text.)

You'll get up to **three** suggestions. That's a design choice, not a limit of the method: three good links beat ten plausible ones, and a post stuffed with internal links serves nobody.

## How suggestions are found — no AI required

Finding related posts is deliberately *not* an AI job. Your site already holds the signals that matter, and Agentimus reads them directly:

- **Shared [topics](topics-for-ai.html)** — the strongest signal. Two posts you've both tagged with *sourdough starters* are related by your own declaration.
- **Shared categories and tags** — the same idea, using WordPress's built-in taxonomies.
- **Title wording in your text** — when another post's title words actually appear in what you've written, that's a natural place for a link to live.

A candidate needs to score on more than a single generic word to appear at all — one shared common word is noise, not a relationship. Each suggestion shows its reasoning in plain words: *"You both cover: sourdough starters, hydration."*

Because this is all local, it's instant, free, and **works on sites with no AI provider configured at all**. Nothing leaves your server to produce a suggestion.

## The one optional AI touch

If your site has an AI provider connected (the same one the [writing assistant](writing-assistant.html) uses), Agentimus makes **one** request per suggestion round to dress the results: the AI picks the best short phrase from your article to turn into each link, and writes a one-line reason. It goes through the same governed path as every Agentimus AI feature — rate-limited, subject to the provider's content guidelines — and it's never trusted blindly: **if the phrase the AI proposes doesn't appear word-for-word in your text, it's discarded** and the locally-found phrase is used instead. No provider, no problem — you get the same suggestions with locally-chosen phrases.

## Inserting a link

Each suggestion comes with one of two buttons, and the box is honest about which you're getting:

- **Insert** — when a suitable phrase exists in your text (the row says *"Links the phrase …"*), clicking it wraps that phrase, right where it already sits, in a link to the suggested post. It's a plain block-editor edit: **undo works** (one Ctrl-Z / Cmd-Z), nothing is saved until you save the post, and no marker or shortcode is involved — just a normal link, as if you'd typed it.
- **Add "See also"** — when no good phrase exists in the text, forcing one would read badly. Instead, the button appends a simple *See also:* line with the link at the end of the post. Same rules: ordinary edit, ordinary undo.

In the classic editor, inserting isn't available — the box shows the address to copy so you can place the link by hand.

Suggestions you don't want, **Dismiss** clears from the list. Nothing is stored about a dismissal; it's a "not this time", not a rule.

## The MCP twin

Connected AI tools get the same capability through the [MCP server](mcp-server.html): a read-only tool, `suggest-internal-links`, that returns the same suggestions for any post. Two properties worth knowing:

- **It always uses the local finder** — an agent asking for suggestions never spends your AI provider quota.
- **It's read-only.** An agent that wants to *act* on a suggestion goes through the governed `update-content` write tool like any other edit — there is no side door that writes.

So an agent can audit your whole site's internal linking ("which posts should link to each other and don't?") and then, only with the write switch on, actually make the edits — each one recorded in [Agent Access](agent-access.html) like every other write.

## Turning it off

There's no settings card for this feature — it's a tool you invoke, not something the plugin emits on its own, so there's nothing running that needs a switch. If you want the box and the MCP tool gone anyway, a one-line filter removes both:

```php
add_filter( 'agentimus_internal_links_enabled', '__return_false' );
```
