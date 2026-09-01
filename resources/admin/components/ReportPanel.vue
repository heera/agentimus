<script>
/**
 * Report — what AI did on this site between two dates.
 *
 * ⭐ WHY IT EXISTS. Every other screen answers "how is this part doing?"; the
 * dashboard answers "what is my site like?". Nothing answered "what happened
 * between these two dates?" except the weekly email, which arrives once a week
 * for one fixed window. This is that read, on demand, for any window — his ask,
 * 2026-08-24: "whenever I first open my browser I want to see today's".
 *
 * ⛔ IT OWNS NO NUMBER. Every block reads {@see \Agentimus\Report\Data}, which
 * asks the same producers the weekly digest asks, and every block links to the
 * screen that owns its detail. A second surface that counted for itself would
 * disagree with the first one the day either changed.
 *
 * ⭐⭐ AND IT NEVER PRETENDS A WINDOW MEANS THE SAME THING TO EVERY NUMBER.
 * Live streams answer any window exactly; Google and Bing publish a day or
 * three behind and say so rather than answering zero; the score has no history
 * at all and says "as of now" whatever is asked. Each block carries its own
 * freshness line, so the difference is never something the reader has to
 * remember.
 */
import CardSkeleton from './CardSkeleton.vue';
import DateRangePicker from './DateRangePicker.vue';
import { formatDate, utcDayNote } from '../js/wpDate.js';

// The presets, in the order a reader reaches for them. `days` counts back from
// today inclusive; `offset` shifts the whole window into the past.
const PRESETS = [
  { id: 'today', label: 'Today', days: 1, offset: 0 },
  { id: 'yesterday', label: 'Yesterday', days: 1, offset: 1 },
  { id: '7', label: '7 days', days: 7, offset: 0 },
  { id: '30', label: '30 days', days: 30, offset: 0 },
];

