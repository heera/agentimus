---
title: FAQ
parent: User Manual
nav_order: 23
---

Short, honest answers to the questions site owners ask most. If you are weighing up whether Agentimus is safe to run on a live site, this is the page to read. The recurring theme: Agentimus adds a machine-readable layer *for AI tools* without touching what your visitors see, without phoning home, and without taking over jobs your other plugins already do.

## What is the AEO/GEO score?

It's the single 0–100 number on your Dashboard that sums up how ready your site is for AI answer engines. It blends five rungs — **Findable**, **Readable**, **Trusted**, **Optimized** and **Cited** — into one figure, and always names the single most useful next step; each rung links to where you fix it and shows how many fixes it holds. The first three are your site setup (the Readiness checks), **Optimized** grades your individual pages for how quotable they are, and **Cited** measures whether AI engines actually name you — but Cited only counts when you turn on **Track AI citations**, and a rung with nothing to measure is left out entirely rather than counted as a zero, so you're never penalised for a feature you don't use.

## Will it slow my site down?

No. Agentimus is built to stay out of the way of your visitors and your server.

- **No front-end scripts or styles.** Agentimus adds no JavaScript or CSS to the pages your visitors load. Your theme, your page speed, and your Core Web Vitals are untouched. (The one exception is the optional WebMCP bridge, which is off by default — see below — and even when on it adds only a tiny script that stays inert in browsers without the API.)
- **The admin app loads on one screen only.** The plugin's dashboard code loads on Agentimus's own admin page and nowhere else, so the rest of wp-admin stays as fast as before.
- **The AI files are cached.** The text files Agentimus serves (like `llms.txt`) are generated once, cached for up to an hour, and are CDN-friendly. An AI crawler fetching them costs you almost nothing.

If you ever want to see the effect for yourself, open `yoursite.com/llms.txt` in a browser — it is a plain text file that loads instantly.

## Does it change anything my visitors see?

No. Nothing on your visible pages changes — no new layout, no banner, no widget, no font. Everything Agentimus publishes is *machine-readable* and meant only for AI assistants and crawlers:

- A small block of structured data (JSON-LD) in the page's hidden `<head>`, which humans never see.
- Plain files served off your own domain, such as `llms.txt` and a discovery document, that only automated tools request.

Your readers get exactly the same site they got before. The whole point of Agentimus is to add an *invisible* layer that helps machines understand your content correctly.

## Do I need an SEO plugin alongside Agentimus?

No. With no SEO plugin installed, Agentimus covers the search essentials itself: per-page SEO titles (an "SEO title" field in the editor), Open Graph/X share cards, canonical links, meta descriptions, structured data, and an XML sitemap with last-changed dates. Each has its own switch under Settings → **Discovery** → *Search basics* — see the [Search basics](search-basics.html) page. If you install an SEO plugin later, Agentimus notices on the next page load and steps aside on everything the plugin owns; your per-page values are kept, and everything returns if that plugin leaves.

## Does it conflict with my SEO plugin?

No — and this is deliberate. When it detects Yoast, Rank Math, SEOPress, All in One SEO, or The SEO Framework, Agentimus stands down on **every overlapping surface**:

- **Structured data (JSON-LD)** switches itself off, so your page never carries two competing blocks. The Readiness report tells you when Agentimus has stepped aside for this reason.
- **SEO titles, share cards, canonicals and meta descriptions** stand down the same way — the editor's SEO title field hides, and a dashboard card names which plugin owns search SEO.
- **The sitemap** yields: Agentimus links your SEO plugin's sitemap instead of serving its own.
- **The other outputs don't overlap.** `llms.txt`, the Markdown versions of your pages, and the discovery document are things SEO plugins don't produce, so there is nothing to collide with.

In short, Agentimus fills the gaps your SEO plugin leaves and yields on the ground your SEO plugin already covers.

## Does Agentimus make outbound requests or send my data anywhere?

By default, **no.** Out of the box, Agentimus:

- Makes no outbound HTTP requests.
- Sends nothing to any external service.
- Collects no analytics or telemetry.
- Stores its agent-activity log in your own database, with **no IP addresses** recorded by default (see *Does Agentimus store IP addresses?* below for the one opt-in exception).

Everything runs on your own site. There are two things worth knowing so the picture is complete:

