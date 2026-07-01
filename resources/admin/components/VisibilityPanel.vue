<script>
/**
 * AI Visibility tab — track whether AI assistants (ChatGPT, Perplexity, Gemini,
 * Claude) mention and cite this site. Two sub-views: Results (the scored latest
 * run) and Settings (brand, competitors, prompts, per-engine keys, schedule).
 *
 * Reuses the app's own building blocks (ar-tabpanel / ar-card / ar-field /
 * ar-input / ar-btn) so it inherits the plugin's paper-and-teal look; only the
 * result widgets carry custom styles, keyed to the same design tokens.
 *
 * Talks to the core REST controller under agentimus/v1/visibility/*. API keys are
 * masked server-side and never round-trip to the browser.
 */
export default {
  name: 'VisibilityPanel',
  props: {
    api: { type: Object, required: true },
  },
  data() {
    return {
      view: 'results',
      keyMask: '__stored__', // matches Settings::KEY_MASK — a stored key shows as this.
      loaded: false,
      error: '',
      busy: false,
      notice: null,
      lastRunAt: '',
      isSample: false,
      dashboard: null,
      providersMeta: {},
      tests: {},
      form: {
        brand: '',
        domain: '',
        competitorsText: '',
        promptsText: '',
        frequency: 'weekly',
        retentionDays: 180,
        providers: {},
      },
    };
  },
  computed: {
    caption() {
      return this.view === 'results'
        ? 'Whether AI assistants mention and cite your site — from your latest run.'
        : 'Add your brand, the prompts to track, and an API key for each engine you want to check.';
    },
    providerIds() {
      return Object.keys(this.providersMeta);
    },
    summary() {
      return (this.dashboard && this.dashboard.summary) || { visibilityScore: 0, citationRate: 0, mentions: 0, checks: 0, errors: 0 };
    },
    hasData() {
      return !!(this.dashboard && this.dashboard.hasData);
    },
    // A plain-English sentence version of the headline numbers, for non-technical
    // readers who shouldn't have to decode "citation rate" to know how they did.
    runHeadline() {
      if (!this.hasData) return '';
      const s = this.summary;
      const brand = this.form.brand || 'your site';
      // Every check failed (e.g. bad key / rate limit) — no answers to summarize.
      if (s.checks === 0) {
        return `None of the ${s.errors} check${s.errors === 1 ? '' : 's'} completed this run — see the errors below.`;
      }
      const base = `AI assistants named ${brand} in ${s.mentions} of ${s.checks} answer${s.checks === 1 ? '' : 's'}`;
      let msg = s.citations
        ? `${base}, and linked your site in ${s.citations} of them.`
        : `${base}, but didn’t link to your site in any.`;
      if (s.errors > 0) {
        msg += ` (${s.errors} check${s.errors === 1 ? '' : 's'} didn’t finish — see below.)`;
      }
      return msg;
    },
    trendPoints() {
      const t = (this.dashboard && this.dashboard.trend) || [];
      if (t.length < 2) return '';
      const w = 460, h = 96, pad = 8, step = (w - pad * 2) / (t.length - 1);
      return t.map((p, i) => `${(pad + i * step).toFixed(1)},${(h - pad - (p.score / 100) * (h - pad * 2)).toFixed(1)}`).join(' ');
    },
    trend() {
      return (this.dashboard && this.dashboard.trend) || [];
    },
    voice() {
      return ((this.dashboard && this.dashboard.shareOfVoice) || []).slice().sort((a, b) => b.mentions - a.mentions);
    },
    prompts() {
      return (this.dashboard && this.dashboard.prompts) || [];
    },
  },
  watch: {
    // A notice belongs to the action that raised it (a run on Results, a save on
    // Settings) — don't let it linger on the other sub-view.
    view() {
      this.notify(null);
    },
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      try {
        const [c, d] = await Promise.all([this.api.getVisibilityConfig(), this.api.getVisibilityDashboard()]);
        this.applyConfig(c.config);
        this.lastRunAt = c.lastRunAt || '';
        this.dashboard = d.dashboard;
        this.isSample = !!d.isSample;
        this.loaded = true;
      } catch (e) {
        this.error = e.message;
      }
    },
    applyConfig(cfg) {
      if (!cfg) return;
      this.form.brand = cfg.brand || '';
      this.form.domain = cfg.domain || '';
      this.form.competitorsText = (cfg.competitors || []).join('\n');
      this.form.promptsText = (cfg.prompts || []).join('\n');
      this.form.frequency = cfg.frequency || 'weekly';
      this.form.retentionDays = cfg.retentionDays || 180;
      this.providersMeta = cfg.providers || {};
      const p = {};
      Object.keys(this.providersMeta).forEach((id) => {
        const x = this.providersMeta[id];
        // x.key is the masked placeholder (KEY_MASK) when a key is stored, '' when
        // not — so a saved key shows as dots and survives a save (blank/mask = keep).
        p[id] = { enabled: !!x.enabled, model: x.model || '', key: x.key || '', web_search: !!x.webSearch };
      });
      this.form.providers = p;
    },
    lines(v) {
      return (v || '').split('\n').map((s) => s.trim()).filter(Boolean);
    },
    // Clear the masked placeholder when the user focuses the key field, so typing
    // a new key replaces it cleanly. If they leave it blank, the stored key is kept.
    onKeyFocus(id) {
      if (this.form.providers[id] && this.form.providers[id].key === this.keyMask) {
        this.form.providers[id].key = '';
      }
    },
    fmtDate(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      return isNaN(d.getTime()) ? '—' : d.toLocaleString();
    },
    scoreTone(n) {
      return n >= 60 ? 'good' : n >= 25 ? 'warn' : 'bad';
    },
    notify(type, text) {
      this.notice = text ? { type, text } : null;
    },
    async save() {
      this.busy = true;
      this.notify(null);
      const payload = {
        brand: this.form.brand,
        domain: this.form.domain,
        competitors: this.lines(this.form.competitorsText),
        prompts: this.lines(this.form.promptsText),
        frequency: this.form.frequency,
        retentionDays: parseInt(this.form.retentionDays, 10) || 180,
        providers: this.form.providers,
      };
      try {
        const r = await this.api.saveVisibilityConfig(payload);
        this.applyConfig(r.config);
        this.notify('success', 'Settings saved.');
      } catch (e) {
        this.notify('error', `Save failed: ${e.message}`);
      } finally {
        this.busy = false;
      }
    },
    async testKey(id) {
      this.tests = { ...this.tests, [id]: { state: 'testing' } };
      try {
        const r = await this.api.testVisibilityKey({ provider: id, key: this.form.providers[id].key, model: this.form.providers[id].model });
        this.tests = { ...this.tests, [id]: r.ok ? { state: 'ok' } : { state: 'bad', msg: r.error } };
      } catch (e) {
        this.tests = { ...this.tests, [id]: { state: 'bad', msg: e.message } };
      }
    },
    async run() {
      this.busy = true;
      this.notify(null);
      try {
        const r = await this.api.runVisibility();
        this.dashboard = r.dashboard;
        this.lastRunAt = r.lastRunAt || this.lastRunAt;
        this.isSample = !!r.isSample;
        if (r.config) this.applyConfig(r.config);
        if (r.run && !r.run.ran) {
          this.notify('warn', r.run.reason === 'no_prompts'
            ? 'Add at least one tracked prompt in Settings first.'
            : 'Enable at least one engine and add its API key in Settings first.');
        } else if (r.run) {
          this.notify('success', `Ran ${r.run.checks} check${r.run.checks === 1 ? '' : 's'}.`);
        }
      } catch (e) {
        this.notify('error', `Run failed: ${e.message}`);
      } finally {
        this.busy = false;
      }
    },
    async seedDemo() {
      this.busy = true;
      this.notify(null);
      try {
        const r = await this.api.seedVisibilityDemo();
        this.dashboard = r.dashboard;
        this.lastRunAt = r.lastRunAt || this.lastRunAt;
        this.isSample = !!r.isSample;
        this.notify('success', 'Sample data loaded.');
      } catch (e) {
        this.notify('error', `Could not load sample: ${e.message}`);
      } finally {
        this.busy = false;
      }
    },
    async clearData() {
      this.busy = true;
      try {
        const r = await this.api.clearVisibilityData();
        this.dashboard = r.dashboard;
        this.lastRunAt = r.lastRunAt || '';
        this.isSample = !!r.isSample;
        this.notify('success', 'Data cleared.');
      } catch (e) {
        this.notify('error', `Clear failed: ${e.message}`);
      } finally {
        this.busy = false;
      }
    },
    chipState(pr) {
      if (pr.error) return { cls: 'err', label: 'error' };
      if (pr.cited) return { cls: 'cited', label: 'cited' };
      if (pr.mentioned) return { cls: 'mention', label: 'mentioned' };
      return { cls: 'absent', label: 'absent' };
    },
    providerLabel(id) {
      return (this.providersMeta[id] && this.providersMeta[id].label) || id;
    },
  },
};
</script>

