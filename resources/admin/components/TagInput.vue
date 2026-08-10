<script>
export default {
  name: 'TagInput',
  props: {
    modelValue: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Add and press Enter' },
    // Render the chips BELOW the field's hint line rather than directly under the input.
    // Opt-in, because it works by flex `order` on the surrounding .ar-field — which would
    // also push the chips past any OTHER block a field appends after its hint (e.g. the
    // built-in allow-list), stranding them away from their own input.
    afterHint: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  data() {
    // `editing` holds the tag value currently pulled into the input for editing.
    // While set, that chip is hidden (it lives in the input) — with NO model
    // update, so editing never triggers a save/toast on its own.
    return { draft: '', editing: null };
  },
  computed: {
    // Chips to render — the one being edited is shown in the input instead.
    visibleTags() {
      return this.editing === null ? this.modelValue : this.modelValue.filter((t) => t !== this.editing);
    },
  },
  methods: {
    // Commit the input: replace the edited tag in place, or append a new one.
    // Emits at most once, and only when the list actually changes — so pressing
    // Enter on an unchanged edit (or blurring an empty box) is silent.
    commit() {
      const value = this.draft.trim();
      const editing = this.editing;
      this.editing = null;
      this.draft = '';

      const next = this.modelValue.slice();
      if (editing !== null) {
        const at = next.indexOf(editing);
        if (at !== -1) {
          if (value === '' || (value !== editing && next.includes(value))) {
            next.splice(at, 1); // cleared, or would duplicate another → drop it
          } else {
            next[at] = value; // edit in place, keeping its position
          }
        }
      } else {
        if (value === '' || next.includes(value)) return; // nothing new to add
        next.push(value);
      }

      const changed = next.length !== this.modelValue.length || next.some((t, i) => t !== this.modelValue[i]);
      if (changed) this.$emit('update:modelValue', next);
    },
    remove(tag) {
      if (tag === this.editing) { this.editing = null; this.draft = ''; }
      this.$emit('update:modelValue', this.modelValue.filter((t) => t !== tag));
    },
    // Click a chip to edit it: load it into the input. No emit here.
    edit(tag) {
      if (this.editing !== null) this.commit(); // finish any current edit first
      this.editing = tag;
      this.draft = tag;
      this.$nextTick(() => { if (this.$refs.input) this.$refs.input.focus(); });
    },
    onKeydown(e) {
      if (e.key === 'Backspace' && !this.draft && this.visibleTags.length) {
        this.remove(this.visibleTags[this.visibleTags.length - 1]);
      }
    },
  },
};
</script>

<!--
  The text box is an ordinary `.ar-input` and the chips sit BELOW it, so the field lines
  up with the plain inputs beside it in a grid at any chip count. (It used to be a
  bordered box wrapping the chips with the input on its own row inside — which made the
  control ~2.3x the height of a normal input and broke the grid's alignment.)
-->
<template>
  <div class="ar-tags">
    <input
      ref="input"
      v-model="draft"
      type="text"
      class="ar-input ar-tags__input"
      :placeholder="placeholder"
      @keydown.enter.prevent="commit"
      @keydown="onKeydown"
      @blur="commit"
    />
    <ul v-if="visibleTags.length" class="ar-tags__list" :class="{ 'ar-tags__list--after-hint': afterHint }">
      <li v-for="tag in visibleTags" :key="tag" class="ar-tags__chip">
        <button type="button" class="ar-tags__edit" v-tip="`Edit “${tag}”`" @click="edit(tag)">{{ tag }}</button>
        <button type="button" class="ar-tags__x" :aria-label="`Remove ${tag}`" @click="remove(tag)">×</button>
      </li>
    </ul>
  </div>
</template>
