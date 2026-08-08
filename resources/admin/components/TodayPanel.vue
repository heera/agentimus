<script>
/**
 * Today — the front door.
 *
 * Every open finding this plugin can produce, already merged and ranked by the
 * server ({@see \Agentimus\Findings}). This component deliberately does no
 * ranking, filtering or judging of its own: two places deciding what matters is
 * how the plugin ended up with five screens that each knew part of the answer.
 *
 * Three rules it does own, all about legibility rather than data:
 *
 *  - The headline is a sentence, not a number. "Five things need you" is
 *    actionable; a score ends the conversation. The score stays, small, beside
 *    it — it answers "is my setup right", which is a different question.
 *  - Optional findings live under their own divider. Urgent and optional in one
 *    unbroken list is what makes long lists unreadable.
 *  - An empty list says so out loud. Silence is not reassurance: with nothing
 *    open, the screen must read as "checked", never as "not working".
 */
export default {
  name: 'TodayPanel',
  props: {
    findings: { type: Object, default: () => ({ findings: [], clear: [], failed: [], counts: {} }) },
    score: { type: Object, default: null },
    busy: { type: Boolean, default: false },
  },
  emits: ['navigate', 'refresh'],
  computed: {
    rows() {
      return Array.isArray(this.findings.findings) ? this.findings.findings : [];
    },
    // Everything that costs something today.
    open() {
      return this.rows.filter((r) => 'later' !== r.tier);
    },
    // Worth knowing, costs nothing today — below the divider.
    later() {
      return this.rows.filter((r) => 'later' === r.tier);
    },
    clearLines() {
      return Array.isArray(this.findings.clear) ? this.findings.clear : [];
    },
    // Sources that threw. Named rather than swallowed: a finding that silently
    // failed to appear is worse than one that says it couldn't be checked.
    failed() {
      return Array.isArray(this.findings.failed) ? this.findings.failed : [];
    },
    // The headline. Counts only what costs something — a page that says "seven
    // things need you" and then shows two urgent rows and five curiosities has
    // lied about the size of the job.
    heading() {
      const n = this.open.length;
      if (!n) return this.rows.length ? 'Nothing needs your attention right now' : 'All clear';
      const count = n === 1 ? 'One thing' : `${this.spell(n)} things`;
      // Sentence case, always. The number is spelled out, so without this the
      // headline opens lowercase ("five things need your attention") and reads
      // like a fragment rather than the page's title.
      // "…your attention", not the shorter "…you": the screen is named Attention,
      // and its opening line is where that name is earned.
      return `${count.charAt(0).toUpperCase()}${count.slice(1)} need${n === 1 ? 's' : ''} your attention`;
    },
    subheading() {
      if (!this.open.length) {
        return this.later.length
          ? 'Nothing is costing you anything today. There are a couple of things worth knowing below.'
          : 'Every check passes and no page or crawler is waiting on a decision.';
      }
      return 'Ranked by what each one costs — visitors lost, trust at risk, or a decision only you can make.';
    },
    scoreLine() {
      if (!this.score || this.score.blocked || 'number' !== typeof this.score.score) return null;
      return `${this.score.score} / 100`;
    },
  },
  methods: {
    // Small numbers read better as words in a headline; past five, digits win.
    spell(n) {
      return ['zero', 'one', 'two', 'three', 'four', 'five'][n] || String(n);
    },
    mark(tier) {
      return 'urgent' === tier ? '●' : 'worth' === tier ? '●' : '○';
    },
    go(action) {
      if (!action) return;
      if (action.url) {
        window.open(action.url, '_blank', 'noopener');
        return;
      }
      // `pages` travels with it: a finding that counted four pages hands those
      // four over, so the list it lands on shows them rather than everything.
      this.$emit('navigate', {
        tab: action.tab,
        view: action.view || '',
        anchor: action.anchor || '',
        pages: action.pages || null,
      });
    },
  },
};
</script>

