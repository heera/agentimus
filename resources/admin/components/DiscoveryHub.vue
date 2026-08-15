<script>
import ProviderRow from './ProviderRow.vue';
import { copyText } from '../js/clipboard.js';

// One plain-words line per well-known address — what the file is FOR, in the
// owner's language, not the spec's. Keyed by served name; a row without an
// entry (a future doc a provider adds) simply shows no note.
const WK_NOTES = {
  'discovery.json': 'Everything this site tells AI assistants, gathered in one file.',
  'agent-card.json': 'A card that introduces this site to AI assistants.',
  'agent.json': 'The same introduction card, at its older address.',
  'mcp.json': 'Announces this site’s MCP server and the tools it offers.',
  'openapi.json': 'Describes the public API in a form software can read.',
  'api-catalog': 'A short list of the APIs this site offers.',
  'agent-skills': 'Lists the skills AI assistants can use on this site.',
  'http-message-signatures-directory': 'This site’s public keys, so others can verify what it signs.',
  'oauth-protected-resource': 'Tells connecting apps how to sign in to the MCP server.',
  'security.txt': 'Who to contact if someone finds a security problem.',
};

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
    // openTools: which tool groups have been expanded. Closed by default —
    // 42 names at once buries the four provider rows that are the point of
    // this list.
    return { showAuto: !resources.some((r) => !r.auto), copiedDoor: '', openTools: {}, capsOverflow: false };
  },
  mounted() {
    this.$nextTick(this.measureCaps);
  },
  updated() {
    this.measureCaps();
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
          // Tools only: the attachable documents have their own section below,
          // the way MCP itself splits tools/list from resources/list.
          const list = (r.toolList || []).filter((t) => 'resource' !== t.kind);
          return { key: r.id, title: r.title, tools: r.tools, doors, list };
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
        // A display name, not a sentence fragment: it is printed beside the
        // address now, and the old "the Abilities API" also made the copy
        // button announce "Copy the the Abilities API address".
        out.push({ badge: 'REST', url: abilities, label: 'Abilities API' });
      }
      return out;
    },
    resources() {
      return this.data.resources || [];
    },
    // Three buckets, no row in two of them: a plugin declared it, Agentimus
    // describes it, or a scan found it.
    declared() {
      return this.resources.filter((r) => !r.auto && !r.described);
    },
    describedByAgentimus() {
      return this.resources.filter((r) => r.described);
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
    // The served rows with their plain-words note attached.
    wellKnownRows() {
      return this.wellKnown.map((w) => ({ ...w, note: WK_NOTES[w.name] || '' }));
    },
    // The attachable documents, gathered across providers for their own section.
    docRows() {
      const out = [];
      this.resources.forEach((r) => {
        (r.toolList || []).forEach((t) => {
          if ('resource' === t.kind) out.push({ name: t.name, title: t.title || '', url: t.uri || '' });
        });
      });
      return out;
    },
    // The first rendered row that carries the sign-in explanation shows it in
    // full; later rows defer to it. Two identical paragraphs back to back
    // taught nothing twice. Render order, not payload order.
    firstHeldId() {
      // Same order the groups render in, so the full explanation lands on the
      // first held row an owner actually reads.
      const held = [...this.declared, ...this.describedByAgentimus, ...this.autoDiscovered].filter(
        (r) => !r.suppressed && r.notPublic
      );
      return held.length ? held[0].id : '';
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
    toggleTools(key) {
      this.openTools = { ...this.openTools, [key]: !this.openTools[key] };
    },
    // Does the capability list actually overflow its box? Measured, not
    // guessed from a row count: macOS hides the overlay scrollbar at rest, so
    // a capped list simply looks like a list that ended, and the only other
    // clue is a total in the heading. Only sets when the answer CHANGES —
    // `updated()` writing state unconditionally would loop.
    measureCaps() {
      const el = this.$refs.capsBox;
      const over = !!(el && el.scrollHeight > el.clientHeight + 2);
      if (over !== this.capsOverflow) this.capsOverflow = over;
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
          <a class="ar-wd-canonical__ext" :href="discoveryUrl" target="_blank" rel="noopener" aria-label="Open discovery.json in a new tab"></a>
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
          <small>What AI assistants may read or do</small>
        </button>
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-tools')">
          <strong>{{ counts.tools }}</strong>
          <span>tools</span>
          <small v-if="toolsHeld > 0">{{ counts.toolsPublished }} public · {{ toolsHeld }} sign-in only</small>
          <small v-else>Actions AI assistants can run</small>
        </button>
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-apis')">
          <strong>{{ counts.apis }}</strong>
          <span>APIs</span>
          <!-- Same words as the dashboard tile: one count, one caption. "Endpoints
               agents can read" said the document verb about a callable thing, and
               collided with the capabilities tile's "read" beside it. -->
          <small>Addresses AI assistants can call for data</small>
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

      <!-- No word legend here (tried 2026-08-11, removed the same day): the owner's
           call is that each SECTION's own lead teaches its word where the rows are,
           not a glossary strip on the endpoint card. -->
    </section>

    <!-- Registered providers — the SOURCES. Capabilities (below) are declared by
         these, so providers read first, then what they expose. -->
    <section id="ar-wd-providers" class="ar-card">
      <h2 class="ar-card__title">Registered Providers</h2>
      <p class="ar-card__lead">
        Everything AI assistants can learn about your site, in one list. Each row is one thing they
        can read or do. The three groups below only say <strong>where each row came from</strong> —
        every row counts the same.
      </p>

      <p v-if="!resources.length" class="ar-wd-empty">
        Nothing registered yet — Agentimus fills this in on its own as it scans your site, and any
        plugin that speaks the WP_Discovery format adds its own rows when you install it.
      </p>

      <template v-else>
        <!-- Provided by plugins — what a plugin deliberately declared. -->
        <div v-if="declared.length" class="ar-wd-group">
          <h3 class="ar-wd-group__title">
            Plugins that describe themselves <span class="ar-wd-group__count">{{ declared.length }}</span>
          </h3>
          <p class="ar-wd-engines">
            The plugin wrote these lines itself. Agentimus passes them on unchanged.
          </p>
          <ul class="ar-wd-list">
            <ProviderRow v-for="r in declared" :key="r.id" :r="r" :brief-held="r.id !== firstHeldId" />
          </ul>
        </div>

        <!-- Described by Agentimus — a plugin it recognises, written by hand.
             Neither declared by that plugin nor found by a scan, and saying
             either would be untrue. This is what the Plugins tab promises, so
             it is shown open rather than folded away. -->
        <div v-if="describedByAgentimus.length" class="ar-wd-group">
          <h3 class="ar-wd-group__title">
            Plugins Agentimus describes for you <span class="ar-wd-group__count">{{ describedByAgentimus.length }}</span>
          </h3>
          <p class="ar-wd-engines">
            Agentimus recognises these plugins and writes the description itself, so they work
            without the plugin doing anything.
          </p>
          <ul class="ar-wd-list">
            <ProviderRow v-for="r in describedByAgentimus" :key="r.id" :r="r" :brief-held="r.id !== firstHeldId" />
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
            Found by looking at your site
            <span class="ar-wd-group__count">{{ autoDiscovered.length }}</span>
          </button>
          <p class="ar-wd-engines">
            Nobody described these. Agentimus read what your site already publishes: your content,
            and the jobs your plugins registered with WordPress.
          </p>
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
            <ProviderRow v-for="r in autoDiscovered" :key="r.id" :r="r" :brief-held="r.id !== firstHeldId" />
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
      <!-- "read or do" invited the owner's own question — "are these tools?". The
           verb stays (a plugin can declare a create capability — the demo site
           does), so the next sentence answers the question outright instead. -->
      <p class="ar-card__lead">
        The specific things AI assistants may read or do, gathered from the providers above — one row each,
        so this list is exactly the <strong>{{ capabilityRows.length }}</strong> your dashboard counts.
        These are permissions, not tools: each names what an API allows and who declares it. The
        tools an assistant can run are their own list below, under <strong>For a signed-in assistant</strong>.
      </p>
      <!-- Scrolls inside a fixed height rather than growing without limit. Most
           of these are one per public REST post type and taxonomy, so the length
           is the SITE's to decide, not ours — a busy shop with an LMS can push
           this past 60 rows and the card would own the whole screen. Same box
           the index groups use. -->
      <ul ref="capsBox" class="ar-wd-tools ar-wd-scrollbox">
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
      <p v-if="capsOverflow" class="ar-wd-scrollnote">
        Showing the first few — scroll the list for all {{ capabilityRows.length }}.
      </p>
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
        The endpoints AI assistants can call directly — one row each, so this list is exactly the
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
        The tools a signed-in assistant can run on this site, grouped by what provides them — the
        groups add up to the total. Each group names the doors that serve it, and the endpoints
        beneath are those doors' addresses. Anonymous assistants are a separate story: only tools you
        publish appear in <code>/.well-known/mcp.json</code>, listed at the bottom. Running
        Agentimus’s own MCP server is its own switch (Settings → Discovery, off by default).
      </p>

      <!-- ONE total, stated once; the groups below PARTITION it (they visibly add up).
           Doors carry no counts anywhere — counts on overlapping doors invited
           "14 + 16 = 30". -->
      <div class="ar-wd-mcp">
        <div class="ar-wd-mcp__cell">
          <span>assistant tools</span>
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
      <!-- Three panels, not three headings in one column. Headers alone left
           the sections reading as one long list; a bordered panel makes each
           one a thing you can see the edges of. Border only, surface unchanged
           — these always hold text, and a step of separation here is paid out
           of the legibility of what reads on it (the same reason .ar-fold
           drops back to the plain surface the moment it opens). -->
      <div v-if="toolGroups.length" class="ar-wd-sect">
      <p class="ar-wd-lhead">
        For a signed-in assistant
        <span class="ar-wd-group__count">{{ counts.tools }}</span>
        <span class="ar-wd-lhead__note">
          tools, grouped by what provides them
        </span>
      </p>
      <ul class="ar-wd-tools">
        <li v-for="g in toolGroups" :key="g.key" class="ar-wd-grp">
          <!-- Openable only when we actually have the names: a caret that
               reveals nothing is worse than no caret. -->
          <component
            :is="g.list.length ? 'button' : 'div'"
            :type="g.list.length ? 'button' : null"
            class="ar-wd-grp__row"
            :class="{ 'is-static': !g.list.length }"
            :aria-expanded="g.list.length ? String(!!openTools[g.key]) : null"
            @click="g.list.length && toggleTools(g.key)"
          >
            <span v-if="g.list.length" class="ar-wd-group__caret" :class="{ 'is-open': openTools[g.key] }" aria-hidden="true">▸</span>
            <span class="ar-wd-tool__id">
              <code>{{ g.title }}</code>
              <span class="ar-wd-tool__title">via {{ g.doors.join(' · ') }}</span>
            </span>
            <span class="ar-wd-badge">{{ g.tools }} {{ g.tools === 1 ? 'tool' : 'tools' }}</span>
          </component>
          <ul v-if="g.list.length" v-show="openTools[g.key]" class="ar-wd-grp__names">
            <li v-for="t in g.list" :key="t.name">
              <code>{{ t.name }}</code>
              <span v-if="t.title">{{ t.title }}</span>
            </li>
          </ul>
        </li>
      </ul>
      </div>

      <!-- Documents live APART from the tools — MCP itself keeps the two lists
           apart (tools/list vs resources/list), and a "documents" chip inside a
           tools group read as four secret abilities (owner, 2026-08-11). They
           are abilities too, technically — registered with a URI instead of an
           action — but what an agent DOES with them is attach and read, so the
           screen mirrors the protocol's own split. -->
      <div v-if="counts.docs" class="ar-wd-sect">
        <p class="ar-wd-lhead">
          Documents an assistant can attach
          <span class="ar-wd-group__count">{{ counts.docs }}</span>
          <span class="ar-wd-lhead__note">read in the session, not run — the files themselves are public</span>
        </p>
        <!-- Same row grammar as the Published list beside it — a bare code+title
             line was the one undressed row style on the card. The chip is the
             literal truth the section note spells out: these files are public. -->
        <ul v-if="docRows.length" class="ar-wd-tools">
          <li v-for="d in docRows" :key="d.name" class="ar-wd-tool">
            <div class="ar-wd-tool__id">
              <a v-if="d.url" class="ar-wd-doclink" :href="d.url" target="_blank" rel="noopener"><code>{{ d.name }}</code></a>
              <code v-else>{{ d.name }}</code>
              <span v-if="d.title" class="ar-wd-tool__title">{{ d.title }}</span>
            </div>
            <div class="ar-wd-tool__meta">
              <span class="ar-wd-auth is-open">public</span>
            </div>
          </li>
        </ul>
        <p class="ar-card__note ar-card__note--wide">
          These are this site's own public files, offered inside the connection so an assistant
          can attach one without fetching the URL. Anyone can read them at their addresses either way.
        </p>
      </div>

      <!-- The doors' addresses — connection info only, no numbers. COPY, not open:
           both doors require a signed-in agent, so a browser click can only ever
           answer 401 — the real user intent is pasting the address into a tool.
           "Where they connect" left "they" dangling — it could as easily have
           meant the tools — so the header names the referent instead. -->
      <div v-if="doorRows.length" class="ar-wd-sect">
      <p class="ar-wd-lhead">
        The doors named above
        <span class="ar-wd-lhead__note">where an assistant connects — signed in, so copy rather than open</span>
      </p>
      <div v-for="d in doorRows" :key="d.url" class="ar-wd-canonical ar-wd-mcp-endpoint">
        <span class="ar-wd-canonical__method">{{ d.badge }}</span>
        <span class="ar-wd-canonical__path">{{ d.url }}</span>
        <!-- Which door this is. The groups above name their doors in prose
             ("via the Abilities API") while these rows carried only a URL, so
             the reader had to recognise a door by its path — and the APIs
             section, using this very row, names its provider beside it. Same
             row, same grammar. -->
        <span class="ar-wd-cap-src">{{ d.label }}</span>
        <button
          type="button"
          class="ar-wd-canonical__ext ar-wd-copy"
          :class="{ 'is-copied': copiedDoor === d.url }"
          :aria-label="'Copy the ' + d.label + ' address'"
          @click="copyDoor(d)"
        >{{ copiedDoor === d.url ? '✓ copied' : 'copy' }}</button>
      </div>
      </div>

      <!-- A DIFFERENT list from the groups above: the tools an anonymous agent
           is handed, not the ones a signed-in agent can run. Both render as
           identical rows, so its own panel is what keeps the two apart. -->
      <div v-if="tools.length" class="ar-wd-sect">
      <p class="ar-wd-lhead">
        Published for anonymous assistants
        <span class="ar-wd-group__count">{{ tools.length }}</span>
        <span class="ar-wd-lhead__note">in /.well-known/mcp.json</span>
      </p>
      <ul class="ar-wd-tools">
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
      </div>
      <!-- Two different empty states: tools can exist but be deliberately withheld
           from anonymous discovery (all sign-in-only since 1.20.0) — saying "none
           yet" while the count above reads 14 would be a lie. -->
      <!-- No count in this sentence: the mini-table above says 14 (this MCP
           server's tools) while the site-wide inventory is 17 (core's abilities
           aren't on Agentimus's scoped server) — citing either here would sit
           beside the other and read as a contradiction. -->
      <p v-else-if="counts.tools > 0" class="ar-wd-empty">
        Nothing is listed here because every tool on this site requires sign-in, and sign-in-only
        tools are deliberately not advertised in the public documents — an anonymous reader gets no map
        of your tooling. An assistant holding real credentials still discovers and runs them the proper way.
      </p>
      <p v-else class="ar-wd-empty">
        No tools for AI assistants yet. They come from the WordPress Abilities API (in core from 6.9, or the
        Abilities API plugin on older versions) or an MCP-aware plugin — once abilities are registered, they appear here.
      </p>
    </section>

    <!-- Well-known documents — full width (validation status now lives as a
         compact chip in the right rail). -->
    <section class="ar-card">
      <h2 class="ar-card__title">Well-Known Documents</h2>
      <p class="ar-card__lead">
        The standard addresses an AI assistant looks for first. Agentimus serves every one of these —
        if a real file on your server answers one instead, it is marked <strong>on disk</strong>,
        because that file wins and nothing here can override it.
      </p>
      <ul class="ar-wd-wk">
        <li v-for="w in wellKnownRows" :key="w.name">
          <a :href="w.url" target="_blank" rel="noopener"><code>/.well-known/{{ w.name }}</code></a>
          <span v-if="w.spec" class="ar-wd-src ar-wd-src--spec">{{ w.spec }}</span>
          <!-- ON DISK stays the exception that changes what an owner would do:
               the web server answers it and we never override it. The quiet
               "served" tick on every other row is the owner's 2026-08-11 ask —
               a bare path said nothing about being live and connected. With a
               note per row, the tick is no longer the row's only content (the
               old per-row "AGENTIMUS"/GENERATED/MANAGED marks were, and said
               only our own plumbing's name — those stay gone). -->
          <span v-if="'file' === w.source" class="ar-wd-src is-file">ON DISK</span>
          <span v-else class="ar-wd-src is-live">SERVED ✓</span>
          <p v-if="w.note" class="ar-wd-wk__note">{{ w.note }}</p>
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
