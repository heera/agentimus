<script>
import { formatDate } from '../wpDate.js';
import { groupChecks } from '../tiers.js';
import { bindDocEsc } from '../docEsc.js';
import { runAll, runExposureScan } from '../livecheck.js';
import { confirm } from '../confirm.js';
import SchemaPreview from './SchemaPreview.vue';

export default {
  name: 'ReadinessPanel',
  components: { SchemaPreview },
  props: {
    checks: { type: Array, default: () => [] },
    optimize: { type: Array, default: () => [] }, // Content worklist behind the Optimized rung.
    optimizeIgnored: { type: Array, default: () => [] }, // Pages set aside as "not cited content".
    optimizeGraded: { type: Number, default: 0 }, // How many pages were actually graded.
    refreshing: { type: Boolean, default: false },
    liveConfig: { type: Object, default: () => ({}) },
    isLocal: { type: Boolean, default: false },
    // Whether Readiness is the tab on screen. The panel stays MOUNTED across tab
    // switches (App uses v-show), so anything fetched once at mount goes stale —
    // connect a search source in Settings and this must notice on return.
    active: { type: Boolean, default: true },
    api: { type: Object, required: true },
  },
  emits: ['refresh', 'navigate', 'flash', 'score-updated'],
  data() {
    return {
      live: null, liveRunning: false, exposure: null, exposureRunning: false,
      schemaOpen: false, busyIgnore: 0, busyIssue: '', busyRestoreAll: false,
      // The scroll-affordance state for each dialog: true while more is below.
      liveMore: false, exposureMore: false,
      // Search Opportunities: null until the (lazy) fetch answers.
      search: null,
      searchPick: '', // The engine the owner chose; '' = let the server pick the richer one.
      busySearchIgnore: 0, // Post id mid-flight in the search worklist's own set-aside.
    };
  },
  computed: {
    // The same checks, grouped under the Findable → Readable → Trusted rungs.
    groups() {
      return groupChecks(this.checks);
    },
    // The whole report in one figure: the rung counts below, summed, with the dot
    // keyed to the worst rung — the header answers "overall?" before any scrolling.
    overall() {
      const pass = this.groups.reduce((n, g) => n + g.pass, 0);
      const total = this.groups.reduce((n, g) => n + g.total, 0);
      const status = ['fail', 'warn'].find((s) => this.groups.some((g) => g.status === s)) || 'pass';
      return { pass, total, status };
    },
    // Which story the Search Opportunities section tells, or '' to stay absent:
    // no source connected at all → absent (the Settings card is where connecting
    // lives); connected but no numbers yet → collecting; numbers but nothing to
    // fix → clear; otherwise the worklist.
    searchState() {
      if (!this.search) return '';
      const s = this.search.sources || {};
      const connected = (s.bing && s.bing.connected) || (s.google && s.google.connected);
      if (!connected) return '';
      if (!this.search.source) return 'collecting';
      if (this.searchOpps) return 'ready';
      // Rows exist but every one is too thin to judge — a different fact from
      // "nothing to fix", and saying the wrong one is a lie by compliment.
      const r = this.search.report;
      return (r && r.judged === false) ? 'thin' : 'clear';
    },
    // The section shows whenever there is anything to act on — a worklist, an
    // honest state, or set-aside pages waiting to be restored (a held-back page
    // must never become invisible just because today's report is empty).
    hasSearchSection() {
      return !!this.searchState || this.searchAside.length > 0;
    },
    searchCounts() {
      return (this.search && this.search.report && this.search.report.counts) || {};
    },
    // The search worklist's own held-back pages (never Optimize's).
    searchAside() {
      return (this.search && this.search.set_aside) || [];
    },
    // Pages the CONTENT worklist also flags, by id → the issue labels. Free:
    // the Optimize worklist is already a prop. When both lists point at one
    // page, saying so turns two separate chores into one visit.
    optimizeFlagsById() {
      const map = {};
      (this.optimize || []).forEach((issue) => {
        (issue.pages || []).forEach((p) => {
          if (!p || !p.id) return;
          if (!map[p.id]) map[p.id] = [];
          if (issue.label && map[p.id].indexOf(issue.label) === -1) map[p.id].push(issue.label);
        });
      });
      return map;
    },
    searchOpps() {
      return Number(this.searchCounts.opportunities || 0);
    },
    searchMedian() {
      return (this.search && this.search.report && this.search.report.median_ctr) || 0;
    },
    // 'thin' (not enough page-one traffic reported) and 'unclicked' (plenty, none
    // clicked) are different facts about a site — never print one for the other.
    searchMedianReason() {
      return (this.search && this.search.report && this.search.report.median_reason) || '';
    },
    // The bar actually applied, not the median it's derived from.
    searchCtrBar() {
      return (this.search && this.search.report && this.search.report.ctr_bar) || 0;
    },
    searchMedianRows() {
      return (this.search && this.search.report && this.search.report.median_rows) || 0;
    },
    searchMedianNeeds() {
      return (this.search && this.search.report && this.search.report.median_needs) || 5;
    },
    // Share of the reported views that were search-operator probes, not people.
    // Above the threshold it is the most important fact on the screen: it is why
    // this worklist looks empty while Search Performance shows big numbers.
    searchNoiseShare() {
      const n = this.search && this.search.report && this.search.report.noise;
      return n ? Number(n.share) || 0 : 0;
    },
    showSearchNoise() {
      return this.searchNoiseShare >= 25;
    },
    searchNoiseExamples() {
      const n = this.search && this.search.report && this.search.report.noise;
      return (n && Array.isArray(n.examples)) ? n.examples : [];
    },
    searchNoiseCount() {
      const n = this.search && this.search.report && this.search.report.noise;
      return n ? Number(n.searches) || 0 : 0;
    },
    // >0 when the source samples pages rather than reporting all of them — the
    // worklist can only ever judge what was sampled, so it has to say so.
    searchPageCap() {
      const s = (this.search && this.search.sources) || {};
      const cur = s[this.searchSource];
      return cur ? Number(cur.pageCap) || 0 : 0;
    },
    searchSourceLabel() {
      return this.search && this.search.source === 'google' ? 'Google Search Console' : 'Bing Webmaster Tools';
    },
    searchSource() {
      return (this.search && this.search.source) || '';
    },
    // The window these numbers describe — "as of when" is part of trusting them.
    searchRange() {
      const r = this.search && this.search.range;
      if (!r || !r.start) return '';
      const d = (s) => formatDate(new Date(`${s}T00:00:00`));
      return `${d(r.start)} – ${d(r.end)}`;
    },
    // Both engines can answer — only then is there a choice worth offering.
    searchBothConnected() {
      const s = (this.search && this.search.sources) || {};
      return !!(s.bing && s.bing.hasData && s.google && s.google.hasData);
    },
    searchGroups() {
      const r = (this.search && this.search.report) || {};
      return [
        {
          key: 'almost',
          label: 'Almost on page one',
          // The header chip repeats the SAME pill the rows below carry, so the
          // group explains its own rank chips before the reader meets them.
          chip: 'Rank 8–20',
          chipTone: 'is-two',
          total: (r.page_counts && r.page_counts.almost) || 0,
          why: 'These sit around rank 8–20. A small improvement can move them up.',
          // What to actually do, in the editor, in plain words. Without this the
          // card sends people to a screen full of passing checks and no next step.
          // Static in-house copy — rendered via v-html, so no user data may ever
          // land here. Bold marks ONLY the which-title/which-description
          // clarifiers (his call: emphasis is for naming the fields, not for
          // decorating the instructions).
          todo: 'Open the post and make it answer this search more directly: use the words people typed in the title searchers see (<strong>the SEO title in the “Search & AI” box</strong>, or <strong>the post title</strong> when that field is empty) and in an early heading, and add a paragraph that answers it plainly. Then link to this post from your other posts — the “Link to your own posts” box in the editor sidebar suggests which ones. Fixing a page does not clear its card — the card leaves when a later report shows better numbers, usually after a few weeks.',
          cards: r.almost_there || [],
        },
        {
          key: 'seen',
          label: 'Seen, but not clicked',
          chip: 'Page one',
          chipTone: 'is-one',
          total: (r.page_counts && r.page_counts.seen) || 0,
          why: 'Already on page one, yet people scroll past. Usually the title or description.',
          todo: 'Nothing is wrong with the page itself — people just aren’t picking it out of the results. In the editor, find the “Search & AI” box in the right-hand sidebar and rewrite two fields there: <strong>the SEO title</strong> and <strong>the AI description</strong>. That pair is exactly what a searcher reads before deciding to click. Fixing a page does not clear its card — the card leaves when a later report shows better numbers, usually after a few weeks.',
          cards: r.seen_not_chosen || [],
        },
      ];
    },
    // Total page-fixes across the content worklist (a page with two issues is two fixes).
    optimizeTotal() {
      return this.optimize.reduce((n, i) => n + Number(i.count || 0), 0);
    },
    // Show the section whenever there's anything to act on — issues, or set-aside pages.
    hasOptimizeSection() {
      return this.optimize.length > 0 || this.optimizeIgnored.length > 0;
    },
    livePass() {
      return this.live ? this.live.filter((r) => r.ok).length : 0;
    },
    // Open while a run is in flight or its results are still on screen.
    liveOpen() {
      return !!(this.live || this.liveRunning);
    },
    // Endpoints a shared cache/CDN served from its store — those fetches bypass
    // WordPress, so the activity log never sees them and freshness can lag.
    cachedChecks() {
      return this.live ? this.live.filter((r) => r.cache) : [];
    },
    cachedVia() {
      const hit = this.cachedChecks.find((r) => r.cache && r.cache.via);
      return hit ? hit.cache.via : 'A CDN / cache';
    },
    cachedNames() {
      return this.cachedChecks.map((r) => r.label).join(', ');
    },
    // ── Exposed-files scan ──────────────────────────────────────────────
    exposureOpen() {
      return !!(this.exposure || this.exposureRunning);
    },
    exposureResults() {
      return this.exposure ? this.exposure.results : [];
    },
    exposedRows() {
      return this.exposureResults.filter((r) => r.state === 'exposed');
    },
    // Exposed AND has data → serious (red). Exposed but 0 bytes → lesser (amber): it
    // leaks nothing yet, but a file like debug.log can fill up, so still worth blocking.
    exposedLeaking() {
      return this.exposedRows.filter((r) => !r.empty);
    },
    exposedEmpty() {
      return this.exposedRows.filter((r) => r.empty);
    },
    exposureSkipped() {
      return this.exposureResults.filter((r) => r.state === 'skip').length;
    },
    // Red only for a real leak on a PUBLIC site; on a detected-local site an exposed file
    // isn't reachable from outside, so it's amber ("would leak once you deploy"), not red.
    exposureSeverity() {
      if (this.exposedLeaking.length && !this.isLocal) return 'bad';
      if (this.exposedRows.length) return 'warn';
      return 'ok';
    },
  },
  mounted() {
    this.loadSearch();
  },
  watch: {
    // Re-read the worklist on every reveal: a source connected (or a page set
    // aside) in another tab must show here without a page reload — the same
    // contract the Bing and Search Performance cards keep.
    active(on) {
      if (on) this.loadSearch();
    },
    // Land focus on the dialog when it opens so Esc closes it and it reads as modal.
    // Esc is ALSO bound at the document while open — the panel handler goes silent
    // the moment a backdrop click parks focus outside the dialog.
    liveOpen(open) {
      if (this._unEscLive) this._unEscLive();
      this._unEscLive = open ? bindDocEsc(() => this.closeLive()) : null;
      if (!open) return;
      this.$nextTick(() => {
        if (this.$refs.liveDialog) this.$refs.liveDialog.focus();
        this.updateLiveHint();
      });
    },
    exposureOpen(open) {
      if (this._unEscExposure) this._unEscExposure();
      this._unEscExposure = open ? bindDocEsc(() => this.closeExposure()) : null;
      if (!open) return;
      this.$nextTick(() => {
        if (this.$refs.exposureDialog) this.$refs.exposureDialog.focus();
        this.updateExposureHint();
      });
    },
    // Results stream in while the dialog is open, growing the list under the
    // reader — re-measure the scroll hint whenever the content changes.
    live: {
      deep: true,
      handler() { this.$nextTick(this.updateLiveHint); },
    },
    exposure: {
      deep: true,
      handler() { this.$nextTick(this.updateExposureHint); },
    },
  },
  beforeUnmount() {
    if (this._unEscLive) this._unEscLive();
    if (this._unEscExposure) this._unEscExposure();
  },
  methods: {
    tagLabel(status) {
      return { pass: 'PASS', warn: 'WARN', fail: 'FAIL' }[status] || String(status || 'CHECK').toUpperCase();
    },
    // Set a page aside as "not cited content" (or restore it). The server returns the
    // recomputed score, which the parent swaps in — so the worklist, the set-aside list,
    // the counts, and the rung all update without a reload.
    // Load the opportunities report — lazily, on mount, and again after a page is
    // set aside from here (the report itself changes).
    async loadSearch(source) {
      if (!this.api || !this.api.getSearchOpportunities) return;
      try {
        this.search = await this.api.getSearchOpportunities(source || this.searchPick);
      } catch (e) {
        this.search = null; // Absent beats a broken section.
      }
    },
    // Flip which engine's numbers the worklist is built from. Never merged: the
    // two count different searchers, so a blended list would be a number neither
    // engine reported.
    pickSearchSource(source) {
      if (this.searchPick === source) return;
      this.searchPick = source;
      this.loadSearch(source);
    },
    // The citability flags for one search card. The server grades each mapped
    // card's page directly now (optimize_flags — {@see Score::page_flags}), so
    // the badge no longer depends on the page sitting in Optimize's recency
    // sample; the worklist map stays as the fallback for report payloads
    // fetched before that field existed.
    cardFlags(card) {
      if (Array.isArray(card.optimize_flags)) return card.optimize_flags.length ? card.optimize_flags : null;
      const m = this.optimizeFlagsById[card.page_id];
      return m && m.length ? m : null;
    },
    // Set aside (or restore) a page in the SEARCH worklist. Its own list: this
    // says "don't suggest search fixes for this page", which is a different
    // judgement from Optimize's "don't grade this for quoting". The route
    // answers with the refreshed report, so no second round-trip.
    // `ident` is { post } for mapped pages, { url } for pages with no post
    // behind them (the homepage on some sites, an archive) — the ledger keys
    // differ, and the URL is the only identity an unmapped page has.
    async setAsideSearch(ident, ignored = true) {
      const busy = (ident && (ident.post || ident.url)) || 0;
      if (this.busySearchIgnore || !busy) return;
      this.busySearchIgnore = busy;
      try {
        this.search = await this.api.ignoreSearch(ident, ignored);
      } catch (e) {
        this.$emit('flash', { type: 'error', text: (e && e.message) || 'Could not update. Try again.' });
      } finally {
        this.busySearchIgnore = 0;
      }
    },
    async setAside(page, ignored) {
      if (this.busyIgnore || !page || !page.id) return;
      // No prompt in either direction: one page is a small, visible, one-click-undoable
      // move (it lands in the Set Aside list right below). Only the bulk actions confirm.
      this.busyIgnore = page.id;
      try {
        const res = await this.api.ignoreOptimize(page.id, ignored);
        if (res && res.score) this.$emit('score-updated', res.score);
      } catch (e) {
        this.$emit('flash', { type: 'error', text: (e && e.message) || 'Could not update. Try again.' });
      } finally {
        this.busyIgnore = 0;
      }
    },
    // Set aside EVERY page this check flags — the server walks the full sample, so
    // the "Showing 6 of 8" tail is included, not just the rows on screen.
    async setAllAside(issue) {
      if (this.busyIssue || !issue || !issue.id) return;
      const ok = await confirm({
        title: `Set all ${issue.count} aside?`,
        message: 'Nothing is deleted or changed — they stay published exactly as they are. Every page this check flags is left out of your content-optimization score (a page set aside is skipped by every check, not just this one). You can restore each one here anytime.',
        confirmLabel: 'Ignore All',
        cancelLabel: 'Cancel',
        tone: 'default',
      });
      if (!ok) return;
      this.busyIssue = issue.id;
      try {
        const res = await this.api.ignoreOptimizeIssue(issue.id);
        if (res && res.score) this.$emit('score-updated', res.score);
      } catch (e) {
        this.$emit('flash', { type: 'error', text: (e && e.message) || 'Could not update. Try again.' });
      } finally {
        this.busyIssue = '';
      }
    },
    // The mirror of Ignore All: empty the parked list. Confirmed, because one click
    // returns every page to grading and refloods the worklist above.
    async restoreAll() {
      if (this.busyRestoreAll || !this.optimizeIgnored.length) return;
      const n = this.optimizeIgnored.length;
      const ok = await confirm({
        title: `Restore all ${n} ${1 === n ? 'page' : 'pages'}?`,
        message: `All ${n} return to content grading and count toward your score again. Anything still flagged reappears on the worklist above — your score may drop until those pages are fixed or set aside again. The pages themselves are not touched.`,
        confirmLabel: 'Restore All',
        cancelLabel: 'Cancel',
        tone: 'default',
      });
      if (!ok) return;
      this.busyRestoreAll = true;
      try {
        const res = await this.api.restoreAllOptimize();
        if (res && res.score) this.$emit('score-updated', res.score);
      } catch (e) {
        this.$emit('flash', { type: 'error', text: (e && e.message) || 'Could not update. Try again.' });
      } finally {
        this.busyRestoreAll = false;
      }
    },
    cacheTitle(r) {
      if (!r.cache) return '';
      const age = r.cache.age != null ? ` · age ${r.cache.age}s` : '';
      return `served from ${r.cache.via}${age} — this fetch bypassed WordPress, so it isn't in your activity log`;
    },
    // Fetch the real endpoints from this browser and grade what an agent receives.
    // The server makes no request — this runs here, same-origin, on click only.
    async verifyLive() {
      if (this.liveRunning) return;
      this.liveRunning = true;
      try {
        this.live = await runAll({ ...this.liveConfig, selfcheckToken: await this.selfcheckToken() });
      } finally {
        this.liveRunning = false;
      }
    },
    // A short-lived token the checks carry so the site's own visit log skips them
    // (the fetches are anonymous by design, so the cookie can't identify us). Best
    // effort: with no token the checks still run — they just get logged.
    async selfcheckToken() {
      try {
        const res = this.api && this.api.mintSelfcheckToken ? await this.api.mintSelfcheckToken() : null;
        return (res && res.token) || '';
      } catch (e) {
        return '';
      }
    },
    // Dismiss the results. A no-op mid-run (the fetch finishes in ~1s) so a stray
    // Esc/backdrop click can't close an empty shell that's about to repopulate.
    closeLive() {
      if (this.liveRunning) return;
      this.live = null;
    },
    // Show each dialog's bottom fade + chevron only while there's more content
    // below (the reset dialog's pattern), so the list reads as scrollable.
    updateLiveHint() {
      const el = this.$refs.liveBody;
      this.liveMore = !!el && el.scrollHeight - el.scrollTop - el.clientHeight > 4;
    },
    scrollLiveBody() {
      const el = this.$refs.liveBody;
      if (!el) return;
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      el.scrollBy({ top: Math.round(el.clientHeight * 0.8), behavior: reduce ? 'auto' : 'smooth' });
    },
    updateExposureHint() {
      const el = this.$refs.exposureBody;
      this.exposureMore = !!el && el.scrollHeight - el.scrollTop - el.clientHeight > 4;
    },
    scrollExposureBody() {
      const el = this.$refs.exposureBody;
      if (!el) return;
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      el.scrollBy({ top: Math.round(el.clientHeight * 0.8), behavior: reduce ? 'auto' : 'smooth' });
    },
    // Scan the site's own URL for publicly-readable sensitive files. Browser-side,
    // same-origin, on click only — the server makes no request (see livecheck.js).
    async scanExposure() {
      if (this.exposureRunning) return;
      this.exposureRunning = true;
      try {
        // Tagged with the same self-check token: probing for wp-config.bak from
        // this screen must never read as a scanner in the site's own records.
        this.exposure = await runExposureScan({ ...this.liveConfig, selfcheckToken: await this.selfcheckToken() });
      } finally {
        this.exposureRunning = false;
      }
    },
    closeExposure() {
      if (this.exposureRunning) return;
      this.exposure = null;
    },
  },
};
</script>

