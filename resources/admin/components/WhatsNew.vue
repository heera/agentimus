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
    data: { type: Object, required: true }, // { version, items: [{title, text}] }
    api: { type: Object, default: null },
  },
  emits: ['dismiss'],
  data() {
    return {
      closing: false,
      logOpen: false,
      logLoading: false,
      logError: '',
      logEntries: null, // null = never fetched; [] = fetched, empty.
    };
  },
  mounted() {
    document.addEventListener('keydown', this.onKey);
  },
  beforeUnmount() {
    document.removeEventListener('keydown', this.onKey);
  },
  methods: {
    onKey(e) {
      if ('Escape' === e.key && this.logOpen) this.logOpen = false;
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
      this.logOpen = true;
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
  <section class="ar-card ar-whatsnew" aria-label="What's new in this update">
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
      <button type="button" class="ar-linkbtn" @click="openLog">Full changelog</button>
      <button type="button" class="ar-btn ar-whatsnew__gotit" :disabled="closing" @click="gotIt">
        {{ closing ? 'Closing…' : 'Got it' }}
      </button>
    </div>

    <!-- The in-admin changelog dialog — every release the installed readme carries,
         newest first: the same notes WordPress.org shows, read from disk. -->
    <Teleport to="body">
      <transition name="ar-modal" appear>
        <div v-if="logOpen" class="ar-modal">
          <div
            ref="logPanel"
            class="ar-modal__panel ar-modal__panel--day"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-chlog-title"
            tabindex="-1"
            @keydown.esc="logOpen = false"
          >
            <div class="ar-modal__head">
              <h2 id="ar-chlog-title" class="ar-modal__title">Changelog</h2>
              <p class="ar-modal__lead">
                Every release this install knows about, newest first — the same notes shown on
                WordPress.org, read straight from the plugin on your own server.
              </p>
            </div>

            <div class="ar-modal__body">
              <div class="ar-modal__scroll ar-chlog" :class="{ 'is-loading': logLoading }">
                <p v-if="logError" class="ar-act-log__state is-error">{{ logError }}</p>
                <template v-if="logEntries && logEntries.length">
                  <section v-for="e in logEntries" :key="e.version" class="ar-chlog__entry">
                    <h3 class="ar-chlog__ver">{{ e.version }}</h3>
                    <ul class="ar-chlog__list">
                      <!-- eslint-disable-next-line vue/no-v-html — server-escaped before formatting -->
                      <li v-for="(n, i) in e.notes" :key="i" class="ar-chlog__note" v-html="n"></li>
                    </ul>
                  </section>
                </template>
              </div>
              <div v-if="logLoading" class="ar-day-loadover" role="status">
                <span class="ar-spinner" aria-hidden="true"></span>
                <span class="ar-day-loadover__label">Loading…</span>
              </div>
            </div>

            <div class="ar-modal__actions">
              <a
                class="ar-linkbtn"
                href="https://wordpress.org/plugins/agentimus/#developers"
                target="_blank"
                rel="noopener noreferrer"
              >View on WordPress.org</a>
              <button type="button" class="ar-btn ar-btn--ghost" @click="logOpen = false">Close</button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>
  </section>
</template>