export default {
  name: 'ReportPanel',
  components: { CardSkeleton, DateRangePicker },
  props: {
    api: { type: Object, required: true },
    active: { type: Boolean, default: false },
  },
  emits: ['navigate'],
  data() {
    return {
      presets: PRESETS,
      preset: 'today',
      // ⛔⛔ THE BROWSER NEVER NAMES A DAY. No dates means "your today", and
      // the server answers with the day this data is stamped in; `anchor` is
      // that day, taken back from every answer, and every preset counts back
      // from it. A laptop six hours ahead of UTC that named its own today
      // asked for a GMT day which had not started — the screen reported "no
      // crawler fetched anything" for a morning the dashboard, one click away,
      // was counting 49 reads in. His site, 2026-08-25.
      anchor: (window.AgentimusData || {}).todayGmt || '',
      from: '',
      to: '',
      picker: false,     // the two-month picker is open
      // ⚠️ ITS OWN NAME. This was called `anchor` too — one word for the
      // server's today AND the button's rect, declared twice in this object,
      // so the rect won and the date was never there. Every preset then
      // counted back from a DOMRect (Invalid Date, and `Yesterday` silently
      // stopped working once the picker had been opened), and the calendar's
      // "no future days" limit was handed the same rect.
      pickerAt: null,    // where the panel hangs from: the button's rect
      triggerEl: null,   // and the button itself, so its own click stays its own
      loading: false,
      error: '',
      data: null,
      loadedOnce: false,
    };
  },
  computed: {
    range() {
      return (this.data && this.data.range) || null;
    },
    // The window's own sentence: "Today, so far" / "19 – 25 Aug". Split in two
    // so the UTC marker can sit between the date and its tail and carry the
    // sentence that explains it.
    rangeLabel() {
      return (this.range && this.range.label) || '';
    },
    rangeTail() {
      if (!this.range || !this.range.open) return '';
      return this.range.days === 1 ? ' · so far' : ' · today so far';
    },
    // ⭐ Present only in the hours when the day being counted is not the date
    // on the reader's own clock — exactly when the label would look a day
    // stale. One sentence, not an abbreviation the reader has to know.
    clockNote() {
      return utcDayNote(this.anchor);
    },
    // A citation run only answers the question when at least one check
    // finished; a run that errored throughout knows nothing.
    citationsAnswered() {
      const s = this.data && this.data.citations && this.data.citations.summary;
      return !!(s && s.checks);
    },
    // Nothing at all happened in this window — said once, plainly, instead of a
    // column of zeros that each have to be read and discarded.
    quiet() {
      const d = this.data;
      if (!d) return false;
      return !d.reads.total && !d.visits.total && !d.impostors.total && !d.access.events;
    },
  },
  watch: {
    // The freshness rule: re-read on every return, not just the first reveal —
    // a screen that reports a window has to be current when it is looked at.
    active(on) {
      if (!on) return;
      // Coming back to the screen re-reads the window it is showing; the first
      // reveal has no window yet, so it asks the server for its own today.
      this.load();
    },
  },
  created() {
    if (this.active) this.load();
  },
  methods: {
    formatDay(day) {
      if (!day) return '';
      // 'YYYY-MM-DD' as a calendar day, read on the UTC face so the day the
      // server named is the day shown.
      return formatDate(new Date(`${day}T12:00:00Z`), true);
    },
    pick(preset) {
      this.preset = preset.id;
      this.picker = false;
      // "Today" asks with no dates at all, so the server answers with its own
      // day; every other preset counts back from the day it last named.
      if ('today' === preset.id) {
        this.from = '';
        this.to = '';
        this.load();
        return;
      }
      this.to = this.shift(preset.offset);
      this.from = this.shift(preset.offset + preset.days - 1);
      this.load();
    },
    /** A day counted back from the server's today, not the browser's. */
    shift(days) {
      // No anchor means the app booted without its payload; asking with no
      // dates at all is the honest degrade — never this machine's calendar.
      if (!this.anchor) return '';
      const d = new Date(`${this.anchor}T12:00:00Z`);
      d.setUTCDate(d.getUTCDate() - days);
      return d.toISOString().slice(0, 10);
    },
    // ⭐ A popover, not a pair of fields in the bar: choosing "the 3rd to the
    // 11th" is a calendar job, and two <input type="date"> boxes make the
    // reader do the arithmetic the calendar is for. His call, 2026-08-25.
    // A toggle, not a one-way door: the same button opens and closes it.
    togglePicker() {
      if (this.picker) {
        this.picker = false;
        return;
      }
      const btn = this.$refs.pickBtn;
      this.pickerAt = btn ? btn.getBoundingClientRect() : null;
      this.triggerEl = btn || null;
      this.picker = true;
    },
    onPicked({ from, to }) {
      this.from = from;
      this.to = to;
      this.preset = 'custom';
      this.picker = false;
      this.load();
    },
    today() {
      return this.anchor;
    },
    async load() {
      this.loading = true;
      this.error = '';
      try {
        this.data = await this.api.getReport({ from: this.from, to: this.to });
        // The answer says which days it actually read. Take them back — this is
        // how "today" becomes the server's today rather than this machine's.
        if (this.data && this.data.range) {
          this.from = this.data.range.from;
          this.to = this.data.range.to;
          // ⛔ `today`, not `to`: the answer's window is whatever was asked
          // for, and a custom window ending in June must not move where the
          // presets count back from.
          this.anchor = this.data.range.today || this.data.range.to;
        }
        this.loadedOnce = true;
      } catch (e) {
        this.error = e.message;
      } finally {
        this.loading = false;
      }
    },
    // How a block says what it can know about the window it was asked for.
    freshLine(block, extra = '') {
      if (!block) return '';
      if (block.freshness === 'state') return 'as of now';
      if (block.freshness === 'lagging') return extra || 'reported behind';
      return this.range && this.range.open ? 'live · to this minute' : 'complete';
    },
    // The house number format (AiTrafficPanel's, and the Today line's since
    // 1.50.0): grouped thousands, pinned locale so the browser's own cannot
    // restyle the site's figures.
    nf(v) {
      return (Number(v) || 0).toLocaleString('en-US');
    },
    // "up from 26" / "down from 40" / "same as before" — an arrow, a tone and
    // words, never colour alone.
    move(now, prev) {
      if (prev === null || prev === undefined) return null;
      const n = Number(now) || 0;
      const p = Number(prev) || 0;
      if (n > p) return { tone: 'up', arrow: '↑', text: `up from ${this.nf(p)}` };
      if (n < p) return { tone: 'down', arrow: '↓', text: `down from ${this.nf(p)}` };
      return { tone: 'flat', arrow: '·', text: `same as the window before` };
    },
    engineName(id) {
      return id === 'google' ? 'Google' : 'Bing';
    },
    go(tab, view) {
      this.$emit('navigate', view ? { tab, view } : { tab });
    },
  },
};
</script>

