<script>
import TagInput from './TagInput.vue';

/**
 * First-run setup wizard — a short, jargon-free guide shown once on a fresh
 * install. Captures identity + a safe content selection, then hands the values
 * up to App for a single settings save. Keeps its own working state (never
 * mutates `settings`) so nothing autosaves mid-wizard.
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
  },
  emits: ['finish', 'skip'],
  data() {
    return {
      step: 1,
      totalSteps: 3,
      entityType: 'Person',
      name: '',
      about: '',
      expertise: [],
      types: [],
    };
  },
  computed: {
    isOrg() {
      return this.entityType === 'Organization';
    },
    namePlaceholder() {
      return this.isOrg ? 'Acme Inc.' : 'Jane Doe';
    },
    aboutPlaceholder() {
      return this.isOrg
        ? 'One sentence on what your organization does.'
        : 'One sentence on who you are and what you do.';
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
  },
  created() {
    this.applyInitial();
  },
  watch: {
    open(val) {
      // Re-seed from current settings each time the wizard opens (so "Run setup
      // again" reflects what's already saved), and focus the dialog.
      if (val) {
        this.applyInitial();
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
      });
    },
  },
};
</script>

<template>
  <Teleport to="body">
    <transition name="ar-modal" appear>
      <div v-if="open" class="ar-modal ar-modal--wiz">
        <div ref="panel" class="ar-modal__panel ar-wiz" role="dialog" aria-modal="true" aria-labelledby="ar-wiz-title" tabindex="-1">
          <div class="ar-modal__head">
            <div class="ar-wiz__steps" aria-hidden="true">
              <span v-for="n in totalSteps" :key="n" class="ar-wiz__dot" :class="{ 'is-on': n <= step }"></span>
            </div>
            <p class="ar-wiz__count">Step {{ step }} of {{ totalSteps }}</p>
          </div>

          <div class="ar-modal__body">
            <div class="ar-modal__scroll">

              <!-- Step 1 — welcome + identity -->
              <div v-if="step === 1" class="ar-wiz__step">
                <h2 id="ar-wiz-title" class="ar-modal__title">Make your site readable by AI assistants</h2>
                <p class="ar-modal__lead">
                  Agentify helps assistants like ChatGPT and Claude understand and cite your site
                  correctly. First, tell them who's behind it — the single most useful thing you can add.
                </p>

                <div class="ar-field">
                  <label for="ar-wiz-type">This site represents</label>
                  <select id="ar-wiz-type" v-model="entityType" class="ar-input">
                    <option v-for="t in entityTypes" :key="t" :value="t">{{ t === 'Person' ? 'A person' : 'An organization' }}</option>
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
              </div>

              <!-- Step 3 — review -->
              <div v-else class="ar-wiz__step">
                <h2 class="ar-modal__title">All set — here's the summary</h2>
                <p class="ar-modal__lead">
                  Agentify will use this to describe your site to AI assistants. You can fine-tune
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
                </div>

                <p v-if="!about" class="ar-card__note ar-warn">
                  Tip: a one-sentence “about” (Step 1) is the highest-impact thing for how assistants describe you.
                </p>
              </div>

            </div>
          </div>

          <div class="ar-modal__actions ar-wiz__actions">
            <button type="button" class="ar-linkbtn ar-wiz__skip" :disabled="saving" @click="$emit('skip')">Skip for now</button>
            <button v-if="step > 1" type="button" class="ar-btn ar-btn--ghost" :disabled="saving" @click="back">Back</button>
            <button v-if="step < totalSteps" type="button" class="ar-btn" @click="next">Continue</button>
            <button v-else type="button" class="ar-btn" :disabled="saving" @click="finish">{{ saving ? 'Finishing…' : 'Finish setup' }}</button>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>
