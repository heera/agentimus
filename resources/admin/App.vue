<script>
import { createApi } from './api.js';
import { summarize } from './tiers.js';
import { uaTip } from './uaTip.js';
import SettingsForm from './components/SettingsForm.vue';
import ReadinessPanel from './components/ReadinessPanel.vue';
import DiscoveryHub from './components/DiscoveryHub.vue';
import ActivityPanel from './components/ActivityPanel.vue';
import AiTrafficPanel from './components/AiTrafficPanel.vue';
import RequestLog from './components/RequestLog.vue';
import AgentAccess from './components/AgentAccess.vue';
import ReviewMenu from './components/ReviewMenu.vue';
import OnboardingWizard from './components/OnboardingWizard.vue';
import AboutPanel from './components/AboutPanel.vue';
import ConfirmDialog from './components/ConfirmDialog.vue';
import VisibilityPanel from './components/VisibilityPanel.vue';

// Live updates: poll the same /activity endpoint the Refresh button uses, on a
// gentle interval. Polling (not SSE/WebSockets) on purpose — it works on any
// shared host without holding a PHP-FPM worker open per admin tab.
const ACTIVITY_POLL_MS = 15000;
// Per-admin viewing preference (their own browser), not a site setting — it only
// governs how often this screen re-fetches, never what the site exposes.
const LIVE_PREF_KEY = 'agentimus:liveUpdates';
// Suspend polling after this long with no interaction — even on a focused tab —
// the way WordPress Heartbeat backs off an idle admin. Resumes on the next move.
const ACTIVITY_IDLE_MS = 5 * 60 * 1000;
// Cheap "is the human still here" signals. Kept in one place so add/remove agree.
const ACTIVITY_EVENTS = ['mousemove', 'keydown', 'wheel', 'pointerdown', 'touchstart'];
// The More menu's resting offset from its trigger: a slight tuck left, so the panel's
// padding lines its labels up under the trigger's text rather than its box.
const MORE_TUCK = -6;
// ...and the breathing room it keeps from the window edge when it has to shift inward.
const MORE_EDGE_GAP = 12;

