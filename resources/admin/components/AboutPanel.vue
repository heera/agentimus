<script>
import SelectMenu from './SelectMenu.vue';

export default {
  name: 'AboutPanel',
  components: { SelectMenu },
  props: {
    version: { type: String, default: '' },
    // { name, version, hook, specUrl, schemaUrl } — sourced from PHP so it
    // mirrors the real constants instead of hand-copied strings.
    protocol: { type: Object, default: () => ({}) },
    // Whether the About tab is the one on screen. The panel stays MOUNTED on
    // other tabs (App uses v-show), so anything it teleports into the shared
    // page header — and the scrollspy behind it — must gate on this, or the
    // jump menu haunts every other screen's header.
    active: { type: Boolean, default: true },
  },
  emits: ['navigate'],
  data() {
    return {
      openFaq: 0,
      // The "On this page" menu doubles as a position indicator: this holds the
      // anchor of the section currently under the header, kept in sync by the
      // scrollspy — scrolling moves it, picking from the menu jumps to it.
      tocCurrent: '',
      // True while a picked jump is smooth-scrolling: the spy stands down so
      // the label shows the DESTINATION, not every section flying past.
      spyLock: false,
      // The jump menu teleports into the sticky page header. On the panel's
      // INITIAL mount (a direct #about load) the header target isn't in the
      // document yet — a Teleport that mounts then silently renders nothing —
      // so it waits for this flag, flipped in mounted(), when everything is.
      teleportReady: false,
      // The working manual, screen by screen — kept in the SAME order as the
      // nav (the four bar tabs, then the More menu top to bottom), so the
      // manual reads like the plugin walks.
      screens: [
        {
          id: 'dashboard',
          title: 'Dashboard',
          where: 'first tab',
          purpose: 'The day’s answer at a glance: your AEO/GEO score with the single most useful next step, endpoint activity by day, your busiest agent-facing files, and recent requests. The one-time “Worth a look next” card after setup and the once-per-release “What’s new” card live here too — never anywhere else in wp-admin.',
          actions: 'Click a day’s bar for that day’s full report. Follow the score card’s “Next:” line straight to the thing it names. Answer the review bell when a new client wants a verdict. Tiles drill into their report screens with the filter carried along.',
          facts: [
            { k: 'info', t: 'The score’s Cited rung only counts when citation tracking is on; otherwise its weight is redistributed, so you are never penalised for a feature you don’t use.' },
            { k: 'info', t: 'A blocked verdict on the score card means one thing: WordPress’s “Discourage search engines” switch is on. Nothing else scores until that master switch does — the card links the fix.' },
            { k: 'tip', t: 'On a local site that verdict reads “Not public yet” in calm amber, not red — being unreachable is the expected state of a development site, and it only matters at launch.' },
          ],
        },
        {
          id: 'settings',
          title: 'Settings',
          where: 'Settings tab',
          purpose: 'Every switch the plugin has, grouped and explained where it sits — identity, content and policy, trust and verification, data sources, the MCP server. What each readiness row links to lives here.',
          actions: 'Flip switches and type — changes autosave (switches immediately, text as you pause). Manage clients holds every allow/block/ignore decision with an undo. Data sources connects Cloudflare and Bing with read-only credentials. Run setup again replays the wizard over your current answers. Reset shows a preview of exactly what would change before it does.',
          facts: [
            { k: 'warn', t: 'The write tier is deliberately nested: turning the MCP server off also turns “Let connected agents write” off. Write access can never stay armed invisibly under a switched-off server.' },
            { k: 'info', t: 'With the MCP server on, the handshake is public: anyone may ask the server its name and its read-tool list — the same information mcp.json already publishes. Running any tool still signs in, and an anonymous asker is shown fewer tools than any credentialed caller: the write tools stay out of its list entirely.' },
            { k: 'info', t: 'Connection secrets — the Cloudflare token, the Bing key, the Google service-account key, visibility keys — are stored encrypted at rest and are never echoed back in a REST response after saving.' },
            { k: 'info', t: 'Google connects through a service-account key you mint in your own Google Cloud and paste here, not a “Connect with Google” button. That button, in other plugins, routes your data through the plugin maker’s server; this key talks to Google directly from your site and is revocable in your own console. The trade is a five-minute setup, walked through step by step on the card.' },
            { k: 'info', t: 'The trainer blocklist and the reading policy are separate ideas: blocking a training crawler hides nothing from readers, because assistants fetch reader requests with different agents. Google is the documented exception — one token governs both.' },
            { k: 'tip', t: 'You rarely need to browse here: every readiness fix that is a switch links straight to its exact field, and the wizard set the important ones already.' },
          ],
        },
        {
          id: 'readiness',
          title: 'Readiness',
          where: 'Readiness tab',
          purpose: 'Every check the score is built from, grouped by rung — Findable, Readable, Trusted — each row saying what it found, what to do, and where to do it. Below them, Optimize grades each page’s citability, Search Opportunities lists pages that already rank but under-earn their clicks, and Agent preview shows the exact JSON-LD and Markdown any page serves to a machine.',
          actions: 'Work the rows: each warn or fail carries plain-words advice and a link that lands on the exact field or screen that fixes it. Run “Verify live” to fetch your own files the way an agent would. Scan for exposed files. In Optimize, open a page to fix it in the editor, or set it aside as not-cited content — counted visibly, never hidden. In Search Opportunities, each group says what to do about it, and the page opens on the “Search & AI” box where its title and description live.',
          facts: [
            { k: 'info', t: 'Advice is state-aware. A thin llms.txt points at your Identity settings only until your profile is in the file — after that it points at publishing content, because that is the only lever left.' },
            { k: 'info', t: 'Search Opportunities only appears once a search source is connected, and it judges “not clicked enough” against your OWN page-one click rate — your site’s average, never an industry benchmark. Pages set aside here keep their own list, separate from Optimize’s: excusing a page from citability grading and from search suggestions are different decisions.' },
            { k: 'info', t: 'The “Verify live” fetches are deliberately anonymous — they grade what an agent receives, not what an admin sees — and they carry a signed token so they stay out of your own Request Log.' },
            { k: 'warn', t: '“Could not verify” is informational, never a verdict against your site: probe data fails open, and a stale good result outranks a fresh failure to fetch.' },
            { k: 'info', t: 'Checks read stored data; the llms.txt and home-page probes run in the background twice a day (and after plugin or theme changes), so a route silently taken over by another plugin is noticed without slowing your admin.' },
            { k: 'tip', t: 'Fixes made in another tab — a site icon in the Customizer, Reading settings — show up here on return, within moments, without a reload.' },
          ],
        },
        {
          id: 'discovery',
          title: 'Discovery',
          where: 'Discovery tab',
          purpose: 'The registry of everything your site publishes for machines — llms.txt and the full-text edition, discovery.json, the agent card, mcp.json — with live links and a validity check across every registration.',
          actions: 'Open any document to read exactly what agents read. Re-scan after changing plugins. Third-party plugins that speak the WP_Discovery protocol appear here alongside Agentimus’s own surfaces.',
          facts: [
            { k: 'info', t: 'A 404 on a discovery URL can be by design: some documents only exist while their feature is on, so an absent file often means “switched off”, not “broken”. The validity line tells the difference.' },
            { k: 'info', t: 'With signing on, the discovery documents carry RFC 9421 signatures — an agent can verify they really came from your server, unaltered.' },
            { k: 'info', t: 'The registry is open: any plugin can register its machine surfaces with one hook (the snippet is in the protocol section below), and they get listed and validated like the built-ins.' },
            { k: 'info', t: 'Two documents are written for agents to act on, not just index: auth.md explains how to sign in — rebuilt from your live settings on every request, so it cannot drift from what the server really does — and the agent skill under /.well-known/agent-skills packages the whole site with explicit “use when” guidance.' },
          ],
        },
        {
          id: 'visibility',
          title: 'AI Visibility',
          where: 'More → AI Visibility',
          purpose: 'Three answers on one screen: can AI search find you (Bing’s index, which ChatGPT search and Copilot read today, and Google’s index, which AI Overviews and Gemini read), how did classic search actually do for you (Search Performance — what was searched for, how often you appeared, how often those results were clicked), and do assistants cite you when asked the questions your buyers ask.',
          actions: 'Connect Bing Webmaster with one read-only key — Agentimus prints the verification tag for you — and read index, crawl and error numbers day by day. Connect Google Search Console and the In Google’s Index card checks your key pages daily. Read Search Performance for your top searches and top pages, switching between Google and Bing when both are connected. Turn on citation checks inside the Citations tab, set the questions (or let AI suggest a spread), and track mentioned, linked, ranked against rivals.',
          facts: [
            { k: 'info', t: 'Search Performance and Search Opportunities (on Readiness) read the same stored numbers through opposite lenses: this screen answers “how am I doing?”, that one answers “what should I fix?”.' },
            { k: 'info', t: 'The In Google’s Index card checks in two tiers, because Google has no bulk index report and allows 2,000 URL inspections a day: a daily watchlist (homepage, busiest pages, newest posts — every answer shown) plus a whole-site rotation of up to 100 pages a day. Small sites get every page checked daily; big ones get an honest stated cadence. Healthy rotation pages become one count — only problems become rows.' },
            { k: 'info', t: 'There is no “Request indexing” button anywhere, deliberately: Google’s Indexing API accepts only job-posting pages, and the Search Console button has no API. Every problem row links straight to that URL’s inspection in Search Console instead, where the real button is one click away.' },
            { k: 'warn', t: 'Google and Bing are never merged into one figure — they count different searchers, so a blended number would be one neither engine ever reported. The card names which one you are reading, and switches between them when both are connected.' },
            { k: 'info', t: 'Citation checks deliberately use their own keys, not WordPress’s shared AI provider: shared connectors hand back only answer text, and grading needs the list of cited sources each engine returns on its own API.' },
            { k: 'info', t: 'Every tab stores its history in your own database, so it keeps growing where the vendors’ own reporting windows end.' },
            { k: 'tip', t: 'Good tracked questions never mention your name. The point is to learn whether an assistant reaches for you when the buyer didn’t.' },
          ],
        },
        {
          id: 'ai-traffic',
          title: 'AI Traffic',
          where: 'More → AI Traffic',
          purpose: 'The human half of the story: readers who arrived from an AI assistant’s answer — daily counts by assistant and by the page they landed on. The Request Log shows machines reading you; this screen shows the people those machines sent.',
          actions: 'Pick the window, see which assistants send readers and to which pages. The weekly email draws its “readers from AI” lines from here.',
          facts: [
            { k: 'info', t: 'Counts are daily aggregates — a total per day, per source, per landing page. Never a row that stands for one person, no IP addresses, no identities.' },
            { k: 'info', t: 'Attribution is honest and therefore conservative: agentic browsers often arrive looking like Direct traffic, so real AI-sent readers can be undercounted — they are never invented.' },
            { k: 'info', t: 'Your own clicks out of assistant chats are skipped — the plugin re-checks the admin cookie even on cached pages, so the owner never inflates their own numbers.' },
          ],
        },
        {
          id: 'log',
          title: 'Request Log',
          where: 'More → Request Log',
          purpose: 'Every request to your agent-facing files — llms.txt, the markdown twins, the discovery documents — and the visits recognised AI agents make, each one named, verdicted and timestamped. This is the plugin’s ground truth: when a wizard proof, a pin or a chart claims something happened, the row that proves it lives here.',
          actions: 'Filter by client, endpoint, network or verdict. Click a user-agent to copy it. Ask “check this bot” to verify a claimed identity against the operator’s own published checks. Allow, block or ignore a client from the review bell — Manage clients (Settings → AI access) keeps every standing decision with an undo. Clear the log any time.',
          // Fact kinds carry the annotation: info = a notice worth knowing,
          // warn = a caution that prevents a wrong conclusion, tip = a lightbulb.
          facts: [
            { k: 'info', t: 'You are never in your own log: logged-in administrators are skipped, and the plugin’s own self-checks carry a signed token that keeps them out — the user-agent alone is never trusted for that, because anyone can wear one.' },
            { k: 'warn', t: 'Names are claims. Recognition reads the user-agent, which is forgeable — that is exactly why verdicts exist: Verified means the operator’s own published checks confirmed the address, Spoofed means they disproved it, Unchecked means nobody has looked yet.' },
            { k: 'info', t: 'Retention and the report window are different facts: rows are kept for as long as you choose (30 days by default); the screen shows at most the last 30 days of what is kept. A size cap outranks both, so the table can never grow without bound.' },
            { k: 'info', t: 'Refusals are recorded. A client that was turned away still gets its row — a proven impostor can’t be refused in silence.' },
            { k: 'info', t: 'No IP addresses are stored by default; the optional flagged-clients setting is the single, disclosed exception (see Privacy below).' },
            { k: 'tip', t: 'A quiet log on a fresh site is normal — crawlers usually find your files within a day or two, and every visit from then on counts up here.' },
          ],
        },
        {
          id: 'agent-access',
          title: 'Agent Access',
          where: 'More → Agent Access',
          purpose: 'The audit trail of credentialed agents — every key minted, every ability used, every refusal. If the Request Log is who knocked, this is what the ones you let in actually did.',
          actions: 'Watch events roll up per credential with first-seen and last-seen. A NEW pill flags a key’s first appearance — and the nav badge re-lights when something new lands. Disconnect an approved assistant from the MCP card; revoke an application password from the user’s profile.',
          facts: [
            { k: 'info', t: 'Every connected agent acts as the WordPress user behind its credential — it can never do more than that user could on these screens, and each ability keeps its own permission check on top.' },
            { k: 'info', t: 'Refusals are events too: a key that tried something it isn’t allowed to do shows up here saying exactly that. Silence is never how a denial ends.' },
            { k: 'info', t: 'Writes only exist while “Let connected agents write” is on, drafts-only unless you also allow publishing — and every write lands in this feed either way.' },
          ],
        },
],
      // Documentation of what the plugin publishes. "Default" is the shipped
      // state; the live on/off for each lives on the Settings tab.
      featureGroups: [
        {
          title: 'Guided Setup',
          lead: 'A couple of minutes from install to a working, measured site — the wizard collects what matters, fixes what it can, and proves the whole thing live.',
          items: [
            { name: 'Setup wizard', where: 'first run · Settings → Run setup again', desc: 'Five short steps that gather everything the checks below will later ask for: who you are (name, role, profile sentence — pre-filled from what WordPress already knows), the topics, profiles and audience that help AI trust you, the services you offer, and what AI may read and do with it. Skipping is safe too: sensible defaults stay on and your site’s tagline seeds the profile, so even a skipped setup speaks.', tag: 'On' },
            { name: 'Guided fixes', where: 'setup wizard', desc: 'Where a WordPress setting stands in the way, the wizard offers the fix as a one-tick choice instead of homework — “Discourage search engines” being the classic. Pre-ticked on live sites, where it’s almost always a leftover; explained and left unticked on local ones. Nothing is ever changed without the tick.', tag: 'On' },
            { name: 'Proof, then a map', where: 'end of setup · Dashboard', desc: 'On a public site, setup ends with proof: ask ChatGPT, Claude, Perplexity or Grok about your site and watch the visit land in your own Request Log, live on the screen — with honest reasons named when nothing arrives. Afterwards a one-time dashboard card points at the rooms worth a look next — AI Visibility, Cloudflare, connecting an assistant — and leaves the choosing to you.', tag: 'On' },
          ],
        },
        {
          title: 'AEO/GEO Score & Optimization',
          lead: 'Your dashboard turns everything below into one score — and the single next thing to improve.',
          items: [
            { name: 'AEO/GEO score', where: 'Dashboard', desc: 'One 0–100 score across five rungs — Findable, Readable, Trusted, Optimized and Cited — with a plain-language next step and an honest per-rung “n to fix” count. Each rung links to where you act on it.', tag: 'On' },
            { name: 'Optimize Your Content', where: 'Readiness → Optimize', desc: 'Per-page citability checks (enough substance, something concrete to quote, quotable passages, a featured image, freshness) with a worklist of the pages to improve. Articles only — commerce products, commerce plugins’ own pages (cart, checkout, account) and container pages that are just a shortcode or plugin block with no authored prose are left out. Grading is fair to technical writing: your subject’s recurring terms read as familiar and code samples aren’t graded as prose. Set aside anything not meant to be cited.', tag: 'On' },
            { name: 'AI readability tips', where: 'post editor', desc: 'The same per-page citability tips in an editor panel while you write. Editor-only — nothing is shown to visitors.', tag: 'On' },
            { name: 'Link to your own posts', where: 'post editor', desc: 'Suggests which of your own posts this one should link to — found from signals your site already has (shared topics, categories and tags, a post’s subject appearing in your text), so it works instantly with no AI at all. With an AI provider connected, one request per click picks the exact phrase to link and explains each suggestion. Insert links the phrase right where it sits in your text — an ordinary editor edit you can undo, saved only when you save the post. Also available to agents as a read-only MCP tool.', tag: 'On' },
            { name: 'Evergreen content', where: 'Settings', desc: 'Mark categories whose posts are timeless (references, tutorials, legal) so they’re exempt from the freshness check.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Write With AI',
          lead: 'Optional — use the AI provider you’ve connected in WordPress (Settings → Connectors, with your own key) to draft and fix the AEO/GEO fields as you write. Everything routes through WordPress’s built-in AI Client, so Agentimus never handles your key; every suggestion is editable and nothing is saved for you. The buttons stay hidden until a provider is set up.',
          items: [
            { name: 'Writing assistant', where: 'the quill button, every Agentimus screen', desc: 'Describe the post you want; the assistant proposes an outline you can edit, then writes every section of the article in parallel — the outline fills in as parts land, long posts aren’t capped by a single AI response, and a failed section retries alone. The assembled draft — real editor blocks, AI description, topics, suggested categories and tags — is shown to you before a single thing is saved. Create draft opens the post in the editor, where image placeholders arrive with their alt text filled in: a Generate button turns any image block’s alt text into an image, a Featured image (AI) panel drafts the hero from the title, and Ask AI rewrites, shortens or extends any text block. It also revises an existing post you pick — content changes, a post’s status never does, and WordPress revisions keep every prior version. Needs “Let connected agents write” plus an AI provider; it never publishes.', tag: 'Opt-in' },
            { name: 'Draft with AI', where: 'post editor', desc: 'One click drafts the AI description for a page from its content (and “Suggest with AI” does the same for its topics), dropping the result into the field as editable text to accept or change.', tag: 'Opt-in' },
            { name: 'Fix with AI', where: 'post editor', desc: 'On each AI-readability warning, drafts a concrete fix — an opening summary you can insert with one click, a missing featured image generated with one click from the post’s title, or a heading outline / shorter quotable passages to copy in. Advisory items that add facts stay copy-only, so nothing unverified is inserted for you.', tag: 'Opt-in' },
            { name: 'Agentimus abilities', where: 'WordPress admin AI · MCP', desc: 'Exposes read-only abilities — your readiness score, AI traffic, request log, edge traffic from Cloudflare, AI-search visibility from Bing, bot checks, and page / JSON-LD / Markdown previews — to WordPress’s built-in AI and, when you turn on the MCP server below, to external AI agents. A second, separate switch adds write abilities (draft/edit content, set AI topics and descriptions, apply Readiness fixes); until you flip it, they don’t exist on any surface. Each ability is permission-gated exactly like the screen it comes from.', tag: 'Auto' },
            { name: 'Content Guidelines aware', where: 'post editor · MCP', desc: 'If your WordPress defines Content Guidelines (an experimental WordPress feature: brand voice, copy and image rules), every AI writing surface honors them automatically — Draft, Suggest and Fix follow them, and an agent connected over MCP sees them right on the draft/edit tools, so it writes in your voice too. Detected automatically, nothing to configure; your guidelines never appear on any public endpoint.', tag: 'Auto' },
          ],
        },
        {
          title: 'Share & Ask AI',
          lead: 'For the moment a post is done: retell it for every network, and let readers hand it to their assistant — with the assistant’s visit landing in your own log.',
          items: [
            { name: 'Share drafts', where: 'post editor → Share tab', desc: 'One card per network — X (Twitter), Facebook, LinkedIn, WhatsApp, Telegram and Reddit — each holding a ready-to-post draft written from the post itself (its title, AI description and topics), shaped to where it’s going: short for X, title-plus-description for Reddit, a message that reads like a message for WhatsApp and Telegram. No AI needed — drafts assemble locally and instantly; with a provider connected, a per-card polish rewrites that one draft in the network’s register, and you keep either version. Copy puts a draft on your clipboard; Open launches the network’s own composer prefilled — Facebook and LinkedIn don’t accept prefilled text, so for those Open copies first and the card says to paste. Nothing is ever posted for you, and readers never see any of this.', tag: 'On' },
            { name: 'Ask AI about this post', where: 'after each post', desc: 'A quiet row of buttons — ChatGPT, Claude, Perplexity, Google AI Mode and Grok — each opening the reader’s assistant with a question about this post already filled in. Plain links: no script runs on your page, nothing is loaded from any assistant, and nothing is sent anywhere until a reader clicks. The payoff is on your side of the log too: the assistant has to fetch your page to answer, and that fetch shows up in your own Request Log — the loop closes on your own site. The row also obeys your own bot policy: an assistant your blocklists forbid from reading the page loses its button, rather than shipping one that can only fail (see the Q&A below for why that’s Google today). Posts only by default; one switch under Settings → Discovery removes the row everywhere.', tag: 'On' },
          ],
        },
        {
          title: 'Discovery Documents',
          lead: 'The core job — standard files that let an agent find and understand your site.',
          items: [
            { name: 'Discovery manifest', where: '/.well-known/discovery.json', desc: 'The master document describing your site, content and capabilities.', tag: 'On' },
            { name: 'Agent card', where: '/.well-known/agent-card.json', desc: 'Agent-to-agent (A2A) identity card, also served at agent.json.', tag: 'On' },
            { name: 'API description', where: '/.well-known/openapi.json · /api-catalog', desc: 'OpenAPI 3.1 spec and an RFC 9727 catalog of your public REST API — every operation carries a stable id, a description and a typed error shape, the details function-calling toolchains read.', tag: 'On' },
            { name: 'MCP manifest', where: '/.well-known/mcp.json', desc: 'Advertises Model Context Protocol servers running on your site — Agentimus’s own, or another plugin’s — with their tools and per-server cards.', tag: 'Auto' },
            { name: 'MCP server', where: '/wp-json/agentimus/v1/mcp', desc: 'Off by default. One switch (Settings → Discovery) runs a Model Context Protocol server on your own site — everything needed ships with the plugin — so the AI assistants you already use can run the fourteen read-only Agentimus tools above. A second switch (off by default) adds the write tools — draft/edit content, set AI topics and descriptions, apply Readiness fixes — and a third decides whether agents may publish or only leave drafts for review. Anyone may ask the server what it is: the handshake answers with its name and read-tool list, the same information mcp.json already publishes, so an assistant can see what’s offered before connecting. Running anything signs in: each tool keeps the same permission checks as its admin screen, every call is recorded under Agent Access, and turning the switch off disconnects connected assistants immediately.', tag: 'Opt-in' },
            { name: 'Connect an assistant', where: 'Settings → Discovery → MCP Server', desc: 'Give an assistant only your server address and it asks you for permission on a consent page served by your own site: it names what it calls itself and where it will return, and you choose Read only or Read and write — you may grant less than it asked for. Approving gives that one assistant its own key; it appears under Connected assistants with its scope, its last call and its own Disconnect, so cutting one off leaves the others working. The flow is the OAuth 2.1 standard with PKCE and dynamic client registration, served entirely by your site — no third party brokers it, and Agentimus stores only a fingerprint of every key. Assistants that cannot ask for approval use a shared token instead (one secret, read-only or read-and-write, shown once, revocable), or a WordPress application password. Whichever door it came through, a read-only key is shown only the read tools, and no key can exceed your write settings.', tag: 'Opt-in' },
            { name: 'How agents sign in (auth.md)', where: '/auth.md', desc: 'A plain-markdown page telling agents how to authenticate — generated from your live settings on every request, so it can never promise a flow you don’t run. It walks the standard auth.md shape (discover, pick a method, register, claim, use the credential, errors, revocation), states outright whether public registration exists, and while the MCP server is off it simply says reading is public and changes stay with you.', tag: 'On' },
            { name: 'Agent skill (SKILL.md)', where: '/.well-known/agent-skills', desc: 'Your site packaged as an installable agent skill (the agentskills.io format): what it offers, the addresses that matter, and explicit “use when” guidance — assembled live from what’s actually switched on, named after your own domain, and linked from llms.txt so agents find it without guessing.', tag: 'On' },
            { name: 'Browser tools (WebMCP)', where: 'your public pages', desc: 'Off by default. Lets an AI agent working inside a visitor’s browser call your site’s read-only tools (like site search) directly, via the emerging WebMCP browser standard. It adds one tiny, self-hosted script that stays inert in browsers without support, and each tool can be shown or hidden individually in Settings.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Machine-Readable Content',
          lead: 'Your existing content, offered in formats agents read cleanly.',
          items: [
            { name: 'llms.txt', where: '/llms.txt', desc: 'A plain-text index of your site and recent content.', tag: 'On' },
            { name: 'llms-full.txt', where: '/llms-full.txt', desc: 'A full-text bundle of your pages and posts, within a size budget.', tag: 'On' },
            { name: 'Markdown', where: '/{slug}.md', desc: 'Clean Markdown of any page, at its own address — a separate URL, so a cache can never confuse it with the page a reader sees. (Answering the page URL itself with Markdown via an Accept header is opt-in; see the docs.)', tag: 'On' },
            { name: 'Change feed', where: '/agentimus-changes.json?since=', desc: 'What changed lately — added, updated and removed content as JSON, with a ?since= filter so an agent re-checks only the delta instead of recrawling the site. Removals are announced honestly (tombstones), it’s one bounded, cached query, and it’s advertised in discovery.json so agents find it on their own.', tag: 'On' },
          ],
        },
        {
          title: 'Structured Data & Crawl Signals',
          lead: 'Standards search engines and agents already understand.',
          items: [
            { name: 'JSON-LD schema', where: 'in your page <head>', desc: 'schema.org WebSite, Person/Organization, articles, breadcrumbs and FAQ — plus speakable markup on posts, naming the headline and lead a voice assistant should read aloud.', tag: 'On' },
            { name: 'Topics for AI', where: 'per post', desc: 'Per-page topics → JSON-LD keywords and about entities (linkable to Wikidata/Wikipedia) plus a Markdown line, so assistants know exactly what each page is about.', tag: 'On' },
            { name: 'AI description', where: 'per post', desc: 'A one-line summary → the JSON-LD description, the Markdown lead, and your page’s meta description (replacing your theme’s, unless a dedicated SEO plugin owns it). Blank pages fall back to the excerpt, or a short summary of the page.', tag: 'On' },
            { name: 'Search basics', where: 'Settings → Discovery', desc: 'With no SEO plugin installed, Agentimus covers the search essentials itself: a per-page “SEO title” field in the editor, Open Graph/X share cards (featured image → your chosen default → Site Icon), and canonical links on the views WordPress leaves bare. Install an SEO plugin and every one of these steps aside automatically — nothing is ever emitted twice — and a dashboard card names the division of labour.', tag: 'On' },
            { name: 'robots.txt', where: '/robots.txt', desc: 'Adds Content-Signal directives and advertises your sitemap.', tag: 'On' },
            { name: 'XML sitemap', where: '/wp-sitemap.xml', desc: 'With no SEO plugin, Agentimus serves your sitemap — including the last-changed dates WordPress core’s own leaves out — at WordPress’s standard address, so a sitemap registered in any search console keeps working. Older addresses (/sitemap.xml, /agentimus-sitemap.xml) redirect there. With an SEO plugin installed, it stands down and links theirs instead.', tag: 'On' },
          ],
        },
        {
          title: 'AI Rights & Opt-Out',
          lead: 'Tell AI systems how your content may be used.',
          items: [
            { name: 'Do-not-train signals', where: '/.well-known/tdmrep.json · tdm-reservation header', desc: 'Signals “don’t train on this” while still allowing reading (W3C TDM).', tag: 'On' },
            { name: 'noai header', where: 'X-Robots-Tag: noai', desc: 'An extra opt-out header on content pages.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Trust & Verification',
          lead: 'Prove the documents really came from you.',
          items: [
            { name: 'Verified responses', where: '/.well-known/http-message-signatures-directory', desc: 'Signs discovery docs (RFC 9421) so agents can verify they’re from you and unaltered.', tag: 'On' },
            { name: 'OAuth metadata', where: '/.well-known/oauth-protected-resource', desc: 'Points agents at your authorization server (RFC 9728) — the one you declare, or the plugin’s own consent flow automatically while the MCP server is on. A document another plugin registers for this address is never shadowed.', tag: 'Auto' },
            { name: 'security.txt', where: '/.well-known/security.txt', desc: 'A standard security contact for your site (RFC 9116).', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Protection',
          lead: 'Shape who reaches your endpoints — all on your server.',
          items: [
            { name: 'Agent guard', where: 'your generated endpoints', desc: 'Blocks (403) denylisted or spoofed agents at the documents above — and, with verification on, proven impostors: clients claiming a verified bot whose address conclusively fails that operator’s own published check, or carrying a cryptographic signature that fails the math (see Web Bot Auth below). Anything unclear is served, never guessed at, and your Allow and Block lists always outrank the machinery.', tag: 'Opt-in' },
            { name: 'Web Bot Auth', where: 'every request, when “Verify bot identities” is on', desc: 'The emerging standard where AI agents cryptographically sign their requests (Google’s agent and OpenAI already do). Agentimus checks the math on your own server — the only outside fetch is the operator’s public key, cached — so a signed request from a recognised operator is proven genuine, a failed signature is a caught forgery, and the many crawlers that don’t sign yet are simply unsigned, never penalised. The mirror of the plugin’s own document signing: Agentimus signs what it serves and verifies who’s asking.', tag: 'Opt-in' },
            { name: 'Verified bots', where: 'Settings → AI access', desc: 'Optionally confirm a visitor claiming a known bot really is that bot, using what its operator publishes: forward-confirmed reverse DNS (Googlebot, Bingbot, Applebot, DuckDuckBot, Yandex) and published IP-range files (GPTBot, OAI-SearchBot, PerplexityBot — refreshed once a day in the background, never while serving a visitor). The list is yours to edit: switch any bot off, or add a new operator yourself the day it starts publishing verification data.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Who’s Reading You',
          lead: 'AI traffic in every direction: agents taking your content, readers AI sends back, and who authenticates to or acts on the machine surface Agentimus creates. All counted on your own site — no IP addresses by default, nothing sent anywhere.',
          items: [
            { name: 'Agent activity', where: 'Dashboard', desc: 'Which AI crawlers fetched which of your files, how often, and when — with a review queue for clients worth a second look. The queue’s count also rides the Agentimus entry in the admin menu as a red badge, visible from every admin screen and kept current while you work — a flagged crawler shows itself without you opening Agentimus. Every endpoint and client row opens the Request Log pre-filtered to it, and hovering a row shows the counts behind its trend arrow. Every standing decision from that queue (blocked, trusted, ignored) is managed in one place, with dates: Settings → AI access → Manage clients. A client refused at the door still gets a row — marked “refused” and counted toward none of your read totals — so turning enforcement on never hides an impostor from you.', tag: 'On' },
            { name: 'Request Log', where: 'More → Request Log', desc: 'Every recorded request, one row each. Filter by client, endpoint, network, user-agent and date to see exactly what a single bot fetched.', tag: 'On' },
            { name: 'At Cloudflare', where: 'Request Log · Settings → Data sources', desc: 'Connect Cloudflare with one read-only token and the Request Log grows its missing half: what Cloudflare saw from AI crawlers before your server did — how far each crawler’s requests got (answered from Cloudflare’s cache, reached your server, blocked), and how much data each AI company pulled. When Cloudflare’s behaviour contradicts your declared policy — blocking a crawler you welcome, waving through trainers you’ve opted out of — a pinned warning names it, links the exact Cloudflare screen, and retires by itself within a day of the fix; hide a pin and it stays readable in Settings until the situation really ends. Numbers are copied hourly into your own database, so your history outlives Cloudflare’s short window, and a Refresh link polls on demand. Read-only by design — Agentimus never changes your Cloudflare settings.', tag: 'Opt-in' },
            { name: 'Agent Access', where: 'More → Agent Access', desc: 'Who authenticates to, and acts on, the machine surface Agentimus creates: assistants being approved; keys being created, first used, renamed or revoked; abilities being run; and requests that were refused, or that probed for abilities which don’t exist. Every row says who — the user and the door the call came through, resolved live: an approved assistant’s own connection, the shared token, or a named application password (a renamed key shows its current name, a revoked one says so plainly). A record, not a guard — nothing here blocks. It stores no IP addresses, so it names the key that was used, not the person, and it sees machine logins only — a normal password sign-in never appears. A brand-new application password is the one worth a second look: it keeps working even after you change your password.', tag: 'On' },
            { name: 'Traffic from AI', where: 'More → AI Traffic', desc: 'Real visitors who arrived from ChatGPT, Perplexity, Gemini, Claude, Copilot, Grok, DeepSeek and more — day by day, by source and landing page. Some AI visits can’t be detected, so read it as a floor. The dashboard card drills in everywhere: its day bars open the per-day detail, and each source or landing page opens the full report pre-filtered to it.', tag: 'On' },
            { name: 'Weekly email', where: 'your inbox · Settings → Weekly email', desc: 'A short email once a week, on the day and time you pick: agent visits against the week before, readers arriving from AI answers, impostors caught, your readiness score and its move since the last note, and one thing worth doing. Built only from the local data above and sent with WordPress’s own mail — no images, no tracking. A week with nothing to report sends nothing, and every email ends with a one-click stop link.', tag: 'On' },
          { name: 'Find missed AI sources', where: 'Settings → Visit log', desc: 'A diagnostic for the blind spot above: lists the referrers Agentimus could not name, so you can see whether an assistant is being overlooked and add it. Records the site name and utm tag only.', tag: 'Opt-in' },
            { name: 'CDN mode', where: 'Settings → Caching & CDN', desc: 'Counts AI-referred visits in the visitor’s browser instead of on the server, so the number survives a full-page cache. For sites behind Cloudflare “Cache Everything” and the like.', tag: 'Opt-in' },
            { name: 'Keep AI endpoints out of your cache', where: 'Settings → Caching & CDN', desc: 'If a cache or CDN in front of your site serves stored copies of your AI files (llms.txt, the .well-known docs, the change feed), those agent fetches never reach WordPress — the log under-counts them and the change feed can go stale. This asks caches not to store those files, so each fetch is counted and current. Works with any cache that respects the standard no-store header; the readiness report links to it when it spots a cache in front of you.', tag: 'Opt-in' },
            { name: 'Refresh AI files when content changes', where: 'Settings → Caching & CDN', desc: 'When you publish or edit a post, Agentimus asks every page cache it can detect (WP Rocket, Nginx Helper, W3 Total Cache, LiteSpeed, WP Super Cache, Cache Enabler…) to drop your llms.txt, the .well-known docs, the change feed and the edited post’s .md twin — files a cache plugin otherwise doesn’t know to refresh, so agents never get a stale copy after an edit. On by default; a no-op when no page cache is installed.', tag: 'On' },
            { name: 'How long records live', where: 'Settings → Visit log', desc: 'Choose a retention period, whether old records are deleted nightly, and a hard size cap. The cap always applies — the log can never grow without limit.', tag: 'On' },
          ],
        },
        {
          title: 'AI Visibility',
          lead: 'One always-on screen, two independent answers: can AI search find you, and do assistants actually cite you when asked. Each feature switches on inside its own tab, and each keeps its data on your server.',
          items: [
            { name: 'Found by AI Search', where: 'AI Visibility → AI Search · Settings → Data sources', desc: 'Bing’s index is what ChatGPT search reads today — Microsoft Copilot too — so “is Bing seeing you cleanly” is a measurable answer to “can AI search find me”. Connect Bing Webmaster Tools with one read-only key (Agentimus even prints the verification tag for you — no file upload, no DNS) and this tab shows how many pages sit in that index day by day, how many were crawled, crawl errors, and pages robots.txt closes off — with a warning pin when Bing’s view contradicts your own policy. Click any day’s bar for that day’s full crawl picture. Numbers are polled daily into your own database, so your history keeps growing where Bing’s own window ends.', tag: 'Opt-in' },
            { name: 'In Google’s Index', where: 'AI Visibility → AI Search · Settings → Data sources', desc: 'Google’s index is what AI Overviews, AI Mode and Gemini read — but Google publishes no bulk index report and allows 2,000 URL inspections a day, so a big site can’t be snapshot in one go, whatever a tool claims. Agentimus does the honest version in two tiers: a daily watchlist — your homepage, busiest pages, newest posts (where “is it indexed yet?” actually lives) — with every answer shown, plus a whole-site rotation of up to 100 pages a day, so a small site is fully covered every day and a big one on a stated cadence. Each answer is Google’s own wording: on Google or not, when Googlebot last visited, whether robots.txt or a noindex is in the way, whether Google chose a different canonical, fetch failures and soft 404s, whether any sitemap lists the page, and rich-result issues. Problem rows deep-link to that URL’s inspection in Search Console — one click from Request indexing. Uses the same read-only key as Search Performance; Check now runs the sweep on demand.', tag: 'Opt-in' },
            { name: 'Citation checks', where: 'AI Visibility → Citations', desc: 'Off by default, and switched on right inside the Citations tab — no hunting through Settings. Turned on, Agentimus asks ChatGPT, Perplexity, Gemini and Claude the questions you set — with your own keys — tracking whether each brand or product gets mentioned, linked and ranked against its rivals, and feeds the “Cited” rung of your score. Results and keys stay on your server (keys encrypted at rest). Everything saves as you change it; turning the checks off keeps your targets and history.', tag: 'Opt-in' },
            { name: 'Questions worth asking', where: 'AI Visibility → Citations → Settings', desc: 'Say what each tracked thing is — the category a buyer would shop in, like “WordPress SEO plugin” — and Agentimus suggests the questions a buyer actually types (“What is the best WordPress SEO plugin?”). If you’ve set up an AI provider in WordPress, “Suggest with AI” asks it for a wider spread. The good questions never mention your name: the point is to find out whether an assistant reaches for you when the buyer didn’t. Suggestions are only ever offered — you choose which to keep.' },
          ],
        },
        {
          title: 'Classic Search, Too',
          lead: 'With no SEO plugin installed, Agentimus covers the search basics — and now reads the results back to you. Connect Google, Bing, or both: the numbers are the engines’ own, stored on your server, and turned into a short list of pages worth an hour of your time.',
          items: [
            { name: 'Google Search Console', where: 'Settings → Data sources', desc: 'Off until you connect it. Google reports which searches brought people to each of your pages — how often you appeared, how often they clicked, and where you ranked. Agentimus reads it with a service-account key you create in your own Google Cloud and paste here: no “Connect with Google” button, because those route your data through the plugin maker’s server. Yours talks to Google directly, costs nothing, asks for no billing details, and is revocable in your own console any time. The card walks the five steps and reads your key back to you, so you know exactly which address to grant access to.', tag: 'Opt-in' },
            { name: 'Search Performance', where: 'AI Visibility → AI Search', desc: 'The plain answer to “how did search go?” — times shown, visits, click rate and average rank for the window, then your top searches and the pages that earned them. Connect both engines and a switch appears; they are never merged into one figure, because Google and Bing count different searchers. Nothing is estimated and nothing is removed: every number is what the engine itself reported. Searches that are really scraper probes — the ones using site: or intext: operators — are marked rather than hidden, and the screen says what share of your views they were; on many sites that share is big enough to drag the click rate well below what real visitors give you. Everything is stored in your own database, so the history outlives the engines’ own reporting windows. If you run the MCP server, a connected assistant can read this same summary and answer “how is my site doing in search?” from your real numbers.', tag: 'Opt-in' },
            { name: 'Search Opportunities', where: 'Readiness', desc: 'The same numbers turned into a to-do list: pages ranking 8–20 that one improvement could lift onto page one, and pages already on page one that people scroll past. “Not clicked enough” is measured against your own site’s page-one click rate — never an industry average — so the bar is fair to you. Each group says what to do in plain words, and every page opens straight on the “Search & AI” box where its title and description live. Scraper probes are excluded here — no title rewrite makes a machine click — and the screen states how much it left out and lets you read the exact searches, so you can judge that filter instead of trusting it. Anything you don’t want suggestions for gets set aside in its own visible list, restorable in a click. A connected assistant can read this worklist too, so you can ask it to take a page from here and rewrite the title and description for you — with the write tools on, it can save the draft as well.', tag: 'Opt-in' },
          ],
        },
      ],
      faqs: [
        { q: 'Do I need to configure anything?', a: 'No. Agentimus works the moment it’s activated, with safe defaults. Open Settings only if you want to add your identity details or change a default.' },
        { q: 'Can AI help me write the description, topics and fixes?', a: 'Yes, if you’ve connected an AI provider in WordPress (Settings → Connectors, with your own key). “Draft with AI” writes the AI description for a page, “Suggest with AI” fills its topics, and “Fix with AI” drafts a concrete fix for each readability warning — always as editable text you review, never saved for you. Agentimus routes everything through WordPress’s AI Client, so it never sees your API key, and the buttons stay hidden until a provider is set up. If your site defines Content Guidelines, every draft follows them.' },
        { q: 'Does AI Visibility use the AI provider I set up in WordPress?', a: 'No — it keeps its own keys, on purpose. A visibility check is graded on the sources each engine cited, and WordPress’s shared connectors hand back only the answer text: the list of cited sources is dropped before Agentimus could read it. Reading those sources means talking to each engine’s own API, so AI Visibility has its own keys (Settings → AI Visibility). They stay on your server, encrypted at rest, and are used for nothing else.' },
        { q: 'What is the AEO/GEO score?', a: 'One number (0–100) on your dashboard that blends five rungs — Findable, Readable, Trusted, Optimized and Cited — into a single measure of how ready your site is for AI answer engines, with the single most useful next step. Cited only counts when you turn on citation tracking; otherwise its weight is redistributed, so you’re never penalised for a feature you don’t use.' },
        { q: 'Can I see exactly what agents receive?', a: 'Yes. Open Readiness → Agent preview to see the exact JSON-LD and Markdown for your whole site or any page or post, and copy it — no need to view page source. A matching read-only preview also sits in the post editor. It even shows what would ship when a feature is off or an SEO plugin owns your schema.' },
        { q: 'Is my private or password-protected content exposed?', a: 'No. Drafts, private and password-protected posts are excluded from every output — llms.txt, Markdown, JSON-LD and the sitemap. Only published, publicly-visible content is ever described.' },
        { q: 'Will this block Google or real search engines?', a: 'No. Blocking is opt-in and aimed at AI training crawlers and spoofed bots. Real search engines are recognised and never blocked by default — and with “Verify bot identities” on, a scanner merely wearing a crawler’s name is told apart from the real one by the operator’s own published checks, so the genuine crawler stays welcome while the fake loses the disguise. Anything the checks can’t settle is served, never guessed at.' },
        { q: 'Where do I manage the clients I’ve blocked, allowed or ignored?', a: 'Settings → AI access → Manage clients. One dialog with three tabs — Blocked, Allowed, Ignored — showing each client’s identity (for known crawlers), when you decided, and an instant undo: Unblock, Un-trust or Un-ignore. Decisions made from now on carry their date; older entries simply show none rather than an invented one. An ignored client also returns to the review bell on its own if its traffic materially grows — un-ignoring just brings it back sooner.' },
        { q: 'What does “Verified responses” (signing) do?', a: 'It signs your discovery documents (RFC 9421) so an agent can confirm they really came from your server and weren’t altered in transit. The key is generated on your server and never leaves it.' },
        { q: 'Does it slow my site down?', a: 'Barely. Generated documents are cached, JSON-LD is tiny, and the plugin makes no external calls on the front end — nothing is fetched from another server while your pages load. (The optional AI Visibility checks run only in the admin or on a schedule, never during a page view.)' },
        { q: 'Does it collect personal data?', a: 'By default, no — the activity log stores no IP addresses, no identities and no query strings, and logged-in admins are skipped. Two optional settings, both off by default, add to that: “Store IP addresses for flagged clients” can record IPs, but only for crawlers flagged as impersonators or spoofs so you can block them; and “Find missed AI sources” records the referring site’s name and the link’s utm_source tag for visits it couldn’t attribute, as a daily count rather than against any visit. See “Privacy & data” above.' },
        { q: 'Where do I see what AI is doing on my site?', a: 'Three places, all on your own server. The Dashboard summarises both directions — which AI crawlers fetched your files, and how many readers AI sent you. More → AI Traffic is the full report on those readers: day by day, by assistant and landing page. More → Request Log is the full report on the crawlers: one row per request, filterable by client, endpoint, network and date.' },
        { q: 'Why am I getting a weekly email from Agentimus?', a: 'That’s the weekly digest — a short note about what AI did on your site: agent visits, readers arriving from AI answers, impostors caught, and your readiness score with its change since the last note. It’s on by default because the plugin’s work is otherwise invisible, and it’s built only from the data already stored on your site — the email to your own inbox is the only thing that leaves the server. A week with nothing to report sends nothing. Stop it with the one-click link inside any of the emails, or under Settings → Weekly email, where you can also pick the day and time it arrives, change the address and send yourself a test.' },
        { q: 'How do I know if an AI agent or app logged into my site?', a: 'More → Agent Access records it: when an application password (the key a program uses to reach WordPress as you) is created, first used, renamed or revoked; when an ability is run; and when a request is refused or someone probes for abilities that don’t exist. Every entry says who — the user and the named key it used. It’s a record, not a guard — it never blocks anything — and it stores no IP addresses, so it names the key that was used, not the person. A new application password is especially worth checking: it keeps working even after you change your password, which is exactly why one appearing unannounced matters.' },
        { q: 'Can AI write a whole post for me?', a: 'Yes — the writing assistant (the quill button on Agentimus’s screens) turns a described idea into a complete draft. It proposes an outline you edit first, then writes every section in parallel — long articles aren’t capped by a single AI response — with the title, AI description, topics and suggested categories and tags, and shows you everything before anything is saved. Create draft opens the post in the editor, where image placeholders arrive alt-filled — fill them from your library or generate them with AI. It can also revise an existing post without ever changing its status. It needs “Let connected agents write” plus an AI provider under Settings → AI, and it never publishes: drafts and pending review only.' },
        { q: 'Does Agentimus run an MCP server?', a: 'Yes — as an opt-in, on WordPress 6.9 or newer. Turn on Settings → Discovery → MCP server and the AI assistants you already use can talk to your site over the Model Context Protocol and run the same permission-checked tools your admin AI gets — fourteen read-only ones, plus the write tools only if you separately allow those (see the next question). Everything needed ships with the plugin. Connecting is usually one approval: give the assistant your server address and it asks you for permission on a page served by your own site, where you choose read-only or read-and-write. Claude, Cursor and ChatGPT work this way today. Assistants that cannot ask — Codex at the time of writing — take a shared token instead: create one on the card and send it as a Bearer header. A WordPress application password still works too, and suits anyone who wants one key per tool tied to a specific user. The handshake is public — the server will tell anyone its name and read-tool list, exactly what mcp.json already publishes — but every tool run signs in, every call is recorded under More → Agent Access, and turning the switch off disconnects connected assistants immediately. Worth knowing: a connection token or an approved assistant’s key works only on this server’s address, while an application password signs in as that user across your whole REST API.' },
        { q: 'Can an agent write to my site through MCP?', a: 'Only if you say so, twice. The MCP server starts read-only; a second switch (“Let connected agents write”) adds the write tools — draft and edit posts and pages complete with categories, tags and a featured image (from your media library, or imported from a URL), set their AI topics and descriptions, and apply Readiness fixes (a fixed list that can only turn documented features on, never loosen a protection). Even then, agents can’t publish: they leave drafts and pending posts for your review, unless you flip a third switch that allows going live. Every write runs as the signed-in user — an agent can never do more than that user could in the editor: filing under existing categories, creating new ones, and uploading images each follow that user’s own permissions — and every call is recorded under More → Agent Access.' },
        { q: 'What happens to AI training of my content?', a: 'By default Agentimus signals “do not train” (via tdmrep.json, the tdm-reservation header and robots Content-Signal) while still letting search engines and AI assistants read it. You control all of this in Settings.' },
        { q: 'Why is there no Google button in the Ask AI row?', a: 'Because your own bot policy forbids it — and the row is honest about that. Google made a single robots token, Google-Extended, govern both AI training and Gemini/AI-Mode reading. Agentimus blocks AI training by default, which therefore also tells Google’s assistant it may not read your pages — its button could only ever answer “page inaccessible,” so Agentimus hides it rather than hand readers a dead end. Allow Google-Extended (or add it to your always-allowed list) under Settings → AI access and the button returns. Blocking GPTBot or ClaudeBot hides nothing: OpenAI and Anthropic fetch reader-initiated requests with separate agents (ChatGPT-User, Claude-User) that a training block never touches.' },
      ],
      // Open standards the discovery output speaks — shown as plain chips.
      standards: [
        'WP_Discovery', 'schema.org', 'OpenAPI 3.1', 'MCP', 'A2A agent cards',
        'RFC 9421 signatures', 'RFC 9728 OAuth', 'RFC 9727 api-catalog',
        'RFC 9116 security.txt', 'W3C TDM',
      ],
    };
  },
  computed: {
    // The "On this page" jump menu: every section in page order, with the
    // screens and feature groups indented under their parents. Values are the
    // anchor ids the picker scrolls to.
    tocOptions() {
      return [
        { value: 'ar-about-screens', label: 'The Screens' },
        ...this.screens.map((s) => ({ value: `ar-about-s-${s.id}`, label: ` ${s.title}` })),
        { value: 'ar-about-features', label: 'What It Does' },
        ...this.featureGroups.map((g, gi) => ({ value: `ar-about-g-${gi}`, label: ` ${g.title}` })),
        { value: 'ar-about-cantdo', label: 'What It Can’t Do' },
        { value: 'ar-about-privacy', label: 'Privacy & Data' },
        { value: 'ar-about-protocol', label: 'The Protocol' },
        { value: 'ar-about-faq', label: 'Questions & Answers' },
      ];
    },
    protocolVersion() { return this.protocol.version || '1.0'; },
    hook() { return this.protocol.hook || 'wpdiscovery_register'; },
    specUrl() { return this.protocol.specUrl || ''; },
    schemaUrl() { return this.protocol.schemaUrl || ''; },
    devSnippet() {
      return "add_action( '" + this.hook + "', function ( $registry ) {\n"
        + "    $registry->register( [\n"
        + "        'id'    => 'my-plugin',\n"
        + "        'title' => 'My Plugin',\n"
        + "        'type'  => 'commerce',\n"
        + "    ] );\n"
        + "} );";
    },
  },
  mounted() {
    this.teleportReady = true;
    window.addEventListener('scroll', this.spy, { passive: true });
    this.$nextTick(() => this.applySpy());
  },
  beforeUnmount() {
    window.removeEventListener('scroll', this.spy);
    if (this._spyRaf) cancelAnimationFrame(this._spyRaf);
    clearTimeout(this._spyT);
  },
  methods: {
    toggleFaq(i) { this.openFaq = this.openFaq === i ? -1 : i; },
    // A pick is a command: jump there and show the destination at once. The
    // spy is locked until the smooth scroll settles (scrollend where it
    // exists, a timeout everywhere), then resumes following the reader.
    jump(id) {
      const el = document.getElementById(id);
      if (!el) return;
      this.tocCurrent = id;
      this.spyLock = true;
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      clearTimeout(this._spyT);
      const release = () => {
        if (!this.spyLock) return;
        this.spyLock = false;
        window.removeEventListener('scrollend', release);
        this.applySpy();
      };
      window.addEventListener('scrollend', release, { once: true });
      this._spyT = setTimeout(release, 1000);
    },
    // Scrollspy, one frame at a time: the current section is the LAST anchor
    // whose top sits above the reading line (just under the sticky bars).
    spy() {
      if (!this.active || this.spyLock || this._spyRaf) return;
      this._spyRaf = requestAnimationFrame(() => {
        this._spyRaf = null;
        this.applySpy();
      });
    },
    applySpy() {
      if (this.spyLock) return;
      const sticky = document.querySelector('.ar__sticky');
      const line = (sticky ? sticky.getBoundingClientRect().bottom : 150) + 30;
      let current = '';
      for (const o of this.tocOptions) {
        const el = document.getElementById(o.value);
        if (!el) continue;
        if (el.getBoundingClientRect().top <= line) current = o.value;
        else if (current) break; // anchors come in page order — we're past the line
      }
      this.tocCurrent = current;
    },
  },
};
</script>

<template>
  <div class="ar-about">
    <!-- "On this page" — a jump menu over every section, screen and feature
         group, teleported into the sticky page header so it's always in reach.
         Deliberately NOT a text search: the browser's own find does words
         better, and it can only work when nothing on the page is hidden. -->
    <Teleport v-if="teleportReady && active" to="#ar-pagehead-tools">
      <div class="ar-about-toc">
        <!-- NOT v-model: a user pick is a jump command (no watcher loop), while
             the scrollspy writes tocCurrent for display only. -->
        <SelectMenu :model-value="tocCurrent" :options="tocOptions" placeholder="On this page…" aria-label="Jump to a section" @update:model-value="jump" />
      </div>
    </Teleport>

    <!-- The working manual, screen by screen — in the nav's own order. -->
    <section id="ar-about-screens" class="ar-card">
      <h2 class="ar-card__title">The Screens</h2>
      <p class="ar-card__lead">
        What each screen is for, what you do on it, and the facts worth knowing so nothing
        surprises you.
      </p>
      <div v-for="s in screens" :id="`ar-about-s-${s.id}`" :key="s.id" class="ar-about-screen">
        <h3 class="ar-about-feat__title">{{ s.title }} <span class="ar-about-screen__where">{{ s.where }}</span></h3>
        <p class="ar-about-screen__purpose">{{ s.purpose }}</p>
        <p class="ar-about-screen__do"><strong>What you do here:</strong> {{ s.actions }}</p>
        <p class="ar-about-screen__k">Worth knowing</p>
        <ul class="ar-about-screen__facts">
          <li v-for="f in s.facts" :key="f.t" :class="`is-${f.k}`">
            <span class="ar-about-screen__ico" aria-hidden="true">
              <!-- notice -->
              <svg v-if="f.k === 'info'" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9" /><path d="M12 11v5" /><path d="M12 7.6v.4" /></svg>
              <!-- alert -->
              <svg v-else-if="f.k === 'warn'" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 21.5 20h-19z" /><path d="M12 10v4.5" /><path d="M12 17.2v.3" /></svg>
              <!-- lightbulb -->
              <svg v-else viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0-3.4 10.9c.8.6 1.4 1.4 1.4 2.1h4c0-.7.6-1.5 1.4-2.1A6 6 0 0 0 12 3z" /><path d="M10 19h4" /><path d="M10.7 21.5h2.6" /></svg>
            </span>
            <span class="ar-about-screen__ft">{{ f.t }}</span>
          </li>
        </ul>
      </div>
    </section>

    <!-- Features -->
    <section id="ar-about-features" class="ar-card">
      <h2 class="ar-card__title">What It Does</h2>
      <p class="ar-card__lead">
        Agentimus does two things for the age of AI agents. <strong>First, it makes your site legible and
        citable</strong> — it publishes the documents agents look for, offers your content in machine-readable
        formats, and adds trust signals, then rolls it all into one AEO/GEO score with the next thing to improve.
        <strong>Second, it lets the AI tools you already use operate your site over MCP</strong> — flip on the
        built-in Model Context Protocol server and Claude, Cursor or Codex can read your readiness, traffic and
        bot data; allow writes with a separate switch and the same agent can draft and edit posts and pages —
        categories, tags, featured image, AI topics and descriptions and all — and, behind a third switch,
        publish them. Every write runs as the signed-in user, permission-checked and audited, so nobody opens
        wp-admin to get a post out.
      </p>
      <p class="ar-card__lead">
        Everything here is on by default unless marked otherwise (the write and MCP features are off until you
        turn them on); see the live documents on the
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'discovery' })">Discovery</button>
        tab and change any default under
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' })">Settings</button>.
        New here? The full
        <a class="ar-linkbtn" href="https://heera.github.io/agentimus/" target="_blank" rel="noopener">documentation ↗</a>
        walks through every feature, step by step.
      </p>

      <div v-for="(g, gi) in featureGroups" :id="`ar-about-g-${gi}`" :key="g.title" class="ar-about-feat">
        <div class="ar-about-feat__head">
          <h3 class="ar-about-feat__title">{{ g.title }}</h3>
          <p class="ar-about-feat__lead">{{ g.lead }}</p>
        </div>
        <ul class="ar-about-list">
          <li v-for="it in g.items" :key="it.name" class="ar-about-item">
            <div class="ar-about-item__main">
              <div class="ar-about-item__top">
                <span class="ar-about-item__name">{{ it.name }}</span>
                <code class="ar-about-item__where">{{ it.where }}</code>
                <span class="ar-about-tag" :class="`is-${it.tag === 'On' ? 'on' : 'opt'}`">{{ it.tag }}</span>
              </div>
              <p class="ar-about-item__desc">{{ it.desc }}</p>
            </div>
          </li>
        </ul>
      </div>
    </section>

    <!-- Honest expectations -->
    <section id="ar-about-cantdo" class="ar-card">
      <h2 class="ar-card__title">What It Can’t Do</h2>
      <p class="ar-card__lead">
        Agentimus makes your site <strong>discoverable and correctly understood</strong> — when an AI agent
        looks at your site, it finds your content, reads a clean version, and describes you accurately. That’s
        what a discovery layer controls, and it does it well. It can’t make an assistant <strong>spontaneously
        mention you</strong> when someone asks a broad question — that’s authority and reputation, earned over
        time through content others reference. No plugin can manufacture it, and tools promising “instant AI
        visibility” are overselling. Agentimus makes sure that when your work does bring an agent to your door,
        nothing is lost in translation.
      </p>
    </section>

    <!-- Privacy & data -->
    <section id="ar-about-privacy" class="ar-card ar-about-priv">
      <h2 class="ar-card__title">Privacy &amp; Data</h2>
      <p class="ar-card__lead">
        A discovery plugin should be honest about itself. Here is exactly what leaves your server, what’s
        published, and what’s stored — verified against the source code.
      </p>

      <div class="ar-about-priv__grid">
        <div class="ar-about-priv__cell ar-about-priv__cell--head">
          <span class="ar-about-priv__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4.4-3 7.4-7 9-4-1.6-7-4.6-7-9V6z"/></svg>
          </span>
          <div>
            <h3>What leaves your server: nothing by default</h3>
            <p>
              No phone-home, no telemetry, no remote config. Outbound calls come only from opt-in
              features, all off by default, each using a credential you provide (stored encrypted at
              rest, masked in the admin). <strong>Citation checks</strong>: queries the engines you
              chose (OpenAI, Perplexity, Gemini, Claude) with your own AI keys to check whether they
              cite you. <strong>At Cloudflare</strong>: one hourly poll of Cloudflare’s analytics API
              with your own read-only token — nothing about your site is sent, numbers only come back.
              <strong>Found by AI Search</strong>: one daily poll of Bing Webmaster Tools with your own
              read-only key, same direction, numbers in only. <strong>Search Performance</strong>: one
              daily poll of Google Search Console with your own service-account key — Agentimus signs in
              directly from your server, with no third party in between, and only your own search
              statistics come back. <strong>Verify bot identities</strong>:
              makes small DNS lookups and, once a day, downloads the public crawler-IP lists bot
              operators publish (Google’s googlebot.json, OpenAI’s gptbot.json, …) so impostors can be
              caught — only those public files are fetched, and nothing about your site or your
              visitors is ever sent. The “Verify live” readiness check still runs in <em>your
              browser</em> against your own URLs. And one email: the <strong>weekly digest</strong> —
              on by default, stopped with one click — goes only to the inbox you chose, carrying your
              own numbers and nothing else.
            </p>
          </div>
        </div>

        <div class="ar-about-priv__cell">
          <h3>What’s published publicly</h3>
          <p>
            The documents under “What it does” — that’s the whole point, and they describe only published,
            public content. See the live list on the
            <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'discovery' })">Discovery</button>
            tab.
          </p>
        </div>

        <div class="ar-about-priv__cell">
          <h3>What’s stored on your site</h3>
          <p>
            An optional, local-only activity log: which endpoint was hit, the agent type, a truncated
            user-agent, and the time. Plus <em>daily aggregate counts</em> of human visits referred by AI
            assistants — a total per day, source and landing page, never a row that stands for one person.
            Kept for a period you choose (30 days by default), pruned nightly, under a size cap that always
            applies. The optional Cloudflare, Bing and Google connections add three more local tables —
            hourly per-crawler counts, daily index numbers, and the latest window of search performance
            (which search, which of your pages, how many times shown and clicked). All aggregates the
            engines themselves report: no searcher identity exists in those APIs, let alone here.
          </p>
          <ul class="ar-about-not">
            <li>No IP addresses in the log</li>
            <li>No identities or logins (admins are skipped)</li>
            <li>No emails</li>
            <li>No query strings or full URLs</li>
          </ul>
          <p class="ar-about-priv__foot">No PII and no GDPR footprint by default. Two optional settings add to
            that, both off unless you switch them on.
            <strong>Store IP addresses for flagged clients</strong> can record IPs, but only for crawlers flagged as
            impersonators or spoofs, kept briefly on your own server and deleted when you switch it off.
            <strong>Find missed AI sources</strong> records, for visits it could not attribute to a known assistant,
            the name of the site the reader came from and the <code>utm_source</code> tag on the link — that tag is
            the single piece of a query string Agentimus ever stores, and it is kept as a daily count, never against a
            visit. Enable either and you take on that data (disclose it in your privacy policy). Your signing key is
            stored un-autoloaded and never leaves the server. Uninstalling removes the tables, settings and key.</p>
        </div>
      </div>
    </section>

    <!-- WP_Discovery Protocol -->
    <section id="ar-about-protocol" class="ar-card ar-about-proto">
      <h2 class="ar-card__title">Built on the WP_Discovery Protocol</h2>
      <p class="ar-card__lead">
        Agentimus is the reference implementation of <strong>WP_Discovery</strong> — a small, open,
        vendor-neutral standard for how a WordPress site describes itself to AI agents. The output isn’t a
        proprietary format: it’s an open spec any tool can read, and any plugin can extend.
      </p>

      <ul class="ar-about-chips">
        <li v-for="s in standards" :key="s" class="ar-about-chip">{{ s }}</li>
      </ul>

      <div class="ar-about-proto__meta">
        <div class="ar-about-proto__fact">
          <span class="ar-about-proto__k">Wire format</span>
          <span class="ar-about-proto__v">v{{ protocolVersion }}</span>
        </div>
        <div class="ar-about-proto__fact">
          <span class="ar-about-proto__k">Validates against</span>
          <a v-if="schemaUrl" class="ar-about-proto__v" :href="schemaUrl" target="_blank" rel="noopener">JSON Schema ↗</a>
          <span v-else class="ar-about-proto__v">JSON Schema</span>
        </div>
        <div class="ar-about-proto__fact">
          <span class="ar-about-proto__k">Specification</span>
          <a v-if="specUrl" class="ar-about-proto__v" :href="specUrl" target="_blank" rel="noopener">Read the spec ↗</a>
          <span v-else class="ar-about-proto__v">Open spec</span>
        </div>
      </div>

      <div class="ar-about-proto__dev">
        <h3>Extend it from your own plugin</h3>
        <p>
          Any plugin can add itself to the discovery output with one hook — no hard dependency on Agentimus.
          If no discovery engine is active, the action simply never fires.
        </p>
        <pre class="ar-about-snippet"><code>{{ devSnippet }}</code></pre>
      </div>
    </section>

    <!-- FAQ -->
    <section id="ar-about-faq" class="ar-card">
      <h2 class="ar-card__title">Questions &amp; Answers</h2>
      <ul class="ar-about-faq">
        <li v-for="(f, i) in faqs" :key="f.q" class="ar-about-faq__item" :class="{ 'is-open': openFaq === i }">
          <button
            type="button"
            class="ar-about-faq__q"
            :aria-expanded="openFaq === i"
            @click="toggleFaq(i)"
          >
            <span>{{ f.q }}</span>
            <span class="ar-about-faq__caret" aria-hidden="true">▸</span>
          </button>
          <p v-show="openFaq === i" class="ar-about-faq__a">{{ f.a }}</p>
        </li>
      </ul>
    </section>

    <!-- Documentation & links -->
    <section class="ar-card">
      <h2 class="ar-card__title">Documentation &amp; Links</h2>
      <p class="ar-card__lead">
        Step-by-step guides for every feature, plus a developer reference — the full documentation lives
        online, and the source is on GitHub.
      </p>
      <p class="ar-card__lead">
        <a class="ar-linkbtn" href="https://heera.github.io/agentimus/" target="_blank" rel="noopener">Documentation ↗</a>
        &nbsp;·&nbsp;
        <a class="ar-linkbtn" href="https://github.com/heera/agentimus" target="_blank" rel="noopener">GitHub ↗</a>
      </p>
    </section>
  </div>
</template>
