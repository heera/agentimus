<script>
import { createApi } from './api.js';
import { summarize } from './tiers.js';
import SettingsForm from './components/SettingsForm.vue';
import ReadinessPanel from './components/ReadinessPanel.vue';
import DiscoveryHub from './components/DiscoveryHub.vue';
import ActivityPanel from './components/ActivityPanel.vue';
import ReviewMenu from './components/ReviewMenu.vue';
import OnboardingWizard from './components/OnboardingWizard.vue';
import AboutPanel from './components/AboutPanel.vue';
import ConfirmDialog from './components/ConfirmDialog.vue';

export default {
  name: 'AgentimusApp',
  components: { SettingsForm, ReadinessPanel, DiscoveryHub, ActivityPanel, ReviewMenu, OnboardingWizard, AboutPanel, ConfirmDialog },
  props: {
    boot: { type: Object, required: true },
  },
  data() {
    const fromHash = (window.location.hash || '').replace(/^#/, '');
    return {
      api: createApi(this.boot),
      // Restore the tab from the URL hash so a refresh keeps the same page.
      tab: ['dashboard', 'settings', 'readiness', 'discovery', 'about'].includes(fromHash) ? fromHash : 'dashboard',
      settings: JSON.parse(JSON.stringify(this.boot.settings || {})),
      defaults: this.boot.defaults || {},
      readiness: this.boot.readiness || [],
      refreshingReadiness: false,
      discovery: this.boot.discovery || {},
      refreshingDiscovery: false,
      activity: {},
      activityLoaded: false,
      refreshingActivity: false,
      blockingNow: null,
      allowingNow: null,
      entityTypes: this.boot.entityTypes || ['Person', 'Organization'],
      postTypes: this.boot.postTypes || [],
      knownTrainers: this.boot.knownTrainers || [],
      knownScanners: this.boot.knownScanners || [],
      webmcpTools: this.boot.webmcpTools || [],
      restNamespacesDetected: this.boot.restNamespacesDetected || [],
      endpoints: this.boot.endpoints || {},
      llmsFullEstimate: this.boot.llmsFullEstimate || {},
      version: this.boot.version || '',
      protocol: this.boot.protocol || {},
      saving: false,
      resetting: false,
      onboarded: !!this.boot.onboarded,
      showWizard: false,
      wizardCelebrate: false,
      onboarding: false,
      profileSaving: false,
      profileSaved: false,
      servicesSaving: false,
      servicesSaved: false,
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
    // The Findable → Readable → Trusted ladder: which rung you've reached and the
    // single next step, shown as the rail headline (see tiers.js).
    ladder() {
      return summarize(this.readiness);
    },
    // DOM id of the single next check the rail's next-step button jumps to.
    nextAnchor() {
      const r = this.ladder.next && this.ladder.next.remaining[0];
      return r ? `ar-check-${r.id}` : null;
    },
    // What the browser-side live self-check needs to probe the real endpoints.
    liveConfig() {
      return {
        endpoints: this.endpoints,
        discovery: this.discovery,
        settings: this.settings,
        samplePost: this.boot.samplePost || '',
      };
    },
    dirty() {
      return JSON.stringify(this.settings) !== this.savedSnapshot;
    },
    // The free-text "profile" block (entity/name/role/about/email) saves explicitly
    // via the Identity card's Save button; everything else autosaves. profileDirty
    // drives that button.
    profileDirty() {
      const saved = JSON.parse(this.savedSnapshot).identity || {};
      const cur = this.settings.identity || {};
      return ['entity_type', 'name', 'role', 'about', 'not_description', 'audience', 'contact_email'].some(
        (k) => (cur[k] || '') !== (saved[k] || ''),
      );
    },
    // Services have their OWN card and Save button (not autosaved, not part of the
    // profile block). Compare only meaningful (named) rows so a blank scaffolding
    // row never marks the card dirty — it isn't saved anyway.
    servicesDirty() {
      const saved = JSON.parse(this.savedSnapshot).identity || {};
      const cur = this.settings.identity || {};
      const named = (arr) => JSON.stringify(
        (Array.isArray(arr) ? arr : [])
          .filter((s) => s && (s.name || '').trim())
          .map((s) => ({ name: (s.name || '').trim(), description: (s.description || '').trim(), url: (s.url || '').trim() })),
      );
      return named(cur.services) !== named(saved.services);
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
        enable_signing: s.enable_signing, enable_webmcp: s.enable_webmcp, webmcp_hidden_tools: s.webmcp_hidden_tools,
        llms_full_posts: s.llms_full_posts, post_types: s.post_types,
        rest_namespaces: s.rest_namespaces, oauth_auth_server: s.oauth_auth_server, content_signal: s.content_signal,
        blocked_trainers: s.blocked_trainers, suppressed_resources: s.suppressed_resources,
        enable_ai_header: s.enable_ai_header, enable_tdmrep: s.enable_tdmrep,
        ai_noai_header: s.ai_noai_header, tdm_policy_url: s.tdm_policy_url,
        block_agents: s.block_agents, block_spoofed: s.block_spoofed, blocked_agents: s.blocked_agents, allowed_agents: s.allowed_agents,
        security: s.security,
        expertise: id.expertise, same_as: id.same_as,
        // NB: services are NOT here — they save explicitly via the Identity card's
        // Save button (see profileDirty/saveProfile), not on every keystroke.
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
        { id: 'about', label: 'About' },
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
            description: 'Configure the signals Agentimus exposes and the identity agents read.',
          },
          readiness: {
            title: 'Readiness',
            description: 'How machine-legible your site is right now — a checklist of pass, warn and fail checks.',
          },
          discovery: {
            title: 'Discovery',
            description: 'The single document agents read to understand this site — every registered plugin aggregated into one place.',
          },
          about: {
            title: 'About',
            description: 'Everything Agentimus does, what each feature publishes, and exactly what it touches — a plain-English, honest account.',
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
        { label: 'mcp/server-card.json', url: e.mcpServerCard },
      ].filter((d) => d.url);
    },
    // Compact validation status for the rail: green when nothing is wrong,
    // otherwise toned by the worst notice level (error → bad, else warn).
    validation() {
      const notices = (this.discovery && this.discovery.notices) || [];
      if (!notices.length) return { ok: true, tone: 'good', count: 0 };
      const tone = notices.some((n) => n.level === 'error') ? 'bad' : 'warn';
      return { ok: false, tone, count: notices.length };
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
    // Load activity eagerly (not only on the Dashboard): the nav "to review"
    // badge needs the threat data on every tab.
    this.refreshActivity();
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
        if (!el) return;
        // Reveal the target if it's tucked inside one or more collapsed <details>.
        for (let d = el.closest('details'); d; d = d.parentElement && d.parentElement.closest('details')) {
          d.open = true;
        }
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        this.flashTarget(el);
      });
    },
    // Briefly ring a jumped-to element so the eye lands on the exact control to
    // change, and focus it when it's a form field (ready to act). The ring is CSS;
    // reduced-motion users get a static outline instead (see app.css).
    flashTarget(el) {
      el.classList.remove('ar-jump-flash');
      void el.offsetWidth; // restart the animation when re-jumping the same target
      el.classList.add('ar-jump-flash');
      clearTimeout(this._jumpFlash);
      this._jumpFlash = setTimeout(() => el.classList.remove('ar-jump-flash'), 1500);
      const field = el.matches('input, select, textarea')
        ? el
        : el.querySelector('input, select, textarea');
      if (field) {
        try { field.focus({ preventScroll: true }); } catch (e) { field.focus(); }
      }
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
        ['entity_type', 'name', 'role', 'about', 'not_description', 'audience', 'contact_email'].forEach((k) => {
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
    // Persist the services list (its own card + Save button). Freezes the in-progress
    // profile draft to its saved values — the profile owns its own Save — then adopts
    // the stored services back so blank rows the sanitiser dropped disappear.
    async saveServices() {
      if (this.servicesSaving || !this.servicesDirty) {
        return;
      }
      this.servicesSaving = true;
      try {
        const savedId = (JSON.parse(this.savedSnapshot).identity) || {};
        const payload = JSON.parse(JSON.stringify(this.settings));
        ['entity_type', 'name', 'role', 'about', 'not_description', 'audience', 'contact_email'].forEach((k) => {
          if (payload.identity) payload.identity[k] = savedId[k];
        });
        const res = await this.api.saveSettings(payload);
        const storedServices = (res.settings && res.settings.identity && Array.isArray(res.settings.identity.services))
          ? res.settings.identity.services : [];
        if (this.settings.identity) this.settings.identity.services = storedServices;
        this.savedSnapshot = JSON.stringify(res.settings);
        this.readiness = res.readiness || this.readiness;
        this.servicesSaved = true;
        clearTimeout(this._svcSavedTimer);
        this._svcSavedTimer = setTimeout(() => { this.servicesSaved = false; }, 2500);
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.servicesSaving = false;
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
      const firstRun = !this.onboarded; // decided before we flip the flag below
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
        if (firstRun) {
          // First-time setup earns a moment: keep the modal open and let the
          // wizard switch to its celebration view. Reviews just save quietly.
          this.onboarded = true;
          this.wizardCelebrate = true;
        } else {
          this.onboarded = true;
          this.showWizard = false;
          this.flash('success', 'Changes saved.');
        }
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.onboarding = false;
      }
    },
    // Dismiss the celebration (the wizard's "done" view) and close the modal.
    closeWizard() {
      this.showWizard = false;
      this.wizardCelebrate = false;
    },
    skipWizard() {
      // Skipping must feel instant: close now and persist the "onboarded" flag
      // in the background. There's no entered data to lose if the write is slow,
      // and awaiting the round-trip made the click look dead (it just dimmed).
      // If the write fails the modal simply reappears next load — self-correcting.
      this.showWizard = false;
      this.onboarded = true;
      this.api.completeOnboarding().catch((e) => this.flash('error', e.message));
    },
    // "Run setup again" from Settings — reopen over the current settings (the
    // child re-seeds itself from them on open). Does not clear the onboarded flag.
    reopenWizard() {
      this.wizardCelebrate = false; // a review never shows the first-run celebration
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
      ['entity_type', 'name', 'role', 'about', 'not_description', 'audience', 'contact_email'].forEach((k) => {
        if (payload.identity) payload.identity[k] = savedId[k];
      });
      this.autoStatus = 'saving';
      try {
        const res = await this.api.saveSettings(payload);
        this.savedSnapshot = JSON.stringify(res.settings);
        this.readiness = res.readiness || this.readiness;
        this.autoStatus = 'saved';
        // If the save changed the blocking rules, the dashboard's "blocked" flags
        // are now stale (e.g. a denylist entry was removed) — re-fetch so those
        // rows reappear as actionable without a manual refresh.
        if (this.activityLoaded && this.blockingKeyOf(res.settings) !== this._activityBlockKey) {
          this.refreshActivity();
        }
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
        this._activityBlockKey = this.blockingKeyOf(this.settings);
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
    async blockAgent(payload) {
      this.blockingNow = payload; // drives the per-row "Blocking…" state.
      try {
        // Returns { activity, settings }: refreshed stats (the row drops out of the
        // "to review" list) plus the updated settings so the Settings denylist /
        // toggles stay in sync without a reload.
        const res = await this.api.blockAgent(payload);
        this.activity = res.activity || res;
        if (res.settings) this.syncBlockSettings(res.settings);
        this._activityBlockKey = this.blockingKeyOf(this.settings);
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.blockingNow = null;
      }
    },
    // "Allow" / trust a flagged client — same shape as blockAgent; the endpoint adds
    // the derived token to the allowlist and returns refreshed stats + settings.
    async allowAgent(payload) {
      this.allowingNow = payload;
      try {
        const res = await this.api.allowAgent(payload);
        this.activity = res.activity || res;
        if (res.settings) this.syncBlockSettings(res.settings);
        this._activityBlockKey = this.blockingKeyOf(this.settings);
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.allowingNow = null;
      }
    },
    // A fingerprint of the block/allow-relevant settings, to detect when the
    // dashboard's flags have gone stale after a settings change.
    blockingKeyOf(s) {
      s = s || {};
      return JSON.stringify([
        !!s.block_agents, !!s.block_spoofed,
        (s.blocked_agents || []).slice().sort(),
        (s.allowed_agents || []).slice().sort(),
      ]);
    },
    // Reflect a Dashboard block into the live Settings state + saved snapshot, so the
    // Settings tab shows the new denylist entry / armed toggle immediately — without
    // overwriting an unsaved profile draft or tripping the autosave.
    syncBlockSettings(saved) {
      if (!saved) return;
      this._skipAutosave = true; // instantState includes these fields; don't re-save.
      this.settings.blocked_agents = Array.isArray(saved.blocked_agents)
        ? saved.blocked_agents.slice()
        : (this.settings.blocked_agents || []);
      this.settings.allowed_agents = Array.isArray(saved.allowed_agents)
        ? saved.allowed_agents.slice()
        : (this.settings.allowed_agents || []);
      this.settings.block_agents = !!saved.block_agents;
      this.settings.block_spoofed = !!saved.block_spoofed;
      this.$nextTick(() => { this._skipAutosave = false; });
      // Keep only these fields in the saved snapshot in step (so they don't read as
      // unsaved); an unsaved profile draft in identity is left exactly as it was.
      try {
        const snap = JSON.parse(this.savedSnapshot);
        snap.blocked_agents = this.settings.blocked_agents;
        snap.allowed_agents = this.settings.allowed_agents;
        snap.block_agents = this.settings.block_agents;
        snap.block_spoofed = this.settings.block_spoofed;
        this.savedSnapshot = JSON.stringify(snap);
      } catch (e) {
        /* leave the snapshot as-is */
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
      <button type="button" class="ar__brand" aria-label="Agentimus — reload" @click="reloadPlugin">
        <span class="ar__mark" aria-hidden="true">
          <svg class="ar__logo" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path class="ar__logo-line" d="M4.5 20.5 L12 3.5 L19.5 20.5" />
            <path class="ar__logo-accent" d="M8 13.6 H16" />
          </svg>
        </span>
        <span class="ar__brandtext">
          <span class="ar__name">Agentimus</span>
          <span v-if="version" class="ar__ver">Version - {{ version }}</span>
        </span>
      </button>

      <span class="ar__sep" aria-hidden="true">
        <svg viewBox="0 0 14 44" width="14" height="44" fill="none">
          <path class="ar__sep-chev" d="M3 11 L9 22 L3 33" />
          <circle class="ar__sep-ring" cx="9" cy="22" r="4.2" />
          <circle class="ar__sep-node" cx="9" cy="22" r="2.4" />
        </svg>
      </span>

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

      <ReviewMenu
        :threats="(activity && activity.threats) || {}"
        :enabled="!!(activity && activity.enabled)"
        :blocking="blockingNow"
        :allowing="allowingNow"
        @block="blockAgent"
        @allow="allowAgent"
        @navigate="goTo"
      />
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

    <!-- One app-wide styled confirmation prompt (replaces window.confirm). -->
    <ConfirmDialog />

    <OnboardingWizard
      :open="showWizard"
      :settings="settings"
      :entity-types="entityTypes"
      :post-types="postTypes"
      :saving="onboarding"
      :returning="onboarded"
      :celebrate="wizardCelebrate"
      @finish="finishWizard"
      @skip="skipWizard"
      @done="closeWizard"
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
          :known-scanners="knownScanners"
          :webmcp-tools="webmcpTools"
          :endpoints="endpoints"
          :rest-namespaces-detected="restNamespacesDetected"
          :provider-resources="providerResources"
          :profile-dirty="profileDirty"
          :profile-saving="profileSaving"
          :profile-saved="profileSaved"
          :services-dirty="servicesDirty"
          :services-saving="servicesSaving"
          :services-saved="servicesSaved"
          :resetting="resetting"
          :defaults="defaults"
          :llms-full-estimate="llmsFullEstimate"
          @save-profile="saveProfile"
          @save-services="saveServices"
          @reset="resetSettings"
          @reopen-wizard="reopenWizard"
        />
        <ReadinessPanel
          v-show="tab === 'readiness'"
          :checks="readiness"
          :refreshing="refreshingReadiness"
          :live-config="liveConfig"
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
          :api="api"
          @refresh="refreshActivity"
          @clear="clearActivity"
          @navigate="goTo"
        />
        <AboutPanel
          v-show="tab === 'about'"
          :version="version"
          :protocol="protocol"
          @navigate="goTo"
        />
      </div>

      <aside class="ar__rail">
        <div class="ar-rail-card ar-rail-card--readiness">
          <p class="ar-rail-card__label">Readiness</p>
          <button
            type="button"
            class="ar-rail-readiness ar-rail-readiness--link"
            title="Open the full readiness report"
            @click="goTo('readiness')"
          >
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
            <div
              class="ar-rail-tier"
              :data-state="ladder.floor ? 'floor' : ladder.topped ? 'top' : ladder.achieved ? 'climb' : 'start'"
            >
              <strong class="ar-rail-tier__name">{{
                ladder.floor ? 'Not reachable'
                : ladder.achieved ? ladder.achieved.label
                : 'Getting started'
              }}</strong>
              <span class="ar-rail-tier__sub">{{
                ladder.floor ? 'agents can’t read the site'
                : ladder.topped ? 'fully agent-ready'
                : ladder.achieved ? 'rung reached'
                : 'first rung in progress'
              }}</span>
            </div>
          </button>

          <!-- Each rung is a quiet stat row that jumps to its group in the
               Readiness tab — the report is where the per-check detail lives. -->
          <ol class="ar-rungs">
            <li v-for="r in ladder.rungs" :key="r.key" class="ar-rung" :data-state="r.state">
              <button
                type="button"
                class="ar-rung__btn"
                :title="`View ${r.label} checks in the readiness report`"
                @click="goTo({ tab: 'readiness', anchor: `ar-group-${r.key}` })"
              >
                <span class="ar-rung__tick" aria-hidden="true"></span>
                <span class="ar-rung__name">{{ r.label }}</span>
                <span class="ar-rung__count">{{ r.pass }}/{{ r.total }}</span>
              </button>
            </li>
          </ol>

          <button
            v-if="ladder.floor"
            type="button"
            class="ar-rail-link ar-rail-next"
            @click="goTo({ tab: 'readiness', anchor: nextAnchor })"
          >Make the site public →</button>
          <button
            v-else-if="ladder.next && ladder.next.remaining.length"
            type="button"
            class="ar-rail-link ar-rail-next"
            :title="`Next: ${ladder.next.remaining[0].label}`"
            @click="goTo({ tab: 'readiness', anchor: nextAnchor })"
          >Next: {{ ladder.next.remaining[0].label }} →</button>
          <p v-else class="ar-rail-allgood">All rungs complete.</p>
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

        <div class="ar-rail-card ar-rail-card--validation" :class="`is-${validation.tone}`">
          <p class="ar-rail-card__label">Registration status</p>
          <button
            v-if="validation.ok"
            type="button"
            class="ar-rail-valid"
            title="See what’s registered"
            @click="goTo({ tab: 'discovery', anchor: 'ar-wd-providers' })"
          >
            <span class="ar-rail-valid__check" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7" /></svg>
            </span>
            <span class="ar-rail-valid__text">All registrations are valid</span>
            <span class="ar-rail-valid__go" aria-hidden="true">→</span>
          </button>
          <button
            v-else
            type="button"
            class="ar-rail-link ar-rail-valid__alert"
            @click="goTo({ tab: 'discovery', anchor: 'ar-wd-validation' })"
          >
            {{ validation.count }} {{ validation.count === 1 ? 'issue' : 'issues' }} to fix — Review →
          </button>
        </div>

        <div
          v-if="tab === 'settings' && autoStatus !== 'idle'"
          class="ar-rail-save"
          :class="`is-${autoStatus}`"
          role="status"
          aria-live="polite"
        >
          <span class="ar-rail-save__dot" aria-hidden="true"></span>
          <span class="ar-rail-save__label">
            {{ autoStatus === 'saving' ? 'Saving…' : autoStatus === 'error' ? 'Save failed' : 'Saved' }}
          </span>
        </div>

        <p class="ar-rail-foot" aria-label="Made with love by Sheikh Heera">Made with <span class="ar-rail-foot__heart" aria-hidden="true">♥</span> by <a class="ar-rail-foot__link" href="https://heera.it" target="_blank" rel="noopener">Sheikh Heera</a></p>
      </aside>
    </main>
  </div>
</template>
