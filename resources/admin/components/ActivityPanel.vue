<script>
export default {
  name: 'ActivityPanel',
  props: {
    data: { type: Object, default: () => ({}) },
    summary: { type: Object, default: null },
    refreshing: { type: Boolean, default: false },
  },
  emits: ['refresh', 'clear', 'navigate'],
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
    recent() {
      return this.data.recent || [];
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
  },
  methods: {
    barHeight(hits) {
      const h = Math.round((hits / this.maxDaily) * 100);
      return `${hits > 0 ? Math.max(8, h) : 2}%`;
    },
    pct(hits, max) {
      return `${Math.max(2, Math.round((hits / max) * 100))}%`;
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
    confirmClear() {
      if (window.confirm('Clear the entire agent activity log? This cannot be undone.')) {
        this.$emit('clear');
      }
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

    <!-- Logging disabled -->
    <section v-if="data.enabled === false" class="ar-card">
      <h2 class="ar-card__title">Endpoint activity</h2>
      <p class="ar-card__lead">
        Activity logging is off. Enable <strong>Agent activity log</strong> in
        Settings → Features to record who fetches your discovery and llms endpoints.
      </p>
    </section>

    <template v-else>
      <!-- Summary + controls -->
      <section class="ar-card">
        <div class="ar-card__head">
          <div>
            <h2 class="ar-card__title">Endpoint activity</h2>
            <p class="ar-card__lead">
              Who fetched your discovery &amp; llms endpoints — AI agents, crawlers and browsers.
              Local-only, no IP logged.
            </p>
          </div>
          <div class="ar-act-controls">
            <button type="button" class="ar-btn" :disabled="refreshing" @click="$emit('refresh')">
              {{ refreshing ? 'Refreshing…' : 'Refresh' }}
            </button>
            <button type="button" class="ar-linkbtn" @click="confirmClear">Clear log</button>
          </div>
        </div>

        <div class="ar-wd-stats ar-act-stats">
          <div class="ar-wd-stat"><strong>{{ totals.today }}</strong><span>today</span></div>
          <div class="ar-wd-stat"><strong>{{ totals.week }}</strong><span>7 days</span></div>
          <div class="ar-wd-stat"><strong>{{ totals.month }}</strong><span>{{ data.window || 30 }} days</span></div>
          <div class="ar-wd-stat"><strong>{{ totals.agents }}</strong><span>clients</span></div>
        </div>

        <div class="ar-act-spark" role="img" aria-label="Hits per day">
          <div v-for="(d, i) in daily" :key="i" class="ar-act-bar" :title="`${d.date}: ${d.hits}`">
            <span class="ar-act-bar__fill" :class="{ 'is-zero': d.hits === 0 }" :style="{ height: barHeight(d.hits) }"></span>
          </div>
        </div>
        <p class="ar-act-sparkcap">Hits per day · last {{ daily.length }} days</p>
      </section>

      <!-- Top agents + by endpoint -->
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

      <!-- Recent feed -->
      <section class="ar-card">
        <h2 class="ar-card__title">Recent requests</h2>
        <p class="ar-card__lead">
          Identified from the User-Agent — major AI crawlers self-identify; scripts and anonymous
          clients may not. Your own logged-in visits aren't recorded.
        </p>
        <ul v-if="recent.length" class="ar-act-feed">
          <li v-for="(r, i) in recent" :key="i">
            <div class="ar-act-feed__row">
              <span class="ar-act-feed__agent">{{ r.agent }}</span>
              <code class="ar-act-feed__ep">{{ r.endpoint }}</code>
              <span class="ar-act-feed__at">{{ ago(r.at) }}</span>
            </div>
            <code v-if="r.ua" class="ar-act-feed__ua" :title="r.ua">{{ r.ua }}</code>
            <span v-else class="ar-act-feed__ua is-empty">no User-Agent sent</span>
          </li>
        </ul>
        <p v-else class="ar-wd-empty">
          No requests recorded yet. Agents that fetch your discovery/llms endpoints will appear here.
        </p>
      </section>
    </template>
  </div>
</template>
