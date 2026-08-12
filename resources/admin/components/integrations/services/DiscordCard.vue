<script>
/**
 * Discord's card on the SERVICES tab — the same events, posted to a server
 * the owner runs.
 *
 * Same grammar as every service card: state and invite, never do. The doing
 * (the webhook URL, the event choices) lives in the panel the parent opens.
 * The honesty line is serviceNote's — the shared dialect.
 */
import IntegrationCard from '../IntegrationCard.vue';
import { serviceNote } from '../../../js/serviceNote.js';

export default {
  name: 'DiscordCard',
  components: { IntegrationCard },
  props: {
    // The GET /integrations discord block: { enabled, url, events, queued, state }.
    discord: { type: Object, required: true },
  },
  emits: ['open'],
  computed: {
    chip() {
      return this.discord.enabled
        ? { label: 'Connected', tone: 'on' }
        : { label: 'Off', tone: '' };
    },
    action() {
      return this.discord.enabled ? 'Manage' : 'Connect';
    },
    note() {
      return serviceNote(this.discord);
    },
  },
};
</script>

<template>
  <IntegrationCard
    mark="Dc"
    name="Discord"
    blurb="The same events, posted to a server you run."
    :chip="chip"
    :action="action"
    @act="$emit('open')"
  >
    <template #note>
      <p v-if="note.text" class="ar-int__note" :class="{ 'is-err': note.isError }">{{ note.text }}</p>
    </template>
  </IntegrationCard>
</template>
