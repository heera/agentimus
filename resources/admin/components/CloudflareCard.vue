<script>
/**
 * The Cloudflare data-source card — connect a scoped API token, see the
 * connection's state, toggle cache-purge-on-change, and read the standing
 * conflicts ledger. Lifted whole out of SettingsForm's "sources" group; it owns
 * all of its own `cf*` state and talks only to the API, so the parent passes
 * just the shared `settings` object (for the cache-purge toggle) and the api
 * client. Errors stay inline (`cfError`) — no toast, no parent round-trip.
 *
 * `active` mirrors "the sources tab is showing": the card stays mounted behind
 * the tab's v-show, so it defers its first status read until first shown, the
 * same lazy load the parent used to do from its group watch.
 */
import { confirm } from '../js/confirm.js';
import { formatDate, formatTime } from '../js/wpDate.js';
import ConnectionRail from './ConnectionRail.vue';
import CardSkeleton from './CardSkeleton.vue';
import AppLink from './AppLink.vue';

export default {
  name: 'CloudflareCard',
  components: { ConnectionRail, CardSkeleton, AppLink },
  props: {
    settings: { type: Object, required: true },
    api: { type: Object, default: null },
    active: { type: Boolean, default: false },
  },
  data() {
    return {
      cf: null, // The /cloudflare status payload, or null before the first load.
      cfToken: '', // The paste field. Sent once on Connect, then cleared — never stored client-side.
      cfConnecting: false,
      cfDisconnecting: false,
      cfError: '', // The last connect/disconnect failure, shown above the field.
      cfJustConnected: false, // Shows the "first numbers are in" pointer right after a connect.
      cfChecked: false, // False until the first status read answers — the UI must not claim "Not connected" while still asking.
      cfConflicts: [], // EVERY active conflict, hidden-on-log or not — this ledger empties only when situations really end.
    };
  },
  watch: {
    // Defer the first status read until the sources tab is actually shown — the
    // same lazy load the parent's group watch used to trigger.
    active(on) {
      if (on && !this.cf) this.loadCloudflare();
    },
  },
  mounted() {
    // Covers a deep-link that opens straight on the sources tab (the watch only
    // fires on a change). The !cf guard keeps it from double-loading.
    if (this.active && !this.cf) this.loadCloudflare();
  },
  methods: {
    async loadCloudflare() {
      if (!this.api) return;
      try {
        this.cf = await this.api.getCloudflareStatus();
      } catch (e) {
        // Leave cf null — the card renders its connect state; a failed status
        // read is not worth a banner.
      } finally {
        this.cfChecked = true;
      }
      this.loadCfConflicts();
    },
    async loadCfConflicts() {
      if (!this.api || !this.cf || !this.cf.connected) {
        this.cfConflicts = [];
        return;
      }
      try {
        const s = await this.api.getCloudflareSummary(7);
        const shown = (s.conflicts || []).map((c) => ({ ...c, hiddenOnLog: false }));
        const hidden = (s.hiddenConflicts || []).map((c) => ({ ...c, hiddenOnLog: true }));
        this.cfConflicts = [...shown, ...hidden].sort(
          (a, b) => (a.level === 'warn' ? 0 : 1) - (b.level === 'warn' ? 0 : 1)
        );
      } catch (e) {
        this.cfConflicts = [];
      }
    },
    async connectCloudflare() {
      const token = this.cfToken.trim();
      if (!token || this.cfConnecting || !this.api) return;
      this.cfConnecting = true;
      this.cfError = '';
      try {
        // The server verifies the token, finds the zone, then runs a first poll
        // inline — so success means there are already numbers to look at.
        this.cf = await this.api.connectCloudflare(token);
        this.cfToken = '';
        this.cfJustConnected = true;
      } catch (e) {
        this.cfError = e && e.message ? e.message : 'Could not connect to Cloudflare.';
      } finally {
        this.cfConnecting = false;
      }
    },
    async disconnectCloudflare() {
      if (this.cfDisconnecting || !this.api) return;
      const ok = await confirm({
        title: 'Disconnect Cloudflare?',
        message: 'Agentimus forgets the token and stops polling right away. The Cloudflare numbers already stored stay in your database, and reconnecting resumes where it left off.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      this.cfDisconnecting = true;
      this.cfError = '';
      try {
        this.cf = await this.api.disconnectCloudflare();
        this.cfJustConnected = false;
      } catch (e) {
        this.cfError = e && e.message ? e.message : 'Could not disconnect.';
      } finally {
        this.cfDisconnecting = false;
      }
    },
    cfPolledText() {
      if (!this.cf || !this.cf.lastPollAt) return '';
      const d = new Date(this.cf.lastPollAt * 1000);
      return `${formatDate(d)} ${formatTime(d)}`;
    },
  },
};
</script>

<template>
  <section id="ar-sec-cloudflare" class="ar-card">
    <h2 class="ar-card__title">Cloudflare</h2>
    <p class="ar-card__lead">
      Cloudflare stands in front of your site. It answers many AI requests from its cache
      and blocks some — your server never sees those. Connect it and the
      <AppLink to="#log">Request Log</AppLink> screen shows what Cloudflare saw, and warns you
      when Cloudflare and your site policy disagree.
    </p>

    <!-- Three states, not two: "still asking" must not flash as "Not
         connected" — an untrue word, even for 200ms, reads as a glitch. -->
    <ConnectionRail
      service="Cloudflare"
      :connected="!!(cf && cf.connected)"
      :checked="cfChecked"
      :label="cf ? 'zone ' + cf.zoneName : ''"
      :polled-text="cfPolledText()"
      :last-error="(cf && cf.lastError) || ''"
      idle-note="Read-only — Agentimus never changes your Cloudflare settings."
    />

    <!-- The card's body arrives all at once when the status read answers, and
         a one-line "checking…" card leaping into a wall of settings shoves
         every card below it down the page. The placeholder holds roughly the
         room the real body will need, button included (his call, 2026-08-15). -->
    <CardSkeleton
      v-if="!cfChecked"
      lead=""
      :lines="['92%', '78%', '64%', '86%']"
      action
    />

    <p v-if="cfError" class="ar-field__hint ar-warn">{{ cfError }}</p>

    <template v-if="cfChecked && (!cf || !cf.connected)">
      <p class="ar-mcp-eyebrow">Connect Cloudflare</p>
      <div class="ar-cf-row">
        <input
          v-model="cfToken"
          class="ar-input"
          type="password"
          autocomplete="off"
          spellcheck="false"
          placeholder="Paste your Cloudflare API token"
          aria-label="Cloudflare API token"
          @keyup.enter="connectCloudflare"
        />
        <button type="button" class="ar-btn" :disabled="cfConnecting || !cfToken.trim()" @click="connectCloudflare">
          {{ cfConnecting ? 'Connecting…' : 'Connect' }}
        </button>
      </div>
      <p class="ar-field__hint">
        Stored like your other secrets — encrypted, never shown again, and sent nowhere
        except Cloudflare’s own API.
      </p>
      <div class="ar-mcp-recipe">
        <ol class="ar-mcp-recipe__steps">
          <li>In Cloudflare, open <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener"><code>My Profile → API Tokens</code></a> → Create Token.</li>
          <li>Give it one permission: <code>Zone → Analytics → Read</code>, for this site’s zone only.</li>
          <li>Optional: also add <code>Zone → Cache Purge → Purge</code>. Then publishing a post clears its old copies from Cloudflare’s cache, and the Cloudflare panel gets a Purge button.</li>
          <li>Paste it here. Agentimus finds the zone by itself.</li>
        </ol>
      </div>
    </template>

    <template v-else-if="cf && cf.connected">
      <p v-if="cfJustConnected" class="ar-field__hint">
        <strong>First numbers are in.</strong> See them on the <AppLink to="#log">Request Log</AppLink> screen.
      </p>
      <p class="ar-field__hint">
        One scoped token, one hourly poll. Numbers are stored in your own database, so
        your history outlives Cloudflare’s short Free-plan window. If the token also
        carries the <code>Cache Purge</code> permission, publishing clears the changed
        pages from Cloudflare’s cache — that is the only thing Agentimus ever asks
        Cloudflare to change.
      </p>
      <label id="ar-feat-cf_purge_on_change" class="ar-toggle ar-toggle--nested">
        <input v-model="settings.cf_purge_on_change" type="checkbox" />
        <span class="ar-toggle__track" aria-hidden="true"></span>
        <span class="ar-toggle__text">
          <strong>Clear Cloudflare’s cache when content changes</strong>
          <small>When you publish or edit, the changed pages are cleared from Cloudflare: the post itself, the front page and your AI files. This is the one cache no caching plugin can reach. It needs the <code>Cache Purge</code> permission on your token. Without it, the attempt is refused, and Agentimus stops asking until you fix the token and press Purge once. Turn this off to keep the token read-only. Either way, the Purge button on the Request Log’s Cloudflare panel still works.</small>
        </span>
      </label>
      <button type="button" class="ar-btn ar-btn--danger ar-btn--small" :disabled="cfDisconnecting" @click="disconnectCloudflare">
        {{ cfDisconnecting ? 'Disconnecting…' : 'Disconnect' }}
      </button>
      <!-- The warnings ledger: EVERY active conflict, always — hiding a pin
           on the Request Log hides it THERE only. A card leaves this list
           when its situation really ends, never by hand.
           Empty = no block, no separator. -->
      <div v-if="cfConflicts.length" class="ar-cf-hiddenblk">
        <p class="ar-mcp-eyebrow">Warnings</p>
        <p class="ar-field__hint">
          Everything Cloudflare and your site policy disagree about right now — including
          warnings you hid on the Request Log screen. A card leaves this list only when
          its situation is really fixed.
        </p>
        <div class="ar-edge-pins">
          <div v-for="c in cfConflicts" :key="c.id" class="ar-edge-pin" :class="`ar-edge-pin--${c.level}`">
            <span class="ar-edge-pin__badge">{{ c.level === 'warn' ? 'Conflict' : 'Not enforced' }}</span>
            <p class="ar-edge-pin__title">{{ c.title }}</p>
            <p class="ar-edge-pin__body">{{ c.body }}</p>
            <div class="ar-edge-pin__actions">
              <a class="ar-linkbtn" :href="c.url" target="_blank" rel="noopener">Review in Cloudflare</a>
              <span v-if="c.hiddenOnLog" class="ar-cf-hiddenmark">hidden on the Request Log</span>
            </div>
          </div>
        </div>
      </div>
    </template>

  </section>
</template>
