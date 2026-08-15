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
    brand="webhook"
    mark="Wh"
    name="Webhook"
    blurb="For every tool this shelf doesn't name. Agentimus sends each event to one web address you paste — automation services like Zapier, Make and n8n catch it there and turn it into anything: an email, a task, a row in Notion. Developers get the same events as signed JSON for their own code."
    :chip="chip"
    :action="action"
    @act="$emit('open')"
  >
    <template #note>
      <p v-if="note.text" class="ar-int__note" :class="{ 'is-err': note.isError }">{{ note.text }}</p>
    </template>
  </IntegrationCard>
</template>
