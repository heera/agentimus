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
 *
 * The bands are STAGES OF ONE LIFECYCLE, not three categories — a finding goes
 * needs-you → waiting → resolved, and it is the same finding throughout. That is
 * why they share a scroll instead of sitting behind tabs: filing by state would
 * hide the movement, and the movement is the part worth seeing.
 *
 * So the order follows the STORY, not a priority ranking: the work, then that
 * same work finished and waiting, then — last — anything about something else.
 * Ranking by urgency put the site-wide citation job in the middle, where it cut
 * the one thread the screen exists to show.
 */
export default {
  name: 'TodayPanel',
  props: {
    findings: { type: Object, default: () => ({ findings: [], resolved: [], clear: [], failed: [], counts: {} }) },
    score: { type: Object, default: null },
    busy: { type: Boolean, default: false },
  },
  emits: ['navigate', 'refresh', 'seen', 'dismiss'],
  computed: {
    rows() {
      return Array.isArray(this.findings.findings) ? this.findings.findings : [];
    },
    // Everything that costs something today. Waiting is excluded by name rather
    // than by "not later": a waiting row that slipped in here would be counted
    // by the headline, which is the exact miscount this tier exists to end.
    open() {
      return this.rows.filter((r) => 'later' !== r.tier && 'waiting' !== r.tier);
    },
    // Suggestions the owner put away. Carried, counted and reversible — never
    // silently dropped, which is the same rule the set-aside ledger follows.
    hidden() {
      return Array.isArray(this.findings.hidden) ? this.findings.hidden : [];
    },
    // Worth knowing, costs nothing today — below the divider.
    later() {
      return this.rows.filter((r) => 'later' === r.tier);
    },
    // Your side is done; a search engine owns what happens next.
    waiting() {
      return this.rows.filter((r) => 'waiting' === r.tier);
    },
    // The engine's own good news — at most ONE row, whatever the site's size.
    // A dozen wins arrive as a count with the moves as chips, never as a dozen
    // lines: the furniture must not grow with the data, least of all the part
    // that pushes the actual work off the screen.
    //
    // Two exits, and it needed both. It expires server-side after a week, which
    // is right for a win nobody looked at; and "Seen it" retires it now, which
    // is what someone who read it on day one deserves. A notice you cannot
    // finish with teaches you to skim the top of the screen.
    won() {
      const r = this.findings.resolved;
      return r && r.title ? r : null;
    },
    // One list of bands, rendered by a single loop. Three near-identical copies
    // of the row markup is how one band quietly stops matching its siblings.
    // Waiting comes BEFORE "when you have time" (his call, and the right one).
    // The waiting rows are the SAME findings as the work above, one state later
    // — "2 pages need an edit" reads directly into "2 pages are done, waiting",
    // and that adjacency is the entire reason these are bands and not tabs.
    // "When you have time" was the only band about something else (a site-wide
    // setup job, not per-page search work), so sitting in the middle it cut the
    // one story the screen exists to tell. Last, it interrupts nothing.
    bands() {
      return [
        { key: 'open', divider: '', rows: this.open },
        { key: 'waiting', divider: 'Waiting on the next report', rows: this.waiting },
        { key: 'later', divider: 'When you have time', rows: this.later },
      ].filter((b) => b.rows.length);
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
      // "…your attention" survives the tab's rename to Findings, deliberately:
      // a sentence earns the word the bare label couldn't (as a label it read
      // as a command). The headline states what the findings ask of you.
      return `${count.charAt(0).toUpperCase()}${count.slice(1)} need${n === 1 ? 's' : ''} your attention`;
    },
    subheading() {
      // No mention of waiting crawlers in either line: the review queue is the
      // bell's story alone now (his call) — this screen never repeats it.
      //
      // Pages waiting on a report are named here rather than in the headline,
      // which is a sentence about what the findings ASK OF YOU — and waiting
      // asks nothing. Stated all the same, because an owner who finished the
      // work needs to see that it registered.
      const held = this.waiting.length ? ` ${this.waitingLine}` : '';
      if (!this.open.length) {
        if (this.later.length) return `Nothing is costing you anything today. There are a couple of things worth knowing below.${held}`;
        return `Every check passes and no page is waiting on a fix.${held}`;
      }
      return `Ranked by what each one costs — visitors lost, AI assistants turned away, or a page underselling itself.${held}`;
    },
    // "Your side is done" said once, in the screen's own voice.
    waitingLine() {
      const n = this.waiting.length;
      if (!n) return '';
      if (1 === n) return 'One finding below is finished on your side and waiting on the next report.';
      const word = this.spell(n);
      return `${word.charAt(0).toUpperCase()}${word.slice(1)} findings below are finished on your side and waiting on the next report.`;
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
    // Filled = costs you something, hollow = costs nothing, half = under way
    // without you. The shapes carry the tier on their own, so the bands stay
    // legible to anyone who cannot separate their colours.
    mark(tier) {
      if ('urgent' === tier || 'worth' === tier) return '●';
      return 'waiting' === tier ? '◐' : '○';
    },
    go(action) {
      if (!action) return;
      if (action.url) {
        window.open(action.url, '_blank', 'noopener');
        return;
      }
      // `pages` travels with it: a finding that counted four pages hands those
      // four over, so the list it lands on shows them rather than everything.
      // `open` travels too: some answers live in a dialog, and landing beside
      // the control that opens it is one step short of the button's promise.
      this.$emit('navigate', {
        tab: action.tab,
        view: action.view || '',
        anchor: action.anchor || '',
        pages: action.pages || null,
        open: action.open || '',
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

      <!-- News first: something you worked on has actually moved. It sits above
           the work because a win nobody sees is a win that did not land, and it
           expires on its own rather than waiting to be dismissed — a permanent
           "recently resolved" list is a museum at the foot of a screen whose
           whole promise is that it stays short. -->
      <p v-if="won" class="ar-today__won">
        <span class="ar-today__won-mark" aria-hidden="true">✓</span>
        <span class="ar-today__won-main">
          <span>{{ won.title }}</span>
          <!-- The specifics, when there is more than one. Same chip grammar as
               every finding's evidence, and clipped by the same rule — a count
               whose proof runs off the page has stopped being proof. -->
          <span v-if="won.evidence && won.evidence.length" class="ar-today__ev">
            <span v-for="e in won.evidence" :key="e" class="ar-today__chip">{{ e }}</span>
          </span>
        </span>
        <!-- The way out. A resolution expires by itself after a week, which is
             right for a win nobody looked at and a week too long for one they
             read on day one — a notice you cannot finish with teaches you to
             skim the top of the screen. Takes the whole batch: "seen it" is
             about the news, not about each line of it. -->
        <button type="button" class="ar-linkbtn ar-today__won-seen" @click="$emit('seen')">Seen it</button>
      </p>

      <!-- The three bands, one loop. Each keeps its own divider; the first has
           none, because the work is what the screen is for and a heading over
           it would only repeat the title. -->
      <template v-for="band in bands" :key="band.key">
        <p v-if="band.divider" class="ar-today__divider">{{ band.divider }}</p>
        <ul class="ar-today__list">
          <li v-for="row in band.rows" :key="row.id + row.title" class="ar-today__row" :class="'is-' + row.tier">
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
            <!-- Same button in every band. An optional or waiting finding is
                 still somewhere to go, and a text link beside five solid buttons
                 reads as a different KIND of thing rather than a quieter one —
                 the waiting rows say "look", not "fix", in their label. -->
            <!-- ⛔ Only on "later" rows, and the server enforces it too. A hide
                 control on an urgent finding would be a way to bury a live
                 problem; on a suggestion it is simply an answer. -->
            <button
              v-if="row.tier === 'later'"
              v-tip="'Put this suggestion away'"
              type="button"
              class="ar-iconbtn ar-today__hide"
              :aria-label="`Put away: ${row.title}`"
              :disabled="busy"
              @click="$emit('dismiss', { id: row.id, hidden: true })"
            >
              <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor"
                   stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M3 8h10" /></svg>
            </button>
            <div v-if="row.action" class="ar-today__act">
              <!-- ⚠️ `ar-btn--small`, not `ar-btn--sm`. The short form was a
                   class that exists nowhere in the stylesheet, so every finding's
                   button silently rendered at FULL size — 12px in a column fixed
                   at 214px — and the longest label hung 9px outside its own
                   background. A modifier that does not exist fails silently and
                   looks deliberate, which is why it survived. -->
              <button type="button" class="ar-btn ar-btn--small" :class="{ 'ar-newtab': !!row.action.url }" @click="go(row.action)">{{ row.action.label }}</button>
            </div>
          </li>
        </ul>
      </template>

      <!-- The all-clear. Stated, because an empty list must read as checked. -->
      <p v-for="line in clearLines" :key="line" class="ar-today__clear">
        <span class="ar-today__clear-mark" aria-hidden="true">✓</span>
        <span>{{ line }}<button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'readiness' })">See the checks</button></span>
      </p>

      <!-- The ledger. A count the owner can open and undo, because a plugin that
           quietly stops mentioning things has stopped being trustworthy about
           everything else it has not mentioned either. -->
      <details v-if="hidden.length" class="ar-fold ar-today__hidden">
        <summary>
          {{ hidden.length }} {{ hidden.length === 1 ? 'suggestion' : 'suggestions' }} you put away
        </summary>
        <ul class="ar-today__hiddenlist">
          <li v-for="row in hidden" :key="row.id">
            <span>{{ row.title }}</span>
            <button type="button" class="ar-linkbtn" :disabled="busy" @click="$emit('dismiss', { id: row.id, hidden: false })">
              Bring it back
            </button>
          </li>
        </ul>
      </details>

      <!-- A source that couldn't be read. Named, so a missing finding is visible. -->
      <p v-if="failed.length" class="ar-today__failed">
        {{ failed.length === 1 ? 'One check couldn’t run' : `${failed.length} checks couldn’t run` }}
        just now, so this list may be short. Nothing is wrong with your site — try
        <button type="button" class="ar-linkbtn" @click="$emit('refresh')">checking again</button>.
      </p>

    </div>
  </div>
</template>
