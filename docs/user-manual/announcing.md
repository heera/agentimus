---
title: Announcing a post
parent: User Manual
nav_order: 4.6
---

Publishing a post and telling people about it are two different jobs, and the second one is usually the one that gets skipped. **Announcing** closes that gap: Agentimus writes the words, you approve them, and the post goes out to **X** or **LinkedIn** under your own account.

It never posts by itself. There is no setting anywhere that announces the moment you publish, because a sentence nobody read before it went out is not something a plugin should be able to send in your name.

## How it works

Every post has a **Share** tab that drafts the text for you. Once you connect X or LinkedIn under **Integrations → Sharing**, those cards gain a **Send it later** row, which puts the draft in the queue. What is queued goes out exactly as you left it.

You approve the words, or you edit them first. Drafts over X's **280 characters** are shortened before they can be queued, so nothing is truncated mid-sentence on arrival.

## The connection is an app you own

Both services work the same way, and it is worth understanding why the setup asks for more than a password.

Agentimus does not have an X account or a LinkedIn account. You create a small application on the platform, and Agentimus uses it to post **as you**. That means the post comes from you rather than from a plugin vendor's shared pipe, your reach is your own, and nothing is brokered by a third party. It also means that if you disconnect, there is nothing left behind holding permission over your account.

### X

Create a developer account at [developer.x.com](https://developer.x.com), then a **Project** and an **App** inside it. On the app's **User authentication settings**, turn on OAuth 2.0, set the type to a **Web App**, and paste the callback URL the card shows you. Copy the **Client ID** into the field below it — there is no secret to copy, because this connection uses PKCE rather than a stored password.

X meters API posting with **credits**. Each announcement spends a little, and their console states the current price. That is between you and X; Agentimus never sees it.

### LinkedIn

**You need a LinkedIn Page first.** LinkedIn only issues app credentials to an app attached to a Page you administer, and a personal profile alone cannot create one. [Creating one](https://www.linkedin.com/company/setup/new/) is free and takes a minute.

The Page exists only so the app can be made. Your announcements still post to **your own feed**, not to the Page's — posting to a Page is a different LinkedIn product that Agentimus does not use.

With the Page in place, create an **App** at [developer.linkedin.com](https://developer.linkedin.com) and associate it. On the **Products** tab add *Share on LinkedIn* and *Sign In with LinkedIn using OpenID Connect* — both are free and approved on request. Paste the callback URL into the **Auth** tab, then copy the **Client ID** and **Client Secret** into Agentimus.

**LinkedIn access lasts about sixty days and does not renew itself.** The card counts down and tells you the date it runs out. When it expires, announcing pauses and says so rather than failing quietly; reconnecting takes one click and starts a fresh sixty days.

## The record

**More → Announcements** shows what went out, what is still queued, and what failed with the reason. A promise can also be sent early from there if you would rather not wait for the queue.

## Turning it off

Switch a card off and queued announcements for it are discarded rather than sent late. The stored grant is deleted from your site.

As with any connection, deleting it here does not revoke it at the other end. Remove the app's access in your X or LinkedIn account settings if you want the permission itself gone.
