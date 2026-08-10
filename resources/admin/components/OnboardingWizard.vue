<script>
import TagInput from './TagInput.vue';

/**
 * Setup wizard. Two entry points share these screens:
 *   • first run  — a grand welcome → 3 short steps → a celebration on finish;
 *   • "Review setup" (returning) — straight into the steps, pre-filled, copy
 *     reworded for review, and no welcome/celebration.
 * Keeps its own working state (never mutates `settings`) so nothing autosaves
 * mid-wizard. The parent does the single save and flips `celebrate` on first-run
 * success so we can show the "done" view.
 */
export default {
  name: 'OnboardingWizard',
  components: { TagInput },
  props: {
    open: { type: Boolean, default: false },
    settings: { type: Object, default: () => ({}) },
    entityTypes: { type: Array, default: () => ['Person', 'Organization'] },
    postTypes: { type: Array, default: () => [] },
    saving: { type: Boolean, default: false },
    returning: { type: Boolean, default: false }, // "Review setup" entry, not first run
    seo: { type: Object, default: () => ({ mode: 'solo', plugin: null }) }, // {mode, plugin} from SeoContext
    celebrate: { type: Boolean, default: false },  // parent signals a first-run save succeeded
    links: { type: Object, default: () => ({}) },  // { llms, discovery } — the done screen's live URLs
    score: { type: Object, default: null },        // the save's fresh AEO score report, for the done screen
    // The proof screen's Ask-AI links ({ assistants: [{label, href, icon, agents}], public })
    // and the REST client it polls the request log with.
    askAi: { type: Object, default: () => ({ assistants: [], public: true }) },
    api: { type: Object, default: null },
    // false = WordPress's "Discourage search engines" is on; the review step
    // then offers the guided one-tick fix instead of leaving it for later.
    blogPublic: { type: Boolean, default: true },
    // Prefill suggestions from what WordPress already knows ({ about: tagline }) —
    // a new owner edits a suggestion instead of staring at a blank field.
    suggest: { type: Object, default: () => ({}) },
  },
  emits: ['finish', 'skip', 'done', 'navigate', 'revisit'],
  data() {
    return {
      step: 1,
      totalSteps: 5,
      started: false, // false → show the welcome screen first (first run only)
      entityType: 'Person',
      name: '',
      role: '',
      about: '',
      audience: '',
      urls: [], // profile URLs → identity.same_as
      services: [], // {name, description, url} rows → identity.services (Schema.org Service)
      expertise: [],
      types: [],
      // The Content-Signal stance, captured in Step 2 (search / cite / train). Working
      // copy seeded from saved settings; emitted on finish. Default = privacy-first:
      // findable + citable, training reserved.
      signal: { search: true, ai_input: true, ai_train: false },
      makePublic: false, // the review step's guided search-visibility fix
      showAllTypes: false, // step 2's "Show N more" fold for plugin-heavy sites
      confetti: [],
      // Whether THIS finish applied the search-visibility fix — captured at
      // save time, because the blogPublic prop heals to true right after and
      // the celebration's tick would otherwise vanish before it was read.
      appliedPublicFix: false,
      // The proof screen: ask an assistant, then watch the site's own request log
      // for the visit. pick → watching → success | timeout (the non-public branch
      // renders off askAi.public instead).
      proof: false,
      proofState: 'pick',
      proofAssistant: null, // the clicked assistants[] entry
      proofRow: null,       // the matched log row
      proofSeconds: 0,      // elapsed watch time; also the honest "N seconds ago"
      proofFoundAfter: 0,
    };
  },
  computed: {
    // Which screen: the proof (entered from the celebration) wins, then the
    // celebration, then welcome (until "Get started"), else the step form.
    view() {
      if (this.proof) return 'proof';
      if (this.celebrate) return 'done';
      return this.started ? 'form' : 'welcome';
    },
    // Proof is only offered when assistants can actually reach the site and
    // there's a client to poll with — a local install or a site that blocks
    // every reader gets the plain finish, never a screen about what can't work.
    canProve() {
      return !!this.api && !!this.askAi.public && (this.askAi.assistants || []).length > 0;
    },
    siteHost() {
      return this.bare(this.links.llms).replace(/\/llms\.txt$/, '');
    },
    proofAgoLabel() {
      const s = Math.max(1, this.proofFoundAfter);
      if (s < 120) return `${s} second${s === 1 ? '' : 's'} ago`;
      return `${Math.round(s / 60)} minutes ago`;
    },
    watchClock() {
      return `${Math.floor(this.proofSeconds / 60)}:${String(this.proofSeconds % 60).padStart(2, '0')}`;
    },
    // The stepper rail's five stations — labels stay one word, descriptions a
    // few, so five columns share the panel without crushing.
    stepMeta() {
      return [
        { label: 'Who', desc: 'You & your site' },
        { label: 'Details', desc: 'Trust signals' },
        { label: 'Offer', desc: 'Your services' },
        { label: 'Reading', desc: 'What AI reads' },
        { label: 'Review', desc: 'Check & finish' },
      ];
    },
    // The proof states that read like forms get the modal's fixed, bordered
    // header; the ceremonial ones (watching, success) stay centered and bare,
    // like the celebration itself.
    proofHeadTitle() {
      if (!this.askAi.public) return 'This part needs a live site';
      if (this.proofState === 'pick') return "Now let's prove it";
      if (this.proofState === 'timeout') return 'Nothing arrived yet';
      return '';
    },
    isOrg() {
      return this.entityType !== 'Person';
    },
    namePlaceholder() {
      return this.isOrg ? 'Acme Inc.' : 'Jane Doe';
    },
    aboutPlaceholder() {
      return this.isOrg
        ? 'One sentence on what your organization does.'
        : 'One sentence on who you are and what you do.';
    },
    // Step-1 copy depends on the entry point so a returning admin isn't greeted
    // like a first-timer.
    introTitle() {
      return this.returning ? 'Review your setup' : 'Make your site readable by AI assistants';
    },
    introLead() {
      return this.returning
        ? 'Your current answers are filled in. Update anything and save — or step through to double-check what assistants see.'
        : "Agentimus helps assistants like ChatGPT and Claude understand and cite your site correctly. First, tell them who's behind it — the single most useful thing you can add.";
    },
    summaryTitle() {
      return this.returning ? 'Review & save' : "All set — here's the summary";
    },
    finishLabel() {
      if (this.saving) return this.returning ? 'Saving…' : 'Finishing…';
      return this.returning ? 'Save changes' : 'Finish setup';
    },
    baseTypes() {
      return this.postTypes.filter((p) => ['post', 'page'].includes(p.slug));
    },
    extraTypes() {
      return this.postTypes.filter((p) => !['post', 'page'].includes(p.slug));
    },
    // A plugin-heavy site can register a dozen public types; the wizard shows
    // four and folds the rest behind "Show N more" so step 2 stays one screen.
    visibleExtraTypes() {
      return this.showAllTypes ? this.extraTypes : this.extraTypes.slice(0, 4);
    },
    hiddenTypeCount() {
      return this.extraTypes.length - 4;
    },
    selectedLabels() {
      return this.postTypes.filter((p) => this.types.includes(p.slug)).map((p) => p.label);
    },
    // Plain-language reassurance for the summary step: the strong protections that
    // are on automatically, so the admin SEES the value without configuring it.
    protections() {
      const s = this.settings || {};
      const list = ['Discoverable by AI assistants — page guide, plain-text pages and rich data'];
      // The guided fix, reflected where the owner reads what finishing will do.
      if (!this.blogPublic && this.makePublic) {
        list.unshift('Search engine visibility switched on — search engines and AI assistants are welcome');
      }
      // The mode line ({mode, plugin} from SeoContext): solo sites hear the
      // claim outright; suite-run sites hear the division of labour instead.
      if (this.seo.mode === 'coexist') {
        const plugin = this.seo.plugin ? this.seo.plugin.label : 'your SEO plugin';
        list.push(`Working alongside ${plugin} — it keeps search SEO, Agentimus adds the AI layer`);
      } else if (s.enable_seo_titles !== false || s.enable_social_cards !== false || s.enable_canonicals !== false) {
        list.push('Search basics covered — SEO titles, share cards, canonical links and a sitemap, no SEO plugin needed');
      }
      if (s.enable_signing !== false) list.push('Signed responses, so assistants can verify they’re really from you');
      // Reads the in-wizard stance (this.signal), not saved settings, so the summary
      // reflects the choice the owner just made in Step 2 before it's saved.
      if (!this.signal.ai_train) list.push('Your content is reserved from AI training');
      if (s.enable_ai_header !== false || s.enable_tdmrep !== false) list.push('Your AI-usage choices stated everywhere agents look');
      return list;
    },
  },
  created() {
    this.applyInitial();
    this.started = this.returning;
  },
  watch: {
    open(val) {
      // Re-seed from current settings each time the wizard opens (so "Review
      // setup" reflects what's already saved), pick the right starting screen,
      // and focus the dialog.
      if (val) {
        this.applyInitial();
        this.started = this.returning;
        this.confetti = [];
        this.resetProof();
        this.$nextTick(() => {
          if (this.$refs.panel) this.$refs.panel.focus();
        });
      } else {
        this.stopProofTimers();
      }
    },
    celebrate(val) {
      if (val) {
        this.makeConfetti();
        this.$nextTick(() => {
          if (this.$refs.panel) this.$refs.panel.focus();
        });
      }
    },
  },
  beforeUnmount() {
    this.stopProofTimers();
    clearTimeout(this._confettiTimer);
  },
  methods: {
    applyInitial() {
      const id = (this.settings && this.settings.identity) || {};
      const avail = this.postTypes.map((p) => p.slug);
      const safe = ['post', 'page'].filter((s) => avail.includes(s));
      this.step = 1;
      this.entityType = id.entity_type || 'Person';
      this.name = id.name || '';
      this.role = id.role || '';
      // Suggestion, not silence: an empty about starts as the site's own
      // tagline (when it says something real), editable like anything typed.
      this.about = id.about || (this.suggest && this.suggest.about) || '';
      this.audience = id.audience || '';
      this.urls = Array.isArray(id.same_as) ? id.same_as.slice() : [];
      this.expertise = Array.isArray(id.expertise) ? id.expertise.slice() : [];
      this.services = Array.isArray(id.services)
        ? id.services.map((s) => ({ name: s.name || '', description: s.description || '', url: s.url || '' }))
        : [];
      // Privacy-safe default: posts + pages pre-selected, everything else opt-in.
      // On a site with neither, fall back to whatever content it actually has.
      this.types = safe.length ? safe : avail.slice();
      this.showAllTypes = false;
      // The guided fix defaults to ON for first-run: the wizard's job is to
      // land the owner on a working site, not to hand out homework. On a local
      // site the flip costs nothing (nothing can reach it) and prevents the
      // classic launch-day leftover. Only the returning "Review setup" entry
      // leaves it unticked — a routine re-save must not silently change a core
      // option.
      this.makePublic = !this.blogPublic && !this.returning;
      const cs = (this.settings && this.settings.content_signal) || {};
      this.signal = {
        search: cs.search !== false,
        ai_input: cs.ai_input !== false,
        ai_train: !!cs.ai_train,
      };
    },
    getStarted() {
      this.started = true;
    },
    // Live URLs display without their scheme — "yoursite.com/llms.txt" reads as
    // an address on YOUR OWN site, which is what makes the moment land.
    bare(url) {
      return String(url || '').replace(/^https?:\/\//, '');
    },
    /* ---- The proof screen ------------------------------------------------ */
    resetProof() {
      this.stopProofTimers();
      this.proof = false;
      this.proofState = 'pick';
      this.proofAssistant = null;
      this.proofRow = null;
      this.proofSeconds = 0;
      this.proofFoundAfter = 0;
      this.appliedPublicFix = false;
      this._proofBaseline = '';
    },
    stopProofTimers() {
      clearInterval(this._proofClock);
      clearInterval(this._proofPoll);
      this._proofClock = null;
      this._proofPoll = null;
    },
    startProof() {
      this.proof = true;
      this.proofState = 'pick';
      // Baseline BEFORE any button is clicked: everything at or before the
      // newest current row is history, not the visit we're about to cause.
      // Server timestamps on both sides, so client clock skew can't lie.
      if (this.askAi.public && this.api) {
        this.api.getActivityLog({ per_page: 1 })
          .then((res) => {
            const rows = (res && res.rows) || [];
            this._proofBaseline = rows.length ? String(rows[0].at || '') : '';
          })
          .catch(() => { this._proofBaseline = ''; });
      }
    },
    askAssistant(a) {
      window.open(a.href, '_blank', 'noopener');
      this.proofAssistant = a;
      this.proofState = 'watching';
      this.proofSeconds = 0;
      this.stopProofTimers();
      this._proofClock = setInterval(() => {
        this.proofSeconds += 1;
        // Honesty deadline: after 90s of silence, stop promising and explain.
        // Polling continues — a late visit still flips this to success.
        if (this.proofSeconds >= 90 && this.proofState === 'watching') {
          this.proofState = 'timeout';
        }
      }, 1000);
      this._proofPoll = setInterval(() => this.pollProof(), 4000);
      this.pollProof();
    },
    pollProof() {
      if (!this.api || !this.proofAssistant) return;
      this.api.getActivityLog({ per_page: 10 })
        .then((res) => {
          const rows = (res && res.rows) || [];
          const hit = rows.find((r) => this.isProofRow(r));
          if (hit && (this.proofState === 'watching' || this.proofState === 'timeout')) {
            this.proofRow = hit;
            this.proofFoundAfter = this.proofSeconds;
            this.proofState = 'success';
            this.stopProofTimers();
          }
        })
        .catch(() => {}); // A failed poll is silence, not an error screen — the next tick retries.
    },
    // Only a NEW row from the assistant the owner actually asked counts as
    // proof. A coincidental crawler arriving mid-watch must not claim it.
    isProofRow(r) {
      if (this._proofBaseline && String(r.at || '') <= this._proofBaseline) return false;
      const a = this.proofAssistant;
      const hay = `${r.ua || ''} ${r.agent || ''}`.toLowerCase();
      if ((a.agents || []).some((t) => hay.includes(String(t).toLowerCase()))) return true;
      return String(r.agent || '').toLowerCase() === String(a.label).toLowerCase();
    },
    addService() {
      this.services.push({ name: '', description: '', url: '' });
    },
    removeService(i) {
      this.services.splice(i, 1);
    },
    tryAnotherAssistant() {
      this.stopProofTimers();
      this.proofAssistant = null;
      this.proofState = 'pick';
    },
    // Back from the proof's first screen: return to the celebration.
    backToDone() {
      this.stopProofTimers();
      this.proofAssistant = null;
      this.proofState = 'pick';
      this.proof = false;
    },
    finishProof() {
      this.stopProofTimers();
      this.$emit('done');
    },
    isTypeOn(slug) {
      return this.types.includes(slug);
    },
    toggleType(slug) {
      const i = this.types.indexOf(slug);
      if (i === -1) this.types.push(slug);
      else this.types.splice(i, 1);
    },
    next() {
      if (this.step < this.totalSteps) this.step += 1;
    },
    back() {
      if (this.step > 1) this.step -= 1;
    },
    finish() {
      if (this.saving) return;
      this.appliedPublicFix = !this.blogPublic && !!this.makePublic;
      this.$emit('finish', {
        entity_type: this.entityType,
        name: this.name,
        role: this.isOrg ? '' : this.role,
        about: this.about,
        audience: this.audience,
        same_as: this.urls,
        services: this.services, // the server keeps rows with a name, drops the rest
        expertise: this.expertise,
        types: this.types,
        content_signal: {
          search: !!this.signal.search,
          ai_input: !!this.signal.ai_input,
          ai_train: !!this.signal.ai_train,
        },
        make_public: !this.blogPublic && !!this.makePublic,
      });
    },
    // One-shot confetti for the celebration. Skipped entirely under
    // reduced-motion — the check + message carry the moment on their own.
    makeConfetti() {
      const reduce = typeof window !== 'undefined'
        && window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) {
        this.confetti = [];
        return;
      }
      const colors = ['#146b64', '#2f7a4c', '#ad7b18', '#b93c2b', '#d98a2b'];
      const pieces = [];
      for (let i = 0; i < 22; i++) {
        pieces.push({
          id: i,
          style: {
            left: Math.round(Math.random() * 100) + '%',
            background: colors[i % colors.length],
            animationDelay: Math.round(Math.random() * 220) + 'ms',
            animationDuration: 900 + Math.round(Math.random() * 500) + 'ms',
          },
        });
      }
      this.confetti = pieces;
      // One-shot: drop the pieces once they've fallen. The celebration screen
      // remounts every time the owner steps Back to it from the proof, and
      // mounted spans restart their CSS animations — confetti marks the SAVE,
      // not every visit to the screen.
      clearTimeout(this._confettiTimer);
      this._confettiTimer = setTimeout(() => { this.confetti = []; }, 2000);
    },
  },
};
</script>

