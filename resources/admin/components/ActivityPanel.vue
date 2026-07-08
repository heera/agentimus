<script>
import { confirm } from '../confirm.js';
import SelectMenu from './SelectMenu.vue';

export default {
  name: 'ActivityPanel',
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
      // The per-day report modal. The page itself never reflows; clicking a bar
      // opens this focused overlay with the day's breakdown + full request log.
      dayModal: { open: false, loading: false, error: '', date: '', total: 0, rows: [], capped: false },
      dayScrollMore: false,
      // Whether each breakdown column is unfolded past its top-N summary (per day).
      dayExpand: { clients: false, endpoints: false },
      // Styled hover tooltip above the bars.
      tip: { show: false, day: null, x: 0, caret: 0 },
      // Styled hover tooltip for long inline values (a full User-Agent). position:fixed
      // so the scrolling request feed / day modal never clips it — replaces the native
      // title="…" bubble with the same dark look as the chart tooltip.
      uaTip: { show: false, text: '', x: 0, y: 0, below: false },
      // Which "AI visits by day" rows are expanded to show their source → page rows.
      refOpenDays: [],
    };
  },
  mounted() {
    window.addEventListener('resize', this.updateFeedHint);
    this.$nextTick(this.updateFeedHint);
  },
  beforeUnmount() {
    window.removeEventListener('resize', this.updateFeedHint);
  },
  watch: {
    recentGrouped() {
      this.$nextTick(this.updateFeedHint);
    },
  },
  computed: {
    totals() {
      return this.data.totals || { today: 0, week: 0, month: 0, all: 0, agents: 0 };
    },
    daily() {
      return this.data.daily || [];
    },
    byAgent() {
      return this.data.byAgent || [];
    },
    byEndpoint() {
      return this.data.byEndpoint || [];
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
    // source → page rows. The day is the finest "when" the store keeps.
    refDaily() {
      return (this.referrals && this.referrals.daily) || [];
    },
    refDailyMax() {
      return Math.max(1, ...this.refDaily.map((d) => d.hits));
    },
    recent() {
      return this.data.recent || [];
    },
    recentGrouped() {
      const groups = new Map();
      for (const r of this.recent) {
        const key = `${r.agent}|${r.endpoint}|${r.ua || ''}`;
        const g = groups.get(key);
        if (g) { g.count += 1; if (!g.network && r.network) g.network = r.network; }
        else groups.set(key, { agent: r.agent, endpoint: r.endpoint, ua: r.ua, network: r.network || '', at: r.at, count: 1 });
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
  },
  methods: {
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
        ? `${d.date}: ${d.hits} ${noun}. Select to open this day's report.`
        : `${d.date}: no hits.`;
    },
    // ---- Day-report modal (the only thing a bar click changes) -----------------
    selectDay(d) {
      if (d.hits) this.openDay(d.date);
    },
    openDay(date) {
      this.loadDay(date);
      this.$nextTick(() => {
        const el = this.$refs.dayDialog;
        if (el) el.focus();
      });
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
        this.$nextTick(() => {
          const el = this.$refs.dayScroll;
          if (el) el.scrollTop = 0;
          this.updateDayScrollHint();
        });
      }
    },
    closeDayModal() {
      this.dayModal.open = false;
    },
    stepDay(delta) {
      const i = this.dayNavIndex;
      if (i < 0) return;
      const next = this.hitDays[i + delta];
      if (next) this.loadDay(next.date);
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
    // Show/hide the styled tooltip for a long value (User-Agent). Positioned in the
    // fixed viewport off the hovered cell's rect, so it escapes the feed's scroll clip.
    showUaTip(ev, text) {
      if (!text) return;
      const rect = ev.currentTarget.getBoundingClientRect();
      const below = rect.top < 96; // not enough room above → drop below.
      this.uaTip = {
        show: true,
        text,
        x: Math.min(Math.max(rect.left, 12), window.innerWidth - 372),
        y: below ? rect.bottom + 8 : rect.top - 8,
        below,
      };
    },
    hideUaTip() {
      this.uaTip.show = false;
    },
    // Click a truncated value (the User-Agent) to copy the WHOLE string — the cell only
    // shows an ellipsis, so this is the way to grab the full text. Clipboard API where
    // available, with the legacy textarea fallback for plain-HTTP (non-secure) sites.
    async copyUa(text) {
      return this.copyVal(text, 'User-Agent');
    },
    async copyVal(text, label) {
      if (!text) return;
      let ok = false;
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
          ok = true;
        }
      } catch (e) { /* fall through to the legacy path */ }
      if (!ok) ok = this.legacyCopy(text);
      this.$emit('flash', ok ? 'success' : 'error', ok ? `${label} copied.` : 'Could not copy — select the text and copy manually.');
    },
    legacyCopy(text) {
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
      } catch (e) {
        return false;
      }
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
    // ---- Formatting ------------------------------------------------------------
    listMax(list) {
      return Math.max(1, ...(list || []).map((x) => x.hits));
    },
    pct(hits, max) {
      return `${Math.max(2, Math.round((hits / max) * 100))}%`;
    },
    dateLabel(date) {
      const dt = new Date(`${date}T00:00:00Z`);
      if (Number.isNaN(dt.getTime())) return date;
      return dt.toLocaleDateString(undefined, {
        timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric',
      });
    },
    exactTime(iso) {
      const dt = new Date(iso);
      if (Number.isNaN(dt.getTime())) return '';
      return dt.toLocaleTimeString('en-GB', {
        timeZone: 'UTC', hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit',
      });
    },
    exactStamp(iso) {
      const dt = new Date(iso);
      if (Number.isNaN(dt.getTime())) return iso;
      return `${dt.toLocaleString('en-GB', {
        timeZone: 'UTC', hour12: false, year: 'numeric', month: 'short', day: '2-digit',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
      })} UTC`;
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
    // ---- AI visits by day ------------------------------------------------------
    toggleRefDay(date) {
      const i = this.refOpenDays.indexOf(date);
      if (i === -1) this.refOpenDays.push(date);
      else this.refOpenDays.splice(i, 1);
    },
    refDayOpen(date) {
      return this.refOpenDays.includes(date);
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
    <div v-if="summary" class="ar-dash-sum">
      <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'readiness' })">
        <span class="ar-dash-tile__k">Readiness</span>
        <strong class="ar-dash-tile__v" :data-tone="summary.tone">{{ summary.readiness.pass }}/{{ summary.readiness.total }}</strong>
        <span class="ar-dash-tile__sub">{{ summary.readiness.pct }}% pass</span>
      </button>
      <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-providers' })">
        <span class="ar-dash-tile__k">Providers</span>
        <strong class="ar-dash-tile__v">{{ summary.providers }}</strong>
        <span class="ar-dash-tile__sub">sources describing your site</span>
      </button>
      <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-providers' })">
        <span class="ar-dash-tile__k">Capabilities</span>
        <strong class="ar-dash-tile__v">{{ summary.capabilities }}</strong>
        <span class="ar-dash-tile__sub">what agents can do or read</span>
      </button>
      <button type="button" class="ar-dash-tile" @click="$emit('navigate', { tab: 'discovery', anchor: 'ar-wd-tools' })">
        <span class="ar-dash-tile__k">Tools</span>
        <strong class="ar-dash-tile__v">{{ summary.tools }}</strong>
        <span class="ar-dash-tile__sub">actions agents can run</span>
      </button>
    </div>

    <!-- Quiet privacy framing so the dashboard reads as informational, not surveillance. -->
    <p class="ar-dash-note">
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
        <div class="ar-card__head">
          <div>
            <div class="ar-act-titlerow">
              <h2 class="ar-card__title">Endpoint activity</h2>
              <span v-if="live" class="ar-act-live" title="Auto-refresh is on — these stats update on their own. Refresh forces an update now.">
                <span class="ar-act-live__dot" aria-hidden="true"></span>Auto-refresh
              </span>
            </div>
            <p class="ar-card__lead">
              Who fetched your discovery &amp; llms endpoints — AI agents, crawlers and browsers.
              Local-only, no IP logged. Records are kept for the last {{ data.window || 30 }} days, then removed.
            </p>
          </div>
          <div class="ar-act-controls">
            <button type="button" class="ar-btn ar-btn--ghost" :disabled="refreshing" @click="$emit('refresh')">
              {{ refreshing ? 'Refreshing…' : 'Refresh' }}
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
          <transition name="ar-tip">
            <div
              v-if="tip.show && tip.day"
              ref="tipEl"
              class="ar-act-tip"
              :style="{ transform: 'translateX(calc(' + tip.x + 'px - 50%))' }"
              role="tooltip"
              aria-hidden="true"
            >
              <span class="ar-act-tip__date">{{ tip.day.date }}</span>
              <span class="ar-act-tip__hits">{{ tip.day.hits }} {{ tip.day.hits === 1 ? 'hit' : 'hits' }}</span>
              <span v-if="tip.day.hits" class="ar-act-tip__top">{{ topClientsText(tip.day) }}</span>
              <span class="ar-act-tip__caret" :style="{ left: tip.caret + 'px' }"></span>
            </div>
          </transition>
        </div>
        <p class="ar-act-sparkcap">Hits per day · last {{ daily.length }} days · click a bar for that day's report</p>
      </section>

      <!-- Overall breakdown (whole window — static) -->
      <div class="ar-wd-cols">
        <section class="ar-card">
          <h2 class="ar-card__title">Top clients <span class="ar-card__tag">Last {{ data.window || 30 }} days</span></h2>
          <ul v-if="byAgent.length" class="ar-act-rank">
            <li v-for="a in byAgent" :key="a.label">
              <span class="ar-act-rank__label">{{ a.label }}</span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(a.hits, maxAgent) }"></span></span>
              <span class="ar-act-rank__n">{{ a.hits }}</span>
            </li>
          </ul>
          <p v-else class="ar-wd-empty">No hits yet.</p>
        </section>

        <section class="ar-card">
          <h2 class="ar-card__title">By endpoint <span class="ar-card__tag">Last {{ data.window || 30 }} days</span></h2>
          <ul v-if="byEndpoint.length" class="ar-act-rank">
            <li v-for="e in byEndpoint" :key="e.label">
              <span class="ar-act-rank__label"><code>{{ e.label }}</code></span>
              <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(e.hits, maxEndpoint) }"></span></span>
              <span class="ar-act-rank__n">{{ e.hits }}</span>
            </li>
          </ul>
          <p v-else class="ar-wd-empty">No hits yet.</p>
        </section>
      </div>

      <!-- Traffic from AI — one report card: magnitude (KPIs), composition
           (top sources + pages), and the timeline drill-down (which source →
           which page, by day). All from the same aggregate-by-day store. -->
      <section v-if="referrals" class="ar-card ar-ai">
        <h2 class="ar-card__title">Traffic from AI <span class="ar-card__tag">Last {{ data.window || 30 }} days</span></h2>
        <p class="ar-card__lead">
          Real visitors who arrived from an AI assistant (ChatGPT, Perplexity, Gemini…). Counted on your
          own site — no IP, nothing sent anywhere. Some AI visits can’t be detected, so read this as a
          floor: at least this many.
        </p>
        <p v-if="referrals.beacon" class="ar-card__lead">
          <strong>CDN mode is on</strong> — these are counted in your visitors’ browsers so the number
          survives a full-page cache. Still first-party (no IP, nothing sent to third parties). A few
          visitors whose ad-blocker or privacy browser blocks the counting script won’t be included, so
          this stays a minimum — never an over-count.
        </p>

        <div class="ar-wd-stats ar-act-stats ar-act-stats--3">
          <div class="ar-wd-stat"><strong>{{ refTotals.today }}</strong><span>today</span></div>
          <div class="ar-wd-stat"><strong>{{ refTotals.window }}</strong><span>{{ data.window || 30 }} days</span></div>
          <div class="ar-wd-stat"><strong>{{ refSources.length }}</strong><span>sources</span></div>
        </div>

        <template v-if="refTotals.window">
          <!-- Composition: who sent traffic, and where it landed. -->
          <div class="ar-ai__cols">
            <div class="ar-ai__col">
              <h3 class="ar-ai__sub">Top sources</h3>
              <ul class="ar-act-rank">
                <li v-for="s in refSources" :key="s.label">
                  <span class="ar-act-rank__label">{{ s.label }}</span>
                  <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(s.hits, listMax(refSources)) }"></span></span>
                  <span class="ar-act-rank__n">{{ s.hits }}</span>
                </li>
              </ul>
            </div>
            <div class="ar-ai__col">
              <h3 class="ar-ai__sub">Top landing pages</h3>
              <ul v-if="refPages.length" class="ar-act-rank">
                <li v-for="p in refPages" :key="p.path">
                  <span class="ar-act-rank__label"><code>{{ p.path }}</code></span>
                  <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(p.hits, listMax(refPages)) }"></span></span>
                  <span class="ar-act-rank__n">{{ p.hits }}</span>
                </li>
              </ul>
              <p v-else class="ar-wd-empty">No pages yet.</p>
            </div>
          </div>

          <!-- Timeline: the same visits by day. Expand a day to see which source
               landed on which page — the day is the finest "when" stored. -->
          <div v-if="refDaily.length" class="ar-ai__byday">
            <h3 class="ar-ai__sub">By day <span class="ar-ai__subnote">click a day — which source → which page, no times stored</span></h3>
            <ul class="ar-aiday">
              <li v-for="d in refDaily" :key="d.date" class="ar-aiday__item">
                <button
                  type="button"
                  class="ar-aiday__row"
                  :aria-expanded="refDayOpen(d.date)"
                  :title="refDayOpen(d.date) ? 'Hide this day' : 'Show which source landed on which page'"
                  @click="toggleRefDay(d.date)"
                >
                  <span class="ar-aiday__date">{{ dateLabel(d.date) }}</span>
                  <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: pct(d.hits, refDailyMax) }"></span></span>
                  <span class="ar-aiday__n">{{ d.hits }}</span>
                  <svg class="ar-aiday__chev" :class="{ 'is-open': refDayOpen(d.date) }" viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
                </button>
                <ul v-show="refDayOpen(d.date)" class="ar-aiday__detail">
                  <li v-for="(r, i) in d.rows" :key="i" class="ar-aivis">
                    <span class="ar-aivis__src">{{ r.source }}</span>
                    <span class="ar-aivis__arr" aria-hidden="true">→</span>
                    <code class="ar-aivis__path">{{ r.path }}</code>
                    <span class="ar-aivis__n">{{ r.hits }}</span>
                  </li>
                  <li v-if="d.rowCount > d.rows.length" class="ar-act-more">+{{ d.rowCount - d.rows.length }} more</li>
                </ul>
              </li>
            </ul>
          </div>
        </template>

        <p v-else class="ar-wd-empty">
          No AI-referred visits recorded yet. When someone arrives from ChatGPT, Perplexity and the like,
          it’ll show here.
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
                    <span v-if="r.network" class="ar-act-feed__net">{{ r.network }}</span>
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
      </section>
    </template>

    <!-- Day report: a focused overlay; the page behind never reflows. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="dayModal.open" class="ar-modal" @click.self="closeDayModal">
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
              <div ref="dayScroll" class="ar-modal__scroll" @scroll="onDayScroll">
                <!-- Breakdown (instant, from the chart data) -->
                <div v-if="modalDetail" class="ar-daybreak">
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

                <!-- Full request log -->
                <div class="ar-daylog">
                  <h3 class="ar-daybreak__h">
                    Requests
                    <span v-if="!dayModal.loading && !dayModal.error" class="ar-daybreak__n">
                      {{ dayModal.total }}<template v-if="dayModal.capped"> · recent {{ dayModal.rows.length }}</template>
                    </span>
                  </h3>
                  <p v-if="dayModal.loading" class="ar-act-log__state">Loading…</p>
                  <p v-else-if="dayModal.error" class="ar-act-log__state is-error">{{ dayModal.error }}</p>
                  <ul v-else-if="dayModal.rows.length" class="ar-act-log">
                    <li v-for="(r, i) in dayModal.rows" :key="i">
                      <span class="ar-act-feed__agent">{{ r.agent }}</span>
                      <span class="ar-act-feed__loc">
                        <code class="ar-act-feed__ep">{{ r.endpoint }}</code>
                        <span v-if="r.network" class="ar-act-feed__net"><span class="ar-act-feed__net-lbl">from</span> {{ r.network }}</span>
                      </span>
                      <code v-if="r.ua" class="ar-act-feed__ua is-copyable" :aria-label="r.ua" @mouseenter="showUaTip($event, r.ua)" @mouseleave="hideUaTip" @click.stop="copyUa(r.ua)">{{ r.ua }}</code>
                      <span v-else class="ar-act-feed__ua is-empty">no User-Agent</span>
                      <span class="ar-act-log__at" :title="exactStamp(r.at)">{{ exactTime(r.at) }}</span>
                    </li>
                  </ul>
                  <p v-else class="ar-act-log__state">No requests recorded on this day.</p>
                </div>
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
          class="ar-act-uatip"
          :class="{ 'is-below': uaTip.below }"
          :style="{ left: uaTip.x + 'px', top: uaTip.y + 'px' }"
          role="tooltip"
          aria-hidden="true"
        ><span class="ar-act-uatip__ua">{{ uaTip.text }}</span><span class="ar-act-uatip__hint">Click to copy</span><span class="ar-act-uatip__caret"></span></div>
      </transition>
    </Teleport>
  </div>
</template>
