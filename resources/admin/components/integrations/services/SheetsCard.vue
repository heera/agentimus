<script>
/**
 * Google Sheets' card on the SERVICES tab — events appended as rows to a
 * spreadsheet the owner names: a history that outlives the 30-day log.
 *
 * Same grammar as every service card: state and invite, never do. The doing
 * (the spreadsheet ID, the share-with-the-service-account teaching) lives in
 * the modal the parent opens. One state is this card's own: NO GOOGLE
 * CONNECTION. The service borrows the Google connection's service-account key
 * — never a second credential — so without one the card says so plainly,
 * points at Settings → Data sources, and offers no dead Connect to click.
 * The honesty line is serviceNote's — the shared dialect.
 */
import IntegrationCard from '../IntegrationCard.vue';
import { serviceNote } from '../../../js/serviceNote.js';

export default {
  name: 'SheetsCard',
  components: { IntegrationCard },
  props: {
    // The GET /integrations sheets block:
    // { enabled, spreadsheet, events, hasKey, saEmail, queued, state }.
    sheets: { type: Object, required: true },
  },
  emits: ['open'],
  computed: {
    chip() {
      if (this.sheets.enabled) return { label: 'Connected', tone: 'on' };
      if (!this.sheets.hasKey) return { label: 'Needs Google', tone: '' };
      return { label: 'Off', tone: '' };
    },
    // No key, not connected → no control at all (dead controls are the one
    // thing this grid refuses to grow); the note below says what to do.
    action() {
      if (this.sheets.enabled) return 'Manage';
      return this.sheets.hasKey ? 'Connect' : '';
    },
    note() {
      if (!this.sheets.hasKey) {
        return this.sheets.enabled
          ? {
              // Connected but the borrowed key left (a Google disconnect):
              // the standing truth, in error tone — nothing can deliver.
              text: 'The Google connection’s key is gone, so nothing can be appended. Reconnect Google under Settings → Data sources, or disconnect here.',
              isError: true,
            }
          : {
              text: 'Uses your Google connection’s service-account key — add it under Settings → Data sources first.',
              isError: false,
            };
      }
      return serviceNote(this.sheets);
    },
  },
};
</script>

<template>
  <IntegrationCard
    mark="Sh"
    name="Google Sheets"
    blurb="Events appended to a sheet you name — a history that outlives the 30-day log."
    :chip="chip"
    :action="action"
    @act="$emit('open')"
  >
    <template #note>
      <p v-if="note.text" class="ar-int__note" :class="{ 'is-err': note.isError }">{{ note.text }}</p>
    </template>
  </IntegrationCard>
</template>
