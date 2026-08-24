<script>
/**
 * A two-month range picker, opened from the Report screen's "Pick dates".
 *
 * ⭐ WHY IT IS BUILT HERE rather than pulled in: the plugin ships no date
 * library and is not about to start for one control. A month grid is arithmetic,
 * and everything that makes a picker feel native to a site — the week's first
 * day, the month and weekday NAMES in the admin's own language, the way a date
 * reads once chosen — already lives in this app ({@see ../js/wpDate.js} and the
 * boot payload's `startOfWeek`, both from the owner's Settings → General).
 *
 * ⛔ THE RULES IT KEEPS:
 * · YOU CHOOSE WHICH END YOU ARE SETTING. The two dates sit at the top as a
 *   pair of tabs, always showing what they hold; tapping one aims the calendar
 *   at that end. His catch, 2026-08-25: a picker that only walks first-click →
 *   second-click gives no way to correct ONE date — every slip means starting
 *   the range again — and on a phone, where one month shows at a time, nothing
 *   on screen even says which end the next tap will set.
 * · Setting the first day past the last (or the last before the first) moves
 *   the other one with it, so a range can never come out backwards and a click
 *   is never refused.
 * · Tomorrow does not exist. Days after today are disabled — the report has
 *   nothing to say about them, and offering them promises an answer.
 * · Nothing is applied until Apply. Escape, a click outside and Cancel all
 *   leave the report on the window it was already showing.
 * · Teleported to body like every other overlay here, so no card's overflow
 *   can clip it.
 */
import { formatDate } from '../js/wpDate.js';

const DAY_MS = 86400000;

