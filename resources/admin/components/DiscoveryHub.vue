<script>
import ProviderRow from './ProviderRow.vue';

export default {
  name: 'DiscoveryHub',
  components: { ProviderRow },
  props: {
    data: { type: Object, default: () => ({}) },
    refreshing: { type: Boolean, default: false },
  },
  emits: ['refresh'],
  data() {
    // Expand the auto-discovered group by default ONLY when there is nothing
    // declared — otherwise it stays collapsed, since it's predictable baseline.
    const resources = (this.data && this.data.resources) || [];
    return { showAuto: !resources.some((r) => !r.auto) };
  },
  computed: {
    endpoints() {
      return this.data.endpoints || {};
    },
    counts() {
      return this.data.counts || { resources: 0, capabilities: 0, apis: 0, errors: 0 };
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
        label: a.title.replace(' (auto-discovery)', '').replace('WordPress ', ''),
        ok: a.available,
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
      return { file: 'ON DISK', managed: 'MANAGED', generated: 'GENERATED' }[source] || source.toUpperCase();
    },
  },
};
</script>

<template>
  <div class="ar-wd">
    <!-- Canonical endpoint -->
    <section class="ar-card ar-wd-endpoint">
      <h2 class="ar-card__title">Discovery endpoint</h2>

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

      <ul class="ar-links ar-wd-altlinks">
        <li><a :href="endpoints.agentCard" target="_blank" rel="noopener">agent-card.json</a></li>
        <li><a :href="endpoints.agentJson" target="_blank" rel="noopener">agent.json (alias)</a></li>
        <li><a :href="endpoints.rest" target="_blank" rel="noopener">REST: /heera-agent-discovery/v1/discovery</a></li>
      </ul>

      <div class="ar-wd-stats">
        <div class="ar-wd-stat">
          <strong>{{ counts.resources }}</strong>
          <span>providers</span>
          <small>Sources describing your site</small>
        </div>
        <div class="ar-wd-stat">
          <strong>{{ counts.capabilities }}</strong>
          <span>capabilities</span>
          <small>What agents can do or read</small>
        </div>
        <div class="ar-wd-stat">
          <strong>{{ counts.tools }}</strong>
          <span>tools</span>
          <small>Actions agents can run</small>
        </div>
        <div class="ar-wd-stat">
          <strong>{{ counts.apis }}</strong>
          <span>APIs</span>
          <small>Endpoints agents can read</small>
        </div>
        <div class="ar-wd-stat" :class="{ 'is-bad': counts.errors > 0 }">
          <strong>{{ counts.errors }}</strong>
          <span>errors</span>
          <small>Problems to fix</small>
        </div>
      </div>
    </section>

    <!-- Registered providers -->
    <section id="ar-wd-providers" class="ar-card">
      <h2 class="ar-card__title">Registered providers</h2>
      <p class="ar-card__lead">
        Everything this site tells AI agents about itself. Two sources: things <strong>provided by your
        plugins</strong>, and things Heera Discovery <strong>found automatically</strong> by scanning the site.
      </p>

      <p v-if="!resources.length" class="ar-wd-empty">
        Nothing registered yet. Heera Discovery will populate this automatically as it scans your site, and any
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

        <!-- Found automatically — Heera Discovery's own scan, with engine status inline. -->
        <div v-if="autoDiscovered.length" class="ar-wd-group">
          <button
            type="button"
            class="ar-wd-group__toggle"
            :aria-expanded="showAuto"
            @click="showAuto = !showAuto"
          >
            <span class="ar-wd-group__caret" :class="{ 'is-open': showAuto }" aria-hidden="true">▸</span>
            Found automatically by Heera Discovery
            <span class="ar-wd-group__count">{{ autoDiscovered.length }}</span>
          </button>
          <p v-if="engineChips.length" class="ar-wd-engines">
            Heera Discovery checked:
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

    <!-- MCP & tools -->
    <section id="ar-wd-tools" class="ar-card">
      <h2 class="ar-card__title">MCP &amp; tools</h2>
      <p class="ar-card__lead">
        The executable side of the WordPress Abilities API — the same source as the
        <strong>Core abilities</strong> resource above, projected into MCP tool shape and published at
        <code>/.well-known/mcp.json</code>. This plugin advertises tools — it doesn't run an MCP server.
      </p>

      <div class="ar-wd-mcp">
        <div class="ar-wd-mcp__cell">
          <span>MCP server</span>
          <strong :class="mcp.available ? 'is-on' : 'is-off'">{{ mcp.available ? 'detected' : 'none' }}</strong>
        </div>
        <div class="ar-wd-mcp__cell">
          <span>source</span><strong>{{ mcp.source || '—' }}</strong>
        </div>
        <div class="ar-wd-mcp__cell">
          <span>transport</span><strong>{{ mcp.transport || '—' }}</strong>
        </div>
        <div class="ar-wd-mcp__cell">
          <span>tools</span><strong>{{ mcp.tools }}</strong>
        </div>
      </div>

      <div v-if="mcp.available && mcp.endpoint" class="ar-wd-canonical ar-wd-mcp-endpoint">
        <span class="ar-wd-canonical__method">MCP</span>
        <span class="ar-wd-canonical__path">{{ mcp.endpoint }}</span>
        <a class="ar-wd-canonical__ext" :href="mcp.endpoint" target="_blank" rel="noopener" aria-label="Open mcp.json in a new tab">↗</a>
      </div>

      <p v-if="!mcp.available && mcp.source === 'abilities'" class="ar-wd-note">
        Tools are registered via the Abilities API, but no MCP server is installed. Add an MCP adapter
        to make them callable; agents can still read the signatures here.
      </p>

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
      <p v-else class="ar-wd-empty">
        No tools registered. Install the Abilities API (heading for core) or an MCP-aware plugin.
      </p>
    </section>

    <!-- Well-known + validation, side by side -->
    <div class="ar-wd-cols">
      <section class="ar-card">
        <h2 class="ar-card__title">Well-known documents</h2>
        <ul class="ar-wd-wk">
          <li v-for="w in wellKnown" :key="w.name">
            <a :href="w.url" target="_blank" rel="noopener"><code>/.well-known/{{ w.name }}</code></a>
            <span class="ar-wd-src" :class="`is-${w.source}`">{{ sourceLabel(w.source) }}</span>
          </li>
        </ul>
      </section>

      <section class="ar-card" :class="{ 'ar-wd-valid': !notices.length }">
        <h2 class="ar-card__title">Validation</h2>
        <div v-if="!notices.length" class="ar-wd-allclear">
          <span class="ar-wd-allclear__badge" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7" /></svg>
          </span>
          <p class="ar-wd-allclear__title">All registrations valid</p>
          <p class="ar-wd-allclear__sub">No schema or conflict issues detected.</p>
        </div>
        <ul v-else class="ar-wd-notices">
          <li v-for="(n, i) in notices" :key="i" class="ar-wd-notice" :class="`is-${n.level}`">
            <span class="ar-wd-notice__tag">{{ n.level.toUpperCase() }}</span>
            <span>{{ n.message }}</span>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>
