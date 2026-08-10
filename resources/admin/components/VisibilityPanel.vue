<script>
/**
 * Visibility (More → Visibility) — the Citations tenant tracks whether AI assistants (ChatGPT, Perplexity, Gemini,
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
import TagInput from './TagInput.vue';
import SelectMenu from './SelectMenu.vue';
import RefreshCrank from './RefreshCrank.vue';
import CardSkeleton from './CardSkeleton.vue';
import { confirm } from '../js/confirm.js';
import { bindDocEsc } from '../js/docEsc.js';
import { groupIcon } from '../js/groupIcons.js';
import { formatStamp } from '../js/wpDate.js';

export default {
  name: 'VisibilityPanel',
  components: { TagInput, SelectMenu, RefreshCrank, CardSkeleton },
  props: {
    api: { type: Object, required: true },
    // Whether the citation-checks FEATURE is on. The screen itself is always
    // on; this only gates the checks tenant (its views, never the room).
    checksOn: { type: Boolean, default: true },
    // Whether the Visibility screen is the tab on view. The panel stays
    // MOUNTED across tab switches (App uses v-show), so results fetched once
    // go stale — coming back must quietly re-read them, the same contract
    // every other data screen keeps.
    active: { type: Boolean, default: true },
  },
  emits: ['flash', 'measured', 'view-change', 'navigate', 'enable-checks'],
  data() {
    return {
      // The landing view matches the leading tab — Performance, the "how did
      // search go?" answer most visits come for. The room's three views group
      // by QUESTION: how you performed (people) · whether you're in the
      // indexes (machines) · whether AI answers cite you.
      view: 'performance',
      // Inside Citations: its own workbench pair (Results / Settings).
      citView: 'results',
      keyMask: '__stored__', // matches Settings::KEY_MASK — a stored key shows as this.
      loaded: false,
      error: '',
      busy: false,
      quietBusy: false, // background results re-read in flight (spins the runbar's refresh)
      // Which sections just saved ('targets' | 'engines' | 'schedule'), so the "Saved ✓"
      // pulse appears next to the heading you actually changed. A single pill on one
      // heading is useless: flip an engine switch and the confirmation flashes off-screen.
      savedSections: [],
      cardStatus: {},   // per-card index → 'saving' | 'saved', for inline feedback.
      lastRunAt: '',
      pollTimer: null, // setTimeout id while polling a background run.
      dashboard: null,
      providersMeta: {},
      tests: {},
      sugg: {}, // Per-product prompt suggestions, keyed by card index: { loading, aiLoading, items }.
      aiAvailable: false, // Site has an AI provider configured (WP 7.0 connectors) — gates "Suggest with AI".
      errorDialog: null, // { id, label, msg, helpUrl } when a Test failure dialog is open.
      form: {
        // One card per product. Competitors and questions are chip lists (arrays).
        targets: [{ name: '', category: '', domain: '', active: true, competitors: [], prompts: [] }],
        scheduleActive: true, // master switch for automatic checks.
        frequency: 'weekly',
        retentionDays: 180,
        providers: {},
      },
    };
  },
  computed: {
    caption() {
      if (this.view === 'performance') {
        return 'How your pages did in the results — people, counted by the engines themselves.';
      }
      if (this.view === 'aisearch') {
        return "Are your pages in the engines' indexes — Google's, and Bing's, the one ChatGPT search and Copilot read today. Machines counted here, not people.";
      }
      // The checks tenant is off: don't promise "your latest run" above a card
      // that explains there are no runs.
      if (!this.checksOn) {
        return 'Citation checks are switched off — the card below says what they would show.';
      }
      return this.citView === 'results'
        ? 'Whether AI assistants mention and link each thing you track — from your latest run.'
        : 'Add each thing you want to track, then an AI key for each engine that should check it.';
    },
    providerIds() {
      return Object.keys(this.providersMeta);
    },
    // True while the engine whose error dialog is open is being re-tested, so the
    // dialog's "Test again" / "Close" buttons can show progress and lock.
    errorTesting() {
      const id = this.errorDialog && this.errorDialog.id;
      return !!(id && this.tests[id] && this.tests[id].state === 'testing');
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
      const names = this.targetNames();
      // "something you track" is the umbrella wording — the tracked items can be a
      // person, brand or product, so never call them "products". A single item uses
      // its own name.
      const brand = names.length > 1 ? 'something you track' : (names[0] || 'your site');
      // Every check failed (e.g. bad key / rate limit) — no answers to summarize.
      if (s.checks === 0) {
        return `None of the ${s.errors} check${s.errors === 1 ? '' : 's'} completed this run — see the errors below.`;
      }
      const base = `AI named ${brand} in ${s.mentions} of ${s.checks} answer${s.checks === 1 ? '' : 's'}`;
      let msg = s.citations
        ? `${base}, and linked your site in ${s.citations} of them.`
        : `${base}, but never linked your site.`;
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
    // One results section per tracked product (name, score, rank, questions).
    products() {
      return (this.dashboard && this.dashboard.products) || [];
    },
  },
  created() {
    if (this.checksOn) {
      this.load();
    } else {
      // Nothing to fetch for a switched-off tenant: skip straight past the
      // skeleton — the landing view is already the tenant that IS alive.
      this.loaded = true;
    }
  },
  beforeUnmount() {
    this.clearPoll();
    this.flushTargets(); // best-effort: persist a pending chip edit before teardown.
    if (this._unEscError) this._unEscError();
  },
  watch: {
    // Document-level Esc while the test-failure dialog is open — the panel-scoped
    // handler dies as soon as focus leaves the panel (e.g. a backdrop click).
    errorDialog(open) {
      if (this._unEscError) this._unEscError();
      this._unEscError = open ? bindDocEsc(() => this.closeError()) : null;
    },
    // The parent seats screen-mates by sub-view (the index cards belong to
    // Results only), so the current view is announced upward.
    view: {
      handler(v) {
        this.$emit('view-change', v);
        // Switching TO Citations re-reads the results quietly — a run may
        // have finished while the reader was on another view.
        if ('citations' === v) this.quietReload();
      },
      immediate: true,
    },
    // Returning to the Visibility screen while Citations is the open view:
    // same quiet re-read, same reason.
    active(on) {
      if (on && 'citations' === this.view) this.quietReload();
    },
  },
  methods: {
    groupIcon,
    // Open a specific sub-view — used when the score card's Cited rung
    // deep-links here. 'results'/'settings' name the citations workbench pair,
    // so they open the Citations tab on that inner view. 'aisearch' keeps its
    // old id and now means the In-the-index view — every stored deep link
    // (Settings cards, the score) lands where its cards went.
    openView(view) {
      if ('performance' === view || 'aisearch' === view) {
        this.view = view;
      } else if ('results' === view || 'settings' === view) {
        this.view = 'citations';
        this.citView = view;
      }
    },
    // The in-tab switch: the citations tenant's own key, thrown from inside its
    // own room (the parent persists it through the normal settings autosave).
    // The quiet half of the freshness rule: re-read the RESULTS without
    // touching the form (applyConfig on reveal would clobber an in-progress
    // chip edit) and without the loading skeleton — yesterday's numbers hold
    // the screen while the fresh ones land. Skipped mid-run: the run's own
    // poll is already keeping the sheet current.
    async quietReload() {
      if (!this.checksOn || !this.loaded || this.busy || this._quietBusy) return;
      this._quietBusy = true;
      this.quietBusy = true;
      try {
        const d = await this.api.getVisibilityDashboard();
        this.dashboard = d.dashboard;
      } catch (e) {
        // Stale-but-shown beats an error toast on a background courtesy.
      } finally {
        this._quietBusy = false;
        this.quietBusy = false;
      }
    },
    // The off switch asks first: it stops scheduled runs and takes the Cited
    // rung out of the score — and the dialog states what is KEPT, so nobody
    // declines out of fear of losing their history.
    async confirmChecksOff() {
      const ok = await confirm({
        title: 'Turn off citation checks?',
        message: 'Scheduled runs stop and the “Cited” rung leaves your score. Your targets, keys and history are all kept — turn it back on any time and it picks up where it left off.',
        confirmLabel: 'Turn Off',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (ok) this.setChecks(false);
    },
    setChecks(on) {
      this.$emit('enable-checks', !!on);
      if (on) {
        // Land on SETUP, not Results: a first turn-on has no results to show,
        // and an empty sheet sends the reader hunting for the form this click
        // was already promising. (The "never measured" finding's deep link
        // lands on this same view; a returning owner re-enabling is one click
        // from their kept history.)
        this.citView = 'settings';
        this.loaded = false; // fetch the config the OFF state never loaded.
        this.load();
      }
    },
    async load() {
      try {
        const [c, d] = await Promise.all([this.api.getVisibilityConfig(), this.api.getVisibilityDashboard()]);
        this.applyConfig(c.config);
        this.aiAvailable = !!c.aiAvailable;
        this.lastRunAt = c.lastRunAt || '';
        this.dashboard = d.dashboard;
        this.loaded = true;
        // A background run may already be in flight (e.g. reloaded mid-run) — resume
        // the "Running…" state and pick up its results when it finishes.
        if (d.running) {
          this.busy = true;
          this.pollRun();
        }
      } catch (e) {
        this.error = e.message;
      }
    },
    applyConfig(cfg) {
      if (!cfg) return;
      const targets = (cfg.targets && cfg.targets.length) ? cfg.targets : [{}];
      // Keep at least one card; competitors/prompts are chip arrays.
      this.form.targets = targets.map((t) => ({
        name: t.name || '',
        category: t.category || '', // Rows saved before this field existed read as ''.
        domain: t.domain || '',
        active: t.active !== false, // default on.
        competitors: (t.competitors || []).slice(),
        prompts: (t.prompts || []).slice(),
      }));
      this.form.scheduleActive = cfg.scheduleActive !== false; // default on.
      this.form.frequency = cfg.frequency || 'weekly';
      this.form.retentionDays = cfg.retentionDays || 180;
      this.providersMeta = cfg.providers || {};
      const p = {};
      Object.keys(this.providersMeta).forEach((id) => {
        const x = this.providersMeta[id];
        // x.key is the masked placeholder (KEY_MASK) when a key is stored, '' when
        // not — so a saved key shows as dots and survives a save (blank/mask = keep).
        // `reveal` is false until the user clicks the eye (which fetches the real key).
        // `modelCustom` = the stored model isn't one of the listed options.
        const models = x.models || [];
        const model = x.model || '';
        p[id] = { enabled: !!x.enabled, model, modelCustom: !!model && !models.includes(model), key: x.key || '', web_search: !!x.webSearch, reveal: false };
      });
      this.form.providers = p;
    },
    // The tracked product names (drops nameless cards).
    targetNames() {
      const seen = [];
      (this.form.targets || []).forEach((t) => {
        const n = (t.name || '').trim();
        if (n && !seen.includes(n)) seen.push(n);
      });
      return seen;
    },
    addTarget() {
      this.form.targets.push({ name: '', category: '', domain: '', active: true, competitors: [], prompts: [] });
      // Focus the new card's name field so the user can keep typing.
      this.$nextTick(() => {
        const els = this.$refs.targetName;
        if (els && els.length) els[els.length - 1].focus();
      });
    },
    async removeTarget(i) {
      const t = this.form.targets[i] || {};
      const hasContent = (t.name || '').trim() || (t.competitors || []).length || (t.prompts || []).length;
      // Only confirm when there's something to lose; a blank card just goes.
      if (hasContent) {
        const ok = await confirm({
          title: `Remove ${(t.name || '').trim() || 'this product'}?`,
          message: 'Its name, website, rivals and questions will be deleted. Past results stay until you clear data.',
          confirmLabel: 'Remove',
          cancelLabel: 'Keep it',
          tone: 'danger',
        });
        if (!ok) return;
      }
      this.form.targets.splice(i, 1);
      if (!this.form.targets.length) {
        this.form.targets.push({ name: '', domain: '', active: true, competitors: [], prompts: [] });
      }
      this.autoSaveTargets(); // removing a product persists on its own.
    },
    // A plain-English one-liner for a product's results (from its summary + rank).
    productSummary(p) {
      const s = p.summary || {};
      const checks = s.checks || 0;
      if (!checks) return s.errors ? 'Every check failed this run — see the errors below.' : 'Not checked yet.';
      const answers = `Mentioned in ${s.mentions} of ${checks} answer${checks === 1 ? '' : 's'}`;
      if (!s.mentions) return `${answers} — no AI named it this run.`;
      const c = s.citations || 0;
      const link = c === 0 ? 'never linked its site' : c === 1 ? 'linked its site once' : `linked its site ${c} times`;
      const rank = p.rank ? `ranks #${p.rank} vs rivals` : 'not ranked against rivals';
      return `${answers} · ${link} · ${rank}`;
    },
    // Options for a provider's model dropdown: its known models + a "Custom…" entry.
    modelOptions(id) {
      const models = (this.providersMeta[id] && this.providersMeta[id].models) || [];
      return [...models.map((m) => ({ value: m, label: m })), { value: '__custom__', label: 'Custom…' }];
    },
    // Tooltip for an engine's optional "web search" toggle. Each engine grounds a
    // different way, so the hint is per-engine (and nudges you to enable it first).
    webSearchTitle(id) {
      if (!this.form.providers[id] || !this.form.providers[id].enabled) return 'Turn this engine on first';
      const hints = {
        openai: 'Answer using a live web search (needs a search-capable model, e.g. gpt-4.1)',
        gemini: 'Answer using a live Google Search',
        anthropic: 'Answer using Claude’s live web search',
      };
      return hints[id] || 'Answer using a live web search';
    },
    // Model picker: choosing a listed model sets it; choosing "Custom…" reveals a
    // text field (keeping the current value so it can be edited into a custom ID).
    onModelPick(id, value) {
      if (value === '__custom__') {
        // Only reveals the free-text box — nothing to persist until they type an ID.
        this.form.providers[id].modelCustom = true;
        return;
      }
      this.form.providers[id].model = value;
      this.form.providers[id].modelCustom = false;
      this.autoSaveProviders();
    },
    // A saved-but-untouched field holds the masked placeholder (shown as dots).
    // Select it on focus so the dots stay visible, but a keystroke replaces the
    // whole thing cleanly. Leave it untouched and the stored key is kept on save.
    onKeyFocus(id, e) {
      if (this.form.providers[id] && this.form.providers[id].key === this.keyMask && e && e.target) {
        e.target.select();
      }
    },
    // Eye toggle: reveal or hide the key. The first reveal of a saved key fetches
    // the real value from the server (it's never in the config payload).
    async toggleReveal(id) {
      const p = this.form.providers[id];
      if (!p) return;
      if (p.reveal) { p.reveal = false; return; }
      if (p.key === this.keyMask && this.providersMeta[id] && this.providersMeta[id].hasKey) {
        try {
          const r = await this.api.revealVisibilityKey({ provider: id });
          p.key = (r && typeof r.key === 'string') ? r.key : '';
        } catch (e) {
          this.notify('error', `Couldn’t reveal key: ${e.message}`);
          return;
        }
      }
      p.reveal = true;
    },
    fmtDate(iso) {
      if (!iso) return '—';
      const d = new Date(iso);
      return isNaN(d.getTime()) ? '—' : formatStamp(d);
    },
    scoreTone(n) {
      return n >= 60 ? 'good' : n >= 25 ? 'warn' : 'bad';
    },
    // Surface panel messages as app-wide toasts (top-right), like the rest of the
    // admin. `notify(null)` is a no-op — toasts dismiss on their own.
    notify(type, text) {
      if (text) this.$emit('flash', type === 'warn' ? 'warning' : type, text);
    },
    // The products payload (dropping nameless cards), shared by manual + auto save.
    buildTargets() {
      return this.form.targets
        .map((t) => ({
          name: (t.name || '').trim(),
          category: (t.category || '').trim(),
          domain: (t.domain || '').trim(),
          active: t.active !== false,
          competitors: (t.competitors || []).slice(),
          prompts: (t.prompts || []).slice(),
        }))
        .filter((t) => t.name);
    },
    // ---- Saving -----------------------------------------------------------
    // There is no Save button on this screen: every control commits itself the moment you
    // change it. Everything funnels through ONE debounced writer, and that is deliberate.
    // The server's update() is a read-modify-write of a single option — it loads the whole
    // record, overwrites the keys you sent, and writes it back — so two requests in flight
    // at once means the slower one writes a stale copy of whatever the other just changed.
    // (Toggle an engine, then click a suggested question within the same breath, and the
    // targets write would silently revert the engine.) One timer, one merged payload, one
    // request: the race cannot happen.
    //
    // `targets: true` is a marker, not data — the product list is rebuilt at flush time so
    // the newest keystroke always wins, however long the payload sat in the queue.
    queueSave(patch) {
      this._patch = { ...(this._patch || {}), ...patch };
      clearTimeout(this._saveTimer);
      this._saveTimer = setTimeout(() => { this.flushSave(); }, 500);
    },
    async flushSave() {
      clearTimeout(this._saveTimer);
      this._saveTimer = null;
      const patch = this._patch;
      this._patch = null;
      if (!patch) return;

      const cards = this._pending ? Array.from(this._pending) : [];
      this._pending = new Set();

      const payload = { ...patch };
      if (payload.targets === true) payload.targets = this.buildTargets();

      try {
        const r = await this.api.saveVisibilityConfig(payload);
        this.pulseSaved(this.sectionsIn(patch));
        if (cards.length) this.reflectSave(cards);
        this.syncSchedule(patch, r && r.config);
      } catch (e) {
        if (cards.length) this.reflectSave(cards, true);
        this.notify('error', `Couldn’t save: ${e.message}`);
      }
    },
    // The server clamps "keep history" to 7–730 days. Nothing re-hydrates the form after an
    // auto-save (that would steal focus mid-edit), so without this the field would go on
    // showing a number the server refused — type 3, it stores 7, you'd still read 3. Only
    // the schedule fields we just sent are synced back.
    syncSchedule(patch, config) {
      if (!config || !('retentionDays' in patch)) return;
      if (typeof config.retentionDays === 'number') this.form.retentionDays = config.retentionDays;
      if (typeof config.frequency === 'string') this.form.frequency = config.frequency;
      if (typeof config.scheduleActive === 'boolean') this.form.scheduleActive = config.scheduleActive;
    },
    autoSaveProviders() {
      this.queueSave({ providers: this.form.providers });
    },
    autoSaveSchedule() {
      this.queueSave({
        scheduleActive: this.form.scheduleActive,
        frequency: this.form.frequency,
        retentionDays: parseInt(this.form.retentionDays, 10) || 180,
      });
    },
    // Persist just the products (a partial save that leaves API keys / schedule
    // untouched), debounced so a burst of chip edits makes one request. No form
    // re-hydrate, so it never steals focus or resets a field you're editing.
    // The product's (possibly unsaved) fields, as both suggest routes want them.
    suggestPayload(i) {
      const t = this.form.targets[i];
      return {
        name: t.name,
        category: t.category,
        domain: t.domain,
        competitors: t.competitors,
        prompts: t.prompts,
      };
    },
    // Either suggest button in flight — they share one chip list, so only one runs at a time.
    suggBusy(i) {
      return !!(this.sugg[i] && (this.sugg[i].loading || this.sugg[i].aiLoading));
    },
    // Fetch candidate questions for a product from its (unsaved) fields. Template-built:
    // instant, free, no network beyond our own REST. Suggestions are per-card, ephemeral,
    // and offered — never auto-added.
    async suggestQuestions(i) {
      const prev = (this.sugg[i] && this.sugg[i].items) || [];
      this.sugg[i] = { loading: true, items: prev };
      try {
        const r = await this.api.suggestVisibility(this.suggestPayload(i));
        this.sugg[i] = { loading: false, items: (r && r.questions) || [] };
      } catch (e) {
        this.sugg[i] = { loading: false, items: [] };
        this.notify('warn', 'Couldn’t fetch suggestions just now.');
      }
    },
    // Same, but asks the site's own configured AI for unbranded audience-intent questions.
    // Costs a call, so it's a deliberate click; any failure falls back to the templates
    // rather than leaving the owner with nothing.
    async suggestQuestionsAi(i) {
      const prev = (this.sugg[i] && this.sugg[i].items) || [];
      this.sugg[i] = { aiLoading: true, items: prev };
      try {
        const r = await this.api.suggestVisibilityAi(this.suggestPayload(i));
        this.sugg[i] = { aiLoading: false, items: (r && r.questions) || [] };
      } catch (e) {
        this.sugg[i] = { aiLoading: false, items: prev };
        this.notify('warn', (e && e.message) || 'Your AI couldn’t write questions just now.');
        await this.suggestQuestions(i);
      }
    },
    // Add one suggested question to the product's tracked list (deduped, capped) and
    // drop it from the offered chips. The server clamps the cap authoritatively on save.
    addSuggestion(i, q) {
      const t = this.form.targets[i];
      const has = (t.prompts || []).some((p) => String(p).trim().toLowerCase() === q.trim().toLowerCase());
      if (!has) {
        if ((t.prompts || []).length >= 25) {
          this.notify('warn', 'You can track up to 25 questions per product.');
          return;
        }
        t.prompts.push(q);
        this.autoSaveTargets(i);
      }
      if (this.sugg[i]) {
        this.sugg[i] = { loading: false, items: this.sugg[i].items.filter((x) => x !== q) };
      }
    },
    // The products (a chip, a name, the Active switch) ride the same single writer as the
    // engines and the schedule — see queueSave(). No form re-hydrate on the way back, so a
    // save never steals focus or resets a field you're still typing in.
    autoSaveTargets(i) {
      // Mark the card being edited as "Saving…" right away for instant feedback.
      if (typeof i === 'number') {
        this._pending = this._pending || new Set();
        this._pending.add(i);
        this.cardStatus = { ...this.cardStatus, [i]: 'saving' };
      }
      this.queueSave({ targets: true });
    },
    // Immediately persist any pending (debounced) save — products, engines or schedule.
    // Called before anything that reads or re-hydrates settings (a run, leaving the panel)
    // so an in-flight edit is never lost or overwritten by stale config.
    async flushTargets() {
      await this.flushSave();
    },
    // Whether a card has anything worth keeping besides its (missing) name.
    hasContent(t) {
      return !!((t.domain || '').trim() || (t.competitors || []).length || (t.prompts || []).length);
    },
    // What's still needed before this card both saves and gets checked. Empty when
    // it's complete (a name + at least one question) — the alert then disappears.
    cardRequirement(t) {
      const noName = !(t.name || '').trim();
      const noQuestion = !t.prompts.length;
      if (noName && noQuestion) return 'A name and at least one question are required — a name to save it, a question to check it.';
      if (noName) return 'Add a name — without one this won’t be saved.';
      return 'Add at least one question, or this won’t be checked.';
    },
    // Tell each edited card the truth: a named card is "Saved ✓" (auto-clears); a
    // card with content but no name is "Not saved" and stays flagged; an empty card
    // clears silently. Never claims success for something that wasn't persisted.
    reflectSave(indexes, failed = false) {
      const next = { ...this.cardStatus };
      const saved = [];
      indexes.forEach((k) => {
        const t = this.form.targets[k];
        if (!t) { delete next[k]; return; }
        // A failed save must not look like nothing happened — keep it flagged
        // "Not saved" so the edit isn't silently lost.
        if (failed) { next[k] = 'unsaved'; return; }
        if ((t.name || '').trim()) { next[k] = 'saved'; saved.push(k); }
        else if (this.hasContent(t)) { next[k] = 'unsaved'; }
        else { delete next[k]; }
      });
      this.cardStatus = next;
      if (saved.length) {
        clearTimeout(this._cardTimer);
        this._cardTimer = setTimeout(() => {
          const cleared = { ...this.cardStatus };
          saved.forEach((k) => { if (cleared[k] === 'saved') delete cleared[k]; });
          this.cardStatus = cleared;
        }, 1800);
      }
    },
    // Which sections a saved payload touched, so the confirmation lands where the user
    // was actually looking. One merged request can carry more than one.
    sectionsIn(patch) {
      const out = [];
      if ('targets' in patch) out.push('targets');
      if ('providers' in patch) out.push('engines');
      if ('scheduleActive' in patch || 'frequency' in patch || 'retentionDays' in patch) out.push('schedule');
      return out;
    },
    savedIn(section) {
      return this.savedSections.includes(section);
    },
    pulseSaved(sections) {
      this.savedSections = Array.isArray(sections) && sections.length ? sections : ['targets'];
      clearTimeout(this._pulseTimer);
      this._pulseTimer = setTimeout(() => { this.savedSections = []; }, 1800);
    },
    async testKey(id) {
      this.tests = { ...this.tests, [id]: { state: 'testing' } };
      try {
        const r = await this.api.testVisibilityKey({ provider: id, key: this.form.providers[id].key, model: this.form.providers[id].model });
        this.tests = { ...this.tests, [id]: r.ok ? { state: 'ok' } : { state: 'bad', msg: r.error } };
      } catch (e) {
        this.tests = { ...this.tests, [id]: { state: 'bad', msg: e.message } };
      }
      // Surface a failure in a readable dialog rather than a truncated hover tooltip.
      // A re-test from inside the dialog reuses this: refresh it with the newest
      // message on another failure, or dismiss it once the key finally works.
      if (this.tests[id] && this.tests[id].state === 'bad') {
        this.openError(id);
      } else if (this.errorDialog && this.errorDialog.id === id) {
        this.closeError();
      }
    },
    // Clear a provider's test result, reverting its inline status to the default
    // ("Get a key" / "✓ saved") and closing the error dialog if it was showing it.
    dismissTest(id) {
      const next = { ...this.tests };
      delete next[id];
      this.tests = next;
      if (this.errorDialog && this.errorDialog.id === id) this.closeError();
    },
    // Open the failure dialog for a provider (from a failed test, or a re-click on
    // its inline status). Focus the panel so Esc closes it and it reads as modal.
    openError(id) {
      const t = this.tests[id];
      if (!t || t.state !== 'bad') return;
      const meta = this.providersMeta[id] || {};
      this.errorDialog = {
        id,
        label: this.providerLabel(id),
        msg: t.msg || 'The test failed.',
        helpUrl: meta.helpUrl || '',
      };
      this.$nextTick(() => { if (this.$refs.errDialog) this.$refs.errDialog.focus(); });
    },
    closeError() {
      this.errorDialog = null;
    },
    // Split an error message into plain-text and URL segments so links render as
    // real, clickable anchors. Tokenizing (rather than v-html) keeps it XSS-safe.
    errorSegments(msg) {
      const text = String(msg == null ? '' : msg) || 'The test failed.';
      const parts = [];
      const re = /(https?:\/\/[^\s<>"')\]]+)/g;
      let last = 0;
      let m;
      while ((m = re.exec(text)) !== null) {
        if (m.index > last) parts.push({ text: text.slice(last, m.index) });
        let url = m[0];
        let trail = '';
        // Don't fold trailing sentence punctuation into the link.
        const tm = url.match(/[.,;:!?]+$/);
        if (tm) { trail = tm[0]; url = url.slice(0, -trail.length); }
        parts.push({ text: url, href: url });
        if (trail) parts.push({ text: trail });
        last = m.index + m[0].length;
      }
      if (last < text.length) parts.push({ text: text.slice(last) });
      return parts.length ? parts : [{ text }];
    },
    // A run happens in the background (checks are slow — more so with web search on —
    // and would otherwise time out the request). Kick it off, then poll the dashboard
    // until the server clears its "running" flag. `busy` keeps the buttons showing
    // "Running…" for the whole pass.
    async run() {
      this.busy = true;
      try {
        // Persist any in-flight chip edit first, so the run checks the current
        // questions and the config it echoes back doesn't overwrite the edit.
        await this.flushTargets();
        const r = await this.api.runVisibility();
        if (r.dashboard) this.dashboard = r.dashboard;
        if (r.config) this.applyConfig(r.config);

        if (r.run && r.run.ran === false) {
          // Nothing to run yet — reported immediately, no background pass queued.
          this.lastRunAt = r.lastRunAt || this.lastRunAt;
          this.busy = false;
          this.notify('warn', r.run.reason === 'no_prompts'
            ? 'Add at least one question in Settings first.'
            : 'Enable at least one engine and add its API key in Settings first.');
          return;
        }

        // Queued. Poll for completion (remember the prior run time to confirm a new one).
        this.pollRun();
      } catch (e) {
        this.busy = false;
        this.notify('error', `Run failed: ${e.message}`);
      }
    },
    // Poll the dashboard while a background run is in flight. Stops when the server's
    // running flag clears (success) or after a generous ceiling (leaves the run going
    // server-side and tells the user to refresh).
    pollRun() {
      this.clearPoll();
      const startedAt = Date.now();
      const CEILING_MS = 6 * 60 * 1000;
      const tick = async () => {
        this.pollTimer = null;
        try {
          const d = await this.api.getVisibilityDashboard();
          if (d.dashboard) this.dashboard = d.dashboard;
          if (!d.running) {
            this.lastRunAt = d.lastRunAt || this.lastRunAt;
            this.busy = false;
            this.notify('success', 'Check complete — results updated.');
            // No payload: the run's dashboard doesn't carry the composite score —
            // the rail refetches it quietly so the Cited rung updates in step.
            this.$emit('measured');
            return;
          }
        } catch (e) {
          /* transient poll error — keep waiting */
        }
        if (Date.now() - startedAt > CEILING_MS) {
          this.busy = false;
          this.notify('warn', 'Still running in the background — refresh in a moment to see results.');
          return;
        }
        this.pollTimer = window.setTimeout(tick, 3000);
      };
      this.pollTimer = window.setTimeout(tick, 3000);
    },
    clearPoll() {
      if (this.pollTimer) {
        window.clearTimeout(this.pollTimer);
        this.pollTimer = null;
      }
    },
    async clearData() {
      this.busy = true;
      try {
        const r = await this.api.clearVisibilityData();
        this.dashboard = r.dashboard;
        this.lastRunAt = r.lastRunAt || '';
        this.notify('success', 'Results cleared.');
        this.$emit('measured'); // Clearing moves the Cited rung too — back to "run a check".
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
    // Share-of-voice bars, most-named first (keep the product itself visible even at 0).
    sortVoice(voice) {
      return (voice || []).slice().sort((a, b) => b.mentions - a.mentions);
    },
    providerLabel(id) {
      return (this.providersMeta[id] && this.providersMeta[id].label) || id;
    },
    // Compact label for a cited source: its host, without the www. prefix.
    srcHost(url) {
      try {
        return new URL(url).hostname.replace(/^www\./, '');
      } catch (e) {
        return url;
      }
    },
  },
};
</script>

