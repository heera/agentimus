<script>
/**
 * One provider row on the PLUGINS tab, from the server's describe() payload:
 * { id, name, blurb, present }. Present means "Described" — the plugin's
 * content joins what this site describes to AI assistants; absent is stated
 * as plainly ("Not installed") rather than hidden, because the roster doubles
 * as the honest answer to "what would Agentimus do more of here?".
 *
 * No action button in phase one: there is nothing to configure on a provider
 * yet, and this grid does not grow dead controls.
 */
import IntegrationCard from '../IntegrationCard.vue';

// The vendor's own mark where we hold a faithful one (brandMarks.js). A
// provider missing from here keeps its monogram below — an honest stand-in
// beats a drawing that only resembles somebody's logo.
const BRANDS = {
  woocommerce: 'woocommerce',
  fluentcart: 'fluentcart',
  fluentcrm: 'fluentcrm',
  fluentsupport: 'fluentsupport',
  fluentforms: 'fluentforms',
  fluentbooking: 'fluentbooking',
  fluentcommunity: 'fluentcommunity',
  edd: 'edd',
};

// Two letters for anything the registry has no mark for — nothing today, but
// the roster grows, and a new provider must not ship a blank tile.
const MARKS = {
  woocommerce: 'Wc',
  fluentcart: 'Ct',
  fluentforms: 'Ff',
  fluentcrm: 'Cr',
  fluentbooking: 'Bk',
  fluentcommunity: 'Cm',
  fluentsupport: 'Sp',
  edd: 'Ed',
};

export default {
  name: 'PluginCard',
  components: { IntegrationCard },
  props: {
    plugin: { type: Object, required: true }, // { id, name, blurb, present }
  },
  computed: {
    brand() {
      return BRANDS[this.plugin.id] || '';
    },
    mark() {
      return MARKS[this.plugin.id] || (this.plugin.name || '?').slice(0, 2);
    },
    chip() {
      return this.plugin.present
        ? { label: 'Described', tone: 'on' }
        : { label: 'Not installed', tone: '' };
    },
  },
};
</script>

<template>
  <IntegrationCard :brand="brand" :mark="mark" :name="plugin.name" :blurb="plugin.blurb" :chip="chip" />
</template>
