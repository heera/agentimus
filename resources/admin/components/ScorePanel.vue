<script>
/**
 * The AEO/GEO score hero — the top section of the Dashboard. Shows one blended
 * 0–100 read on how ready the site is to be found, understood, and cited by AI
 * answer engines, the four pillars behind it, and an impact-ranked "do this next"
 * list. All data comes from the server ({@see \Agentimus\Score}); action rows reuse
 * the same in-app jump / external-link shape as the Readiness report.
 */
export default {
  name: 'ScorePanel',
  props: {
    aeo: { type: Object, default: null },
  },
  emits: ['navigate'],
  data() {
    return { R: 54, ready: false };
  },
  computed: {
    score() {
      return this.aeo ? this.aeo.score : 0;
    },
    blocked() {
      return !!(this.aeo && this.aeo.blocked);
    },
    // Green once strong, amber when fair, red when weak or blocked — matches the
    // readiness gauge's good/ok/low language.
    tone() {
      if (this.blocked) return 'low';
      return this.score >= 70 ? 'good' : this.score >= 50 ? 'ok' : 'low';
    },
    circumference() {
      return 2 * Math.PI * this.R;
    },
    // Full offset until mounted, then animates to the score (empty → filled).
    dashOffset() {
      const pct = this.blocked ? 0 : this.score;
      return this.ready ? this.circumference * (1 - pct / 100) : this.circumference;
    },
    pillars() {
      return this.aeo && this.aeo.pillars ? Object.values(this.aeo.pillars) : [];
    },
    actions() {
      return this.aeo && this.aeo.actions ? this.aeo.actions : [];
    },
  },
  mounted() {
    // One frame later so the ring transitions in rather than snapping.
    window.requestAnimationFrame(() => { this.ready = true; });
  },
  methods: {
    pillarTone(p) {
      if (p.score === null) return 'muted';
      return p.score >= 70 ? 'good' : p.score >= 50 ? 'ok' : 'low';
    },
    // A short chip per action, by why-it's-here rather than raw severity.
    sevLabel(s) {
      return { fail: 'Fix', warn: 'Improve', content: 'Content', info: 'Optional' }[s] || 'Improve';
    },
  },
};
</script>

<template>
  <section v-if="aeo" class="ar-card ar-score">
    <div class="ar-score__head">
      <div class="ar-score__intro">
        <h2 class="ar-card__title">AI visibility score <span class="ar-card__tag">AEO / GEO</span></h2>
        <p class="ar-score__lead">How ready your site is to be found, understood, and cited by AI answer engines — from the checks below plus your content and any measured citations.</p>
      </div>
      <div class="ar-score__dial" :data-tone="tone" role="img" :aria-label="blocked ? 'Score unavailable — site blocked from crawlers' : `Score ${score} out of 100 — ${aeo.band}`">
        <svg viewBox="0 0 128 128" aria-hidden="true">
          <circle class="ar-score__track" cx="64" cy="64" :r="R" />
          <circle class="ar-score__fill" cx="64" cy="64" :r="R" :stroke-dasharray="circumference" :stroke-dashoffset="dashOffset" />
        </svg>
        <div class="ar-score__num">
          <strong>{{ blocked ? '—' : score }}</strong>
          <span>{{ aeo.band }}</span>
        </div>
      </div>
    </div>

    <p v-if="blocked" class="ar-score__blocked">
      Your site is hidden from search engines, so agents can’t reach it. Fix that first — everything else is on hold until they can.
    </p>

    <div class="ar-score__pillars">
      <component
        :is="p.to ? 'button' : 'div'"
        v-for="p in pillars"
        :key="p.key"
        class="ar-score__pillar"
        :class="{ 'is-link': p.to }"
        :data-tone="pillarTone(p)"
        :type="p.to ? 'button' : undefined"
        :title="p.to ? ('Open ' + (p.to === 'visibility' ? 'AI Visibility' : 'the Readiness report')) : undefined"
        @click="p.to ? $emit('navigate', { tab: p.to }) : null"
      >
        <div class="ar-score__pillar-top">
          <span class="ar-score__pillar-label">{{ p.label }}<span v-if="p.to" class="ar-score__pillar-go" aria-hidden="true">→</span></span>
          <span class="ar-score__pillar-num">{{ p.score === null ? '—' : p.score }}</span>
        </div>
        <span class="ar-score__track2"><span class="ar-score__bar" :style="{ width: (p.score === null ? 0 : p.score) + '%' }"></span></span>
        <span class="ar-score__pillar-note">{{ p.note }}</span>
      </component>
    </div>

    <div v-if="actions.length" class="ar-score__actions">
      <h3 class="ar-score__actions-h">Do this next</h3>
      <ul class="ar-score__list">
        <li v-for="a in actions" :key="a.id" class="ar-score__item" :data-sev="a.severity">
          <span class="ar-score__sev">{{ sevLabel(a.severity) }}</span>
          <span class="ar-score__body">
            <span class="ar-score__act-title">{{ a.title }}</span>
            <span class="ar-score__act-why">{{ a.why }}</span>
          </span>
          <a
            v-if="a.action && a.action.href"
            class="ar-score__act-btn"
            :href="a.action.href"
            target="_blank"
            rel="noopener"
          >{{ a.action.label }} ↗</a>
          <button
            v-else-if="a.action"
            type="button"
            class="ar-score__act-btn"
            @click="$emit('navigate', { tab: a.action.tab, anchor: a.action.anchor })"
          >{{ a.action.label }} →</button>
        </li>
      </ul>
    </div>
    <p v-else-if="!blocked" class="ar-score__clear">Nothing outstanding — your site is in great shape for AI. Keep publishing.</p>
  </section>
