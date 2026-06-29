<script>
/**
 * Nav-bar "Activity to review" menu: a count badge that opens a dropdown of the
 * flagged clients still needing a decision (blocked ones are handled, so they're
 * filtered out). Visible from every tab. Block uses the same inline two-step
 * confirm as the old dashboard panel.
 */
export default {
  name: 'ReviewMenu',
  props: {
    threats: { type: Object, default: () => ({ sources: [], counts: {}, blockingOn: false }) },
    blocking: { type: Object, default: null },
    allowing: { type: Object, default: null },
    // Whether activity logging is on. The bell is a persistent anchor whenever it
    // is — a stable, quiet resting state (no count badge) rather than vanishing the
    // moment the queue clears. With logging off there's nothing to watch, so hide it.
    enabled: { type: Boolean, default: false },
    // Live updates: whether this screen is auto-refreshing, and how often (seconds),
    // for the toggle's label. The parent owns the state and the interval.
    live: { type: Boolean, default: false },
    liveInterval: { type: Number, default: 15 },
  },
  emits: ['block', 'allow', 'navigate', 'set-live'],
  data() {
    return { open: false };
  },
  computed: {
    // Pending = still needs a decision. A blocked client is handled (and managed in
    // Settings), so it's neither listed, counted, nor surfaced here at all.
    pending() {
      return (this.threats.sources || []).filter((s) => !s.blocked);
    },
    count() {
      return this.pending.length;
    },
    // Counts reflect ONLY the pending rows shown here, so the chips, list and badge
    // always agree (the server's threats.counts include already-blocked sources).
    counts() {
      const c = { new: 0, heavy: 0, spoof: 0 };
      for (const s of this.pending) {
        if (s.flags.new) c.new += 1;
        if (s.flags.heavy) c.heavy += 1;
        if (s.flags.spoof) c.spoof += 1;
      }
      return c;
    },
  },
  mounted() {
    document.addEventListener('click', this.onDocClick);
    document.addEventListener('keydown', this.onKey);
  },
  beforeUnmount() {
    document.removeEventListener('click', this.onDocClick);
    document.removeEventListener('keydown', this.onKey);
  },
  methods: {
    toggle() {
      this.open = !this.open;
    },
    close() {
      this.open = false;
    },
    onDocClick(e) {
      if (this.open && this.$el && !this.$el.contains(e.target)) this.close();
    },
    onKey(e) {
      if ('Escape' === e.key) this.close();
    },
    doBlock(s) {
      this.$emit('block', 'spoofed' === s.action ? { spoofed: true } : { ua: s.ua });
    },
    // Whether this row's block request is currently in flight (shows "Blocking…").
    isBlocking(s) {
      const b = this.blocking;
      if (!b) return false;
      return b.spoofed ? 'spoofed' === s.action : b.ua === s.ua;
    },
    // "Allow" / trust this client (an 'agent' row) — adds it to the allowlist so
    // it's never blocked and never flagged for review again.
    doAllow(s) {
      this.$emit('allow', { ua: s.ua });
    },
    isAllowing(s) {
      return !!this.allowing && this.allowing.ua === s.ua;
    },
    reasonText(reason) {
      if ('no-ua' === reason) return 'No User-Agent to match';
      if ('no-token' === reason) return 'No safe one-click rule — block in Settings if needed';
      return '';
    },
    // A recognised crawler's real name (ShapBot, GPTBot) wins over the generic
    // classifier label ("Other bot"), so the row title says who it actually is.
    rowTitle(s) {
      return (s.known && s.known.name) || (s.guide && s.guide.name) || s.agent;
    },
    // Plain-English category for a recognised crawler — what an owner needs to
    // judge it, without knowing the token: an AI crawler is a real choice to make.
    kindLabel(kind) {
      return { ai: 'AI crawler', seo: 'SEO crawler', search: 'Search engine', social: 'Social preview' }[kind] || 'Crawler';
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
  },
};
</script>

<template>
  <div v-if="enabled || open" class="ar__review" :class="{ 'is-open': open, 'is-quiet': !count }">
    <button
      type="button"
      class="ar__review-btn"
      :aria-expanded="open"
      :aria-label="count ? `${count} client${1 === count ? '' : 's'} to review` : 'Activity to review — nothing flagged'"
      @click.stop="toggle"
    >
      <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M8 2a3.2 3.2 0 0 0-3.2 3.2c0 3.2-1.3 4.2-1.3 4.2h9c0 0-1.3-1-1.3-4.2A3.2 3.2 0 0 0 8 2Z" />
        <path d="M6.8 12.2a1.3 1.3 0 0 0 2.4 0" />
      </svg>
      <span v-if="count" class="ar__review-count">{{ count }}</span>
    </button>

    <div v-if="open" class="ar__review-pop" role="dialog" aria-label="Activity to review" @click.stop>
      <div class="ar__review-pop-head">
        <strong class="ar__review-title">Activity to review</strong>
        <div class="ar__review-head-right">
          <div class="ar-susp-counts">
            <span v-if="counts.spoof" class="ar-susp-badge is-spoof">{{ counts.spoof }} spoofed</span>
            <span v-if="counts.heavy" class="ar-susp-badge is-heavy">{{ counts.heavy }} high-volume</span>
            <span v-if="counts.new" class="ar-susp-badge is-new">{{ counts.new }} new</span>
          </div>
          <button
            type="button"
            class="ar__live"
            :class="{ 'is-on': live }"
            role="switch"
            :aria-checked="live"
            :aria-label="`Auto-refresh — check for new activity every ${liveInterval} seconds`"
            :title="live ? `Auto-refresh is on — checking for new activity every ${liveInterval}s. Click to stop.` : `Auto-refresh is off — click to check for new activity every ${liveInterval}s, without reloading.`"
            @click="$emit('set-live', !live)"
          >
            <span class="ar__live-dot" aria-hidden="true"></span>
            <span class="ar__live-label">Auto-refresh</span>
          </button>
        </div>
      </div>
      <p class="ar__review-lead">New, unusually busy, or disguising what they are. Nothing is blocked unless you choose to.</p>

      <p v-if="!threats.blockingOn && pending.length" class="ar-susp-banner">
        Blocking is off — flagged clients are still served. Use <strong>Block</strong>, or turn it on in
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' }); close()">Settings</button>.
      </p>

      <ul v-if="pending.length" class="ar-susp-list ar__review-list">
        <li v-for="(s, i) in pending" :key="i" class="ar-susp-row">
          <div class="ar-susp-row__info">
            <div class="ar-susp-row__head">
              <span class="ar-susp-row__agent">{{ rowTitle(s) }}</span>
              <span v-if="s.flags.heavy || s.flags.new" class="ar-susp-badges">
                <span v-if="s.flags.heavy" class="ar-susp-badge is-heavy">high volume</span>
                <span v-if="s.flags.new" class="ar-susp-badge is-new">new</span>
              </span>
            </div>
            <div v-if="s.known" class="ar-susp-row__known">
              <span class="ar-susp-kind" :class="'is-' + s.known.kind">{{ kindLabel(s.known.kind) }}</span>
              <span class="ar-susp-row__by">{{ s.known.operator }}</span>
              <a v-if="s.known.url" class="ar-susp-row__learn" :href="s.known.url" target="_blank" rel="noopener noreferrer">what is this?</a>
            </div>
            <div v-else-if="s.guide && (s.guide.url || s.guide.lookup)" class="ar-susp-row__known">
              <template v-if="s.guide.url">
                <span class="ar-susp-row__by">Self-declared</span>
                <a class="ar-susp-row__learn" :href="s.guide.url" target="_blank" rel="noopener noreferrer nofollow" :title="'The page this client points to in its own User-Agent — not verified, open with care.'">{{ s.guide.host || 'its site' }}</a>
                <span class="ar-susp-row__unverified">not verified</span>
              </template>
              <a v-else class="ar-susp-row__learn" :href="s.guide.lookup" target="_blank" rel="noopener noreferrer nofollow" title="Search the web to identify this crawler">Look it up</a>
            </div>
            <code class="ar-susp-row__ua" :title="s.ua">{{ s.ua || 'No User-Agent' }}</code>
            <div class="ar-susp-row__meta">
              {{ s.hits }} hit{{ 1 === s.hits ? '' : 's' }}<template v-if="s.recent"> · {{ s.recent }} in last hr</template><template v-if="s.lastSeen"> · {{ ago(s.lastSeen) }}</template>
            </div>
          </div>
          <div class="ar-susp-row__action">
            <template v-if="'agent' === s.action">
              <button type="button" class="ar-susp-block" :disabled="isBlocking(s) || isAllowing(s)" @click="doBlock(s)">
                {{ isBlocking(s) ? 'Blocking…' : 'Block ' + s.token }}
              </button>
              <button type="button" class="ar-susp-allow" :disabled="isBlocking(s) || isAllowing(s)" @click="doAllow(s)">
                {{ isAllowing(s) ? 'Allowing…' : 'Allow' }}
              </button>
            </template>
            <button v-else-if="'spoofed' === s.action" type="button" class="ar-susp-block" :disabled="isBlocking(s)" @click="doBlock(s)">
              {{ isBlocking(s) ? 'Blocking…' : 'Block scanners' }}
            </button>
            <span v-else class="ar-susp-reason">{{ reasonText(s.reason) }}</span>
          </div>
        </li>
      </ul>
      <p v-else class="ar__review-empty">Nothing needs a look right now.</p>
    </div>
  </div>
</template>
