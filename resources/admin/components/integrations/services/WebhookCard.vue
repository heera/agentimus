<script>
/**
 * The webhook's card on the SERVICES tab — the one service that works today.
 *
 * The card carries the connection's honesty line: last delivered, or the last
 * error, in that order of hope. A connected webhook that has been silently
 * failing must confess it HERE, on the card, where the owner actually looks —
 * not in a log nobody opens. All the doing (connect, save, disconnect, the
 * secret) lives in the panel the parent opens; this card only states and
 * invites.
 */
import IntegrationCard from '../IntegrationCard.vue';
import { formatStamp } from '../../../js/wpDate.js';

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
    // One line, the freshest truth first: an error outranks old good news.
    note() {
      if (!this.webhook.enabled) return '';
      const s = this.webhook.state || {};
      if (s.lastError) {
        return `Last error: ${s.lastError}${s.lastErrorAt ? ` — ${this.when(s.lastErrorAt)}` : ''}`;
      }
      if (s.lastDeliveredAt) {
        let line = `Last delivered ${this.when(s.lastDeliveredAt)}`;
        if (this.webhook.queued > 0) line += ` — ${this.webhook.queued} waiting`;
        return line;
      }
      return this.webhook.queued > 0
        ? `${this.webhook.queued} ${this.webhook.queued === 1 ? 'event' : 'events'} waiting for the next delivery run`
        : 'Connected — nothing has happened to send yet.';
    },
    noteIsError() {
      return !!(this.webhook.enabled && this.webhook.state && this.webhook.state.lastError);
    },
  },
  methods: {
    when(unix) {
      return formatStamp(new Date(unix * 1000));
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
      <p v-if="note" class="ar-int__note" :class="{ 'is-err': noteIsError }">{{ note }}</p>
    </template>
  </IntegrationCard>
</template>
