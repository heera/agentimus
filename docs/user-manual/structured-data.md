---
title: Structured data
parent: User Manual
nav_order: 8
---

Structured data is a small, machine-readable summary of your page that Agentimus prints in the page's HTML head. Humans never see it, but search engines and AI assistants read it to understand *who you are*, *what a page is about*, and *how your pages relate to each other* — without having to guess from your layout or wording.

Agentimus writes this summary in **JSON-LD** (JavaScript Object Notation for Linked Data), the format Google, Bing and the major AI answer engines all understand. It follows the [schema.org](https://schema.org) vocabulary, so the labels it uses (`WebSite`, `Person`, `BlogPosting`, and so on) mean the same thing to every tool that reads them.

This page explains exactly what Agentimus emits, when, and how to shape it. You do not need to write any code — everything here comes from your settings.

## Why it matters

When a person searches or asks an assistant a question, the tool has to decide what your page is and whether to trust it. Clean structured data gives it a direct, unambiguous answer:

- **You are identified.** The assistant knows the site belongs to a specific person or organisation, not an anonymous page.
- **Pages are typed.** A blog post is marked as an article; a page is marked as a page; a genuine question-and-answer page is marked as an FAQ.
- **Everything is connected.** Each article points back to you as its author and publisher, so an assistant can attribute your work correctly.

That is the difference between an AI answer that says "according to Jane Smith's guide" and one that quietly paraphrases you with no credit.

## Where it appears and when

Agentimus hooks into WordPress at the very top of the page head and prints a single `<script type="application/ld+json">` block. Inside that block is one **graph** — a list of connected nodes — tailored to the page being viewed:

- **On every front-end page**, it emits the two site-level identity nodes: your `WebSite` and your Person/Organization entity.
- **On your front page**, it also adds any `Service` nodes you have declared.
- **On a single post or page** (of a content type Agentimus covers — posts and pages by default), it additionally emits the per-page nodes: the article/page node, a breadcrumb trail, and an FAQ node if the content clearly is a Q&A.

By default Agentimus covers **posts and pages**. If you have opted extra content types in (for example WooCommerce products or a custom post type) under Settings, those single views get the per-page nodes too.

## The site identity nodes

These two nodes describe the site as a whole and appear on every front-end request.

### WebSite (and the sitelinks search box)

The `WebSite` node names your site and, importantly, declares a `SearchAction` — a standardised description of your site's own search box. This is what can let Google show a **sitelinks search box** (a search field right inside your listing) for your brand.

```json
{
  "@type": "WebSite",
  "@id": "https://example.com/#website",
  "url": "https://example.com/",
  "name": "Example Site",
  "description": "Your site's tagline",
  "inLanguage": "en-US",
  "potentialAction": {
    "@type": "SearchAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "https://example.com/?s={search_term_string}"
    },
    "query-input": "required name=search_term_string"
  }
}
```

The name, tagline and language come straight from your WordPress **General Settings** and **Site Language** — there is nothing extra to configure.

### Your identity: Person or Organization

This is the heart of the graph. It says who stands behind the site. Depending on what you choose on the **Identity** page, its type is either `Person` (a human — a blogger, freelancer, author) or an Organization type (`Organization`, `LocalBusiness`, `Store`, and any others an add-on adds).

```json
{
  "@type": "Person",
  "@id": "https://example.com/#identity",
  "name": "Jane Smith",
  "url": "https://example.com/",
  "jobTitle": "Pastry Chef",
  "description": "I write about home baking and pastry technique.",
  "disambiguatingDescription": "Not a commercial bakery or a recipe aggregator.",
  "audience": { "@type": "Audience", "audienceType": "Home bakers" },
  "knowsAbout": ["Sourdough", "Laminated dough", "Chocolate work"],
  "sameAs": [
    "https://www.wikidata.org/wiki/Q42",
    "https://mastodon.social/@janesmith"
  ]
}
```

Every field here is drawn from the Identity page:

| JSON-LD field | Identity setting | What it does |
|---|---|---|
| `@type` | Entity type | Person, or an Organization subtype |
| `name` | Name | The person or organisation's name |
| `jobTitle` | Role | Your role (Person only) |
| `description` | About | A short description of the site |
| `disambiguatingDescription` | What this is **not** | Tells assistants what *not* to confuse you with |
| `audience` | Audience | Who the site is for |
| `knowsAbout` | Areas of expertise | The topics you are authoritative on |
| `sameAs` | Verified profiles | Links to your Wikidata entry, social profiles, etc. |

The `sameAs` links are especially valuable: they let an assistant confirm it is talking about *you* and not someone with a similar name.

### Services you offer

If you have listed any services on the Identity page, each becomes a `Service` node so an assistant can answer "what can I hire this site for?". Services are **opt-in** — Agentimus never guesses them from your content — and they ride on the front page.

```json
{
  "@type": "Service",
  "name": "Wedding cake commissions",
  "description": "Custom multi-tier cakes for events.",
  "url": "https://example.com/cakes/",
  "provider": { "@id": "https://example.com/#identity" }
}
```

Notice `provider` points back to your identity node by its `@id` rather than repeating your name — more on that below.

## The per-page nodes

On a single post or page, Agentimus adds nodes that describe *that* page.

### The page itself

The main node describes the content and its type is chosen to fit:

- A blog **post** becomes a `BlogPosting`.
- A **page** becomes a `WebPage`.
- Any other covered content type becomes a generic `Article`.

```json
{
  "@type": "BlogPosting",
  "@id": "https://example.com/sourdough-guide/#blogposting",
  "url": "https://example.com/sourdough-guide/",
  "headline": "A Beginner's Guide to Sourdough",
  "description": "A step-by-step introduction to keeping a starter and baking your first loaf.",
  "datePublished": "2026-05-01T09:00:00+00:00",
  "dateModified": "2026-06-12T14:30:00+00:00",
  "author": { "@id": "https://example.com/#identity" },
  "publisher": { "@id": "https://example.com/#identity" },
  "mainEntityOfPage": "https://example.com/sourdough-guide/",
  "keywords": ["Sourdough", "Bread baking"],
  "about": [
    {
      "@type": "DefinedTerm",
      "name": "Sourdough",
      "sameAs": "https://www.wikidata.org/wiki/Q191600"
    }
  ]
}
```

The `author` and `publisher` both point back to your identity node, so your credit travels with the page. The `keywords` and `about` fields appear only when you have given the page topics — see the **Topics for AI** page for how those work, including the authoritative `sameAs` links on each topic.

### The one-line description

The `description` on the node is a single plain sentence that sums the page up. You set it in the editor's **AI description** box, and that one line is the single source of truth for three surfaces at once, so they can never drift apart: the JSON-LD `description` above, the lead line of the page's `.md` twin, and the page's own HTML `<meta name="description">`.

If you leave the box empty, Agentimus falls back to the post's manual excerpt or, failing that, a roughly thirty-word summary drawn straight from the start of the post body. It comes up empty only when a post has neither an excerpt nor any body text. Whatever the source, the final line is tidied — markup stripped, spacing collapsed — and capped at 300 characters.

Because that line is authoritative, Agentimus doesn't just add its own tag: it looks at the `<meta name="description">` your theme printed and **replaces** it (or adds one if there wasn't any), so a page always carries exactly one, matching every other surface. It touches only `name="description"` — your `og:description` and `twitter:description` tags are left exactly as they are.

