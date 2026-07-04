---
title: AI Visibility
parent: User Manual
nav_order: 12
---

AI Visibility monitoring is the one part of Agentimus that talks to the outside world. Everything else in the plugin runs quietly on your own site. This feature, when you switch it on and add your own AI keys, actually asks the big AI assistants questions and reports back whether they mention you, link to you, and how you stack up against your rivals — tracked over time so you can see the picture change.

It is built into this plugin. There is nothing extra to install. It is simply switched off until you decide to use it.

## What it does, in plain terms

You tell Agentimus a few things:

- **What you want to watch** — your brand, a product, or a person (for example, yourself).
- **The questions real people would type** — like "what's the best llms.txt plugin for WordPress?"
- **Which AI engines should answer** — ChatGPT, Perplexity, Gemini, and/or Claude.

Agentimus then asks each engine each of your questions and reads the answers. For every answer it records three things:

- **Mentioned** — did the answer name you at all?
- **Linked** — did the answer point to your website (either in the text or in its cited sources)? This is the strongest signal, because it means an AI is actively sending people to you.
- **Rank** — when your rivals also show up in the same answer, who gets named first?

It repeats this on a schedule you choose, so you build a history and can watch whether your visibility is climbing or slipping.

## Important: it is off by default and uses your own keys

Two things are worth understanding before you turn it on:

- **It is the only feature that makes an outbound request.** Out of the box Agentimus sends nothing anywhere. This feature is the single exception, and it only ever contacts the AI engines you explicitly enable, only when a check actually runs.
- **You bring your own API key ("BYOK").** Agentimus does not include AI access. You sign up with each AI provider you want to use, get an API key from them, and paste it in. Each check spends a small amount of your own credit with that provider. Because it costs *your* money, Agentimus will never start running automatic checks on your behalf — you have to switch that on yourself.

Your keys are stored on your own server, are used only to run your checks, and are never shown back to the browser unless you deliberately click to reveal one.

## Where to find it

Open **Agentimus** in your WordPress admin menu and click the **AI Visibility** tab. Inside that tab there are two views:

- **Results** — the scoreboard from your latest check.
- **Settings** — where you add what to track, turn on engines, paste keys, and set the schedule.

When you first arrive, the Results view will say there are no results yet and point you to Settings. That's expected — you set things up once, then run your first check.

## Step 1 — Add what you want to track

In **Settings**, the first card is **What you're tracking**. Agentimus starts you off with one item, pre-filled with your site's name and web address so a first check is meaningful straight away. For each thing you track you can fill in:

| Field | Required? | What it's for |
|---|---|---|
| **What is it called?** | Yes | The exact name you want AI to say (for example, `Agentimus`). Used to detect a mention. |
| **Its website** | Optional | So Agentimus can tell when an AI links to *you* specifically. Leave blank if it has no site. |
| **Who are its rivals?** | Optional | The competing names. Agentimus shows who AI picks instead of you. |
| **What should we ask AI?** | Yes to get results | Real questions a person would type. Press Enter after each one. These are the answers that get graded. |

Everything here **saves as you type** — there's no separate save button for this card. You'll see a small "Saving…" then "Saved ✓" next to each item, and a plain warning if an item still needs a name or a question before it can be checked.

You can track several different things (up to ten), and each one keeps its own website, rivals, questions, and its own scoreboard. Add more with **+ Add another**. Each item also has an **Active / Paused** switch — pause one to leave it out of checks without deleting its setup, and turn it back on later.

Rivals and questions are added as chips: type and press Enter to add one, and click a chip to edit it. You can track up to 25 questions and up to 20 rivals per item.

## Step 2 — Turn on AI engines and add your keys

The second card is **AI engines**. Each row is one assistant. Flip the toggle to turn an engine on, then paste that engine's API key. You get a key from the provider's own site — each row has a **Get a key** link that opens the right page:

