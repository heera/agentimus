---
title: Privacy & data
parent: User Manual
nav_order: 20
---

Agentimus was built to be quiet. It helps AI assistants and crawlers understand your site, but it does that by publishing files and signals on *your own* server — not by sending your data anywhere else. This page explains, in plain language, exactly what stays on your site, the one feature that is the exception, and why a couple of things that *look* like outside connections aren't.

Everything below is checked against the plugin's actual source code, not marketing copy.

## The short version

- **Out of the box, Agentimus makes no outbound connections at all.** No phone-home, no telemetry, no analytics, no remote fonts or scripts, no "check for updates" pings of its own.
- **By default it collects no IP addresses and no personal data.** The activity log lives in your own WordPress database and deliberately never stores a visitor's IP. There is a single opt-in exception — **Store IP addresses for flagged clients** (off by default) — which records an IP *only* for a crawler flagged as an impersonator or a spoof, never an ordinary visitor, and keeps it on your own server so you can block it (explained in full below).
- **There are exactly two exceptions to that no-outbound rule, both opt-in.** The optional **AI Visibility** feature is off until you switch it on and paste in your own AI provider API key; only then does Agentimus call an outside service — the AI engines *you* chose — to check whether they mention and cite you. And the optional **Verify bot identities** / **Identify every bot** settings make DNS lookups and, once a day, download the public crawler-IP lists that bot operators publish (Google's `googlebot.json`, OpenAI's `gptbot.json`, …) so impostors can be caught. Only those public files are fetched — nothing about your site, your content or your visitors is ever sent with either feature.
- **The signing key that proves your discovery documents are genuinely yours never leaves your server.**
- Two things that might *look* like outside requests are not: the `$schema` label inside your discovery documents (it is never fetched), and the readiness report's **Verify live** button (it runs in your browser, against your own public URLs).

## No outbound requests by default

When you install and activate Agentimus and go about your day, the plugin does not open a single connection to the outside world. There is no built-in analytics, no usage tracking, no "call home" to the author, and no remote configuration.

The one thing it sends anywhere is the [weekly digest](weekly-digest.html) — an email to *your own inbox*, through your site's own mail system like any WordPress notification. It contains no images and no tracking, reports only data already stored on your site, and stops with the one-click link in any of the emails.

Your visitors don't get anything new either. A default install adds no front-end JavaScript or CSS to your pages, so there is nothing loading from a third-party CDN and nothing watching your visitors. (Exactly two opt-in features add a script, both off by default: the experimental WebMCP bridge, which stays completely inert in browsers that don't support it, and the CDN-mode referral beacon — a tiny first-party counter that records no IP and nothing identifying, for sites whose edge cache hides arrivals from the server.)

Everything Agentimus produces — your `llms.txt`, the full-text edition, the Markdown versions of your pages, your JSON-LD, your robots rules, and the discovery documents — is generated on your own server and served from your own domain.

## The activity log stays on your site

One of Agentimus's main jobs is to show you *which* AI crawlers and agents are fetching your content. It does this with a first-party activity log, and it is careful about what it records.

For each hit to one of its endpoints, the log stores:

- **which endpoint was requested** (for example `/llms.txt`),
- **the classified agent type** (for example "GPTBot", "ClaudeBot", or "unknown script/tool"),
- **a truncated user-agent string**, and
- **the time of the request**.

That's it. The log **deliberately does not record IP addresses** and keeps no per-visitor identity. It is a picture of *what kind of client* is reading you, not *who*. All of it lives in your own WordPress database and nothing is sent anywhere. (The one way any IP is ever stored — only for flagged impersonating or spoofed crawlers, and only if you opt in — is covered just below.)

By default the log keeps **30 days** of history and prunes older rows automatically each day. (Developers can change the retention window with the `agentimus_activity_retention_days` filter.) Requests from you — a logged-in administrator inspecting your own endpoints — are skipped so they don't clutter the picture.

