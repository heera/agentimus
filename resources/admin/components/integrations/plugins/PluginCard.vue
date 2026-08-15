<script>
/**
 * One provider row on the PLUGINS tab, from the server's describe() payload:
 * { id, name, blurb, present, describes }.
 *
 * ⭐ THREE states, not two. Installed and described are different facts, and
 * the card used to show only the first: on a site with the whole family
 * installed it read "Described" eight times over, while four of those plugins
 * keep everything behind a login and are described nowhere. A tick that is
 * always on says nothing. So a plugin that is here but has nothing public
 * says exactly that, and takes the sentence that explains it.
 *
 * Absent keeps its blurb — there it reads as the offer, "this is what would
 * happen if you installed it", which is the honest answer to "what would
 * Agentimus do more of here?".
 *
 * No action button: there is nothing to configure on a provider, and this
 * grid does not grow dead controls.
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
  fluentboards: 'fluentboards',
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
  fluentboards: 'Bd',
  fluentsupport: 'Sp',
  edd: 'Ed',
};

export default {
  name: 'PluginCard',
  components: { IntegrationCard },
  props: {
    plugin: { type: Object, required: true }, // { id, name, blurb, present, describes }
  },
  computed: {
    brand() {
      return BRANDS[this.plugin.id] || '';
    },
    mark() {
      return MARKS[this.plugin.id] || (this.plugin.name || '?').slice(0, 2);
    },
    chip() {
      if (!this.plugin.present) return { label: 'Not installed', tone: '' };
      return this.plugin.describes
        ? { label: 'Described', tone: 'on' }
        : { label: 'Nothing public', tone: '' };
    },
    // ⛔ Never claim a cause we did not check. The plugin is here and nothing
    // of its own is public — that is all this says.
    line() {
      if (this.plugin.present && !this.plugin.describes) {
        return 'Installed. Nothing in it is public, so there is nothing to pass on.';
      }
      return this.plugin.blurb;
    },
  },
};
</script>

<template>
  <IntegrationCard :brand="brand" :mark="mark" :name="plugin.name" :blurb="line" :chip="chip" />
</template>
