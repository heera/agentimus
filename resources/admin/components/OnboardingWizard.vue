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
  },
  emits: ['finish', 'skip', 'done', 'navigate'],
  data() {
    return {
      step: 1,
      totalSteps: 3,
      started: false, // false → show the welcome screen first (first run only)
      entityType: 'Person',
      name: '',
      about: '',
      expertise: [],
      types: [],
      // The Content-Signal stance, captured in Step 2 (search / cite / train). Working
      // copy seeded from saved settings; emitted on finish. Default = privacy-first:
      // findable + citable, training reserved.
      signal: { search: true, ai_input: true, ai_train: false },
      confetti: [],
    };
  },
  computed: {
    // Which screen: the celebration wins, then welcome (until "Get started"),
    // else the step form.
    view() {
      if (this.celebrate) return 'done';
      return this.started ? 'form' : 'welcome';
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
    // The done screen's score line — only when the save's fresh report has a number.
    doneScore() {
      return this.score && typeof this.score.score === 'number' ? this.score.score : null;
    },
    baseTypes() {
      return this.postTypes.filter((p) => ['post', 'page'].includes(p.slug));
    },
    extraTypes() {
      return this.postTypes.filter((p) => !['post', 'page'].includes(p.slug));
    },
    selectedLabels() {
      return this.postTypes.filter((p) => this.types.includes(p.slug)).map((p) => p.label);
    },
    // Plain-language reassurance for the summary step: the strong protections that
    // are on automatically, so the admin SEES the value without configuring it.
    protections() {
      const s = this.settings || {};
      const list = ['Discoverable by AI assistants — page guide, plain-text pages and rich data'];
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
        this.$nextTick(() => {
          if (this.$refs.panel) this.$refs.panel.focus();
        });
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
  methods: {
    applyInitial() {
      const id = (this.settings && this.settings.identity) || {};
      const avail = this.postTypes.map((p) => p.slug);
      const safe = ['post', 'page'].filter((s) => avail.includes(s));
      this.step = 1;
      this.entityType = id.entity_type || 'Person';
      this.name = id.name || '';
      this.about = id.about || '';
      this.expertise = Array.isArray(id.expertise) ? id.expertise.slice() : [];
      // Privacy-safe default: posts + pages pre-selected, everything else opt-in.
      // On a site with neither, fall back to whatever content it actually has.
      this.types = safe.length ? safe : avail.slice();
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
      this.$emit('finish', {
        entity_type: this.entityType,
        name: this.name,
        about: this.about,
        expertise: this.expertise,
        types: this.types,
        content_signal: {
          search: !!this.signal.search,
          ai_input: !!this.signal.ai_input,
          ai_train: !!this.signal.ai_train,
        },
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
    },
  },
};
</script>

<template>
  <Teleport to="body">
    <transition name="ar-modal" appear>
      <div v-if="open" class="ar-modal ar-modal--wiz">
        <div ref="panel" class="ar-modal__panel ar-wiz" role="dialog" aria-modal="true" aria-labelledby="ar-wiz-title" tabindex="-1">

          <!-- Step dots only during the form -->
          <div v-if="view === 'form'" class="ar-modal__head">
            <div class="ar-wiz__steps" aria-hidden="true">
              <span v-for="n in totalSteps" :key="n" class="ar-wiz__dot" :class="{ 'is-on': n <= step }"></span>
            </div>
            <p class="ar-wiz__count">Step {{ step }} of {{ totalSteps }}</p>
          </div>

          <div class="ar-modal__body">
            <div class="ar-modal__scroll">

              <!-- WELCOME (first run only) -->
              <div v-if="view === 'welcome'" class="ar-wiz__welcome">
                <div class="ar-wiz__welcome-mark" aria-hidden="true">
                  <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2.5v3.5M12 18v3.5M2.5 12h3.5M18 12h3.5" />
                    <path d="M12 7.5l1.7 2.8L16.5 12l-2.8 1.7L12 16.5l-1.7-2.8L7.5 12l2.8-1.7z" />
                  </svg>
                </div>
                <h2 id="ar-wiz-title" class="ar-wiz__welcome-title">Welcome to Agentimus</h2>
                <p class="ar-wiz__welcome-lead">
                  Let’s make your site easy for AI assistants like ChatGPT and Claude to understand and
                  cite — correctly, and in your words. It takes about a minute, and you can change
                  anything later.
                </p>
                <ul class="ar-wiz__welcome-points">
                  <li>Tell assistants who’s behind the site</li>
                  <li>Choose what they’re allowed to read</li>
                  <li>Strong protections switch on automatically</li>
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

                  <div class="ar-field">
                    <label for="ar-wiz-about">What is this site about?</label>
                    <textarea id="ar-wiz-about" v-model="about" class="ar-input" rows="3" :placeholder="aboutPlaceholder"></textarea>
                    <small class="ar-field__hint">One plain sentence. Assistants quote this when they mention you.</small>
                  </div>

                  <div class="ar-field">
                    <label>Topics you cover <span class="ar-field__tag">optional</span></label>
                    <TagInput v-model="expertise" placeholder="Add a topic, press Enter" />
                  </div>
                </div>

                <!-- Step 2 — what can AI read -->
                <div v-else-if="step === 2" class="ar-wiz__step">
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
                        v-for="pt in extraTypes"
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
                        <li><span>About</span><span class="ar-preview__muted">{{ about ? 'set' : 'not set' }}</span></li>
                        <li><span>Topics</span><span class="ar-preview__muted">{{ expertise.length }}</span></li>
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

                  <p v-if="!about" class="ar-card__note ar-warn">
                    Tip: a one-sentence “about” (Step 1) is the highest-impact thing for how assistants describe you.
                  </p>
                </div>
              </template>

              <!-- DONE (first-run celebration) -->
              <div v-else class="ar-wiz__done">
                <div class="ar-wiz__confetti" aria-hidden="true">
                  <span v-for="c in confetti" :key="c.id" :style="c.style"></span>
                </div>
                <div class="ar-wiz__done-check" aria-hidden="true">
                  <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7" /></svg>
                </div>
                <h2 id="ar-wiz-title" class="ar-wiz__done-title">You’re all set{{ name ? ', ' + name : '' }}!</h2>
                <p class="ar-wiz__done-lead">
                  Your site now speaks the language AI assistants understand — discoverable, signed and
                  verifiable, and on your terms.
                </p>
                <!-- The wow: real addresses that began serving the owner's own
                     words seconds ago — click one and see. The score row jumps
                     to the Readiness tab (and closes the wizard); the external
                     links open beside the modal so the moment isn't lost. -->
                <p class="ar-wiz__explore-label">Live on your site right now</p>
                <ul class="ar-wiz__explore">
                  <li v-if="links.llms">
                    <a :href="links.llms" target="_blank" rel="noopener">
                      <span class="ar-wiz__explore-name">{{ bare(links.llms) }}</span>
                      <span class="ar-wiz__explore-desc">The guide AI assistants read first — your name, your sentence and your topics are already in it</span>
                    </a>
                  </li>
                  <li v-if="links.discovery">
                    <a :href="links.discovery" target="_blank" rel="noopener">
                      <span class="ar-wiz__explore-name">{{ bare(links.discovery) }}</span>
                      <span class="ar-wiz__explore-desc">A machine-readable card telling AI agents what your site offers and your rules for using it</span>
                    </a>
                  </li>
                  <li v-if="doneScore !== null">
                    <button type="button" @click="$emit('navigate', 'readiness')">
                      <span class="ar-wiz__explore-name">AI readiness: {{ doneScore }}/100</span>
                      <span class="ar-wiz__explore-desc">Your starting score — the Readiness tab lists the moves that raise it</span>
                    </button>
                  </li>
                </ul>
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
            <template v-else>
              <button type="button" class="ar-btn ar-wiz__done-btn" @click="$emit('done')">Go to dashboard</button>
            </template>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>