| Engine | Where you get a key | Answers from |
|---|---|---|
| **ChatGPT (OpenAI)** | platform.openai.com | What it already knows, unless you turn on live web |
| **Perplexity** | perplexity.ai settings | Always a live web search |
| **Gemini (Google)** | aistudio.google.com | What it already knows, unless you turn on live web |
| **Claude (Anthropic)** | console.anthropic.com | What it already knows, unless you turn on live web |

Each engine also lets you pick a **model**. Agentimus defaults to a fast, low-cost model for each provider so recurring checks stay cheap, and you can choose a different one from the list or type a custom model ID.

**Test before you rely on it.** Each row has a **Test** button that does one tiny round-trip to confirm the key works. A green "✓ Working" means you're set; a failure opens a readable message (often with a link to fix it, such as "add billing").

A saved key shows as dots and is kept safe on your server. Leaving the dots untouched keeps the stored key. Clearing the field and saving deliberately removes the key. There's an eye icon to reveal a key if you need to copy or check it.

## Step 3 — Live web search (optional but recommended)

By default, most engines answer from their training — that is, what they already "remember" about you. That tells you how AI describes you off the top of its head.

Turning on **Live web** for an engine makes it actually search the live web before answering and cite the pages it used. This matters because a **live** answer's "linked your site" score reflects what AI can find about you *right now* — not just what it memorised months ago.