<template>
  <!-- A stack of free-standing cards on the admin background: the report header, then
       one card per rung group — the same section-card grammar as the Settings page. -->
  <section class="ar-readiness-stack">
    <div class="ar-card ar-card__head ar-card__head--inline ar-card__head--stack-sm">
      <div class="ar-card__titlewrap">
        <h2 class="ar-card__title">Readiness Report</h2>
        <!-- The report-wide total: the rung cards' dot-and-count grammar, summed. -->
        <span
          v-if="overall.total"
          class="ar-readiness__total"
          :class="`is-${overall.status}`"
          :aria-label="`${overall.pass} of ${overall.total} checks pass overall`"
        >
          <span class="ar-readiness__total-dot" aria-hidden="true"></span>{{ overall.pass }}/{{ overall.total }}
        </span>
        <!-- Refresh THIS report (recompute the checklist below). Kept beside the title
             and apart from the tool buttons so it reads as "update this card", not as
             another live check like "Verify live". -->
        <button
          type="button"
          class="ar-readiness__refresh"
          :class="{ 'is-busy': refreshing }"
          :disabled="refreshing"
          @click="$emit('refresh')"
          :aria-label="refreshing ? 'Re-running the readiness checks…' : 'Re-run the readiness checks'"
          :title="refreshing ? 'Re-running…' : 'Re-run the readiness checks'"
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>
        </button>
      </div>
      <div class="ar-card__actions">
        <button type="button" class="ar-btn" @click="schemaOpen = true">
          Agent preview
        </button>
        <button type="button" class="ar-btn" :disabled="liveRunning" @click="verifyLive">
          {{ liveRunning ? 'Checking…' : 'Verify live' }}
        </button>
        <button type="button" class="ar-btn" :disabled="exposureRunning" @click="scanExposure">
          {{ exposureRunning ? 'Scanning…' : 'Scan for exposed files' }}
        </button>
      </div>
    </div>

    <div
      v-for="g in groups"
      :id="`ar-group-${g.key}`"
      :key="g.key"
      class="ar-card ar-checkgroup"
      :class="`is-${g.status}`"
    >
      <div class="ar-checkgroup__head">
        <span class="ar-checkgroup__rung" aria-hidden="true"></span>
        <div class="ar-checkgroup__text">
          <h3 class="ar-checkgroup__name">{{ g.label }}</h3>
          <p v-if="g.blurb" class="ar-checkgroup__blurb">{{ g.blurb }}</p>
        </div>
        <span class="ar-checkgroup__count">{{ g.pass }}/{{ g.total }}</span>
      </div>

      <ul class="ar-checks">
        <li v-for="c in g.items" :id="`ar-check-${c.id}`" :key="c.id" class="ar-check" :class="`is-${c.status}`">
          <span class="ar-check__rule" aria-hidden="true"></span>
          <div class="ar-check__text">
            <strong>
              {{ c.label }}
              <a
                v-if="c.ar"
                class="ar-check__arid"
                href="https://agentready.org/"
                target="_blank"
                rel="noopener"
                :aria-label="`AgentReady requirement ${c.ar}`"
              >{{ c.ar }}</a>
            </strong>
            <small>{{ c.detail }}</small>
          </div>
          <span class="ar-check__tag" :class="`is-${c.status}`">{{ tagLabel(c.status) }}</span>
          <!-- Fix + action sit at row level, not inside the text column, so the
               note spans the full row width — flush with the status chip edge. -->
          <p v-if="c.fix" class="ar-check__fix">{{ c.fix }}</p>
          <a
            v-if="c.action && c.action.href"
            class="ar-check__action"
            :href="c.action.href"
            target="_blank"
            rel="noopener"
          >{{ c.action.label }} ↗</a>
          <button
            v-else-if="c.action"
            type="button"
            class="ar-check__action"
            @click="$emit('navigate', { tab: c.action.tab, anchor: c.action.anchor })"
          >{{ c.action.label }} →</button>
          <!-- Extra doors beyond the one action (e.g. robots-change: what each
               connected engine last read). Server-built, only when connected. -->
          <a
            v-for="l in c.links || []"
            :key="l.href"
            class="ar-check__action"
            :href="l.href"
            target="_blank"
            rel="noopener"
          >{{ l.label }} ↗</a>
        </li>
      </ul>
    </div>

    <!-- The Optimized rung's section: per-page content citability. Unlike the config
         rungs above, these are fixed in each post's editor, so every issue lists the
         pages that trip it as edit links. Each can be set aside as "not cited content"
         — kept in a visible list, and always shown as a "set aside" count so the score
         stays honest. Matches the rung → tab-section model. -->
    <div v-if="hasOptimizeSection" id="ar-group-optimized" class="ar-card ar-checkgroup is-warn">
      <div class="ar-checkgroup__head">
        <span class="ar-checkgroup__rung" aria-hidden="true"></span>
        <div class="ar-checkgroup__text">
          <h3 class="ar-checkgroup__name">Optimize Your Content</h3>
          <p class="ar-checkgroup__blurb">
            Pages an answer engine would find harder to read or quote. Open one to fix it in the editor,
            or set aside anything that isn’t meant to be cited.
          </p>
        </div>
        <span class="ar-checkgroup__count">
          {{ optimizeGraded }} graded<template v-if="optimizeIgnored.length"> · {{ optimizeIgnored.length }} set aside</template>
        </span>
      </div>

      <ul v-if="optimize.length" class="ar-checks">
        <li v-for="issue in optimize" :id="`ar-opt-${issue.id}`" :key="issue.id" class="ar-check is-warn">
          <span class="ar-check__rule" aria-hidden="true"></span>
          <div class="ar-check__text">
            <!-- The server names the real content types behind the count ("3 Posts, 1 Page");
                 the items fallback covers a stale pre-upgrade payload without one. -->
            <div class="ar-optcheck__head">
              <strong>{{ issue.label }} <span class="ar-optcheck__n">· {{ issue.countLabel || `${issue.count} ${issue.count === 1 ? 'item' : 'items'}` }}</span></strong>
              <!-- One click for the whole check — the server walks the full sample,
                   so the "Showing 6 of 8" tail is included. Pointless on a single
                   page, whose own Set aside is right below. -->
              <button
                v-if="issue.count > 1"
                type="button"
                class="ar-optcheck__aside ar-optcheck__aside--all"
                :disabled="busyIssue === issue.id"
                @click="setAllAside(issue)"
              >{{ busyIssue === issue.id ? 'Ignoring…' : 'Ignore All' }}</button>
            </div>
            <small>{{ issue.why }}</small>
            <ul class="ar-optcheck__pages">
              <li v-for="p in issue.pages" :key="p.id" class="ar-optcheck__row">
                <a :href="p.url" target="_blank" rel="noopener" class="ar-optcheck__page">{{ p.title }} ↗</a>
                <button
                  type="button"
                  class="ar-optcheck__aside"
                  :disabled="busyIgnore === p.id"
                  @click="setAside(p, true)"
                >Ignore It</button>
              </li>
            </ul>
            <p v-if="issue.pages.length < issue.count" class="ar-optcheck__more">
              Showing {{ issue.pages.length }} of {{ issue.count }}.
            </p>
          </div>
        </li>
      </ul>
      <p v-else class="ar-optcheck__clear">Every graded post and page reads as citable. Anything set aside is listed below.</p>

      <!-- Set aside — always visible, one-click restore, so nothing is silently hidden. -->
      <div v-if="optimizeIgnored.length" class="ar-setaside">
        <div class="ar-setaside__head">
          <strong class="ar-setaside__title">Set Aside <span class="ar-optcheck__n">· {{ optimizeIgnored.length }}</span></strong>
          <span class="ar-setaside__note">not cited content — left out of the score</span>
          <button
            type="button"
            class="ar-optcheck__restore ar-setaside__restoreall"
            :disabled="busyRestoreAll"
            @click="restoreAll"
          >{{ busyRestoreAll ? 'Restoring…' : 'Restore All' }}</button>
        </div>
        <ul class="ar-optcheck__pages">
          <li v-for="p in optimizeIgnored" :key="p.id" class="ar-optcheck__row">
            <div class="ar-optcheck__asided">
              <a :href="p.url" target="_blank" rel="noopener" class="ar-optcheck__page ar-optcheck__page--muted">{{ p.title }} ↗</a>
              <!-- What it was flagged for, so the aside list keeps the "why" the
                   worklist knew. No flags = nothing to say (it passes everything now). -->
              <small v-if="p.flags && p.flags.length" class="ar-optcheck__flags">{{ p.flags.join(' · ') }}</small>
            </div>
            <button
              type="button"
              class="ar-optcheck__restore"
              :disabled="busyIgnore === p.id"
              @click="setAside(p, false)"
            >Restore</button>
          </li>
        </ul>
      </div>
    </div>

    <!-- Search Opportunities: the same worklist idea aimed at classic search —
         queries a page already ranks for but under-earns. Sibling of Optimize
         because both are "pages worth improving", and both honor the SAME
         per-page set-aside. Present only once a search source is connected;
         its own honest states carry the rest. -->
    <div v-if="hasSearchSection" id="ar-group-search" class="ar-card ar-checkgroup" :class="searchOpps ? 'is-warn' : ''">
      <div class="ar-checkgroup__head">
        <span class="ar-checkgroup__rung" aria-hidden="true"></span>
        <div class="ar-checkgroup__text">
          <h3 class="ar-checkgroup__name">Search Opportunities</h3>
          <!-- Covers BOTH groups: one is ranked too low to be clicked, the other
               is ranked well and passed over. Describing only the second would
               misname half the list. -->
          <p class="ar-checkgroup__blurb">
            Pages already showing up in search that could earn more — either sitting just
            off page one, or on page one and being scrolled past. Each one lists what
            people typed to find it.
          </p>
        </div>
        <span v-if="searchOpps" class="ar-checkgroup__count">
          {{ searchOpps }} page{{ searchOpps === 1 ? '' : 's' }} to look at<template v-if="searchCounts.set_aside"> · {{ searchCounts.set_aside }} set aside</template>
        </span>
      </div>

      <!-- Whose numbers these are — said in plain words, always, with the switch
           beside it when both engines can answer. -->
      <p v-if="searchState !== 'collecting'" class="ar-srcline ar-opp__srcline">
        <span>These numbers come from <strong>{{ searchSourceLabel }}</strong><template v-if="searchRange">, covering {{ searchRange }}</template>.</span>
        <span v-if="searchBothConnected" class="ar-srcpick">
          <span class="ar-srcpick__label">Show</span>
          <span class="ar-srcpick__set">
            <button type="button" class="ar-srcpick__btn" :class="{ 'is-on': searchSource === 'google' }" @click="pickSearchSource('google')">Google</button>
            <button type="button" class="ar-srcpick__btn" :class="{ 'is-on': searchSource === 'bing' }" @click="pickSearchSource('bing')">Bing</button>
          </span>
        </span>
      </p>
      <!-- The scope this worklist can see at all. Its own line, NOT appended to the
           provenance sentence above: that line is a flex row holding the engine
           switch, and a longer sentence wraps the switch onto a row of its own,
           stranding a control away from the numbers it changes. -->
      <p v-if="searchPageCap" class="ar-srcline ar-opp__scope">
        {{ searchSourceLabel }} has no single report pairing searches with pages, so
        Agentimus fetches that page by page — for your {{ searchPageCap }} busiest pages,
        to keep the daily check small. This worklist can only see those.
      </p>

      <!-- When most of the reported demand is machines, that IS the finding, and
           it comes first: it explains both an empty worklist and why Search
           Performance — which keeps the raw record — shows bigger numbers. -->
      <p v-if="showSearchNoise" class="ar-checkgroup__blurb ar-opp__state ar-opp__noise">
        <strong>{{ searchNoiseShare }}% of the views {{ searchSourceLabel }} recorded on your
        pages weren’t people.</strong> They came from automated <code>site:</code> and
        <code>intext:</code> probes — scrapers and SEO tools running bulk searches. Those
        are left out of everything below, because no title rewrite makes a scraper click.
        Search Performance shows the raw record including them, which is why its numbers
        are larger than what’s judged here.
      </p>
      <!-- The filter, auditable. If it ever swallows a real search, this is where
           the owner sees it — a percentage can only be believed, a list can be read.
           Shown whenever ANYTHING was dropped, not only above the note's threshold:
           the card totals below exclude these, so a single 5-view probe makes a page
           read 2,139 here and 2,144 on Search Performance. Under 25% the paragraph
           above stays hidden, and this pill would be the only way to reconcile that. -->
      <details v-if="searchNoiseExamples.length" class="ar-opp__noiselist">
        <summary>See what was left out ({{ searchNoiseCount }} search{{ searchNoiseCount === 1 ? '' : 'es' }})</summary>
        <!-- Own scroll container: this table carries a 520px floor, and a long
             operator string must never push the page sideways on a phone. -->
        <div class="ar-opp__qwrap">
          <table class="ar-opp__queries">
            <thead>
              <!-- The column has to name its basis. These are views summed across
                   every page the search touched, so the figure is legitimately
                   larger than the single site-wide number Search Performance shows
                   for the same search — and without a header that reads as a
                   contradiction. -->
              <tr><th>Search left out</th><th>Views across your pages</th></tr>
            </thead>
            <tbody>
              <tr v-for="(ex, i) in searchNoiseExamples" :key="i">
                <td class="ar-opp__q">{{ ex.query }}</td>
                <td class="ar-opp__num">{{ ex.impressions.toLocaleString() }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="ar-opp__noisefoot">
          <template v-if="searchNoiseExamples.length > 1">Showing the {{ searchNoiseExamples.length }} biggest.</template>
          A search is only dropped when it uses an operator — <code>site:</code>
          <code>intext:</code> <code>inurl:</code> <code>filetype:</code> and the
          like — the way machines search, not people.
        </p>
      </details>

      <!-- Honest states, in the order a site meets them. -->
      <p v-if="searchState === 'collecting'" class="ar-checkgroup__blurb ar-opp__state">
        Connected, no numbers yet. Search engines report on a delay — the first
        window usually lands within a day or two, and this fills itself.
      </p>
      <p v-else-if="searchState === 'clear'" class="ar-checkgroup__blurb ar-opp__state">
        <template v-if="searchMedian">
          Nothing obvious this window. Your pages are earning the clicks their
          rankings deserve. New queries land here as {{ searchSourceLabel }} reports them.
        </template>
        <template v-else-if="searchMedianReason === 'unclicked'">
          Nothing sits on page two waiting for a push. Click rates can’t be judged yet —
          your page-one results are being shown but hardly clicked, so there is no normal
          click rate here to measure any single page against, and none is called
          under-earning on a
          guess.<template v-if="searchBothConnected"> Switch above for the other engine’s view.</template>
        </template>
        <template v-else>
          Nothing on page two has enough searches behind it to be worth a push, and click
          rates can’t be judged yet:
          <template v-if="searchMedianRows">only {{ searchMedianRows }} of your pages
          {{ searchMedianRows === 1 ? 'gets' : 'get' }} enough real views on page one to
          measure</template><template v-else>none of your pages get enough real views on
          page one to measure</template>, and it takes
          {{ searchMedianNeeds }} before a normal click rate for this site can be worked out.
          That’s a shortage of measurable traffic, not a verdict on your
          pages.<template v-if="searchBothConnected"> Switch above for the other engine’s view.</template>
        </template>
      </p>
      <p v-else-if="searchState === 'thin'" class="ar-checkgroup__blurb ar-opp__state">
        Not enough search traffic here to judge yet. {{ searchSourceLabel }} is reporting
        your pages, but neither the individual searches nor the pages they add up to
        carry enough views to tell a real pattern from a coincidence — so Agentimus says
        nothing rather than sending you to rewrite a title over a handful of
        impressions.<template v-if="searchBothConnected"> Switch above for the other
        engine’s view.</template>
      </p>

      <template v-else-if="searchState === 'ready'">
        <template v-for="group in searchGroups" :key="group.key">
          <template v-if="group.cards.length">
            <div class="ar-opp__grouphead">
              <h4 class="ar-opp__grouptitle">{{ group.label }}</h4>
              <span class="ar-opp__pos" :class="group.chipTone">{{ group.chip }}</span>
            </div>
            <p class="ar-opp__groupwhy">{{ group.why }}</p>
            <p class="ar-opp__todo">
              <!-- eslint-disable-next-line vue/no-v-html — group.todo is our own static copy above, never user data -->
              <strong>What to do:</strong> <span v-html="group.todo"></span>
              <!-- Never let a cap pass for completeness. -->
              <template v-if="group.total > group.cards.length">
                Showing the {{ group.cards.length }} pages with the most impressions, of {{ group.total }} —
                fix these and the next ones move up.
              </template>
            </p>
            <ul class="ar-checks ar-opp__list">
              <li v-for="card in group.cards" :key="group.key + card.page_url" class="ar-check is-warn ar-opp">
                <span class="ar-check__rule" aria-hidden="true"></span>
                <div class="ar-opp__body">
                  <div class="ar-opp__top">
                    <a v-if="card.edit_url" :href="card.edit_url" class="ar-opp__title">{{ card.title }}</a>
                    <span v-else class="ar-opp__title">{{ card.title }}</span>
                    <code class="ar-opp__path">{{ card.path }}</code>
                    <!-- Both worklists pointing at one page is worth saying: it
                         turns two separate chores into a single visit. -->
                    <span v-if="cardFlags(card)" class="ar-opp__alsoflag">
                      Also in Optimize: {{ cardFlags(card).join(' · ').toLowerCase() }}
                    </span>
                  </div>
                  <div class="ar-opp__qwrap">
                    <table class="ar-opp__queries">
                      <thead>
                        <tr>
                          <th>What people searched</th><th>Rank</th><th>Times shown</th><th>Visits</th><th>Click rate</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="q in card.queries" :key="q.query">
                          <td class="ar-opp__q">{{ q.query }}</td>
                          <td><span class="ar-opp__pos" :class="q.position < 8 ? 'is-one' : 'is-two'">{{ q.position }}</span></td>
                          <td class="ar-opp__num">{{ q.impressions.toLocaleString() }}</td>
                          <td class="ar-opp__num">{{ q.clicks.toLocaleString() }}</td>
                          <td class="ar-opp__num" :class="group.key === 'seen' ? 'is-low' : 'is-dim'">{{ q.ctr }}%</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <!-- A page can qualify on its TOTAL when no single search does
                       (an engine may split one page's demand across dozens of
                       long-tail searches). Say so, or the small numbers in the
                       table look like they can't add up to the verdict. -->
                  <p v-if="card.whole_page" class="ar-opp__more">
                    Judged on this page’s totals — <strong>{{ card.impressions.toLocaleString() }}</strong> times shown
                    across {{ card.searches }} search{{ card.searches === 1 ? '' : 'es' }}, {{ card.clicks.toLocaleString() }} visit{{ card.clicks === 1 ? '' : 's' }},
                    average rank {{ card.position }}. No single search is big on its own; together they are.
                    The biggest are listed above.
                  </p>
                  <!-- Every other card states the page's WHOLE demand too. The rows
                       above are only the searches that qualified for this group, so
                       without this a card totalling 815 views sits beside a Search
                       Performance row reading 9,143 for the same page and looks like a
                       contradiction. Same figures as `whole_page` above — `bucket()`
                       always carries the page's full totals — only the framing differs,
                       because here a single search earned the place, not the total. -->
                  <p v-else class="ar-opp__more">
                    This page in total — <strong>{{ card.impressions.toLocaleString() }}</strong> times shown
                    across {{ card.searches }} search{{ card.searches === 1 ? '' : 'es' }}, {{ card.clicks.toLocaleString() }} visit{{ card.clicks === 1 ? '' : 's' }},
                    average rank {{ card.position }}.
                    <template v-if="card.more">Above are the {{ card.queries.length }} biggest of
                      {{ card.queries.length + card.more }} searches</template><template v-else>Above
                      {{ card.queries.length === 1 ? 'is the search' : 'are the searches' }}</template>
                    where this page {{ group.key === 'seen' ? 'is passed over' : 'sits on page two' }}.
                  </p>
                  <!-- Each action names the fix and lands on the box that holds
                       it. New tab, like every page link in the worklists: this
                       screen is a checklist you work down, not a place to leave. -->
                  <div class="ar-opp__actions">
                    <!-- A card with no post offers no editor doors — say why, or the
                         missing buttons read as a bug. Two cases, each told only the
                         truth that fits it: the HOMEPAGE's result title really is the
                         site title + tagline (that's what core prints for the front
                         page), so it gets that instruction and a real door to
                         Settings → General; any other doorless address (an archive, a
                         gone permalink) has no single lever, so no lever is named. -->
                    <span v-if="card.doorless === 'home'" class="ar-opp__noeditor">This is your homepage — searchers see your site title and tagline as its title; its description comes from your theme.</span>
                    <span v-else-if="card.doorless" class="ar-opp__noeditor">No post behind this address — WordPress builds this page from other content (an archive, or an address that no longer exists), so there is no editor to open.</span>
                    <a v-if="card.doorless === 'home' && card.general_url" :href="card.general_url" target="_blank" rel="noopener" class="ar-opp__edit is-primary">Edit site title &amp; tagline ↗</a>
                    <a v-if="card.edit_url" :href="card.edit_url" target="_blank" rel="noopener" class="ar-opp__edit is-primary">Improve title &amp; description ↗</a>
                    <a v-if="group.key === 'almost' && card.links_url" :href="card.links_url" target="_blank" rel="noopener" class="ar-opp__edit">Add internal links ↗</a>
                    <a v-if="cardFlags(card) && card.read_url" :href="card.read_url" target="_blank" rel="noopener" class="ar-opp__edit">Check readability ↗</a>
                    <button
                      v-if="card.page_id || card.page_url"
                      type="button"
                      class="ar-optcheck__restore"
                      :disabled="busySearchIgnore === (card.page_id || card.page_url)"
                      @click="setAsideSearch(card.page_id ? { post: card.page_id } : { url: card.page_url }, true)"
                    >Set aside</button>
                  </div>
                </div>
              </li>
            </ul>
          </template>
        </template>
        <p v-if="searchMedian" class="ar-card__note">
          “Not clicked enough” means a click rate below <strong>{{ searchCtrBar }}%</strong> —
          well under the <strong>{{ searchMedian }}%</strong> your own page-one results
          typically get. Both numbers are your site’s own, not an industry figure, and a page
          has to fall clearly short before it’s listed rather than merely below average.
        </p>
      </template>

      <!-- The search worklist's OWN set-aside list — always visible, one-click
           restore, so a page held back here is never hidden. Separate from the
           Optimize list above: different judgement, different ledger, each shown
           where it was made. -->
      <div v-if="searchAside.length" class="ar-setaside ar-opp__aside">
        <div class="ar-setaside__head">
          <p class="ar-opp__eyebrow">
            Set aside from search
            <span class="ar-opp__why">— {{ searchAside.length }} page{{ searchAside.length === 1 ? '' : 's' }} you don’t want search suggestions for, from either engine</span>
          </p>
        </div>
        <ul class="ar-optcheck__pages">
          <li v-for="p in searchAside" :key="p.id || p.url" class="ar-optcheck__row">
            <div class="ar-optcheck__asided">
              <a :href="p.url" target="_blank" rel="noopener" class="ar-optcheck__page ar-optcheck__page--muted">{{ p.title }} ↗</a>
            </div>
            <button
              type="button"
              class="ar-optcheck__restore"
              :disabled="busySearchIgnore === (p.id || p.url)"
              @click="setAsideSearch(p.id ? { post: p.id } : { url: p.url }, false)"
            >Restore</button>
          </li>
        </ul>
      </div>
    </div>

    <!-- Verify-live result: a focused overlay; the report behind never reflows. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="liveOpen" class="ar-modal" @click.self="closeLive">
          <div
            ref="liveDialog"
            class="ar-modal__panel ar-modal__panel--live"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-live-title"
            tabindex="-1"
            @keydown.esc="closeLive"
          >
            <div class="ar-modal__head">
              <div class="ar-live-head">
                <h2 id="ar-live-title" class="ar-modal__title">What Agents Actually Receive</h2>
                <span
                  v-if="live"
                  class="ar-live__tally"
                  :class="{ 'is-bad': livePass < live.length }"
                >{{ livePass }}/{{ live.length }} OK</span>
              </div>
              <p class="ar-modal__lead">
                Fetched from your browser through the public URL — so this reflects what an agent gets
                (including anything a CDN serves), not just your settings. The server makes no request.
              </p>
            </div>

            <div class="ar-modal__body">
              <div ref="liveBody" class="ar-modal__scroll" @scroll="updateLiveHint">
                <!-- A shared cache served stored copies → those fetches skip WordPress,
                     so the activity log under-counts and discovery can go stale. -->
                <div v-if="cachedChecks.length" class="ar-live-cache" role="alert">
                  <strong class="ar-live-cache__title">A cache is sitting in front of your AI endpoints</strong>
                  <p>{{ cachedVia }} returned a <em>stored</em> copy of {{ cachedChecks.length }} endpoint{{ cachedChecks.length > 1 ? 's' : '' }} ({{ cachedNames }}), so those agent fetches never reach WordPress. Your <strong>Activity log under-counts</strong> real AI traffic, and freshness-sensitive endpoints (the change feed, page markdown) can go <strong>stale</strong>.</p>
                  <p class="ar-live-cache__fix"><strong>Fix:</strong> bypass cache for <code>*.md</code>, <code>/llms.txt</code>, <code>/.well-known/*</code> and <code>*changes.json</code> at your CDN/proxy. <a href="https://heera.github.io/agentimus/user-manual/caching.html" target="_blank" rel="noopener">How to fix it ↗</a></p>
                  <p class="ar-live-cache__fix">Can’t change your cache? Turn on <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings', anchor: 'ar-feat-bypass_shared_cache' })">Keep AI endpoints out of your cache</button> in Settings — Agentimus asks caches not to store these, which works with any cache that respects <code>Cache-Control</code> (most do).</p>
                </div>

                <ul v-if="live" class="ar-live__list">
                  <li v-for="r in live" :key="r.key" class="ar-live__row" :class="{ 'is-bad': !r.ok, 'is-cached': !!r.cache }">
                    <span class="ar-live__dot" aria-hidden="true"></span>
                    <span class="ar-live__label">{{ r.label }}</span>
                    <span class="ar-live__detail">{{ r.detail }}</span>
                    <span v-if="r.cache" class="ar-live__cachetag" :title="cacheTitle(r)">cached</span>
                  </li>
                </ul>
                <div v-else class="ar-live__loading">
                  <span class="ar-spinner" aria-hidden="true"></span>
                  <span class="ar-live__loading-label">Fetching your endpoints…</span>
                </div>
              </div>
              <div class="ar-modal__fade" :class="{ 'is-visible': liveMore }">
                <button type="button" class="ar-modal__fade-btn" :disabled="!liveMore" aria-label="Scroll down for more" @click="scrollLiveBody">
                  <svg viewBox="0 0 16 16" class="ar-modal__chev" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
                </button>
              </div>
            </div>

            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" :disabled="liveRunning" @click="closeLive">Close</button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <!-- Exposed-files scan: same browser-side, same-origin approach as Verify live —
         checks whether risky files are publicly downloadable, reading only status + size. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="exposureOpen" class="ar-modal" @click.self="closeExposure">
          <div
            ref="exposureDialog"
            class="ar-modal__panel ar-modal__panel--live"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-exposure-title"
            tabindex="-1"
            @keydown.esc="closeExposure"
          >
            <div class="ar-modal__head">
              <div class="ar-live-head">
                <h2 id="ar-exposure-title" class="ar-modal__title">Exposed-Files Check</h2>
                <span
                  v-if="exposure"
                  class="ar-live__tally"
                  :class="{ 'is-bad': exposureSeverity === 'bad', 'is-warn': exposureSeverity === 'warn' }"
                >{{ exposedLeaking.length ? exposedLeaking.length + (isLocal ? ' to fix' : ' exposed') : (exposedEmpty.length ? exposedEmpty.length + ' empty' : 'all clear') }}</span>
              </div>
              <p class="ar-modal__lead">
                Requested each risky path from your browser through the public URL — the same view an
                outside scanner gets. It reads only whether a file is reachable, never its contents.<template v-if="isLocal"> This is a local site, so nothing’s public yet — read any finding as a heads-up for when you deploy.</template>
              </p>
            </div>

            <div class="ar-modal__body">
              <div ref="exposureBody" class="ar-modal__scroll" @scroll="updateExposureHint">
                <div v-if="exposedLeaking.length" class="ar-live-cache" :class="{ 'ar-live-cache--bad': !isLocal }" role="alert">
                  <strong v-if="isLocal" class="ar-live-cache__title">{{ exposedLeaking.length }} file{{ exposedLeaking.length > 1 ? 's' : '' }} would be exposed once this is live</strong>
                  <strong v-else class="ar-live-cache__title ar-live-cache__title--bad">{{ exposedLeaking.length }} file{{ exposedLeaking.length > 1 ? 's are' : ' is' }} publicly downloadable</strong>
                  <p v-if="isLocal">Nothing is public on a local site — but {{ exposedLeaking.length > 1 ? 'these files' : 'this file' }} would be downloadable once you deploy. <strong>Delete {{ exposedLeaking.length > 1 ? 'them' : 'it' }}</strong> or add a block rule before going live.</p>
                  <p v-else>Anyone can fetch {{ exposedLeaking.length > 1 ? 'these' : 'this' }} — they may hold passwords, keys or private data. <strong>Delete {{ exposedLeaking.length > 1 ? 'them' : 'it' }} from your server</strong>, or block the path at your CDN/webserver.</p>
                  <p class="ar-live-cache__fix"><strong>How to block:</strong> <a href="https://heera.github.io/agentimus/user-manual/exposure.html#blocking-exposed-files" target="_blank" rel="noopener">Nginx / Apache / Cloudflare rules ↗</a></p>
                </div>
                <div v-if="exposedEmpty.length" class="ar-live-cache" role="alert">
                  <strong class="ar-live-cache__title">{{ exposedEmpty.length }} reachable but empty file{{ exposedEmpty.length > 1 ? 's' : '' }}</strong>
                  <p>{{ exposedEmpty.length > 1 ? 'These are' : 'This is' }} publicly reachable but currently <strong>empty (0 bytes)</strong>, so {{ exposedEmpty.length > 1 ? 'they leak' : 'it leaks' }} nothing yet — but a file like <code>debug.log</code> can fill up later. Worth blocking or removing anyway. <a href="https://heera.github.io/agentimus/user-manual/exposure.html#blocking-exposed-files" target="_blank" rel="noopener">How to block ↗</a></p>
                </div>
                <div v-if="exposure && !exposedRows.length" class="ar-live-allclear">
                  <strong>✓ All clear.</strong> None of the {{ exposureResults.length }} checked path{{ exposureResults.length === 1 ? '' : 's' }} {{ exposureResults.length === 1 ? 'is' : 'are' }} publicly readable.
                  <template v-if="exposureSkipped"> ({{ exposureSkipped }} couldn’t be checked — blocked or offline.)</template>
                </div>

                <ul v-if="exposure" class="ar-live__list">
                  <li
                    v-for="r in exposureResults"
                    :key="r.path"
                    class="ar-live__row"
                    :class="{ 'is-bad': r.state === 'exposed' && !r.empty && !isLocal, 'is-warn': r.state === 'exposed' && (r.empty || isLocal), 'is-skip': r.state === 'skip' }"
                  >
                    <span class="ar-live__dot" aria-hidden="true"></span>
                    <span class="ar-live__label">{{ r.path }}</span>
                    <span class="ar-live__detail">{{ r.detail }}</span>
                    <!-- Public site: a loud red urgency cue. On a local site the amber dot +
                         "— downloadable" detail + the banner already say "would be exposed on
                         deploy", so no per-row chip there — it was pure repetition. -->
                    <span v-if="r.state === 'exposed' && !r.empty && !isLocal" class="ar-live__cachetag" title="This file is publicly downloadable">exposed</span>
                    <span v-else-if="r.state === 'exposed' && r.empty" class="ar-live__cachetag" title="Reachable but empty (0 bytes)">empty</span>
                  </li>
                </ul>
                <div v-else class="ar-live__loading">
                  <span class="ar-spinner" aria-hidden="true"></span>
                  <span class="ar-live__loading-label">Checking your site…</span>
                </div>

                <p v-if="exposure" class="ar-live__foot">
                  Want to check your own files too? Add paths under <strong>Settings → Exposure → “Also scan these paths.”</strong>
                </p>
              </div>
              <div class="ar-modal__fade" :class="{ 'is-visible': exposureMore }">
                <button type="button" class="ar-modal__fade-btn" :disabled="!exposureMore" aria-label="Scroll down for more" @click="scrollExposureBody">
                  <svg viewBox="0 0 16 16" class="ar-modal__chev" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
                </button>
              </div>
            </div>

            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" :disabled="exposureRunning" @click="closeExposure">Close</button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <!-- JSON-LD preview: the exact structured data Agentimus emits, for the site
         or any page/post. Its own focus-trapped dialog on the same .ar-modal shell. -->
    <SchemaPreview
      :open="schemaOpen"
      :api="api"
      @close="schemaOpen = false"
      @flash="(...args) => $emit('flash', ...args)"
    />
  </section>
</template>
