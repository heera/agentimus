---
title: AI Visibility
parent: User Manual
nav_order: 13
---

AI Visibility monitoring is the one part of Agentimus that talks to the outside world. Everything else in the plugin runs quietly on your own site. This feature, when you switch it on and add your own AI keys, actually asks the big AI assistants questions and reports back whether they mention you, link to you, and how you stack up against your rivals — tracked over time so you can see the picture change.

It is built into this plugin — nothing extra to install — but it's **hidden until you ask for it**. Because it's the one feature that spends your own AI credit, it's an explicit opt-in: turn on **Track AI citations** under **Settings → Features** and the **AI Visibility** tab appears, and a **Cited** rung joins your [AEO/GEO score](dashboard.html). Leave it off and neither shows — your score is a clean four-rung ladder.

## What it does, in plain terms

You tell Agentimus a few things:

- **What you want to watch** — your brand, a product, or a person (for example, yourself).
- **The questions real people would type** — like "what's the best llms.txt plugin for WordPress?"
- **Which AI engines should answer** — ChatGPT, Gemini, Claude, and/or Perplexity.

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

Once **Track AI citations** is on (Settings → Features), an **AI Visibility** tab appears in the Agentimus menu. Open it. Inside that tab there are two views:

- **Results** — the scoreboard from your latest check.
- **Settings** — where you add what to track, turn on engines, paste keys, and set the schedule.

When you first arrive, the Results view will say there are no results yet and point you to Settings. That's expected — you set things up once, then run your first check.

## Step 1 — Add what you want to track

In **Settings**, the first card is **What you're tracking**. Agentimus starts you off with one item, pre-filled with your site's name and web address so a first check is meaningful straight away. For each thing you track you can fill in:

| Field | Required? | What it's for |
|---|---|---|
| **What is it called?** | Yes | The exact name you want AI to say (for example, `Agentimus`). Used to detect a mention. |
| **What kind of thing is it?** | Optional, but worth filling in | The category a buyer would shop in — "WordPress SEO plugin", "accountancy firm in Leeds". This is *not* what your site writes about; it's the market you compete in. It's what the suggested questions (below) are built from. |
| **Its website** | Optional | So Agentimus can tell when an AI links to *you* specifically. Leave blank if it has no site. |
| **Who are its rivals?** | Optional | The competing names. Agentimus shows who AI picks instead of you. |
| **What should we ask AI?** | Yes to get results | Real questions a person would type. Press Enter after each one. These are the answers that get graded. |

**The whole screen saves as you type.** There is no Save button anywhere on it — your tracked items, your engines and your schedule are all stored the moment you change them. You'll see a small "Saving…" then "Saved ✓" next to each item, and a plain warning if an item still needs a name or a question before it can be checked.

You can track several different things (up to ten), and each one keeps its own website, rivals, questions, and its own scoreboard. Add more with **+ Add another**. Each item also has an **Active / Paused** switch — pause one to leave it out of checks without deleting its setup, and turn it back on later.

Rivals and questions are added as chips: type and press Enter to add one, and click a chip to edit it. You can track up to 25 questions and up to 20 rivals per item.

### Stuck for questions? Let Agentimus suggest some

Next to the questions field are up to two buttons, and they do different things:

- **Suggest questions** builds candidates from a set of built-in templates. It's instant, free, and makes no network request of any kind — no AI is involved. It's always available.
- **✦ Suggest with AI** asks the AI provider you configured in WordPress (under **Settings → AI**) to write the questions instead. It only appears when a provider is actually set up, and it spends one AI call from your own quota. If the model comes back with nothing usable, Agentimus quietly falls back to the template questions rather than showing you an empty list.

Either way you get a row of suggestions; click one to add it as a question, and ignore the rest. Nothing is added for you.

**Both work from the category, and the AI button stays disabled until you fill it in.** That's a deliberate guardrail, not fussiness. A suggestion you accept doesn't just sit there — it becomes a question that is **graded on every single run**, forever. When suggestions were built from what a site *blogs about* rather than the market it *sells into*, they came out as things like "What is the best JavaScript?" — a question that can never legitimately name you, so it scored a permanent zero and quietly reported that "AI never mentions you". Better to ask for one short phrase than to hand you a question that is guaranteed to fail.

If you leave the category blank, Agentimus won't invent a market for you. It offers only questions built from your own name — "What is *X*?", "*X* vs *Y*" — which are honest, if less revealing.

One thing you'll notice about the AI's suggestions: they never contain your own name. That's on purpose. A question with your name already in it is guaranteed to get your name back in the answer, so it measures nothing.

## Step 2 — Turn on AI engines and add your keys

The second card is **AI engines**. Each row is one assistant. Flip the toggle to turn an engine on, then paste that engine's API key. You get a key from the provider's own site — each row has a **Get a key** link that opens the right page:

