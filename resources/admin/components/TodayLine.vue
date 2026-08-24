<script>
/**
 * Today — the first line on the dashboard.
 *
 * ⭐ HIS ASK, 2026-08-24: "whenever I first open my browser and visit the
 * Agentimus dashboard, I want to see what today's stats are, and I miss that
 * cleanly." The dashboard says what the site IS; every screen says how its own
 * part is doing; nothing said what happened TODAY. This is that one line,
 * above the cards, before any click.
 *
 * ⛔ ONLY WHAT CAN HONESTLY MEAN TODAY. Reads, visits and assistant actions are
 * live — they answer "since midnight" exactly. Search does not (Google and Bing
 * publish days behind) and the score has no history at all, so neither appears
 * here: a "today" figure that cannot be about today is worse than no figure.
 * The Report screen carries those, where each block can say its own date.
 *
 * ⛔ It owns no number either: same collector as the Report screen and the same
 * producers as the weekly email, so the three can never disagree.
 */
import { utcDayNote } from '../js/wpDate.js';

export default {
  name: 'TodayLine',
  props: {
    api: { type: Object, required: true },
    // The dashboard is the landing screen; this only reads when it is showing.
    active: { type: Boolean, default: false },
  },
  emits: ['navigate'],
  data() {
    return { data: null, loading: false, failed: false };
  },
  computed: {
    reads() {
      return this.data ? this.data.reads.total : 0;
    },
    visits() {
      return this.data ? this.data.visits.total : 0;
    },
    actions() {
      return this.data ? this.data.access.events : 0;
    },
    impostors() {
      return this.data ? this.data.impostors.total : 0;
    },
    clockNote() {
      return utcDayNote(this.data && this.data.range ? this.data.range.today : '');
    },
    // Nothing yet today — true on most mornings of most small sites, and worth
    // saying in words rather than as three zeros the reader has to interpret.
    quiet() {
      return !!this.data && !this.reads && !this.visits && !this.actions;
    },
    // The busiest crawler and the busiest source, named — a number says how
    // much, a name says who, and the name is what makes the line worth reading.
    who() {
      const client = ((this.data && this.data.reads.byClient) || [])[0];
      const source = ((this.data && this.data.visits.bySource) || [])[0];
      const parts = [];
      if (client && client.label) parts.push(`${client.label} read you most`);
      if (source && source.label) parts.push(`${source.label} sent the visits`);
      if (this.impostors) parts.push(`${this.impostors} caught pretending to be an engine`);
      if (!parts.length) return '';
      return `${parts.join(' · ')}.`;
    },
  },
  watch: {
    active(on) {
      if (on) this.load();
    },
  },
  created() {
    if (this.active) this.load();
  },
  methods: {
    async load() {
      if (this.loading) return;
      this.loading = true;
      try {
        // ⛔ No dates: the SERVER says which day "today" is. The browser's clock
        // is a third one after the site's and the log's, and this data is
        // counted in UTC days — a laptop six hours ahead would otherwise ask
        // for a day the log has not reached and be told, truthfully, that
        // nothing had happened. His site, 2026-08-25.
        this.data = await this.api.getReport({});
        this.failed = false;
      } catch (e) {
        // ⛔ A line that cannot read its numbers shows nothing rather than
        // zeros: on this screen a zero is a real answer, and a failed fetch
        // must never be able to imitate one.
        this.failed = true;
      } finally {
        this.loading = false;
      }
    },
    move(block) {
      if (!this.data || !block) return null;
      const prev = block.prev;
      if (prev === null || prev === undefined) return null;
      const now = Number(block.total) || 0;
      const was = Number(prev) || 0;
      if (now > was) return { tone: 'up', arrow: '↑', text: `${now - was} more than yesterday` };
      if (now < was) return { tone: 'down', arrow: '↓', text: `${was - now} fewer than yesterday` };
      return { tone: 'flat', arrow: '·', text: 'same as yesterday' };
    },
  },
};
</script>

