<script>
/**
 * The webhook's card on the SERVICES tab — the first service that worked.
 *
 * The card carries the connection's honesty line: last delivered, or the last
 * error, in that order of hope. A connected webhook that has been silently
 * failing must confess it HERE, on the card, where the owner actually looks —
 * not in a log nobody opens. All the doing (connect, save, disconnect, the
 * secret) lives in the panel the parent opens; this card only states and
 * invites. The line itself comes from serviceNote — one dialect for every
 * service card.
 */
import IntegrationCard from '../IntegrationCard.vue';
import { serviceNote } from '../../../js/serviceNote.js';

export default {
  name: 'WebhookCard',
  components: { IntegrationCard },
  props: {
    // The GET /integrations webhook block: { enabled, url, events, hasSecret, queued, state }.
    webhook: { type: Object, required: true },
  },
  emits: ['open'],
  computed: {
    chip() {
      return this.webhook.enabled
        ? { label: 'Connected', tone: 'on' }
        : { label: 'Off', tone: '' };
    },
    action() {
      return this.webhook.enabled ? 'Manage' : 'Connect';
    },
    note() {
      return serviceNote(this.webhook);
    },
  },
};
</script>

<template>
  <IntegrationCard
    mark="Wh"
    name="Webhook"
    blurb="Events from this site, sent to any URL as signed JSON — the door to Zapier, Make, n8n and your own scripts."
    :chip="chip"
    :action="action"
    @act="$emit('open')"
  >
    <template #note>
      <p v-if="note.text" class="ar-int__note" :class="{ 'is-err': note.isError }">{{ note.text }}</p>
    </template>
  </IntegrationCard>
</template>
