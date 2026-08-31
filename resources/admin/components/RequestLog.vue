<script>
/**
 * Request log — the per-request view the dashboard's rollups can't give you.
 *
 * The Dashboard answers "who visited, and how much". This answers "what did THIS client
 * actually fetch", by letting the two breakdowns intersect: filter by agent AND endpoint
 * and you get the cross-tab the summary cards structurally cannot show.
 *
 * Paging is numbered (PagerBar): the server counts the whole filtered set, so the strip
 * can say "page 3 of 41" and jump anywhere. Numbered pages drift when a crawler inserts
 * rows mid-walk — the accepted price of every numbered admin list, and the owner's call
 * (2026-08-31) over the cursor walk this screen started with.
 *
 * The footer always states what the view CANNOT show: nothing older than the retention
 * window exists, and a busy site's row cap may have discarded rows inside it. A log that
 * quietly stops short reads as "that's everything".
 */
import SelectMenu from './SelectMenu.vue';
import CardSkeleton from './CardSkeleton.vue';
import PagerBar from './PagerBar.vue';
import { uaTip } from '../js/uaTip.js';
import { surfaceName, isRenamed } from '../js/surfaceNames.js';
import { formatStamp, relTimeShort } from '../js/wpDate.js';

// ⛔ ONE DEFINITION OF "NO FILTERS", because three copies of this literal used to
// drift: a filter added to the initial state but missed in Clear all is one the
// owner can set and cannot unset. A fresh object every call — the lists are
// mutable and must never be shared between the state and a reset.
const noFilters = () => ({
  from: '', to: '', agent: [], endpoint: [], network: [], ua: '', verdict: [], signer: [],
});
// The filters whose control is multi-select, so a single value arriving from a
// dashboard drill-down gets wrapped into the list shape the picker expects.
const LIST_FILTERS = ['agent', 'endpoint', 'network', 'verdict', 'signer'];

