---
title: Agent Access
parent: User Manual
nav_order: 4
---

## What Agent Access shows

The [activity log]({% link user-manual/dashboard.md %}) answers *who **read** your machine-readable files*. **Agent Access** answers the other half: *who **authenticated to**, and **acted on**, the machine surface Agentimus creates.* You'll find it under **More → Agent Access**.

It records three kinds of thing:

- **Application passwords** — the keys a program (an automation, an AI agent, a mobile app) uses to reach WordPress *as you*, over the REST API. Agent Access notes when one is **created, first used, renamed or revoked**.
- **Abilities** — the actions WordPress 7.0's **Abilities API** lets an AI assistant run on your site. Agent Access notes when one is **run** — Agentimus's own abilities (the read-only reports, and the write tools if you've enabled those) and, where your site supports it, any other plugin's.
- **Refused or probed requests** — a request for an ability that was **turned away**, or a probe for abilities that **don't exist** (someone guessing at names).

Like the activity log, it is **first-party and local-only**, and it **stores no IP addresses and no personal data**. That has a consequence worth stating plainly: it can name the *key* that was used, but never the *person* using it. It also sees **machine logins only** — someone signing in with your normal username and password never appears here. Records are kept for 90 days and hard-capped, so the log can never grow without bound.

> **It is a record, not a guard.** Agent Access never *blocks* anything. It shows you what happened; it does not stand in the way of it. If you want to actually turn agents away, that's the opt-in [crawler blocking]({% link user-manual/ai-access.md %}), a separate feature.

---

## The one line worth reading

Of everything Agent Access records, one event is worth a second look: **a new application password you don't recognise.**

An application password keeps working **even after you change your WordPress password.** That's what makes it convenient for an app — and it's exactly why a stray one matters: it's the classic way an intruder keeps a foothold after you've locked them out. So every "new application password" row carries a plain reminder:

> *Didn't create this? Revoke it.*

You revoke one under **Users → Profile → Application Passwords**. If you *did* create it (you set up Zapier, a mobile app, an AI agent), you can ignore the note — it's there for the one time you didn't.

The sharpest signal of all is a **refused** request that came in on one of your own application passwords: *a key you issued tried to run something it isn't allowed to.* That's either a misconfigured integration or a stolen key — and either way, the fix is the same: if you don't recognise it, revoke it.

---

## What each row means

| Row | What happened |
|---|---|
| **New application password: "…"** | A key was created for a user. The name is whatever the app called itself. |
| **Application password used for the first time: "…"** | A key that had never authenticated before just did. A dormant key waking up. |
| **Application password renamed / revoked: "…"** | A key's record changed, or it was deleted. |
| **Ability used: …** | An ability ran. A repeat rolls up into a count ("3 times") rather than a new row, so a busy agent can't bury the feed. |
| **Ability refused: …** | A real ability, a well-formed request — and WordPress said no. |
| **Someone probed for abilities that don't exist** | Requests for ability names nobody advertises — guessing. Aggregated into one row whose count tells you the scale. |

New rows are flagged until you've seen them, and the count appears on the **More** menu so you notice one arriving from any screen.

## Who did it

Every row carries a quiet second line naming who was behind it — *"by anna · app password “zapier”"* for an ability run over a key, *"by anna · logged-in session"* for one run from the admin. The names are looked up live from your own users and keys at the moment you view the screen, so they can never go stale: rename a key and old rows show its current name; revoke it and they say *"app password (since revoked)"*; delete the user and the row says that too, instead of a bare number. Nothing extra is stored to make this work — still no IP addresses, no identities.

One wording is deliberate: password-lifecycle rows (created, renamed, revoked) say *"on anna's account"* rather than *"by anna"*. Those rows record whose account the key lives on — and an administrator can create or revoke a key on someone else's profile — so the screen claims only what it actually knows.

---

## "What can I see here?" — the honesty box

At the top of the screen, a coloured box tells you, in plain words, exactly what this site can and can't observe — because it depends on your WordPress version:

- **Watching every ability on this site** — the best case (WordPress 6.9+, or the Abilities API plugin at 0.4.0+). Every ability run is recorded, Agentimus's own and any other plugin's.
- **Watching Agentimus's abilities only** — an older Abilities API plugin that doesn't announce when an ability runs. Agentimus still sees its *own* abilities (it runs those itself); update the plugin to see the rest.
- **This site has no abilities to watch** — WordPress didn't include abilities before 6.9, and this site doesn't have them. The box tells you how to add them, or that your WordPress is too old.

The **application-password** half works on every supported WordPress regardless — except on a **plain-HTTP** site, where WordPress switches application passwords off entirely, in which case the screen says so rather than showing an empty table.

There's one thing it's honest about not seeing: WordPress only tells Agentimus an ability ran *after* it has already allowed it, so a request that was **refused for lack of permission** is visible, but a bad request that never got that far isn't. A quiet screen means *nothing succeeded* — not *nobody tried*. The footer says as much.

---

## Turning it on or off

Agent Access is **on by default**. It's the rare setting with nothing to trade off: it stores no personal data, makes no outbound request, and never blocks — so there's nothing to weigh. If you'd still rather not keep the record, turn off **Record agent access** under **Settings → Exposure**.

For developers: the events, the retention window and each recorded event are all filterable — see the [developer reference]({% link developer.md %}).
