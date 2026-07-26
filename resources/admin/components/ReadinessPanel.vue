<script>
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
    api: { type: Object, required: true },
  },
  emits: ['refresh', 'navigate', 'flash', 'score-updated'],
  data() {
    return {
      live: null, liveRunning: false, exposure: null, exposureRunning: false,
      schemaOpen: false, busyIgnore: 0,
      // The scroll-affordance state for each dialog: true while more is below.
      liveMore: false, exposureMore: false,
    };
  },
  computed: {
    // The same checks, grouped under the Findable → Readable → Trusted rungs.
    groups() {
      return groupChecks(this.checks);
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
    tagLabel(status) {
      return { pass: 'PASS', warn: 'WARN', fail: 'FAIL' }[status] || String(status || 'CHECK').toUpperCase();
    },
    // Set a page aside as "not cited content" (or restore it). The server returns the
    // recomputed score, which the parent swaps in — so the worklist, the set-aside list,
    // the counts, and the rung all update without a reload.
    async setAside(page, ignored) {
      if (this.busyIgnore || !page || !page.id) return;
      // Reassure at the moment of the click: setting aside is non-destructive and
      // reversible. (Restoring is safe and obvious, so it needs no prompt.)
      if (ignored) {
        const ok = await confirm({
          title: `Set “${page.title}” aside?`,
          message: 'Nothing is deleted or changed — it stays published exactly as it is. It’s just left out of your content-optimization score. You can restore it here anytime.',
          confirmLabel: 'Set aside',
          cancelLabel: 'Cancel',
          tone: 'default',
        });
        if (!ok) return;
      }
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
        <h2 class="ar-card__title">Readiness report</h2>
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
      <!-- The per-PAGE gaps live on their own screen — this report is site-level. One
           quiet signpost, because "my score is fine but my pages are bare" is the gap
           an owner can't see from here. -->
      <p class="ar-readiness__bulkhint">
        Pages missing their AI description, topics or image alt text?
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'fill-gaps' })">
          Fill the gaps in bulk →
        </button>
      </p>
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
            <strong>{{ c.label }}</strong>
            <small>{{ c.detail }}</small>
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
          </div>
          <span class="ar-check__tag" :class="`is-${c.status}`">{{ tagLabel(c.status) }}</span>
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
          <h3 class="ar-checkgroup__name">Optimize your content</h3>
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
            <strong>{{ issue.label }} <span class="ar-optcheck__n">· {{ issue.countLabel || `${issue.count} ${issue.count === 1 ? 'item' : 'items'}` }}</span></strong>
            <small>{{ issue.why }}</small>
            <ul class="ar-optcheck__pages">
              <li v-for="p in issue.pages" :key="p.id" class="ar-optcheck__row">
                <a :href="p.url" target="_blank" rel="noopener" class="ar-optcheck__page">{{ p.title }} ↗</a>
                <button
                  type="button"
                  class="ar-optcheck__aside"
                  :disabled="busyIgnore === p.id"
                  @click="setAside(p, true)"
                >Set aside</button>
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
        <p class="ar-setaside__head">
          Set aside · {{ optimizeIgnored.length }}
          <span class="ar-setaside__note">not cited content — left out of the score</span>
        </p>
        <ul class="ar-optcheck__pages">
          <li v-for="p in optimizeIgnored" :key="p.id" class="ar-optcheck__row">
            <a :href="p.url" target="_blank" rel="noopener" class="ar-optcheck__page ar-optcheck__page--muted">{{ p.title }} ↗</a>
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
                <h2 id="ar-live-title" class="ar-modal__title">What agents actually receive</h2>
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
                <h2 id="ar-exposure-title" class="ar-modal__title">Exposed-files check</h2>
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
