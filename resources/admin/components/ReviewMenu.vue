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
  },
  emits: ['block', 'navigate'],
  data() {
    return { open: false, confirmKey: null };
  },
  computed: {
    // Pending = still needs a decision. A blocked client is handled, not "to review".
    pending() {
      return (this.threats.sources || []).filter((s) => !s.blocked);
    },
    blockedCount() {
      return (this.threats.sources || []).filter((s) => s.blocked).length;
    },
    count() {
      return this.pending.length;
    },
    counts() {
      return this.threats.counts || { new: 0, heavy: 0, spoof: 0 };
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
      this.confirmKey = null;
    },
    close() {
      this.open = false;
      this.confirmKey = null;
    },
    onDocClick(e) {
      if (this.open && this.$el && !this.$el.contains(e.target)) this.close();
    },
    onKey(e) {
      if ('Escape' === e.key) this.close();
    },
    armBlock(key) {
      this.confirmKey = key;
    },
    cancelBlock() {
      this.confirmKey = null;
    },
    doBlock(s) {
      this.confirmKey = null;
      this.$emit('block', 'spoofed' === s.action ? { spoofed: true } : { ua: s.ua });
    },
    reasonText(reason) {
      if ('no-ua' === reason) return 'No User-Agent to match';
      if ('no-token' === reason) return 'Looks like a browser — block manually if needed';
      return '';
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
  <div v-if="count > 0 || open" class="ar__review" :class="{ 'is-open': open }">
    <button
      type="button"
      class="ar__review-btn"
      :aria-expanded="open"
      :aria-label="`${count} client${1 === count ? '' : 's'} to review`"
      @click.stop="toggle"
    >
      <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M8 2a3.2 3.2 0 0 0-3.2 3.2c0 3.2-1.3 4.2-1.3 4.2h9c0 0-1.3-1-1.3-4.2A3.2 3.2 0 0 0 8 2Z" />
        <path d="M6.8 12.2a1.3 1.3 0 0 0 2.4 0" />
      </svg>
      <span v-if="count" class="ar__review-count">{{ count }}</span>
      <svg class="ar__review-caret" viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
    </button>

    <div v-if="open" class="ar__review-pop" role="dialog" aria-label="Activity to review" @click.stop>
      <div class="ar__review-pop-head">
        <strong class="ar__review-title">Activity to review</strong>
        <div class="ar-susp-counts">
          <span v-if="counts.spoof" class="ar-susp-badge is-spoof">{{ counts.spoof }} spoofed</span>
          <span v-if="counts.heavy" class="ar-susp-badge is-heavy">{{ counts.heavy }} high-volume</span>
          <span v-if="counts.new" class="ar-susp-badge is-new">{{ counts.new }} new</span>
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
              <span class="ar-susp-row__agent">{{ s.agent }}</span>
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
            <template v-if="'agent' === s.action || 'spoofed' === s.action">
              <span v-if="confirmKey === i" class="ar-susp-confirm">
                <button type="button" class="ar-susp-block ar-susp-block--go" @click="doBlock(s)">Confirm</button>
                <button type="button" class="ar-susp-cancel" @click="cancelBlock">Cancel</button>
              </span>
              <button v-else type="button" class="ar-susp-block" @click="armBlock(i)">
                {{ 'spoofed' === s.action ? 'Block scanners' : 'Block ' + s.token }}
              </button>
            </template>
            <span v-else class="ar-susp-reason">{{ reasonText(s.reason) }}</span>
          </div>
        </li>
      </ul>
      <p v-else class="ar__review-empty">Nothing needs a look right now.</p>

      <button v-if="blockedCount" type="button" class="ar__review-foot" @click="$emit('navigate', { tab: 'settings' }); close()">
        ✓ {{ blockedCount }} already blocked · manage in Settings
      </button>
    </div>
  </div>
</template>
