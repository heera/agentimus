<script>
import TagInput from './TagInput.vue';
import SelectMenu from './SelectMenu.vue';
import IpChecker from './IpChecker.vue';
import ClientManager from './ClientManager.vue';
import { bindDocEsc } from '../docEsc.js';

export default {
  name: 'SettingsForm',
  components: { TagInput, IpChecker, SelectMenu, ClientManager },
  props: {
    settings: { type: Object, required: true },
    retentionChoices: { type: Array, default: () => [7, 14, 30, 60, 90, 180, 365] },
    maxRowsChoices: { type: Array, default: () => [10000, 25000, 50000, 100000, 250000] },
    busy: { type: Boolean, default: false },
    api: { type: Object, default: null },
    entityTypes: { type: Array, default: () => ['Person', 'Organization', 'LocalBusiness', 'Store'] },
    postTypes: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] }, // {id, name} for the evergreen picker.
    knownTrainers: { type: Array, default: () => [] },
    knownScanners: { type: Array, default: () => [] },
    knownAllowed: { type: Array, default: () => [] },
    defaultAllowed: { type: Array, default: () => [] },
    webmcpTools: { type: Array, default: () => [] },
    mcpServer: { type: Object, default: () => ({}) }, // {endpoint, abilitiesAvailable, adapterAvailable} for the MCP-server card.
    debug: { type: Object, default: () => ({}) },
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
  emits: ['save-profile', 'save-services', 'reset', 'reopen-wizard', 'clients-changed'],
  data() {
    return {
      // Which settings group the sub-nav is showing. One group is visible at a
      // time so the page reads as a few focused screens, not one long scroll.
      // Identity leads — the highest-signal section, and where a new owner starts.
      group: 'identity',
      clientManagerOpen: false,
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
      mcpCopied: false,
      // The MCP connect helper. The pasted/minted application password lives ONLY
      // in this component's state — never saved, never sent anywhere except into
      // the config text the user copies. Navigating away forgets it.
      mcpClient: 'claude-desktop',
      mcpKeyName: 'Claude Desktop', // follows the picker until the user edits it
      mcpKeyNameTouched: false,
      mcpPassword: '',
      mcpKeyCreating: false,
      mcpKeyError: '',
      mcpKeyWarn: '', // the duplicate-name case — a warning with a way out, not a failure
      mcpKeyWarnOpen: false, // the "?" dialog carrying that warning's full story
      mcpScopeOpen: false, // the "?" dialog behind the key-scope fact in the step head
      mcpKeyCreated: '', // name of the key just minted — powers the "shown once" note
      mcpKeyCopied: false,
      mcpSnippetCopied: false,
      mcpSnippetOpen: false, // the raw config, collapsed by default — most people just copy
      // The live status probe. 'checking' until the browser has asked the endpoint;
      // the saved-state snapshot is what separates "off until you save" from "down".
      mcpProbe: 'checking',
      mcpSavedEnabled: false,
      mcpTestRunning: false,
      mcpTestChecks: [],
    };
  },
  mounted() {
    window.addEventListener('resize', this.updateScrollHint);
    // Status probe for the MCP card — only meaningful for the SAVED state; a
    // freshly flipped toggle shows "turns on when you save" instead.
    this.mcpSavedEnabled = !!(this.settings && this.settings.enable_mcp_server);
    if (this.mcpSavedEnabled) this.probeMcpStatus();
  },
  beforeUnmount() {
    window.removeEventListener('resize', this.updateScrollHint);
    if (this._unEscReset) this._unEscReset();
    if (this._unEscTaken) this._unEscTaken();
    if (this._unEscScope) this._unEscScope();
  },
  watch: {
    // Document-level Esc while the reset dialog is open — the panel-scoped
    // handler dies as soon as focus leaves the panel (e.g. a backdrop click).
    showReset(open) {
      if (this._unEscReset) this._unEscReset();
      this._unEscReset = open ? bindDocEsc(() => this.closeReset()) : null;
    },
    // A finished save is the moment the MCP switch actually takes effect — refresh
    // the saved-state snapshot and re-probe, so the status line follows reality.
    busy(now, was) {
      if (was && !now) {
        this.mcpSavedEnabled = !!(this.settings && this.settings.enable_mcp_server);
        if (this.mcpSavedEnabled) this.probeMcpStatus();
      }
    },
    // A different key invalidates any test verdict on screen.
    mcpPassword() {
      this.mcpTestChecks = [];
    },
    // Doc-level Esc for the duplicate-name explainer, same convention as every
    // dialog here — a panel-scoped handler dies as soon as focus leaves it.
    mcpKeyWarnOpen(open) {
      if (this._unEscTaken) this._unEscTaken();
      this._unEscTaken = open ? bindDocEsc(() => { this.mcpKeyWarnOpen = false; }) : null;
    },
    mcpScopeOpen(open) {
      if (this._unEscScope) this._unEscScope();
      this._unEscScope = open ? bindDocEsc(() => { this.mcpScopeOpen = false; }) : null;
    },
  },
  computed: {
    retentionOptions() {
      return this.retentionChoices.map((d) => ({
        value: d,
        label: d === 365 ? '1 year' : d % 30 === 0 && d >= 60 ? `${d / 30} months` : `${d} days`,
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
    mcpClients() {
      // Desktop-app agents lead (most Agentimus owners aren't coders), the
      // developer CLIs follow. Code editors (Cursor, VS Code, Windsurf) are
      // deliberately not featured — their users are served by "Other tools",
      // and the manual keeps per-editor recipes. keyName is the suggested
      // application-password name where the tab label is too compound for one.
      return [
        { key: 'claude-desktop', label: 'Claude Desktop' },
        { key: 'codex', label: 'ChatGPT / Codex', keyName: 'Codex' },
        { key: 'claude-code', label: 'Claude Code' },
        { key: 'other', label: 'Other tools' },
      ];
    },
    mcpAppPw() {
      return (this.mcpServer && this.mcpServer.appPasswords) || {};
    },
    mcpUsername() {
      return (this.mcpServer && this.mcpServer.username) || 'YOUR-USERNAME';
    },
    // WordPress shows application passwords as "xxxx xxxx xxxx …" and ignores the
    // spaces when checking them — strip them so the header is canonical.
    mcpPasswordClean() {
      return (this.mcpPassword || '').replace(/\s+/g, '');
    },
    // "Basic <this>" — base64 of user:password, computed locally. btoa() rejects
    // characters above U+00FF (a non-ASCII username), so fall back through a
    // UTF-8 byte encoding, which is what the server decodes anyway.
    mcpAuthB64() {
      if (!this.mcpPasswordClean) return '';
      const raw = `${this.mcpUsername}:${this.mcpPasswordClean}`;
      try {
        return btoa(raw);
      } catch (e) {
        return btoa(unescape(encodeURIComponent(raw)));
      }
    },
    // The placeholder keeps an uncredentialed snippet honest: it's visibly not a
    // value, and the nudge under the box says how to fill it.
    mcpAuthShown() {
      return this.mcpAuthB64 || '<KEY>';
    },
    mcpSnippet() {
      const url = (this.mcpServer && this.mcpServer.endpoint) || '';
      const auth = this.mcpAuthShown;
      switch (this.mcpClient) {
        case 'claude-code':
          return `claude mcp add --transport http agentimus ${url} \\\n  --header "Authorization: Basic ${auth}"`;
        case 'claude-desktop':
          // Desktop's connector UI can't send a login header (OAuth only), so this
          // rides the mcp-remote bridge. The header value goes through env with NO
          // space after the colon in args — Claude Desktop on Windows mangles
          // spaces inside args (documented mcp-remote workaround); env is safe.
          return [
            '{',
            '  "mcpServers": {',
            '    "agentimus": {',
            '      "command": "npx",',
            '      "args": [',
            '        "-y", "mcp-remote",',
            `        "${url}",`,
            '        "--header", "Authorization:${AGENTIMUS_AUTH}"',
            '      ],',
            `      "env": { "AGENTIMUS_AUTH": "Basic ${auth}" }`,
            '    }',
            '  }',
            '}',
          ].join('\n');
        case 'codex':
          return `[mcp_servers.agentimus]\nurl = "${url}"\nhttp_headers = { "Authorization" = "Basic ${auth}" }`;
        default:
          return [
            'Transport   Streamable HTTP (MCP)',
            `URL         ${url}`,
            'Auth        HTTP Basic — WordPress username + application password',
            `Header      Authorization: Basic ${auth}`,
            '',
            'Tool only speaks stdio? Bridge it:',
            `  npx -y mcp-remote ${url} --header "Authorization: Basic ${auth}"`,
          ].join('\n');
      }
    },
    mcpClientLabel() {
      const c = this.mcpClients.find((x) => x.key === this.mcpClient);
      return c ? c.label : 'AI tool';
    },
    // Plain-HTTP endpoints trip two different wires — most MCP clients refuse
    // insecure remote hosts, and WordPress won't issue application passwords
    // without TLS off a local machine — so say it once, up front.
    mcpEndpointInsecure() {
      return /^http:\/\//i.test((this.mcpServer && this.mcpServer.endpoint) || '');
    },
    // Generic on purpose: the active tab already names the tool, and the step
    // head says "Copy the setup" — repeating the client name here was noise.
    mcpCopyLabel() {
      return this.mcpClient === 'other' ? 'Copy the connection facts' : 'Copy the setup';
    },
    // What the status strip shows. 'unsaved' wins over the probe: the switch only
    // takes effect on save, and claiming "running"/"down" before that would lie
    // in one direction or the other.
    mcpStatus() {
      if (this.settings && this.settings.enable_mcp_server && !this.mcpSavedEnabled) return 'unsaved';
      return this.mcpProbe;
    },
    // "Last AI tool call: 2 hours ago — key “Claude Code” (admin)." — from Agent
    // access, external clients only (credentialed runs). Silent when the site
    // can't know (no Abilities API hooks): absence of a claim, not a claim of absence.
    mcpLastCallText() {
      const l = this.mcpServer && this.mcpServer.lastToolCall;
      if (!l || !l.known) return '';
      if (!l.call) return 'No AI client has called a tool yet.';
      const key = l.call.key ? `key “${l.call.key}”` : 'a since-revoked key';
      const user = l.call.user ? ` (${l.call.user})` : '';
      return `Last AI tool call: ${this.relTime(l.call.at)} — ${key}${user}.`;
    },
    // Where the config goes and what to do next — the part each tool does differently.
    mcpSnippetHint() {
      switch (this.mcpClient) {
        case 'claude-code':
          return 'Run this in a terminal. Add --scope user to connect it in every folder, not just the current one.';
        case 'claude-desktop':
          return 'Add this to claude_desktop_config.json (Claude Desktop → Settings → Developer → Edit Config), merge into "mcpServers" if the file already has servers, and restart the app. Needs Node.js — Desktop can’t send a login header itself, so the small mcp-remote bridge carries it.';
        case 'codex':
          return 'Add this to ~/.codex/config.toml — the one file covers the Codex CLI, the IDE extension, and Codex in the ChatGPT desktop app (needs a recent Codex; older versions had no HTTP server support). Inside a session, /mcp shows whether it connected.';
        default:
          return 'Any MCP client that speaks Streamable HTTP and can send a header connects with these facts; the bridge line covers tools that only launch local (stdio) servers.';
      }
    },
    // ------------------------------------------------------------------------
    // The settings page is split into a few labelled groups, shown one at a time
    // via the sub-nav. Order runs broad → specific: what you publish, who you
    // are, what bots may do, then the rarely-touched developer/maintenance bits.
    groups() {
      return [
        { key: 'identity', label: 'Identity', hint: 'Who owns this site' },
        { key: 'discovery', label: 'Discovery', hint: 'Files & data AI can read' },
        { key: 'access', label: 'AI access', hint: 'What bots may do — and who to block' },
        { key: 'exposure', label: 'Exposure', hint: 'Limit what your site reveals to bots & scanners' },
        { key: 'advanced', label: 'Advanced', hint: 'Trust, developer & maintenance' },
      ];
    },
    // One-line description of the group on screen, shown under the sub-nav.
    activeGroupHint() {
      const g = this.groups.find((x) => x.key === this.group);
      return g ? g.hint : '';
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
    selectedTypeCount() {
      const sel = Array.isArray(this.settings.post_types) ? this.settings.post_types : [];
      const slugs = this.postTypes.map((p) => p.slug);
      return sel.filter((s) => slugs.includes(s)).length;
    },
    // The read capabilities the CURRENT selection advertises — computed exactly like
    // the discovery adapter does (type REST bases first, then the union of their
    // public taxonomies' bases, deduped), so this preview and the Discovery hub can
    // never disagree. Types the payload marks non-advertising (no restBase) add nothing.
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
    features() {
      // Plain-language labels; the real filename/term stays in the hint so it's
      // always discoverable.
      return [
        { key: 'enable_llms_txt', label: 'AI page guide', hint: 'A plain map of your pages, topics and recent posts for assistants. (file: llms.txt)' },
        { key: 'enable_llms_full', label: 'Full text for AI', hint: 'Bundles your pages and recent posts into one document an assistant can read in a single pass. (file: llms-full.txt)' },
        { key: 'enable_markdown', label: 'Plain-text versions', hint: 'Lets assistants fetch a clean text version of any included page — add .md to its URL.' },
        { key: 'enable_robots', label: 'Crawler rules', hint: 'States your preferences to crawlers and blocks known AI-training bots by name. (file: robots.txt)' },
        { key: 'enable_schema', label: 'Rich data for search', hint: 'Adds structured data search engines and assistants understand (JSON-LD). Leave off if your SEO plugin already does this.' },
        { key: 'enable_page_checks', label: 'AI readability tips', hint: 'Adds an “AI Readability” panel in the post editor with per-page tips (headings, summary, thin content, image alt). Editor-only — nothing is shown to visitors.' },
        // Named for the screen it unlocks, with what it literally does in a quieter aside —
        // "Track AI citations" describes the mechanism, "AI Visibility" is what you look for
        // in the nav. `sub` is optional; no other feature needs one yet.
        { key: 'enable_visibility', label: 'AI Visibility', sub: '(Track AI citations)', hint: 'Shows the AI Visibility screen and adds the “Cited” rung to your score — measures whether AI engines actually name your site in their answers. Off by default: it needs your own AI provider key and spends your credit to run checks.' },
        { key: 'enable_sitemap', label: 'Sitemap (backup)', hint: 'Adds a sitemap only when WordPress core and your SEO plugin don’t already provide one — never duplicates.' },
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
          hint: 'Removes a few rarely-used auto-generated links from your pages’ source code (the short-link and oEmbed embed links), trimming the technical footprint bots scrape. No effect on how your pages look or rank.',
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
        features: [...this.features.map((f) => ({ label: f.label, on: !!d[f.key] })), { label: 'Visit log', on: !!d.enable_activity }],
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
    async copyPlainText(text) {
      if (!text) return false;
      let ok = false;
      // navigator.clipboard needs a secure context (HTTPS or localhost); on plain
      // HTTP it's absent or throws, so fall back to the legacy execCommand path.
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
          ok = true;
        }
      } catch (e) { /* fall through */ }
      if (!ok) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
      }
      return ok;
    },
    async copyMcpEndpoint() {
      if (!(await this.copyPlainText((this.mcpServer && this.mcpServer.endpoint) || ''))) return;
      this.mcpCopied = true;
      clearTimeout(this._mcpCopyTimer);
      this._mcpCopyTimer = setTimeout(() => { this.mcpCopied = false; }, 2000);
    },
    async copyMcpSnippet() {
      if (!(await this.copyPlainText(this.mcpSnippet))) return;
      this.mcpSnippetCopied = true;
      clearTimeout(this._mcpSnippetCopyTimer);
      this._mcpSnippetCopyTimer = setTimeout(() => { this.mcpSnippetCopied = false; }, 2000);
    },
    // Copies the key exactly as the field shows it (spaces and all) — that's the
    // form WordPress displays, and it signs in either way.
    async copyMcpKey() {
      if (!(await this.copyPlainText(this.mcpPassword))) return;
      this.mcpKeyCopied = true;
      clearTimeout(this._mcpKeyCopyTimer);
      this._mcpKeyCopyTimer = setTimeout(() => { this.mcpKeyCopied = false; }, 2000);
    },
    onMcpKeyNameInput() {
      this.mcpKeyNameTouched = true;
      // The warning names a specific name; typing moved on from it.
      this.mcpKeyWarn = '';
      this.mcpKeyWarnOpen = false;
    },
    pickMcpClient(key) {
      this.mcpClient = key;
      // The key-name suggestion follows the picker (one key per tool, named after
      // it, so revoking one later is unambiguous) — but never over a user's edit.
      if (!this.mcpKeyNameTouched) {
        const c = this.mcpClients.find((x) => x.key === key);
        this.mcpKeyName = c && key !== 'other' ? (c.keyName || c.label) : 'AI tool';
        this.mcpKeyWarn = ''; // the warning names a specific name; it moved on
        this.mcpKeyWarnOpen = false;
      }
    },
    // "2 hours ago" for the status strip — coarse on purpose; the precise
    // timestamp lives one click away in Agent access.
    relTime(iso) {
      const t = Date.parse(iso || '');
      if (!t) return 'recently';
      const s = Math.max(0, (Date.now() - t) / 1000);
      if (s < 90) return 'just now';
      if (s < 3600) return `${Math.round(s / 60)} minutes ago`;
      if (s < 172800) { const h = Math.round(s / 3600); return h === 1 ? '1 hour ago' : `${h} hours ago`; }
      const d = Math.round(s / 86400);
      return d < 30 ? `${d} days ago` : new Date(t).toLocaleDateString();
    },
    // Is the server actually answering? Asked over an AUTHENTICATED admin route
    // (agentimus/v1/mcp-status) rather than an unauthenticated GET to the MCP endpoint
    // itself: the endpoint correctly answers 401 to a credential-less request, which
    // the browser logs as a red console error on every admin load. The status route
    // reports the same liveness (is our MCP route registered?) server-side, with a
    // clean 200 via the nonce — so the console stays quiet.
    async probeMcpStatus() {
      this.mcpProbe = 'checking';
      try {
        const res = await this.api.getMcpStatus();
        this.mcpProbe = res && res.running ? 'running' : 'unreachable';
      } catch (e) {
        this.mcpProbe = 'unreachable';
      }
    },
    // The proof step: the exact calls an AI client makes — initialize, then
    // tools/list on the returned session — with the key from step 2 and no
    // cookies, so a pass means the server AND the credential both work.
    async runMcpTest() {
      if (this.mcpTestRunning || !this.mcpAuthB64) return;
      this.mcpTestRunning = true;
      const checks = [];
      this.mcpTestChecks = checks;
      const url = (this.mcpServer && this.mcpServer.endpoint) || '';
      const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json, text/event-stream',
        Authorization: `Basic ${this.mcpAuthB64}`,
      };
      const rpc = (id, method, params) => JSON.stringify({ jsonrpc: '2.0', id, method, params });
      try {
        let res = null;
        try {
          res = await fetch(url, {
            method: 'POST', credentials: 'omit', headers,
            body: rpc(1, 'initialize', {
              protocolVersion: '2025-03-26',
              capabilities: {},
              clientInfo: { name: 'agentimus-settings-test', version: '1' },
            }),
          });
        } catch (e) {
          checks.push({ state: 'fail', label: 'Server reachable', note: 'the request never got an answer — is the site reachable from this browser?' });
          return;
        }
        if (res.status === 404) {
          checks.push({ state: 'fail', label: 'Server reachable', note: 'the address returned “not found” — save the settings with the switch on, then retry' });
          return;
        }
        checks.push({ state: 'ok', label: 'Server reachable' });
        if (res.status === 401 || res.status === 403) {
          checks.push({ state: 'fail', label: 'Sign-in accepted', note: `the site refused this key — check the username (${this.mcpUsername}) and the key itself; a wrong username fails exactly like a wrong password` });
          return;
        }
        checks.push({ state: 'ok', label: 'Sign-in accepted', note: `as ${this.mcpUsername}` });
        let init = null;
        try { init = await res.json(); } catch (e) { /* judged below */ }
        if (!res.ok || !init || !init.result) {
          checks.push({ state: 'fail', label: 'MCP handshake (initialize)', note: `unexpected answer (HTTP ${res.status})` });
          return;
        }
        const server = init.result.serverInfo || {};
        checks.push({ state: 'ok', label: 'MCP handshake (initialize)', note: [server.name, server.version].filter(Boolean).join(' ') });
        let list = null;
        try {
          list = await fetch(url, {
            method: 'POST', credentials: 'omit',
            headers: {
              ...headers,
              'Mcp-Session-Id': res.headers.get('Mcp-Session-Id') || '',
              'MCP-Protocol-Version': init.result.protocolVersion || '2025-03-26',
            },
            body: rpc(2, 'tools/list', {}),
          });
        } catch (e) { /* judged below */ }
        let tools = null;
        if (list && list.ok) {
          try { tools = (await list.json()).result.tools; } catch (e) { /* judged below */ }
        }
        if (Array.isArray(tools)) {
          checks.push({ state: 'ok', label: `Tools listed — ${tools.length} available to this user` });
        } else {
          checks.push({ state: 'fail', label: 'Tools listed', note: list ? `unexpected answer (HTTP ${list.status})` : 'the request never got an answer' });
        }
      } finally {
        this.mcpTestRunning = false;
      }
    },
    // Mint an application password for the signed-in user via core's own REST
    // endpoint. Core returns the plaintext exactly once — it flows straight into
    // the config below and nowhere else.
    async createMcpKey() {
      if (this.mcpKeyCreating || !this.api) return;
      const name = (this.mcpKeyName || '').trim() || 'AI tool';
      this.mcpKeyCreating = true;
      this.mcpKeyError = '';
      this.mcpKeyWarn = '';
      try {
        // Core's REST endpoint accepts duplicate names without complaint, so a
        // double-click would silently mint two identical-looking keys — and the
        // first one lives on, orphaned but valid. Check by name first and say
        // what to do instead.
        const existing = await this.api.listAppPasswords(this.mcpAppPw.endpoint).catch(() => null);
        if (Array.isArray(existing)
          && existing.some((k) => (k.name || '').toLowerCase() === name.toLowerCase())) {
          this.mcpKeyWarn = `A key named “${name}” already exists (see Users → Profile → Application Passwords). Pick a different name, or revoke the old one first — creating a second key with the same name would leave the old one live and the two indistinguishable.`;
          return;
        }
        const res = await this.api.createAppPassword(this.mcpAppPw.endpoint, name);
        this.mcpPassword = (res && res.password) || '';
        this.mcpKeyCreated = (res && res.name) || name;
        if (!this.mcpPassword) {
          // A 2xx without the one-time password would leave a key the user can
          // never use — name that plainly instead of showing an empty config.
          this.mcpKeyCreated = '';
          this.mcpKeyError = 'The key was created but WordPress didn’t return it — revoke it under Users → Profile and try again there.';
        }
      } catch (e) {
        // Core's own message is the honest one here — e.g. a permissions refusal,
        // or application passwords having been switched off since page load.
        this.mcpKeyError = e && e.message ? e.message : 'Could not create the key.';
      } finally {
        this.mcpKeyCreating = false;
      }
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
    addAllow(name) {
      if (!Array.isArray(this.settings.allowed_agents)) this.settings.allowed_agents = [];
      if (!this.settings.allowed_agents.includes(name)) this.settings.allowed_agents.push(name);
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
    <!-- Settings sub-navigation: the page is split into a few labelled groups,
         shown one at a time, so it reads as focused screens instead of one long
         stack. Styled as a segmented control — visually distinct from the
         masthead tabs so the two nav levels never read as the same control. -->
    <div class="ar-tabpanel">
      <nav class="ar-tabpanel__tabs" aria-label="Settings sections">
        <button
          v-for="g in groups"
          :key="g.key"
          type="button"
          class="ar-subnav__item"
          :class="{ 'is-active': group === g.key }"
          :aria-current="group === g.key ? 'page' : null"
          :title="g.hint"
          @click="group = g.key"
        >{{ g.label }}</button>
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
        <p class="ar-card__lead">Toggle each agent-readiness signal.</p>

        <label v-for="f in features" :id="'ar-feat-' + f.key" :key="f.key" class="ar-toggle">
          <input v-model="settings[f.key]" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>{{ f.label }}<span v-if="f.sub" class="ar-toggle__sub">{{ f.sub }}</span></strong>
            <small><template v-for="(p, i) in specHintParts(f.hint)" :key="i"><a v-if="p.href" :href="p.href" class="ar-spec-link" target="_blank" rel="noopener noreferrer">{{ p.term }}</a><template v-else>{{ p.text }}</template></template></small>
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

      <!-- Visit log — master toggle + CDN-mode sub-option. A feature with a situational
           nested setting, like Topics and Browser tools below; kept out of the Features
           list because it's monitoring, not an agent-readiness signal the site emits. -->
      <section id="ar-sec-activity" class="ar-card">
        <h2 class="ar-card__title">Visit log</h2>
        <p class="ar-card__lead">
          Records which AI assistants fetch your AI files, and counts the visitors AI sends you
          (“Traffic from AI”). Everything is stored on your own site — no IP addresses by default (one optional
          setting stores IPs for flagged crawlers only), nothing sent anywhere. You read the summary on the
          Dashboard, and the full reports under More → AI Traffic and More → Request Log.
        </p>

        <label id="ar-feat-enable_activity" class="ar-toggle">
          <input v-model="settings.enable_activity" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Record AI activity &amp; referrals</strong>
            <small>Powers the Dashboard and both reports: which assistants fetch your files, and who AI sends your way.</small>
          </span>
        </label>

        <div v-show="settings.enable_activity" class="ar-webmcp-tools">
          <label id="ar-feat-log_unknown_referrers" class="ar-toggle">
            <input v-model="settings.log_unknown_referrers" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Find missed AI sources (diagnostic)</strong>
              <small>“Traffic from AI” only counts assistants it recognises, and a miss leaves no trace. Turn this on and Agentimus also lists the referrers it <em>couldn’t</em> name — so you can see whether an assistant is being overlooked. Records the site name and <code>utm_source</code> tag only: still no IPs, nothing sent anywhere. It writes a row for every visit referred from another site, so switch it on for a week, read the list under More → AI Traffic, then switch it off.</small>
            </span>
          </label>

          <!-- How long records live, and the ceiling that applies either way. -->
          <label id="ar-feat-activity_auto_prune" class="ar-toggle">
            <input v-model="settings.activity_auto_prune" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Delete old records automatically</strong>
              <small>Each night, anything older than the period below is removed. Turn this off and records are kept until the log reaches its size cap — then the oldest are dropped to make room. Either way the log can’t grow without limit.</small>
            </span>
          </label>

          <div v-show="settings.activity_auto_prune" class="ar-field ar-field--inline ar-field--log">
            <label id="ar-lbl-retention">Keep records for</label>
            <SelectMenu
              v-model="settings.activity_retention_days"
              :options="retentionOptions"
              aria-label="How long to keep activity records"
            />
          </div>

          <div class="ar-field ar-field--inline ar-field--log">
            <label id="ar-lbl-maxrows">Size cap</label>
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
            The size cap wins over the period: on a busy site the log can reach it before records are
            old enough to expire, and the oldest are removed anyway.
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
        </div>
      </section>

      <!-- Caching & CDN — how Agentimus copes when a page cache/CDN fronts the site: it can
           hide agent fetches from the log and serve stale AI files. -->
      <section id="ar-sec-caching" class="ar-card">
        <h2 class="ar-card__title">Caching &amp; CDN</h2>
        <p class="ar-card__lead">
          A full-page cache or CDN in front of your site (Cloudflare, an Nginx or Varnish cache, a caching
          plugin) speeds up your pages — but it can get between AI and Agentimus two ways: it can serve stored
          copies of your AI files (so agent fetches aren’t logged and the files go stale), and it can hide the
          visitors AI sends you (so “Traffic from AI” under-counts). These settings handle both. None of them
          matter if nothing caches your site.
        </p>

        <label v-show="settings.enable_activity" id="ar-feat-enable_referral_beacon" class="ar-toggle">
          <input v-model="settings.enable_referral_beacon" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>CDN mode — count AI visits in the browser</strong>
            <small>Turn on only if your site sits behind a full-page cache/CDN (e.g. Cloudflare “Cache Everything”). It counts “Traffic from AI” in the visitor’s browser so the number survives the cache. Adds a tiny counting script to your pages. A few visitors — those using an ad-blocker or a privacy-focused browser that blocks scripts like this — won’t be counted, so read the total as a minimum, never an over-count.</small>
          </span>
        </label>

        <label id="ar-feat-bypass_shared_cache" class="ar-toggle">
          <input v-model="settings.bypass_shared_cache" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Keep AI endpoints out of your cache</strong>
            <small>If a cache or CDN sits in front of your site, it can serve stored copies of your AI files (<code>llms.txt</code>, the <code>.well-known</code> docs, the change feed) — so those agent fetches never reach WordPress, the log under-counts them, and the change feed can go stale. Turn this on and Agentimus asks caches not to store those files (a <code>no-store</code> header), so each fetch reaches WordPress and is counted and current. It works with any cache that respects that header; a cache told to “cache everything” or ignore origin headers still needs a rule set there. If nothing sits in front of your site, leave it off — it trades a little edge-caching on those endpoints.</small>
          </span>
        </label>

        <label id="ar-feat-purge_cache_on_change" class="ar-toggle">
          <input v-model="settings.purge_cache_on_change" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Refresh AI files when content changes</strong>
            <small>When you publish or edit a post, your page cache refreshes that page — but not your AI files, so a cache can keep serving a stale <code>llms.txt</code>, change feed or <code>.md</code> twin until its own timer runs out. Turn this on and Agentimus asks every cache it can find (WP Rocket, Nginx Helper, W3 Total Cache, LiteSpeed, WP Super Cache, Cache Enabler…) to drop those files on each content change, so agents never get a stale copy after an edit. On by default; it does nothing if no page cache is installed. This keeps files <em>fresh</em> — it doesn’t change the log count (for that, use the switch above).</small>
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

        <div v-show="settings.enable_topics" class="ar-webmcp-tools">
          <label id="ar-feat-topics_derive_default" class="ar-toggle">
            <input v-model="settings.topics_derive_default" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Use tags &amp; categories by default</strong>
              <small>New content fills its topics from its own tags and categories automatically. Anything you type overrides this.</small>
            </span>
          </label>

          <div class="ar-field ar-field--inline">
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
        <h2 class="ar-card__title">AI description</h2>
        <p class="ar-card__lead">
          Adds a one-line summary to your content’s AI data — the structured data (JSON-LD
          <code>description</code>) and the plain-text (<code>.md</code>) version — so assistants
          summarise and cite it correctly. You write it in the editor; blank pages fall back to the
          excerpt. Stands aside for a dedicated SEO plugin, like the rest of Agentimus.
        </p>

        <label id="ar-feat-enable_ai_description" class="ar-toggle">
          <input v-model="settings.enable_ai_description" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Add a description to your content’s AI data</strong>
            <small>Shows an “AI description” box in the editor.</small>
          </span>
        </label>

        <div v-show="settings.enable_ai_description" class="ar-webmcp-tools">
          <label id="ar-feat-ai_description_meta_tag" class="ar-toggle">
            <input v-model="settings.ai_description_meta_tag" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Set the page meta description too</strong>
              <small>Uses your description as the page’s meta description tag — replacing your theme’s — unless a dedicated SEO plugin manages it. Turn off to enrich only the AI data and leave your <code>&lt;head&gt;</code> alone.</small>
            </span>
          </label>
        </div>
      </section>

      <!-- Browser tools (WebMCP) — master toggle + per-tool expose/hide - -->
      <section id="ar-sec-webmcp" class="ar-card">
        <h2 class="ar-card__title">Browser tools <span class="ar-card__tag">experimental</span></h2>
        <p class="ar-card__lead">
          Lets an AI agent working inside a browser call your site’s read-only tools (like site
          search) directly, via the emerging <strong>WebMCP</strong> browser standard. It adds a
          tiny script that does nothing in browsers without support. Off by default — turn it on
          only to be an early adopter.
        </p>

        <label id="ar-feat-enable_webmcp" class="ar-toggle">
          <input v-model="settings.enable_webmcp" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Offer browser tools to AI agents</strong>
            <small>Registers the read-only tools below with the browser, for agents that support WebMCP.</small>
          </span>
        </label>

        <div v-show="settings.enable_webmcp" class="ar-webmcp-tools">
          <p v-if="!webmcpTools.length" class="ar-field__hint">No browser tools are registered yet.</p>
          <template v-else>
            <p class="ar-webmcp-tools__head">
              Tools offered to agents — turn one off to hide it (it won’t be registered with the browser at all).
            </p>
            <label v-for="t in webmcpTools" :key="t.name" class="ar-toggle">
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

      <!-- MCP server — master toggle + connection details --------------- -->
      <section id="ar-sec-mcp" class="ar-card">
        <h2 class="ar-card__title">MCP server <span class="ar-card__tag">experimental</span></h2>
        <p class="ar-card__lead">
          Lets AI tools you already use — Claude Desktop, the ChatGPT app (through its Codex
          side), Claude Code, the Codex CLI — talk to this site over the
          <strong>Model Context Protocol</strong>. A connected tool acts as your WordPress
          user: it signs in first, and it can only do what that user could on these
          screens — reading only, unless you also allow writing below. Nothing becomes
          public. (ChatGPT’s own connector screen can’t connect — it’s OAuth-only; its
          Codex side is the way in.)
        </p>

        <p v-if="mcpServer.abilitiesAvailable === false" class="ar-field__hint">
          Needs WordPress 6.9 or newer (the Abilities API). The switch below won’t do anything on
          this site yet.
        </p>
        <p v-else-if="mcpServer.adapterAvailable === false" class="ar-field__hint">
          Development checkout — the bundled MCP library is missing. Run <code>composer install</code>
          in the plugin folder.
        </p>

        <label id="ar-feat-enable_mcp_server" class="ar-toggle">
          <input v-model="settings.enable_mcp_server" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Run the Agentimus MCP server</strong>
            <small>Every call needs a WordPress login (an application password works) and the same permissions as these admin screens. Read-only tools, unless you allow writing below.</small>
          </span>
        </label>

        <!-- The write tier: a second deliberate switch, plus a third for going live.
             Off = the write tools don't exist on any surface, so "read-only" above
             stays literally true until the owner says otherwise. -->
        <div v-show="settings.enable_mcp_server" class="ar-webmcp-tools">
          <label id="ar-feat-enable_agent_writes" class="ar-toggle">
            <input v-model="settings.enable_agent_writes" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Let connected agents write</strong>
              <small>Adds write tools: draft and edit posts and pages — including categories, tags and the featured image — set their AI topics and descriptions, and apply Readiness fixes (a fixed list of safe switches — it can only turn documented features on, never loosen a protection). An agent still acts as the signed-in user and can never do more than that user could in the editor. Every write lands under <a href="#agent-access">Agent Access</a>.</small>
            </span>
          </label>
          <div v-show="settings.enable_agent_writes" class="ar-webmcp-tools">
            <label id="ar-feat-agent_writes_publish" class="ar-toggle">
              <input v-model="settings.agent_writes_publish" type="checkbox" />
              <span class="ar-toggle__track" aria-hidden="true"></span>
              <span class="ar-toggle__text">
                <strong>…including publishing, without your review</strong>
                <small>Lets an agent put content live (and it still needs a user allowed to publish). Off — the safe default — means agents only create drafts and pending posts for you to review; editing something already published follows that user’s normal edit permission either way.</small>
              </span>
            </label>
          </div>
        </div>

        <div v-show="settings.enable_mcp_server" class="ar-mcp-connect">
          <!-- Status: is the server actually answering, and has anything used it?
               The probe runs in the browser because the adapter's state genuinely
               cannot be known during an admin page load (it boots on rest_api_init). -->
          <div class="ar-mcp-status">
            <p class="ar-mcp-status__state" :data-state="mcpStatus">
              <span class="ar-mcp-status__dot" aria-hidden="true"></span>
              <template v-if="mcpStatus === 'running'">Running — the address answers and asks callers to sign in.</template>
              <template v-else-if="mcpStatus === 'unsaved'">Turns on when you save.</template>
              <template v-else-if="mcpStatus === 'unreachable'">Not answering — the address returned “not found”. Re-save the settings; if it persists, something in front of the site may be caching REST responses.</template>
              <template v-else>Checking the server…</template>
            </p>
            <p v-if="mcpLastCallText" class="ar-mcp-status__last">
              {{ mcpLastCallText }} <a href="#agent-access">Agent Access →</a>
            </p>
          </div>
          <p v-if="mcpEndpointInsecure" class="ar-field__hint">
            The address is plain <code>http://</code> — many AI clients refuse insecure
            connections to anything but a local machine. Fine for local development; a live
            site needs HTTPS (WordPress won’t issue application passwords without it anyway).
          </p>

          <!-- ① the tool -->
          <div class="ar-mcp-step">
            <p class="ar-mcp-step__head"><span class="ar-mcp-step__n" aria-hidden="true">1</span>Pick your AI tool</p>
            <div class="ar-rev-tabs ar-mcp-tabs" role="tablist" aria-label="AI tool">
              <button
                v-for="c in mcpClients"
                :key="c.key"
                type="button"
                class="ar-rev-tab"
                :class="{ 'is-active': mcpClient === c.key }"
                role="tab"
                :aria-selected="mcpClient === c.key"
                @click="pickMcpClient(c.key)"
              >{{ c.label }}</button>
            </div>
          </div>

          <!-- ② the key. One click mints an application password for the signed-in
               user via core's own endpoint; pasting an existing one works too. -->
          <div class="ar-mcp-step">
            <p class="ar-mcp-step__head">
              <span class="ar-mcp-step__n" aria-hidden="true">2</span>Give it a key
              <span v-if="mcpAppPw.available" class="ar-mcp-scope">
                — signs in as <strong>{{ mcpUsername }}</strong>, whole REST API
                <button
                  type="button"
                  class="ar-mcp-key__whybtn ar-mcp-key__whybtn--info"
                  aria-label="What that means, and how to stay safe"
                  title="What that means, and how to stay safe"
                  @click="mcpScopeOpen = true"
                >?</button>
              </span>
            </p>
            <div v-if="mcpAppPw.available" class="ar-mcp-key">
              <div class="ar-mcp-key__paths">
                <!-- The normal path: mint a fresh key. The name is REQUIRED and
                     belongs to WordPress's password record — it's how Agent
                     access attributes calls and how you revoke just this tool. -->
                <div class="ar-mcp-key__path">
                  <p class="ar-mcp-key__pathlabel">
                    Create a fresh key
                    <span v-if="mcpKeyWarn" class="ar-mcp-key__taken" role="alert">
                      That name is taken.
                      <button
                        type="button"
                        class="ar-mcp-key__whybtn"
                        aria-label="Why, and what to do"
                        title="Why, and what to do"
                        @click="mcpKeyWarnOpen = true"
                      >?</button>
                    </span>
                  </p>
                  <div class="ar-mcp-key__row">
                    <input
                      v-model="mcpKeyName"
                      type="text"
                      class="ar-input ar-mcp-key__name"
                      aria-label="Name for the new key (required)"
                      placeholder="Name, e.g. Claude Code"
                      @input="onMcpKeyNameInput"
                    />
                    <button
                      type="button"
                      class="ar-btn ar-mcp-key__create"
                      :disabled="mcpKeyCreating || !mcpKeyName.trim()"
                      :title="mcpKeyName.trim() ? '' : 'Give the key a name first'"
                      @click="createMcpKey"
                    >{{ mcpKeyCreating ? 'Creating…' : 'Create key' }}</button>
                  </div>
                  <p class="ar-field__hint">
                    Makes a new application password with this name — the name is how you’ll
                    spot the tool in <strong>Agent Access</strong>, and revoke it alone later.
                  </p>
                </div>
                <!-- The rare path: a password saved at creation time (WordPress
                     never shows one again). The name field doesn't apply here. -->
                <div class="ar-mcp-key__path">
                  <p class="ar-mcp-key__pathlabel">Or paste one you saved</p>
                  <div class="ar-mcp-key__row">
                    <span class="ar-mcp-key__pastewrap">
                      <input
                        v-model="mcpPassword"
                        type="text"
                        class="ar-input ar-mcp-key__paste"
                        placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
                        autocomplete="off"
                        spellcheck="false"
                        aria-label="Existing application password"
                      />
                      <button
                        v-if="mcpPassword"
                        type="button"
                        class="ar-mcp-key__copy"
                        :class="{ 'is-copied': mcpKeyCopied }"
                        :aria-label="mcpKeyCopied ? 'Copied' : 'Copy the key'"
                        :title="mcpKeyCopied ? 'Copied' : 'Copy the key'"
                        @click="copyMcpKey"
                      >
                        <svg v-if="!mcpKeyCopied" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1" /></svg>
                        <svg v-else viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5 9.5 18 20 6.5" /></svg>
                      </button>
                    </span>
                  </div>
                  <p class="ar-field__hint">
                    WordPress shows a password only at the moment it’s created — if you kept one
                    (a password manager, usually), paste it and the setup below completes
                    instantly. It keeps the name it was created under; the name field on the
                    left doesn’t apply to it.
                  </p>
                </div>
              </div>
              <p v-if="mcpKeyError" class="ar-field__hint ar-mcp-key__err" role="alert">{{ mcpKeyError }}</p>
              <p v-else-if="mcpKeyCreated" class="ar-field__hint" role="status">
                Key “{{ mcpKeyCreated }}” created for <strong>{{ mcpUsername }}</strong> and filled
                into the setup below — copy it now, this is the only time your site shows it.
                Nothing is stored in this page or the plugin.
              </p>
            </div>
            <p v-else class="ar-field__hint">
              This site can’t issue <strong>application passwords</strong> (WordPress turns them off
              without HTTPS, and some security plugins disable them) — and without them AI tools
              have no way to sign in here, so connecting won’t work until that changes.
            </p>
          </div>

          <!-- ③ the setup, one button. The raw config stays a click away rather
               than dominating the card — most people just paste it on. -->
          <div class="ar-mcp-step">
            <p class="ar-mcp-step__head"><span class="ar-mcp-step__n" aria-hidden="true">3</span>Copy the setup</p>
            <div class="ar-mcp-copyrow">
              <button
                type="button"
                class="ar-btn ar-mcp-copybtn"
                :disabled="!mcpAuthB64"
                :title="mcpAuthB64 ? '' : 'Create or paste a key in step 2 first'"
                @click="copyMcpSnippet"
              >
                {{ mcpSnippetCopied ? 'Copied ✓' : mcpCopyLabel }}
              </button>
              <button type="button" class="ar-linkbtn" @click="mcpSnippetOpen = !mcpSnippetOpen">
                {{ mcpSnippetOpen ? 'Hide the configuration ▴' : 'Show what this copies ▾' }}
              </button>
            </div>
            <p v-if="!mcpAuthB64 && mcpAppPw.available" class="ar-field__hint">
              Unlocks when step 2 has a key — everything else is already written with your
              site’s real values. (The preview shows a <code>&lt;KEY&gt;</code> placeholder
              where the key will go.)
            </p>
            <p v-else class="ar-field__hint">{{ mcpSnippetHint }}</p>
            <div v-show="mcpSnippetOpen" class="ar-mcp-snippet">
              <pre class="ar-about-snippet ar-mcp-snippet__code"><code>{{ mcpSnippet }}</code></pre>
              <button type="button" class="button button-small ar-mcp-snippet__copy" @click="copyMcpSnippet">
                {{ mcpSnippetCopied ? 'Copied' : 'Copy' }}
              </button>
            </div>
          </div>

          <!-- ④ proof. The same call the AI tool will make, from this browser,
               cookie-free — so a pass means the server and the key both work. -->
          <div class="ar-mcp-step">
            <p class="ar-mcp-step__head"><span class="ar-mcp-step__n" aria-hidden="true">4</span>Check it works</p>
            <div class="ar-mcp-test">
              <button
                type="button"
                class="ar-btn ar-btn--ghost"
                :disabled="mcpTestRunning || !mcpAuthB64"
                :title="mcpAuthB64 ? '' : 'Create or paste a key in step 2 first'"
                @click="runMcpTest"
              >{{ mcpTestRunning ? 'Testing…' : 'Test the connection' }}</button>
            </div>
            <ul v-if="mcpTestChecks.length" class="ar-mcp-test__list" role="status">
              <li v-for="(c, i) in mcpTestChecks" :key="i" :data-state="c.state">
                <span class="ar-mcp-test__mark" aria-hidden="true">{{ c.state === 'ok' ? '✓' : '✕' }}</span>
                {{ c.label }}<template v-if="c.note"> — {{ c.note }}</template>
              </li>
            </ul>
            <p v-if="mcpTestChecks.length && !mcpTestRunning" class="ar-field__hint">
              This tests the server and the key from your browser — the same call your AI tool
              makes. What it can’t see is the tool’s own side: the config file and a restart.
              Every real call lands under <a href="#agent-access">Agent Access</a>, attributed to
              the user and key it signed in with.
            </p>
          </div>

          <!-- The raw facts, for the 1% who need them. -->
          <details class="ar-mcp-adv">
            <summary>Advanced — address &amp; transport</summary>
            <p class="ar-mcp-connect__endpoint">
              <code>{{ mcpServer.endpoint }}</code>
              <button type="button" class="button button-small" @click="copyMcpEndpoint">
                {{ mcpCopied ? 'Copied' : 'Copy' }}
              </button>
            </p>
            <p class="ar-field__hint">
              Transport: <strong>Streamable HTTP</strong> (MCP). Sign-in: <strong>HTTP Basic</strong> —
              WordPress username + application password, as an <code>Authorization: Basic …</code>
              header. The full facts, including a bridge for stdio-only tools, are under
              “Other tools” in step 1.
            </p>
          </details>
        </div>
      </section>

      <!-- Content types ------------------------------------------------ -->
      <section v-if="postTypes.length" class="ar-card">
        <h2 class="ar-card__title" id="ar-content-types">Content types</h2>
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
        <!-- Teach the taxonomy expansion (why N types → more than N tokens) WITHOUT
             claiming to equal the dashboard's capabilities total: on a site where a
             commerce plugin owns wp/v2, Agentimus's core-content provider stands down
             and these tokens are superseded, so "= the 4 on the dashboard" would be a
             lie there. The mapping itself is always true. -->
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
        <h2 class="ar-card__title">Evergreen content</h2>
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

      <!-- Provider integrations ---------------------------------------- -->
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
    </div>

    <!-- ============================================================ -->
    <!-- IDENTITY — who owns this site                                -->
    <!-- ============================================================ -->
    <div v-show="group === 'identity'" class="ar-group" data-group="identity">
      <!-- Identity ----------------------------------------------------- -->
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

        <div id="ar-id-expertise" class="ar-field">
          <label>{{ expertiseLabel }}</label>
          <TagInput v-model="identity.expertise" placeholder="Add a topic, press Enter" />
          <small class="ar-field__hint">Feeds this list and schema <code>knowsAbout</code>. Saved as you add.</small>
        </div>

        <div id="ar-id-sameas" class="ar-field">
          <label>Profile URLs</label>
          <TagInput v-model="identity.same_as" :placeholder="profileUrlPlaceholder" />
          <small class="ar-field__hint">
            Public profile URLs (LinkedIn, X, GitHub, Facebook, Wikipedia…) that help agents resolve your entity. Saved as you add.
            <span v-if="identity.same_as.some((u) => !isUrl(u))" class="ar-warn">Some entries are not full https:// URLs.</span>
          </small>
        </div>

      </section>

      <!-- Services ----------------------------------------------------- -->
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
    </div>

    <!-- ============================================================ -->
    <!-- AI ACCESS — what bots may do, and who to block               -->
    <!-- ============================================================ -->
    <div v-show="group === 'access'" class="ar-group" data-group="access">
      <!-- Crawler policy ----------------------------------------------- -->
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

              <label id="ar-feat-enable_ai_header" class="ar-toggle">
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

      <!-- Block scanners & scrapers ------------------------------------ -->
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
            <div class="ar-field__head">
              <label>Blocked user-agents <span class="ar-field__tag">optional</span></label>
              <button v-if="api" type="button" class="ar-linkbtn ar-field__manage" @click="clientManagerOpen = true">Manage clients</button>
            </div>
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
            <p v-if="invalidBlockedPatterns.length" class="ar-card__note ar-warn">
              ⚠ Invalid {{ invalidBlockedPatterns.length === 1 ? 'pattern' : 'patterns' }}:
              <code>{{ invalidBlockedPatterns.join(', ') }}</code> —
              {{ invalidBlockedPatterns.length === 1 ? "that isn't a valid /…/ expression, so it'll be matched as plain text, not a pattern." : "those aren't valid /…/ expressions, so they'll be matched as plain text, not patterns." }}
              Fix the pattern, or drop the slashes to match it as a plain fragment.
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

          <p v-if="!settings.verify_bots" class="ar-card__note ar-warn">
            ⚠ <strong>One costume beats this list.</strong> Blocking matches names, and real search engines are
            always let through — so a blocked bot can dodge every rule here just by calling itself
            <code>Googlebot</code>. Turn on <strong>Verify search engines by reverse DNS</strong> (below) and a
            proven fake loses that free pass.
          </p>
        </div>

        <!-- Verification is not gated by blocking: on its own it flags impersonators in
             the review queue and powers the row's Details verdict, whether or not you deny anyone. -->
        <label class="ar-toggle ar-toggle--standalone">
          <input v-model="settings.verify_bots" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Verify search engines by reverse DNS</strong>
            <small>
              When a visitor <em>says</em> it's Googlebot, Bingbot, Applebot, DuckDuckBot or Yandex, confirm its
              network address really belongs to that engine — the one check that catches a scanner copying a
              crawler's name. A confirmed impersonator is flagged for review as an <strong>Impersonator</strong>,
              and opening its <strong>Details</strong> shows the verdict. Works whether or not blocking is on; if blocking
              <em>is</em> on, a proven fake also loses its free pass. This is the one feature that makes a small
              outbound DNS lookup (cached per visitor).
              <strong>Behind a proxy or CDN?</strong> On Cloudflare it works automatically — Agentimus reads the real
              visitor IP. Another proxy may need the true client IP passed through; either way, a slow or failed
              lookup never drops a real crawler.
            </small>
          </span>
        </label>

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
              off your server. <em>(Needs “Verify search engines” on to catch impersonators.)</em>
            </small>
          </span>
        </label>

        <label class="ar-toggle ar-toggle--standalone">
          <input v-model="settings.identify_bots" type="checkbox" />
          <span class="ar-toggle__track" aria-hidden="true"></span>
          <span class="ar-toggle__text">
            <strong>Identify every bot by reverse DNS</strong>
            <small>
              Verification only covers the five search engines that publish reverse DNS. Turn this on to
              reverse-resolve <strong>every</strong> recorded bot and show the <strong>network it belongs to</strong>
              — <code>amazonaws.com</code>, <code>openai.com</code>, <code>googlebot.com</code> — so you can see
              <em>what</em> is really accessing your site, not just its self-declared name. Agentimus stores the
              <strong>network, not the IP</strong> (it's org-level, not personal), and verifiable engines get their
              verified/impostor verdict from the same lookup. Makes a small outbound DNS lookup per new address
              (cached, and bounded by the same limits as verification). To confirm a single address and see its full
              host, use <strong>Check an IP</strong> below.
            </small>
          </span>
        </label>

        <!-- Ad-hoc identity lookup: paste any IP, see which engine it really is. Self-contained
             (its own REST call); rendered only when the API handle is available. -->
        <IpChecker v-if="api" :api="api" />

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
            <span class="ar-suggest__label">Add a trusted AI agent</span>
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
    </div>

    <Teleport to="body">
      <ClientManager
        v-if="clientManagerOpen"
        :api="api"
        @close="clientManagerOpen = false"
        @changed="$emit('clients-changed', $event)"
      />
    </Teleport>

    <!-- ============================================================ -->
    <!-- EXPOSURE — limit what anonymous bots & scanners can read     -->
    <!-- ============================================================ -->
    <div v-show="group === 'exposure'" class="ar-group" data-group="exposure">
      <section id="ar-sec-exposure" class="ar-card">
        <h2 class="ar-card__title">Exposure</h2>
        <p class="ar-card__lead">
          The opposite of Discovery: stop your site quietly over-sharing with crawlers, bots and
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
              <a v-if="debug.fixUrl" :href="debug.fixUrl" target="_blank" rel="noopener">How to fix ↗</a>
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
          The other side of Exposure: not what your site reveals, but who reaches in. An
          application password lets a program act as you through WordPress’s API — and it keeps
          working even after you change your password, which is exactly why one appearing
          unannounced is worth knowing about.
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
    <div v-show="group === 'advanced'" class="ar-group" data-group="advanced">
      <!-- Security.txt ------------------------------------------------- -->
      <section id="ar-sec-security" class="ar-card">
        <h2 class="ar-card__title">Security contact</h2>
        <p class="ar-card__lead">
          If someone spots a security problem on your site, this tells them where to report it —
          published at the standard place (<code>/.well-known/security.txt</code>) that researchers and
          agents look. <strong>What to do:</strong> turn it on and add one contact (usually your email).
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

        <div v-show="settings.enable_security_txt">
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

      <!-- Advanced / Developer (collapsed; Authenticated API lives here) -->
      <section class="ar-card ar-card--muted ar-adv">
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
            {{ resetting ? 'Resetting…' : 'Reset all' }}
          </button>
        </div>
      </section>
    </div>
      </div>
    </div>

    <!-- The key-scope explainer — the step-2 head states the fact, this carries
         what it means and how to stay safe. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="mcpScopeOpen" class="ar-modal" @click.self="mcpScopeOpen = false">
          <div
            class="ar-modal__panel ar-modal__panel--confirm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-mcp-scope-title"
            tabindex="-1"
          >
            <div class="ar-modal__head">
              <h2 id="ar-mcp-scope-title" class="ar-modal__title">One key, the whole REST API</h2>
              <p class="ar-modal__lead">
                An application password signs in as <strong>{{ mcpUsername }}</strong> everywhere
                WordPress’s REST API goes — it isn’t scoped to this server. That’s how WordPress
                works, not something Agentimus can change.
              </p>
              <p class="ar-modal__lead">
                So: give each AI tool its own key, named after it — then you can revoke one tool
                without touching the others, under <strong>Users → Profile → Application
                Passwords</strong>. For extra margin, create a dedicated user with only the
                permissions the tool needs, and mint the tool’s key signed in as that user.
              </p>
            </div>
            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" @click="mcpScopeOpen = false">Close</button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <!-- The duplicate-key-name explainer — the inline flag says what, this says why
         and what to do. Small confirm-size shell, doc-level Esc like every dialog. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="mcpKeyWarnOpen" class="ar-modal" @click.self="mcpKeyWarnOpen = false">
          <div
            class="ar-modal__panel ar-modal__panel--confirm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-mcp-taken-title"
            tabindex="-1"
          >
            <div class="ar-modal__head">
              <h2 id="ar-mcp-taken-title" class="ar-modal__title">That name is taken</h2>
              <p class="ar-modal__lead">{{ mcpKeyWarn }}</p>
            </div>
            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" @click="mcpKeyWarnOpen = false">Close</button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

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
