<script>
import TagInput from './TagInput.vue';

export default {
  name: 'SettingsForm',
  components: { TagInput },
  props: {
    settings: { type: Object, required: true },
    entityTypes: { type: Array, default: () => ['Person', 'Organization', 'LocalBusiness', 'Store'] },
    postTypes: { type: Array, default: () => [] },
    knownTrainers: { type: Array, default: () => [] },
    knownScanners: { type: Array, default: () => [] },
    endpoints: { type: Object, default: () => ({}) },
    restNamespacesDetected: { type: Array, default: () => [] },
    providerResources: { type: Array, default: () => [] },
    profileDirty: { type: Boolean, default: false },
    profileSaving: { type: Boolean, default: false },
    profileSaved: { type: Boolean, default: false },
    servicesDirty: { type: Boolean, default: false },
    servicesSaving: { type: Boolean, default: false },
    servicesSaved: { type: Boolean, default: false },
    resetting: { type: Boolean, default: false },
    defaults: { type: Object, default: () => ({}) },
    llmsFullEstimate: { type: Object, default: () => ({}) },
  },
  emits: ['save-profile', 'save-services', 'reset', 'reopen-wizard'],
  data() {
    return {
      typeQuery: '',
      nsQuery: '',
      showReset: false,
      scrollMore: false,
      // Keep the developer section open if a value is already configured, so an
      // existing setup is never hidden; collapsed by default on a fresh install.
      showAdvanced: !!(this.settings && (this.settings.oauth_auth_server || '').trim()),
      oauthChecking: false,
      oauthCheck: null,
    };
  },
  mounted() {
    window.addEventListener('resize', this.updateScrollHint);
  },
  beforeUnmount() {
    window.removeEventListener('resize', this.updateScrollHint);
  },
  computed: {
    filteredPostTypes() {
      const q = this.typeQuery.trim().toLowerCase();
      if (!q) return this.postTypes;
      return this.postTypes.filter(
        (pt) =>
          pt.label.toLowerCase().includes(q) ||
          pt.slug.toLowerCase().includes(q) ||
          (pt.source && pt.source.toLowerCase().includes(q)),
      );
    },
    selectedTypeCount() {
      const sel = Array.isArray(this.settings.post_types) ? this.settings.post_types : [];
      const slugs = this.postTypes.map((p) => p.slug);
      return sel.filter((s) => slugs.includes(s)).length;
    },
    blockedCount() {
      return Array.isArray(this.settings.blocked_trainers) ? this.settings.blocked_trainers.length : 0;
    },
    oauthCheckClass() {
      if (!this.oauthCheck) return '';
      if (this.oauthCheck.ok === true) return 'is-ok';
      if (this.oauthCheck.ok === false) return 'is-bad';
      return 'is-info';
    },
    identity() {
      // Guard against a missing identity object on first paint.
      if (!this.settings.identity) {
        this.settings.identity = {
          entity_type: 'Person',
          name: '',
          role: '',
          about: '',
          contact_email: '',
          expertise: [],
          same_as: [],
          services: [],
        };
      }
      // Older saved settings predate services — make sure it's always an array.
      if (!Array.isArray(this.settings.identity.services)) {
        this.settings.identity.services = [];
      }
      return this.settings.identity;
    },
    security() {
      // Guard against a missing security object on first paint.
      if (!this.settings.security || typeof this.settings.security !== 'object') {
        this.settings.security = {
          contacts: [], policy: '', acknowledgments: '',
          encryption: '', hiring: '', preferred_languages: '', expires_days: 182,
        };
      }
      return this.settings.security;
    },
    // RFC 9116 requires at least one Contact; the identity email seeds the first.
    // Without any contact the generator emits nothing, so we warn before that.
    hasSecurityContact() {
      const email = (this.identity.contact_email || '').trim();
      const extra = Array.isArray(this.security.contacts) ? this.security.contacts : [];
      return !!email || extra.length > 0;
    },
    securityTxtUrl() {
      try {
        return new URL('/.well-known/security.txt', this.endpoints.robots || this.endpoints.llms || window.location.origin).href;
      } catch (e) {
        return '';
      }
    },
    features() {
      // Plain-language labels; the real filename/term stays in the hint so it's
      // always discoverable.
      return [
        { key: 'enable_llms_txt', label: 'AI page guide', hint: 'A plain map of your pages, topics and recent posts for assistants. (file: llms.txt)' },
        { key: 'enable_llms_full', label: 'Full text for AI', hint: 'Bundles your pages and recent posts into one document an assistant can read in a single pass. (file: llms-full.txt)' },
        { key: 'enable_markdown', label: 'Plain-text versions', hint: 'Lets assistants fetch a clean text version of any page — add .md to its URL.' },
        { key: 'enable_robots', label: 'Crawler rules', hint: 'States your preferences to crawlers and blocks known AI-training bots by name. (file: robots.txt)' },
        { key: 'enable_schema', label: 'Rich data for search', hint: 'Adds structured data search engines and assistants understand (JSON-LD). Leave off if your SEO plugin already does this.' },
        { key: 'enable_activity', label: 'Visit log', hint: 'Records which AI assistants fetch your AI files, and counts visitors AI sends you. Local-only, no IP addresses.' },
        { key: 'enable_sitemap', label: 'Sitemap (backup)', hint: 'Adds a sitemap only when WordPress core and your SEO plugin don’t already provide one — never duplicates.' },
        { key: 'enable_signing', label: 'Sign discovery docs', hint: 'Cryptographically sign your discovery files (Ed25519) and publish a key directory so agents can verify they came from you. Experimental (Web Bot Auth).' },
      ];
    },
    // A heads-up under the posts-per-type input: the server's COUNT-only estimate
    // of the full-text file size for the saved config (refreshes on reload).
    fullSizeNote() {
      const e = this.llmsFullEstimate || {};
      if (!e.items) return null;
      const fmt = (n) => (n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');
      return {
        warn: !!e.will_truncate,
        text: e.will_truncate
          ? `About ${e.items} items (≈${fmt(e.est_bytes)}) would exceed the ${fmt(e.budget_bytes)} limit, so the file will be truncated — lower this, or rely on the /llms.txt index.`
          : `About ${e.items} items (≈${fmt(e.est_bytes)}), within the ${fmt(e.budget_bytes)} limit.`,
      };
    },
    resetPreview() {
      // A compact summary of the factory defaults, shown in the reset dialog so
      // the user sees exactly what they'll get before confirming.
      const d = this.defaults || {};
      const cs = d.content_signal || {};
      return {
        features: this.features.map((f) => ({ label: f.label, on: !!d[f.key] })),
        signals: this.signalRows.map((r) => ({ label: r.label, allow: !!cs[r.key] })),
        trainers: Array.isArray(d.blocked_trainers) ? d.blocked_trainers.length : 0,
        types: Array.isArray(d.post_types) ? d.post_types.length : 0,
        fullPosts: d.llms_full_posts,
      };
    },
    signal() {
      // Content-Signal is a fixed vocabulary; guard against a missing object.
      if (!this.settings.content_signal || typeof this.settings.content_signal !== 'object') {
        this.settings.content_signal = { search: true, ai_input: true, ai_train: false };
      }
      return this.settings.content_signal;
    },
    signalRows() {
      return [
        { key: 'search', label: 'Show in search engines', hint: 'Let Google and other search engines find your pages.' },
        { key: 'ai_input', label: 'Let AI read & cite you', hint: 'Allow assistants to read your content and cite it in their answers.' },
        { key: 'ai_train', label: 'Allow AI training', hint: 'Allow your content to be used to train AI models.' },
      ];
    },
    signalPreview() {
      const yn = (v) => (v ? 'yes' : 'no');
      return `Content-Signal: search=${yn(this.signal.search)}, ai-input=${yn(this.signal.ai_input)}, ai-train=${yn(this.signal.ai_train)}`;
    },
    // The same ai-train decision, broadcast beyond robots.txt.
    reservedSignal() {
      return !this.signal.ai_train; // ai-train=no → content is reserved (opting out).
    },
    tdmrepUrl() {
      // /.well-known is always domain-root-relative (RFC 8615), regardless of any
      // WordPress subdirectory, so origin + path is correct everywhere.
      return window.location.origin + '/.well-known/tdmrep.json';
    },
    // One plain sentence describing where the opt-out is published, reflecting
    // which standardized channels are enabled. Only shown when reserving.
    channelsSummary() {
      if (!this.settings.enable_ai_header && !this.settings.enable_tdmrep) {
        return 'robots.txt is only a request a crawler can ignore. Turn on a channel below to also publish your choice as a standardized signal that’s harder to skip.';
      }
      return 'robots.txt is only a request a crawler can ignore — so your “no AI training” choice also goes out in the standardized signals below, which are harder for a bot to skip.';
    },
    isOrg() {
      return this.identity.entity_type !== 'Person';
    },
    namePlaceholder() {
      return this.isOrg ? 'Acme Inc.' : 'Jane Doe';
    },
    aboutPlaceholder() {
      return this.isOrg
        ? 'One factual sentence on what your business or organization does and its focus.'
        : 'One factual sentence stating who you are and your expertise.';
    },
    expertiseLabel() {
      return this.isOrg ? 'Topics & specialties' : 'Expertise topics';
    },
    profileUrlPlaceholder() {
      return this.isOrg ? 'https://www.linkedin.com/company/you' : 'https://github.com/you';
    },
    trainerSuggestions() {
      const current = this.settings.blocked_trainers || [];
      return this.knownTrainers.filter((t) => !current.includes(t));
    },
    scannerSuggestions() {
      const current = this.settings.blocked_agents || [];
      return this.knownScanners.filter((s) => !current.includes(s));
    },
    riskyBlockedAgents() {
      // Flag entries broad enough to catch legitimate traffic, so the admin gets a
      // heads-up. (Search engines are always allowed by the server regardless; this
      // is the softer "you might also block real browsers/AI crawlers" warning.)
      const danger = ['bot', 'mozilla', 'safari', 'chrome', 'gecko', 'webkit', 'applewebkit',
        'android', 'iphone', 'ipad', 'mobile', 'compatible', 'crawler', 'spider', 'http', 'www', 'like'];
      const list = Array.isArray(this.settings.blocked_agents) ? this.settings.blocked_agents : [];
      return list.filter((a) => {
        const t = String(a).trim().toLowerCase();
        if (!t) return false;
        const literal = t.replace(/[/^$.*+?()[\]{}|\\]/g, ''); // strip pattern chars to gauge real breadth
        if (literal === '' && t.includes('*')) return true;    // all-wildcard ("*", ".*") — matches everyone
        if (literal.length > 0 && literal.length < 3) return true; // ultra-short token → broad
        return danger.includes(t) || danger.includes(literal);
      });
    },
    isDefaultTrainers() {
      const a = [...(this.settings.blocked_trainers || [])].sort();
      const b = [...this.knownTrainers].sort();
      return a.length === b.length && a.every((v, i) => v === b[i]);
    },
    publishedNsCount() {
      const sel = Array.isArray(this.settings.rest_namespaces) ? this.settings.rest_namespaces : [];
      return this.restNamespacesDetected.filter((ns) => sel.includes(ns)).length;
    },
    filteredNamespaces() {
      const q = this.nsQuery.trim().toLowerCase();
      if (!q) return this.restNamespacesDetected;
      return this.restNamespacesDetected.filter((ns) => ns.toLowerCase().includes(q));
    },
  },
  methods: {
    isUrl(value) {
      return /^https?:\/\//i.test(value);
    },
    // The same-origin RFC 9728 doc the plugin publishes from this setting. We
    // check OUR OWN site (not the third-party auth server) on purpose: it's
    // readable cross-origin-free, and it answers the real question — "is my auth
    // flow now discoverable?" — rather than poking someone else's server.
    oauthWellKnownUrl() {
      const base = this.endpoints.robots || this.endpoints.llms || '';
      try {
        return `${new URL(base).origin}/.well-known/oauth-protected-resource`;
      } catch (e) {
        return '';
      }
    },
    async checkOauth() {
      if (this.oauthChecking) return;
      const entered = (this.settings.oauth_auth_server || '').trim();
      this.oauthCheck = null;
      if (!entered) {
        this.oauthCheck = { ok: null, msg: 'Nothing to check — leave this blank unless your site has a login-protected API.' };
        return;
      }
      if (!this.isUrl(entered)) {
        this.oauthCheck = { ok: false, msg: 'Enter a full address, e.g. https://auth.example.com' };
        return;
      }
      const url = this.oauthWellKnownUrl();
      if (!url) {
        this.oauthCheck = { ok: false, msg: 'Could not work out your site address to run the check.' };
        return;
      }
      const norm = (v) => String(v).replace(/\/+$/, '');
      this.oauthChecking = true;
      try {
        // Anonymous, uncached — exactly what an agent sees on the public URL.
        const res = await fetch(url, { method: 'GET', credentials: 'omit', cache: 'no-store' });
        if (res.status !== 200) {
          this.oauthCheck = { ok: false, msg: `Not published yet (HTTP ${res.status}). Save your settings, then check again.` };
          return;
        }
        let doc = null;
        try { doc = await res.json(); } catch (e) { doc = null; }
        const servers = doc && Array.isArray(doc.authorization_servers) ? doc.authorization_servers : [];
        if (servers.some((s) => norm(s) === norm(entered))) {
          this.oauthCheck = { ok: true, msg: 'Published ✓ — agents can now discover your login server at /.well-known/oauth-protected-resource.' };
        } else if (servers.length) {
          this.oauthCheck = { ok: false, msg: `Published, but it still lists ${servers[0]} — save your latest change, then check again.` };
        } else {
          this.oauthCheck = { ok: false, msg: 'Published, but no login server is listed yet. Save your settings, then check again.' };
        }
      } catch (e) {
        this.oauthCheck = { ok: false, msg: 'Could not reach the metadata on your own site (offline or blocked).' };
      } finally {
        this.oauthChecking = false;
      }
    },
    openReset() {
      if (this.resetting) return;
      this.showReset = true;
      this.$nextTick(() => {
        if (this.$refs.resetDialog) this.$refs.resetDialog.focus();
        this.updateScrollHint();
      });
    },
    closeReset() {
      this.showReset = false;
    },
    onBodyScroll() {
      this.updateScrollHint();
    },
    // Show the bottom fade + chevron only while there's more content below, so
    // the user knows the list scrolls (and the cue disappears at the end).
    updateScrollHint() {
      const el = this.$refs.resetBody;
      this.scrollMore = !!el && el.scrollHeight - el.scrollTop - el.clientHeight > 4;
    },
    scrollResetBody() {
      const el = this.$refs.resetBody;
      if (!el) return;
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      el.scrollBy({ top: Math.round(el.clientHeight * 0.8), behavior: reduce ? 'auto' : 'smooth' });
    },
    doReset() {
      this.showReset = false;
      this.$emit('reset');
    },
    addService() {
      // A row needs a name to be saved, so don't stack unsaveable blank rows —
      // fill the empty one already on screen before adding another.
      if (this.identity.services.some((s) => !(s.name || '').trim())) {
        return;
      }
      this.identity.services.push({ name: '', description: '', url: '' });
    },
    removeService(index) {
      this.identity.services.splice(index, 1);
      // A removal is intentional and complete — persist it immediately rather than
      // waiting for an explicit Save (which only the add/edit flow needs). The
      // parent's servicesDirty guard makes this a no-op when nothing actually
      // changed vs the saved state (e.g. removing an unsaved or blank row).
      this.$emit('save-services');
    },
    addTrainer(name) {
      if (!Array.isArray(this.settings.blocked_trainers)) this.settings.blocked_trainers = [];
      if (!this.settings.blocked_trainers.includes(name)) this.settings.blocked_trainers.push(name);
    },
    resetTrainers() {
      this.settings.blocked_trainers = [...this.knownTrainers];
    },
    addScanner(name) {
      if (!Array.isArray(this.settings.blocked_agents)) this.settings.blocked_agents = [];
      if (!this.settings.blocked_agents.includes(name)) this.settings.blocked_agents.push(name);
    },
    isTypeOn(slug) {
      return Array.isArray(this.settings.post_types) && this.settings.post_types.includes(slug);
    },
    toggleType(slug) {
      if (!Array.isArray(this.settings.post_types)) this.settings.post_types = [];
      const list = this.settings.post_types;
      const i = list.indexOf(slug);
      if (i === -1) list.push(slug);
      else list.splice(i, 1);
    },
    selectAllTypes() {
      this.settings.post_types = this.postTypes.map((p) => p.slug);
    },
    isNsOn(ns) {
      return Array.isArray(this.settings.rest_namespaces) && this.settings.rest_namespaces.includes(ns);
    },
    toggleNs(ns) {
      if (!Array.isArray(this.settings.rest_namespaces)) this.settings.rest_namespaces = [];
      const list = this.settings.rest_namespaces;
      const i = list.indexOf(ns);
      if (i === -1) list.push(ns);
      else list.splice(i, 1);
    },
    // Provider resources publish by default; the owner opts OUT by suppressing.
    isPublished(id) {
      const sup = Array.isArray(this.settings.suppressed_resources) ? this.settings.suppressed_resources : [];
      return !sup.includes(id);
    },
    togglePublish(id) {
      if (!Array.isArray(this.settings.suppressed_resources)) this.settings.suppressed_resources = [];
      const list = this.settings.suppressed_resources;
      const i = list.indexOf(id);
      if (i === -1) list.push(id); // now suppressed
      else list.splice(i, 1); // back to published
    },
    providerLabel(plugin) {
      return plugin ? String(plugin).split('/')[0] : '';
    },
  },
};
</script>

<template>
  <form class="ar-form" @submit.prevent="$emit('save-profile')">
    <!-- Identity ------------------------------------------------------- -->
    <section id="ar-sec-identity" class="ar-card">
      <h2 class="ar-card__title">Identity</h2>
      <p class="ar-card__lead">The highest-signal data an agent reads — who owns this site and what it's about.</p>

      <!-- Compose-and-save block: free text you compose, then commit with Save. -->
      <div class="ar-id-block">
        <div class="ar-grid">
        <div class="ar-field">
          <label for="ar-type">Entity type</label>
          <select id="ar-type" v-model="identity.entity_type" class="ar-input">
            <option v-for="t in entityTypes" :key="t" :value="t">{{ t.replace(/([a-z])([A-Z])/g, '$1 $2') }}</option>
          </select>
        </div>
        <div class="ar-field">
          <label for="ar-name">Name</label>
          <input id="ar-name" v-model="identity.name" type="text" class="ar-input" :placeholder="namePlaceholder" />
        </div>
        <div v-if="identity.entity_type === 'Person'" class="ar-field">
          <label for="ar-role">Role / title</label>
          <input id="ar-role" v-model="identity.role" type="text" class="ar-input" placeholder="Software architect" />
        </div>
      </div>

      <div class="ar-field">
        <label for="ar-about">Profile sentence</label>
        <textarea
          id="ar-about"
          v-model="identity.about"
          class="ar-input"
          rows="3"
          :placeholder="aboutPlaceholder"
        ></textarea>
        <small class="ar-field__hint">Used at the top of llms.txt, the full-text edition, and the JSON-LD description.</small>
      </div>

      <div class="ar-field">
        <label for="ar-not">What you’re not <span class="ar-field__tag">optional</span></label>
        <textarea
          id="ar-not"
          v-model="identity.not_description"
          class="ar-input"
          rows="2"
          placeholder="e.g. This is not a personal blog or a news site."
        ></textarea>
        <small class="ar-field__hint">An explicit exclusion so agents don’t miscategorize you. Becomes JSON-LD <code>disambiguatingDescription</code> and a line in llms.txt.</small>
      </div>

      <div class="ar-field">
        <label for="ar-audience">Audience <span class="ar-field__tag">optional</span></label>
        <input id="ar-audience" v-model="identity.audience" type="text" class="ar-input" placeholder="e.g. Small business owners evaluating IT services" />
        <small class="ar-field__hint">Who the site is for. Feeds JSON-LD <code>audience</code> and llms.txt.</small>
      </div>

      <div class="ar-field">
        <label for="ar-contact">Public contact email <span class="ar-field__tag">optional</span></label>
        <input id="ar-contact" v-model="identity.contact_email" type="email" class="ar-input" placeholder="hello@example.com" />
        <small class="ar-field__hint">
          Published in <code>discovery.json</code> so agents can reach you. Leave empty to expose none —
          your WordPress admin email is never used.
        </small>
      </div>

        <div class="ar-id-foot">
          <span v-if="profileSaving" class="ar-id-foot__status">Saving…</span>
          <span v-else-if="profileDirty" class="ar-id-foot__status is-dirty">Unsaved changes</span>
          <span v-else-if="profileSaved" class="ar-id-foot__status is-saved">Saved ✓</span>
          <span v-else class="ar-id-foot__status">Saved</span>
          <button type="button" class="ar-btn" :disabled="profileSaving || !profileDirty" @click="$emit('save-profile')">
            {{ profileSaving ? 'Saving…' : 'Save profile' }}
          </button>
        </div>
      </div>

      <div class="ar-field">
        <label>{{ expertiseLabel }}</label>
        <TagInput v-model="identity.expertise" placeholder="Add a topic, press Enter" />
        <small class="ar-field__hint">Feeds this list and schema <code>knowsAbout</code>. Saved as you add.</small>
      </div>

      <div class="ar-field">
        <label>Profile URLs</label>
        <TagInput v-model="identity.same_as" :placeholder="profileUrlPlaceholder" />
        <small class="ar-field__hint">
          Public profile URLs (LinkedIn, X, GitHub, Facebook, Wikipedia…) that help agents resolve your entity. Saved as you add.
          <span v-if="identity.same_as.some((u) => !isUrl(u))" class="ar-warn">Some entries are not full https:// URLs.</span>
        </small>
      </div>

    </section>

    <!-- Services ------------------------------------------------------- -->
    <section id="ar-sec-services" class="ar-card">
      <h2 class="ar-card__title">Services</h2>
      <p class="ar-card__lead">
        What you can be hired for — each becomes a Schema.org <code>Service</code> linked to you as
        the provider, so agents can answer “what does this site offer?”. Optional; leave empty if
        you don't sell services.
      </p>

      <div v-for="(svc, i) in identity.services" :key="i" class="ar-svc">
        <button type="button" class="ar-svc__x" aria-label="Remove service" title="Remove service" @click="removeService(i)">×</button>
        <div class="ar-svc__row">
          <input
            v-model="svc.name"
            type="text"
            class="ar-input ar-svc__name"
            placeholder="Service name (e.g. WordPress plugin development)"
            aria-label="Service name"
          />
          <input
            v-model="svc.url"
            type="url"
            class="ar-input ar-svc__url"
            placeholder="https://… (optional)"
            aria-label="Service URL"
          />
        </div>
        <input
          v-model="svc.description"
          type="text"
          class="ar-input"
          placeholder="One line on what it includes (optional)"
          aria-label="Service description"
        />
      </div>
      <button type="button" class="ar-svc__add" @click="addService">+ Add a service</button>

      <div class="ar-id-foot">
        <span v-if="servicesSaving" class="ar-id-foot__status">Saving…</span>
        <span v-else-if="servicesDirty" class="ar-id-foot__status is-dirty">Unsaved changes</span>
        <span v-else-if="servicesSaved" class="ar-id-foot__status is-saved">Saved ✓</span>
        <span v-else class="ar-id-foot__status">Saved</span>
        <button type="button" class="ar-btn" :disabled="servicesSaving || !servicesDirty" @click="$emit('save-services')">
          {{ servicesSaving ? 'Saving…' : 'Save services' }}
        </button>
      </div>
    </section>

    <!-- Security.txt --------------------------------------------------- -->
    <section id="ar-sec-security" class="ar-card">
      <h2 class="ar-card__title">Security.txt</h2>
      <p class="ar-card__lead">
        A machine- and human-readable security contact at
        <code>/.well-known/security.txt</code> (RFC 9116). Generated only when enabled —
        and it stands aside automatically if a real file or another plugin already provides one.
      </p>

      <label class="ar-toggle">
        <input v-model="settings.enable_security_txt" type="checkbox" />
        <span class="ar-toggle__track" aria-hidden="true"></span>
        <span class="ar-toggle__text">
          <strong>Generate security.txt</strong>
          <small>Publish a vulnerability-disclosure contact for security researchers and agents.</small>
        </span>
      </label>

      <div v-show="settings.enable_security_txt">
        <p v-if="!hasSecurityContact" class="ar-card__note ar-warn">
          Add at least one contact below (or a public contact email under Identity) —
          RFC 9116 requires one, so until then nothing is served.
        </p>

        <div class="ar-field">
          <label>Security contacts</label>
          <TagInput v-model="security.contacts" placeholder="security@example.com, https://… or tel:+…" />
          <small class="ar-field__hint">
            Add an email, a report-form URL, or a phone number, then press Enter.
            <span v-if="identity.contact_email">Your Identity email <code>{{ identity.contact_email }}</code> is reused here automatically as the first contact.</span>
            <span v-else>A public contact email set under Identity is reused here automatically.</span>
          </small>
        </div>

        <div class="ar-grid">
          <div class="ar-field">
            <label for="ar-sec-policy">Disclosure policy URL <span class="ar-field__tag">optional</span></label>
            <input id="ar-sec-policy" v-model="security.policy" type="url" class="ar-input" placeholder="https://example.com/security-policy" />
          </div>
          <div class="ar-field">
            <label for="ar-sec-ack">Acknowledgments URL <span class="ar-field__tag">optional</span></label>
            <input id="ar-sec-ack" v-model="security.acknowledgments" type="url" class="ar-input" placeholder="https://example.com/hall-of-fame" />
          </div>
          <div class="ar-field">
            <label for="ar-sec-enc">Encryption key URL <span class="ar-field__tag">optional</span></label>
            <input id="ar-sec-enc" v-model="security.encryption" type="url" class="ar-input" placeholder="https://example.com/pgp-key.txt" />
          </div>
          <div class="ar-field">
            <label for="ar-sec-hiring">Security hiring URL <span class="ar-field__tag">optional</span></label>
            <input id="ar-sec-hiring" v-model="security.hiring" type="url" class="ar-input" placeholder="https://example.com/jobs/security" />
          </div>
        </div>

        <div class="ar-grid">
          <div class="ar-field">
            <label for="ar-sec-langs">Preferred languages <span class="ar-field__tag">optional</span></label>
            <input id="ar-sec-langs" v-model="security.preferred_languages" type="text" class="ar-input" placeholder="en, fr" />
            <small class="ar-field__hint">Comma-separated; defaults to your site language.</small>
          </div>
          <div class="ar-field ar-field--inline">
            <label for="ar-sec-exp">Expires after (days)</label>
            <input id="ar-sec-exp" v-model.number="security.expires_days" type="number" min="1" max="365" class="ar-input ar-input--sm" />
          </div>
        </div>

        <p class="ar-card__note">
          <strong>Gap-filling, never override.</strong>
          A real <code>/.well-known/security.txt</code> file or another plugin's document always wins;
          this generator only fills the gap.
          <span v-if="hasSecurityContact">
            Live at <a :href="securityTxtUrl" target="_blank" rel="noopener"><code>/.well-known/security.txt</code></a>,
            and indexed in <code>discovery.json</code> under <code>trust</code>.
          </span>
        </p>
      </div>
    </section>

    <!-- Features ------------------------------------------------------- -->
    <section id="ar-sec-features" class="ar-card">
      <h2 class="ar-card__title">Features</h2>
      <p class="ar-card__lead">Toggle each agent-readiness signal.</p>

      <label v-for="f in features" :key="f.key" class="ar-toggle">
        <input v-model="settings[f.key]" type="checkbox" />
        <span class="ar-toggle__track" aria-hidden="true"></span>
        <span class="ar-toggle__text">
          <strong>{{ f.label }}</strong>
          <small>{{ f.hint }}</small>
        </span>
      </label>

      <div class="ar-field ar-field--inline">
        <label for="ar-full-count">Posts in /llms-full.txt</label>
        <input
          id="ar-full-count"
          v-model.number="settings.llms_full_posts"
          type="number"
          min="1"
          max="500"
          class="ar-input ar-input--sm"
        />
      </div>
      <small v-if="fullSizeNote" class="ar-field__hint" :class="{ 'ar-warn': fullSizeNote.warn }">{{ fullSizeNote.text }}</small>
    </section>

    <!-- Crawler policy ------------------------------------------------- -->
    <section id="ar-sec-ai" class="ar-card">
      <h2 class="ar-card__title">Crawler policy</h2>
      <p class="ar-card__lead">
        Decide what AI assistants may do with your content. Search and citation stay on by default;
        you can refuse training.
      </p>

      <div class="ar-field">
        <label>Usage declaration <span class="ar-field__tag">Content-Signal</span></label>
        <div class="ar-signals">
          <label v-for="row in signalRows" :key="row.key" class="ar-toggle">
            <input v-model="signal[row.key]" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>{{ row.label }}</strong>
              <small>{{ row.hint }}</small>
            </span>
            <span class="ar-signal-state" :class="signal[row.key] ? 'is-allow' : 'is-block'">
              {{ signal[row.key] ? 'Allowed' : 'Blocked' }}
            </span>
          </label>
        </div>
        <small class="ar-field__hint">Emitted in robots.txt as <code>{{ signalPreview }}</code></small>
      </div>

      <div class="ar-field">
        <!-- Allowed: an explicit list to refuse specific crawlers. -->
        <label v-if="signal.ai_train">Block specific crawlers <span class="ar-field__tag">optional</span></label>
        <!-- Blocked: no specifics — just a one-line note. -->
        <small v-else class="ar-field__hint">
          {{ blockedCount
            ? 'Known AI-training crawlers are also hard-blocked by name for stronger enforcement.'
            : 'No crawlers are hard-blocked — relying on the ai-train=no signal alone.' }}
        </small>

        <div v-show="signal.ai_train" class="ar-enforce-body">
          <TagInput v-model="settings.blocked_trainers" placeholder="Add a custom user-agent" />
          <div v-if="trainerSuggestions.length" class="ar-suggest">
            <span class="ar-suggest__label">Add a known crawler</span>
            <button
              v-for="t in trainerSuggestions"
              :key="t"
              type="button"
              class="ar-suggest__chip"
              @click="addTrainer(t)"
            >+ {{ t }}</button>
          </div>
          <small class="ar-field__hint">
            Refused by name with <code>Disallow: /</code>.
            <span v-if="signal.ai_train">Training is Allowed, so only the crawlers you list here are blocked.</span>
            <button v-if="!isDefaultTrainers" type="button" class="ar-linkbtn" @click="resetTrainers">Reset to defaults</button>
          </small>
        </div>
      </div>

      <!-- Opt-out channels — only relevant when reserving (training blocked) -->
      <div class="ar-field">
        <div v-if="reservedSignal" class="ar-channels-panel">
          <div class="ar-channels-panel__head">
            Published beyond robots.txt <span class="ar-field__tag">stronger signals</span>
          </div>
          <p class="ar-channels-panel__lead">{{ channelsSummary }}</p>
          <p class="ar-channels-panel__note">
            The opt-out file is site-wide — it can’t block individual bots. Per-bot blocks live in the
            crawler list above (robots.txt), and in scanner blocking below for a hard 403.
          </p>

          <details>
            <summary class="ar-linkbtn">Publishing channels</summary>
            <p class="ar-field__hint">
              Each channel states the same “no AI training” choice in a different place. They’re on by
              default — turn one off only if you don’t want to publish through that channel.
            </p>

            <label class="ar-toggle">
              <input v-model="settings.enable_ai_header" type="checkbox" />
              <span class="ar-toggle__track" aria-hidden="true"></span>
              <span class="ar-toggle__text">
                <strong>Response header</strong>
                <small>Attaches an invisible “do not train” tag to every page your site serves, so an AI crawler gets the signal directly — even if it never reads your robots.txt.</small>
              </span>
            </label>

            <label class="ar-toggle">
              <input v-model="settings.enable_tdmrep" type="checkbox" />
              <span class="ar-toggle__track" aria-hidden="true"></span>
              <span class="ar-toggle__text">
                <strong>Opt-out file</strong>
                <small>Publishes a small standard file that formally declares your content off-limits for AI training — the machine-readable format AI companies check, and the one that lines up with EU text-and-data-mining rules. <a :href="tdmrepUrl" target="_blank" rel="noopener">View the file</a>.</small>
              </span>
            </label>

            <label class="ar-toggle">
              <input v-model="settings.ai_noai_header" type="checkbox" />
              <span class="ar-toggle__track" aria-hidden="true"></span>
              <span class="ar-toggle__text">
                <strong>Also send a “noai” header</strong>
                <small>An extra page header asking AI tools not to use your text or images. It isn’t an official standard — only some platforms honor it — so treat it as a harmless bonus signal on top of the two above.</small>
              </span>
            </label>

            <div class="ar-field">
              <label for="ar-tdm-policy">AI-usage policy URL <span class="ar-field__tag">optional</span></label>
              <input id="ar-tdm-policy" v-model="settings.tdm_policy_url" type="url" class="ar-input" placeholder="https://example.com/ai-policy" />
              <small class="ar-field__hint">
                A link to your own page spelling out your AI terms — e.g. “training allowed only with a
                licence; email us.” When set, the header and opt-out file point AI companies to it so they
                know your conditions or how to ask permission. Leave it blank for a plain “no” — your
                opt-out still works exactly the same without it.
              </small>
            </div>
          </details>
        </div>

        <p v-else class="ar-card__note">
          AI training is allowed, so no opt-out signals are published — on the web, no signal already
          means “allowed”. To opt out, turn off <strong>Allow AI training</strong> above: that publishes a
          no-training signal in robots.txt, a response header, and <code>/.well-known/tdmrep.json</code> at
          once. To keep specific crawlers out while staying open, list them under
          <strong>Block specific crawlers</strong> above.
        </p>
      </div>
    </section>

    <!-- Block scanners & scrapers -------------------------------------- -->
    <section id="ar-sec-blocking" class="ar-card">
      <h2 class="ar-card__title">Block scanners &amp; scrapers <span class="ar-field__tag">optional</span></h2>
      <p class="ar-card__lead">
        The crawler rules above are a polite request — well-behaved bots honour them. This is the
        hard stop: the bots below are turned away from your AI files instead of being served. Off by default.
      </p>

      <label class="ar-toggle">
        <input v-model="settings.block_agents" type="checkbox" />
        <span class="ar-toggle__track" aria-hidden="true"></span>
        <span class="ar-toggle__text">
          <strong>Deny blocked agents</strong>
          <small>Turn the bots in the list below away — they get nothing instead of your <code>llms.txt</code>, <code>discovery.json</code> and other AI files.</small>
        </span>
      </label>

      <div v-show="settings.block_agents" class="ar-enforce-body">
        <label class="ar-toggle">
          <input v-model="settings.block_spoofed" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Auto-deny spoofed / legacy-device agents</strong>
            <small>Turn away bots that disguise themselves as ancient phones (old Nokia/BlackBerry handsets) — a classic scanner trick. These show up as “Likely spoof/scanner” in your activity log.</small>
          </span>
        </label>

        <div class="ar-field">
          <label>Blocked user-agents <span class="ar-field__tag">optional</span></label>
          <TagInput v-model="settings.blocked_agents" placeholder="Add a user-agent to deny" />
          <div v-if="scannerSuggestions.length" class="ar-suggest">
            <span class="ar-suggest__label">Add a known scanner</span>
            <button
              v-for="s in scannerSuggestions"
              :key="s"
              type="button"
              class="ar-suggest__chip"
              @click="addScanner(s)"
            >+ {{ s }}</button>
          </div>
          <p v-if="riskyBlockedAgents.length" class="ar-card__note ar-warn">
            ⚠ Broad {{ riskyBlockedAgents.length === 1 ? 'entry' : 'entries' }}:
            <code>{{ riskyBlockedAgents.join(', ') }}</code> —
            {{ riskyBlockedAgents.length === 1 ? 'this is' : 'these are' }} broad enough to also hit real browsers or AI crawlers you may want.
            Major search engines (Googlebot, Bingbot…) are always allowed regardless, but consider something more specific.
          </p>
          <small class="ar-field__hint">
            Type part of a bot's name — capitalisation doesn't matter, and a fragment is enough
            (<code>SemrushBot</code> also catches <code>SemrushBot/7~bl</code>). Use <code>*</code> to stand in for
            anything (<code>Semrush*</code>, <code>*bot/2*</code>), or wrap a pattern in <code>/…/</code> for
            <strong>advanced matching</strong> (<code>/semrushbot\/\d+/</code>).
          </small>
        </div>

        <p class="ar-card__note">
          <strong>Safe by design.</strong>
          This only affects the AI files this plugin makes (like <code>llms.txt</code> and <code>discovery.json</code>).
          Your normal pages, your real files on disk, and anything your SSL certificate needs keep working as usual.
        </p>
      </div>

      <div v-if="(settings.allowed_agents || []).length" class="ar-field ar-field--allow">
        <label>Always allowed <span class="ar-field__tag">trusted</span></label>
        <TagInput v-model="settings.allowed_agents" placeholder="Add a user-agent to trust" />
        <small class="ar-field__hint">
          Clients you marked <strong>Allow</strong> in the review list — never blocked and never flagged
          again (the same treatment as Googlebot). Remove one to start flagging it again.
        </small>
      </div>
    </section>

    <!-- Content types -------------------------------------------------- -->
    <section v-if="postTypes.length" class="ar-card">
      <h2 class="ar-card__title">Content types</h2>
      <p class="ar-card__lead">
        Pick which kinds of content AI assistants can read. Posts and pages are usually enough;
        add products or other types if you want them included.
      </p>
      <div class="ar-types-bar">
        <input
          v-if="postTypes.length > 8"
          v-model="typeQuery"
          type="search"
          class="ar-input ar-types-search"
          placeholder="Filter types…"
        />
        <div class="ar-types-meta">
          <span class="ar-types-count">{{ selectedTypeCount }} / {{ postTypes.length }} enabled</span>
          <button type="button" class="ar-linkbtn" @click="selectAllTypes">Select all</button>
        </div>
      </div>

      <div class="ar-types-scroll">
        <div class="ar-types-grid">
          <label
            v-for="pt in filteredPostTypes"
            :key="pt.slug"
            class="ar-type"
            :class="{ 'is-on': isTypeOn(pt.slug) }"
          >
            <input type="checkbox" :checked="isTypeOn(pt.slug)" @change="toggleType(pt.slug)" />
            <span class="ar-type__check" aria-hidden="true"></span>
            <span class="ar-type__body">
              <span class="ar-type__label">{{ pt.label }}</span>
              <span class="ar-type__meta">
                <span v-if="pt.source" class="ar-type__src">{{ pt.source }}</span>
                <code>{{ pt.slug }}</code>
              </span>
            </span>
          </label>
          <p v-if="!filteredPostTypes.length" class="ar-types-empty">No types match “{{ typeQuery }}”.</p>
        </div>
      </div>
      <p class="ar-card__note">
        <strong>Curates what's advertised — not an access control.</strong>
        Unticking a type removes it from llms.txt, schema and discovery, but your
        WordPress REST API stays public: <code>/wp-json/wp/v2</code> remains reachable regardless.
      </p>
    </section>

    <!-- Discovery: REST APIs (opt-in) ---------------------------------- -->
    <section v-if="restNamespacesDetected.length" class="ar-card">
      <h2 class="ar-card__title">Discovery — REST APIs</h2>
      <p class="ar-card__lead">
        REST APIs detected on your site. Publish the ones agents should use; internal or admin
        APIs (analytics, telemetry, admin) are best left off. Nothing is published unless you tick it.
      </p>
      <div class="ar-types-bar">
        <input
          v-if="restNamespacesDetected.length > 8"
          v-model="nsQuery"
          type="search"
          class="ar-input ar-types-search"
          placeholder="Filter APIs…"
        />
        <div class="ar-types-meta">
          <span class="ar-types-count">{{ publishedNsCount }} / {{ restNamespacesDetected.length }} published</span>
        </div>
      </div>
      <div class="ar-types-scroll">
        <div class="ar-types-grid">
          <label
            v-for="ns in filteredNamespaces"
            :key="ns"
            class="ar-type"
            :class="{ 'is-on': isNsOn(ns) }"
          >
            <input type="checkbox" :checked="isNsOn(ns)" @change="toggleNs(ns)" />
            <span class="ar-type__check" aria-hidden="true"></span>
            <span class="ar-type__body">
              <span class="ar-type__label">{{ ns }}</span>
              <span class="ar-type__meta"><code>/wp-json/{{ ns }}</code></span>
            </span>
          </label>
          <p v-if="!filteredNamespaces.length" class="ar-types-empty">No APIs match “{{ nsQuery }}”.</p>
        </div>
      </div>
      <p class="ar-card__note">
        <strong>Publishing advertises an API — it doesn't open or close it.</strong>
        Ticking one lists it in discovery so agents prefer it; leaving it off just hides it from
        the map. Either way the route is exactly as reachable as WordPress already makes it.
      </p>
    </section>

    <!-- Provider integrations ------------------------------------------ -->
    <section v-if="providerResources.length" class="ar-card">
      <h2 class="ar-card__title">Provider integrations</h2>
      <p class="ar-card__lead">
        Resources that installed plugins declared for agents. Each is <strong>published by default</strong> —
        switch off any you'd rather not advertise. You decide whether it's listed; the plugin decides what it says.
      </p>

      <label v-for="r in providerResources" :key="r.id" class="ar-toggle ar-toggle--rich">
        <input type="checkbox" :checked="isPublished(r.id)" @change="togglePublish(r.id)" />
        <span class="ar-toggle__track" aria-hidden="true"></span>
        <span class="ar-toggle__text">
          <strong>{{ r.title }}</strong>
          <small class="ar-prov-meta">
            <code>{{ r.type }}</code>
            <span v-if="r.provider" class="ar-prov">{{ providerLabel(r.provider) }}</span>
            <span v-if="r.capabilities && r.capabilities.length">{{ r.capabilities.length }} capabilit{{ r.capabilities.length === 1 ? 'y' : 'ies' }}</span>
            <span v-if="r.hasAgent">agent card</span>
          </small>
        </span>
        <span class="ar-signal-state" :class="isPublished(r.id) ? 'is-allow' : 'is-block'">
          {{ isPublished(r.id) ? 'Published' : 'Suppressed' }}
        </span>
      </label>

      <p class="ar-card__note">
        <strong>This controls listing, not access.</strong>
        Suppressing removes a resource from discovery, the agent card and the REST mirror — but the
        plugin and its endpoints keep working exactly as before. It changes what agents are told, not what the site does.
      </p>
    </section>

    <!-- Advanced / Developer (collapsed; Authenticated API lives here) -- -->
    <section class="ar-card ar-card--muted ar-adv">
      <button
        type="button"
        class="ar-adv__toggle ar-reset"
        :aria-expanded="showAdvanced ? 'true' : 'false'"
        aria-controls="ar-adv-body"
        @click="showAdvanced = !showAdvanced"
      >
        <span class="ar-reset__text">
          <strong>Advanced <span class="ar-field__tag">developer</span></strong>
          <small>Authenticated-API discovery for sites with a login-protected API. Most sites don’t need this.</small>
        </span>
        <svg class="ar-adv__chev" :class="{ 'is-open': showAdvanced }" viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
      </button>

      <div v-if="showAdvanced" id="ar-adv-body" class="ar-adv__body">
        <h3 class="ar-adv__title">Authenticated API <span class="ar-field__tag">optional</span></h3>
        <p class="ar-card__lead">
          Only for a site whose API apps or AI agents <strong>log into</strong> — a headless build or app backend that uses OAuth.
          <strong>Most sites should leave this blank.</strong> And if your API already publishes its own login metadata,
          Agentimus finds it automatically, so there’s nothing to enter here.
        </p>
        <div class="ar-field">
          <label for="ar-oauth-as">Login (authorization) server address</label>
          <div class="ar-oauth">
            <input id="ar-oauth-as" v-model="settings.oauth_auth_server" type="url" class="ar-input" placeholder="https://auth.example.com" />
            <button type="button" class="ar-btn ar-btn--ghost ar-oauth__check" :disabled="oauthChecking" @click="checkOauth">
              {{ oauthChecking ? 'Checking…' : 'Check' }}
            </button>
          </div>
          <p class="ar-field__hint">
            This is where apps sign in — your API platform shows it; you don’t make it up. Agentimus then publishes it at
            <code>/.well-known/oauth-protected-resource</code> so agents can find the login. <strong>Check</strong> confirms it’s live on your site.
          </p>
          <p v-if="oauthCheck" class="ar-oauth__msg" :class="oauthCheckClass" role="status" aria-live="polite">{{ oauthCheck.msg }}</p>
        </div>
      </div>
    </section>

    <!-- Endpoints (hidden when the rail shows them; returns on narrow screens) -->
    <section class="ar-card ar-card--muted ar-card--endpoints">
      <h2 class="ar-card__title">Live endpoints</h2>
      <ul class="ar-links">
        <li><a :href="endpoints.llms" target="_blank" rel="noopener">{{ endpoints.llms }}</a></li>
        <li><a :href="endpoints.llmsFull" target="_blank" rel="noopener">{{ endpoints.llmsFull }}</a></li>
        <li><a :href="endpoints.robots" target="_blank" rel="noopener">{{ endpoints.robots }}</a></li>
      </ul>
    </section>

    <!-- Manage setup: a guided (non-destructive) review and a destructive
         reset, grouped in ONE block so they read as related lifecycle actions
         (and share one background). The red button carries the danger cue. -->
    <section class="ar-card ar-card--muted ar-manage">
      <div class="ar-reset">
        <div class="ar-reset__text">
          <strong>Setup guide</strong>
          <small>Re-open the guided setup with your current answers filled in — review or fine-tune who you are and what AI assistants can read. <em>Nothing is reset.</em></small>
        </div>
        <button type="button" class="ar-btn ar-btn--ghost" @click="$emit('reopen-wizard')">Review setup</button>
      </div>

      <hr class="ar-manage__sep" />

      <div class="ar-reset">
        <div class="ar-reset__text">
          <strong>Reset to defaults</strong>
          <small>Wipe every setting back to the recommended factory defaults. This also <em>clears your identity profile</em> (name, about, links) and can’t be undone.</small>
        </div>
        <button type="button" class="ar-btn ar-btn--danger" :disabled="resetting" @click="openReset">
          {{ resetting ? 'Resetting…' : 'Reset' }}
        </button>
      </div>
    </section>

    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="showReset" class="ar-modal" @click.self="closeReset">
          <div
            ref="resetDialog"
            class="ar-modal__panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-reset-title"
            tabindex="-1"
            @keydown.esc="closeReset"
          >
            <div class="ar-modal__head">
              <h2 id="ar-reset-title" class="ar-modal__title">Reset to defaults?</h2>
              <p class="ar-modal__lead">
                Every setting returns to the recommended factory defaults below. Your identity
                profile — name, about, expertise and links — is cleared. This can’t be undone.
              </p>
            </div>

            <div class="ar-modal__body">
              <div ref="resetBody" class="ar-modal__scroll" @scroll="onBodyScroll">
                <div class="ar-preview">
              <div class="ar-preview__group">
                <p class="ar-preview__label">Features</p>
                <ul class="ar-preview__list">
                  <li v-for="f in resetPreview.features" :key="f.label">
                    <span>{{ f.label }}</span>
                    <span class="ar-preview__state" :class="f.on ? 'is-on' : 'is-off'">{{ f.on ? 'On' : 'Off' }}</span>
                  </li>
                </ul>
              </div>

              <div class="ar-preview__group">
                <p class="ar-preview__label">Crawler policy</p>
                <ul class="ar-preview__list">
                  <li v-for="s in resetPreview.signals" :key="s.label">
                    <span>{{ s.label }}</span>
                    <span class="ar-preview__state" :class="s.allow ? 'is-on' : 'is-off'">{{ s.allow ? 'Allowed' : 'Refused' }}</span>
                  </li>
                  <li>
                    <span>Blocked AI trainers</span>
                    <span class="ar-preview__muted">{{ resetPreview.trainers }} crawlers</span>
                  </li>
                </ul>
              </div>

              <div class="ar-preview__group">
                <p class="ar-preview__label">Content</p>
                <ul class="ar-preview__list">
                  <li><span>Content types indexed</span><span class="ar-preview__muted">{{ resetPreview.types }}</span></li>
                  <li><span>Posts in /llms-full.txt</span><span class="ar-preview__muted">{{ resetPreview.fullPosts }}</span></li>
                  <li><span>Identity profile</span><span class="ar-preview__muted">cleared</span></li>
                </ul>
              </div>
                </div>
              </div>
              <div class="ar-modal__fade" :class="{ 'is-visible': scrollMore }">
                <button type="button" class="ar-modal__fade-btn" :disabled="!scrollMore" aria-label="Scroll down for more" @click="scrollResetBody">
                  <svg viewBox="0 0 16 16" class="ar-modal__chev" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
                </button>
              </div>
            </div>

            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" @click="closeReset">Cancel</button>
              <button type="button" class="ar-btn ar-btn--danger" :disabled="resetting" @click="doReset">
                {{ resetting ? 'Resetting…' : 'Reset to defaults' }}
              </button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>
  </form>
</template>