### Storing IPs for flagged crawlers (off by default)

There is exactly one setting that can change the no-IP default, and it ships **off**: **Store IP addresses for flagged clients**. Turn it on only if you want to see the actual addresses behind abusive crawlers so you can block them at your host or CDN.

When it's on, Agentimus records an IP address in a **separate store** — never in the ordinary activity log — and only for a client it has already flagged as one of two things:

- an **impersonating crawler**: one that claims a bot from the Verified-bots list (Googlebot, GPTBot, …) but conclusively fails that operator's own published check — reverse DNS, or its published IP ranges — or
- a **legacy-device spoof / scanner**: a client hiding behind a long-dead phone or embedded-device user-agent.

It never stores the IP of an ordinary visitor, and it never stores an IP for a client that isn't flagged.

Those addresses are personal data, so Agentimus keeps the footprint as small as it honestly can:

- they live **only on your own server**, in their own small table — nothing is ever sent off-site;
- they're kept for a **short retention** (about 14 days) and pruned automatically;
- they're wiped when you use the log's **Clear** action; and
- they're **deleted in full the moment you switch the setting back off** — Agentimus won't hold data you've just declined.

If you enable this setting, treat those IPs as personal data and disclose it in your site's privacy policy.

### "Traffic from AI" is aggregate too

The dashboard's **Traffic from AI** card, which counts real people who arrived from an AI assistant like ChatGPT or Perplexity, follows the same rule. It stores daily, aggregate counts only — no IP addresses, no per-visitor records, and nothing is sent off your server. Read that number as a floor ("at least this many"), because some AI-referred visits simply can't be detected.

## The one exception: AI Visibility

The **AI Visibility** feature is the single part of Agentimus that talks to an outside service. It exists so you can watch whether AI assistants actually mention and cite you over time — which, unavoidably, means asking those assistants some questions.

It is designed around three protections: it is **off by default**, it uses **your own API keys** (bring-your-own-key), and it runs **only when you ask it to**.

### What gets sent, and to whom

When you enable AI Visibility and add an API key for one or more providers, Agentimus sends the questions you configured to those providers and reads back their answers to see whether you were mentioned, linked, and how you rank against your rivals. It sends this **only to the engines you switched on**, and **only when a check actually runs** — either when you click **Run check now**, or on the automatic schedule you chose (daily or weekly, and only if you turned that on).

Nothing else is sent. Your keys are used solely to make these calls.

The providers you can choose to enable, each with their own terms and privacy policy, are:

| Provider | Service | Terms | Privacy policy |
|---|---|---|---|
| OpenAI | ChatGPT | openai.com/policies/terms-of-use | openai.com/policies/privacy-policy |
| Perplexity | Perplexity | perplexity.ai/hub/legal/terms-of-service | perplexity.ai/hub/legal/privacy-policy |
| Google | Gemini | ai.google.dev/gemini-api/terms | policies.google.com/privacy |
| Anthropic | Claude | anthropic.com/legal/consumer-terms | anthropic.com/legal/privacy |

Once your questions leave for a provider, that provider's own terms and privacy policy govern what happens to them — which is why they're listed here for you to review before you turn the feature on.

### Where your API keys live

Your API keys are stored on your own site, in your WordPress database, and are used only to make the calls above.

The admin screen never echoes a key back to you in full. Once saved, a key shows as a masked placeholder (a row of dots). The complete key is returned only when you explicitly click to reveal your own key, and only to an administrator (the reveal is gated to users who can manage options). Leaving the masked placeholder in place on save means "keep the stored key as it is"; clearing the field on purpose removes the key.

### Nothing runs until you turn it on

A fresh install schedules nothing. Automatic checks stay off until you both switch the feature on *and* give an item at least one question and a working key. You can pause any single item you're tracking, or the whole schedule, at any time without losing its setup. Because every scheduled run spends *your* API budget, Agentimus never starts recurring checks on your behalf.

