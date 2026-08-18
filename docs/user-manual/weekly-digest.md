---
title: Weekly email digest
parent: User Manual
nav_order: 4
---

Once a week, Agentimus emails you a short note about what AI did on your site. Everything the plugin watches — crawler visits, readers arriving from AI answers, impostors turned away, your readiness score — only speaks when you open the dashboard; the digest is the one surface that comes to you. It exists because the plugin's work is otherwise invisible: a site can be read by agents every day without its owner ever seeing a trace of it.

It's on by default, built entirely from the data already stored on your site, and sent with WordPress's own mail function. The email to your own inbox is the only thing that leaves the server — there are no images, no tracking pixels, and no outside service involved.

## What's in the email

The subject line carries the headline — *"Your site's AI week: 143 agent reads, score 84"* — and the body walks through, top to bottom:

- **Agent visits**, this week against the week before, broken down by client (GPTBot, ClaudeBot, PerplexityBot and so on), so a change in who's reading you is visible at a glance.
- **Readers arriving from AI answers** — humans who clicked through to your site from ChatGPT, Perplexity and friends. Usually the most motivating number in the email.
- **Impostor requests turned away** — clients that claimed to be a known crawler and failed verification.
- **Your readiness score**, with its change since the last digest — "84, up from 78" always means "up from what we told you last time".
- **One thing worth doing** — the current top suggestion from your [Readiness report](readiness.html), never a list.

A footer links to the dashboard and carries a one-click stop link.

Comparisons work from a small snapshot stored at each successful send. The first digest has no previous note to compare against, and says so plainly rather than inventing a delta.

## When it arrives

Under **Settings → Weekly email** you pick the day and the hour, in your site's own timezone; new installs default to Monday morning. The schedule rides WordPress's own task system (WP-Cron), which runs when your site gets a visit — so on a very quiet site the email may arrive a little after the chosen hour, with the first visit that follows it. At a weekly cadence that lateness is harmless.

**A quiet week sends nothing.** If there were no agent visits, no AI referrals, no access-log activity and the score didn't move, there is no email — the digest never manufactures news, and silence genuinely means nothing happened.

The activity window is honest about your retention setting: the digest reports at most the last 7 days, and if you keep less than that, it reports the window you actually keep and labels it accordingly.

## Stopping it, testing it

Every email ends with a **one-click stop link** — it works without logging in, is single-use, and turns the digest off on the spot. The same toggle lives under Settings → Weekly email, where you can also change the recipient (it defaults to the site admin address) and send yourself a **test email** right now. The test ignores the quiet-week rule — its job is to prove your site can deliver mail — and doesn't disturb the score comparisons.

## If it never arrives

Three things to check, in order: your spam folder; whether the week was genuinely quiet (see above); and whether your site can send email at all — WordPress's built-in mail is unreliable on many hosts, and an SMTP plugin (WP Mail SMTP, FluentSMTP or similar) fixes that for every email your site sends, not just this one. The test button surfaces delivery errors directly, so it's the fastest way to tell "quiet week" apart from "mail is broken".

The digest can also be sent somewhere other than your inbox. Under [Integrations](integrations.html), *Weekly digest sent* is one of the moments you can forward to Telegram, Slack, Discord, a Google Sheet or an address of your own.