<template>
  <div class="agv">
    <div v-if="error" class="agv-note agv-note--bad">Could not load AI Visibility: {{ error }}</div>
    <p v-else-if="!loaded" class="agv-loading">Loading…</p>

    <div v-else class="ar-tabpanel">
      <nav class="ar-tabpanel__tabs" aria-label="AI Visibility views">
        <button type="button" class="ar-subnav__item" :class="{ 'is-active': view === 'results' }" @click="view = 'results'">Results</button>
        <button type="button" class="ar-subnav__item" :class="{ 'is-active': view === 'settings' }" @click="view = 'settings'">Settings</button>
      </nav>
      <p class="ar-tabpanel__caption">{{ caption }}</p>

      <div class="ar-tabpanel__body">
        <div v-if="notice" class="agv-note" :class="`agv-note--${notice.type}`">
          <span>{{ notice.text }}</span>
          <button type="button" class="agv-note__x" aria-label="Dismiss" @click="notify(null)">×</button>
        </div>
        <!-- RESULTS -------------------------------------------------------- -->
        <div v-show="view === 'results'" class="agv-results">
          <div class="agv-runbar">
            <span class="agv-runbar__meta">
              Last run · {{ fmtDate(lastRunAt) }}
              <span v-if="isSample" class="agv-sample" title="Preview data — clear before relying on it">sample</span>
            </span>
            <div class="agv-runbar__actions">
              <button type="button" class="ar-btn ar-btn--ghost agv-btn-sm" :disabled="busy" @click="seedDemo">Load sample</button>
              <button v-if="hasData" type="button" class="ar-btn ar-btn--ghost agv-btn-sm agv-btn-danger" :disabled="busy" @click="clearData">Clear</button>
              <button type="button" class="ar-btn agv-btn-sm" :disabled="busy" @click="run">{{ busy ? 'Running…' : 'Run check now' }}</button>
            </div>
          </div>

          <div v-if="!hasData" class="agv-empty">
            <h2>No results yet</h2>
            <p>Load sample data to preview, or add tracked prompts and an engine key in Settings and run your first real check.</p>
            <div class="agv-empty__actions">
              <button type="button" class="ar-btn" :disabled="busy" @click="seedDemo">Load sample data</button>
              <button type="button" class="ar-btn ar-btn--ghost" @click="view = 'settings'">Go to Settings</button>
            </div>
          </div>

          <template v-else>
            <p v-if="runHeadline" class="agv-headline">{{ runHeadline }}</p>
            <div class="agv-cards">
              <div class="agv-card" :data-tone="scoreTone(summary.visibilityScore)">
                <div class="agv-card__value">{{ summary.visibilityScore }}<small>%</small></div>
                <div class="agv-card__label">Visibility score</div>
                <div class="agv-card__sub">Checks where your site was mentioned</div>
              </div>
              <div class="agv-card" :data-tone="scoreTone(summary.citationRate)">
                <div class="agv-card__value">{{ summary.citationRate }}<small>%</small></div>
                <div class="agv-card__label">Citation rate</div>
                <div class="agv-card__sub">Checks that linked your domain</div>
              </div>
              <div class="agv-card">
                <div class="agv-card__value">{{ summary.mentions }}<small>/{{ summary.checks }}</small></div>
                <div class="agv-card__label">Mentions</div>
                <div class="agv-card__sub">Answers naming you, across every engine</div>
              </div>
              <div class="agv-card" :data-tone="summary.errors ? 'warn' : ''">
                <div class="agv-card__value">{{ summary.errors }}</div>
                <div class="agv-card__label">Errors</div>
                <div class="agv-card__sub">Checks that didn’t finish</div>
              </div>
            </div>

            <div class="agv-grid2">
              <div class="agv-panel">
                <h3 class="agv-panel__title">Visibility trend</h3>
                <template v-if="trend.length >= 2">
                  <svg class="agv-spark" viewBox="0 0 460 96" preserveAspectRatio="none">
                    <polyline :points="trendPoints" fill="none" stroke="var(--ar-accent)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                  </svg>
                  <div class="agv-spark__labels">
                    <span>{{ fmtDate(trend[0].at) }}</span>
                    <span>{{ fmtDate(trend[trend.length - 1].at) }}</span>
                  </div>
                </template>
                <p v-else class="agv-muted">Two or more runs will draw a trend here.</p>
              </div>

              <div class="agv-panel">
                <h3 class="agv-panel__title">Share of voice</h3>
                <p v-if="!voice.length" class="agv-muted">Add competitors in Settings to compare.</p>
                <div v-for="v in voice" :key="v.name" class="agv-bar" :class="{ 'is-brand': v.isBrand }">
                  <div class="agv-bar__name">{{ v.name }}</div>
                  <div class="agv-bar__track"><div class="agv-bar__fill" :style="{ width: v.share + '%' }"></div></div>
                  <div class="agv-bar__val">{{ v.share }}%</div>
                </div>
              </div>
            </div>

            <div class="agv-panel">
              <h3 class="agv-panel__title">By prompt</h3>
              <div class="agv-legend">
                <span class="agv-chip" data-state="cited">cited</span><small>linked you</small>
                <span class="agv-chip" data-state="mention">mentioned</span><small>named you</small>
                <span class="agv-chip" data-state="absent">absent</span><small>didn’t mention you</small>
              </div>
              <div v-for="(p, i) in prompts" :key="i" class="agv-prompt">
                <div class="agv-prompt__q">{{ p.prompt }}</div>
                <div class="agv-prompt__providers">
                  <span
                    v-for="(pr, j) in p.providers"
                    :key="j"
                    class="agv-chip"
                    :data-state="chipState(pr).cls"
                    :title="pr.error || pr.excerpt || ''"
                  >{{ providerLabel(pr.provider) }} · {{ chipState(pr).label }}</span>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- SETTINGS ------------------------------------------------------- -->
        <form v-show="view === 'settings'" class="agv-form" @submit.prevent="save">
          <section class="ar-card">
            <h2 class="ar-card__title">Identity &amp; targets</h2>
            <p class="ar-card__lead">Who to track and what to compare against.</p>
            <div class="ar-grid">
              <div class="ar-field">
                <label>Brand / name</label>
                <input type="text" class="ar-input" v-model="form.brand" />
                <small class="ar-field__hint">Pre-filled from your Identity.</small>
              </div>
              <div class="ar-field">
                <label>Domain</label>
                <input type="text" class="ar-input" v-model="form.domain" placeholder="example.com" />
                <small class="ar-field__hint">Used to detect citations.</small>
              </div>
            </div>
            <div class="ar-grid">
              <div class="ar-field">
                <label>Competitors (one per line)</label>
                <textarea class="ar-input" v-model="form.competitorsText" rows="3"></textarea>
              </div>
              <div class="ar-field">
                <label>Tracked prompts (one per line)</label>
                <textarea class="ar-input" v-model="form.promptsText" rows="3"></textarea>
              </div>
            </div>
          </section>

          <section class="ar-card">
            <h2 class="ar-card__title">AI engines</h2>
            <p class="ar-card__lead">Bring your own API key. Keys stay on your server and are only used to run checks. Perplexity answers from live web results with citations; the others reflect what the model already knows.</p>
            <div class="agv-engines">
              <div class="agv-engine agv-engine--head">
                <span>Engine</span><span>API key</span><span>Model</span><span></span><span></span>
              </div>
              <div v-for="id in providerIds" :key="id" class="agv-engine">
                <div class="agv-engine__id">
                  <label class="ar-toggle agv-sw">
                    <input type="checkbox" v-model="form.providers[id].enabled" />
                    <span class="ar-toggle__track" aria-hidden="true"></span>
                  </label>
                  <span class="agv-engine__name">
                    {{ providersMeta[id].label }}
                    <span v-if="providersMeta[id].grounded" class="agv-engine__tag">live web</span>
                    <label v-else-if="providersMeta[id].webSearchCapable" class="agv-wspill"
                      :class="{ 'is-on': form.providers[id].web_search, 'is-off': !form.providers[id].enabled }"
                      :title="!form.providers[id].enabled ? 'Enable this engine first' : (id === 'openai' ? 'Answer from a live web search (needs a search-capable model, e.g. gpt-4.1)' : 'Ground answers on live Google Search')">
                      <input type="checkbox" v-model="form.providers[id].web_search" :disabled="!form.providers[id].enabled" />
                      web search
                    </label>
                  </span>
                </div>
                <input type="password" class="ar-input agv-engine__key" v-model="form.providers[id].key" autocomplete="off"
                  @focus="onKeyFocus(id)"
                  :placeholder="providersMeta[id].hasKey ? 'Key saved — leave blank to keep' : providersMeta[id].keyHint" />
                <input type="text" class="ar-input agv-engine__model" v-model="form.providers[id].model" spellcheck="false" />
                <button type="button" class="ar-btn ar-btn--ghost agv-btn-sm" @click="testKey(id)">Test</button>
                <span class="agv-engine__status" :data-state="tests[id] ? tests[id].state : ''"
                  :title="tests[id] && tests[id].state === 'bad' ? tests[id].msg : ''">
                  <template v-if="tests[id] && tests[id].state === 'testing'">Testing…</template>
                  <template v-else-if="tests[id] && tests[id].state === 'ok'">✓ Working</template>
                  <template v-else-if="tests[id] && tests[id].state === 'bad'">✗ {{ tests[id].msg || 'Failed' }}</template>
                  <span v-else-if="providersMeta[id].hasKey" class="agv-engine__saved">✓ saved</span>
                  <a v-else-if="providersMeta[id].helpUrl" :href="providersMeta[id].helpUrl" target="_blank" rel="noopener" class="agv-engine__help">Get a key ↗</a>
                </span>
              </div>
            </div>
          </section>

          <section class="ar-card">
            <h2 class="ar-card__title">Schedule</h2>
            <div class="ar-grid">
              <div class="ar-field">
                <label>Run checks</label>
                <select class="ar-input" v-model="form.frequency">
                  <option value="manual">Manual only</option>
                  <option value="daily">Daily</option>
                  <option value="weekly">Weekly</option>
                </select>
              </div>
              <div class="ar-field">
                <label>Keep history (days)</label>
                <input type="number" class="ar-input" v-model="form.retentionDays" min="7" max="730" />
              </div>
            </div>
            <div class="agv-save">
              <button type="submit" class="ar-btn" :disabled="busy">{{ busy ? 'Saving…' : 'Save settings' }}</button>
            </div>
          </section>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.agv-loading { color: var(--ar-ink-soft); padding: 22px 26px; }