Results — the scoreboards, rankings and cited sources — are stored locally in your database, right alongside the rest of your Agentimus data.

## The signing key never leaves your server

Agentimus can optionally sign your discovery documents (using Ed25519 / HTTP Message Signatures) so that an agent can confirm the documents really came from you and weren't altered in transit. This is on by default where your server supports it, and it is feature-detected — if the required cryptography library isn't available, signing is simply skipped.

The important privacy point: **the private signing key stays on your server.**

- The keypair is generated on your own site the first time it's needed.
- The private key is stored in a way that is **not autoloaded**, so a secret never rides along on every page load.
- Only the **public** half is published — at `/.well-known/http-message-signatures-directory` — which is exactly what agents need to verify a signature. A public key is meant to be shared; it can't be used to forge your signatures.
- If you'd rather supply the key from a constant or a secrets vault instead of the database, the `agentimus_signing_secret_key` filter lets a developer do that, and that override never touches the database at all.

## About that "$schema" line in your discovery documents

If you open your `/.well-known/discovery.json` you'll see a line near the top that looks like this:

```json
{
  "$schema": "https://heera.github.io/wp-discovery-protocol/schemas/discovery/1.0/discovery.schema.json",
  "...": "..."
}
```

That URL can look like your site is reaching out to fetch something. It isn't.

A `$schema` value is simply a **label that names the format** of the document — the same way a `schema.org` URL identifies a vocabulary without anyone downloading it. Agentimus writes this label into the output as a piece of text and **never fetches it**. Neither the label above, nor the example addresses on `example.com` in the developer sample files, are ever requested by the plugin. (A developer can even change or remove the label with the `agentimus_schema_url` filter.)

## "Verify live" runs in your browser

The **Readiness report** has a **Verify live** button that fetches your real agent endpoints and grades what actually comes back — a genuinely useful check, because it sees exactly what an AI agent would get, including anything a CDN in front of your site is caching.

You might reasonably wonder whether that breaks the "no outbound requests" promise. It doesn't, because of *where* the request comes from:

- The check runs **in your own browser**, not on the server.
- It fetches **your own public URLs** on the same origin, and only **when you click the button**.
- It requests them anonymously (without your login), so it sees the same view an agent would.
- The **server itself still makes no outbound request** — your browser does the fetching, exactly as any visitor's browser would when loading your pages.

So the plugin's server-side "zero outbound" guarantee stays intact; Verify live is just your own browser looking at your own public files.

## What Agentimus publishes vs. what it keeps

To keep the whole picture in one place:

| Data | Where it lives | Does it leave your server? |
|---|---|---|
| llms.txt, Markdown, JSON-LD, robots rules, discovery documents | Generated and served from your own domain | Published publicly (that's their purpose) — but only ever describing already-public content |
| Agent activity log (endpoint, agent type, truncated user-agent, time) | Your WordPress database | No |
| Flagged-crawler IPs (opt-in, **off by default**) | A separate table in your WordPress database, short retention (~14 days), cleared with the log and purged when the setting is switched back off | No |
| "Traffic from AI" counts | Your WordPress database (daily, aggregate) | No |
| The private signing key | Your server, non-autoloaded (or your own vault via filter) | No |
| The public signing key | Published at `/.well-known/http-message-signatures-directory` | Yes — public keys are meant to be shared, and it can't forge anything |
| AI Visibility API keys | Your WordPress database, masked in the admin | No (used only to make the calls you configure) |
| AI Visibility questions & results | Questions are sent to the providers you enable; results stored in your database | Questions: yes, to your chosen AI providers, only on a run. Results: stored locally |

A last reassurance worth repeating: Agentimus only ever describes and publishes content your site **already makes public**. Password-protected and private posts are kept out of your JSON-LD, sitemap, llms.txt and Markdown, and suppressing or removing an item changes only what's *advertised* — never what's reachable behind its own authentication.
