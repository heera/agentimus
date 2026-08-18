---
title: Integrations
parent: User Manual
nav_order: 4.5
---

Agentimus watches your site quietly. Most of what it notices — a new finding, a crawler pretending to be Googlebot, your robots rules changing under you — only speaks when you open the dashboard. **Integrations** is how it reaches you instead: the same moments, sent to the place you already look.

Everything here is off until you switch it on, and every service stays yours. Your bot, your channel, your sheet, your address. Agentimus holds no account of its own and brokers nothing through a third party.

## The moments you can send

Six things can be reported. You choose which ones per service, so Slack can carry everything while Telegram carries only what would get you out of your chair.

| Moment | What it means |
| --- | --- |
| **New finding** | Something new needs your attention. Checked once a day. |
| **Weekly digest sent** | The weekly email went out; this carries its numbers. |
| **Impostor caught** | A client pretending to be a known crawler failed its check. |
| **Robots policy changed** | The rules in `robots.txt` moved — by you, or by another plugin. |
| **Citation check finished** | A citation monitoring run completed, with its counts. |
| **Agent wrote content** | An AI assistant created or edited a post or page. |

## How delivery works

Nothing is sent while someone is waiting for a page. When a moment happens, Agentimus adds a row to a short queue and asks WordPress to run it about **thirty seconds later**. Delivery happens on that scheduled run, never inside the request that caused it.

A failed delivery is retried three times, backing off a minute, then five, then fifteen. After that the row is kept and marked failed, with the reason — a dead webhook is a fact worth seeing, not a thing to hide.

**This depends on WordPress's scheduler.** On a site behind a full-page cache or CDN, WordPress may go a long time without running scheduled work. Nothing reaches PHP, so nothing triggers the scheduler. If your reports go quiet, loading any admin page is usually enough to start the queue moving. Agentimus tells you when it can see that scheduled work has stalled rather than letting the silence look like calm.

## The services

Each card walks you through its own setup, and every connection is **tested before it is saved**. If the test fails, nothing is stored — you are not left with a connection that looks configured and delivers nothing.

### Telegram

Messages from a bot you own, to you or to a channel.

Open [@BotFather](https://t.me/BotFather) in Telegram and send `/newbot`. It gives you a **token** — that is what you paste in. Then **message your new bot once** and press Start: Telegram only lets a bot write to someone who has spoken to it first. For the chat id, message [@userinfobot](https://t.me/userinfobot) and it replies with your number.

To send to a channel instead, make the channel public, claim its `t.me/` link, and add your bot as an administrator with **Post Messages** allowed.

### Slack

Events into a channel, where the team already looks.

Create an app at [api.slack.com/apps](https://api.slack.com/apps), choose **your own workspace** — a community workspace you merely belong to will not let you install an app — then turn on **Incoming Webhooks** and add one to a channel. Copy the URL it gives you.

### Discord

The same events, posted to a server you run.

You need a server you **administer**; one you merely joined will not let you make a webhook. In the channel you want: **Edit Channel → Integrations → Webhooks → New Webhook**, then **Copy Webhook URL**. The [web app](https://discord.com/channels/@me) does all of this if you would rather not install Discord.

### Google Sheets

Events appended as rows — a history that outlives the request log.

This one borrows the key you already connected for Google Search Console. Enable the **Google Sheets API** for that project, make a spreadsheet, and share it with the service account address the card shows you. Leave **General access** on *Restricted*: the sheet stays private, and the service account is the only thing reading it.

If Google refuses, the card says which refusal it was. A `403` does not always mean you forgot to share the sheet — the Sheets API is enabled separately, per project, and that failure looks identical until something names it.

### Your own address (a webhook)

For anything else: a JSON body posted to a URL you control.

Every delivery is **signed**. An `X-Agentimus-Signature` header carries an HMAC-SHA256 of the exact bytes sent, using a secret only your site and your receiver know. Your endpoint can then prove a request really came from your site, and not from someone who found the URL. Rotating the secret is deliberate and explicit, because a receiver checking the old one stops trusting you the moment it changes.

## The record

**More → Announcements** keeps the ledger: what went out, what is still queued, and what failed with the reason. It is a record rather than a guess — if a message never arrived, the row says so and says why.

## Turning it off

Switch a service off and it stops immediately. Anything still queued for it is discarded rather than delivered late, and the stored credential is deleted from your site.

Deleting the credential here does not revoke it at the other end. If a token may have leaked, revoke it where it was issued — with `@BotFather` for Telegram, or in the app that made it — because that is the only place it can actually be cancelled.
