---
title: Web Bot Auth
parent: User Manual
nav_order: 18
---

Some AI agents now **cryptographically sign their requests**. Instead of just *saying* "I'm OpenAI's agent" in a user-agent string — which anyone can copy — the agent signs each request with a private key, and publishes the matching public key at a well-known address on its own domain. Anyone receiving the request can check the math and know, with certainty, who sent it.

The open standard behind this is called **Web Bot Auth** (built on HTTP Message Signatures, RFC 9421), and Agentimus verifies these signatures — right on your server, with no outside service involved. It's the third and strongest of the three identity checks behind the **Verify bot identities** switch, alongside [reverse DNS and published IP ranges](ai-access.html#verify-bot-identities-optional).

## Why a signature beats a name

Every other identity signal a crawler sends can be imitated. A user-agent string is plain text — a scanner pastes "Googlebot" into its headers and it *is* Googlebot, as far as the text goes. Reverse DNS and IP-range checks fix that by tying the claim to the operator's network, and they work well — but they prove where a request *came from*, not who *made* it.

A signature proves authorship. Only the operator holds the private key, so a valid signature can't be copied, replayed from an old request (each one carries a single-use nonce that Agentimus remembers), or forged from someone else's address. When the math checks out, that request came from the operator — full stop. That's why, in Agentimus's records, a signature verdict outranks every claim-based verdict.

## Who signs today — honestly

Adoption is young, and this page won't pretend otherwise. As of this release, two operators run confirmed, live signing:

- **Google Agent** (`agent.bot.goog`) — Google's *agent*, the one that acts on a user's behalf. **Googlebot — the search crawler — does not sign**, and Google's own documentation says to keep using reverse DNS for it.
- **OpenAI agent** (`chatgpt.com`) — the ChatGPT agent / Operator traffic.

Cloudflare, Anthropic and others have committed to the standard, and more operators will light up over time. Which leads to the design rule the whole feature is built on:

**Agentimus verifies the agents that sign today — and never penalises the rest.** An unsigned request loses nothing and gains nothing; it simply gets the other two checks, same as before. Signing is extra credit, not a requirement. The classic search crawlers are mostly unsigned and will be for some time — treating "unsigned" as suspicious would flag the whole legitimate web.

## What a verdict means

Every request is read once, cheaply, and lands in one of four states:

- **Signed (verified)** — the signature checks out against the operator's published key, and the operator is one Agentimus recognises. The request is cryptographically proven. In the [Request Log](dashboard.html#the-request-log) its Status column shows **signed**, and the tooltip names the operator: *"Cryptographically verified — signed by OpenAI agent."*
- **Forged** — the request *claimed* to be from a recognised signer, but the math failed. This is the one state that's damning: nobody fails their own operator's signature by accident. The Status column shows **forged**, and the client is flagged for review — the review card reads **"Forged signature"**, or **"Forged signature — turned away"** if enforcement refused it (below).
- **Unsigned** — no Web Bot Auth signature at all. The overwhelmingly common case, and never held against anyone.
- **Inconclusive** — something in between: the signer's key directory couldn't be fetched right now, or the key isn't in it yet (operators rotate keys, and a directory can lag a few minutes behind). Agentimus **fails open**: an inconclusive request is treated exactly like an unsigned one. Only conclusive failure — valid claim, wrong math — counts against a client.

## What happens on your server (and what leaves it)

Verification itself is pure math, done locally with the same Ed25519 primitive WordPress already ships. The **only network fetch** in the whole feature is retrieving a signer's public keys, from a fixed well-known path on the *signer's own domain*:

```
https://chatgpt.com/.well-known/http-message-signatures-directory
```

That fetch is cached for hours, a failure is remembered briefly so it isn't retried in a loop, the number of fetches per minute is capped, and it never happens unless a request actually presents a signature naming that signer. Nothing about your site, your visitors or the request is sent anywhere — Agentimus asks the operator for its public key, and that's the end of the conversation. (This is deliberately different from services that verify signatures *for* you by forwarding them the request headers.)

## One switch, not two

There is no separate Web Bot Auth setting. Signature checking rides the existing **Settings → Block scanners & scrapers → Verify bot identities** switch — one decision ("check that bots are who they say they are"), three methods, each used where it applies. If verification is off, signatures are ignored along with everything else.

## What it changes about enforcement

On its own, a verdict just informs — the log badge, the review card, the Details panel. With **blocking** on (and its spoofed-agents rule), it also bites:

- A **forged** signature is treated like any other proven impostor: refused with a `403` at the AI endpoints Agentimus guards, and recorded as **refused** so the deception is never silent.
- A **signed, verified** agent is exempt from the legacy-device *heuristic* (a proven identity can't be a "likely spoof") — but **not** from your denylist. If you've blocked an agent, it stays blocked, verified or not. Verification never quietly undoes a choice you made.

Everything inconclusive is served. The fail-open rule from the other two methods applies unchanged: enforcement acts only on proof.

## Both directions

There's a pleasing symmetry here: Agentimus doesn't just check signatures — **your site signs, too**. The discovery documents Agentimus publishes (`discovery.json` and friends) are signed with your site's own Ed25519 key, and your public key is served from the same well-known path on *your* domain. It's on by default and stands down silently on the rare host without the required library. So your site speaks Web Bot Auth in both directions: it verifies who's asking, and it signs what it serves — an agent fetching your discovery data can hold your site to the same standard your site holds them to.

## For developers

The recognised-signers list is filterable. To trust an operator Agentimus doesn't ship — or your own agent — add its origin and a label:

```php
add_filter( 'agentimus_known_signature_agents', function ( $known ) {
    $known['https://agent.example.com'] = 'Example Agent';
    return $known;
} );
```

The origin must be `https`, and its key directory is expected at `/.well-known/http-message-signatures-directory`. Remember what recognition means: a *verified* unknown signer earns nothing (valid math from an origin nobody vouches for is just a fact, not a credential), and a *failed* signature only counts as forged when it claims a recognised signer.

## One caveat that lives outside the plugin

All of this happens when a request reaches WordPress. A full-page cache or CDN that serves your AI endpoints from a stored copy answers *before* WordPress runs — so a signed request is never verified, never logged, and never refused. If you run one, see [Caching & CDNs](caching.html) for the one-time exclusion rule that fixes it.
