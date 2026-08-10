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
export default {
  name: 'ConnectionRail',
  props: {
    connected: { type: Boolean, default: false },
    checked: { type: Boolean, default: false },
    label: { type: String, default: '' },      // host / zone / property, shown when connected
    polledText: { type: String, default: '' }, // "numbers as of X", or '' to omit the clause
    lastError: { type: String, default: '' },  // last poll error, or '' to omit the clause
    idleNote: { type: String, default: '' },   // the read-only reassurance shown when not connected
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
        <span class="ar-mcp-rail__sep" aria-hidden="true">·</span><span class="ar-warn">Last poll failed: {{ lastError }}.</span>
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