</template>

<style scoped>
.ar-score { display: flex; flex-direction: column; gap: 22px; }
.ar-score__head { display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
.ar-score__intro { flex: 1 1 320px; min-width: 0; }
.ar-score__lead { margin: 8px 0 0; font-size: 13.5px; line-height: 1.55; color: var(--ar-ink-soft); max-width: 52ch; }

/* Dial — same track+fill ring as the readiness gauge, sized up as the hero. */
.ar-score__dial { position: relative; flex: 0 0 auto; width: 128px; height: 128px; }
.ar-score__dial svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.ar-score__track { fill: none; stroke: var(--ar-surface-2); stroke-width: 9; }
.ar-score__fill {
  fill: none; stroke-width: 9; stroke-linecap: round;
  transition: stroke-dashoffset 900ms cubic-bezier(0.22, 1, 0.36, 1);
}
.ar-score__dial[data-tone="good"] .ar-score__fill { stroke: var(--ar-good); }
.ar-score__dial[data-tone="ok"] .ar-score__fill { stroke: var(--ar-warn); }
.ar-score__dial[data-tone="low"] .ar-score__fill { stroke: var(--ar-bad); }
.ar-score__num {
  position: absolute; inset: 0; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 1px;
}
.ar-score__num strong { font-size: 34px; line-height: 1; font-weight: 700; color: var(--ar-ink); font-variant-numeric: tabular-nums; }
.ar-score__num span { font-family: var(--ar-mono); font-size: 10px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-ink-faint); }

.ar-score__blocked {
  margin: 0; padding: 12px 14px; border-radius: 9px; font-size: 13.5px; line-height: 1.5;
  color: var(--ar-bad); background: rgba(185, 60, 43, 0.08); border: 1px solid rgba(185, 60, 43, 0.25);
}