<template>
  <Teleport to="body">
    <transition name="ar-modal" appear>
      <div v-if="open" class="ar-modal ar-modal--wiz">
        <div ref="panel" class="ar-modal__panel ar-wiz" role="dialog" aria-modal="true" aria-labelledby="ar-wiz-title" tabindex="-1">

          <!-- The stepper rail: numbered stations joined by a line, ✓ once passed. -->
          <div v-if="view === 'form'" class="ar-modal__head">
            <ol class="ar-stepper" :aria-label="`Step ${step} of ${totalSteps}`">
              <li
                v-for="(s, i) in stepMeta"
                :key="s.label"
                :class="{ 'is-done': step > i + 1, 'is-now': step === i + 1 }"
                :aria-current="step === i + 1 ? 'step' : null"
              >
                <span class="ar-stepper__dot" aria-hidden="true">
                  <svg v-if="step > i + 1" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7" /></svg>
                  <template v-else>{{ i + 1 }}</template>
                </span>
                <span class="ar-stepper__label">{{ s.label }}</span>
                <span class="ar-stepper__desc">{{ s.desc }}</span>
              </li>
            </ol>
          </div>
          <!-- Form-like proof states carry their title as a fixed, bordered head. -->
          <div v-else-if="view === 'proof' && proofHeadTitle" class="ar-modal__head">
            <h2 id="ar-wiz-title" class="ar-modal__title ar-wiz__proofttl">{{ proofHeadTitle }}</h2>
          </div>

          <div class="ar-modal__body">
            <div class="ar-modal__scroll">

              <!-- WELCOME (first run only) -->
              <div v-if="view === 'welcome'" class="ar-wiz__welcome">
                <!-- The plugin's own mark, not a generic glyph — the same "A"
                     the header brand tile wears. -->
                <div class="ar-wiz__welcome-mark ar-wiz__welcome-mark--brand" aria-hidden="true">
                  <svg class="ar__logo" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path class="ar__logo-line" d="M4.5 20.5 L12 3.5 L19.5 20.5" />
                    <path class="ar__logo-accent" d="M8 13.6 H16" />
                  </svg>
                </div>
                <h2 id="ar-wiz-title" class="ar-wiz__welcome-title">Welcome to Agentimus</h2>
                <p class="ar-wiz__welcome-lead">
                  Let’s make your site easy for AI assistants like ChatGPT and Claude to understand
                  and cite — correctly, and in your words. A couple of minutes of questions, and
                  everything can change later. Here’s what Agentimus runs for you from today:
                </p>
                <!-- The snapshot: the whole plugin in five plain lines, so a new
                     owner knows what they now have before they answer anything. -->
                <ul class="ar-wiz__welcome-points">
                  <li><strong>A machine edition of your site</strong> — llms.txt and plain-text pages, live automatically</li>
                  <li><strong>A readiness score</strong> — what AI can see and what to fix, in plain words</li>
                  <li><strong>A request log</strong> — every AI visit to your site, named</li>
                  <li><strong>Your rules, enforced</strong> — who may read you, who may train on you</li>
                  <li v-if="askAi.public"><strong>Proof, not promise</strong> — watch a real assistant read your site at the end</li>
                  <li v-else><strong>Assistants can work your site</strong> — with your permission, every action recorded</li>
                </ul>
              </div>

              <!-- FORM (steps 1–3) -->
              <template v-else-if="view === 'form'">
                <!-- Step 1 — identity -->
                <div v-if="step === 1" class="ar-wiz__step">
                  <h2 id="ar-wiz-title" class="ar-modal__title">{{ introTitle }}</h2>
                  <p class="ar-modal__lead">{{ introLead }}</p>

                  <div class="ar-field">
                    <label for="ar-wiz-type">This site represents</label>
                    <select id="ar-wiz-type" v-model="entityType" class="ar-input">
                      <option value="Person">A person</option>
                      <option value="Organization">An organization</option>
                    </select>
                  </div>

                  <div class="ar-field">
                    <label for="ar-wiz-name">Name</label>
                    <input id="ar-wiz-name" v-model="name" type="text" class="ar-input" :placeholder="namePlaceholder" />
                  </div>

                  <div v-if="!isOrg" class="ar-field">
                    <label for="ar-wiz-role">Your role or title <span class="ar-field__tag">optional</span></label>
                    <input id="ar-wiz-role" v-model="role" type="text" class="ar-input" placeholder="e.g. WordPress developer" />
                    <small class="ar-field__hint">How engines say what you do — it becomes your schema jobTitle.</small>
                  </div>

                  <div class="ar-field">
                    <label for="ar-wiz-about">What is this site about?</label>
                    <textarea id="ar-wiz-about" v-model="about" class="ar-input" rows="3" :placeholder="aboutPlaceholder"></textarea>
                    <small class="ar-field__hint">One plain sentence. Assistants quote this when they mention you.</small>
                  </div>
                </div>

                <!-- Step 2 — trust details: everything readiness will later ask
                     for, collected here instead of as post-setup homework. All
                     optional, each explains what it earns. -->
                <div v-else-if="step === 2" class="ar-wiz__step">
                  <h2 class="ar-modal__title">Help AI trust and describe you</h2>
                  <p class="ar-modal__lead">
                    All of this is optional — but each answer makes AI answers about you sharper.
                    Skip anything; it lives under Settings → Identity later.
                  </p>

                  <div class="ar-field">
                    <label>Topics you cover</label>
                    <TagInput v-model="expertise" placeholder="e.g. WordPress tutorials — press Enter to add" />
                    <small class="ar-field__hint">3–5 is plenty. They go into your llms.txt and your schema’s knowsAbout.</small>
                  </div>

                  <div class="ar-field">
                    <label>Your profiles elsewhere</label>
                    <TagInput v-model="urls" placeholder="e.g. https://github.com/you — press Enter to add" />
                    <small class="ar-field__hint">LinkedIn, GitHub, X, Wikipedia… Assistants use these to confirm you are who you say.</small>
                  </div>

                  <div class="ar-field">
                    <label for="ar-wiz-audience">Who is this site for?</label>
                    <input id="ar-wiz-audience" v-model="audience" type="text" class="ar-input" placeholder="e.g. Developers and fragrance lovers" />
                    <small class="ar-field__hint">Helps assistants recommend you to the right people.</small>
                  </div>
                </div>

                <!-- Step 3 — services: the "what does this site offer?" answer.
                     A repeater that starts empty, so skipping is doing nothing. -->
                <div v-else-if="step === 3" class="ar-wiz__step">
                  <h2 class="ar-modal__title">What do you offer?</h2>
                  <p class="ar-modal__lead">
                    Anything people can hire you for or buy from you. Each one becomes a
                    Schema.org <code>Service</code> naming you as the provider, so assistants
                    can answer “what does this site offer?”. Don’t sell anything? Just
                    continue — this is fully optional.
                  </p>
                  <div v-for="(svc, i) in services" :key="i" class="ar-wiz__svc">
                    <div class="ar-wiz__svc-head">
                      <span class="ar-wiz__subhead">Service {{ i + 1 }}</span>
                      <button type="button" class="ar-linkbtn" @click="removeService(i)">Remove</button>
                    </div>
                    <div class="ar-field">
                      <label :for="'ar-wiz-svc-name-' + i">Name</label>
                      <input :id="'ar-wiz-svc-name-' + i" v-model="svc.name" type="text" class="ar-input" placeholder="e.g. WordPress plugin development" />
                    </div>
                    <div class="ar-field">
                      <label :for="'ar-wiz-svc-desc-' + i">One line about it <span class="ar-field__tag">optional</span></label>
                      <input :id="'ar-wiz-svc-desc-' + i" v-model="svc.description" type="text" class="ar-input" placeholder="e.g. Custom plugins, from idea to release" />
                    </div>
                    <div class="ar-field">
                      <label :for="'ar-wiz-svc-url-' + i">Link to it <span class="ar-field__tag">optional</span></label>
                      <input :id="'ar-wiz-svc-url-' + i" v-model="svc.url" type="url" class="ar-input" placeholder="e.g. https://yoursite.com/services" />
                    </div>
                  </div>
                  <button type="button" class="ar-linkbtn ar-wiz__moretypes" @click="addService">+ Add a service</button>
                  <p v-if="!services.length" class="ar-card__note">
                    Nothing to add is a fine answer — most blogs skip this and continue.
                  </p>
                </div>

                <!-- Step 4 — what can AI read -->
                <div v-else-if="step === 4" class="ar-wiz__step">
                  <h2 class="ar-modal__title">What can AI assistants read?</h2>
                  <p class="ar-modal__lead">
                    Posts and pages are included by default. Turn on anything else you'd like assistants
                    to read — and leave private things (orders, form entries, customer data) off.
                  </p>

                  <div v-if="baseTypes.length" class="ar-types-grid">
                    <label
                      v-for="pt in baseTypes"
                      :key="pt.slug"
                      class="ar-type"
                      :class="{ 'is-on': isTypeOn(pt.slug) }"
                    >
                      <input type="checkbox" :checked="isTypeOn(pt.slug)" @change="toggleType(pt.slug)" />
                      <span class="ar-type__check" aria-hidden="true"></span>
                      <span class="ar-type__body">
                        <span class="ar-type__label">{{ pt.label }}</span>
                        <span class="ar-type__meta"><span class="ar-type__src">recommended</span></span>
                      </span>
                    </label>
                  </div>

                  <template v-if="extraTypes.length">
                    <p class="ar-wiz__subhead">Other content on your site</p>
                    <div class="ar-types-grid">
                      <label
                        v-for="pt in visibleExtraTypes"
                        :key="pt.slug"
                        class="ar-type"
                        :class="{ 'is-on': isTypeOn(pt.slug) }"
                      >
                        <input type="checkbox" :checked="isTypeOn(pt.slug)" @change="toggleType(pt.slug)" />
                        <span class="ar-type__check" aria-hidden="true"></span>
                        <span class="ar-type__body">
                          <span class="ar-type__label">{{ pt.label }}</span>
                          <span v-if="pt.source" class="ar-type__meta"><span class="ar-type__src">{{ pt.source }}</span></span>
                        </span>
                      </label>
                    </div>
                    <button
                      v-if="!showAllTypes && hiddenTypeCount > 0"
                      type="button"
                      class="ar-linkbtn ar-wiz__moretypes"
                      @click="showAllTypes = true"
                    >Show {{ hiddenTypeCount }} more</button>
                  </template>

                  <p class="ar-card__note">
                    This only controls what's <strong>advertised</strong> to assistants — it doesn't make
                    anything public that wasn't already. You can change it any time in Settings.
                  </p>

                  <p class="ar-wiz__subhead">What may they do with it?</p>
                  <div class="ar-types-grid">
                    <label class="ar-type" :class="{ 'is-on': signal.search }">
                      <input type="checkbox" v-model="signal.search" />
                      <span class="ar-type__check" aria-hidden="true"></span>
                      <span class="ar-type__body">
                        <span class="ar-type__label">Show me in search engines</span>
                        <span class="ar-type__meta"><span class="ar-type__src">Google, Bing…</span></span>
                      </span>
                    </label>
                    <label class="ar-type" :class="{ 'is-on': signal.ai_input }">
                      <input type="checkbox" v-model="signal.ai_input" />
                      <span class="ar-type__check" aria-hidden="true"></span>
                      <span class="ar-type__body">
                        <span class="ar-type__label">Read &amp; cite me in answers</span>
                        <span class="ar-type__meta"><span class="ar-type__src">with attribution</span></span>
                      </span>
                    </label>
                    <label class="ar-type" :class="{ 'is-on': signal.ai_train }">
                      <input type="checkbox" v-model="signal.ai_train" />
                      <span class="ar-type__check" aria-hidden="true"></span>
                      <span class="ar-type__body">
                        <span class="ar-type__label">Use my content to train AI</span>
                        <span class="ar-type__meta"><span class="ar-type__src">off by default</span></span>
                      </span>
                    </label>
                  </div>
                  <p class="ar-card__note">
                    Stated in your <code>robots.txt</code> as a Content-Signal — a polite request well-behaved
                    crawlers honour.
                  </p>
                </div>

                <!-- Step 3 — review -->
                <div v-else class="ar-wiz__step">
                  <h2 class="ar-modal__title">{{ summaryTitle }}</h2>
                  <p class="ar-modal__lead">
                    Agentimus will use this to describe your site to AI assistants. You can fine-tune
                    everything later in Settings.
                  </p>

                  <div class="ar-preview">
                    <div class="ar-preview__group">
                      <p class="ar-preview__label">Who</p>
                      <ul class="ar-preview__list">
                        <li><span>{{ isOrg ? 'Organization' : 'Person' }}</span><span class="ar-preview__muted">{{ name || '—' }}</span></li>
                        <li v-if="!isOrg"><span>Role</span><span class="ar-preview__muted">{{ role || 'not set' }}</span></li>
                        <li><span>About</span><span class="ar-preview__muted">{{ about ? 'set' : 'not set' }}</span></li>
                        <li><span>Topics</span><span class="ar-preview__muted">{{ expertise.length }}</span></li>
                        <li><span>Profiles</span><span class="ar-preview__muted">{{ urls.length }}</span></li>
                        <li><span>Services</span><span class="ar-preview__muted">{{ services.filter((s) => s.name.trim()).length || 'none' }}</span></li>
                      </ul>
                    </div>
                    <div class="ar-preview__group">
                      <p class="ar-preview__label">AI assistants can read</p>
                      <ul class="ar-preview__list">
                        <li><span>Content</span><span class="ar-preview__muted">{{ selectedLabels.length ? selectedLabels.join(', ') : 'nothing selected' }}</span></li>
                      </ul>
                    </div>
                    <div class="ar-preview__group">
                      <p class="ar-preview__label">Working automatically — nothing to configure</p>
                      <ul class="ar-wiz__protect">
                        <li v-for="p in protections" :key="p">{{ p }}</li>
                      </ul>
                    </div>
                  </div>

                  <!-- The guided fix for WordPress's own master switch: warn AND
                       offer the one-tick cure, never just homework for later. -->
                  <template v-if="!blogPublic">
                    <p class="ar-wiz__subhead">One WordPress setting is in the way</p>
                    <div class="ar-types-grid">
                      <label class="ar-type" :class="{ 'is-on': makePublic }">
                        <input type="checkbox" v-model="makePublic" />
                        <span class="ar-type__check" aria-hidden="true"></span>
                        <span class="ar-type__body">
                          <span class="ar-type__label">Switch on Search engine visibility</span>
                          <span class="ar-type__meta"><span class="ar-type__src">Settings → Reading</span></span>
                        </span>
                      </label>
                    </div>
                    <p class="ar-card__note">
                      WordPress is asking search engines to stay away (“Discourage search engines”
                      is checked) — and that tells AI assistants your site is closed, too.
                      Finishing setup switches it on for you; untick to leave it as it
                      is.<template v-if="!askAi.public"> Your site is local, so this changes
                      nothing today — it just saves the classic launch-day surprise.</template>
                    </p>
                  </template>

                  <p v-if="!about" class="ar-card__note ar-wiz__tip">
                    Tip: a one-sentence “about” (Step 1) is the highest-impact thing for how assistants describe you.
                  </p>
                </div>
              </template>

              <!-- DONE (first-run celebration): the site speaking machine, SHOWN.
                   The just-published llms.txt with the owner's own words marked —
                   then the handoff to the proof. -->
              <div v-else-if="view === 'done'" class="ar-wiz__done">
                <div class="ar-wiz__confetti" aria-hidden="true">
                  <span v-for="c in confetti" :key="c.id" :style="c.style"></span>
                </div>
                <div class="ar-wiz__done-check" aria-hidden="true">
                  <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7" /></svg>
                </div>
                <h2 id="ar-wiz-title" class="ar-wiz__done-title">Your site speaks machine now</h2>
                <p class="ar-wiz__done-lead">
                  All of this switched on the moment you clicked Finish — live right now:
                </p>
                <!-- Real accomplishments only: each tick is verifiable, and the
                     addresses are the actual live files — click one and see. -->
                <!-- One line per tick — the copy is sized to fit, not clipped. -->
                <ul class="ar-wiz__protect ar-wiz__protect--live">
                  <li v-if="links.llms">
                    <span><a :href="links.llms" target="_blank" rel="noopener"><code>{{ bare(links.llms) }}</code></a> — the guide AI assistants read first</span>
                  </li>
                  <li v-if="links.discovery">
                    <span><a :href="links.discovery" target="_blank" rel="noopener"><code>{{ bare(links.discovery) }}</code></a> — your machine-readable card</span>
                  </li>
                  <li><span>Every post has a plain-text edition — add <code>.md</code> to its address</span></li>
                  <li><span>Your AI rules are stated everywhere crawlers look</span></li>
                  <li v-if="appliedPublicFix"><span>Search engine visibility is on — AI and search engines welcome</span></li>
                </ul>
              </div>

              <!-- PROOF: ask a real assistant, then watch the site's own request
                   log for the visit. The wow is the owner's own log line. -->
              <div v-else class="ar-wiz__step" :class="{ 'ar-wiz__proof': !proofHeadTitle }">

                <!-- Non-public site, detected up front: no spinner that can't end. -->
                <template v-if="!askAi.public">
                  <p class="ar-modal__lead">
                    Your site runs at <code>{{ siteHost }}</code> — an address only this computer
                    can reach, so AI assistants can't visit it. Everything you set up still works;
                    there's just no visit to watch. On your live site, this screen proves the
                    whole thing in under a minute.
                  </p>
                  <p class="ar-card__note">
                    Everything above is saved. The Request Log starts recording the moment real
                    traffic can reach you.
                  </p>
                </template>

                <!-- PICK an assistant -->
                <template v-else-if="proofState === 'pick'">
                  <p class="ar-modal__lead">
                    Open an assistant and ask it about your site — the question is already filled
                    in. When it visits, you'll see the visit land in your own request log, live,
                    on this screen.
                  </p>
                  <div class="ar-ask-grid">
                    <button v-for="a in askAi.assistants" :key="a.label" type="button" class="ar-ask" @click="askAssistant(a)">
                      <span class="ar-ask__mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false"><path :d="a.icon" fill="currentColor" /></svg>
                      </span>
                      <span class="ar-ask__body">
                        <span class="ar-ask__name">{{ a.label }}</span>
                        <span class="ar-ask__sub">opens with your question ready</span>
                      </span>
                    </button>
                  </div>
                  <p class="ar-card__note">
                    Nothing to install and nothing shared — the question simply includes your
                    address, <code>{{ siteHost }}</code>.
                  </p>
                </template>

                <!-- WATCHING the log -->
                <div v-else-if="proofState === 'watching'" class="ar-watch">
                  <div class="ar-watch__pulse" aria-hidden="true"></div>
                  <h2 id="ar-wiz-title" class="ar-watch__title">Watching your request log…</h2>
                  <p class="ar-watch__clock">{{ watchClock }}</p>
                  <p class="ar-modal__lead ar-watch__lead">
                    Ask {{ proofAssistant ? proofAssistant.label : 'the assistant' }} to read
                    your site. The moment it does, its visit shows up right here.
                  </p>
                </div>

                <!-- SUCCESS: the owner's own log line -->
                <div v-else-if="proofState === 'success'" class="ar-wiz__done">
                  <h2 id="ar-wiz-title" class="ar-wiz__done-title">There it is.</h2>
                  <p class="ar-wiz__done-lead">
                    <strong>{{ proofRow.agent }}</strong> read your site {{ proofAgoLabel }}.
                    This is the line from your own request log:
                  </p>
                  <div class="ar-logline">
                    <span class="ar-logline__dot" aria-hidden="true"></span>
                    <span class="ar-logline__name">{{ proofRow.agent }}</span>
                    <span class="ar-logline__chip">{{ proofRow.endpoint }}</span>
                    <span v-if="proofRow.network" class="ar-logline__net">from {{ proofRow.network }}</span>
                    <span class="ar-logline__time">{{ proofAgoLabel }}</span>
                  </div>
                  <p class="ar-card__note ar-wiz__centernote">
                    From now on, every AI visit lands in this log — that's the Request Log tab.
                  </p>
                  <div class="ar-connect">
                    <div class="ar-connect__body">
                      <span class="ar-connect__title">Want ChatGPT to do more than read?</span>
                      <span class="ar-connect__sub">
                        Connect it to your site — one click, no keys. It can then check pages and
                        draft content, with your permission, and every action shows in Agent Access.
                      </span>
                    </div>
                    <button type="button" class="ar-btn ar-btn--ghost" @click="$emit('navigate', { tab: 'settings', anchor: 'ar-sec-mcp' })">Connect ChatGPT</button>
                  </div>
                </div>

                <!-- TIMEOUT: honest, plain words, firewall first -->
                <template v-else>
                  <p class="ar-modal__lead">
                    That can happen, and it usually isn't your site's fault. The likely reasons,
                    most common first:
                  </p>
                  <ol class="ar-reasons">
                    <li>
                      <span class="ar-reasons__n" aria-hidden="true">1</span>
                      <span><strong>A firewall turned the assistant away.</strong>
                      <small>If you use Cloudflare, its “Bot Fight Mode” is the usual cause — it
                      blocks the assistant's reader before your site ever sees it.</small></span>
                    </li>
                    <li>
                      <span class="ar-reasons__n" aria-hidden="true">2</span>
                      <span><strong>The assistant answered from memory.</strong>
                      <small>It already knew enough about your site and didn't visit this time.
                      Asking it to “read {{ siteHost }} and quote it” usually makes it fetch.</small></span>
                    </li>
                    <li>
                      <span class="ar-reasons__n" aria-hidden="true">3</span>
                      <span><strong>Some assistants are just slow.</strong>
                      <small>A visit can land minutes later — this screen keeps watching, and the
                      Request Log records it even after you close this.</small></span>
                    </li>
                  </ol>
                </template>
              </div>

            </div>
          </div>

          <div class="ar-modal__actions ar-wiz__actions">
            <template v-if="view === 'welcome'">
              <button type="button" class="ar-linkbtn ar-wiz__skip" @click="$emit('skip')">Skip for now</button>
              <button type="button" class="ar-btn" @click="getStarted">Get started</button>
            </template>
            <template v-else-if="view === 'form'">
              <button type="button" class="ar-linkbtn ar-wiz__skip" :disabled="saving" @click="$emit('skip')">Skip for now</button>
              <button v-if="step > 1" type="button" class="ar-btn ar-btn--ghost" :disabled="saving" @click="back">Back</button>
              <button v-if="step < totalSteps" type="button" class="ar-btn" @click="next">Continue</button>
              <button v-else type="button" class="ar-btn" :disabled="saving" @click="finish">{{ finishLabel }}</button>
            </template>
            <template v-else-if="view === 'done'">
              <template v-if="canProve">
                <button type="button" class="ar-linkbtn ar-wiz__skip" @click="$emit('done')">Go to dashboard</button>
                <button type="button" class="ar-btn ar-btn--ghost" @click="$emit('revisit')">Back</button>
                <button type="button" class="ar-btn" @click="startProof">Now let's prove it</button>
              </template>
              <template v-else>
                <button type="button" class="ar-btn ar-btn--ghost" @click="$emit('revisit')">Back</button>
                <button type="button" class="ar-btn" @click="$emit('done')">Go to dashboard</button>
              </template>
            </template>
            <template v-else>
              <template v-if="!askAi.public">
                <button type="button" class="ar-btn ar-btn--ghost" @click="backToDone">Back</button>
                <button type="button" class="ar-btn" @click="finishProof">Go to dashboard</button>
              </template>
              <template v-else-if="proofState === 'success'">
                <button type="button" class="ar-btn ar-wiz__done-btn" @click="finishProof">Go to dashboard</button>
              </template>
              <template v-else-if="proofState === 'timeout'">
                <button type="button" class="ar-linkbtn ar-wiz__skip" @click="finishProof">Skip this — go to dashboard</button>
                <button type="button" class="ar-btn ar-btn--ghost" @click="tryAnotherAssistant">Try another assistant</button>
              </template>
              <template v-else-if="proofState === 'watching'">
                <button type="button" class="ar-linkbtn ar-wiz__skip" @click="finishProof">Skip this — go to dashboard</button>
                <button type="button" class="ar-btn ar-btn--ghost" @click="tryAnotherAssistant">Back</button>
              </template>
              <template v-else>
                <button type="button" class="ar-linkbtn ar-wiz__skip" @click="finishProof">Skip this — go to dashboard</button>
                <button type="button" class="ar-btn ar-btn--ghost" @click="backToDone">Back</button>
              </template>
            </template>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>
