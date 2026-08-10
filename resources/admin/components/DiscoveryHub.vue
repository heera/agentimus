<script>
import ProviderRow from './ProviderRow.vue';
import { copyText } from '../js/clipboard.js';

export default {
  name: 'DiscoveryHub',
  components: { ProviderRow },
  props: {
    data: { type: Object, default: () => ({}) },
    refreshing: { type: Boolean, default: false },
  },
  emits: ['refresh', 'navigate'],
  data() {
    // Expand the auto-discovered group by default ONLY when there is nothing
    // declared — otherwise it stays collapsed, since it's predictable baseline.
    const resources = (this.data && this.data.resources) || [];
    return { showAuto: !resources.some((r) => !r.auto), copiedDoor: '' };
  },
  computed: {
    endpoints() {
      return this.data.endpoints || {};
    },
    counts() {
      return this.data.counts || { resources: 0, capabilities: 0, tools: 0, apis: 0, errors: 0 };
    },
    // The providers tile shows the REGISTERED number so it matches the provider
    // list below; "N public · M sign-in only" reconciles it with what anonymous
    // agents actually see. Same pattern for tools (whose big number is already
    // the full inventory). Older payloads without the split fields degrade to
    // the plain captions.
    providersRegistered() {
      const c = this.counts;
      return typeof c.resourcesRegistered === 'number' ? c.resourcesRegistered : c.resources;
    },
    providersHeld() {
      return Math.max(0, this.providersRegistered - (this.counts.resources || 0));
    },
    toolsHeld() {
      const c = this.counts;
      if (typeof c.toolsPublished !== 'number') return 0;
      return Math.max(0, (c.tools || 0) - c.toolsPublished);
    },
    // One row per capability token, built from the published union (so the row count
    // is exactly counts.capabilities) with the declaring provider named. token → the
    // full dotted name; verb → its last segment (read / create); provider → who declares
    // it (the first published resource that lists it).
    capabilityRows() {
      const norm = (c) => (typeof c === 'string' ? c : (c && c.id) || '');
      const union = this.capabilities.map(norm).filter(Boolean);
      return union.map((token) => {
        const owner = this.resources.find(
          (r) => Array.isArray(r.capabilities) && r.capabilities.map(norm).indexOf(token) !== -1
        );
        const parts = token.split('.');
        return {
          token,
          verb: parts[parts.length - 1] || 'read',
          provider: owner ? owner.title : '—',
        };
      });
    },
    // One row per API — matched to its provider by endpoint URL so the count
    // (counts.apis) has a countable home, the way capabilities do. base → the URL;
    // provider → whichever registered provider exposes that endpoint.
    apiRows() {
      return (this.data.apis || []).map((a) => {
        const url = a.base || '';
        const owner = this.resources.find(
          (r) => Array.isArray(r.endpoints) && r.endpoints.some((e) => e.url === url)
        );
        const authType = a.auth && a.auth.type ? a.auth.type : 'none';
        return {
          url,
          type: String(a.type || 'rest').toUpperCase(),
          auth: authType === 'none' ? 'public' : authType,
          provider: owner ? owner.title : '',
        };
      });
    },
    // The subset of capabilities that come from the site's OWN content (the auto-
    // discovered WordPress Core provider) — the ones the owner steers from Content
    // types. Plugin-declared capabilities (scheduling.*, crm.*) are the plugin's business.
    ownContentCapabilities() {
      const core = this.resources.find((r) => r.id === 'wordpress-core');
      if (!core || !Array.isArray(core.capabilities)) return [];
      return core.capabilities.map((c) => (typeof c === 'string' ? c : c.id)).filter(Boolean);
    },
    mcpAuthLabel() {
      return this.mcp.auth === 'oauth' ? 'OAuth' : 'sign-in';
    },
    // The summary strip's server cell: the one place server identity lives.
    mcpServerLabel() {
      const servers = this.mcp.servers || [];
      if (servers.length > 1) return servers.length + ' detected';
      if (servers.length === 1) return servers[0].name || 'Detected';
      return this.mcp.available ? 'Detected' : 'None';
    },
    // The PARTITION: one group per tool-bearing provider (same titles as the
    // "Registered providers" list above), each naming the doors that serve it.
    // Group counts add up to the summary total — the only arithmetic on the card.
    toolGroups() {
      const servers = this.mcp.servers || [];
      return this.resources
        .filter((r) => (r.tools || 0) > 0)
        .map((r) => {
          // The adapter flattens ability names (ns/tool → ns-tool) when it serves
          // them over MCP, so a namespace prefix match tells us whether a detected
          // server carries this group's tools.
          const ns = String(r.id || '').replace(/^abilities-/, '');
          const doors = [];
          servers.forEach((s) => {
            const list = s.tool_list || [];
            if (ns && list.some((t) => String(t.name || '').indexOf(ns + '-') === 0)) {
              doors.push(s.name || 'the MCP server');
            }
          });
          doors.push('the Abilities API');
          return { key: r.id, title: r.title, tools: r.tools, doors };
        });
    },
    // The doors' addresses — connection info only, deliberately without counts.
    doorRows() {
      const out = [];
      (this.mcp.servers || []).forEach((s) => {
        if (s.endpoint) out.push({ badge: 'MCP', url: s.endpoint, label: s.name || 'MCP server' });
      });
      if (!out.length && this.mcp.available && this.mcp.endpoint) {
        out.push({ badge: 'MCP', url: this.mcp.endpoint, label: 'MCP server' });
      }
      const abilities = (this.data && this.data.abilitiesEndpoint) || '';
      if (abilities && (this.counts.tools || 0) > 0) {
        out.push({ badge: 'REST', url: abilities, label: 'the Abilities API' });
      }
      return out;
    },
    resources() {
      return this.data.resources || [];
    },
    declared() {
      return this.resources.filter((r) => !r.auto);
    },
    autoDiscovered() {
      return this.resources.filter((r) => r.auto);
    },
    // The auto-discovery engines as compact status chips, shown inline in the
    // "Found automatically" group header (so engine + its results sit together).
    engineChips() {
      return this.adapters.map((a) => ({
        label: String((a && (a.title || a.id)) || 'Adapter').replace(' (auto-discovery)', '').replace('WordPress ', ''),
        ok: !!(a && a.available),
      }));
    },
    adapters() {
      return this.data.adapters || [];
    },
    capabilities() {
      return this.data.capabilities || [];
    },
    tools() {
      return this.data.tools || [];
    },
    mcp() {
      return this.data.mcp || { available: false, source: '', transport: '', tools: 0 };
    },
    wellKnown() {
      return this.data.wellKnown || [];
    },
    notices() {
      return this.data.notices || [];
    },
    discoveryUrl() {
      return this.endpoints.discovery || '';
    },
    discoveryPath() {
      try {
        return new URL(this.discoveryUrl).pathname;
      } catch (e) {
        return '/.well-known/discovery.json';
      }
    },
  },
  methods: {
    sourceLabel(source) {
      return { file: 'ON DISK', managed: 'MANAGED', generated: 'GENERATED' }[source] || String(source || 'SOURCE').toUpperCase();
    },
    // A summary stat → its section. We're already on the discovery tab, so route
    // through the app's own goTo (via 'navigate') for the shared smooth-scroll +
    // flash, rather than a bespoke scroll here.
    jumpTo(anchor) {
      this.$emit('navigate', { tab: 'discovery', anchor });
    },
    async copyDoor(d) {
      const text = (d && d.url) || '';
      if (!text) return;
      const ok = await copyText(text);
      if (!ok) return;
      this.copiedDoor = text;
      clearTimeout(this._doorCopyTimer);
      this._doorCopyTimer = setTimeout(() => { this.copiedDoor = ''; }, 2000);
    },
  },
};
</script>