- **ChatGPT, Gemini, and Claude** each have a **Live web on/off** toggle. (ChatGPT's live search needs a search-capable model, such as `gpt-4.1`.)
- **Perplexity** always searches the live web — there's nothing to switch on, so it's simply marked **Always live web**.

Live checks take longer and can cost a little more per question, because the engine runs a real search behind the scenes. Agentimus gives those checks extra time so they don't cut off early.

## Step 4 — Run your first check

Go back to the **Results** view and click **Run check now**. Because a full check makes many calls one after another (more so with live web search on), it runs **in the background** — the button shows "Running…", you're free to keep working, and the scoreboard fills in on its own when it finishes. While it runs, your previous complete results stay on screen rather than flickering with half-finished numbers.

If nothing can run yet, Agentimus tells you immediately instead of pretending to work — for example, "Add at least one question in Settings first" or "Enable at least one engine and add its API key in Settings first."

## Reading the results

Once a check completes, the Results view leads with a plain-English sentence such as *"AI named Agentimus in 6 of 8 answers, and linked your site in 2 of them."* Below that are four headline cards:

- **Seen in answers** — the share of answers that named something you track (your overall visibility score).
- **Linked your site** — the share of answers that pointed to your website. The strongest sign.
- **Mentions** — how many answers named you, out of how many checks ran.
- **Errors** — checks that didn't finish (for example, a bad key or a rate limit). These are shown so a failure never quietly drags your score down.

Under the cards, **Visibility over time** draws a simple trend line across your recent completed checks, so you can see at a glance whether you're being mentioned more or less often as time goes on. A couple of checks are needed before the line appears.

### Per-item breakdown

Each thing you track then gets its own section showing:

- **Seen in answers**, **Linked its site**, and **Rank vs rivals** for that item.
- **Who AI names — you vs rivals** — share-of-voice bars comparing how often you were named against each competitor, so you can see who's winning the conversation.

### Question by question

Every question is listed with a small tag per engine so you can see exactly how each assistant answered:

- **cited** — named you *and* linked your site.
- **mentioned** — named you.
- **absent** — didn't name you.
- **error** — the check didn't complete (the reason is shown right below).

Answers that used a live web search carry a small **web** marker, and Agentimus lists the **actual pages each engine cited** as links — so you can see precisely where an AI is getting its information about you. This is often the most useful part: it tells you which of your (or a rival's) pages the AI is leaning on.

## Turn on automatic checks (scheduling)

Running by hand is fine, but the real value comes from tracking change over time. In **Settings → Schedule**:

- Flip **Run checks automatically** on.
- Choose **Daily** or **Weekly**.

From then on, Agentimus runs your checks on that cadence in the background, with no clicks needed. You can still hit **Run check now** whenever you like.

A few sensible guardrails apply:

- Automatic checks stay **off until you turn them on**, because each run spends your own AI credit.
- Even when switched on, Agentimus won't schedule anything until there's actually something to do — that means the master switch is on, at least one engine is enabled *and* keyed, and at least one active item has a question. A half-configured setup quietly schedules nothing rather than burning calls on an empty run.
- You can **pause a single item** or **turn off the whole schedule** at any time without losing your setup.

## Keeping and clearing your history

Under Schedule, **Keep history (days)** controls how long results are stored before Agentimus trims the oldest ones (anywhere from 7 to 730 days; the default is 180). This applies to every check, manual or automatic.

On the Results view, **Clear** wipes all stored results for the site if you want a clean slate. Your settings — items, keys, schedule — are untouched; only the past results are removed.

## Costs, keys, and privacy

- **Costs are yours and are small but real.** Each check is one question sent to one engine. If you track 3 questions across 2 engines, that's 6 calls per run. Live web searches cost a little more than plain answers. Agentimus paces the calls slightly and backs off politely if a provider says you're going too fast, so a run won't hammer your account.
- **Your keys never leave your server.** They're stored in your own database, sent only to the matching provider, masked when the settings screen loads, and revealed only when you explicitly click the eye icon.
- **Results are stored locally**, in your own site's database — nothing is sent to Agentimus or anyone else.
- **The providers' own terms apply** to the questions you send them. Their terms and privacy links are listed in the plugin's readme under *External services*: OpenAI, Perplexity, Google (Gemini), and Anthropic.
- **Removing the plugin cleans up after itself** — uninstalling Agentimus deletes the results table, the saved settings (including keys), and cancels the scheduled checks.

## Running many sites (multisite)

If you run a WordPress network, there's a **AI Visibility** page in the **Network Admin** menu that rolls every site's latest numbers into one table — each site's brand, visibility score, citation rate, engines in use, and last run — with an average across the network. Each site still keeps and configures its own data; the network screen is a read-only overview with an **Open** link to jump into any site's own settings.

## An honest note on what this can and can't do

It's worth being straight about this, because plenty of tools aren't. This feature measures reality — it does **not** manufacture it.

- Detection is deliberately simple and transparent: a "mention" means your name or domain literally appears in the answer, and a "link" means your site shows up in the text or the cited sources. It's an honest floor, not a courtroom verdict, so a result is always explainable.
- Whether an AI *spontaneously* recommends you for a broad question comes down to authority and reputation, earned over time through genuinely useful content that others reference. No plugin can conjure that, and anything promising "instant AI visibility" is overselling.

Where Agentimus genuinely helps is making sure that when an AI *does* look at your site, it finds and understands you correctly — that's what the rest of the plugin (Markdown delivery, structured data, llms.txt, the discovery document, Topics for AI) is for. AI Visibility monitoring is the mirror that shows you whether that work is paying off. Pair it with the Readiness report to fix what's missing, and the Agent activity log to see which AI crawlers are actually visiting.

## Quick reference

| Setting | Where | Notes |
|---|---|---|
| Turn the feature on | Enable an engine + add a key | Off by default; nothing runs until configured |
| What to track | Settings → What you're tracking | Up to 10 items, each with its own name, site, rivals, questions |
| Questions per item | Chips in each item | Up to 25 |
| Rivals per item | Chips in each item | Up to 20 |
| Engines | Settings → AI engines | ChatGPT, Perplexity, Gemini, Claude |
| Live web search | Per-engine toggle | Perplexity always live; others optional |
| Test a key | Test button per engine | One tiny call to confirm it works |
| Run now | Results → Run check now | Runs in the background |
| Automatic checks | Settings → Schedule | Daily or weekly; off until you switch it on |
| Keep history | Settings → Schedule | 7–730 days (default 180) |
| Clear results | Results → Clear | Removes stored results only, not your setup |
