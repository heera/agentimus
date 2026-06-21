<script>
export default {
  name: 'ActivityPanel',
  props: {
    data: { type: Object, default: () => ({}) },
    summary: { type: Object, default: null },
    loaded: { type: Boolean, default: false },
    refreshing: { type: Boolean, default: false },
  },
  emits: ['refresh', 'clear', 'navigate', 'block'],
  data() {
    return { feedMore: false };
  },
  mounted() {
    window.addEventListener('resize', this.updateFeedHint);
    // Init the cue/button state on first paint — data may already be present on
    // remount, so the recentGrouped watcher won't fire to set feedMore.
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
    recent() {
      return this.data.recent || [];
    },
    // Abuse/threat signals computed server-side (UA-only heuristics). Empty-safe
    // so the "Suspicious activity" card simply doesn't render when nothing's flagged.
    threats() {
      return this.data.threats || { sources: [], counts: { new: 0, heavy: 0, spoof: 0 }, blockingOn: false };
    },
    // Collapse the raw feed into one row per identical request
    // (client + endpoint + User-Agent), with a hit count and the latest time.
    // `recent` is already newest-first, so the first time we see a key is its
    // most recent hit; later duplicates just bump the count. Keeps the list
    // short even when a crawler hammers the same endpoint.
    recentGrouped() {
      const groups = new Map();
      for (const r of this.recent) {
        const key = `${r.agent}|${r.endpoint}|${r.ua || ''}`;
        const g = groups.get(key);
        if (g) {
          g.count += 1;
        } else {
          groups.set(key, { agent: r.agent, endpoint: r.endpoint, ua: r.ua, at: r.at, count: 1 });
        }
      }
      return Array.from(groups.values());
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
    // One-click block from a flagged row. Confirms first (it also turns
    // enforcement on), then emits the payload the /activity/block endpoint wants:
    // {spoofed:true} arms the whole scanner class, {ua} blocks a derived token.
    blockSource(s) {
      const msg = 'spoofed' === s.action
        ? 'Block spoofed / scanner user-agents? They will get a 403 at your discovery & llms endpoints. This also turns agent blocking on.'
        : `Block "${s.token}"? Requests with this user-agent will get a 403 at your discovery & llms endpoints. This also turns agent blocking on.`;
      if (window.confirm(msg)) {
        this.$emit('block', 'spoofed' === s.action ? { spoofed: true } : { ua: s.ua });
      }
    },
    // Plain-English note for a flagged row that isn't safely one-click-blockable.
    reasonText(reason) {
      if ('no-ua' === reason) return 'No User-Agent to match';
      if ('no-token' === reason) return 'Looks like a browser — block manually if needed';
      return '';
    },
    onFeedScroll() {
      this.updateFeedHint();
    },
    // Show the bottom fade + chevron only while the feed has more rows below.
    updateFeedHint() {
      const el = this.$refs.feedScroll;
      this.feedMore = !!el && el.scrollHeight - el.scrollTop - el.clientHeight > 4;
    },
    // Clicking the chevron nudges the feed down by ~one viewport of rows. The
    // list already scrolls on its own; this just makes the cue an affordance.
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

      <!-- Suspicious activity — only shown when something is actually flagged -->
      <section v-if="threats.sources.length" class="ar-card ar-susp">
        <div class="ar-card__head">
          <div>
            <h2 class="ar-card__title">Activity to review</h2>
            <p class="ar-card__lead">
              Clients worth a closer look — new, unusually busy, or disguising what they are.
              It only flags them for you; nothing is blocked unless you choose to.
            </p>
          </div>
          <div class="ar-susp-counts">
            <span v-if="threats.counts.spoof" class="ar-susp-badge is-spoof">{{ threats.counts.spoof }} spoofed</span>
            <span v-if="threats.counts.heavy" class="ar-susp-badge is-heavy">{{ threats.counts.heavy }} high-volume</span>
            <span v-if="threats.counts.new" class="ar-susp-badge is-new">{{ threats.counts.new }} new</span>
          </div>
        </div>

        <p v-if="!threats.blockingOn" class="ar-susp-banner">
          Blocking is off — flagged clients are still served. Use <strong>Block</strong> below, or turn it on in
          <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' })">Settings</button>.
        </p>

        <ul class="ar-susp-list">
          <li v-for="(s, i) in threats.sources" :key="i" class="ar-susp-row">
            <div class="ar-susp-row__info">
              <div class="ar-susp-row__head">
                <span class="ar-susp-row__agent">{{ s.agent }}</span>
                <!-- Spoof is the agent's identity (its name is already "Likely
                     spoof/scanner"), so badges carry only the behavioral signals. -->
                <span v-if="s.flags.heavy || s.flags.new" class="ar-susp-badges">
                  <span v-if="s.flags.heavy" class="ar-susp-badge is-heavy">high volume</span>
                  <span v-if="s.flags.new" class="ar-susp-badge is-new">new</span>
                </span>
              </div>
              <code class="ar-susp-row__ua" :title="s.ua">{{ s.ua || 'No User-Agent' }}</code>
              <div class="ar-susp-row__meta">
                {{ s.hits }} hit{{ 1 === s.hits ? '' : 's' }}<template v-if="s.recent"> · {{ s.recent }} in last hr</template><template v-if="s.lastSeen"> · {{ ago(s.lastSeen) }}</template>
              </div>
            </div>
            <div class="ar-susp-row__action">
              <span v-if="s.blocked" class="ar-susp-blocked">✓ Blocked</span>
              <button v-else-if="'agent' === s.action" type="button" class="ar-susp-block" @click="blockSource(s)">Block {{ s.token }}</button>
              <button v-else-if="'spoofed' === s.action" type="button" class="ar-susp-block" @click="blockSource(s)">Block scanners</button>
              <span v-else class="ar-susp-reason">{{ reasonText(s.reason) }}</span>
            </div>
          </li>
        </ul>
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
        <h2 class="ar-card__title">
          Recent requests
          <span v-if="recent.length" class="ar-card__tag">Latest {{ recent.length }}</span>
        </h2>
        <p class="ar-card__lead">
          Identified from the User-Agent — major AI crawlers self-identify; scripts and anonymous
          clients may not. Repeat hits from the same client are grouped. Your own logged-in
          visits aren't recorded.
        </p>
        <div v-if="recentGrouped.length" class="ar-act-feedwrap">
          <ul ref="feedScroll" class="ar-act-feed" @scroll="onFeedScroll">
            <li v-for="(r, i) in recentGrouped" :key="i">
              <span class="ar-act-feed__agent">{{ r.agent }}</span>
              <code class="ar-act-feed__ep">{{ r.endpoint }}</code>
              <code v-if="r.ua" class="ar-act-feed__ua" :title="r.ua">{{ r.ua }}</code>
              <span v-else class="ar-act-feed__ua is-empty">no User-Agent</span>
              <span class="ar-act-feed__count" :title="r.count > 1 ? `${r.count} hits` : null">{{ r.count > 1 ? '×' + r.count : '' }}</span>
              <span class="ar-act-feed__at">{{ ago(r.at) }}</span>
            </li>
          </ul>
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
  </div>
</template>
