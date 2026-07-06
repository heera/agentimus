<script>
import { groupChecks } from '../tiers.js';
import { runAll, runExposureScan } from '../livecheck.js';
import SchemaPreview from './SchemaPreview.vue';

export default {
  name: 'ReadinessPanel',
  components: { SchemaPreview },
  props: {
    checks: { type: Array, default: () => [] },
    refreshing: { type: Boolean, default: false },
    liveConfig: { type: Object, default: () => ({}) },
    isLocal: { type: Boolean, default: false },
    api: { type: Object, required: true },
  },
  emits: ['refresh', 'navigate', 'flash'],
  data() {
    return { live: null, liveRunning: false, exposure: null, exposureRunning: false, schemaOpen: false };
  },
  computed: {
    // The same checks, grouped under the Findable → Readable → Trusted rungs.
    groups() {
      return groupChecks(this.checks);
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
    liveOpen(open) {
      if (!open) return;
      this.$nextTick(() => {
        if (this.$refs.liveDialog) this.$refs.liveDialog.focus();
      });
    },
    exposureOpen(open) {
      if (!open) return;
      this.$nextTick(() => {
        if (this.$refs.exposureDialog) this.$refs.exposureDialog.focus();
      });
    },
  },
  methods: {
    tagLabel(status) {
      return { pass: 'PASS', warn: 'WARN', fail: 'FAIL' }[status] || String(status || 'CHECK').toUpperCase();
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
        this.live = await runAll(this.liveConfig);
      } finally {
        this.liveRunning = false;
      }
    },
    // Dismiss the results. A no-op mid-run (the fetch finishes in ~1s) so a stray
    // Esc/backdrop click can't close an empty shell that's about to repopulate.
    closeLive() {
      if (this.liveRunning) return;
      this.live = null;
    },
    // Scan the site's own URL for publicly-readable sensitive files. Browser-side,
    // same-origin, on click only — the server makes no request (see livecheck.js).
    async scanExposure() {
      if (this.exposureRunning) return;
      this.exposureRunning = true;
      try {
        this.exposure = await runExposureScan(this.liveConfig);
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
  <section class="ar-card">
    <div class="ar-card__head ar-card__head--inline">
      <h2 class="ar-card__title">Readiness report</h2>
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
        <button type="button" class="ar-btn" :disabled="refreshing" @click="$emit('refresh')">
          {{ refreshing ? 'Running…' : 'Re-run' }}
        </button>
      </div>
    </div>

    <div
      v-for="g in groups"
      :id="`ar-group-${g.key}`"
      :key="g.key"
      class="ar-checkgroup"
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
              <div class="ar-modal__scroll">
                <!-- A shared cache served stored copies → those fetches skip WordPress,
                     so the activity log under-counts and discovery can go stale. -->
                <div v-if="cachedChecks.length" class="ar-live-cache" role="alert">
                  <strong class="ar-live-cache__title">A cache is sitting in front of your AI endpoints</strong>
                  <p>{{ cachedVia }} returned a <em>stored</em> copy of {{ cachedChecks.length }} endpoint{{ cachedChecks.length > 1 ? 's' : '' }} ({{ cachedNames }}), so those agent fetches never reach WordPress. Your <strong>Activity log under-counts</strong> real AI traffic, and freshness-sensitive endpoints (the change feed, page markdown) can go <strong>stale</strong>.</p>
                  <p class="ar-live-cache__fix"><strong>Fix:</strong> bypass cache for <code>*.md</code>, <code>/llms.txt</code>, <code>/.well-known/*</code> and <code>*changes.json</code> at your CDN/proxy. <a href="https://heera.github.io/agentimus/user-manual/caching.html" target="_blank" rel="noopener">How to fix it ↗</a></p>
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
              <div class="ar-modal__scroll">
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
                    <span v-if="r.state === 'exposed' && !r.empty && !isLocal" class="ar-live__cachetag" title="This file is publicly downloadable">exposed</span>
                    <span v-else-if="r.state === 'exposed' && !r.empty && isLocal" class="ar-live__cachetag" title="Would be downloadable once deployed">on deploy</span>
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