/* Notices */
.agv-note { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 16px 26px 0; padding: 10px 14px; font-size: 13px; background: var(--ar-surface); border: 1px solid var(--ar-line); border-left: 3px solid var(--ar-ink-faint); border-radius: var(--ar-radius); }
.agv-note--success { border-left-color: var(--ar-good); }
.agv-note--warn { border-left-color: var(--ar-warn); }
.agv-note--bad, .agv-note--error { border-left-color: var(--ar-bad); }
.agv-note__x { background: none; border: 0; font-size: 17px; line-height: 1; cursor: pointer; color: var(--ar-ink-faint); }

.agv-results { padding: 22px 26px; }
.agv-muted { color: var(--ar-ink-soft); font-size: 13px; }
.agv-headline { font-family: var(--ar-serif); font-size: 16.5px; line-height: 1.5; color: var(--ar-ink); margin: 0 0 20px; max-width: 70ch; }
.agv-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin: -4px 0 16px; font-size: 12px; color: var(--ar-ink-soft); }
.agv-legend small { margin-right: 10px; }
.agv-engine__saved { color: var(--ar-good); font-weight: 600; }

/* Small button size + danger tint, layered on .ar-btn */
.agv-btn-sm { padding: 8px 16px; font-size: 11px; }
.agv-btn-danger { color: var(--ar-bad); border-color: var(--ar-line-strong); }