<template>
  <div v-show="active" class="ar-tabpanel arep">

    <!-- The window picker. Presets first because they are what a daily reader
         reaches for; anything else opens the calendar, so the bar stays a row
         of choices rather than a form. -->
    <div class="arep__toolbar">
      <div class="arep__presets" role="group" aria-label="Choose the days to report on">
        <button
          v-for="p in presets"
          :key="p.id"
          type="button"
          class="arep__preset"
          :class="{ 'is-on': preset === p.id }"
          :aria-pressed="preset === p.id ? 'true' : 'false'"
          @click="pick(p)"
        >{{ p.label }}</button>
        <button
          ref="pickBtn"
          type="button"
          class="arep__preset"
          :class="{ 'is-on': preset === 'custom' }"
          :aria-expanded="picker ? 'true' : 'false'"
          :aria-pressed="preset === 'custom' ? 'true' : 'false'"
          @click="togglePicker"
        >Pick dates</button>
      </div>

      <p v-if="rangeLabel" class="arep__when">{{ rangeLabel
        }}<span v-if="clockNote" class="arep__utc" v-tip="clockNote"> · UTC {{ range.days === 1 ? 'day' : 'days' }}</span>{{ rangeTail }}</p>
    </div>

    <DateRangePicker
      v-if="picker"
      :from="from"
      :to="to"
      :max="today()"
      :anchor="pickerAt"
      :trigger="triggerEl"
      @apply="onPicked"
      @close="picker = false"
    />

    <CardSkeleton v-if="loading && !loadedOnce" :rows="4" />
    <p v-else-if="error" class="arep__error">{{ error }}</p>

    <template v-else-if="data">
      <div class="arep__blocks">

        <!-- AI reads ------------------------------------------------------ -->
        <section class="ar-card arep__block">
          <header class="arep__head">
            <h2 class="arep__title">AI reads</h2>
            <span class="arep__fresh">{{ freshLine(data.reads) }}</span>
          </header>
          <p class="arep__hero">
            <span class="arep__num">{{ nf(data.reads.total) }}</span>
            <span class="arep__unit">{{ data.reads.total === 1 ? 'read by an AI crawler' : 'reads by AI crawlers' }}</span>
          </p>
          <p v-if="move(data.reads.total, data.reads.prev)" class="arep__move" :class="'is-' + move(data.reads.total, data.reads.prev).tone">
            <span aria-hidden="true">{{ move(data.reads.total, data.reads.prev).arrow }}</span>{{ move(data.reads.total, data.reads.prev).text }}
          </p>
          <ul v-if="data.reads.byClient && data.reads.byClient.length" class="arep__rows">
            <li v-for="(row, i) in data.reads.byClient" :key="i">
              <span class="arep__who">{{ row.label || 'Unnamed' }}</span>
              <span class="arep__count">{{ nf(row.hits) }}</span>
            </li>
          </ul>
          <p v-else class="arep__empty">No crawler fetched anything in these days.</p>
          <button type="button" class="ar-linkbtn ar-linkbtn--go arep__go" @click="go('log')">Open the request log <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
        </section>

        <!-- People from AI answers ---------------------------------------- -->
        <section class="ar-card arep__block">
          <header class="arep__head">
            <h2 class="arep__title">Visits from AI</h2>
            <span class="arep__fresh">{{ freshLine(data.visits) }}</span>
          </header>
          <p class="arep__hero">
            <span class="arep__num">{{ nf(data.visits.total) }}</span>
            <span class="arep__unit">{{ data.visits.total === 1 ? 'visit an AI assistant sent you' : 'visits AI assistants sent you' }}</span>
          </p>
          <p v-if="move(data.visits.total, data.visits.prev)" class="arep__move" :class="'is-' + move(data.visits.total, data.visits.prev).tone">
            <span aria-hidden="true">{{ move(data.visits.total, data.visits.prev).arrow }}</span>{{ move(data.visits.total, data.visits.prev).text }}
          </p>
          <ul v-if="data.visits.bySource && data.visits.bySource.length" class="arep__rows">
            <li v-for="(row, i) in data.visits.bySource" :key="i">
              <span class="arep__who">{{ row.label || 'Unnamed' }}</span>
              <span class="arep__count">{{ nf(row.hits) }}</span>
            </li>
          </ul>
          <p v-else class="arep__empty">Nobody arrived from an AI answer in these days.</p>
          <button type="button" class="ar-linkbtn ar-linkbtn--go arep__go" @click="go('visitors')">Open Visitors <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
        </section>

        <!-- What acted here ----------------------------------------------- -->
        <section class="ar-card arep__block">
          <header class="arep__head">
            <h2 class="arep__title">What acted here</h2>
            <span class="arep__fresh">{{ freshLine(data.access) }}</span>
          </header>
          <p class="arep__hero">
            <span class="arep__num">{{ nf(data.access.events) }}</span>
            <span class="arep__unit">{{ data.access.events === 1 ? 'assistant action' : 'assistant actions' }}</span>
          </p>
          <p v-if="data.impostors.total" class="arep__flag">
            {{ data.impostors.total }} {{ data.impostors.total === 1 ? 'crawler was' : 'crawlers were' }} caught claiming to be an
            engine {{ data.impostors.total === 1 ? 'it wasn’t' : 'they weren’t' }}.
          </p>
          <p v-else class="arep__empty">No impostor caught in these days.</p>
          <button type="button" class="ar-linkbtn ar-linkbtn--go arep__go" @click="go('agent-access')">Open Agent Access <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
        </section>

        <!-- In search ------------------------------------------------------ -->
        <section v-if="data.search.engines.length" class="ar-card arep__block">
          <header class="arep__head">
            <h2 class="arep__title">In search</h2>
            <span class="arep__fresh is-lagging">reported a day or more behind</span>
          </header>
          <div v-for="e in data.search.engines" :key="e.source" class="arep__engine">
            <template v-if="e.covered">
              <p class="arep__hero">
                <span class="arep__num">{{ nf(e.shown) }}</span>
                <span class="arep__unit">times shown on {{ engineName(e.source) }}, {{ e.clicks }} {{ e.clicks === 1 ? 'visit' : 'visits' }}</span>
              </p>
              <p class="arep__note">{{ e.covered }} of these days {{ e.covered === 1 ? 'has' : 'have' }} been published.</p>
            </template>
            <!-- ⛔ Never zero. A day the engine has not reported on is not a day
                 nobody searched, and printing 0 would say exactly that. -->
            <!-- ⛔ "yet" is only true one way round. A window the engine has
                 not reached is waiting on it; a window it passed over has
                 nothing and never will. Saying "yet" about the second one
                 promises an update that is not coming. -->
            <p v-else class="arep__empty">
              <template v-if="!e.latestDay">{{ engineName(e.source) }} hasn’t published anything yet.</template>
              <template v-else-if="e.latestDay < range.from">
                {{ engineName(e.source) }} hasn’t reached these days yet — its newest is {{ formatDay(e.latestDay) }}.
              </template>
              <template v-else>
                {{ engineName(e.source) }} has nothing for these days — its newest is {{ formatDay(e.latestDay) }}.
              </template>
            </p>
          </div>
          <button type="button" class="ar-linkbtn ar-linkbtn--go arep__go" @click="go('visibility')">Open Search performance <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
        </section>

        <!-- Citations ------------------------------------------------------ -->
        <section v-if="data.citations.enabled" class="ar-card arep__block">
          <header class="arep__head">
            <h2 class="arep__title">Citations</h2>
            <span class="arep__fresh is-lagging">{{ data.citations.runs ? 'checked in these days' : 'no check in these days' }}</span>
          </header>
          <!-- ⛔ "0 of 0" is not an answer. A run whose every check failed knows
               nothing about whether AI named you, and printing 0/0 says it
               does. -->
          <p v-if="citationsAnswered" class="arep__hero">
            <span class="arep__num">{{ data.citations.summary.mentions }}<small>/{{ data.citations.summary.checks }}</small></span>
            <!-- Both numbers explained, or the fraction is a riddle: the 8 is
                 "questions the AI answered", the 3 is "answers that named you".
                 His catch, 2026-08-30 — "3/8" with neither number defined. -->
            <span class="arep__unit">answers named something you track — the last run asked the AI {{ data.citations.summary.checks }} questions</span>
          </p>
          <p v-else-if="data.citations.summary && data.citations.summary.errors" class="arep__flag">
            Every check in the last run failed, so nothing was measured.
          </p>
          <p class="arep__note">
            <template v-if="data.citations.runs">
              {{ data.citations.runs }} {{ data.citations.runs === 1 ? 'run' : 'runs' }} in these days.
            </template>
            <template v-else-if="data.citations.lastRunAt">
              <!-- Say the schedule's REAL state: "checks run on a schedule" told
                   an owner whose schedule was off that something was running. -->
              <template v-if="data.citations.scheduled">Checks run on a schedule — none in these days. The last was {{ formatDay(data.citations.lastRunAt.slice(0, 10)) }}.</template>
              <template v-else>The schedule is off — a check runs only when you start one. The last was {{ formatDay(data.citations.lastRunAt.slice(0, 10)) }}.</template>
            </template>
            <template v-else>No check has run yet.</template>
          </p>
          <button type="button" class="ar-linkbtn ar-linkbtn--go arep__go" @click="go('visibility', 'results')">Open Citations <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
        </section>

        <!-- Readiness score ------------------------------------------------ -->
        <section v-if="data.score.now !== null" class="ar-card arep__block">
          <header class="arep__head">
            <h2 class="arep__title">Readiness score</h2>
            <span class="arep__fresh">as of now</span>
          </header>
          <p class="arep__hero">
            <span class="arep__num">{{ data.score.now }}<small>/100</small></span>
            <span class="arep__unit">{{ data.score.band }}</span>
          </p>
          <!-- ⭐ An absence naming itself: there is no history table behind the
               score, so it reads the same for every window, and says why. -->
          <p class="arep__note">
            The score describes your site as it stands, not as it stood on a date — it reads the same
            whichever days you pick.
          </p>
          <button type="button" class="ar-linkbtn ar-linkbtn--go arep__go" @click="go('readiness')">Open Readiness <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
        </section>

        <!-- robots.txt ------------------------------------------------------ -->
        <section v-if="data.robots.change" class="ar-card arep__block">
          <header class="arep__head">
            <h2 class="arep__title">robots.txt changed</h2>
            <span class="arep__fresh">as of now</span>
          </header>
          <p class="arep__note">
            Your crawler rules changed. If that wasn’t you, check what you activated recently.
          </p>
          <button type="button" class="ar-linkbtn ar-linkbtn--go arep__go" @click="go('discovery')">Open Discovery <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
        </section>

        <!-- One thing worth doing ------------------------------------------ -->
        <!-- ⭐ Full width, and last. It is the only card that asks you to DO
             something rather than reporting what happened, so it closes the
             grid instead of taking a slot in it — which also leaves the
             readings above in tidy rows of three. -->
        <section v-if="data.nudge.top" class="ar-card arep__block arep__block--wide">
          <header class="arep__head">
            <h2 class="arep__title">One thing worth doing</h2>
            <span class="arep__fresh">as of now</span>
          </header>
          <p class="arep__nudge">{{ data.nudge.top.label }}</p>
          <p v-if="data.nudge.top.detail" class="arep__note">{{ data.nudge.top.detail }}</p>
          <button type="button" class="ar-linkbtn ar-linkbtn--go arep__go" @click="go('findings')">Open Findings <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
        </section>

      </div>

      <!-- ⭐ A quiet window is a real answer, so it is written down rather than
           left as a page of zeros. -->
      <p v-if="quiet" class="arep__quiet">
        Nothing came by in these days — no crawler read you, nobody arrived from an AI answer, and no
        assistant acted here. On a small site that is ordinary, not a fault.
      </p>
    </template>
  </div>
