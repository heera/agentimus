<script>
/**
 * The once-per-release "What's new" card — shown at the top of the plugin's own
 * Dashboard after an update, and nowhere else. Deliberately NOT a site-wide admin
 * notice and NOT a redirect: release notes belong where the owner already is when
 * they care about the plugin. Dismiss ("Got it") stores the version server-side,
 * so the card appears exactly once per release, never per session.
 *
 * Content comes hand-curated from the server (3–4 headline items per release) —
 * the changelog's highlights, not its whole text. "Full changelog" opens an
 * in-admin dialog reading the BUNDLED readme (no trip to wordpress.org, no
 * outbound call); dialog conventions match the others: fixed panel, Esc or
 * Close only, no backdrop-click close.
 */
export default {
  name: 'WhatsNew',
  props: {
    // ⭐⭐ 'card' = the dashboard's once-per-release moment, dismissible and
    // perishable. 'panel' = the same items opened on purpose from More, for
    // ever. ⛔ ONE source of items, two shells — two hand-written copies of the
    // same release notes is the exact shape that drifts, and the drift stays
    // invisible until somebody reads both.
    //
    // ⚠️ Dismissing the card used to bury BOTH the highlights and the changelog
    // dialog below until the next release. "Got it" now means only "stop showing
    // me the card"; nothing becomes unreachable.
    mode: { type: String, default: 'card' },
    data: { type: Object, required: true }, // { version, items: [{title, text}] }
    api: { type: Object, default: null },
  },
  emits: ['dismiss', 'close', 'open-panel'],
  data() {
    return {
      closing: false,
      logLoading: false,
      logError: '',
      logEntries: null, // null = never fetched; [] = fetched, empty.
    };
  },
  methods: {
    // The fold opening IS the request. ⛔ Not on dialog open: most people come
    // here for this release and never touch the older ones.
    onOlder(e) {
      if (e.target.open) this.openLog();
    },
    async gotIt() {
      if (this.closing) return;
      this.closing = true;
      try {
        if (this.api) await this.api.markWhatsNewSeen();
      } catch (e) { /* dismissing is best-effort — never block the click on a failed write */ }
      this.$emit('dismiss');
    },
    async openLog() {
      this.$nextTick(() => {
        if (this.$refs.logPanel) this.$refs.logPanel.focus();
      });
      if (null !== this.logEntries || !this.api) return; // Already fetched (or no API handle).
      this.logLoading = true;
      this.logError = '';
      try {
        const r = await this.api.getChangelog();
        this.logEntries = r.entries || [];
      } catch (e) {
        this.logError = 'Couldn’t read the changelog — ' + ((e && e.message) || 'request failed') + '.';
        this.logEntries = null; // Retry on next open.
      } finally {
        this.logLoading = false;
      }
    },
  },
};
</script>

<template>
  <!-- The on-demand shell. Same items, no "just updated" tag and no dismiss —
       nothing here is perishable when you opened it on purpose. -->
  <Teleport v-if="'panel' === mode" to="body">
    <div class="ar-modal" @click.self="$emit('close')">
      <div
        class="ar-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ar-wn-title"
        @keydown.esc="$emit('close')"
      >
        <div class="ar-modal__head">
          <p class="ar-wn__ver">Version {{ data.version }}</p>
          <h2 id="ar-wn-title" class="ar-modal__title">What’s New</h2>
          <p class="ar-modal__lead">
            The headlines from this release. Everything before it is in the changelog,
            read from the plugin on your own server.
          </p>
        </div>
        <div class="ar-modal__body">
          <div class="ar-modal__scroll ar-wn__list">
            <div v-for="(item, i) in data.items" :key="i" class="ar-wn__item">
              <h4 class="ar-wn__itemtitle">{{ item.title }}</h4>
              <p class="ar-wn__itemtext">{{ item.text }}</p>
            </div>

            <!-- ⭐ NOT a second dialog. Release notes already read newest-first
                 and then older, so the older ones belong further down the same
                 scroll — a modal stacked on a modal makes the reader manage two
                 things to read one. Still fetched on demand: opening What's New
                 must not cost a request nobody asked for. -->
            <details class="ar-fold ar-wn__older" @toggle="onOlder">
              <summary>Older releases</summary>
              <p class="ar-wn__oldernote">
                Every release this install knows about, newest first — the same notes shown on
                WordPress.org, read from the plugin on your own server.
              </p>
              <p v-if="logLoading" class="ar-wn__oldernote">
                <span class="ar-spinner" aria-hidden="true"></span> Reading the changelog…
              </p>
              <p v-if="logError" class="ar-act-log__state is-error">{{ logError }}</p>
              <section v-for="e in (logEntries || [])" :key="e.version" class="ar-chlog__entry">
                <h3 class="ar-chlog__ver">{{ e.version }}</h3>
                <ul class="ar-chlog__list">
                  <!-- eslint-disable-next-line vue/no-v-html — server-escaped before formatting -->
                  <li v-for="(n, i) in e.notes" :key="i" class="ar-chlog__note" v-html="n"></li>
                </ul>
              </section>
            </details>
          </div>
        </div>
        <div class="ar-modal__actions">
          <span class="ar-support__foot">Agentimus {{ data.version }}</span>
          <a
            class="ar-linkbtn"
            href="https://wordpress.org/plugins/agentimus/#developers"
            target="_blank"
            rel="noopener"
          >View on WordPress.org</a>
          <button type="button" class="ar-btn" @click="$emit('close')">Close</button>
        </div>
      </div>
    </div>
  </Teleport>

  <section v-else class="ar-card ar-whatsnew" aria-label="What's new in this update">
    <div class="ar-whatsnew__head">
      <span class="ar-whatsnew__spark" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z" />
          <path d="M19 15.5l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9z" />
        </svg>
      </span>
      <h2 class="ar-whatsnew__title">What’s new in {{ data.version }}</h2>
      <span class="ar-whatsnew__tag">Just updated</span>
    </div>

    <div class="ar-whatsnew__grid">
      <div v-for="(item, i) in data.items" :key="i" class="ar-whatsnew__item">
        <strong class="ar-whatsnew__itemtitle">{{ item.title }}</strong>
        <p class="ar-whatsnew__itemtext">{{ item.text }}</p>
      </div>
    </div>

    <div class="ar-whatsnew__foot">
      <!-- ⛔ No longer a dialog of its own. The card asks for the panel, which
           carries the highlights AND the older releases in one scroll — a card
           that opened its own modal meant two surfaces telling one story. -->
      <button type="button" class="ar-linkbtn" @click="$emit('open-panel')">Full changelog</button>
      <button type="button" class="ar-btn ar-whatsnew__gotit" :disabled="closing" @click="gotIt">
        {{ closing ? 'Closing…' : 'Got it' }}
      </button>
    </div>

  </section>

</template>
