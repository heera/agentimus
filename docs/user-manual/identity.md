---
title: Identity
parent: User Manual
nav_order: 10
---

The Identity section is where you tell AI assistants *who* is behind your site. It is the single highest-signal thing you can fill in. When ChatGPT, Claude, Perplexity, Google's AI overviews or any other assistant reads your site, this is the information they use to decide who you are, what you know, and whether they can trust and cite you.

Everything you type here is copied, word for word, into the machine-readable files Agentimus publishes: your `llms.txt` guide, the structured data (JSON-LD) in your page source, and the `discovery.json` manifest. You write it once, in plain language, and Agentimus formats it correctly for every one of those places.

## Where to find it

In your WordPress admin, open the Agentimus screen and click the **Identity** tab. It is the first tab and the one Agentimus opens on, because it is where a new site should start. You will see two cards:

- **Identity** — who you are (entity type, name, profile sentence, and so on).
- **Services** — what you can be hired for.

## Why this section matters most

AI assistants do not read your site the way a person does. They pull in your text, try to work out what kind of thing your site is, and then answer questions about it. If they guess, they get it wrong: a consultant's site gets described as a blog, a shop gets mistaken for a review site, two people with the same name get merged into one.

The Identity fields remove the guesswork. You state plainly who owns the site, what it is about, what it is *not*, who it is for, and where else you exist on the web. Assistants that read this are far more likely to describe and cite you accurately.

## Entity type: a person or an organisation?

The first choice is **Entity type**. It decides whether your site is described as a human being or as a business, and it changes the technical `@type` label assistants read. There are four built-in options:

| Type | Use it when the site belongs to… | Example |
| --- | --- | --- |
| **Person** | An individual — a freelancer, author, consultant, creator | Jane Doe, a software architect |
| **Organization** | A company, non-profit, team or group | Acme Inc. |
| **LocalBusiness** | A business tied to a physical location | A dental practice, a law firm |
| **Store** | A shop that sells goods | A boutique, an online store |

Picking **Person** is the "human" case. The other three are increasingly specific kinds of organisation (a Store is a kind of LocalBusiness, which is a kind of Organization), so choose the most specific one that fits.

Two things change on screen once you pick a type:

- The **Role / title** field only appears for a **Person** — a company does not have a job title.
- The placeholder hints adjust (for example, the profile-URL box suggests a LinkedIn *company* page for an organisation and a GitHub profile for a person).

If you or a developer needs another type (say, `Restaurant`), it can be added with the `agentimus_entity_types` filter, but the four above cover the vast majority of sites.

## The profile fields

The following fields sit together in one block with a **Save profile** button at the bottom. Type your text, then click **Save profile** to commit them all at once. (The two fields further down — Expertise and Profile URLs — save automatically as you add them; more on that below.)

### Name

Your name or your business's name — exactly as you want assistants to say it. If you leave it blank, Agentimus falls back to your WordPress site title. This becomes the headline of your `llms.txt` file and the `name` in your structured data, so make it the real, human-readable name rather than a slug or domain.

### Role / title (Person only)

A short job title, such as *Software architect* or *Pastry chef*. This only shows for a Person. It becomes the `jobTitle` in your structured data, giving assistants a one-word answer to "what does this person do?".

### Profile sentence

This is the most important field on the whole page. Write **one plain, factual sentence** that says who you are and what you are known for. For example:

> Jane Doe is a software architect specialising in high-traffic WordPress and API design.

Or for a business:

> Acme Inc. builds accounting software for independent tradespeople in the UK.

Keep it factual, not salesy — this is the line assistants quote most often when they cite you. It appears at the top of your `llms.txt`, in the full-text edition, and as the `description` in your structured data and discovery manifest.

### What you're not (optional)

An often-overlooked field that punches above its weight. Here you write an **explicit exclusion** — what your site is *not* — so assistants don't miscategorise you. For example:

> This is not a personal blog or a news site.

> We are a software company, not an IT support or repair service.

Assistants love to slot sites into familiar buckets. One clear "we are not X" sentence stops the wrong bucket. It becomes the `disambiguatingDescription` in your structured data and a "What this is not:" line in your `llms.txt`.

### Audience (optional)

Who the site is for, in a short phrase — for example, *Small business owners evaluating IT services* or *Home cooks who want reliable recipes*. This helps an assistant judge when your site is a good answer for the person asking. It feeds the `audience` value in your structured data and an "Audience:" line in your `llms.txt`.

### Public contact email (optional)

An email address agents can use to reach you. This is **opt-in and private by default** — if you leave it blank, no email is published anywhere. Your WordPress admin email is *never* used or exposed. When you do set it, it appears (as a `mailto:` contact) in your `discovery.json` manifest, and it also seeds the first contact in your `security.txt` file if you use that feature. It is deliberately kept out of the visible JSON-LD on your pages to avoid handing your address to scrapers.

## Expertise topics

Below the Save-profile block, the **Expertise topics** field (labelled *Topics & specialties* for organisations) is a tag box. Add the subjects you are an authority on — press Enter after each one. Aim for three to five solid topics, for example:

```
WordPress performance
REST API design
Caching strategies
```

These establish your topical authority. They become the `knowsAbout` list in your structured data and an "Expertise" list in your `llms.txt`. This field **saves automatically as you add or remove tags** — there is no separate Save button for it.

## Profile links (sameAs)