<template>
  <div class="ar-today">
    <div class="ar-card ar-today__card">

      <!-- Head: the sentence, and the score kept deliberately small beside it. -->
      <div class="ar-today__head">
        <div class="ar-today__headings">
          <h2 class="ar-today__title">{{ heading }}</h2>
          <p class="ar-today__sub">{{ subheading }}</p>
        </div>
        <!-- The reading, and directly under it the control that re-takes it.
             Stacked rather than side by side: the button belongs TO the number,
             and a row of two put them at the same level of importance. -->
        <div class="ar-today__meta">
          <span v-if="scoreLine" class="ar-today__score-n">{{ scoreLine }}</span>
          <!-- data-reserve holds the WIDER of the two labels, which here is the
               idle one: "Checking…" is shorter than "Check again", so reserving
               the busy form (the usual way round) reserved nothing and the
               column shrank 89px → 74px mid-click, taking the score with it. -->
          <button
            type="button"
            class="ar-linkbtn ar-today__refresh ar-btn--reserve"
            data-reserve="Check again"
            :disabled="busy"
            @click="$emit('refresh')"
          ><span>{{ busy ? 'Checking…' : 'Check again' }}</span></button>
        </div>
      </div>

      <!-- What costs something today. -->
      <ul v-if="open.length" class="ar-today__list">
        <li v-for="row in open" :key="row.id + row.title" class="ar-today__row" :class="'is-' + row.tier">
          <span class="ar-today__mark" aria-hidden="true">{{ mark(row.tier) }}</span>
          <div class="ar-today__main">
            <p class="ar-today__row-title">{{ row.title }}</p>
            <p v-if="row.why" class="ar-today__why">{{ row.why }}</p>
            <ul v-if="row.points && row.points.length" class="ar-today__points">
              <li v-for="pt in row.points" :key="pt">{{ pt }}</li>
            </ul>
            <p v-if="row.evidence && row.evidence.length" class="ar-today__ev">
              <span v-for="e in row.evidence" :key="e" class="ar-today__chip">{{ e }}</span>
            </p>
          </div>
          <div v-if="row.action" class="ar-today__act">
            <button type="button" class="ar-btn ar-btn--sm" @click="go(row.action)">{{ row.action.label }}</button>
          </div>
        </li>
      </ul>

      <!-- Optional work, fenced off so it never competes with the list above. -->
      <template v-if="later.length">
        <p class="ar-today__divider">When you have time</p>
        <ul class="ar-today__list">
          <li v-for="row in later" :key="row.id + row.title" class="ar-today__row is-later">
            <span class="ar-today__mark" aria-hidden="true">{{ mark(row.tier) }}</span>
            <div class="ar-today__main">
              <p class="ar-today__row-title">{{ row.title }}</p>
              <p v-if="row.why" class="ar-today__why">{{ row.why }}</p>
              <ul v-if="row.points && row.points.length" class="ar-today__points">
                <li v-for="pt in row.points" :key="pt">{{ pt }}</li>
              </ul>
              <p v-if="row.evidence && row.evidence.length" class="ar-today__ev">
                <span v-for="e in row.evidence" :key="e" class="ar-today__chip">{{ e }}</span>
              </p>
            </div>
            <!-- Same button as every other row. An optional finding is still an
                 action, and a text link beside five solid buttons reads as a
                 different KIND of thing rather than a quieter one. -->
            <div v-if="row.action" class="ar-today__act">
              <button type="button" class="ar-btn ar-btn--sm" @click="go(row.action)">{{ row.action.label }}</button>
            </div>
          </li>
        </ul>
      </template>

      <!-- The all-clear. Stated, because an empty list must read as checked. -->
      <p v-for="line in clearLines" :key="line" class="ar-today__clear">
        <span class="ar-today__clear-mark" aria-hidden="true">✓</span>
        <span>{{ line }}<button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'readiness' })">See the checks</button></span>
      </p>

      <!-- A source that couldn't be read. Named, so a missing finding is visible. -->
      <p v-if="failed.length" class="ar-today__failed">
        {{ failed.length === 1 ? 'One check couldn’t run' : `${failed.length} checks couldn’t run` }}
        just now, so this list may be short. Nothing is wrong with your site — try
        <button type="button" class="ar-linkbtn" @click="$emit('refresh')">checking again</button>.
      </p>

    </div>
  </div>
</template>
