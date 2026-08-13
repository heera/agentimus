<script>
/**
 * The nav-bar spark — the writing assistant's launcher, sibling of the review bell.
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
      :aria-label="ready ? 'Writing Assistant' : 'Writing Assistant — needs setup'"
      :aria-expanded="guideOpen"
      @click.stop="click"
    >
      <!-- A spark, not the old quill. The quill said "writing", which is only half
           of it — what the button actually opens is the machine that does the
           writing. The spark is the mark people already read as "AI will do this",
           and it is the one shape here that survives the 45% dim state, where a
           thin nib and its hairline stem simply disappeared. -->
      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 3.2c1.1 5.3 2.5 6.7 7.8 7.8-5.3 1.1-6.7 2.5-7.8 7.8-1.1-5.3-2.5-6.7-7.8-7.8 5.3-1.1 6.7-2.5 7.8-7.8Z" />
      </svg>
    </button>

    <!-- The dimmed-state guidance: name the missing prerequisite(s), jump where we can. -->
    <div v-if="guideOpen" class="ar__assist-guide" role="dialog" aria-label="Writing Assistant setup" @click.stop>
      <strong class="ar__assist-guide__title">The writing assistant drafts posts for you, right here.</strong>
      <p class="ar__assist-guide__lead">It needs two things before it can start:</p>
      <ul class="ar__assist-guide__list">
        <li :class="{ 'is-done': state.writesOn }">
          <span class="ar__assist-guide__mark" aria-hidden="true">{{ state.writesOn ? '✓' : '1' }}</span>
          <span>
            <strong>Let connected agents write</strong> — your consent switch for AI writing.
            It lives inside the MCP Server card<template v-if="state.serverOn === false">;
            the server switch above it is off, so turn that on first</template>.
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
      <p class="ar__assist-guide__foot">Once both are set, this button lights up — nothing is ever written without your explicit “Create draft”.</p>
    </div>
  </div>
</template>
