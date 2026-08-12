<script>
/**
 * Integrations — what Agentimus connects to. Two tabs, one grammar.
 *
 * PLUGINS is the roster of plugins whose content this site describes to AI
 * assistants (presence stated honestly either way), plus the developer card
 * pointing at the provider guide. SERVICES is what receives this site's own
 * report events — the webhook, Telegram, Slack, Discord, Google Sheets and
 * the private feed (the one PULL service: readers come to a tokened URL),
 * the blessed roster complete.
 *
 * The tabs are the plugin's own tab convention: 50/50 full width, the active
 * indicator on the TOP edge (the .ar-tabpanel__tabs strip Visibility already
 * wears). The card grid is the blessed FluentForms-style grammar, shared via
 * IntegrationCard.
 *
 * Each service's doing happens in the app's modal shell (his ruling: the
 * below-the-grid panel is out) — the same .ar-modal ConfirmDialog and the
 * Agent Preview wear, Teleported to body per the standing law, holding that
 * service's fields and teaching copy. One modal at a time: `panel` names the
 * open service, '' means none; Esc and a backdrop click both dismiss, and a
 * save or disconnect closes back to the updated card with the grid never
 * having moved. The webhook's signing secret appears exactly once, in the
 * response that minted it; the feed's tokened URL follows the same law; the
 * Telegram bot token goes the other way — pasted in, never echoed back.
 *
 * Always mounted, fetch on first reveal, re-read on every return — the
 * freshness rule every data screen follows.
 */
import CardSkeleton from '../CardSkeleton.vue';
import IntegrationCard from './IntegrationCard.vue';
import WebhookCard from './services/WebhookCard.vue';
import TelegramCard from './services/TelegramCard.vue';
import SlackCard from './services/SlackCard.vue';
import DiscordCard from './services/DiscordCard.vue';
import SheetsCard from './services/SheetsCard.vue';
import FeedCard from './services/FeedCard.vue';
import PluginCard from './plugins/PluginCard.vue';
import { copyText } from '../../js/clipboard.js';
import { confirm } from '../../js/confirm.js';
import { bindDocEsc } from '../../js/docEsc.js';

const DEV_GUIDE = 'https://heera.github.io/agentimus/developer/integrate-your-plugin.html';