- **The optional AI Visibility feature is the one real exception.** If you turn it on and add your *own* API key, Agentimus queries the AI providers you chose (OpenAI, Perplexity, Gemini and/or Anthropic) to check whether they mention and cite you. It only contacts the engines you switch on, only when a check runs (on demand or on your schedule), and your keys stay on your server. If you never enable it, no request is ever made. The plugin's readme carries the full *External services* disclosure.
- **The "Verify live" self-check runs in your browser, not on your server.** The Readiness report has a button that fetches your own public URLs to show you exactly what an AI tool would receive. That fetch happens from *your* browser, hitting *your* own pages — the server itself still makes no outbound request.

One common point of confusion: the discovery document contains a `$schema` value. That is a *label* that names the document's format (the same way a `schema.org` link names a vocabulary). It is text in the file; nothing fetches it.

## Why am I getting a weekly email from Agentimus?

That's the [weekly digest](weekly-digest.html) — a short note about what AI did on your site: agent visits against the week before, readers arriving from AI answers, impostors caught, and your readiness score with its change since the last note. It's on by default because the plugin's work is otherwise invisible, and it's built only from data already stored on your site — the email to your own inbox is the only thing that leaves the server. A week with nothing to report sends nothing. Stop it with the one-click link inside any of the emails, or under **Settings → Weekly email**, where you can also pick the day and time it arrives, change the address, and send yourself a test.

## Can AI help me write the description, topics and fixes?

Yes, if you're on **WordPress 7.0** and have connected an AI provider under **Settings → AI**. Agentimus then adds three helpers to the post editor:

- **Draft with AI** on the **AI description** field — writes the one-sentence summary of the page.
- **Draft with AI** on the **Topics for AI** field — suggests the page's key topics as chips.
- **Fix with AI** on any **Readability** row that is warning or failing — drafts a concrete fix for that specific issue.

Everything goes through WordPress's own shared connectors, so **Agentimus never sees or stores your API key** — the key stays in WordPress. If you haven't set up a provider, the buttons simply don't appear; nothing breaks and nothing nags you. And every suggestion arrives as ordinary editable text: you read it, change it if you want, and save it yourself. Nothing is written to your site on your behalf. See the **Write with AI** page for the details.

## Does AI Visibility use the AI provider I set up in WordPress?

No — AI Visibility needs its **own** keys, even if you've already connected a provider under Settings → AI. It isn't an oversight, it's what the feature measures.

A visibility check is graded on the **sources each engine cited** — which pages an answer leaned on, and whether any of them were yours. WordPress's shared connectors return only the **answer text**; the cited sources are dropped before Agentimus can read them. Reading those sources means going to each engine's own API with that engine's own key. So the two features keep separate keys, and both sets stay on your server.

## Is my data private? Can agents change my site through Agentimus?

Yes to privacy, and no to any new access.

- Agentimus only describes content your site **already makes public**. It does not expose drafts, private pages, user data, or anything behind a login.
- Removing or hiding an item in Agentimus changes what is *advertised* to AI tools — not what is reachable. The underlying pages and endpoints behave exactly as they did before, still behind their own authentication.
- The activity log is first-party and aggregate: it records that a bot fetched your content, without storing IP addresses or per-visitor records by default. (One optional setting, off by default, can store the IP of a *flagged* impersonating or spoofed crawler — never an ordinary visitor — so you can block it; see *Does Agentimus store IP addresses?* below.)

Out of the box, Agentimus grants agents **no** ability to write to, edit, or control your site — it is a read-and-describe layer. The one exception is explicit and yours to grant: an off-by-default switch on the MCP server card can let *signed-in* agents write (drafts, edits, categories, tags, featured images), with publishing behind a further switch of its own — see *Can an AI agent write to my site?* below. Anonymous visitors and crawlers can never write, switch or no switch.

## Does Agentimus run an MCP server?

Yes — as an opt-in, on WordPress 6.9 or newer. Turn on **Settings → Discovery → MCP server** and AI tools you already use (Claude, Claude Code, Cursor, ChatGPT, Codex) can talk to your site over the Model Context Protocol and run the same permission-checked tools your admin AI gets — twenty **read-only** ones (the ranked findings list, readiness, the pages worth fixing and what each is flagged for, the categories and tags your site already uses, who reached your site, AI traffic, search performance and opportunities, the request log, bot checks, internal-link suggestions and previews), plus the write tools if you separately allow those (next question).

