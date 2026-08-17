<script>
/**
 * Integrations — what Agentimus connects to. Two tabs, one grammar.
 *
 * PLUGINS is the roster of plugins whose content this site describes to AI
 * assistants (presence stated honestly either way), plus the developer card
 * pointing at the provider guide. SERVICES is what receives this site's own
 * report events — the webhook, Telegram, Slack, Discord, Google Sheets and
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
 * response that minted it; the
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
import XCard from './services/XCard.vue';
import LinkedInCard from './services/LinkedInCard.vue';
import PluginCard from './plugins/PluginCard.vue';
import BrandMark from '../BrandMark.vue';
import { copyText } from '../../js/clipboard.js';
import { formatDate, relTimeShort } from '../../js/wpDate.js';
import { confirm } from '../../js/confirm.js';
import { bindDocEsc } from '../../js/docEsc.js';

const DEV_GUIDE = 'https://heera.github.io/agentimus/developer/integrate-your-plugin.html';

export default {
  name: 'IntegrationsPanel',
  components: { CardSkeleton, BrandMark, IntegrationCard, WebhookCard, TelegramCard, SlackCard, DiscordCard, SheetsCard, XCard, LinkedInCard, PluginCard },
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
      webhook: { enabled: false, url: '', events: [], hasSecret: false, queued: 0, stalledFor: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      telegram: { enabled: false, chat: '', events: [], tier: 'all', hasToken: false, queued: 0, stalledFor: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      slack: { enabled: false, url: '', events: [], queued: 0, stalledFor: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      discord: { enabled: false, url: '', events: [], queued: 0, stalledFor: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      sheets: { enabled: false, spreadsheet: '', events: [], hasKey: false, saEmail: '', queued: 0, stalledFor: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      events: [],
      plugins: [],
      // The SHARING tab's read: each network's use of its shared connection,
      // plus the ledger at a glance for the roll-up line.
      sharing: {
        telegram: { enabled: false, channel: '', hasToken: false, active: false, queued: 0, lastSentAt: 0 },
        x: { enabled: false, connected: false, active: false, handle: '', refreshError: '', connectError: '', callbackUrl: '', hasClientId: false, queued: 0, lastSentAt: 0 },
        linkedin: { enabled: false, connected: false, expired: false, expiresAt: 0, active: false, name: '', connectError: '', callbackUrl: '', hasSecret: false, clientId: '', queued: 0, lastSentAt: 0 },
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
      // X's modal form: the one field a PKCE public client needs.
      xForm: { clientId: '' },
      // LinkedIn's modal form: a confidential client's pair. The secret
      // follows the bot token's law — pasted once, never echoed back.
      liForm: { clientId: '', clientSecret: '' },
      callbackCopied: false,
      sl: { url: '', events: [] },
      dc: { url: '', events: [] },
      sh: { spreadsheet: '', events: [] },
      saving: false,
      formError: '',
      // The secret's single appearance. Cleared when the panel closes — after
      // that, only its existence is ever admitted again.
      secret: '',
      secretCopied: false,
    };
  },
  computed: {
    // The open service's name over the modal — one shell, one title slot.
    modalTitle() {
      return { webhook: 'Webhook', telegram: 'Telegram', x: 'X (Twitter)', linkedin: 'LinkedIn', slack: 'Slack', discord: 'Discord', sheets: 'Google Sheets' }[this.panel] || '';
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
      this.view = 'services';
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
    closePanel() {
      this.panel = '';
      this.secret = '';
      this.secretCopied = false;
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
    xStateLine() {
      const x = this.sharing.x;
      const parts = [];
      if (x.lastSentAt) parts.push(`Last announcement ${relTimeShort(x.lastSentAt * 1000)}`);
      if (x.queued) parts.push(`${x.queued} queued`);
      return parts.length ? parts.join(' · ') : 'No announcements yet.';
    },
    /* -- X (Twitter) ------------------------------------------------------- */
    openX() {
      // Connections live on Services — reached from the Sharing card, the
      // owner lands there first (Telegram's road), with the modal open on top.
      this.view = 'services';
      this.xForm.clientId = '';
      this.formError = '';
      this.callbackCopied = false;
      this.panel = 'x';
    },
    async copyCallback() {
      const ok = await copyText(this.sharing.x.callbackUrl);
      this.callbackCopied = ok;
      if (ok) setTimeout(() => { this.callbackCopied = false; }, 2200);
    },
    // The authorize round-trip leaves the page — X brings the owner back to
    // the callback, which lands them home on this screen, connected (or with
    // the refusal written on the card).
    async authorizeX() {
      if (!this.api || this.saving) return;
      this.saving = true;
      this.formError = '';
      try {
        const data = await this.api.actIntegrations({ action: 'authorize', service: 'x', client_id: this.xForm.clientId });
        if (data.authorizeUrl) window.location.href = data.authorizeUrl;
      } catch (e) {
        this.saving = false;
        this.formError = e.message || 'Could not start the authorization.';
      }
    },
    async disconnectX() {
      const ok = await confirm({
        title: 'Disconnect X?',
        message: 'Announcements stop, anything still queued for X is discarded when it comes due, and the grant is revoked at X itself — not just deleted here. Reconnecting runs the authorize again.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      await this.doDisconnect({ action: 'disconnect', service: 'x' }, 'X disconnected — the grant was revoked.');
    },
    async saveXSharing(on) {
      if (!this.api || this.shareSaving) return;
      if (!on) {
        const ok = await confirm({
          title: 'Turn off announcing on X?',
          message: 'New announcements can\u2019t be queued for X, and anything already queued parks as \u201cDidn\u2019t go\u201d when its minute comes. The connection stays — coming back is one click.',
          confirmLabel: 'Turn off',
          cancelLabel: 'Keep announcing',
          tone: 'danger',
        });
        if (!ok) return;
      }
      this.shareSaving = true;
      this.shareError = '';
      try {
        this.apply(await this.api.actIntegrations({ action: 'share', service: 'x', enabled: on }));
        this.$emit('flash', 'success', on ? 'Announcing on X is on.' : 'Announcing on X is off.');
      } catch (e) {
        this.shareError = e.message || 'Could not save the announcing setup.';
      } finally {
        this.shareSaving = false;
      }
    },
    async testXSharing() {
      if (!this.api || this.shareSaving) return;
      this.shareSaving = true;
      this.shareError = '';
      try {
        this.apply(await this.api.actIntegrations({ action: 'share-test', service: 'x' }));
        this.$emit('flash', 'success', 'Test posted \u2014 look at the timeline.');
      } catch (e) {
        this.shareError = e.message || 'The test didn\u2019t go.';
      } finally {
        this.shareSaving = false;
      }
    },
    /* -- LinkedIn ----------------------------------------------------------- */
    liStateLine() {
      const li = this.sharing.linkedin;
      const parts = [];
      if (li.lastSentAt) parts.push(`Last announcement ${relTimeShort(li.lastSentAt * 1000)}`);
      if (li.queued) parts.push(`${li.queued} queued`);
      return parts.length ? parts.join(' · ') : 'No announcements yet.';
    },
    // The date the sixty days end — the card's standing honesty line.
    liReconnectBy() {
      const at = this.sharing.linkedin.expiresAt;
      return at ? formatDate(new Date(at * 1000)) : '';
    },
    openLinkedIn() {
      // Same road as X: the Sharing card's connect lands on Services first.
      this.view = 'services';
      // A reconnect keeps the stored app: the id is pre-filled, the secret
      // stays where it lives (its field empty means "keep what's stored").
      this.liForm.clientId = this.sharing.linkedin.clientId || '';
      this.liForm.clientSecret = '';
      this.formError = '';
      this.callbackCopied = false;
      this.panel = 'linkedin';
    },
    async copyLinkedInCallback() {
      const ok = await copyText(this.sharing.linkedin.callbackUrl);
      this.callbackCopied = ok;
      if (ok) setTimeout(() => { this.callbackCopied = false; }, 2200);
    },
    // Same round-trip as X's: the page leaves for LinkedIn and the callback
    // lands the owner back on this screen — connected, or with the refusal
    // written on the card.
    async authorizeLinkedIn() {
      if (!this.api || this.saving) return;
      this.saving = true;
      this.formError = '';
      try {
        const data = await this.api.actIntegrations({
          action: 'authorize',
          service: 'linkedin',
          client_id: this.liForm.clientId,
          client_secret: this.liForm.clientSecret,
        });
        if (data.authorizeUrl) window.location.href = data.authorizeUrl;
      } catch (e) {
        this.saving = false;
        this.formError = e.message || 'Could not start the authorization.';
      }
    },
    async disconnectLinkedIn() {
      const ok = await confirm({
        title: 'Disconnect LinkedIn?',
        message: 'Announcements stop, anything still queued for LinkedIn is discarded when it comes due, and the app\u2019s access and secret are forgotten here. Reconnecting runs the authorize again.',
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (!ok) return;
      await this.doDisconnect({ action: 'disconnect', service: 'linkedin' }, 'LinkedIn disconnected.');
    },
    async saveLinkedInSharing(on) {
      if (!this.api || this.shareSaving) return;
      if (!on) {
        const ok = await confirm({
          title: 'Turn off announcing on LinkedIn?',
          message: 'New announcements can\u2019t be queued for LinkedIn, and anything already queued parks as \u201cDidn\u2019t go\u201d when its minute comes. The connection stays \u2014 coming back is one click.',
          confirmLabel: 'Turn off',
          cancelLabel: 'Keep announcing',
          tone: 'danger',
        });
        if (!ok) return;
      }
      this.shareSaving = true;
      this.shareError = '';
      try {
        this.apply(await this.api.actIntegrations({ action: 'share', service: 'linkedin', enabled: on }));
        this.$emit('flash', 'success', on ? 'Announcing on LinkedIn is on.' : 'Announcing on LinkedIn is off.');
      } catch (e) {
        this.shareError = e.message || 'Could not save the announcing setup.';
      } finally {
        this.shareSaving = false;
      }
    },
    async testLinkedInSharing() {
      if (!this.api || this.shareSaving) return;
      this.shareSaving = true;
      this.shareError = '';
      try {
        this.apply(await this.api.actIntegrations({ action: 'share-test', service: 'linkedin' }));
        this.$emit('flash', 'success', 'Test posted \u2014 look at your feed.');
      } catch (e) {
        this.shareError = e.message || 'The test didn\u2019t go.';
      } finally {
        this.shareSaving = false;
      }
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
        this.$emit('flash', 'success', wasConnected ? 'Slack settings saved.' : 'Slack connected — the channel just got its first message.');
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
        this.$emit('flash', 'success', wasConnected ? 'Discord settings saved.' : 'Discord connected — the channel just got its first message.');
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
            A plugin is <strong>described</strong> when it keeps something public — that part joins
            what AI assistants can read about your site. Plugins that keep everything behind your
            login have nothing to pass on, and say so. Ones you don’t run are listed too, so you can
            see the whole picture.
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
            <XCard :x="sharing.x" @open="openX" />
            <LinkedInCard :linkedin="sharing.linkedin" @open="openLinkedIn" />
            <TelegramCard :telegram="telegram" @open="openTelegram" />
            <SlackCard :slack="slack" @open="openSlack" />
            <DiscordCard :discord="discord" @open="openDiscord" />
            <SheetsCard :sheets="sheets" @open="openSheets" />
            <!-- The catch-all closes the shelf at full width, on its own
                 ground — after the named tools, "everything else" makes
                 sense; before them it would just be jargon. -->
            <div class="ar-int__wide">
              <WebhookCard :webhook="webhook" @open="openPanel" />
            </div>
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
                <span class="ar-int__mark is-brand" aria-hidden="true"><BrandMark brand="x" /></span>
                <h3 class="ar-int__name">Twitter</h3>
                <span class="ar-int__chip" :class="{ 'is-on': sharing.x.active }">
                  {{ sharing.x.active ? 'Announcing' : (sharing.x.connected ? 'Off' : 'No app yet') }}
                </span>
                <button
                  v-if="sharing.x.enabled"
                  type="button"
                  class="ar-int__power"
                  :disabled="shareSaving"
                  aria-label="Turn off announcing on X"
                  @click="saveXSharing(false)"
                >
                  <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 1.6v6" /><path d="M11.9 3.9a5.6 5.6 0 1 1-7.8 0" /></svg>
                </button>
              </div>

              <template v-if="!sharing.x.connected">
                <p class="ar-int__blurb">
                  Announcing on X runs through an app you own — connect it once on Services.
                </p>
                <div class="ar-int__foot ar-int__foot--fill">
                  <button type="button" class="ar-btn ar-btn--ghost" @click="openX">
                    Connect X on Services
                  </button>
                </div>
              </template>

              <template v-else>
                <p class="ar-int__blurb">
                  Announcements post to <strong>@{{ sharing.x.handle || '…' }}</strong>'s own timeline —
                  there is nothing to point at, so there is nothing to fill in. Drafts over 280 are
                  refused at the queue, where you can still fix them.
                </p>
                <p v-if="sharing.x.refreshError" class="ar-int__note is-err">Renewal refused — announcing is paused. Reconnect on Services.</p>
                <p v-if="shareError" class="ar-int__moderr" role="alert">{{ shareError }}</p>
                <!-- Bad news TAKES the state line's seat, never stacks on top
                     of it: while the grant is refused, "no announcements yet"
                     is a fact about a card that cannot announce at all. -->
                <p v-if="sharing.x.active && !sharing.x.refreshError" class="ar-int__note">{{ xStateLine() }}</p>
                <div class="ar-int__foot ar-int__foot--fill">
                  <button
                    v-if="!sharing.x.enabled"
                    type="button"
                    class="ar-btn"
                    :disabled="shareSaving"
                    @click="saveXSharing(true)"
                  >Turn on Announcing</button>
                  <button
                    v-if="sharing.x.active"
                    type="button"
                    class="ar-btn ar-btn--ghost"
                    :disabled="shareSaving"
                    @click="testXSharing"
                  >Send a Test Announcement</button>
                </div>
              </template>
            </article>

            <article class="ar-int__card">
              <div class="ar-int__head">
                <span class="ar-int__mark is-brand" aria-hidden="true"><BrandMark brand="linkedin" /></span>
                <h3 class="ar-int__name">LinkedIn</h3>
                <span class="ar-int__chip" :class="{ 'is-on': sharing.linkedin.active, 'is-err': sharing.linkedin.connected && sharing.linkedin.expired }">
                  {{ sharing.linkedin.active ? 'Announcing' : (sharing.linkedin.connected ? (sharing.linkedin.expired ? 'Reconnect' : 'Off') : 'No app yet') }}
                </span>
                <button
                  v-if="sharing.linkedin.enabled"
                  type="button"
                  class="ar-int__power"
                  :disabled="shareSaving"
                  aria-label="Turn off announcing on LinkedIn"
                  @click="saveLinkedInSharing(false)"
                >
                  <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 1.6v6" /><path d="M11.9 3.9a5.6 5.6 0 1 1-7.8 0" /></svg>
                </button>
              </div>

              <template v-if="!sharing.linkedin.connected">
                <p class="ar-int__blurb">
                  Announcing on LinkedIn runs through an app you own — connect it once on Services.
                  LinkedIn needs you to have a Page before it will make that app.
                </p>
                <div class="ar-int__foot ar-int__foot--fill">
                  <button type="button" class="ar-btn ar-btn--ghost" @click="openLinkedIn">
                    Connect LinkedIn on Services
                  </button>
                </div>
              </template>

              <template v-else>
                <p class="ar-int__blurb">
                  Announcements post to <strong>{{ sharing.linkedin.name || '…' }}</strong>'s own feed —
                  there is nothing to point at, so there is nothing to fill in. Drafts over 3,000
                  characters are refused at the queue, where you can still fix them.
                </p>
                <p v-if="sharing.linkedin.expired" class="ar-int__note is-err">
                  The sixty-day access ran out — announcing is paused. Reconnect on Services.
                </p>
                <p v-else-if="liReconnectBy()" class="ar-int__note">
                  LinkedIn's access lasts about sixty days and doesn't renew itself — reconnect by {{ liReconnectBy() }}.
                </p>
                <p v-if="shareError" class="ar-int__moderr" role="alert">{{ shareError }}</p>
                <!-- Same law as X's card: the paused sentence owns the line. -->
                <p v-if="sharing.linkedin.active && !sharing.linkedin.expired" class="ar-int__note">{{ liStateLine() }}</p>
                <div class="ar-int__foot ar-int__foot--fill">
                  <button
                    v-if="!sharing.linkedin.enabled"
                    type="button"
                    class="ar-btn"
                    :disabled="shareSaving || sharing.linkedin.expired"
                    @click="saveLinkedInSharing(true)"
                  >Turn on Announcing</button>
                  <button
                    v-if="sharing.linkedin.active"
                    type="button"
                    class="ar-btn ar-btn--ghost"
                    :disabled="shareSaving"
                    @click="testLinkedInSharing"
                  >Send a Test Announcement</button>
                </div>
              </template>
            </article>
            <article class="ar-int__card">
              <div class="ar-int__head">
                <span class="ar-int__mark is-brand" aria-hidden="true"><BrandMark brand="telegram" /></span>
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
                <div class="ar-int__foot ar-int__foot--fill">
                  <button type="button" class="ar-btn ar-btn--ghost" @click="openTelegram">
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
                  <!-- Save rides IN the field, on the right edge: the button
                       belongs to the one value it writes, not to the card's
                       foot where it sat beside an unrelated action. It shows
                       only once announcing is on — before that, turning it on
                       IS the save. -->
                  <div class="ar-field__group">
                    <input
                      id="ar-int-tg-share-channel"
                      v-model="shareTg.channel"
                      type="text"
                      class="ar-input"
                      autocomplete="off"
                      placeholder="@yourchannel"
                      :disabled="shareSaving"
                    />
                    <button
                      v-if="sharing.telegram.enabled"
                      type="button"
                      class="ar-field__act"
                      :disabled="shareSaving || shareTg.channel === sharing.telegram.channel"
                      aria-label="Save the channel"
                      @click="saveTelegramSharing(true)"
                    >
                      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.8 3.8h11l4.4 4.4v11a1.4 1.4 0 0 1-1.4 1.4H4.8a1.4 1.4 0 0 1-1.4-1.4V5.2a1.4 1.4 0 0 1 1.4-1.4Z" /><path d="M16.6 20.6v-7H7.4v7" /><path d="M7.4 3.8v4.6h6.4" /></svg>
                    </button>
                  </div>
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
                <div class="ar-int__foot ar-int__foot--fill">
                  <button
                    v-if="!sharing.telegram.enabled"
                    type="button"
                    class="ar-btn"
                    :disabled="shareSaving"
                    @click="saveTelegramSharing(true)"
                  >Turn on announcing</button>
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
                Connecting sends one test event there first, to prove the road works.
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
                here and each event arrives in that channel as one message — connecting posts one
                test message there to prove the road works. Mattermost and Rocket.Chat speak the
                same format — their URLs work too.
              </p>
              <p v-else-if="panel === 'discord'" class="ar-int__panellead">
                In your server: <strong>Server Settings → Integrations → Webhooks → New
                Webhook</strong>, pick the channel, copy its URL and paste it here. Each event
                arrives in that channel as one embed — connecting posts one test message there to
                prove the road works.
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

                <!-- X (Twitter) -->
                <template v-else-if="panel === 'x'">
                  <template v-if="!sharing.x.connected">
                    <details class="ar-fold ar-fold--guide">
                      <summary>Creating your X app, step by step</summary>
                      <ol class="ar-guide">
                        <li>Sign in at <code>developer.x.com</code> and create your developer account. X meters API posting with <strong>credits</strong> — each announcement spends a little; their console states the price.</li>
                        <li>Create a <strong>Project</strong> and an <strong>App</strong> inside it — names are yours to pick.</li>
                        <li>In the app's <strong>User authentication settings</strong>: turn on <strong>OAuth 2.0</strong>, set the type to <strong>Public client</strong>, and paste the <strong>Callback URL</strong> from below.</li>
                        <li>Copy the app's <strong>Client ID</strong> into the field below — there is no secret to copy; this connection doesn't use one.</li>
                      </ol>
                    </details>

                    <div class="ar-field">
                      <label for="ar-int-x-callback">Callback URL — paste this into your X app</label>
                      <div class="ar-int__oncerow">
                        <input id="ar-int-x-callback" class="ar-input" type="text" readonly :value="sharing.x.callbackUrl" />
                        <button type="button" class="ar-btn ar-btn--ghost" @click="copyCallback">{{ callbackCopied ? 'Copied ✓' : 'Copy' }}</button>
                      </div>
                      <p class="ar-field__hint">
                        X sends you back here after you approve — it must match the app's settings exactly.
                      </p>
                    </div>

                    <div class="ar-field">
                      <label for="ar-int-x-client">Client ID</label>
                      <input
                        id="ar-int-x-client"
                        v-model="xForm.clientId"
                        type="text"
                        class="ar-input"
                        autocomplete="off"
                        placeholder="R2d2QmVFTjJh…"
                        :disabled="saving"
                      />
                      <p class="ar-field__hint">
                        From your app's Keys and tokens page. Just the Client ID — never the secret.
                      </p>
                    </div>
                    <p v-if="sharing.x.connectError" class="ar-int__moderr" role="alert">{{ sharing.x.connectError }}</p>
                  </template>

                  <template v-else>
                    <p class="ar-int__panellead">
                      Connected as <strong>@{{ sharing.x.handle || '…' }}</strong> — approved to post, read nothing.
                      Access renews itself; if X ever refuses the renewal, announcing pauses and this card
                      says so — queued posts wait as “Didn't go” rather than vanishing.
                    </p>
                    <p v-if="sharing.x.refreshError" class="ar-int__moderr" role="alert">
                      Renewal refused: {{ sharing.x.refreshError }} — reconnect to resume.
                    </p>
                  </template>
                </template>

                <!-- LinkedIn -->
                <template v-else-if="panel === 'linkedin'">
                  <template v-if="!sharing.linkedin.connected">
                    <!-- The prerequisite states itself BEFORE the steps, not
                         inside a fold: LinkedIn will not issue credentials at
                         all without a Page, and an owner who learns that on
                         LinkedIn's site has already been sent on a dead
                         errand (his own hands, 2026-08-15). -->
                    <p class="ar-int__panellead">
                      <strong>You need a LinkedIn Page first.</strong> LinkedIn only issues app credentials
                      to an app attached to a Page you administer — a personal profile alone can't create
                      one. Making a Page is free and takes a minute. Your announcements still post to
                      <em>your own</em> feed, not the Page's; the Page exists only so the app can be made.
                    </p>
                    <details class="ar-fold ar-fold--guide">
                      <summary>Creating your LinkedIn app, step by step</summary>
                      <ol class="ar-guide">
                        <li>Sign in at <code>developer.linkedin.com</code> and create an <strong>App</strong> — it requires a LinkedIn <strong>Page</strong> to associate, and you must administer that Page.</li>
                        <li>On the app's <strong>Products</strong> tab, add <strong>Share on LinkedIn</strong> and <strong>Sign In with LinkedIn using OpenID Connect</strong> — both are free, self-serve, and approved on request.</li>
                        <li>On the <strong>Auth</strong> tab, paste the <strong>Callback URL</strong> from below under Authorized redirect URLs.</li>
                        <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> from the same Auth tab into the fields below.</li>
                      </ol>
                    </details>

                    <div class="ar-field">
                      <label for="ar-int-li-callback">Callback URL — paste this into your LinkedIn app</label>
                      <div class="ar-int__oncerow">
                        <input id="ar-int-li-callback" class="ar-input" type="text" readonly :value="sharing.linkedin.callbackUrl" />
                        <button type="button" class="ar-btn ar-btn--ghost" @click="copyLinkedInCallback">{{ callbackCopied ? 'Copied ✓' : 'Copy' }}</button>
                      </div>
                      <p class="ar-field__hint">
                        LinkedIn sends you back here after you approve — it must match the app's settings exactly.
                      </p>
                    </div>

                    <div class="ar-field">
                      <label for="ar-int-li-client">Client ID</label>
                      <input
                        id="ar-int-li-client"
                        v-model="liForm.clientId"
                        type="text"
                        class="ar-input"
                        autocomplete="off"
                        placeholder="86xyzab12cd…"
                        :disabled="saving"
                      />
                    </div>
                    <div class="ar-field">
                      <label for="ar-int-li-secret">Client Secret</label>
                      <input
                        id="ar-int-li-secret"
                        v-model="liForm.clientSecret"
                        type="password"
                        class="ar-input"
                        autocomplete="off"
                        :placeholder="sharing.linkedin.hasSecret ? 'Stored — paste again only to replace it' : 'WPL_AP1.…'"
                        :disabled="saving"
                      />
                      <p class="ar-field__hint">
                        Unlike X, LinkedIn's flow requires the secret. It stays on your server and is never shown back.
                      </p>
                    </div>
                    <p v-if="sharing.linkedin.connectError" class="ar-int__moderr" role="alert">{{ sharing.linkedin.connectError }}</p>
                  </template>

                  <template v-else>
                    <p class="ar-int__panellead">
                      Connected as <strong>{{ sharing.linkedin.name || '…' }}</strong> — approved to post, read nothing.
                      LinkedIn's access lasts about sixty days and doesn't renew itself
                      <template v-if="liReconnectBy()">— reconnect by <strong>{{ liReconnectBy() }}</strong> </template>—
                      when it ends, announcing pauses and queued posts wait as “Didn't go” rather than vanishing.
                    </p>
                    <p v-if="sharing.linkedin.expired" class="ar-int__moderr" role="alert">
                      The sixty days ran out — disconnect, then connect again to resume.
                    </p>
                  </template>
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
                v-if="panel === 'x' && sharing.x.connected"
                type="button"
                class="ar-btn ar-btn--danger"
                :disabled="saving"
                @click="disconnectX"
              >Disconnect</button>
              <button v-if="panel === 'x' && !sharing.x.connected" type="button" class="ar-btn" :disabled="saving" @click="authorizeX">
                {{ saving ? 'Off to X…' : 'Authorize on X' }}
              </button>
              <button
                v-if="panel === 'linkedin' && sharing.linkedin.connected"
                type="button"
                class="ar-btn ar-btn--danger"
                :disabled="saving"
                @click="disconnectLinkedIn"
              >Disconnect</button>
              <button v-if="panel === 'linkedin' && !sharing.linkedin.connected" type="button" class="ar-btn" :disabled="saving" @click="authorizeLinkedIn">
                {{ saving ? 'Off to LinkedIn…' : 'Authorize on LinkedIn' }}
              </button>
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
            </div>
          </div>
        </div>
      </transition>
    </Teleport>
  </div>
</template>