Like the schema itself, this defers to a dedicated SEO plugin: if Yoast, Rank Math, SEOPress, All in One SEO or The SEO Framework is active, Agentimus leaves the description alone so it never duplicates or fights the one your SEO plugin writes.

Both switches live on a dedicated **AI description** card under **Settings → Discovery**, each on by default. The master toggle turns the whole feature on or off; a sub-toggle lets you enrich only the JSON-LD and `.md` surfaces and never touch the page `<head>`, if you would rather keep managing the visible meta tag yourself.

### Breadcrumbs

A `BreadcrumbList` gives assistants the page's place in your site — Home, then its first category, then the page itself. It helps them describe *where* a page sits.

```json
{
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "https://example.com/" },
    { "@type": "ListItem", "position": 2, "name": "Recipes", "item": "https://example.com/category/recipes/" },
    { "@type": "ListItem", "position": 3, "name": "A Beginner's Guide to Sourdough", "item": "https://example.com/sourdough-guide/" }
  ]
}
```

### FAQ pages (auto-detected)

If a page genuinely reads as a list of questions and answers, Agentimus emits a `FAQPage` node so assistants can lift your Q&A cleanly. **You do not tag anything** — it is detected from the content, using two deliberate, low-false-positive signals:

1. **Details/summary blocks** — the native WordPress "Details" block, where the summary is the question and the body is the answer (a common accordion/FAQ pattern).
2. **Question headings** — an `h2`, `h3` or `h4` whose text ends in a question mark, with the text that follows it (up to the next heading) as the answer.