/* Pillars — four mini bars, each carrying its own tone. */
.ar-score__pillars { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
.ar-score__pillar { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
/* A pillar with a stage destination is a button — a doorway to where you act on it. */
.ar-score__pillar.is-link {
  cursor: pointer; text-align: left; font: inherit; appearance: none; -webkit-appearance: none;
  background: none; border: 0; padding: 0; border-radius: 8px;
  box-shadow: 0 0 0 0 transparent; transition: box-shadow 0.15s ease, background 0.15s ease;
}
/* A ring of surface colour on hover — reads as highlighted without a padding shift
   that would misalign it against the non-clickable Optimize pillar. */
.ar-score__pillar.is-link:hover { background: var(--ar-surface-2); box-shadow: 0 0 0 6px var(--ar-surface-2); }
.ar-score__pillar.is-link:hover { background: var(--ar-surface-2); }
.ar-score__pillar.is-link:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 2px; }
.ar-score__pillar-go { margin-left: 5px; font-family: var(--ar-mono); font-size: 11px; color: var(--ar-ink-faint); opacity: 0; transition: opacity 0.15s ease; }
.ar-score__pillar.is-link:hover .ar-score__pillar-go,
.ar-score__pillar.is-link:focus-visible .ar-score__pillar-go { opacity: 1; }
.ar-score__pillar-top { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.ar-score__pillar-label { font-size: 12.5px; font-weight: 600; color: var(--ar-ink); }
.ar-score__pillar-num { font-family: var(--ar-mono); font-size: 13px; font-variant-numeric: tabular-nums; color: var(--ar-ink-soft); }
.ar-score__track2 { height: 6px; background: var(--ar-surface-2); border-radius: 999px; overflow: hidden; }
.ar-score__bar { display: block; height: 100%; border-radius: 999px; background: var(--ar-ink-faint); transition: width 700ms cubic-bezier(0.22, 1, 0.36, 1); }
.ar-score__pillar[data-tone="good"] .ar-score__bar { background: var(--ar-good); }
.ar-score__pillar[data-tone="ok"] .ar-score__bar { background: var(--ar-warn); }
.ar-score__pillar[data-tone="low"] .ar-score__bar { background: var(--ar-bad); }
.ar-score__pillar[data-tone="muted"] .ar-score__pillar-num { color: var(--ar-ink-faint); }
.ar-score__pillar-note { font-size: 11.5px; line-height: 1.4; color: var(--ar-ink-faint); }

/* Do this next. */
.ar-score__actions { border-top: 1px solid var(--ar-line); padding-top: 18px; }
.ar-score__actions-h { margin: 0 0 12px; font-family: var(--ar-mono); font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--ar-ink-faint); }
.ar-score__list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; }
.ar-score__item { display: flex; align-items: center; gap: 14px; padding: 11px 0; border-top: 1px solid var(--ar-line); }
.ar-score__item:first-child { border-top: 0; }
.ar-score__sev {
  flex: 0 0 auto; align-self: flex-start; margin-top: 1px; min-width: 62px; text-align: center;
  font-family: var(--ar-mono); font-size: 10px; letter-spacing: 0.05em; text-transform: uppercase;
  padding: 3px 8px; border-radius: 6px; color: var(--ar-ink-soft); background: var(--ar-surface-2);
}
.ar-score__item[data-sev="fail"] .ar-score__sev { color: var(--ar-bad); background: rgba(185, 60, 43, 0.1); }
.ar-score__item[data-sev="warn"] .ar-score__sev { color: var(--ar-warn); background: rgba(173, 123, 24, 0.12); }
.ar-score__body { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.ar-score__act-title { font-size: 13.5px; font-weight: 600; color: var(--ar-ink); }
.ar-score__act-why { font-size: 12.5px; line-height: 1.45; color: var(--ar-ink-soft); }
.ar-score__act-btn {
  flex: 0 0 auto; align-self: center; white-space: nowrap; text-decoration: none; cursor: pointer;
  font-family: var(--ar-mono); font-size: 11.5px; padding: 6px 12px; border-radius: 7px;
  color: var(--ar-accent); background: rgba(20, 107, 100, 0.08);
  border: 1px solid rgba(20, 107, 100, 0.22); transition: background 0.15s ease;
}
.ar-score__act-btn:hover { background: rgba(20, 107, 100, 0.15); }
.ar-score__act-btn:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 2px; }
.ar-score__clear { margin: 0; font-size: 13.5px; color: var(--ar-good); }

@media (max-width: 640px) {
  .ar-score__pillars { grid-template-columns: repeat(2, 1fr); }
  .ar-score__head { justify-content: center; text-align: center; }
  .ar-score__lead { margin-inline: auto; }
}
@media (prefers-reduced-motion: reduce) {
  .ar-score__fill, .ar-score__bar { transition: none; }
}
</style>
