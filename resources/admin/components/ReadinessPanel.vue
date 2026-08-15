<script>
import { groupChecks } from '../js/tiers.js';
import { bindDocEsc } from '../js/docEsc.js';
import { runAll, runExposureScan } from '../js/livecheck.js';
import { confirm } from '../js/confirm.js';
import SchemaPreview from './SchemaPreview.vue';
import RefreshCrank from './RefreshCrank.vue';

export default {
  name: 'ReadinessPanel',
  components: { SchemaPreview, RefreshCrank },
  props: {
    checks: { type: Array, default: () => [] },
    optimize: { type: Array, default: () => [] }, // Content worklist behind the Optimized rung.
    optimizeIgnored: { type: Array, default: () => [] }, // Pages set aside as "not cited content".
    optimizeGraded: { type: Number, default: 0 }, // How many pages were actually graded.
    refreshing: { type: Boolean, default: false },
    liveConfig: { type: Object, default: () => ({}) },
    isLocal: { type: Boolean, default: false },
    // What the Search Opportunities card (now on Visibility → Search)
    // announced about itself: { show, count, aside }. Null until it has spoken.
    // This panel renders a pointer from it — never the worklist.
    searchPointer: { type: Object, default: null },
    api: { type: Object, required: true },
  },
  emits: ['refresh', 'navigate', 'flash', 'score-updated'],
  data() {
    return {
      live: null, liveRunning: false, exposure: null, exposureRunning: false,
      schemaOpen: false, busyIgnore: 0, busyIssue: '', busyRestoreAll: false,
      // The scroll-affordance state for each dialog: true while more is below.
      liveMore: false, exposureMore: false,
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
    // Total page-fixes across the content worklist (a page with two issues is two fixes).
    optimizeTotal() {
      return this.optimize.reduce((n, i) => n + Number(i.count || 0), 0);
    },
    // The issue affecting the most pages — the one worth naming in a summary.
    optimizeTopIssue() {
      return this.optimize.reduce((top, i) => (!top || Number(i.count || 0) > Number(top.count || 0) ? i : top), null);
    },
    // "Up to N", not a sum: one page can carry several issues, so adding the
    // counts would claim more pages than the site has. The largest single count
    // is the only figure that is certainly true of real pages.
    optimizeSummary() {
      const top = this.optimizeTopIssue;
      if (!top) return '';
      const kinds = this.optimize.length;
      const pages = Number(top.count || 0);
      const graded = Number(this.optimizeGraded || 0);
      // "Pieces", not "pages" — the graded sample is posts, pages and anything
      // else published (same wording as the finding this mirrors).
      const scope = graded ? `up to ${pages} of your ${graded} graded pieces` : `${pages} ${pages === 1 ? 'piece' : 'pieces'}`;
      const verb = pages === 1 ? 'has' : 'have';
      const kindsPart = kinds === 1 ? 'one kind of issue' : `${kinds} kinds of issue`;
      return `There's ${kindsPart} across your recent posts and pages — ${scope} ${verb} something worth fixing. The most common is “${top.label}”.`;
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
  watch: {
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
    // 'off' is the neutral fourth state: the feature this row measures is
    // switched off, so there is nothing to grade — the chip states the fact
    // in faint ink and the row stays out of every tally (see tiers.js).
    tagLabel(status) {
      return { pass: 'PASS', warn: 'WARN', fail: 'FAIL', off: 'OFF' }[status] || String(status || 'CHECK').toUpperCase();
    },
    // Set a page aside as "not cited content" (or restore it). The server returns the
    // recomputed score, which the parent swaps in — so the worklist, the set-aside list,
    // the counts, and the rung all update without a reload.
    async setAside(page, ignored) {
      if (this.busyIgnore || !page || !page.id) return;
      // No prompt in either direction: one page is a small, visible, one-click-undoable
      // move (it lands in the Set Aside list right below). Only the bulk actions confirm.
      this.busyIgnore = page.id;
      try {
        const res = await this.api.ignoreOptimize(page.id, ignored);
        if (res && res.score) this.$emit('score-updated', res.score);
      } catch (e) {
        this.$emit('flash', 'error', (e && e.message) || 'Could not update. Try again.');
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
        confirmLabel: 'Set All Aside',
        cancelLabel: 'Cancel',
        tone: 'default',
      });
      if (!ok) return;
      this.busyIssue = issue.id;
      try {
        const res = await this.api.ignoreOptimizeIssue(issue.id);
        if (res && res.score) this.$emit('score-updated', res.score);
      } catch (e) {
        this.$emit('flash', 'error', (e && e.message) || 'Could not update. Try again.');
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
        this.$emit('flash', 'error', (e && e.message) || 'Could not update. Try again.');
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
        <RefreshCrank
          :busy="refreshing"
          :aria-label="refreshing ? 'Re-running the readiness checks…' : 'Re-run the readiness checks'"
          :title="refreshing ? 'Re-running…' : 'Re-run the readiness checks'"
          @refresh="$emit('refresh')"
        />
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
              <!-- The spec citation stays a link, but whispers: v-tip (the app's
                   themed tooltip — never a native title) says what the code means,
                   so the chip doesn't have to try to say it with size. -->
              <a
                v-if="c.ar"
                class="ar-check__arid"
                href="https://agentready.org/"
                target="_blank"
                rel="noopener"
                v-tip="'The AgentReady spec requirement this check implements — the chip links to the spec.'"
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
          >{{ c.action.label }}</a>
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
          >{{ l.label }}</a>
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

      <!-- The summary IS the card now. This list used to be nine rows deep, each
           unfolding its own pages, with the set-aside ledger below it — so
           learning what was wrong with ONE page meant reading all nine and
           assembling the answer yourself. Today's content worklist is that same
           information turned ninety degrees, one row per page, so this card
           keeps the headline and hands over the working-through. The full
           by-issue view stays one click away, because its bulk actions have no
           equivalent per page. -->
      <!-- Nothing flagged. Said here rather than inside the disclosure, which is
           the one place an all-clear must never hide. -->
      <p v-if="!optimize.length" class="ar-optcheck__clear">Every graded post and page reads as citable. Anything set aside is listed below.</p>

      <div v-if="optimize.length" class="ar-optsum">
        <p class="ar-optsum__lead">{{ optimizeSummary }}</p>
        <button type="button" class="ar-btn ar-btn--small" @click="$emit('navigate', { tab: 'findings', anchor: 'ar-work' })">
          Work through them page by page
        </button>
      </div>

      <details v-if="optimize.length" class="ar-fold">
        <summary>
          Or work by issue<span class="ar-optcheck__n"> · {{ optimize.length }}</span>
        </summary>

      <ul class="ar-checks">
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
              >{{ busyIssue === issue.id ? 'Setting aside…' : 'Set All Aside' }}</button>
            </div>
            <small>{{ issue.why }}</small>
            <ul class="ar-optcheck__pages">
              <li v-for="p in issue.pages" :key="p.id" class="ar-optcheck__row">
                <a :href="p.url" target="_blank" rel="noopener" class="ar-optcheck__page">{{ p.title }}</a>
                <button
                  type="button"
                  class="ar-optcheck__aside"
                  :disabled="busyIgnore === p.id"
                  @click="setAside(p, true)"
                >Set Aside</button>
              </li>
            </ul>
            <p v-if="issue.pages.length < issue.count" class="ar-optcheck__more">
              Showing {{ issue.pages.length }} of {{ issue.count }}.
            </p>
          </div>
        </li>
      </ul>

      </details>

      <!-- Set aside — folded, not hidden. The COUNT stays on screen because a
           silent ledger is how pages get quietly excluded from a score forever;
           the list itself was the longest thing on the card and nobody reads a
           parked list every visit. One click still restores anything. -->
      <details v-if="optimizeIgnored.length" class="ar-fold ar-setaside">
        <summary class="ar-setaside__head">
          <strong class="ar-setaside__title">Set Aside <span class="ar-optcheck__n">· {{ optimizeIgnored.length }}</span></strong>
          <span class="ar-setaside__note">not cited content — left out of the score</span>
        </summary>
        <div class="ar-setaside__actions">
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
              <a :href="p.url" target="_blank" rel="noopener" class="ar-optcheck__page ar-optcheck__page--muted">{{ p.title }}</a>
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
      </details>
    </div>

    <!-- Search Opportunities LIVES on Visibility → Search now, seated
         directly under the search report it is carved from — the release's
         third room-move, on the content worklist's precedent: Readiness states
         headlines and hands over the working-through. This is the headline
         card left behind: shown exactly when the worklist itself would show
         (the moved card announces its state up through App), carrying the live
         count, never the rows. The old { tab: 'readiness',
         anchor: 'ar-group-search' } address is aliased in App.goTo, so no id
         here — a second ar-group-search in the DOM would shadow the real one. -->
    <div v-if="searchPointer && searchPointer.show" class="ar-card ar-checkgroup" :class="searchPointer.count ? 'is-warn' : ''">
      <div class="ar-checkgroup__head">
        <span class="ar-checkgroup__rung" aria-hidden="true"></span>
        <div class="ar-checkgroup__text">
          <h3 class="ar-checkgroup__name">Search Opportunities</h3>
          <p class="ar-checkgroup__blurb">
            Pages already showing up in search that could earn more — sitting just off
            page one, or on page one and being scrolled past.
          </p>
        </div>
        <span v-if="searchPointer.count || searchPointer.aside" class="ar-checkgroup__count">
          <template v-if="searchPointer.count">{{ searchPointer.count }} page{{ searchPointer.count === 1 ? '' : 's' }} to look at</template><template v-if="searchPointer.count && searchPointer.aside"> · </template><template v-if="searchPointer.aside">{{ searchPointer.aside }} set aside</template>
        </span>
      </div>
      <div class="ar-optsum">
        <p class="ar-optsum__lead">This worklist lives on Visibility → Search now, directly under the search report it reads from — the numbers above the to-dos.</p>
        <button type="button" class="ar-btn ar-btn--small" @click="$emit('navigate', { tab: 'visibility', view: 'performance', anchor: 'ar-group-search' })">
          Open Search Opportunities
        </button>
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
                  <p class="ar-live-cache__fix"><strong>Fix:</strong> bypass cache for <code>*.md</code>, <code>/llms.txt</code>, <code>/.well-known/*</code> and <code>*changes.json</code> at your CDN/proxy. <a href="https://heera.github.io/agentimus/user-manual/caching.html" target="_blank" rel="noopener">How to fix it</a></p>
                  <p class="ar-live-cache__fix">Can’t change your cache? Turn on <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings', anchor: 'ar-feat-bypass_shared_cache' })">Keep AI endpoints out of your cache</button> in Settings — Agentimus asks caches not to store these, which works with any cache that respects <code>Cache-Control</code> (most do).</p>
                </div>

                <ul v-if="live" class="ar-live__list">
                  <li v-for="r in live" :key="r.key" class="ar-live__row" :class="{ 'is-bad': !r.ok, 'is-cached': !!r.cache }">
                    <span class="ar-live__dot" aria-hidden="true"></span>
                    <span class="ar-live__label">{{ r.label }}</span>
                    <span class="ar-live__detail">{{ r.detail }}</span>
                    <span v-if="r.cache" class="ar-live__cachetag" v-tip="cacheTitle(r)">cached</span>
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
                  <p class="ar-live-cache__fix"><strong>How to block:</strong> <a href="https://heera.github.io/agentimus/user-manual/exposure.html#blocking-exposed-files" target="_blank" rel="noopener">Nginx / Apache / Cloudflare rules</a></p>
                </div>
                <div v-if="exposedEmpty.length" class="ar-live-cache" role="alert">
                  <strong class="ar-live-cache__title">{{ exposedEmpty.length }} reachable but empty file{{ exposedEmpty.length > 1 ? 's' : '' }}</strong>
                  <p>{{ exposedEmpty.length > 1 ? 'These are' : 'This is' }} publicly reachable but currently <strong>empty (0 bytes)</strong>, so {{ exposedEmpty.length > 1 ? 'they leak' : 'it leaks' }} nothing yet — but a file like <code>debug.log</code> can fill up later. Worth blocking or removing anyway. <a href="https://heera.github.io/agentimus/user-manual/exposure.html#blocking-exposed-files" target="_blank" rel="noopener">How to block</a></p>
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
                    <span v-if="r.state === 'exposed' && !r.empty && !isLocal" class="ar-live__cachetag" v-tip="`This file is publicly downloadable`">exposed</span>
                    <span v-else-if="r.state === 'exposed' && r.empty" class="ar-live__cachetag" v-tip="`Reachable but empty (0 bytes)`">empty</span>
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
