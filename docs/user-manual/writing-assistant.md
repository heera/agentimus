---
title: Writing assistant
parent: User Manual
nav_order: 11
---

# The writing assistant

The **writing assistant** turns a described idea into a complete, fully dressed draft — without leaving wp-admin and without any external AI tool. A **quill button** in Agentimus's own navigation (next to the review bell) opens it as a drawer on every Agentimus screen.

It drafts and revises; **it never publishes**. Nothing is saved to WordPress until you click **Create draft**, and going live remains a button only you press.

It also drafts to your site's own quality bar: the writing prompts embed the same AI-readability rules the editor panel grades against — quotable paragraph lengths, plain wording, concrete specifics, real inline sources — so a fresh draft aims to pass the checks it will be measured by.

{: .note }
> This is the third of Agentimus's three AI writing surfaces, and the most complete: the in-editor helpers ([Write with AI]({% link user-manual/write-with-ai.md %})) draft *fields* while you write; external agents over the [MCP server]({% link user-manual/mcp-server.md %}) operate your site from *outside*; the writing assistant writes *whole posts* from inside wp-admin.

## What you need first

The quill button lights up when two things are true:

1. **Let connected agents write** is on (Settings → Discovery, the same trust switch that governs MCP writes — the assistant uses the identical, audited write path).
2. An **AI provider** is connected in WordPress (Settings → AI, WordPress 7.0+, your own key — Agentimus never sees it).

Until then the button appears dimmed, and clicking it tells you exactly which of the two is missing and where to turn it on.

## From idea to draft

1. **Describe the post.** Write a brief in your own words — subject, angle, anything that matters ("write a post about X, practical tone, mention Y"). The more real detail you give it, the less it invents.
2. **Edit the outline.** The assistant proposes a working title and sections first. Retitle them, rewrite the per-section notes, add or remove sections — or start over. Nothing composes until the outline looks right: the outline *is* the contract for the draft.
3. **Write the article — every part at once.** The article is written in parallel: the introduction, each outline section, the closing and the title details each get their own call, and the outline screen shows every part filling in as it lands. Because each section has its own budget, long articles aren't capped by what fits in one response. A part that fails — a rate limit, a network hiccup — shows why on its row, and **Retry the failed parts** re-runs only those; even reloading the page mid-write resumes with the finished parts kept. (Editing the outline mid-way starts the article over: every part is written against the whole plan, so a changed plan means a fresh article.)
4. **Preview everything.** The full draft arrives as a preview: title, body, AI description, topics, suggested categories and tags, and the draft/pending choice. Not satisfied? **Edit the brief** (your generation is kept), refine with an instruction ("shorter intro, add a FAQ"), or draft again — every overwrite has a one-step Undo.
5. **Create draft.** Only now does anything touch WordPress. The post is created as a draft (or pending review) and the editor opens on it — real blocks, not a blob.

Your brief and preview survive a page reload or an accidental Esc — they're held in your browser for up to a week — but nothing lands in WordPress until you say so.

{: .note }
> If your WordPress defines **Content Guidelines**, the assistant follows them automatically — your brief outranks them, and they outrank the neutral default. Same rule as every other AI surface in Agentimus.

{: .note }
> The assistant also writes against the same bar the editor's **AI Readability** panel grades: a liftable opening summary, quotable paragraph lengths, plain sentences, and real cited sources. A fresh draft should open with those checks green — anything the panel still flags (a missing featured image, say) has its usual one-click fix right on the warning.

## Images: placeholders, then one click each

The assistant plans images but doesn't paste them into your text sight-unseen. A draft arrives with **alt-filled image placeholders** — empty image blocks, each already describing what belongs there — placed after the sections they illustrate:

- **Generate image from the alt text** — a button in every image block's toolbar turns its alt text into a generated image, saved to your media library with the alt text kept. Rewrite the alt, generate again.
- **Featured image (AI)** — a panel in the document sidebar drafts a hero image from the post's title.
- **The check that closes the loop** — the AI Readability panel's *No featured image* warning carries its own **Generate with AI** button, so the gap and its fix live on the same row.
- **Or use the library** — every placeholder is a normal image block; pick from your media library as always.

{: .warning }
> Image generation needs an **image-capable plan** on your provider. Free tiers often include text but not images — in that case Generate explains the situation in plain words and the library path always works.

## Ask AI on any block

Select a paragraph, heading, list or quote and open **Ask AI** in the block sidebar. Say what to change — "make this shorter", "split this up", "add a conclusion after this" — and the assistant rewrites the selection or adds blocks around it. Text it wasn't asked to change is preserved word for word, and the editor's normal undo (Ctrl+Z) brings anything back.

## Revising an existing post

The assistant's second door: pick a post, describe the change, and review the revision in the same preview before applying it.

- **Status never changes.** A published post stays published, a draft stays a draft — the assistant edits content, never visibility.
- **WordPress revisions** keep every prior version, so applying a revision is never one-way.
- **Honest refusals.** Posts built from unusual blocks, image-heavy layouts, or very long documents are declined with a reason instead of being mangled — the assistant only takes jobs it can do faithfully.

## Boundaries worth knowing

- **Draft it now** (skipping the outline) writes the whole post in one pass, which caps it at roughly long-article length. Outlined articles write section by section and don't share that cap — but the drawer's *revise* bar still rewrites the whole document, so on a very long article prefer the editor's per-block **Ask AI** for changes.
- It never publishes, regardless of the MCP publish switch — that switch governs external agents only.
- Every write runs as *you*, through the same permission-checked, audited path as everything else in Agentimus.
