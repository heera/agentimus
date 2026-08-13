<script>
/**
 * LinkedIn's card on the SERVICES tab — a connection, not an event receiver;
 * the same nature as X's card beside it. Its one personality of its own is
 * the calendar: LinkedIn's grant lives about sixty days and does not renew
 * itself, so this card is where "Reconnect by …" first appears — quietly
 * while days remain, as the card's error tone once they have run out.
 *
 * Same grammar as the roster: state and invite, never do. The doing (the
 * app's id and secret, the authorize round-trip) lives in the panel the
 * parent opens.
 */
import IntegrationCard from '../IntegrationCard.vue';
import { formatDate } from '../../../js/wpDate.js';

export default {
  name: 'LinkedInCard',
  components: { IntegrationCard },
  props: {
    // The payload's sharing.linkedin block: { connected, expired, expiresAt, name, connectError, … }.
    linkedin: { type: Object, required: true },
  },
  emits: ['open'],
  computed: {
    chip() {
      if (this.linkedin.connected && this.linkedin.expired) return { label: 'Reconnect', tone: 'err' };
      return this.linkedin.connected
        ? { label: 'Connected', tone: 'on' }
        : { label: 'Not connected', tone: '' };
    },
    action() {
      if (this.linkedin.connected && this.linkedin.expired) return 'Reconnect';
      return this.linkedin.connected ? 'Manage' : 'Connect';
    },
    note() {
      if (this.linkedin.connected && this.linkedin.expired) {
        return { text: 'The sixty-day access ran out — reconnect and announcing resumes.', isError: true };
      }
      if (this.linkedin.connectError) return { text: this.linkedin.connectError, isError: true };
      if (this.linkedin.connected) {
        const by = this.linkedin.expiresAt ? formatDate(new Date(this.linkedin.expiresAt * 1000)) : '';
        return { text: `Connected as ${this.linkedin.name || '…'}${by ? ` · reconnect by ${by}` : ''}`, isError: false };
      }
      return { text: '', isError: false };
    },
  },
};
</script>

<template>
  <IntegrationCard
    mark="In"
    name="LinkedIn"
    blurb="Posts your announcements through an app you own. LinkedIn receives no events — only what you queue on Sharing."
    :chip="chip"
    :action="action"
    @act="$emit('open')"
  >
    <template #note>
      <p v-if="note.text" class="ar-int__note" :class="{ 'is-err': note.isError }">{{ note.text }}</p>
    </template>
  </IntegrationCard>
</template>
