<script>
/**
 * Request log — the per-request view the dashboard's rollups can't give you.
 *
 * The Dashboard answers "who visited, and how much". This answers "what did THIS client
 * actually fetch", by letting the two breakdowns intersect: filter by agent AND endpoint
 * and you get the cross-tab the summary cards structurally cannot show.
 *
 * Paging is a cursor walk, not page numbers. The server hands back a `cursor` (the id of
 * the last row on this page); we push it on a stack so "Newer" can walk back. Page numbers
 * would be a lie here — a crawler inserting rows mid-walk shifts every offset.
 *
 * The footer always states what the view CANNOT show: nothing older than the retention
 * window exists, and a busy site's row cap may have discarded rows inside it. A log that
 * quietly stops short reads as "that's everything".
 */
import SelectMenu from './SelectMenu.vue';
import { uaTip } from '../uaTip.js';

export default {
  name: 'RequestLog',
  components: { SelectMenu },
  mixins: [uaTip],
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show, so it stays mounted across tab switches. Fetch on first reveal.
    active: { type: Boolean, default: false },
  },
  emits: ['flash'],
  data() {
    return {
      filters: { from: '', to: '', agent: '', endpoint: '', network: '', ua: '', verdict: '' },
      // Distinct values seen in the retained window; fetched once, alongside the first page.
      facets: { agents: [], endpoints: [], networks: [] },
      rows: [],
      total: 0,
      hasMore: false,
      cursor: null,
      retentionDays: 30,
      perPage: 50,
      // Cursor of the page we're on, plus the trail behind it, so "Newer" can reverse.
      before: 0,
      trail: [],
      loading: false,
      loaded: false,
      error: '',
    };
  },
  computed: {
    hasFilters() {
      return Object.values(this.filters).some((v) => v !== '');
    },
    // Only meaningful once "identify every bot" has attributed something. Read from the
    // facets, not the current page — a filtered page can be all-blank while the site does
    // have network data, and the column would vanish mid-session.
    hasNetwork() {
      return this.facets.networks.length > 0;
    },
    // '' is a real option — it clears the filter — so it leads each list. Just "Any": the
    // label above the control already says which field it is, and "Any endpoint" is wide
    // enough to ellipsis inside a 109px trigger.
    agentOptions() {
      return [{ value: '', label: 'Any' }, ...this.facets.agents];
    },
    endpointOptions() {
      return [{ value: '', label: 'Any' }, ...this.facets.endpoints];
    },
    networkOptions() {
      return [{ value: '', label: 'Any' }, ...this.facets.networks];
    },
    verdictOptions() {
      return [
        { value: '', label: 'Any' },
        { value: '1', label: 'Verified' },
        { value: '2', label: 'Spoofed' },
        { value: '0', label: 'Unchecked' },
      ];
    },
    pageFrom() {
      return this.rows.length ? this.trail.length * this.perPage + 1 : 0;
    },
    pageTo() {
      return this.trail.length * this.perPage + this.rows.length;
    },
  },
  watch: {
    active(on) {
      if (on && !this.loaded) this.load();
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    async loadFacets() {
      if (!this.api) return;
      try {
        const f = await this.api.getActivityLogFacets();
        this.facets = {
          agents: f.agents || [],
          endpoints: f.endpoints || [],
          networks: f.networks || [],
        };
      } catch (e) {
        // Non-fatal: the log still lists and pages. The dropdowns just have nothing to
        // offer, so don't surface an error over a working table.
        this.facets = { agents: [], endpoints: [], networks: [] };
      }
    },
    async load(before = 0) {
      if (!this.api) return;
      if (!this.loaded) this.loadFacets();
      this.loading = true;
      this.error = '';
      try {
        const res = await this.api.getActivityLog({
          ...this.filters,
          before: before || '',
          per_page: this.perPage,
        });
        this.rows = res.rows || [];
        this.total = res.total || 0;
        this.hasMore = !!res.hasMore;
        this.cursor = res.cursor || null;
        this.retentionDays = res.retentionDays || 30;
        this.before = before;
        this.loaded = true;
      } catch (e) {
        // A 400 from a malformed date arrives here with the server's own message; show it
        // rather than a generic failure, because it tells the owner exactly what to fix.
        this.error = (e && e.message) || 'Unable to load the request log.';
        this.rows = [];
        this.total = 0;
        this.hasMore = false;
      } finally {
        this.loading = false;
      }
    },
    apply() {
      this.trail = [];
      this.load(0);
    },
    reset() {
      this.filters = { from: '', to: '', agent: '', endpoint: '', network: '', ua: '', verdict: '' };
      this.apply();
    },
    older() {
      if (!this.cursor) return;
      this.trail.push(this.before);
      this.load(this.cursor);
    },
    newer() {
      if (!this.trail.length) return;
      this.load(this.trail.pop());
    },
    // Click a cell to pivot the whole log onto that value — the fastest path from
    // "this row looks odd" to "show me everything this client did".
    pivot(key, value) {
      if (!value) return;
      this.filters[key] = value;
      this.apply();
    },
    verdictLabel(v) {
      if (v === 1) return 'verified';
      if (v === 2) return 'spoofed';
      return '';
    },
    ago(iso) {
      const t = Date.parse(iso);
      if (!t) return '';
      const m = Math.round((Date.now() - t) / 60000);
      if (m < 1) return 'just now';
      if (m < 60) return `${m}m ago`;
      const h = Math.round(m / 60);
      if (h < 24) return `${h}h ago`;
      return `${Math.round(h / 24)}d ago`;
    },
  },
};
</script>