</template>

<style>
.arep { display: flex; flex-direction: column; gap: 16px; }

/* ⭐ The window is chosen in its own band, not floating over the page: one
   surface that holds the presets, the dates and the range it resolved to, so
   the controls read as a toolbar belonging to the grid below rather than as
   loose buttons above it. His call, 2026-08-24. */
.arep__toolbar {
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
  padding: 12px 14px;
  background: var(--ar-surface);
  border: 1px solid var(--ar-line);
  border-radius: var(--ar-radius);
}
.arep__presets { display: flex; gap: 6px; flex-wrap: wrap; }
.arep__preset {
  font-family: var(--ar-mono); font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase;
  padding: 7px 13px; border-radius: var(--ar-radius); cursor: pointer;
  background: transparent; border: 1px solid var(--ar-line); color: var(--ar-ink-soft);
  transition: transform 0.12s, background 0.22s ease, border-color 0.22s ease;
}
.arep__preset:hover { background: var(--ar-surface-2); border-color: var(--ar-line-strong); color: var(--ar-ink); }
.arep__preset:active { transform: translateY(1px); }
.arep__preset:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 2px; }
.arep__preset.is-on { background: var(--ar-surface-2); border-color: var(--ar-line-strong); color: var(--ar-ink); }
.arep__when { margin: 0; font-family: var(--ar-mono); font-size: 11px; letter-spacing: 0.04em; color: var(--ar-ink-faint); }
/* ⭐ The marker carries an explanation, so it is marked as carrying one: a
   dotted underline in the ink it already wears, never colour alone. */
