<script>
export default {
  name: 'AboutPanel',
  props: {
    version: { type: String, default: '' },
    // { name, version, hook, specUrl, schemaUrl } — sourced from PHP so it
    // mirrors the real constants instead of hand-copied strings.
    protocol: { type: Object, default: () => ({}) },
  },
  emits: ['navigate'],
  data() {
    return {
      openFaq: 0,
      // Documentation of what the plugin publishes. "Default" is the shipped
      // state; the live on/off for each lives on the Settings tab.
      featureGroups: [
        {
          title: 'AEO/GEO score & optimization',
          lead: 'Your dashboard turns everything below into one score — and the single next thing to improve.',
          items: [
            { name: 'AEO/GEO score', where: 'Dashboard', desc: 'One 0–100 score across five rungs — Findable, Readable, Trusted, Optimized and Cited — with a plain-language next step. Each rung links to where you fix it.', tag: 'On' },
            { name: 'Optimize your content', where: 'Readiness → Optimize', desc: 'Per-page citability checks (enough substance, something concrete to quote, quotable passages, freshness) with a worklist of the pages to improve. Articles only — commerce products and structural pages are left out. Set aside anything not meant to be cited.', tag: 'On' },
            { name: 'AI readability tips', where: 'post editor', desc: 'The same per-page citability tips in an editor panel while you write. Editor-only — nothing is shown to visitors.', tag: 'On' },
            { name: 'Evergreen content', where: 'Settings', desc: 'Mark categories whose posts are timeless (references, tutorials, legal) so they’re exempt from the freshness check.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Write with AI',
          lead: 'Optional — use the AI provider you’ve connected in WordPress (Settings → Connectors, with your own key) to draft and fix the AEO/GEO fields as you write. Everything routes through WordPress’s built-in AI Client, so Agentimus never handles your key; every suggestion is editable and nothing is saved for you. The buttons stay hidden until a provider is set up.',
          items: [
            { name: 'Draft with AI', where: 'post editor', desc: 'One click drafts the AI description for a page from its content (and “Suggest with AI” does the same for its topics), dropping the result into the field as editable text to accept or change.', tag: 'Opt-in' },
            { name: 'Fix with AI', where: 'post editor', desc: 'On each AI-readability warning, drafts a concrete fix — an opening summary you can insert with one click, or a heading outline / shorter quotable passages to copy in. Advisory items that add facts stay copy-only, so nothing unverified is inserted for you.', tag: 'Opt-in' },
            { name: 'Agentimus abilities', where: 'WordPress admin AI · MCP', desc: 'Exposes read-only abilities — your readiness score, AI traffic, request log, bot checks, and page / JSON-LD / Markdown previews — to WordPress’s built-in AI and, when you turn on the MCP server below, to external AI agents. Each is permission-gated exactly like the screen it comes from.', tag: 'Auto' },
          ],
        },
        {
          title: 'Discovery documents',
          lead: 'The core job — standard files that let an agent find and understand your site.',
          items: [
            { name: 'Discovery manifest', where: '/.well-known/discovery.json', desc: 'The master document describing your site, content and capabilities.', tag: 'On' },
            { name: 'Agent card', where: '/.well-known/agent-card.json', desc: 'Agent-to-agent (A2A) identity card, also served at agent.json.', tag: 'On' },
            { name: 'API description', where: '/.well-known/openapi.json · /api-catalog', desc: 'OpenAPI 3.1 spec and a catalog of your public REST API.', tag: 'On' },
            { name: 'MCP manifest', where: '/.well-known/mcp.json', desc: 'Advertises Model Context Protocol servers running on your site — Agentimus’s own, or another plugin’s — with their tools and per-server cards.', tag: 'Auto' },
            { name: 'MCP server', where: '/wp-json/agentimus/v1/mcp', desc: 'Off by default. One switch (Settings → Discovery) runs a Model Context Protocol server on your own site — everything needed ships with the plugin — so AI tools you already use (Claude Code, Claude Desktop, Codex) can run the nine read-only Agentimus tools above; the switch card writes the exact setup for each. Nothing is public: every call signs in with a WordPress login, each tool keeps the same permission checks as its admin screen, every call is recorded under Agent access, and turning the switch off disconnects connected tools immediately.', tag: 'Opt-in' },
            { name: 'Browser tools (WebMCP)', where: 'your public pages', desc: 'Off by default. Lets an AI agent working inside a visitor’s browser call your site’s read-only tools (like site search) directly, via the emerging WebMCP browser standard. It adds one tiny, self-hosted script that stays inert in browsers without support, and each tool can be shown or hidden individually in Settings.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Machine-readable content',
          lead: 'Your existing content, offered in formats agents read cleanly.',
          items: [
            { name: 'llms.txt', where: '/llms.txt', desc: 'A plain-text index of your site and recent content.', tag: 'On' },
            { name: 'llms-full.txt', where: '/llms-full.txt', desc: 'A full-text bundle of your pages and posts, within a size budget.', tag: 'On' },
            { name: 'Markdown', where: '/{slug}.md', desc: 'Clean Markdown of any page, at its own address — a separate URL, so a cache can never confuse it with the page a reader sees. (Answering the page URL itself with Markdown via an Accept header is opt-in; see the docs.)', tag: 'On' },
            { name: 'Change feed', where: '/agentimus-changes.json?since=', desc: 'What changed lately — added, updated and removed content as JSON, with a ?since= filter so an agent re-checks only the delta instead of recrawling the site. Removals are announced honestly (tombstones), it’s one bounded, cached query, and it’s advertised in discovery.json so agents find it on their own.', tag: 'On' },
          ],
        },
        {
          title: 'Structured data & crawl signals',
          lead: 'Standards search engines and agents already understand.',
          items: [
            { name: 'JSON-LD schema', where: 'in your page <head>', desc: 'schema.org WebSite, Person/Organization, articles, breadcrumbs and FAQ.', tag: 'On' },
            { name: 'Topics for AI', where: 'per post', desc: 'Per-page topics → JSON-LD keywords and about entities (linkable to Wikidata/Wikipedia) plus a Markdown line, so assistants know exactly what each page is about.', tag: 'On' },
            { name: 'AI description', where: 'per post', desc: 'A one-line summary → the JSON-LD description, the Markdown lead, and your page’s meta description (replacing your theme’s, unless a dedicated SEO plugin owns it). Blank pages fall back to the excerpt, or a short summary of the page.', tag: 'On' },
            { name: 'robots.txt', where: '/robots.txt', desc: 'Adds Content-Signal directives and advertises your sitemap.', tag: 'On' },
            { name: 'XML sitemap', where: '/agentimus-sitemap.xml', desc: 'A fallback sitemap — stands down when core or an SEO plugin provides one.', tag: 'On' },
          ],
        },
        {
          title: 'AI rights & opt-out',
          lead: 'Tell AI systems how your content may be used.',
          items: [
            { name: 'Do-not-train signals', where: '/.well-known/tdmrep.json · tdm-reservation header', desc: 'Signals “don’t train on this” while still allowing reading (W3C TDM).', tag: 'On' },
            { name: 'noai header', where: 'X-Robots-Tag: noai', desc: 'An extra opt-out header on content pages.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Trust & verification',
          lead: 'Prove the documents really came from you.',
          items: [
            { name: 'Verified responses', where: '/.well-known/http-message-signatures-directory', desc: 'Signs discovery docs (RFC 9421) so agents can verify they’re from you and unaltered.', tag: 'On' },
            { name: 'OAuth metadata', where: '/.well-known/oauth-protected-resource', desc: 'Points agents at your authorization server (RFC 9728).', tag: 'When set' },
            { name: 'security.txt', where: '/.well-known/security.txt', desc: 'A standard security contact for your site (RFC 9116).', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Protection',
          lead: 'Shape who reaches your endpoints — all on your server.',
          items: [
            { name: 'Agent guard', where: 'your generated endpoints', desc: 'Blocks (403) denylisted or spoofed agents at the documents above.', tag: 'Opt-in' },
            { name: 'Verified search engines', where: 'reverse-DNS check', desc: 'Optionally confirm a visitor claiming to be Googlebot, Bingbot, Applebot, DuckDuckBot or Yandex really is — by forward-confirmed reverse DNS — before trusting it, so a scanner can’t pass by copying a crawler’s name.', tag: 'Opt-in' },
          ],
        },
        {
          title: 'Who’s reading you',
          lead: 'AI traffic in every direction: agents taking your content, readers AI sends back, and who authenticates to or acts on the machine surface Agentimus creates. All counted on your own site — no IP addresses by default, nothing sent anywhere.',
          items: [
            { name: 'Agent activity', where: 'Dashboard', desc: 'Which AI crawlers fetched which of your files, how often, and when — with a review queue for clients worth a second look. Every standing decision from that queue (blocked, trusted, ignored) is managed in one place, with dates: Settings → AI access → Manage clients.', tag: 'On' },
            { name: 'Request log', where: 'More → Request log', desc: 'Every recorded request, one row each. Filter by client, endpoint, network, user-agent and date to see exactly what a single bot fetched.', tag: 'On' },
            { name: 'Agent access', where: 'More → Agent access', desc: 'Who authenticates to, and acts on, the machine surface Agentimus creates: application passwords being created, first used, renamed or revoked; abilities being run; and requests that were refused, or that probed for abilities which don’t exist. Every row says who — the user and the named application password it used, resolved live, so a renamed key shows its current name and a revoked one says so plainly. A record, not a guard — nothing here blocks. It stores no IP addresses, so it names the key that was used, not the person, and it sees machine logins only — a normal password sign-in never appears. A brand-new application password is the one worth a second look: it keeps working even after you change your password.', tag: 'On' },
            { name: 'Traffic from AI', where: 'More → AI traffic', desc: 'Real visitors who arrived from ChatGPT, Perplexity, Gemini, Claude, Copilot, Grok, DeepSeek and more — day by day, by source and landing page. Some AI visits can’t be detected, so read it as a floor. The dashboard card’s day bars open the same per-day detail: which assistant sent readers to which page.', tag: 'On' },
            { name: 'Find missed AI sources', where: 'Settings → Visit log', desc: 'A diagnostic for the blind spot above: lists the referrers Agentimus could not name, so you can see whether an assistant is being overlooked and add it. Records the site name and utm tag only.', tag: 'Opt-in' },
            { name: 'CDN mode', where: 'Settings → Caching & CDN', desc: 'Counts AI-referred visits in the visitor’s browser instead of on the server, so the number survives a full-page cache. For sites behind Cloudflare “Cache Everything” and the like.', tag: 'Opt-in' },
            { name: 'Keep AI endpoints out of your cache', where: 'Settings → Caching & CDN', desc: 'If a cache or CDN in front of your site serves stored copies of your AI files (llms.txt, the .well-known docs, the change feed), those agent fetches never reach WordPress — the log under-counts them and the change feed can go stale. This asks caches not to store those files, so each fetch is counted and current. Works with any cache that respects the standard no-store header; the readiness report links to it when it spots a cache in front of you.', tag: 'Opt-in' },
            { name: 'Refresh AI files when content changes', where: 'Settings → Caching & CDN', desc: 'When you publish or edit a post, Agentimus asks every page cache it can detect (WP Rocket, Nginx Helper, W3 Total Cache, LiteSpeed, WP Super Cache, Cache Enabler…) to drop your llms.txt, the .well-known docs, the change feed and the edited post’s .md twin — files a cache plugin otherwise doesn’t know to refresh, so agents never get a stale copy after an edit. On by default; a no-op when no page cache is installed.', tag: 'On' },
            { name: 'How long records live', where: 'Settings → Visit log', desc: 'Choose a retention period, whether old records are deleted nightly, and a hard size cap. The cap always applies — the log can never grow without limit.', tag: 'On' },
          ],
        },
        {
          title: 'AI Visibility monitoring',
          lead: 'Optional — check how AI assistants actually describe you, and add the “Cited” rung to your score. The one feature that reaches out, using API keys you provide (stored encrypted at rest).',
          items: [
            { name: 'AI Visibility checks', where: 'Settings → AI Visibility', desc: 'Off by default. Turn it on and Agentimus asks ChatGPT, Perplexity, Gemini and Claude the questions you set — with your own keys — tracking whether each brand or product gets mentioned, linked and ranked against its rivals, and feeds the “Cited” rung of your score. Results and keys stay on your server (keys encrypted at rest). Everything on the screen saves as you change it; there is no Save button.', tag: 'Opt-in' },
            { name: 'Questions worth asking', where: 'Settings → AI Visibility', desc: 'Say what each tracked thing is — the category a buyer would shop in, like “WordPress SEO plugin” — and Agentimus suggests the questions a buyer actually types (“What is the best WordPress SEO plugin?”). If you’ve set up an AI provider in WordPress, “Suggest with AI” asks it for a wider spread. The good questions never mention your name: the point is to find out whether an assistant reaches for you when the buyer didn’t. Suggestions are only ever offered — you choose which to keep.' },
          ],
        },
      ],
      faqs: [
        { q: 'Do I need to configure anything?', a: 'No. Agentimus works the moment it’s activated, with safe defaults. Open Settings only if you want to add your identity details or change a default.' },
        { q: 'Can AI help me write the description, topics and fixes?', a: 'Yes, if you’ve connected an AI provider in WordPress (Settings → Connectors, with your own key). “Draft with AI” writes the AI description for a page, “Suggest with AI” fills its topics, and “Fix with AI” drafts a concrete fix for each readability warning — always as editable text you review, never saved for you. Agentimus routes everything through WordPress’s AI Client, so it never sees your API key, and the buttons stay hidden until a provider is set up.' },
        { q: 'Does AI Visibility use the AI provider I set up in WordPress?', a: 'No — it keeps its own keys, on purpose. A visibility check is graded on the sources each engine cited, and WordPress’s shared connectors hand back only the answer text: the list of cited sources is dropped before Agentimus could read it. Reading those sources means talking to each engine’s own API, so AI Visibility has its own keys (Settings → AI Visibility). They stay on your server, encrypted at rest, and are used for nothing else.' },
        { q: 'What is the AEO/GEO score?', a: 'One number (0–100) on your dashboard that blends five rungs — Findable, Readable, Trusted, Optimized and Cited — into a single measure of how ready your site is for AI answer engines, with the single most useful next step. Cited only counts when you turn on citation tracking; otherwise its weight is redistributed, so you’re never penalised for a feature you don’t use.' },
        { q: 'Can I see exactly what agents receive?', a: 'Yes. Open Readiness → Agent preview to see the exact JSON-LD and Markdown for your whole site or any page or post, and copy it — no need to view page source. A matching read-only preview also sits in the post editor. It even shows what would ship when a feature is off or an SEO plugin owns your schema.' },
        { q: 'Is my private or password-protected content exposed?', a: 'No. Drafts, private and password-protected posts are excluded from every output — llms.txt, Markdown, JSON-LD and the sitemap. Only published, publicly-visible content is ever described.' },
        { q: 'Will this block Google or real search engines?', a: 'No. Blocking is opt-in and aimed at AI training crawlers and spoofed bots. Real search engines are recognised and never blocked by default.' },
        { q: 'Where do I manage the clients I’ve blocked, allowed or ignored?', a: 'Settings → AI access → Manage clients. One dialog with three tabs — Blocked, Allowed, Ignored — showing each client’s identity (for known crawlers), when you decided, and an instant undo: Unblock, Un-trust or Un-ignore. Decisions made from now on carry their date; older entries simply show none rather than an invented one. An ignored client also returns to the review bell on its own if its traffic materially grows — un-ignoring just brings it back sooner.' },
        { q: 'What does “Verified responses” (signing) do?', a: 'It signs your discovery documents (RFC 9421) so an agent can confirm they really came from your server and weren’t altered in transit. The key is generated on your server and never leaves it.' },
        { q: 'Does it slow my site down?', a: 'Barely. Generated documents are cached, JSON-LD is tiny, and the plugin makes no external calls on the front end — nothing is fetched from another server while your pages load. (The optional AI Visibility checks run only in the admin or on a schedule, never during a page view.)' },
        { q: 'Does it collect personal data?', a: 'By default, no — the activity log stores no IP addresses, no identities and no query strings, and logged-in admins are skipped. Two optional settings, both off by default, add to that: “Store IP addresses for flagged clients” can record IPs, but only for crawlers flagged as impersonators or spoofs so you can block them; and “Find missed AI sources” records the referring site’s name and the link’s utm_source tag for visits it couldn’t attribute, as a daily count rather than against any visit. See “Privacy & data” above.' },
        { q: 'Where do I see what AI is doing on my site?', a: 'Three places, all on your own server. The Dashboard summarises both directions — which AI crawlers fetched your files, and how many readers AI sent you. More → AI traffic is the full report on those readers: day by day, by assistant and landing page. More → Request log is the full report on the crawlers: one row per request, filterable by client, endpoint, network and date.' },
        { q: 'How do I know if an AI agent or app logged into my site?', a: 'More → Agent access records it: when an application password (the key a program uses to reach WordPress as you) is created, first used, renamed or revoked; when an ability is run; and when a request is refused or someone probes for abilities that don’t exist. Every entry says who — the user and the named key it used. It’s a record, not a guard — it never blocks anything — and it stores no IP addresses, so it names the key that was used, not the person. A new application password is especially worth checking: it keeps working even after you change your password, which is exactly why one appearing unannounced matters.' },
        { q: 'Does Agentimus run an MCP server?', a: 'Yes — as an opt-in, on WordPress 6.9 or newer. Turn on Settings → Discovery → MCP server and AI tools you already use (Claude Code, Claude Desktop, Codex) can talk to your site over the Model Context Protocol and run the same nine read-only, permission-checked tools your admin AI gets. Everything needed ships with the plugin, and the switch card writes the exact setup for your tool — pick it, mint a key with one click, copy the result — then a Test button proves the connection with the same calls the tool will make. (ChatGPT itself is the exception — its connectors only support OAuth sign-ins, which WordPress logins aren’t — though Codex inside the ChatGPT desktop app connects fine via the Codex setup.) Nothing becomes public: every request signs in with a WordPress login, every call is recorded under More → Agent access, and turning the switch off disconnects connected tools immediately. Worth knowing: an application password isn’t scoped to this server — it signs in as that user across your whole REST API — so give each AI tool its own password, on a user with only the permissions it needs.' },
        { q: 'What happens to AI training of my content?', a: 'By default Agentimus signals “do not train” (via tdmrep.json, the tdm-reservation header and robots Content-Signal) while still letting search engines and AI assistants read it. You control all of this in Settings.' },
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
  methods: {
    toggleFaq(i) { this.openFaq = this.openFaq === i ? -1 : i; },
  },
};
</script>

<template>
  <div class="ar-about">
    <!-- Features -->
    <section class="ar-card">
      <h2 class="ar-card__title">What it does</h2>
      <p class="ar-card__lead">
        Agentimus makes your site legible to AI agents — it publishes the documents they look for, offers
        your content in machine-readable formats, and adds trust signals so agents can find, read and
        verify your site — then rolls it all into one AEO/GEO score with the next thing to improve.
        Everything here is on by default unless marked otherwise; see the live documents
        on the
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'discovery' })">Discovery</button>
        tab and change any default under
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' })">Settings</button>.
        New here? The full
        <a class="ar-linkbtn" href="https://heera.github.io/agentimus/" target="_blank" rel="noopener">documentation ↗</a>
        walks through every feature, step by step.
      </p>

      <div v-for="g in featureGroups" :key="g.title" class="ar-about-feat">
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
              </div>
              <p class="ar-about-item__desc">{{ it.desc }}</p>
            </div>
            <span class="ar-about-tag" :class="`is-${it.tag === 'On' ? 'on' : 'opt'}`">{{ it.tag }}</span>
          </li>
        </ul>
      </div>
    </section>

    <!-- Honest expectations -->
    <section class="ar-card">
      <h2 class="ar-card__title">What it can’t do</h2>
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
    <section class="ar-card ar-about-priv">
      <h2 class="ar-card__title">Privacy &amp; data</h2>
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
              No phone-home, no telemetry, no remote config. The only outbound calls come from the optional
              <strong>AI Visibility</strong> feature — off unless you switch it on and add your own AI API
              key, at which point it queries the engines you chose (OpenAI, Perplexity, Gemini, Claude) to
              check whether they cite you. Your keys stay on your server — masked in the admin, and shown
              back only to you, only when you click “reveal” on your own key. The “Verify live” readiness
              check also runs in <em>your browser</em> against your own URLs.
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
            applies.
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
    <section class="ar-card">
      <h2 class="ar-card__title">Questions &amp; answers</h2>
      <ul class="ar-about-faq">
        <li v-for="(f, i) in faqs" :key="i" class="ar-about-faq__item" :class="{ 'is-open': openFaq === i }">
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
      <h2 class="ar-card__title">Documentation &amp; links</h2>
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
