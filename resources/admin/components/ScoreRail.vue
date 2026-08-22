<script>
import { uaTip } from '../js/uaTip.js';
import { tipGuard } from '../js/tipGuard.js';

/**
 * The right rail: the AEO/GEO score card (gauge, band, the five rungs, the
 * next-step line), the live-endpoint and discovery-doc link cards, the
 * registration-status card and the colophon. Pure display over props — the
 * score data, and every derived list, stays with App (panels share them);
 * navigation goes back up as a `navigate` emit, exactly what goTo takes.
 * Carries its own uaTip mixin instance for the next-step hover hints.
 */
export default {
  name: 'ScoreRail',
  mixins: [uaTip],
  props: {
    aeo: { type: Object, default: null },
    siteIsLocal: { type: Boolean, default: false },
    ringReady: { type: Boolean, default: false },
    endpoints: { type: Object, required: true },
    discoveryDocs: { type: Array, default: () => [] },
    optimizeTotal: { type: Number, default: 0 },
    optimizeActionable: { type: Boolean, default: false },
    validation: { type: Object, required: true },
  },
  emits: ['navigate'],
  computed: {
    /* The same button does two different things: a config fix opens the tool
       in a NEW TAB (window.open in openNext), a per-post gap walks to another
       screen of this app. One arrow for both was a promise it broke half the
       time, so the glyph says which one this is (audit, 2026-08-15). */
    nextGlyph() {
      const a = this.aeoNext && this.aeoNext.action;
      return a && a.href ? '\u2197' : '\u2192';
    },

    circumference() {
      return 2 * Math.PI * 52;
    },
    // The rail gauge shows the composite AEO/GEO score {@see \Agentimus\Score}.
    aeoTone() {
      if (!this.aeo || this.aeo.blocked) return this.siteIsLocal ? 'ok' : 'low';
      return this.aeo.score >= 70 ? 'good' : this.aeo.score >= 50 ? 'ok' : 'low';
    },
    aeoDashOffset() {
      const pct = this.aeo && !this.aeo.blocked ? this.aeo.score : 0;
      return this.ringReady ? this.circumference * (1 - pct / 100) : this.circumference;
    },
    // The single most impactful next step, shown as the rail's next-step line.
    aeoNext() {
      return this.aeo && this.aeo.actions && this.aeo.actions.length ? this.aeo.actions[0] : null;
    },
    // Its hover hint: WHY this is the next thing — the complaint in plain words
    // (the action's own `why`), with the click destination as the second line.
    aeoNextTip() {
      return this.aeoNext ? (this.aeoNext.why || this.aeoNext.title || '') : '';
    },
    aeoNextTipHint() {
      const a = this.aeoNext && this.aeoNext.action;
      if (!a) return '';
      // href actions open a new tab (see openNext) — say so; in-app jumps keep
      // the action's own label ("Open Visibility").
      return a.href ? 'Open in a new tab →' : (a.label ? `${a.label} →` : '');
    },
  },
  methods: {
    // Navigate up to App, hiding any hint first (the app's goTo used to do this).
    go(target) {
      this.hideUaTip();
      this.$emit('navigate', target);
    },
    // ---- rungs -------------------------------------------------------------
    // A rung's dot state: green when complete, amber when partway, muted when empty.
    // Check rungs complete at 100 (all checks pass); signal rungs at 70+.
    rungState(r) {
      if (r.score === null) return '';
      if ('check' === r.kind) return 100 === r.score ? 'done' : r.score >= 50 ? 'current' : '';
      return r.score >= 70 ? 'done' : r.score >= 50 ? 'current' : '';
    },
    // Every rung shows its 0–100 score on one consistent scale (they roll up to the
    // composite in the gauge). The per-check tally lives on the Readiness tab.
    rungCount(r) {
      // An unmeasured Cited explains itself in words, in the value slot itself
      // (right-aligned like every value) — a bare dash read as "broken", when
      // the truth is just "no reading yet".
      if (null === r.score) return 'cited' === r.key ? 'not measured yet' : '—';
      return `${r.score}%`;
    },
    rungTarget(r) {
      // Cited opens Visibility on the sub-view the score chose: Settings when setup
      // isn't complete enough to run a check, otherwise Results.
      return 'visibility' === r.to ? { tab: 'visibility', view: r.view || 'results' } : { tab: 'readiness', anchor: `ar-group-${r.key}` };
    },
    rungTitle(r) {
      return 'visibility' === r.to ? 'Open Visibility' : `View ${r.label} checks in the readiness report`;
    },
    // "N to fix" for a check-backed rung: its non-passing (warn or fail) checks.
    rungTodo(r) {
      return r && 'check' === r.kind ? Math.max(0, (r.total || 0) - (r.pass || 0)) : 0;
    },
    // The next-step line: follow the top action's own jump/link, or fall back to the
    // full report. An external-link action opens in a new tab.
    openNext() {
      // Only called when the top action has a real destination (config fix / measure);
      // content gaps are per-post and render as a non-clickable info line instead.
      const a = this.aeoNext && this.aeoNext.action;
      if (!a) return;
      this.hideUaTip(); // the href path never reaches go()'s own hide
      if (a.href) {
        window.open(a.href, '_blank', 'noopener');
        return;
      }
      this.go(a);
    },
    showRailTip(ev, text, hint = '') {
      if (!text) return;
      const el = ev.currentTarget;
      // Scroll-induced mouseenters wait for a real mouse move (see tipGuard).
      if (tipGuard.suppress(ev, el, () => this.showRailTip({ currentTarget: el, type: 'retry' }, text, hint))) return;
      const rect = el.getBoundingClientRect();
      const card = (el.closest && el.closest('.ar-rail-card')) || el.parentElement;
      const c = card.getBoundingClientRect();
      const below = rect.top < 96;
      // The width cap rides the reactive state: an imperative tip.style.maxWidth
      // would be wiped the moment Vue re-patches the bubble's :style binding.
      const maxW = `${Math.max(160, Math.round(c.width - 24))}px`;
      this.uaTip = {
        show: true, text, hint, below, maxW,
        x: Math.round(c.left + c.width / 2),
        y: below ? rect.bottom + 8 : rect.top - 8,
        caret: 0, // unused by the --rail variant: its caret is CSS-centred
      };
    },
  },
};
</script>

