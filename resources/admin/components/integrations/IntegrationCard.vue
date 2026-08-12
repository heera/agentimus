<script>
/**
 * One integration card — the shared grammar every tile on the Integrations
 * screen speaks, so a webhook, a store plugin and a coming-soon service all
 * read as the same kind of thing at a glance.
 *
 * The shape is fixed on purpose (his blessed mock): monogram mark + name, one
 * line of what it is, then a ruled footer holding a UNIFORM-width status chip
 * and a uniform ghost action. Uniformity is the feature — a column of cards
 * must read as a column of states, which dies the moment one chip is wider or
 * one card's button is louder than its neighbours'.
 *
 * A card with no action renders no control at all: a "Coming" tile gets no
 * disabled button to puzzle over — dead controls are the one thing this grid
 * refuses to grow.
 */
export default {
  name: 'IntegrationCard',
  props: {
    // Two or three characters standing in for a logo we don't ship.
    mark: { type: String, required: true },
    name: { type: String, required: true },
    blurb: { type: String, required: true },
    // { label, tone } — tone 'on' (Connected/Described) or '' (everything quiet).
    chip: { type: Object, required: true },
    // Button label, or empty for no control at all.
    action: { type: String, default: '' },
    // When set, the action is an outward link (the developer card) instead of a button.
    actionHref: { type: String, default: '' },
  },
  emits: ['act'],
};
</script>

<template>
  <article class="ar-int__card">
    <div class="ar-int__head">
      <span class="ar-int__mark" aria-hidden="true">{{ mark }}</span>
      <h3 class="ar-int__name">{{ name }}</h3>
    </div>
    <p class="ar-int__blurb">{{ blurb }}</p>
    <!-- The card's own status line (the webhook's last delivered / last error). -->
    <slot name="note"></slot>
    <div class="ar-int__foot">
      <span class="ar-int__chip" :class="{ 'is-on': chip.tone === 'on' }">{{ chip.label }}</span>
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
