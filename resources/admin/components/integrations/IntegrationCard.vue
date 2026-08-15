<script>
/**
 * One integration card — the shared grammar every tile on the Integrations
 * screen speaks, so a webhook, a store plugin and a coming-soon service all
 * read as the same kind of thing at a glance.
 *
 * The shape is fixed on purpose (his blessed mock): mark + name, one line of
 * what it is, then a ruled footer holding a UNIFORM-width status chip and a
 * uniform ghost action. Uniformity is the feature — a column of cards must
 * read as a column of states, which dies the moment one chip is wider or one
 * card's button is louder than its neighbours'.
 *
 * The mark is the vendor's own (his call, 2026-08-14: brand marks everywhere,
 * no monograms). `mark` remains as the fallback for a brand we hold no
 * faithful glyph for — a two-letter stand-in beats a lookalike.
 *
 * A card with no action renders no control at all: a "Coming" tile gets no
 * disabled button to puzzle over — dead controls are the one thing this grid
 * refuses to grow.
 */
import BrandMark from '../BrandMark.vue';
import { brandMark } from '../../js/brandMarks.js';

export default {
  name: 'IntegrationCard',
  components: { BrandMark },
  props: {
    // Brand id for the real mark ('telegram', 'woocommerce'…), when we have one.
    brand: { type: String, default: '' },
    // Two or three characters — the fallback when we don't.
    mark: { type: String, required: true },
    name: { type: String, required: true },
    blurb: { type: String, required: true },
    // { label, tone } — 'on' (Connected/Described), 'err' (needs the owner's
    // hand — LinkedIn's expired grant), or '' (everything quiet).
    chip: { type: Object, required: true },
    // Button label, or empty for no control at all.
    action: { type: String, default: '' },
    // When set, the action is an outward link (the developer card) instead of a button.
    actionHref: { type: String, default: '' },
  },
  emits: ['act'],
  computed: {
    hasBrand() {
      return !!brandMark(this.brand);
    },
  },
};
</script>

<template>
  <article class="ar-int__card">
    <div class="ar-int__head">
      <span class="ar-int__mark" :class="{ 'is-brand': hasBrand }" aria-hidden="true">
        <BrandMark v-if="hasBrand" :brand="brand" />
        <template v-else>{{ mark }}</template>
      </span>
      <h3 class="ar-int__name">{{ name }}</h3>
    </div>
    <p class="ar-int__blurb">{{ blurb }}</p>
    <!-- The card's own status line (the webhook's last delivered / last error). -->
    <slot name="note"></slot>
    <div class="ar-int__foot">
      <span class="ar-int__chip" :class="{ 'is-on': chip.tone === 'on', 'is-err': chip.tone === 'err' }">{{ chip.label }}</span>
      <a
        v-if="action && actionHref"
        class="ar-int__act"
        :href="actionHref"
        target="_blank"
        rel="noopener"
      >{{ action }} <span class="ar-int__ext" aria-hidden="true">&#8599;</span></a>
      <button v-else-if="action" type="button" class="ar-int__act" @click="$emit('act')">
        {{ action }}
      </button>
    </div>
  </article>
</template>
