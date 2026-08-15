<script>
/**
 * The connection status rail shared by the data-source cards (Cloudflare, Bing,
 * Google): a state dot, then "Connected · <label> · numbers as of <polledText>
 * · Last poll failed: <lastError>", or "Not connected · <idleNote>", or
 * "Checking the connection…" before the first status read answers.
 *
 * Three states, never two — "still asking" must not flash as "Not connected",
 * an untrue word even for 200ms. Purely presentational: the parent owns the
 * connection state and the per-provider wording.
 */
import { plainError } from '../js/plainError.js';

export default {
  name: 'ConnectionRail',
  props: {
    connected: { type: Boolean, default: false },
    checked: { type: Boolean, default: false },
    label: { type: String, default: '' },      // host / zone / property, shown when connected
    polledText: { type: String, default: '' }, // "numbers as of X", or '' to omit the clause
    lastError: { type: String, default: '' },  // last poll error, or '' to omit the clause
    idleNote: { type: String, default: '' },   // the read-only reassurance shown when not connected
    // The service's own name, so a failure can say which side went quiet.
    service: { type: String, default: 'the service' },
  },
  computed: {
    // The owner reads what happened; the machine's own sentence stays one
    // hover away, never rewritten and never lost.
    failure() {
      return plainError(this.lastError, this.service);
    },
  },
};
</script>

<template>
  <div class="ar-mcp-rail" :data-state="connected ? 'running' : (checked ? 'unsaved' : 'idle')">
    <span class="ar-mcp-rail__dot" aria-hidden="true"></span>
    <template v-if="connected">
      <strong>Connected</strong>
      <span class="ar-mcp-rail__sep" aria-hidden="true">·</span><span>{{ label }}</span>
      <template v-if="polledText">
        <span class="ar-mcp-rail__sep" aria-hidden="true">·</span><span>numbers as of {{ polledText }}</span>
      </template>
      <template v-if="lastError">
        <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
        <span class="ar-warn">Last poll failed. {{ failure.text }}</span>
        <!-- The original, for whoever needs it — a developer, a support
             thread — without putting cURL in the owner's way. -->
        <span v-if="failure.technical" class="ar-tipwrap">
          <button type="button" class="ar-tipbtn" :aria-label="'The exact message: ' + failure.technical">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9.2" /><path d="M12 7.6v5.2" /><path d="M12 16.4h.01" /></svg>
          </button>
          <span class="ar-tip" aria-hidden="true">{{ failure.technical }}</span>
        </span>
      </template>
    </template>
    <template v-else-if="checked">
      <strong>Not connected.</strong>
      <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
      <span>{{ idleNote }}</span>
    </template>
    <template v-else>
      <span>Checking the connection…</span>
    </template>
  </div>
</template>
