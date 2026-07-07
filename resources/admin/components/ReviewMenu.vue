<script>
/**
 * Nav-bar "Review queue" menu: a count badge that opens a dropdown of the flagged
 * clients still needing a decision (blocked ones are handled, so they're filtered out).
 * Visible from every tab.
 *
 * Design note — HONEST by construction. We show only signals we actually have: the
 * forward-confirmed reverse-DNS verdict (for the 5 verifiable search engines), the
 * known-bot identity, the legacy-device spoof heuristic, self-declaration, volume and
 * novelty. We deliberately DON'T fabricate a trust %, ASN/IP-ownership breakdown, or a
 * "Block IP" button — the plugin stores no IP and isn't a firewall, so those would be
 * invented. Verification is a three-state fact (verified / failed / not-checked), never a
 * score.
 */
export default {
  name: 'ReviewMenu',
  props: {
    threats: { type: Object, default: () => ({ sources: [], counts: {}, blockingOn: false }) },
    blocking: { type: Object, default: null },
    allowing: { type: Object, default: null },
    dismissing: { type: Object, default: null },
    // Whether activity logging is on. The bell is a persistent anchor whenever it
    // is — a stable, quiet resting state (no count badge) rather than vanishing the
    // moment the queue clears. With logging off there's nothing to watch, so hide it.
    enabled: { type: Boolean, default: false },
    // Live updates: whether this screen is auto-refreshing, and how often (seconds),
    // for the toggle's label. The parent owns the state and the interval.
    live: { type: Boolean, default: false },
    liveInterval: { type: Number, default: 15 },
  },
  emits: ['block', 'allow', 'dismiss', 'navigate', 'set-live', 'flash'],
  data() {
    // `tab` is the active filter (all | attention | new). `openRow` holds the identity key
    // of the one row whose Details panel is expanded (keyed, not indexed, so a live refresh
    // that reorders rows keeps the right one open).
    return {
      open: false,
      tab: 'all',
      openRow: null,
      // Styled hover tooltip for the User-Agent (the dark "click to copy" bubble).
      // position:fixed + teleported to <body>, but pinned to BOTH panel edges (left `x` +
      // `right`) so it fills the panel with equal side gaps; `caret` points it at the UA.
      uaTip: { show: false, text: '', x: 0, right: null, y: 0, caret: 16, below: false },
    };
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
    // "Needs attention": the clear problems — a caught impersonator or a legacy-device
    // scanner. These are what an owner should look at first.
    attention() {
      return this.pending.filter((s) => 'spoofed' === s.verdict || (s.flags && s.flags.spoof));
    },
    // Show the Flagged filter only when it's a PROPER subset of All — otherwise the tab
    // bar adds nothing (every row already flagged, or none is). Novelty needs no tab of
    // its own: every new row already carries a "New" badge inline.
    showReviewTab() {
      return this.attention.length > 0 && this.attention.length < this.count;
    },
    // The effective tab — falls back to All if Flagged isn't showing, so a data change can
    // never leave the list filtered by a hidden tab.
    activeTab() {
      return 'attention' === this.tab && this.showReviewTab ? 'attention' : 'all';
    },
    // The rows the active tab shows (server already sorts worst-first).
    shown() {
      return 'attention' === this.activeTab ? this.attention : this.pending;
    },
    // Rows paired with their computed view-model, so card() runs once per row.
    shownCards() {
      return this.shown.map((s) => ({ s, c: this.card(s) }));
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
    // ---- Decisions -------------------------------------------------------------
    doBlock(s) {
      this.$emit('block', 'spoofed' === s.action ? { spoofed: true } : { ua: s.ua });
    },
    isBlocking(s) {
      const b = this.blocking;
      if (!b) return false;
      return b.spoofed ? 'spoofed' === s.action : b.ua === s.ua;
    },
    doAllow(s) {
      this.$emit('allow', { ua: s.ua });
    },
    isAllowing(s) {
      return !!this.allowing && this.allowing.ua === s.ua;
    },
    doDismiss(s) {
      this.$emit('dismiss', { ua: s.ua, hits: s.hits });
    },
    isDismissing(s) {
      return !!this.dismissing && this.dismissing.ua === s.ua;
    },
    // A stable identity for a pending row (survives a reorder on live refresh).
    rowKey(s) {
      return `${s.action}|${s.token || ''}|${s.ua || ''}`;
    },
    toggleDetails(s) {
      const key = this.rowKey(s);
      this.openRow = this.openRow === key ? null : key;
    },
    isOpen(s) {
      return this.openRow === this.rowKey(s);
    },
    // ---- The compact row view-model — one calm, honest summary per client ------
    card(s) {
      // Caught impersonating a verifiable search engine (the one hard signal).
      if ('spoofed' === s.verdict) {
        return {
          tone: 'danger',
          icon: 'x',
          state: 'Failed verification',
          why: `Claims to be ${this.engineName(s)}, but failed reverse-DNS verification when it visited.`,
          recommend: `Can’t be blocked by name — block its IP at your host or CDN (Details shows how to find it).`,
        };
      }
      // Forward-confirmed — a genuine crawler (rare here; a verified engine is trusted and
      // usually never surfaces, but honour it if it does).
      if ('verified' === s.verdict) {
        return { tone: 'ok', icon: 'check', state: 'Verified crawler', by: this.rowOperator(s), why: '' };
      }
      // Legacy-device spoof — a scanner hiding behind a dead handset.
      if (s.flags && s.flags.spoof) {
        return {
          tone: 'warn',
          icon: 'x',
          state: 'Likely scanner',
          why: 'Claims a long-dead device that no real visitor runs.',
          recommend: '',
        };
      }
      // A recognised crawler we can name but not network-verify.
      if (s.known) {
        return { tone: 'calm', icon: 'kind', kind: s.known.kind, state: this.kindLabel(s.known.kind), by: s.known.operator, why: '' };
      }
      // A crawler that only names itself.
      if (s.guide && (s.guide.url || s.guide.name)) {
        return { tone: 'calm', icon: 'dot', state: 'Self-declared', by: s.guide.host || '', unverified: true, why: '' };
      }
      return { tone: 'calm', icon: 'dot', state: 'Unrecognized', why: '' };
    },
    rowOperator(s) {
      return (s.known && s.known.operator) || '';
    },
    // ---- Details panel (collapsed by default — no UA/verdict noise in the row) --
    verifyLine(s) {
      if ('spoofed' === s.verdict) return { text: 'Failed — reverse-DNS mismatch', tone: 'danger' };
      if ('verified' === s.verdict) return { text: 'Passed — forward-confirmed', tone: 'ok' };
      return { text: 'Not checked', tone: 'muted' };
    },
    detailSentence(s) {
      if ('spoofed' === s.verdict) {
        const n = this.engineName(s);
        return `It claims to be ${n}, but its network address failed reverse-DNS verification when it visited — a forgery, not the real ${n}. Blocking the name would also block the genuine ${n}, so block the impostor’s IP instead. Agentimus keeps no IP addresses (that’s the privacy promise), but your server’s access log and your CDN/firewall dashboard do — search either for the User-Agent above to find the addresses, then block them there.`;
      }
      if (s.known) {
        const v = 'verified' === s.verdict ? ' Reverse-DNS confirmed it as genuine.' : '';
        return `${s.known.name} — ${this.kindLabel(s.known.kind)} operated by ${s.known.operator}.${v} ${this.kindAdvice(s.known.kind)}`;
      }
      if (s.flags && s.flags.spoof) {
        return 'It claims a long-dead device (Symbian, Java ME, old Windows CE…) that no real visitor runs — near-always a scanner hiding behind an innocuous name. Blocking the scanner class is safe.';
      }
      if (s.guide && s.guide.url) {
        return `It points to ${s.guide.host || 'a site'} in its own User-Agent — its claim, not verified. Open it with care, then Allow or Block.`;
      }
      return 'It isn’t in the known-bot list and declares no home page of its own. Research it below, then Allow or Block.';
    },
    // ---- Styled UA tooltip + click-to-copy (mirrors ActivityPanel) -------------
    showUaTip(ev, text) {
      if (!text) return;
      const cell = ev.currentTarget.getBoundingClientRect();
      const pop = this.$refs.pop ? this.$refs.pop.getBoundingClientRect() : null;
      const gap = 16;
      const below = cell.top < 130; // near the top → drop below the cell instead of above.
      const y = below ? cell.bottom + 8 : cell.top - 8;
      if (!pop) {
        this.uaTip = { show: true, text, x: cell.left, right: null, y, caret: 16, below };
        return;
      }
      // Pin BOTH edges to the panel (equal gap each side); keep the caret over the UA cell.
      const x = pop.left + gap;
      const right = Math.max(gap, window.innerWidth - pop.right + gap);
      const caret = Math.max(14, Math.min(cell.left + cell.width / 2 - x, pop.width - gap * 2 - 14));
      this.uaTip = { show: true, text, x, right, y, caret, below };
    },
    hideUaTip() {
      this.uaTip.show = false;
    },
    async copyUa(text) {
      if (!text) return;
      let ok = false;
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
          ok = true;
        }
      } catch (e) { /* fall through to the legacy path */ }
      if (!ok) ok = this.legacyCopy(text);
      this.$emit('flash', ok ? 'success' : 'error', ok ? 'User-Agent copied.' : 'Could not copy — select the text and copy manually.');
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
    // ---- Small helpers ---------------------------------------------------------
    engineName(s) {
      const ua = (s.ua || '').toLowerCase();
      if (ua.indexOf('googlebot') !== -1) return 'Googlebot';
      if (ua.indexOf('bingbot') !== -1) return 'Bingbot';
      if (ua.indexOf('duckduckbot') !== -1) return 'DuckDuckBot';
      if (ua.indexOf('applebot') !== -1) return 'Applebot';
      if (ua.indexOf('yandex') !== -1) return 'Yandex';
      return 'a search engine';
    },
    kindAdvice(kind) {
      return {
        ai: 'Allow it to read your site for AI answers, or Block to opt out of AI training and ingest.',
        seo: 'Backlink/rank tooling — often high-volume. Block if it’s just load you don’t need.',
        search: 'A regional or vertical search engine. Usually safe to Allow.',
        social: 'Fetches a preview card when your links are shared. Usually safe to Allow.',
      }[kind] || 'Allow it if you recognise it, or Block if you don’t.';
    },
    searchName(s) {
      const generic = ['Other bot', 'Likely spoof/scanner', 'Unrecognized', 'No user-agent', 'Browser', 'Script/tool'];
      const name = (s.known && s.known.name) || (s.guide && s.guide.name) || '';
      if (name) return name;
      if (s.agent && generic.indexOf(s.agent) === -1) return s.agent;
      return (s.ua || '').slice(0, 60);
    },
    researchLinks(s) {
      const q = this.searchName(s);
      if (!q) return [];
      const quoted = `"${q}" crawler bot user-agent`;
      return [
        { label: 'Google', url: `https://www.google.com/search?q=${encodeURIComponent(quoted)}` },
        { label: 'Bing', url: `https://www.bing.com/search?q=${encodeURIComponent(quoted)}` },
        { label: 'DuckDuckGo', url: (s.guide && s.guide.lookup) || `https://duckduckgo.com/?q=${encodeURIComponent(q + ' crawler bot')}` },
      ];
    },
    showNoIpNote(s) {
      return '' === (s.verdict || '');
    },
    rowTitle(s) {
      return (s.known && s.known.name) || (s.guide && s.guide.name) || s.agent;
    },
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
      :aria-label="count ? `${count} client${1 === count ? '' : 's'} to review` : 'Review queue — nothing flagged'"
      @click.stop="toggle"
    >
      <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M8 2a3.2 3.2 0 0 0-3.2 3.2c0 3.2-1.3 4.2-1.3 4.2h9c0 0-1.3-1-1.3-4.2A3.2 3.2 0 0 0 8 2Z" />
        <path d="M6.8 12.2a1.3 1.3 0 0 0 2.4 0" />
      </svg>
      <span v-if="count" class="ar__review-count">{{ count }}</span>
    </button>

    <div v-if="open" ref="pop" class="ar__review-pop" role="dialog" aria-label="Review queue" @click.stop>
      <!-- Header: title + a plain count, with the auto-refresh switch to the right. -->
      <div class="ar-rev-head">
        <div class="ar-rev-head__titles">
          <strong class="ar-rev-head__title">Review queue</strong>
          <span class="ar-rev-head__sub">{{ count ? `${count} to review` : 'Nothing to review' }}</span>
        </div>
        <button
          type="button"
          class="ar__live"
          :class="{ 'is-on': live }"
          role="switch"
          :aria-checked="live"
          :aria-label="`Auto-refresh — check for new activity every ${liveInterval} seconds`"
          :title="live ? `Auto-refresh is on — checking every ${liveInterval}s. Click to stop.` : `Auto-refresh is off — click to check every ${liveInterval}s.`"
          @click="$emit('set-live', !live)"
        >
          <span class="ar__live-dot" aria-hidden="true"></span>
          <span class="ar__live-label">Auto-refresh</span>
        </button>
      </div>

      <!-- Filter tabs (only when there's enough to sort through). -->
      <div v-if="showReviewTab" class="ar-rev-tabs" role="tablist">
        <button type="button" class="ar-rev-tab" :class="{ 'is-active': activeTab === 'all' }" role="tab" :aria-selected="activeTab === 'all'" @click="tab = 'all'">
          All <span class="ar-rev-tab__n">{{ count }}</span>
        </button>
        <button type="button" class="ar-rev-tab is-attention" :class="{ 'is-active': activeTab === 'attention' }" role="tab" :aria-selected="activeTab === 'attention'" @click="tab = 'attention'">
          Flagged <span class="ar-rev-tab__n">{{ attention.length }}</span>
        </button>
      </div>

      <p v-if="!threats.blockingOn && pending.length" class="ar-rev-banner">
        Blocking is off — flagged clients are still served. Use <strong>Block</strong>, or turn it on in
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' }); close()">Settings</button>.
      </p>

      <ul v-if="shownCards.length" class="ar-rev-list">
        <li v-for="({ s, c }, i) in shownCards" :key="i" class="ar-rev-item" :class="'is-' + c.tone">
          <div class="ar-rev-item__main">
            <!-- Avatar: a neutral bot glyph, tinted by severity (no third-party logos). -->
            <span class="ar-rev-avatar" :class="'is-' + c.tone" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="8" width="14" height="10" rx="2.6" />
                <path d="M12 5.2V8" /><circle cx="12" cy="4.2" r="1.1" />
                <path d="M9.4 12.6v1.2M14.6 12.6v1.2" />
                <path d="M3.4 12v2.4M20.6 12v2.4" />
              </svg>
            </span>

            <div class="ar-rev-body">
              <div class="ar-rev-nameline">
                <span class="ar-rev-name">{{ rowTitle(s) }}</span>
                <span v-if="s.flags.heavy" class="ar-rev-tag is-heavy">High volume</span>
                <span v-if="s.flags.new" class="ar-rev-tag is-new">New</span>
              </div>

              <!-- The hero: verification / identity state, coloured by severity. -->
              <div class="ar-rev-state" :class="'is-' + c.tone">
                <svg v-if="c.icon === 'x'" class="ar-rev-state__ic" viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="8" cy="8" r="6.4" /><path d="M6 6l4 4M10 6l-4 4" /></svg>
                <svg v-else-if="c.icon === 'check'" class="ar-rev-state__ic" viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="6.4" /><path d="M5.4 8.2l1.8 1.8 3.4-3.6" /></svg>
                <span v-else class="ar-rev-state__dot"></span>
                <span class="ar-rev-state__label">{{ c.state }}</span>
                <span v-if="c.by" class="ar-rev-state__by">· {{ c.by }}</span>
                <span v-if="c.unverified" class="ar-rev-state__note">not verified</span>
              </div>

              <p v-if="c.why" class="ar-rev-why">{{ c.why }}</p>

              <div class="ar-rev-meta">
                {{ s.hits }} request{{ 1 === s.hits ? '' : 's' }}<template v-if="s.lastSeen"> · {{ ago(s.lastSeen) }}</template>
              </div>

              <div v-if="c.recommend" class="ar-rev-rec" :class="'is-' + c.tone">
                <strong>Recommended:</strong> {{ c.recommend }}
              </div>

              <!-- Actions: the real decisions as buttons; utilities as quiet links. -->
              <div class="ar-rev-actions">
                <template v-if="'agent' === s.action">
                  <button type="button" class="ar-rev-btn ar-rev-btn--allow" :disabled="isBlocking(s) || isAllowing(s)" @click="doAllow(s)">
                    {{ isAllowing(s) ? 'Allowing…' : 'Allow' }}
                  </button>
                  <button type="button" class="ar-rev-btn ar-rev-btn--block" :disabled="isBlocking(s) || isAllowing(s)" :title="'Blocks user-agents matching “' + s.token + '”'" @click="doBlock(s)">
                    {{ isBlocking(s) ? 'Blocking…' : 'Block' }}
                  </button>
                </template>
                <button v-else-if="'spoofed' === s.action" type="button" class="ar-rev-btn ar-rev-btn--block" :disabled="isBlocking(s)" @click="doBlock(s)">
                  {{ isBlocking(s) ? 'Blocking…' : 'Block scanners' }}
                </button>

                <button type="button" class="ar-rev-link ar-rev-link--details" :class="{ 'is-on': isOpen(s) }" :aria-expanded="isOpen(s)" @click="toggleDetails(s)">
                  {{ isOpen(s) ? 'Hide details' : 'Details' }}
                </button>
                <button type="button" class="ar-rev-link ar-rev-link--ignore" :disabled="isDismissing(s)" @click="doDismiss(s)">
                  {{ isDismissing(s) ? 'Ignoring…' : 'Ignore' }}
                </button>
              </div>
            </div>
          </div>

          <!-- Details: the honest evidence — verification result + the actual UA (copyable)
               + a plain explanation + web-search fallbacks. No fabricated IP/ASN. -->
          <div v-if="isOpen(s)" class="ar-rev-details">
            <div class="ar-rev-kv">
              <span class="ar-rev-kv__k">Verification</span>
              <span class="ar-rev-kv__v" :class="'is-' + verifyLine(s).tone">{{ verifyLine(s).text }}</span>
            </div>
            <div class="ar-rev-kv">
              <span class="ar-rev-kv__k">User-Agent</span>
              <code
                v-if="s.ua"
                class="ar-rev-kv__v ar-rev-ua is-copyable"
                :aria-label="s.ua"
                @mouseenter="showUaTip($event, s.ua)"
                @mouseleave="hideUaTip"
                @click.stop="copyUa(s.ua)"
              >{{ s.ua }}</code>
              <span v-else class="ar-rev-kv__v ar-rev-ua is-empty">No User-Agent</span>
            </div>

            <p class="ar-rev-details__why">{{ detailSentence(s) }}</p>

            <div class="ar-rev-details__research">
              <span class="ar-rev-details__reslabel">Search the web:</span>
              <a
                v-for="r in researchLinks(s)"
                :key="r.label"
                class="ar-rev-details__reslink"
                :href="r.url"
                target="_blank"
                rel="noopener noreferrer nofollow"
              >{{ r.label }}</a>
            </div>

            <p v-if="showNoIpNote(s)" class="ar-rev-details__note">
              Agentimus keeps no IP for logged hits, so a bot’s network identity can’t be re-checked after the fact.
              Turn on <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' }); close()">Verify search engines</button>
              to confirm crawlers live, as they arrive.
            </p>
          </div>
        </li>
      </ul>
      <p v-else-if="count" class="ar-rev-empty">Nothing in this view.</p>
      <p v-else class="ar-rev-empty">Nothing needs a look right now.</p>
    </div>

    <!-- Styled UA tooltip — teleported to <body> so the scrolling dropdown never clips it. -->
    <Teleport to="body">
      <transition name="ar-tip">
        <div
          v-if="uaTip.show"
          class="ar-act-uatip"
          :class="{ 'is-below': uaTip.below }"
          :style="{ left: uaTip.x + 'px', right: uaTip.right != null ? uaTip.right + 'px' : null, top: uaTip.y + 'px', maxWidth: uaTip.right != null ? 'none' : null }"
          role="tooltip"
          aria-hidden="true"
        ><span class="ar-act-uatip__ua">{{ uaTip.text }}</span><span class="ar-act-uatip__hint">Click to copy</span><span class="ar-act-uatip__caret" :style="{ left: uaTip.caret + 'px' }"></span></div>
      </transition>
    </Teleport>
  </div>
</template>
