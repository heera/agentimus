<script>
/**
 * The plain "loading a card" placeholder — a lead line naming what is loading,
 * then three shimmer bars. The one shape shared by the simple data screens
 * (Agent Access, Request Log, AI Traffic, AI Visibility). Screens whose loaded
 * layout is distinctive — Search Performance, the Bing/Google index cards —
 * keep their own bespoke skeletons that mirror that layout, and are left alone.
 *
 * The PARENT owns WHEN it shows (its own v-if on the loading state); this owns
 * only the shape. `lead` is the single line that says what is loading.
 */
export default {
  name: 'CardSkeleton',
  props: {
    // The single line that says what is loading. Pass '' where something else
    // on the card already says it (the data-source cards' status rail does).
    lead: { type: String, default: 'Loading…' },
    // The bars' widths — more of them for a card whose loaded body is tall,
    // so the placeholder holds roughly the room the real thing will take.
    lines: { type: Array, default: () => ['88%', '72%', '80%'] },
    // A card that ends in a button holds that button's space too, or the whole
    // page still jumps at the last moment.
    action: { type: Boolean, default: false },
  },
};
</script>

<template>
  <div class="ar-skel ar-skel--card" aria-busy="true">
    <p v-if="lead" class="ar-card__lead">{{ lead }}</p>
    <span v-for="(w, i) in lines" :key="i" class="ar-skel__line" :style="{ width: w }"></span>
    <span v-if="action" class="ar-skel__action"></span>
  </div>
</template>
