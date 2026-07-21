<script>
/**
 * The nav-bar quill — the writing assistant's launcher, sibling of the review bell.
 * Two states, both honest:
 *  - READY (writes switch on + an AI provider configured): click opens the drawer.
 *  - DIMMED otherwise: the button stays visible (the house rule — "appears as
 *    'turn on in Settings' rather than hidden, so you always know it's there"),
 *    and clicking opens a small popover naming exactly which prerequisite is
 *    missing, with a jump link where one exists in-app.
 */
export default {
  name: 'AssistantLauncher',
  props: {
    // { writesOn, providerReady } from the bootstrap.
    state: { type: Object, default: () => ({ writesOn: false, providerReady: false }) },
  },
  emits: ['open', 'navigate'],
  data() {
    return { guideOpen: false };
  },
  computed: {
    ready() {
      return !!(this.state.writesOn && this.state.providerReady);
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
    click() {
      if (this.ready) {
        this.guideOpen = false;
        this.$emit('open');
      } else {
        this.guideOpen = !this.guideOpen;
      }
    },
    goSettings() {
      this.guideOpen = false;
      this.$emit('navigate', { tab: 'settings', anchor: 'ar-sec-mcp' });
    },
    onDocClick(e) {
      if (this.guideOpen && this.$el && !this.$el.contains(e.target)) this.guideOpen = false;
    },
    onKey(e) {
      if ('Escape' === e.key) this.guideOpen = false;
    },
  },
};
</script>

<template>
  <div class="ar__assist" :class="{ 'is-open': guideOpen }">
    <button
      type="button"
      class="ar__review-btn ar__assist-btn"
      :class="{ 'is-dim': !ready }"
      :aria-label="ready ? 'Writing assistant' : 'Writing assistant — needs setup'"
      :aria-expanded="guideOpen"
      @click.stop="click"
    >
      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M20.5 3.5c-4-.5-8.3 1-11.2 3.9-2 2-3.3 4.6-3.8 7.2L3 17.1l3.9 3.9 2.5-2.5c2.6-.5 5.2-1.8 7.2-3.8 2.9-2.9 4.4-7.2 3.9-11.2z" />
      <path d="M14.5 9.5L4.5 19.5" />
      </svg>
    </button>

    <!-- The dimmed-state guidance: name the missing prerequisite(s), jump where we can. -->
    <div v-if="guideOpen" class="ar__assist-guide" role="dialog" aria-label="Writing assistant setup" @click.stop>
      <strong class="ar__assist-guide__title">The writing assistant drafts posts for you, right here.</strong>
      <p class="ar__assist-guide__lead">It needs two things before it can start:</p>
      <ul class="ar__assist-guide__list">
        <li :class="{ 'is-done': state.writesOn }">
          <span class="ar__assist-guide__mark" aria-hidden="true">{{ state.writesOn ? '✓' : '1' }}</span>
          <span>
            <strong>Let connected agents write</strong> — your consent switch for AI writing.
            <button v-if="!state.writesOn" type="button" class="ar-linkbtn" @click="goSettings">Turn on in Settings</button>
            <em v-else>on</em>
          </span>
        </li>
        <li :class="{ 'is-done': state.providerReady }">
          <span class="ar__assist-guide__mark" aria-hidden="true">{{ state.providerReady ? '✓' : '2' }}</span>
          <span>
            <strong>An AI provider</strong> — connect one under <strong>WordPress Settings → AI</strong>
            (the same provider the editor’s “Draft with AI” uses).
            <em v-if="state.providerReady">connected</em>
          </span>
        </li>
      </ul>
      <p class="ar__assist-guide__foot">Once both are set, this pen button lights up — nothing is ever written without your explicit “Create draft”.</p>
    </div>
  </div>
</template>