export default {
  name: 'IntegrationsPanel',
  components: { CardSkeleton, IntegrationCard, WebhookCard, TelegramCard, SlackCard, DiscordCard, SheetsCard, FeedCard, PluginCard },
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show; fetch on first reveal, re-read on every return.
    active: { type: Boolean, default: false },
  },
  emits: ['flash'],
  data() {
    return {
      view: 'plugins',
      devGuide: DEV_GUIDE,
      loading: false,
      loaded: false,
      error: '',
      webhook: { enabled: false, url: '', events: [], hasSecret: false, queued: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      telegram: { enabled: false, chat: '', events: [], tier: 'all', hasToken: false, queued: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      slack: { enabled: false, url: '', events: [], queued: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      discord: { enabled: false, url: '', events: [], queued: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      sheets: { enabled: false, spreadsheet: '', events: [], hasKey: false, saEmail: '', queued: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      feed: { enabled: false, events: [], hasToken: false, lastFetchedAt: 0, queued: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      events: [],
      plugins: [],
      // Which service's focused panel is open ('' = none, one at a time).
      panel: '',
      form: { url: '', events: [] },
      tg: { token: '', chat: '', tier: 'all', events: [] },
      sl: { url: '', events: [] },
      dc: { url: '', events: [] },
      sh: { spreadsheet: '', events: [] },
      fe: { events: [] },
      saving: false,
      formError: '',
      // The secret's single appearance. Cleared when the panel closes — after
      // that, only its existence is ever admitted again.
      secret: '',
      secretCopied: false,
      // The feed URL's single appearance — the same one-sighting law.
      feedUrl: '',
      feedUrlCopied: false,
    };
  },
  computed: {
    // The open service's name over the modal — one shell, one title slot.
    modalTitle() {
      return { webhook: 'Webhook', telegram: 'Telegram', slack: 'Slack', discord: 'Discord', sheets: 'Google Sheets', feed: 'Private feed' }[this.panel] || '';
    },
  },
  watch: {
    active(on) {
      // Every arrival re-fetches: a delivery may have landed (or failed) since
      // this screen was last on top, and the card's honesty line must say so.
      if (on) this.load();
    },
    panel(open) {
      // Esc must close the modal even after focus leaves it (backdrop click
      // parks focus on <body>) — bind at the document for exactly as long as
      // it is open, the same net every dialog in the app wears.
      if (this._unEsc) this._unEsc();
      this._unEsc = open ? bindDocEsc(() => this.closePanel()) : null;
    },
  },
  // The watcher only fires on a CHANGE, so a cold load straight to
  // #integrations — where `active` is already true at mount — would render
  // chrome and never fetch. Same guard every kept-alive panel uses.
  mounted() {
    if (this.active) this.load();
  },
  beforeUnmount() {
    if (this._unEsc) this._unEsc();
  },
  methods: {
    async load() {
      if (!this.api) return;
      this.loading = true;
      this.error = '';
      try {
        this.apply(await this.api.getIntegrations());
        this.loaded = true;
      } catch (e) {
        this.error = e.message || 'Could not load integrations.';
      } finally {
        this.loading = false;
      }
    },
    apply(data) {
      this.webhook = data.webhook || this.webhook;
      this.telegram = data.telegram || this.telegram;
      this.slack = data.slack || this.slack;
      this.discord = data.discord || this.discord;
      this.sheets = data.sheets || this.sheets;
      this.feed = data.feed || this.feed;
      this.events = data.events || [];
      this.plugins = data.plugins || [];
    },
    // A fresh connect starts with every box ticked — subscribing to nothing is
    // the one setup that can never be what anyone meant, and the server
    // refuses it anyway.
    defaultEvents(service) {
      return service.enabled && service.events.length
        ? [...service.events]
        : this.events.map((e) => e.name);
    },
    openPanel() {
      this.form.url = this.webhook.url || '';
      this.form.events = this.defaultEvents(this.webhook);
      this.formError = '';
      this.secret = '';
      this.secretCopied = false;
      this.panel = 'webhook';
      this.$nextTick(() => {
        const el = this.$refs.urlInput;
        if (el) el.focus();
      });
    },
    openTelegram() {
      // The token field always starts empty: the stored one is never echoed
      // back, so an empty field on a connected bot means "keep it".
      this.tg.token = '';
      this.tg.chat = this.telegram.chat || '';
      this.tg.tier = this.telegram.tier || 'all';
      this.tg.events = this.defaultEvents(this.telegram);
      this.formError = '';
      this.panel = 'telegram';
      this.$nextTick(() => {
        const el = this.telegram.enabled ? this.$refs.tgChatInput : this.$refs.tgTokenInput;
        if (el) el.focus();
      });
    },
    openSlack() {
      this.sl.url = this.slack.url || '';
      this.sl.events = this.defaultEvents(this.slack);
      this.formError = '';
      this.panel = 'slack';
      this.$nextTick(() => {
        const el = this.$refs.slUrlInput;
        if (el) el.focus();
      });
    },
    openDiscord() {
      this.dc.url = this.discord.url || '';
      this.dc.events = this.defaultEvents(this.discord);
      this.formError = '';
      this.panel = 'discord';
      this.$nextTick(() => {
        const el = this.$refs.dcUrlInput;
        if (el) el.focus();
      });
    },
    openSheets() {
      this.sh.spreadsheet = this.sheets.spreadsheet || '';
      this.sh.events = this.defaultEvents(this.sheets);
      this.formError = '';
      this.panel = 'sheets';
      this.$nextTick(() => {
        const el = this.$refs.shIdInput;
        if (el) el.focus();
      });
    },
    openFeed() {
      this.fe.events = this.defaultEvents(this.feed);
      this.formError = '';
      this.feedUrl = '';
      this.feedUrlCopied = false;
      this.panel = 'feed';
      // No field to fill — the dialog itself takes focus, so Esc and Tab
      // land where every other modal starts them.
      this.$nextTick(() => {
        const el = this.$refs.dialog;
        if (el) el.focus();
      });
    },
    closePanel() {
      this.panel = '';
      this.secret = '';
      this.secretCopied = false;
      this.feedUrl = '';
      this.feedUrlCopied = false;
    },
    async save() {
      if (!this.api || this.saving) return;
      this.saving = true;
      this.formError = '';
      try {
        const wasConnected = this.webhook.enabled;
        const data = await this.api.actIntegrations({
          action: wasConnected ? 'save' : 'connect',
          url: this.form.url,
          events: this.form.events,
        });
        this.apply(data);
        if (data.secret) {
          // The one sighting. The panel stays open so it can be copied.
          this.secret = data.secret;
          this.secretCopied = false;
        } else {
          this.$emit('flash', 'success', 'Webhook saved.');
          this.closePanel();
        }
      } catch (e) {
        this.formError = e.message || 'Could not save the webhook.';
      } finally {
        this.saving = false;
      }
    },
    async saveTelegram() {
      if (!this.api || this.saving) return;
      this.saving = true;
      this.formError = '';
      try {
        const wasConnected = this.telegram.enabled;
        this.apply(await this.api.actIntegrations({
          action: wasConnected ? 'save' : 'connect',
          service: 'telegram',
          token: this.tg.token,
          chat: this.tg.chat,
          tier: this.tg.tier,
          events: this.tg.events,
        }));
        this.$emit(
          'flash',
          'success',
          wasConnected ? 'Telegram settings saved.' : 'Telegram connected — the chat just got its first message.'
        );
        this.closePanel();
      } catch (e) {
        this.formError = e.message || 'Could not save the Telegram connection.';
      } finally {
        this.saving = false;
      }
    },
    async saveSlack() {
      if (!this.api || this.saving) return;
      this.saving = true;
      this.formError = '';
      try {
        const wasConnected = this.slack.enabled;
        this.apply(await this.api.actIntegrations({
          action: wasConnected ? 'save' : 'connect',
          service: 'slack',
          url: this.sl.url,
          events: this.sl.events,
        }));
        this.$emit('flash', 'success', wasConnected ? 'Slack settings saved.' : 'Slack connected.');
        this.closePanel();
      } catch (e) {
        this.formError = e.message || 'Could not save the Slack connection.';
      } finally {
        this.saving = false;
      }
    },
    async saveDiscord() {
      if (!this.api || this.saving) return;
      this.saving = true;
      this.formError = '';
      try {
        const wasConnected = this.discord.enabled;
        this.apply(await this.api.actIntegrations({
          action: wasConnected ? 'save' : 'connect',
          service: 'discord',
          url: this.dc.url,
          events: this.dc.events,
        }));
        this.$emit('flash', 'success', wasConnected ? 'Discord settings saved.' : 'Discord connected.');
        this.closePanel();
      } catch (e) {
        this.formError = e.message || 'Could not save the Discord connection.';
      } finally {
        this.saving = false;
      }
    },
    async saveSheets() {
      if (!this.api || this.saving) return;
      this.saving = true;
      this.formError = '';
      try {
        const wasConnected = this.sheets.enabled;
        this.apply(await this.api.actIntegrations({
          action: wasConnected ? 'save' : 'connect',
          service: 'sheets',
          spreadsheet: this.sh.spreadsheet,
          events: this.sh.events,
        }));
        this.$emit(
          'flash',
          'success',
          wasConnected ? 'Sheets settings saved.' : 'Google Sheets connected — the sheet just got its header row and a first row.'
        );
        this.closePanel();
      } catch (e) {
        this.formError = e.message || 'Could not save the Google Sheets connection.';
      } finally {
        this.saving = false;
      }
    },
    async saveFeed() {
      if (!this.api || this.saving) return;
      this.saving = true;
      this.formError = '';
      try {
        const wasConnected = this.feed.enabled;
        const data = await this.api.actIntegrations({
          action: wasConnected ? 'save' : 'connect',
          service: 'feed',
          events: this.fe.events,
        });
        this.apply(data);
        if (data.feedUrl) {
          // The one sighting. The panel stays open so it can be copied.
          this.feedUrl = data.feedUrl;
          this.feedUrlCopied = false;
        } else {
          this.$emit('flash', 'success', 'Feed settings saved.');
          this.closePanel();
        }
      } catch (e) {
        this.formError = e.message || 'Could not save the feed.';
      } finally {
        this.saving = false;
      }
    },
    async regenerateFeed() {
      if (!this.api || this.saving) return;
      // Same care as rotating the webhook secret: the old URL dies right here.
      const ok = await confirm({
        title: 'Make a new URL?',
        message: 'The current URL stops answering the moment this saves — every reader still polling it is out. Paste the new URL into each of them.',
        confirmLabel: 'New URL',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      this.saving = true;
      this.formError = '';
      try {
        const data = await this.api.actIntegrations({ action: 'regenerate', service: 'feed' });
        this.apply(data);
        this.feedUrl = data.feedUrl || '';
        this.feedUrlCopied = false;
      } catch (e) {
        this.formError = e.message || 'Could not make a new URL.';
      } finally {
        this.saving = false;
      }
    },
    async disconnectFeed() {
      if (!this.api || this.saving) return;
      const ok = await confirm({
        title: 'Disconnect the feed?',
        message: 'The URL stops answering, the remembered events are dropped, and anything still queued for the feed is discarded. Reconnecting makes a new URL.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      await this.doDisconnect({ action: 'disconnect', service: 'feed' }, 'Feed disconnected.');
    },
    async copyFeedUrl() {
      if (await copyText(this.feedUrl)) {
        this.feedUrlCopied = true;
      } else {
        this.$emit('flash', 'error', 'Could not copy — select the URL and copy it by hand.');
      }
    },
    async disconnectSheets() {
      if (!this.api || this.saving) return;
      const ok = await confirm({
        title: 'Disconnect Google Sheets?',
        message: 'Rows stop being appended and anything still queued for Sheets is discarded. Rows already in the spreadsheet stay — the sheet is yours.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      await this.doDisconnect({ action: 'disconnect', service: 'sheets' }, 'Google Sheets disconnected.');
    },
    async disconnectDiscord() {
      if (!this.api || this.saving) return;
      const ok = await confirm({
        title: 'Disconnect Discord?',
        message: 'Messages stop, anything still queued for Discord is discarded, and the webhook URL is forgotten.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      await this.doDisconnect({ action: 'disconnect', service: 'discord' }, 'Discord disconnected.');
    },
    async disconnectSlack() {
      if (!this.api || this.saving) return;
      const ok = await confirm({
        title: 'Disconnect Slack?',
        message: 'Messages stop, anything still queued for Slack is discarded, and the webhook URL is forgotten.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      await this.doDisconnect({ action: 'disconnect', service: 'slack' }, 'Slack disconnected.');
    },
    async disconnect() {
      if (!this.api || this.saving) return;
      const ok = await confirm({
        title: 'Disconnect the webhook?',
        message: 'Events stop being sent, anything still queued is discarded, and the signing secret is deleted. Reconnecting mints a new secret.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      await this.doDisconnect({ action: 'disconnect' }, 'Webhook disconnected.');
    },
    async disconnectTelegram() {
      if (!this.api || this.saving) return;
      const ok = await confirm({
        title: 'Disconnect Telegram?',
        message: 'Messages stop, anything still queued for Telegram is discarded, and the stored bot token is deleted. Reconnecting asks for the token again.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      await this.doDisconnect({ action: 'disconnect', service: 'telegram' }, 'Telegram disconnected.');
    },
    async doDisconnect(payload, done) {
      this.saving = true;
      try {
        this.apply(await this.api.actIntegrations(payload));
        this.closePanel();
        this.$emit('flash', 'success', done);
      } catch (e) {
        this.$emit('flash', 'error', e.message || 'Could not disconnect.');
      } finally {
        this.saving = false;
      }
    },
    async regenerate() {
      if (!this.api || this.saving) return;
      // Same care as rotating the MCP token: the old secret dies right here.
      const ok = await confirm({
        title: 'Make a new secret?',
        message: 'Deliveries are signed with the new secret from now on — update your receiver, or its checks will start failing.',
        confirmLabel: 'New secret',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      this.saving = true;
      this.formError = '';
      try {
        const data = await this.api.actIntegrations({ action: 'regenerate' });
        this.apply(data);
        this.secret = data.secret || '';
        this.secretCopied = false;
      } catch (e) {
        this.formError = e.message || 'Could not make a new secret.';
      } finally {
        this.saving = false;
      }
    },
    async copySecret() {
      if (await copyText(this.secret)) {
        this.secretCopied = true;
      } else {
        this.$emit('flash', 'error', 'Could not copy — select the secret and copy it by hand.');
      }
    },
  },
};
</script>

<template>
  <div class="ar-int">
    <!-- The plugin's tab convention: 50/50 full width, active indicator on the top edge. -->
    <nav class="ar-tabpanel__tabs" aria-label="Integrations views">
      <button
        type="button"
        class="ar-subnav__item"
        :class="{ 'is-active': view === 'plugins' }"
        @click="view = 'plugins'"
      >Plugins</button>
      <button
        type="button"
        class="ar-subnav__item"
        :class="{ 'is-active': view === 'services' }"
        @click="view = 'services'"
      >Services</button>
    </nav>

    <div class="ar-tabpanel__body">
      <p v-if="error" class="ar-int__error" role="alert">{{ error }} — try again, and if it persists, reload the page.</p>
      <CardSkeleton v-if="!loaded && !error" lead="Loading integrations…" />

      <template v-if="loaded">
        <!-- PLUGINS: what this site describes on other plugins' behalf. -->
        <section v-show="view === 'plugins'">
          <p class="ar-int__lead">
            Plugins Agentimus recognises here are <strong>described</strong> — their content joins what
            AI assistants can read about your site. Ones you don’t run are listed so you know what
            would be described if you did.
          </p>
          <div class="ar-int__grid">
            <PluginCard v-for="p in plugins" :key="p.id" :plugin="p" />
            <!-- The developer card: the door for everyone not on the roster. -->
            <IntegrationCard
              :mark="'</>'"
              name="Your plugin"
              blurb="Any plugin can describe itself to AI assistants through the WP_Discovery hook — one action, no dependency on Agentimus."
              :chip="{ label: 'For developers', tone: '' }"
              action="Guide"
              :action-href="devGuide"
            />
          </div>
        </section>

        <!-- SERVICES: what receives this site's own report events. -->
        <section v-show="view === 'services'">
          <p class="ar-int__lead">
            Services receive <strong>events</strong> from this site — a new finding, a caught impostor,
            the weekly digest’s numbers. Your site’s own reports only, never your visitors’ data, and
            nothing is sent until you connect one.
          </p>
          <div class="ar-int__grid">
            <WebhookCard :webhook="webhook" @open="openPanel" />
            <TelegramCard :telegram="telegram" @open="openTelegram" />
            <SlackCard :slack="slack" @open="openSlack" />
            <DiscordCard :discord="discord" @open="openDiscord" />
            <SheetsCard :sheets="sheets" @open="openSheets" />
            <FeedCard :feed="feed" @open="openFeed" />
          </div>

        </section>
      </template>
    </div>

    <!-- The service modal: one shell, the open service's fields inside. Teleported
         to body (the standing law), Esc and a backdrop click both dismiss, and the
         grid behind it never moves. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="panel" class="ar-modal ar-modal--svc" @click.self="closePanel">
          <div
            ref="dialog"
            class="ar-modal__panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-int-modal-title"
            tabindex="-1"
            @keydown.esc="closePanel"
          >
            <div class="ar-modal__head">
              <h2 id="ar-int-modal-title" class="ar-modal__title">{{ modalTitle }}</h2>
              <p v-if="panel === 'webhook'" class="ar-int__panellead">
                Each event arrives at your URL as one JSON POST. The
                <code>X-Agentimus-Signature</code> header carries an HMAC-SHA256 of the body, signed
                with the secret below — check it and you know the event came from this site.
              </p>
              <p v-else-if="panel === 'telegram'" class="ar-int__panellead">
                Telegram delivery is a <strong>bot</strong> you own: message
                <code>@BotFather</code>, tell it <code>/newbot</code>, and it hands you a token —
                that takes about a minute. Paste the token here, say which chat to post to, and
                connecting sends that chat one test message to prove the road works.
              </p>
              <p v-else-if="panel === 'slack'" class="ar-int__panellead">
                Slack calls this an <strong>incoming webhook</strong>: in Slack, add the
                “Incoming Webhooks” app to the channel you want, and it hands you a URL. Paste it
                here and each event arrives in that channel as one message. Mattermost and
                Rocket.Chat speak the same format — their URLs work too.
              </p>
              <p v-else-if="panel === 'discord'" class="ar-int__panellead">
                In your server: <strong>Server Settings → Integrations → Webhooks → New
                Webhook</strong>, pick the channel, copy its URL and paste it here. Each event
                arrives in that channel as one embed.
              </p>
              <p v-else-if="panel === 'sheets'" class="ar-int__panellead">
                Each event becomes one row appended to a spreadsheet you own — a history that
                outlives the 30-day log, using the same service-account key as your Google
                connection. In Google Sheets, hit <strong>Share</strong> and add
                <template v-if="sheets.saEmail"><code>{{ sheets.saEmail }}</code></template>
                <template v-else>the service account’s email</template>
                as an Editor, then paste the spreadsheet’s ID below. Connecting appends a header
                row and one test row to prove the road works.
              </p>
              <p v-else-if="panel === 'feed'" class="ar-int__panellead">
                A private feed of what Agentimus finds — paste its URL into any feed reader or
                automation and events arrive there, alongside every finding still open. Nothing is
                ever sent out: readers come to it. <strong>Anyone with the URL can read it, so
                treat it like a key.</strong> It answers as RSS; add
                <code>&amp;format=json</code> for JSON Feed.
              </p>
            </div>

            <div class="ar-modal__body">
              <div class="ar-modal__scroll">
                <!-- Webhook -->
                <template v-if="panel === 'webhook'">
                  <div class="ar-field">
                    <label for="ar-int-url">Where to send events</label>
                    <input
                      id="ar-int-url"
                      ref="urlInput"
                      v-model="form.url"
                      type="url"
                      class="ar-input"
                      placeholder="https://hooks.example.com/…"
                      :disabled="saving"
                    />
                  </div>

                  <fieldset class="ar-int__events">
                    <legend>Which events to send</legend>
                    <label v-for="ev in events" :key="ev.name" class="ar-int__event">
                      <input v-model="form.events" type="checkbox" :value="ev.name" :disabled="saving" />
                      <span class="ar-int__eventtext">
                        <strong>{{ ev.label }}</strong>
                        <small>{{ ev.description }}</small>
                      </span>
                    </label>
                  </fieldset>

                  <!-- The secret's one and only sighting (connect or regenerate). -->
                  <div v-if="secret" class="ar-int__once">
                    <div class="ar-int__oncerow">
                      <code class="ar-int__secret">{{ secret }}</code>
                      <button type="button" class="ar-btn ar-btn--ghost" @click="copySecret">
                        {{ secretCopied ? 'Copied' : 'Copy' }}
                      </button>
                    </div>
                    <p class="ar-field__hint">
                      <strong>Shown once.</strong> Copy it into your receiver now — it can’t be shown
                      again. Lost it? “New secret” mints a fresh one.
                    </p>
                  </div>
                </template>

                <!-- Telegram -->
                <template v-else-if="panel === 'telegram'">
                  <div class="ar-field">
                    <label for="ar-int-tg-token">Bot token</label>
                    <input
                      id="ar-int-tg-token"
                      ref="tgTokenInput"
                      v-model="tg.token"
                      type="password"
                      class="ar-input"
                      autocomplete="off"
                      placeholder="123456789:ABC…"
                      :disabled="saving"
                    />
                    <p v-if="telegram.enabled && telegram.hasToken" class="ar-field__hint">
                      A token is stored. Leave this blank to keep it — paste a new one only to replace it.
                    </p>
                  </div>

                  <div class="ar-field">
                    <label for="ar-int-tg-chat">Chat id</label>
                    <input
                      id="ar-int-tg-chat"
                      ref="tgChatInput"
                      v-model="tg.chat"
                      type="text"
                      class="ar-input"
                      autocomplete="off"
                      placeholder="123456789 or @yourchannel"
                      :disabled="saving"
                    />
                    <p class="ar-field__hint">
                      Your own id (ask <code>@userinfobot</code>), a group’s id (starts with a minus), or
                      <code>@name</code> for a channel you run. Message your bot once first — Telegram
                      only lets a bot write where it’s been let in.
                    </p>
                  </div>

                  <fieldset class="ar-int__events">
                    <legend>Which events to send</legend>
                    <label v-for="ev in events" :key="ev.name" class="ar-int__event">
                      <input v-model="tg.events" type="checkbox" :value="ev.name" :disabled="saving" />
                      <span class="ar-int__eventtext">
                        <strong>{{ ev.label }}</strong>
                        <small>{{ ev.description }}</small>
                      </span>
                    </label>
                  </fieldset>

                  <fieldset class="ar-int__events">
                    <legend>Which findings ring your phone</legend>
                    <label class="ar-int__event">
                      <input v-model="tg.tier" type="radio" value="all" :disabled="saving" />
                      <span class="ar-int__eventtext">
                        <strong>Every new finding</strong>
                        <small>Anything the daily check turns up.</small>
                      </span>
                    </label>
                    <label class="ar-int__event">
                      <input v-model="tg.tier" type="radio" value="urgent" :disabled="saving" />
                      <span class="ar-int__eventtext">
                        <strong>Urgent only</strong>
                        <small>The rest still waits on the Findings screen — just not on your phone.</small>
                      </span>
                    </label>
                  </fieldset>
                </template>

                <!-- Slack -->
                <template v-else-if="panel === 'slack'">
                  <div class="ar-field">
                    <label for="ar-int-sl-url">Webhook URL</label>
                    <input
                      id="ar-int-sl-url"
                      ref="slUrlInput"
                      v-model="sl.url"
                      type="url"
                      class="ar-input"
                      autocomplete="off"
                      placeholder="https://hooks.slack.com/services/…"
                      :disabled="saving"
                    />
                  </div>

                  <fieldset class="ar-int__events">
                    <legend>Which events to send</legend>
                    <label v-for="ev in events" :key="ev.name" class="ar-int__event">
                      <input v-model="sl.events" type="checkbox" :value="ev.name" :disabled="saving" />
                      <span class="ar-int__eventtext">
                        <strong>{{ ev.label }}</strong>
                        <small>{{ ev.description }}</small>
                      </span>
                    </label>
                  </fieldset>
                </template>

                <!-- Discord -->
                <template v-else-if="panel === 'discord'">
                  <div class="ar-field">
                    <label for="ar-int-dc-url">Webhook URL</label>
                    <input
                      id="ar-int-dc-url"
                      ref="dcUrlInput"
                      v-model="dc.url"
                      type="url"
                      class="ar-input"
                      autocomplete="off"
                      placeholder="https://discord.com/api/webhooks/…"
                      :disabled="saving"
                    />
                  </div>

                  <fieldset class="ar-int__events">
                    <legend>Which events to send</legend>
                    <label v-for="ev in events" :key="ev.name" class="ar-int__event">
                      <input v-model="dc.events" type="checkbox" :value="ev.name" :disabled="saving" />
                      <span class="ar-int__eventtext">
                        <strong>{{ ev.label }}</strong>
                        <small>{{ ev.description }}</small>
                      </span>
                    </label>
                  </fieldset>
                </template>

                <!-- Google Sheets -->
                <template v-else-if="panel === 'sheets'">
                  <div class="ar-field">
                    <label for="ar-int-sh-id">Spreadsheet ID</label>
                    <input
                      id="ar-int-sh-id"
                      ref="shIdInput"
                      v-model="sh.spreadsheet"
                      type="text"
                      class="ar-input"
                      autocomplete="off"
                      placeholder="1AbC…xYz"
                      :disabled="saving"
                    />
                    <p class="ar-field__hint">
                      The long code in the sheet’s URL, between <code>/d/</code> and
                      <code>/edit</code> — or paste the whole URL and the code is taken from it.
                    </p>
                  </div>

                  <fieldset class="ar-int__events">
                    <legend>Which events to append</legend>
                    <label v-for="ev in events" :key="ev.name" class="ar-int__event">
                      <input v-model="sh.events" type="checkbox" :value="ev.name" :disabled="saving" />
                      <span class="ar-int__eventtext">
                        <strong>{{ ev.label }}</strong>
                        <small>{{ ev.description }}</small>
                      </span>
                    </label>
                  </fieldset>

                  <p class="ar-field__hint">
                    Every event lands as one row of five columns, in this order:
                    <strong>When</strong> (site time) · <strong>Event</strong> ·
                    <strong>Level</strong> (a finding’s urgency; empty otherwise) ·
                    <strong>What happened</strong> · <strong>Link</strong>.
                  </p>
                </template>

                <!-- The private feed -->
                <template v-else-if="panel === 'feed'">
                  <fieldset class="ar-int__events">
                    <legend>Which events the feed carries</legend>
                    <label v-for="ev in events" :key="ev.name" class="ar-int__event">
                      <input v-model="fe.events" type="checkbox" :value="ev.name" :disabled="saving" />
                      <span class="ar-int__eventtext">
                        <strong>{{ ev.label }}</strong>
                        <small>{{ ev.description }}</small>
                      </span>
                    </label>
                  </fieldset>

                  <p class="ar-field__hint">
                    The feed keeps the last 50 events, plus an item for every finding still open.
                    Your site’s own reports only — never your visitors’ data.
                  </p>

                  <!-- The URL's one and only sighting (connect or new URL). -->
                  <div v-if="feedUrl" class="ar-int__once">
                    <div class="ar-int__oncerow">
                      <code class="ar-int__secret">{{ feedUrl }}</code>
                      <button type="button" class="ar-btn ar-btn--ghost" @click="copyFeedUrl">
                        {{ feedUrlCopied ? 'Copied' : 'Copy' }}
                      </button>
                    </div>
                    <p class="ar-field__hint">
                      <strong>Shown once.</strong> Paste it into your reader now — it can’t be
                      shown again. Lost it? “New URL” mints a fresh one.
                    </p>
                  </div>
                </template>
              </div>
            </div>

            <!-- A refused connect must land NEXT TO the button that asked — the
                 body scrolls, the verdict must not. -->
            <p v-if="formError" class="ar-int__moderr" role="alert">{{ formError }}</p>

            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" :disabled="saving" @click="closePanel">
                Close
              </button>
              <button
                v-if="panel === 'webhook' && webhook.enabled"
                type="button"
                class="ar-btn ar-btn--ghost"
                :disabled="saving"
                @click="regenerate"
              >New secret</button>
              <button
                v-if="panel === 'webhook' && webhook.enabled"
                type="button"
                class="ar-btn ar-btn--danger"
                :disabled="saving"
                @click="disconnect"
              >Disconnect</button>
              <button
                v-if="panel === 'telegram' && telegram.enabled"
                type="button"
                class="ar-btn ar-btn--danger"
                :disabled="saving"
                @click="disconnectTelegram"
              >Disconnect</button>
              <button
                v-if="panel === 'slack' && slack.enabled"
                type="button"
                class="ar-btn ar-btn--danger"
                :disabled="saving"
                @click="disconnectSlack"
              >Disconnect</button>
              <button
                v-if="panel === 'discord' && discord.enabled"
                type="button"
                class="ar-btn ar-btn--danger"
                :disabled="saving"
                @click="disconnectDiscord"
              >Disconnect</button>
              <button
                v-if="panel === 'sheets' && sheets.enabled"
                type="button"
                class="ar-btn ar-btn--danger"
                :disabled="saving"
                @click="disconnectSheets"
              >Disconnect</button>
              <button
                v-if="panel === 'feed' && feed.enabled"
                type="button"
                class="ar-btn ar-btn--ghost"
                :disabled="saving"
                @click="regenerateFeed"
              >New URL</button>
              <button
                v-if="panel === 'feed' && feed.enabled"
                type="button"
                class="ar-btn ar-btn--danger"
                :disabled="saving"
                @click="disconnectFeed"
              >Disconnect</button>
              <button v-if="panel === 'webhook'" type="button" class="ar-btn" :disabled="saving" @click="save">
                {{ saving ? 'Saving…' : (webhook.enabled ? 'Save' : 'Connect') }}
              </button>
              <button v-else-if="panel === 'telegram'" type="button" class="ar-btn" :disabled="saving" @click="saveTelegram">
                {{ saving ? 'Saving…' : (telegram.enabled ? 'Save' : 'Connect') }}
              </button>
              <button v-else-if="panel === 'slack'" type="button" class="ar-btn" :disabled="saving" @click="saveSlack">
                {{ saving ? 'Saving…' : (slack.enabled ? 'Save' : 'Connect') }}
              </button>
              <button v-else-if="panel === 'discord'" type="button" class="ar-btn" :disabled="saving" @click="saveDiscord">
                {{ saving ? 'Saving…' : (discord.enabled ? 'Save' : 'Connect') }}
              </button>
              <button v-else-if="panel === 'sheets'" type="button" class="ar-btn" :disabled="saving" @click="saveSheets">
                {{ saving ? 'Saving…' : (sheets.enabled ? 'Save' : 'Connect') }}
              </button>
              <button v-else-if="panel === 'feed'" type="button" class="ar-btn" :disabled="saving" @click="saveFeed">
                {{ saving ? 'Saving…' : (feed.enabled ? 'Save' : 'Connect') }}
              </button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>
  </div>
</template>