.arep__utc { border-bottom: 1px dotted currentColor; cursor: help; }

/* ⭐ THE GRID IS THE POINT: every card in a row is the same height (stretch,
   not start), every card holds the same skeleton — head · figure · movement ·
   body · link — and the link is pushed to the bottom, so the eye reads down a
   column and across a row without anything drifting. His catch, 2026-08-24:
   auto heights plus a title that wrapped on one card put every number in that
   row at a different height. */
/* At most THREE columns (his call, 2026-08-30): auto-fit alone stretched to
   five slivers on an ultra-wide window — titles wrapping, near-empty cards
   adrift — which is what made the screen read as unorganised. Three readable
   columns beat five thin ones; below ~950px the min() falls back to auto-fit
   behaviour and the mobile rules below still collapse to one. */
.arep__blocks { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); gap: 14px; }
@media (min-width: 1240px) {
  .arep__blocks { grid-template-columns: repeat(3, 1fr); }
  /* No holes in the last row (the What's New rule, applied here): a lone
     trailing card takes the whole row, the tail of a pair takes the two
     columns left. Cards are v-if'd per site, so the count varies — these
     cover every remainder. */
  .arep__block:last-child:nth-child(3n + 1) { grid-column: 1 / -1; }
  .arep__block:last-child:nth-child(3n + 2) { grid-column: span 2; }
}
.arep__block { display: flex; flex-direction: column; gap: 8px; }
/* The one card that asks for an action rather than reporting one. */
.arep__block--wide { grid-column: 1 / -1; }