Connecting is usually just the address. Give an assistant your server address and it asks *you* for permission on a page on your own site, where you choose **Read only** or **Read and write** — you can grant less than it asked for. Approve, and that assistant gets its own key and its own **Disconnect** button under **Connected assistants**. This works with Claude and Cursor today. An assistant that can't ask for permission — ChatGPT is one — takes a **shared connection token** instead: one secret you create in a click and send as a `Bearer` header. And the older path, one **application password** per tool, still works exactly as before.

Nothing becomes public: every request must arrive with one of those three keys, each tool keeps the same permission checks as the admin screen it comes from, every call is recorded under **More → Agent Access**, and turning the switch off disconnects connected tools immediately. With the switch off — the default — the server doesn't exist at all.

Worth knowing: an application password isn't scoped to this server — it signs in as that user across your whole REST API. Give each AI tool its own password, on a user with only the permissions it needs. Approved connections and the shared token are narrower: both work only on the MCP address, and nowhere else in WordPress. The full story, including how this relates to other plugins' MCP servers, is on the **MCP server** page.

## Can an AI agent write to my site?

Only if you say so, twice. The MCP server starts read-only; a second switch — **Let connected agents write** — adds the write tools: draft and edit posts and pages complete with categories, tags and a featured image (from your media library, or imported from a URL), set their AI topics and descriptions, and apply Readiness fixes (a fixed list of safe switches that can only turn documented features on, never loosen a protection). Even then, agents can't publish: they leave drafts and pending posts for your review, unless you flip a third switch that allows going live.

A key can also be narrower than the switches. When you approve an assistant, or create a shared token, you pick **Read only** or **Read and write** for that key alone — and a read-only key isn't even shown the write tools. No key ever goes the other way: publishing still needs the publish switch, whatever the key was granted.

Every write runs as the signed-in user — an agent can never do more than that user could in the editor; filing under existing categories, creating new ones, and uploading images each follow that user's own permissions — and every call is recorded under **More → Agent Access**, naming the door it came through. The details, including how edits to already-published posts behave, are on the **MCP server** page.

## Does Agentimus store IP addresses?

By default, **no** — and that was a deliberate design choice. The activity log records only technical request facts (the endpoint, a friendly agent label, a truncated User-Agent, and a UTC time), and the Traffic-from-AI card stores only per-day tallies. Neither keeps an IP address.

There is one optional setting for this: **Store IP addresses for flagged clients**, which is **off by default**. When you switch it on, Agentimus records an IP *only* for a client it has flagged as an **impersonating crawler** (one that claims to be Googlebot, Bingbot and the like but fails a reverse-DNS check) or a **legacy-device spoof/scanner** — never for an ordinary visitor. The point is practical: it shows you the exact addresses to block at your host or CDN.

When enabled, those IPs are personal data, but they stay tightly contained: kept in a separate store on **your own server only**, for a **short retention** (about 14 days), cleared whenever you clear the activity log, and deleted entirely if you turn the setting back off. Nothing is ever sent off your server. If you enable it, mention it in your site's privacy policy.

## What is llms.txt, and what do you mean by a "discovery layer"?

These two ideas are the heart of what Agentimus does, so it is worth a plain explanation.

**`llms.txt`** is a plain text file served at your site root (`yoursite.com/llms.txt`). Think of it as a table of contents written for AI assistants: it lists your key pages and posts in a clean, easy-to-read form, so a tool like ChatGPT or Claude can quickly see what your site is about and where to look. It sits alongside the familiar `robots.txt`, but where `robots.txt` says *what a crawler may fetch*, `llms.txt` helps an AI *understand and summarise* what it finds. There is also a fuller `llms-full.txt` that includes the actual content of your top pages.

A **discovery layer** is the broader set of machine-readable files Agentimus publishes so AI tools can find and interpret your site without guessing from your visual layout. Besides `llms.txt`, it includes:

- A **discovery document** — a structured description of your site (who you are, what it offers, where the important content lives).
- **Markdown versions** of your pages — a clean, plain-text rendering an AI can read without wading through your theme's HTML.
- **Structured data (JSON-LD)** in the page head.

The discovery document isn't a format private to this plugin — it implements the openly-licensed **WP_Discovery Protocol**, and Agentimus is its reference implementation. The point of the whole layer is simple: when an AI shows up, it gets a clean, accurate version of your site instead of scraping and misreading your page markup.

