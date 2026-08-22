<script>
/**
 * One provider row on the PLUGINS tab, from the server's describe() payload:
 * { id, name, blurb, present, describes, home }.
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
 * ⭐ ONE action, and it is a link out: the plugin's own page (his ask,
 * 2026-08-22). There is still nothing to CONFIGURE on a provider — the card
 * grows no button — but "what is this thing?" is a real question, and six of the
 * eight cards on a typical site are plugins the owner does not run yet.
 * ⛔ A provider with no verified address renders no control at all, exactly as
 * before. This grid still does not grow dead controls, and it will not send
 * anyone to a guessed address either.
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
    plugin: { type: Object, required: true }, // { id, name, blurb, present, describes, home }
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
    // ⛔ ONE WORD FOR ALL EIGHT, not "Website" on the installed ones and "Learn
    // more" on the rest. Two reasons, and both are this card's own rules:
    //   · This component's contract is a UNIFORM-width chip and a uniform ghost
    //     action — "a column of cards must read as a column of states". Two
    //     labels are two button widths, and the footer row goes ragged.
    //   · "Learn more" is advertising copy, and seven of these eight are the
    //     owner's own company's plugins. Agentimus naming a destination is a
    //     fact; Agentimus selling one is not its job.
    // ⭐ "Plugin website" — HIS WORDING, 2026-08-22, and the extra word earns its
    // place: on a screen whose other tab is full of SERVICES this site connects
    // to, a bare "Website" leaves open whose website it is.
    // ⚠️ Sentence case, not Title Case: the app writes its buttons that way
    // ("Clear history", "Get a key", "Set up sharing", "Write a review") and only
    // Revoke/Rotate Token break it. ⛔ Not the Title-Case rule — that governs
    // titles the PLUGIN GENERATES, not controls in its own chrome.
    actionLabel() {
      return this.plugin.home ? 'Plugin website' : '';
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
  <IntegrationCard
    :brand="brand"
    :mark="mark"
    :name="plugin.name"
    :blurb="line"
    :chip="chip"
    :action="actionLabel"
    :action-href="plugin.home || ''"
  />
</template>
