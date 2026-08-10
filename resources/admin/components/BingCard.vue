<script>
/**
 * The Bing data-source card — verify the site (msvalidate tag), connect a
 * read-only Webmaster API key, and toggle IndexNow (which needs no Bing
 * connection at all). Lifted whole out of SettingsForm's "sources" group; it
 * owns its own `bing*` state and talks only to the API, so the parent passes
 * the shared `settings` object (the IndexNow toggle), the api client, and the
 * IndexNow key-file URL. Errors stay inline (`bingError`) — no toast.
 *
 * `active` mirrors "the sources tab is showing": the card stays mounted behind
 * the tab's v-show, so it defers its first status read until first shown.
 */
import { confirm } from '../js/confirm.js';
import { formatDate, formatTime } from '../js/wpDate.js';
import ConnectionRail from './ConnectionRail.vue';

export default {
  name: 'BingCard',
  components: { ConnectionRail },
  props: {
    settings: { type: Object, required: true },
    api: { type: Object, default: null },
    active: { type: Boolean, default: false },
    indexnowKeyUrl: { type: String, default: '' },
  },
  data() {
    return {
      bing: null, // Bing connection status (never the key).
      bingChecked: false, // Same three-state honesty as the Cloudflare rail.
      bingCode: '', // The msvalidate paste field; cleared after save.
      bingKey: '', // The API key paste field. Sent once on Connect, then cleared.
      bingSavingCode: false,
      bingConnecting: false,
      bingDisconnecting: false,
      bingError: '',
      bingJustConnected: false,
    };
  },
  watch: {
    active(on) {
      if (on && !this.bing) this.loadBing();
    },
  },
  mounted() {
    if (this.active && !this.bing) this.loadBing();
  },
  methods: {
    async loadBing() {
      if (!this.api) return;
      try {
        this.bing = await this.api.getBingStatus();
      } catch (e) {
        // Leave bing null — a failed status read is not worth a banner.
      } finally {
        this.bingChecked = true;
      }
    },
    async saveBingCode() {
      const code = this.bingCode.trim();
      if (!code || this.bingSavingCode || !this.api) return;
      this.bingSavingCode = true;
      this.bingError = '';
      try {
        this.bing = await this.api.saveBingCode(code);
        this.bingCode = '';
      } catch (e) {
        this.bingError = (e && e.message) || 'Could not save the verification code.';
      } finally {
        this.bingSavingCode = false;
      }
    },
    async connectBing() {
      const key = this.bingKey.trim();
      if (!key || this.bingConnecting || !this.api) return;
      this.bingConnecting = true;
      this.bingError = '';
      try {
        this.bing = await this.api.connectBing(key);
        this.bingKey = '';
        this.bingJustConnected = true;
      } catch (e) {
        this.bingError = (e && e.message) || 'Could not connect to Bing.';
      } finally {
        this.bingConnecting = false;
      }
    },
    async disconnectBing() {
      if (this.bingDisconnecting || !this.api) return;
      const ok = await confirm({
        title: 'Disconnect Bing?',
        message: 'Agentimus forgets the key and stops polling right away. The Bing numbers already stored stay in your database, and the printed verification tag stays too — reconnecting is one paste.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      this.bingDisconnecting = true;
      this.bingError = '';
      try {
        this.bing = await this.api.disconnectBing();
        this.bingJustConnected = false;
      } catch (e) {
        this.bingError = (e && e.message) || 'Could not disconnect.';
      } finally {
        this.bingDisconnecting = false;
      }
    },
    bingHost() {
      const url = (this.bing && this.bing.siteUrl) || '';
      return url.replace(/^https?:\/\//, '').replace(/\/$/, '');
    },
    bingPolledText() {
      if (!this.bing || !this.bing.lastPollAt) return '';
      const d = new Date(this.bing.lastPollAt * 1000);
      return `${formatDate(d)} ${formatTime(d)}`;
    },
  },
};
</script>

<template>
  <section id="ar-sec-bing" class="ar-card">
    <h2 class="ar-card__title">Bing</h2>
    <p class="ar-card__lead">
      Bing is the index ChatGPT search reads today — Microsoft Copilot too. If Bing can
      see your pages, AI search can find them. Connect it and the
      <a href="#visibility">Visibility</a> screen shows how much of your site is in
      that index, and warns you when something keeps Bing’s crawler out.
    </p>

    <ConnectionRail
      :connected="!!(bing && bing.connected)"
      :checked="bingChecked"
      :label="bingHost()"
      :polled-text="bingPolledText()"
      :last-error="(bing && bing.lastError) || ''"
      idle-note="Read-only — Agentimus never changes your Bing settings."
    />

    <p v-if="bingError" class="ar-field__hint ar-warn">{{ bingError }}</p>

    <template v-if="bingChecked && (!bing || !bing.connected)">
      <p class="ar-mcp-eyebrow">Step 1 · Show Bing this site is yours</p>
      <div class="ar-cf-row">
        <input
          v-model="bingCode"
          class="ar-input"
          type="text"
          autocomplete="off"
          spellcheck="false"
          placeholder="Paste your verification code (msvalidate.01)"
          aria-label="Bing verification code"
          @keyup.enter="saveBingCode"
        />
        <button type="button" class="ar-btn" :disabled="bingSavingCode || !bingCode.trim()" @click="saveBingCode">
          {{ bingSavingCode ? 'Saving…' : 'Save' }}
        </button>
      </div>
      <p class="ar-field__hint">
        Agentimus prints the verification tag on your pages for you — nothing to upload.
        In Bing, pick the <strong>HTML Meta Tag</strong> verification option and paste
        its code (or the whole tag) here. Already verified in Bing — for example through
        a Google Search Console import? Skip this step.
      </p>
      <div v-if="bing && bing.hasMsvalidate" class="ar-bing__code">&lt;meta name="msvalidate.01" content="…" /&gt; — printed on your pages now</div>

      <p class="ar-mcp-eyebrow">Step 2 · Paste your API key</p>
      <div class="ar-cf-row">
        <input
          v-model="bingKey"
          class="ar-input"
          type="password"
          autocomplete="off"
          spellcheck="false"
          placeholder="Paste your Bing Webmaster API key"
          aria-label="Bing Webmaster API key"
          @keyup.enter="connectBing"
        />
        <button type="button" class="ar-btn" :disabled="bingConnecting || !bingKey.trim()" @click="connectBing">
          {{ bingConnecting ? 'Connecting…' : 'Connect' }}
        </button>
      </div>
      <p class="ar-field__hint">
        Stored like your other secrets — encrypted, never shown again, and sent nowhere
        except Bing’s own API.
      </p>
      <div class="ar-mcp-recipe">
        <ol class="ar-mcp-recipe__steps">
          <li>Open <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener"><code>bing.com/webmasters</code></a> — a Microsoft, Google or Facebook account works.</li>
          <li>Add this site, or import your sites from Google Search Console in one click.</li>
          <li>Open <code>Settings → API Access → Generate API Key</code> and paste the key here. Agentimus finds the site and asks Bing to verify it by itself.</li>
        </ol>
      </div>
    </template>

    <template v-else-if="bing && bing.connected">
      <!-- Names the CARD, not the screen — the lead one paragraph up already
           links "Visibility", and two identical links read as a glitch. -->
      <p v-if="bingJustConnected" class="ar-field__hint">
        <strong>First numbers are in.</strong> <a href="#visibility">In Bing's index →</a>
      </p>
      <p class="ar-field__hint">
        One key, read-only, one daily poll. Numbers are stored in your own database, so
        your history keeps growing where Bing’s own window ends.
      </p>
      <!-- The setup steps fold away on connect; say so, or the missing code
           field reads as LOST rather than folded (his catch). -->
      <p class="ar-field__hint">
        The setup steps are folded away while connected.
        <template v-if="bing.hasMsvalidate">Your verification tag stays printed on your pages.</template>
        <template v-else>Your site was already verified in Bing, so no verification tag was needed.</template>
        Disconnecting brings the steps back.
      </p>
    </template>

    <!-- IndexNow lives on the Bing card because Bing's index is where the
         ping matters most (ChatGPT search reads it) — but it needs NO Bing
         connection, and the copy says so. Off by default: it is an
         outbound request, and the standing promise is "no outbound by
         default". -->
    <label id="ar-feat-indexnow_enabled" class="ar-toggle">
      <input v-model="settings.indexnow_enabled" type="checkbox" />
      <span class="ar-toggle__track" aria-hidden="true"></span>
      <span class="ar-toggle__text">
        <strong>Announce changes with IndexNow</strong>
        <small>When you publish, edit or remove a post, Agentimus tells search engines right away — one standard ping to <code>api.indexnow.org</code>, which Bing, Yandex and other participating engines all read. Bing hearing about a new post in minutes matters, because ChatGPT search and Copilot read Bing's index. Only the changed addresses are sent, nothing else, and it works with or without a Bing connection. A small key file at <code>/&lt;key&gt;.txt</code> proves the pings come from your site.</small>
      </span>
    </label>
    <p v-if="settings.indexnow_enabled && indexnowKeyUrl" class="ar-field__hint">
      Key file: <a :href="indexnowKeyUrl" target="_blank" rel="noopener">{{ indexnowKeyUrl }}</a>
      — live once this setting is saved. Engines fetch it to confirm the pings are yours;
      the Bing index card on Visibility reports each announcement's outcome.
    </p>

    <!-- Disconnect closes the card — an exit lives at the end, never
         between the settings it would orphan. -->
    <button v-if="bing && bing.connected" type="button" class="ar-btn ar-btn--danger ar-btn--small" :disabled="bingDisconnecting" @click="disconnectBing">
      {{ bingDisconnecting ? 'Disconnecting…' : 'Disconnect' }}
    </button>

    <p class="ar-card__note ar-cf-note">
      More sources will join here later — always under the same rules: optional,
      read-only, your database.
    </p>
  </section>
</template>
