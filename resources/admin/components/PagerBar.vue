<script>
/**
 * PagerBar — the one numbered pager every listing shares.
 *
 * Length-aware: first and last page always visible, the current page with one
 * neighbour either side, ellipses for the folds — so "where am I, how much is
 * there" is answered at a glance, which Newer/Older never could. One component
 * so the strip looks and behaves the same on every screen; the styles live
 * under `.ar-pager` in app.css.
 *
 * The parent owns the data and the fetch; this only says which page was asked
 * for. `busy` freezes the strip during a load so a double-click can't queue two
 * competing reads.
 */
export default {
  name: 'PagerBar',
  props: {
    page: { type: Number, required: true },
    pages: { type: Number, required: true },
    busy: { type: Boolean, default: false },
    label: { type: String, default: 'Pages' },
  },
  emits: ['go'],
  computed: {
    // A CONSTANT seven slots once the list outgrows them, so the strip never
    // changes width as the reader walks it — an ellipsis that appears mid-walk
    // shifts every button under the pointer (his catch, 2026-08-31). Near an
    // end the five slots on that side are real pages; in the middle the two
    // folds hold the ellipses. Seven or fewer pages need no folds at all.
    window() {
      const L = this.pages;
      const p = this.page;
      if (L <= 7) {
        const all = [];
        for (let n = 1; n <= L; n++) all.push(n);
        return all;
      }
      if (p <= 4) return [1, 2, 3, 4, 5, '…', L];
      if (p >= L - 3) return [1, '…', L - 4, L - 3, L - 2, L - 1, L];
      return [1, '…', p - 1, p, p + 1, '…', L];
    },
  },
  methods: {
    go(n) {
      if (this.busy || n < 1 || n > this.pages || n === this.page) return;
      this.$emit('go', n);
    },
  },
};
</script>

<template>
  <nav class="ar-pager" :aria-label="label">
    <button type="button" class="ar-pager__btn" :disabled="busy || page <= 1" aria-label="Previous page" @click="go(page - 1)">‹</button>
    <template v-for="(n, i) in window">
      <span v-if="n === '…'" :key="'gap' + i" class="ar-pager__gap" aria-hidden="true">…</span>
      <button
        v-else
        :key="n"
        type="button"
        class="ar-pager__btn"
        :aria-current="n === page ? 'page' : null"
        :disabled="busy"
        @click="go(n)"
      >{{ n }}</button>
    </template>
    <button type="button" class="ar-pager__btn" :disabled="busy || page >= pages" aria-label="Next page" @click="go(page + 1)">›</button>
  </nav>
</template>
