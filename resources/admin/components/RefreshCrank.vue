<script>
/**
 * The freshness-rule hand-crank: the little refresh mark seated beside a data
 * card's title that re-reads the stored report on demand. The same button, the
 * same icon, on every data surface — so it is drawn here once.
 *
 * Deliberately dumb. The parent owns the wording (a screen reader hears the
 * full action in `aria-label`, the hover `title` is terser) and owns what a
 * press DOES — this only draws the button, spins the icon while `busy`, and
 * emits `refresh` when clicked. The emit carries no payload on purpose: a bare
 * `@refresh="load"` can never pass the click event on as an argument (the bug
 * that once sent a PointerEvent in as a paging cursor).
 *
 * `busy` drives the spin and, on its own, the disabled state. Pass `disabled`
 * as well when a SECOND condition must also lock the button — a live run in
 * flight, say — without spinning the icon.
 */
export default {
  name: 'RefreshCrank',
  props: {
    busy: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    ariaLabel: { type: String, required: true },
    title: { type: String, default: '' },
  },
  emits: ['refresh'],
};
</script>

<template>
  <button
    type="button"
    class="ar-readiness__refresh"
    :class="{ 'is-busy': busy }"
    :disabled="busy || disabled"
    :aria-label="ariaLabel"
    v-tip="title"
    @click="$emit('refresh')"
  >
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" /><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" /></svg>
  </button>
</template>
