<script>
import { confirm } from '../confirm.js';
import { tileIcon } from '../groupIcons.js';
import SelectMenu from './SelectMenu.vue';

import { uaTip } from '../uaTip.js';
import { tipGuard } from '../tipGuard.js';
import { formatDate, formatTime, formatStamp } from '../wpDate.js';

export default {
  name: 'ActivityPanel',
  mixins: [uaTip],
  components: { SelectMenu },
  props: {
    data: { type: Object, default: () => ({}) },
    summary: { type: Object, default: null },
    loaded: { type: Boolean, default: false },
    refreshing: { type: Boolean, default: false },
    // Whether auto-refresh is armed (controlled from the bell menu). Drives the
    // passive "Auto-updating" marker so this card explains its own live numbers.
    live: { type: Boolean, default: false },
    api: { type: Object, default: null },
  },
  emits: ['refresh', 'clear', 'navigate', 'flash'],
  data() {
    return {
      feedMore: false,
      // Hint for an unverified network chip — folds in the "it's the IP, not the browser"
      // explanation, in plain language (no PTR/reverse-DNS jargon), so a self-declared name
      // is never read as a site the visitor came from. Shared by both feed surfaces.
      netTip:
        'This name comes from the visitor’s IP address, not their browser. It’s what the '
        + 'network that owns the IP calls itself — looked up from the IP, and not confirmed '
        + 'by Agentimus, so it may not be a real website you can open.',
      // The per-day report modal. The page itself never reflows; clicking a bar
      // opens this focused overlay with the day's breakdown + full request log.
      dayModal: { open: false, loading: false, error: '', date: '', total: 0, rows: [], capped: false },
      // The Traffic-from-AI twin of dayModal — same dialog conventions, fed by
      // /activity/ai-day (source → landing-page pairings; days are the finest
      // "when" stored, so there are no per-visit times).
      aiDay: { open: false, loading: false, error: '', date: '', hits: 0, rows: [], rowCount: 0, capped: false },
      aiDayScrollMore: false,
      dayScrollMore: false,
      // Whether each breakdown column is unfolded past its top-N summary (per day).
      dayExpand: { clients: false, endpoints: false },
      // Styled hover tooltip above the bars.
      tip: { show: false, day: null, x: 0, caret: 0 },
      refTip: { show: false, day: null, x: 0, caret: 0 },
      // Styled hover tooltip for long inline values (a full User-Agent). position:fixed
      // so the scrolling request feed / day modal never clips it — replaces the native
      // title="…" bubble with the same dark look as the chart tooltip.
      // Same styled bubble, for the unverified-network hint ({@see netTip}) — a custom
      // tooltip rather than a native title="…", so it matches the UA/chart tooltips.
      netHint: { show: false, x: 0, y: 0, caret: 16, below: false },
    };
  },
  mounted() {
    window.addEventListener('resize', this.updateFeedHint);
    this.$nextTick(this.updateFeedHint);
    // Scrolling drops the chart and network bubbles like it drops the UA one.
    this._tipUnguards = [
      tipGuard.register(this.hideTip),
      tipGuard.register(this.hideRefTip),
      tipGuard.register(this.hideNetHint),
    ];
  },
  beforeUnmount() {
    window.removeEventListener('resize', this.updateFeedHint);
    document.removeEventListener('keydown', this.onDayModalKeydown);
    document.removeEventListener('keydown', this.onAiDayKeydown);
    (this._tipUnguards || []).forEach((off) => off());
  },
  watch: {
    recentGrouped() {
      this.$nextTick(this.updateFeedHint);
    },
  },
  computed: {
    // Public/sign-in split for the summary tiles — mirrors the Discovery hub's
    // reconciliation, so the two screens can never show different providers
    // numbers without saying why. Zero when the payload lacks the split fields.
    dashProvidersHeld() {
      const s = this.summary || {};
      if (typeof s.providersPublic !== 'number') return 0;
      return Math.max(0, (s.providers || 0) - s.providersPublic);
    },
    dashToolsHeld() {
      const s = this.summary || {};
      if (typeof s.toolsPublic !== 'number') return 0;
      return Math.max(0, (s.tools || 0) - s.toolsPublic);
    },
    // The dashboard's own span (min(30, retention)) vs what the log still holds.
    windowDays() {
      return this.data.window || 30;
    },
    retention() {
      return this.data.retention || this.windowDays;
    },
    maxRowsLabel() {
      return (this.data.maxRows || 50000).toLocaleString();
    },
    totals() {
      return this.data.totals || { today: 0, week: 0, month: 0, all: 0, agents: 0 };
    },
    daily() {
      return this.data.daily || [];
    },
    byAgent() {
      return this.decorateRank(this.data.byAgent);
    },
    byEndpoint() {
      return this.decorateRank(this.data.byEndpoint);
    },
    // The half-window length the trend arrows compare (and their tooltips cite).
    trendHalfDays() {
      return Math.floor((this.data.window || 30) / 2);
    },
    // Traffic AI sent you (humans arriving from ChatGPT/Perplexity/… — the mirror
    // of the bot log above).
    referrals() {
      return this.data.referrals || null;
    },
    refTotals() {
      return (this.referrals && this.referrals.totals) || { today: 0, window: 0 };
    },
    refSources() {
      return (this.referrals && this.referrals.bySource) || [];
    },
    refPages() {
      return (this.referrals && this.referrals.topPages) || [];
    },
    // Per-day AI-referral breakdown (newest first), each day carrying its
    // source → page rows. The day is the finest "when" the store keeps. The full,
    // expandable list lives on the AI traffic screen; here it only feeds the sparkline.
    refDaily() {
      return (this.referrals && this.referrals.daily) || [];
    },
    // The five that fit a summary card. The rest are one click away.
    refTopSources() {
      return this.refSources.slice(0, 5);
    },
    refTopPages() {
      return this.refPages.slice(0, 5);
    },
    /**
     * A continuous day series for the sparkline, oldest → newest. The store returns ONLY
     * days that had a visit, so a quiet week would otherwise draw as a solid wall of bars
     * with the gaps closed up — the shape of the month would be a lie. Zero-fill it.
     * Days are UTC calendar days, so the walk is done in UTC.
     */
    refSpark() {
      const byDate = new Map(this.refDaily.map((d) => [d.date, d.hits]));
      const days = this.data.window || 30;
      const today = new Date();
      const out = [];
      for (let i = days - 1; i >= 0; i--) {
        const dt = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate() - i));
        const date = dt.toISOString().slice(0, 10);
        out.push({ date, hits: byDate.get(date) || 0 });
      }
      return out;
    },
    refSparkMax() {
      return Math.max(1, ...this.refSpark.map((d) => d.hits));
    },
    refSparkAria() {
      return `AI-referred visits per day over the last ${this.refSpark.length} days, ${this.refTotals.window} in total`;
    },
    // Counted server-side over the whole window. refSources is the leaderboard, capped
    // at 8, so its length would under-report a site that hears from more sources.
    refSourceCount() {
      return this.referrals && typeof this.referrals.sourceCount === 'number'
        ? this.referrals.sourceCount
        : this.refSources.length;
    },
    // Only used to word the link through to the full report — the diagnostic itself
    // lives on the AI traffic screen now.
    unknownOn() {
      return !!(this.data.unknownSources && this.data.unknownSources.enabled);
    },
    recent() {
      return this.data.recent || [];
    },
    recentGrouped() {
      const groups = new Map();
      for (const r of this.recent) {
        const key = `${r.agent}|${r.endpoint}|${r.ua || ''}`;
        const g = groups.get(key);
        if (g) { g.count += 1; if (!g.network && r.network) { g.network = r.network; g.verdict = r.verdict || 0; } }
        else groups.set(key, { agent: r.agent, endpoint: r.endpoint, ua: r.ua, network: r.network || '', verdict: r.verdict || 0, at: r.at, count: 1 });
      }
      return Array.from(groups.values());
    },
    // Whether any recent row carries an owning network — the "Identify every bot" column only
    // appears when there's data for it, so a default install shows a plain table.
    hasNetwork() {
      return this.recentGrouped.some((r) => r.network);
    },
    maxDaily() {
      return Math.max(1, ...this.daily.map((d) => d.hits));
    },
    maxAgent() {
      return Math.max(1, ...this.byAgent.map((a) => a.hits));
    },
    maxEndpoint() {
      return Math.max(1, ...this.byEndpoint.map((e) => e.hits));
    },
    // The bar highlighted while its report is open.
    selectedDay() {
      return this.dayModal.open ? this.dayModal.date : null;
    },
    // The chart record for the open day — instant breakdown (clients/endpoints +
    // distinct counts) while the full log loads.
    modalDetail() {
      if (!this.dayModal.date) return null;
      return this.daily.find((d) => d.date === this.dayModal.date) || null;
    },
    // Full per-day breakdown, derived from the already-loaded request log (each row
    // carries its client + endpoint) — so "+N more" can unfold the complete list
    // with no extra fetch. On a capped day (>500 hits) this covers the rows we hold.
    fullDayClients() {
      return this.tallyDay('agent');
    },
    fullDayEndpoints() {
      return this.tallyDay('endpoint');
    },
    // What each column renders: the full list when expanded, else the top-N summary.
    shownClients() {
      if (!this.modalDetail) return [];
      return this.dayExpand.clients ? this.fullDayClients : this.modalDetail.clients;
    },
    shownEndpoints() {
      if (!this.modalDetail) return [];
      return this.dayExpand.endpoints ? this.fullDayEndpoints : this.modalDetail.endpoints;
    },
    // Days with activity — the set the modal's prev/next/dropdown move through.
    hitDays() {
      return this.daily.filter((d) => d.hits > 0);
    },
    dayNavIndex() {
      return this.hitDays.findIndex((d) => d.date === this.dayModal.date);
    },
    dayHasPrev() {
      return this.dayNavIndex > 0;
    },
    dayHasNext() {
      return this.dayNavIndex >= 0 && this.dayNavIndex < this.hitDays.length - 1;
    },
    hitDaysDesc() {
      return this.hitDays.slice().reverse();
    },
    // Options for the day picker (custom SelectMenu): newest first, each labelled with
    // its date and hit count — the same text the old native <option>s carried.
    dayOptions() {
      return this.hitDaysDesc.map((d) => ({
        value: d.date,
        label: `${this.dateLabel(d.date)} · ${d.hits} ${d.hits === 1 ? 'hit' : 'hits'}`,
      }));
    },
    // ---- AI-traffic day modal (the endpoint-activity modal's twin) ----------
    aiHitDays() {
      return this.refSpark.filter((d) => d.hits > 0);
    },
    aiDayIndex() {
      return this.aiHitDays.findIndex((d) => d.date === this.aiDay.date);
    },
    aiDayHasPrev() {
      return this.aiDayIndex > 0;
    },
    aiDayHasNext() {
      return this.aiDayIndex >= 0 && this.aiDayIndex < this.aiHitDays.length - 1;
    },
    aiDayOptions() {
      return this.aiHitDays
        .slice()
        .reverse()
        .map((d) => ({
          value: d.date,
          label: `${this.dateLabel(d.date)} · ${d.hits} ${d.hits === 1 ? 'visit' : 'visits'}`,
        }));
    },
  },
  methods: {
    tileIcon,
    barHeight(hits) {
      const h = Math.round((hits / this.maxDaily) * 100);
      return `${hits > 0 ? Math.max(8, h) : 2}%`;
    },
    topClientsText(d) {
      return (d.clients || []).slice(0, 3).map((t) => `${t.label} ${t.hits}`).join(' · ');
    },
    barAria(d) {
      const noun = d.hits === 1 ? 'hit' : 'hits';
      return d.hits
        ? `${this.dateLabel(d.date)}: ${d.hits} ${noun}. Select to open this day's report.`
        : `${this.dateLabel(d.date)}: no hits.`;
    },
    // ---- Day-report modal (the only thing a bar click changes) -----------------
    selectDay(d) {
      if (d.hits) this.openDay(d.date);
    },
    openDay(date) {
      this.loadDay(date);
      // Esc is caught at the document, not just the panel: with backdrop-click
      // close removed, a stray outside click parks focus off the dialog and a
      // panel-scoped keydown would never hear the key.
      document.addEventListener('keydown', this.onDayModalKeydown);
      this.$nextTick(() => {
        const el = this.$refs.dayDialog;
        if (el) el.focus();
      });
    },
    onDayModalKeydown(e) {
      if ('Escape' === e.key) this.closeDayModal();
    },
    // Tally the loaded day-log rows by a field into busiest-first {label, hits}.
    tallyDay(field) {
      const counts = new Map();
      for (const r of (this.dayModal.rows || [])) {
        const label = (r && r[field]) || '';
        counts.set(label, (counts.get(label) || 0) + 1);
      }
      return Array.from(counts, ([label, hits]) => ({ label, hits })).sort((a, b) => b.hits - a.hits);
    },
    // Load (or switch to) a day inside the open modal. Guards a stale response.
    async loadDay(date) {
      this.dayModal = { open: true, loading: true, error: '', date, total: 0, rows: [], capped: false };
      this.dayScrollMore = false;
      this.dayExpand = { clients: false, endpoints: false };
      // Back to the top NOW, not after the fetch: the breakdown swaps to the new
      // day instantly (chart data), and the busy state lives up there — a reader
      // parked deep in the old day's request list would otherwise see nothing
      // happen until the rows arrive.
      this.$nextTick(() => {
        const el = this.$refs.dayScroll;
        if (el) el.scrollTop = 0;
      });
      if (!this.api || !this.api.getActivityDay) {
        this.dayModal.loading = false;
        this.dayModal.error = 'Unable to load requests in this context.';
        return;
      }
      try {
        const res = await this.api.getActivityDay(date);
        if (!this.dayModal.open || this.dayModal.date !== date) return;
        this.dayModal.rows = res.rows || [];
        this.dayModal.total = res.total || 0;
        this.dayModal.capped = !!res.capped;
      } catch (e) {
        if (this.dayModal.open && this.dayModal.date === date) {
          this.dayModal.error = (e && e.message) || 'Failed to load requests.';
        }
      } finally {
        if (this.dayModal.open && this.dayModal.date === date) this.dayModal.loading = false;
        this.$nextTick(() => this.updateDayScrollHint());
      }
    },
    closeDayModal() {
      this.dayModal.open = false;
      document.removeEventListener('keydown', this.onDayModalKeydown);
    },
    stepDay(delta) {
      const i = this.dayNavIndex;
      if (i < 0) return;
      const next = this.hitDays[i + delta];
      if (next) this.loadDay(next.date);
    },
    // ---- AI-traffic day modal (same conventions as the endpoint one) ----------
    openAiDay(d) {
      if (!d.hits) return;
      this.loadAiDay(d.date);
      document.addEventListener('keydown', this.onAiDayKeydown);
      this.$nextTick(() => {
        const el = this.$refs.aiDayDialog;
        if (el) el.focus();
      });
    },
    async loadAiDay(date) {
      this.aiDay = { open: true, loading: true, error: '', date, hits: 0, rows: [], rowCount: 0, capped: false };
      if (!this.api || !this.api.getAiTrafficDay) {
        this.aiDay.loading = false;
        this.aiDay.error = 'Unable to load visits in this context.';
        return;
      }
      try {
        // Always the full day (bounded at 200 server-side): the dialog scrolls,
        // so a "Show all" click between the reader and the tail is pure friction.
        const res = await this.api.getAiTrafficDay(date, { full: 1 });
        if (!this.aiDay.open || this.aiDay.date !== date) return;
        this.aiDay.hits = res.hits || 0;
        this.aiDay.rows = res.rows || [];
        this.aiDay.rowCount = res.rowCount || 0;
        this.aiDay.capped = !!res.capped;
      } catch (e) {
        if (this.aiDay.open && this.aiDay.date === date) {
          this.aiDay.error = (e && e.message) || 'Failed to load visits.';
        }
      } finally {
        if (this.aiDay.open && this.aiDay.date === date) this.aiDay.loading = false;
        this.$nextTick(() => this.updateAiDayScrollHint());
      }
    },
    onAiDayScroll() {
      this.updateAiDayScrollHint();
    },
    updateAiDayScrollHint() {
      const el = this.$refs.aiDayScroll;
      this.aiDayScrollMore = !!el && el.scrollHeight - el.scrollTop - el.clientHeight > 4;
    },
    scrollAiDayBody() {
      const el = this.$refs.aiDayScroll;
      if (!el) return;
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      el.scrollBy({ top: Math.round(el.clientHeight * 0.8), behavior: reduce ? 'auto' : 'smooth' });
    },
    closeAiDay() {
      this.aiDay.open = false;
      document.removeEventListener('keydown', this.onAiDayKeydown);
    },
    onAiDayKeydown(e) {
      if ('Escape' === e.key) this.closeAiDay();
    },
    stepAiDay(delta) {
      const i = this.aiDayIndex;
      if (i < 0) return;
      const next = this.aiHitDays[i + delta];
      if (next) this.loadAiDay(next.date);
    },
    onDayScroll() {
      this.updateDayScrollHint();
    },
    updateDayScrollHint() {
      const el = this.$refs.dayScroll;
      this.dayScrollMore = !!el && el.scrollHeight - el.scrollTop - el.clientHeight > 4;
    },
    scrollDayBody() {
      const el = this.$refs.dayScroll;
      if (!el) return;
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      el.scrollBy({ top: Math.round(el.clientHeight * 0.8), behavior: reduce ? 'auto' : 'smooth' });
    },
    // ---- Styled hover tooltip --------------------------------------------------
    showTip(d, ev) {
      const bar = ev.currentTarget;
      // Scroll-induced mouseenters wait for a real mouse move (see tipGuard).
      if (tipGuard.suppress(ev, bar, () => this.showTip(d, { currentTarget: bar, type: 'retry' }))) return;
      this.tip.day = d;
      this.tip.show = true;
      const wrap = this.$refs.sparkWrap;
      if (wrap && bar) {
        const w = wrap.getBoundingClientRect();
        const b = bar.getBoundingClientRect();
        this.tip.x = b.left + b.width / 2 - w.left;
      }
      this.$nextTick(() => this.positionTip(bar));
    },
    hideTip() {
      this.tip.show = false;
    },
    // The AI-traffic sparkline's styled tooltip — same mechanics as showTip/
    // positionTip, its own wrap/element/state because the two charts live in
    // different cards and can in principle be hovered in quick succession.
    showRefTip(d, ev) {
      const bar = ev.currentTarget;
      if (tipGuard.suppress(ev, bar, () => this.showRefTip(d, { currentTarget: bar, type: 'retry' }))) return;
      this.refTip.day = d;
      this.refTip.show = true;
      const wrap = this.$refs.refSparkWrap;
      if (wrap && bar) {
        const w = wrap.getBoundingClientRect();
        const b = bar.getBoundingClientRect();
        this.refTip.x = b.left + b.width / 2 - w.left;
      }
      this.$nextTick(() => {
        const tipEl = this.$refs.refTipEl;
        if (!wrap || !tipEl || !bar) return;
        const w = wrap.getBoundingClientRect();
        const b = bar.getBoundingClientRect();
        const tw = tipEl.offsetWidth;
        const pad = 6;
        const center = b.left + b.width / 2 - w.left;
        const half = tw / 2;
        const x = Math.max(half + pad, Math.min(center, w.width - half - pad));
        this.refTip.x = x;
        this.refTip.caret = Math.max(10, Math.min(center - (x - half), tw - 10));
      });
    },
    hideRefTip() {
      this.refTip.show = false;
    },
    positionTip(bar) {
      const wrap = this.$refs.sparkWrap;
      const tipEl = this.$refs.tipEl;
      if (!wrap || !tipEl || !bar) return;
      const w = wrap.getBoundingClientRect();
      const b = bar.getBoundingClientRect();
      const tw = tipEl.offsetWidth;
      const pad = 6;
      const center = b.left + b.width / 2 - w.left;
      const half = tw / 2;
      const x = Math.max(half + pad, Math.min(center, w.width - half - pad));
      this.tip.x = x;
      this.tip.caret = Math.max(10, Math.min(center - (x - half), tw - 10));
    },
    // A shown network is only *confirmed* when its hit forward-confirmed (verdict 1 — a
    // real, verifiable engine). Any other network is just the visitor IP's self-declared
    // reverse-DNS (PTR) name: it may not even resolve forward, so flag it as unverified
    // rather than let it read like a site the visitor came from.
    netUnverified(r) {
      return !!(r && r.network) && 1 !== r.verdict;
    },
    // Show/hide the styled hint bubble for an unverified network chip. Positioned off the
    // hovered chip's rect (fixed viewport), same measure-and-clamp as showUaTip so the box
    // never overflows and the caret keeps pointing at the chip. Content is the static netTip.
    showNetHint(ev) {
      const el = ev.currentTarget;
      if (tipGuard.suppress(ev, el, () => this.showNetHint({ currentTarget: el, type: 'retry' }))) return;
      const rect = el.getBoundingClientRect();
      const below = rect.top < 96; // not enough room above → drop below.
      const anchor = rect.left + 16;
      this.netHint = {
        show: true,
        x: Math.max(rect.left, 12),
        y: below ? rect.bottom + 8 : rect.top - 8,
        caret: 16,
        below,
      };
      this.$nextTick(() => {
        const el = this.$refs.netHintEl;
        if (!el) return;
        const w = el.offsetWidth;
        const x = Math.max(12, Math.min(this.netHint.x, window.innerWidth - w - 12));
        this.netHint.x = x;
        this.netHint.caret = Math.max(12, Math.min(anchor - x, w - 16));
      });
    },
    hideNetHint() {
      this.netHint.show = false;
    },
    // Attach a growth/down delta to each breakdown row, once per data change, so the
    // template stays declarative (Top clients / By endpoint).
    decorateRank(list) {
      return (list || []).map((r) => ({ ...r, delta: this.trendView(r) }));
    },
    // A calm trend indicator: a colored arrow + % ONLY where there's enough signal to make
    // the claim honestly. Anything else — too few hits, no earlier baseline to compare
    // against, or movement within the dead-band — reads as a quiet "–" (no trend claimed),
    // so half-vs-half jitter on sparse/spiky bot traffic isn't dressed up as a swing.
    // The referral rows' bubble: the row's share of the window's AI visits —
    // the one number the leaderboard doesn't already show.
    refShareTip(hits) {
      const total = (this.refTotals && this.refTotals.window) || 0;
      if (!total) return `${hits} ${1 === hits ? 'visit' : 'visits'}`;
      return `${hits} of ${total} AI visits · ${Math.round((hits / total) * 100)}%`;
    },
    // The styled bubble behind a trend row: the two half-window sums the arrow
    // was computed from — real numbers, not a bare percentage to guess at.
    trendTip(r) {
      if (!r.delta || 'flat' === r.delta.dir) {
        return 'No trend claimed — steady, or too few hits to say.';
      }
      return `Recent ${this.trendHalfDays} days: ${r.recent} · the ${this.trendHalfDays} before: ${r.earlier}`;
    },
    trendView(r) {
      const MIN_HITS = 20; // below this, too little volume to call a trend.
      const BAND = 10;     // |change| within this reads as steady, not movement.
      const t = r ? r.trend : null;
      const hits = (r && r.hits) || 0;
      if (hits < MIN_HITS || t === null || t === undefined || Math.abs(t) <= BAND) {
        return { dir: 'flat', label: '–' };
      }
      return t > 0 ? { dir: 'up', label: `▲ ${t}%` } : { dir: 'down', label: `▼ ${Math.abs(t)}%` };
    },
    // ---- Formatting ------------------------------------------------------------
    listMax(list) {
      return Math.max(1, ...(list || []).map((x) => x.hits));
    },
    pct(hits, max) {
      return `${Math.max(2, Math.round((hits / max) * 100))}%`;
    },
    // The site's own display formats (Settings → General), read on the UTC clock
    // face — stored days are UTC calendar days, and a local-time read would label
    // every westward evening one day early.
    dateLabel(date) {
      const dt = new Date(`${date}T00:00:00Z`);
      if (Number.isNaN(dt.getTime())) return date;
      return formatDate(dt, true);
    },
    exactTime(iso) {
      const dt = new Date(iso);
      if (Number.isNaN(dt.getTime())) return '';
      return formatTime(dt, true);
    },
    exactStamp(iso) {
      const dt = new Date(iso);
      if (Number.isNaN(dt.getTime())) return iso;
      return `${formatStamp(dt, true)} UTC`;
    },
    ago(iso) {
      const then = new Date(iso).getTime();
      if (!then) return '';
      const s = Math.max(0, Math.round((Date.now() - then) / 1000));
      if (s < 60) return 'just now';
      const m = Math.round(s / 60);
      if (m < 60) return `${m}m ago`;
      const h = Math.round(m / 60);
      if (h < 24) return `${h}h ago`;
      return `${Math.round(h / 24)}d ago`;
    },
    // ---- Recent feed scroll cue ------------------------------------------------
    async confirmClear() {
      const ok = await confirm({
        title: 'Clear activity log?',
        message: 'This permanently deletes the entire agent activity log. This cannot be undone.',
        confirmLabel: 'Clear log',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (ok) this.$emit('clear');
    },
    onFeedScroll() {
      this.updateFeedHint();
    },
    updateFeedHint() {
      const el = this.$refs.feedScroll;
      this.feedMore = !!el && el.scrollHeight - el.scrollTop - el.clientHeight > 4;
    },
    scrollFeed() {
      const el = this.$refs.feedScroll;
      if (!el) return;
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      el.scrollBy({ top: Math.round(el.clientHeight * 0.8), behavior: reduce ? 'auto' : 'smooth' });
    },
  },
};
</script>

<template>
  <div class="ar-act">
    <!-- At-a-glance summary (clickable → jumps to the relevant tab) -->
    <!-- Readiness isn't a tile here — it's the rail's AEO/GEO card, which owns the score
         and rungs. Repeating "19/19 · 100%" beside a "91" gauge just shows two readiness
         numbers. These three describe the agent SURFACE instead. -->
    <div v-if="summary" class="ar-dash-sum">
      <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-providers' })">
        <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('providers')"></span>
        <span class="ar-dash-tile__body">
          <span class="ar-dash-tile__row">
            <strong class="ar-dash-tile__v">{{ summary.providers }}</strong>
            <span class="ar-dash-tile__k">Providers</span>
          </span>
          <span v-if="dashProvidersHeld > 0" class="ar-dash-tile__sub">{{ summary.providersPublic }} public · {{ dashProvidersHeld }} sign-in only</span>
          <span v-else class="ar-dash-tile__sub">sources describing your site</span>
        </span>
      </button>
      <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-capabilities' })">
        <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('capabilities')"></span>
        <span class="ar-dash-tile__body">
          <span class="ar-dash-tile__row">
            <strong class="ar-dash-tile__v">{{ summary.capabilities }}</strong>
            <span class="ar-dash-tile__k">Capabilities</span>
          </span>
          <span class="ar-dash-tile__sub">what agents can do or read</span>
        </span>
      </button>
      <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-apis' })">
        <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('apis')"></span>
        <span class="ar-dash-tile__body">
          <span class="ar-dash-tile__row">
            <strong class="ar-dash-tile__v">{{ summary.apis }}</strong>
            <span class="ar-dash-tile__k">APIs</span>
          </span>
          <span class="ar-dash-tile__sub">interfaces agents can call</span>
        </span>
      </button>
      <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-tools' })">
        <span class="ar-dash-tile__ic" aria-hidden="true" v-html="tileIcon('tools')"></span>
        <span class="ar-dash-tile__body">
          <span class="ar-dash-tile__row">
            <strong class="ar-dash-tile__v">{{ summary.tools }}</strong>
            <span class="ar-dash-tile__k">Tools</span>
          </span>
          <span v-if="dashToolsHeld > 0" class="ar-dash-tile__sub">{{ summary.toolsPublic }} public · {{ dashToolsHeld }} sign-in only</span>
          <span v-else class="ar-dash-tile__sub">actions agents can run</span>
        </span>
      </button>
    </div>

    <!-- Quiet privacy framing so the dashboard reads as informational, not
         surveillance. The shield-check is the privacy-fact mark (.ar-privnote). -->
    <p class="ar-dash-note ar-privnote">
      Informational only — which AI assistants read your site, in aggregate. No IP addresses by default,
      nothing sent anywhere.
    </p>

    <!-- First load in flight: show a skeleton, not the empty state. -->
    <template v-if="!loaded">
      <section class="ar-card" aria-busy="true">
        <h2 class="ar-card__title">Endpoint activity</h2>
        <p class="ar-card__lead">Loading recent endpoint activity…</p>
        <div class="ar-skel">
          <div class="ar-skel__tiles">
            <span class="ar-skel__box"></span>
            <span class="ar-skel__box"></span>
            <span class="ar-skel__box"></span>
            <span class="ar-skel__box"></span>
          </div>
          <span class="ar-skel__line" style="width: 88%"></span>
          <span class="ar-skel__line" style="width: 72%"></span>
          <span class="ar-skel__line" style="width: 80%"></span>
        </div>
      </section>
    </template>

    <!-- Logging disabled -->
    <section v-else-if="data.enabled === false" class="ar-card">
      <h2 class="ar-card__title">Endpoint activity</h2>
      <p class="ar-card__lead">
        Activity logging is off. Enable <strong>Agent activity log</strong> in
        Settings → Features to record who fetches your discovery and llms endpoints.
      </p>
    </section>

    <template v-else>
      <!-- Overview: totals + chart -->
      <section class="ar-card">
        <!-- Ruled head: the lead keeps its tight old spot under the title, the
             buttons sit centered at the right, and the masthead rule lives on
             the HEAD's own bottom edge — full card width, always below the
             buttons, never stopping short at the text column. -->
        <div class="ar-card__head ar-card__head--ruled">
          <div>
            <div class="ar-act-titlerow">
              <h2 class="ar-card__title">Endpoint activity</h2>
              <span v-if="live" class="ar-act-live" title="Auto-refresh is on — these stats update on their own. Refresh forces an update now.">
                <span class="ar-act-live__dot" aria-hidden="true"></span>Auto-refresh
              </span>
            </div>
            <p class="ar-card__lead">
              Who fetched your discovery &amp; llms endpoints — AI agents, crawlers and browsers.
              Local-only, no IP logged.
              <!-- `window` is what these cards cover; `retention` is what still exists. The two
                   differ once an owner keeps more than 30 days, so this sentence — which is about
                   what is KEPT — must never read `window`. -->
              <template v-if="retention > windowDays">
                These cards cover the last {{ windowDays }} days; records are kept for {{ retention }} days, and the
                full history is readable in the Request Log.
              </template>
              <template v-else-if="data.autoPrune === false">
                Records are kept until the log reaches {{ maxRowsLabel }} rows, then the oldest are removed.
              </template>
              <template v-else>
                Records are kept for the last {{ retention }} days, then removed.
              </template>
            </p>
          </div>
          <div class="ar-act-controls">
            <!-- data-reserve holds the busy label's width from the start, so the
                 Refresh → Refreshing… swap never rewraps the lead beside it. -->
            <button type="button" class="ar-btn ar-btn--ghost ar-btn--reserve" data-reserve="Refreshing…" :disabled="refreshing" @click="$emit('refresh')">
              <span>{{ refreshing ? 'Refreshing…' : 'Refresh' }}</span>
            </button>
            <button type="button" class="ar-btn ar-btn--danger" @click="confirmClear">Clear log</button>
          </div>
        </div>

        <div class="ar-wd-stats ar-act-stats ar-act-stats--4">
          <div class="ar-wd-stat"><strong>{{ totals.today }}</strong><span>today</span></div>
          <div class="ar-wd-stat"><strong>{{ totals.week }}</strong><span>7 days</span></div>
          <div class="ar-wd-stat"><strong>{{ totals.month }}</strong><span>{{ data.window || 30 }} days</span></div>
          <div class="ar-wd-stat"><strong>{{ totals.agents }}</strong><span>clients</span></div>
        </div>

        <div class="ar-act-sparkwrap" ref="sparkWrap">
          <!-- On phones the chart keeps its bars touch-wide and scrolls sideways
               (opening at the newest days) instead of squeezing 30 targets into
               one screen; wider screens fit as before. -->
          <div class="ar-act-sparkscroll">
          <div class="ar-act-spark" role="group" aria-label="Hits per day — select a day to open its report" @mouseleave="hideTip">
            <button
              v-for="(d, i) in daily"
              :key="i"
              type="button"
              class="ar-act-bar"
              :class="{ 'is-active': selectedDay === d.date }"
              :aria-label="barAria(d)"
              @click="selectDay(d)"
              @mouseenter="showTip(d, $event)"
              @focus="showTip(d, $event)"
              @blur="hideTip"
            >
              <span class="ar-act-bar__fill" :class="{ 'is-zero': d.hits === 0 }" :style="{ height: barHeight(d.hits) }"></span>
            </button>
          </div>
          </div>
          <transition name="ar-tip">
            <div
              v-if="tip.show && tip.day"
              ref="tipEl"
              class="ar-act-tip"
              :style="{ transform: 'translateX(calc(' + tip.x + 'px - 50%))' }"
              role="tooltip"
              aria-hidden="true"
            >
              <span class="ar-act-tip__date">{{ dateLabel(tip.day.date) }}</span>
              <span class="ar-act-tip__hits">{{ tip.day.hits }} {{ tip.day.hits === 1 ? 'hit' : 'hits' }}</span>
              <span v-if="tip.day.hits" class="ar-act-tip__top">{{ topClientsText(tip.day) }}</span>
              <span class="ar-act-tip__caret" :style="{ left: tip.caret + 'px' }"></span>
            </div>
          </transition>
        </div>
        <p class="ar-act-sparkcap">Hits per day · last {{ daily.length }} days · click a bar for that day's report</p>
      </section>

      <!-- Overall breakdown (whole window — static). Two free-standing cards,
           direct children of the .ar-act grid so its gap separates them;
           endpoints first (what was fetched says more than who fetched it). -->
      <section class="ar-card">
        <h2 class="ar-card__title">By endpoint <span class="ar-card__tag">Last {{ data.window || 30 }} days</span></h2>
        <!-- The arrow claim must match what trend_pct actually computes: the
             two HALVES of this window, not this window vs the one before. -->
        <p class="ar-card__lead">
          What gets read — hits for each of your agent-facing endpoints, busiest first. The arrow
          compares the recent {{ trendHalfDays }} days with the {{ trendHalfDays }} before; hover a
          row for the numbers behind it.
        </p>
        <!-- Each row drills into the Request Log, pre-filtered to its endpoint —
             every hit behind the number, with client and verdict. -->
        <ul v-if="byEndpoint.length" class="ar-act-rank ar-act-rank--trend">
          <li v-for="e in byEndpoint" :key="e.label">
            <button
              type="button"
              class="ar-act-rank__btn"
              :aria-label="`Open the Request Log filtered to ${e.label}`"
              @click="$emit('navigate', { tab: 'log', log: { endpoint: e.label } })"
              @mouseenter="showUaTip($event, trendTip(e), 'Click for every request', 'cursor')"
              @mouseleave="hideUaTip"
            >
              <span class="ar-act-rank__label"><code>{{ e.label }}</code></span>
              <span class="ar-act-delta" :class="'is-' + e.delta.dir">{{ e.delta.label }}</span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(e.hits, maxEndpoint) }"></span></span>
              <span class="ar-act-rank__n">{{ e.hits }}</span>
            </button>
          </li>
        </ul>
        <p v-else class="ar-wd-empty">No hits yet.</p>
      </section>

      <section class="ar-card">
        <h2 class="ar-card__title">Top clients <span class="ar-card__tag">Last {{ data.window || 30 }} days</span></h2>
        <p class="ar-card__lead">
          Who does the reading — the clients behind those hits: AI agents, crawlers and browsers,
          busiest first. Names are what each client declares about itself.
        </p>
        <!-- Same drill-down: the Request Log filtered to this client — what one
             bot actually fetches, the intersection that screen was built for. -->
        <ul v-if="byAgent.length" class="ar-act-rank ar-act-rank--trend">
          <li v-for="a in byAgent" :key="a.label">
            <button
              type="button"
              class="ar-act-rank__btn"
              :aria-label="`Open the Request Log filtered to ${a.label}`"
              @click="$emit('navigate', { tab: 'log', log: { agent: a.label } })"
              @mouseenter="showUaTip($event, trendTip(a), 'Click for every request', 'cursor')"
              @mouseleave="hideUaTip"
            >
              <span class="ar-act-rank__label">{{ a.label }}</span>
              <span class="ar-act-delta" :class="'is-' + a.delta.dir">{{ a.delta.label }}</span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(a.hits, maxAgent) }"></span></span>
              <span class="ar-act-rank__n">{{ a.hits }}</span>
            </button>
          </li>
        </ul>
        <p v-else class="ar-wd-empty">No hits yet.</p>
      </section>

      <!-- Traffic from AI — one report card: magnitude (KPIs), composition
           (top sources + pages), and the timeline drill-down (which source →
           which page, by day). All from the same aggregate-by-day store. -->
      <section v-if="referrals" class="ar-card ar-ai">
        <h2 class="ar-card__title">Traffic from AI <span class="ar-card__tag">Last {{ data.window || 30 }} days</span></h2>
        <!-- The floor caveat earns its space on a summary card; the longer CDN-mode
             explanation belongs with the full report, which is where it lives. -->
        <p class="ar-card__lead">
          Real visitors who arrived from an AI assistant (ChatGPT, Perplexity, Gemini…). Counted on your
          own site — no IP, nothing sent anywhere. Some AI visits can’t be detected, so read this as a
          floor: at least this many.
        </p>

        <div class="ar-wd-stats ar-act-stats ar-act-stats--3">
          <div class="ar-wd-stat"><strong>{{ refTotals.today }}</strong><span>today</span></div>
          <div class="ar-wd-stat"><strong>{{ refTotals.window }}</strong><span>{{ data.window || 30 }} days</span></div>
          <div class="ar-wd-stat"><strong>{{ refSourceCount }}</strong><span>sources</span></div>
        </div>

        <template v-if="refTotals.window">
          <!-- Shape of the month. A read-only sparkline, not the 30-row list that used to
               live here: anything whose height grows with the window belongs on the AI
               traffic screen, not on a summary card. -->
          <div ref="refSparkWrap" class="ar-act-sparkwrap ar-act-sparkwrap--ref">
            <!-- Same phone treatment as the endpoint chart: touch-wide bars in a
                 sideways scroller anchored at the newest days. -->
            <div class="ar-act-sparkscroll">
            <div class="ar-refspark" role="group" :aria-label="refSparkAria" @mouseleave="hideRefTip">
              <button
                v-for="(d, i) in refSpark"
                :key="i"
                type="button"
                class="ar-refspark__bar"
                :class="{ 'is-zero': !d.hits, 'is-active': aiDay.open && aiDay.date === d.date }"
                :aria-label="`${dateLabel(d.date)}, ${d.hits} ${d.hits === 1 ? 'visit' : 'visits'}${d.hits ? ' — open this day' : ''}`"
                :style="{ height: pct(d.hits, refSparkMax) }"
                @click="openAiDay(d)"
                @mouseenter="showRefTip(d, $event)"
                @focus="showRefTip(d, $event)"
                @blur="hideRefTip"
              ></button>
            </div>
            </div>
            <transition name="ar-tip">
              <div
                v-if="refTip.show && refTip.day"
                ref="refTipEl"
                class="ar-act-tip"
                :style="{ transform: 'translateX(calc(' + refTip.x + 'px - 50%))' }"
                role="tooltip"
                aria-hidden="true"
              >
                <span class="ar-act-tip__date">{{ dateLabel(refTip.day.date) }}</span>
                <span class="ar-act-tip__hits">{{ refTip.day.hits }} {{ refTip.day.hits === 1 ? 'visit' : 'visits' }}</span>
                <span class="ar-act-tip__caret" :style="{ left: refTip.caret + 'px' }"></span>
              </div>
            </transition>
          </div>
          <p class="ar-act-sparkcap">Visits per day · last {{ refSpark.length }} days · click a bar for that day’s report</p>

          <!-- Composition: who sent traffic, and where it landed. Top 5 of each;
               each row drills into the full report pre-filtered to itself. -->
          <div class="ar-ai__cols">
            <div class="ar-ai__col">
              <h3 class="ar-ai__sub">Top sources</h3>
              <ul class="ar-act-rank">
                <li v-for="s in refTopSources" :key="s.label">
                  <button
                    type="button"
                    class="ar-act-rank__btn"
                    :aria-label="`Open the AI traffic report filtered to ${s.label}`"
                    @click="$emit('navigate', { tab: 'ai-traffic', ai: { source: s.label } })"
                    @mouseenter="showUaTip($event, refShareTip(s.hits), 'Click for the full report', 'cursor')"
                    @mouseleave="hideUaTip"
                  >
                    <span class="ar-act-rank__label">{{ s.label }}</span>
                    <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(s.hits, listMax(refTopSources)) }"></span></span>
                    <span class="ar-act-rank__n">{{ s.hits }}</span>
                  </button>
                </li>
              </ul>
            </div>
            <div class="ar-ai__col">
              <h3 class="ar-ai__sub">Top landing pages</h3>
              <ul v-if="refTopPages.length" class="ar-act-rank">
                <li v-for="p in refTopPages" :key="p.path">
                  <button
                    type="button"
                    class="ar-act-rank__btn"
                    :aria-label="`Open the AI traffic report filtered to ${p.path}`"
                    @click="$emit('navigate', { tab: 'ai-traffic', ai: { path: p.path } })"
                    @mouseenter="showUaTip($event, refShareTip(p.hits), 'Click for the full report', 'cursor')"
                    @mouseleave="hideUaTip"
                  >
                    <span class="ar-act-rank__label"><code>{{ p.path }}</code></span>
                    <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(p.hits, listMax(refTopPages)) }"></span></span>
                    <span class="ar-act-rank__n">{{ p.hits }}</span>
                  </button>
                </li>
              </ul>
              <p v-else class="ar-wd-empty">No pages yet.</p>
            </div>
          </div>
        </template>

        <p v-else class="ar-wd-empty">
          No AI-referred visits recorded yet. When someone arrives from ChatGPT, Perplexity and the like,
          it’ll show here.
        </p>

        <p class="ar-card__more">
          <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'ai-traffic' })">
            See the full report
            <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg>
          </button>
          <span class="ar-card__morenote">day-by-day visits, every source and landing page{{ unknownOn ? ', and what we couldn’t attribute' : '' }}</span>
        </p>
      </section>

      <!-- Recent requests (latest, live — static) -->
      <section class="ar-card">
        <h2 class="ar-card__title">
          Recent requests
          <span v-if="recent.length" class="ar-card__tag">Latest {{ recent.length }}</span>
        </h2>
        <p class="ar-card__lead">
          Latest activity across all days. Identified from the User-Agent — major AI crawlers self-identify;
          scripts and anonymous clients may not. Repeat hits are grouped. Your own logged-in visits aren't recorded.
        </p>
        <div v-if="recentGrouped.length" class="ar-act-feedwrap">
          <div ref="feedScroll" class="ar-act-reqs" @scroll="onFeedScroll">
            <table class="ar-act-table">
              <thead>
                <tr>
                  <th scope="col">Agent</th>
                  <th scope="col">Endpoint</th>
                  <th v-if="hasNetwork" scope="col">Network</th>
                  <th scope="col">User-Agent</th>
                  <th scope="col">Hits</th>
                  <th scope="col">Seen</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(r, i) in recentGrouped" :key="i">
                  <td class="ar-act-table__agent">{{ r.agent }}</td>
                  <td><code class="ar-act-feed__ep">{{ r.endpoint }}</code></td>
                  <td v-if="hasNetwork">
                    <span
                      v-if="r.network"
                      class="ar-act-feed__net"
                      :class="{ 'is-unverified': netUnverified(r) }"
                      @mouseenter="netUnverified(r) && showNetHint($event)"
                      @mouseleave="hideNetHint"
                    >{{ r.network }}<span v-if="netUnverified(r)" class="ar-act-feed__net-mark" aria-label="unverified">?</span></span>
                    <span v-else class="ar-act-table__dash" aria-label="not identified">—</span>
                  </td>
                  <td class="ar-act-table__uacol">
                    <code v-if="r.ua" class="ar-act-feed__ua is-copyable" :aria-label="r.ua" @mouseenter="showUaTip($event, r.ua)" @mouseleave="hideUaTip" @click.stop="copyUa(r.ua)">{{ r.ua }}</code>
                    <span v-else class="ar-act-feed__ua is-empty">no User-Agent</span>
                  </td>
                  <td><span class="ar-act-feed__count" :title="r.count > 1 ? `${r.count} hits` : null">{{ r.count > 1 ? '×' + r.count : '' }}</span></td>
                  <td class="ar-act-table__at">{{ ago(r.at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="ar-act-feedfade" :class="{ 'is-visible': feedMore }">
            <button
              type="button"
              class="ar-act-feedfade__btn"
              :disabled="!feedMore"
              aria-label="Scroll down for more requests"
              @click="scrollFeed"
            >
              <svg viewBox="0 0 16 16" class="ar-act-feedfade__chev" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
            </button>
          </div>
        </div>
        <p v-else class="ar-wd-empty">
          No requests recorded yet. Agents that fetch your discovery/llms endpoints will appear here.
        </p>

        <!-- The same relationship the card above has to the AI traffic screen: this is a
             capped, unfiltered slice of what the Request log holds in full. Naming the
             destination rather than reusing "See the full report" — two links to two
             different screens must not read as the same link. -->
        <p class="ar-card__more">
          <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'log' })">
            Open the Request Log
            <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg>
          </button>
          <span class="ar-card__morenote">every request, filterable by client, endpoint, network and date</span>
        </p>
      </section>
    </template>

    <!-- Day report: a focused overlay; the page behind never reflows. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <!-- No backdrop-click close (Heera, 2026-07-13): a stray click while
             scanning a day's rows would silently drop the report. Esc and the
             Close button remain the two deliberate ways out. -->
        <div v-if="dayModal.open" class="ar-modal">
          <div
            ref="dayDialog"
            class="ar-modal__panel ar-modal__panel--day"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-day-title"
            tabindex="-1"
            @keydown.esc="closeDayModal"
            @keydown.left="stepDay(-1)"
            @keydown.right="stepDay(1)"
          >
            <div class="ar-modal__head">
              <div class="ar-day-head">
                <h2 id="ar-day-title" class="ar-modal__title">{{ dateLabel(dayModal.date) }}</h2>
                <div class="ar-day-nav" role="group" aria-label="Switch day">
                  <button type="button" class="ar-day-nav__btn" :disabled="!dayHasPrev || dayModal.loading" aria-label="Previous day with activity" @click="stepDay(-1)">
                    <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 4l-4 4 4 4" /></svg>
                  </button>
                  <SelectMenu
                    class="ar-day-picker"
                    :model-value="dayModal.date"
                    :options="dayOptions"
                    aria-label="Jump to a day with activity"
                    @update:model-value="loadDay"
                  />
                  <button type="button" class="ar-day-nav__btn" :disabled="!dayHasNext || dayModal.loading" aria-label="Next day with activity" @click="stepDay(1)">
                    <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg>
                  </button>
                </div>
              </div>
              <p class="ar-modal__lead">
                Everything on this day — who hit you, what they hit, and every individual request (exact times, UTC).
              </p>
            </div>

            <div class="ar-modal__body">
              <div ref="dayScroll" class="ar-modal__scroll" :class="{ 'is-loading': dayModal.loading }" @scroll="onDayScroll">
                <!-- Breakdown (instant, from the chart data) — but deliberately held
                     back until the request rows land too: half the report appearing
                     ahead of the other half read as inconsistent (Heera, 2026-07-13).
                     One loading state, then the whole day at once. -->
                <div v-if="modalDetail && !dayModal.loading" class="ar-daybreak">
                  <div class="ar-daybreak__col">
                    <h3 class="ar-daybreak__h">By client <span class="ar-daybreak__n">{{ modalDetail.clientCount }}</span></h3>
                    <ul class="ar-act-rank">
                      <li v-for="a in shownClients" :key="a.label">
                        <span class="ar-act-rank__label">{{ a.label }}</span>
                        <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(a.hits, listMax(shownClients)) }"></span></span>
                        <span class="ar-act-rank__n">{{ a.hits }}</span>
                      </li>
                    </ul>
                    <button v-if="modalDetail.clientCount > modalDetail.clients.length" type="button" class="ar-act-more" :aria-expanded="dayExpand.clients" @click="dayExpand.clients = !dayExpand.clients">
                      <span>{{ dayExpand.clients ? 'Show less' : '+' + (modalDetail.clientCount - modalDetail.clients.length) + ' more' }}</span>
                      <span class="ar-act-more__caret" aria-hidden="true">{{ dayExpand.clients ? '▴' : '▾' }}</span>
                    </button>
                    <p v-if="dayExpand.clients && dayModal.capped" class="ar-act-more-note">from the last {{ dayModal.rows.length }} requests this day</p>
                  </div>
                  <div class="ar-daybreak__col">
                    <h3 class="ar-daybreak__h">By endpoint <span class="ar-daybreak__n">{{ modalDetail.endpointCount }}</span></h3>
                    <ul class="ar-act-rank">
                      <li v-for="e in shownEndpoints" :key="e.label">
                        <span class="ar-act-rank__label"><code>{{ e.label }}</code></span>
                        <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(e.hits, listMax(shownEndpoints)) }"></span></span>
                        <span class="ar-act-rank__n">{{ e.hits }}</span>
                      </li>
                    </ul>
                    <button v-if="modalDetail.endpointCount > modalDetail.endpoints.length" type="button" class="ar-act-more" :aria-expanded="dayExpand.endpoints" @click="dayExpand.endpoints = !dayExpand.endpoints">
                      <span>{{ dayExpand.endpoints ? 'Show less' : '+' + (modalDetail.endpointCount - modalDetail.endpoints.length) + ' more' }}</span>
                      <span class="ar-act-more__caret" aria-hidden="true">{{ dayExpand.endpoints ? '▴' : '▾' }}</span>
                    </button>
                    <p v-if="dayExpand.endpoints && dayModal.capped" class="ar-act-more-note">from the last {{ dayModal.rows.length }} requests this day</p>
                  </div>
                </div>

                <!-- Full request log (hidden with the breakdown while loading) -->
                <div v-if="!dayModal.loading" class="ar-daylog">
                  <h3 class="ar-daybreak__h">
                    Requests
                    <span v-if="!dayModal.error" class="ar-daybreak__n">
                      {{ dayModal.total }}<template v-if="dayModal.capped"> · recent {{ dayModal.rows.length }}</template>
                    </span>
                  </h3>
                  <p v-if="dayModal.error" class="ar-act-log__state is-error">{{ dayModal.error }}</p>
                  <ul v-else-if="dayModal.rows.length" class="ar-act-log">
                    <li v-for="(r, i) in dayModal.rows" :key="i">
                      <span class="ar-act-feed__agent">{{ r.agent }}</span>
                      <span class="ar-act-feed__loc">
                        <code class="ar-act-feed__ep">{{ r.endpoint }}</code>
                        <span
                          v-if="r.network"
                          class="ar-act-feed__net"
                          :class="{ 'is-unverified': netUnverified(r) }"
                          @mouseenter="netUnverified(r) && showNetHint($event)"
                          @mouseleave="hideNetHint"
                        ><span class="ar-act-feed__net-lbl">from</span> {{ r.network }}<span v-if="netUnverified(r)" class="ar-act-feed__net-mark" aria-label="unverified">?</span></span>
                      </span>
                      <code v-if="r.ua" class="ar-act-feed__ua is-copyable" :aria-label="r.ua" @mouseenter="showUaTip($event, r.ua)" @mouseleave="hideUaTip" @click.stop="copyUa(r.ua)">{{ r.ua }}</code>
                      <span v-else class="ar-act-feed__ua is-empty">no User-Agent</span>
                      <span class="ar-act-log__at" :title="exactStamp(r.at)">{{ exactTime(r.at) }}</span>
                    </li>
                  </ul>
                  <p v-else class="ar-act-log__state">No requests recorded on this day.</p>
                </div>
              </div>
              <!-- Day-switch feedback that cannot be missed: a centered spinner over
                   the dimmed body. (A quiet header chip was tried first and went
                   unnoticed — the state has to live where the user is looking.) -->
              <div v-if="dayModal.loading" class="ar-day-loadover" role="status">
                <span class="ar-spinner" aria-hidden="true"></span>
                <span class="ar-day-loadover__label">Loading…</span>
              </div>
              <div class="ar-modal__fade" :class="{ 'is-visible': dayScrollMore }">
                <button type="button" class="ar-modal__fade-btn" :disabled="!dayScrollMore" aria-label="Scroll down for more" @click="scrollDayBody">
                  <svg viewBox="0 0 16 16" class="ar-modal__chev" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
                </button>
              </div>
            </div>

            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" @click="closeDayModal">Close</button>
            </div>
          </div>
        </div>
      </transition>

      <!-- The Traffic-from-AI day report — dayModal's twin, same conventions:
           fixed size, spinner-until-loaded, Esc/Close only (no backdrop close). -->
      <transition name="ar-modal">
        <div v-if="aiDay.open" class="ar-modal">
          <div
            ref="aiDayDialog"
            class="ar-modal__panel ar-modal__panel--day"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-aiday-title"
            tabindex="-1"
            @keydown.esc="closeAiDay"
            @keydown.left="stepAiDay(-1)"
            @keydown.right="stepAiDay(1)"
          >
            <div class="ar-modal__head">
              <div class="ar-day-head">
                <h2 id="ar-aiday-title" class="ar-modal__title">{{ dateLabel(aiDay.date) }}</h2>
                <div class="ar-day-nav" role="group" aria-label="Switch day">
                  <button type="button" class="ar-day-nav__btn" :disabled="!aiDayHasPrev || aiDay.loading" aria-label="Previous day with AI visits" @click="stepAiDay(-1)">
                    <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 4l-4 4 4 4" /></svg>
                  </button>
                  <SelectMenu
                    class="ar-day-picker"
                    :model-value="aiDay.date"
                    :options="aiDayOptions"
                    aria-label="Jump to a day with AI visits"
                    @update:model-value="loadAiDay"
                  />
                  <button type="button" class="ar-day-nav__btn" :disabled="!aiDayHasNext || aiDay.loading" aria-label="Next day with AI visits" @click="stepAiDay(1)">
                    <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg>
                  </button>
                </div>
              </div>
              <p class="ar-modal__lead">
                AI-referred visits on this day — which assistant sent them, and which page they landed on.
                The day is the finest “when” stored, so there are no times.
              </p>
            </div>

            <div class="ar-modal__body">
              <div ref="aiDayScroll" class="ar-modal__scroll" :class="{ 'is-loading': aiDay.loading }" @scroll="onAiDayScroll">
                <div v-if="!aiDay.loading" class="ar-daylog ar-daylog--solo">
                  <h3 class="ar-daybreak__h">
                    Visits
                    <span v-if="!aiDay.error" class="ar-daybreak__n">{{ aiDay.hits }}</span>
                  </h3>
                  <p v-if="aiDay.error" class="ar-act-log__state is-error">{{ aiDay.error }}</p>
                  <ul v-else-if="aiDay.rows.length" class="ar-aiday__detail ar-aiday__detail--modal">
                    <li v-for="(r, i) in aiDay.rows" :key="i" class="ar-aivis">
                      <span class="ar-aivis__src">{{ r.source }}</span>
                      <span class="ar-aivis__arr" aria-hidden="true">→</span>
                      <code
                        class="ar-aivis__path is-copyable"
                        :aria-label="r.path"
                        @mouseenter="showUaTip($event, r.path)"
                        @mouseleave="hideUaTip"
                        @click.stop="copyVal(r.path, 'Path')"
                      >{{ r.path }}</code>
                      <span class="ar-aivis__n">{{ r.hits }}</span>
                    </li>
                    <li v-if="aiDay.capped" class="ar-aivis ar-aivis--muted">
                      Showing the {{ aiDay.rows.length }} busiest of {{ aiDay.rowCount }} pairings.
                    </li>
                  </ul>
                  <p v-else class="ar-act-log__state">No AI-referred visits recorded on this day.</p>
                </div>
              </div>
              <div v-if="aiDay.loading" class="ar-day-loadover" role="status">
                <span class="ar-spinner" aria-hidden="true"></span>
                <span class="ar-day-loadover__label">Loading…</span>
              </div>
              <div class="ar-modal__fade" :class="{ 'is-visible': aiDayScrollMore }">
                <button type="button" class="ar-modal__fade-btn" :disabled="!aiDayScrollMore" aria-label="Scroll down for more" @click="scrollAiDayBody">
                  <svg viewBox="0 0 16 16" class="ar-modal__chev" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
                </button>
              </div>
            </div>

            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" @click="closeAiDay">Close</button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <!-- Styled hover tooltip for long values (User-Agent). Teleported to <body>: the
         panels animate (.ar__body > * { animation: ar-rise }), and a transform makes an
         element a CONTAINING BLOCK for position:fixed — so a fixed tooltip left inside a
         panel is anchored to the panel, not the viewport, and lands off-screen. At body
         level it is viewport-anchored and never clipped by the scrolling feed / modal. -->
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

    <!-- Same styled bubble as the UA tooltip, for the plain-language unverified-network
         hint. The --info modifier drops the mono font for readable prose. -->
    <Teleport to="body">
      <transition name="ar-tip">
        <div
          v-if="netHint.show"
          ref="netHintEl"
          class="ar-act-uatip ar-act-uatip--info"
          :class="{ 'is-below': netHint.below }"
          :style="{ left: netHint.x + 'px', top: netHint.y + 'px' }"
          role="tooltip"
          aria-hidden="true"
        >{{ netTip }}<span class="ar-act-uatip__caret" :style="{ left: netHint.caret + 'px' }"></span></div>
      </transition>
    </Teleport>
  </div>
</template>
