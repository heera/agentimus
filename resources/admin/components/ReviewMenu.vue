<script>
/**
 * Nav-bar "Review queue" menu: a count badge that opens a dropdown of the flagged
 * clients still needing a decision (blocked ones are handled, so they're filtered out).
 * Visible from every tab.
 *
 * Design note — HONEST by construction. We show only signals we actually have: the
 * forward-confirmed reverse-DNS verdict (for the 5 verifiable search engines), the
 * known-bot identity, the legacy-device spoof heuristic, self-declaration, volume and
 * novelty — plus, when the owner opts in, the real source IPs captured for FLAGGED clients
 * (Settings → "Store IP addresses for flagged clients"). We deliberately DON'T fabricate a
 * trust %, an ASN/IP-ownership breakdown, or a "Block IP" button (the plugin isn't a
 * firewall). Verification is a three-state fact (verified / failed / not-checked), never a
 * score.
 */
import { impostorDetail, scannerDetail } from '../js/reviewCopy.js';
import { tipGuard } from '../js/tipGuard.js';
import { relTimeShort } from '../js/wpDate.js';
import { copyText } from '../js/clipboard.js';

export default {
  name: 'ReviewMenu',
  props: {
    threats: { type: Object, default: () => ({ sources: [], counts: {}, blockingOn: false }) },
    blocking: { type: Object, default: null },
    allowing: { type: Object, default: null },
    dismissing: { type: Object, default: null },
    reverifying: { type: Object, default: null },
    // The last re-check result, shown inline on the matching row: { ua, status, verdict }.
    reverifyResult: { type: Object, default: null },
    // Whether activity logging is on. The bell is a persistent anchor whenever it
    // is — a stable, quiet resting state (no count badge) rather than vanishing the
    // moment the queue clears. With logging off there's nothing to watch, so hide it.
    enabled: { type: Boolean, default: false },
    // The boot payload's cached queue count (the WP sidebar bubble's own
    // number). Holds the badge truthful for the beat before the first
    // activity payload lands — the live list takes over the moment it does.
    seed: { type: Number, default: 0 },
    // Whether "Store IP addresses for flagged clients" is currently on. Drives the
    // impostor Details copy: with it off we say "turn it on for future visits"; with it
    // on but no IP stored (a hit that predates it) we say so instead of misleadingly
    // telling the owner to enable a setting that's already enabled.
    storeIps: { type: Boolean, default: false },
    // Live updates: whether this screen is auto-refreshing, and how often (seconds),
    // for the toggle's label. The parent owns the state and the interval.
    live: { type: Boolean, default: false },
    liveInterval: { type: Number, default: 15 },
  },
  emits: ['block', 'allow', 'dismiss', 'reverify', 'navigate', 'set-live', 'flash', 'manage'],
  data() {
    // `tab` is the active filter (all | attention | new). `openRow` holds the identity key
    // of the one row whose Details panel is expanded (keyed, not indexed, so a live refresh
    // that reorders rows keeps the right one open).
    return {
      open: false,
      tab: 'all',
      openRow: null,
      // Styled hover tooltip for the User-Agent (the dark "click to copy" bubble).
      // position:fixed + teleported to <body>, but pinned to BOTH panel edges (left `x` +
      // `right`) so it fills the panel with equal side gaps; `caret` points it at the UA.
      uaTip: { show: false, text: '', x: 0, right: null, y: 0, caret: 16, below: false },
    };
  },
  computed: {
    // Pending = still needs a decision. A blocked client is handled (and managed in
    // Settings), so it's neither listed, counted, nor surfaced here at all.
    pending() {
      return (this.threats.sources || []).filter((s) => !s.blocked);
    },
    count() {
      // Before the first activity payload arrives the parent passes threats
      // as {} — no sources array at all. That is "not answered yet", not
      // "zero": the boot seed holds the badge steady until the list lands.
      if (!Array.isArray(this.threats.sources)) return Number(this.seed) || 0;
      return this.pending.length;
    },
    // "Needs attention": the clear problems — a caught impersonator or a legacy-device
    // scanner. These are what an owner should look at first.
    attention() {
      return this.pending.filter((s) => 'spoofed' === s.verdict || (s.flags && s.flags.spoof));
    },
    // Show the Flagged filter only when it's a PROPER subset of All — otherwise the tab
    // bar adds nothing (every row already flagged, or none is). Novelty needs no tab of
    // its own: every new row already carries a "New" badge inline.
    showReviewTab() {
      return this.attention.length > 0 && this.attention.length < this.count;
    },
    // The effective tab — falls back to All if Flagged isn't showing, so a data change can
    // never leave the list filtered by a hidden tab.
    activeTab() {
      return 'attention' === this.tab && this.showReviewTab ? 'attention' : 'all';
    },
    // The rows the active tab shows (server already sorts worst-first).
    shown() {
      return 'attention' === this.activeTab ? this.attention : this.pending;
    },
    // Rows paired with their computed view-model, so card() runs once per row.
    shownCards() {
      return this.shown.map((s) => ({ s, c: this.card(s) }));
    },
    // How long a client keeps its "new" flag, from the server — so a site that
    // filters `agentimus_new_agent_seconds` shows its real window, not our default.
    newSecs() {
      return this.threats.newSecs || 48 * 3600;
    },
    // The window as plain copy ("48 hours") for the footer note.
    newWindowLabel() {
      const h = Math.max(1, Math.round(this.newSecs / 3600));
      return `${h} hour${1 === h ? '' : 's'}`;
    },
  },
  watch: {
    // Repaint the WP sidebar's menu badge the moment the queue changes HERE —
    // the sidebar is server-rendered from a cached count, and waiting for the
    // next Heartbeat tick would leave the two numbers disagreeing for up to a
    // minute after a Block. The painter is published by ReviewBadge::inline_js();
    // if it's absent the Heartbeat listener isn't on this page either — skip.
    count(n) {
      if (window.agentimusReviewBadge) window.agentimusReviewBadge(n);
    },
  },
  mounted() {
    document.addEventListener('click', this.onDocClick);
    document.addEventListener('keydown', this.onKey);
    this._uaTipUnguard = tipGuard.register(this.hideUaTip);
  },
  beforeUnmount() {
    document.removeEventListener('click', this.onDocClick);
    document.removeEventListener('keydown', this.onKey);
    if (this._uaTipUnguard) this._uaTipUnguard();
  },
  methods: {
    toggle() {
      this.open = !this.open;
    },
    close() {
      this.open = false;
    },
    onDocClick(e) {
      if (this.open && this.$el && !this.$el.contains(e.target)) this.close();
    },
    onKey(e) {
      if ('Escape' === e.key) this.close();
    },
    // ---- Decisions -------------------------------------------------------------
    doBlock(s) {
      this.$emit('block', 'spoofed' === s.action ? { spoofed: true } : { ua: s.ua });
    },
    isBlocking(s) {
      const b = this.blocking;
      if (!b) return false;
      return b.spoofed ? 'spoofed' === s.action : b.ua === s.ua;
    },
    doAllow(s) {
      this.$emit('allow', { ua: s.ua });
    },
    isAllowing(s) {
      return !!this.allowing && this.allowing.ua === s.ua;
    },
    doDismiss(s) {
      this.$emit('dismiss', { ua: s.ua, hits: s.hits });
    },
    isDismissing(s) {
      return !!this.dismissing && this.dismissing.ua === s.ua;
    },
    // "Re-check": ask the server to run reverse-DNS live now against this client's captured
    // IP(s). The parent owns the request + refresh; the result shows inline via reverifyResult.
    doReverify(s) {
      if (this.isReverifying(s)) return;
      this.$emit('reverify', { ua: s.ua });
    },
    isReverifying(s) {
      return !!this.reverifying && this.reverifying.ua === s.ua;
    },
    // The inline outcome of the most recent re-check, for this row only. Null unless the last
    // result belongs to this client and it isn't mid-flight (so a re-run clears the old line).
    reverifyResultFor(s) {
      const r = this.reverifyResult;
      if (!r || r.ua !== s.ua || this.isReverifying(s)) return null;
      if ('checked' === r.status) {
        if (2 === r.verdict) return { tone: 'danger', text: 'Confirmed impostor — failed the identity check.' };
        if (1 === r.verdict) return { tone: 'ok', text: 'Verified — confirmed as genuine.' };
        return { tone: 'muted', text: 'Couldn’t determine — the check gave no usable answer.' };
      }
      if ('no-ip' === r.status) return { tone: 'muted', text: 'No address on record to check.' };
      return null;
    },
    // A stable identity for a pending row (survives a reorder on live refresh).
    rowKey(s) {
      return `${s.action}|${s.token || ''}|${s.ua || ''}`;
    },
    toggleDetails(s) {
      const key = this.rowKey(s);
      this.openRow = this.openRow === key ? null : key;
    },
    isOpen(s) {
      return this.openRow === this.rowKey(s);
    },
    // ---- The compact row view-model — one calm, honest summary per client ------
    card(s) {
      // Caught by CRYPTOGRAPHY: it signed its request and the maths didn't check
      // out. Strongest signal on this screen — no inference, no IP, no doubt — so
      // it gets its own card ahead of the address-based verdicts below.
      if ('spoofed' === s.verdict && s.signer) {
        return {
          tone: 'danger',
          icon: 'x',
          state: s.refused ? 'Forged signature — turned away' : 'Forged signature',
          why: `It signed its request as ${s.signer} — but the signature didn’t match ${s.signer}’s published key. Only the real operator can produce a valid one.`,
          recommend: s.refused
            ? 'It got nothing: every attempt was refused before your content was served. Listed here so you know it happened — no action needed.'
            : 'Turn on blocking (with the spoofed-crawlers rule) and this is refused automatically.',
        };
      }
      // Proven genuine by signature — the other side of the same coin.
      if ('verified' === s.verdict && s.signer) {
        return {
          tone: 'ok',
          icon: 'check',
          state: 'Cryptographically verified',
          by: s.signer,
          why: `Signed with ${s.signer}’s own key — proof of identity, not a guess.`,
        };
      }
      // Caught impersonating a verifiable bot (the one hard signal).
      if ('spoofed' === s.verdict) {
        const label = this.claimLabel(s);
        const m = this.claimMethod(s);
        const failed = 'ranges' === m
          ? `its address isn’t in ${label}’s published IP ranges`
          : ('rdns' === m ? 'it failed reverse-DNS verification when it visited' : 'its address failed verification when it visited');
        // The genuine engine can share this exact User-Agent string with its
        // impersonator — they land on ONE row. Without this sentence the card
        // brands the real crawler's traffic with the impostor's verdict (caught
        // live: real Googlebot's fresh, verified crawl read as "failed, 5h ago").
        const okN = Number(s.verifiedHits) || 0;
        const split = okN
          ? ` The real ${label} also crawls this site — ${okN} request${1 === okN ? '' : 's'} under this name verified as genuine and ${1 === okN ? 'was' : 'were'} served normally.`
          : '';
        // With blocking + the spoofed-class block both on, the Guard already refuses
        // this proven impostor at the AI endpoints — say so, and scope the remaining
        // advice honestly: an IP block at the host/CDN is what stops it SITE-WIDE.
        const enforced = this.threats.blockingOn && this.threats.blockSpoofed;
        const ipAdvice = (s.ips && s.ips.length)
          ? `block its ${s.ips.length} IP${1 === s.ips.length ? '' : 's'} (shown in Details) at your host or CDN`
          : `block its IP at your host or CDN (Details shows how to find it)`;
        return {
          tone: 'danger',
          icon: 'x',
          state: 'Failed verification',
          why: `Claims to be ${label}, but ${failed}.${split}`,
          recommend: enforced
            ? `Already refused at this site’s AI endpoints. To stop it site-wide, ${ipAdvice} — it can’t be blocked by name.`
            : `Can’t be blocked by name — ${ipAdvice}.`,
        };
      }
      // Forward-confirmed — a genuine crawler (rare here; a verified engine is trusted and
      // usually never surfaces, but honour it if it does).
      if ('verified' === s.verdict) {
        return { tone: 'ok', icon: 'check', state: 'Verified crawler', by: this.rowOperator(s), why: '' };
      }
      // Legacy-device spoof — a scanner hiding behind a dead handset.
      if (s.flags && s.flags.spoof) {
        return {
          tone: 'warn',
          icon: 'x',
          state: 'Likely scanner',
          why: 'Claims a long-dead device that no real visitor runs.',
          recommend: '',
        };
      }
      // A recognised crawler we can name but not network-verify.
      if (s.known) {
        return { tone: 'calm', icon: 'kind', kind: s.known.kind, state: this.kindLabel(s.known.kind), by: s.known.operator, why: '' };
      }
      // A crawler that only names itself.
      if (s.guide && (s.guide.url || s.guide.name)) {
        return { tone: 'calm', icon: 'dot', state: 'Self-declared', by: s.guide.host || '', unverified: true, why: '' };
      }
      return { tone: 'calm', icon: 'dot', state: 'Unrecognized', why: '' };
    },
    rowOperator(s) {
      return (s.known && s.known.operator) || '';
    },
    // Why a row has NO one-click Allow/Block — the server's `reason`, said out
    // loud where the buttons would sit, so their absence reads as a decision
    // (next to a sibling card that HAS them), not a rendering fault. '' hides it.
    noActionNote(s) {
      if (s.action || s.blocked) return '';
      if ('fake-engine' === s.reason) return 'No Allow/Block — a real search engine’s name.';
      if ('no-ua' === s.reason) return 'No Allow/Block — it sends no name.';
      if ('no-token' === s.reason) return 'No Allow/Block — its name is too generic.';
      return '';
    },
    // ---- Details panel (collapsed by default — no UA/verdict noise in the row) --
    // Which verification method applies to this row's claim: 'rdns', 'ranges', 'both',
    // or '' when it claims nothing verifiable. From the server's registry data — the
    // stored verdict doesn't record which check fired, so 'both' words generically.
    claimMethod(s) {
      const c = s.claim || null;
      if (!c) return '';
      if (c.rdns && c.ranges) return 'both';
      return c.ranges ? 'ranges' : 'rdns';
    },
    claimLabel(s) {
      return (s.claim && s.claim.label) || this.engineName(s);
    },
    verifyLine(s) {
      const m = this.claimMethod(s);
      // A signature verdict names the method that actually decided it.
      if (s.signer) {
        return 'spoofed' === s.verdict
          ? { text: `Failed — signature didn’t match ${s.signer}’s key`, tone: 'danger' }
          : { text: `Passed — cryptographic signature (${s.signer})`, tone: 'ok' };
      }
      if ('spoofed' === s.verdict) {
        const how = 'ranges' === m ? 'outside the published IP ranges' : ('rdns' === m ? 'reverse-DNS mismatch' : 'identity check failed');
        return { text: `Failed — ${how}`, tone: 'danger' };
      }
      if ('verified' === s.verdict) {
        const how = 'ranges' === m ? 'inside the published IP ranges' : ('rdns' === m ? 'forward-confirmed' : 'identity confirmed');
        return { text: `Passed — ${how}`, tone: 'ok' };
      }
      return { text: 'Not checked', tone: 'muted' };
    },
    // How to read the attributed network — kept terse so the line never wraps. The red
    // "Failed verification" hero already conveys the judgment; the note just says what the
    // network IS: the confirmed engine, the impostor's real source, or plain reverse-DNS.
    networkLine(s) {
      if ('verified' === s.verdict) return { text: 'verified', tone: 'ok' };
      if ('spoofed' === s.verdict) return { text: 'the real source', tone: 'danger' };
      return { text: 'reverse DNS', tone: 'muted' };
    },
    // The home page a self-declared crawler names in its own User-Agent, and what
    // answered there when the site last looked (a weekly background check — never
    // on this render). null when it declares none.
    //
    // Three states, and the third is deliberately empty of judgement: a request
    // that got nowhere may be THIS server's network, so it must not read as
    // evidence about the crawler.
    homeLine(s) {
      if (!s.guide || !s.guide.url) return null;
      // A proven impostor copied its whole User-Agent, URL included: that page is
      // the real operator's and will answer. Showing it here would read as a point
      // in this client's favour on a card that has already caught it out.
      if ('spoofed' === s.verdict) return null;
      const r = s.guide.reachable || null;
      const line = { url: s.guide.url, host: s.guide.host || s.guide.url };
      if (!r) return { ...line, text: 'not checked yet', tone: 'muted' };
      if ('answers' === r.state) return { ...line, text: 'answers', tone: 'ok' };
      if ('missing' === r.state) {
        return { ...line, text: r.code ? `nothing there (${r.code})` : 'no such site', tone: 'warn' };
      }
      return { ...line, text: 'couldn’t reach it — says nothing', tone: 'muted' };
    },
    detailSentence(s) {
      if ('spoofed' === s.verdict) {
        return impostorDetail({
          name: this.claimLabel(s),
          ipCount: (s.ips && s.ips.length) || 0,
          storeIps: this.storeIps,
          method: this.claimMethod(s) || 'rdns',
        });
      }
      if (s.known) {
        const v = 'verified' === s.verdict ? ' Reverse-DNS confirmed it as genuine.' : '';
        return `${s.known.name} — ${this.kindLabel(s.known.kind)} operated by ${s.known.operator}.${v} ${this.kindAdvice(s.known.kind)}`;
      }
      if (s.flags && s.flags.spoof) {
        return scannerDetail({ ipCount: (s.ips && s.ips.length) || 0 });
      }
      if (s.guide && s.guide.url) {
        const host = s.guide.host || 'a site';
        const state = (s.guide.reachable && s.guide.reachable.state) || '';
        // What the page says about itself is still only its claim — a reachable
        // page proves somebody is home, never who this client is.
        if ('answers' === state) {
          return `It points to ${host} in its own User-Agent, and that page answers — read it before you decide. Still its own claim, not proof of who it is.`;
        }
        if ('missing' === state) {
          return `It points to ${host} in its own User-Agent, and there is nothing there. A crawler worth allowing keeps a page saying who it is and how to block it. Research it below, then Allow or Block.`;
        }
        if ('unreached' === state) {
          return `It points to ${host} in its own User-Agent. This site couldn’t reach that page, which may be its own network — that says nothing either way. Open it with care, then Allow or Block.`;
        }
        return `It points to ${host} in its own User-Agent — its claim, not verified. Open it with care, then Allow or Block.`;
      }
      return 'It isn’t in the known-crawler list and declares no home page of its own. Research it below, then Allow or Block.';
    },
    // ---- Styled UA tooltip + click-to-copy (mirrors ActivityPanel) -------------
    showUaTip(ev, text) {
      if (!text) return;
      const el = ev.currentTarget;
      // Scroll-induced mouseenters wait for a real mouse move (see tipGuard).
      if (tipGuard.suppress(ev, el, () => this.showUaTip({ currentTarget: el, type: 'retry' }, text))) return;
      const cell = el.getBoundingClientRect();
      const pop = this.$refs.pop ? this.$refs.pop.getBoundingClientRect() : null;
      const gap = 16;
      const below = cell.top < 130; // near the top → drop below the cell instead of above.
      const y = below ? cell.bottom + 8 : cell.top - 8;
      if (!pop) {
        this.uaTip = { show: true, text, x: cell.left, right: null, y, caret: 16, below };
        return;
      }
      // Pin BOTH edges to the panel (equal gap each side); keep the caret over the UA cell.
      const x = pop.left + gap;
      const right = Math.max(gap, window.innerWidth - pop.right + gap);
      const caret = Math.max(14, Math.min(cell.left + cell.width / 2 - x, pop.width - gap * 2 - 14));
      this.uaTip = { show: true, text, x, right, y, caret, below };
    },
    hideUaTip() {
      this.uaTip.show = false;
    },
    async copyValue(text, label = 'User-Agent') {
      if (!text) return;
      const ok = await copyText(text);
      this.$emit('flash', ok ? 'success' : 'error', ok ? `${label} copied.` : 'Could not copy — select the text and copy manually.');
    },
    // ---- Small helpers ---------------------------------------------------------
    engineName(s) {
      const ua = (s.ua || '').toLowerCase();
      if (ua.indexOf('googlebot') !== -1) return 'Googlebot';
      if (ua.indexOf('bingbot') !== -1) return 'Bingbot';
      if (ua.indexOf('duckduckbot') !== -1) return 'DuckDuckBot';
      if (ua.indexOf('applebot') !== -1) return 'Applebot';
      if (ua.indexOf('yandex') !== -1) return 'Yandex';
      return 'a search engine';
    },
    kindAdvice(kind) {
      return {
        ai: 'Allow it to read your site for AI answers, or Block to opt out of AI training and ingest.',
        seo: 'Backlink/rank tooling — often high-volume. Block if it’s just load you don’t need.',
        search: 'A regional or vertical search engine. Usually safe to Allow.',
        social: 'Fetches a preview card when your links are shared. Usually safe to Allow.',
      }[kind] || 'Allow it if you recognise it, or Block if you don’t.';
    },
    searchName(s) {
      const generic = ['Other bot', 'Likely spoof/scanner', 'Unrecognized', 'No user-agent', 'Browser', 'Script/tool'];
      const name = (s.known && s.known.name) || (s.guide && s.guide.name) || '';
      if (name) return name;
      if (s.agent && generic.indexOf(s.agent) === -1) return s.agent;
      return (s.ua || '').slice(0, 60);
    },
    researchLinks(s) {
      const q = this.searchName(s);
      if (!q) return [];
      const quoted = `"${q}" crawler bot user-agent`;
      return [
        { label: 'Google', url: `https://www.google.com/search?q=${encodeURIComponent(quoted)}` },
        { label: 'Bing', url: `https://www.bing.com/search?q=${encodeURIComponent(quoted)}` },
        { label: 'DuckDuckGo', url: (s.guide && s.guide.lookup) || `https://duckduckgo.com/?q=${encodeURIComponent(q + ' crawler bot')}` },
      ];
    },
    // The "turn on Verify bot identities" nudge. Shown ONLY when the client claims one of
    // the reverse-DNS-verifiable engines but hasn't been checked (verification off) — the
    // one case where enabling Verify actually does something. A crawler that claims no
    // verifiable engine (e.g. Bytespider) can never be verified by that feature, so the
    // nudge would be dead-end advice and is suppressed.
    showVerifyNote(s) {
      return '' === (s.verdict || '') && !!s.verifiable;
    },
    rowTitle(s) {
      return (s.known && s.known.name) || (s.guide && s.guide.name) || s.agent;
    },
    // The "New" chip label, with a countdown ONLY when novelty is this row's sole
    // claim to the queue — such a row leaves by itself when its window lapses, and
    // predicting that beats letting the owner discover a silent disappearance. A row
    // that's also heavy / a spoof / a caught impostor stays past the window, so a
    // countdown there would promise an exit that isn't coming.
    newChip(s) {
      const sticky = 'spoofed' === s.verdict || (s.flags && (s.flags.heavy || s.flags.spoof));
      const first = new Date(s.firstSeen).getTime();
      if (sticky || !first) return 'New';
      const left = first + this.newSecs * 1000 - Date.now();
      if (left <= 0) return 'New · leaves soon';
      const m = Math.round(left / 60000);
      if (m < 60) return `New · leaves in ${Math.max(1, m)}m`;
      return `New · leaves in ${Math.round(m / 60)}h`;
    },
    kindLabel(kind) {
      return { ai: 'AI crawler', seo: 'SEO crawler', search: 'Search engine', social: 'Social preview' }[kind] || 'Crawler';
    },
    // The volume/recency line under the hero. A verdict must count and date the
    // requests that EARNED it: on a "Failed verification" card the aggregate
    // hits/lastSeen can belong mostly to the REAL engine sharing the same name,
    // so quote the failing requests there. spoofHits of 0 on a spoofed row means
    // the verdict came from an admin re-check, not logged requests — the
    // aggregate is all there is, so it falls through honestly.
    metaLine(s) {
      const failedN = Number(s.spoofHits) || 0;
      if ('spoofed' === s.verdict && failedN > 0) {
        const when = s.spoofLastSeen ? ` · last ${this.ago(s.spoofLastSeen)}` : '';
        return `${failedN} failed request${1 === failedN ? '' : 's'}${when}`;
      }
      const when = s.lastSeen ? ` · ${this.ago(s.lastSeen)}` : '';
      return `${s.hits} request${1 === s.hits ? '' : 's'}${when}`;
    },
    ago(iso) {
      return relTimeShort(new Date(iso).getTime());
    },
  },
};
</script>

