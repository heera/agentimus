<script>
import TagInput from './TagInput.vue';
import SelectMenu from './SelectMenu.vue';
import IpChecker from './IpChecker.vue';
import ClientManager from './ClientManager.vue';
import { bindDocEsc } from '../js/docEsc.js';
import { confirm } from '../js/confirm.js';
import { uaTip } from '../js/uaTip.js';
import { formatDate, formatTime } from '../js/wpDate.js';

import { groupIcon } from '../js/groupIcons.js';
import CloudflareCard from './CloudflareCard.vue';
import BingCard from './BingCard.vue';
import GoogleCard from './GoogleCard.vue';
import McpServerCard from './McpServerCard.vue';

export default {
  name: 'SettingsForm',
  components: { TagInput, IpChecker, SelectMenu, ClientManager, CloudflareCard, BingCard, GoogleCard, McpServerCard },
  // The styled hover bubble (the admin's ONLY tooltip — native title="…" is banned:
  // it can't be themed, appears late, and truncates). Used by the Verified-bots chips.
  mixins: [uaTip],
  props: {
    settings: { type: Object, required: true },
    // Reveal signal (the form stays mounted behind v-show). Arriving re-fetches the
    // token's last-used line, which otherwise sits frozen at its page-load state.
    active: { type: Boolean, default: false },
    retentionChoices: { type: Array, default: () => [7, 14, 30, 60, 90, 180, 365] },
    maxRowsChoices: { type: Array, default: () => [10000, 25000, 50000, 100000, 250000] },
    busy: { type: Boolean, default: false },
    api: { type: Object, default: null },
    indexnowKeyUrl: { type: String, default: '' }, // The /{key}.txt address, for the IndexNow hint.
    entityTypes: { type: Array, default: () => ['Person', 'Organization', 'LocalBusiness', 'Store'] },
    postTypes: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] }, // {id, name} for the evergreen picker.
    knownTrainers: { type: Array, default: () => [] },
    knownScanners: { type: Array, default: () => [] },
    knownAllowed: { type: Array, default: () => [] },
    defaultAllowed: { type: Array, default: () => [] },
    verifierBuiltins: { type: Array, default: () => [] }, // Built-in verified-bot registry entries.
    socialImageUrl: { type: String, default: '' }, // Thumbnail of the saved default share image (the setting holds only the ID).
    adminEmail: { type: String, default: '' }, // The digest's fallback recipient, shown as the field's placeholder — never prefilled as a value (a saved copy would stop following admin-email changes).
    webmcpTools: { type: Array, default: () => [] },
    mcpServer: { type: Object, default: () => ({}) }, // {endpoint, abilitiesAvailable, adapterAvailable} for the MCP-server card.
    debug: { type: Object, default: () => ({}) },
    endpoints: { type: Object, default: () => ({}) },
    restNamespacesDetected: { type: Array, default: () => [] },
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
  emits: ['save-profile', 'save-services', 'reset', 'reopen-wizard', 'clients-changed', 'flash', 'navigate'],
  data() {
    return {
      // Which settings group the sub-nav is showing. One group is visible at a
      // time so the page reads as a few focused screens, not one long scroll.
      // Identity leads — the highest-signal section, and where a new owner starts.
      group: 'identity',
      clientManagerOpen: false,
      // The default-share-image picker: the live thumbnail (seeded from the
      // bootstrap, replaced on pick) and the wp.media frame, built lazily.
      socialThumb: this.socialImageUrl,
      socialFrame: null,
      // The "add a verified bot" mini-form (Verified-bots registry manager).
      verAdd: { label: '', ua: '', domains: '', url: '' },
      verAddOpen: false,
      verAddBusy: false, // Probing the ranges URL server-side before accepting it.
      verAddError: '', // The probe's verdict when the URL didn't check out.
      digestTestSending: false, // The weekly-email "send a test" button's in-flight lock.
      typeQuery: '',
      catQuery: '',
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
    if (this._unEscReset) this._unEscReset();
  },
  watch: {
    // Document-level Esc while the reset dialog is open — the panel-scoped
    // handler dies as soon as focus leaves the panel (e.g. a backdrop click).
    showReset(open) {
      if (this._unEscReset) this._unEscReset();
      this._unEscReset = open ? bindDocEsc(() => this.closeReset()) : null;
    },
  },
  computed: {
    // The Verified-bots registry as one list: built-ins (toggleable) then the owner's
    // custom entries (removable). Mirrors VerifierRegistry::entries() server-side.
    verifierRows() {
      const disabled = this.settings.verifier_disabled || [];
      const builtins = this.verifierBuiltins.map((e) => ({ ...e, disabled: disabled.includes(e.token) }));
      // _ci = index into settings.verifier_custom: a just-added entry has no token
      // until the server assigns one on save, so removal keys on position.
      const customs = (this.settings.verifier_custom || []).map((e, i) => ({ ...e, builtin: false, disabled: false, _ci: i }));
      return builtins.concat(customs);
    },
    // The add-form is submittable only when it would survive the server's sanitiser:
    // a name, a UA needle of 3+ chars, and at least one verification source (https URL
    // and/or a domain suffix).
    // The comma-split domain pieces, scheme-stripped — shared by readiness + the
    // missing-piece message so the two can never disagree.
    verAddDomainPieces() {
      return this.verAdd.domains.split(',').map((d) => d.trim().replace(/^https?:\/\//, '')).filter(Boolean);
    },
    verAddReady() {
      const a = this.verAdd;
      const pieces = this.verAddDomainPieces;
      // Mirror the server's sanitiser: a "domain" without a dot is dropped there, so it
      // must not count as a source here — or the entry would silently lose it on save.
      const hasDomains = pieces.length > 0 && pieces.every((d) => d.includes('.'));
      const url = a.url.trim();
      const hasUrl = /^https:\/\/.+/.test(url);
      return a.label.trim() !== '' && a.ua.trim().length >= 3 && (hasDomains || hasUrl) && (url === '' || hasUrl) && (pieces.length === 0 || hasDomains);
    },
    // Why the Add button is disabled, named specifically — a dead button with the
    // reason buried mid-paragraph is a dead end. '' while the form is untouched
    // (no nagging an empty form) or complete.
    verAddMissing() {
      const a = this.verAdd;
      const url = a.url.trim();
      if (!a.label.trim() && !a.ua.trim() && !a.domains.trim() && !url) return '';
      if (!a.label.trim()) return 'Give it a name.';
      if (a.ua.trim().length < 3) return 'Enter its User-Agent token — at least 3 characters.';
      if (url && !/^https:\/\/.+/.test(url)) return 'The ranges URL must start with https://.';
      const pieces = this.verAddDomainPieces;
      if (pieces.length && pieces.some((d) => !d.includes('.'))) return 'A reverse-DNS domain needs a dot — e.g. crawl.newbot.example.';
      if (!pieces.length && !url) return 'Add at least one source — a reverse-DNS domain and/or a published IP-ranges URL.';
      return '';
    },
    retentionOptions() {
      return this.retentionChoices.map((d) => ({
        value: d,
        label: d === 365 ? '1 year' : d % 30 === 0 && d >= 60 ? `${d / 30} months` : `${d} days`,
      }));
    },
    // The weekly email's send slot. Day names are the app's English prose; the hour
    // labels render through the site's own time format (wpDate), so "8:00" vs
    // "8:00 am" follows Settings → General.
    digestDayOptions() {
      return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((label, i) => ({
        value: i + 1,
        label,
      }));
    },
    digestHourOptions() {
      // Any fixed date works: only the clock face is formatted, on the UTC dial.
      return Array.from({ length: 24 }, (_, h) => ({
        value: h,
        label: formatTime(new Date(Date.UTC(2026, 0, 5, h, 0, 0)), true),
      }));
    },
    /**
     * Each cap carries what it actually costs on disk. Measured on a real table: ~124 bytes of
     * payload per row (the User-Agent averages 83 chars) plus ~143 bytes across the four
     * secondary indexes — call it 300–700 B/row once InnoDB's page fill is accounted for. The
     * range is honest; a single number here would be a guess dressed as a fact.
     */
    maxRowsOptions() {
      const mb = (rows, bytes) => (rows * bytes) / 1048576;
      const fmt = (n) => (n < 10 ? n.toFixed(1) : Math.round(n));
      return this.maxRowsChoices.map((rows) => ({
        value: rows,
        label: `${rows.toLocaleString()} rows · ≈ ${fmt(mb(rows, 300))}–${fmt(mb(rows, 700))} MB`,
      }));
    },
    // ---- MCP connect helper -------------------------------------------------
    // Every client below gets a config the user can paste WITHOUT editing —
    // endpoint, username and the encoded login are all filled in here, in the
    // browser. Hand-computing base64 was the step users actually failed on
    // (and the shell trick for it doesn't exist on Windows).
    // The connection-token cards. Each says how one client is added; the ones
    // needing OAuth (Wave 2) are listed as coming, not hidden — the owner should
    // see the whole map. `cmd` cards copy a line; `deep` cards open the editor.
    // ------------------------------------------------------------------------
    // The settings page is split into a few labelled groups, shown one at a time
    // via the sub-nav. Order runs broad → specific: what you publish, who you
    // are, what bots may do, then the rarely-touched developer/maintenance bits.
    groups() {
      return [
        { key: 'identity', label: 'Identity', hint: 'Who owns this site' },
        { key: 'discovery', label: 'Discovery', hint: 'Files & data AI can read' },
        { key: 'access', label: 'AI Access', hint: 'What crawlers may do — and who to block' },
        { key: 'exposure', label: 'Exposure', hint: 'Limit what your site reveals to crawlers & scanners' },
        { key: 'sources', label: 'Data Sources', hint: 'Outside services Agentimus reads from — optional, read-only' },
        { key: 'advanced', label: 'Advanced', hint: 'Trust, developer & maintenance' },
      ];
    },
    // One-line description of the group on screen, shown under the sub-nav.
    activeGroupHint() {
      const g = this.groups.find((x) => x.key === this.group);
      return g ? g.hint : '';
    },
    // The owner's own list, then the ones plugins offered — two groups rather
    // than one mixed grid. A border alone made the odd card out look like a
    // rendering glitch; a labelled group under a rule says what it is. Empty
    // groups drop out, so a site with no integrations never sees the heading.
    typeGroups() {
      return [
        { key: 'own', label: '', types: this.filteredPostTypes.filter((pt) => !pt.forced) },
        { key: 'plugin', label: 'Offered by Plugins', types: this.filteredPostTypes.filter((pt) => pt.forced) },
      ].filter((g) => g.types.length);
    },
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
    // Everything actually advertised — the owner's picks PLUS the plugin-offered
    // types they have not refused. Counting only post_types read "3 / 8" on a
    // site advertising four.
    selectedTypeCount() {
      return this.postTypes.filter((pt) => this.typeShowsOn(pt)).length;
    },
    // The read capabilities the CURRENT selection advertises — computed exactly like
    // the discovery adapter does (type REST bases first, then the union of their
    // public taxonomies' bases, deduped), so this preview and the Discovery hub can
    // never disagree. Types the payload marks non-advertising (no restBase) add nothing.
    // The types a plugin switched on, by name — for the one line under the grid
    // that explains the dashed cards.
    forcedTypes() {
      return (this.postTypes || []).filter((p) => p.forced).map((p) => p.label);
    },
    advertisedCapabilities() {
      const sel = Array.isArray(this.settings.post_types) ? this.settings.post_types : [];
      const bases = [];
      const taxes = [];
      this.postTypes.forEach((pt) => {
        if (!sel.includes(pt.slug) || !pt.restBase) return;
        bases.push(pt.restBase);
        (pt.taxonomies || []).forEach((t) => taxes.push(t));
      });
      const seen = {};
      return bases
        .concat(taxes)
        .map((b) => 'content.' + b + '.read')
        .filter((c) => (seen[c] ? false : (seen[c] = true)));
    },
    filteredCategories() {
      const q = this.catQuery.trim().toLowerCase();
      if (!q) return this.categories;
      return this.categories.filter((c) => c.name.toLowerCase().includes(q));
    },
    evergreenCount() {
      return Array.isArray(this.settings.evergreen_categories) ? this.settings.evergreen_categories.length : 0;
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
    // Which Ask-AI buttons the site's OWN policy hides, said right under the
    // toggle — a switched-on feature must not silently show fewer buttons than
    // the owner expects. Mirrors AskAi::permitted() (PHP stays the enforcer):
    // per assistant, the token that does the READING; a block on it hides the
    // button, an entry on the always-allow list un-hides it.
    askAiPolicyNote() {
      const tokens = {
        ChatGPT: 'ChatGPT-User',
        Claude: 'Claude-User',
        Perplexity: 'Perplexity-User',
        'Google AI Mode': 'Google-Extended',
        Grok: 'Grok',
      };
      const blocked = [...(this.settings.blocked_trainers || []), ...(this.settings.blocked_agents || [])];
      const allowed = this.settings.allowed_agents || [];
      const hit = (token, list) => list.some((e) => e && token.toLowerCase().includes(String(e).toLowerCase()));
      const hidden = Object.entries(tokens)
        .filter(([, token]) => !hit(token, allowed) && hit(token, blocked))
        .map(([name, token]) => `${name} (you block ${token})`);
      if (!hidden.length) return '';
      return ` Hidden by your own crawler rules right now: ${hidden.join(', ')} — that is the reader the assistant sends to fetch your page, so its button would only ever fail. Unblock it, or add it to your always-allowed list, to show the button.`;
    },
    features() {
      // Plain-language labels; the real filename/term stays in the hint so it's
      // always discoverable.
      return [
        { key: 'enable_llms_txt', label: 'AI page guide', hint: 'A plain map of your pages, topics and recent posts for assistants. (file: llms.txt)' },
        { key: 'enable_llms_full', label: 'Full text for AI', hint: 'Bundles your pages and recent posts into one document an assistant can read in a single pass. (file: llms-full.txt)' },
        { key: 'enable_markdown', label: 'Plain-text versions', hint: 'Lets assistants fetch a clean text version of any included page — add .md to its URL.' },
        { key: 'enable_robots', label: 'Crawler rules', hint: 'States your preferences to crawlers and blocks known AI-training crawlers by name. (file: robots.txt)' },
        { key: 'enable_schema', label: 'Rich data for search', hint: 'Adds structured data search engines and assistants understand (JSON-LD). Leave off if your SEO plugin already does this.' },
        { key: 'enable_page_checks', label: 'AI readability tips', hint: 'Adds an “AI Readability” panel in the post editor with per-page tips (headings, summary, thin content, image alt). Editor-only — nothing is shown to visitors.' },
        { key: 'enable_share_copy', label: 'Share drafts', hint: 'Adds a “Share” tab in the post editor with ready-to-post drafts for X, Facebook, LinkedIn, WhatsApp, Telegram and Reddit — written from the post itself, polished with AI per card if a provider is set up. Editor-only; nothing is ever posted for you.' },
        { key: 'enable_ask_ai', label: 'Ask-AI buttons', hint: 'A small “Ask AI about this post” row after each post: one click opens ChatGPT, Claude, Perplexity, Google AI Mode or Grok pre-filled with the post’s address — and the assistant’s visit shows up in your request log. Plain links, no script; nothing is sent until a reader clicks.' + this.askAiPolicyNote },
        // Citation checks deliberately have NO row here: their key hangs inside
        // their own door — the Citations tab on the always-on Visibility
        // screen — exactly like the data sources activate by their own setup.
        { key: 'enable_sitemap', label: 'Sitemap', hint: 'With no SEO plugin installed, Agentimus serves your sitemap — including the last-changed dates WordPress core’s own leaves out. With an SEO plugin, it steps aside and only fills the gap when nothing else provides one.' },
        { key: 'enable_changes', label: 'Change feed', hint: 'A JSON feed of recently added or updated pages so assistants can re-check just what changed, instead of re-reading your whole site. (file: agentimus-changes.json)' },
        { key: 'enable_signing', label: 'Verified responses', hint: 'Digitally signs your AI files so assistants can confirm they really came from your site and weren’t tampered with on the way. On by default; no setup needed.' },
      ];
    },
    exposureControls() {
      // Defensive toggles — all OFF by default. Plain label; the real
      // mechanism/file stays in the hint so it's discoverable but never required.
      return [
        {
          key: 'hide_user_enumeration',
          label: 'Hide your users & authors',
          hint: 'Stops anonymous visitors from listing your usernames — closes the author list at /wp-json/wp/v2/users, the ?author=1 trick, the users sitemap and the oEmbed author. Your admin and the editor are unaffected.',
        },
        {
          key: 'disable_author_archives',
          label: 'Disable author archive pages',
          hint: 'Turns your author pages (yoursite.com/author/…) into “not found” for visitors. Useful when authors aren’t a feature of your site — it removes one more place a username can show up.',
        },
        {
          key: 'hide_wp_version',
          label: 'Hide your WordPress version',
          hint: 'Removes the WordPress version number from your pages and feed (the “generator” tag) and from core file links, so it’s not handed to vulnerability scanners.',
        },
        {
          key: 'tidy_head_links',
          label: 'Tidy page-head links',
          hint: 'Removes a few rarely-used auto-generated links from your pages’ source code (the short-link and oEmbed embed links), trimming the technical footprint crawlers scrape. No effect on how your pages look or rank.',
        },
        {
          key: 'disable_xmlrpc',
          label: 'Disable XML-RPC',
          hint: 'Turns off the legacy xmlrpc.php endpoint — a common target for password-guessing and pingback-spam attacks. Safe for most sites; leave on only if an older app or Jetpack feature needs it.',
        },
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
        features: [...this.features.map((f) => ({ label: f.label, on: !!d[f.key] })), { label: 'Visit Log', on: !!d.enable_activity }],
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
        { key: 'search', label: 'Show in search engines', hint: 'Let Google and other search engines find your pages. This writes a robots.txt Content-Signal — it’s not WordPress’s “Discourage search engines” setting (Settings → Reading), which is what actually controls indexing.' },
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
      return 'robots.txt is only a request a crawler can ignore — so your “no AI training” choice also goes out in the standardized signals below, which are harder for a crawler to skip.';
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
    allowSuggestions() {
      const current = this.settings.allowed_agents || [];
      // Hide a suggestion once its token is already trusted — case-insensitively,
      // since the allow-list matches as a lowercase substring server-side.
      const have = current.map((a) => String(a).toLowerCase());
      return this.knownAllowed.filter((a) => !have.includes(String(a).toLowerCase()));
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
    invalidBlockedPatterns() {
      // Entries written as /…/ whose body isn't a valid expression: the server
      // would silently fall back to matching the literal text, so warn that the
      // "advanced" pattern won't work as intended. Best-effort (JS regex syntax),
      // which still catches the common typos — an unbalanced ( or [.
      const list = Array.isArray(this.settings.blocked_agents) ? this.settings.blocked_agents : [];
      return list.filter((a) => {
        const s = String(a).trim();
        const close = s.lastIndexOf('/');
        if (s[0] !== '/' || close <= 0) return false; // not a /…/ pattern
        const body = s.slice(1, close);
        if (body === '') return true;
        try { new RegExp(body); return false; } catch (e) { return true; }
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
    // Open the WordPress media modal for the default share image. The frame is
    // built once and reused; selecting stores the attachment ID in settings
    // (which autosaves like any other field) and updates the local thumbnail.
    pickSocialImage() {
      if (!window.wp || !window.wp.media) return; // Media scripts missing — the button did nothing visible anyway.
      if (!this.socialFrame) {
        this.socialFrame = window.wp.media({
          title: 'Default Share Image',
          library: { type: 'image' },
          multiple: false,
          button: { text: 'Use this image' },
        });
        this.socialFrame.on('select', () => {
          const att = this.socialFrame.state().get('selection').first().toJSON();
          this.settings.social_default_image = att.id;
          const sizes = att.sizes || {};
          this.socialThumb = (sizes.thumbnail || sizes.medium || { url: att.url }).url;
        });
      }
      this.socialFrame.open();
    },
    clearSocialImage() {
      this.settings.social_default_image = 0;
      this.socialThumb = '';
    },
    // Called from outside too (App, for the review queue's footer link) — the dialog
    // teleports to <body>, so it opens over whatever tab is active.
    openClientManager() {
      this.clientManagerOpen = true;
    },
    // ---- Verified-bots registry manager ---------------------------------------
    toggleVerAdd() {
      this.verAddOpen = !this.verAddOpen;
      if (this.verAddOpen) {
        this.$nextTick(() => {
          if (this.$refs.verAddName) this.$refs.verAddName.focus();
        });
      } else {
        // Close means discard: a reopened form starts clean instead of resurrecting a
        // half-typed entry and a stale probe verdict.
        this.verAdd = { label: '', ua: '', domains: '', url: '' };
        this.verAddError = '';
      }
    },
    toggleVerifier(row) {
      if (!row.builtin) return;
      const disabled = (this.settings.verifier_disabled || []).slice();
      const i = disabled.indexOf(row.token);
      if (i === -1) disabled.push(row.token);
      else disabled.splice(i, 1);
      this.settings.verifier_disabled = disabled;
    },
    removeVerifier(row) {
      if (row.builtin) return;
      this.settings.verifier_custom = (this.settings.verifier_custom || []).filter((e, i) => i !== row._ci);
    },
    // One real digest, right now, to the SAVED recipient. Synchronous on the
    // server (a single wp_mail), so the flash is a true verdict — a failure
    // here means the site itself cannot send mail, and the message says so.
    async sendTestDigest() {
      if (this.digestTestSending || !this.api) return;
      this.digestTestSending = true;
      try {
        const r = await this.api.sendTestDigest();
        this.$emit('flash', 'success', `Test email sent to ${r.to}. If it doesn’t arrive, check the spam folder.`);
      } catch (e) {
        this.$emit('flash', 'error', (e && e.message) || 'The email could not be sent.');
      } finally {
        this.digestTestSending = false;
      }
    },
    async addVerifier() {
      if (!this.verAddReady || this.verAddBusy) return;
      const a = this.verAdd;
      const url = a.url.trim();
      this.verAddError = '';
      // A ranges URL must PROVE itself before it's accepted: the server fetches it once
      // (same bounded rules as the daily refresh) and checks it parses as a range file —
      // otherwise any https address would pass, including a homepage.
      if (url && this.api) {
        this.verAddBusy = true;
        try {
          const r = await this.api.probeRanges(url);
          if (!r.ok) {
            this.verAddError = r.message || 'That URL didn’t serve a range file.';
            return;
          }
          this.$emit('flash', 'success', `Range file looks good — ${r.prefixes} range${1 === r.prefixes ? '' : 's'}.`);
        } catch (e) {
          this.verAddError = 'Couldn’t check the URL — ' + ((e && e.message) || 'the request failed') + '.';
          return;
        } finally {
          this.verAddBusy = false;
        }
      }
      const entry = {
        label: a.label.trim(),
        ua: a.ua.trim().toLowerCase(),
        domains: a.domains.split(',').map((d) => d.trim()).filter(Boolean),
        url,
      };
      this.settings.verifier_custom = (this.settings.verifier_custom || []).concat([entry]);
      this.verAdd = { label: '', ua: '', domains: '', url: '' };
      this.verAddOpen = false;
      // Custom rows list last (mirroring the server's order) — on a long list the new
      // row would land off-screen, so bring it into view as the visible confirmation.
      this.$nextTick(() => {
        const rows = this.$el.querySelectorAll('.ar-verreg__row');
        if (rows.length) rows[rows.length - 1].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      });
    },
    isUrl(value) {
      return /^https?:\/\//i.test(value);
    },
    // Splits a feature hint into segments so the well-known standards it names
    // (llms.txt, robots.txt, JSON-LD, …) render as outgoing links to the spec that
    // defines each one — the standard describing the file's purpose, NOT the live
    // file on this site. Returns [{ text } | { term, href }] for the template to
    // render; longest terms are matched first so "llms-full.txt" wins over
    // "llms.txt". Scoped to these curated terms, so unrelated text stays plain.
    specHintParts(hint) {
      const specs = [
        ['llms-full.txt', 'https://llmstxt.org/'],
        ['llms.txt', 'https://llmstxt.org/'],
        ['robots.txt', 'https://www.rfc-editor.org/rfc/rfc9309'],
        ['JSON-LD', 'https://json-ld.org/'],
        ['sitemap', 'https://www.sitemaps.org/'],
        ['.md', 'https://commonmark.org/'],
      ];
      const href = {};
      specs.forEach(([t, h]) => { href[t.toLowerCase()] = h; });
      const pattern = specs
        .map(([t]) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
        .join('|');
      const re = new RegExp('(' + pattern + ')', 'gi');
      const parts = [];
      let last = 0;
      let m;
      while ((m = re.exec(hint)) !== null) {
        if (m.index > last) parts.push({ text: hint.slice(last, m.index) });
        parts.push({ term: m[0], href: href[m[0].toLowerCase()] });
        last = m.index + m[0].length;
      }
      if (last < hint.length) parts.push({ text: hint.slice(last) });
      return parts;
    },
    groupIcon,
    // A deep-link (from Readiness / Dashboard) may target a field that lives in a
    // group other than the one on screen. That group is display:none, so the
    // parent's scrollIntoView would no-op — switch to the group that contains the
    // anchor first. DOM-based (closest [data-group]) so it needs no anchor→group
    // map and keeps working as sections move between groups.
    revealAnchor(anchor) {
      const el = anchor && document.getElementById(anchor);
      if (!el) return;
      const grp = el.closest('[data-group]');
      const key = grp && grp.getAttribute('data-group');
      if (key && key !== this.group) this.group = key;
    },
    // WebMCP per-tool expose/hide. Stored as a deny-list (webmcp_hidden_tools), so a
    // tool is exposed by default and only hidden when the owner turns it off.
    isToolExposed(name) {
      const hidden = this.settings.webmcp_hidden_tools || [];
      return !hidden.includes(name);
    },
    toggleToolHidden(name) {
      if (!Array.isArray(this.settings.webmcp_hidden_tools)) {
        this.settings.webmcp_hidden_tools = [];
      }
      const arr = this.settings.webmcp_hidden_tools;
      const i = arr.indexOf(name);
      if (i === -1) arr.push(name);
      else arr.splice(i, 1);
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
          this.oauthCheck = { ok: true, msg: 'Published ✓ — AI assistants can now discover your login server at /.well-known/oauth-protected-resource.' };
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
    addAllow(name) {
      if (!Array.isArray(this.settings.allowed_agents)) this.settings.allowed_agents = [];
      if (!this.settings.allowed_agents.includes(name)) this.settings.allowed_agents.push(name);
    },
    // What the tick shows. For a type the OWNER picked, their own list; for one
    // a PLUGIN offered, the plugin's yes minus any veto.
    //
    // The veto is read from the LIVE settings object, never from pt.vetoed —
    // that field is a photograph taken when the page loaded, so unticking wrote
    // the veto (it worked) while the card carried on showing a tick.
    typeShowsOn(pt) {
      if (!pt.forced) return this.isTypeOn(pt.slug);
      const vetoed = Array.isArray(this.settings.post_types_vetoed) ? this.settings.post_types_vetoed : [];
      return !vetoed.includes(pt.slug);
    },
    // Clicking a plugin-offered card records a VETO (or clears one) rather than
    // editing the owner's own list. Ticking it removes the veto — which hands
    // the decision back to the plugin rather than pinning the type on, so if the
    // plugin later stops asking, the type simply goes.
    toggleTypeCard(pt) {
      if (!pt.forced) {
        this.toggleType(pt.slug);
        return;
      }
      if (!Array.isArray(this.settings.post_types_vetoed)) this.settings.post_types_vetoed = [];
      const list = this.settings.post_types_vetoed;
      const i = list.indexOf(pt.slug);
      if (i === -1) list.push(pt.slug);
      else list.splice(i, 1);
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
    isEvergreen(id) {
      return Array.isArray(this.settings.evergreen_categories) && this.settings.evergreen_categories.includes(id);
    },
    toggleEvergreen(id) {
      if (!Array.isArray(this.settings.evergreen_categories)) this.settings.evergreen_categories = [];
      const list = this.settings.evergreen_categories;
      const i = list.indexOf(id);
      if (i === -1) list.push(id);
      else list.splice(i, 1);
    },
    clearEvergreen() {
      this.settings.evergreen_categories = [];
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
  },
};
</script>

<template>
  <form class="ar-form" @submit.prevent="$emit('save-profile')">
    <!-- Settings sub-navigation: the page is split into a few labelled groups,
         shown one at a time, so it reads as focused screens instead of one long
         stack. Styled as a segmented control — visually distinct from the
         masthead tabs so the two nav levels never read as the same control. -->
    <div class="ar-tabpanel">
      <nav class="ar-tabpanel__tabs ar-tabpanel__tabs--scroll" aria-label="Settings sections">
        <button
          v-for="g in groups"
          :key="g.key"
          type="button"
          class="ar-subnav__item"
          :class="{ 'is-active': group === g.key }"
          :aria-current="group === g.key ? 'page' : null"
          @click="group = g.key"
        ><span class="ar-subnav__icon" aria-hidden="true" v-html="groupIcon(g.key)"></span>{{ g.label }}</button>
      </nav>
      <p class="ar-tabpanel__caption">{{ activeGroupHint }}</p>

      <div class="ar-tabpanel__body" :aria-busy="busy">
    <!-- ============================================================ -->
    <!-- DISCOVERY — files & data AI can read                         -->
    <!-- ============================================================ -->
    <div v-show="group === 'discovery'" class="ar-group" data-group="discovery">
      <!-- Features ----------------------------------------------------- -->
      <section id="ar-sec-features" class="ar-card">
        <h2 class="ar-card__title">Features</h2>
        <p class="ar-card__lead">
          The files and signals your site publishes for AI, so assistants can find, read and cite
          your content properly. Each switch adds or removes exactly one of them. The text under a
          switch names what it publishes. The everyday ones are already on.
        </p>

        <template v-for="f in features" :key="f.key">
          <label :id="'ar-feat-' + f.key" class="ar-toggle">
            <input v-model="settings[f.key]" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>{{ f.label }}<span v-if="f.sub" class="ar-toggle__sub">{{ f.sub }}</span></strong>
              <small><template v-for="(p, i) in specHintParts(f.hint)" :key="i"><a v-if="p.href" :href="p.href" class="ar-spec-link" target="_blank" rel="noopener noreferrer">{{ p.term }}</a><template v-else>{{ p.text }}</template></template></small>
            </span>
          </label>

          <!-- The post count is a setting OF "Full text for AI", so it lives right under
               that toggle as its indented child — not stranded at the card's end. -->
          <div v-if="f.key === 'enable_llms_full'" :inert="!settings.enable_llms_full" class="ar-webmcp-tools">
            <div class="ar-field ar-field--inline ar-field--estimate">
              <label for="ar-full-count">Posts in /llms-full.txt</label>
              <input
                id="ar-full-count"
                v-model.number="settings.llms_full_posts"
                type="number"
                min="1"
                max="500"
                class="ar-input ar-input--sm"
              />
              <small v-if="fullSizeNote" class="ar-field__hint" :class="{ 'ar-warn': fullSizeNote.warn }">{{ fullSizeNote.text }}</small>
            </div>
          </div>
        </template>
      </section>

      <!-- Visit log — master toggle + CDN-mode sub-option. A feature with a situational
           nested setting, like Topics and Browser tools below; kept out of the Features
           list because it's monitoring, not an agent-readiness signal the site emits. -->
      <section id="ar-sec-activity" class="ar-card">
        <h2 class="ar-card__title">Visit Log</h2>
        <p class="ar-card__lead">
          Records which AI assistants fetch your AI files, and counts the visitors AI sends you
          (“Traffic from AI”). Everything is stored on your own site — no IP addresses by default (one optional
          setting stores IPs for flagged crawlers only), nothing sent anywhere. You read the summary on the
          Dashboard, and the full reports under More → Visitors and More → Request Log.
        </p>

        <label id="ar-feat-enable_activity" class="ar-toggle">
          <input v-model="settings.enable_activity" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Record AI activity &amp; referrals</strong>
            <small>Powers the Dashboard and both reports: which assistants fetch your files, and who AI sends your way.</small>
          </span>
        </label>

        <div :inert="!settings.enable_activity" class="ar-webmcp-tools">
          <label id="ar-feat-log_unknown_referrers" class="ar-toggle ar-toggle--nested">
            <input v-model="settings.log_unknown_referrers" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Find missed AI sources (diagnostic)</strong>
              <small>“Traffic from AI” only counts assistants it knows, and a miss leaves no trace. Turn this on and Agentimus also lists the sites it <em>could not</em> name. You can then see whether an assistant is being missed. It records the site name and the <code>utm_source</code> tag only — still no IP addresses, nothing sent anywhere. It writes a row for every visit that came from another site, so turn it on for a week, read the list under More → Visitors, then turn it off.</small>
            </span>
          </label>

          <!-- How long records live, and the ceiling that applies either way. -->
          <label id="ar-feat-activity_auto_prune" class="ar-toggle ar-toggle--nested">
            <input v-model="settings.activity_auto_prune" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Delete old records automatically</strong>
              <small>Each night, anything older than the period below is removed. Turn this off and records are kept until the log reaches its size cap — then the oldest are dropped to make room. Either way the log can’t grow without limit.</small>
            </span>
          </label>

          <div :inert="!settings.activity_auto_prune" class="ar-field ar-field--inline ar-field--log ar-field--divided">
            <label id="ar-lbl-retention">Keep records for</label>
            <SelectMenu
              v-model="settings.activity_retention_days"
              :options="retentionOptions"
              aria-label="How long to keep activity records"
            />
          </div>

        </div>
      </section>

      <!-- Size cap — its own numbered section, NOT a child of "Delete old records
           automatically": the ceiling applies whether or not auto-delete is on. It hides
           with the Visit log's master switch (nothing to cap), and the card counter
           skips hidden cards, so the spec-sheet numbering stays compact. -->
      <section :inert="!settings.enable_activity" id="ar-sec-sizecap" class="ar-card">
        <h2 class="ar-card__title">Size Cap</h2>
        <p class="ar-card__lead">
          The visit log’s hard ceiling. It applies even with automatic deletion off — the log can
          never grow without limit.
        </p>

        <div class="ar-field ar-field--inline ar-field--log">
          <label id="ar-lbl-maxrows">Keep at most</label>
          <SelectMenu
            v-model="settings.activity_max_rows"
            :options="maxRowsOptions"
            mono
            aria-label="Maximum rows kept in the activity log"
          />
        </div>

        <p class="ar-log-note">
          <!-- The cap outranks the period: a busy site can hit the ceiling long before a record
               is old enough to expire, and the oldest go anyway. Saying "keep for 90 days" without
               this reads as a promise the cap can break. -->
          The size cap comes first, before the time limit. On a busy site the log can reach the cap
          before records are old enough to expire. The oldest are then removed anyway.
          The Dashboard always reports on the last <strong>{{ Math.min(30, settings.activity_retention_days || 30) }} days</strong>.
          <template v-if="(settings.activity_retention_days || 30) > 30">
            Keeping {{ settings.activity_retention_days }} days gives the <em>Request Log</em> a deeper history to page
            through — it doesn’t stretch the Dashboard’s cards.
          </template>
          <template v-else-if="settings.activity_auto_prune && (settings.activity_retention_days || 30) < 30">
            Keeping fewer than 30 days shortens the Dashboard to match, rather than drawing empty days for records
            that were deleted.
          </template>
          Flagged crawler IPs are not covered by this — they’re the only personal data stored, and they’re
          removed on their own, shorter schedule.
        </p>
      </section>

      <!-- Weekly email — the digest. One owner-facing email a week, built only from
           local data; the stop link inside every email flips this same switch. -->
      <section id="ar-sec-digest" class="ar-card">
        <h2 class="ar-card__title">Weekly Email</h2>
        <p class="ar-card__lead">
          Once a week, Agentimus emails you a short note about what AI did on your site. It covers
          AI reads, readers who arrived from AI answers, impostors caught, and your readiness score.
          It is built only from data already stored on your site, and sent with WordPress’s own mail.
          Nothing else leaves your server. A week with nothing to report sends nothing.
        </p>

        <label id="ar-feat-digest_enabled" class="ar-toggle">
          <input v-model="settings.digest_enabled" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Send the weekly note</strong>
            <small>Arrives once a week, on the day and time you pick below. Every email carries a one-click stop link, so turning it off never needs this screen.</small>
          </span>
        </label>

        <div :inert="!settings.digest_enabled" class="ar-webmcp-tools">
          <div class="ar-field ar-field--inline ar-field--digest">
            <span class="ar-digest-slot">
              <label id="ar-lbl-digest-day">Send it every</label>
              <SelectMenu
                v-model="settings.digest_day"
                :options="digestDayOptions"
                aria-label="Which day of the week the email is sent"
              />
            </span>
            <span class="ar-digest-slot">
              <label id="ar-lbl-digest-hour">at</label>
              <SelectMenu
                v-model="settings.digest_hour"
                :options="digestHourOptions"
                aria-label="What time of day the email is sent"
              />
            </span>
          </div>
          <p class="ar-log-note">
            That’s your site’s clock (Settings → General → Timezone). WordPress sends scheduled email on
            the first visit after the chosen time, so on a quiet site it can arrive a little later.
          </p>
          <div class="ar-field">
            <label for="ar-digest-recipient">Send it to</label>
            <input
              id="ar-digest-recipient"
              v-model.trim="settings.digest_recipient"
              type="email"
              class="ar-input"
              :placeholder="adminEmail || 'The site admin email'"
              autocomplete="email"
            />
          </div>
          <p class="ar-log-note">
            Empty means the site admin email. The test button below uses the <em>saved</em> address —
            save first if you just changed it.
          </p>
          <p>
            <button type="button" class="ar-btn ar-btn--ghost" :disabled="digestTestSending" @click="sendTestDigest">
              {{ digestTestSending ? 'Sending…' : 'Send a test email' }}
            </button>
          </p>
        </div>
      </section>

      <!-- Caching & CDN — how Agentimus copes when a page cache/CDN fronts the site: it can
           hide agent fetches from the log and serve stale AI files. -->
      <section id="ar-sec-caching" class="ar-card">
        <h2 class="ar-card__title">Caching &amp; CDN</h2>
        <!-- ⭐ Was a 72-word sentence: two problems, each with its own parenthesis,
             joined by a dash and a colon. Two problems want two sentences. -->
        <p class="ar-card__lead">
          A cache or CDN in front of your site makes your pages faster. Cloudflare, Nginx, Varnish and
          caching plugins all do this. But a cache can come between AI and Agentimus in two ways. It can
          serve saved copies of your AI files, so those files fall out of date and we never see them being read.
          And it can hide the visitors AI sends you, so “Traffic from AI” counts too few. The settings
          here deal with both. If nothing caches your site, none of them matter.
        </p>

        <label :inert="!settings.enable_activity" id="ar-feat-enable_referral_beacon" class="ar-toggle">
          <input v-model="settings.enable_referral_beacon" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>CDN mode — count AI visits in the browser</strong>
            <small>Turn this on only if a full-page cache or CDN sits in front of your site — for example Cloudflare with “Cache Everything”. It counts “Traffic from AI” in the visitor’s browser, so the number survives the cache. It adds a small counting script to your pages. Some visitors block scripts like this, with an ad-blocker or a privacy browser, and are not counted. So read the total as a minimum, never as too high.</small>
          </span>
        </label>

        <label id="ar-feat-bypass_shared_cache" class="ar-toggle">
          <input v-model="settings.bypass_shared_cache" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Keep AI files out of your cache</strong>
            <small>A cache in front of your site can serve saved copies of your AI files: <code>llms.txt</code>, the <code>.well-known</code> docs and the change feed. Those reads never reach WordPress, so the log counts too few and the change feed can fall out of date. Turn this on and Agentimus asks caches not to store those files. Every read then reaches WordPress, and is counted and current. This works with any cache that respects the request. A cache told to “cache everything”, or to ignore your server, still needs its own rule. If nothing sits in front of your site, leave this off: it gives up a little speed on those files.</small>
          </span>
        </label>

        <label id="ar-feat-purge_cache_on_change" class="ar-toggle">
          <input v-model="settings.purge_cache_on_change" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Refresh AI files when content changes</strong>
            <small>When you publish or edit a post, your page cache refreshes that page. It does not refresh your AI files. So a cache can keep serving an old <code>llms.txt</code>, change feed or <code>.md</code> copy until its own timer ends. Turn this on and Agentimus asks every cache it can find to drop those files after each change — WP Rocket, Nginx Helper, W3 Total Cache, LiteSpeed, WP Super Cache, Cache Enabler and others. AI assistants then never get an old copy after an edit. On by default. It does nothing if you have no page cache. This keeps files <em>current</em>; it does not change the log count. For that, use the switch above.</small>
          </span>
        </label>
      </section>

      <!-- Topics for AI — master toggle + how topics are chosen -------- -->
      <section id="ar-sec-topics" class="ar-card">
        <h2 class="ar-card__title">Topics for AI</h2>
        <p class="ar-card__lead">
          Adds a short list of topics to your content’s AI data — the structured data (JSON-LD
          <code>keywords</code>) and the plain-text (<code>.md</code>) version — so assistants
          understand and cite it correctly. You set the topics in the editor; nothing shows on
          the visible page. Stands aside for your SEO plugin’s structured data, like the rest of Agentimus.
        </p>

        <label id="ar-feat-enable_topics" class="ar-toggle">
          <input v-model="settings.enable_topics" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Add topics to your content’s AI data</strong>
            <small>Shows a “Topics for AI” box in the editor.</small>
          </span>
        </label>

        <div :inert="!settings.enable_topics" class="ar-webmcp-tools">
          <label id="ar-feat-topics_derive_default" class="ar-toggle ar-toggle--nested">
            <input v-model="settings.topics_derive_default" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Use tags &amp; categories by default</strong>
              <small>New content fills its topics from its own tags and categories automatically. Anything you type overrides this.</small>
            </span>
          </label>

          <!-- Divided, not attached: the cap clamps every topics list — typed, derived,
               or AI-suggested (Topics::cap()) — so it's a PEER of the derive toggle
               under the master, and the hairline above says exactly that. -->
          <div class="ar-field ar-field--inline ar-field--divided">
            <label for="ar-topics-max">Most topics per item</label>
            <input
              id="ar-topics-max"
              v-model.number="settings.topics_max"
              type="number"
              min="1"
              max="50"
              class="ar-input ar-input--sm"
            />
          </div>
        </div>
      </section>

      <!-- AI description — master toggle + whether it owns the meta tag -- -->
      <section id="ar-sec-ai-description" class="ar-card">
        <h2 class="ar-card__title">AI Description</h2>
        <p class="ar-card__lead">
          Adds a one-line summary to your content’s AI data — the structured data (JSON-LD
          <code>description</code>) and the plain-text (<code>.md</code>) version — so assistants
          summarise and cite it correctly. You write it in the editor. If you leave it blank, the
          page’s excerpt is used instead. If you run an SEO plugin, Agentimus lets that plugin lead.
        </p>

        <label id="ar-feat-enable_ai_description" class="ar-toggle">
          <input v-model="settings.enable_ai_description" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Add a description to your content’s AI data</strong>
            <small>Adds an “AI description” field to the editor, in the <em>AI Description &amp; Topics</em> box.</small>
          </span>
        </label>

        <div :inert="!settings.enable_ai_description" class="ar-webmcp-tools">
          <label id="ar-feat-ai_description_meta_tag" class="ar-toggle ar-toggle--nested">
            <input v-model="settings.ai_description_meta_tag" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Set the page meta description too</strong>
              <small>Uses your description as the page’s meta description tag — replacing your theme’s — unless a dedicated SEO plugin manages it. Turn off to enrich only the AI data and leave your <code>&lt;head&gt;</code> alone.</small>
            </span>
          </label>
        </div>
      </section>

      <!-- Search basics — the solo-mode surfaces (SEO title / share cards / canonicals) -->
      <section id="ar-sec-search-basics" class="ar-card">
        <h2 class="ar-card__title">Search Basics</h2>
        <p class="ar-card__lead">
          The search essentials most sites install an SEO plugin for — covered here, so you don’t
          need one. Everything in this card applies only while no SEO plugin is installed: the
          moment one activates, Agentimus steps aside automatically and these switches wait
          quietly.
        </p>

        <label id="ar-feat-enable_seo_titles" class="ar-toggle">
          <input v-model="settings.enable_seo_titles" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Per-page SEO title</strong>
            <small>Adds an “SEO title” field in the editor. When filled in, it replaces that page’s title in search results and browser tabs — your site name stays appended.</small>
          </span>
        </label>

        <label id="ar-feat-enable_social_cards" class="ar-toggle">
          <input v-model="settings.enable_social_cards" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Social share cards</strong>
            <small>Adds the tags (Open Graph) that give a shared link its preview in social and chat apps: a title, a description and an image. The image is the page’s featured image, or your Site Icon when a page has none.</small>
          </span>
        </label>

        <div :inert="!settings.enable_social_cards" class="ar-webmcp-tools">
          <div class="ar-field">
            <label for="ar-social-image-pick">Default share image <span class="ar-field__tag">optional</span></label>
            <div class="ar-media-pick">
              <img v-if="socialThumb" :src="socialThumb" alt="" class="ar-media-pick__thumb" />
              <button id="ar-social-image-pick" type="button" class="ar-btn ar-btn--ghost" @click="pickSocialImage">
                {{ settings.social_default_image ? 'Change image' : 'Choose image' }}
              </button>
              <button v-if="settings.social_default_image" type="button" class="ar-btn ar-btn--ghost" @click="clearSocialImage">Remove</button>
            </div>
            <small class="ar-field__hint">Used when a shared page has no featured image of its own. Leave empty and your Site Icon steps in.</small>
          </div>
        </div>

        <label id="ar-feat-enable_canonicals" class="ar-toggle">
          <input v-model="settings.enable_canonicals" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Canonical links</strong>
            <small>Marks your front page and archive pages with their one official URL, so search engines don’t treat variations of the same address as duplicates. WordPress already covers single posts and pages; this fills in the rest.</small>
          </span>
        </label>
      </section>

      <!-- MCP server — master toggle + connection details --------------- -->
      <McpServerCard :settings="settings" :mcpServer="mcpServer" :api="api" :active="active" :busy="busy" />

      <!-- Browser tools (WebMCP) — master toggle + per-tool expose/hide - -->
      <section id="ar-sec-webmcp" class="ar-card">
        <h2 class="ar-card__title">Browser Tools <span class="ar-card__tag">Experimental</span></h2>
        <p class="ar-card__lead">
          Lets an AI assistant working inside a browser call your site’s read-only tools (like site
          search) directly, via the emerging <strong>WebMCP</strong> browser standard. It adds a
          tiny script that does nothing in browsers without support. Off by default — turn it on
          only to be an early adopter.
        </p>

        <label id="ar-feat-enable_webmcp" class="ar-toggle">
          <input v-model="settings.enable_webmcp" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Offer browser tools to AI assistants</strong>
            <small>Registers the read-only tools below with the browser, for assistants that support WebMCP.</small>
          </span>
        </label>

        <div :inert="!settings.enable_webmcp" class="ar-webmcp-tools">
          <p v-if="!webmcpTools.length" class="ar-field__hint">No browser tools registered yet — the moment Agentimus or another plugin registers one, it appears here with its own switch.</p>
          <template v-else>
            <p class="ar-webmcp-tools__head">
              Tools offered to assistants — turn one off to hide it (it won’t be registered with the browser at all).
            </p>
            <label v-for="t in webmcpTools" :key="t.name" class="ar-toggle ar-toggle--nested">
              <input type="checkbox" :checked="isToolExposed(t.name)" @change="toggleToolHidden(t.name)" />
              <span class="ar-toggle__track" aria-hidden="true"></span>
              <span class="ar-toggle__text">
                <strong><code>{{ t.name }}</code></strong>
                <small>{{ t.description }}</small>
              </span>
            </label>
          </template>
        </div>
      </section>

      <!-- Content types ------------------------------------------------ -->
      <section v-if="postTypes.length" class="ar-card">
        <h2 class="ar-card__title" id="ar-content-types">Content Types</h2>
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
          <!-- One loop, two groups. The plugin-offered cards behave exactly like
               the owner's own — same markup, same control — and only the meaning
               of a tick differs: there it CLEARS a veto rather than adding a
               pick, so switching one back on returns the decision to the plugin
               instead of pinning the type on. Two copies of this card is how one
               group quietly stops matching the other. -->
          <template v-for="group in typeGroups" :key="group.key">
            <p v-if="group.label" class="ar-types-group">{{ group.label }}</p>
            <div class="ar-types-grid">
              <label
                v-for="pt in group.types"
                :key="pt.slug"
                class="ar-type"
                :class="{ 'is-on': typeShowsOn(pt) }"
                :title="pt.forced ? (typeShowsOn(pt) ? 'Offered by a plugin. Untick to stop advertising it — the plugin keeps working.' : 'You switched this off. Tick it to hand the decision back to the plugin.') : null"
              >
                <input type="checkbox" :checked="typeShowsOn(pt)" @change="toggleTypeCard(pt)" />
                <span class="ar-type__check" aria-hidden="true"></span>
                <span class="ar-type__body">
                  <span class="ar-type__label">{{ pt.label }}</span>
                  <span class="ar-type__meta">
                    <span v-if="pt.source" class="ar-type__src">{{ pt.source }}</span>
                    <code>{{ pt.slug }}</code>
                  </span>
                </span>
              </label>
            </div>
          </template>
          <p v-if="!filteredPostTypes.length" class="ar-types-empty">No types match “{{ typeQuery }}”.</p>
        </div>
        <!-- Teach the taxonomy expansion (why N types → more than N tokens) WITHOUT
             claiming to equal the dashboard's capabilities total: on a site where a
             commerce plugin owns wp/v2, Agentimus's core-content provider stands down
             and these tokens are superseded, so "= the 4 on the dashboard" would be a
             lie there. The mapping itself is always true. -->
        <!-- Taught once, under the grid, rather than on every locked card: a line
             inside the card made its row taller than the one above and the whole
             grid looked broken. Shown only when there IS one, so a site with no
             integrations never reads an explanation for something it cannot see. -->
        <p v-if="forcedTypes.length" class="ar-card__note ar-card__note--wide">
          <strong>Offered by plugins</strong> — an integration asked for
          {{ forcedTypes.length === 1 ? 'this type' : 'these types' }}, so
          {{ forcedTypes.length === 1 ? 'it is' : 'they are' }} on unless you say otherwise. Unticking
          stops {{ forcedTypes.length === 1 ? 'it' : 'them' }} being advertised and your choice outlasts the
          plugin being deactivated; ticking again hands the decision back rather than pinning
          {{ forcedTypes.length === 1 ? 'it' : 'them' }} on. The plugin itself keeps working either way.
        </p>
        <p v-if="advertisedCapabilities.length" class="ar-card__note ar-card__note--wide">
          <strong>Each ticked type is advertised with its public taxonomies</strong> — a post carries
          categories and tags, so {{ selectedTypeCount }}
          {{ selectedTypeCount === 1 ? 'type' : 'types' }} here map to
          {{ advertisedCapabilities.length }} read
          {{ advertisedCapabilities.length === 1 ? 'capability' : 'capabilities' }} in llms.txt,
          schema and discovery:
          <template v-for="(cap, i) in advertisedCapabilities" :key="cap">
            <code>{{ cap }}</code><span v-if="i < advertisedCapabilities.length - 1"> · </span>
          </template>
        </p>
        <p class="ar-card__note ar-card__note--wide">
          <strong>Curates what's advertised — not an access control.</strong>
          Unticking a type removes it from llms.txt, schema and discovery, but your
          WordPress REST API stays public: <code>/wp-json/wp/v2</code> remains reachable regardless.
        </p>
      </section>

      <!-- Evergreen content --------------------------------------------- -->
      <section v-if="categories.length" class="ar-card">
        <h2 class="ar-card__title">Evergreen Content</h2>
        <p class="ar-card__lead">
          Mark categories whose posts are timeless — references, tutorials, definitions, legal pages.
          Posts in them are left out of the “freshness” check, so they’re never flagged as stale just for being old.
        </p>
        <div class="ar-types-bar">
          <input
            v-if="categories.length > 8"
            v-model="catQuery"
            type="search"
            class="ar-input ar-types-search"
            placeholder="Filter categories…"
          />
          <div class="ar-types-meta">
            <span class="ar-types-count">{{ evergreenCount }} evergreen</span>
            <button v-if="evergreenCount" type="button" class="ar-linkbtn" @click="clearEvergreen">Clear</button>
          </div>
        </div>

        <div class="ar-types-scroll">
          <div class="ar-types-grid">
            <label
              v-for="c in filteredCategories"
              :key="c.id"
              class="ar-type"
              :class="{ 'is-on': isEvergreen(c.id) }"
            >
              <input type="checkbox" :checked="isEvergreen(c.id)" @change="toggleEvergreen(c.id)" />
              <span class="ar-type__check" aria-hidden="true"></span>
              <span class="ar-type__body">
                <span class="ar-type__label">{{ c.name }}</span>
              </span>
            </label>
            <p v-if="!filteredCategories.length" class="ar-types-empty">No categories match “{{ catQuery }}”.</p>
          </div>
        </div>
        <p class="ar-card__note">
          Only affects the freshness check — nothing else changes. Leave empty to age-check every post.
        </p>
      </section>

      <!-- Discovery: REST APIs (opt-in) -------------------------------- -->
      <section v-if="restNamespacesDetected.length" class="ar-card">
        <h2 class="ar-card__title">Discovery — REST APIs</h2>
        <p class="ar-card__lead">
          REST APIs detected on your site. Publish the ones AI assistants should use; internal or admin
          APIs — analytics, usage tracking, admin — are best left off. Nothing is published unless you tick it.
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
          Ticking one lists it in discovery so AI assistants prefer it; leaving it off just hides it from
          the map. Either way the route is exactly as reachable as WordPress already makes it.
        </p>
      </section>

      <!-- Provider integrations ---------------------------------------- -->
    </div>

    <!-- ============================================================ -->
    <!-- IDENTITY — who owns this site                                -->
    <!-- ============================================================ -->
    <div v-show="group === 'identity'" class="ar-group" data-group="identity">
      <!-- Identity ----------------------------------------------------- -->
      <section id="ar-sec-identity" class="ar-card">
        <h2 class="ar-card__title">Identity</h2>
        <p class="ar-card__lead">The highest-signal data an AI assistant reads — who owns this site and what it's about.</p>

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
          <small class="ar-field__hint">An explicit exclusion so AI assistants don’t miscategorize you. Becomes JSON-LD <code>disambiguatingDescription</code> and a line in llms.txt.</small>
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
            Published in <code>discovery.json</code> so AI assistants can reach you. Leave empty to expose none —
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

        <div id="ar-id-expertise" class="ar-field">
          <label>{{ expertiseLabel }}</label>
          <TagInput v-model="identity.expertise" placeholder="Add a topic, press Enter" />
          <small class="ar-field__hint">Feeds this list and schema <code>knowsAbout</code>. Saved as you add.</small>
        </div>

        <div id="ar-id-sameas" class="ar-field">
          <label>Profile URLs</label>
          <TagInput v-model="identity.same_as" :placeholder="profileUrlPlaceholder" />
          <small class="ar-field__hint">
            Public profile URLs (LinkedIn, X, GitHub, Facebook, Wikipedia…) that help AI assistants confirm you are who you say. Saved as you add.
            <span v-if="identity.same_as.some((u) => !isUrl(u))" class="ar-warn">Some entries are not full https:// URLs.</span>
          </small>
        </div>

      </section>

      <!-- Services ----------------------------------------------------- -->
      <section id="ar-sec-services" class="ar-card">
        <h2 class="ar-card__title">Services</h2>
        <p class="ar-card__lead">
          What you can be hired for — each becomes a Schema.org <code>Service</code> linked to you as
          the provider, so AI assistants can answer “what does this site offer?”. Optional; leave empty if
          you don't sell services.
        </p>

        <div v-for="(svc, i) in identity.services" :key="i" class="ar-svc">
          <button type="button" class="ar-svc__x" aria-label="Remove service" v-tip="`Remove service`" @click="removeService(i)">×</button>
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
    </div>

    <!-- ============================================================ -->
    <!-- AI ACCESS — what bots may do, and who to block               -->
    <!-- ============================================================ -->
    <div v-show="group === 'access'" class="ar-group" data-group="access">
      <!-- Crawler policy ----------------------------------------------- -->
      <section id="ar-sec-ai" class="ar-card">
        <h2 class="ar-card__title">Crawler Policy</h2>
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
              The opt-out file is site-wide — it can’t block individual crawlers. Per-crawler blocks live in the
              crawler list above (robots.txt), and in scanner blocking below for a hard 403.
            </p>

            <details>
              <summary class="ar-linkbtn">Publishing channels</summary>
              <p class="ar-field__hint">
                Each channel states the same “no AI training” choice in a different place. They’re on by
                default — turn one off only if you don’t want to publish through that channel.
              </p>

              <label id="ar-feat-enable_ai_header" class="ar-toggle">
                <input v-model="settings.enable_ai_header" type="checkbox" />
                <span class="ar-toggle__track" aria-hidden="true"></span>
                <span class="ar-toggle__text">
                  <strong>Response header</strong>
                  <small>Attaches an invisible “do not train” tag to every page your site serves. An AI crawler then gets the signal directly, even if it never reads your robots.txt.</small>
                </span>
              </label>

              <label class="ar-toggle">
                <input v-model="settings.enable_tdmrep" type="checkbox" />
                <span class="ar-toggle__track" aria-hidden="true"></span>
                <span class="ar-toggle__text">
                  <strong>Opt-out file</strong>
                  <small>Publishes a small standard file that declares your content off-limits for AI training. This is the format AI companies check, and the one that matches EU text-and-data-mining rules. <a :href="tdmrepUrl" target="_blank" rel="noopener">View the file</a>.</small>
                </span>
              </label>

              <label class="ar-toggle">
                <input v-model="settings.ai_noai_header" type="checkbox" />
                <span class="ar-toggle__track" aria-hidden="true"></span>
                <span class="ar-toggle__text">
                  <strong>Also send a “noai” header</strong>
                  <small>An extra page header asking AI tools not to use your text or images. It is not an official standard, and only some platforms follow it. Treat it as a harmless extra signal on top of the two above.</small>
                </span>
              </label>

              <div class="ar-field">
                <label for="ar-tdm-policy">AI-usage policy URL <span class="ar-field__tag">optional</span></label>
                <input id="ar-tdm-policy" v-model="settings.tdm_policy_url" type="url" class="ar-input" placeholder="https://example.com/ai-policy" />
                <small class="ar-field__hint">
                  A link to your own page setting out your AI terms. For example: “training allowed only
                  with a licence; email us.” When you set it, the header and the opt-out file point AI
                  companies to that page, so they know your terms and how to ask permission. Leave it
                  blank for a plain “no” — your opt-out works exactly the same without it.
                </small>
              </div>
            </details>
          </div>

          <p v-else class="ar-card__note">
            AI training is allowed, so nothing is published to refuse it. On the web, saying nothing
            already means “allowed”. To refuse, turn off <strong>Allow AI training</strong> above. That
            publishes a no-training signal in three places at once: robots.txt, a page header, and
            <code>/.well-known/tdmrep.json</code>. To keep certain crawlers out while staying open to the
            rest, list them under <strong>Block specific crawlers</strong> above.
          </p>
        </div>
      </section>

      <!-- Block scanners & scrapers ------------------------------------ -->
      <section id="ar-sec-blocking" class="ar-card">
        <h2 class="ar-card__title">Block Scanners &amp; Scrapers <span class="ar-field__tag">optional</span></h2>
        <p class="ar-card__lead">
          The crawler rules above are a polite request — well-behaved crawlers honour them. This is the
          hard stop: the crawlers below are turned away from your AI files instead of being served. Off by default.
        </p>

        <label class="ar-toggle">
          <input v-model="settings.block_agents" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Deny blocked crawlers</strong>
            <small>Turn the crawlers in the list below away — they get nothing instead of your <code>llms.txt</code>, <code>discovery.json</code> and other AI files.</small>
          </span>
        </label>

        <div :inert="!settings.block_agents" class="ar-enforce-body">
          <label class="ar-toggle ar-toggle--nested">
            <input v-model="settings.block_spoofed" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Auto-deny spoofed / legacy-device crawlers</strong>
              <small>Turn away crawlers caught lying about who they are. There are two kinds. First, crawlers
              pretending to be very old phones — old Nokia and BlackBerry handsets, a common scanner trick. Your
              activity log shows these as “Likely spoof/scanner”. Second, with <strong>Verify bot
              identities</strong> on, <strong>proven impostors</strong>: a visitor claiming to be a verified bot
              such as Googlebot or GPTBot, whose address clearly fails that operator's own check. An unclear
              result never turns anyone away.</small>
            </span>
          </label>

          <div class="ar-field">
            <div class="ar-field__head">
              <label>Blocked user-agents <span class="ar-field__tag">optional</span></label>
              <button v-if="api" type="button" class="ar-linkbtn ar-field__manage" @click="clientManagerOpen = true">Manage clients</button>
            </div>
            <TagInput v-model="settings.blocked_agents" placeholder="Add a user-agent to deny — 3+ characters" />
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
            <p v-if="invalidBlockedPatterns.length" class="ar-card__note ar-warn">
              ⚠ Invalid {{ invalidBlockedPatterns.length === 1 ? 'pattern' : 'patterns' }}:
              <code>{{ invalidBlockedPatterns.join(', ') }}</code> —
              {{ invalidBlockedPatterns.length === 1 ? "that isn't a valid /…/ expression, so it'll be matched as plain text, not a pattern." : "those aren't valid /…/ expressions, so they'll be matched as plain text, not patterns." }}
              Fix the pattern, or drop the slashes to match it as a plain fragment.
            </p>
            <small class="ar-field__hint">
              Type part of a crawler's name — capitalisation doesn't matter, and a fragment is enough
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

          <p v-if="!settings.verify_bots" class="ar-card__note ar-warn">
            ⚠ <strong>One costume beats this list.</strong> Blocking matches names, and real search engines are
            always let through — so a blocked crawler can dodge every rule here just by calling itself
            <code>Googlebot</code>. Turn on <strong>Verify bot identities</strong> (in the next card) and a
            proven fake loses that free pass.
          </p>
        </div>

        <!-- The trust list lives with the deny list — the two name-based rules the
             owner writes, side by side. Not inside the inert body: trusting a client
             also stops flagging, which works whether or not blocking is on. -->
        <div class="ar-field ar-field--allow">
          <div class="ar-field__head">
            <label>Always allowed <span class="ar-field__tag">trusted</span></label>
            <button v-if="api" type="button" class="ar-linkbtn ar-field__manage" @click="clientManagerOpen = true">Manage clients</button>
          </div>
          <TagInput v-model="settings.allowed_agents" placeholder="Add a user-agent to trust" />
          <small v-if="(settings.allowed_agents || []).length" class="ar-field__hint">
            Clients you marked <strong>Allow</strong> in the review list land here — never blocked and never
            flagged again (the same treatment as Googlebot). Remove one to start flagging it again.
          </small>
          <small v-else class="ar-field__hint">
            Add a user-agent here to always trust it — never blocked and never flagged, the same treatment
            as Googlebot. Clients you mark <strong>Allow</strong> in the review list also land here.
          </small>

          <div v-if="allowSuggestions.length" class="ar-suggest">
            <span class="ar-suggest__label">Add a trusted AI crawler</span>
            <button
              v-for="a in allowSuggestions"
              :key="a"
              type="button"
              class="ar-suggest__chip"
              @click="addAllow(a)"
            >+ {{ a }}</button>
          </div>

          <div v-if="defaultAllowed.length" class="ar-builtin">
            <span class="ar-builtin__label">Built in · always allowed</span>
            <span v-for="d in defaultAllowed" :key="d" class="ar-builtin__chip">{{ d }}</span>
            <small class="ar-builtin__note">
              Recognised by signature and trusted automatically — you don't need to add them.
            </small>
          </div>

        </div>
      </section>

      <!-- Bot identity -------------------------------------------------- -->
      <section id="ar-sec-verify" class="ar-card">
        <h2 class="ar-card__title">Bot Identity <span class="ar-field__tag">optional</span></h2>
        <p class="ar-card__lead">
          A User-Agent name is only a claim — anyone can call themselves Googlebot. The checks here look
          past the name. They verify the crawlers whose operators publish a way to check. They show which
          network every other crawler really belongs to. And they let you look up any single address. An
          unclear answer never counts against anyone.
        </p>

        <!-- Verification is not gated by blocking: on its own it flags impersonators in
             the review queue and powers the row's Details verdict, whether or not you deny anyone. -->
        <label class="ar-toggle ar-toggle--standalone">
          <input v-model="settings.verify_bots" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Verify bot identities</strong>
            <small>
              When a visitor claims to be a known crawler, check that the claim is real. This is the one check
              that catches a scanner copying a crawler's name. A proven fake is flagged for review as an
              <strong>Impersonator</strong>. A slow or failed lookup never turns away a real crawler.
              <!-- The depth lives behind a fold (his call, 2026-08-12: the full
                   paragraph inline read as a wall). Same details/summary grammar
                   as Crawler Policy's "Publishing channels". -->
            </small>
          </span>
        </label>
        <!-- OUTSIDE the label on purpose: a <summary> inside a <label> loses its
             click to the checkbox, so the fold could never open (his catch,
             2026-08-12). Sibling, indented to the toggle's text column. -->
        <details class="ar-toggle__more">
          <summary class="ar-linkbtn">How the checks work</summary>
          <!-- ⭐⭐ WAS ONE 82-WORD SENTENCE — the longest on the whole screen, and
               the pattern behind most of the long ones: a LIST written as prose.
               Three ways of checking, separated by semicolons, each with its own
               parenthesis and its own dash. Splitting it into shorter sentences
               would not have been enough; three things need three lines. -->
          <div class="ar-toggle__note">
            <p>
              Agentimus can confirm that a visitor claiming to be a known crawler really is that
              crawler. It checks the ones in the <strong>Verified bots</strong> list below. There are
              three ways, and which one it uses depends on what each operator publishes.
            </p>
            <ul>
              <li>
                <strong>Reverse DNS</strong> — Googlebot, Bingbot and similar. Checked live, for each
                visitor.
              </li>
              <li>
                <strong>Published IP ranges</strong> — GPTBot, PerplexityBot and similar. Checked
                against a list we refresh once a day in the background, never while a page is being
                served. So a publisher whose file is offline costs your visitors nothing.
              </li>
              <li>
                <strong>Signed requests</strong> — Web Bot Auth. Some crawlers sign every request, as
                Google's crawler and OpenAI already do. Your own server checks the signature. This is
                the strongest proof of the three.
              </li>
            </ul>
            <p>
              This works whether blocking is on or off. If blocking is on and you block spoofed
              crawlers, a proven fake is refused at your AI files.
            </p>
            <p>
              <strong>Behind a proxy or CDN?</strong> On Cloudflare this works by itself, because
              Agentimus reads the real visitor address. Another proxy may need to pass the true
              visitor address through.
            </p>
          </div>
        </details>

        <!-- The Verified-bots registry: which bots this site can verify, and how. Owner-
             editable because verifiability is a property of the OPERATOR (they publish
             rDNS domains or an IP-range file) — a new operator may publish tomorrow, and
             the owner shouldn't wait for a plugin release to trust it. -->
        <div class="ar-field ar-verreg">
          <div class="ar-field__head">
            <label>Verified bots <span class="ar-field__tag">registry</span></label>
            <button type="button" class="ar-linkbtn ar-field__manage" @click="toggleVerAdd">
              {{ verAddOpen ? 'Close' : 'Add a bot' }}
            </button>
          </div>

          <!-- Add form ABOVE the list, right under the button that opens it — on a long
               list or a phone, a bottom-of-list form would open off-screen (Heera's
               catch). First field is focused on open, so it's type-ready immediately. -->
          <div v-if="verAddOpen" class="ar-verreg__add">
            <!-- Instructions FIRST (read before typing, not after), then the fields,
                 then the action row: the live "what's still missing" line on the left
                 and the button on the right. -->
            <small class="ar-field__hint ar-verreg__intro">
              Use the crawler's exact name from its User-Agent. It needs 3 characters or more, because a
              short everyday word would wrongly match other crawlers. Then add at least one source from
              the operator's own docs: the domain its reverse DNS must land in, its published IP-ranges
              file, or both. Agentimus fetches that file once, as you add it, to confirm it is real.
              Saved with the settings.
            </small>
            <!-- "one of the two" belongs to the PAIR, so neither field may claim to be
                 individually "(optional)" — that read as "you can skip both". -->
            <div class="ar-verreg__grid" @input="verAddError = ''">
              <input ref="verAddName" v-model="verAdd.label" type="text" placeholder="Name — e.g. NewBot" maxlength="40" />
              <input v-model="verAdd.ua" type="text" placeholder="User-Agent contains — e.g. newbot" maxlength="64" />
              <input v-model="verAdd.domains" type="text" placeholder="Reverse-DNS domains, comma-separated — this and/or the URL" />
              <input v-model="verAdd.url" type="url" placeholder="Published IP-ranges URL (https) — checked when you add" />
            </div>
            <div class="ar-verreg__addrow">
              <!-- One line, one truth: the probe's verdict when a URL failed its check,
                   else the ONE thing still missing — a disabled button must always say why. -->
              <small v-if="verAddError" class="ar-field__hint ar-verreg__missing">{{ verAddError }}</small>
              <small v-else-if="verAddMissing" class="ar-field__hint ar-verreg__missing">{{ verAddMissing }}</small>
              <button type="button" class="ar-btn ar-verreg__addbtn" :disabled="!verAddReady || verAddBusy" @click="addVerifier">
                {{ verAddBusy ? 'Checking…' : 'Add bot' }}
              </button>
            </div>
          </div>

          <ul class="ar-verreg__list">
            <li v-for="row in verifierRows" :key="row.builtin ? row.token : 'c' + row._ci" class="ar-verreg__row" :class="{ 'is-off': row.disabled }">
              <label v-if="row.builtin" class="ar-verreg__check">
                <input type="checkbox" :checked="!row.disabled" @change="toggleVerifier(row)" />
                <span class="ar-verreg__name">{{ row.label }}</span>
              </label>
              <span v-else class="ar-verreg__check">
                <span class="ar-verreg__name">{{ row.label }}</span>
                <span class="ar-verreg__custom">custom</span>
              </span>
              <code class="ar-verreg__ua" @mouseenter="showUaTip($event, 'Claimed when the User-Agent contains “' + row.ua + '”', '')" @mouseleave="hideUaTip">{{ row.ua }}</code>
              <span class="ar-verreg__methods">
                <!-- These chips hug the row's right edge: right-align the bubble so it
                     grows leftward over our own card, not over the rail beside it. -->
                <span v-if="row.domains && row.domains.length" class="ar-verreg__chip" @mouseenter="showUaTip($event, 'Reverse DNS must land in: ' + row.domains.join(', '), '', 'right')" @mouseleave="hideUaTip">reverse DNS</span>
                <button
                  v-if="row.url"
                  type="button"
                  class="ar-verreg__chip is-copyable"
                  @mouseenter="showUaTip($event, 'Published ranges: ' + row.url, 'Click to copy', 'right')"
                  @mouseleave="hideUaTip"
                  @click.stop="copyVal(row.url, 'Ranges URL')"
                >IP ranges</button>
              </span>
              <button v-if="!row.builtin" type="button" class="ar-linkbtn ar-verreg__remove" @click="removeVerifier(row)">Remove</button>
            </li>
          </ul>

          <small class="ar-field__hint">
            Verification is only ever a check against what an operator <em>publishes</em>. Turning a crawler off
            here just makes it unverifiable again — nothing gets flagged by its absence.
          </small>
        </div>

        <label class="ar-toggle ar-toggle--standalone">
          <input v-model="settings.store_flagged_ips" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Store IP addresses for flagged clients</strong>
            <small>
              By default Agentimus keeps <strong>no IP addresses</strong>. Turn this on and it stores the IP
              <em>only</em> for clients flagged as an <strong>impersonator</strong> (failed reverse-DNS) or a
              legacy-device <strong>scanner</strong> — never ordinary traffic — so the review card can show you the
              exact addresses to block at your host or CDN. <strong>This is personal data:</strong> it's kept for a
              short time, cleared when you clear the log, and deleted if you switch this back off. Nothing is ever sent
              off your server. <em>(Needs “Verify bot identities” on to catch impersonators.)</em>
            </small>
          </span>
        </label>

        <label class="ar-toggle ar-toggle--standalone">
          <input v-model="settings.identify_bots" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Identify every bot by reverse DNS</strong>
            <small>
              Verification only covers the crawlers in the Verified-bots list. Turn this on to
              reverse-resolve <strong>every</strong> recorded client and show the <strong>network it belongs to</strong>
              — <code>amazonaws.com</code>, <code>openai.com</code>, <code>googlebot.com</code> — so you can see
              <em>what</em> is really accessing your site, not just its self-declared name. Agentimus stores the
              <strong>network, not the IP</strong> (it's org-level, not personal), and verifiable crawlers get their
              verified/impostor verdict from the same lookup. Makes a small outbound DNS lookup per new address
              (cached, and bounded by the same limits as verification). To confirm a single address and see its full
              host, use <strong>Check an IP</strong> below.
            </small>
          </span>
        </label>

        <!-- Ad-hoc identity lookup: paste any IP, see which engine it really is. Self-contained
             (its own REST call); rendered only when the API handle is available. -->
        <IpChecker v-if="api" :api="api" />
      </section>
    </div>

    <Teleport to="body">
      <ClientManager
        v-if="clientManagerOpen"
        :api="api"
        @close="clientManagerOpen = false"
        @changed="$emit('clients-changed', $event)"
      />
    </Teleport>

    <!-- The styled hover bubble (shared uaTip mixin) — body-level like the activity
         tables', so no card or scroll box can clip it. -->
    <Teleport to="body">
      <transition name="ar-tip">
        <div
          v-if="uaTip.show"
          ref="uaTipEl"
          class="ar-act-uatip"
          :class="{ 'is-below': uaTip.below }"
          :style="{ left: uaTip.x + 'px', top: uaTip.y + 'px' }"
          role="tooltip"
          aria-hidden="true"
        ><span class="ar-act-uatip__ua">{{ uaTip.text }}</span><span v-if="uaTip.hint" class="ar-act-uatip__hint">{{ uaTip.hint }}</span><span class="ar-act-uatip__caret" :style="{ left: uaTip.caret + 'px' }"></span></div>
      </transition>
    </Teleport>

    <!-- ============================================================ -->
    <!-- EXPOSURE — limit what anonymous bots & scanners can read     -->
    <!-- ============================================================ -->
    <div v-show="group === 'exposure'" class="ar-group" data-group="exposure">
      <section id="ar-sec-exposure" class="ar-card">
        <h2 class="ar-card__title">Exposure</h2>
        <p class="ar-card__lead">
          The opposite of Discovery: stop your site quietly over-sharing with crawlers and
          scanners. Every control here is off by default and affects only anonymous visitors — you
          and your editors are never restricted.
        </p>

        <!-- WordPress debug posture: a subtle green line when fine, a prominent amber/red
             card when debug logging/display is left on in production. Read-only — the fix
             is a wp-config.php edit Agentimus won't make. -->
        <div v-if="debug && debug.state" class="ar-dbgcard" :class="'is-' + debug.state">
          <span v-if="debug.state === 'pass'" class="ar-dbgcard__ok">✓ {{ debug.message }}</span>
          <template v-else>
            <strong class="ar-dbgcard__title">{{ debug.message }}</strong>
            <p v-if="debug.fix" class="ar-dbgcard__fix">
              {{ debug.fix }}
              <a v-if="debug.fixUrl" :href="debug.fixUrl" target="_blank" rel="noopener">How to fix</a>
            </p>
          </template>
        </div>

        <label v-for="c in exposureControls" :id="'ar-exp-' + c.key" :key="c.key" class="ar-toggle">
          <input v-model="settings[c.key]" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>{{ c.label }}</strong>
            <small>{{ c.hint }}</small>
          </span>
        </label>

        <!-- Custom paths for the exposed-files self-check. The scan itself runs in
             Readiness (browser-side); this just curates what it looks for. -->
        <div class="ar-field" id="ar-exp-scan">
          <label>Also scan these paths for exposure <span class="ar-field__tag">optional</span></label>
          <TagInput v-model="settings.exposed_extra_paths" placeholder="Add a filename or path, e.g. backup.zip or /private/export.csv" />
          <small class="ar-field__hint">
            The built-in list already covers the common ones (config backups, <code>.env</code>, keys, database
            dumps) — add anything site-specific you don’t want public. Don’t know the exact location? Just enter a
            <strong>filename</strong> (<code>backup.zip</code>) and it’s checked at your site root and in
            <code>wp-content</code> and <code>uploads</code>; or give a <strong>full path</strong>
            (<code>/private/export.csv</code>) to check exactly that. Run it under
            <strong>Readiness → Scan for exposed files</strong>.
          </small>
        </div>
      </section>

      <!-- Agent access ---------------------------------------------------
           Its own card rather than another row in the list above, for one reason: that
           card's lead promises "every control here is off by default", and this one is ON.
           Folding it in would quietly make that sentence untrue. -->
      <section id="ar-sec-agent-access" class="ar-card">
        <h2 class="ar-card__title">Agent Access</h2>
        <p class="ar-card__lead">
          The other side of Exposure: not what your site gives out, but who can get in. An
          application password lets a program act as you through WordPress’s API. It keeps working
          even after you change your password. That is why one appearing without warning is worth
          knowing about.
        </p>

        <label id="ar-aa-events" class="ar-toggle">
          <input v-model="settings.agent_access_events" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Record agent access</strong>
            <small>
              Keep a record of application passwords being created or used, and abilities being
              run. Stores no IP addresses and no personal data. It records — it never blocks.
            </small>
          </span>
        </label>

        <p class="ar-card__note">
          Read it under <strong>More → Agent Access</strong>.
        </p>
      </section>
    </div>

    <!-- ============================================================ -->
    <!-- ADVANCED — trust, developer & maintenance                    -->
    <!-- ============================================================ -->
    <div v-show="group === 'sources'" class="ar-group" data-group="sources">
      <!-- Cloudflare — the first data source. Setup lives here; the numbers it
           produces render on the Request Log screen, next to the log they explain.
           Future sources (Search Console, Analytics) join this group as sections. -->

      <CloudflareCard :settings="settings" :api="api" :active="group === 'sources'" />

      <GoogleCard :api="api" :active="group === 'sources'" @navigate="$emit('navigate', $event)" />

      <!-- Bing — the second data source: the index ChatGPT search reads today.
           Setup is two pastes; the verification tag is printed by Agentimus
           itself, so there is no file upload and no DNS. -->

      <BingCard :settings="settings" :api="api" :active="group === 'sources'" :indexnowKeyUrl="indexnowKeyUrl" />
    </div>

    <div v-show="group === 'advanced'" class="ar-group" data-group="advanced">
      <!-- Security.txt ------------------------------------------------- -->
      <section id="ar-sec-security" class="ar-card">
        <h2 class="ar-card__title">Security Contact</h2>
        <p class="ar-card__lead">
          If someone spots a security problem on your site, this tells them where to report it —
          published at the standard place (<code>/.well-known/security.txt</code>) that researchers and
          AI assistants look. <strong>What to do:</strong> turn it on and add one contact (usually your email).
          It steps aside automatically if your site already provides one.
        </p>

        <label id="ar-feat-enable_security_txt" class="ar-toggle">
          <input v-model="settings.enable_security_txt" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Publish a security contact</strong>
            <small>So researchers know how to reach you to report a problem responsibly, instead of disclosing it publicly.</small>
          </span>
        </label>

        <div :inert="!settings.enable_security_txt">
          <p v-if="!hasSecurityContact" class="ar-card__note ar-warn">
            Add at least one contact below (or a public contact email under Identity) —
            the standard requires one, so until then nothing is served.
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

      <!-- Endpoints (hidden when the rail shows them; returns on narrow screens) —
           kept ABOVE the zone break so the developer & maintenance zone stays a
           coherent pair on phones. -->
      <section class="ar-card ar-card--muted ar-card--endpoints">
        <h2 class="ar-card__title">Live Endpoints</h2>
        <ul class="ar-links">
          <li><a :href="endpoints.llms" target="_blank" rel="noopener">{{ endpoints.llms }}</a></li>
          <li><a :href="endpoints.llmsFull" target="_blank" rel="noopener">{{ endpoints.llmsFull }}</a></li>
          <li><a :href="endpoints.robots" target="_blank" rel="noopener">{{ endpoints.robots }}</a></li>
        </ul>
      </section>

      <!-- The tab's closing zone — developer & maintenance. The cards wear the
           normal surface like every other; the labeled hairline on the canvas is
           what marks where the everyday settings end. -->
      <div class="ar-zonebreak"><span>Developer &amp; maintenance</span></div>

      <!-- Advanced / Developer (collapsed; Authenticated API lives here) -->
      <section class="ar-card ar-adv">
        <button
          type="button"
          class="ar-adv__toggle ar-reset"
          :aria-expanded="showAdvanced ? 'true' : 'false'"
          aria-controls="ar-adv-body"
          @click="showAdvanced = !showAdvanced"
        >
          <span class="ar-reset__text">
            <strong>Authenticated API <span class="ar-field__tag">developer</span></strong>
            <small>Authenticated-API discovery for sites with a login-protected API. Most sites don’t need this.</small>
          </span>
          <svg class="ar-adv__chev" :class="{ 'is-open': showAdvanced }" viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6l4 4 4-4" /></svg>
        </button>

        <div v-if="showAdvanced" id="ar-adv-body" class="ar-adv__body">
          <h3 class="ar-adv__title">Authenticated API <span class="ar-field__tag">optional</span></h3>
          <p class="ar-card__lead">
            Only for a site whose API apps or AI assistants <strong>log into</strong> — a headless build or app backend that uses OAuth.
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
              <code>/.well-known/oauth-protected-resource</code> so AI assistants can find the login. <strong>Check</strong> confirms it’s live on your site.
            </p>
            <p v-if="oauthCheck" class="ar-oauth__msg" :class="oauthCheckClass" role="status" aria-live="polite">{{ oauthCheck.msg }}</p>
          </div>
        </div>
      </section>

      <!-- Manage setup: a guided (non-destructive) review and a destructive
           reset, grouped in ONE block so they read as related lifecycle actions.
           The red button carries the danger cue. -->
      <section class="ar-card ar-manage">
        <div class="ar-reset">
          <div class="ar-reset__text">
            <strong>Setup Guide</strong>
            <small>Re-open the guided setup with your current answers filled in — review or fine-tune who you are and what AI assistants can read. <em>Nothing is reset.</em></small>
          </div>
          <button type="button" class="ar-btn ar-btn--ghost" @click="$emit('reopen-wizard')">Review setup</button>
        </div>

        <hr class="ar-manage__sep" />

        <div class="ar-reset">
          <div class="ar-reset__text">
            <strong>Reset to Defaults</strong>
            <small>Wipe every setting back to the recommended factory defaults. This also <em>clears your identity profile</em> (name, about, links) and can’t be undone.</small>
          </div>
          <button type="button" class="ar-btn ar-btn--danger" :disabled="resetting" @click="openReset">
            {{ resetting ? 'Resetting…' : 'Reset all' }}
          </button>
        </div>
      </section>
    </div>
      </div>
    </div>

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
              <h2 id="ar-reset-title" class="ar-modal__title">Reset to Defaults?</h2>
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
