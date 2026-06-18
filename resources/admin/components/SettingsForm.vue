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
    endpoints: { type: Object, default: () => ({}) },
    restNamespacesDetected: { type: Array, default: () => [] },
    providerResources: { type: Array, default: () => [] },
    profileDirty: { type: Boolean, default: false },
    profileSaving: { type: Boolean, default: false },
    profileSaved: { type: Boolean, default: false },
    resetting: { type: Boolean, default: false },
    defaults: { type: Object, default: () => ({}) },
    llmsFullEstimate: { type: Object, default: () => ({}) },
  },
  emits: ['save-profile', 'reset', 'reopen-wizard'],
  data() {
    return { typeQuery: '', nsQuery: '', showReset: false, scrollMore: false };
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
        };
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
        { key: 'enable_activity', label: 'Visit log', hint: 'Records which AI assistants fetch your AI files. Local-only, no IP addresses.' },
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
    doReset() {
      this.showReset = false;
      this.$emit('reset');
    },
    addTrainer(name) {
      if (!Array.isArray(this.settings.blocked_trainers)) this.settings.blocked_trainers = [];
      if (!this.settings.blocked_trainers.includes(name)) this.settings.blocked_trainers.push(name);
    },
    resetTrainers() {
      this.settings.blocked_trainers = [...this.knownTrainers];
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
            Emails, <code>https://</code> report forms or <code>tel:</code> numbers; press Enter to add.
            <span v-if="identity.contact_email">Your Identity email <code>{{ identity.contact_email }}</code> is used automatically as the first contact.</span>
            <span v-else>The public contact email under Identity, if set, is reused here automatically.</span>
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
    <section class="ar-card">
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

    <!-- Authenticated API (RFC 9728, optional) ------------------------- -->
    <section class="ar-card">
      <h2 class="ar-card__title">Authenticated API <span class="ar-field__tag">optional</span></h2>
      <p class="ar-card__lead">
        If your site exposes an API protected by OAuth, name its authorization server and Agentomatic
        publishes RFC 9728 metadata at <code>/.well-known/oauth-protected-resource</code> so agents can
        find the auth flow. Leave empty for a content site.
      </p>
      <div class="ar-field">
        <label for="ar-oauth-as">OAuth authorization server URL</label>
        <input id="ar-oauth-as" v-model="settings.oauth_auth_server" type="url" class="ar-input" placeholder="https://auth.example.com" />
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

    <!-- Setup guide --------------------------------------------------- -->
    <section class="ar-card ar-card--muted">
      <div class="ar-reset">
        <div class="ar-reset__text">
          <strong>Setup guide</strong>
          <small>Re-run the short guided setup to review who you are and what AI assistants can read.</small>
        </div>
        <button type="button" class="ar-btn ar-btn--ghost" @click="$emit('reopen-wizard')">Run setup again</button>
      </div>
    </section>

    <!-- Reset ---------------------------------------------------------- -->
    <section class="ar-card ar-card--reset">
      <div class="ar-reset">
        <div class="ar-reset__text">
          <strong>Reset to defaults</strong>
          <small>Restore every setting — identity, crawler policy and feature toggles — to the recommended factory defaults.</small>
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
              <div class="ar-modal__fade" :class="{ 'is-visible': scrollMore }" aria-hidden="true">
                <svg viewBox="0 0 16 16" class="ar-modal__chev" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6l4 4 4-4" /></svg>
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
