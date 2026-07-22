---
title: Installation
parent: User Manual
nav_order: 2
---

This page walks you through getting Agentimus onto your site: what your server needs, the two ways to install it, what happens when you activate it, the one-minute setup wizard that greets you on your first visit, and where to find everything afterwards. No technical knowledge is assumed — if you can install a plugin, you can do all of this.

## Before you start: requirements

Agentimus runs on the same modern WordPress most sites already have. You need:

| Requirement | Minimum |
| --- | --- |
| WordPress | 6.0 or newer |
| PHP | 7.4 or newer |
| A user account that can install plugins | An **Administrator** (the "manage options" capability) |

That's the whole list. There are no external accounts to create, no API keys to paste in, and nothing to buy. Agentimus works fully on an ordinary shared host.

A couple of things are nice to have but never required:

- **libsodium** (a common PHP extension) lets Agentimus cryptographically sign the machine-readable documents it publishes, so AI agents can verify they really came from you. If your server doesn't have it, that one feature quietly stands down and everything else works exactly the same.
- **The WordPress Abilities API** (built into WordPress 6.9+, or available as a separate plugin on older versions) lets Agentimus describe any "tools" your site exposes to agents. It's detected automatically and simply skipped when it isn't present.

You don't have to check for either of these. Agentimus feature-detects both and adapts on its own.

## Installing Agentimus

There are two ways to install, and both take under a minute. Most people should use the first one.

### Option A: from the WordPress.org plugin directory (recommended)

This is the normal, one-click route and it keeps you on the automatic-update track.

1. In your WordPress admin, go to **Plugins → Add New**.
2. In the search box, type **Agentimus**.
3. On the Agentimus card, click **Install Now**, then **Activate**.

That's it — skip ahead to *Activating the plugin* below (activation happens as part of step 3).

### Option B: uploading the ZIP file

