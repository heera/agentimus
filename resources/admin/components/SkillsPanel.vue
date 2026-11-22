<script>
/**
 * Skills: the owner's rules and ways of doing a task, which every agent reads
 * before working on the site (list-skills, get-skill).
 *
 * His ruling, 2026-11-22: a skill the owner writes is on at once; a skill an agent
 * saves waits off until the owner turns it on, and an agent that changes its own
 * skill turns it off again. Those wait at the top, with a Turn on button.
 *
 * Every change confirms itself with a toast. A switch marks its row busy, never a
 * disabled checkbox (WordPress draws that one over the hidden input). The editor is
 * the app's modal shell, its fields in the scrolling body so Save stays in view.
 */
import CardSkeleton from './CardSkeleton.vue';
import RefreshCrank from './RefreshCrank.vue';
import { confirm } from '../js/confirm.js';
import { bindDocEsc } from '../js/docEsc.js';
import { skillByLabel, waitingSkills, skillLimits } from '../js/skills.js';

export default {
  name: 'SkillsPanel',
  components: { CardSkeleton, RefreshCrank },
  props: {
    api: { type: Object, default: null },
    active: { type: Boolean, default: false },
  },
  emits: ['flash'],
  data() {
    return {
      skills: [],
      max: 50,
      loading: false,
      loaded: false,
      error: '',
      busy: 0,
      // The editor: null when closed, else { id, name, when, steps, on }.
      edit: null,
      saving: false,
      formError: '',
      limits: skillLimits,
    };
  },
  computed: {
    waiting() {
      return waitingSkills(this.skills);
    },
    others() {
      return this.skills.filter((s) => !this.waiting.includes(s));
    },
    full() {
      return this.skills.length >= this.max;
    },
  },
  watch: {
    active(on) {
      if (on) this.load();
    },
    edit(open) {
      if (this._unEsc) this._unEsc();
      this._unEsc = open ? bindDocEsc(() => { this.edit = null; }) : null;
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    byLabel: skillByLabel,
    async load() {
      if (!this.api || this.loading) return;
      this.loading = true;
      this.error = '';
      try {
        this.take(await this.api.getSkills());
        this.loaded = true;
      } catch (e) {
        this.error = e.message || 'Could not load the skills.';
      } finally {
        this.loading = false;
      }
    },
    take(res) {
      this.skills = (res && res.skills) || [];
      if (res && res.max) this.max = res.max;
    },
    async switchSkill(s) {
      if (this.busy) return;
      const on = !s.on;
      s.on = on; // Answer the click at once…
      this.busy = s.id;
      try {
        this.take(await this.api.setSkillOn(s.id, on));
        this.$emit('flash', 'success', on ? `${s.name} is on: agents follow it now.` : `${s.name} is off: agents won’t follow it.`);
      } catch (e) {
        s.on = !on; // …and take it back when the save fails.
        this.$emit('flash', 'error', e.message || 'Could not save that change.');
      } finally {
        this.busy = 0;
      }
    },
    openNew() {
      this.formError = '';
      this.edit = { id: 0, name: '', when: '', steps: '', on: true };
    },
    openEdit(s) {
      this.formError = '';
      this.edit = { id: s.id, name: s.name, when: s.when, steps: s.steps, on: s.on };
    },
    async save() {
      const e = this.edit;
      if (!e || this.saving) return;
      this.saving = true;
      this.formError = '';
      try {
        const body = { name: e.name, when: e.when, steps: e.steps, on: e.on };
        this.take(e.id ? await this.api.updateSkill(e.id, body) : await this.api.createSkill(body));
        this.$emit('flash', 'success', e.id ? `${e.name} is saved.` : `${e.name} is added${e.on ? ' and on' : ''}.`);
        this.edit = null;
      } catch (err) {
        this.formError = err.message || 'Could not save this skill.';
      } finally {
        this.saving = false;
      }
    },
    async remove(s) {
      const ok = await confirm({
        title: `Delete ${s.name}?`,
        message: 'Agents stop following it at once. This can’t be undone.',
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      this.busy = s.id;
      try {
        this.take(await this.api.deleteSkill(s.id));
        this.$emit('flash', 'success', `${s.name} is deleted.`);
      } catch (e) {
        this.$emit('flash', 'error', e.message || 'Could not delete that skill.');
      } finally {
        this.busy = 0;
      }
    },
  },
};
</script>

<template>
  <section class="ar-card ar-skills">
    <div class="ar-card__head ar-card__head--inline">
      <div class="ar-card__titlewrap">
        <h2 class="ar-card__title">Skills</h2>
        <RefreshCrank
          :busy="loading"
          :aria-label="loading ? 'Reloading skills…' : 'Reload skills'"
          :title="loading ? 'Reloading…' : 'Reload skills'"
          @refresh="load()"
        />
      </div>
      <div class="ar-card__actions">
        <button type="button" class="ar-btn" :disabled="full" @click="openNew">Add skill</button>
      </div>
    </div>
    <p class="ar-card__lead">
      Your rules and ways of doing things on this site, written down once so every agent follows them.
      Agents read the skills that are on before they start a task. A skill you write is on as soon as you
      save it. A skill an agent saves waits here, off, until you turn it on.
    </p>

    <p v-if="error" class="ar-skills__error" role="alert">{{ error }}</p>
    <CardSkeleton v-if="!loaded && !error" lead="Loading skills…" />

    <p v-if="loaded && !skills.length" class="ar-skills__empty">
      <strong>No skills yet.</strong> Add one, such as “Publishing a post: add a featured image and one
      category”, or ask an agent to remember something for next time.
    </p>
    <p v-if="full" class="ar-skills__empty">This site has {{ max }} skills, the most it keeps. Delete one to add another.</p>

    <template v-for="group in [{ key: 'waiting', rows: waiting }, { key: 'others', rows: others }]" :key="group.key">
      <h3 v-if="group.key === 'waiting' && group.rows.length" class="ar-skills__group">Waiting for you</h3>
      <ul v-if="group.rows.length" class="ar-skills__list" :class="`is-${group.key}`">
        <li v-for="s in group.rows" :key="s.id" class="ar-skills__row">
          <label class="ar-toggle ar-skills__toggle" :data-busy="busy ? '' : null">
            <input type="checkbox" :checked="s.on" @change="switchSkill(s)" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>{{ s.name }}</strong>
              <small v-if="s.when">{{ s.when }}</small>
              <small class="ar-skills__meta">
                <span class="ar-skills__by" :class="`is-${s.by}`">{{ byLabel(s.by) }}</span>
                <span v-if="!s.on && s.by === 'agent'" class="ar-skills__wait">Off until you turn it on.</span>
              </small>
            </span>
          </label>
          <div class="ar-skills__actions">
            <button v-if="!s.on && s.by === 'agent'" type="button" class="ar-btn ar-btn--small" :disabled="!!busy" @click="switchSkill(s)">Turn on</button>
            <button type="button" class="ar-btn ar-btn--ghost ar-btn--small" @click="openEdit(s)">Edit</button>
            <button type="button" class="ar-btn ar-btn--danger ar-btn--small" :disabled="!!busy" @click="remove(s)">Delete</button>
          </div>
        </li>
      </ul>
    </template>

    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="edit" class="ar-modal" @click.self="edit = null">
          <div class="ar-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ar-skill-title" tabindex="-1">
            <div class="ar-modal__head">
              <h2 id="ar-skill-title" class="ar-modal__title">{{ edit.id ? 'Edit Skill' : 'Add a Skill' }}</h2>
              <p class="ar-modal__lead">Plain text. Agents read it as your instructions for this site.</p>
            </div>
            <div class="ar-modal__body">
              <div class="ar-modal__scroll">
                <div class="ar-field">
                  <label for="ar-skill-name">Name</label>
                  <input id="ar-skill-name" v-model="edit.name" type="text" class="ar-input" :maxlength="limits.name" placeholder="Publishing a post" />
                </div>
                <div class="ar-field">
                  <label for="ar-skill-when">When to use it</label>
                  <input id="ar-skill-when" v-model="edit.when" type="text" class="ar-input" :maxlength="limits.when" placeholder="Before publishing or scheduling any post" />
                </div>
                <div class="ar-field">
                  <label for="ar-skill-steps">The rule or the steps</label>
                  <textarea id="ar-skill-steps" v-model="edit.steps" class="ar-input ar-skills__steps" rows="8" :maxlength="limits.steps" placeholder="1. Add a featured image.&#10;2. Pick one category.&#10;3. Set an AI description."></textarea>
                </div>
                <label class="ar-toggle ar-skills__on">
                  <input v-model="edit.on" type="checkbox" />
                  <span class="ar-toggle__track" aria-hidden="true"></span>
                  <span class="ar-toggle__text"><strong>On</strong><small>Agents follow it from their next task.</small></span>
                </label>
                <p v-if="formError" class="ar-field__hint ar-mcp-key__err" role="alert">{{ formError }}</p>
              </div>
            </div>
            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" @click="edit = null">Cancel</button>
              <button type="button" class="ar-btn" :disabled="saving || !edit.name.trim() || !edit.steps.trim()" @click="save">
                {{ saving ? 'Saving…' : 'Save' }}
              </button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>
  </section>
</template>
