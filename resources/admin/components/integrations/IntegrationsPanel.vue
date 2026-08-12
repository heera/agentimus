<script>
/**
 * Integrations — what Agentimus connects to. Two tabs, one grammar.
 *
 * PLUGINS is the roster of plugins whose content this site describes to AI
 * assistants (presence stated honestly either way), plus the developer card
 * pointing at the provider guide. SERVICES is what receives this site's own
 * report events — today the outgoing webhook, with the rest of the roster
 * shown as "Coming" so the owner learns what exists without a dead control
 * to click.
 *
 * The tabs are the plugin's own tab convention: 50/50 full width, the active
 * indicator on the TOP edge (the .ar-tabpanel__tabs strip Visibility already
 * wears). The card grid is the blessed FluentForms-style grammar, shared via
 * IntegrationCard.
 *
 * The webhook's doing happens in a focused panel below the grid — url, event
 * checkboxes, Save/Disconnect — never a modal (the Teleport law exists, but a
 * panel that stays in the page needs no teleporting at all). The signing
 * secret appears exactly once, in the response that minted it; afterwards the
 * screen only ever admits one exists.
 *
 * Always mounted, fetch on first reveal, re-read on every return — the
 * freshness rule every data screen follows.
 */
import CardSkeleton from '../CardSkeleton.vue';
import IntegrationCard from './IntegrationCard.vue';
import WebhookCard from './services/WebhookCard.vue';
import PluginCard from './plugins/PluginCard.vue';
import { copyText } from '../../js/clipboard.js';
import { confirm } from '../../js/confirm.js';

// The services that exist as plans, not code. Stated so the roster teaches
// what is coming — with no controls, because there is nothing to control.
const COMING = [
  { id: 'telegram', mark: 'Tg', name: 'Telegram', blurb: 'Events as messages from a bot you own — your phone learns what your site saw.' },
  { id: 'slack', mark: 'Sl', name: 'Slack', blurb: 'Events into a channel, where your team already looks.' },
  { id: 'discord', mark: 'Dc', name: 'Discord', blurb: 'The same events, posted to a server you run.' },
  { id: 'sheets', mark: 'Sh', name: 'Google Sheets', blurb: 'Events appended to a sheet — a history that outlives the 30-day log.' },
];

const DEV_GUIDE = 'https://heera.github.io/agentimus/developer/integrate-your-plugin.html';

export default {
  name: 'IntegrationsPanel',
  components: { CardSkeleton, IntegrationCard, WebhookCard, PluginCard },
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show; fetch on first reveal, re-read on every return.
    active: { type: Boolean, default: false },
  },
  emits: ['flash'],
  data() {
    return {
      view: 'plugins',
      coming: COMING,
      devGuide: DEV_GUIDE,
      loading: false,
      loaded: false,
      error: '',
      webhook: { enabled: false, url: '', events: [], hasSecret: false, queued: 0, state: { lastDeliveredAt: 0, lastError: '', lastErrorAt: 0 } },
      events: [],
      plugins: [],
      // The focused webhook panel.
      panelOpen: false,
      form: { url: '', events: [] },
      saving: false,
      formError: '',
      // The secret's single appearance. Cleared when the panel closes — after
      // that, only its existence is ever admitted again.
      secret: '',
      secretCopied: false,
    };
  },
  watch: {
    active(on) {
      // Every arrival re-fetches: a delivery may have landed (or failed) since
      // this screen was last on top, and the card's honesty line must say so.
      if (on) this.load();
    },
  },
  // The watcher only fires on a CHANGE, so a cold load straight to
  // #integrations — where `active` is already true at mount — would render
  // chrome and never fetch. Same guard every kept-alive panel uses.
  mounted() {
    if (this.active) this.load();
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
      this.events = data.events || [];
      this.plugins = data.plugins || [];
    },
    openPanel() {
      // The form starts from what is stored; a fresh connect starts with every
      // box ticked — subscribing to nothing is the one setup that can never be
      // what anyone meant, and the server refuses it anyway.
      this.form.url = this.webhook.url || '';
      this.form.events = this.webhook.enabled && this.webhook.events.length
        ? [...this.webhook.events]
        : this.events.map((e) => e.name);
      this.formError = '';
      this.secret = '';
      this.secretCopied = false;
      this.panelOpen = true;
      this.$nextTick(() => {
        const el = this.$refs.urlInput;
        if (el) el.focus();
      });
    },
    closePanel() {
      this.panelOpen = false;
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
      this.saving = true;
      try {
        this.apply(await this.api.actIntegrations({ action: 'disconnect' }));
        this.closePanel();
        this.$emit('flash', 'success', 'Webhook disconnected.');
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
            <IntegrationCard
              v-for="s in coming"
              :key="s.id"
              :mark="s.mark"
              :name="s.name"
              :blurb="s.blurb"
              :chip="{ label: 'Coming', tone: '' }"
            />
          </div>

          <!-- The focused webhook panel: everything the card only points at. -->
          <section v-if="panelOpen" id="ar-int-webhook" class="ar-card ar-int__panel">
            <div class="ar-card__head ar-card__head--inline">
              <div class="ar-card__titlewrap">
                <h2 class="ar-card__title">Webhook</h2>
              </div>
            </div>
            <p class="ar-int__panellead">
              Each event arrives at your URL as one JSON POST. The
              <code>X-Agentimus-Signature</code> header carries an HMAC-SHA256 of the body, signed
              with the secret below — check it and you know the event came from this site.
            </p>

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

            <p v-if="formError" class="ar-field__hint ar-int__err" role="alert">{{ formError }}</p>

            <div class="ar-int__actions">
              <button type="button" class="ar-btn" :disabled="saving" @click="save">
                {{ saving ? 'Saving…' : (webhook.enabled ? 'Save' : 'Connect') }}
              </button>
              <button
                v-if="webhook.enabled"
                type="button"
                class="ar-btn ar-btn--ghost"
                :disabled="saving"
                @click="regenerate"
              >New secret</button>
              <button
                v-if="webhook.enabled"
                type="button"
                class="ar-btn ar-btn--danger"
                :disabled="saving"
                @click="disconnect"
              >Disconnect</button>
              <button type="button" class="ar-btn ar-btn--ghost" :disabled="saving" @click="closePanel">
                Close
              </button>
            </div>
          </section>
        </section>
      </template>
    </div>
  </div>
</template>
