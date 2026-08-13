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
import { relTimeShort } from '../../js/wpDate.js';
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
      view: 'services',
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
      // The SHARING tab's read: each network's use of its shared connection,
      // plus the ledger at a glance for the roll-up line.
      sharing: {
        telegram: { enabled: false, channel: '', hasToken: false, active: false, queued: 0, lastSentAt: 0 },
        ledger: { total: 0, queued: 0, sentWeek: 0, failed: 0 },
      },
      // The sharing form is inline (there is no credential to take, so no
      // modal): the channel being typed, and its own error and busy flags.
      shareTg: { channel: '' },
      shareError: '',
      shareSaving: false,
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
      return { webhook: 'Webhook', telegram: 'Telegram', slack: 'Slack', discord: 'Discord', sheets: 'Google Sheets', feed: 'Private Feed' }[this.panel] || '';
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
      if (data.sharing) {
        this.sharing = data.sharing;
        // The form starts from what is stored; apply() only runs after a load
        // or a save, so this never overwrites mid-typing.
        this.shareTg.channel = data.sharing.telegram.channel || this.shareTg.channel;
      }
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
    // The announcing state line — the sharing counterpart of the Services
    // cards' delivery note: the last send and what's waiting, or the honest
    // "none yet".
    sharingStateLine() {
      const tg = this.sharing.telegram;
      const parts = [];
      if (tg.lastSentAt) parts.push(`Last announcement ${relTimeShort(tg.lastSentAt * 1000)}`);
      if (tg.queued) parts.push(`${tg.queued} queued`);
      return parts.length ? parts.join(' · ') : 'No announcements yet.';
    },
    async testTelegramSharing() {
      if (!this.api || this.shareSaving) return;
      this.shareSaving = true;
      this.shareError = '';
      try {
        this.apply(await this.api.actIntegrations({ action: 'share-test', service: 'telegram' }));
        this.$emit('flash', 'success', 'Test posted — look at the channel.');
      } catch (e) {
        this.shareError = e.message || 'The test didn’t go.';
      } finally {
        this.shareSaving = false;
      }
    },
    goAnnouncements() {
      window.location.hash = '#announcements';
    },
    // The sharing use's one switch: on proves the channel with a test post
    // (the server skips the proof for a channel it already proved); off keeps
    // the channel, so coming back is one click. Off asks first (his call) —
    // and the confirm tells the WHOLE truth: queued rows will park as failed
    // at their minute, because a due promise with no door goes nowhere.
    async saveTelegramSharing(on) {
      if (!this.api || this.shareSaving) return;
      if (!on) {
        const ok = await confirm({
          title: 'Turn off announcing?',
          message: 'New announcements can’t be queued, and anything already queued parks as “Didn’t go” when its minute comes — retry it after turning announcing back on. The bot stays connected and the channel is kept: coming back is one click.',
          confirmLabel: 'Turn off',
          cancelLabel: 'Keep announcing',
          tone: 'danger',
        });
        if (!ok) return;
      }
      this.shareSaving = true;
      this.shareError = '';
      // Whether the server will prove the road — only a channel it has not
      // stored yet gets the test post, so only that save may claim one.
      const proved = on && this.shareTg.channel !== this.sharing.telegram.channel;
      try {
        this.apply(await this.api.actIntegrations({
          action: 'share',
          service: 'telegram',
          enabled: on,
          channel: this.shareTg.channel,
        }));
        this.$emit(
          'flash',
          'success',
          on
            ? (proved ? 'Announcing is on — the channel just got its first post.' : 'Announcing is on.')
            : 'Announcing is off. The channel is kept.'
        );
      } catch (e) {
        this.shareError = e.message || 'Could not save the announcing setup.';
      } finally {
        this.shareSaving = false;
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
        message: 'Messages and announcements stop, anything still queued for Telegram is discarded, and the stored bot token is deleted from this site. The token itself keeps working at Telegram — if it may have leaked, also revoke it with @BotFather (/mybots → API Token).',
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
        :class="{ 'is-active': view === 'services' }"
        @click="view = 'services'"
      >Services</button>
      <button
        type="button"
        class="ar-subnav__item"
        :class="{ 'is-active': view === 'sharing' }"
        @click="view = 'sharing'"
      >Sharing</button>
      <button
        type="button"
        class="ar-subnav__item"
        :class="{ 'is-active': view === 'plugins' }"
        @click="view = 'plugins'"
      >Plugins</button>
    </nav>

    <div class="ar-tabpanel__body">
      <p v-if="error" class="ar-int__error" role="alert">{{ error }} — try again, and if it persists, reload the page.</p>
      <CardSkeleton v-if="!loaded && !error" lead="Loading integrations…" />

      <template v-if="loaded">
        <!-- PLUGINS: what this site describes on other plugins' behalf. -->
        <section v-show="view === 'plugins'">
          <p class="ar-int__lead">
            <span class="ar-int__lead-i" aria-hidden="true"><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 14.2A6.2 6.2 0 1 0 8 1.8a6.2 6.2 0 0 0 0 12.4Z" /><path d="M8 7.4v3.4" /><path d="M8 5.2h.01" /></svg></span>
            <span class="ar-int__lead-t">
            Plugins Agentimus recognises here are <strong>described</strong> — their content joins what
            AI assistants can read about your site. Ones you don’t run are listed so you know what
            would be described if you did.
            </span>
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
            <span class="ar-int__lead-i" aria-hidden="true"><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 14.2A6.2 6.2 0 1 0 8 1.8a6.2 6.2 0 0 0 0 12.4Z" /><path d="M8 7.4v3.4" /><path d="M8 5.2h.01" /></svg></span>
            <span class="ar-int__lead-t">
            Services receive <strong>events</strong> from this site — a new finding, a caught impostor,
            the weekly digest’s numbers. Your site’s own reports only, never your visitors’ data, and
            nothing is sent until you connect one.
            </span>
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

        <!-- SHARING: what announcing uses the connections for. No credential
             lives here — that is Services' business; this tab holds each
             network's own switch and its own destination. -->
        <section v-show="view === 'sharing'">
          <p class="ar-int__lead">
            <span class="ar-int__lead-i" aria-hidden="true"><svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 14.2A6.2 6.2 0 1 0 8 1.8a6.2 6.2 0 0 0 0 12.4Z" /><path d="M8 7.4v3.4" /><path d="M8 5.2h.01" /></svg></span>
            <span class="ar-int__lead-t">
            Announcing posts your <strong>Share drafts</strong> for real — the ones each post offers
            under its Share tab. The connection itself lives on Services; here you pick where
            announcements go. Events tell you, announcements tell your readers — two rooms, on purpose.
            </span>
          </p>
          <div class="ar-int__grid">
            <article class="ar-int__card">
              <div class="ar-int__head">
                <h3 class="ar-int__name">Telegram</h3>
                <span class="ar-int__chip" :class="{ 'is-on': sharing.telegram.active }">
                  {{ sharing.telegram.active ? 'Announcing' : (sharing.telegram.hasToken ? 'Off' : 'No bot yet') }}
                </span>
                <!-- The off switch lives in the title row (his call — a third
                     button made the foot wrap): a power mark, named for
                     readers who can't see it. -->
                <button
                  v-if="sharing.telegram.enabled"
                  type="button"
                  class="ar-int__power"
                  :disabled="shareSaving"
                  aria-label="Turn off announcing"
                  @click="saveTelegramSharing(false)"
                >
                  <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 1.6v6" /><path d="M11.9 3.9a5.6 5.6 0 1 1-7.8 0" /></svg>
                </button>
              </div>

              <template v-if="!sharing.telegram.hasToken">
                <p class="ar-int__blurb">
                  Announcing uses the same bot as Services — connect it once and both work.
                </p>
                <div class="ar-int__foot">
                  <button type="button" class="ar-btn ar-btn--ghost" @click="view = 'services'">
                    Connect the bot on Services
                  </button>
                </div>
              </template>

              <template v-else>
                <p class="ar-int__blurb">
                  Your bot is connected. Announcements go to a channel of their own — readers’ room,
                  not the chat your events use.
                </p>
                <div class="ar-field">
                  <label for="ar-int-tg-share-channel">Channel</label>
                  <input
                    id="ar-int-tg-share-channel"
                    v-model="shareTg.channel"
                    type="text"
                    class="ar-input"
                    autocomplete="off"
                    placeholder="@yourchannel"
                    :disabled="shareSaving"
                  />
                  <p class="ar-field__hint">
                    <code>@name</code> for a public channel you run, or a private one’s
                    <code>-100…</code> number. Add the bot to the channel as an admin first —
                    Telegram only lets admins post.
                  </p>
                </div>
                <!-- The recipe shows only until announcing is ON — a finished
                     setup doesn't need its tutorial. The hint above owns the
                     vocabulary and the why; these steps own only the doing. -->
                <details v-if="!sharing.telegram.enabled" class="ar-fold ar-fold--guide">
                  <summary>Setting up the channel, step by step</summary>
                  <ol class="ar-guide">
                    <li>In Telegram: <strong>New Channel</strong> — name it for your readers.</li>
                    <li>Make it <strong>Public</strong> and claim its <code>t.me/…</code> link — the part after <code>t.me/</code> goes in the field above.</li>
                    <li>Open the channel → its name → <strong>Administrators → Add Admin</strong> → your bot, with <strong>Post Messages</strong> allowed.</li>
                  </ol>
                </details>
                <p v-if="shareError" class="ar-int__moderr" role="alert">{{ shareError }}</p>
                <p v-if="sharing.telegram.active" class="ar-int__note">{{ sharingStateLine() }}</p>
                <div class="ar-int__foot">
                  <button type="button" class="ar-btn" :disabled="shareSaving" @click="saveTelegramSharing(true)">
                    {{ sharing.telegram.enabled ? 'Save' : 'Turn on announcing' }}
                  </button>
                  <button
                    v-if="sharing.telegram.active"
                    type="button"
                    class="ar-btn ar-btn--ghost"
                    :disabled="shareSaving"
                    @click="testTelegramSharing"
                  >Send a test announcement</button>
                </div>
              </template>
            </article>

          </div>

          <!-- Not a card (his ruling): a fact with no switch, field or door is
               TEACHING, not furniture — it answers "why aren't they here" in a
               quiet line instead of dressing up as a network. -->
          <p class="ar-int__aside">
            Facebook and WhatsApp aren’t here — neither lets an app post (their rule), so their
            Share-tab drafts stay copy-paste.
          </p>

          <!-- The mock's owed gold note — the Worth-knowing grammar (the
               .ar-aud__note family is the app's worth-knowing dialect; the
               aud- prefix is history, the churn rule keeps it): the one
               scheduling truth the switches above don't say themselves. -->
          <div class="ar-aud__note">
            <p class="ar-aud__note-t">
              <span class="ar-aud__note-i" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.8a6.6 6.6 0 0 0-3.9 11.9c.8.6 1.3 1.5 1.3 2.4h5.2c0-.9.5-1.8 1.3-2.4A6.6 6.6 0 0 0 12 2.8z" /><path d="M9.9 20h4.2" /></svg>
              </span>
              Worth knowing
            </p>
            <!-- No divider here: the dashboard's pipe separates label from its
                 chip COLUMNS — with one plain column there is nothing to part. -->
            <div class="ar-aud__note-cols">
              <span class="ar-aud__note-facts">
                <span class="ar-aud__note-item">Announcements go out from your own server at the minute you set. If your site is asleep at that minute — small sites sometimes are — the post goes out on its next visit instead, and Announcements records when it really went.</span>
              </span>
            </div>
          </div>

          <!-- The ledger's one line here — the record itself lives on its own
               screen (the boundary law); this is only the count and the door. -->
          <div class="ar-int__ledgerline">
            <p class="ar-int__ledgertext">
              <strong>Scheduled Announcements</strong>
              <template v-if="sharing.ledger.total"> — {{ sharing.ledger.queued }} queued · {{ sharing.ledger.sentWeek }} sent this week<template v-if="sharing.ledger.failed"> · <span class="ar-int__ledgerbad">{{ sharing.ledger.failed }} didn’t go</span></template></template>
              <template v-else> — nothing yet. Queue one from a post’s Share tab.</template>
            </p>
            <button type="button" class="ar-btn ar-btn--ghost" @click="goAnnouncements">See them all</button>
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
                    <legend>Which Events to Send</legend>
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
                  <!-- The first-timer's whole recipe, folded — the returning
                       user never pays for the tutorial (his teaching doctrine:
                       the guide lives where the fields are, closed until asked),
                       and a CONNECTED bot doesn't need its birth story at all. -->
                  <details v-if="!telegram.hasToken" class="ar-fold ar-fold--guide">
                    <summary>Never made a bot? The whole thing takes about three minutes</summary>
                    <ol class="ar-guide">
                      <li>In Telegram, open <code>@BotFather</code> and send <code>/newbot</code> — give the bot a name, then a username ending in <code>bot</code>.</li>
                      <li>BotFather answers with a <strong>token</strong> — the long <code>123456:ABC…</code> code. That’s what you paste below. Lost it? <code>/mybots</code> → your bot → API Token.</li>
                      <li><strong>Message your new bot once</strong> — open it and press Start. Telegram only lets a bot write to someone who wrote first.</li>
                      <li>For the <strong>chat id</strong>: message <code>@userinfobot</code> and it replies with your number.</li>
                    </ol>
                  </details>
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
                    <legend>Which Events to Send to Your Private Chat</legend>
                    <label v-for="ev in events" :key="ev.name" class="ar-int__event">
                      <input v-model="tg.events" type="checkbox" :value="ev.name" :disabled="saving" />
                      <span class="ar-int__eventtext">
                        <strong>{{ ev.label }}</strong>
                        <small>{{ ev.description }}</small>
                      </span>
                    </label>
                  </fieldset>

                  <fieldset class="ar-int__events">
                    <legend>Which Findings to Send</legend>
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
                        <small>A message only when something is actually broken.</small>
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
                    <legend>Which Events to Send</legend>
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
                    <legend>Which Events to Send</legend>
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
                    <legend>Which Events to Append</legend>
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