export default {
  name: 'AgentimusApp',
  components: { SettingsForm, ReadinessPanel, DiscoveryHub, ActivityPanel, AiTrafficPanel, RequestLog, AgentAccess, ReviewMenu, OnboardingWizard, AboutPanel, ConfirmDialog, VisibilityPanel },
  // The styled hover bubble (shared with the activity tables) — the score rail's
  // rung and next-step hints use it instead of slow, unthemeable native titles.
  mixins: [uaTip],
  props: {
    boot: { type: Object, required: true },
  },
  data() {
    const fromHash = (window.location.hash || '').replace(/^#/, '');
    // AI Visibility is opt-in: a #visibility hash falls back to the dashboard when the
    // feature is off (the tab isn't shown, so there'd be nowhere to navigate from).
    const visOn = !!(this.boot.settings && this.boot.settings.enable_visibility);
    // Same story for the Request log and AI traffic: with the activity log switched off
    // there is nothing to read, so both tabs are hidden and their hashes have nowhere to
    // land. This list is what a COLD load validates against — `tabs()` only governs a
    // hashchange — so a screen missing from it silently boots on the dashboard instead.
    const actOn = !!(this.boot.settings && this.boot.settings.enable_activity);
    const activityTabs = ['log', 'ai-traffic'];
    // 'agent-access' is unconditional here, unlike the activity/visibility tabs above: it has
    // no setting to hide it, and it is always mounted, so its hash always has somewhere to land.
    let startTab = ['dashboard', ...activityTabs, 'agent-access', 'visibility', 'settings', 'readiness', 'discovery', 'about'].includes(fromHash) ? fromHash : 'dashboard';
    if ('visibility' === startTab && !visOn) startTab = 'dashboard';
    if (activityTabs.includes(startTab) && !actOn) startTab = 'dashboard';
    return {
      api: createApi(this.boot),
      // Header lifts with a shadow once the page is scrolled; flush at the very top.
      scrolled: false,
      // Restore the tab from the URL hash so a refresh keeps the same page.
      tab: startTab,
      settings: JSON.parse(JSON.stringify(this.boot.settings || {})),
      defaults: this.boot.defaults || {},
      readiness: this.boot.readiness || [],
      aeo: this.boot.score || null,
      refreshingReadiness: false,
      discovery: this.boot.discovery || {},
      refreshingDiscovery: false,
      activity: {},
      activityLoaded: false,
      refreshingActivity: false,
      // Unread agent-access events, for the nav badge. Owned here rather than in the panel
      // because the badge must be right on every screen, including before the panel is opened.
      agentAccessUnseen: 0,
      // Opt-in live updates (off by default). Remembered per browser.
      live: (() => {
        try { return window.localStorage.getItem(LIVE_PREF_KEY) === '1'; } catch (e) { return false; }
      })(),
      blockingNow: null,
      allowingNow: null,
      dismissingNow: null,
      reverifyingNow: null,
      reverifyResult: null, // { ua, status, verdict } — shown inline on the row, not as a toast.
      entityTypes: this.boot.entityTypes || ['Person', 'Organization'],
      // Retention + row-cap choices come from PHP (Settings::RETENTION_CHOICES /
      // MAX_ROWS_CHOICES), so the dropdowns can never offer a value sanitize() rejects.
      retentionChoices: this.boot.retentionChoices || [7, 14, 30, 60, 90, 180, 365],
      maxRowsChoices: this.boot.maxRowsChoices || [10000, 25000, 50000, 100000, 250000],
      categories: this.boot.categories || [],
      postTypes: this.boot.postTypes || [],
      knownTrainers: this.boot.knownTrainers || [],
      knownScanners: this.boot.knownScanners || [],
      knownAllowed: this.boot.knownAllowed || [],
      defaultAllowed: this.boot.defaultAllowed || [],
      webmcpTools: this.boot.webmcpTools || [],
      mcpServer: this.boot.mcpServer || {},
      debug: this.boot.debug || {},
      isLocal: !!this.boot.isLocal,
      restNamespacesDetected: this.boot.restNamespacesDetected || [],
      endpoints: this.boot.endpoints || {},
      // The exposed-files probe list. Seeded from boot but kept as reactive data (not
      // read from the frozen boot prop) so a settings save can refresh it in place —
      // a just-added custom path then scans without a page reload.
      exposedPaths: this.boot.exposedPaths || [],
      llmsFullEstimate: this.boot.llmsFullEstimate || {},
      version: this.boot.version || '',
      protocol: this.boot.protocol || {},
      saving: false,
      resetting: false,
      onboarded: !!this.boot.onboarded,
      // The "More" nav menu (Request log / AI Visibility / About).
      moreOpen: false,
      // Horizontal offset of the menu from its trigger, in px. Normally a slight tuck to
      // the left (MORE_TUCK); clamped by positionMore() when the trigger sits close enough
      // to the right edge that a left-anchored panel would run off it.
      moreShift: MORE_TUCK,
      showWizard: false,
      wizardCelebrate: false,
      onboarding: false,
      profileSaving: false,
      profileSaved: false,
      servicesSaving: false,
      servicesSaved: false,
      savingSettings: false,
      notices: [],
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
        exposedPaths: this.exposedPaths,
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
    // A signature of every AUTOSAVED field. When it changes we debounce-autosave.
    // Derived (the WHOLE settings object minus the fields that save via the Identity
    // card's own Save button — the profile text block and services) rather than an
    // enumerated allow-list, so a NEW toggle/field autosaves automatically. There is no
    // list to keep in sync, so a new switch can never be silently forgotten. This
    // deliberately mirrors exactly what autosaveInstant() sends.
    instantState() {
      const s = JSON.parse(JSON.stringify(this.settings || {}));
      const id = s.identity || {};
      // These are saved on the Identity card, not on every change, so they must NOT
      // trigger autosave (kept identical to autosaveInstant's exclusion list + services).
      ['entity_type', 'name', 'role', 'about', 'not_description', 'audience', 'contact_email', 'services'].forEach(
        (k) => { delete id[k]; },
      );
      s.identity = id;
      return JSON.stringify(s);
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
    // The rail gauge now shows the composite AEO/GEO score {@see \Agentimus\Score}.
    aeoTone() {
      if (!this.aeo || this.aeo.blocked) return 'low';
      return this.aeo.score >= 70 ? 'good' : this.aeo.score >= 50 ? 'ok' : 'low';
    },
    aeoDashOffset() {
      const pct = this.aeo && !this.aeo.blocked ? this.aeo.score : 0;
      return this.ringReady ? this.circumference * (1 - pct / 100) : this.circumference;
    },
    // The single most impactful next step, shown as the rail's next-step line.
    aeoNext() {
      return this.aeo && this.aeo.actions && this.aeo.actions.length ? this.aeo.actions[0] : null;
    },
    // Its hover hint: WHY this is the next thing — the complaint in plain words
    // (the action's own `why`), with the click destination as the second line.
    aeoNextTip() {
      return this.aeoNext ? (this.aeoNext.why || this.aeoNext.title || '') : '';
    },
    aeoNextTipHint() {
      const a = this.aeoNext && this.aeoNext.action;
      if (!a) return '';
      // href actions open a new tab (see openNext) — say so; in-app jumps keep
      // the action's own label ("Open AI Visibility").
      return a.href ? 'Open in a new tab →' : (a.label ? `${a.label} →` : '');
    },
    // The per-page content worklist behind the Optimized rung (issue → affected pages).
    optimizeWork() {
      return (this.aeo && this.aeo.content) || [];
    },
    // Total page-fixes across it (a page with two issues is two fixes) — the
    // rung row's "N to fix" chip, since the Next line only names the top issue.
    optimizeTotal() {
      return this.optimizeWork.reduce((n, i) => n + Number(i.count || 0), 0);
    },
    // Pages the owner set aside as "not cited content" (excluded from grading).
    optimizeIgnored() {
      return (this.aeo && this.aeo.ignored) || [];
    },
    // The Optimized rung opens its section when there's anything to act on — issues to
    // fix, or set-aside pages to review/restore.
    optimizeActionable() {
      return this.optimizeWork.length > 0 || this.optimizeIgnored.length > 0;
    },
    host() {
      const url = this.endpoints.robots || this.endpoints.llms || '';
      try {
        return new URL(url).host;
      } catch (e) {
        return '';
      }
    },
    // The four screens you work in day to day. Kept in the bar itself so the nav stays
    // short enough to read on a narrow admin, where it would otherwise scroll sideways.
    primaryTabs() {
      return [
        { id: 'dashboard', label: 'Dashboard' },
        { id: 'settings', label: 'Settings' },
        { id: 'readiness', label: 'Readiness' },
        { id: 'discovery', label: 'Discovery' },
      ];
    },
    // The occasional ones, behind "More".
    //
    // AI Visibility is always LISTED, even when citation tracking is off — showing it greyed
    // out tells an owner the feature exists and where to turn it on, where hiding it just
    // leaves a hole they never learn about. The request log is different: it's on by default
    // and switching recording off is a deliberate act, so it simply goes.
    moreTabs() {
      return [
        {
          id: 'visibility',
          label: 'AI Visibility',
          disabled: !this.settings.enable_visibility,
          note: 'Turn on in Settings',
        },
        // Two screens, one switch — but they are NOT the same view of the same thing.
        // The request log is the bot side (one row per fetch, with a clock time); AI
        // traffic is the human side (day totals, no clock time). Keeping them apart is
        // what keeps "agents taking" and "AI giving back" legible in the nav.
        ...(this.settings.enable_activity
          ? [{ id: 'ai-traffic', label: 'AI Traffic' }, { id: 'log', label: 'Request Log' }]
          : []),
        // Agent access is the ACT side of the same story the two screens above tell about
        // READS, so it sits with them. Always listed (never hidden behind its own setting):
        // the screen's whole job is to be honest about what it can and cannot see, and a
        // missing nav item is the one thing it could never explain.
        {
          id: 'agent-access',
          label: 'Agent Access',
          badge: this.agentAccessUnseen,
        },
        // About is reference material, not a working screen — the rule sets it apart.
        { id: 'about', label: 'About Agentimus', divided: true },
      ];
    },
    // True when the screen you're on lives inside the menu, so "More" can carry the active
    // underline — otherwise nothing in the bar would show where you are.
    moreActive() {
      return this.moreTabs.some((t) => t.id === this.tab && !t.disabled);
    },
    // Every reachable screen — what syncTabFromHash() validates a #hash against. A disabled
    // item is listed but NOT navigable, so #visibility must not resolve while it's off.
    tabs() {
      return [...this.primaryTabs, ...this.moreTabs.filter((t) => !t.disabled)];
    },
    dashSummary() {
      const c = (this.discovery && this.discovery.counts) || {};
      // Same reconciliation as the Discovery hub's tiles: the big number is the
      // registered inventory (matching the provider list the tile links to), and
      // the public/sign-in split rides along for the subline.
      return {
        readiness: this.score,
        tone: this.tone,
        providers: typeof c.resourcesRegistered === 'number' ? c.resourcesRegistered : c.resources || 0,
        providersPublic: typeof c.resourcesRegistered === 'number' ? c.resources || 0 : null,
        capabilities: c.capabilities || 0,
        tools: c.tools || 0,
        toolsPublic: typeof c.toolsPublished === 'number' ? c.toolsPublished : null,
      };
    },
    pageMeta() {
      return (
        {
          dashboard: {
            title: 'Dashboard',
            description: 'An overview of your agent-readiness — what you expose, and who is reading it.',
          },
          'ai-traffic': {
            title: 'AI Traffic',
            description: 'Readers an AI assistant sent you — day by day, by source and landing page.',
          },
          log: {
            title: 'Request Log',
            description: 'Every request an agent made — filter by client and endpoint to see exactly what one bot fetched.',
          },
          'agent-access': {
            title: 'Agent Access',
            description: 'What agents did on your site — keys created and used, abilities run. A record, not a guard.',
          },
          visibility: {
            title: 'AI Visibility',
            description: 'Track whether ChatGPT, Perplexity, Gemini and Claude mention and cite your site — over time.',
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
            title: 'About Agentimus',
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
  },
  watch: {
    // Turning citation tracking off while viewing AI Visibility → leave the now-hidden tab.
    'settings.enable_visibility'(on) {
      if (!on && this.tab === 'visibility') this.goTo('dashboard');
    },
    // Same for the request log and AI traffic: both panels are v-if'd on this setting, so
    // switching recording off while viewing one would unmount it and leave the screen blank.
    'settings.enable_activity'(on) {
      if (!on && (this.tab === 'log' || this.tab === 'ai-traffic')) this.goTo('dashboard');
    },
    // Opening by click or keyboard should put you inside the menu: on the screen you're
    // already on, or the first one you can reach. Escape hands focus back to the trigger
    // (see onMoreKey). Opening by HOVER must not touch focus at all.
    moreOpen(open) {
      if (!open) {
        this._moreFocus = false;
        return;
      }
      // Measured after the panel exists but before the browser paints it, so it never
      // appears at the wrong offset and jumps.
      this.$nextTick(() => {
        this.positionMore();
        if (!this._moreFocus) return;
        const menu = this.$refs.moreMenu;
        if (!menu) return;
        const here = menu.querySelector('.ar__more-item.is-active:not(:disabled)');
        const first = menu.querySelector('.ar__more-item:not(:disabled)');
        if (here || first) (here || first).focus();
      });
    },
    tab(val) {
      // Never leave the More menu hanging open over a screen you've already left — this
      // also covers arriving via Back/Forward or a primary tab, not just picking an item.
      this.moreOpen = false;
      // Reflect the active tab in the URL hash, PUSHING a history entry so the
      // browser Back/Forward buttons step through the tabs. The guard skips the
      // push when the hash already matches — i.e. when the change itself came from
      // Back/Forward (hashchange → syncTabFromHash) — so there's no loop or dupe.
      if (window.location.hash.replace(/^#/, '') !== val) {
        window.history.pushState(null, '', `#${val}`);
      }
      // A fresh tab should start at the top, not inherit the previous page's scroll —
      // unless a jump-to-section is in flight (goTo owns that scroll for dashboard tiles).
      if (!this._jumpAnchor) {
        window.scrollTo(0, 0);
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
    // `mousedown`, not `click`: the trigger's own click would otherwise fire after this
    // handler had already closed the menu, and it would reopen on every second press.
    document.addEventListener('mousedown', this.onMoreDocDown);
    document.addEventListener('keydown', this.onMoreKey);
    document.addEventListener('visibilitychange', this.onTabVisible);
    window.requestAnimationFrame(() => {
      this.ringReady = true;
    });
    // Make sure the hash reflects the initial tab, and follow back/forward + manual edits.
    if (window.location.hash.replace(/^#/, '') !== this.tab) {
      window.history.replaceState(null, '', `#${this.tab}`);
    }
    window.addEventListener('hashchange', this.syncTabFromHash);
    // A window resized while the menu is open has to re-clamp, or it clips again.
    window.addEventListener('resize', this.onMoreResize);
    // Record the exact switch/card the user just changed so the save lock can scope
    // to it. We read it from the change event's target (capture phase) rather than
    // document.activeElement: a card's hidden checkbox doesn't reliably become the
    // focused element on click, so focus alone misses cards.
    this._onControlChange = (e) => {
      const c = (e.target && e.target.closest) ? e.target.closest('.ar-toggle, .ar-type') : null;
      if (c) this._lastChanged = c;
    };
    document.addEventListener('change', this._onControlChange, true);
    // Header lift: shadow the pinned header once the page scrolls, so content tucks
    // visibly beneath it instead of merging. rAF-throttled + passive so scroll stays smooth.
    this._onScroll = () => {
      if (this._scrollRaf) return;
      this._scrollRaf = window.requestAnimationFrame(() => {
        this._scrollRaf = 0;
        this.scrolled = window.scrollY > 40;
      });
    };
    window.addEventListener('scroll', this._onScroll, { passive: true });
    this._onScroll();
    // Load activity eagerly (not only on the Dashboard): the nav "to review"
    // badge needs the threat data on every tab.
    // refreshActivity() now also carries the Agent access unread count (see syncAgentAccessBadge),
    // so a newly minted application password announces itself in the nav wherever the owner
    // happens to be — on first load, and then live on every poll tick.
    this.refreshActivity();
    // Resume live updates if this admin left them on.
    if (this.live) this.startActivityPolling();
    // First run: greet a new admin with the setup wizard.
    if (!this.onboarded) {
      this.showWizard = true;
    }
  },
  beforeUnmount() {
    document.removeEventListener('mousedown', this.onMoreDocDown);
    document.removeEventListener('keydown', this.onMoreKey);
    document.removeEventListener('visibilitychange', this.onTabVisible);
    clearTimeout(this._moreOpenTimer);
    clearTimeout(this._moreCloseTimer);
    window.removeEventListener('resize', this.onMoreResize);
    window.removeEventListener('hashchange', this.syncTabFromHash);
    document.removeEventListener('change', this._onControlChange, true);
    window.removeEventListener('scroll', this._onScroll);
    if (this._scrollRaf) window.cancelAnimationFrame(this._scrollRaf);
    this.stopActivityPolling();
  },
  methods: {
    // Dashboard tiles emit { tab, anchor? }. Switch tab, then (once the now-shown
    // tab has laid out) scroll the target section into view so a click lands on
    // the relevant content, not just the top of the page.
    // ---- AEO/GEO rail card rungs -------------------------------------------
    // A rung's dot state: green when complete, amber when partway, muted when empty.
    // Check rungs complete at 100 (all checks pass); signal rungs at 70+.
    rungState(r) {
      if (r.score === null) return '';
      if ('check' === r.kind) return 100 === r.score ? 'done' : r.score >= 50 ? 'current' : '';
      return r.score >= 70 ? 'done' : r.score >= 50 ? 'current' : '';
    },
    // Every rung shows its 0–100 score on one consistent scale (they roll up to the
    // composite in the gauge). The per-check tally lives on the Readiness tab.
    rungCount(r) {
      // An unmeasured Cited explains itself in words, in the value slot itself
      // (right-aligned like every value) — a bare dash read as "broken", when
      // the truth is just "no reading yet".
      if (null === r.score) return 'cited' === r.key ? 'not measured yet' : '—';
      return `${r.score}%`;
    },
    rungTarget(r) {
      // Cited opens AI Visibility on the sub-view the score chose: Settings when setup
      // isn't complete enough to run a check, otherwise Results.
      return 'visibility' === r.to ? { tab: 'visibility', view: r.view || 'results' } : { tab: 'readiness', anchor: `ar-group-${r.key}` };
    },
    rungTitle(r) {
      return 'visibility' === r.to ? 'Open AI Visibility' : `View ${r.label} checks in the readiness report`;
    },
    // "N to fix" for a check-backed rung: its non-passing (warn or fail) checks.
    rungTodo(r) {
      return r && 'check' === r.kind ? Math.max(0, (r.total || 0) - (r.pass || 0)) : 0;
    },
    // The next-step line: follow the top action's own jump/link, or fall back to the
    // full report. An external-link action opens in a new tab.
    openNext() {
      // Only called when the top action has a real destination (config fix / measure);
      // content gaps are per-post and render as a non-clickable info line instead.
      const a = this.aeoNext && this.aeoNext.action;
      if (!a) return;
      this.hideUaTip(); // the href path never reaches goTo's own hide
      if (a.href) {
        window.open(a.href, '_blank', 'noopener');
        return;
      }
      this.goTo(a);
    },
    pickMore(t) {
      // `disabled` already blocks the click, but a keyboard "Enter" on an aria-disabled
      // element still fires in some browsers — refuse it here too.
      if (t.disabled) return;
      // The tab watcher closes the menu; setting it here too would be redundant. Suppress
      // hover until the pointer leaves, or the menu we just navigated away from springs
      // straight back open under the cursor.
      this._moreSuppress = true;
      this.tab = t.id;
    },

    /* ---- The More menu: opens on hover, on click, and on Enter ------------------ */

    /**
     * Hover-to-open is for a mouse only. On a touch screen the first tap would both open
     * the menu and count as hovering, so the menu would fight the tap that dismisses it;
     * there, click alone opens it.
     */
    canHoverOpen() {
      return !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
    },
    openMore(withFocus) {
      // Only a click or a keypress moves focus into the menu. A hover that stole focus
      // would yank the caret out of whatever the user was doing, just for passing by.
      this._moreFocus = withFocus;
      this.moreOpen = true;
    },
    /**
     * Keep the panel inside the window. It hangs off the LEFT of its trigger, and on a
     * narrow admin that trigger sits near the right edge — so the panel ran off the side,
     * where wp-admin's `overflow-x: hidden` on <body> cut it off rather than letting it
     * scroll into view. Slide it inward by as much as it takes, and no more, so it stays
     * under its trigger whenever there is room.
     *
     * Measured against documentElement.clientWidth, NEVER window.innerWidth. The panel
     * mounts at its default offset, and if that overflows, the document grows — and a
     * mobile browser widens the visual viewport to match, so `innerWidth` reports the
     * width the panel just created and we would clamp to the damage instead of preventing
     * it. On a 430px phone that read back as 508px and the menu stayed off-screen.
     * clientWidth is the layout viewport: overflow can't move it (and it excludes the
     * desktop scrollbar, which innerWidth wrongly counts as usable space).
     */
    positionMore() {
      const menu = this.$refs.moreMenu;
      const wrap = this.$refs.moreWrap;
      if (!menu || !wrap) return;
      const viewport = document.documentElement.clientWidth || window.innerWidth;
      const anchor = wrap.getBoundingClientRect().left;
      const room = viewport - MORE_EDGE_GAP - menu.offsetWidth;
      // Offsets are relative to the trigger, so convert both bounds into its coordinates.
      const rightmost = room - anchor;
      const leftmost = MORE_EDGE_GAP - anchor;
      // min() before max(): on a window too narrow for the panel at all, hugging the LEFT
      // edge keeps the labels readable, where hugging the right would hide them.
      this.moreShift = Math.round(Math.max(leftmost, Math.min(MORE_TUCK, rightmost)));
    },
    onMoreResize() {
      if (this.moreOpen) this.positionMore();
    },
    toggleMore() {
      clearTimeout(this._moreOpenTimer);
      clearTimeout(this._moreCloseTimer);
      if (this.moreOpen) {
        this.moreOpen = false;
        // The pointer is still on the trigger, and mouseenter won't fire again — but
        // without this a stray re-enter would reopen what the click just closed.
        this._moreSuppress = true;
      } else {
        this.openMore(true);
      }
    },
    onMoreEnter() {
      if (!this.canHoverOpen()) return;
      clearTimeout(this._moreCloseTimer);
      if (this.moreOpen || this._moreSuppress) return;
      // A short intent delay: brushing past "More" on the way to Discovery shouldn't
      // fling a menu open.
      this._moreOpenTimer = setTimeout(() => this.openMore(false), 90);
    },
    onMoreLeave() {
      clearTimeout(this._moreOpenTimer);
      this._moreSuppress = false;
      if (!this.moreOpen || !this.canHoverOpen()) return;
      // Forgive the corner-cut: leaving the panel diagonally, or overshooting it, gets a
      // grace period before it closes. A menu that vanishes mid-reach is worse than one
      // that lingers. A menu opened by click or keyboard stays until Escape or a click out.
      if (this._moreFocus) return;
      this._moreCloseTimer = setTimeout(() => {
        // Someone tabbed into the menu while the pointer wandered off. Don't close a menu
        // the keyboard is standing in.
        const menu = this.$refs.moreMenu;
        if (menu && menu.contains(document.activeElement)) return;
        this.moreOpen = false;
      }, 220);
    },
    /**
     * The 16px line-icon for a menu entry, drawn in the same stroke weight as the chevrons
     * and arrows used everywhere else. Each one says what the screen is ABOUT, not what it
     * looks like: an eye for "are you being seen", a rising line for arriving readers,
     * stacked rules for a log of requests.
     */
    moreIcon(id) {
      return {
        visibility: ['M1.6 8S4 3.9 8 3.9 14.4 8 14.4 8 12 12.1 8 12.1 1.6 8 1.6 8Z', 'M8 9.7a1.7 1.7 0 1 0 0-3.4 1.7 1.7 0 0 0 0 3.4Z'],
        'ai-traffic': ['M2 12.2 6.1 7.9l2.6 2.2 4.6-5.4', 'M10.1 4.4h3.5v3.4'],
        log: ['M3 4.2h10', 'M3 8h10', 'M3 11.8h6'],
        // A key: this screen is about the credentials that reach the machine surface.
        'agent-access': ['M9.9 6.1a2.6 2.6 0 1 0 3.7 3.7 2.6 2.6 0 0 0-3.7-3.7Z', 'M9.9 9.8 4 15.7', 'M6.4 13.2l1.6 1.6'],
        about: ['M8 14.2A6.2 6.2 0 1 0 8 1.8a6.2 6.2 0 0 0 0 12.4Z', 'M8 7.4v3.4', 'M8 5.2h.01'],
      }[id] || [];
    },
    /**
     * Arrow-key roving focus, which `role="menu"` promises and a bare list of buttons does
     * not deliver. Only enabled items take focus — a greyed-out entry is a signpost, not a
     * stop on the way down.
     */
    onMoreNav(e) {
      const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End'];
      if (!keys.includes(e.key)) return;
      e.preventDefault();
      const items = [...this.$refs.moreMenu.querySelectorAll('.ar__more-item:not(:disabled)')];
      if (!items.length) return;
      const at = items.indexOf(document.activeElement);
      const next = {
        ArrowDown: at < 0 ? 0 : (at + 1) % items.length,
        ArrowUp: at < 0 ? items.length - 1 : (at - 1 + items.length) % items.length,
        Home: 0,
        End: items.length - 1,
      }[e.key];
      items[next].focus();
    },
    // Scoped to the menu's own wrapper, not $el — $el is the whole app, so a click on any
    // tab would count as "inside" and the menu would never close.
    onMoreDocDown(e) {
      if (this.moreOpen && this.$refs.moreWrap && !this.$refs.moreWrap.contains(e.target)) {
        this.moreOpen = false;
      }
    },
    onMoreKey(e) {
      if (this.moreOpen && e.key === 'Escape') {
        this.moreOpen = false;
        const btn = this.$refs.moreWrap && this.$refs.moreWrap.querySelector('.ar__more-btn');
        if (btn) btn.focus();
      }
    },
    // The rail's tooltips position against the CARD, not the viewport: width
    // capped to the card's inside, and the BODY centred on it purely in CSS
    // (translateX(-50%) on the --rail variant) — no width measurement, so no
    // race with Vue's re-render can ever misplace it. The caret sits at the
    // bubble's centre, which is also the centre of these full-width rows.
    showRailTip(ev, text, hint = '') {
      if (!text) return;
      const rect = ev.currentTarget.getBoundingClientRect();
      const card = (ev.currentTarget.closest && ev.currentTarget.closest('.ar-rail-card')) || ev.currentTarget.parentElement;
      const c = card.getBoundingClientRect();
      const below = rect.top < 96;
      // The width cap rides the reactive state: an imperative tip.style.maxWidth
      // would be wiped the moment Vue re-patches the bubble's :style binding.
      const maxW = `${Math.max(160, Math.round(c.width - 24))}px`;
      this.uaTip = {
        show: true, text, hint, below, maxW,
        x: Math.round(c.left + c.width / 2),
        y: below ? rect.bottom + 8 : rect.top - 8,
        caret: 0, // unused by the --rail variant: its caret is CSS-centred
      };
    },
    // The review queue's footer link. The dialog teleports to <body>, so it opens
    // over whatever tab is active — no need to switch to Settings first.
    openClientManager() {
      if (this.$refs.settingsForm) this.$refs.settingsForm.openClientManager();
    },
    goTo(target) {
      // Navigation unmounts whatever the pointer was over — never strand its tooltip.
      this.hideUaTip();
      const { tab, anchor, view } = typeof target === 'string' ? { tab: target } : target || {};
      // Tell the tab watcher not to snap to the top: we're aiming at a section below.
      this._jumpAnchor = anchor || null;
      if (tab) this.tab = tab;
      // Deep-link into the AI Visibility panel's Settings/Results sub-view when asked.
      if ('visibility' === tab && view) {
        this.$nextTick(() => {
          if (this.$refs.visibilityPanel && this.$refs.visibilityPanel.openView) {
            this.$refs.visibilityPanel.openView(view);
          }
        });
      }
      if (!anchor) return;
      // The Settings form is now split into groups shown one at a time; a target
      // may live in a group that isn't active (and is therefore display:none, so
      // scrollIntoView would no-op). Ask the form to surface the right group
      // first — it's harmless for anchors on other tabs.
      if (this.$refs.settingsForm && this.$refs.settingsForm.revealAnchor) {
        this.$refs.settingsForm.revealAnchor(anchor);
      }
      this.$nextTick(() => {
        // The watcher has already read the flag by now; clear it so a later plain
        // tab switch still snaps to the top even if the anchor lookup below bails.
        this._jumpAnchor = null;
        const el = document.getElementById(anchor);
        if (!el) return;
        // Reveal the target if it's tucked inside one or more collapsed <details>.
        for (let d = el.closest('details'); d; d = d.parentElement && d.parentElement.closest('details')) {
          d.open = true;
        }
        // Land the target JUST BELOW the sticky header, not at the raw viewport top —
        // otherwise scrollIntoView({block:'start'}) tucks the section's own heading
        // under the pinned header (admin bar + the two header strips). Measure the
        // header's rendered bottom so it's right on desktop AND mobile (where the nav
        // wraps taller). The header sits at the page top whether stuck or not, so its
        // bottom is a stable offset.
        const sticky = document.querySelector('.ar__sticky');
        const gap = (sticky ? sticky.getBoundingClientRect().bottom : 0) + 12;
        const y = el.getBoundingClientRect().top + window.pageYOffset - gap;
        window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
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
      if (!h || h === this.tab) return;
      if (this.tabs.some((t) => t.id === h)) {
        this.tab = h;
        return;
      }
      // An unreachable hash — a typo, or #visibility while citation tracking is off. We stay
      // where we are, so put the address bar back in step: a URL that names a screen you
      // aren't looking at is worse than no deep link at all. replaceState, not pushState, or
      // Back would bounce straight into the bad hash again.
      window.history.replaceState(null, '', `#${this.tab}`);
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
        if (res.score) this.aeo = res.score; // Keep the rail card live with the save.
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
        if (res.score) this.aeo = res.score; // Keep the rail card live with the save.
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
        if (res.score) this.aeo = res.score; // Keep the rail card live with the save.
        if (Array.isArray(res.exposedPaths)) this.exposedPaths = res.exposedPaths;
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
        if (payload.content_signal) next.content_signal = payload.content_signal;
        const res = await this.api.saveSettings(next);
        this._skipAutosave = true;
        this.settings = JSON.parse(JSON.stringify(res.settings || {}));
        this.$nextTick(() => { this._skipAutosave = false; });
        this.savedSnapshot = JSON.stringify(res.settings || {});
        this.readiness = res.readiness || this.readiness;
        if (res.score) this.aeo = res.score; // Keep the rail card live with the save.
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
    // Autosave for toggles / selections / chips. A discrete control (a toggle or
    // card — the active element is not a text field) locks the form immediately so
    // it behaves like a button mid-action; a text field stays editable and just
    // debounces, so typing is never interrupted.
    queueAutosave() {
      const ae = (typeof document !== 'undefined') ? document.activeElement : null;
      const typing = !!(ae && ae.matches && ae.matches('input[type="text"], input[type="url"], input[type="number"], input[type="search"], textarea'));
      if (!typing) this.beginBusy(); // lock now for a toggle/card click
      clearTimeout(this._autoTimer);
      this._autoTimer = setTimeout(() => this.autosaveInstant(), typing ? 500 : 60);
    },
    // The busy lock scopes to the single switch/card the user just changed (not
    // the whole panel): we tag that control with `.is-busy` so it dims and ignores
    // clicks like a button mid-action, while its neighbours stay live. A floor of
    // ~360ms keeps a very fast local save from being an imperceptible flicker;
    // a fresh save before that floor cancels the pending release so the lock never
    // drops mid-flight.
    beginBusy() {
      clearTimeout(this._busyTimer);
      if (!this.savingSettings) {
        this.savingSettings = true;
        this._busyStart = Date.now();
      }
      if (!this._busyEls) this._busyEls = [];
      const ae = (typeof document !== 'undefined') ? document.activeElement : null;
      const ctrl = this._lastChanged
        || ((ae && ae.closest) ? ae.closest('.ar-toggle, .ar-type') : null);
      this._lastChanged = null; // consume it so a later text-field save can't reuse it
      if (ctrl && this._busyEls.indexOf(ctrl) === -1) {
        ctrl.setAttribute('data-busy', '');
        this._busyEls.push(ctrl);
      }
    },
    endBusy() {
      const elapsed = Date.now() - (this._busyStart || 0);
      clearTimeout(this._busyTimer);
      this._busyTimer = setTimeout(() => {
        this.savingSettings = false;
        (this._busyEls || []).forEach((el) => el.removeAttribute('data-busy'));
        this._busyEls = [];
      }, Math.max(0, 360 - elapsed));
    },
    // Persists everything EXCEPT the in-progress profile text (frozen to its
    // last-saved value), so composing a profile sentence is never saved until the
    // user clicks Save. On success it never replaces this.settings (that would wipe
    // the draft); on failure it rolls the autosaved fields back to the last good
    // state — keeping the identity draft — so a flipped switch returns to where it
    // was, and surfaces a proper error.
    async autosaveInstant() {
      const savedId = (JSON.parse(this.savedSnapshot).identity) || {};
      const payload = JSON.parse(JSON.stringify(this.settings));
      ['entity_type', 'name', 'role', 'about', 'not_description', 'audience', 'contact_email'].forEach((k) => {
        if (payload.identity) payload.identity[k] = savedId[k];
      });
      this.beginBusy(); // lock for the round-trip (covers the text-field path too)
      try {
        const res = await this.api.saveSettings(payload);
        this.savedSnapshot = JSON.stringify(res.settings);
        this.readiness = res.readiness || this.readiness;
        if (res.score) this.aeo = res.score; // Keep the rail card live with the save.
        // Adopt the server's freshly-expanded exposed-files list so a just-edited
        // custom path is scanned immediately, no reload (see data().exposedPaths).
        if (Array.isArray(res.exposedPaths)) this.exposedPaths = res.exposedPaths;
        this.flash('success', 'Settings saved.', 2500);
        // If the save changed the blocking rules, the dashboard's "blocked" flags
        // are now stale (e.g. a denylist entry was removed) — re-fetch so those
        // rows reappear as actionable without a manual refresh.
        if (this.activityLoaded && this.blockingKeyOf(res.settings) !== this._activityBlockKey) {
          this.refreshActivity();
        }
      } catch (e) {
        // Roll the autosaved change back to the last saved state, but keep the
        // in-progress identity draft (profile text + services save on their own
        // button, not here) so the failure never discards unsaved typing.
        const reverted = JSON.parse(this.savedSnapshot);
        reverted.identity = this.settings.identity;
        this._skipAutosave = true;
        this.settings = reverted;
        this.$nextTick(() => { this._skipAutosave = false; });
        this.flash('error', (e && e.message) || 'Could not save your changes — your switch was reset.');
      } finally {
        this.endBusy();
      }
    },
    async refreshReadiness() {
      this.refreshingReadiness = true;
      try {
        this.readiness = await this.api.getReadiness();
        await this.refreshScore(); // the rail is computed from content too — keep it in step.
        this.flash('success', `Readiness re-run — ${this.score.pass}/${this.score.total} checks pass.`);
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.refreshingReadiness = false;
      }
    },
    // Re-read the AEO/GEO rail. Quiet by design: it has no spinner and swallows its errors,
    // because it runs on tab focus and must never interrupt what the owner is doing.
    async refreshScore() {
      try {
        const r = await this.api.getScore();
        if (r && r.score) this.aeo = r.score;
        this._scoreAt = Date.now();
      } catch (e) { /* leave the last known score in place */ }
    },
    // Content is edited in the POST EDITOR — i.e. in another tab — so by the time the owner
    // looks back at this one, the score they're reading can be several edits old, still
    // naming a page they already fixed. Re-read it when the tab comes back to the front,
    // throttled so flicking between windows doesn't hammer the endpoint.
    onTabVisible() {
      if (document.visibilityState !== 'visible') return;
      if (this._scoreAt && Date.now() - this._scoreAt < 10000) return;
      this.refreshScore();
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
    // Refresh is the dashboard's only refresh control, and the score card sits on the same
    // screen — so it refreshes that too. Scoping it strictly to the activity log was
    // technically defensible and practically wrong: people press it expecting the screen to
    // be current, and were left reading a score that named a page they'd already fixed.
    async refreshActivity() {
      this.refreshingActivity = true;
      try {
        this.activity = await this.api.getActivity();
        this.syncAgentAccessBadge(this.activity);
        this.activityLoaded = true;
        this._activityBlockKey = this.blockingKeyOf(this.settings);
        await this.refreshScore();
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.refreshingActivity = false;
      }
    },
    // The unread count rides the /activity payload, which the review bell already polls — so the
    // Agent access badge goes live on exactly the same tick as the bell, at the cost of one COUNT
    // query on a request that was happening anyway. Polling /agent-access instead would mean a
    // second round-trip every 15s that also dragged back up to 100 event rows to render a number.
    //
    // Guarded on `undefined` rather than falsy: a genuine 0 must be able to CLEAR the badge (the
    // owner revoked the key elsewhere, or read the feed in another tab), while an older payload
    // that lacks the field entirely must leave the count alone rather than silently zero it.
    syncAgentAccessBadge(payload) {
      if (payload && payload.agentAccessUnseen !== undefined) {
        this.agentAccessUnseen = payload.agentAccessUnseen || 0;
      }
    },
    // Opt-in live updates. Toggled from the "Activity to review" bell menu; we own
    // the state here because we own the interval. Persisted per browser.
    setLive(on) {
      this.live = !!on;
      try { window.localStorage.setItem(LIVE_PREF_KEY, this.live ? '1' : '0'); } catch (e) { /* private mode */ }
      if (this.live) {
        this.startActivityPolling();
        this.silentRefreshActivity(); // don't make them wait a full tick for the first update
      } else {
        this.stopActivityPolling();
      }
    },
    startActivityPolling() {
      if (this._pollTimer) return; // already running
      this._pollFails = 0;
      this._idle = false;
      this._lastActivity = Date.now();
      this._pollTimer = window.setInterval(this.pollTick, ACTIVITY_POLL_MS);
      // Returning to the tab should feel instant, and we skip ticks while it's
      // hidden, so treat coming back as activity and refresh on the way in.
      this._onVisible = () => {
        if (document.hidden) return;
        this._lastActivity = Date.now();
        this._idle = false;
        if (this.live) this.silentRefreshActivity();
      };
      document.addEventListener('visibilitychange', this._onVisible);
      // Any interaction marks the admin present, and wakes a suspended loop.
      this._onActivity = () => this.noteActivity();
      ACTIVITY_EVENTS.forEach((evt) => window.addEventListener(evt, this._onActivity, { passive: true }));
    },
    stopActivityPolling() {
      if (this._pollTimer) { window.clearInterval(this._pollTimer); this._pollTimer = null; }
      if (this._onVisible) { document.removeEventListener('visibilitychange', this._onVisible); this._onVisible = null; }
      if (this._onActivity) {
        ACTIVITY_EVENTS.forEach((evt) => window.removeEventListener(evt, this._onActivity));
        this._onActivity = null;
      }
      this._idle = false;
    },
    // Record presence. Just a number write on most events (cheap enough to skip
    // throttling); on the idle→active edge it wakes the loop with a fresh pull.
    noteActivity() {
      this._lastActivity = Date.now();
      if (this._idle) {
        this._idle = false;
        if (this.live && !document.hidden) this.silentRefreshActivity();
      }
    },
    pollTick() {
      // Don't poll a backgrounded tab, and never stack a request on top of an
      // in-flight refresh / block / allow.
      if (document.hidden || this.refreshingActivity || this.blockingNow || this.allowingNow || this.reverifyingNow) return;
      // Suspend on a focused-but-idle tab too, the way Heartbeat does — the next
      // interaction resumes via noteActivity(). The interval keeps ticking (a free
      // no-op) so we don't have to tear down and rebuild the timer.
      if (Date.now() - (this._lastActivity || 0) > ACTIVITY_IDLE_MS) { this._idle = true; return; }
      this.silentRefreshActivity();
    },
    // Background refresh: no spinner, no success toast. Compares the "to review"
    // count against what's on screen and nudges only when something NEW shows up.
    async silentRefreshActivity() {
      if (this._silentInFlight) return; // never let two background fetches overlap
      this._silentInFlight = true;
      let fresh = null;
      try {
        fresh = await this.api.getActivity();
        this._pollFails = 0;
      } catch (e) {
        // A transient blip shouldn't nag; a persistently dead endpoint shouldn't
        // hammer the server forever either — back off after a few failures.
        this._pollFails = (this._pollFails || 0) + 1;
        if (this._pollFails >= 4) this.stopActivityPolling();
      } finally {
        this._silentInFlight = false;
      }
      if (!fresh) return;
      // No toast on a background refresh — the bell badge is the persistent "to
      // review" signal, and the stat tiles update in place. A popup here would be
      // a redundant, interruptive third notice for the same event.
      this.activity = fresh;
      // Live, on the same tick as the review bell: a key minted right now lights the nav up
      // without the owner having to reload the page to find out.
      this.syncAgentAccessBadge(fresh);
      this.activityLoaded = true;
      this._activityBlockKey = this.blockingKeyOf(this.settings);
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
    // "Ignore" a flagged client — drops it from the review queue without allowing or
    // blocking (no policy change, so no settings to sync). The endpoint returns refreshed
    // stats with the row already gone; it reappears only if the client changes materially.
    async dismissAgent(payload) {
      this.dismissingNow = payload;
      try {
        this.activity = await this.api.dismissAgent(payload);
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.dismissingNow = null;
      }
    },
    // "Re-check" a flagged client — run reverse-DNS live now on its captured IP(s) and layer the
    // result over the stored verdict. The endpoint returns refreshed stats (the row updates or
    // clears in place); the outcome shows INLINE on the row (reverifyResult), matching the quiet,
    // no-toast pattern of Block/Allow/Ignore. Only a real failure (network / throttle) toasts.
    async reverifyBot(payload) {
      this.reverifyingNow = payload;
      this.reverifyResult = null; // Clear any prior line while this one runs.
      try {
        const res = await this.api.reverifyBot(payload);
        this.activity = res.activity || res;
        this.reverifyResult = { ua: payload.ua, status: res.status, verdict: res.verdict };
      } catch (e) {
        this.flash('error', e.message);
      } finally {
        this.reverifyingNow = null;
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
    // Toast stack: each call adds its own toast (saving, saved, error…) that
    // stacks top-right and dismisses on its own timer — so a burst of saves shows
    // a toast each, instead of one notice clobbering the previous.
    flash(type, text, duration = 4000) {
      this._noticeSeq = (this._noticeSeq || 0) + 1;
      const id = this._noticeSeq;
      this.notices.push({ id, type, text });
      if (this.notices.length > 4) this.notices.splice(0, this.notices.length - 4);
      window.setTimeout(() => {
        const i = this.notices.findIndex((n) => n.id === id);
        if (i !== -1) this.notices.splice(i, 1);
      }, duration);
    },
    titleFor(type) {
      return { success: 'Success', error: 'Error', warning: 'Warning', info: 'Heads up' }[type] || 'Notice';
    },
  },
};
</script>

<template>
  <div class="ar">
    <div class="ar__sticky" :class="{ 'is-stuck': scrolled }">
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
          v-for="t in primaryTabs"
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

      <!-- The occasional screens. Folding them behind one control keeps the bar short on a
           narrow admin, and lets AI Visibility appear and disappear without the nav's width
           jumping. It carries the active underline when the screen you're on is inside it,
           so the bar always shows where you are.

           Deliberately a SIBLING of <nav>, not a child: .ar__tabs is an overflow-x scroller
           (so the tabs can scroll on a narrow admin) and an absolutely-positioned menu inside
           it would be clipped by that scroll box. .ar__review solves the same problem the
           same way. It also keeps role="tablist" honest — a menu button is not a tab. -->
      <div
        ref="moreWrap"
        class="ar__more"
        :class="{ 'is-open': moreOpen }"
        @mouseenter="onMoreEnter"
        @mouseleave="onMoreLeave"
      >
        <button
          ref="moreBtn"
          class="ar__tab ar__more-btn"
          :class="{ 'is-active': moreActive }"
          aria-haspopup="menu"
          :aria-expanded="moreOpen ? 'true' : 'false'"
          :aria-label="agentAccessUnseen
            ? `More screens — ${agentAccessUnseen} unread agent access ${agentAccessUnseen === 1 ? 'event' : 'events'}`
            : 'More screens'"
          @click="toggleMore"
        >
          More
          <!-- A dot, not a number: the count lives on the item inside. All this has to do is
               tell someone there is something in here they haven't read — and it has to do it
               from whichever screen they happen to be on. -->
          <span v-if="agentAccessUnseen" class="ar__more-dot" aria-hidden="true"></span>
          <span class="ar__more-caret" aria-hidden="true">
            <svg viewBox="0 0 12 12" width="9" height="9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="2.5 4.5 6 8 9.5 4.5" /></svg>
          </span>
        </button>

        <!-- The trigger is a TAB; this is a MENU. Hence sentence-case sans labels rather
             than the bar's mono eyebrows: a destination has a name you read, not a metadata
             label you scan. The icon column is what a stacked row of tabs could never have. -->
        <div
          v-if="moreOpen"
          ref="moreMenu"
          class="ar__more-menu"
          role="menu"
          :style="{ left: moreShift + 'px' }"
          @keydown="onMoreNav"
        >
          <template v-for="t in moreTabs" :key="t.id">
            <div v-if="t.divided" class="ar__more-sep" role="separator"></div>
            <button
              class="ar__more-item"
              :class="{ 'is-active': tab === t.id, 'is-disabled': t.disabled }"
              role="menuitem"
              :disabled="t.disabled"
              :aria-disabled="t.disabled ? 'true' : null"
              :aria-current="tab === t.id ? 'page' : null"
              @click="pickMore(t)"
            >
              <svg class="ar__more-icon" viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path v-for="(d, i) in moreIcon(t.id)" :key="i" :d="d" />
              </svg>
              <span class="ar__more-item-label">{{ t.label }}</span>
              <!-- Unread agent-access events. A new application password is the one thing here
                   worth pulling someone's eye across the screen for. -->
              <span v-if="t.badge" class="ar__more-item-badge">{{ t.badge }}</span>
              <!-- Say why it's unreachable, rather than leaving a dead entry to puzzle over. -->
              <span v-if="t.disabled && t.note" class="ar__more-item-note">{{ t.note }}</span>
            </button>
          </template>
        </div>
      </div>

      <ReviewMenu
        :threats="(activity && activity.threats) || {}"
        :enabled="!!(activity && activity.enabled)"
        :store-ips="!!settings.store_flagged_ips"
        :blocking="blockingNow"
        :allowing="allowingNow"
        :dismissing="dismissingNow"
        :reverifying="reverifyingNow"
        :reverify-result="reverifyResult"
        :live="live"
        :live-interval="15"
        @block="blockAgent"
        @allow="allowAgent"
        @dismiss="dismissAgent"
        @reverify="reverifyBot"
        @set-live="setLive"
        @navigate="goTo"
        @flash="flash"
        @manage="openClientManager"
      />
    </header>

    <div class="ar__pagehead">
      <h1 class="ar__pagehead-title">{{ pageMeta.title }}</h1>
      <p v-if="pageMeta.description" class="ar__pagehead-desc">{{ pageMeta.description }}</p>
    </div>
    </div>

    <Teleport to="body">
      <transition-group tag="div" name="ar-toast" class="ar-toasts" aria-live="polite">
        <div v-for="n in notices" :key="n.id" class="ar-toast" :class="`is-${n.type}`" role="status">
          <span class="ar-toast__bar" aria-hidden="true"></span>
          <div class="ar-toast__body">
            <strong class="ar-toast__title">{{ titleFor(n.type) }}</strong>
            <span v-if="n.text" class="ar-toast__msg">{{ n.text }}</span>
          </div>
        </div>
      </transition-group>
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
      @navigate="(t) => { closeWizard(); goTo(t); }"
    />

    <main class="ar__body is-railed">
      <div class="ar__main">
        <SettingsForm
          v-show="tab === 'settings'"
          ref="settingsForm"
          :busy="savingSettings"
          :api="api"
          v-model:settings="settings"
          :entity-types="entityTypes"
          :retention-choices="retentionChoices"
          :max-rows-choices="maxRowsChoices"
          :post-types="postTypes"
          :categories="categories"
          :known-trainers="knownTrainers"
          :known-scanners="knownScanners"
          :known-allowed="knownAllowed"
          :default-allowed="defaultAllowed"
          :webmcp-tools="webmcpTools"
          :mcp-server="mcpServer"
          :debug="debug"
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
          @clients-changed="syncBlockSettings"
        />
        <ReadinessPanel
          v-show="tab === 'readiness'"
          :checks="readiness"
          :optimize="optimizeWork"
          :optimize-ignored="optimizeIgnored"
          :optimize-graded="(aeo && aeo.graded) || 0"
          :refreshing="refreshingReadiness"
          :live-config="liveConfig"
          :is-local="isLocal"
          :api="api"
          @refresh="refreshReadiness"
          @navigate="goTo"
          @flash="flash"
          @score-updated="(s) => { aeo = s; }"
        />
        <DiscoveryHub
          v-show="tab === 'discovery'"
          :data="discovery"
          :refreshing="refreshingDiscovery"
          @refresh="refreshDiscovery"
          @navigate="goTo"
        />
        <ActivityPanel
          v-show="tab === 'dashboard'"
          :data="activity"
          :summary="dashSummary"
          :loaded="activityLoaded"
          :refreshing="refreshingActivity"
          :live="live"
          :api="api"
          @refresh="refreshActivity"
          @clear="clearActivity"
          @navigate="goTo"
          @flash="flash"
        />
        <AiTrafficPanel
          v-if="settings.enable_activity"
          v-show="tab === 'ai-traffic'"
          :api="api"
          :active="tab === 'ai-traffic'"
          :log-unknown="!!settings.log_unknown_referrers"
          @navigate="goTo"
          @flash="flash"
        />
        <RequestLog
          v-if="settings.enable_activity"
          v-show="tab === 'log'"
          :api="api"
          :active="tab === 'log'"
          @flash="flash"
        />
        <!-- No v-if: this screen is mounted on every site, however old. Its entire job is to
             say honestly what it can and cannot see here, and it cannot do that if it isn't
             there. `seen` clears the nav badge the moment the owner reads the feed. -->
        <AgentAccess
          v-show="tab === 'agent-access'"
          :api="api"
          :active="tab === 'agent-access'"
          :unread="agentAccessUnseen"
          @seen="agentAccessUnseen = 0"
          @flash="flash"
        />
        <VisibilityPanel
          v-if="settings.enable_visibility"
          v-show="tab === 'visibility'"
          ref="visibilityPanel"
          :api="api"
          @flash="flash"
        />
        <AboutPanel
          v-show="tab === 'about'"
          :version="version"
          :protocol="protocol"
          @navigate="goTo"
        />
      </div>

      <aside class="ar__rail">
        <!-- The Readiness card, evolved: the gauge now shows the composite AEO/GEO
             score, and the ladder extends the three readiness rungs with two AEO/GEO
             rungs (Optimized, Cited). Same card, same dark look — extended. -->
        <div v-if="aeo" class="ar-rail-card ar-rail-card--readiness">
          <p class="ar-rail-card__label">AEO / GEO</p>
          <button
            type="button"
            class="ar-rail-readiness ar-rail-readiness--link"
            aria-label="Open the full readiness report"
            @click="goTo('readiness')"
          >
            <div class="ar-rail-gauge" role="img" :aria-label="`AEO/GEO score ${aeo.score} of 100`">
              <svg viewBox="0 0 116 116">
                <circle class="ar-rail-gauge__track" cx="58" cy="58" r="52" />
                <circle
                  class="ar-rail-gauge__fill"
                  cx="58"
                  cy="58"
                  r="52"
                  :data-tone="aeoTone"
                  :stroke-dasharray="circumference"
                  :stroke-dashoffset="aeoDashOffset"
                />
              </svg>
              <span class="ar-rail-gauge__num">{{ aeo.blocked ? '—' : aeo.score }}<small v-if="!aeo.blocked">%</small></span>
            </div>
            <div class="ar-rail-tier" :data-state="aeo.blocked ? 'floor' : (aeo.ready ? 'top' : 'climb')">
              <strong class="ar-rail-tier__name">{{ aeo.blocked ? 'Not reachable' : aeo.band }}</strong>
              <span class="ar-rail-tier__sub">{{
                aeo.blocked ? 'agents can’t read the site'
                : aeo.ready ? 'fully agent-ready'
                : 'getting ready'
              }}</span>
            </div>
          </button>

          <!-- The three readiness rungs, extended by the two AEO/GEO rungs. Each links
               to where you act on it; Optimized is per-post, so it's a plain stat row. -->
          <ol class="ar-rungs">
            <li v-for="r in aeo.rungs" :key="r.key" class="ar-rung" :data-state="rungState(r)">
              <button
                v-if="r.to"
                type="button"
                class="ar-rung__btn"
                :aria-label="rungTitle(r)"
                @click="goTo(rungTarget(r))"
              >
                <span class="ar-rung__tick" aria-hidden="true"></span>
                <span class="ar-rung__name">{{ r.label }}</span>
                <!-- Check-backed rungs count their non-passing checks; Cited is a
                     measurement, not a checklist, so it never wears the chip. -->
                <em v-if="rungTodo(r)" class="ar-rung__todo">{{ rungTodo(r) }} to fix</em>
                <span class="ar-rung__count">{{ rungCount(r) }}</span>
              </button>
              <!-- Optimized routes like the other rungs — to its section on the
                   Readiness tab (the per-page worklist), when there's work to do. -->
              <button
                v-else-if="r.key === 'optimized' && optimizeActionable"
                type="button"
                class="ar-rung__btn"
                aria-label="See which pages to optimize"
                @click="goTo({ tab: 'readiness', anchor: 'ar-group-optimized' })"
              >
                <span class="ar-rung__tick" aria-hidden="true"></span>
                <span class="ar-rung__name">{{ r.label }}</span>
                <!-- The WHOLE worklist's size — the Next line below only ever
                     names the top issue, so this is where the total lives. -->
                <em v-if="optimizeTotal" class="ar-rung__todo">{{ optimizeTotal }} to fix</em>
                <span class="ar-rung__count">{{ rungCount(r) }}</span>
              </button>
              <div v-else class="ar-rung__btn ar-rung__btn--static">
                <span class="ar-rung__tick" aria-hidden="true"></span>
                <span class="ar-rung__name">{{ r.label }}</span>
                <span class="ar-rung__count">{{ rungCount(r) }}</span>
              </div>
            </li>
          </ol>

          <button
            v-if="aeoNext && aeoNext.action"
            type="button"
            class="ar-rail-link ar-rail-next"
            @mouseenter="showRailTip($event, aeoNextTip, aeoNextTipHint)"
            @mouseleave="hideUaTip"
            @focus="showRailTip($event, aeoNextTip, aeoNextTipHint)"
            @blur="hideUaTip"
            @click="openNext"
          >Next: {{ aeoNext.title }} →</button>
          <p
            v-else-if="aeoNext"
            class="ar-rail-next ar-rail-next--info"
            @mouseenter="showRailTip($event, aeoNextTip)"
            @mouseleave="hideUaTip"
          >Next: {{ aeoNext.title }}</p>
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

        <!-- Registration status — its own compact one-line card (separate from the black
             card). Green ✓ when valid, amber alert when broken; the → is always shown. -->
        <button
          type="button"
          class="ar-rail-card ar-rail-regcard"
          :class="validation.ok ? 'is-ok' : 'is-alert'"
          :title="validation.ok ? 'See what’s registered' : 'Review registration issues'"
          @click="goTo({ tab: 'discovery', anchor: validation.ok ? 'ar-wd-providers' : 'ar-wd-validation' })"
        >
          <span class="ar-rail-regcard__icon" aria-hidden="true">{{ validation.ok ? '✓' : '⚠' }}</span>
          <span class="ar-rail-regcard__text">{{ validation.ok ? 'All registrations are valid' : `${validation.count} ${validation.count === 1 ? 'issue' : 'issues'} to fix` }}</span>
          <span class="ar-rail-regcard__go" aria-hidden="true">→</span>
        </button>

        <p class="ar-rail-foot" aria-label="Made with love by Sheikh Heera"><span class="ar-rail-foot__text">Made with <span class="ar-rail-foot__heart" aria-hidden="true">♥</span> by <a class="ar-rail-foot__link" href="https://heera.it" target="_blank" rel="noopener">Sheikh Heera</a></span></p>
      </aside>
    </main>

    <!-- The styled hover bubble for the score rail's rung + next-step hints —
         the shared uaTip state, in its prose (--info) variant. -->
    <Teleport to="body">
      <transition name="ar-tip">
        <div
          v-if="uaTip.show"
          ref="uaTipEl"
          class="ar-act-uatip ar-act-uatip--info ar-act-uatip--rail"
          :class="{ 'is-below': uaTip.below }"
          :style="{ left: uaTip.x + 'px', top: uaTip.y + 'px', maxWidth: uaTip.maxW || null }"
          role="tooltip"
          aria-hidden="true"
        ><span class="ar-act-uatip__ua">{{ uaTip.text }}</span><span v-if="uaTip.hint" class="ar-act-uatip__hint">{{ uaTip.hint }}</span><span class="ar-act-uatip__caret"></span></div>
      </transition>
    </Teleport>
  </div>
</template>