/* ⛔ The freshness label sits UNDER the title, never beside it: beside it, a
   long title wraps and the whole card below shifts down by a line while its
   neighbours don't. On its own line the header is one height for every card. */
.arep__head { display: flex; flex-direction: column; gap: 3px; margin-bottom: 2px; }
.arep__title { margin: 0; font-family: var(--ar-serif); font-size: 19px; font-weight: 600; line-height: 1.2; color: var(--ar-ink); text-wrap: balance; }
.arep__fresh { font-family: var(--ar-mono); font-size: 9.5px; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ar-ink-faint); }
.arep__fresh.is-lagging { color: var(--ar-warn); }

.arep__hero { display: flex; align-items: baseline; gap: 9px; flex-wrap: wrap; margin: 0; min-height: 32px; }
.arep__num { font-family: var(--ar-serif); font-size: 30px; line-height: 1; font-variant-numeric: tabular-nums; color: var(--ar-ink); }
.arep__num small { font-size: 15px; color: var(--ar-ink-faint); }
.arep__unit { font-size: 13.5px; color: var(--ar-ink-soft); }
.arep__move { display: flex; align-items: baseline; gap: 5px; margin: 0; font-size: 12.5px; }
.arep__move.is-up { color: var(--ar-good); }
.arep__move.is-down { color: var(--ar-bad); }
.arep__move.is-flat { color: var(--ar-ink-faint); }