/** 'YYYY-MM-DD' for a Date, read on its local clock face. */
function iso(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function fromIso(s) {
  const [y, m, d] = String(s || '').split('-').map(Number);
  return y && m && d ? new Date(y, m - 1, d) : new Date();
}

export default {
  name: 'DateRangePicker',
  props: {
    from: { type: String, required: true },
    to: { type: String, required: true },
    // The last day worth offering — today, for a report about what happened.
    max: { type: String, required: true },
    // Viewport rect of the control that opened this, so the panel sits under it.
    anchor: { type: Object, default: null },
    // ⛔ The control itself, so an outside-click never counts the TRIGGER as
    // outside: mousedown closed the panel and the button's own click reopened
    // it in the same gesture, which reads as a button that does nothing.
    // His catch, 2026-08-25.
    trigger: { type: Object, default: null },
  },
  emits: ['apply', 'close'],
  data() {
    const start = fromIso(this.from);
    return {
      // The left-hand month; the right is always the one after it.
      view: new Date(start.getFullYear(), start.getMonth(), 1),
      pickFrom: this.from,
      pickTo: this.to,
      // Which end the next tap sets: 'from' or 'to'.
      editing: 'from',
      pos: { top: 0, left: 0 },
      // ⛔ On a phone a popover anchored to a button is the wrong shape: there
      // is no room beside it, the panel ends up wider than the screen, and the
      // page behind shows through the gaps. Below this width it becomes a
      // centred sheet instead — same picker, a shape that fits. His catch,
      // 2026-08-25.
      narrow: window.innerWidth <= 720,
    };
  },
  computed: {
    startOfWeek() {
      const boot = window.AgentimusData || {};
      const n = Number(boot.startOfWeek);
      return Number.isFinite(n) ? ((n % 7) + 7) % 7 : 1;
    },
    weekdays() {
      const lang = document.documentElement.lang || undefined;
      const fmt = new Intl.DateTimeFormat(lang, { weekday: 'short' });
      // 2024-01-07 was a Sunday: a known anchor to walk from.
      return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(2024, 0, 7 + ((this.startOfWeek + i) % 7));
        return fmt.format(d);
      });
    },
    months() {
      return [0, 1].map((offset) => {
        const first = new Date(this.view.getFullYear(), this.view.getMonth() + offset, 1);
        return { key: iso(first), label: this.monthLabel(first), days: this.grid(first) };
      });
    },
    // Never offer a month made entirely of days that do not exist yet.
    canGoForward() {
      const nextLeft = new Date(this.view.getFullYear(), this.view.getMonth() + 1, 1);
      return nextLeft <= fromIso(this.max);
    },
    fromLabel() {
      return this.pickFrom ? formatDate(fromIso(this.pickFrom)) : 'Pick a day';
    },
    toLabel() {
      return this.pickTo ? formatDate(fromIso(this.pickTo)) : 'Pick a day';
    },
    days() {
      if (!this.pickFrom || !this.pickTo) return 0;
      return Math.round((fromIso(this.pickTo) - fromIso(this.pickFrom)) / DAY_MS) + 1;
    },
    ready() {
      return !!this.pickFrom && !!this.pickTo;
    },
  },
  mounted() {
    this.place();
    window.addEventListener('resize', this.place);
    document.addEventListener('mousedown', this.onOutside, true);
    this.$nextTick(() => this.$refs.panel && this.$refs.panel.focus());
  },
  beforeUnmount() {
    window.removeEventListener('resize', this.place);
    document.removeEventListener('mousedown', this.onOutside, true);
  },
  methods: {
    monthLabel(d) {
      const lang = document.documentElement.lang || undefined;
      return new Intl.DateTimeFormat(lang, { month: 'long', year: 'numeric' }).format(d);
    },
    /** Six weeks of cells for one month, padded with its neighbours' days. */
    grid(first) {
      const lead = (first.getDay() - this.startOfWeek + 7) % 7;
      const start = new Date(first.getFullYear(), first.getMonth(), 1 - lead);
      const today = iso(new Date());
      const max = this.max;

      return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        const key = iso(d);
        const inMonth = d.getMonth() === first.getMonth();
        const lo = this.pickFrom;
        const hi = this.pickTo;
        return {
          key,
          day: d.getDate(),
          inMonth,
          today: key === today,
          disabled: key > max,
          edge: key === lo || key === hi,
          inRange: !!lo && !!hi && key >= lo && key <= hi,
        };
      });
    },
    step(months) {
      this.view = new Date(this.view.getFullYear(), this.view.getMonth() + months, 1);
    },
    /** Aim the calendar at one end, and show the month that end lives in. */
    edit(which) {
      this.editing = which;
      const target = which === 'from' ? this.pickFrom : this.pickTo;
      if (!target) return;
      const d = fromIso(target);
      const left = this.view.getFullYear() * 12 + this.view.getMonth();
      const at = d.getFullYear() * 12 + d.getMonth();
      // Bring it into view only when it isn't already on screen — a month that
      // jumps under the reader for no reason is its own kind of mistake.
      if (at < left || at > left + 1) this.view = new Date(d.getFullYear(), d.getMonth(), 1);
    },
    choose(cell) {
      if (cell.disabled) return;

      if (this.editing === 'from') {
        this.pickFrom = cell.key;
        // A first day after the last drags the last with it rather than being
        // refused: the reader is moving the window, not making an error.
        if (!this.pickTo || cell.key > this.pickTo) this.pickTo = cell.key;
        // Then aim at the other end, which is what they are almost certainly
        // setting next — and the tabs still let them come straight back.
        this.editing = 'to';
        return;
      }

      this.pickTo = cell.key;
      if (!this.pickFrom || cell.key < this.pickFrom) this.pickFrom = cell.key;
    },
    apply() {
      if (!this.ready) return;
      this.$emit('apply', { from: this.pickFrom, to: this.pickTo });
    },
    place() {
      this.narrow = window.innerWidth <= 720;
      const panel = this.$refs.panel;
      if (this.narrow || !panel || !this.anchor) return;
      const w = panel.offsetWidth;
      const h = panel.offsetHeight;
      const gap = 8;
      const left = Math.max(12, Math.min(this.anchor.left, window.innerWidth - w - 12));
      // Below the control by default; above it when there isn't room.
      const below = this.anchor.bottom + gap + h <= window.innerHeight - 12;
      this.pos = {
        left,
        top: below ? this.anchor.bottom + gap : Math.max(12, this.anchor.top - gap - h),
      };
    },
    onOutside(e) {
      if (this.trigger && this.trigger.contains(e.target)) return; // the toggle owns that click
      const panel = this.$refs.panel;
      if (panel && !panel.contains(e.target)) this.$emit('close');
    },
  },
};
</script>