<template>
  <div v-if="enabled || open" class="ar__review" :class="{ 'is-open': open, 'is-quiet': !count }">
    <button
      type="button"
      class="ar__review-btn"
      :aria-expanded="open"
      :aria-label="count ? `${count} client${1 === count ? '' : 's'} to review` : 'Review queue — nothing flagged'"
      @click.stop="toggle"
    >
      <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M8 2a3.2 3.2 0 0 0-3.2 3.2c0 3.2-1.3 4.2-1.3 4.2h9c0 0-1.3-1-1.3-4.2A3.2 3.2 0 0 0 8 2Z" />
        <path d="M6.8 12.2a1.3 1.3 0 0 0 2.4 0" />
      </svg>
      <span v-if="count" class="ar__review-count">{{ count }}</span>
    </button>

    <div v-if="open" ref="pop" class="ar__review-pop" role="dialog" aria-label="Review queue" @click.stop>
      <!-- Fixed header block — mirror of the footer: a flex sibling above the scroll
           region, so the title, auto-refresh switch and filter tabs never scroll away. -->
      <div class="ar-rev-top">
      <!-- Header: title + a plain count, with the auto-refresh switch to the right. -->
      <div class="ar-rev-head">
        <div class="ar-rev-head__titles">
          <strong class="ar-rev-head__title">Review queue</strong>
          <span class="ar-rev-head__sub">{{ count ? `${count} to review` : 'Nothing to review' }}</span>
        </div>
        <button
          type="button"
          class="ar__live"
          :class="{ 'is-on': live }"
          role="switch"
          :aria-checked="live"
          :aria-label="`Auto-refresh — check for new activity every ${liveInterval} seconds`"
          v-tip="live ? `Auto-refresh is on — checking every ${liveInterval}s. Click to stop.` : `Auto-refresh is off — click to check every ${liveInterval}s.`"
          @click="$emit('set-live', !live)"
        >
          <span class="ar__live-dot" aria-hidden="true"></span>
          <span class="ar__live-label">Auto-refresh</span>
        </button>
      </div>

      <!-- Filter tabs (only when there's enough to sort through). -->
      <div v-if="showReviewTab" class="ar-rev-tabs" role="tablist">
        <button type="button" class="ar-rev-tab" :class="{ 'is-active': activeTab === 'all' }" role="tab" :aria-selected="activeTab === 'all'" @click="tab = 'all'">
          All <span class="ar-rev-tab__n">{{ count }}</span>
        </button>
        <button type="button" class="ar-rev-tab is-attention" :class="{ 'is-active': activeTab === 'attention' }" role="tab" :aria-selected="activeTab === 'attention'" @click="tab = 'attention'">
          Flagged <span class="ar-rev-tab__n">{{ attention.length }}</span>
        </button>
      </div>
      </div>

      <!-- The list region — the only part that scrolls, between the fixed header and footer.
           The blocking-off banner scrolls with it: contextual, not chrome. -->
      <div class="ar-rev-scroll">
      <p v-if="!threats.blockingOn && pending.length" class="ar-rev-banner">
        Blocking is off — flagged clients are still served. Use <strong>Block</strong>, or turn it on in
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' }); close()">Settings</button>.
      </p>

      <ul v-if="shownCards.length" class="ar-rev-list">
        <li v-for="({ s, c }, i) in shownCards" :key="i" class="ar-rev-item" :class="'is-' + c.tone">
          <div class="ar-rev-item__main">
            <!-- Avatar: a neutral bot glyph, tinted by severity (no third-party logos). -->
            <span class="ar-rev-avatar" :class="'is-' + c.tone" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="8" width="14" height="10" rx="2.6" />
                <path d="M12 5.2V8" /><circle cx="12" cy="4.2" r="1.1" />
                <path d="M9.4 12.6v1.2M14.6 12.6v1.2" />
                <path d="M3.4 12v2.4M20.6 12v2.4" />
              </svg>
            </span>

            <div class="ar-rev-body">
              <div class="ar-rev-nameline">
                <span class="ar-rev-name">{{ rowTitle(s) }}</span>
                <span v-if="s.flags && s.flags.heavy" class="ar-rev-tag is-heavy">High volume</span>
                <span v-if="s.flags && s.flags.new" class="ar-rev-tag is-new" v-tip="s.firstSeen ? `First seen ${ago(s.firstSeen)}` : null">{{ newChip(s) }}</span>
              </div>

              <!-- The hero: verification / identity state, coloured by severity. -->
              <div class="ar-rev-state" :class="'is-' + c.tone">
                <svg v-if="c.icon === 'x'" class="ar-rev-state__ic" viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="8" cy="8" r="6.4" /><path d="M6 6l4 4M10 6l-4 4" /></svg>
                <svg v-else-if="c.icon === 'check'" class="ar-rev-state__ic" viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="6.4" /><path d="M5.4 8.2l1.8 1.8 3.4-3.6" /></svg>
                <span v-else class="ar-rev-state__dot"></span>
                <span class="ar-rev-state__label">{{ c.state }}</span>
                <span v-if="c.by" class="ar-rev-state__by">· {{ c.by }}</span>
                <span v-if="c.unverified" class="ar-rev-state__note">not verified</span>
              </div>

              <p v-if="c.why" class="ar-rev-why">{{ c.why }}</p>

              <div class="ar-rev-meta">
                {{ metaLine(s) }}
              </div>

              <div v-if="c.recommend" class="ar-rev-rec" :class="'is-' + c.tone">
                <strong>Recommended:</strong> {{ c.recommend }}
              </div>

              <!-- Actions: the real decisions as buttons; utilities as quiet links. -->
              <div class="ar-rev-actions">
                <template v-if="'agent' === s.action">
                  <button type="button" class="ar-rev-btn ar-rev-btn--allow" :disabled="isBlocking(s) || isAllowing(s)" @click="doAllow(s)">
                    {{ isAllowing(s) ? 'Allowing…' : 'Allow' }}
                  </button>
                  <button type="button" class="ar-rev-btn ar-rev-btn--block" :disabled="isBlocking(s) || isAllowing(s)" v-tip="'Blocks user-agents matching “' + s.token + '”'" @click="doBlock(s)">
                    {{ isBlocking(s) ? 'Blocking…' : 'Block' }}
                  </button>
                </template>
                <button v-else-if="'spoofed' === s.action" type="button" class="ar-rev-btn ar-rev-btn--block" :disabled="isBlocking(s)" @click="doBlock(s)">
                  {{ isBlocking(s) ? 'Blocking…' : 'Block scanners' }}
                </button>
                <span v-else-if="noActionNote(s)" class="ar-rev-noact">
                  <svg class="ar-rev-noact__ic" viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><circle cx="8" cy="8" r="6.4" /><path d="M8 7.4v3.2" /><path d="M8 5.1v.1" /></svg>
                  {{ noActionNote(s) }}
                </span>

                <button type="button" class="ar-rev-link ar-rev-link--details" :class="{ 'is-on': isOpen(s) }" :aria-expanded="isOpen(s)" @click="toggleDetails(s)">
                  {{ isOpen(s) ? 'Hide details' : 'Details' }}
                </button>
                <button type="button" class="ar-rev-link ar-rev-link--ignore" :disabled="isDismissing(s)" @click="doDismiss(s)">
                  {{ isDismissing(s) ? 'Ignoring…' : 'Ignore' }}
                </button>
              </div>
            </div>
          </div>

          <!-- Details: the honest evidence — verification result + the actual UA (copyable)
               + a plain explanation + web-search fallbacks. No fabricated IP/ASN. -->
          <div v-if="isOpen(s)" class="ar-rev-details">
            <div class="ar-rev-kv">
              <span class="ar-rev-kv__k">Verification</span>
              <span class="ar-rev-kv__v" :class="'is-' + verifyLine(s).tone">{{ verifyLine(s).text }}</span>
            </div>

            <!-- Owning network (opt-in "Identify every bot") — the reverse-DNS attribution: what
                 this client actually IS, even when it's not a verifiable engine. Org-level, no IP. -->
            <div v-if="s.network" class="ar-rev-kv">
              <span class="ar-rev-kv__k">Network</span>
              <span class="ar-rev-kv__v ar-rev-network">{{ s.network }}<span class="ar-rev-network__note" :class="'is-' + networkLine(s).tone">· {{ networkLine(s).text }}</span></span>
            </div>

            <!-- The home page it declares in its own UA, and whether that page answers.
                 A link, because the whole point is that the owner can go and look; the
                 note beside it is what the weekly background check found. -->
            <div v-if="homeLine(s)" class="ar-rev-kv">
              <span class="ar-rev-kv__k">Home page</span>
              <span class="ar-rev-kv__v ar-rev-home"><a
                :href="homeLine(s).url"
                target="_blank"
                rel="noopener noreferrer nofollow"
              >{{ homeLine(s).host }}</a><span class="ar-rev-home__note" :class="'is-' + homeLine(s).tone">· {{ homeLine(s).text }}</span></span>
            </div>

            <!-- Admin "Re-check": re-run reverse-DNS live now on this client's captured IP(s).
                 Shown only when there's an address to check (a verifiable engine flagged while
                 IP capture was on). Ad-hoc IP checks live in the global IP tool. Runs regardless
                 of the always-on "Verify bot identities" setting. -->
            <div v-if="s.verifiable && s.ips && s.ips.length" class="ar-rev-recheck">
              <div class="ar-rev-recheck__row">
                <button
                  type="button"
                  class="ar-rev-recheck__btn"
                  :disabled="isReverifying(s)"
                  @click.stop="doReverify(s)"
                >
                  <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.6 8a5.6 5.6 0 1 1-1.7-4M13.6 2.2v3.4h-3.4" /></svg>
                  {{ isReverifying(s) ? 'Re-checking…' : 'Re-check now' }}
                </button>
                <span v-if="s.reverifiedAt" class="ar-rev-recheck__ago">Re-checked {{ ago(s.reverifiedAt) }}</span>
              </div>
              <p v-if="reverifyResultFor(s)" class="ar-rev-recheck__result" :class="'is-' + reverifyResultFor(s).tone">{{ reverifyResultFor(s).text }}</p>
              <p v-else class="ar-rev-recheck__hint">Re-runs the identity check live on the captured address{{ 1 === s.ips.length ? '' : 'es' }} — no “Verify bot identities” needed.</p>
            </div>

            <div class="ar-rev-kv">
              <span class="ar-rev-kv__k">User-Agent</span>
              <code
                v-if="s.ua"
                class="ar-rev-kv__v ar-rev-ua is-copyable"
                :aria-label="s.ua"
                @mouseenter="showUaTip($event, s.ua)"
                @mouseleave="hideUaTip"
                @click.stop="copyValue(s.ua, 'User-Agent')"
              >{{ s.ua }}</code>
              <span v-else class="ar-rev-kv__v ar-rev-ua is-empty">No User-Agent</span>
            </div>

            <!-- Captured source IPs (opt-in) — real addresses to block; click to copy. -->
            <div v-if="s.ips && s.ips.length" class="ar-rev-kv">
              <span class="ar-rev-kv__k">IP address{{ 1 === s.ips.length ? '' : 'es' }}</span>
              <span class="ar-rev-kv__v ar-rev-ips">
                <button
                  v-for="ip in s.ips"
                  :key="ip.ip"
                  type="button"
                  class="ar-rev-ip"
                  v-tip="`Click to copy — seen ${ip.hits}×`"
                  @click.stop="copyValue(ip.ip, 'IP address')"
                >{{ ip.ip }}</button>
              </span>
            </div>

            <p class="ar-rev-details__why">{{ detailSentence(s) }}</p>

            <div class="ar-rev-details__research">
              <span class="ar-rev-details__reslabel">Search the web:</span>
              <a
                v-for="r in researchLinks(s)"
                :key="r.label"
                class="ar-rev-details__reslink"
                :href="r.url"
                target="_blank"
                rel="noopener noreferrer nofollow"
              >{{ r.label }}</a>
            </div>

            <p v-if="showVerifyNote(s)" class="ar-rev-details__note">
              This client wasn’t auto-verified when it visited, and no address was captured to
              re-check. Turn on <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings' }); close()">Verify bot identities</button>
              to confirm crawlers automatically, as they arrive.
            </p>
          </div>
        </li>
      </ul>
      <p v-else-if="count" class="ar-rev-empty">Nothing in this view — the clients waiting for you are under the other tab.</p>
      <p v-else class="ar-rev-empty">Nothing needs a look right now.</p>
      </div>

      <!-- Fixed footer — outside the scroll region, so it never moves. Always shown:
           it explains the queue's one silent exit (the "New" window) AND says flagged rows
           don't share it, plus offers the client manager, where past decisions live. -->
      <div class="ar-rev-foot">
        <p class="ar-rev-foot__note">
          New clients stay here for {{ newWindowLabel }} after their first visit, then leave on
          their own — their requests stay in the log. Flagged clients don't leave on a timer:
          they stay until you Allow, Block or Ignore them.
        </p>
        <button type="button" class="ar-linkbtn ar-rev-foot__manage" @click="$emit('manage'); close()">Manage clients</button>
      </div>
    </div>

    <!-- Styled UA tooltip — teleported to <body> so the scrolling dropdown never clips it. -->
    <Teleport to="body">
      <transition name="ar-tip">
        <div
          v-if="uaTip.show"
          class="ar-act-uatip"
          :class="{ 'is-below': uaTip.below }"
          :style="{ left: uaTip.x + 'px', right: uaTip.right != null ? uaTip.right + 'px' : null, top: uaTip.y + 'px', maxWidth: uaTip.right != null ? 'none' : null }"
          role="tooltip"
          aria-hidden="true"
        ><span class="ar-act-uatip__ua">{{ uaTip.text }}</span><span class="ar-act-uatip__hint">Click to copy</span><span class="ar-act-uatip__caret" :style="{ left: uaTip.caret + 'px' }"></span></div>
      </transition>
    </Teleport>
  </div>
</template>
