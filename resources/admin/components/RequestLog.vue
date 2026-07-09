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
export default {
  name: 'RequestLog',
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show, so it stays mounted across tab switches. Fetch on first reveal.
    active: { type: Boolean, default: false },
  },
  emits: ['flash'],
  data() {
    return {
      filters: { from: '', to: '', agent: '', endpoint: '', network: '', ua: '', verdict: '' },
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
    // Only meaningful once "identify every bot" has attributed something.
    hasNetwork() {
      return this.rows.some((r) => r.network);
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
    async load(before = 0) {
      if (!this.api) return;
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
      <h2 class="ar-card__title">Requests</h2>
      <button type="button" class="ar-btn ar-btn--ghost" :disabled="loading" @click="load(before)">
        Refresh
      </button>
    </div>

    <div class="ar-log__filters">
      <label class="ar-log__field">
        <span>Client</span>
        <input v-model.trim="filters.agent" type="text" placeholder="GPTBot" @keyup.enter="apply" />
      </label>
      <label class="ar-log__field">
        <span>Endpoint</span>
        <input v-model.trim="filters.endpoint" type="text" placeholder="discovery.json" @keyup.enter="apply" />
      </label>
      <label class="ar-log__field">
        <span>User-Agent starts with</span>
        <input v-model.trim="filters.ua" type="text" placeholder="Mozilla/5.0" @keyup.enter="apply" />
      </label>
      <label v-if="hasNetwork" class="ar-log__field">
        <span>Network</span>
        <input v-model.trim="filters.network" type="text" placeholder="amazonaws.com" @keyup.enter="apply" />
      </label>
      <label class="ar-log__field">
        <span>Verification</span>
        <select v-model="filters.verdict">
          <option value="">Any</option>
          <option value="1">Verified</option>
          <option value="2">Spoofed</option>
          <option value="0">Unchecked</option>
        </select>
      </label>
      <label class="ar-log__field">
        <span>From</span>
        <input v-model="filters.from" type="date" />
      </label>
      <label class="ar-log__field">
        <span>To</span>
        <input v-model="filters.to" type="date" />
      </label>

      <div class="ar-log__actions">
        <button type="button" class="ar-btn" :disabled="loading" @click="apply">Filter</button>
        <button
          v-if="hasFilters"
          type="button"
          class="ar-btn ar-btn--ghost"
          :disabled="loading"
          @click="reset"
        >Clear</button>
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
                <button type="button" class="ar-log__pivot" title="Show only this client" @click="pivot('agent', r.agent)">
                  {{ r.agent }}
                </button>
                <span v-if="verdictLabel(r.verdict)" class="ar-log__verdict" :class="`is-${verdictLabel(r.verdict)}`">
                  {{ verdictLabel(r.verdict) }}
                </span>
              </td>
              <td>
                <button type="button" class="ar-log__pivot" title="Show only this endpoint" @click="pivot('endpoint', r.endpoint)">
                  <code class="ar-act-feed__ep">{{ r.endpoint }}</code>
                </button>
              </td>
              <td v-if="hasNetwork">
                <span v-if="r.network" class="ar-act-feed__net">{{ r.network }}</span>
                <span v-else class="ar-act-table__dash" aria-label="not identified">—</span>
              </td>
              <td>
                <code v-if="r.ua" class="ar-act-feed__ua" :title="r.ua">{{ r.ua }}</code>
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

    <p v-if="loaded" class="ar-log__note">
      The log keeps the last {{ retentionDays }} days — older requests are deleted, not hidden. On a
      very busy site the oldest rows inside that window may also have been trimmed, so read a full
      page as a floor, not a total.
    </p>
  </section>
</template>