<template>
  <Teleport to="body">
    <div
      ref="panel"
      class="ar-drp"
      role="dialog"
      aria-modal="false"
      aria-label="Pick the days to report on"
      tabindex="-1"
      :class="{ 'is-sheet': narrow }"
      :style="narrow ? null : { top: pos.top + 'px', left: pos.left + 'px' }"
      @keydown.esc.stop="$emit('close')"
    >
      <!-- ⭐ The two ends, always visible and always switchable. This is the
           control the calendar needs: it says what each end holds, which one
           the next tap will set, and lets either be corrected on its own. -->
      <div class="ar-drp__ends" role="group" aria-label="Which date to set">
        <button
          type="button"
          class="ar-drp__end"
          :class="{ 'is-on': editing === 'from' }"
          :aria-pressed="editing === 'from' ? 'true' : 'false'"
          @click="edit('from')"
        >
          <span class="ar-drp__endlabel">From</span>
          <span class="ar-drp__enddate">{{ fromLabel }}</span>
        </button>
        <span class="ar-drp__arrow" aria-hidden="true">→</span>
        <button
          type="button"
          class="ar-drp__end"
          :class="{ 'is-on': editing === 'to' }"
          :aria-pressed="editing === 'to' ? 'true' : 'false'"
          @click="edit('to')"
        >
          <span class="ar-drp__endlabel">To</span>
          <span class="ar-drp__enddate">{{ toLabel }}</span>
        </button>
      </div>

      <div class="ar-drp__months">
        <section v-for="(m, i) in months" :key="m.key" class="ar-drp__month">
          <header class="ar-drp__head">
            <button
              v-if="i === 0"
              type="button"
              class="ar-drp__step"
              aria-label="Previous month"
              @click="step(-1)"
            >‹</button>
            <span class="ar-drp__label">{{ m.label }}</span>
            <!-- ⛔ The forward arrow lives in the SECOND month's header, which
                 the narrow layout hides — so the first month carries its own,
                 shown only there. Without it a phone could walk backwards for
                 ever and never return. -->
            <button
              v-if="i === 0"
              type="button"
              class="ar-drp__step ar-drp__step--next ar-drp__step--narrow"
              aria-label="Next month"
              :disabled="!canGoForward"
              @click="step(1)"
            >›</button>
            <button
              v-if="i === 1"
              type="button"
              class="ar-drp__step ar-drp__step--next"
              aria-label="Next month"
              :disabled="!canGoForward"
              @click="step(1)"
            >›</button>
          </header>

          <div class="ar-drp__grid" role="grid">
            <span v-for="d in weekdays" :key="d" class="ar-drp__wd">{{ d }}</span>
            <button
              v-for="cell in m.days"
              :key="cell.key"
              type="button"
              class="ar-drp__day"
              :class="{
                'is-out': !cell.inMonth,
                'is-today': cell.today,
                'is-edge': cell.edge,
                'is-in': cell.inRange && !cell.edge,
              }"
              :disabled="cell.disabled"
              :aria-current="cell.today ? 'date' : null"
              @click="choose(cell)"
            >{{ cell.day }}</button>
          </div>
        </section>
      </div>

      <footer class="ar-drp__foot">
        <p class="ar-drp__chosen">{{ days }} {{ days === 1 ? 'day' : 'days' }}</p>
        <div class="ar-drp__actions">
          <button type="button" class="ar-btn ar-btn--ghost agv-btn-sm" @click="$emit('close')">Cancel</button>
          <button type="button" class="ar-btn agv-btn-sm" :disabled="!ready" @click="apply">Apply</button>
        </div>
      </footer>
    </div>
  </Teleport>
</template>

<style>
.ar-drp {
  position: fixed;
  z-index: 100002; /* over the app, under nothing else this screen opens */
  background: var(--ar-surface);
  border: 1px solid var(--ar-line-strong);
  border-radius: var(--ar-radius);
  box-shadow: 0 18px 44px rgba(0, 0, 0, 0.34);
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-width: calc(100vw - 24px);
}
.ar-drp:focus { outline: none; }
.ar-drp__ends { display: flex; align-items: center; gap: 8px; }
.ar-drp__end {
  flex: 1; min-width: 0; cursor: pointer; text-align: left;
  display: flex; flex-direction: column; gap: 2px;
  padding: 7px 11px; border-radius: var(--ar-radius);
  background: transparent; border: 1px solid var(--ar-line); color: var(--ar-ink);
}
.ar-drp__end:hover { border-color: var(--ar-line-strong); background: var(--ar-surface-2); }
.ar-drp__end:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 2px; }
/* The active end is marked by its BORDER and a filled label, never by colour
   alone — the same rule the rest of this app keeps. */
