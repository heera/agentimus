<script>
/**
 * One vendor's mark, drawn in the plugin's own ink.
 *
 * Every surface that names a service — the Integrations tiles, the
 * announcements ledger's network column — asks for a brand by id and gets
 * either the real mark or nothing. Nothing is the honest answer for a brand
 * whose geometry we don't have; the caller then shows the name alone or its
 * monogram, and no drawing pretends to be somebody's logo.
 *
 * Marks inherit currentColor, so one glyph works on every ground and in both
 * themes — see brandMarks.js for the rules the registry keeps.
 */
import { brandMark } from '../js/brandMarks.js';

export default {
  name: 'BrandMark',
  props: {
    // Brand id: 'x', 'telegram', 'slack', 'woocommerce'… (brandMarks.js).
    brand: { type: String, required: true },
    // The mark is decoration beside a name that is already on screen, so it is
    // hidden from screen readers unless a caller asks for a label.
    label: { type: String, default: '' },
  },
  computed: {
    mark() {
      return brandMark(this.brand);
    },
  },
};
</script>

<template>
  <svg
    v-if="mark"
    class="ar-brandmark"
    :viewBox="mark.viewBox"
    :role="label ? 'img' : null"
    :aria-label="label || null"
    :aria-hidden="label ? null : 'true'"
    focusable="false"
  >
    <template v-for="(s, i) in mark.shapes" :key="i">
      <circle
        v-if="s.c"
        :cx="s.c[0]"
        :cy="s.c[1]"
        :r="s.c[2]"
        :class="{ 'is-stroked': s.stroke }"
        :transform="s.t || null"
      />
      <rect
        v-else-if="s.r"
        :x="s.r[0]"
        :y="s.r[1]"
        :width="s.r[2]"
        :height="s.r[3]"
        :rx="s.r[4]"
        :class="{ 'is-stroked': s.stroke }"
        :transform="s.t || null"
      />
      <path
        v-else
        :d="s.d"
        :class="{ 'is-stroked': s.stroke }"
        :fill-rule="s.fr || null"
        :clip-rule="s.fr || null"
        :transform="s.t || null"
      />
    </template>
  </svg>
</template>