<template>
  <div class="agv">
    <div v-if="error" class="agv-note agv-note--bad">Could not load Visibility: {{ error }}</div>
    <!-- First load in flight: the shared skeleton, not a bare "Loading…" — same
         treatment as Endpoint Activity and Agent Access. -->
    <CardSkeleton v-else-if="!loaded" lead="Loading AI visibility…" />

    <div v-else class="ar-tabpanel">
      <nav class="ar-tabpanel__tabs" aria-label="Visibility views">
        <!-- Three views, three QUESTIONS: how search went for you (people),
             whether you're in the indexes (machines), whether AI answers cite
             you. Search leads — "how did search go?" is the everyday glance —
             and it is NAMED for its subject (his call: "Performance" alone
             never said performance of WHAT; the sibling labels all say). The
             view ids keep their history ('performance' stays this view's id,
             'aisearch' means the In-the-index view) so every stored deep link
             still lands. The citations workbench pair nests INSIDE its own
             tab, as before. -->
        <button type="button" class="ar-subnav__item" :class="{ 'is-active': view === 'performance' }" @click="view = 'performance'"><span class="ar-subnav__icon" aria-hidden="true" v-html="groupIcon('results')"></span>Search</button>
        <button type="button" class="ar-subnav__item" :class="{ 'is-active': view === 'aisearch' }" @click="view = 'aisearch'"><span class="ar-subnav__icon" aria-hidden="true" v-html="groupIcon('sources')"></span>In the index</button>
        <button type="button" class="ar-subnav__item" :class="{ 'is-active': view === 'citations' }" @click="view = 'citations'"><span class="ar-subnav__icon" aria-hidden="true" v-html="groupIcon('citations')"></span>Citations</button>
      </nav>
      <p class="ar-tabpanel__caption">{{ caption }}</p>

      <div class="ar-tabpanel__body">
        <!-- RESULTS -------------------------------------------------------- -->
        <!-- Checks off: the Citations tab holds its own key. Written for a
             FIRST TIMER, in the worklist intro's own grammar (his call — the
             ask-first invitation: mark, centered headline, plain explainer,
             one button, fine print; ar-work__intro reused, zero new CSS). The
             jargon (the "Cited" rung, keys, credit) only appears after the
             reader knows what it buys them, and the button says "set up", not
             "turn on", because that is what the click does: it opens the
             setup, runs nothing and spends nothing. -->
        <section v-if="!checksOn && view === 'citations'" class="ar-card ar-card--muted">
          <h2 class="ar-card__title">Citations <span class="ar-card__tag">Off</span></h2>
          <!-- "someone", never "a buyer" (his catch): nothing here knows
               whether the site sells anything. -->
          <p class="ar-card__lead" style="margin: 0 0 14px; padding: 0; border-bottom: 0;">
            Whether AI assistants name and link your site when someone asks about your
            subject — measured on a schedule, never guessed.
          </p>
          <div class="ar-work__intro">
            <!-- The Citations view's own quote marks, at invitation size — the
                 same symbol its subnav tab wears (one concept, one mark). -->
            <svg class="ar-work__intro-mark" viewBox="0 0 48 48" width="44" height="44" fill="none"
                 stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M11 24.5a7 7 0 1 0 7 7C18 24 15.4 15.5 9.8 9" />
              <path d="M31.4 24.5a7 7 0 1 0 7 7C38.4 24 35.8 15.5 30.2 9" />
            </svg>

            <h3 class="ar-work__intro-title">Do AI answers name your site?</h3>
            <p class="ar-work__intro-lead">
              Write the questions your site should be the answer to — or let AI suggest
              a spread — and Agentimus puts them to ChatGPT, Perplexity, Gemini and
              Claude on a schedule, through your own AI keys. Each answer is graded —
              mentioned, linked, or a rival named instead — and the verdicts build your
              history and the “Cited” rung of your score.
            </p>

            <button type="button" class="ar-btn" @click="setChecks(true)">Set up citation checks</button>

            <p class="ar-work__intro-note">
              Setting this up runs nothing and spends nothing yet. Checks start once you’ve
              added questions and a key — each run then spends a little of your own AI credit.
            </p>
          </div>
        </section>

        <!-- The citations workbench pair, nested inside its own tab. -->
        <nav v-if="checksOn && view === 'citations'" class="agv-innernav" aria-label="Citations views">
          <button type="button" :class="{ 'is-active': citView === 'results' }" @click="citView = 'results'">Results</button>
          <button type="button" :class="{ 'is-active': citView === 'settings' }" @click="citView = 'settings'">Settings</button>
        </nav>

        <!-- The tenant's key hangs inside its own door: state named, off switch
             beside it — the global Features list no longer carries this toggle. -->
        <div v-if="checksOn && view === 'citations' && citView === 'settings'" class="agv-checkstate">
          <span>Citation checks are <strong>on</strong> — scheduled runs, the “Cited” rung and the MCP read tool are active. Turning them off keeps your targets and history.</span>
          <!-- A power mark + a confirm (his call): this link stops a scheduled
               feature and reshapes the score, so it must not fire on a stray
               click — and the dialog is where the "nothing is lost" promise
               gets read before it matters. -->
          <button type="button" class="ar-linkbtn agv-checkstate__off" @click="confirmChecksOff">
            <svg class="agv-checkstate__power" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M12 3v9" />
              <path d="M17.7 7.2a8 8 0 1 1-11.4 0" />
            </svg>
            Turn off citation checks
          </button>
        </div>

        <div v-show="checksOn && view === 'citations' && citView === 'results'" class="agv-results">
          <!-- The run summary is one sheet; each tracked product below is its own card. -->
          <div class="agv-sheet">
          <div class="agv-runbar">
            <span class="agv-runbar__meta">Last run · {{ fmtDate(lastRunAt) }}</span>
            <!-- The hand-crank half of the freshness rule — same mark as every
                 other data card. Re-reads stored results; it never starts a run. -->
            <RefreshCrank
              :busy="quietBusy"
              :disabled="busy"
              aria-label="Re-read the citation results"
              title="Re-read the citation results"
              @refresh="quietReload"
            />
            <div class="agv-runbar__actions">
              <button v-if="hasData" type="button" class="ar-btn ar-btn--ghost agv-btn-sm agv-btn-danger" :disabled="busy" @click="clearData">Clear</button>
              <button type="button" class="ar-btn agv-btn-sm" :disabled="busy" @click="run">{{ busy ? 'Running…' : 'Run check now' }}</button>
            </div>
          </div>

          <div v-if="!hasData" class="agv-empty">
            <h2>No results yet</h2>
            <p>Head to Settings to add a product with a few questions and one AI key, then run your first check.</p>
            <div class="agv-empty__actions">
              <button type="button" class="ar-btn" @click="citView = 'settings'">Go to Settings</button>
              <button type="button" class="ar-btn ar-btn--ghost" :disabled="busy" @click="run">{{ busy ? 'Running…' : 'Run check now' }}</button>
            </div>
          </div>

          <template v-else>
            <p v-if="runHeadline" class="agv-headline">{{ runHeadline }}</p>
            <div class="agv-cards">
              <div class="agv-card" :data-tone="scoreTone(summary.visibilityScore)">
                <div class="agv-card__value">{{ summary.visibilityScore }}<small>%</small></div>
                <div class="agv-card__label">Seen in answers</div>
                <div class="agv-card__sub">Answers that named something you track</div>
              </div>
              <div class="agv-card" :data-tone="scoreTone(summary.citationRate)">
                <div class="agv-card__value">{{ summary.citationRate }}<small>%</small></div>
                <div class="agv-card__label">Linked your site</div>
                <div class="agv-card__sub">The strongest sign — AI linked to you</div>
              </div>
              <div class="agv-card">
                <div class="agv-card__value">{{ summary.mentions }}<small>/{{ summary.checks }}</small></div>
                <div class="agv-card__label">Mentions</div>
                <div class="agv-card__sub">Named answers, across all products</div>
              </div>
              <div class="agv-card" :data-tone="summary.errors ? 'warn' : ''">
                <div class="agv-card__value">{{ summary.errors }}</div>
                <div class="agv-card__label">Errors</div>
                <div class="agv-card__sub">Checks that didn’t finish</div>
              </div>
            </div>

            <div class="agv-panel">
              <h3 class="agv-panel__title">Visibility over time</h3>
              <p class="agv-panel__hint">How often AI mentions you, across everything you track — run to run.</p>
              <template v-if="trend.length >= 2">
                <svg class="agv-spark" viewBox="0 0 460 96" preserveAspectRatio="none">
                  <polyline :points="trendPoints" fill="none" stroke="var(--ar-accent)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                </svg>
                <div class="agv-spark__labels">
                  <span>{{ fmtDate(trend[0].at) }}</span>
                  <span>{{ fmtDate(trend[trend.length - 1].at) }}</span>
                </div>
              </template>
              <p v-else class="agv-muted">Run a couple of checks and a trend line will appear here.</p>
            </div>

            <div class="agv-legend agv-legend--top">
              <span class="agv-legend__title">What the tags mean:</span>
              <span class="agv-chip" data-state="cited">cited</span><small>named it &amp; linked its site</small>
              <span class="agv-chip" data-state="mention">mentioned</span><small>named it</small>
              <span class="agv-chip" data-state="absent">absent</span><small>didn’t name it</small>
            </div>
          </template>
          </div>

          <!-- One self-contained card per product, a peer of the summary sheet. -->
          <template v-if="hasData">
            <section v-for="p in products" :key="p.name" class="agv-panel agv-product">
              <div class="agv-product__head">
                <h3 class="agv-product__name">{{ p.name || 'Your site' }}</h3>
                <a v-if="p.domain" class="agv-product__site" :href="'https://' + p.domain" target="_blank" rel="noopener">{{ p.domain }} ↗</a>
                <span v-if="p.paused" class="agv-paused-pill">Paused</span>
              </div>

              <template v-if="p.summary.checks || p.summary.errors">
                <div class="agv-product__stats">
                  <div class="agv-stat" :data-tone="scoreTone(p.summary.visibilityScore)">
                    <span class="agv-stat__val">{{ p.summary.visibilityScore }}%</span>
                    <span class="agv-stat__label">Seen in answers</span>
                  </div>
                  <div class="agv-stat" :data-tone="scoreTone(p.summary.citationRate)">
                    <span class="agv-stat__val">{{ p.summary.citationRate }}%</span>
                    <span class="agv-stat__label">Linked its site</span>
                  </div>
                  <div class="agv-stat">
                    <span class="agv-stat__val">{{ p.rank ? '#' + p.rank : '—' }}</span>
                    <span class="agv-stat__label">Rank vs rivals</span>
                  </div>
                </div>

                <p class="agv-product__line">{{ productSummary(p) }}</p>

                <div v-if="p.shareOfVoice && p.shareOfVoice.length > 1" class="agv-product__block">
                  <h4 class="agv-sub">Who AI names — {{ p.name || 'you' }} vs rivals</h4>
                  <div v-for="v in sortVoice(p.shareOfVoice)" :key="v.name" class="agv-bar" :class="{ 'is-brand': v.isBrand }">
                    <div class="agv-bar__name">{{ v.name }}</div>
                    <div class="agv-bar__track"><div class="agv-bar__fill" :style="{ width: v.share + '%' }"></div></div>
                    <div class="agv-bar__val">{{ v.share }}%</div>
                  </div>
                </div>

                <div v-if="p.prompts && p.prompts.length" class="agv-product__block">
                  <h4 class="agv-sub">Question by question</h4>
                  <div v-for="(q, i) in p.prompts" :key="i" class="agv-prompt">
                    <div class="agv-prompt__q">{{ q.prompt }}</div>
                    <div class="agv-prompt__providers">
                      <div v-for="(pr, j) in q.providers" :key="j" class="agv-pr">
                        <span class="agv-chip" :data-state="chipState(pr).cls" v-tip="pr.error || pr.excerpt || ''">{{ providerLabel(pr.provider) }} · {{ chipState(pr).label }}</span>
                        <span v-if="pr.error" class="agv-err">{{ pr.error }}</span>
                        <span v-if="pr.sources && pr.sources.length" class="agv-web" v-tip="`Answered using a live web search`">web</span>
                        <ul v-if="pr.sources && pr.sources.length" class="agv-src">
                          <li v-for="(u, k) in pr.sources" :key="k">
                            <a :href="u" v-tip="u" target="_blank" rel="noopener nofollow">{{ srcHost(u) }}</a>
                          </li>
                        </ul>
                      </div>
                    </div>
                  </div>
                </div>
              </template>
              <p v-else-if="p.paused" class="agv-muted">Paused — this one isn’t being checked. <button type="button" class="agv-linkbtn" @click="citView = 'settings'">Turn it back on</button>.</p>
              <p v-else-if="p.hasQuestions" class="agv-muted">No results for this one yet — <button type="button" class="agv-linkbtn" :disabled="busy" @click="run">run a check</button>.</p>
              <p v-else class="agv-muted">No questions to ask yet, so there’s nothing to score. <button type="button" class="agv-linkbtn" @click="citView = 'settings'">Add a question</button>.</p>
            </section>
          </template>
        </div>

        <!-- SETTINGS ------------------------------------------------------- -->
        <!-- No Save button anywhere on this screen: every control persists itself. Enter
             inside a field would otherwise submit and reload the admin page, so swallow it. -->
        <form v-show="checksOn && view === 'citations' && citView === 'settings'" class="agv-form" @submit.prevent>
          <section class="ar-card">
            <h2 class="ar-card__title">
              What You're Tracking
              <transition name="agv-fade"><span v-if="savedIn('targets')" class="agv-saved-pill">Saved ✓</span></transition>
            </h2>
            <p class="ar-card__lead">
              Add each thing you want to watch — you, your brand, a product. For each one we
              ask AI your questions and check the answers. Changes here save on their own.
            </p>

            <div class="agv-products">
              <div v-for="(t, i) in form.targets" :key="i" class="agv-productcard" :class="{ 'is-paused': !t.active }">
                <div class="agv-productcard__bar">
                  <span class="agv-productcard__num">{{ i + 1 }}</span>
                  <span class="agv-productcard__title" :class="{ 'is-untitled': !(t.name || '').trim() }">{{ (t.name || '').trim() || 'Untitled' }}</span>
                  <transition name="agv-fade">
                    <span v-if="cardStatus[i]" class="agv-productcard__save" :data-state="cardStatus[i]">
                      {{ cardStatus[i] === 'saving' ? 'Saving…' : cardStatus[i] === 'saved' ? 'Saved ✓' : 'Not saved' }}
                    </span>
                  </transition>
                  <label class="ar-toggle agv-sw agv-productcard__toggle"
                    v-tip="t.active ? 'Active — included in checks' : 'Paused — skipped in checks'">
                    <input type="checkbox" v-model="t.active" @change="autoSaveTargets(i)" />
                    <span class="ar-toggle__track" aria-hidden="true"></span>
                    <span class="agv-productcard__state">{{ t.active ? 'Active' : 'Paused' }}</span>
                  </label>
                  <button type="button" class="agv-productcard__remove" @click="removeTarget(i)"
                    :disabled="form.targets.length === 1 && !t.name" v-tip="`Remove this product`">Remove</button>
                </div>

                <div class="ar-grid">
                  <div class="ar-field">
                    <label>What is it called? <span class="ar-field__note">required</span></label>
                    <input type="text" class="ar-input" v-model="t.name" ref="targetName" spellcheck="false"
                      :class="{ 'agv-input-warn': !(t.name || '').trim() && hasContent(t) }"
                      placeholder="e.g. Agentimus" @change="autoSaveTargets(i)" />
                    <small class="ar-field__hint">The exact name you want AI to say. Needed to save this product.</small>
                  </div>
                  <div class="ar-field">
                    <label>What kind of thing is it?</label>
                    <input type="text" class="ar-input" v-model="t.category" spellcheck="false"
                      maxlength="80"
                      placeholder="e.g. WordPress SEO plugin" @change="autoSaveTargets(i)" />
                    <small class="ar-field__hint">The category people would look for it in — not what you write about. This is what we use to suggest questions.</small>
                  </div>
                </div>
                <div class="ar-grid">
                  <div class="ar-field">
                    <label>Its website <span class="ar-field__note">optional</span></label>
                    <input type="text" class="ar-input" v-model="t.domain" spellcheck="false"
                      placeholder="e.g. agentimus.com" @change="autoSaveTargets(i)" />
                    <small class="ar-field__hint">So we can tell if AI links to it. Leave blank if it doesn’t have one.</small>
                  </div>
                  <div class="ar-field">
                    <label>Who are its rivals?</label>
                    <TagInput v-model="t.competitors" after-hint placeholder="Add a rival, press Enter" @update:modelValue="autoSaveTargets(i)" />
                    <small class="ar-field__hint">The other names it competes with. We’ll show who AI picks instead.</small>
                  </div>
                </div>
                <div class="ar-grid">
                  <div class="ar-field">
                    <label>What should we ask AI?</label>
                    <TagInput v-model="t.prompts" after-hint placeholder="Type a question, press Enter" @update:modelValue="autoSaveTargets(i)" />
                    <!-- Hint left, the two generators right-aligned on the SAME line. -->
                    <div class="agv-suggest__head">
                      <small class="ar-field__hint">Real questions a person would type. Press Enter after each. These are the answers we grade.</small>
                      <div class="agv-suggest__actions">
                        <button
                          type="button"
                          class="agv-linkbtn agv-suggest__trigger"
                          :disabled="suggBusy(i)"
                          @click="suggestQuestions(i)"
                        >{{ sugg[i] && sugg[i].loading ? 'Finding ideas…' : 'Suggest questions' }}</button>
                        <!-- Needs a category for the same reason the templates do: without one
                             the model would invent a market. Disabled rather than hidden, so
                             the reason is visible instead of mysterious. -->
                        <button
                          v-if="aiAvailable"
                          type="button"
                          class="agv-linkbtn agv-suggest__trigger"
                          :disabled="suggBusy(i) || !(t.category || '').trim()"
                          v-tip="(t.category || '').trim()
                            ? 'Ask the AI you set up under Settings → AI to write questions people would type'
                            : 'First say what kind of thing this is, above — otherwise the AI has no market to ask about.'"
                          @click="suggestQuestionsAi(i)"
                        >{{ sugg[i] && sugg[i].aiLoading ? 'Asking your AI…' : '✦ Suggest with AI' }}</button>
                      </div>
                    </div>
                    <div class="agv-suggest">
                      <div v-if="sugg[i] && sugg[i].items && sugg[i].items.length" class="agv-suggest__chips">
                        <button
                          v-for="q in sugg[i].items"
                          :key="q"
                          type="button"
                          class="agv-suggest__chip"
                          @click="addSuggestion(i, q)"
                        ><span aria-hidden="true">+</span> {{ q }}</button>
                      </div>
                      <p v-else-if="sugg[i] && !suggBusy(i) && sugg[i].items" class="agv-muted agv-suggest__empty">
                        {{ (t.category || '').trim()
                          ? 'No new ideas right now — you already track the ones we’d suggest. Type your own above.'
                          : 'Fill in “What kind of thing is it?” above and we can suggest the questions people ask.' }}
                      </p>
                    </div>
                  </div>
                </div>
                <p v-if="!(t.name || '').trim() || !t.prompts.length" class="agv-productcard__warn">
                  ⚠ {{ cardRequirement(t) }}
                </p>
              </div>
            </div>

            <button type="button" class="agv-list__add agv-add-product" @click="addTarget">+ Add another</button>
          </section>

          <section class="ar-card">
            <h2 class="ar-card__title">
              AI Engines
              <transition name="agv-fade"><span v-if="savedIn('engines')" class="agv-saved-pill">Saved ✓</span></transition>
            </h2>
            <p class="ar-card__lead">Turn on the AI engines you want and paste each one’s API key (you get these from the engine’s own site). Keys stay on your server and are only used to run your checks. Perplexity always answers using a live web search; the others answer from what they already know, unless you switch on their web search. Changes here save on their own.</p>
            <!-- Pre-empts the fair "why not use WordPress's AI connector?" question a
                 user or developer will ask on seeing separate keys here. The reason is
                 intrinsic to the feature, not a WordPress-version thing. -->
            <p class="ar-card__note ar-card__note--wide">
              <strong>Why its own keys, and not your WordPress AI connector?</strong> A visibility check is
              graded on the <strong>sources each engine cited</strong> in its answer, and it compares
              <strong>several engines side by side</strong>. WordPress’s shared AI connector hands back the
              answer text only — the cited sources are dropped — and points at a single provider, so it can’t
              run this check. Talking to each engine directly is the only way to see who it cited. (This is
              also why citation checks work on WordPress older than 7.0, where that connector doesn’t exist.)
            </p>
            <div class="agv-engines">
              <div class="agv-engine agv-engine--head">
                <span>Engine</span><span>API key</span><span>Model</span><span></span><span></span>
              </div>
              <div v-for="id in providerIds" :key="id" class="agv-engine">
                <div class="agv-engine__id">
                  <label class="ar-toggle agv-sw">
                    <input type="checkbox" :id="'agv-eng-' + id" v-model="form.providers[id].enabled" @change="autoSaveProviders" />
                    <span class="ar-toggle__track" aria-hidden="true"></span>
                  </label>
                  <span class="agv-engine__name">
                    <label :for="'agv-eng-' + id" class="agv-engine__toglabel">{{ providersMeta[id].label }}</label>
                    <span v-if="providersMeta[id].grounded" class="agv-engine__tag" :class="{ 'is-off': !form.providers[id].enabled }" v-tip="`Perplexity always answers from a live web search — there's nothing to switch on.`">Live web is always on</span>
                    <label v-else-if="providersMeta[id].webSearchCapable" class="agv-wspill"
                      :class="{ 'is-on': form.providers[id].web_search, 'is-off': !form.providers[id].enabled }"
                      v-tip="webSearchTitle(id)">
                      <input type="checkbox" v-model="form.providers[id].web_search" :disabled="!form.providers[id].enabled" @change="autoSaveProviders" />
                      Live web {{ form.providers[id].web_search ? 'on' : 'off' }}
                    </label>
                  </span>
                </div>
                <div class="agv-engine__keywrap">
                  <!-- @change fires only when the value actually changed, so merely focusing
                       (which selects the masked dots) never commits anything. -->
                  <input :type="form.providers[id].reveal ? 'text' : 'password'" class="ar-input agv-engine__key" v-model="form.providers[id].key" autocomplete="off"
                    @focus="onKeyFocus(id, $event)"
                    @change="autoSaveProviders"
                    :placeholder="providersMeta[id].hasKey ? 'Cleared — this removes the key' : providersMeta[id].keyHint" />
                  <button v-if="providersMeta[id].hasKey || form.providers[id].key" type="button" class="agv-engine__eye"
                    v-tip="form.providers[id].reveal ? 'Hide key' : 'Show key'"
                    :aria-label="form.providers[id].reveal ? 'Hide key' : 'Show key'"
                    @click="toggleReveal(id)">
                    <svg v-if="!form.providers[id].reveal" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.9 17.9A10.7 10.7 0 0 1 12 19c-7 0-11-7-11-7a19.5 19.5 0 0 1 5.1-5.9M9.9 4.2A10.9 10.9 0 0 1 12 4c7 0 11 7 11 7a19.4 19.4 0 0 1-2.2 3.2M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M1 1l22 22"/></svg>
                  </button>
                </div>
                <div class="agv-engine__modelcell">
                  <SelectMenu
                    :model-value="form.providers[id].modelCustom ? '__custom__' : form.providers[id].model"
                    :options="modelOptions(id)"
                    mono
                    :aria-label="`Model for ${providersMeta[id].label}`"
                    @update:model-value="(v) => onModelPick(id, v)" />
                  <input v-if="form.providers[id].modelCustom" type="text" class="ar-input agv-engine__model"
                    v-model="form.providers[id].model" spellcheck="false" placeholder="Type a model ID"
                    @change="autoSaveProviders" />
                </div>
                <button type="button" class="ar-btn ar-btn--ghost agv-btn-sm" @click="testKey(id)">Test</button>
                <span class="agv-engine__status" :data-state="tests[id] ? tests[id].state : ''">
                  <template v-if="tests[id] && tests[id].state === 'testing'">Testing…</template>
                  <template v-else-if="tests[id] && tests[id].state === 'ok'">✓ Working</template>
                  <template v-else-if="tests[id] && tests[id].state === 'bad'">
                    <button type="button" class="agv-engine__status-btn" @click="openError(id)">✗ See error</button>
                    <button type="button" class="agv-engine__status-x" @click="dismissTest(id)"
                      aria-label="Dismiss error" v-tip="`Dismiss`">✕</button>
                  </template>
                  <span v-else-if="providersMeta[id].hasKey" class="agv-engine__saved">✓ saved</span>
                  <a v-else-if="providersMeta[id].helpUrl" :href="providersMeta[id].helpUrl" target="_blank" rel="noopener" class="agv-engine__help">Get a key ↗</a>
                </span>
              </div>
            </div>

            <p class="ar-card__note ar-card__note--wide">
              <strong>Already added a key under Settings → AI?</strong> You still need one here. WordPress’s shared
              connectors hand back the answer text only — they drop the list of sources an engine cited, and those
              sources are what a visibility check grades. Reading them needs each engine’s own API, so these keys are
              kept separate. They stay on your server and are only used to run your checks.
            </p>
          </section>

          <section class="ar-card">
            <h2 class="ar-card__title">
              Schedule
              <transition name="agv-fade"><span v-if="savedIn('schedule')" class="agv-saved-pill">Saved ✓</span></transition>
            </h2>
            <p class="ar-card__lead">Changes here save on their own.</p>

            <!-- Master switch — just the toggle + On/Off under the section heading. -->
            <div class="ar-field agv-runfield">
              <div class="agv-switch">
                <label class="ar-toggle agv-sw" v-tip="`Run checks automatically`">
                  <input type="checkbox" id="agv-schedule" v-model="form.scheduleActive" aria-label="Run checks automatically" @change="autoSaveSchedule" />
                  <span class="ar-toggle__track" aria-hidden="true"></span>
                </label>
                <label class="agv-switch__state" for="agv-schedule">{{ form.scheduleActive ? 'On' : 'Off' }}</label>
              </div>
            </div>

            <!-- Two independent settings, side by side. The per-field hints make
                 clear what belongs to the schedule and what doesn't. -->
            <div class="ar-grid agv-sched">
              <div class="ar-field" :class="{ 'agv-dim': !form.scheduleActive }">
                <label>How often</label>
                <select class="ar-input" v-model="form.frequency" :disabled="!form.scheduleActive" @change="autoSaveSchedule">
                  <option value="daily">Daily</option>
                  <option value="weekly">Weekly</option>
                </select>
                <small class="ar-field__hint">Checks run on their own at this frequency.</small>
              </div>
              <div class="ar-field">
                <label>Keep history (days)</label>
                <input type="number" class="ar-input" v-model="form.retentionDays" min="7" max="730" @change="autoSaveSchedule" />
                <small class="ar-field__hint">Applies to every check, including manual runs.</small>
              </div>
            </div>
          </section>
        </form>
      </div>
    </div>

    <!-- Test-failure dialog: the full provider error, wrapped and readable, with
         any URLs in it turned into real clickable links. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="errorDialog" class="ar-modal" @click.self="closeError">
          <div
            ref="errDialog"
            class="ar-modal__panel ar-modal__panel--confirm"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="agv-err-title"
            tabindex="-1"
            @keydown.esc="closeError"
          >
            <div class="ar-modal__head">
              <h2 id="agv-err-title" class="ar-modal__title">{{ errorDialog.label }} — test failed</h2>
            </div>
            <div class="ar-modal__body">
              <div class="ar-modal__scroll">
                <p class="agv-err__msg"><template
                  v-for="(seg, k) in errorSegments(errorDialog.msg)" :key="k"
                ><a v-if="seg.href" :href="seg.href" target="_blank" rel="noopener">{{ seg.text }}</a><template v-else>{{ seg.text }}</template></template></p>
              </div>
            </div>
            <div class="ar-modal__actions agv-err__actions">
              <a v-if="errorDialog.helpUrl" :href="errorDialog.helpUrl" target="_blank" rel="noopener" class="ar-btn ar-btn--ghost agv-btn-sm">Get a key ↗</a>
              <button type="button" class="ar-btn ar-btn--ghost agv-btn-sm" :disabled="errorTesting" @click="testKey(errorDialog.id)">{{ errorTesting ? 'Testing…' : 'Test again' }}</button>
              <button type="button" class="ar-btn agv-btn-sm" :disabled="errorTesting" @click="closeError">Close</button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>
  </div>
