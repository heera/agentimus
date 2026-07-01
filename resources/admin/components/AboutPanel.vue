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
          title: 'Discovery documents',
          lead: 'The core job — standard files that let an agent find and understand your site.',
          items: [
            { name: 'Discovery manifest', where: '/.well-known/discovery.json', desc: 'The master document describing your site, content and capabilities.', tag: 'On' },
            { name: 'Agent card', where: '/.well-known/agent-card.json', desc: 'Agent-to-agent (A2A) identity card, also served at agent.json.', tag: 'On' },
            { name: 'API description', where: '/.well-known/openapi.json · /api-catalog', desc: 'OpenAPI 3.1 spec and a catalog of your public REST API.', tag: 'On' },
            { name: 'MCP manifest', where: '/.well-known/mcp.json', desc: 'Advertises Model Context Protocol tools when a plugin registers abilities.', tag: 'Auto' },
          ],
        },
        {
          title: 'Machine-readable content',
          lead: 'Your existing content, offered in formats agents read cleanly.',
          items: [
            { name: 'llms.txt', where: '/llms.txt', desc: 'A plain-text index of your site and recent content.', tag: 'On' },
            { name: 'llms-full.txt', where: '/llms-full.txt', desc: 'A full-text bundle of your pages and posts, within a size budget.', tag: 'On' },
            { name: 'Markdown', where: '/{slug}.md', desc: 'Clean Markdown of any page, plus Accept: text/markdown negotiation.', tag: 'On' },
          ],
        },
        {
          title: 'Structured data & crawl signals',
          lead: 'Standards search engines and agents already understand.',
          items: [
            { name: 'JSON-LD schema', where: 'in your page <head>', desc: 'schema.org WebSite, Person/Organization, articles, breadcrumbs and FAQ.', tag: 'On' },
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
          title: 'Protection & insight',
          lead: 'See and shape who reaches your endpoints — all on your server.',
          items: [
            { name: 'Agent guard', where: 'your generated endpoints', desc: 'Blocks (403) denylisted or spoofed agents at the documents above.', tag: 'Opt-in' },
            { name: 'Activity & AI referrals', where: 'stored locally', desc: 'A local log of which agents hit your endpoints, and human visits referred by AI assistants.', tag: 'On' },
          ],
        },
      ],
      faqs: [
        { q: 'Do I need to configure anything?', a: 'No. Agentimus works the moment it’s activated, with safe defaults. Open Settings only if you want to add your identity details or change a default.' },
        { q: 'Is my private or password-protected content exposed?', a: 'No. Drafts, private and password-protected posts are excluded from every output — llms.txt, Markdown, JSON-LD and the sitemap. Only published, publicly-visible content is ever described.' },
        { q: 'Will this block Google or real search engines?', a: 'No. Blocking is opt-in and aimed at AI training crawlers and spoofed bots. Real search engines are recognised and never blocked by default.' },
        { q: 'What does “Verified responses” (signing) do?', a: 'It signs your discovery documents (RFC 9421) so an agent can confirm they really came from your server and weren’t altered in transit. The key is generated on your server and never leaves it.' },
        { q: 'Does it slow my site down?', a: 'Barely. Generated documents are cached, JSON-LD is tiny, and the plugin makes no external calls on the front end — nothing is fetched from another server while your pages load. (The optional AI Visibility checks run only in the admin or on a schedule, never during a page view.)' },
        { q: 'Does it collect personal data?', a: 'No. The activity log stores no IP addresses, no identities and no query strings, and logged-in admins are skipped. See “Privacy & data” above.' },
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
        verify your site. Everything here is on by default unless marked otherwise; see the live documents
        on the
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'discovery' })">Discovery</button>
        tab and change any default under
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' })">Settings</button>.
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
              check whether they cite you. Your keys stay on your server. The “Verify live” readiness check
              also runs in <em>your browser</em> against your own URLs.
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
            user-agent, and the time. Plus daily, aggregate counts of human visits referred by AI assistants.
            Kept 30 days, pruned daily.
          </p>
          <ul class="ar-about-not">
            <li>No IP addresses</li>
            <li>No identities or logins (admins are skipped)</li>
            <li>No emails</li>
            <li>No query strings or full URLs</li>
          </ul>
          <p class="ar-about-priv__foot">No PII, no GDPR footprint by default. Your signing key is stored
            un-autoloaded and never leaves the server. Uninstalling removes the tables, settings and key.</p>
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
  </div>
</template>
