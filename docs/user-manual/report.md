---
title: Report
parent: User Manual
nav_order: 3.2
---

## What the Report screen answers

Every other screen answers *"how is this part of my site doing?"*. The **Report** screen answers a different question: **what happened here between two dates?**

You reach it under **More → Report**. Pick a window at the top — **Today**, **Yesterday**, **7 days**, **30 days**, or **Pick dates** for any two days you like — and the whole page re-reads for that window:

- **AI reads** — how many times AI crawlers fetched something, and which ones did it most.
- **Visits from AI** — how many people arrived from an AI answer, and which assistant sent them.
- **What acted here** — how many things a connected AI assistant did on your site.
- **In search** — clicks and impressions from the engines you have connected.
- **Citations** — whether a citation check ran in that window, and what it found.
- **Readiness score** — where your site stands.
- **One thing worth doing** — the single most valuable open item, with the button that opens it.

Every block ends in the link that opens the screen owning that detail, so the Report never becomes a second version of a screen you already have.

---

## It is the same reading as your weekly email

The Report screen and the [weekly email digest](weekly-digest.html) are built by the same code, asking the same producers. They cannot tell you two different things, because there is only one of them counting. The email is that reading posted to you once a week; this screen is the same reading, for any window, on demand.

---

## Each block says how fresh it can be

This is the part that makes the page trustworthy. A date does not mean the same thing to every number, so each block states what it can know:

| Freshness line | What it means |
|---|---|
| **live · to this minute** | Your own log answers the window exactly. Reads, visits and assistant actions are all live. |
| **reported a day or more behind** | Google and Bing publish their search data days late. The block names the newest day each engine has actually published rather than printing a zero — a zero here would read as "nobody searched", when it means "not reported yet". |
| **as of now** | Your score and the top fix describe the site as it stands, not as it stood on a date. They read the same whichever days you pick, and say so. |

---

## Your dashboard opens with today

The same reading for the day you are in sits at the top of the **Dashboard**, above the cards: what AI read, how many people arrived from an AI answer, what assistants did, each against yesterday, and one sentence naming who. **See any range →** opens the full Report screen.

Only the numbers that can honestly mean *today* appear there. Your score and the top fix are not among them — they read the same all week, so they stay where they already live: the score in the rail beside the card, the fix on [Findings](findings.html).

---

## Days here are counted in UTC

Every number on this page is counted in **UTC calendar days** — the clock your activity log is stamped in, and the same clock the daily chart and the weekly email already use.

If your site's timezone is far from UTC, there are a few hours each day when the day being counted is not the date on your own clock. When that happens the window says **"UTC day"** next to the date, and hovering it explains why. The rest of the time the two agree and nothing is added.

Why not count in your own timezone? Because the visit counter stores one row per day, with the day fixed at the moment the visit is recorded — there is no per-visit timestamp to re-file, in new rows or in the history already stored. Counting reads on one clock and visits on another would put two different days side by side in the same row. One clock for everything is the honest choice, and saying so is better than looking a day stale.

---

## A window where nothing happened

On a small site, most single days are quiet, and the page says so in a sentence rather than printing a column of zeros for you to read and discard. That is the ordinary answer, not a fault — widen the window to 7 or 30 days to see the shape of things.
