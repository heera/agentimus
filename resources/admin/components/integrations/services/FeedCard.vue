<script>
/**
 * The private feed's card on the SERVICES tab — the one service that never
 * calls anybody. Readers come to a tokened URL, so this card's honesty line
 * is the mirror image of its siblings': not "last delivered" but LAST
 * FETCHED — when a reader last actually came. A feed nobody polls says so,
 * plainly, where the owner looks.
 *
 * Same grammar as every service card: state and invite, never do. The doing
 * (the event choices, the URL's one sighting, rotation) lives in the modal
 * the parent opens.
 */
import IntegrationCard from '../IntegrationCard.vue';
import { formatStamp } from '../../../js/wpDate.js';

export default {
  name: 'FeedCard',
  components: { IntegrationCard },
  props: {
    // The GET /integrations feed block:
    // { enabled, events, hasToken, lastFetchedAt, queued, state }.
    feed: { type: Object, required: true },
  },
  emits: ['open'],
  computed: {
    chip() {
      return this.feed.enabled
        ? { label: 'Connected', tone: 'on' }
        : { label: 'Off', tone: '' };
    },
    action() {
      return this.feed.enabled ? 'Manage' : 'Connect';
    },
    // The fetch-side honesty line. Deliveries into the ring can't fail (they
    // are an option write), so the only truth worth a line is whether anyone
    // is actually reading.
    note() {
      if (!this.feed.enabled) return { text: '', isError: false };
      if (this.feed.lastFetchedAt) {
        return {
          text: `Last fetched ${formatStamp(new Date(this.feed.lastFetchedAt * 1000))}`,
          isError: false,
        };
      }
      return { text: 'Connected — no reader has fetched it yet.', isError: false };
    },
  },
};
</script>

<template>
  <IntegrationCard
    mark="Fd"
    name="Private feed"
    blurb="What Agentimus finds, as RSS or JSON Feed at a private URL — nothing is sent out; your reader comes to it."
    :chip="chip"
    :action="action"
    @act="$emit('open')"
  >
    <template #note>
      <p v-if="note.text" class="ar-int__note" :class="{ 'is-err': note.isError }">{{ note.text }}</p>
    </template>
  </IntegrationCard>
</template>