The **Profile URLs** field is another tag box, for the full web addresses of your other public profiles — LinkedIn, X, GitHub, Facebook, a Wikipedia page, and so on:

```
https://github.com/janedoe
https://www.linkedin.com/in/janedoe
https://en.wikipedia.org/wiki/Jane_Doe
```

These are called `sameAs` links in structured-data terms, and they do something powerful: they let an assistant tie *this* site to a **known entity** it already recognises, instead of guessing. If your name is shared with other people, these links are what let an assistant say "yes, this is that Jane Doe" with confidence.

Use full `https://` addresses — Agentimus will flag any entry that is not a proper URL. Like the expertise tags, this field **saves automatically** as you add each link. The links appear as `sameAs` in your structured data and in the `same_as` list of your `discovery.json`.

## Services

The **Services** card lets you declare what you can be hired for. This is optional — leave it empty if you don't sell services. Each service you add has three parts:

- **Name** (required) — e.g. *WordPress plugin development*
- **URL** (optional) — a link to a page about that service
- **Description** (optional) — one line on what it includes

Click **+ Add a service** for each one, fill it in, then click **Save services**. (A service with no name is ignored, and removing a service saves immediately.) Each service becomes a Schema.org `Service` entry in your structured data, linked back to you as the provider — so an assistant can answer "what does this site offer?" or "who can I hire to do X?".

Services are only ever what *you* declare here. Agentimus never guesses services from your page content.

## Where every field ends up

Here is the full map of how each Identity field flows into the three machine surfaces Agentimus publishes. This is what an assistant actually reads.

| Identity field | Structured data (JSON-LD) | `llms.txt` / full-text | `discovery.json` |
| --- | --- | --- | --- |
| Entity type | `@type` (Person / Organization / …) | — | `type` (person / organization) |
| Name | `name` | File heading | `name` |
| Role / title | `jobTitle` (Person only) | — | `role` |
| Profile sentence | `description` | Top line + "## About" | `about` |
| What you're not | `disambiguatingDescription` | "What this is not:" line | `not_description` |
| Audience | `audience` → `audienceType` | "Audience:" line | `audience` |
| Expertise topics | `knowsAbout` | "Expertise" list | — |
| Profile URLs | `sameAs` | — | `same_as` |
| Contact email | — (kept private) | — | `contacts` (mailto) + seeds `security.txt` |
| Services | `Service` node(s) | — | — |

A filled-in identity produces structured data on your pages that looks roughly like this:

```json
{
  "@type": "Person",
  "@id": "https://example.com/#identity",
  "name": "Jane Doe",
  "url": "https://example.com/",
  "jobTitle": "Software architect",
  "description": "Jane Doe is a software architect specialising in high-traffic WordPress.",
  "disambiguatingDescription": "This is not a personal blog or a news site.",
  "audience": { "@type": "Audience", "audienceType": "WordPress agency owners" },
  "knowsAbout": ["WordPress performance", "REST API design"],
  "sameAs": ["https://github.com/janedoe", "https://www.linkedin.com/in/janedoe"]
}
```

And the top of your `llms.txt` reads like this:

```markdown
# Jane Doe

## About

Jane Doe is a software architect specialising in high-traffic WordPress.

What this is not: This is not a personal blog or a news site.

Audience: WordPress agency owners

Expertise:

- WordPress performance
- REST API design
```

## Saving your work

There are three different save behaviours in this section, so it helps to know which is which:

- **The profile block** (entity type, name, role, profile sentence, what-you're-not, audience, contact email) saves together when you click **Save profile**. The button shows "Unsaved changes" until you do, so nothing is committed by accident.
- **Expertise topics** and **Profile URLs** save automatically each time you add or remove a tag.
- **Services** save when you click **Save services**; removing a service saves on its own.

## When the structured data ships (and when it doesn't)

Your `llms.txt` and `discovery.json` always carry your identity. The on-page **structured data (JSON-LD)**, though, only ships when Agentimus's "Rich data for search" feature is on **and** you don't already have an SEO plugin that outputs its own structured data. Agentimus deliberately stands down when it detects Yoast SEO, Rank Math, SEOPress, The SEO Framework or All in One SEO, so your pages never carry two conflicting sets of structured data. If you rely on one of those plugins for schema, fill in your identity there as well; your `llms.txt` and discovery manifest still carry everything you set here.

## Identity and your AEO/GEO score

The Agentimus dashboard scores how ready your site is for AI. Three of the checks behind the **Trusted** rung of that score come straight from this page:

- **Author / entity profile** — passes once your profile sentence is set.
- **Expertise topics** — passes once you have at least one expertise tag.
- **sameAs profiles** — passes once you add at least one profile link.

Filling in all three lifts your Trusted rung — and your overall AEO/GEO score — and, more importantly, gives assistants a complete, trustworthy picture of who you are.

## A quick setup checklist

1. Choose your **Entity type** (Person, Organization, LocalBusiness or Store).
2. Enter your **Name** (or accept the site-title default).
3. Add a **Role / title** if you are a person.
4. Write one factual **Profile sentence** — the most important field here.
5. Add a **What you're not** line to prevent miscategorisation.
6. Optionally set your **Audience** and a **Public contact email**.
7. Click **Save profile**.
8. Add three to five **Expertise topics** (they save as you type).
9. Add your **Profile URLs** — LinkedIn, GitHub, X, and so on (they save as you type).
10. Add any **Services** you offer and click **Save services**.
