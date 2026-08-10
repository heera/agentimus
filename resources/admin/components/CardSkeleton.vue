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
    lead: { type: String, default: 'Loading…' },
  },
};
</script>

<template>
  <div class="ar-skel" aria-busy="true">
    <p class="ar-card__lead">{{ lead }}</p>
    <span class="ar-skel__line" style="width: 88%"></span>
    <span class="ar-skel__line" style="width: 72%"></span>
    <span class="ar-skel__line" style="width: 80%"></span>
  </div>
</template>
