<script>
/**
 * AI traffic — the in-depth report on readers an AI assistant sent you.
 *
 * The dashboard's "Traffic from AI" card is a summary: three numbers, two leaderboards, a
 * sparkline. Everything whose height grows with the window lives HERE — the per-day
 * drill-down and the unrecognised-referrer diagnostic — along with the filters.
 *
 * It fetches its own report rather than reading the dashboard's payload, for two reasons.
 * The dashboard is live-polled and capped at `report_days()` (30), while the table keeps
 * `retention_days()` — so on a site keeping 90 days, 60 of them were stored and reachable
 * from nowhere. And the summary sums across every assistant: "which pages does Perplexity
 * send readers to" is a source × page cross-tab it structurally cannot answer.
 *
 * THREE filters, no more. The store has five columns — day, source, path, hits, id. A
 * "network" or "user-agent" filter would imply a per-visit record this table doesn't keep.
 */
import SelectMenu from './SelectMenu.vue';
import { uaTip } from '../uaTip.js';

export default {
  name: 'AiTrafficPanel',
  mixins: [uaTip],
  components: { SelectMenu },
  props: {
    api: { type: Object, default: null },
    // Load when the screen is first opened, not on every dashboard visit.
    active: { type: Boolean, default: false },
    // The "Find missed AI sources" setting (log_unknown_referrers). It lives on the Settings
    // screen, so it flips while THIS panel is hidden — the report then carries a stale
    // `unknown` block (its enabled state and the diagnostic lists). Watched so the report
    // reloads on the next open, instead of only after a full page refresh.
    logUnknown: { type: Boolean, default: false },
  },
  // `flash` is required by the uaTip mixin: copying a path reports its result there.
  emits: ['navigate', 'flash'],
  data() {
    return {
      report: null,
      sources: [],
      loading: false,
      error: '',
      // What the user is editing. Applied to the server only on Filter / Enter / Clear —
      // typing a path prefix should not fire a query per keystroke.
      filters: { from: '', to: '', source: '', path: '' },
      applied: { from: '', to: '', source: '', path: '' },
      refOpenDays: [],
      // date -> { loading, error, rows, rowCount, full, capped }. Fetched the first time a
      // day is opened and dropped whenever the report reloads under it.
      dayCache: {},
      // Set when a setting the report depends on changed while we were hidden; consumed on
      // the next activation to force a reload of an otherwise-cached report.
      stale: false,
    };
  },
  computed: {
    range() {
      return (this.report && this.report.range) || { from: '', to: '', floor: '' };
    },
    total() {
      return (this.report && this.report.total) || 0;
    },
    sourceCount() {
      return (this.report && this.report.sourceCount) || 0;
    },
    activeDays() {
      return (this.report && this.report.activeDays) || 0;
    },
    bySource() {
      return (this.report && this.report.bySource) || [];
    },
    topPages() {
      return (this.report && this.report.topPages) || [];
    },
    daily() {
      return (this.report && this.report.daily) || [];
    },
    dailyMax() {
      return Math.max(1, ...this.daily.map((d) => d.hits));
    },
    beacon() {
      return !!(this.report && this.report.beacon);
    },
    unknownOn() {
      return !!(this.report && this.report.unknown && this.report.unknown.enabled);
    },
    unknownHosts() {
      return (this.report && this.report.unknown && this.report.unknown.hosts) || [];
    },
    unknownUtm() {
      return (this.report && this.report.unknown && this.report.unknown.utm) || [];
    },
    sourceOptions() {
      return [{ value: '', label: 'Any assistant' }, ...this.sources];
    },
    // The date inputs are pre-filled from the server's range, so they ride along on every
    // Filter press. `isDefault` is the server's own answer to "is this still the default
    // window?" — without it we'd offer to Clear a filter nobody set.
    hasFilters() {
      return !!this.applied.source || !!this.applied.path || (!!this.report && !this.range.isDefault);
    },
    // Says what the cards actually cover, since the range is no longer always "last 30 days".
    rangeLabel() {
      if (!this.range.from) return '';
      const days = Math.round((Date.parse(`${this.range.to}T00:00:00Z`) - Date.parse(`${this.range.from}T00:00:00Z`)) / 86400000) + 1;
      if (this.range.isDefault) return `Last ${days} days`;
      return `${this.dateLabel(this.range.from)} – ${this.dateLabel(this.range.to)}`;
    },
    // What narrows the report right now, in the words the user chose.
    filterSummary() {
      const bits = [];
      if (this.applied.source) bits.push(this.applied.source);
      if (this.applied.path) bits.push(`pages under ${this.applied.path}`);
      return bits.join(' · ');
    },
  },
  watch: {
    active(on) {
      if (on && (!this.report || this.stale)) this.load();
    },
    logUnknown() {
      // Never loaded yet → the first open fetches fresh anyway. Otherwise the loaded
      // report's `unknown` block is now stale: reload right away if we're showing, else
      // defer to the next open (the toggle lives on Settings, so this is the usual path).
      if (!this.report) return;
      this.stale = true;
      if (this.active) this.load();
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    async load() {
      if (!this.api) return;
      this.loading = true;
      this.error = '';
      this.stale = false;
      // A reloaded report may cover a different range or filter; any day rows cached under
      // it are answers to a question nobody is asking any more.
      this.dayCache = {};
      try {
        const params = {};
        Object.entries(this.applied).forEach(([k, v]) => { if (v) params[k] = v; });
        const [report, facets] = await Promise.all([
          this.api.getAiTraffic(params),
          this.sources.length ? Promise.resolve({ sources: this.sources }) : this.api.getAiTrafficFacets(),
        ]);
        this.report = report;
        this.sources = facets.sources || [];
        // Reflect the range the SERVER settled on — it clamps to retention, never the
        // future, never inverted — so the date inputs can't disagree with the cards.
        this.filters.from = report.range.from;
        this.filters.to = report.range.to;
        this.refOpenDays = this.refOpenDays.filter((d) => this.daily.some((x) => x.date === d));
      } catch (e) {
        this.error = e.message || 'Could not load the report.';
      } finally {
        this.loading = false;
      }
    },
    apply() {
      this.applied = { ...this.filters };
      this.load();
    },
    reset() {
      this.filters = { from: '', to: '', source: '', path: '' };
      this.applied = { from: '', to: '', source: '', path: '' };
      this.load();
    },

    /* ---- The per-day drill-down ---------------------------------------------- */

    toggleRefDay(date) {
      const i = this.refOpenDays.indexOf(date);
      if (i === -1) {
        this.refOpenDays.push(date);
        this.loadDay(date);
      } else {
        this.refOpenDays.splice(i, 1);
      }
    },
    refDayOpen(date) {
      return this.refOpenDays.includes(date);
    },
    day(date) {
      return this.dayCache[date] || { loading: false, error: '', rows: [], rowCount: 0, full: false, capped: false };
    },
    /**
     * One day's rows, under the same filters as the report. `full` lifts the DAY_TOP cap —
     * without it the pairings behind "+N more" are counted but reachable from nowhere.
     */
    async loadDay(date, full = false) {
      if (!this.api) return;
      const have = this.dayCache[date];
      if (have && !have.error && (have.full || !full)) return;
      this.dayCache = { ...this.dayCache, [date]: { ...this.day(date), loading: true, error: '' } };
      try {
        const params = {};
        if (this.applied.source) params.source = this.applied.source;
        if (this.applied.path) params.path = this.applied.path;
        if (full) params.full = '1';
        const res = await this.api.getAiTrafficDay(date, params);
        this.dayCache = {
          ...this.dayCache,
          [date]: { loading: false, error: '', rows: res.rows || [], rowCount: res.rowCount || 0, full, capped: !!res.capped },
        };
      } catch (e) {
        this.dayCache = { ...this.dayCache, [date]: { loading: false, error: 'Could not load this day.', rows: [], rowCount: 0, full: false, capped: false } };
      }
    },

    /* ---- Formatting ----------------------------------------------------------- */

    listMax(list) {
      return Math.max(1, ...(list || []).map((x) => x.hits));
    },
    pct(hits, max) {
      return `${Math.max(2, Math.round((hits / max) * 100))}%`;
    },
    // Stored days are UTC calendar days; parse and format as UTC or a westward timezone
    // renders every label one day early.
    dateLabel(date) {
      const dt = new Date(`${date}T00:00:00Z`);
      if (Number.isNaN(dt.getTime())) return date;
      return dt.toLocaleDateString(undefined, {
        timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
      });
    },
  },
};
</script>

<template>
  <div class="ar-aitraffic">
    <section class="ar-card ar-ai">
      <div class="ar-card__titlewrap">
        <h2 class="ar-card__title">
          Traffic from AI
          <span v-if="rangeLabel" class="ar-card__tag">{{ rangeLabel }}</span>
        </h2>
        <button
          type="button"
          class="ar-log__refresh"
          :class="{ 'is-busy': loading }"
          :disabled="loading"
          :aria-label="loading ? 'Reloading AI traffic…' : 'Reload AI traffic'"
          @mouseenter="showUaTip($event, loading ? 'Reloading…' : 'Reload AI traffic', '')"
          @mouseleave="hideUaTip"
          @click="load"
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>
        </button>
      </div>
      <p class="ar-card__lead">
        Real visitors who arrived from an AI assistant (ChatGPT, Perplexity, Gemini…). Counted on your
        own site — no IP, nothing sent anywhere. Some AI visits can’t be detected, so read this as a
        floor: at least this many.
      </p>
      <p v-if="beacon" class="ar-card__lead">
        <strong>CDN mode is on</strong> — these are counted in your visitors’ browsers so the number
        survives a full-page cache. Treat it as indicative, not exact: a visitor whose ad-blocker
        blocks the counting script is missed, and because the count comes from the browser it can
        also be inflated by automated traffic. It’s a useful trend, not an audited figure.
      </p>

      <!-- The assistant is a closed set drawn from the log itself, so it's a dropdown. The
           landing page is free text, because a path is unbounded and the match is a prefix.
           There is deliberately no client / network / user-agent filter: this store keeps a
           daily count per (source, path) and nothing that could stand for a person. -->
      <div class="ar-log__filters">
        <div class="ar-log__row">
          <div class="ar-log__field">
            <span class="ar-log__label">Assistant</span>
            <SelectMenu v-model="filters.source" :options="sourceOptions" aria-label="Filter by assistant" />
          </div>
          <div class="ar-log__field ar-log__field--ua">
            <span class="ar-log__label">Landing page starts with</span>
            <input v-model.trim="filters.path" class="ar-input" type="text" placeholder="/blog" @keyup.enter="apply" />
          </div>
          <div class="ar-log__field ar-log__field--date">
            <span class="ar-log__label">From</span>
            <input v-model="filters.from" class="ar-input" type="date" :min="range.floor" :max="filters.to" aria-label="From date" />
          </div>
          <div class="ar-log__field ar-log__field--date">
            <span class="ar-log__label">To</span>
            <input v-model="filters.to" class="ar-input" type="date" :min="filters.from" :max="range.to" aria-label="To date" />
          </div>
        </div>

        <div class="ar-log__actions">
          <button v-if="hasFilters" type="button" class="ar-btn ar-btn--ghost" :disabled="loading" @click="reset">Clear</button>
          <button type="button" class="ar-btn" :disabled="loading" @click="apply">Filter</button>
        </div>
      </div>

      <p v-if="error" class="ar-log__error" role="alert">{{ error }}</p>

      <div v-else-if="loading && !report" class="ar-log__empty">Loading…</div>

      <template v-else>
        <div class="ar-wd-stats ar-act-stats ar-act-stats--3">
          <div class="ar-wd-stat"><strong>{{ total }}</strong><span>{{ total === 1 ? 'visit' : 'visits' }}</span></div>
          <div class="ar-wd-stat"><strong>{{ sourceCount }}</strong><span>{{ sourceCount === 1 ? 'source' : 'sources' }}</span></div>
          <div class="ar-wd-stat"><strong>{{ activeDays }}</strong><span>days with visits</span></div>
        </div>

        <p v-if="filterSummary" class="ar-ai__filternote">Showing <strong>{{ filterSummary }}</strong> only.</p>

        <p v-if="!total && hasFilters" class="ar-wd-empty">Nothing matched those filters.</p>

        <p v-else-if="!total" class="ar-wd-empty">
          No AI-referred visits recorded yet. When someone arrives from ChatGPT, Perplexity and the like,
          it’ll show here.
        </p>
      </template>
    </section>

    <!-- Composition — its own card, like the dashboard's By-endpoint /
         Top-clients pair: where the visits came from, and where they landed.
         Honors the same filters and range as the overview above. -->
    <section v-if="!error && report && total" class="ar-card ar-ai">
      <h2 class="ar-card__title">Top sources &amp; landing pages <span v-if="rangeLabel" class="ar-card__tag">{{ rangeLabel }}</span></h2>
      <p class="ar-card__lead">
        Where the visits came from, and where they landed — busiest first.
      </p>
      <div class="ar-ai__cols">
        <div class="ar-ai__col">
          <h3 class="ar-ai__sub">Top sources</h3>
          <ul class="ar-act-rank">
            <li v-for="s in bySource" :key="s.label">
              <span class="ar-act-rank__label">{{ s.label }}</span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(s.hits, listMax(bySource)) }"></span></span>
              <span class="ar-act-rank__n">{{ s.hits }}</span>
            </li>
          </ul>
        </div>
        <div class="ar-ai__col">
          <h3 class="ar-ai__sub">Top landing pages</h3>
          <ul v-if="topPages.length" class="ar-act-rank">
            <li v-for="p in topPages" :key="p.path">
              <span class="ar-act-rank__label"><code>{{ p.path }}</code></span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(p.hits, listMax(topPages)) }"></span></span>
              <span class="ar-act-rank__n">{{ p.hits }}</span>
            </li>
          </ul>
          <p v-else class="ar-wd-empty">No pages yet.</p>
        </div>
      </div>
    </section>

    <!-- Timeline — its OWN card: the overview above answers "how much and
         where", this answers "when". Expand a day to see which source landed
         on which page (the day is the finest "when" stored). Honors the same
         filters and range as the overview. -->
    <section v-if="!error && report && total && daily.length" class="ar-card ar-ai">
      <h2 class="ar-card__title">By day <span v-if="rangeLabel" class="ar-card__tag">{{ rangeLabel }}</span></h2>
      <p class="ar-card__lead">
        Click a day to see which source landed on which page. The day is the finest “when”
        stored — no times, nothing that could stand for a person.
      </p>
      <ul class="ar-aiday">
              <li v-for="d in daily" :key="d.date" class="ar-aiday__item">
                <button
                  type="button"
                  class="ar-aiday__row"
                  :aria-expanded="refDayOpen(d.date)"
                  @click="toggleRefDay(d.date)"
                >
                  <span class="ar-aiday__date">{{ dateLabel(d.date) }}</span>
                  <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(d.hits, dailyMax) }"></span></span>
                  <span class="ar-aiday__n">{{ d.hits }}</span>
                  <svg class="ar-aiday__chev" :class="{ 'is-open': refDayOpen(d.date) }" viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
                </button>
                <ul v-if="refDayOpen(d.date)" class="ar-aiday__detail">
                  <li v-if="day(d.date).loading" class="ar-aivis ar-aivis--muted">Loading…</li>
                  <li v-else-if="day(d.date).error" class="ar-aivis ar-aivis--muted">
                    {{ day(d.date).error }}
                    <button type="button" class="ar-linkbtn" @click="loadDay(d.date)">Retry</button>
                  </li>
                  <template v-else>
                    <li v-for="(r, i) in day(d.date).rows" :key="i" class="ar-aivis">
                      <span class="ar-aivis__src">{{ r.source }}</span>
                      <span class="ar-aivis__arr" aria-hidden="true">→</span>
                      <!-- A narrow card truncates the path, so the full value stays reachable:
                           the same styled bubble the activity tables use, and click-to-copy. -->
                      <code
                        class="ar-aivis__path is-copyable"
                        :aria-label="r.path"
                        @mouseenter="showUaTip($event, r.path)"
                        @mouseleave="hideUaTip"
                        @click.stop="copyVal(r.path, 'Path')"
                      >{{ r.path }}</code>
                      <span class="ar-aivis__n">{{ r.hits }}</span>
                    </li>
                    <li v-if="day(d.date).rowCount > day(d.date).rows.length" class="ar-aivis ar-aivis--muted">
                      <template v-if="day(d.date).capped">
                        Showing the {{ day(d.date).rows.length }} busiest of {{ day(d.date).rowCount }} — narrow the filters to see the rest.
                      </template>
                      <button v-else type="button" class="ar-linkbtn" @click="loadDay(d.date, true)">
                        Show all {{ day(d.date).rowCount }}
                      </button>
                    </li>
                  </template>
                </ul>
              </li>
      </ul>
    </section>

    <!-- Unrecognised referrers — the opt-in diagnostic, over the SAME range as the cards
         above, so the two can never invite the wrong conclusion about the same days. -->
    <section v-if="unknownOn" class="ar-card ar-ai">
      <h2 class="ar-card__title">Unrecognised referrers <span class="ar-card__tag">Diagnostic</span></h2>
      <p class="ar-card__lead">
        Readers arrived from these places and none matched a known AI assistant. Most will be ordinary
        sites, search engines and social apps — and some, like Google’s AI Overviews, can’t be told apart
        from an ordinary search click, so Agentimus refuses to guess. If you spot an assistant among them,
        add it with the <code>agentimus_ai_referral_sources</code> filter and it will count from then on.
      </p>

      <div v-if="unknownHosts.length || unknownUtm.length" class="ar-ai__cols">
        <div class="ar-ai__col">
          <h3 class="ar-ai__sub">Referrer hosts</h3>
          <ul v-if="unknownHosts.length" class="ar-act-rank">
            <li v-for="h in unknownHosts" :key="h.token">
              <span class="ar-act-rank__label"><code>{{ h.token }}</code></span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(h.hits, listMax(unknownHosts)) }"></span></span>
              <span class="ar-act-rank__n">{{ h.hits }}</span>
            </li>
          </ul>
          <p v-else class="ar-wd-empty">None.</p>
        </div>
        <div class="ar-ai__col">
          <h3 class="ar-ai__sub">
            Link source tags
            <span class="ar-ai__subnote">where a link says it came from — its <code>utm_source</code> tag — seen even when the referrer was stripped</span>
          </h3>
          <ul v-if="unknownUtm.length" class="ar-act-rank">
            <li v-for="u in unknownUtm" :key="u.token">
              <span class="ar-act-rank__label"><code>{{ u.token }}</code></span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(u.hits, listMax(unknownUtm)) }"></span></span>
              <span class="ar-act-rank__n">{{ u.hits }}</span>
            </li>
          </ul>
          <p v-else class="ar-wd-empty">None.</p>
        </div>
      </div>

      <p v-else class="ar-wd-empty">
        Nothing unrecognised in this range. Every referred visit came either from your own pages or from a
        source Agentimus already knows by name.
      </p>
    </section>

    <!-- The diagnostic is where an owner learns what they're missing, so when it's OFF the
         screen says so once, here, rather than staying silent about its own blind spot. -->
    <section v-else-if="report" class="ar-card">
      <h2 class="ar-card__title">Unrecognised referrers <span class="ar-card__tag">Off</span></h2>
      <p class="ar-card__lead">
        “Traffic from AI” only counts assistants it recognises, and a miss leaves no trace. Turn on
        <strong>Find missed AI sources</strong> in
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings', anchor: 'ar-sec-activity' })">Settings → Visit log</button>
        and Agentimus will list the referrers it couldn’t name, so you can see whether an assistant is
        being overlooked.
      </p>
    </section>

    <!-- Teleported to body, like the activity tables' bubble: the panel animates with a
         transform, and a transformed ancestor becomes the containing block for a
         position:fixed child — which would anchor the tooltip to the panel, not the
         viewport, and land it off-screen. -->
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
  </div>
</template>