</template>

<style scoped>

/* Notices */
.agv-note { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 16px 26px 0; padding: 10px 14px; font-size: 13px; background: var(--ar-surface); border: 1px solid var(--ar-line); border-left: 3px solid var(--ar-ink-faint); border-radius: var(--ar-radius); }
.agv-note--success { border-left-color: var(--ar-good); }
.agv-note--warn { border-left-color: var(--ar-warn); }
.agv-note--bad, .agv-note--error { border-left-color: var(--ar-bad); }
.agv-note__x { background: none; border: 0; font-size: 17px; line-height: 1; cursor: pointer; color: var(--ar-ink-faint); }

/* Results is a transparent stack on wp-admin's background: the run summary is one
   sheet, then every tracked product is its own full-width card — the same free-
   standing-card grammar as the Settings page. */
/* Nested workbench tabs inside the Citations tab — pill-segmented and quieter
   than the screen's own subnav, so the hierarchy reads at a glance. */
.agv-innernav { display: flex; gap: 8px; margin: 2px 0 18px; }
.agv-innernav button {
  font-family: var(--ar-mono); font-size: 10.5px; letter-spacing: 0.08em; text-transform: uppercase;
  padding: 8px 16px; border: 1px solid var(--ar-line); border-radius: 999px;
  background: var(--ar-surface); color: var(--ar-ink-soft); cursor: pointer;
}
.agv-innernav button.is-active { background: var(--ar-ink); border-color: var(--ar-ink); color: var(--ar-surface); }
.agv-innernav button:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 2px; }
/* The in-tab feature key: current state named, the off switch beside it. */
.agv-checkstate {
  display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px 16px;
  margin: 0 0 16px; padding: 10px 14px; font-size: 12.5px; color: var(--ar-ink-soft);
  background: var(--ar-surface-2); border: 1px solid var(--ar-line); border-radius: var(--ar-radius);
}
.agv-checkstate__off { color: var(--ar-bad); display: inline-flex; align-items: center; gap: 5px; }
.agv-checkstate__power { flex: 0 0 auto; }

