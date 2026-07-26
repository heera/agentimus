---
title: Write with AI
parent: User Manual
nav_order: 12
---

# Write with AI

**Write with AI** is an optional helper that lets the AI you've connected to WordPress draft two of Agentimus's fields for you as you write — the **AI description** and **Topics for AI** — and suggest concrete fixes for the **AI Readability** warnings on a page. Every suggestion lands as editable text that you review; nothing is ever saved for you.

It's off until you connect an AI provider, so on a site without AI set up you won't see any of it.

{: .note }
> This is the **in-editor** helper: WordPress's own AI drafts these fields while *you* work in the post editor, and nothing saves until you save the post. Want AI to write or revise a *whole post* from inside wp-admin? That's the [writing assistant]({% link user-manual/writing-assistant.md %}). Letting an external agent — Claude Code, Cursor, Codex — operate your site over a connection is a third, separately opt-in feature; see the [MCP server]({% link user-manual/mcp-server.md %}) page, under *Letting agents write*.

## What you need first

Write with AI uses **WordPress's own AI Client** (WordPress 7.0 and later). You connect a provider once, under **Settings → Connectors** (also called AI Credentials), with your own API key:

- Agentimus routes every request through WordPress and **never sees or stores your key** — the key stays in WordPress's Connectors store.
- Any provider WordPress supports works — OpenAI, Anthropic (Claude), Google (Gemini), and others. Google's **Gemini has a free tier** (no credit card) if you'd like to try it at no cost; the paid providers cost only a fraction of a cent per draft.
- Each click uses **your** provider's quota or credit, so it counts against whatever plan your key is on.

Once a text-capable provider is configured, the buttons appear automatically in the post editor.

## Draft with AI — the description and topics

In the post editor:

- The **AI description** box gains a **"Draft with AI"** button. Click it and Agentimus reads the page's title and content and writes a one-sentence summary straight into the field.
- The **Topics for AI** box gains a **"Suggest with AI"** button that fills in the key topics for the page as chips.

In both cases the result drops in as ordinary, editable text (or chips) — accept it, tweak it, or clear it. It isn't saved until you save the post like any other edit.

## Fix with AI — the readability warnings

Open the **Agentimus → AI Readability** panel in the editor. Every check that's passing shows a green tick and nothing else. Every **warning** now carries a **"Fix with AI"** button that drafts a concrete fix for *that* issue:

| Warning | What the AI drafts | How you apply it |
|---|---|---|
| No opening summary | A one-sentence lead for the page | **Apply** inserts it as the first paragraph (undo with Ctrl/Cmd + Z) |
| No headings | A suggested H2/H3 outline | **Copy** and place the headings yourself |
| One long block | The long paragraph rewritten as shorter ones | **Copy** and replace the block |
| Nothing concrete to quote | Specifics you could add (a statistic, an example, a source) | **Copy** — then find and verify a real figure before you publish |
| Thin content | A short outline of what's missing | **Copy** — write the sections in your own words |
| No featured image | A featured image generated from the post's title | **Generate with AI** creates it and sets it as featured — needs an image-capable provider plan |

Only the **opening summary** and the **featured image** act in one click — one inserts a safe, additive lead paragraph, the other sets an image you can replace at any time. The rest are **copy-only on purpose**: rewriting your prose or adding a factual claim is your call, and Agentimus won't insert an unverified statistic or restructure your page for you. After you apply a fix and save, the matching warning clears on the next check.

## Good to know

- **Nothing is auto-saved.** Every suggestion is editable and only persists when *you* save the post.
- **It's grounded in your page.** Drafts are written from the page's own title and content, not invented from nowhere — but AI can still be wrong, so read before you accept, especially for facts.
- **The buttons hide themselves** when no provider is configured, so the editor stays clean on sites that don't use AI.
- **Turn it off** without removing your provider: a site can disable the assist with the `agentimus_ai_assist_enabled` filter (see the developer reference).

## Related

- [MCP server]({% link user-manual/mcp-server.md %}) — the *other* way AI writes here: an external agent creating and editing whole posts over a connection (opt-in, off by default).
- [Structured data]({% link user-manual/structured-data.md %}) — where the AI description feeds.
- [Topics for AI]({% link user-manual/topics-for-ai.md %}) — the topics the assist drafts.
- [Readiness]({% link user-manual/readiness.md %}) — the AI Readability checks the "Fix with AI" buttons act on.
