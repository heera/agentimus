<script>
/**
 * What gets checked — the gear on Your Content.
 *
 * ⭐⭐ This is NOT Settings → Content Types, and the difference is the whole
 * reason it exists. That screen curates what LEAVES the site: llms.txt, the .md
 * twins, schema, discovery. This decides what the plugin READS FOR THE OWNER,
 * publishes nothing, and so it starts switched on for everything the site
 * publishes rather than for a cautious pair of types.
 *
 * Two rules the panel is built on:
 *
 *  - A type that cannot be checked is SHOWN, with its reason. Leaving it out
 *    reads as a bug ("where are my products?") and leaves the owner nothing to
 *    ask about.
 *  - The footer says what saving does. Widening the scope starts a read of the
 *    new content, and a button that said only "Save" would leave someone
 *    wondering an hour later why their numbers had moved.
 *
 * Choices are held locally until Save, so Cancel really cancels.
 */
import { bindDocEsc } from '../js/docEsc.js';

export default {
  name: 'CheckScopePanel',
  props: {
    // [{ slug, label, source, count, on, blocked }] — blocked carries the reason.
    types: { type: Array, default: () => [] },
    busy: { type: Boolean, default: false },
  },
  emits: ['close', 'save'],
  data() {
    return {
      // Slugs switched on in THIS editing session. Seeded from the server's
      // answer, never bound straight to it: a modal that mutates the payload it
      // was handed cannot offer a Cancel.
      on: this.types.filter((t) => !t.blocked && t.on).map((t) => t.slug),
    };
  },
  computed: {
    // The owner's own content first, then whatever a plugin added — the same
    // grouping Settings uses, so the two panels read as one family.
    mine() {
      return this.types.filter((t) => !t.blocked && !t.source);
    },
    added() {
      return this.types.filter((t) => !t.blocked && t.source);
    },
    blocked() {
      return this.types.filter((t) => t.blocked);
    },
    checkable() {
      return this.types.filter((t) => !t.blocked);
    },
    countLabel() {
      const n = this.on.length;
      const all = this.checkable.length;
      return `${n} of ${all} ${all === 1 ? 'kind' : 'kinds'} checked`;
    },
    // How much content the current choice would have the sweep read that it is
    // not reading now. Named out loud, because on a big store this is the whole
    // cost of pressing Save.
    addedItems() {
      return this.checkable
        .filter((t) => this.on.includes(t.slug) && !t.on)
        .reduce((n, t) => n + Number(t.count || 0), 0);
    },
    dirty() {
      const before = this.checkable.filter((t) => t.on).map((t) => t.slug).sort().join('|');
      return before !== this.on.slice().sort().join('|');
    },
  },
  mounted() {
    this._unEsc = bindDocEsc(() => this.$emit('close'));
    this.$nextTick(() => {
      // ⚠️ A ref inside v-for is an ARRAY in Vue 3, whatever the binding
      // resolves to for a single item — so `$refs.firstBox.focus()` was a
      // TypeError on every open, which is what never running a thing buys you.
      const box = this.$refs.firstBox;
      const el = Array.isArray(box) ? box[0] : box;
      if (el && el.focus) el.focus();
    });
  },
  beforeUnmount() {
    if (this._unEsc) this._unEsc();
  },
  methods: {
    isOn(slug) {
      return this.on.includes(slug);
    },
    toggle(slug) {
      const i = this.on.indexOf(slug);
      if (i === -1) this.on.push(slug);
      else this.on.splice(i, 1);
    },
    // Saved as REFUSALS, not as picks — so a content type installed next month
    // joins on its own instead of waiting for someone to reopen this panel.
    save() {
      this.$emit('save', this.checkable.filter((t) => !this.on.includes(t.slug)).map((t) => t.slug));
    },
  },
};
</script>

<template>
  <Teleport to="body">
    <div class="ar-modal" @click.self="$emit('close')">
      <div
        class="ar-modal__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ar-scope-title"
        @keydown.esc="$emit('close')"
      >
        <div class="ar-modal__head">
          <h2 id="ar-scope-title" class="ar-modal__title">What Gets Checked</h2>
          <p class="ar-modal__lead">
            Every kind of content you publish that a search engine can reach. This is your
            dashboard only — checking something here doesn’t add it to what AI assistants can read.
          </p>
        </div>

        <div class="ar-modal__body">
          <div class="ar-modal__scroll">
            <div v-if="mine.length" class="ar-types-grid">
              <label
                v-for="(t, i) in mine"
                :key="t.slug"
                class="ar-type"
                :class="{ 'is-on': isOn(t.slug) }"
              >
                <input
                  :ref="i === 0 ? 'firstBox' : null"
                  type="checkbox"
                  :checked="isOn(t.slug)"
                  @change="toggle(t.slug)"
                />
                <span class="ar-type__body">
                  <span class="ar-type__label">{{ t.label }}</span>
                  <span class="ar-type__meta">
                    <code>{{ t.slug }}</code>
                    <span class="ar-type__n">{{ t.count.toLocaleString() }} published</span>
                  </span>
                  <span v-if="t.note && !isOn(t.slug)" class="ar-type__why">{{ t.note }}</span>
                </span>
              </label>
            </div>

            <template v-if="added.length">
              <p class="ar-types-group">Added by your plugins</p>
              <div class="ar-types-grid">
                <label
                  v-for="t in added"
                  :key="t.slug"
                  class="ar-type"
                  :class="{ 'is-on': isOn(t.slug) }"
                >
                  <input type="checkbox" :checked="isOn(t.slug)" @change="toggle(t.slug)" />
                  <span class="ar-type__body">
                    <span class="ar-type__label">{{ t.label }}</span>
                    <span class="ar-type__meta">
                      <span class="ar-type__src">{{ t.source }}</span>
                      <code>{{ t.slug }}</code>
                      <span class="ar-type__n">{{ t.count.toLocaleString() }} published</span>
                    </span>
                    <span v-if="t.note && !isOn(t.slug)" class="ar-type__why">{{ t.note }}</span>
                  </span>
                </label>
              </div>
            </template>

            <!-- Shown, never omitted. A type that is simply missing reads as a
                 bug, and an owner cannot ask about something they were never
                 shown. -->
            <template v-if="blocked.length">
              <p class="ar-types-group">Cannot be checked</p>
              <div class="ar-types-grid">
                <span v-for="t in blocked" :key="t.slug" class="ar-type is-locked">
                  <span class="ar-type__body">
                    <span class="ar-type__label">{{ t.label }}</span>
                    <span class="ar-type__why">{{ t.blocked }}</span>
                  </span>
                </span>
              </div>
            </template>

            <p class="ar-card__note">
              <strong>Products are checked for what they’re found for, not for how they read.</strong>
              A product page is short on purpose, so “this is thin” would be bad advice. The
              writing checks run on posts, pages and other written content only.
            </p>
            <p class="ar-card__note">
              <strong>Checking is not advertising.</strong> What AI assistants may read is a
              separate list, under Settings → Content Types. Neither one changes the other.
            </p>
          </div>
        </div>

        <div class="ar-modal__actions">
          <span class="ar-types-count">
            {{ countLabel }}<template v-if="addedItems > 0"> · {{ addedItems.toLocaleString() }} more to read</template>
          </span>
          <button type="button" class="ar-btn ar-btn--ghost" :disabled="busy" @click="$emit('close')">
            Cancel
          </button>
          <!-- Says what happens, because it starts real work: widening the scope
               puts the new content in the sweep's queue. -->
          <button type="button" class="ar-btn" :disabled="busy || !dirty" @click="save">
            {{ busy ? 'Saving…' : 'Save and check' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