<template>
  <section v-if="data && !failed" class="ar-card ar-today-line" aria-label="Today so far">
    <div class="ar-today-line__head">
      <div class="ar-today-line__heading">
        <h2 class="ar-today-line__title">Today</h2>
        <!-- ⭐ "UTC day" appears only when the site's own date is not the day
             being counted — the hours when this card would otherwise look a day
             stale to the person reading it. His site at 01:47 local, 2026-08-25:
             the card said August 24, and it was right. -->
        <p class="ar-today-line__when">{{ data.range.label
          }}<span v-if="clockNote" class="ar-today-line__utc" v-tip="clockNote"> · UTC day</span> · so far</p>
      </div>
      <button type="button" class="ar-linkbtn ar-today-line__more" @click="$emit('navigate', 'report')">
        See any range →
      </button>
    </div>

    <div v-if="!quiet" class="ar-today-line__figs">
      <div class="ar-today-line__fig">
        <span class="ar-today-line__val">{{ reads }}</span>
        <span class="ar-today-line__label">{{ reads === 1 ? 'AI read' : 'AI reads' }}</span>
        <span v-if="move(data.reads)" class="ar-today-line__move" :class="'is-' + move(data.reads).tone">
          <span aria-hidden="true">{{ move(data.reads).arrow }}</span>{{ move(data.reads).text }}
        </span>
      </div>
      <div class="ar-today-line__fig">
        <span class="ar-today-line__val">{{ visits }}</span>
        <span class="ar-today-line__label">{{ visits === 1 ? 'visit AI sent you' : 'visits AI sent you' }}</span>
        <span v-if="move(data.visits)" class="ar-today-line__move" :class="'is-' + move(data.visits).tone">
          <span aria-hidden="true">{{ move(data.visits).arrow }}</span>{{ move(data.visits).text }}
        </span>
      </div>
      <div class="ar-today-line__fig">
        <span class="ar-today-line__val">{{ actions }}</span>
        <span class="ar-today-line__label">{{ actions === 1 ? 'assistant action' : 'assistant actions' }}</span>
      </div>

      <!-- ⭐ The sentence is the row's fourth cell, not a line adrift beneath
           it: it is what fills the width a card this wide otherwise leaves
           empty, and it reads as the answer to the numbers beside it. His
           catch, 2026-08-25. -->
      <p v-if="who" class="ar-today-line__note">{{ who }}</p>
    </div>

    <p v-if="quiet" class="ar-today-line__quiet">
      Nothing yet today — no crawler has read you, and nobody has arrived from an AI answer.
    </p>
  </section>
</template>

<style>
/* ⭐ The one card on this screen that is about a DAY rather than about the
   site's standing, so it wears its own ground: the accent at a low mix into
   whatever surface the scheme uses. ⛔ Derived, never a literal — every scheme
   and both modes re-key with it, and the ink on top stays the ordinary ink,
   because accent is guaranteed readable ON PAPER and nowhere else. */
/* ⚠️ `.ar-card.ar-today-line`, not `.ar-today-line` alone: the card's own
   background rule is a single class too, and it is written later in the
   bundle — at equal weight the last one wins, so the wash silently did
   nothing. The same trap as the one that cost an evening on the nav buttons:
   when a style half-lands, look for a LATER rule at the same weight. */
/* ⭐⭐ A HOUSE GROUND, MARKED — his call, 2026-08-25, after seeing the
   alternatives on screen: the raised surface the app already uses for its big
   panels, plus an accent edge to say "this one is about a day". ⛔ NOT a wash
   of its own. A tinted ground was a colour the house does not have, and it had
   to be re-tuned per mode to be seen at all — 9% of the accent reads on dark
   charcoal and disappears into light paper. `--ar-surface-2` is already a
   designed step in every scheme and both modes, and the edge needs no tuning,
   costs no legibility under the text, and leaves the palette as it was.
   ⚠️ Two classes on purpose: `.ar-card` sets both the ground and the border in
   single-class rules written later in the bundle, so a single-class rule here
   would silently lose the tie. */