| Engine | Where you get a key | Answers from |
|---|---|---|
| **ChatGPT (OpenAI)** | platform.openai.com | What it already knows, unless you turn on live web |
| **Gemini (Google)** | aistudio.google.com | What it already knows, unless you turn on live web |
| **Claude (Anthropic)** | console.anthropic.com | What it already knows, unless you turn on live web |
| **Perplexity** | perplexity.ai settings | Always a live web search |

Each engine also lets you pick a **model**. Agentimus defaults to a fast, low-cost model for each provider so recurring checks stay cheap, and you can choose a different one from the list or type a custom model ID.

As with the tracked items, this card **saves as you type** — flipping an engine on, pasting a key or changing a model is stored straight away.

**Test before you rely on it.** Each row has a **Test** button that does one tiny round-trip to confirm the key works. A green "✓ Working" means you're set; a failure opens a readable message (often with a link to fix it, such as "add billing").

A saved key shows as dots and is kept safe on your server. Leaving the dots untouched keeps the stored key; clearing the field deliberately removes it. There's an eye icon to reveal a key if you need to copy or check it.

### "But I already gave WordPress an API key"

If you're on WordPress 7.0 and have already connected an AI provider under **Settings → AI** — the one that powers [Write with AI](write-with-ai.html) — it's fair to ask why AI Visibility makes you paste a key again. The reason is what a visibility check actually grades.

A check is graded on the **sources each engine cited**: which pages it leaned on, and whether any of them were yours. WordPress's shared connectors hand back the **answer text only** — the list of cited sources is dropped before Agentimus can read it. Getting at those sources means talking to each engine through its own API, which needs that engine's own key. So AI Visibility keeps its keys separate. They stay on your server and are used only to run your checks.

## Step 3 — Live web search (optional but recommended)

By default, most engines answer from their training — that is, what they already "remember" about you. That tells you how AI describes you off the top of its head.

Turning on **Live web** for an engine makes it actually search the live web before answering and cite the pages it used. This matters because a **live** answer's "linked your site" score reflects what AI can find about you *right now* — not just what it memorised months ago.

- **ChatGPT, Gemini, and Claude** each have a **Live web on/off** toggle. (ChatGPT's live search needs a search-capable model, such as `gpt-4.1`.)
- **Perplexity** always searches the live web — there's nothing to switch on, so it's simply marked **Live web is always on**.

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

## How this feeds the "Cited" rung

When **Track AI citations** is on, your latest check's **"Seen in answers"** rate becomes the **Cited** rung of your [AEO/GEO score](dashboard.html) — the one rung that measures a real *outcome* rather than your setup. Two things keep it honest:

- **It's dated, and it expires.** The rung notes *when* it was last checked, and a reading older than **90 days** stops counting toward the score (it's shown as a dated reference until you re-run). An old measurement never quietly stands in for a current one.
- **No key, no number.** If you remove a provider key — or haven't run a check yet — Cited reads a grey **"—"** and is simply left out of the score, its weight shared across the other rungs. Clicking the **Cited** rung on the Dashboard takes you straight here: to **Settings** when your setup isn't complete enough to run a check, or to **Results** when it is.

## Turn on automatic checks (scheduling)

Running by hand is fine, but the real value comes from tracking change over time. In **Settings → Schedule**:

- Flip **Run checks automatically** on.
- Choose **Daily** or **Weekly**.

These save on their own too — like the rest of the screen, there's no Save button to press afterwards. From then on, Agentimus runs your checks on that cadence in the background, with no clicks needed. You can still hit **Run check now** whenever you like.

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
| Show the feature | Settings → Features → **Track AI citations** | Off by default; the tab + the Cited rung appear when on |
| Run checks | Enable an engine + add a key | Bring your own key; each check spends your own credit |
| What to track | Settings → What you're tracking | Up to 10 items, each with its own name, category, site, rivals, questions |
| The category | "What kind of thing is it?" per item | The market you sell into, not what you blog about; the suggestions are built from it |
| Questions per item | Chips in each item | Up to 25 |
| Rivals per item | Chips in each item | Up to 20 |
| Suggest questions | Button under the questions field | Built-in templates: instant, free, no AI call |
| ✦ Suggest with AI | Button under the questions field | Uses the provider from Settings → AI; needs the category; spends one AI call |
| Engines | Settings → AI engines | ChatGPT, Gemini, Claude, Perplexity |
| Live web search | Per-engine toggle | Perplexity always live; others optional |
| Test a key | Test button per engine | One tiny call to confirm it works |
| Saving | Anywhere on the Settings view | Everything saves as you type — there is no Save button |
| Run now | Results → Run check now | Runs in the background |
| Automatic checks | Settings → Schedule | Daily or weekly; off until you switch it on |
| Keep history | Settings → Schedule | 7–730 days (default 180) |
| Clear results | Results → Clear | Removes stored results only, not your setup |