/* Run bar */
.agv-runbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.agv-runbar__meta { color: var(--ar-ink-soft); font-family: var(--ar-mono); font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; }
.agv-sample { margin-left: 8px; font-size: 9px; letter-spacing: 0.06em; color: var(--ar-warn); background: color-mix(in srgb, var(--ar-warn) 10%, var(--ar-surface)); border: 1px solid color-mix(in srgb, var(--ar-warn) 30%, var(--ar-line)); padding: 2px 6px; border-radius: 10px; }
.agv-runbar__actions { display: flex; align-items: center; gap: 8px; }

/* Score tiles */
.agv-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
@media (max-width: 900px) { .agv-cards { grid-template-columns: repeat(2, 1fr); } }
.agv-card { background: var(--ar-surface); border: 1px solid var(--ar-line); border-radius: var(--ar-radius); padding: 16px 18px; }
.agv-card__value { font-family: var(--ar-serif); font-size: 34px; line-height: 1; color: var(--ar-ink); }
.agv-card__value small { font-size: 16px; color: var(--ar-ink-faint); font-family: var(--ar-sans); }
.agv-card[data-tone="good"] .agv-card__value { color: var(--ar-good); }
.agv-card[data-tone="warn"] .agv-card__value { color: var(--ar-warn); }
.agv-card[data-tone="bad"] .agv-card__value { color: var(--ar-bad); }
.agv-card__label { margin-top: 8px; font-family: var(--ar-mono); font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ar-ink-soft); }
.agv-card__sub { margin-top: 3px; font-size: 12px; color: var(--ar-ink-faint); }

