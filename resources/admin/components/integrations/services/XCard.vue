<script>
/**
 * X's card on the SERVICES tab — a connection, not an event receiver. Unlike
 * every neighbour it subscribes to nothing: an announcement is the owner's
 * public voice, and telemetry does not belong in it. The card says so
 * instead of wearing an empty checkbox list.
 *
 * Same grammar as the roster: state and invite, never do. The doing (the
 * client id, the authorize round-trip) lives in the panel the parent opens.
 */
import IntegrationCard from '../IntegrationCard.vue';

export default {
  name: 'XCard',
  components: { IntegrationCard },
  props: {
    // The payload's sharing.x block: { connected, handle, refreshError, connectError, … }.
    x: { type: Object, required: true },
  },
  emits: ['open'],
  computed: {
    chip() {
      return this.x.connected
        ? { label: 'Connected', tone: 'on' }
        : { label: 'Not connected', tone: '' };
    },
    action() {
      return this.x.connected ? 'Manage' : 'Connect';
    },
    note() {
      if (this.x.refreshError) return { text: `Renewal refused — announcing is paused: ${this.x.refreshError}`, isError: true };
      if (this.x.connectError) return { text: this.x.connectError, isError: true };
      if (this.x.connected) return { text: `Connected as @${this.x.handle || '…'} · renews itself`, isError: false };
      return { text: '', isError: false };
    },
  },
};
</script>

<template>
  <IntegrationCard
    brand="x"
    mark="X"
    name="Twitter"
    blurb="Posts your announcements through an app you own. X receives no events — only what you queue on Sharing."
    :chip="chip"
    :action="action"
    @act="$emit('open')"
  >
    <template #note>
      <p v-if="note.text" class="ar-int__note" :class="{ 'is-err': note.isError }">{{ note.text }}</p>
    </template>
  </IntegrationCard>
</template>
