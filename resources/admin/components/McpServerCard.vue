<script>
/**
 * The MCP server card — the experimental Model Context Protocol endpoint: the
 * master on/off toggle, the connection token (mint, rotate, revoke), the
 * connected-agents list (OAuth grants), the per-client setup helper (snippet,
 * deeplinks, connection test), and the live status probe. Lifted whole out of
 * SettingsForm — it is one interconnected unit (the client picker drives the
 * snippet drives the copy actions; the password drives the test; the token
 * drives the connections), so it owns all of its own MCP and OAuth-grant state.
 *
 * It two-way-binds three shared settings (the master toggle and the write-tier
 * pair) through the `settings` prop, and watches `busy` — a save finishing is
 * the moment the toggle actually takes effect, so it re-reads the saved state
 * and re-probes then. `active` mirrors the panel being shown (reveal plus
 * window-focus re-fetch of the token's live metadata). No emits: errors inline.
 */
import { confirm } from '../confirm.js';
import { formatDate, formatTime } from '../wpDate.js';
import { copyText } from '../clipboard.js';
import { bindDocEsc } from '../docEsc.js';

export default {
  name: 'McpServerCard',
  props: {
    settings: { type: Object, required: true },
    mcpServer: { type: Object, default: () => ({}) },
    api: { type: Object, default: null },
    active: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
  },
  data() {
    return {
      mcpCopied: false,
      // The connection token (the primary connect path). `mcpTokenPlain` holds the
      // plaintext for THIS page life only — the server keeps a fingerprint and can
      // never show it again, so a reload legitimately loses it.
      mcpToken: null,
      mcpTokenPlain: '',
      mcpTokenScope: 'read',
      mcpTokenBusy: false,
      mcpTokenError: '',
      mcpTokenCopied: false,
      // Connected agents — the OAuth grants the owner approved on the consent
      // page. Each carries its own scope and its own revoke.
      oauthGrants: [],
      oauthBusy: '',
      oauthError: '',
      // The live last-call fact from /mcp-status; overrides the page-load copy.
      mcpLiveLastCall: null,
      // Which assistant's setup steps the help fold is showing.
      mcpSetupPick: 'claude',
      mcpRecipeCopied: false,
      mcpCardCopied: '',
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
  computed: {
    mcpConnectCards() {
      const url = (this.mcpServer && this.mcpServer.endpoint) || '';
      const tok = this.mcpTokenPlain;
      const auth = tok ? `Authorization: Bearer ${tok}` : 'Authorization: Bearer <TOKEN>';
      return [
        {
          key: 'claude-code',
          label: 'Claude Code',
          kind: 'Terminal',
          steps: ['Copy the command.', 'Run it in your terminal. Done.'],
          copy: `claude mcp add --transport http agentimus ${url} --header "${auth}"`,
        },
        {
          key: 'claude-desktop',
          label: 'Claude Desktop',
          kind: 'App',
          steps: ['Settings → Connectors → Add custom connector.', 'Paste the address, then add the token when asked.'],
          copy: url,
        },
        {
          key: 'cursor',
          label: 'Cursor',
          kind: 'Editor',
          steps: ['Click the button — Cursor opens, already filled in.', 'Confirm the install inside Cursor.'],
          deeplink: this.cursorDeeplink,
          deeplinkLabel: 'Add to Cursor',
          note: 'Needs a recent Cursor — old versions ignore the button and show nothing.',
        },
        {
          key: 'vscode',
          label: 'VS Code',
          kind: 'Editor',
          steps: ['Click the button — VS Code opens, already filled in.', 'Confirm the install inside VS Code.'],
          deeplink: this.vscodeDeeplink,
          deeplinkLabel: 'Add to VS Code',
        },
        {
          key: 'claude-ai',
          label: 'Claude on claude.ai',
          kind: 'Web · Mobile',
          soon: 'Coming next: connect with a one-time approval — no token to paste.',
        },
        {
          key: 'chatgpt',
          label: 'ChatGPT',
          kind: 'Web',
          soon: 'Coming next: connect with a one-time approval — no token to paste.',
        },
        {
          key: 'other',
          label: 'Any MCP client',
          kind: 'Generic',
          steps: ['Server address: the line below.', `Send the token as ${auth}`],
          copy: url,
        },
      ];
    },
    // One line per assistant that can reach this site — OAuth grants first
    // (each its own identity), then the shared token as one row. The card's
    // whole point: every key that opens the door is listed in ONE place, with
    // the same exit next to it.
    mcpConnections() {
      const rows = this.oauthGrants.map((g) => ({
        kind: 'grant',
        id: g.clientId,
        name: g.name,
        host: g.host,
        when: this.grantWhen(g),
        live: !!g.lastUsed,
        scope: g.scope,
        grant: g,
      }));
      if (this.mcpToken) {
        rows.push({
          kind: 'token',
          id: 'shared-token',
          name: 'Shared token',
          host: 'any assistant holding it',
          when: this.mcpTokenLastUsedText
            ? `Created ${this.mcpTokenCreatedText} · ${this.mcpTokenLastUsedText.replace(/^Last used /, 'last used ')}`
            : `Created ${this.mcpTokenCreatedText} · not used yet`,
          live: !!(this.mcpToken && this.mcpToken.last_used_at),
          scope: this.mcpToken.scope,
        });
      }
      return rows;
    },
    // "Running · 15 tools · last call 2 minutes ago" — the three status facts
    // that used to be spread over a strip, a paragraph and a hint.
    mcpToolCount() {
      const n = this.mcpServer && this.mcpServer.toolCount;
      return Number.isFinite(n) && n > 0 ? n : 0;
    },
    // The setup recipe for the assistant picked in the help fold. Most entries
    // are now "paste the address" — that is what OAuth bought us — so the
    // token only appears where a client genuinely can't ask for approval.
    mcpRecipe() {
      const url = (this.mcpServer && this.mcpServer.endpoint) || '';
      const tok = this.mcpTokenPlain;
      const map = {
        claude: {
          title: 'Claude — web, desktop, mobile',
          steps: [
            'Settings → Connectors → Add custom connector.',
            'Paste the address above. Claude opens a page on this site asking for your approval.',
            'Approve, and Claude appears in Connected assistants.',
          ],
        },
        'claude-code': {
          title: 'Claude Code — terminal',
          steps: ['Run this command.', 'Start Claude Code — it sends you here to approve.'],
          copy: `claude mcp add --transport http agentimus ${url}`,
        },
        cursor: {
          title: 'Cursor — editor',
          steps: [
            'Settings → MCP → add a server with the address above, no key.',
            'Ask Cursor something about your site; it shows an Authenticate button.',
            'Approve here, and Cursor is connected.',
          ],
          note: 'Needs a recent Cursor — old versions ignore MCP install links and show nothing.',
        },
        vscode: {
          title: 'VS Code — editor',
          steps: [
            'Command Palette → MCP: Add Server → HTTP → paste the address above.',
            'Confirm, then approve here when it asks.',
          ],
        },
        chatgpt: {
          title: 'ChatGPT — web, desktop & Codex',
          steps: [
            'In ChatGPT: Settings → Security → turn on “Developer mode”. It unlocks custom connectors (ChatGPT pauses its memory feature while it is on).',
            'Plugins → the “+” button → paste the address above, keep Authentication on “OAuth”, and Create.',
            '“Sign in with Agentimus” opens a page on this site — approve, and ChatGPT appears in Connected assistants.',
            'Codex can’t ask for approval: create a token under “Shared token” below and send it as this header.',
          ],
          copy: tok ? `Authorization: Bearer ${tok}` : 'Authorization: Bearer <create a token below>',
          note: 'Site behind Cloudflare? Free-plan “Bot Fight Mode” blocks ChatGPT’s calls with a 403 — turn it off for this to work. Verified July 2026.',
        },
        other: {
          title: 'Any MCP client',
          steps: [
            'Give it the address above, over HTTP transport.',
            'If it can ask for approval, it sends you here. If it cannot, create one under “Shared token” below and send it as a Bearer header.',
          ],
          copy: tok ? `Authorization: Bearer ${tok}` : '',
        },
      };
      return map[this.mcpSetupPick] || map.claude;
    },
    // Cursor takes base64-encoded JSON; VS Code takes URL-encoded JSON. Both
    // carry the header so the client arrives already authenticated.
    mcpDeeplinkConfig() {
      return {
        type: 'http',
        url: (this.mcpServer && this.mcpServer.endpoint) || '',
        headers: { Authorization: `Bearer ${this.mcpTokenPlain}` },
      };
    },
    cursorDeeplink() {
      if (!this.mcpTokenPlain) return '';
      const json = JSON.stringify(this.mcpDeeplinkConfig);
      let b64;
      try {
        b64 = btoa(json);
      } catch (e) {
        b64 = btoa(unescape(encodeURIComponent(json)));
      }
      return `cursor://anysphere.cursor-deeplink/mcp/install?name=agentimus&config=${encodeURIComponent(b64)}`;
    },
    vscodeDeeplink() {
      if (!this.mcpTokenPlain) return '';
      const cfg = encodeURIComponent(JSON.stringify({ name: 'agentimus', ...this.mcpDeeplinkConfig }));
      return `vscode:mcp/install?${cfg}`;
    },
    // "Read and write" can only be offered when the write tier is actually on —
    // the token is a door key, the settings are the walls.
    mcpWritesAvailable() {
      return !!this.settings.enable_agent_writes;
    },
    mcpTokenCreatedText() {
      if (!this.mcpToken) return '';
      return formatDate(new Date(this.mcpToken.created_at * 1000));
    },
    // The line ThinkRank can't print: our own request log knows who called last.
    mcpTokenLastUsedText() {
      if (!this.mcpToken || !this.mcpToken.last_used_at) return '';
      const who = (this.mcpToken.last_used_ua || '').trim();
      const when = this.relTime(new Date(this.mcpToken.last_used_at * 1000).toISOString());
      return who ? `Last used ${when} by ${who}` : `Last used ${when}`;
    },
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
    // The same fact, shortened for the status rail: the rail is scanned, not
    // read, so it carries when and who — the full sentence lives in the log.
    mcpRailCallText() {
      const l = this.mcpLiveLastCall || (this.mcpServer && this.mcpServer.lastToolCall);
      if (!l || !l.known) return '';
      if (!l.call) return 'no assistant has called yet';
      const who = l.call.key ? ` by ${l.call.key}` : '';
      return `last call ${this.relTime(l.call.at)}${who}`;
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
  },
  watch: {
    // A finished save is the moment the MCP switch actually takes effect — refresh
    // the saved-state snapshot and re-probe, so the status line follows reality.
    busy(now, was) {
      if (was && !now) {
        this.mcpSavedEnabled = !!(this.settings && this.settings.enable_mcp_server);
        if (this.mcpSavedEnabled) {
          this.probeMcpStatus();
          this.loadMcpToken();
          this.loadOauthGrants();
        }
      }
    },
    // Same catch-up rule as the Agent Access screen: arriving at Settings re-reads
    // the token's metadata, so "Not used yet" / "Last used…" tells today's truth,
    // not the page-load's. (The plaintext is untouched — it only exists at creation.)
    active(on) {
      if (!on || !this.mcpSavedEnabled) return;
      this.loadMcpToken();
      this.loadOauthGrants(); // A connect approved in another tab shows up on arrival.
    },
    // Turning the write tier off must not leave a "read and write" choice
    // selected — the walls moved, so the key's request follows them down.
    'settings.enable_agent_writes'(on) {
      if (!on && 'write' === this.mcpTokenScope) this.mcpTokenScope = 'read';
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
  mounted() {
    window.addEventListener('focus', this.onWindowFocus);
    document.addEventListener('visibilitychange', this.onWindowFocus);
    this.mcpSavedEnabled = !!(this.settings && this.settings.enable_mcp_server);
    if (this.mcpSavedEnabled) {
      this.probeMcpStatus();
      this.loadMcpToken();
      this.loadOauthGrants();
    }
  },
  beforeUnmount() {
    window.removeEventListener('focus', this.onWindowFocus);
    document.removeEventListener('visibilitychange', this.onWindowFocus);
    if (this._unEscTaken) this._unEscTaken();
    if (this._unEscScope) this._unEscScope();
  },
  methods: {
    async copyPlainText(text) {
      return copyText(text);
    },
    async copyMcpEndpoint() {
      if (!(await this.copyPlainText((this.mcpServer && this.mcpServer.endpoint) || ''))) return;
      this.mcpCopied = true;
      clearTimeout(this._mcpCopyTimer);
      this._mcpCopyTimer = setTimeout(() => { this.mcpCopied = false; }, 2000);
    },
    /* ---- connected agents (OAuth grants) -------------------------------- */
    async loadOauthGrants() {
      try {
        const res = await this.api.getOauthGrants();
        this.oauthGrants = (res && res.grants) || [];
      } catch (e) {
        this.oauthGrants = [];
      }
    },
    // One assistant out, the others untouched — the reason per-agent grants
    // exist at all. Same danger dialog as the token's kill switch.
    async confirmRevokeGrant(grant) {
      const ok = await confirm({
        title: `Disconnect ${grant.name}?`,
        message: `${grant.name} loses access right away. Every other connected assistant keeps working. It can connect again by asking for your approval.`,
        confirmLabel: 'Disconnect',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (ok) this.revokeOauthGrant(grant);
    },
    async revokeOauthGrant(grant) {
      if (this.oauthBusy) return;
      this.oauthBusy = grant.clientId;
      this.oauthError = '';
      try {
        const res = await this.api.revokeOauthGrant(grant.clientId);
        this.oauthGrants = (res && res.grants) || [];
      } catch (e) {
        this.oauthError = e.message || 'Could not disconnect that assistant.';
      } finally {
        this.oauthBusy = '';
      }
    },
    // "Approved today · last used 2 minutes ago" — one line, two facts, and it
    // says "not used yet" rather than inventing a time.
    grantWhen(grant) {
      const approved = `Approved ${formatDate(new Date(grant.approved * 1000))}`;
      if (!grant.lastUsed) return `${approved} · not used yet`;
      return `${approved} · last used ${this.relTime(new Date(grant.lastUsed * 1000).toISOString())}`;
    },

    /* ---- connection token ---------------------------------------------- */
    async loadMcpToken() {
      try {
        const res = await this.api.getMcpToken();
        this.mcpToken = (res && res.token) || null;
        if (this.mcpToken) this.mcpTokenScope = this.mcpToken.scope;
      } catch (e) {
        this.mcpToken = null;
      }
    },
    // Create or rotate — the same act. Rotating ends every current connection,
    // so the button that calls this says so.
    async createMcpToken() {
      if (this.mcpTokenBusy) return;
      this.mcpTokenBusy = true;
      this.mcpTokenError = '';
      try {
        const res = await this.api.createMcpToken(this.mcpTokenScope);
        this.mcpTokenPlain = (res && res.plaintext) || '';
        this.mcpToken = (res && res.token) || null;
      } catch (e) {
        this.mcpTokenError = e.message || 'Could not create the token.';
      } finally {
        this.mcpTokenBusy = false;
      }
    },
    // Rotation is as final as disconnection for every currently connected
    // assistant, so it earns the same dialog — and the warning lives THERE,
    // not in a hint line beside the button.
    async confirmRotateMcpToken() {
      const ok = await confirm({
        title: 'Rotate the connection token?',
        message: 'Rotating ends every current connection at once. Assistants can only reconnect with the new token.',
        confirmLabel: 'Rotate Token',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (ok) this.createMcpToken();
    },
    // The kill switch goes through the shared danger dialog — a quiet inline
    // confirm proved missable in the very first review walk.
    async confirmRevokeMcpToken() {
      const ok = await confirm({
        title: 'Disconnect every assistant?',
        message: 'The connection token stops working right away. Every assistant using it loses access until you create a new token.',
        confirmLabel: 'Disconnect Everything',
        cancelLabel: 'Cancel',
        tone: 'danger',
      });
      if (ok) this.revokeMcpToken();
    },
    async revokeMcpToken() {
      if (this.mcpTokenBusy) return;
      this.mcpTokenBusy = true;
      this.mcpTokenError = '';
      try {
        await this.api.revokeMcpToken();
        this.mcpToken = null;
        this.mcpTokenPlain = '';
      } catch (e) {
        this.mcpTokenError = e.message || 'Could not disconnect.';
      } finally {
        this.mcpTokenBusy = false;
      }
    },
    async copyMcpTokenPlain() {
      if (!(await this.copyPlainText(this.mcpTokenPlain))) return;
      this.mcpTokenCopied = true;
      clearTimeout(this._mcpTokenCopyTimer);
      this._mcpTokenCopyTimer = setTimeout(() => { this.mcpTokenCopied = false; }, 2000);
    },
    async copyMcpRecipe() {
      if (!(await this.copyPlainText(this.mcpRecipe.copy || ''))) return;
      this.mcpRecipeCopied = true;
      clearTimeout(this._mcpRecipeTimer);
      this._mcpRecipeTimer = setTimeout(() => { this.mcpRecipeCopied = false; }, 2000);
    },
    async copyMcpCard(card) {
      if (!card.copy || !(await this.copyPlainText(card.copy))) return;
      this.mcpCardCopied = card.key;
      clearTimeout(this._mcpCardCopyTimer);
      this._mcpCardCopyTimer = setTimeout(() => { this.mcpCardCopied = ''; }, 2000);
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
      return d < 30 ? `${d} days ago` : formatDate(new Date(t));
    },
    // Is the server actually answering? Asked over an AUTHENTICATED admin route
    // (agentimus/v1/mcp-status) rather than an unauthenticated GET to the MCP endpoint
    // itself: the endpoint correctly answers 401 to a credential-less request, which
    // the browser logs as a red console error on every admin load. The status route
    // reports the same liveness (is our MCP route registered?) server-side, with a
    // clean 200 via the nonce — so the console stays quiet.
    // Approving happens in ANOTHER window — the assistant's app, or a consent tab.
    // So the moment this window gets focus back is exactly the moment the card is
    // most likely to be lying. Cheaper and calmer than polling: no timers, no
    // requests while the owner is elsewhere.
    onWindowFocus() {
      if (document.hidden || !this.active || !this.mcpSavedEnabled) return;
      this.probeMcpStatus();
      this.loadOauthGrants();
      this.loadMcpToken();
    },
    async probeMcpStatus() {
      this.mcpProbe = 'checking';
      try {
        const res = await this.api.getMcpStatus();
        this.mcpProbe = res && res.running ? 'running' : 'unreachable';
        // The probe carries the live last-call fact too, so the status rail
        // stops quoting whatever was true when the page loaded.
        if (res && res.lastToolCall) this.mcpLiveLastCall = res.lastToolCall;
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
  },
};
</script>

<template>
      <section id="ar-sec-mcp" class="ar-card">
        <h2 class="ar-card__title">MCP Server <span class="ar-card__tag">experimental</span></h2>
        <p class="ar-card__lead">
          Lets AI assistants you already use — Claude, Cursor, ChatGPT and others — use this
          site’s tools over the <strong>Model Context Protocol</strong>. Most of them ask you
          for approval and you say yes; the ones that can’t ask use a token instead. Either
          way a connected assistant acts as your WordPress user: it can only do what that user
          could on these screens — reading only, unless you allow writing below. Nothing
          becomes public.
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
            <small>Every call signs in first — by your approval, a token, or an application password — and gets the same permissions as these admin screens. Read-only tools, unless you allow writing below.</small>
          </span>
        </label>

        <!-- The write tier: a second deliberate switch, plus a third for going live.
             Off = the write tools don't exist on any surface, so "read-only" above
             stays literally true until the owner says otherwise. -->
        <div :inert="!settings.enable_mcp_server" class="ar-webmcp-tools">
          <label id="ar-feat-enable_agent_writes" class="ar-toggle ar-toggle--nested">
            <input v-model="settings.enable_agent_writes" type="checkbox" />
            <span class="ar-toggle__track" aria-hidden="true"></span>
            <span class="ar-toggle__text">
              <strong>Let connected agents write</strong>
              <small>Adds write tools: draft and edit posts and pages — including categories, tags and the featured image — set their AI topics and descriptions, and apply Readiness fixes (a fixed list of safe switches — it can only turn documented features on, never loosen a protection). An agent still acts as the signed-in user and can never do more than that user could in the editor. Every write lands under <a href="#agent-access">Agent Access</a>.</small>
            </span>
          </label>
          <div :inert="!settings.enable_agent_writes" class="ar-webmcp-tools">
            <label id="ar-feat-agent_writes_publish" class="ar-toggle ar-toggle--nested">
              <input v-model="settings.agent_writes_publish" type="checkbox" />
              <span class="ar-toggle__track" aria-hidden="true"></span>
              <span class="ar-toggle__text">
                <strong>Let agents publish without your review</strong>
                <small>Lets an agent put content live (and it still needs a user allowed to publish). Off — the safe default — means agents only create drafts and pending posts for you to review; editing something already published follows that user’s normal edit permission either way.</small>
              </span>
            </label>
          </div>
        </div>

        <!-- The off-state must narrate the order, or an arriving link ("allow
             writing", "approve ChatGPT") strands the reader in front of greyed
             switches and an invisible connect block. -->
        <p v-if="!settings.enable_mcp_server" class="ar-card__note ar-card__note--wide">
          Everything here is off right now. The order: turn on the server above and save —
          your connect address and setup steps appear on this card, assistants like ChatGPT
          and Claude can then ask for your approval, and each one you approve is listed here.
          After that, allow writing above if you want them drafting.
        </p>

        <div v-show="settings.enable_mcp_server" class="ar-mcp-connect">
          <!-- Status: is the server actually answering, and has anything used it?
               The probe runs in the browser because the adapter's state genuinely
               cannot be known during an admin page load (it boots on rest_api_init). -->
          <div class="ar-mcp-rail" :data-state="mcpStatus">
            <span class="ar-mcp-rail__dot" aria-hidden="true"></span>
            <strong v-if="mcpStatus === 'running'">Running</strong>
            <strong v-else-if="mcpStatus === 'unsaved'">Turns on when you save</strong>
            <strong v-else-if="mcpStatus === 'unreachable'">Not answering</strong>
            <strong v-else>Checking…</strong>
            <template v-if="mcpStatus === 'running' && mcpToolCount">
              <span class="ar-mcp-rail__sep" aria-hidden="true">·</span><span>{{ mcpToolCount }} tools</span>
            </template>
            <template v-if="mcpRailCallText">
              <span class="ar-mcp-rail__sep" aria-hidden="true">·</span><span>{{ mcpRailCallText }}</span>
            </template>
            <a href="#agent-access">See every call in Agent Access →</a>
          </div>
          <p v-if="mcpStatus === 'unreachable'" class="ar-field__hint">
            The address returned “not found”. Re-save the settings; if it persists, something in
            front of the site may be caching REST responses.
          </p>

          <!-- The address is the setup now: an assistant that can ask for approval
               needs nothing else from this screen. -->
          <div class="ar-mcp-addr">
            <p class="ar-mcp-eyebrow">Your server address</p>
            <div class="ar-mcp-addr__row">
              <code class="ar-mcp-addr__url">{{ mcpServer.endpoint }}</code>
              <button type="button" class="ar-btn ar-btn--ghost ar-btn--small" @click="copyMcpEndpoint">
                {{ mcpCopied ? 'Copied' : 'Copy' }}
              </button>
            </div>
            <p class="ar-field__hint ar-mcp-addr__hint">
              Give this to an assistant and it asks you for approval — nothing to paste back.
              Assistants that can’t do that need a token instead; see <em>Setting up an assistant</em> below.
            </p>
            <p v-if="mcpEndpointInsecure" class="ar-field__hint">
              The address is plain <code>http://</code> — many AI clients refuse insecure
              connections to anything but a local machine. Fine for local development; a live
              site needs HTTPS.
            </p>
          </div>

          <!-- The connection token: one revocable secret for every client.
               Created here, shown once, and capped by the write tier above. -->
          <!-- The roster: every key that can reach this site, in one place, each
               with the same exit beside it. The loudest block on the card. -->
          <div class="ar-mcp-roster">
            <p class="ar-mcp-roster__head">
              Connected assistants
              <span class="ar-mcp-roster__count" :class="{ 'is-zero': !mcpConnections.length }">{{ mcpConnections.length }}</span>
            </p>
            <p v-if="mcpConnections.length" class="ar-field__hint ar-mcp-roster__note">
              The assistants you approved, plus the shared token. Disconnecting one
              leaves the others working. Application passwords have their own section below.
            </p>

            <ul v-if="mcpConnections.length" class="ar-grants">
              <li v-for="c in mcpConnections" :key="c.id" class="ar-grant">
                <span class="ar-grant__dot" :class="{ 'is-idle': !c.live }" aria-hidden="true"></span>
                <span class="ar-grant__who">
                  <strong>{{ c.name }}</strong>
                  <span v-if="c.host" class="ar-grant__host">{{ c.host }}</span>
                  <span class="ar-grant__how">{{ c.kind === 'token' ? 'token' : 'approval' }}</span>
                  <small>{{ c.when }}</small>
                </span>
                <span
                  class="ar-grant__scope"
                  :class="{ 'is-write': c.scope === 'write', 'is-token': c.kind === 'token' }"
                >{{ c.scope === 'write' ? 'Read · Write' : 'Read only' }}</span>
                <button
                  v-if="c.kind === 'grant'"
                  type="button"
                  class="ar-btn ar-btn--danger ar-btn--small"
                  :disabled="oauthBusy === c.id"
                  @click="confirmRevokeGrant(c.grant)"
                >{{ oauthBusy === c.id ? 'Disconnecting…' : 'Disconnect' }}</button>
                <button
                  v-else
                  type="button"
                  class="ar-btn ar-btn--danger ar-btn--small"
                  :disabled="mcpTokenBusy"
                  @click="confirmRevokeMcpToken"
                >Revoke</button>
              </li>
            </ul>

            <p v-else class="ar-mcp-roster__empty">
              <strong>No assistant is connected yet.</strong>
              Give an assistant the address above — it will ask for your approval, and appear here.
            </p>

            <p v-if="oauthError" class="ar-field__hint ar-mcp-key__err" role="alert">{{ oauthError }}</p>
            <p v-if="mcpToken" class="ar-field__hint ar-mcp-roster__note">
              <em>Revoke</em> ends every connection using the shared token at once. To swap
              it for a fresh secret instead, rotate it in the <em>Shared token</em> section below.
            </p>
          </div>

          <!-- Setup help: folded, and only ever shows the steps for the ONE
               assistant you picked. The owner never learns our taxonomy. -->
          <details class="ar-mcp-adv">
            <summary>Setting up an assistant <span class="ar-mcp-adv__aside">— pick yours for exact steps</span></summary>
            <div class="ar-mcp-picker" role="group" aria-label="Your assistant">
              <button
                v-for="p in [
                  { key: 'claude', label: 'Claude', kind: 'web · desktop · mobile' },
                  { key: 'claude-code', label: 'Claude Code', kind: 'terminal' },
                  { key: 'cursor', label: 'Cursor', kind: 'editor' },
                  { key: 'vscode', label: 'VS Code', kind: 'editor' },
                  { key: 'chatgpt', label: 'ChatGPT', kind: 'web · desktop · Codex' },
                  { key: 'other', label: 'Something else', kind: 'any MCP client' },
                ]"
                :key="p.key"
                type="button"
                class="ar-mcp-pick"
                :class="{ 'is-on': mcpSetupPick === p.key }"
                :aria-pressed="mcpSetupPick === p.key"
                @click="mcpSetupPick = p.key"
              >{{ p.label }}<small>{{ p.kind }}</small></button>
            </div>
            <div class="ar-mcp-recipe">
              <p class="ar-mcp-eyebrow">{{ mcpRecipe.title }}</p>
              <ol class="ar-mcp-recipe__steps">
                <li v-for="(s, i) in mcpRecipe.steps" :key="i">{{ s }}</li>
              </ol>
              <div v-if="mcpRecipe.copy" class="ar-mcp-addr__row">
                <code class="ar-mcp-addr__url">{{ mcpRecipe.copy }}</code>
                <button type="button" class="ar-btn ar-btn--ghost ar-btn--small" @click="copyMcpRecipe">
                  {{ mcpRecipeCopied ? 'Copied' : 'Copy' }}
                </button>
              </div>
              <p v-if="mcpRecipe.note" class="ar-field__hint">{{ mcpRecipe.note }}</p>
            </div>
          </details>

          <!-- Fallbacks for assistants that can't ask for approval themselves. -->
          <details class="ar-mcp-adv">
            <summary>Shared token <span class="ar-mcp-adv__aside">— for assistants that can’t ask for approval</span></summary>
            <div class="ar-mcp-step ar-mcp-token">
            <p class="ar-field__hint ar-mcp-token__lead">
              A <strong>shared token</strong> is one secret any assistant can hold. Use it when an
              assistant can’t ask for your approval itself.
            </p>

            <!-- No token yet: choose what it may do, then create it. -->
            <template v-if="!mcpToken">
              <div class="ar-mcp-scopes" role="radiogroup" aria-label="What the assistant may do">
                <label class="ar-mcp-scope-opt" :class="{ 'is-sel': mcpTokenScope === 'read' }">
                  <input v-model="mcpTokenScope" type="radio" value="read" name="ar-mcp-scope" />
                  <span class="ar-mcp-scope-opt__body">
                    <strong>Read only</strong>
                    <small>The assistant can look things up. It can never change anything.</small>
                  </span>
                </label>
                <label
                  class="ar-mcp-scope-opt"
                  :class="{ 'is-sel': mcpTokenScope === 'write', 'is-off': !mcpWritesAvailable }"
                >
                  <input v-model="mcpTokenScope" type="radio" value="write" name="ar-mcp-scope" :disabled="!mcpWritesAvailable" />
                  <span class="ar-mcp-scope-opt__body">
                    <strong>Read and write</strong>
                    <small v-if="mcpWritesAvailable">Can also draft and edit — always within the write settings above.</small>
                    <small v-else>Turn on “Let connected agents write” above to offer this.</small>
                  </span>
                </label>
              </div>
              <div class="ar-mcp-token__actions">
                <button type="button" class="ar-btn" :disabled="mcpTokenBusy" @click="createMcpToken">
                  {{ mcpTokenBusy ? 'Creating…' : 'Create connection token' }}
                </button>
              </div>
            </template>

            <!-- Just created: the one and only sighting of the secret. -->
            <div v-if="mcpTokenPlain" class="ar-mcp-token__once">
              <div class="ar-mcp-token__row">
                <code class="ar-mcp-token__value">{{ mcpTokenPlain }}</code>
                <button type="button" class="button button-small" @click="copyMcpTokenPlain">
                  {{ mcpTokenCopied ? 'Copied' : 'Copy' }}
                </button>
              </div>
              <p class="ar-field__hint">
                <strong>Shown once.</strong> Copy it now — Agentimus keeps only a fingerprint, so it
                cannot show it again. Lost it? Rotate and get a new one.
              </p>
            </div>

            <!-- A token exists: it has its row in the roster above, so this is
                 only the two dangerous buttons. -->
            <template v-if="mcpToken">
              <p class="ar-field__hint">
                A shared token is active — see it in <strong>Connected assistants</strong> above.
                Rotating gives you a fresh secret and ends every connection using the old one.
              </p>
              <div class="ar-mcp-token__actions">
                <button type="button" class="ar-btn ar-btn--ghost" :disabled="mcpTokenBusy" @click="confirmRotateMcpToken">Rotate Token</button>
                <button type="button" class="ar-btn ar-btn--danger" :disabled="mcpTokenBusy" @click="confirmRevokeMcpToken">Revoke Token</button>
              </div>
            </template>
            <p v-if="mcpTokenError" class="ar-field__hint ar-mcp-key__err" role="alert">{{ mcpTokenError }}</p>
            <p v-if="mcpToken && !mcpTokenPlain" class="ar-field__hint">
              Setup lines that need the secret itself can only show it at creation.
              Rotate for a fresh one — every connection using the old token ends when you do.
            </p>
            <div v-if="mcpTokenPlain" class="ar-mcp-cards">
              <div v-for="card in mcpConnectCards.filter((c) => c.deeplink)" :key="card.key" class="ar-mcp-card">
                <h4 class="ar-mcp-card__title">{{ card.label }} <span class="ar-mcp-card__kind">one-click, with this token</span></h4>
                <div class="ar-mcp-card__copyrow">
                  <a class="ar-btn ar-btn--ghost ar-mcp-card__deeplink" :href="card.deeplink">{{ card.deeplinkLabel }}</a>
                </div>
              </div>
            </div>
          </div>

          </details>

          <!-- The per-client path: one application password per tool, for owners
               who want to revoke tools individually. Its own fold — it carries a
               four-step setup, and burying that under a shared summary made one
               fold look like two features wearing the same coat. -->
          <details class="ar-mcp-adv">
            <summary>Application passwords <span class="ar-mcp-adv__aside">— one key per assistant</span></summary>
          <div class="ar-mcp-step ar-mcp-apppw">
            <p class="ar-field__hint">
              A separate WordPress application password per assistant. More to manage, but you can
              revoke one assistant without disturbing the others.
            </p>

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

          <div class="ar-mcp-step">
            <p class="ar-mcp-step__head">
              <span class="ar-mcp-step__n" aria-hidden="true">2</span>Give it a key
              <span v-if="mcpAppPw.available" class="ar-mcp-scope">
                — signs in as <strong>{{ mcpUsername }}</strong>, whole REST API
                <button
                  type="button"
                  class="ar-mcp-key__whybtn ar-mcp-key__whybtn--info"
                  aria-label="What that means, and how to stay safe"
                  v-tip="`What that means, and how to stay safe`"
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
                        v-tip="`Why, and what to do`"
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
                      v-tip="mcpKeyName.trim() ? '' : 'Give the key a name first'"
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
                        v-tip="mcpKeyCopied ? 'Copied' : 'Copy the key'"
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
                v-tip="mcpAuthB64 ? '' : 'Create or paste a key in step 2 first'"
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
                v-tip="mcpAuthB64 ? '' : 'Create or paste a key in step 2 first'"
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
          </div>
          </details>

          <!-- The raw facts, for the 1% who need them. -->
          <details class="ar-mcp-adv">
            <summary>Advanced <span class="ar-mcp-adv__aside">— transport &amp; sign-in</span></summary>
            <p class="ar-field__hint">
              Transport: <strong>Streamable HTTP</strong> (MCP). Sign-in, in the order the server
              tries them: an <strong>approved connection</strong> (OAuth 2.1 with PKCE), the
              <strong>shared token</strong> as a Bearer header, or an
              <strong>application password</strong> over HTTP Basic. A stdio-only tool needs a
              bridge — see the setup steps for “Something else”.
            </p>
          </details>
        </div>
      </section>

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
</template>
