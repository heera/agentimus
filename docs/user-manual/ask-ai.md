---
title: Ask AI about this post
parent: User Manual
nav_order: 25
---

A growing share of readers finish an article by asking an AI assistant about it — summarise this, check this claim, explain this part. The **Ask AI** row meets that moment on your own page: a small line of buttons after each post that opens the reader's assistant of choice with a question about *this post* already filled in.

## What a reader sees

After the post content, one quiet row: **ChatGPT, Claude, Perplexity, Google AI Mode and Grok**. One click opens that assistant with a prompt carrying the post's address. The reader can edit the prompt, or just send it.

These are plain links — no script runs on your page, nothing is loaded from any assistant, and **nothing is sent anywhere until a reader clicks**. A reader who ignores the row costs your page nothing.

## Why a site would want this

Because the assistant has to *fetch your page* to answer — and that fetch lands in your own **Request Log**, like any other agent visit. Sharing tools that offer AI buttons usually ship them blind: you never learn whether anything came back. Here the loop closes on your own site: button → assistant visit → a row in your log.

There's a quieter benefit too: the assistant answers from your actual page — your words, your framing — rather than from whatever it half-remembers about the topic.

## Your own bot policy can hide a button — on purpose

Each button is only shown when your site's own policy permits that assistant to *read* the page. The row checks your blocklists before rendering, and the Settings toggle tells you when a button is hidden and why.

In practice this matters for exactly one assistant today: **Google**. Google made a single robots token, `Google-Extended`, govern both AI *training* and Gemini/AI Mode *reading* — so a site that blocks AI training (Agentimus's default stance) has also told Google's assistant it may not read the page, and its button would only ever produce "page inaccessible." Rather than hand readers a button your own policy breaks, Agentimus hides it. Unblock Google-Extended — or add it to your always-allowed list — and the button returns.

Blocking **GPTBot** or **ClaudeBot** hides nothing: OpenAI and Anthropic fetch reader-initiated requests with separate agents (`ChatGPT-User`, `Claude-User`) that a training block never touches — the read-versus-train distinction done properly. Only an explicit block of one of those reading agents hides its button.

## Which posts carry it

Posts, by default (not pages). Developers can widen or narrow that with the `agentimus_ask_ai_post_types` filter.

Gemini is absent deliberately: it has no reliable way to prefill a prompt from a link. If one appears, it joins the row in a future release.

## Turning it off

Settings → Discovery → Features → **Ask-AI buttons**. One switch removes the row everywhere. Password-protected posts never carry it either way.