<template>
  <section class="ar-card ar-log">
    <!-- The page header already carries the title and the one-line explanation; repeating
         them here would just be the same sentence twice on one screen. -->
    <div class="ar-card__head ar-card__head--inline">
      <div class="ar-card__titlewrap">
        <h2 class="ar-card__title">Requests</h2>
        <!-- Refreshes THIS page of results, keeping the filters and the cursor — the same
             "update this card" affordance the readiness report uses beside its own title. -->
        <button
          type="button"
          class="ar-log__refresh"
          :class="{ 'is-busy': loading }"
          :disabled="loading"
          :aria-label="loading ? 'Reloading the requests…' : 'Reload these requests'"
          @mouseenter="showUaTip($event, loading ? 'Reloading…' : 'Reload these requests', '')"
          @mouseleave="hideUaTip"
          @click="load(before)"
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>
        </button>
      </div>
    </div>

    <!-- Client, endpoint and network are closed sets drawn from the log itself, so they're
         dropdowns: nobody should have to type "Bytespider (ByteDance)" exactly. Only the
         User-Agent is free text, because it's an unbounded string and the match is a prefix. -->
    <div class="ar-log__filters">
      <div class="ar-log__row">
        <div class="ar-log__field">
          <span class="ar-log__label">Client</span>
          <SelectMenu v-model="filters.agent" :options="agentOptions" aria-label="Filter by client" />
        </div>
        <div class="ar-log__field">
          <span class="ar-log__label">Endpoint</span>
          <SelectMenu v-model="filters.endpoint" :options="endpointOptions" mono aria-label="Filter by endpoint" />
        </div>
        <div v-if="hasNetwork" class="ar-log__field">
          <span class="ar-log__label">Network</span>
          <SelectMenu v-model="filters.network" :options="networkOptions" mono aria-label="Filter by network" />
        </div>
        <div class="ar-log__field">
          <span class="ar-log__label">Verification</span>
          <SelectMenu v-model="filters.verdict" :options="verdictOptions" aria-label="Filter by verification" />
        </div>
        <div class="ar-log__field ar-log__field--ua">
          <span class="ar-log__label">User-Agent starts with</span>
          <input v-model.trim="filters.ua" class="ar-input" type="text" placeholder="Mozilla/5.0" @keyup.enter="apply" />
        </div>
        <div class="ar-log__field ar-log__field--date">
          <span class="ar-log__label">From</span>
          <input v-model="filters.from" class="ar-input" type="date" aria-label="From date" />
        </div>
        <div class="ar-log__field ar-log__field--date">
          <span class="ar-log__label">To</span>
          <input v-model="filters.to" class="ar-input" type="date" aria-label="To date" />
        </div>
      </div>

      <div class="ar-log__actions">
        <button
          v-if="hasFilters"
          type="button"
          class="ar-btn ar-btn--ghost"
          :disabled="loading"
          @click="reset"
        >Clear</button>
        <button type="button" class="ar-btn" :disabled="loading" @click="apply">Filter</button>
      </div>
    </div>

    <p v-if="error" class="ar-log__error" role="alert">{{ error }}</p>

    <div v-else-if="loading && !rows.length" class="ar-log__empty">Loading…</div>

    <div v-else-if="!rows.length" class="ar-log__empty">
      <template v-if="hasFilters">Nothing matched those filters.</template>
      <template v-else>No requests recorded yet.</template>
    </div>

    <div v-else class="ar-act-feedwrap">
      <div class="ar-act-reqs">
        <table class="ar-act-table">
          <thead>
            <tr>
              <th scope="col">Client</th>
              <th scope="col">Endpoint</th>
              <th v-if="hasNetwork" scope="col">Network</th>
              <th scope="col">User-Agent</th>
              <th scope="col">Seen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="i" :class="{ 'is-spoofed': r.verdict === 2 }">
              <td class="ar-act-table__agent">
                <button
                  type="button"
                  class="ar-log__pivot"
                  :aria-label="`Show only ${r.agent}`"
                  @mouseenter="showUaTip($event, 'Show only this client', '')"
                  @mouseleave="hideUaTip"
                  @click="pivot('agent', r.agent)"
                >{{ r.agent }}</button>
                <span v-if="verdictLabel(r.verdict)" class="ar-log__verdict" :class="`is-${verdictLabel(r.verdict)}`">
                  {{ verdictLabel(r.verdict) }}
                </span>
              </td>
              <td>
                <button
                  type="button"
                  class="ar-log__pivot"
                  :aria-label="`Show only ${r.endpoint}`"
                  @mouseenter="showUaTip($event, 'Show only this endpoint', '')"
                  @mouseleave="hideUaTip"
                  @click="pivot('endpoint', r.endpoint)"
                ><code class="ar-act-feed__ep">{{ r.endpoint }}</code></button>
              </td>
              <td v-if="hasNetwork">
                <span v-if="r.network" class="ar-act-feed__net">{{ r.network }}</span>
                <span v-else class="ar-act-table__dash" aria-label="not identified">—</span>
              </td>
              <td>
                <!-- Truncated in the cell, so the bubble carries the whole string and a
                     click copies it — the same contract as the dashboard's activity feed. -->
                <code
                  v-if="r.ua"
                  class="ar-act-feed__ua is-copyable"
                  :aria-label="r.ua"
                  @mouseenter="showUaTip($event, r.ua)"
                  @mouseleave="hideUaTip"
                  @click.stop="copyUa(r.ua)"
                >{{ r.ua }}</code>
                <span v-else class="ar-act-table__dash">—</span>
              </td>
              <td class="ar-log__seen">{{ ago(r.at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="rows.length" class="ar-log__foot">
      <p class="ar-log__count">
        Showing <strong>{{ pageFrom }}–{{ pageTo }}</strong> of {{ total }}
      </p>
      <div class="ar-log__pager">
        <button type="button" class="ar-btn ar-btn--ghost" :disabled="!trail.length || loading" @click="newer">
          ← Newer
        </button>
        <button type="button" class="ar-btn ar-btn--ghost" :disabled="!hasMore || loading" @click="older">
          Older →
        </button>
      </div>
    </div>

    <!-- The rule sits on the <p>, which spans the card; the prose inside is measured for
         readability. Putting max-width on the <p> itself cropped the rule short of the card. -->
    <p v-if="loaded" class="ar-log__note">
      <span>
        The log keeps the last {{ retentionDays }} days — older requests are deleted, not hidden. On a
        very busy site the oldest rows inside that window may also have been trimmed, so read a full
        page as a floor, not a total.
      </span>
    </p>

    <!-- Rendered at body level, like the activity feed's: anchored inside the card it would
         be clipped by the table's scroll box. -->
    <Teleport to="body">
      <transition name="ar-tip">
        <div
          v-if="uaTip.show"
          ref="uaTipEl"
          class="ar-act-uatip"
          :class="{ 'is-below': uaTip.below }"
          :style="{ left: uaTip.x + 'px', top: uaTip.y + 'px' }"
          role="tooltip"
          aria-hidden="true"
        ><span class="ar-act-uatip__ua">{{ uaTip.text }}</span><span v-if="uaTip.hint" class="ar-act-uatip__hint">{{ uaTip.hint }}</span><span class="ar-act-uatip__caret" :style="{ left: uaTip.caret + 'px' }"></span></div>
      </transition>
    </Teleport>
  </section>
</template>
