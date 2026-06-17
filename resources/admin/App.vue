<script>
import { createApi } from './api.js';
import SettingsForm from './components/SettingsForm.vue';
import ReadinessPanel from './components/ReadinessPanel.vue';
import DiscoveryHub from './components/DiscoveryHub.vue';
import ActivityPanel from './components/ActivityPanel.vue';
import OnboardingWizard from './components/OnboardingWizard.vue';

export default {
  name: 'AgentifyApp',
  components: { SettingsForm, ReadinessPanel, DiscoveryHub, ActivityPanel, OnboardingWizard },
  props: {
    boot: { type: Object, required: true },
  },
  data() {
    const fromHash = (window.location.hash || '').replace(/^#/, '');
    return {
      api: createApi(this.boot),
      // Restore the tab from the URL hash so a refresh keeps the same page.
      tab: ['dashboard', 'settings', 'readiness', 'discovery'].includes(fromHash) ? fromHash : 'dashboard',
      settings: JSON.parse(JSON.stringify(this.boot.settings || {})),
      defaults: this.boot.defaults || {},
      readiness: this.boot.readiness || [],
      refreshingReadiness: false,
      discovery: this.boot.discovery || {},
      refreshingDiscovery: false,
      activity: {},
      activityLoaded: false,
      refreshingActivity: false,
      entityTypes: this.boot.entityTypes || ['Person', 'Organization'],
      postTypes: this.boot.postTypes || [],
      knownTrainers: this.boot.knownTrainers || [],
      restNamespacesDetected: this.boot.restNamespacesDetected || [],
      endpoints: this.boot.endpoints || {},
      version: this.boot.version || '',
      saving: false,
      resetting: false,
      onboarded: !!this.boot.onboarded,
      showWizard: false,
      onboarding: false,
      profileSaving: false,
      profileSaved: false,
      autoStatus: 'idle',
      notice: null,
      ringReady: false,
      savedSnapshot: JSON.stringify(this.boot.settings || {}),
    };
  },
  computed: {
    score() {
      if (!this.readiness.length) return { pass: 0, total: 0, pct: 0 };
      const pass = this.readiness.filter((c) => c.status === 'pass').length;
      const total = this.readiness.length;
      return { pass, total, pct: Math.round((pass / total) * 100) };
    },
    tone() {
      return this.score.pct >= 80 ? 'good' : this.score.pct >= 50 ? 'ok' : 'low';
    },
    issues() {
      return this.readiness.filter((c) => c.status !== 'pass').length;
    },
    dirty() {
      return JSON.stringify(this.settings) !== this.savedSnapshot;
    },
    // The free-text "profile" block (entity/name/role/about/email) saves explicitly;
    // everything else autosaves. profileDirty drives the in-card Save button.
    profileDirty() {
      const saved = JSON.parse(this.savedSnapshot).identity || {};
      const cur = this.settings.identity || {};
      return ['entity_type', 'name', 'role', 'about', 'contact_email'].some(
        (k) => (cur[k] || '') !== (saved[k] || ''),
      );
    },
    // Serializes every AUTOSAVED field (toggles, selections, chips). When this
    // changes we debounce-autosave — without touching the unsaved profile text.
    instantState() {
      const s = this.settings;
      const id = s.identity || {};
      return JSON.stringify({
        enable_llms_txt: s.enable_llms_txt, enable_llms_full: s.enable_llms_full,
        enable_markdown: s.enable_markdown, enable_robots: s.enable_robots,
        enable_schema: s.enable_schema, enable_activity: s.enable_activity,
        enable_sitemap: s.enable_sitemap, enable_security_txt: s.enable_security_txt,
        llms_full_posts: s.llms_full_posts, post_types: s.post_types,
        rest_namespaces: s.rest_namespaces, content_signal: s.content_signal,
        blocked_trainers: s.blocked_trainers, suppressed_resources: s.suppressed_resources,
        security: s.security,
        expertise: id.expertise, same_as: id.same_as,
      });
    },
    // Third-party DECLARED resources the owner can publish/suppress. Our own
    // auto-discovery (wordpress-core, REST stubs, abilities) is curated elsewhere.
    providerResources() {
      const list = (this.discovery && this.discovery.resources) || [];
      return list.filter((r) => !r.auto);
    },
    circumference() {
      return 2 * Math.PI * 52;
    },
    dashOffset() {
      // Starts empty (full offset), animates to the score once mounted.
      return this.ringReady ? this.circumference * (1 - this.score.pct / 100) : this.circumference;
    },
    host() {
      const url = this.endpoints.robots || this.endpoints.llms || '';
      try {
        return new URL(url).host;
      } catch (e) {
        return '';
      }
    },
    tabs() {
      return [
        { id: 'dashboard', label: 'Dashboard' },
        { id: 'settings', label: 'Settings' },
        { id: 'readiness', label: 'Readiness' },
        { id: 'discovery', label: 'Discovery' },
      ];
    },
    dashSummary() {
      const c = (this.discovery && this.discovery.counts) || {};
      return {
        readiness: this.score,
        tone: this.tone,
        providers: c.resources || 0,
        capabilities: c.capabilities || 0,
        tools: c.tools || 0,
      };
    },
    pageMeta() {
      return (
        {
          dashboard: {
            title: 'Dashboard',
            description: 'An overview of your agent-readiness — what you expose, and who is reading it.',
          },
          settings: {
            title: 'Settings',
            description: 'Configure the signals Agentify exposes and the identity agents read.',
          },
          readiness: {
            title: 'Readiness',
            description: 'How machine-legible your site is right now — a checklist of pass, warn and fail checks.',
          },
          discovery: {
            title: 'Discovery',
            description: 'The single document agents read to understand this site — every registered plugin aggregated into one place.',
          },
        }[this.tab] || { title: '', description: '' }
      );
    },
    discoveryDocs() {
      const e = (this.discovery && this.discovery.endpoints) || {};
      return [
        { label: 'discovery.json', url: e.discovery },
        { label: 'agent-card.json', url: e.agentCard },
        { label: 'mcp.json', url: e.mcp },
      ].filter((d) => d.url);
    },
    noticeTitle() {
      return { success: 'Success', error: 'Error', warning: 'Warning' }[this.notice?.type] || 'Notice';
    },
  },
  watch: {
    tab(val) {
      // Reflect the active tab in the URL hash (no history spam, no reload).
      if (window.location.hash.replace(/^#/, '') !== val) {
        window.history.replaceState(null, '', `#${val}`);
      }
      // Activity data is lazy-loaded the first time the Dashboard is opened.
      if (val === 'dashboard' && !this.activityLoaded) {
        this.refreshActivity();
      }
    },
    instantState() {
      // A reset just replaced the whole settings object; don't autosave that
      // (the server already stored the defaults).
      if (this._skipAutosave) {
        this._skipAutosave = false;
        return;
      }
      // A toggle / selection / chip changed → autosave (debounced), leaving the
      // in-progress profile text untouched (it has its own Save).
      this.queueAutosave();
    },
  },
  mounted() {
    window.requestAnimationFrame(() => {
      this.ringReady = true;
    });
    // Make sure the hash reflects the initial tab, and follow back/forward + manual edits.
    if (window.location.hash.replace(/^#/, '') !== this.tab) {
      window.history.replaceState(null, '', `#${this.tab}`);
    }
    window.addEventListener('hashchange', this.syncTabFromHash);
    // Dashboard is the default landing (and a possible deep-link): the watcher
    // won't fire on the initial value, so load its data here.
    if (this.tab === 'dashboard') {
      this.refreshActivity();
    }
    // First run: greet a new admin with the setup wizard.
    if (!this.onboarded) {
      this.showWizard = true;
    }
  },
  beforeUnmount() {
    window.removeEventListener('hashchange', this.syncTabFromHash);
  },
  methods: {
    // Dashboard tiles emit { tab, anchor? }. Switch tab, then (once the now-shown
    // tab has laid out) scroll the target section into view so a click lands on
    // the relevant content, not just the top of the page.
    goTo(target) {
      const { tab, anchor } = typeof target === 'string' ? { tab: target } : target || {};
      if (tab) this.tab = tab;
      if (!anchor) return;
      this.$nextTick(() => {
        const el = document.getElementById(anchor);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    },
    reloadPlugin() {
      // Drop any #tab and do a full reload, landing on the default page.
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
      window.location.reload();
    },
    syncTabFromHash() {
      const h = window.location.hash.replace(/^#/, '');
      if (h && h !== this.tab && this.tabs.some((t) => t.id === h)) {
        this.tab = h;
      }
    },
    // Persist the free-text profile block (entity/name/role/about/email). Sends the
    // full settings; reflects the sanitized profile back into the live object so the
    // form shows exactly what was stored, then briefly flashes "Saved".
    async saveProfile() {
      if (this.profileSaving || !this.profileDirty) {
        return;
      }
      this.profileSaving = true;
      try {
        const res = await this.api.saveSettings(this.settings);
        const savedId = (res.settings && res.settings.identity) || {};
        ['entity_type', 'name', 'role', 'about', 'contact_email'].forEach((k) => {
          if (this.settings.identity) this.settings.identity[k] = savedId[k];
        });
        this.savedSnapshot = JSON.stringify(res.settings);
        this.readiness = res.readiness || this.readiness;
        this.profileSaved = true;
        clearTimeout(this._savedTimer);
        this._savedTimer = setTimeout(() => { this.profileSaved = false; }, 2500);
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.profileSaving = false;
      }
    },
    // Restore factory defaults. Wipes the stored option server-side, then
    // adopts the returned defaults as the new live + saved state so the form
    // reflects them immediately (no reload) and autosave won't fight it.
    async resetSettings() {
      if (this.resetting) return;
      this.resetting = true;
      // Suspend autosave: replacing this.settings changes instantState, which
      // would otherwise queue a redundant save of the defaults we just stored.
      clearTimeout(this._autoTimer);
      try {
        const res = await this.api.resetSettings();
        this._skipAutosave = true;
        this.settings = JSON.parse(JSON.stringify(res.settings || {}));
        this.$nextTick(() => { this._skipAutosave = false; });
        this.savedSnapshot = JSON.stringify(res.settings || {});
        this.readiness = res.readiness || this.readiness;
        this.autoStatus = 'idle';
        this.flash('success', 'Settings restored to defaults.');
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.resetting = false;
      }
    },
    // First-run wizard: persist the captured identity + content choices in one
    // save, adopt the result without re-triggering autosave, then mark onboarding
    // done. Wizard state lives in the child; we only receive the final payload.
    async finishWizard(payload) {
      if (this.onboarding) return;
      this.onboarding = true;
      clearTimeout(this._autoTimer); // the settings swap below must not queue a save
      try {
        const next = JSON.parse(JSON.stringify(this.settings));
        next.identity = next.identity || {};
        next.identity.entity_type = payload.entity_type;
        next.identity.name = payload.name;
        next.identity.about = payload.about;
        next.identity.expertise = payload.expertise;
        next.post_types = payload.types;
        const res = await this.api.saveSettings(next);
        this._skipAutosave = true;
        this.settings = JSON.parse(JSON.stringify(res.settings || {}));
        this.$nextTick(() => { this._skipAutosave = false; });
        this.savedSnapshot = JSON.stringify(res.settings || {});
        this.readiness = res.readiness || this.readiness;
        await this.api.completeOnboarding();
        this.onboarded = true;
        this.showWizard = false;
        this.flash('success', 'Your site is set up for AI assistants.');
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.onboarding = false;
      }
    },
    async skipWizard() {
      if (this.onboarding) return;
      this.onboarding = true;
      try {
        await this.api.completeOnboarding();
        this.onboarded = true;
        this.showWizard = false;
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.onboarding = false;
      }
    },
    // "Run setup again" from Settings — reopen over the current settings (the
    // child re-seeds itself from them on open). Does not clear the onboarded flag.
    reopenWizard() {
      this.showWizard = true;
    },
    // Debounced autosave for toggles / selections / chips.
    queueAutosave() {
      clearTimeout(this._autoTimer);
      this._autoTimer = setTimeout(() => this.autosaveInstant(), 600);
    },
    // Persists everything EXCEPT the in-progress profile text (frozen to its
    // last-saved value), so composing a profile sentence is never saved until the
    // user clicks Save. Never replaces this.settings (that would wipe the draft).
    async autosaveInstant() {
      const savedId = (JSON.parse(this.savedSnapshot).identity) || {};
      const payload = JSON.parse(JSON.stringify(this.settings));
      ['entity_type', 'name', 'role', 'about', 'contact_email'].forEach((k) => {
        if (payload.identity) payload.identity[k] = savedId[k];
      });
      this.autoStatus = 'saving';
      try {
        const res = await this.api.saveSettings(payload);
        this.savedSnapshot = JSON.stringify(res.settings);
        this.readiness = res.readiness || this.readiness;
        this.autoStatus = 'saved';
      } catch (e) {
        this.autoStatus = 'error';
        this.flash('error', e.message);
      }
    },
    async refreshReadiness() {
      this.refreshingReadiness = true;
      try {
        this.readiness = await this.api.getReadiness();
        this.flash('success', `Readiness re-run — ${this.score.pass}/${this.score.total} checks pass.`);
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.refreshingReadiness = false;
      }
    },
    async refreshDiscovery() {
      this.refreshingDiscovery = true;
      try {
        this.discovery = await this.api.getDiscoveryHub();
        this.flash('success', 'Discovery registry re-scanned.');
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.refreshingDiscovery = false;
      }
    },
    async refreshActivity() {
      this.refreshingActivity = true;
      try {
        this.activity = await this.api.getActivity();
        this.activityLoaded = true;
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.refreshingActivity = false;
      }
    },
    async clearActivity() {
      try {
        this.activity = await this.api.clearActivity();
        this.flash('success', 'Activity log cleared.');
      } catch (e) {
        this.flash('error', e.message);
      }
    },
    flash(type, text) {
      this.notice = { type, text };
      window.clearTimeout(this._noticeTimer);
      this._noticeTimer = window.setTimeout(() => {
        this.notice = null;
      }, 4000);
    },
  },
};
</script>

<template>
  <div class="ar">
    <header class="ar__bar">
      <button type="button" class="ar__brand" aria-label="Agentify — reload" @click="reloadPlugin">
        <span class="ar__mark" aria-hidden="true">
          <svg class="ar__logo" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path class="ar__logo-line" d="M4.5 20.5 L12 3.5 L19.5 20.5" />
            <path class="ar__logo-accent" d="M8 13.6 H16" />
          </svg>
        </span>
        <span class="ar__brandtext">
          <span class="ar__name">Agentify</span>
          <span v-if="version" class="ar__ver">Version - {{ version }}</span>
        </span>
      </button>

      <nav class="ar__tabs" role="tablist">
        <button
          v-for="t in tabs"
          :key="t.id"
          class="ar__tab"
          :class="{ 'is-active': tab === t.id }"
          role="tab"
          :aria-selected="tab === t.id"
          @click="tab = t.id"
        >
          {{ t.label }}
        </button>
      </nav>

    </header>

    <Teleport to="body">
      <transition name="ar-toast">
        <div v-if="notice" class="ar-toast" :class="`is-${notice.type}`" role="status" aria-live="polite">
          <span class="ar-toast__bar" aria-hidden="true"></span>
          <div class="ar-toast__body">
            <strong class="ar-toast__title">{{ noticeTitle }}</strong>
            <span class="ar-toast__msg">{{ notice.text }}</span>
          </div>
        </div>
      </transition>
    </Teleport>

    <OnboardingWizard
      :open="showWizard"
      :settings="settings"
      :entity-types="entityTypes"
      :post-types="postTypes"
      :saving="onboarding"
      @finish="finishWizard"
      @skip="skipWizard"
    />

    <div class="ar__pagehead">
      <h1 class="ar__pagehead-title">{{ pageMeta.title }}</h1>
      <p v-if="pageMeta.description" class="ar__pagehead-desc">{{ pageMeta.description }}</p>
    </div>

    <main class="ar__body is-railed">
      <div class="ar__main">
        <SettingsForm
          v-show="tab === 'settings'"
          v-model:settings="settings"
          :entity-types="entityTypes"
          :post-types="postTypes"
          :known-trainers="knownTrainers"
          :endpoints="endpoints"
          :rest-namespaces-detected="restNamespacesDetected"
          :provider-resources="providerResources"
          :profile-dirty="profileDirty"
          :profile-saving="profileSaving"
          :profile-saved="profileSaved"
          :resetting="resetting"
          :defaults="defaults"
          @save-profile="saveProfile"
          @reset="resetSettings"
          @reopen-wizard="reopenWizard"
        />
        <ReadinessPanel
          v-show="tab === 'readiness'"
          :checks="readiness"
          :refreshing="refreshingReadiness"
          @refresh="refreshReadiness"
          @navigate="goTo"
        />
        <DiscoveryHub
          v-show="tab === 'discovery'"
          :data="discovery"
          :refreshing="refreshingDiscovery"
          @refresh="refreshDiscovery"
        />
        <ActivityPanel
          v-show="tab === 'dashboard'"
          :data="activity"
          :summary="dashSummary"
          :loaded="activityLoaded"
          :refreshing="refreshingActivity"
          @refresh="refreshActivity"
          @clear="clearActivity"
          @navigate="goTo"
        />
      </div>

      <aside class="ar__rail">
        <div class="ar-rail-card ar-rail-card--readiness">
          <p class="ar-rail-card__label">Readiness</p>
          <div class="ar-rail-readiness">
            <div class="ar-rail-gauge" role="img" :aria-label="`Readiness ${score.pct}%`">
              <svg viewBox="0 0 116 116">
                <circle class="ar-rail-gauge__track" cx="58" cy="58" r="52" />
                <circle
                  class="ar-rail-gauge__fill"
                  cx="58"
                  cy="58"
                  r="52"
                  :data-tone="tone"
                  :stroke-dasharray="circumference"
                  :stroke-dashoffset="dashOffset"
                />
              </svg>
              <span class="ar-rail-gauge__num">{{ score.pct }}<small>%</small></span>
            </div>
            <div class="ar-rail-readiness__meta">
              <div class="ar-rail-status" :data-tone="tone">
                <strong>{{ score.pass }}/{{ score.total }}</strong>
                <span>pass</span>
              </div>
              <button v-if="issues" type="button" class="ar-rail-link" @click="tab = 'readiness'">
                {{ issues }} to review →
              </button>
              <p v-else class="ar-rail-allgood">All checks pass.</p>
            </div>
          </div>
        </div>

        <div class="ar-rail-card">
          <p class="ar-rail-card__label">Live endpoints</p>
          <ul class="ar-rail-links">
            <li><a :href="endpoints.llms" target="_blank" rel="noopener">llms.txt</a></li>
            <li><a :href="endpoints.llmsFull" target="_blank" rel="noopener">llms-full.txt</a></li>
            <li><a :href="endpoints.robots" target="_blank" rel="noopener">robots.txt</a></li>
          </ul>
        </div>

        <div v-if="discoveryDocs.length" class="ar-rail-card">
          <p class="ar-rail-card__label">Discovery docs</p>
          <ul class="ar-rail-links">
            <li v-for="d in discoveryDocs" :key="d.label">
              <a :href="d.url" target="_blank" rel="noopener">{{ d.label }}</a>
            </li>
          </ul>
        </div>

        <div v-if="tab === 'settings'" class="ar-rail-save" :class="`is-${autoStatus}`">
          <span class="ar-rail-save__dot" aria-hidden="true"></span>
          <span class="ar-rail-save__label">
            {{ autoStatus === 'saving' ? 'Saving…' : autoStatus === 'error' ? 'Save failed' : autoStatus === 'saved' ? 'Saved' : 'Auto-save on' }}
          </span>
        </div>
      </aside>
    </main>
  </div>
</template>
