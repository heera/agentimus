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
import CardSkeleton from './CardSkeleton.vue';
import { uaTip } from '../js/uaTip.js';
import { formatDate } from '../js/wpDate.js';

export default {
  name: 'AiTrafficPanel',
  mixins: [uaTip],
  components: { SelectMenu, CardSkeleton },
  props: {
    api: { type: Object, default: null },
    // Load when the screen is first opened, not on every dashboard visit.
    active: { type: Boolean, default: false },
    // The "Find missed AI sources" setting (log_unknown_referrers). It lives on the Settings
    // screen, so it flips while THIS panel is hidden — the report then carries a stale
    // `unknown` block (its enabled state and the diagnostic lists). Watched so the report
    // reloads on the next open, instead of only after a full page refresh.
    logUnknown: { type: Boolean, default: false },
    // A drill-down from a dashboard row: filter keys to apply on arrival
    // (plus a seq stamp so repeat clicks on the same row still re-apply).
    preset: { type: Object, default: null },
    // The dashboard's audience block — the site totals this screen opens with.
    audience: { type: Object, default: null },
  },
  // `flash` is required by the uaTip mixin: copying a path reports its result there.
  // `reload-audience` asks App to re-pull the activity payload this screen's
  // totals ride on — fired after the first-fetch button succeeds.
  emits: ['navigate', 'flash', 'reload-audience'],
  data() {
    return {
      report: null,
      sources: [],
      loading: false,
      error: '',
      // The first-fetch invitation's own state, kept apart from the report's:
      // its button must be able to fail with Google's words while the rest of
      // the screen stays whatever it was.
      fetchBusy: false,
      fetchError: '',
      // What the user is editing. Applied to the server only on Filter / Enter / Clear —
      // typing a path prefix should not fire a query per keystroke.
      // ⭐ `source` is a LIST, matching the request log's filters — same
      // component, same meaning of "several" (Repository::as_list on the server).
      // `path` stays single: it is a prefix match, not a set.
      filters: { from: '', to: '', source: [], path: '' },
      applied: { from: '', to: '', source: [], path: '' },
      // The open day in the By-Day strip, as an index into strip; -1 = closed.
      // The same modal contract every chart in the app keeps (Bing's bars).
      selectedDay: -1,
      // date -> { loading, error, rows, rowCount, full, capped }. Fetched the first time a
      // day is opened and dropped whenever the report reloads under it.
      dayCache: {},
      // Set when a setting the report depends on changed while we were hidden; consumed on
      // the next activation to force a reload of an otherwise-cached report.
      stale: false,
      // The 'Learn more' fold on the diagnostic card.
      unknownWhy: false,
      // The diagnostic lists' "Show all" folds — the shortlist is the default,
      // the remainder is counted out loud, and this is its door.
      unknownAllHosts: false,
      unknownAllUtm: false,
    };
  },
  computed: {
    // ---- The site totals this screen now opens with ------------------------
    people() {
      return (this.audience && this.audience.people) || null;
    },
    ga() {
      return (this.people && this.people.all) || null;
    },
    gaOn() {
      return !!(this.ga && this.ga.connected);
    },
    // Connected in Settings, never successfully polled. Rendered as an
    // invitation with a fetch button — the owner already connected it, so the
    // "connect it" pointer would be an instruction to do a done thing.
    gaPending() {
      return !!(this.ga && this.ga.pending);
    },
    // GA4's own count of AI-referred sessions, when it has one. `null` means
    // Google has no opinion — different from a counted zero, and shown as such.
    gaAi() {
      return this.gaOn && this.ga.aiSessions !== null && this.ga.aiSessions !== undefined ? this.ga.aiSessions : null;
    },
    // Ours, from the referral store.
    ownAi() {
      return (this.people && this.people.ai && this.people.ai.visits) || 0;
    },
    // Which way the two differ, in words a first-time reader can act on. Never
    // "wrong" or "correct": they watch different people, and the sentence says
    // which people each one misses.
    disagreement() {
      if (this.gaAi === null) return '';
      const g = this.gaAi;
      const o = this.ownAi;
      if (g === o) return 'Both numbers match this month. That happens, and it means nothing is missing from either one.';
      if (g > o) {
        return 'Google’s number is higher. Some people arrive without their browser telling your site where they came from, so Agentimus can’t see the AI behind those visits — Google still can, because its script runs in the visitor’s browser.';
      }
      return 'Agentimus’ number is higher. Your own server sees every visit, including people who turn down cookies — and those people are missing from Google’s count entirely.';
    },
    // The AI slice as a percentage of all visits, one decimal.
    aiShare() {
      if (!this.gaOn || !this.ga.sessions) return null;
      return Math.round((this.ownAi / this.ga.sessions) * 1000) / 10;
    },
    // Up, down or level against the window before this one.
    change() {
      return (this.people && this.people.ai && this.people.ai.change) || 0;
    },
    hasPrev() {
      return !!(this.people && this.people.ai && this.people.ai.hasPrev);
    },
    trendWord() {
      if (!this.hasPrev) return '';
      if (this.change > 0) return `${this.n(this.change)} more than the ${this.audience.window} days before`;
      if (this.change < 0) return `${this.n(Math.abs(this.change))} fewer than the ${this.audience.window} days before`;
      return `the same as the ${this.audience.window} days before`;
    },
    trendDir() {
      if (!this.hasPrev || this.change === 0) return 'flat';
      return this.change > 0 ? 'up' : 'down';
    },
    // Assistants: ours when we have them (it works with nothing connected),
    // Google's naming when only that exists.
    assistants() {
      const own = (this.people && this.people.ai && this.people.ai.top) || [];
      if (own.length) return own;
      return (this.ga && this.ga.aiBySource) || [];
    },
    landingPages() {
      return (this.people && this.people.ai && this.people.ai.pages) || [];
    },
    topAssistant() {
      return this.assistants.length ? this.assistants[0] : null;
    },
    topAssistantPct() {
      if (!this.topAssistant || !this.ownAi) return 0;
      return Math.round((this.topAssistant.hits / this.ownAi) * 100);
    },
    topLanding() {
      return this.landingPages.length ? this.landingPages[0] : null;
    },
    // GA4's per-engine arrivals — Bing's human traffic measured by the same
    // instrument as Google's. `null`/empty means GA4 hasn't said (an older
    // snapshot, or a poll away), not "no engine sent anyone".
    engines() {
      return (this.ga && this.ga.engineBySource) || [];
    },
    engineTotal() {
      return this.gaOn && this.ga.engineSessions !== null && this.ga.engineSessions !== undefined
        ? this.ga.engineSessions
        : null;
    },
    engineLine() {
      return this.engines.map((e) => `${e.source} ${this.n(e.hits)}`).join(' · ');
    },
    // Everyone's busiest pages, from analytics.
    allPages() {
      return (this.ga && this.ga.pages) || [];
    },
    // "2m 14s" — plain, and never a bare number of seconds, which nobody reads
    // as a duration.
    avgTime() {
      const s = (this.ga && this.ga.avgSeconds) || 0;
      if (!s) return '—';
      if (s < 60) return `${s}s`;
      const m = Math.floor(s / 60);
      const r = s % 60;
      return r ? `${m}m ${r}s` : `${m}m`;
    },
    returning() {
      if (!this.gaOn) return 0;
      return Math.max(0, this.ga.users - this.ga.newUsers);
    },
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
    // The By-Day strip: every day of the window, zero-filled — the rhythm of
    // absence is part of the picture, and a chart that skipped quiet days
    // would compress a month into its three busy ones. Bounded by the range
    // the server already clamped (and a 400-slot backstop besides).
    strip() {
      const by = Object.create(null);
      this.daily.forEach((d) => { by[d.date] = d.hits; });
      const from = this.range.from;
      const to = this.range.to;
      const start = new Date(`${from}T00:00:00Z`);
      const end = new Date(`${to}T00:00:00Z`);
      if (!from || !to || Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
        return this.daily.map((d) => ({ date: d.date, hits: d.hits }));
      }
      const out = [];
      for (let t = start.getTime(); t <= end.getTime() && out.length < 400; t += 86400000) {
        const date = new Date(t).toISOString().slice(0, 10);
        out.push({ date, hits: by[date] || 0 });
      }
      return out;
    },
    selected() {
      const d = this.strip[this.selectedDay];
      return this.selectedDay >= 0 && d && d.hits ? d : null;
    },
    // The day picker offers only days someone arrived — a jump list of blanks
    // would be thirty ways to open nothing.
    dayOptions() {
      return this.strip
        .map((d, i) => ({ d, i }))
        .filter((x) => x.d.hits)
        .map((x) => ({
          value: String(x.i),
          label: `${this.dateLabel(x.d.date)} · ${x.d.hits} ${x.d.hits === 1 ? 'visit' : 'visits'}`,
        }));
    },
    // The diagnostic shortlists: the 8 busiest (the house number — same as the
    // overview's caps), the remainder counted out loud below.
    unknownHostsShown() {
      return this.unknownAllHosts ? this.unknownHosts : this.unknownHosts.slice(0, 8);
    },
    unknownHostsRest() {
      const rest = this.unknownHosts.slice(8);
      return { count: rest.length, hits: rest.reduce((a, x) => a + x.hits, 0) };
    },
    unknownUtmShown() {
      return this.unknownAllUtm ? this.unknownUtm : this.unknownUtm.slice(0, 8);
    },
    unknownUtmRest() {
      const rest = this.unknownUtm.slice(8);
      return { count: rest.length, hits: rest.reduce((a, x) => a + x.hits, 0) };
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
      return [{ value: '', label: 'Any Assistant' }, ...this.sources];
    },
    // The date inputs are pre-filled from the server's range, so they ride along on every
    // Filter press. `isDefault` is the server's own answer to "is this still the default
    // window?" — without it we'd offer to Clear a filter nobody set.
    hasFilters() {
      return this.applied.source.length > 0 || !!this.applied.path || (!!this.report && !this.range.isDefault);
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
      // Names them while the list is short enough to read, then counts. Same
      // judgement the picker's own trigger makes.
      if (this.applied.source.length) {
        bits.push(this.applied.source.length > 2 ? `${this.applied.source.length} assistants` : this.applied.source.join(' + '));
      }
      if (this.applied.path) bits.push(`pages under ${this.applied.path}`);
      return bits.join(' · ');
    },
  },
  watch: {
    // A picked day fetches its rows and hands focus to the dialog — the same
    // open contract the Bing day report keeps.
    selectedDay(now) {
      if (now < 0 || !this.strip[now] || !this.strip[now].hits) return;
      this.loadDay(this.strip[now].date);
      this.$nextTick(() => {
        const el = this.$refs.aiDayDialog;
        if (el && el.focus) el.focus();
      });
    },
    // The freshness contract: every reveal re-reads the current report —
    // same filters, quietly (old numbers hold the screen while the fresh
    // ones land), so a return between visits never shows yesterday's AI
    // traffic under a live audience card. Deferred a tick so a dashboard
    // drill-down's preset fetch (its watcher fires first and starts a
    // filtered load) is never raced: if a load is in flight, stand down.
    active(on) {
      if (!on) return;
      if (!this.report || this.stale) {
        this.load();
        return;
      }
      this.$nextTick(() => {
        if (!this.loading) this.load();
      });
    },
    // A dashboard row's drill-down: start from clean filters, apply the preset
    // keys, and fetch the filtered report.
    preset(p) {
      if (!p) return;
      const { seq, ...keys } = p;
      // Normalised like the request log's: a linking screen writes `source: 'ChatGPT'`
      // and the picker expects a list.
      if (undefined !== keys.source && !Array.isArray(keys.source)) {
        keys.source = keys.source ? [keys.source] : [];
      }
      this.filters = { from: '', to: '', source: [], path: '', ...keys };
      this.apply();
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
    // Grouped digits, matching the dashboard card these totals come from.
    n(v) {
      return (Number(v) || 0).toLocaleString('en-US');
    },
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
        Object.entries(this.applied).forEach(([k, v]) => { if (Array.isArray(v) ? v.length : v) params[k] = v; });
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
        this.selectedDay = -1; // fresh data, stale selection.
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
      this.filters = { from: '', to: '', source: [], path: '' };
      this.applied = { from: '', to: '', source: [], path: '' };
      this.load();
    },

    /* ---- The per-day drill-down ---------------------------------------------- */

    pickDay(i) {
      if (this.strip[i] && this.strip[i].hits) this.selectedDay = i;
    },
    closeDay() {
      this.selectedDay = -1;
    },
    // Step lands on the next day someone actually ARRIVED — an empty day has
    // no report to show, so the arrows skip it rather than opening a blank.
    stepDay(delta) {
      let i = this.selectedDay + delta;
      while (i >= 0 && i < this.strip.length && !this.strip[i].hits) i += delta;
      if (i >= 0 && i < this.strip.length) this.selectedDay = i;
    },
    hasStep(delta) {
      for (let i = this.selectedDay + delta; i >= 0 && i < this.strip.length; i += delta) {
        if (this.strip[i].hits) return true;
      }
      return false;
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
        if (this.applied.source.length) params.source = this.applied.source;
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
    // The first fetch, from the screen that needs it. Success hands the reload
    // to App (the audience block rides the activity payload); failure prints
    // Google's own words right under the button that earned them.
    async startAnalyticsFetch() {
      if (this.fetchBusy || !this.api || !this.api.refreshGoogleAnalytics) return;
      this.fetchBusy = true;
      this.fetchError = '';
      try {
        await this.api.refreshGoogleAnalytics();
        this.$emit('reload-audience');
      } catch (e) {
        this.fetchError = (e && e.message) || 'The fetch failed — try again in a moment.';
      } finally {
        this.fetchBusy = false;
      }
    },
    pct(hits, max) {
      return `${Math.max(2, Math.round((hits / max) * 100))}%`;
    },
    // Stored days are UTC calendar days; parse and format as UTC or a westward timezone
    // renders every label one day early. The pattern is the site's own date format.
    dateLabel(date) {
      const dt = new Date(`${date}T00:00:00Z`);
      if (Number.isNaN(dt.getTime())) return date;
      return formatDate(dt, true);
    },
  },
};
</script>

<template>
  <div class="ar-aitraffic">
    <!-- Connected, never fetched: the invitation, in the worklist intro's
         grammar — icon, headline, one lead, ONE button, a reassurance line.
         The state exists because the first poll hasn't run; the button IS the
         first poll, so nobody is ever told to reconnect a thing they just
         connected. Disappears forever once numbers exist. -->
    <section v-if="gaPending" class="ar-card ar-rd">
      <div class="ar-work__intro">
        <svg class="ar-work__intro-mark" viewBox="0 0 48 48" width="44" height="44" fill="none"
             stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M6 40h36" />
          <path d="M11 40V26M20 40V18M29 40V22M38 40V12" />
          <circle cx="38" cy="8" r="2.4" />
        </svg>

        <h3 class="ar-work__intro-title">Google Analytics Is Connected</h3>
        <p class="ar-work__intro-lead">
          One fetch brings in your whole audience — every visitor, however they found
          you, next to the ones AI sent. After this first one, it refreshes itself
          once a day.
        </p>

        <button
          type="button"
          class="ar-btn ar-btn--reserve"
          data-reserve="Fetching your numbers…"
          :disabled="fetchBusy"
          @click="startAnalyticsFetch"
        ><span>{{ fetchBusy ? 'Fetching your numbers…' : 'Fetch my numbers' }}</span></button>

        <p v-if="fetchError || ga.error" class="ar-field__hint ar-warn">
          {{ fetchError || ga.error }}
        </p>
        <p class="ar-work__intro-note">
          Takes a few seconds — one read-only request to Google, stored in your own database.
        </p>
      </div>
    </section>

    <!-- EVERYONE first, then the AI slice of it. Two cards, not one: they are
         two different questions, and the AI card's numbers only mean something
         against the totals in this one. -->
    <section v-if="gaOn" class="ar-card ar-rd ar-rd--all">
      <h2 class="ar-card__title">
        Everyone Who Visited
        <span class="ar-card__tag">Last {{ audience.window }} days</span>
      </h2>
      <p class="ar-card__lead">
        Every visitor, however they found you. A visit is one person’s trip to your
        site; they can come back and visit again.
      </p>

      <div class="ar-rd__grid">
        <div class="ar-rd__cell">
          <p class="ar-rd__cn">{{ n(ga.users) }}</p>
          <p class="ar-rd__cl">people</p>
          <p class="ar-rd__cs">{{ n(ga.newUsers) }} first-timers · {{ n(returning) }} came back</p>
        </div>
        <div class="ar-rd__cell">
          <p class="ar-rd__cn">{{ n(ga.sessions) }}</p>
          <p class="ar-rd__cl">visits</p>
          <p class="ar-rd__cs">{{ ga.perVisit }} pages per visit</p>
        </div>
        <div class="ar-rd__cell">
          <p class="ar-rd__cn">{{ n(ga.views) }}</p>
          <p class="ar-rd__cl">pages opened</p>
          <p class="ar-rd__cs">across the whole site</p>
        </div>
        <div class="ar-rd__cell">
          <p class="ar-rd__cn">{{ avgTime }}</p>
          <p class="ar-rd__cl">average visit</p>
          <p class="ar-rd__cs">{{ ga.engagedPct }}% stayed to read</p>
        </div>
      </div>

      <!-- Which engines sent them — GA4's own attribution, organic clicks
           only, so Bing's people are counted by the same instrument as
           Google's. The Search Performance card counts what the ENGINES
           report; the sentence says so before anyone compares the two. -->
      <p v-if="engineTotal" class="ar-rd__note"><span>
        Search engines sent <strong>{{ n(engineTotal) }}</strong> of these visits —
        {{ engineLine }}. That’s Google Analytics’ own attribution; the Search
        Performance card counts what the engines themselves report, so the two
        won’t match exactly.
      </span></p>

      <p v-if="ga.stale" class="ar-rd__note"><span>
        These haven’t refreshed in over two days — they’re the last good numbers, not today’s.
      </span></p>
    </section>

    <!-- Content analytics, not audience overview — so it stands on its own
         rather than hanging off the bottom of the four totals above it. -->
    <section v-if="gaOn && allPages.length" class="ar-card ar-rd">
      <h2 class="ar-card__title">
        Busiest Pages
        <span class="ar-card__tag">Last {{ audience.window }} days</span>
      </h2>
      <p class="ar-card__lead">Your whole site, busiest first — everyone, not only AI.</p>
      <ul class="ar-rd__list ar-rd__list--wide">
        <li v-for="p in allPages" :key="p.path">
          <span class="ar-rd__list-l is-path">{{ p.path }}</span>
          <span class="ar-rd__list-n">{{ n(p.views) }}</span>
        </li>
      </ul>
    </section>

    <!-- The AI slice: did AI send anyone, is it growing, which pages earned it. -->
    <section v-if="people" class="ar-card ar-rd">
      <p class="ar-rd__kicker">AI sent you</p>
      <p class="ar-rd__hero">
        {{ n(ownAi) }}<span class="ar-rd__hero-u">visits</span>
      </p>

      <p v-if="trendWord" class="ar-rd__trend" :class="'is-' + trendDir">
        <span class="ar-rd__arrow" aria-hidden="true">{{ trendDir === 'up' ? '↑' : (trendDir === 'down' ? '↓' : '·') }}</span>
        {{ trendWord }}
      </p>
      <p v-else class="ar-rd__trend is-flat">in the last {{ audience.window }} days</p>

      <p class="ar-rd__context">
        <template v-if="gaOn && ga.sessions">
          Out of {{ n(ga.sessions) }} visits in total — about {{ aiShare }}% of everyone who came.
        </template>
        <!-- Pending says nothing here: the invitation card above already owns
             that story, and "connect it" would instruct a done thing. -->
        <template v-else-if="gaPending">
          Fetch your Google Analytics numbers above to see what share of your
          visitors that is.
        </template>
        <template v-else>
          Connect Google Analytics under <strong>Settings → Data Sources</strong> to see what
          share of your visitors that is.
        </template>
      </p>

      <!-- The interpretation the reader would otherwise have to do themselves:
           who leads, what they read, and whether that is a lot. -->
      <ul v-if="topAssistant || topLanding" class="ar-rd__insights">
        <li v-if="topAssistant">
          <span class="ar-rd__ik">Top assistant</span>
          <span class="ar-rd__iv">{{ topAssistant.source }}</span>
          <span class="ar-rd__ip">{{ topAssistantPct }}%</span>
        </li>
        <li v-if="topLanding">
          <span class="ar-rd__ik">Most-cited page</span>
          <span class="ar-rd__iv is-path">{{ topLanding.path }}</span>
          <span class="ar-rd__ip">{{ topLanding.hits }}</span>
        </li>
      </ul>

      <!-- A footnote, and it looks like one. It used to be a whole section
           competing with the numbers it annotates. -->
      <p v-if="gaAi !== null && gaAi !== ownAi" class="ar-rd__fine">
        <span class="ar-rd__fine-i" aria-hidden="true">i</span>
        Google Analytics says {{ n(gaAi) }} — each counts people the other can’t see.
      </p>
    </section>

    <!-- A STRIP, not a card.
         As a full-width card carrying the same weight as the data cards, this
         implied it governed the screen — and its old lead, "narrow everything
         below", said so outright. It does not. It governs the cards beneath it
         and nothing else: the two analytics cards at the top read a different
         dataset entirely, and the "AI sent you" hero directly above reads the
         audience payload rather than this filtered report. Someone who picked
         one assistant and glanced up at an unchanged hero had every reason to
         think the control was broken.
         So it stops being a section. The controls already carry their own
         tinted box; that is frame enough for a toolbar, and the line above it
         now states its reach instead of overstating it. -->
    <div class="ar-rdfilter">
      <div class="ar-rdfilter__bar">
        <p class="ar-rdfilter__t">
          Filter the cards below
          <span v-if="rangeLabel" class="ar-card__tag">{{ rangeLabel }}</span>
        </p>
        <p class="ar-rdfilter__scope">The totals above don’t change.</p>
        <button
          type="button"
          class="ar-log__refresh ar-rdfilter__reload"
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
      <!-- The CDN caveat was three lines of prose interrupting the flow between
           a heading and its controls. Folded: it matters, and it does not
           matter more than getting to the filters. -->
      <details v-if="beacon" class="ar-ai__cdn">
        <summary>Counted in the browser (CDN mode)</summary>
        <p>
          These are counted in your visitors’ browsers so the number survives a full-page
          cache. A visitor whose ad-blocker stops the script is missed, and browser counting
          can also be inflated by automated traffic. Treat it as a trend, not an audited figure.
        </p>
      </details>

      <!-- The assistant is a closed set drawn from the log itself, so it's a dropdown. The
           landing page is free text, because a path is unbounded and the match is a prefix.
           There is deliberately no client / network / user-agent filter: this store keeps a
           daily count per (source, path) and nothing that could stand for a person. -->
      <div class="ar-log__filters">
        <div class="ar-log__row">
          <!-- "Sent by", not "Assistant". On a screen whose own subtitle
               promises the machines are in the Request Log, a bare "Assistant"
               reads as a machine that visited. It is not: it names WHO REFERRED
               the person, and the relationship is what the label has to say. -->
          <div class="ar-log__field">
            <span class="ar-log__label">Sent by</span>
            <SelectMenu v-model="filters.source" :options="sourceOptions" multiple aria-label="Filter by the assistants that sent them" />
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

      <p v-if="error" class="ar-log__error" role="alert">{{ error }} — try Refresh, and if it persists, reload the page.</p>

      <!-- First load in flight: the shared skeleton, not a bare "Loading…" — same
           treatment as Endpoint Activity and Agent Access. -->
      <CardSkeleton v-else-if="loading && !report" lead="Loading AI traffic…" />

      <template v-else>
        <p v-if="filterSummary" class="ar-ai__filternote">Showing <strong>{{ filterSummary }}</strong> only.</p>

        <p v-if="!total && hasFilters" class="ar-wd-empty">Nothing matched those filters.</p>

        <p v-else-if="!total" class="ar-wd-empty">
          No AI-referred visits recorded yet. When someone arrives from ChatGPT, Perplexity and the like,
          it’ll show here.
        </p>
      </template>
    </div>

    <!-- Composition — its own card, like the dashboard's By-endpoint /
         Top-clients pair: where the visits came from, and where they landed.
         Honors the same filters and range as the overview above. -->
    <section v-if="!error && report && total" class="ar-card ar-ai">
      <h2 id="ar-ai-sources" class="ar-card__title">Top Sources &amp; Landing Pages <span v-if="rangeLabel" class="ar-card__tag">{{ rangeLabel }}</span></h2>
      <!-- Says PEOPLE, in its own lead. The page subtitle says it once at the
           top, which is off-screen by the time anyone reaches this card — and a
           list reading "ChatGPT 7" beside a column of page paths is the exact
           shape of a crawler report. Every card down here has to carry the
           distinction itself or the reader rebuilds the wrong model. -->
      <p class="ar-card__lead">
        People an assistant sent you — which assistant, and the page they opened.
        Busiest first.
      </p>
      <div class="ar-ai__cols">
        <div class="ar-ai__col">
          <h3 class="ar-ai__sub">Which Assistant Sent Them</h3>
          <ul class="ar-act-rank">
            <li v-for="s in bySource" :key="s.label">
              <span class="ar-act-rank__label">{{ s.label }}</span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(s.hits, listMax(bySource)) }"></span></span>
              <span class="ar-act-rank__n">{{ s.hits }}</span>
            </li>
          </ul>
        </div>
        <div class="ar-ai__col">
          <h3 class="ar-ai__sub">The Page They Opened</h3>
          <ul v-if="topPages.length" class="ar-act-rank">
            <li v-for="p in topPages" :key="p.path">
              <span class="ar-act-rank__label"><code>{{ p.path }}</code></span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(p.hits, listMax(topPages)) }"></span></span>
              <span class="ar-act-rank__n">{{ p.hits }}</span>
            </li>
          </ul>
          <p v-else class="ar-wd-empty">No pages yet — rows appear here as visitors from AI answers land on specific pages.</p>
        </div>
      </div>
    </section>

    <!-- Timeline — its OWN card: the overview above answers "how much and
         where", this answers "when". Expand a day to see which assistant sent
         someone and the page they opened (the day is the finest "when" stored).
         Honors the same filters and range as the overview. -->
    <section v-if="!error && report && total && daily.length" class="ar-card ar-ai">
      <h2 class="ar-card__title">By Day <span v-if="rangeLabel" class="ar-card__tag">{{ rangeLabel }}</span></h2>
      <!-- "which assistant landed on which page" was a straight error, and the
           worst kind: it taught the crawler model of this entire screen in one
           sentence. No assistant landed anywhere. A PERSON opened the page,
           after an assistant pointed them at it. -->
      <p class="ar-card__lead">
        Every day of the window in one strip — click a day to see which assistant sent
        someone, and the page they opened. Days are the finest detail stored — no times,
        nothing that could stand for a person.
      </p>
      <!-- The Bing chart's grammar: each bar with visits is a button, hover
           says the day, a click opens the day report. Empty days keep their
           slot (the rhythm of absence is part of the picture) but are not
           doors — there is nothing behind them to open. Card height is
           constant on any site, busy or quiet. -->
      <div class="ar-bing__scrollwrap">
        <div class="ar-bing__trend">
          <button
            v-for="(d, i) in strip"
            :key="d.date"
            type="button"
            class="ar-bing__bar ar-aiday__bar"
            :class="{ 'is-active': selectedDay === i, 'is-empty': !d.hits }"
            :style="d.hits ? { height: pct(d.hits, dailyMax) } : null"
            :disabled="!d.hits"
            :aria-label="`${dateLabel(d.date)} — ${d.hits} ${d.hits === 1 ? 'visit' : 'visits'}`"
            @click="pickDay(i)"
            @mouseenter="showUaTip($event, `${dateLabel(d.date)} · ${d.hits} ${d.hits === 1 ? 'visit' : 'visits'}`)"
            @mouseleave="hideUaTip"
          ></button>
        </div>
      </div>
      <div class="ar-bing__cap">
        <span v-if="strip.length">{{ dateLabel(strip[0].date) }}</span>
        <span v-if="strip.length > 1">{{ dateLabel(strip[strip.length - 1].date) }}</span>
      </div>
    </section>

    <!-- The day report — the same modal contract as the Bing chart's. Teleported
         to body: the panel root is a size container, and a container is the
         containing block for fixed descendants — unteleported, the overlay
         pins to the panel, not the viewport. -->
    <Teleport to="body">
    <transition name="ar-modal">
      <div v-if="selected" class="ar-modal">
        <div
          ref="aiDayDialog"
          class="ar-modal__panel ar-modal__panel--day"
          role="dialog"
          aria-modal="true"
          aria-labelledby="ar-aiday-title"
          tabindex="-1"
          @keydown.esc="closeDay"
          @keydown.left="stepDay(-1)"
          @keydown.right="stepDay(1)"
        >
          <div class="ar-modal__head">
            <div class="ar-day-head">
              <h2 id="ar-aiday-title" class="ar-modal__title">{{ dateLabel(selected.date) }}</h2>
              <div class="ar-day-nav" role="group" aria-label="Switch day">
                <button type="button" class="ar-day-nav__btn" :disabled="!hasStep(-1)" aria-label="Previous day with visits" @click="stepDay(-1)">
                  <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 4l-4 4 4 4" /></svg>
                </button>
                <SelectMenu
                  class="ar-day-picker"
                  :model-value="String(selectedDay)"
                  :options="dayOptions"
                  aria-label="Jump to a day"
                  @update:model-value="selectedDay = Number($event)"
                />
                <button type="button" class="ar-day-nav__btn" :disabled="!hasStep(1)" aria-label="Next day with visits" @click="stepDay(1)">
                  <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg>
                </button>
              </div>
            </div>
            <p class="ar-modal__lead">
              {{ selected.hits }} {{ selected.hits === 1 ? 'visit' : 'visits' }} — which assistant
              sent someone, and the page they opened. Same filters as the cards above.
            </p>
          </div>

          <div class="ar-modal__body">
            <div class="ar-modal__scroll">
              <ul class="ar-aiday__detail ar-aiday__detail--modal">
                <li v-if="day(selected.date).loading" class="ar-aivis ar-aivis--muted">Loading…</li>
                <li v-else-if="day(selected.date).error" class="ar-aivis ar-aivis--muted">
                  {{ day(selected.date).error }}
                  <button type="button" class="ar-linkbtn" @click="loadDay(selected.date)">Retry</button>
                </li>
                <template v-else>
                  <li v-for="(r, i) in day(selected.date).rows" :key="i" class="ar-aivis">
                    <span class="ar-aivis__src">{{ r.source }}</span>
                    <span class="ar-aivis__arr" aria-hidden="true">→</span>
                    <!-- A narrow panel truncates the path, so the full value stays reachable:
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
                  <li v-if="day(selected.date).rowCount > day(selected.date).rows.length" class="ar-aivis ar-aivis--muted">
                    <template v-if="day(selected.date).capped">
                      Showing the {{ day(selected.date).rows.length }} busiest of {{ day(selected.date).rowCount }} — narrow the filters to see the rest.
                    </template>
                    <button v-else type="button" class="ar-linkbtn" @click="loadDay(selected.date, true)">
                      Show all {{ day(selected.date).rowCount }}
                    </button>
                  </li>
                </template>
              </ul>
            </div>
          </div>

          <div class="ar-modal__actions">
            <button type="button" class="ar-btn ar-btn--ghost" @click="closeDay">Close</button>
          </div>
        </div>
      </div>
    </transition>
    </Teleport>

    <!-- Unrecognised referrers — the opt-in diagnostic, over the SAME range as the cards
         above, so the two can never invite the wrong conclusion about the same days. -->
    <section v-if="unknownOn" class="ar-card ar-ai">
      <h2 class="ar-card__title">Unrecognised Referrers <span class="ar-card__tag">Diagnostic</span></h2>
      <p class="ar-card__lead">
        People who arrived from somewhere we couldn’t match to an AI assistant. Most are
        ordinary sites, search engines and social apps.
        <button type="button" class="ar-linkbtn" @click="unknownWhy = !unknownWhy">
          {{ unknownWhy ? 'Hide details' : 'Learn more' }}
        </button>
      </p>
      <p v-if="unknownWhy" class="ar-card__lead">
        Some, like Google’s AI Overviews, can’t be told apart from an ordinary search click, so
        Agentimus refuses to guess rather than inflate the count. Spot an assistant in this list and
        you can add it with the <code>agentimus_ai_referral_sources</code> filter — it counts from
        then on.
      </p>

      <!-- Two columns only when BOTH lists have rows — an empty twin must not
           hold half the card open for the word "None". Each list shows its 8
           busiest with the remainder counted out loud; "Show all" is the door. -->
      <div v-if="unknownHosts.length || unknownUtm.length" :class="{ 'ar-ai__cols': unknownHosts.length && unknownUtm.length }">
        <div v-if="unknownHosts.length" class="ar-ai__col">
          <h3 class="ar-ai__sub">Referrer Hosts</h3>
          <ul class="ar-act-rank">
            <li v-for="h in unknownHostsShown" :key="h.token">
              <span class="ar-act-rank__label"><code>{{ h.token }}</code></span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(h.hits, listMax(unknownHosts)) }"></span></span>
              <span class="ar-act-rank__n">{{ h.hits }}</span>
            </li>
          </ul>
          <p v-if="unknownHostsRest.count" class="ar-ai__more">
            <template v-if="!unknownAllHosts">
              …and {{ unknownHostsRest.count }} more {{ unknownHostsRest.count === 1 ? 'host' : 'hosts' }},
              {{ unknownHostsRest.hits }} {{ unknownHostsRest.hits === 1 ? 'visit' : 'visits' }} between them.
            </template>
            <button type="button" class="ar-linkbtn" @click="unknownAllHosts = !unknownAllHosts">
              {{ unknownAllHosts ? 'Show the top 8' : `Show all ${unknownHosts.length}` }}
            </button>
          </p>
        </div>
        <div v-if="unknownUtm.length" class="ar-ai__col">
          <h3 class="ar-ai__sub">
            Link source tags
            <span class="ar-ai__subnote">where a link says it came from — its <code>utm_source</code> tag — seen even when the referrer was stripped</span>
          </h3>
          <ul class="ar-act-rank">
            <li v-for="u in unknownUtmShown" :key="u.token">
              <span class="ar-act-rank__label"><code>{{ u.token }}</code></span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(u.hits, listMax(unknownUtm)) }"></span></span>
              <span class="ar-act-rank__n">{{ u.hits }}</span>
            </li>
          </ul>
          <p v-if="unknownUtmRest.count" class="ar-ai__more">
            <template v-if="!unknownAllUtm">
              …and {{ unknownUtmRest.count }} more {{ unknownUtmRest.count === 1 ? 'tag' : 'tags' }},
              {{ unknownUtmRest.hits }} {{ unknownUtmRest.hits === 1 ? 'visit' : 'visits' }} between them.
            </template>
            <button type="button" class="ar-linkbtn" @click="unknownAllUtm = !unknownAllUtm">
              {{ unknownAllUtm ? 'Show the top 8' : `Show all ${unknownUtm.length}` }}
            </button>
          </p>
        </div>
        <p v-if="unknownHosts.length && !unknownUtm.length" class="ar-ai__more">
          No link source tags in this window — a link that names its source (<code>utm_source</code>)
          would be counted here even when the referrer was stripped.
        </p>
      </div>

      <p v-else class="ar-wd-empty">
        Nothing unrecognised in this range. Every referred visit came either from your own pages or from a
        source Agentimus already knows by name.
      </p>
    </section>

    <!-- The diagnostic is where an owner learns what they're missing, so when it's OFF the
         screen says so once, here, rather than staying silent about its own blind spot. -->
    <section v-else-if="report" class="ar-card">
      <h2 class="ar-card__title">Unrecognised Referrers <span class="ar-card__tag">Off</span></h2>
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