<template>
  <div class="ar-wd">
    <!-- Canonical endpoint -->
    <section class="ar-card ar-wd-endpoint">
      <h2 class="ar-card__title">Discovery Endpoint</h2>

      <div class="ar-wd-endpoint-row">
        <div class="ar-wd-canonical">
          <span class="ar-wd-canonical__method">GET</span>
          <span class="ar-wd-canonical__path">{{ discoveryPath }}</span>
          <a class="ar-wd-canonical__ext" :href="discoveryUrl" target="_blank" rel="noopener" aria-label="Open discovery.json in a new tab">↗</a>
        </div>
        <button type="button" class="ar-btn" :disabled="refreshing" @click="$emit('refresh')">
          {{ refreshing ? 'Scanning…' : 'Re-scan' }}
        </button>
      </div>
      <!-- No alt-doc link list here: the always-visible rail already lists the discovery
           docs (a global shortcut), and the "Well-known documents" section below is the
           full inventory WITH its generated/managed status. Repeating the links here made
           agent-card.json / discovery.json appear three times on one screen. -->

      <!-- Each stat jumps (and flashes) to the section where its number is countable —
           so a total is never a dead end. Errors is a link only when there ARE errors
           (the "Registration status" card that would receive the jump renders only then);
           at zero it's a plain, calm cell. -->
      <div class="ar-wd-stats ar-wd-stats--fill">
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-providers')">
          <strong>{{ providersRegistered }}</strong>
          <span>providers</span>
          <small v-if="providersHeld > 0">{{ counts.resources }} public · {{ providersHeld }} sign-in only</small>
          <small v-else>Sources describing your site</small>
        </button>
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-capabilities')">
          <strong>{{ counts.capabilities }}</strong>
          <span>capabilities</span>
          <small>What agents can do or read</small>
        </button>
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-tools')">
          <strong>{{ counts.tools }}</strong>
          <span>tools</span>
          <small v-if="toolsHeld > 0">{{ counts.toolsPublished }} public · {{ toolsHeld }} sign-in only</small>
          <small v-else>Actions agents can run</small>
        </button>
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-apis')">
          <strong>{{ counts.apis }}</strong>
          <span>APIs</span>
          <small>Endpoints agents can read</small>
        </button>
        <button
          v-if="counts.errors > 0"
          type="button"
          class="ar-wd-stat is-link is-bad"
          @click="jumpTo('ar-wd-validation')"
        >
          <strong>{{ counts.errors }}</strong>
          <span>errors</span>
          <small>Problems to fix — open</small>
        </button>
        <div v-else class="ar-wd-stat">
          <strong>{{ counts.errors }}</strong>
          <span>errors</span>
          <small>None — all clear</small>
        </div>
      </div>
    </section>

    <!-- Registered providers — the SOURCES. Capabilities (below) are declared by
         these, so providers read first, then what they expose. -->
    <section id="ar-wd-providers" class="ar-card">
      <h2 class="ar-card__title">Registered Providers</h2>
      <p class="ar-card__lead">
        Everything this site tells AI agents about itself. Two sources: things <strong>provided by your
        plugins</strong>, and things Agentimus <strong>found automatically</strong> by scanning the site.
      </p>

      <p v-if="!resources.length" class="ar-wd-empty">
        Nothing registered yet. Agentimus will populate this automatically as it scans your site, and any
        WP_Discovery-aware plugin you install will add to it.
      </p>

      <template v-else>
        <!-- Provided by plugins — what a plugin deliberately declared. -->
        <div v-if="declared.length" class="ar-wd-group">
          <h3 class="ar-wd-group__title">
            Provided by your plugins <span class="ar-wd-group__count">{{ declared.length }}</span>
          </h3>
          <ul class="ar-wd-list">
            <ProviderRow v-for="r in declared" :key="r.id" :r="r" />
          </ul>
        </div>

        <!-- Found automatically — Agentimus's own scan, with engine status inline. -->
        <div v-if="autoDiscovered.length" class="ar-wd-group">
          <button
            type="button"
            class="ar-wd-group__toggle"
            :aria-expanded="showAuto"
            @click="showAuto = !showAuto"
          >
            <span class="ar-wd-group__caret" :class="{ 'is-open': showAuto }" aria-hidden="true">▸</span>
            Found automatically by Agentimus
            <span class="ar-wd-group__count">{{ autoDiscovered.length }}</span>
          </button>
          <p v-if="engineChips.length" class="ar-wd-engines">
            Agentimus checked:
            <span
              v-for="e in engineChips"
              :key="e.label"
              class="ar-wd-engine"
              :class="e.ok ? 'is-on' : 'is-off'"
            >{{ e.label }} {{ e.ok ? '✓' : '✕' }}</span>
          </p>
          <ul v-show="showAuto" class="ar-wd-list">
            <ProviderRow v-for="r in autoDiscovered" :key="r.id" :r="r" />
          </ul>
        </div>
      </template>
    </section>

    <!-- Capabilities — WHAT the providers above expose, aggregated into one countable
         list (one row per token). Sits right after the providers that declare it, and
         is where the dashboard's "Capabilities" tile lands, so its number connects to
         rows you can count — the way Providers lands on the provider cards. -->
    <section v-if="capabilityRows.length" id="ar-wd-capabilities" class="ar-card">
      <h2 class="ar-card__title">Capabilities <span class="ar-card__count">{{ capabilityRows.length }}</span></h2>
      <p class="ar-card__lead">
        The specific things agents may read or do, gathered from the providers above — one row each,
        so this list is exactly the <strong>{{ capabilityRows.length }}</strong> your dashboard counts.
        Each is declared by the provider named beside it.
      </p>
      <ul class="ar-wd-tools">
        <li v-for="c in capabilityRows" :key="c.token" class="ar-wd-tool">
          <div class="ar-wd-tool__id">
            <code>{{ c.token }}</code>
          </div>
          <div class="ar-wd-tool__meta">
            <span class="ar-wd-badge">{{ c.verb }}</span>
            <span class="ar-wd-cap-src">{{ c.provider }}</span>
          </div>
        </li>
      </ul>
      <p v-if="ownContentCapabilities.length" class="ar-card__note ar-card__note--wide">
        <strong>Your own site's content</strong> ({{ ownContentCapabilities.join(', ') }}) is steered by
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings', anchor: 'ar-content-types' })">Settings → Content types</button>
        — each ticked type brings its public taxonomies too. The rest are declared by the plugins that own them.
      </p>
    </section>

    <!-- APIs — the ENDPOINTS agents call, one countable row each (the APIs stat lands
         here). Same endpoints listed on the provider cards, gathered so the number has
         its own home instead of sharing the providers section. -->
    <section v-if="apiRows.length" id="ar-wd-apis" class="ar-card">
      <h2 class="ar-card__title">APIs <span class="ar-card__count">{{ apiRows.length }}</span></h2>
      <p class="ar-card__lead">
        The endpoints agents can call directly — one row each, so this list is exactly the
        <strong>{{ apiRows.length }}</strong> your dashboard counts. Each belongs to the provider named beside it.
      </p>
      <div v-for="a in apiRows" :key="a.url" class="ar-wd-canonical ar-wd-mcp-endpoint">
        <span class="ar-wd-canonical__method">{{ a.type }}</span>
        <span class="ar-wd-canonical__path">{{ a.url }}</span>
        <span v-if="a.provider" class="ar-wd-cap-src">{{ a.provider }}</span>
        <span class="ar-wd-auth" :class="a.auth === 'public' ? 'is-open' : 'is-locked'">{{ a.auth }}</span>
      </div>
    </section>

    <!-- MCP & tools -->
    <section id="ar-wd-tools" class="ar-card">
      <h2 class="ar-card__title">MCP &amp; Tools</h2>
      <p class="ar-card__lead">
        The tools a signed-in agent can run on this site, grouped by what provides them — the
        groups add up to the total. Each group names the doors that serve it, and the endpoints
        beneath are those doors' addresses. Anonymous agents are a separate story: only tools you
        publish appear in <code>/.well-known/mcp.json</code>, listed at the bottom. Running
        Agentimus’s own MCP server is its own switch (Settings → Discovery, off by default).
      </p>

      <!-- ONE total, stated once; the groups below PARTITION it (they visibly add up).
           Doors carry no counts anywhere — counts on overlapping doors invited
           "14 + 16 = 30". -->
      <div class="ar-wd-mcp">
        <div class="ar-wd-mcp__cell">
          <span>agent tools</span>
          <strong :class="counts.tools > 0 ? 'is-on' : 'is-off'">{{ counts.tools }}</strong>
        </div>
        <div class="ar-wd-mcp__cell">
          <span>public</span><strong>{{ typeof counts.toolsPublished === 'number' ? counts.toolsPublished : '—' }}</strong>
        </div>
        <div class="ar-wd-mcp__cell">
          <span>auth</span><strong>{{ counts.tools > 0 ? mcpAuthLabel : '—' }}</strong>
        </div>
        <div class="ar-wd-mcp__cell">
          <span>MCP server</span>
          <strong :class="mcp.available ? 'is-on' : 'is-off'">{{ mcpServerLabel }}</strong>
        </div>
      </div>

      <!-- The partition: provider groups, same names as "Registered providers" above. -->
      <ul v-if="toolGroups.length" class="ar-wd-tools">
        <li v-for="g in toolGroups" :key="g.key" class="ar-wd-tool">
          <div class="ar-wd-tool__id">
            <code>{{ g.title }}</code>
            <span class="ar-wd-tool__title">via {{ g.doors.join(' · ') }}</span>
          </div>
          <div class="ar-wd-tool__meta">
            <span class="ar-wd-badge">{{ g.tools }} {{ g.tools === 1 ? 'tool' : 'tools' }}</span>
          </div>
        </li>
      </ul>

      <!-- The doors' addresses — connection info only, no numbers. COPY, not open:
           both doors require a signed-in agent, so a browser click can only ever
           answer 401 — the real user intent is pasting the address into a tool. -->
      <div v-for="d in doorRows" :key="d.url" class="ar-wd-canonical ar-wd-mcp-endpoint">
        <span class="ar-wd-canonical__method">{{ d.badge }}</span>
        <span class="ar-wd-canonical__path">{{ d.url }}</span>
        <button
          type="button"
          class="ar-wd-canonical__ext ar-wd-copy"
          :class="{ 'is-copied': copiedDoor === d.url }"
          :aria-label="'Copy the ' + d.label + ' address'"
          @click="copyDoor(d)"
        >{{ copiedDoor === d.url ? '✓ copied' : 'copy' }}</button>
      </div>

      <ul v-if="tools.length" class="ar-wd-tools">
        <li v-for="t in tools" :key="t.name" class="ar-wd-tool">
          <div class="ar-wd-tool__id">
            <code>{{ t.name }}</code>
            <span v-if="t.title" class="ar-wd-tool__title">{{ t.title }}</span>
          </div>
          <div class="ar-wd-tool__meta">
            <span v-if="t.annotations && t.annotations.readOnlyHint" class="ar-wd-badge">read-only</span>
            <span v-if="t.inputSchema && Object.keys(t.inputSchema).length" class="ar-wd-badge ar-wd-badge--schema">schema</span>
            <span class="ar-wd-auth" :class="t.auth === 'none' ? 'is-open' : 'is-locked'">
              {{ t.auth === 'none' ? 'public' : t.auth }}
            </span>
          </div>
        </li>
      </ul>
      <!-- Two different empty states: tools can exist but be deliberately withheld
           from anonymous discovery (all sign-in-only since 1.20.0) — saying "none
           yet" while the count above reads 14 would be a lie. -->
      <!-- No count in this sentence: the mini-table above says 14 (this MCP
           server's tools) while the site-wide inventory is 17 (core's abilities
           aren't on Agentimus's scoped server) — citing either here would sit
           beside the other and read as a contradiction. -->
      <p v-else-if="counts.tools > 0" class="ar-wd-empty">
        Nothing is listed here because every agent tool on this site requires sign-in, and sign-in-only
        tools are deliberately not advertised in the public documents — an anonymous reader gets no map
        of your tooling. An agent holding real credentials still discovers and runs them the proper way.
      </p>
      <p v-else class="ar-wd-empty">
        No agent tools yet. They come from the WordPress Abilities API (in core from 6.9, or the Abilities
        API plugin on older versions) or an MCP-aware plugin — once abilities are registered, they appear here.
      </p>
    </section>

    <!-- Well-known documents — full width (validation status now lives as a
         compact chip in the right rail). -->
    <section class="ar-card">
      <h2 class="ar-card__title">Well-Known Documents</h2>
      <ul class="ar-wd-wk">
        <li v-for="w in wellKnown" :key="w.name">
          <a :href="w.url" target="_blank" rel="noopener"><code>/.well-known/{{ w.name }}</code></a>
          <span class="ar-wd-src" :class="`is-${w.source}`">{{ sourceLabel(w.source) }}</span>
        </li>
      </ul>
    </section>

    <!-- Validation only takes main-column space when there is something to fix;
         the all-clear state is the green chip in the rail. -->
    <section v-if="notices.length" id="ar-wd-validation" class="ar-card">
      <h2 class="ar-card__title">Registration Status</h2>
      <ul class="ar-wd-notices">
        <li v-for="(n, i) in notices" :key="i" class="ar-wd-notice" :class="`is-${n.level || 'info'}`">
          <span class="ar-wd-notice__tag">{{ String(n.level || 'info').toUpperCase() }}</span>
          <span>{{ n.message }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>
