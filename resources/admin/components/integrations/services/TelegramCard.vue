<script>
/**
 * Telegram's card on the SERVICES tab — events as messages from a bot the
 * owner made, so a phone learns what the site saw.
 *
 * Same grammar as every service card: state and invite, never do. The doing
 * (token, chat id, the two-step proof) lives in the panel the parent opens.
 * The honesty line is serviceNote's — the shared dialect.
 */
import IntegrationCard from '../IntegrationCard.vue';
import { serviceNote } from '../../../js/serviceNote.js';

export default {
  name: 'TelegramCard',
  components: { IntegrationCard },
  props: {
    // The GET /integrations telegram block: { enabled, chat, events, tier, hasToken, queued, state }.
    telegram: { type: Object, required: true },
  },
  emits: ['open'],
  computed: {
    chip() {
      return this.telegram.enabled
        ? { label: 'Connected', tone: 'on' }
        : { label: 'Off', tone: '' };
    },
    action() {
      return this.telegram.enabled ? 'Manage' : 'Connect';
    },
    note() {
      return serviceNote(this.telegram);
    },
  },
};
</script>

<template>
  <IntegrationCard
    mark="Tg"
    name="Telegram"
    blurb="Events as messages from a bot you own — wherever you use Telegram, your site's news finds you."
    :chip="chip"
    :action="action"
    @act="$emit('open')"
  >
    <template #note>
      <p v-if="note.text" class="ar-int__note" :class="{ 'is-err': note.isError }">{{ note.text }}</p>
    </template>
  </IntegrationCard>
</template>