## Will Agentimus guarantee that ChatGPT cites me, or improve my "AI ranking"?

Honest answer: **no plugin can promise that, and Agentimus does not.** Here is the real split.

There are two halves to being cited by an AI:

1. **Being discoverable and correctly understood.** When an assistant does look at your site, can it find your content, read a clean version, and describe you accurately? **This is exactly what Agentimus controls, and it does it well.**
2. **Being spontaneously mentioned** when someone asks a broad question like "best resources for X." That depends on your site's **authority and reputation** — earned over time through genuinely notable content that other people reference.

No plugin, no `llms.txt`, and no schema can manufacture authority. Any tool promising "instant AI visibility" is overselling. What Agentimus guarantees is that when your authority *does* bring an AI to your door, nothing is lost in translation — you are read correctly and described in your own words.

A related feature worth knowing: the dashboard's **Traffic from AI** card counts real people who arrived from an AI assistant (detected from the referrer and the `utm_source` some AI tools add). Treat that number as a floor — "at least this many" — because some AI visits can't be detected at all.

## How do I turn features off?

Every output Agentimus produces has its own switch under **Settings**, so you can run only the parts you want. You never have to take an all-or-nothing approach. Toggles include:

| Feature | What turning it off does |
| --- | --- |
| `llms.txt` and `llms-full.txt` | Stops serving the AI table-of-contents / full-text files |
| Markdown mirror | Stops serving plain-text Markdown versions of your pages |
| Structured data (JSON-LD) | Stops printing the hidden data block (it already stands down for SEO plugins) |
| Topics for AI | Stops adding your per-page topic words to the AI layer |
| robots.txt management | Hands `robots.txt` back to WordPress/your other plugins |
| Sitemap | Stops the gap-only sitemap (already off when core/SEO provides one) |
| Activity log | Stops recording which bots fetched your content |

A few features are **off by default** and only run when you ask: the AI Visibility checks, the WebMCP browser bridge, `security.txt`, and the non-standard `noai` header. Whole-plugin off is just as easy — deactivate Agentimus from your Plugins screen and every file and header it served stops immediately; nothing is left behind on your public pages.

If you turn something on and it doesn't seem to appear, it is almost always working: the AI files are cached for up to an hour, so open the file directly (e.g. `yoursite.com/llms.txt`) and refresh, or use the Readiness report's **Verify live** button to see exactly what an AI tool receives right now.

## Does it work with page builders and custom post types?

Yes. Agentimus was built to cover real-world sites, not just plain posts and pages.

- **Page builders.** On a page Elementor or Beaver Builder owns, Agentimus asks the builder itself for the finished page; Divi, WPBakery, Gutenberg blocks and shortcodes render through WordPress's own standard content pipeline. Either way the Markdown and `llms.txt` output reflects your *finished* page, not raw shortcodes or builder markup. (For unusual custom renderers, developers can supply the content through a filter — see the Developer reference.) Builder pages are also protected from connected agents: an agent asking to replace such a page's body is refused with the honest reason — the body lives in the builder, so the agent is pointed there instead of being told "saved" about an edit no visitor would see. Everything else on the page (title, AI description, topics, tags, featured image) stays editable.
- **Custom post types.** Out of the box Agentimus exposes your **posts and pages**, which is the privacy-safe default. Any *public* custom post type — WooCommerce products, a portfolio type, a docs type, and so on — can be added to the mix from the post-type selection in Settings. It never silently exposes every custom type on its own, so nothing leaks by surprise; you choose what to include.

So whether you run a page-builder marketing site, a WooCommerce store, or a blog, Agentimus can describe the content that matters to you.

## A file isn't showing up — is it broken?

Almost always it is working. Two things to check:

- **Caching.** The generated AI files are cached for up to an hour. Open the file directly and refresh, or use **Verify live** on the Readiness report to fetch your real URLs from your browser and see what an agent actually receives — including anything your CDN is caching.
- **A static file is overriding it.** If a real `robots.txt` file exists at your site root, or your CDN serves its own, it overrides the one WordPress (and Agentimus) generates. The Readiness report flags this. Remove the static file to let Agentimus manage the rules.

For a full tour of every file, where it lives, and how to open it, see the **Machine-readable outputs** page.