.ar-drp__end.is-on { border-color: var(--ar-accent); background: color-mix(in srgb, var(--ar-accent) 12%, var(--ar-surface)); }
.ar-drp__endlabel { font-family: var(--ar-mono); font-size: 9.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ar-ink-faint); }
.ar-drp__end.is-on .ar-drp__endlabel { color: var(--ar-accent); }
.ar-drp__enddate { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ar-drp__arrow { flex: 0 0 auto; color: var(--ar-ink-faint); font-size: 13px; }

.ar-drp__months { display: flex; gap: 22px; }
.ar-drp__month { display: flex; flex-direction: column; gap: 8px; }
.ar-drp__month + .ar-drp__month { padding-left: 22px; border-left: 1px solid var(--ar-line); }

.ar-drp__head { display: flex; align-items: center; gap: 8px; min-height: 26px; }
.ar-drp__label { flex: 1; text-align: center; font-family: var(--ar-serif); font-size: 15px; font-weight: 600; color: var(--ar-ink); white-space: nowrap; }
.ar-drp__step {
  width: 26px; height: 26px; flex: 0 0 auto; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 16px; line-height: 1;
  background: transparent; border: 1px solid var(--ar-line); border-radius: var(--ar-radius);
  color: var(--ar-ink-soft);
}
.ar-drp__step:hover:not(:disabled) { background: var(--ar-surface-2); color: var(--ar-ink); border-color: var(--ar-line-strong); }
.ar-drp__step:disabled { opacity: 0.4; cursor: default; }
.ar-drp__step:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 2px; }
.ar-drp__step--next { margin-left: auto; }
.ar-drp__step--narrow { display: none; }

.ar-drp__grid { display: grid; grid-template-columns: repeat(7, 32px); gap: 2px; }
.ar-drp__wd {
  height: 22px; display: flex; align-items: center; justify-content: center;
  font-family: var(--ar-mono); font-size: 9.5px; letter-spacing: 0.04em; text-transform: uppercase;
  color: var(--ar-ink-faint);
}
.ar-drp__day {
  height: 30px; padding: 0; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-family: var(--ar-sans); font-size: 12.5px; font-variant-numeric: tabular-nums;
  background: transparent; border: 1px solid transparent; border-radius: var(--ar-radius);
  color: var(--ar-ink);
}
.ar-drp__day:hover:not(:disabled) { background: var(--ar-surface-2); }
.ar-drp__day:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 1px; }
/* A day outside this month, and a day that hasn't happened, are both dimmed —
   but only the second is unclickable, and the cursor says so. */
.ar-drp__day.is-out { color: var(--ar-ink-faint); }
.ar-drp__day:disabled { color: var(--ar-ink-faint); opacity: 0.45; cursor: default; }
/* Today is marked by an outline, never by colour alone. */
.ar-drp__day.is-today { border-color: var(--ar-line-strong); font-weight: 600; }
.ar-drp__day.is-in { background: color-mix(in srgb, var(--ar-accent) 18%, var(--ar-surface)); border-radius: 0; }
.ar-drp__day.is-edge { background: var(--ar-accent); color: var(--ar-paper); font-weight: 600; border-color: var(--ar-accent); }

.ar-drp__foot { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding-top: 10px; border-top: 1px solid var(--ar-line); }
.ar-drp__chosen { margin: 0; font-family: var(--ar-mono); font-size: 10.5px; letter-spacing: 0.03em; color: var(--ar-ink-soft); }
.ar-drp__actions { margin-left: auto; display: flex; gap: 8px; }

/* One month at a time once two won't fit — the same picker, not a lesser one —
   and centred in the screen rather than hung off a button that has no room
   beside it. Day cells grow to a thumb-sized target while they are at it. */
@media (max-width: 720px) {
  .ar-drp__months { gap: 0; }
  .ar-drp__month, .ar-drp__grid { width: 100%; }
  .ar-drp__month + .ar-drp__month { display: none; }
  .ar-drp__grid { grid-template-columns: repeat(7, 1fr); gap: 3px; }
  .ar-drp__day { height: 40px; font-size: 14px; }
  .ar-drp__wd { height: 26px; }
  .ar-drp__step--narrow { display: inline-flex; }
  .ar-drp__foot { flex-direction: column; align-items: stretch; gap: 10px; }
  .ar-drp__actions { margin-left: 0; }
  .ar-drp__actions .ar-btn { flex: 1; }
}

.ar-drp.is-sheet {
  left: 12px;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  max-height: calc(100vh - 24px);
  max-height: calc(100svh - 24px); /* iOS: the SMALL viewport, not the large one */
  overflow-y: auto;
}
</style>