.agv-results { display: grid; gap: 18px; }
.agv-sheet {
  padding: 22px 26px;
  background: var(--ar-surface); border: 1px solid var(--ar-line);
  border-radius: var(--ar-radius);
}
.agv-product { padding: 22px 26px; margin-bottom: 0; }
.agv-form { display: grid; gap: 18px; }
.agv-muted { color: var(--ar-ink-soft); font-size: 13px; }
.agv-headline { font-family: var(--ar-serif); font-size: 16.5px; line-height: 1.5; color: var(--ar-ink); margin: 0 0 20px; max-width: 70ch; text-wrap: pretty; }
/* Auto-save confirmation pill next to the section title. */
.agv-saved-pill { margin-left: 10px; font-family: var(--ar-mono); font-size: 10px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-good); background: color-mix(in srgb, var(--ar-good) 12%, var(--ar-surface)); border: 1px solid color-mix(in srgb, var(--ar-good) 30%, var(--ar-line)); padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
.agv-fade-enter-active, .agv-fade-leave-active { transition: opacity 0.25s ease; }
.agv-fade-enter-from, .agv-fade-leave-to { opacity: 0; }
.agv-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin: -4px 0 16px; font-size: 12px; color: var(--ar-ink-soft); }
.agv-legend small { margin-right: 10px; }
.agv-engine__saved { color: var(--ar-good); font-weight: 600; }

