<script>
import ProviderRow from './ProviderRow.vue';
import { copyText } from '../js/clipboard.js';
import { authWords, isOpenToAnyone } from '../js/authWords.js';

// One plain-words line per well-known address — what the file is FOR, in the
// owner's language, not the spec's. Keyed by served name; a row without an
// entry (a future doc a provider adds) simply shows no note.
const WK_NOTES = {
  'discovery.json': 'Everything this site tells AI assistants, gathered in one file.',
  'agent-card.json': 'A card that introduces this site to AI assistants.',
  'agent.json': 'The same introduction card, at its older address.',
  'mcp.json': 'Lists this site’s MCP server and the tools it offers.',
  'openapi.json': 'Describes the public API in a form software can read.',
  'api-catalog': 'A short list of the APIs this site offers.',
  'agent-skills': 'Lists the skills AI assistants can use on this site.',
  'http-message-signatures-directory': 'This site’s public keys, so others can verify what it signs.',
  'oauth-protected-resource': 'Tells connecting apps how to sign in to the MCP server.',
  'security.txt': 'Who to contact if someone finds a security problem.',
};

export default {
  name: 'DiscoveryHub',
  components: { ProviderRow },
  props: {
    data: { type: Object, default: () => ({}) },
    // The live settings object. Mutating it is how every switch in this app saves:
    // App.vue autosaves from a signature of the whole object, so there is no list
    // to keep in sync and a new switch can never be silently forgotten.
    settings: { type: Object, default: () => ({}) },
    refreshing: { type: Boolean, default: false },
  },
  emits: ['refresh', 'navigate'],
  data() {
    // rowFilter: which rows the list shows. 'all' by default — the screen's job
    // is to describe the site, not to open on its problems; the held-back count
    // in the summary is the way to those, and it is one click.
    // openParts: which of the two sections the owner has shut. Absent = open.
    return { openGroups: {}, openParts: {}, copiedDoor: '', capsOverflow: false, toolsOverflow: false, rowFilter: 'all' };
  },
  mounted() {
    this.$nextTick(this.measureBoxes);
  },
  updated() {
    this.measureBoxes();
  },
  computed: {
    endpoints() {
      return this.data.endpoints || {};
    },
    counts() {
      return this.data.counts || { resources: 0, capabilities: 0, tools: 0, apis: 0, errors: 0 };
    },
    // The providers tile shows the REGISTERED number so it matches the provider
    // list below; "N public · M sign-in only" reconciles it with what anonymous
    // agents actually see. Same pattern for tools (whose big number is already
    // the full inventory). Older payloads without the split fields degrade to
    // the plain captions.
    providersRegistered() {
      const c = this.counts;
      return typeof c.resourcesRegistered === 'number' ? c.resourcesRegistered : c.resources;
    },
    providersHeld() {
      return Math.max(0, this.providersRegistered - (this.counts.resources || 0));
    },
    toolsHeld() {
      const c = this.counts;
      if (typeof c.toolsPublished !== 'number') return 0;
      return Math.max(0, (c.tools || 0) - c.toolsPublished);
    },
    // One row per capability token, built from the published union (so the row count
    // is exactly counts.capabilities) with the declaring providers named. token → the
    // full dotted name; verb → its last segment (read / create); provider → who declares
    // it.
    // ⚠️⚠️ EVERY declarer, not the first one. This took `.find()` and named a single
    // owner, which is right on a site with one shop and a borrowed name on a site
    // with three: agentimus-site.test declares `commerce.products.read` from
    // FluentCart, WooCommerce AND EDD, and the row credited FluentCart alone — so
    // turning FluentCart off would leave the capability standing with its stated
    // source gone. Same mistake as MailPoet filed under WooCommerce's name: a row
    // that names one vendor for what several provide is telling the owner something
    // untrue about their own site.
    capabilityRows() {
      const norm = (c) => (typeof c === 'string' ? c : (c && c.id) || '');
      const union = this.capabilities.map(norm).filter(Boolean);
      return union.map((token) => {
        const owners = this.resources
          .filter((r) => Array.isArray(r.capabilities) && r.capabilities.map(norm).indexOf(token) !== -1)
          .map((r) => r.title)
          .filter(Boolean);
        const parts = token.split('.');
        return {
          token,
          verb: parts[parts.length - 1] || 'read',
          provider: owners.length ? owners.join(' · ') : '—',
        };
      });
    },
    // ⭐⭐ THE ADDRESSES, GATHERED — restored 2026-08-17 after he compared the
    // merged screen against the old one. Deleting the "Addresses assistants can
    // ask" card was half right: it DID repeat the rows, but a summary of things
    // scattered down a list is not the same thing as a duplicate, and losing it
    // turned one glance into one click per row. ⛔ It is not the old card back:
    // it sits INSIDE "Things Assistants Can Read", above the rows it summarises,
    // rather than in a card of its own further down the page.
    //
    // ⭐ PUBLISHED ONLY, and that is what makes the number honest: this is the
    // list your public file actually carries, so it counts 5 while the section
    // counts 6 rows — the sixth being the address the daily check refused. Each
    // number is labelled with what it counts, and the odd one out explains itself
    // on its own row below.
    publishedAddresses() {
      return (this.data.apis || []).map((a) => {
        const url = a.base || '';
        const owner = this.resources.find(
          (r) => Array.isArray(r.endpoints) && r.endpoints.some((e) => e.url === url)
        );
        const authType = a.auth && a.auth.type ? a.auth.type : 'none';
        return {
          url,
          type: String(a.type || 'rest').toUpperCase(),
          // ⛔ Never the raw scheme. This chip read "apikey" four pixels from a
          // state pill saying "Needs a key" — one fact, two labels, one of them
          // machine vocabulary handed to a shop owner.
          auth: authWords(authType),
          open: isOpenToAnyone(authType),
          provider: owner ? owner.title : '',
        };
      });
    },
    // The daily check itself, in one sentence — so "nothing is held back" can be told
    // apart from "nothing has ever been checked".
    reachability() {
      return this.data.reachability || null;
    },
    reachabilityNote() {
      const r = this.reachability;
      if (!r) return '';
      if (r.error) return r.error;
      if (!r.checkedAt) return 'These addresses have not been checked yet. The first check runs in the background shortly after a plugin is activated.';
      if (r.stale) return 'The background check has not run for over two days, so these addresses are unverified rather than verified. Check that WordPress cron is running on this site.';
      // ⛔ One number for what was held back, never a reason — the rows below give
      // each address its own, and a summary that guessed at a shared reason said
      // "refused a visitor" about an address that simply no longer exists.
      const held = r.checked - r.open;
      return `Checked ${r.checked} ${r.checked === 1 ? 'address' : 'addresses'} in the background: ${r.open} open to anyone${held ? `, ${held} not listed` : ''}.`;
    },
    // The subset of capabilities that come from the site's OWN content (the auto-
    // discovered WordPress Core provider) — the ones the owner steers from Content
    // types. Plugin-declared capabilities (scheduling.*, crm.*) are the plugin's business.
    ownContentCapabilities() {
      const core = this.resources.find((r) => r.id === 'wordpress-core');
      if (!core || !Array.isArray(core.capabilities)) return [];
      return core.capabilities.map((c) => (typeof c === 'string' ? c : c.id)).filter(Boolean);
    },
    // The "sign-in" cell's ANSWER, so it must never repeat the cell's own word —
    // it read "sign-in: sign-in", which tells the owner nothing twice. Name the
    // door when the site has one; otherwise say the thing that is true whatever
    // the door turns out to be. ⛔ Never name a mechanism the site has not set
    // up: with no MCP server, McpSurface leaves this empty on purpose.
    mcpAuthLabel() {
      if (this.mcp.auth === 'oauth') return 'OAuth';
      if (this.mcp.auth === 'application-password') return 'Application password';
      return 'Required';
    },
    // The summary strip's server cell: the one place server identity lives.
    mcpServerLabel() {
      const servers = this.mcp.servers || [];
      if (servers.length > 1) return servers.length + ' detected';
      if (servers.length === 1) return servers[0].name || 'Detected';
      return this.mcp.available ? 'Detected' : 'None';
    },
    /* ── The one list ──────────────────────────────────────────────────────
     * Two groups, and they answer the two questions an owner has: what can an
     * assistant READ here, and what can it DO. ⛔ Not "who told us" — that was
     * the old top-level split, and it organised the screen around a question
     * almost nobody asks. ⛔ Not by state either: published/held-back re-sorts
     * itself whenever anything changes, so the screen would never look the same
     * twice; state is a pill and a filter, not a structure.
     * ⭐ One rule decides which group a row is in, so no row is ever in two:
     * anything with jobs is a "do", everything else is a "read".
     */
    // ⭐ Running order, his call: WordPress's own namespace first, then ours, then
    // whatever the plugins registered — foundation, then host, then guests. Stable
    // within each rank, so the order everything else was given is untouched.
    doRows() {
      const rank = (r) => ('abilities-core' === r.id ? 0 : (r.own ? 1 : 2));
      return this.resources
        .filter((r) => (r.tools || 0) > 0)
        .sort((a, b) => rank(a) - rank(b));
    },
    readRows() {
      return this.ownFirst(this.resources.filter((r) => !(r.tools || 0)));
    },
    // Each filter carries its own count, because the strip that used to state
    // those counts IS this control now. ⭐ The tone stays with the number whether
    // the filter is pressed or not: green means published and amber means held
    // back, and a colour that changed with selection would be decoration.
    rowFilters() {
      // ⭐ "listed / not listed" — the one pair the whole screen now uses for this
      // state. It replaced "published / held back" here so the filter, the pills,
      // the stats and the dashboard tiles all say the same two words.
      return [
        { key: 'all', label: 'everything', count: this.resources.length, tone: '' },
        { key: 'pub', label: 'listed', count: this.publishedRowCount, tone: 'is-on' },
        { key: 'held', label: 'not listed', count: this.heldRowCount, tone: this.heldRowCount > 0 ? 'is-held' : '' },
      ];
    },
    // In the owner's terms: is this row in the files an assistant reads? Three
    // different things keep it out and they all count the same here — the owner's
    // own switch, a provider that publishes nothing anonymously, and an address
    // the daily check could not open.
    rowIsPublished() {
      return (r) => {
        if (this.controllable(r) ? !this.isPublished(r.id) : r.suppressed) return false;
        if (r.notPublic) return false;
        return !(r.endpoints || []).some((e) => e.open && e.open.published === false);
      };
    },
    publishedRowCount() {
      return this.resources.filter(this.rowIsPublished).length;
    },
    heldRowCount() {
      return this.resources.length - this.publishedRowCount;
    },
    // ⛔ An empty filter result is a dead end; a sentence is an answer.
    emptyFilterNote() {
      if (this.rowFilter === 'held') return 'Everything in this group is listed.';
      if (this.rowFilter === 'pub') return 'Nothing in this group is listed right now.';
      return 'Nothing here yet.';
    },
    // The ways in to one row's jobs. Only the hub can see the detected servers,
    // so the row is told rather than left to work it out.
    doorsFor() {
      const servers = this.mcp.servers || [];
      return (r) => {
        if (!(r.tools || 0)) return [];
        // The adapter flattens ability names (ns/tool → ns-tool) when it serves
        // them over MCP, so a namespace prefix match tells us whether a detected
        // server carries this row's jobs.
        const ns = String(r.id || '').replace(/^abilities-/, '');
        const doors = [];
        servers.forEach((s) => {
          const list = s.tool_list || [];
          if (ns && list.some((t) => String(t.name || '').indexOf(ns + '-') === 0)) {
            doors.push(s.name || 'the MCP server');
          }
        });
        doors.push('the Abilities API');
        return doors;
      };
    },
    // The doors' addresses — connection info only, deliberately without counts.
    doorRows() {
      const out = [];
      (this.mcp.servers || []).forEach((s) => {
        if (s.endpoint) out.push({ badge: 'MCP', url: s.endpoint, label: s.name || 'MCP server' });
      });
      if (!out.length && this.mcp.available && this.mcp.endpoint) {
        out.push({ badge: 'MCP', url: this.mcp.endpoint, label: 'MCP server' });
      }
      const abilities = (this.data && this.data.abilitiesEndpoint) || '';
      if (abilities && (this.counts.tools || 0) > 0) {
        // A display name, not a sentence fragment: it is printed beside the
        // address now, and the old "the Abilities API" also made the copy
        // button announce "Copy the the Abilities API address".
        out.push({ badge: 'REST', url: abilities, label: 'Abilities API' });
      }
      return out;
    },
    resources() {
      return this.data.resources || [];
    },
    // Rows the owner may switch off. Not the site's own content (Content types
    // steers that) and not a bare data door (already an opt-in of its own).
    controllable() {
      // ⛔ And never on a row that publishes nothing anyway — a group whose
      // plugin advertised none of its jobs cannot be switched any further off,
      // and a switch that cannot change anything is worse than none (his call
      // when we prototyped this list).
      return (r) => !(r.auto && r.type !== 'agent') && !r.notPublic;
    },
    // What the "never publish changing jobs" tick would actually take away RIGHT
    // NOW — so it must skip groups already switched off, or it promises to stop
    // work that is not happening.
    changingJobsNote() {
      const n = this.resources
        .filter((r) => r.type === 'agent' && this.isPublished(r.id))
        // ⚠️ announcedChanges, not changes: this switch can only stop announcing
        // what is announced, so counting a group's unannounced jobs would make it
        // promise work it will not do. `changes` counts every job in the group,
        // for the group's own line — two questions, two fields.
        .reduce((sum, r) => sum + (r.announcedChanges || 0), 0);
      // ⭐ "listed", like the rest of the screen — this used "announced" and "kept
      // out of the public files" for the same state the pills call "not listed".
      if (!n) return 'No listed job changes anything, so this would take nothing away.';
      if (this.settings.hold_back_changing_jobs) {
        return n === 1 ? '1 job is not listed because of this.' : n + ' jobs are not listed because of this.';
      }
      return n === 1
        ? 'Would stop listing 1 job that changes something.'
        : 'Would stop listing ' + n + ' jobs that change something.';
    },
    hasJobGroups() {
      return this.resources.some((r) => r.type === 'agent');
    },
    // The auto-discovery engines as compact status chips. ⛔ They no longer head a
    // group of their own: which engine found a row is that row's own footnote now,
    // and this pair only answers "did Agentimus look at all".
    engineChips() {
      return this.adapters.map((a) => ({
        label: String((a && (a.title || a.id)) || 'Adapter').replace(' (auto-discovery)', '').replace('WordPress ', ''),
        ok: !!(a && a.available),
      }));
    },
    adapters() {
      return this.data.adapters || [];
    },
    capabilities() {
      return this.data.capabilities || [];
    },
    tools() {
      return this.data.tools || [];
    },
    mcp() {
      return this.data.mcp || { available: false, source: '', transport: '', tools: 0 };
    },
    wellKnown() {
      return this.data.wellKnown || [];
    },
    // The served rows with their plain-words note attached.
    wellKnownRows() {
      return this.wellKnown.map((w) => ({ ...w, note: WK_NOTES[w.name] || '' }));
    },
    // The attachable documents, gathered across providers for their own section.
    docRows() {
      const out = [];
      this.resources.forEach((r) => {
        (r.toolList || []).forEach((t) => {
          if ('resource' === t.kind) out.push({ name: t.name, title: t.title || '', url: t.uri || '' });
        });
      });
      return out;
    },
    // The first rendered row that carries the sign-in explanation shows it in
    // full; later rows defer to it. Two identical paragraphs back to back
    // taught nothing twice. Render order, not payload order.
    firstHeldId() {
      // Render order, not payload order: reads come before dos on the screen, so
      // the full explanation lands on the first held row an owner actually meets.
      const held = [...this.readRows, ...this.doRows].filter(
        (r) => !r.suppressed && r.notPublic
      );
      return held.length ? held[0].id : '';
    },
    notices() {
      return this.data.notices || [];
    },
    discoveryUrl() {
      return this.endpoints.discovery || '';
    },
    discoveryPath() {
      try {
        return new URL(this.discoveryUrl).pathname;
      } catch (e) {
        return '/.well-known/discovery.json';
      }
    },
  },
  methods: {
    // Published unless the owner said otherwise — the boundary the spec calls
    // owner authority, and the same list the settings screen used to write.
    // ⭐ The fixed things first, the ones you can act on after — his call,
    // 2026-08-15. Ours leads (we never sit among the plugins we describe), then
    // everything with no switch (the site's own content, and a group whose
    // plugin published nothing), then the rows an owner can actually work.
    // Stable, so the order everything else was given is untouched.
    ownFirst(list) {
      const rank = (r) => (r.own ? 0 : (this.controllable(r) ? 2 : 1));
      return [...list].sort((a, b) => rank(a) - rank(b));
    },
    isPublished(id) {
      const sup = Array.isArray(this.settings.suppressed_resources) ? this.settings.suppressed_resources : [];
      return sup.indexOf(id) === -1;
    },
    togglePublish(id) {
      if (!Array.isArray(this.settings.suppressed_resources)) this.settings.suppressed_resources = [];
      const list = this.settings.suppressed_resources;
      const i = list.indexOf(id);
      if (i === -1) list.push(id);
      else list.splice(i, 1);
    },
    // ⭐ OPEN unless the owner shut it. These two sections are what the card is
    // for; a closed one would make the screen look empty. `openParts` remembers a
    // deliberate close, keyed by section so a re-render cannot swap them.
    partOpen(key) {
      return this.openParts[key] !== false;
    },
    onPartToggle(key, event) {
      this.openParts = { ...this.openParts, [key]: !!event.target.open };
    },
    // A stat jumping to a section must not land on a closed one.
    openPart(key) {
      if (this.openParts[key] === false) this.openParts = { ...this.openParts, [key]: true };
    },
    // The rows this filter leaves standing. ⛔ Filtering, never re-sorting: the
    // list keeps one order whatever is selected, so a row is always where it was
    // last time.
    visible(rows) {
      if (this.rowFilter === 'pub') return rows.filter(this.rowIsPublished);
      if (this.rowFilter === 'held') return rows.filter((r) => !this.rowIsPublished(r));
      return rows;
    },
    // Does the capability list actually overflow its box? Measured, not
    // guessed from a row count: macOS hides the overlay scrollbar at rest, so
    // a capped list simply looks like a list that ended, and the only other
    // clue is a total in the heading. Only sets when the answer CHANGES —
    // `updated()` writing state unconditionally would loop.
    measureCaps() {
      const el = this.$refs.capsBox;
      const over = !!(el && el.scrollHeight > el.clientHeight + 2);
      if (over !== this.capsOverflow) this.capsOverflow = over;
    },
    // Both capped lists, measured the same way — the tools list is the one that
    // actually overflows in practice (67 rows on a nine-plugin site).
    measureBoxes() {
      this.measureCaps();
      const el = this.$refs.toolsBox;
      const over = !!(el && el.scrollHeight > el.clientHeight + 2);
      if (over !== this.toolsOverflow) this.toolsOverflow = over;
    },
    // A summary stat → its section. We're already on the discovery tab, so route
    // through the app's own goTo (via 'navigate') for the shared smooth-scroll +
    // flash, rather than a bespoke scroll here.
    jumpTo(anchor) {
      // ⛔ Never scroll someone to a section they cannot see. The tools and APIs
      // stats land inside the two folds, so a stat pressed while its section is
      // shut would flash an empty summary line and nothing else.
      // ⚠️ The address list lives INSIDE the read fold, so its anchor has to open
      // that fold too — and the "held back" filter hides it, so clear that as well
      // or the stat scrolls to a list that is not on the page.
      if ('ar-wd-read' === anchor || 'ar-wd-apis' === anchor) this.openPart('read');
      if ('ar-wd-apis' === anchor && 'held' === this.rowFilter) this.rowFilter = 'all';
      if ('ar-wd-do' === anchor) this.openPart('do');
      this.$emit('navigate', { tab: 'discovery', anchor });
    },
    async copyDoor(d) {
      const text = (d && d.url) || '';
      if (!text) return;
      const ok = await copyText(text);
      if (!ok) return;
      this.copiedDoor = text;
      clearTimeout(this._doorCopyTimer);
      this._doorCopyTimer = setTimeout(() => { this.copiedDoor = ''; }, 2000);
    },
  },
};
</script>

