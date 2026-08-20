<script>
/**
 * A custom, theme-styled dropdown — a drop-in replacement for a native <select>
 * whose option list can't be styled. The trigger reuses the shared `.ar-input`
 * box so it matches the height and border of the text fields beside it.
 *
 * Options accept plain strings or { value, label } objects. Emits update:modelValue
 * so it works with v-model. Closes on outside click, Esc, or a pick; supports
 * arrow-key navigation.
 */
export default {
  name: 'SelectMenu',
  props: {
    modelValue: { type: [String, Number, Array], default: '' },
    // ⭐ MULTI-PICK. modelValue becomes an ARRAY, options carry a checkbox, and
    // the menu stays open while you tick — closing after each pick is what makes
    // a one-at-a-time control tiring to use for a list. An option whose value is
    // '' is the CLEAR ("Any"): picking it empties the selection rather than
    // joining it, because "Any" and "Any + ChatGPT" are the same query and only
    // one of them is honest.
    multiple: { type: Boolean, default: false },
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Select…' },
    ariaLabel: { type: String, default: '' },
    mono: { type: Boolean, default: false }, // monospace values (e.g. model IDs)
    // Matches a native select's disabled: the trigger stops responding AND stops
    // being focusable, so a menu can't be opened by keyboard while whatever it
    // configures is mid-flight.
    disabled: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  data() {
    // ⚠️ `pending` is the multi-pick selection this component has EMITTED but not
    // yet seen come back as a prop. Without it, two ticks inside one render tick
    // both read the old modelValue and the second overwrites the first — caught
    // live on 2026-08-21, two clicks in one call landed one selection. Cleared
    // the moment the parent's value arrives, so the prop stays the truth.
    return { open: false, activeIndex: -1, pending: null };
  },
  watch: {
    modelValue() {
      this.pending = null;
    },
  },
  computed: {
    items() {
      return this.options.map((o) => (o && typeof o === 'object' ? o : { value: o, label: String(o) }));
    },
    // The current selection, always as a list — single mode is a list of one,
    // so nothing below has to branch on the mode.
    picked() {
      if (!this.multiple) return this.modelValue === '' || this.modelValue === null || this.modelValue === undefined ? [] : [this.modelValue];
      if (this.pending) return this.pending;
      return Array.isArray(this.modelValue) ? this.modelValue : [];
    },
    selectedLabel() {
      if (!this.multiple) {
        const hit = this.items.find((i) => i.value === this.modelValue);
        return hit ? hit.label : (this.modelValue || this.placeholder);
      }
      // ⚠️ Nothing ticked is not "none" — it is NO filter, and the word for that
      // is whatever the '' option calls itself ("Any"), not an empty box.
      if (!this.picked.length) {
        const any = this.items.find((i) => i.value === '');
        return any ? any.label : this.placeholder;
      }
      if (1 === this.picked.length) {
        const hit = this.items.find((i) => i.value === this.picked[0]);
        return hit ? hit.label : String(this.picked[0]);
      }
      // ⛔ A count, not a joined list: three crawler names do not fit the trigger
      // and would ellipsize to something that reads like ONE long name.
      return `${this.picked.length} selected`;
    },
  },
  beforeUnmount() {
    this.detach();
  },
  methods: {
    toggle() {
      if (this.disabled) return;
      if (this.open) this.close();
      else this.openMenu();
    },
    openMenu() {
      if (this.disabled) return;
      this.open = true;
      const first = this.picked.length ? this.picked[0] : this.modelValue;
      this.activeIndex = Math.max(0, this.items.findIndex((i) => i.value === first));
      document.addEventListener('mousedown', this.onDocDown, true);
      document.addEventListener('keydown', this.onKey, true);
    },
    close() {
      this.open = false;
      this.detach();
    },
    detach() {
      document.removeEventListener('mousedown', this.onDocDown, true);
      document.removeEventListener('keydown', this.onKey, true);
    },
    isPicked(item) {
      return this.picked.some((v) => v === item.value);
    },
    pick(item) {
      if (!this.multiple) {
        this.$emit('update:modelValue', item.value);
        this.close();
        this.$nextTick(() => { if (this.$refs.btn) this.$refs.btn.focus(); });
        return;
      }
      // ⭐ The clear option CLOSES — his call, 2026-08-21. "Any" is not a tick,
      // it is the end of the question: there is nothing left to add once you
      // have said "no filter", so the menu behaves like a single-select pick and
      // hands focus back to the trigger. Every other option toggles and stays
      // open, because you are usually picking more than one.
      if (item.value === '') {
        this.pending = [];
        this.$emit('update:modelValue', []);
        this.close();
        this.$nextTick(() => { if (this.$refs.btn) this.$refs.btn.focus(); });
        return;
      }
      const next = this.isPicked(item)
        ? this.picked.filter((v) => v !== item.value)
        : [...this.picked, item.value];
      // Held locally FIRST so a second tick in the same tick builds on this one,
      // then emitted. The watcher above drops it when the parent answers.
      this.pending = next;
      this.$emit('update:modelValue', next);
    },
    onDocDown(e) {
      if (this.$el && !this.$el.contains(e.target)) this.close();
    },
    // ⛔ An open menu OWNS these keys, so it stops them as well as consuming
    // them. The listener is on document in the CAPTURE phase, so without
    // stopPropagation an Escape meant for the dropdown carried on to whatever
    // is behind it — inside the onboarding wizard that closed the whole modal
    // while the little list underneath was still on screen. Enter is stopped
    // for the same reason: it picks an option, it does not also submit the form
    // the menu happens to sit in.
    onKey(e) {
      if (!this.open) return;
      const own = ['Escape', 'ArrowDown', 'ArrowUp', 'Enter'];
      if (!own.includes(e.key)) return;
      e.preventDefault();
      e.stopPropagation();
      if (e.key === 'Escape') { this.close(); if (this.$refs.btn) this.$refs.btn.focus(); }
      else if (e.key === 'ArrowDown') { this.activeIndex = Math.min(this.items.length - 1, this.activeIndex + 1); }
      else if (e.key === 'ArrowUp') { this.activeIndex = Math.max(0, this.activeIndex - 1); }
      else if (e.key === 'Enter') { const it = this.items[this.activeIndex]; if (it) this.pick(it); }
    },
  },
};
</script>

<template>
  <div class="ar-select" :class="{ 'is-open': open, 'ar-select--mono': mono }">
    <button
      ref="btn"
      type="button"
      class="ar-input ar-select__btn"
      :aria-label="ariaLabel"
      aria-haspopup="listbox"
      :aria-expanded="open ? 'true' : 'false'"
      :disabled="disabled"
      @click="toggle"
    >
      <!-- Optional leading mark (an icon, usually). In the flex flow rather than
           positioned over the trigger, so the label can never sit under it — an
           overlay would need a padding override, and a padding override on a
           scoped rule is a specificity coin-flip. -->
      <span v-if="$slots.leading" class="ar-select__lead" aria-hidden="true"><slot name="leading" /></span>
      <span class="ar-select__value">{{ selectedLabel }}</span>
      <span class="ar-select__caret" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
      </span>
    </button>
    <ul v-if="open" class="ar-select__menu" :class="{ 'is-multi': multiple }" role="listbox" :aria-multiselectable="multiple ? 'true' : 'false'">
      <li
        v-for="(it, i) in items"
        :key="it.value"
        class="ar-select__opt"
        :class="{ 'is-active': i === activeIndex, 'is-selected': multiple ? isPicked(it) : it.value === modelValue }"
        role="option"
        :aria-selected="(multiple ? isPicked(it) : it.value === modelValue) ? 'true' : 'false'"
        @mouseenter="activeIndex = i"
        @click="pick(it)"
      >
        <!-- The box is drawn, not a real <input>: the row is already the control
             (it carries role=option and the click), and a nested input would be a
             second focus stop inside one option. ⛔ The CLEAR row never shows one
             — it is an action, not a state you can be in. -->
        <span v-if="multiple && it.value !== ''" class="ar-select__box" aria-hidden="true">
          <svg v-if="isPicked(it)" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7" /></svg>
        </span>
        <span class="ar-select__opt-l">{{ it.label }}</span>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.ar-select { position: relative; }
.ar-select__btn {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  width: 100%; text-align: left; cursor: pointer;
}
.ar-select__lead { flex: 0 0 auto; display: inline-flex; align-items: center; color: var(--ar-accent); }
/* Takes the middle so the caret stays pinned right whether or not a lead is there. */
.ar-select__value { flex: 1 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* Disabled reads the same as the rest of the admin's disabled controls: dimmed,
   and the pointer stops promising it will do something. */
.ar-select__btn:disabled { opacity: 0.55; cursor: default; }
.ar-select--mono .ar-select__value { font-family: var(--ar-mono); }
/* Multi rows lay out box + label; the box holds its column so the labels stay
   aligned whether or not each one is ticked. */
.ar-select__menu.is-multi .ar-select__opt { display: flex; align-items: flex-start; gap: 9px; }
/* The box keeps the first line's optical centre when a long label wraps. */
.ar-select__menu.is-multi .ar-select__box { margin-top: 1px; }
.ar-select__box {
  flex: 0 0 auto; width: 15px; height: 15px; border-radius: 4px;
  border: 1px solid var(--ar-line-strong); display: inline-flex;
  align-items: center; justify-content: center; color: var(--ar-on-signal);
}
.ar-select__opt.is-selected .ar-select__box { background: var(--ar-accent); border-color: var(--ar-accent); }
/* ⛔ No ellipsis here. This is the list you READ to choose from; clipping the
   choice is the one thing it must never do. The trigger still truncates — that
   is a summary of a decision already made. */
.ar-select__opt-l { min-width: 0; overflow-wrap: anywhere; }
.ar-select__caret { flex: 0 0 auto; display: inline-flex; color: var(--ar-ink-faint); transition: transform 0.15s ease; }
.ar-select.is-open .ar-select__caret { transform: rotate(180deg); }
.ar-select.is-open .ar-select__btn { border-color: var(--ar-accent); box-shadow: 0 0 0 3px rgba(20, 107, 100, 0.13); }

/* ⭐ THE MENU SIZES TO ITS OPTIONS, not to the trigger — his call, 2026-08-21:
   "Refused (not serv…" had no way to be read. It is never NARROWER than the
   control it belongs to (min-width), grows to its longest label (max-content),
   and stops before it can run off the screen (max-width) — past that the labels
   wrap, which is the one option that always stays readable. */
.ar-select__menu {
  position: absolute; z-index: 40; top: calc(100% + 5px); left: 0; right: auto;
  min-width: 100%; width: max-content; max-width: min(30rem, 80vw);
  margin: 0; padding: 4px; list-style: none;
  background: var(--ar-paper); border: 1px solid var(--ar-line-strong);
  border-radius: var(--ar-radius); box-shadow: 0 12px 30px -12px rgba(27, 25, 19, 0.4);
  max-height: 260px; overflow: auto;
}
.ar-select__opt {
  padding: 7px 10px; border-radius: calc(var(--ar-radius) - 2px);
  font-size: 13px; color: var(--ar-ink); cursor: pointer; white-space: normal;
}
.ar-select--mono .ar-select__opt { font-family: var(--ar-mono); font-size: 12px; }
.ar-select__opt.is-active { background: var(--ar-surface-2); }
.ar-select__opt.is-selected { color: var(--ar-accent); font-weight: 600; }
</style>