To avoid spammy or accidental FAQ markup, Agentimus only publishes the node when it finds **at least two** genuine question-and-answer pairs — a single question is just prose. Answers are trimmed to a sensible length to keep the data tidy.

```json
{
  "@type": "FAQPage",
  "@id": "https://example.com/sourdough-guide/#faq",
  "url": "https://example.com/sourdough-guide/",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "How long does a starter take to activate?",
      "acceptedAnswer": { "@type": "Answer", "text": "About five to seven days of daily feeding." }
    },
    {
      "@type": "Question",
      "name": "Can I use whole-wheat flour?",
      "acceptedAnswer": { "@type": "Answer", "text": "Yes — it actually ferments faster." }
    }
  ]
}
```

If you want a page to be recognised as an FAQ, write it that way: use the Details block, or use question headings that end in "?", with the answer directly below.

## How the nodes link together (`@id`)

Rather than repeating your name and details inside every node, Agentimus gives each site-level node a stable **`@id`** — a permanent anchor — and links to it from everywhere else:

- Your WebSite is `#website`.
- Your identity is `#identity`.
- Each post's node, breadcrumb and FAQ carry their own page-based `@id` (for example `…/sourdough-guide/#blogposting` and `…#faq`).

So a post's `author`, its `publisher`, and every Service's `provider` are all written as `{ "@id": "https://example.com/#identity" }`. This tells a machine "the author here is *the same entity* defined above" — one authoritative description, referenced everywhere, with no risk of the details drifting apart. It is exactly how well-formed schema graphs are supposed to fit together, and it is what lets an assistant confidently connect an article to the person who wrote it.

## When Agentimus stands down for an SEO plugin

Duplicate or conflicting structured data is one of the fastest ways to confuse a search engine — and one of the most common reasons schema gets ignored. So Agentimus checks whether a major SEO plugin is already emitting schema, and if so it **stays completely silent** on structured data (all its other features keep working).

It stands down when any of these is active:

- Yoast SEO
- Rank Math
- SEOPress
- The SEO Framework
- All in One SEO

You do not have to choose — this is automatic. If you run one of these plugins, that plugin owns your schema and Agentimus won't add a second, competing copy. The **Readiness** panel will show this as a passing check ("An SEO plugin owns schema; Agentimus is standing down to avoid duplicates"), so you can confirm at a glance that your site still has structured data, just from the other plugin.

Schema is one of several surfaces that defer this way — SEO titles, share cards, canonical links and the sitemap follow the same rule, and the same detection covers them all. See [Search basics](search-basics.html) for the full picture of what Agentimus provides when no SEO plugin is installed.

## The privacy guard: drafts and password-protected content

Structured data describes your public site, so Agentimus is careful never to leak content that isn't public:

- **The site-level identity nodes** (WebSite, your entity, Services) appear on public front-end pages as normal — they describe the site, not private content.
- **A per-page node is only emitted for content that is genuinely public.** The post must be **published** (drafts, pending, scheduled and private posts get no per-page node) and it must **not be password-protected**.

The password rule is strict for a reason: a password-protected page's body could contain question-and-answer text that would otherwise flow into the public FAQ node. Agentimus refuses to describe that content at all, so nothing behind a password ever surfaces in the structured data — even after the page is later published.

## Turning it on, off, and previewing it

Structured data is controlled by the **JSON-LD structured data** toggle under **Settings → Features**. It is **on by default**. Reasons you might turn it off:

- You run one of the SEO plugins above (though Agentimus already stands down automatically, so you can safely leave it on).
- You have another dedicated schema plugin you prefer.

To see exactly what Agentimus would print for a given page before it ships, use the built-in **Agent preview** (available from the Readiness panel and from the editor). It shows the full JSON-LD graph — including a draft post's would-be node, so you can check your markup *before* you publish. (The preview still respects the password guard: a password-protected page's body is never shown, even in preview.)

## Shaping what it says

Almost everything in the identity and Service nodes is yours to control, and it all lives in one place: the **Identity** page. That is where you set whether you are a Person or an Organization, your name and role, your "about" and "what this is not" descriptions, your audience, your areas of expertise, your verified `sameAs` profiles, and your services. Filling that page in thoroughly is the single most valuable thing you can do for your structured data — it turns an anonymous graph into a confident, verifiable statement of who you are.

For the per-page `keywords` and `about` topic data, see the **Topics for AI** page.