<template>
  <div class="ar-wd">
    <!-- Canonical endpoint -->
    <section class="ar-card ar-wd-endpoint">
      <h2 class="ar-card__title">Discovery Endpoint</h2>

      <div class="ar-wd-endpoint-row">
        <div class="ar-wd-canonical">
          <span class="ar-wd-canonical__method">GET</span>
          <span class="ar-wd-canonical__path">{{ discoveryPath }}</span>
          <a class="ar-wd-canonical__ext" :href="discoveryUrl" target="_blank" rel="noopener" aria-label="Open discovery.json in a new tab"></a>
        </div>
        <button type="button" class="ar-btn" :disabled="refreshing" @click="$emit('refresh')">
          {{ refreshing ? 'Scanning…' : 'Re-scan' }}
        </button>
      </div>
      <!-- No alt-doc link list here: the always-visible rail already lists the discovery
           docs (a global shortcut), and the "Well-known documents" section below is the
           full inventory WITH its generated/managed status. Repeating the links here made
           agent-card.json / discovery.json appear three times on one screen. -->

      <!-- Each stat jumps (and flashes) to the section where its number is countable —
           so a total is never a dead end. Errors is a link only when there ARE errors
           (the "Registration status" card that would receive the jump renders only then);
           at zero it's a plain, calm cell. -->
      <div class="ar-wd-stats ar-wd-stats--fill">
        <!-- ⚠️⚠️ THIS SAID THE SAME THREE NUMBERS AS THE STRIP 200px BELOW IT, IN
             DIFFERENT WORDS, AND ONE OF THEM WAS WRONG. It read "7 public · 4
             sign-in only" while the strip read "published 7 · held back 4" — and
             of those four, one is a dead address and one the owner switched off,
             so only two were ever "sign-in only". ⭐ A summary may repeat a number
             the page states again below (that is what a summary is for); it may
             never repeat it in different words, and it may never be wrong. Same
             words as the control that owns the split.
             ⛔ "providers" and "sources describing your site" are gone too: the
             section is no longer called that, and a stat whose label names a
             heading that does not exist sends the reader looking for it. -->
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-providers')">
          <strong>{{ providersRegistered }}</strong>
          <span>things offered</span>
          <small v-if="providersHeld > 0">{{ counts.resources }} listed · {{ providersHeld }} not listed</small>
          <small v-else>Everything assistants can read or run</small>
        </button>
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-capabilities')">
          <strong>{{ counts.capabilities }}</strong>
          <span>capabilities</span>
          <small>What AI assistants may read or do</small>
        </button>
        <!-- ⭐ Each stat lands where its number can be COUNTED. Now that one list
             holds everything, that means the group inside it, not a section of
             its own: tools land on the "can do" group, APIs on the "can read"
             one — never all three on the same anchor, which would make two of
             them read as decoration. -->
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-do')">
          <strong>{{ counts.tools }}</strong>
          <span>tools</span>
          <!-- Same correction: the 33 are not all "sign-in only" — four of them
               are a group the owner switched off. What is true of all 33 is only
               that your public file does not list them. -->
          <small v-if="toolsHeld > 0">{{ counts.toolsPublished }} listed · {{ toolsHeld }} not listed</small>
          <small v-else>Actions AI assistants can run</small>
        </button>
        <!-- ⚠️ Back to the address list, not the section. Pointed at the section it
             landed on a heading reading 6 while the stat said 5 — they count
             different things (rows vs addresses), and a number that lands beside a
             different number is the fault this row exists to avoid. -->
        <button type="button" class="ar-wd-stat is-link" @click="jumpTo('ar-wd-apis')">
          <strong>{{ counts.apis }}</strong>
          <span>APIs</span>
          <!-- Same words as the dashboard tile: one count, one caption. "Endpoints
               agents can read" said the document verb about a callable thing, and
               collided with the capabilities tile's "read" beside it. -->
          <small>Addresses AI assistants can call for data</small>
        </button>
        <button
          v-if="counts.errors > 0"
          type="button"
          class="ar-wd-stat is-link is-bad"
          @click="jumpTo('ar-wd-validation')"
        >
          <strong>{{ counts.errors }}</strong>
          <span>errors</span>
          <small>Problems to fix — open</small>
        </button>
        <div v-else class="ar-wd-stat">
          <strong>{{ counts.errors }}</strong>
          <span>errors</span>
          <small>None — all clear</small>
        </div>
      </div>

      <!-- No word legend here (tried 2026-08-11, removed the same day): the owner's
           call is that each SECTION's own lead teaches its word where the rows are,
           not a glossary strip on the endpoint card. -->
    </section>

    <!-- ⭐⭐ ONE LIST, split by the question an owner actually has (his call,
         2026-08-17). This section replaces three: the provider inventory folded
         by HOW WE FOUND IT, a separate list of addresses, and a separate list of
         jobs — which between them described the same thing up to three times.
         Provenance is not a structure, it is one grey line at the bottom of a row.
         ⛔ NOT grouped by vendor (one WooCommerce row holding its address AND its
         jobs): `Jobs from "core"` and `Your site's content` are both WordPress,
         and only a person knows that — the code would infer it from a slug, the
         same guess that once filed MailPoet's jobs under WooCommerce's name. -->
    <section id="ar-wd-providers" class="ar-card">
      <!-- Title Case, like every other card title on the screen — his convention,
           stated 2026-08-17. It also rhymes with the dashboard's "What Your Site
           Runs", which is the same question asked of the site's own software. -->
      <h2 class="ar-card__title">What Your Site Offers AI Assistants</h2>
      <p class="ar-card__lead">
        Everything an assistant can read or run here, one row each. Open a row for its addresses,
        its jobs and where it came from.
      </p>

      <p v-if="!resources.length" class="ar-wd-empty">
        Nothing here yet. Agentimus fills this in by itself as it reads your site. And a plugin that
        knows how to introduce itself adds its own rows when you install it.
      </p>

      <template v-else>
        <!-- ⭐⭐ THE SUMMARY IS THE FILTER — his question, 2026-08-17: "why is only
             Held Back clickable?". There was no good answer. Three cells looked
             identical and one behaved differently, and worse, a separate row of
             chips underneath already did that same filtering: two controls for
             one job, touching each other. Now every cell is the same kind of
             thing — a count you can press — so there is nothing left to explain.
             ⛔ The list still never reorders itself; filtering is all this does. -->
        <div class="ar-wd-sum" role="group" aria-label="Show which rows">
          <button
            v-for="f in rowFilters"
            :key="f.key"
            type="button"
            class="ar-wd-sum__cell"
            :aria-pressed="String(rowFilter === f.key)"
            @click="rowFilter = f.key"
          >
            <span>{{ f.label }}</span>
            <strong :class="f.tone">{{ f.count }}</strong>
          </button>
        </div>

        <!-- ⛔ No "addresses checked" cell beside those: this sentence already
             carries the same numbers, and a count stated twice invites the reader
             to look for the difference between them. -->
        <p v-if="reachabilityNote" class="ar-wd-reach__note">{{ reachabilityNote }}</p>

        <!-- ⭐⭐ EACH GROUP IS ITS OWN PANEL, and its heading is a real heading.
             His read, 2026-08-17: "both sections are mild in terms of being
             noticeable, no clear separation". They were — `.ar-wd-lhead` is an
             11px faint uppercase label, quieter than the rows beneath it, so two
             sections that are the whole point of the screen read as one long
             list. `.ar-wd-sect` is the answer this plugin already found once, on
             the MCP card: "headers alone left the sections reading as one long
             list; a bordered panel makes each one a thing you can see the edges
             of." -->
        <!-- ⚠️⚠️ TWO LEVELS OF DISCLOSURE, and they must never look alike. The
             SECTION fold is a bordered box that hides half the screen; a ROW
             opens a line to show one thing's detail. Outer is a box with a
             triangle, inner is a name with a chevron, and the outer starts OPEN —
             these two sections are the card's content, not an offer to see it,
             so collapsing is for getting one out of the way, never a door you
             must find. -->
        <details
          id="ar-wd-read"
          class="ar-fold ar-wd-part"
          :open="partOpen('read')"
          @toggle="onPartToggle('read', $event)"
        >
          <!-- ⚠️ THE FILTERED COUNT, NOT THE TOTAL. Under "Held back" these
               headings read 6 and 5 while showing 1 and 3 — a number beside a
               heading has one job, which is to count the rows under it, and one
               that cannot be counted is worse than none. The totals are not lost:
               the strip above states all three.
               ⭐ A real heading inside the summary, the way the Search
               Opportunities groups do it: a line that reads as a heading on
               screen has to be one in the outline too. -->
          <summary>
            <h3 class="ar-wd-part__title">
              Things Assistants Can Read
              <span class="ar-wd-group__count">{{ visible(readRows).length }}</span>
            </h3>
          </summary>
          <!-- Full width, his call: a definition is not a sidebar remark, and a
               measure that stops halfway across a wide card leaves a ragged
               column of grey beside a list that runs the whole width. -->
          <!-- ⭐ Rewritten for a reader whose English is a second language: no
               metaphor ("a community's rooms"), no sentence over 20 words, and the
               26-word claim about proving split into steps you can follow. -->
          <p class="ar-card__note ar-card__note--wide">
            A web address an assistant can get data from — your posts, a product list, a community’s
            spaces. We test each one every day: we send a real request with no sign-in. If it works,
            we mark the address <strong>open to anyone</strong>.
          </p>
          <!-- ⛔ Hidden under the "held back" filter: a list of what IS published,
               sitting above rows chosen for not being published, would answer a
               question nobody just asked. -->
          <div v-if="publishedAddresses.length && rowFilter !== 'held'" id="ar-wd-apis" class="ar-wd-addrs">
            <p class="ar-wd-lhead">
              Addresses assistants can ask
              <span class="ar-wd-group__count">{{ publishedAddresses.length }}</span>
              <span class="ar-wd-lhead__note">what your public file contains</span>
            </p>
            <!-- ⭐ ITS OWN, LIGHTER ROW — not the bordered card the doors wear. As
                 cards these five took 34% of the section at 49px each, against
                 74px for the rows that are the actual inventory, so the recap read
                 as a second list of similar things rather than a summary of the
                 first. ⛔ A summary that competes with what it summarises has
                 stopped being one. -->
            <ul class="ar-wd-addrs__list">
              <li v-for="a in publishedAddresses" :key="a.url">
                <span class="ar-wd-addrs__type">{{ a.type }}</span>
                <code>{{ a.url }}</code>
                <span v-if="a.provider" class="ar-wd-addrs__src">{{ a.provider }}</span>
                <span class="ar-wd-auth" :class="a.open ? 'is-open' : 'is-locked'">{{ a.auth }}</span>
              </li>
            </ul>
          </div>
          <!-- ⚠️ THE ROWS HAVE TO BE RE-ANNOUNCED HERE. The section's own heading is
               three blocks up — past the lead, past the recap — so after that
               address list the rows simply began, and a reader could not tell
               whether they were more addresses, or what they were at all. ⛔ NO
               COUNT on this line: the section heading above already states it, and
               the same number twice is the fault we just spent the morning
               removing. The "do" section needs none of this — nothing interrupts
               between its lead and its rows. -->
          <!-- ⛔ Was "What offers them" — a heading made of a pronoun, so the
               reader has to look back to find what "them" means. -->
          <p v-if="visible(readRows).length" class="ar-wd-lhead">
            Where these come from
            <span class="ar-wd-lhead__note">open one to see its check and where it came from</span>
          </p>
          <ul v-if="visible(readRows).length" class="ar-wd-list">
            <ProviderRow v-for="r in visible(readRows)" :key="r.id" :r="r" :brief-held="r.id !== firstHeldId" :controllable="controllable(r)" :published="isPublished(r.id)" :doors="doorsFor(r)" @toggle-publish="togglePublish" />
          </ul>
          <p v-else class="ar-wd-empty">{{ emptyFilterNote }}</p>
        </details>

        <details
          id="ar-wd-do"
          class="ar-fold ar-wd-part"
          :open="partOpen('do')"
          @toggle="onPartToggle('do', $event)"
        >
          <summary>
            <h3 class="ar-wd-part__title">
              Things Assistants Can Do
              <span class="ar-wd-group__count">{{ visible(doRows).length }}</span>
            </h3>
          </summary>
          <!-- ⭐ SAY WHAT THE THING IS, then how it is reached — his note that the
               sentence which finally made "job" clear to him was the one naming
               the machinery. Named once, in words an owner can carry: a job is
               asked for and carried out; an address is fetched. And the answer to
               "does a job mean writing?" is put where the question arises. -->
          <!-- ⭐ Was a sentence fragment with a dash-list, then a 30-word sentence
               using "a stranger" as a metaphor. Now: a definition with a subject
               and a verb, an example list introduced properly, and "someone who
               has not signed in" said outright. -->
          <p class="ar-card__note ar-card__note--wide">
            A job is something an assistant asks your site to do. For example: find a number, write
            a description, or update a product. Plugins register jobs with WordPress, and an
            assistant reaches them through your MCP connection.
            <strong>Every job needs a sign-in first</strong>, even the ones that only read. Someone
            who has not signed in can never run one. Letting assistants connect to Agentimus itself
            is a separate switch under Settings → Discovery, off unless you turn it on.
          </p>
          <ul v-if="visible(doRows).length" class="ar-wd-list">
            <!-- ⛔ No kind badge in this group: every row in it is jobs, and the
                 heading three lines up already says so. -->
            <ProviderRow v-for="r in visible(doRows)" :key="r.id" :r="r" :brief-held="r.id !== firstHeldId" :controllable="controllable(r)" :published="isPublished(r.id)" :doors="doorsFor(r)" :show-kind="false" @toggle-publish="togglePublish" />
          </ul>
          <p v-else class="ar-wd-empty">{{ emptyFilterNote }}</p>
        </details>

        <p v-if="engineChips.length" class="ar-wd-engines">
          Agentimus looked for these automatically:
          <span
            v-for="e in engineChips"
            :key="e.label"
            class="ar-wd-engine"
            :class="e.ok ? 'is-on' : 'is-off'"
          >{{ e.label }} {{ e.ok ? '✓' : '✕' }}</span>
        </p>

        <!-- The one rule that spans every group above, so it sits under them all
             rather than inside any one fold. -->
        <label v-if="hasJobGroups" class="ar-wd-rule">
          <input v-model="settings.hold_back_changing_jobs" type="checkbox" />
          <span class="ar-wd-switch__track" aria-hidden="true"></span>
          <span class="ar-wd-rule__text">
            <strong>Never publish jobs that change something</strong>
            <!-- Say WHOSE decision this is. Without it the switch reads as a
                 contradiction (he read it that way): we follow the vendor on
                 what to advertise, and here we appear to overrule them. Two
                 different people are deciding — the plugin says what it offers,
                 the owner says what their own site announces — and the screen
                 has to name that or it looks like we changed our mind. -->
            <small>
              Your choice, not the plugin’s. These plugins asked to have them listed. This leaves
              them unlisted anyway. Off unless you switch it on.
            </small>
            <small>{{ changingJobsNote }}</small>
          </span>
        </label>

        <p class="ar-card__note">
          <strong>This changes what your site lists, not what it can do.</strong>
          Switching something off means it is not listed. The plugin keeps working exactly as
          before, and an assistant that signs in still finds it.
        </p>
      </template>
    </section>

    <!-- Capabilities — WHAT the providers above expose, aggregated into one countable
         list (one row per token). Sits right after the providers that declare it, and
         is where the dashboard's "Capabilities" tile lands, so its number connects to
         rows you can count — the way Providers lands on the provider cards. -->
    <section v-if="capabilityRows.length" id="ar-wd-capabilities" class="ar-card">
      <!-- ⚠️⚠️ THIS CARD SAID TWO UNTRUE THINGS AT ONCE, both caught by him asking
           whether it made sense. ① "What Assistants May READ" — while the list
           holds `scheduling.booking.create`, which is not a read. A title that is
           wrong for even one row of its own list is a title that has to change,
           and the old comment here admitted the verb was a problem rather than
           fixing it. ② It sent the reader to "For a signed-in assistant", a
           heading that no longer exists — the merge folded those groups into the
           rows and this pointer was left aiming at nothing. ⛔ Deleting a section
           means hunting every sentence that named it. -->
      <h2 class="ar-card__title">What Those Rows Allow <span class="ar-card__count">{{ capabilityRows.length }}</span></h2>
      <p class="ar-card__lead">
        One line for each kind of thing the rows above allow, and this is where their number comes
        from. These are the words your document uses to state permission —
        <code>commerce.products.read</code> means “read this shop’s products”. They are not buttons:
        what an assistant can actually run sits on the rows themselves, under
        <strong>Things Assistants Can Do</strong>. Most only read, but a plugin may declare a create
        or an update too.
      </p>
      <!-- Scrolls inside a fixed height rather than growing without limit. Most
           of these are one per public REST post type and taxonomy, so the length
           is the SITE's to decide, not ours — a busy shop with an LMS can push
           this past 60 rows and the card would own the whole screen. Same box
           the index groups use. -->
      <ul ref="capsBox" class="ar-wd-tools ar-wd-scrollbox">
        <li v-for="c in capabilityRows" :key="c.token" class="ar-wd-tool">
          <div class="ar-wd-tool__id">
            <code>{{ c.token }}</code>
          </div>
          <div class="ar-wd-tool__meta">
            <span class="ar-wd-badge">{{ c.verb }}</span>
            <span class="ar-wd-cap-src">{{ c.provider }}</span>
          </div>
        </li>
      </ul>
      <p v-if="capsOverflow" class="ar-wd-scrollnote">
        Scroll the list to see all {{ capabilityRows.length }}.
      </p>
      <!-- ⛔ The thirteen `content.*.read` tokens are gone from this sentence. They
           were a 36-word wall of code inside a paragraph, and every one of them is
           already a row in the list directly above — listing them again made the
           note both unreadable and redundant. The count says how many; the rows
           say which. -->
      <p v-if="ownContentCapabilities.length" class="ar-card__note ar-card__note--wide">
        <strong>{{ ownContentCapabilities.length }} of these come from your own content.</strong>
        You choose them in
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings', anchor: 'ar-content-types' })">Settings → Content types</button>.
        When you tick a kind of content, its categories and tags are included too. The rest come
        from the plugins that own them.
      </p>
    </section>

    <!-- The MCP server itself: the door, not what is behind it. The jobs moved up
         into their own rows, so what is left here is connection business — which
         server, how you sign in, which files it offers, and what a stranger can
         read of it. -->
    <section id="ar-wd-tools" class="ar-card">
      <h2 class="ar-card__title">The MCP Connection</h2>
      <!-- ⚠️ ITS SECOND SENTENCE IS GONE. It said "an assistant that has not
           signed in can read the list you publish… but it cannot run anything on
           it" — and the note sitting directly on that list, further down this
           same card, says exactly that: "anyone can read /.well-known/mcp.json —
           running one still needs a sign-in". ⭐ Of two places that say the same
           thing, the one ON the thing wins; the lead only has to get you there. -->
      <p class="ar-card__lead">
        How an assistant connects to run the jobs listed above, and what it can see before it signs
        in.
      </p>

      <!-- ONE total, stated once; the groups below PARTITION it (they visibly add up).
           Doors carry no counts anywhere — counts on overlapping doors invited
           "14 + 16 = 30". -->
      <div class="ar-wd-mcp">
        <div class="ar-wd-mcp__cell">
          <span>things to do</span>
          <strong :class="counts.tools > 0 ? 'is-on' : 'is-off'">{{ counts.tools }}</strong>
        </div>
        <div class="ar-wd-mcp__cell">
          <!-- "public" alone, sitting beside "sign-in", read as "these 67 are
               open to anyone". They are LISTED in a file anyone can read, which
               is a different sentence — the same one the panel below now uses. -->
          <span>in public file</span><strong>{{ typeof counts.toolsPublished === 'number' ? counts.toolsPublished : '—' }}</strong>
        </div>
        <!-- ⚠️ IT IS A FACT ABOUT THE SERVER, NOT ABOUT THE JOBS BESIDE IT. Two
             cells here count tools and two describe the MCP server, and they were
             dressed identically — so "sign-in: OAuth" read as "all 42 of these
             need OAuth". They do not: OAuth is how an assistant gets in through
             the MCP door, and the same jobs are also reachable with an
             application password through WordPress's own abilities route. Naming
             the door in the label is the whole fix. -->
        <div class="ar-wd-mcp__cell">
          <span>MCP sign-in</span><strong>{{ counts.tools > 0 ? mcpAuthLabel : '—' }}</strong>
        </div>
        <div class="ar-wd-mcp__cell">
          <span>MCP server</span>
          <strong :class="mcp.available ? 'is-on' : 'is-off'">{{ mcpServerLabel }}</strong>
        </div>
      </div>

      <!-- ⛔ The per-provider job groups that stood here are GONE, not moved twice:
           each provider's jobs now live inside that provider's own row above, so
           this card never repeats a name the list has already given. -->

      <!-- Documents live APART from the tools — MCP itself keeps the two lists
           apart (tools/list vs resources/list), and a "documents" chip inside a
           tools group read as four secret abilities (owner, 2026-08-11). They
           are abilities too, technically — registered with a URI instead of an
           action — but what an agent DOES with them is attach and read, so the
           screen mirrors the protocol's own split. -->
      <div v-if="counts.docs" class="ar-wd-sect">
        <p class="ar-wd-lhead">
          Documents an assistant can attach
          <span class="ar-wd-group__count">{{ counts.docs }}</span>
          <span class="ar-wd-lhead__note">read in the session, not run — the files themselves are public</span>
        </p>
        <!-- Same row grammar as the Published list beside it — a bare code+title
             line was the one undressed row style on the card. The chip is the
             literal truth the section note spells out: these files are public. -->
        <ul v-if="docRows.length" class="ar-wd-tools">
          <li v-for="d in docRows" :key="d.name" class="ar-wd-tool">
            <div class="ar-wd-tool__id">
              <a v-if="d.url" class="ar-wd-doclink" :href="d.url" target="_blank" rel="noopener"><code>{{ d.name }}</code></a>
              <code v-else>{{ d.name }}</code>
              <span v-if="d.title" class="ar-wd-tool__title">{{ d.title }}</span>
            </div>
            <div class="ar-wd-tool__meta">
              <span class="ar-wd-auth is-open">public</span>
            </div>
          </li>
        </ul>
        <p class="ar-card__note ar-card__note--wide">
          These are this site's own public files, offered inside the connection so an assistant
          can attach one without fetching the URL. Anyone can read them at their addresses either way.
        </p>
      </div>

      <!-- The doors' addresses — connection info only, no numbers. COPY, not open:
           both doors require a signed-in agent, so a browser click can only ever
           answer 401 — the real user intent is pasting the address into a tool.
           "Where they connect" left "they" dangling — it could as easily have
           meant the tools — so the header names the referent instead. -->
      <div v-if="doorRows.length" class="ar-wd-sect">
      <!-- ⛔ "The doors named above" was a metaphor a reader has to decode, on top
           of a backward reference. These are the addresses an assistant connects
           to; say that. -->
      <p class="ar-wd-lhead">
        Addresses an assistant connects to
        <span class="ar-wd-lhead__note">a sign-in is needed, so copy the address instead of opening it</span>
      </p>
      <div v-for="d in doorRows" :key="d.url" class="ar-wd-canonical ar-wd-mcp-endpoint">
        <span class="ar-wd-canonical__method">{{ d.badge }}</span>
        <span class="ar-wd-canonical__path">{{ d.url }}</span>
        <!-- Which door this is. The groups above name their doors in prose
             ("via the Abilities API") while these rows carried only a URL, so
             the reader had to recognise a door by its path — and the APIs
             section, using this very row, names its provider beside it. Same
             row, same grammar. -->
        <span class="ar-wd-cap-src">{{ d.label }}</span>
        <button
          type="button"
          class="ar-wd-canonical__ext ar-wd-copy"
          :class="{ 'is-copied': copiedDoor === d.url }"
          :aria-label="'Copy the ' + d.label + ' address'"
          @click="copyDoor(d)"
        >{{ copiedDoor === d.url ? '✓ copied' : 'copy' }}</button>
      </div>
      </div>

      <!-- A DIFFERENT list from the groups above: the tools NAMED in the public
           file, not a second set of tools. Both render as identical rows, so
           its own panel is what keeps the two apart.
           ⛔ It used to read "Published for anonymous assistants", which invited
           the reading that a stranger could RUN these. They can be read by
           anyone and run by nobody without a sign-in — the same two questions
           that had 32 of them stamped "public" in the document itself. The
           heading names the file; the note says the rest. -->
      <div v-if="tools.length" class="ar-wd-sect">
      <p class="ar-wd-lhead">
        Listed in your public file
        <span class="ar-wd-group__count">{{ tools.length }}</span>
        <span class="ar-wd-lhead__note">
          anyone can read /.well-known/mcp.json — running one still needs a sign-in
        </span>
      </p>
      <!-- ⚠️ CAPPED, like the capabilities list beside it. This one had no bound
           at all, which was backwards: the 6-row list was capped and the 67-row
           list ran free, stretching the card past every other panel on the
           screen (his catch, 2026-08-17, on a nine-plugin site). -->
      <ul ref="toolsBox" class="ar-wd-tools ar-wd-scrollbox">
        <li v-for="t in tools" :key="t.name" class="ar-wd-tool">
          <div class="ar-wd-tool__id">
            <code>{{ t.name }}</code>
            <span v-if="t.title" class="ar-wd-tool__title">{{ t.title }}</span>
          </div>
          <div class="ar-wd-tool__meta">
            <span v-if="t.annotations && t.annotations.readOnlyHint" class="ar-wd-badge">read-only</span>
            <!-- ⛔ THE `schema` BADGE IS GONE (his catch): every tool that reaches
                 this list declares an input schema — 67 of 67 on agentimus-site —
                 so it printed on every row and distinguished nothing. Same rule
                 that already dropped the KIND badge from this section: a label
                 that never varies inside its own section is furniture. It was
                 also the wrong KIND of fact — "read-only" answers "can this
                 change my site?", which the owner acts on; "schema" describes
                 the tool's wire format, which they do not. -->
            <!-- ⭐ Only the exception is worth a chip. Every ability is gated by
                 its own permission callback, so this reads "sign-in" on every
                 row under a heading that already says "For a signed-in
                 assistant" — a badge that never varies is furniture. It shows
                 only when something really is open to anyone, and it never
                 prints the raw scheme name: "wp" is our word, not the owner's. -->
            <span v-if="t.auth === 'none'" class="ar-wd-auth is-open">public</span>
          </div>
        </li>
      </ul>
      <p v-if="toolsOverflow" class="ar-wd-scrollnote">
        Scroll the list to see all {{ tools.length }}.
      </p>
      </div>
      <!-- Two different empty states: tools can exist but be deliberately withheld
           from anonymous discovery (all sign-in-only since 1.20.0) — saying "none
           yet" while the count above reads 14 would be a lie. -->
      <!-- No count in this sentence: the mini-table above says 14 (this MCP
           server's tools) while the site-wide inventory is 17 (core's abilities
           aren't on Agentimus's scoped server) — citing either here would sit
           beside the other and read as a contradiction. -->
      <!-- ⛔ Does not re-argue the policy: the rows above already say why a
           sign-in-only group stays out of the public file. An empty state says
           what is empty and what still works, and stops. -->
      <p v-else-if="counts.tools > 0" class="ar-wd-empty">
        Nothing is listed here, because every job on this site needs a sign-in. An assistant that
        signs in still finds them all.
      </p>
      <p v-else class="ar-wd-empty">
        Nothing for assistants to do here yet. These come from your plugins: once one registers a job
        with WordPress, it shows up in this list by itself.
      </p>
    </section>

    <!-- Well-known documents — full width (validation status now lives as a
         compact chip in the right rail). -->
    <section class="ar-card">
      <h2 class="ar-card__title">Well-Known Documents</h2>
      <!-- ⭐ Was one 33-word sentence with a dash, a relative clause and a reason
           clause stacked on each other. Three short sentences say the same thing. -->
      <p class="ar-card__lead">
        The addresses an AI assistant checks first, because every site keeps them in the same place.
        Agentimus answers all of them. If a real file on your server answers one instead, we mark it
        <strong>on disk</strong> — a real file always wins, and nothing here can change that.
      </p>
      <ul class="ar-wd-wk">
        <li v-for="w in wellKnownRows" :key="w.name">
          <a :href="w.url" target="_blank" rel="noopener"><code>/.well-known/{{ w.name }}</code></a>
          <span v-if="w.spec" class="ar-wd-src ar-wd-src--spec">{{ w.spec }}</span>
          <!-- ON DISK stays the exception that changes what an owner would do:
               the web server answers it and we never override it. The quiet
               "served" tick on every other row is the owner's 2026-08-11 ask —
               a bare path said nothing about being live and connected. With a
               note per row, the tick is no longer the row's only content (the
               old per-row "AGENTIMUS"/GENERATED/MANAGED marks were, and said
               only our own plumbing's name — those stay gone). -->
          <span v-if="'file' === w.source" class="ar-wd-src is-file">ON DISK</span>
          <span v-else class="ar-wd-src is-live">SERVED ✓</span>
          <p v-if="w.note" class="ar-wd-wk__note">{{ w.note }}</p>
        </li>
      </ul>
    </section>

    <!-- Validation only takes main-column space when there is something to fix;
         the all-clear state is the green chip in the rail. -->
    <section v-if="notices.length" id="ar-wd-validation" class="ar-card">
      <h2 class="ar-card__title">Registration Status</h2>
      <ul class="ar-wd-notices">
        <li v-for="(n, i) in notices" :key="i" class="ar-wd-notice" :class="`is-${n.level || 'info'}`">
          <span class="ar-wd-notice__tag">{{ String(n.level || 'info').toUpperCase() }}</span>
          <span>{{ n.message }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>