/* Panels */
.agv-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
@media (max-width: 900px) { .agv-grid2 { grid-template-columns: 1fr; } }
.agv-panel { background: var(--ar-surface); border: 1px solid var(--ar-line); border-radius: var(--ar-radius); padding: 16px 18px; margin-bottom: 16px; }
.agv-grid2 .agv-panel { margin-bottom: 0; }
.agv-panel__title { margin: 0 0 14px; font-family: var(--ar-serif); font-size: 15px; color: var(--ar-ink); }

.agv-spark { width: 100%; height: 84px; display: block; }
.agv-spark__labels { display: flex; justify-content: space-between; color: var(--ar-ink-faint); font-size: 11px; margin-top: 6px; }

/* Share of voice */
.agv-bar { display: flex; align-items: center; gap: 10px; margin: 9px 0; }
.agv-bar__name { width: 130px; font-size: 13px; color: var(--ar-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.agv-bar.is-brand .agv-bar__name { font-weight: 600; }
.agv-bar__track { flex: 1; background: var(--ar-surface-2); border: 1px solid var(--ar-line); border-radius: var(--ar-radius); height: 12px; overflow: hidden; }
.agv-bar__fill { height: 100%; background: var(--ar-line-strong); }
.agv-bar.is-brand .agv-bar__fill { background: var(--ar-accent); }
.agv-bar__val { width: 40px; text-align: right; font-size: 12px; color: var(--ar-ink-soft); font-variant-numeric: tabular-nums; }

/* By prompt */
.agv-prompt { padding: 12px 0; border-top: 1px solid var(--ar-line); }
.agv-prompt:first-of-type { border-top: 0; padding-top: 0; }
.agv-prompt__q { font-size: 13.5px; font-weight: 600; color: var(--ar-ink); margin-bottom: 8px; }
.agv-prompt__providers { display: flex; flex-wrap: wrap; gap: 6px; }
.agv-chip { font-family: var(--ar-mono); font-size: 10.5px; letter-spacing: 0.03em; padding: 3px 9px; border-radius: 20px; border: 1px solid var(--ar-line); background: var(--ar-surface-2); color: var(--ar-ink-soft); }
.agv-chip[data-state="cited"] { color: var(--ar-good); border-color: color-mix(in srgb, var(--ar-good) 35%, var(--ar-line)); }
.agv-chip[data-state="mention"] { color: var(--ar-accent); border-color: color-mix(in srgb, var(--ar-accent) 35%, var(--ar-line)); }
.agv-chip[data-state="err"] { color: var(--ar-bad); border-color: color-mix(in srgb, var(--ar-bad) 35%, var(--ar-line)); }

/* Empty state */
.agv-empty { text-align: center; background: var(--ar-surface); border: 1px dashed var(--ar-line-strong); border-radius: var(--ar-radius); padding: 40px 20px; }
.agv-empty h2 { margin: 0 0 6px; font-family: var(--ar-serif); }
.agv-empty p { color: var(--ar-ink-soft); max-width: 460px; margin: 0 auto 16px; font-size: 13.5px; }
.agv-empty__actions { display: flex; gap: 10px; justify-content: center; }

/* Settings — compact engine rows. A single fixed grid template shared by every
 * row (and the header) so all four line up exactly, regardless of the tag. */
.agv-engines { margin-top: 6px; }
.agv-engine {
  display: grid;
  grid-template-columns: 290px minmax(0, 1fr) 150px auto 112px;
  align-items: center; gap: 12px;
  padding: 13px 0; border-top: 1px solid var(--ar-line);
}
.agv-engine--head {
  padding: 0 0 8px; border-top: 0;
  font-family: var(--ar-mono); font-size: 10px; letter-spacing: 0.08em;
  text-transform: uppercase; color: var(--ar-ink-faint);
}
.agv-engine--head + .agv-engine { border-top: 0; }
.agv-engine__id { display: flex; align-items: center; gap: 12px; min-width: 0; }
/* Reuse the plugin's toggle switch, stripped of its list-row chrome. */
.agv-sw.ar-toggle { padding: 0; border-top: 0; align-items: center; gap: 0; flex: 0 0 auto; }
.agv-engine__name { display: inline-flex; align-items: center; gap: 9px; flex-wrap: wrap; font-size: 13.5px; font-weight: 600; color: var(--ar-ink); min-width: 0; }
/* Toggleable "web search" pill on the same line — same family as the static
 * LIVE WEB tag, with an on/off dot. Dimmed + disabled until the engine is on. */
.agv-wspill { display: inline-flex; align-items: center; gap: 6px; font-family: var(--ar-mono); font-size: 9px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-ink-faint); background: var(--ar-surface-2); border: 1px solid var(--ar-line-strong); padding: 3px 8px; border-radius: 999px; cursor: pointer; white-space: nowrap; }
.agv-wspill input { position: absolute; opacity: 0; width: 0; height: 0; }
.agv-wspill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--ar-line-strong); transition: background 0.15s; }
.agv-wspill.is-on { color: var(--ar-accent); background: color-mix(in srgb, var(--ar-accent) 10%, var(--ar-surface)); border-color: color-mix(in srgb, var(--ar-accent) 30%, var(--ar-line)); }
.agv-wspill.is-on::before { background: var(--ar-accent); }
.agv-wspill.is-off { opacity: 0.4; cursor: not-allowed; }
.agv-engine__tag { font-family: var(--ar-mono); font-size: 9px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-accent); background: color-mix(in srgb, var(--ar-accent) 10%, var(--ar-surface)); border: 1px solid color-mix(in srgb, var(--ar-accent) 25%, var(--ar-line)); padding: 2px 6px; border-radius: 10px; }
.agv-engine .ar-input { padding: 7px 10px; font-size: 13px; }
.agv-engine__model { font-family: var(--ar-mono); font-size: 12px; }
.agv-engine__status { font-size: 12px; color: var(--ar-ink-faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.agv-engine__status[data-state="ok"] { color: var(--ar-good); font-weight: 600; }
.agv-engine__status[data-state="bad"] { color: var(--ar-bad); }
.agv-engine__help { color: var(--ar-accent); text-decoration: none; }
@media (max-width: 1024px) {
  .agv-engine--head { display: none; }
  .agv-engine { grid-template-columns: 1fr; gap: 8px; padding: 14px 0; }
  .agv-engine__status { white-space: normal; }
}

.agv-save { margin-top: 22px; text-align: right; }
</style>