<template>
      <aside class="ar__rail">
        <!-- The Readiness card, evolved: the gauge now shows the composite AEO/GEO
             score, and the ladder extends the three readiness rungs with two AEO/GEO
             rungs (Optimized, Cited). Same card, same dark look — extended. -->
        <div v-if="aeo" class="ar-rail-card ar-rail-card--readiness">
          <!-- The one place the headline label is taught: kicker and meaning share
               the line, dash-joined like a masthead and its tagline. The gloss sets
               in the upright serif — a voice this card already speaks, and one every
               platform's font stack can honour. -->
          <p class="ar-rail-card__label">AEO / GEO <span class="ar-rail-card__gloss">&mdash; Your Readiness for AI Search</span></p>
          <button
            type="button"
            class="ar-rail-readiness ar-rail-readiness--link"
            aria-label="Open the full readiness report"
            @click="go('readiness')"
          >
            <div class="ar-rail-gauge" role="img" :aria-label="`AEO/GEO score ${aeo.score} of 100`">
              <svg viewBox="0 0 116 116">
                <circle class="ar-rail-gauge__track" cx="58" cy="58" r="52" />
                <circle
                  class="ar-rail-gauge__fill"
                  cx="58"
                  cy="58"
                  r="52"
                  :data-tone="aeoTone"
                  :stroke-dasharray="circumference"
                  :stroke-dashoffset="aeoDashOffset"
                />
              </svg>
              <span class="ar-rail-gauge__num">{{ aeo.blocked ? '—' : aeo.score }}<small v-if="!aeo.blocked">%</small></span>
            </div>
            <!-- The blocked verdict (blog_public off) reads differently by context:
                 on a live site it's the red master-switch alarm; on a local site
                 it's a calm to-do for launch day, not a failure. -->
            <!-- The band carries the gauge's own tone as well as its state:
                 "Excellent" beside a green ring was rendering white, because
                 "climb" (anything short of fully ready) was doing the colouring
                 — so the word and the ring it labels disagreed. -->
            <div
              class="ar-rail-tier"
              :data-state="aeo.blocked ? (siteIsLocal ? 'local' : 'floor') : (aeo.ready ? 'top' : 'climb')"
              :data-tone="aeoTone"
            >
              <strong class="ar-rail-tier__name">{{ aeo.blocked ? (siteIsLocal ? 'Not public yet' : 'Not reachable') : aeo.band }}</strong>
              <!-- Only the blocked states earn a second line — they explain the
                   em-dash gauge. On the healthy path "fully agent-ready" was a
                   second verdict under the first; the band says it all. -->
              <span v-if="aeo.blocked" class="ar-rail-tier__sub">{{
                siteIsLocal ? 'switch on Search engine visibility before launch' : 'AI assistants can’t read the site'
              }}</span>
            </div>
          </button>

          <!-- The three readiness rungs, extended by the two AEO/GEO rungs. Each links
               to where you act on it; Optimized is per-post, so it's a plain stat row. -->
          <ol class="ar-rungs">
            <li v-for="r in aeo.rungs" :key="r.key" class="ar-rung" :data-state="rungState(r)">
              <button
                v-if="r.to"
                type="button"
                class="ar-rung__btn"
                :aria-label="rungTitle(r)"
                @click="go(rungTarget(r))"
              >
                <span class="ar-rung__tick" aria-hidden="true"></span>
                <span class="ar-rung__name">{{ r.label }}</span>
                <!-- Check-backed rungs count their non-passing checks; Cited is a
                     measurement, not a checklist, so it never wears the chip. -->
                <em v-if="rungTodo(r)" class="ar-rung__todo">{{ rungTodo(r) }} to fix</em>
                <span class="ar-rung__count">{{ rungCount(r) }}</span>
              </button>
              <!-- Optimized routes like the other rungs — to its section on the
                   Readiness tab (the per-page worklist), when there's work to do. -->
              <button
                v-else-if="r.key === 'optimized' && optimizeActionable"
                type="button"
                class="ar-rung__btn"
                aria-label="See which pages to optimize"
                @click="go({ tab: 'readiness', anchor: 'ar-group-optimized' })"
              >
                <span class="ar-rung__tick" aria-hidden="true"></span>
                <span class="ar-rung__name">{{ r.label }}</span>
                <!-- The WHOLE worklist's size — the Next line below only ever
                     names the top issue, so this is where the total lives. -->
                <em v-if="optimizeTotal" class="ar-rung__todo">{{ optimizeTotal }} to fix</em>
                <span class="ar-rung__count">{{ rungCount(r) }}</span>
              </button>
              <div v-else class="ar-rung__btn ar-rung__btn--static">
                <span class="ar-rung__tick" aria-hidden="true"></span>
                <span class="ar-rung__name">{{ r.label }}</span>
                <span class="ar-rung__count">{{ rungCount(r) }}</span>
              </div>
            </li>
          </ol>

          <button
            v-if="aeoNext && aeoNext.action"
            type="button"
            class="ar-rail-link ar-rail-next"
            @mouseenter="showRailTip($event, aeoNextTip, aeoNextTipHint)"
            @mouseleave="hideUaTip"
            @focus="showRailTip($event, aeoNextTip, aeoNextTipHint)"
            @blur="hideUaTip"
            @click="openNext"
          >Next: {{ aeoNext.title }} {{ nextGlyph }}</button>
          <p
            v-else-if="aeoNext"
            class="ar-rail-next ar-rail-next--info"
            @mouseenter="showRailTip($event, aeoNextTip)"
            @mouseleave="hideUaTip"
          >Next: {{ aeoNext.title }}</p>
          <p v-else class="ar-rail-allgood">
            <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="8" cy="8" r="6.5" />
              <path d="M5.2 8.3l2 2 3.6-4.2" />
            </svg>
            Everything looks good.
          </p>
        </div>

        <div class="ar-rail-card">
          <!-- The mark wears the plugin's one shape for a mark: a soft rounded
               square, glyph in the accent on its own faint wash. ⛔ never a
               filled disc — a solid circle already means "state" here (the
               connection rails' dot, the readiness rungs' done tick), and a
               green disc beside a list of files would read as a health light
               claiming something it cannot know. -->
          <p class="ar-rail-card__label ar-rail-card__label--mark">
            <span class="ar-rail-card__mark" aria-hidden="true">
              <!-- ⭐ AN OPEN BOOK — his call, 2026-08-22: the two marks must carry
                   the same split the headings do. A globe said "these live on the
                   web", which is true of BOTH cards and so distinguished nothing.
                   A book is what a reader opens to find out what something is
                   about, which is exactly what llms.txt is. -->
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 7.4v11.4" />
                <path d="M12 7.4C10.3 6.2 8 5.7 5 5.7v11.4c3 0 5.3.5 7 1.7" />
                <path d="M12 7.4c1.7-1.2 4-1.7 7-1.7v11.4c-3 0-5.3.5-7 1.7" />
              </svg>
            </span>
            <!-- ⭐ HIS WORDING, 2026-08-22. The heading SAYS what the files are,
                 so nothing has to be explained under it — a teaching line was
                 tried here first and rejected: the rail is mono top to bottom,
                 and a serif sentence dropped into it read as a second voice.
                 ⭐⭐ THE TWO HEADINGS ARE THE SAME CHARACTER COUNT ON PURPOSE — 21
                 each — and that is a real constraint, not a preference. This
                 kicker is MONOSPACE, so characters ARE pixels: 21 chars renders
                 at 157.9px in both cards, so the two headings start and end on the
                 same pixel. ⛔ EDIT EITHER HEADING AND YOU MUST RE-COUNT BOTH — one
                 character of drift is 7.5px of misalignment, and it shows, because
                 the cards sit one above the other in a 286px column.
                 ⚠️ The line holds 217px (the card's 248px inner width, less the
                 22px mark and its 9px gap), so 28 characters is the ceiling before
                 a heading wraps under its own icon.
                 ⛔⛔ NEITHER HEADING MAY SAY "READ" — his catch, and it is a logic
                 fault, not a word choice. An earlier pair led with "Files AI
                 assistants read", which quietly claims the OTHER card's files are
                 not read by AI. They are: discovery.json and the MCP cards exist
                 to be fetched. The true split is CONTENT vs CAPABILITIES — what
                 the site is about, against what it can do — so neither card
                 mentions reading and neither can imply the other does not. -->
            What your site covers
          </p>
          <ul class="ar-rail-links">
            <li><a :href="endpoints.llms" target="_blank" rel="noopener">llms.txt</a></li>
            <li><a :href="endpoints.llmsFull" target="_blank" rel="noopener">llms-full.txt</a></li>
            <li><a :href="endpoints.robots" target="_blank" rel="noopener">robots.txt</a></li>
          </ul>
        </div>

        <div v-if="discoveryDocs.length" class="ar-rail-card">
          <p class="ar-rail-card__label ar-rail-card__label--mark">
            <span class="ar-rail-card__mark" aria-hidden="true">
              <!-- ⭐⭐ THE BOLT IS ALREADY THIS PLUGIN'S MARK FOR CAPABILITIES —
                   TILE_ICONS.capabilities in js/groupIcons.js, whose dashboard tile
                   reads "What AI assistants may read or do, declared in
                   discovery.json". That is this card's first file. So this is not a
                   new symbol, it is the existing one arriving where it belongs:
                   groupIcons' own law, "one concept, one symbol, wherever it
                   appears".
                   ⛔ The PATH is shared with that file and must stay in step with
                   it — only the stroke differs (1.7 here, 2 there), because the
                   rail is the quietest column on the screen.
                   ⛔ A page-with-lines said "these are documents", which is true of
                   both cards and told the owner nothing about which is which. -->
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12.9 3.4L6 13h4.4l-1.3 7.6L16 11h-4.4z" />
              </svg>
            </span>
            <!-- ⛔ 21 characters, matching the card above. See the note there
                 before touching either — including the rule that neither may say
                 "read". "Discovery docs" named the mechanism; this names what the
                 owner gets from it. -->
            What your site offers
          </p>
          <ul class="ar-rail-links">
            <li v-for="d in discoveryDocs" :key="d.label">
              <a :href="d.url" target="_blank" rel="noopener">{{ d.label }}</a>
            </li>
          </ul>
        </div>

        <!-- Registration status — its own compact one-line card (separate from the black
             card). Green ✓ when valid, amber alert when broken; the → is always shown. -->
        <button
          type="button"
          class="ar-rail-card ar-rail-regcard"
          :class="validation.ok ? 'is-ok' : 'is-alert'"
          @click="go({ tab: 'discovery', anchor: validation.ok ? 'ar-wd-providers' : 'ar-wd-validation' })"
        >
          <!-- A drawn ring, not a text glyph: ✓ and ⚠ come from two different
               places in the font stack and never sat on the same baseline or
               at the same weight. One ring, one stroke, both states.
               ⛔⛔ AND THE RING STAYS A RING. On 2026-08-22 this mark was given the
               two cards' square tile so all three would match, and it was reverted
               the same evening — his call, agreeing with the objection: a circle in
               this rail means STATE (the connection dot, the readiness tick, and
               this row, which renders a VERDICT about the registrations). The two
               cards above name kinds of thing. Square = a kind, circle = a
               judgement about one, and making all three the same shape threw that
               away for the sake of a family resemblance nothing needed.
               ⭐ What the marks share instead is size (18px) and the accent-family
               edge — enough to read as one set without the shapes lying. -->
          <span class="ar-rail-regcard__icon" aria-hidden="true">
            <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="9" cy="9" r="7.4" />
              <path v-if="validation.ok" d="M5.9 9.3l2.2 2.2 4-4.6" />
              <template v-else><path d="M9 5.4v4.4" /><path d="M9 12.5h.01" /></template>
            </svg>
          </span>
          <span class="ar-rail-regcard__text">{{ validation.ok ? 'All registrations are valid' : `${validation.count} ${validation.count === 1 ? 'issue' : 'issues'} to fix` }}</span>
          <span class="ar-rail-regcard__go" aria-hidden="true">→</span>
        </button>

        <p class="ar-rail-foot" aria-label="Made with love by Sheikh Heera"><span class="ar-rail-foot__text">Made with <span class="ar-rail-foot__heart" aria-hidden="true">♥</span> by <a class="ar-rail-foot__link" href="https://heera.it" target="_blank" rel="noopener">Sheikh Heera</a></span></p>
      </aside>

    <!-- The styled hover bubble for the score rail's rung + next-step hints —
         the shared uaTip state, in its prose (--info) variant. -->
    <Teleport to="body">
      <transition name="ar-tip">
        <div
          v-if="uaTip.show"
          ref="uaTipEl"
          class="ar-act-uatip ar-act-uatip--info ar-act-uatip--rail"
          :class="{ 'is-below': uaTip.below }"
          :style="{ left: uaTip.x + 'px', top: uaTip.y + 'px', maxWidth: uaTip.maxW || null }"
          role="tooltip"
          aria-hidden="true"
        ><span class="ar-act-uatip__ua">{{ uaTip.text }}</span><span v-if="uaTip.hint" class="ar-act-uatip__hint">{{ uaTip.hint }}</span><span class="ar-act-uatip__caret"></span></div>
      </transition>
    </Teleport>
</template>
