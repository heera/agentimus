<script>
/**
 * Abilities: which of other plugins' abilities agents may use.
 *
 * WooCommerce, FluentCRM and others offer abilities through WordPress. Agents reach
 * them with three tools (list-plugins, list-plugin-abilities, run-ability), and only
 * the ones turned on here. His ruling, 2026-11-22: every ability starts off, new ones
 * too. The site's MCP level stays the limit: at Read only abilities the plugin marks
 * read-only run.
 *
 * A switch saves at once and turns back if the save fails: an optimistic flip that
 * stays flipped after a failed write tells the owner something untrue.
 *
 * Always mounted, fetch on first reveal, re-read on every return.
 */
import CardSkeleton from './CardSkeleton.vue';
import RefreshCrank from './RefreshCrank.vue';
import { abilityLevelLabel, pluginOnLine, switchedOneLine, switchedAllLine } from '../js/abilities.js';

export default {
  name: 'AbilitiesPanel',
  components: { CardSkeleton, RefreshCrank },
  props: {
    api: { type: Object, default: null },
    active: { type: Boolean, default: false },
    // The saved site level decides which abilities can run now.
    writes: { type: Boolean, default: false },
  },
  emits: ['flash'],
  data() {
    return {
      plugins: [],
      available: true,
      loading: false,
      loaded: false,
      error: '',
      busy: '',
    };
  },
  watch: {
    active(on) {
      if (on) this.load();
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    levelLabel: abilityLevelLabel,
    onLine(p) {
      return pluginOnLine(p);
    },
    async load() {
      if (!this.api || this.loading) return;
      this.loading = true;
      this.error = '';
      try {
        const res = await this.api.getAbilities();
        this.plugins = (res && res.plugins) || [];
        this.available = !res || res.available !== false;
        this.loaded = true;
      } catch (e) {
        this.error = e.message || 'Could not load the abilities.';
      } finally {
        this.loading = false;
      }
    },
    async switchAbilities(names, on, key, done) {
      if (this.busy) return;
      const before = JSON.parse(JSON.stringify(this.plugins));
      // Flip at once, so the switch answers the click.
      this.plugins.forEach((p) => p.abilities.forEach((a) => { if (names.includes(a.name)) a.on = on; }));
      this.busy = key;
      try {
        const res = await this.api.setAbilities(names, on);
        this.plugins = (res && res.plugins) || this.plugins;
        if (done) this.$emit('flash', 'success', done);
      } catch (e) {
        this.plugins = before; // The save failed: show what is really stored.
        this.$emit('flash', 'error', e.message || 'Could not save that change.');
      } finally {
        this.busy = '';
      }
    },
    toggle(a) {
      const on = !a.on;
      this.switchAbilities([a.name], on, a.name, switchedOneLine(a.label, on));
    },
    allOn(p) {
      return p.abilities.every((a) => a.on);
    },
    toggleAll(p) {
      const on = !this.allOn(p);
      this.switchAbilities(p.abilities.map((a) => a.name), on, `plugin:${p.plugin}`, switchedAllLine(p.title, p.abilities.length, on));
    },
    blocked(a) {
      return a.level !== 'read' && !this.writes;
    },
  },
};
</script>

<template>
  <section class="ar-card ar-abil">
    <div class="ar-card__head ar-card__head--inline">
      <div class="ar-card__titlewrap">
        <h2 class="ar-card__title">Other Plugins’ Abilities</h2>
        <RefreshCrank
          :busy="loading"
          :aria-label="loading ? 'Reloading abilities…' : 'Reload abilities'"
          :title="loading ? 'Reloading…' : 'Reload abilities'"
          @refresh="load()"
        />
      </div>
    </div>
    <p class="ar-card__lead">
      Plugins such as WooCommerce or FluentCRM offer abilities to AI assistants. An assistant connected to
      Agentimus can use the ones you turn on here, as the signed-in user and with that plugin’s own checks.
      Every ability starts off, new ones too. At the <strong>Read</strong> level only abilities the plugin
      marks as reading can run; the others need <strong>Write</strong>.
    </p>

    <p v-if="error" class="ar-abil__error" role="alert">{{ error }}</p>
    <CardSkeleton v-if="!loaded && !error" lead="Loading abilities…" />

    <p v-if="loaded && !available" class="ar-abil__empty">
      <strong>This WordPress has no Abilities API.</strong> It arrived in WordPress 6.9, so there is nothing to turn on here yet.
    </p>
    <p v-else-if="loaded && !plugins.length" class="ar-abil__empty">
      <strong>No other plugin offers abilities yet.</strong> When one does, it appears here, turned off.
    </p>

    <!-- The first plugin sits under the lead's own rule; a second line there doubled it. -->
    <div v-for="(p, i) in plugins" :key="p.plugin" class="ar-abil__plugin" :class="{ 'is-first': i === 0 }">
      <div class="ar-abil__head">
        <h3 class="ar-abil__name">{{ p.title }} <code v-if="p.title !== p.plugin">{{ p.plugin }}</code></h3>
        <span class="ar-abil__count">{{ onLine(p) }}</span>
        <button
          type="button"
          class="ar-btn ar-btn--ghost ar-btn--small"
          :disabled="!!busy"
          @click="toggleAll(p)"
        >{{ allOn(p) ? 'Turn all off' : 'Turn all on' }}</button>
      </div>
      <ul class="ar-abil__list">
        <li v-for="a in p.abilities" :key="a.name" class="ar-abil__row">
          <!-- Busy is marked on the row, never as a disabled checkbox: WordPress draws a
               disabled checkbox over the hidden one, a sliver on every switch mid-save. -->
          <label class="ar-toggle ar-abil__toggle" :data-busy="busy ? '' : null">
            <input type="checkbox" :checked="a.on" @change="toggle(a)" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>{{ a.label }}</strong>
              <small>{{ a.description }}</small>
              <small class="ar-abil__meta">
                <code>{{ a.name }}</code>
                <span class="ar-abil__level" :class="`is-${a.level}`">{{ levelLabel(a.level) }}</span>
                <span v-if="a.on && blocked(a)" class="ar-abil__wait">Needs the Write level to run.</span>
              </small>
            </span>
          </label>
        </li>
      </ul>
    </div>
  </section>
</template>