Use this if you downloaded the plugin as a `.zip` (for example from the plugin's own site) or if you're moving it between sites.

1. Go to **Plugins → Add New**, then click **Upload Plugin** at the top of the page.
2. Click **Choose File**, pick the `agentimus.zip` file, and click **Install Now**.
3. When the upload finishes, click **Activate Plugin**.

Prefer to do it by hand over FTP or a file manager? Unzip the download and copy the resulting `agentimus` folder into your site's `/wp-content/plugins/` directory, then go to **Plugins** in your admin and click **Activate** under Agentimus. The end result is identical.

## Activating the plugin

Whichever route you took, clicking **Activate** is the moment Agentimus sets itself up. Behind the scenes, on activation it:

- Saves a set of **safe default settings** (see *Safe by default* below), so the plugin is useful immediately without you touching anything.
- Creates its own small database tables for the first-party activity log and (if you ever turn it on) AI Visibility.
- Registers the special `/.well-known/` web addresses that agents look for, and refreshes your site's URL rules once so those addresses work on the very first request.

None of this sends any data anywhere — it all happens locally on your own site.

**On a brand-new install, Agentimus then takes you straight to its own screen** and opens the setup wizard, instead of leaving you on the Plugins list. (This one-time redirect is skipped during bulk plugin activation and in network admin, and only happens for a genuinely fresh install — if you're upgrading or re-activating, Agentimus recognises your existing settings and never re-runs the wizard.)

## The first-run setup wizard

The wizard is the friendly heart of first-run. It opens automatically the first time you land on the Agentimus screen and walks you through everything in about a minute. You can change every answer later, so there are no wrong choices here.

You'll first see a short **Welcome** screen explaining what Agentimus does. Click **Get started** to begin — or **Skip for now** if you'd rather explore first (skipping is instant and you can re-run the wizard any time). The wizard then has three quick steps:

### Step 1: Who's behind the site

This is the single most valuable thing you can tell AI assistants, because it's what they'll quote when they mention you.

- **This site represents** — choose **A person** or **An organization**.
- **Name** — your name, or your business/brand name.
- **What is this site about?** — one plain sentence describing who you are and what you do. Assistants lift this almost verbatim, so keep it clear and human.
- **Topics you cover** (optional) — a few subjects you write about. Type one and press Enter to add it as a tidy chip.

### Step 2: What AI assistants can read

Here you choose which of your content types AI assistants are told about. **Posts and pages are already ticked** because they're safe and public. Anything else your site has (custom content from other plugins) is listed separately and left **off**, so private things like orders, form entries or customer records are never advertised unless you deliberately turn them on.

An important reassurance shown right on this step: this only controls what gets **advertised** to assistants. It never makes anything public that wasn't already public — the underlying content keeps its normal WordPress visibility either way.

Alongside choosing *which* content types to advertise, this step is also where you set your **AI stance** — three simple checkboxes for what assistants may do with your content: **findable in search engines** (Google, Bing…), **read and cited in answers** (with attribution), and **used to train AI** (left off, so training is reserved by default). Your choices are stated in your `robots.txt` as a **Content-Signal** — a polite request that well-behaved crawlers honour — and you can change them any time later under **Crawler policy** in Settings.

### Step 3: Review and finish

The last step is a plain-English summary: who your site represents, what assistants can read, and a short list of the strong protections that switched on **automatically** — such as being discoverable through machine-readable files, and (where your server supports it) signed responses agents can verify. When you've left training off, this list also confirms your content is **reserved from AI training**, reflecting the stance you set in Step 2. Click **Finish setup** and Agentimus saves everything in one go.

The closing screen then shows what just went live on your site, as clickable addresses: your `llms.txt` guide and the machine-readable discovery document — open either in a new tab and you'll find the words you typed a moment earlier already inside — together with your site's **starting readiness score**, which jumps to the Readiness tab so you can see what raises it. From that moment Agentimus is fully running.

### Skipping or re-running the wizard

You're never locked into the wizard. **Skip for now** closes it and marks setup as complete — the plugin keeps its safe defaults and keeps working. If you skip and later want to run through it again, open **Settings** and use the **Run setup again** option; it reopens the same steps pre-filled with your current answers, so it doubles as a quick way to review what assistants see.

## Finding the Agentimus menu

Once activated, Agentimus lives in your WordPress admin sidebar as a single top-level item, **Agentimus** (look for the small "A" monogram icon, sitting near Settings). Click it to open the plugin. Only Administrators see this menu.

Everything in Agentimus lives on that one screen, organised into **tabs across the top**. You switch between them by clicking; the tab you're on is remembered in the page address, so a browser refresh keeps you in place. The tabs are:

| Tab | What it's for |
| --- | --- |
| **Dashboard** | Your at-a-glance home: your AEO/GEO score (with the next thing to improve), and a private, first-party log of which AI agents and crawlers have been fetching your content (no IP addresses are stored by default; one optional, off-by-default setting can store a flagged crawler's IP so you can block it). |
| **AI Visibility** | An **opt-in, off-by-default** monitor that tracks whether ChatGPT, Perplexity, Gemini and Claude actually mention and cite you over time. It's the one feature that makes outbound calls, and only after you enable it and add your own API key. |
| **Settings** | Every switch and field: the machine-readable signals Agentimus publishes, and the identity details agents read. This is also where **Run setup again** lives. |
| **Readiness** | A plain-English pass / warn / fail checklist of how machine-legible your site is right now, with one-click jumps to fix anything missing — plus **Agent preview**, which shows the exact data an agent receives for any page. |
| **Discovery** | The single discovery document agents read to understand your whole site, with per-item control over what's published. |
| **About** | An honest, plain-language account of every feature, exactly what each one publishes, and a privacy section covering what Agentimus does and doesn't touch. |

> The setup wizard prompt above lists five core tabs; the **AI Visibility** tab is the sixth. It sits alongside the others but stays completely inert until you choose to enable it, so a fresh install behaves as if it weren't there.

### Two shortcuts worth knowing

- **The toolbar shortcut.** An **Agentimus** item appears in your WordPress toolbar (the black bar at the top) from any admin screen and the front end of your site, so the plugin is one click away wherever you are. It's hidden only when you're already on the Agentimus screen, and only Administrators see it.
- **The Plugins-screen link.** On your **Plugins** list, Agentimus adds a **Settings** link that jumps you straight to its Settings tab.

## Safe by default: what a fresh install does (and doesn't) do

A brand-new Agentimus install is deliberately cautious. Out of the box:

- It makes **no outbound HTTP requests**, loads no remote scripts or fonts, and sends no analytics or telemetry. Everything runs on your own server.
- The activity log records which bots fetch your content **without storing any IP addresses** or personal data by default. (One optional setting, off by default, can store the IP of a *flagged* impersonating or spoofed crawler — never an ordinary visitor's — on your own server so you can block it.)
- It **blocks nothing** — Agentimus is a discovery layer, so every agent is served until you deliberately turn on the optional scanner/agent blocking.
- The features that publish clean, machine-readable versions of your public content (the llms.txt files, markdown delivery, JSON-LD structured data, the fallback sitemap, and per-page topics) are **on**, because they only ever describe content that's already public. The fallback sitemap even stands aside automatically if WordPress core or an SEO plugin already provides one.
- The genuinely powerful or experimental switches — publishing a `security.txt` contact file, the browser-tools (WebMCP) bridge, and the exposure-hardening controls — all ship **off**, waiting for you to opt in.
- **AI Visibility** ships off and stays off until you enable it and supply your own provider API key. It is the only part of Agentimus that ever contacts an outside service.

In short: activate it, run the one-minute wizard (or skip it), and your site is already sensibly configured with nothing leaking and nothing phoning home.

## A note for multisite networks

If you network-activate Agentimus across a WordPress Multisite, each existing site gets the same one-time setup — its own tables, safe defaults and web-address rules — and any new site added later is set up automatically the moment it's created. Each site keeps its own separate settings and its own AI Visibility configuration.

## Troubleshooting a fresh install

- **The Agentimus screen shows "The admin interface has not been built yet."** This only appears if you're running the plugin straight from its source repository rather than a packaged release. The versions from WordPress.org and the official ZIP ship with the interface already built, so you won't see this — use one of those, or, if you're a developer, run `npm install && npm run build` in the plugin folder.
- **A `/.well-known/` or `/llms.txt` address returns "not found" right after activating.** This is almost always your site's URL rules needing a refresh. Visit **Settings → Permalinks** in your admin and click **Save Changes** once (you don't need to change anything) to rebuild them.
- **You don't see the setup wizard.** The wizard only appears on a genuinely fresh install. If Agentimus detects settings from a previous install or an upgrade, it treats you as already set up. You can always open the same steps from **Settings → Run setup again**.