/* Small button size + danger tint, layered on .ar-btn */
.agv-btn-sm { padding: 8px 16px; font-size: 11px; }
.agv-btn-danger { color: var(--ar-bad); border-color: var(--ar-line-strong); }

/* Run bar */
.agv-runbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.agv-runbar__meta { color: var(--ar-ink-soft); font-family: var(--ar-mono); font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; }
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
.agv-panel__hint { margin: -8px 0 14px; font-size: 12.5px; color: var(--ar-ink-soft); }

/* Top legend for the per-product tags. */
.agv-legend--top { margin: 4px 0 16px; padding: 10px 14px; background: var(--ar-surface); border: 1px solid var(--ar-line); border-radius: var(--ar-radius); }
.agv-legend__title { font-weight: 600; color: var(--ar-ink); margin-right: 4px; }

/* Per-product results section. */
.agv-product__head { display: flex; align-items: baseline; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
.agv-product__name { margin: 0; font-family: var(--ar-serif); font-size: 17px; color: var(--ar-ink); }
.agv-product__site { font-size: 12px; color: var(--ar-accent); text-decoration: none; }
.agv-product__site:hover { text-decoration: underline; }
.agv-paused-pill { font-family: var(--ar-mono); font-size: 9px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-ink-soft); background: var(--ar-surface-2); border: 1px solid var(--ar-line-strong); padding: 2px 8px; border-radius: 999px; }
.agv-product__stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px; }
.agv-stat { background: var(--ar-surface-2); border: 1px solid var(--ar-line); border-radius: var(--ar-radius); padding: 12px 14px; text-align: center; }
.agv-stat__val { display: block; font-family: var(--ar-serif); font-size: 26px; line-height: 1; color: var(--ar-ink); font-variant-numeric: tabular-nums; }
.agv-stat[data-tone="good"] .agv-stat__val { color: var(--ar-good); }
.agv-stat[data-tone="warn"] .agv-stat__val { color: var(--ar-warn, #b7791f); }
.agv-stat[data-tone="bad"] .agv-stat__val { color: var(--ar-bad); }
.agv-stat__label { display: block; margin-top: 6px; font-size: 11.5px; color: var(--ar-ink-soft); }
.agv-product__line { margin: 0 0 14px; font-size: 13.5px; color: var(--ar-ink-soft); }
.agv-product__block { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--ar-line); }
.agv-sub { margin: 0 0 10px; font-family: var(--ar-mono); font-size: 10.5px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-ink-faint); }