export default {
  name: 'RequestLog',
  components: { SelectMenu, CardSkeleton, PagerBar },
  mixins: [uaTip],
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show, so it stays mounted across tab switches. Fetch on first reveal.
    active: { type: Boolean, default: false },
    // A drill-down from a dashboard row: filter keys to apply on arrival
    // (plus a seq stamp so repeat clicks on the same row still re-apply).
    preset: { type: Object, default: null },
  },
  emits: ['flash'],
  data() {
    return {
      // ⭐ The five value filters are LISTS — his call, 2026-08-21. `from`/`to`/`ua`
      // stay single: a date range already IS a range, and `ua` is a prefix match.
      filters: noFilters(),
      // Distinct values seen in the retained window; fetched once, alongside the first page.
      facets: { agents: [], endpoints: [], networks: [], signers: [] },
      rows: [],
      total: 0,
      retentionDays: 30,
      verifyOn: false,
      identifyOn: false,
      // Whether the page-view stream is on — the footer says which site it is
      // describing, so an empty log is never read as "nothing came".
      pageViewsOn: false,
      autoPrune: true,
      maxRows: 50000,
      // 25, not 50: the edge cards live below this log now, and a 50-row page
      // pushed them a full screen further away than they need to be.
      perPage: 25,
      // The 1-based page the strip is on. The server takes it as an offset;
      // total ÷ perPage tells the pager how many there are.
      page: 1,
      // ⛔ Every load takes a ticket; only the newest one may write. Two loads
      // can be in flight at once — a drill-down starts a filtered read while the
      // reveal's unfiltered one is still out — and without this the SLOWER
      // response wins whichever it is, which is how a filtered screen ends up
      // showing an unfiltered count.
      reqSeq: 0,
      sort: { by: 'at', dir: 'desc' },
      loading: false,
      loaded: false,
      error: '',
    };
  },
  computed: {
    hasFilters() {
      // An empty LIST is no filter, exactly as an empty string is.
      return Object.values(this.filters).some((v) => (Array.isArray(v) ? v.length > 0 : v !== ''));
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
    // ⛔ THE PICKER SAYS WHAT THE TABLE SAYS. The facets arrive as raw keys, and
    // spreading them straight in put `markdown` and `rest:discovery` in the
    // dropdown while the column beside it read "Page markdown (.md)" and
    // "Discovery (REST API)" — two vocabularies for one thing, on one screen.
    // Adding `page` made it plainly wrong rather than merely untidy: "page" reads
    // like a file. The VALUE stays the raw key, because that is what the server
    // matches on; only the label is translated.
    endpointOptions() {
      return [
        { value: '', label: 'Any' },
        ...this.facets.endpoints.map((v) => ({ value: v, label: surfaceName(v) })),
      ];
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
        { value: 'refused', label: 'Refused (not served)' },
      ];
    },
    // ⭐ "Signed by anyone" leads the list and is not a facet. Almost nothing signs
    // its requests yet, so the signer facet is usually empty — and an owner whose
    // question is "has ANY assistant signed to me?" cannot ask it by picking a name
    // they have never seen. `*` asks it. An empty result is then a real answer, and
    // the control stays on screen to give it.
    signerOptions() {
      return [
        { value: '', label: 'Any' },
        { value: '*', label: 'Signed by anyone' },
        ...this.facets.signers,
      ];
    },
    // Why a row shows a dash. Two honest reasons, and the wording must not imply
    // the client passed anything: most visitors simply have no identity to check.
    nomarkTip() {
      return this.verifyOn
        ? 'No identity check — this client claims no crawler that can be verified (browsers and scripts never do). It was served normally.'
        : 'No identity check — “Verify bot identities” is off, so no client’s claim was checked. Turn it on in Settings → AI Access.';
    },
    // Why a row has no network. Two honest cases again: the lookup wasn't run at
    // all, or it ran and couldn't attribute this address to an organisation.
    networkTip() {
      return this.identifyOn
        ? 'No network — the lookup couldn’t attribute this visitor to an organisation. Common for home broadband, small hosts and anything behind a privacy proxy.'
        : 'No network — “Identify every bot” is off, so visitors aren’t looked up. Turn it on in Settings → AI Access to see which organisation each one belongs to.';
    },
    pageStart() {
      return (this.page - 1) * this.perPage;
    },
    pages() {
      return Math.max(1, Math.ceil(this.total / this.perPage));
    },
    pageFrom() {
      return this.rows.length ? this.pageStart + 1 : 0;
    },
    pageTo() {
      return this.pageStart + this.rows.length;
    },
    // The columns, in the order they are drawn. `key` is what the server sorts by.
    columns() {
      // ⭐ `w` is the DECLARED width, and it is why the header stops moving.
      // With table-layout:fixed these are the whole story — the browser never
      // measures the cells — so a sort that brings longer values onto the page
      // cannot re-proportion the table under the pointer. ⛔ User-Agent has no
      // width on purpose: it takes whatever is left, which is the one column
      // that should absorb the slack rather than fight for it.
      return [
        { key: 'client', label: 'Client', w: '18%' },
        { key: 'status', label: 'Status', cls: 'ar-log__statuscol', w: '96px' },
        { key: 'endpoint', label: 'Address', w: '20%' },
        ...(this.hasNetwork ? [{ key: 'network', label: 'Network', w: '14%' }] : []),
        { key: 'ua', label: 'User-Agent' },
        { key: 'at', label: 'Requested at', w: '17%' },
      ];
    },
  },
  watch: {
    // The freshness contract: every reveal re-reads the CURRENT page — same
    // filters, same page, exactly what the refresh button beside the title
    // does — so a return between polls never shows a log frozen at the last
    // visit. Deferred a tick so a dashboard drill-down's preset fetch (its
    // watcher fires first and starts a page-one load) is never raced by a
    // stale read: if a load is already in flight, this one stands down.
    active(on) {
      if (!on) return;
      if (!this.loaded) {
        this.load();
        return;
      }
      this.$nextTick(() => {
        if (!this.loading) this.load(this.page);
      });
    },
    // A dashboard row's drill-down: start from clean filters, apply the preset
    // keys, and refetch if the log has already loaded once (first reveal picks
    // the filters up by itself).
    // ⛔ ALWAYS re-reads, never "only if we have loaded once". The active
    // watcher above is declared first and therefore fires first, so on a FIRST
    // visit it had already started an UNFILTERED load by the time these filters
    // arrived — and the old `if (this.loaded)` guard then declined to correct
    // it. His catch, 2026-08-21: "4 caught faking an identity" landed on all 165
    // rows. The ticket in load() makes the superseded read harmless.
    preset(p) {
      if (!p) return;
      const { seq, ...keys } = p;
      // ⚠️ A preset is written by whoever links here (a dashboard row sends
      // `{ verdict: '2' }`), so it arrives in the SINGLE shape. Normalise it —
      // otherwise the picker gets a string where it expects a list and shows
      // nothing as ticked while the query is filtered.
      const listed = {};
      Object.keys(keys).forEach((k) => {
        listed[k] = LIST_FILTERS.includes(k) && !Array.isArray(keys[k])
          ? (keys[k] === '' || keys[k] === null || keys[k] === undefined ? [] : [keys[k]])
          : keys[k];
      });
      this.filters = { ...noFilters(), ...listed };
      this.apply();
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    surfaceName,
    isRenamed,
    async loadFacets() {
      if (!this.api) return;
      try {
        const f = await this.api.getActivityLogFacets();
        this.facets = {
          agents: f.agents || [],
          endpoints: f.endpoints || [],
          networks: f.networks || [],
          signers: f.signers || [],
        };
      } catch (e) {
        // Non-fatal: the log still lists and pages. The dropdowns just have nothing to
        // offer, so don't surface an error over a working table.
        this.facets = { agents: [], endpoints: [], networks: [], signers: [] };
      }
    },
    async load(page = 1) {
      if (!this.api) return;
      if (!this.loaded) this.loadFacets();
      this.loading = true;
      this.error = '';
      const seq = ++this.reqSeq;
      try {
        const res = await this.api.getActivityLog({
          ...this.filters,
          offset: (page - 1) * this.perPage,
          per_page: this.perPage,
          orderby: this.sort.by,
          order: this.sort.dir,
        });
        // A superseded read must not repaint the screen it lost.
        if (seq !== this.reqSeq) return;
        this.rows = res.rows || [];
        this.total = res.total || 0;
        this.retentionDays = res.retentionDays || 30;
        this.verifyOn = !!res.verifyOn;
        this.identifyOn = !!res.identifyOn;
        this.pageViewsOn = !!res.pageViewsOn;
        this.autoPrune = res.autoPrune !== false;
        this.maxRows = res.maxRows || 50000;
        this.page = page;
        this.loaded = true;
      } catch (e) {
        if (seq !== this.reqSeq) return;
        // A 400 from a malformed date arrives here with the server's own message; show it
        // rather than a generic failure, because it tells the owner exactly what to fix.
        this.error = (e && e.message) || 'Unable to load the request log.';
        this.rows = [];
        this.total = 0;
      } finally {
        if (seq === this.reqSeq) this.loading = false;
      }
    },
    apply() {
      this.load(1);
    },
    // ⭐ Clicking a header sorts the WHOLE filtered set, not the page on screen —
    // sorting 50 visible rows while calling it "sorted" is the lie this avoids.
    // Same column toggles direction; a new one starts at its natural end: newest
    // first for a time, A→Z for a name.
    sortBy(key) {
      if (!key) return;
      this.sort = this.sort.by === key
        ? { by: key, dir: 'asc' === this.sort.dir ? 'desc' : 'asc' }
        : { by: key, dir: 'at' === key ? 'desc' : 'asc' };
      this.load(1);
    },
    sortState(key) {
      if (!key || this.sort.by !== key) return 'none';
      return 'asc' === this.sort.dir ? 'ascending' : 'descending';
    },
    reset() {
      this.filters = noFilters();
      this.apply();
    },
    goPage(n) {
      this.load(n);
    },
    // Click a cell to pivot the whole log onto that value — the fastest path from
    // "this row looks odd" to "show me everything this client did".
    pivot(key, value) {
      if (!value) return;
      // Clicking a cell REPLACES that control's selection rather than adding to
      // it: "show me everything this client did" is a fresh question, not a
      // widening of the one already on screen.
      this.filters[key] = Array.isArray(this.filters[key]) ? [value] : value;
      this.apply();
    },
    verdictLabel(v) {
      if (v === 1) return 'verified';
      if (v === 2) return 'spoofed';
      return '';
    },
    // ONE mark per row — the row's outcome, not a pile of labels. A refusal
    // outranks everything: whatever the identity story was, "it got nothing" is
    // the fact that matters, and the hover carries the why. Ordinary rows show a
    // dash rather than an empty cell, the same way the Network column already
    // reads when there's nothing to say.
    statusMark(r) {
      if (r.refused) {
        const why = r.signer
          ? `Its signature claimed ${r.signer} and failed the maths.`
          : 'It claimed a crawler its operator’s own check disproved.';
        return {
          text: 'refused',
          cls: 'is-refused',
          tip: `Turned away — refused before anything was served, so it counts toward none of your read totals. ${why}`,
        };
      }
      if (r.verdict === 2) {
        return {
          text: r.signer ? 'forged' : 'spoofed',
          cls: 'is-spoofed' + (r.signer ? ' is-signed' : ''),
          tip: r.signer
            ? `Signature failed — it claimed ${r.signer} but the maths didn’t check out.`
            : 'Failed the identity check — an impostor.',
        };
      }
      if (r.verdict === 1) {
        return {
          text: r.signer ? 'signed' : 'verified',
          cls: 'is-verified' + (r.signer ? ' is-signed' : ''),
          tip: r.signer
            ? `Cryptographically verified — signed by ${r.signer}.`
            : 'Verified — the address really belongs to this operator.',
        };
      }
      // A signature that verified, from an operator this site has no opinion
      // about. The maths is as sound as any row above; what's missing is anyone
      // to vouch for the name behind it — so it says so, and does NOT borrow the
      // verified mark. Calling this "unchecked" would be false: it was checked.
      if (r.signer) {
        return {
          text: 'signed',
          cls: 'is-none is-signed',
          tip: `Signed by ${r.signer}, and the signature checks out — but that's an operator this site doesn't recognise, so it earns no extra trust. Identity, not reputation.`,
        };
      }
      // No verdict is still an ANSWER, so it gets a word like every other row —
      // a dash makes the reader guess, and most rows land here.
      return { text: 'unchecked', cls: 'is-none', tip: this.nomarkTip };
    },
    // The exact moment, in the site's own date/time format. formatStamp takes a
    // DATE, not an ISO string — handing it the string rendered an empty cell.
    stamp(iso) {
      const d = new Date(iso);
      return Number.isNaN(d.getTime()) ? '' : formatStamp(d);
    },
    ago(iso) {
      return relTimeShort(Date.parse(iso));
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
        <!-- Refreshes THIS page of results, keeping the filters and the page — the same
             "update this card" affordance the readiness report uses beside its own title.
             (The cursor era passed `before` here, a name this template never had, so
             refresh silently reset to the newest page; `page` is real and stays put.) -->
        <button
          type="button"
          class="ar-log__refresh"
          :class="{ 'is-busy': loading }"
          :disabled="loading"
          :aria-label="loading ? 'Reloading the requests…' : 'Reload these requests'"
          @mouseenter="showUaTip($event, loading ? 'Reloading…' : 'Reload these requests', '')"
          @mouseleave="hideUaTip"
          @click="load(page)"
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
          <SelectMenu v-model="filters.agent" :options="agentOptions" multiple aria-label="Filter by client" />
        </div>
        <div class="ar-log__field">
          <span class="ar-log__label">Address</span>
          <SelectMenu v-model="filters.endpoint" :options="endpointOptions" multiple mono aria-label="Filter by endpoint" />
        </div>
        <div v-if="hasNetwork" class="ar-log__field">
          <span class="ar-log__label">Network</span>
          <SelectMenu v-model="filters.network" :options="networkOptions" multiple mono aria-label="Filter by network" />
        </div>
        <div class="ar-log__field">
          <span class="ar-log__label">Verification</span>
          <SelectMenu v-model="filters.verdict" :options="verdictOptions" multiple aria-label="Filter by verification" />
        </div>
        <!-- Unlike Network, this one shows even with an empty facet list: "has anything
             signed to me yet?" is a question the owner is entitled to ask and get no
             for, and a hidden control cannot answer it. -->
        <div class="ar-log__field">
          <span class="ar-log__label">Signature</span>
          <SelectMenu v-model="filters.signer" :options="signerOptions" multiple aria-label="Filter by signature" />
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

    <p v-if="error" class="ar-log__error" role="alert">{{ error }} — try Refresh, and if it persists, reload the page.</p>

    <!-- First load in flight: the shared skeleton, not a bare "Loading…" — same
         treatment as Endpoint Activity and Agent Access. -->
    <CardSkeleton v-else-if="loading && !rows.length" lead="Loading the request log…" />

    <div v-else-if="!rows.length" class="ar-log__empty">
      <template v-if="hasFilters">Nothing matched those filters.</template>
      <template v-else>No requests recorded yet — the first time a crawler or an AI assistant fetches one of your AI files, its visit appears here.</template>
    </div>

    <div v-else class="ar-act-feedwrap">
      <div class="ar-act-reqs">
        <table class="ar-act-table ar-act-table--cards ar-log__table">
          <!-- One declaration of the geometry, shared by every row and every
               sort. Without it each page re-measured its own content and the
               header shifted every time the order changed. -->
          <colgroup>
            <col v-for="c in columns" :key="'col-' + c.label" :style="c.w ? { width: c.w } : null" />
          </colgroup>
          <thead>
            <tr>
              <!-- ⭐ Every column sorts, so every header is a button — except
                   Network, which is only present on some sites and carries no
                   server-side order of its own. aria-sort tells a screen reader
                   which one is active and which way. -->
              <th
                v-for="c in columns"
                :key="c.label"
                scope="col"
                :class="[c.cls, { 'is-sortable': c.key, 'is-sorted': c.key && sort.by === c.key }]"
                :aria-sort="sortState(c.key)"
              >
                <button v-if="c.key" type="button" class="ar-log__sort" @click="sortBy(c.key)">
                  {{ c.label }}
                  <!-- ⭐ BOTH ARROWS, ALWAYS — his reference, 2026-08-21. A mark
                       that appears only on the sorted column tells you where you
                       ARE but never that the others can be sorted at all; a
                       stacked pair says "this is a control" at rest, and lights
                       the half that is active. -->
                  <span class="ar-log__sortmark" aria-hidden="true">
                    <svg width="9" height="12" viewBox="0 0 8 12">
                      <path d="M4 0.6l3.2 4h-6.4z" :class="{ 'is-on': sort.by === c.key && 'asc' === sort.dir }" />
                      <path d="M4 11.4l-3.2-4h6.4z" :class="{ 'is-on': sort.by === c.key && 'desc' === sort.dir }" />
                    </svg>
                  </span>
                </button>
                <template v-else>{{ c.label }}</template>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="i" :class="{ 'is-spoofed': r.verdict === 2, 'is-refused': r.refused }">
              <td class="ar-act-table__agent" data-label="Client">
                <button
                  type="button"
                  class="ar-log__pivot"
                  :aria-label="`Show only ${r.agent}`"
                  @mouseenter="showUaTip($event, 'Show only this client', '')"
                  @mouseleave="hideUaTip"
                  @click="pivot('agent', r.agent)"
                >{{ r.agent }}</button>
              </td>
              <td class="ar-log__statuscol" data-label="Status">
                <svg class="ar-cardico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.6-7 9-4-1.4-7-4.5-7-9V6z" /></svg>
                <span
                  class="ar-log__mark"
                  :class="statusMark(r).cls"
                  @mouseenter="showUaTip($event, statusMark(r).tip, '')"
                  @mouseleave="hideUaTip"
                >{{ statusMark(r).text }}</span>
              </td>
              <td data-label="Address">
                <svg class="ar-cardico" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="1.5" /><rect x="3" y="13" width="18" height="7" rx="1.5" /><line x1="7" y1="7.5" x2="7" y2="7.5" /><line x1="7" y1="16.5" x2="7" y2="16.5" /></svg>
                <button
                  type="button"
                  class="ar-log__pivot"
                  :aria-label="`Show only ${surfaceName(r.endpoint)}`"
                  @mouseenter="showUaTip($event, 'Show only this address', '')"
                  @mouseleave="hideUaTip"
                  @click="pivot('endpoint', r.endpoint)"
                ><code class="ar-act-feed__ep" :title="isRenamed(r.endpoint) ? r.endpoint : null">{{ surfaceName(r.endpoint) }}</code></button>
              </td>
              <td v-if="hasNetwork" data-label="Network">
                <svg class="ar-cardico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5" /><path d="M3.5 12h17" /><path d="M12 3.5c2.6 2.5 2.6 14.5 0 17-2.6-2.5-2.6-14.5 0-17z" /></svg>
                <span v-if="r.network" class="ar-act-feed__net">{{ r.network }}</span>
                <!-- A word, not a dash: the reader should never have to interpret
                     punctuation to learn we simply don't know. -->
                <span
                  v-else
                  class="ar-log__mark is-none"
                  @mouseenter="showUaTip($event, networkTip, '')"
                  @mouseleave="hideUaTip"
                >unknown</span>
              </td>
              <td data-label="User-Agent">
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
              <!-- The column says "at", so it shows the actual moment, in the site's
                   own Settings → General formats. "How recent?" moves to the hover. -->
              <td
                class="ar-log__seen"
                data-label="Requested at"
                @mouseenter="showUaTip($event, ago(r.at), '')"
                @mouseleave="hideUaTip"
              ><svg class="ar-cardico" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" /></svg>{{ stamp(r.at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="rows.length" class="ar-log__foot">
      <p class="ar-log__count">
        Showing <strong>{{ pageFrom }}–{{ pageTo }}</strong> of {{ total }}
      </p>
      <PagerBar v-if="pages > 1" :page="page" :pages="pages" :busy="loading" label="Request log pages" @go="goPage" />
    </div>

    <!-- The rule sits on the <p>, which spans the card; the prose inside is measured for
         readability. Putting max-width on the <p> itself cropped the rule short of the card. -->
    <p v-if="loaded" class="ar-log__note">
      <!-- "older requests are deleted" is only true when auto-delete is on. With it off,
           nothing expires by age and the row cap is the only thing that removes anything. -->
      <span v-if="autoPrune">
        The log keeps the last {{ retentionDays }} days — older requests are deleted, not hidden. On a
        very busy site the oldest rows inside that window may also have been trimmed to stay under the
        {{ maxRows.toLocaleString() }}-row cap, so read a full page as a floor, not a total.
      </span>
      <span v-else>
        Old requests are never deleted by age. The log keeps growing until it reaches
        {{ maxRows.toLocaleString() }} rows, after which the oldest are removed to make room — so read a
        full page as a floor, not a total.
      </span>
      <!-- ⭐ Each state names its own limit. "No crawler read this article" is a
           conclusion, and it may be drawn from a question that was never asked
           (the stream is off) or from one your cache answered before WordPress
           heard it. Neither is the same as nobody coming. -->
      <span v-if="pageViewsOn">
        Visits to your pages are recorded too, but only the ones your server actually handles: if a
        page is served from a cache — a CDN, or a caching plugin — WordPress never runs, so that visit
        cannot be counted here.
      </span>
      <span v-else>
        Visits to your pages are <strong>not</strong> being recorded — only the AI files above. Crawlers
        may well be reading your articles; this log isn’t looking. Turn on “Record crawler visits to your
        pages” in Settings → Visit Log.
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
