<script>
/**
 * Slack's card on the SERVICES tab — events into a channel, where the team
 * already looks.
 *
 * Same grammar as every service card: state and invite, never do. The doing
 * (the incoming-webhook URL, the event choices) lives in the panel the parent
 * opens. The honesty line is serviceNote's — the shared dialect.
 */
import IntegrationCard from '../IntegrationCard.vue';
import { serviceNote } from '../../../js/serviceNote.js';

export default {
  name: 'SlackCard',
  components: { IntegrationCard },
  props: {
    // The GET /integrations slack block: { enabled, url, events, queued, state }.
    slack: { type: Object, required: true },
  },
  emits: ['open'],
  computed: {
    chip() {
      return this.slack.enabled
        ? { label: 'Connected', tone: 'on' }
        : { label: 'Off', tone: '' };
    },
    action() {
      return this.slack.enabled ? 'Manage' : 'Connect';
    },
    note() {
      return serviceNote(this.slack);
    },
  },
};
</script>

<template>
  <IntegrationCard
    brand="slack"
    mark="Sl"
    name="Slack"
    blurb="New findings, impostor alerts and the weekly digest, posted into a channel you pick — your site speaking up where your team already works."
    :chip="chip"
    :action="action"
    @act="$emit('open')"
  >
    <template #note>
      <p v-if="note.text" class="ar-int__note" :class="{ 'is-err': note.isError }">{{ note.text }}</p>
    </template>
  </IntegrationCard>
</template>