/* Settings: one card per tracked product. */
.agv-products { display: flex; flex-direction: column; gap: 16px; }
.agv-productcard { border: 1px solid var(--ar-line-strong); border-radius: var(--ar-radius); padding: 14px 16px 4px; background: var(--ar-surface); }
.agv-productcard__bar { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.agv-productcard__num { flex: 0 0 auto; width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center; font-family: var(--ar-mono); font-size: 11px; color: var(--ar-paper); background: var(--ar-ink); border-radius: 50%; }
.agv-productcard__title { flex: 1; font-weight: 600; font-size: 14px; color: var(--ar-ink); }
.agv-productcard__title.is-untitled { font-weight: 400; font-style: italic; color: var(--ar-ink-faint); }
.agv-productcard__remove { flex: 0 0 auto; font-family: var(--ar-mono); font-size: 10px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--ar-ink-faint); background: none; border: 1px solid var(--ar-line); border-radius: var(--ar-radius); padding: 5px 9px; cursor: pointer; }
.agv-productcard__remove:hover:not(:disabled) { color: var(--ar-bad); border-color: color-mix(in srgb, var(--ar-bad) 35%, var(--ar-line)); }
.agv-productcard__remove:disabled { opacity: 0.4; cursor: default; }
/* Active/Paused switch on the product card; the card dims while paused. */
.agv-productcard__toggle { flex: 0 0 auto; }
/* Inline per-card save feedback in the card header. */
.agv-productcard__save { flex: 0 0 auto; font-family: var(--ar-mono); font-size: 10px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; padding: 3px 9px; border-radius: 999px; }
.agv-productcard__save[data-state="saving"] { color: var(--ar-ink-soft); background: var(--ar-surface-2); border: 1px solid var(--ar-line); }
.agv-productcard__save[data-state="saved"] { color: var(--ar-good); background: color-mix(in srgb, var(--ar-good) 12%, var(--ar-surface)); border: 1px solid color-mix(in srgb, var(--ar-good) 30%, var(--ar-line)); }
.agv-productcard__save[data-state="unsaved"] { color: var(--ar-warn, #b7791f); background: color-mix(in srgb, var(--ar-warn, #b7791f) 12%, var(--ar-surface)); border: 1px solid color-mix(in srgb, var(--ar-warn, #b7791f) 30%, var(--ar-line)); }
/* Name field flagged amber when it's the only thing missing. */
.ar-input.agv-input-warn { border-color: color-mix(in srgb, var(--ar-warn, #b7791f) 55%, var(--ar-line)); }
.agv-productcard__state { margin-left: 8px; font-family: var(--ar-mono); font-size: 10px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--ar-ink-soft); }
.agv-productcard.is-paused { opacity: 0.62; }
.agv-productcard.is-paused .agv-productcard__num { background: var(--ar-ink-faint); }
.agv-productcard.is-paused .agv-productcard__state { color: var(--ar-ink-faint); }
/* Keep the name field readable while paused so it can still be edited. */
.agv-productcard.is-paused .ar-field { opacity: 1; }

/* Schedule master switch — a plain field (no tinted banner), matching the two
   settings below it. The switch and its On/Off word sit on one line. */
.agv-switch { display: inline-flex; align-items: center; gap: 10px; }
.agv-switch__state { font-family: var(--ar-mono); font-size: 11px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: var(--ar-ink-soft); cursor: pointer; }
/* The two side-by-side settings; hints align tidily under each. */
.agv-sched .ar-field { align-content: start; }
.agv-sched .ar-field__hint { margin-top: 6px; }
/* When the schedule is off, "How often" is clearly disabled — a greyed, filled,
   not-allowed select (obvious at a glance), with its label + hint muted. */
.agv-sched .ar-input:disabled {
  color: var(--ar-ink-soft);
  -webkit-text-fill-color: var(--ar-ink-soft);
  background: var(--ar-surface-2);
  border-color: var(--ar-line-strong);
  cursor: not-allowed;
  opacity: 1;
}
.agv-dim > label,
.agv-dim > .ar-field__hint { opacity: 0.55; }

/* Alert on a product that can't be checked (no questions): centered, subtle amber. */
.agv-productcard__warn {
  margin: 14px 0 8px;
  padding: 10px 14px;
  text-align: center;
  font-size: 13px;
  font-weight: 500;
  color: var(--ar-warn, #b7791f);
  background: color-mix(in srgb, var(--ar-warn, #b7791f) 10%, var(--ar-surface));
  border: 1px solid color-mix(in srgb, var(--ar-warn, #b7791f) 30%, var(--ar-line));
  border-radius: var(--ar-radius);
}
/* A text button that reads like a link (used in the results empty states). */
.agv-linkbtn { background: none; border: 0; padding: 0; font: inherit; color: var(--ar-accent); cursor: pointer; text-decoration: underline; text-underline-offset: 2px; }
.agv-linkbtn:hover { text-decoration: none; }
.agv-linkbtn:disabled { color: var(--ar-ink-faint); cursor: default; text-decoration: none; }

/* Prompt suggestions — a quiet trigger under the questions field, then add-able chips. */
/* The offered suggestions sit AFTER the questions you already track (the TagInput's chip
   list, which orders itself to 1), so the card reads: field → hint+actions → yours → ours. */
.agv-suggest { order: 2; margin-top: 9px; }
/* Hint on the left, the generators right-aligned on the same line; they wrap under it
   only when the column gets too narrow to hold both. */
.agv-suggest__head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 2px 16px; }
.agv-suggest__head .ar-field__hint { flex: 1 1 260px; margin-top: 0; }
/* The two generators sit side by side — templates first (free, instant), AI second. */
.agv-suggest__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 16px; margin-left: auto; }
.agv-suggest__trigger { font-size: 12.5px; white-space: nowrap; }
.agv-suggest__trigger:disabled { opacity: 0.55; cursor: default; }
.agv-suggest__chips { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 9px; }
.agv-suggest__chip {
  max-width: 100%; text-align: left; cursor: pointer;
  font: inherit; font-size: 12.5px; line-height: 1.3;
  padding: 5px 10px; border-radius: 999px;
  color: var(--ar-accent); background: rgba(20, 107, 100, 0.07);
  border: 1px solid rgba(20, 107, 100, 0.24); transition: background 0.15s ease;
}
.agv-suggest__chip span { font-family: var(--ar-mono); opacity: 0.7; margin-right: 2px; }
.agv-suggest__chip:hover { background: rgba(20, 107, 100, 0.15); }
.agv-suggest__chip:focus-visible { outline: 2px solid var(--ar-accent); outline-offset: 2px; }
.agv-suggest__empty { margin: 9px 0 0; font-size: 12px; }
.agv-list__add {
  align-self: flex-start; font-family: var(--ar-mono); font-size: 11px;
  letter-spacing: 0.04em; color: var(--ar-accent); background: none;
  border: 1px dashed var(--ar-line-strong); border-radius: var(--ar-radius);
  padding: 8px 14px; cursor: pointer;
}
.agv-list__add:hover { background: var(--ar-surface-2); }
.agv-add-product { margin-top: 14px; }
.ar-field__note { font-family: var(--ar-mono); font-size: 10px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--ar-ink-faint); font-weight: 400; }

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
.agv-prompt__providers { display: flex; flex-direction: column; gap: 8px; }
.agv-pr { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.agv-chip { font-family: var(--ar-mono); font-size: 10.5px; letter-spacing: 0.03em; padding: 3px 9px; border-radius: 20px; border: 1px solid var(--ar-line); background: var(--ar-surface-2); color: var(--ar-ink-soft); }
.agv-chip[data-state="cited"] { color: var(--ar-good); border-color: color-mix(in srgb, var(--ar-good) 35%, var(--ar-line)); }
.agv-chip[data-state="mention"] { color: var(--ar-accent); border-color: color-mix(in srgb, var(--ar-accent) 35%, var(--ar-line)); }
.agv-chip[data-state="err"] { color: var(--ar-bad); border-color: color-mix(in srgb, var(--ar-bad) 35%, var(--ar-line)); }

/* "web" marker + the cited source URLs, shown when an engine answered from a live search. */
.agv-web { font-family: var(--ar-mono); font-size: 9px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-accent); background: color-mix(in srgb, var(--ar-accent) 10%, var(--ar-surface)); border: 1px solid color-mix(in srgb, var(--ar-accent) 30%, var(--ar-line)); padding: 2px 6px; border-radius: 10px; }
.agv-src { display: flex; flex-wrap: wrap; align-items: center; gap: 5px 10px; list-style: none; margin: 0; padding: 0; flex-basis: 100%; }
.agv-src::before { content: 'sources'; font-family: var(--ar-mono); font-size: 9px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-ink-faint); }
.agv-src li { font-size: 11px; line-height: 1.3; }
.agv-src a { color: var(--ar-ink-soft); text-decoration: none; border-bottom: 1px dotted var(--ar-line-strong); }
.agv-src a:hover { color: var(--ar-accent); border-bottom-color: var(--ar-accent); }
/* The reason a check errored, shown inline (no need to hover the chip). */
.agv-err { flex-basis: 100%; font-size: 11px; line-height: 1.4; color: var(--ar-bad); }

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
  grid-template-columns: 290px minmax(0, 1fr) 172px auto 112px;
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
/* The engine name is a <label> for its enable checkbox, so clicking it toggles the engine
   (matches the Features toggles). It inherits the name's weight/size; just flag it clickable. */
.agv-engine__toglabel { cursor: pointer; }
/* Toggleable "web search" pill on the same line — same family as the static
 * LIVE WEB tag, with an on/off dot. Dimmed + disabled until the engine is on. */
/* Optional per-engine "live web" search — a compact toggle pill, on the engine's own
   row. The dot + colour show on/off; the label uses the same "live web" wording as
   Perplexity's badge so it's clear these engines also search the live web when on. */
.agv-wspill { display: inline-flex; align-items: center; gap: 6px; font-family: var(--ar-mono); font-size: 9px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-ink-faint); background: var(--ar-surface-2); border: 1px solid var(--ar-line-strong); padding: 3px 8px; border-radius: 999px; cursor: pointer; white-space: nowrap; }
.agv-wspill input { position: absolute; opacity: 0; width: 0; height: 0; }
.agv-wspill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--ar-line-strong); transition: background 0.15s; }
.agv-wspill.is-on { color: var(--ar-accent); background: color-mix(in srgb, var(--ar-accent) 10%, var(--ar-surface)); border-color: color-mix(in srgb, var(--ar-accent) 30%, var(--ar-line)); }
.agv-wspill.is-on::before { background: var(--ar-accent); }
.agv-wspill.is-off { opacity: 0.4; cursor: not-allowed; }
/* Perplexity's fixed "always grounded" badge — informational, not a control (no dot). */
.agv-engine__tag { font-family: var(--ar-mono); font-size: 9px; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ar-accent); background: color-mix(in srgb, var(--ar-accent) 10%, var(--ar-surface)); border: 1px solid color-mix(in srgb, var(--ar-accent) 25%, var(--ar-line)); padding: 2px 7px; border-radius: 10px; }
.agv-engine__tag.is-off { opacity: 0.4; }
.agv-engine .ar-input { padding: 7px 10px; font-size: 13px; }
.agv-engine__model { font-family: var(--ar-mono); font-size: 12px; }
/* Model picker: a select of known models, with a custom field stacked under it
   when "Custom…" is chosen. */
.agv-engine__modelcell { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.agv-engine__modelcell .agv-engine__model { width: 100%; }
/* Key field + reveal (eye) button. The eye sits inside the input's right edge. */
.agv-engine__keywrap { position: relative; min-width: 0; }
.agv-engine__keywrap .ar-input { width: 100%; padding-right: 34px; }
.agv-engine__eye {
  position: absolute; top: 50%; right: 5px; transform: translateY(-50%);
  display: inline-flex; align-items: center; justify-content: center;
  width: 26px; height: 26px; padding: 0; border: 0; background: none;
  color: var(--ar-ink-faint); border-radius: 5px; cursor: pointer;
}
.agv-engine__eye:hover { color: var(--ar-ink); background: var(--ar-surface-2); }
.agv-engine__status { font-size: 12px; color: var(--ar-ink-faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.agv-engine__status[data-state="ok"] { color: var(--ar-good); font-weight: 600; }
.agv-engine__status[data-state="bad"] { color: var(--ar-bad); }
/* A failed test collapses to a compact trigger; the full message opens in a dialog. */
.agv-engine__status-btn {
  font: inherit; font-size: 12px; color: var(--ar-bad); background: none; border: 0;
  padding: 0; cursor: pointer; text-decoration: underline; text-underline-offset: 2px;
  max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.agv-engine__status-btn:hover { text-decoration: none; }
/* Dismiss a failed test → inline status falls back to "Get a key" / "✓ saved". */
.agv-engine__status-x { font: inherit; font-size: 11px; line-height: 1; color: var(--ar-ink-faint); background: none; border: 0; padding: 2px 5px; margin-left: 6px; cursor: pointer; border-radius: 4px; }
.agv-engine__status-x:hover { color: var(--ar-ink); background: var(--ar-surface-2); }
.agv-engine__help { color: var(--ar-accent); text-decoration: none; }
/* The dialog body: preserve the provider's line breaks, wrap long URLs, keep links teal. */
.agv-err__msg { margin: 0; font-size: 13.5px; line-height: 1.6; color: var(--ar-ink); white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word; }
.agv-err__msg a { color: var(--ar-accent); }
/* Keep the three footer buttons on one line at their natural width (never shrink
   below their label, which is what made them wrap). Let them wrap as a group on
   a truly narrow panel instead. */
.agv-err__actions { flex-wrap: wrap; }
.agv-err__actions .ar-btn { flex: 0 0 auto; white-space: nowrap; }
@media (max-width: 1024px) {
  .agv-engine--head { display: none; }
  .agv-engine { grid-template-columns: 1fr; gap: 8px; padding: 14px 0; }
  .agv-engine__status { white-space: normal; }
}

</style>
