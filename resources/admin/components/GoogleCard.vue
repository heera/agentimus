<script>
/**
 * The Google data-source card — connect a service-account key for Search
 * Console (read-only), and optionally a second grant on the same key for GA4
 * Analytics. Lifted whole out of SettingsForm's "sources" group; it owns its
 * own `google*`/`ga4*` state and the key-file parsing, and talks only to the
 * API. Errors stay inline (`googleError`/`ga4Error`) — no toast.
 *
 * It binds no `settings`, so it takes only `api` + `active`. It re-emits
 * `navigate` (the "Search Opportunities" links) up to the parent, which routes
 * it. `active` mirrors "the sources tab is showing": the card stays mounted
 * behind the tab's v-show, so it defers its first status read until first shown.
 */
import { confirm } from '../confirm.js';
import { formatDate, formatTime } from '../wpDate.js';
import { copyText } from '../clipboard.js';
import ConnectionRail from './ConnectionRail.vue';

export default {
  name: 'GoogleCard',
  components: { ConnectionRail },
  props: {
    api: { type: Object, default: null },
    active: { type: Boolean, default: false },
  },
  emits: ['navigate'],
  data() {
    return {
      google: null, // Google connection status (never the key).
      googleChecked: false,
      googleKey: '', // The service-account JSON — read from the chosen file (or pasted). Sent once on Connect, then cleared.
      googleKeyName: '', // The chosen file's name, so the row shows what was picked.
      googleFileError: '',
      googlePasteOpen: false, // The paste fallback, for anyone who can't reach the file.
      googleConnecting: false,
      googleDisconnecting: false,
      googleError: '',
      googleJustConnected: false,
      ga4Property: '', // The GA4 property ID being typed; never holds a secret.
      ga4Busy: false,
      ga4Error: '',
    };
  },
  computed: {
    // The service account's address, read out of the pasted key file in the
    // browser (nothing is sent). It is what Search Console must be told about,
    // and it is otherwise buried in a wall of JSON — the step people get stuck
    // on. A half-typed or wrong-shaped paste simply yields '' rather than throwing.
    googleKeyEmail() {
      const raw = (this.googleKey || '').trim();
      if (raw.length < 20 || raw[0] !== '{') return '';
      try {
        const data = JSON.parse(raw);
        return data && data.type === 'service_account' && data.client_email ? String(data.client_email) : '';
      } catch (e) {
        return ''; // Still typing, or not a key file — say nothing rather than guess.
      }
    },
  },
  watch: {
    active(on) {
      if (on && !this.google) this.loadGoogle();
    },
  },
  mounted() {
    if (this.active && !this.google) this.loadGoogle();
  },
  methods: {
    async loadGoogle() {
      if (!this.api) return;
      try {
        this.google = await this.api.getGoogleStatus();
      } catch (e) {
        // Leave google null — a failed status read is not worth a banner.
      }
      this.googleChecked = true;
    },
    // Read the chosen key file in the browser. Nothing is uploaded: the text
    // becomes the same value a paste would have produced, and only Connect
    // sends it — to this site's own REST route.
    readGoogleKeyFile(event) {
      const file = event.target && event.target.files && event.target.files[0];
      if (!file) return;
      this.googleFileError = '';
      if (file.size > 64 * 1024) {
        this.googleFileError = 'That file is far larger than a service-account key. Choose the small .json file Google downloaded.';
        return;
      }
      const reader = new FileReader();
      reader.onload = () => {
        this.googleKey = String(reader.result || '').trim();
        this.googleKeyName = file.name;
        this.googlePasteOpen = false;
        if (!this.googleKeyEmail) {
          this.googleFileError = 'That file doesn’t look like a service-account key — it should start with {"type": "service_account". Check you picked the JSON key, not another download.';
        }
      };
      reader.onerror = () => {
        this.googleFileError = 'That file could not be read. Try choosing it again.';
      };
      reader.readAsText(file);
    },
    async connectGoogle() {
      const keyJson = this.googleKey.trim();
      if (!keyJson || this.googleConnecting || !this.api) return;
      this.googleConnecting = true;
      this.googleError = '';
      try {
        this.google = await this.api.connectGoogle(keyJson);
        this.googleKey = '';
        this.googleKeyName = '';
        this.googleFileError = '';
        this.googlePasteOpen = false;
        this.googleJustConnected = true;
      } catch (e) {
        this.googleError = (e && e.message) || 'Could not connect to Google.';
      } finally {
        this.googleConnecting = false;
      }
    },
    async disconnectGoogle() {
      if (this.googleDisconnecting || !this.api) return;
      // The message names the Analytics casualty only when there IS one — a
      // warning about a thing you never connected reads as a bug.
      const alsoAnalytics = this.google && this.google.analytics && this.google.analytics.connected
        ? ' Analytics stops with it — it reads with this same key.'
        : '';
      const ok = await confirm({
        title: 'Disconnect Google?',
        message: `Agentimus forgets the service-account key and stops polling right away.${alsoAnalytics} The numbers already stored stay in your database — reconnecting is one paste.`,
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      this.googleDisconnecting = true;
      this.googleError = '';
      try {
        this.google = await this.api.disconnectGoogle();
        this.googleJustConnected = false;
      } catch (e) {
        this.googleError = (e && e.message) || 'Could not disconnect.';
      } finally {
        this.googleDisconnecting = false;
      }
    },
    // Verified against the live API before it saves — a property the key can't
    // read would otherwise fail hours later on a cron, where nobody sees it.
    async connectGoogleAnalytics() {
      if (this.ga4Busy || !this.api || !this.ga4Property) return;
      this.ga4Busy = true;
      this.ga4Error = '';
      try {
        this.google = await this.api.connectGoogleAnalytics(this.ga4Property);
        this.ga4Property = '';
      } catch (e) {
        this.ga4Error = (e && e.message) || 'Could not read that property.';
      } finally {
        this.ga4Busy = false;
      }
    },
    // Analytics off, Search Console untouched — two grants, two switches.
    async disconnectGoogleAnalytics() {
      if (this.ga4Busy || !this.api) return;
      const ok = await confirm({
        title: 'Stop reading Analytics?',
        message: 'The dashboard goes back to counting only the readers search or an AI answer sent — not direct, social or email. The audience numbers already stored stay in your database, the key is untouched, and resuming is one paste of the property ID.',
        confirmLabel: 'Stop Reading',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      this.ga4Busy = true;
      this.ga4Error = '';
      try {
        this.google = await this.api.disconnectGoogleAnalytics();
      } catch (e) {
        this.ga4Error = (e && e.message) || 'Could not disconnect Analytics.';
      } finally {
        this.ga4Busy = false;
      }
    },
    googlePolledText() {
      if (!this.google || !this.google.lastPollAt) return '';
      const d = new Date(this.google.lastPollAt * 1000);
      return `${formatDate(d)} ${formatTime(d)}`;
    },
    copyPlainText(text) {
      return copyText(text);
    },
  },
};
</script>

<template>
  <section id="ar-sec-google" class="ar-card">
    <h2 class="ar-card__title">Google</h2>
    <p class="ar-card__lead">
      Google Search Console knows which queries bring your pages to searchers — and
      which pages under-earn their rankings. Connect it and
      <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'visibility', view: 'performance', anchor: 'ar-group-search' })">Search Opportunities</button> turns those numbers into a worklist,
      each entry wired to the exact field that fixes it — and the Visibility screen
      checks daily that your key pages are actually in Google's index, the index
      AI Overviews and Gemini read.
    </p>

    <ConnectionRail
      :connected="!!(google && google.connected)"
      :checked="googleChecked"
      :label="google ? google.property : ''"
      :polled-text="googlePolledText()"
      :last-error="(google && google.lastError) || ''"
      idle-note="Read-only — a key you mint, talking straight to Google. No middleman, ever."
    />

    <p v-if="googleError" class="ar-field__hint ar-warn">{{ googleError }}</p>

    <template v-if="googleChecked && (!google || !google.connected)">
      <p class="ar-mcp-eyebrow">Your service-account key file</p>
      <!-- A downloaded file should be CHOSEN, not copy-pasted: the key is a
           2 KB JSON file, and a textarea full of it reads as a wall of noise.
           Read in the browser (FileReader) — the contents never leave this
           page until Connect. Pasting stays available for anyone who can't
           reach the file (a remote admin, a locked-down machine). -->
      <div class="ar-google__pick">
        <label class="ar-btn ar-google__choose">
          <input
            type="file"
            accept="application/json,.json"
            class="ar-google__file"
            aria-label="Google service-account key file"
            @change="readGoogleKeyFile"
          />
          Choose key file…
        </label>
        <span v-if="googleKeyName" class="ar-google__filename">{{ googleKeyName }}</span>
        <button v-if="!googlePasteOpen && !googleKey" type="button" class="ar-linkbtn ar-google__pastelink" @click="googlePasteOpen = true">
          or paste it instead
        </button>
      </div>
      <p v-if="googleFileError" class="ar-field__hint ar-warn">{{ googleFileError }}</p>
      <textarea
        v-if="googlePasteOpen && !googleKeyName"
        v-model="googleKey"
        class="ar-input ar-google__key"
        rows="3"
        autocomplete="off"
        spellcheck="false"
        placeholder='Paste the whole key file — it starts {"type": "service_account", …}'
        aria-label="Google service-account key JSON"
      ></textarea>
      <!-- The moment the key is pasted, read its address out of the file and
           put it on screen. Step 4 asks the owner to grant THIS address access
           in Search Console, and hunting for it inside a JSON file is the step
           people get stuck on. Parsed in the browser; nothing is sent. -->
      <div v-if="googleKeyEmail" class="ar-google__found">
        <p class="ar-google__found-lead">
          <strong>Key read.</strong> It belongs to this address — the one Search Console needs to know:
        </p>
        <div class="ar-google__found-row">
          <code class="ar-google__email">{{ googleKeyEmail }}</code>
          <button type="button" class="ar-btn ar-btn--small" @click="copyPlainText(googleKeyEmail)">Copy</button>
        </div>
        <p class="ar-google__found-lead">
          Add it in
          <a href="https://search.google.com/search-console/users" target="_blank" rel="noopener">Search Console → Settings → Users and permissions</a>
          (choose <strong>Restricted</strong>), then connect below. If you connect first,
          nothing breaks — Agentimus will just tell you this same thing.
        </p>
        <button type="button" class="ar-btn ar-google__connect" :disabled="googleConnecting" @click="connectGoogle">
          {{ googleConnecting ? 'Connecting…' : 'Connect' }}
        </button>
      </div>
      <!-- A pasted-but-unreadable key still needs a way forward: the server's
           error message is more specific than anything guessed here. -->
      <div v-else-if="googleKey.trim()" class="ar-google__pick">
        <button type="button" class="ar-btn" :disabled="googleConnecting" @click="connectGoogle">
          {{ googleConnecting ? 'Connecting…' : 'Connect' }}
        </button>
      </div>
      <p class="ar-field__hint">
        Stored like your other secrets — encrypted, never shown again, and sent nowhere
        except Google’s own API. Unlike other plugins, there is no “connect with Google”
        button here on purpose: those route your data through the plugin maker’s server.
        This key is yours, minted in your own Google Cloud, revocable there any time.
      </p>
      <p class="ar-field__hint ar-google__reassure">
        <strong>Five minutes, once.</strong> It looks like a lot because it happens in Google’s
        console, but nothing here costs money, nothing touches your website, and every step is
        undoable — you’re creating a robot account and letting it <em>read</em> your search
        statistics. Delete the key any time and this stops working; nothing else changes.
      </p>
      <p class="ar-field__hint">
        <strong>Before you start:</strong> your site needs to be a verified property in
        <a href="https://search.google.com/search-console" target="_blank" rel="noopener">Search Console</a>
        already — that’s where the numbers come from. If you’ve never set it up there, do that first.
      </p>
      <div class="ar-mcp-recipe">
        <ol class="ar-mcp-recipe__steps">
          <li>Open <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener"><code>console.cloud.google.com</code></a> and create a <strong>service account</strong>. Any project works — a fresh one keeps this tidy and separate from anything else you run. Name it anything (“agentimus” is fine); steps 2 and 3 are marked optional, so <strong>Create and close</strong> finishes it in one click. <em>No billing details are asked for.</em></li>
          <li>Turn on the <a href="https://console.cloud.google.com/apis/library/searchconsole.googleapis.com" target="_blank" rel="noopener"><strong>Search Console API</strong></a> for that same project — the blue Enable button, one click.</li>
          <li>Back on your service account, open the <code>Keys</code> tab → <code>Add key</code> → <code>Create new key</code> → <strong>JSON</strong>. A small file downloads: that’s the key. Google shows a yellow warning about key files here — it’s advice for code running <em>inside</em> Google Cloud, which can borrow an identity instead. Your WordPress server isn’t, so a key file is the correct path.</li>
          <li>Click <strong>Choose key file…</strong> above and pick that downloaded file. Agentimus reads it here in your browser and shows you the robot’s email address, with a Copy button.</li>
          <li>In <a href="https://search.google.com/search-console/users" target="_blank" rel="noopener">Search Console → Settings → Users and permissions</a>, click <strong>Add user</strong>, paste that address, choose <strong>Restricted</strong>, and confirm. Then press Connect — Agentimus finds your site’s property on its own.</li>
        </ol>
      </div>
    </template>

    <template v-else-if="google && google.connected">
      <p v-if="googleJustConnected" class="ar-field__hint">
        <strong>First numbers are in.</strong> <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'visibility', view: 'performance', anchor: 'ar-group-search' })">Search Opportunities →</button>
      </p>
      <p class="ar-field__hint">
        One key, read-only, one daily poll of query-level search performance. Numbers are
        stored in your own database, so your history keeps growing where Google’s own
        window ends. The key stays revocable in your Google Cloud console; the granted
        access stays visible in Search Console’s user list<template v-if="google.saEmail"> under
        <code>{{ google.saEmail }}</code></template>.
      </p>
      <!-- Analytics rides this same key ({@see Settings::disconnect() —
           it resets the whole store, property included}), so the warning
           belongs HERE, before the act, not discovered afterwards. -->
      <p v-if="google.analytics && google.analytics.connected" class="ar-field__hint">
        Disconnecting also stops <strong>Analytics</strong> below — it reads with this same
        key. Your stored history stays either way, search and audience both.
      </p>
      <button type="button" class="ar-btn ar-btn--danger ar-btn--small" :disabled="googleDisconnecting" @click="disconnectGoogle">
        {{ googleDisconnecting ? 'Disconnecting…' : 'Disconnect' }}
      </button>

      <!-- Analytics: a SECOND grant on the same key. Kept visually inside the
           Google card (it is the same account) but with its own connect and
           its own disconnect, because an owner who wants search numbers has
           not thereby asked us to read their whole audience. -->
      <div class="ar-ga4">
        <p class="ar-ga4__title">Analytics <span class="ar-field__tag">optional</span></p>
        <template v-if="google.analytics && google.analytics.connected">
          <p class="ar-field__hint">
            Reading property <code>{{ google.analytics.property }}</code> — total readers,
            visits and page views for the same window the dashboard reports. This is what
            turns “People” on the dashboard from two routes into everyone.
          </p>
          <p v-if="google.analytics.lastError" class="ar-field__hint ar-ga4__err">{{ google.analytics.lastError }}</p>
          <!-- Danger family, like Disconnect above: both END a data feed, and a
               stop-action in Cancel's white reads weaker than what it does. The
               real severity gap (a dead credential vs a retypeable property ID)
               is carried by the words, not by dressing one stop as harmless. -->
          <button type="button" class="ar-btn ar-btn--danger ar-btn--small" :disabled="ga4Busy" @click="disconnectGoogleAnalytics">
            {{ ga4Busy ? 'Working…' : 'Stop reading Analytics' }}
          </button>
        </template>
        <template v-else>
          <p class="ar-field__hint">
            Without this, the dashboard can only count readers search or an AI answer sent —
            not direct, social or email. Three short steps, all in the same Google account
            your key already lives in:
          </p>
          <!-- The Search Console half earned a linked recipe; the half people
               actually get stuck on deserves the same. Step 1 is the trap: the
               main recipe enables only the Search Console API, and a key
               without the Analytics Data API fails here with a Google error
               that never names the fix. -->
          <div class="ar-mcp-recipe">
            <ol class="ar-mcp-recipe__steps">
              <li>Turn on the <a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" rel="noopener"><strong>Google Analytics Data API</strong></a> for the same project your key came from — the blue Enable button, one click. The Search Console API from the recipe above doesn't cover Analytics.</li>
              <li>In <a href="https://analytics.google.com/" target="_blank" rel="noopener">GA4 → Admin</a> → <strong>Property access management</strong>, add
                <template v-if="google.saEmail"><code>{{ google.saEmail }}</code> <button type="button" class="ar-linkbtn" @click="copyPlainText(google.saEmail)">Copy</button></template>
                <!-- Reachable only when the stored key never parsed (so no address
                     was kept) — exactly when the reader most needs a map to it. -->
                <template v-else>the service-account email — the <code>client_email</code> inside your key file, also listed under <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">Service accounts</a> in your Google Cloud console —</template>
                as a <strong>Viewer</strong>.</li>
              <li><strong>Property details</strong>, in that same Admin column, shows the numeric <strong>property ID</strong> — paste it below. Not the <code>G-</code> measurement ID.</li>
            </ol>
          </div>
          <div class="ar-ga4__row">
            <input
              v-model="ga4Property"
              type="text"
              class="ar-input ar-ga4__input"
              inputmode="numeric"
              placeholder="123456789"
              aria-label="GA4 property ID"
            />
            <button type="button" class="ar-btn ar-btn--small" :disabled="ga4Busy || !ga4Property" @click="connectGoogleAnalytics">
              {{ ga4Busy ? 'Checking…' : 'Connect Analytics' }}
            </button>
          </div>
          <p v-if="ga4Error" class="ar-field__hint ar-ga4__err">{{ ga4Error }}</p>
        </template>
      </div>
    </template>
  </section>
</template>
