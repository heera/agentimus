<script>
import { groupChecks } from '../tiers.js';
import { runAll } from '../livecheck.js';

export default {
  name: 'ReadinessPanel',
  props: {
    checks: { type: Array, default: () => [] },
    refreshing: { type: Boolean, default: false },
    liveConfig: { type: Object, default: () => ({}) },
  },
  emits: ['refresh', 'navigate'],
  data() {
    return { live: null, liveRunning: false };
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
  },
  watch: {
    // Land focus on the dialog when it opens so Esc closes it and it reads as modal.
    liveOpen(open) {
      if (!open) return;
      this.$nextTick(() => {
        if (this.$refs.liveDialog) this.$refs.liveDialog.focus();
      });
    },
  },
  methods: {
    tagLabel(status) {
      return { pass: 'PASS', warn: 'WARN', fail: 'FAIL' }[status] || String(status || 'CHECK').toUpperCase();
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
  },
};
</script>

<template>
  <section class="ar-card">
    <div class="ar-card__head ar-card__head--inline">
      <h2 class="ar-card__title">Readiness report</h2>
      <div class="ar-card__actions">
        <button type="button" class="ar-btn" :disabled="liveRunning" @click="verifyLive">
          {{ liveRunning ? 'Checking…' : 'Verify live' }}
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
                <ul v-if="live" class="ar-live__list">
                  <li v-for="r in live" :key="r.key" class="ar-live__row" :class="{ 'is-bad': !r.ok }">
                    <span class="ar-live__dot" aria-hidden="true"></span>
                    <span class="ar-live__label">{{ r.label }}</span>
                    <span class="ar-live__detail">{{ r.detail }}</span>
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
  </section>
</template>