.arep__rows { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.arep__rows li { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: baseline; font-size: 13px; padding-bottom: 6px; border-bottom: 1px dotted var(--ar-line); }
.arep__rows li:last-child { border-bottom: 0; padding-bottom: 0; }
/* One line per client, ellipsised — a scanner with a novel-length User-Agent
   used to wrap mid-word and break the whole column's rhythm. The Request Log,
   one click away, carries the full string. */
.arep__who { color: var(--ar-ink); min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.arep__count { font-family: var(--ar-mono); font-size: 12px; font-variant-numeric: tabular-nums; color: var(--ar-ink-soft); }

.arep__note, .arep__flag { margin: 0; font-size: 12.5px; line-height: 1.6; color: var(--ar-ink-soft); }
.arep__flag { color: var(--ar-warn); }
.arep__empty { margin: 0; font-size: 12.5px; color: var(--ar-ink-faint); }
.arep__nudge { margin: 0; font-size: 14.5px; line-height: 1.5; color: var(--ar-ink); }
.arep__engine { display: flex; flex-direction: column; gap: 6px; }
/* Pinned to the bottom-RIGHT of the card: every card's way out sits in the
   same corner, so the eye finds it without reading (his call, 2026-08-25), and
   the row of links still lands on one line because the cards are equal height. */
.arep__go { align-self: flex-end; margin-top: auto; padding-top: 4px; font-size: 12.5px; }
.arep__error { margin: 0; font-size: 13px; color: var(--ar-bad); }
/* ⭐ The amber comes from the design system's own pair, never a literal:
   --ar-warn-wash / --ar-warn-tint are defined for light, for dark, and again
   inside every scheme dialect in schemes.css — so this one rule is correct in
   all nine schemes and both modes, and follows any future re-key for free.
   ⛔ Warm GROUND only: the text stays normal ink. Amber as TEXT is guarded on
   paper and nowhere else. */
.arep__quiet {
  margin: 0; padding: 14px 16px;
  /* A step warmer than the system's 10% wash: this note is meant to be
     noticed, and on a strongly coloured scheme (ectoplasm, ocean) a 10% mix
     into that scheme's own ground reads as the ground, not as amber. Still
     derived from --ar-warn, so every scheme and both modes re-key with it. */
  background: color-mix(in srgb, var(--ar-warn) 15%, var(--ar-surface));
  border: 1px solid var(--ar-warn-tint);
  border-radius: var(--ar-radius);
  font-size: 13px; line-height: 1.6; color: var(--ar-ink);
}

/* Phone: one column, and the picker keeps its own row so a thumb can reach
   every preset without the range label crowding them. */
@container (max-width: 720px) {
  .arep__blocks { grid-template-columns: 1fr; }
  .arep__toolbar { align-items: flex-start; flex-direction: column; gap: 10px; }
}
@media (max-width: 782px) {
  .arep__blocks { grid-template-columns: 1fr; }
  .arep__toolbar { align-items: flex-start; flex-direction: column; gap: 10px; }
}
</style>