.ar-card.ar-today-line {
  /* The figure's own first line, in one place: the number's line box. The
     sentence beside them reserves exactly this much so its first line lands on
     the SAME baseline as the labels — see __note::before. */
  --ar-today-num-line: 33px;
  display: flex; flex-direction: column; gap: 14px;
  background: var(--ar-surface-2);
  border-left: 4px solid var(--ar-accent);
}
.ar-today-line__head { display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.ar-today-line__heading { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
/* Titled like every other card on the dashboard — same serif, same size — so
   it reads as a section of the page rather than a strip stuck on top of it. */
.ar-today-line__title { margin: 0; font-family: var(--ar-serif); font-weight: 600; font-size: 19px; letter-spacing: -0.01em; color: var(--ar-ink); }
.ar-today-line__when { margin: 0; font-family: var(--ar-mono); font-size: 10.5px; letter-spacing: 0.04em; color: var(--ar-ink-faint); }
.ar-today-line__utc { border-bottom: 1px dotted currentColor; cursor: help; }
/* ⚠️ Two classes again, for the same reason as the card's ground: `.ar-linkbtn`
   sets `margin-left: 4px` and is written later in the bundle, so a single-class
   `margin-left: auto` here lost the tie and the link sat glued to the title
   instead of in the corner. */
.ar-today-line__head .ar-today-line__more { margin-left: auto; align-self: flex-start; font-size: 12.5px; }
/* ⭐ ONE ROW, FOUR CELLS: three readings of the day and the sentence that says
   who did it. The numbers take the width they need, the sentence takes the
   rest — which is how a card this wide stops having a hole in it (his catch,
   2026-08-25, on a version where the figures were capped and the sentence sat
   alone underneath).
   ⛔ The dividers are --ar-line-STRONG: on the raised surface this card wears,
   the ordinary hairline was there and invisible, which is worse than no rule
   at all. */
.ar-today-line__figs {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, max-content)) minmax(200px, 1fr);
  align-items: stretch;
  gap: 0;
}
.ar-today-line__fig {
  display: flex; flex-direction: column; gap: 1px; min-width: 0;
  padding: 0 26px;
  border-left: 1px solid var(--ar-line-strong);
}
.ar-today-line__fig:first-child { padding-left: 0; border-left: 0; }
.ar-today-line__note {
  margin: 0; align-self: stretch;
  padding-left: 26px;
  border-left: 1px solid var(--ar-line-strong);
  font-size: 12.5px; line-height: 1.6; color: var(--ar-ink-soft);
}
/* ⭐ The cell has no number, so without this its text floats up and sits
   between the figures' two lines — his catch, 2026-08-25. This reserves the
   number's line box, which puts the sentence's first line on the SAME baseline
   as the labels next to it. One value, shared with the number above. */
.ar-today-line__note::before { content: ''; display: block; height: calc(var(--ar-today-num-line) + 1px); }
.ar-today-line__val { font-family: var(--ar-serif); font-size: 29px; line-height: var(--ar-today-num-line); font-variant-numeric: tabular-nums; color: var(--ar-ink); }
/* The label and the sentence share their type metrics on purpose: the row's
   second line is one line, so both must set the same size and leading or their
   baselines land a few pixels apart. */
.ar-today-line__label { font-size: 12.5px; line-height: 1.6; color: var(--ar-ink-soft); }
.ar-today-line__move { margin-top: 3px; font-size: 12px; display: inline-flex; align-items: baseline; gap: 5px; }
.ar-today-line__move.is-up { color: var(--ar-good); }
.ar-today-line__move.is-down { color: var(--ar-bad); }
.ar-today-line__move.is-flat { color: var(--ar-ink-faint); }
.ar-today-line__quiet { margin: 0; font-size: 13px; line-height: 1.6; color: var(--ar-ink-soft); }

/* Narrow: the cells stack, and the dividers become the rules between rows —
   a vertical hairline down the left of a full-width block reads as a quote. */
@media (max-width: 900px) {
  .ar-today-line__figs { grid-template-columns: 1fr 1fr; }
  .ar-today-line__note { grid-column: 1 / -1; align-self: start; margin-top: 14px; padding: 12px 0 0; border-left: 0; border-top: 1px solid var(--ar-line-strong); }
  .ar-today-line__note::before { display: none; }
}
@media (max-width: 560px) {
  .ar-today-line__figs { grid-template-columns: 1fr; gap: 12px; }
  .ar-today-line__fig { padding: 0; border-left: 0; }
  .ar-today-line__fig + .ar-today-line__fig { padding-top: 12px; border-top: 1px solid var(--ar-line-strong); }
}
</style>
