<script>
/**
 * In Google's Index — the Google half of the AI-search infrastructure story,
 * seated beside the Bing card. Bing's card answers "how much of the site does
 * Bing hold?"; this one answers "is this site in Google's index?" — the index
 * AI Overviews, AI Mode and Gemini read.
 *
 * Rows are for what needs eyes, with no exceptions: problems lead the card
 * and join the daily check until they heal, and a page that heals announces
 * "now on Google" instead of vanishing. Healthy pages never earn rows — not
 * even the watched ones, because which pages get asked about first is a
 * schedule, not a display; the summary sentence states that schedule in
 * words where it changes anything. The headline counts speak for the WHOLE
 * SITE, and that sentence does the arithmetic out loud, so no healthy page
 * is invisible — just quiet.
 * Presence, not traffic (traffic lives one card down in Search Performance).
 *
 * Off state = one quiet pointer at Settings → Data Sources. No form here.
 */
import { formatDate, relTimeShort } from '../js/wpDate.js';
import RefreshCrank from './RefreshCrank.vue';
import PagerBar from './PagerBar.vue';

// The problem buckets, in reading order (most-lost first) — keyed by the
// SERVER's stateKey, whose per-state totals are counted before the row cap,
// so a group's count pill tells the truth even when its rows are a slice.
// Each group quotes Google's own wording once: the footnote promises it.
const GROUP_META = [
  { key: 'unknown', label: 'Never seen by Google', sub: 'Google’s own wording: “URL is unknown to Google” — it hasn’t discovered these pages at all.' },
  { key: 'discovered', label: 'Discovered, not yet crawled', sub: 'Google’s own wording: “Discovered – currently not indexed” — known, still waiting for a first visit.' },
  { key: 'crawled', label: 'Crawled, but left out', sub: 'Google’s own wording: “Crawled – currently not indexed” — visited, then not added to the index.' },
  { key: 'canonical', label: 'Google chose a different address', sub: 'These pages are known — Google just files them under another URL; each row names which.' },
  { key: 'blocked', label: 'Blocked by this site', sub: 'robots.txt or a noindex tag asks Google to stay out — each row names which.' },
  { key: 'error', label: 'Check failed', sub: 'These checks did not get an answer from Google — each row carries its own error.' },
  { key: 'other', label: 'Not in the index', sub: '' },
];

export default {
  name: 'GoogleIndexPanel',
  components: { RefreshCrank, PagerBar },
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show, so it stays mounted across tab switches.
    active: { type: Boolean, default: false },
  },
  emits: ['navigate'],
  data() {
    return {
      view: null,
      loading: false,
      loaded: false,
      checking: false,
      // A cancel asked for while the loop runs, and the memory of one that
      // landed. The loop cannot be aborted mid-chunk — a request already with
      // Google is paid for either way — so `cancelling` means "no further
      // chunks" and the button says so.
      //
      // These are the LOCAL half of the stop, kept for the instant feedback
      // between the click and the server's answer. The durable half is the
      // server's own pause (view.pausedAt), which is what survives a reload —
      // this flag cannot, and a stop the next page load forgets is not a stop.
      cancelling: false,
      cancelled: false,
      error: '',
      // Per-state fetched rows: { [stateKey]: { rows, page, pages, total,
      // busy, loaded, error } }. Groups render from counts alone; rows exist
      // here only after their group was opened.
      groupData: {},
      openGroups: {},
      lookupQuery: '',
      lookupOut: null,
      // The query the shown answer belongs to. An answer must never outlive
      // the question that produced it — editing or clearing the box drops it.
      lookupFor: '',
      lookupBusy: false,
      // The live single-URL check, which spends one of the day's inspections —
      // separate from lookupBusy so the stored answer stays on screen while the
      // fresh one is being fetched.
      // The URL currently being asked about, '' when idle. Per-URL rather than a
      // single boolean because the button now sits on every row: one spinner on
      // the row that was clicked, not on all of them.
      checkingUrl: '',
      checkError: '',
    };
  },
  computed: {
    connected() {
      return !!(this.view && this.view.connected);
    },
    rows() {
      return (this.view && this.view.rows) || [];
    },
    counts() {
      return (this.view && this.view.counts) || { checked: 0, onGoogle: 0, notOnGoogle: 0, errors: 0, canonicalDiffers: 0 };
    },
    watched() {
      return (this.view && this.view.watched) || { busiest: 0, newest: 0, dailyCap: 0 };
    },
    hasRows() {
      return this.rows.length > 0;
    },
    // The owner stopped the run — locally the instant they clicked, and from
    // the server's own pause on every visit after that. A queue that is not
    // moving has to say why, or "34 pages still to check" reads as a promise
    // that something is working through them.
    stopped() {
      return !!(this.cancelled || (this.view && this.view.pausedAt));
    },
    site() {
      return (this.view && this.view.site) || { totalUrls: 0, checked: 0, onGoogle: 0, notOnGoogle: 0, cycleDays: 0, healed: [], healedTotal: 0, problems: [], problemsTotal: 0 };
    },
    // The headline numbers speak for the WHOLE SITE — on a 200-page site with
    // 100 pages out of the index, a green 21/21 watch sample as the first
    // thing read would be a lie by framing. problemStates carries the site's
    // pre-cap bucket totals, watched problems included.
    rail() {
      const s = this.site;
      const t = s.problemStates || {};
      const checked = s.checked || this.counts.checked;
      const on = s.checked ? s.onGoogle : this.counts.onGoogle;
      return {
        checked,
        on,
        not: Math.max(0, checked - on),
        diff: Number(t.canonical) || 0,
        failed: Number(t.error) || 0,
      };
    },
    // What "Needs a look" can honestly promise about re-checking. With more
    // problems than the daily allowance, "these are re-checked daily" would
    // be an overclaim; on a site the rotation covers in a day, naming the
    // allowance at all is noise.
    problemsCadence() {
      const w = this.watched;
      if (this.site.cycleDays <= 1) return 'Every one of these is re-checked daily, until it heals.';
      if (this.site.problemsTotal > w.promotedDaily) {
        return `Up to ${w.promotedDaily} of these re-join the daily check each day — the ones asked about longest ago first — until they heal.`;
      }
      return 'Every one of these re-joins the daily check until it heals.';
    },
    // The whole-site summary does the arithmetic FOR the reader: quiet healthy
    // pages + the rows on screen must add back up to every page checked. An
    // unstated remainder reads as a gap, not as health.
    siteLine() {
      const s = this.site;
      if (!s.totalUrls || !s.checked) return '';
      const all = s.checked >= s.totalUrls;
      const head = all
        ? `All ${s.totalUrls} pages of this site have been checked — ${s.onGoogle} are in Google's index.`
        : `${s.checked} of this site's ${s.totalUrls} pages checked so far — ${s.onGoogle} in Google's index.`;
      const quiet = Math.max(0, s.checked - (s.problemsTotal || 0));
      const tally = s.problemsTotal > 0
        ? `The ${quiet} healthy pages stay quiet — rows are for the ${s.problemsTotal} that need${s.problemsTotal === 1 ? 's' : ''} a look.`
        : 'Every checked page is healthy — nothing needs a row.';
      // The cadence sentence is where the checking ORDER lives now that no
      // section renders it: on a site the rotation covers in a day there is
      // no order worth naming, and on a bigger one it is the whole point —
      // your homepage does not wait a week for its turn.
      const w = this.watched;
      let cadence;
      if (s.cycleDays <= 1) {
        cadence = 'Every page is re-checked daily.';
      } else {
        const daily = [`your homepage, up to ${w.busiest} busiest pages and up to ${w.newest} newest posts`];
        if (s.problemsTotal > w.promotedDaily) {
          daily.push(`the ${w.promotedDaily} problem pages waiting longest`);
        } else if (s.problemsTotal) {
          daily.push('every page needing a look, until it heals');
        }
        cadence = `Checked every day: ${daily.join(', plus ')}. Every other page comes around about every ${s.cycleDays} days.`;
      }
      return `${head} ${tally} ${cadence}`;
    },
    sitemaps() {
      return (this.view && this.view.sitemaps) || { checkedAt: 0, liveUrl: '', registered: [] };
    },
    // The registration that matches what the site serves TODAY (slash-blind).
    liveRegistration() {
      const norm = (u) => String(u || '').replace(/\/+$/, '');
      const live = norm(this.sitemaps.liveUrl);
      if (!live) return null;
      return this.sitemaps.registered.find((r) => norm(r.path) === live) || null;
    },
    gscSitemapsLink() {
      return this.view && this.view.property
        ? `https://search.google.com/search-console/sitemaps?resource_id=${encodeURIComponent(this.view.property)}`
        : '';
    },
    // Registration health in one sentence — the failure only Google's own
    // bookkeeping can see: a perfect on-site file whose REGISTRATION points
    // at an address that moved. null = never looked (silence, not a verdict).
    sitemapNote() {
      const s = this.sitemaps;
      if (!s.checkedAt || !s.liveUrl) return null;
      if (!s.registered.length) {
        return { warn: true, text: `No sitemap is registered in Search Console — submit ${s.liveUrl} once, and Google finds new posts on its own.` };
      }
      const live = this.liveRegistration;
      if (!live) {
        return { warn: true, text: `The sitemap${s.registered.length > 1 ? 's' : ''} registered in Search Console point${s.registered.length > 1 ? '' : 's'} at ${s.registered.length > 1 ? 'addresses' : 'an address'} this site no longer serves — submit ${s.liveUrl} once.` };
      }
      if (!live.lastRead) {
        return { warn: false, text: 'Sitemap submitted — Google hasn\'t read it yet. The first read usually lands within a day.' };
      }
      if (live.errors > 0) {
        return { warn: true, text: `Google reads your sitemap but reports ${live.errors} error${live.errors > 1 ? 's' : ''} in it.` };
      }
      return { warn: false, text: `Google's registered sitemap is the one this site serves — last read ${this.day(live.lastRead)}${live.submitted ? `, ${live.submitted} URLs` : ''}.` };
    },
    // One collapsible per SERVER stateKey with problems in it — built from
    // the pre-cap totals alone, so every group that exists appears with its
    // true count whether or not any of its rows ever shipped. Rows are the
    // on-demand half ({@see fetchGroup}).
    problemGroups() {
      const totals = this.site.problemStates || {};
      return GROUP_META.filter((g) => (Number(totals[g.key]) || 0) > 0).map((g) => ({
        key: g.key,
        label: g.label,
        sub: g.sub,
        count: Number(totals[g.key]) || 0,
      }));
    },
    // When the 50-row cap binds, the full list lives in Search Console's own
    // Pages report — the archive to our lens.
    gscPagesLink() {
      return this.view && this.view.property
        ? `https://search.google.com/search-console/index?resource_id=${encodeURIComponent(this.view.property)}`
        : '';
    },
    // A clause true of EVERY problem row is a site fact, not a row fact —
    // said once above the groups, dropped from each row below.
    hoistedClauses() {
      const rows = this.site.problems;
      if (rows.length < 2) return [];
      const out = [];
      if (rows.every((r) => r.verdict && r.verdict !== 'pass' && !r.inSitemap)) out.push('no sitemap Google knows lists any of them');
      if (rows.every((r) => r.verdict && r.verdict !== 'pass' && !r.referrers)) out.push('no page Google knows links to any of them');
      return out;
    },
    // The hoisted sentence, lag named: right after a sitemap submission the
    // healthy registration line below and a "no sitemap" clause here are BOTH
    // true — without naming the lag, that reads as broken data.
    hoistLine() {
      const c = this.hoistedClauses;
      if (!c.length) return '';
      const parts = [];
      if (c.some((x) => x.includes('sitemap'))) {
        parts.push('Google’s page-by-page records don’t yet connect them to a sitemap — those records lag, and update as each page is re-checked');
      }
      if (c.some((x) => x.includes('links'))) {
        parts.push('no page Google knows links to any of them');
      }
      return `All of the pages below share this: ${parts.join('; and ')}.`;
    },
  },
  watch: {
    active(on) {
      if (on && !this.loading) this.load();
    },
    // A stale answer beside a changed question is a label claiming more than
    // its data — the moment the box no longer holds the query that produced
    // the answer, the answer goes.
    lookupQuery(now) {
      if (this.lookupOut && now.trim() !== this.lookupFor) this.lookupOut = null;
    },
  },
  mounted() {
    if (this.active) this.load();
    // Coming back from another browser tab — or a laptop woken from sleep —
    // is a reveal: the sweep kept going server-side (the continuation cron),
    // and the card must show where it got to, and finish any remainder,
    // without a manual reload. Same listener BingPanel and SettingsForm use.
    document.addEventListener('visibilitychange', this.onTabReturn);
  },
  beforeUnmount() {
    document.removeEventListener('visibilitychange', this.onTabReturn);
  },
  methods: {
    // Not while `checking` — a live chunk loop IS the fresh state. But a wake
    // from sleep delivers this event while the sleep-killed chunk request
    // still counts as in flight: give its rejection a beat to land, then look
    // again. If the loop truly lives on, it keeps the card fresh itself.
    onTabReturn() {
      if (document.hidden || !this.active || this.loading) return;
      if (this.checking) {
        setTimeout(() => {
          if (!this.checking && !this.loading) this.load();
        }, 1500);
        return;
      }
      this.load();
    },
    async load() {
      if (!this.api || this.loading) return;
      this.loading = true;
      this.error = '';
      try {
        this.view = await this.api.getGoogleIndex();
        // Fetched group rows may predate this fresh view — re-read the open
        // ones, forget the rest.
        this.refreshGroups();
        // A run left mid-flight (a refresh killed the loop, a guard ran out)
        // resumes by itself: this is the owner's own browser — the same
        // hands that pressed Check Now — and wp-cron on a cached site can
        // take hours over what this loop finishes in a minute. Unless the
        // owner stopped it on purpose: then the queue waits for another press
        // or for the daily check, which is what the rail says it will do.
        //
        // ⛔ `pausedAt` and not `cancelled` alone: the local flag dies with the
        // page, so a refresh used to start the run up again seconds after the
        // owner stopped it. The stop is the server's now, and it outlives the
        // tab that asked for it.
        if (this.view && this.view.pending > 0 && !this.checking && !this.cancelled && !this.view.pausedAt) this.checkNow();
      } catch (e) {
        this.error = e && e.message ? e.message : 'Could not load the Google index status.';
      } finally {
        // In finally, not the try: a failed FIRST load must still leave the
        // skeleton for the error/off states, not hang on it forever.
        this.loaded = true;
        this.loading = false;
      }
    },
    // The sweep is chunked server-side (a full watchlist in one request held
    // a worker long enough to 502) — so "Check Now" is a LOOP of short
    // requests, each returning the rows it managed plus how many remain.
    // Answers fill in live between chunks; a hard cap bounds the loop even
    // if `pending` never falls (a chunk that met a transport blip re-queues
    // its URL and pauses in silence, so progress can stall with no error).
    async checkNow() {
      if (!this.api || this.checking) return;
      this.checking = true;
      // A deliberate press clears an earlier cancel — the hands that stopped
      // it are the hands starting it again.
      this.cancelling = false;
      this.cancelled  = false;
      this.error = '';
      try {
        // 120 ≥ one full run (watchlist + the whole daily rotation) even at
        // the slow-origin worst case of one inspection per chunk — the cap
        // exists for runaways, not as a working limit that strands a queue.
        let guard = 120;
        do {
          this.view = await this.api.refreshGoogleIndex();
        } while (this.view && this.view.pending > 0 && !this.view.lastError && !this.view.quotaHit && !this.cancelling && --guard > 0);
        // The run rewrote the stored answers the group listings read from.
        this.refreshGroups();
      } catch (e) {
        // Surfaced inside the card ({{ error }} under the rail) — a loop
        // that dies must say so where the owner is looking.
        this.error = e && e.message ? e.message : 'Could not reach Google.';
      } finally {
        this.checking = false;
        // Only a cancel that actually ended a loop counts. One asked for as
        // the last chunk landed leaves nothing pending, and a veto over an
        // empty queue would only sit there confusing the next visit.
        this.cancelled  = this.cancelling && !!this.view && this.view.pending > 0;
        this.cancelling = false;
      }
    },
    // Stop after the chunk in flight. That chunk's inspections are already
    // spent with Google, so there is nothing to save by killing it mid-answer
    // — and letting it land keeps its verdicts instead of throwing them away.
    // The queue is server-side: cancelling abandons nothing, it puts the rest
    // back to the daily check or the next press.
    //
    // ⛔ Which is why the server has to hear about it. Ending only this loop
    // stopped nothing: the continuation event finished the run within minutes,
    // and a page refresh set the loop going again. A failed call leaves the
    // loop stopped anyway — the local flag still holds for this screen — so it
    // is silent here rather than an error over a button that visibly worked.
    async cancelCheck() {
      if (!this.checking) return;
      this.cancelling = true;
      try {
        await this.api.cancelGoogleIndex();
      } catch (e) { /* the loop stops regardless; the daily check still finishes the queue */ }
    },
    // The verdict in plain words. "On Google" is Google's own phrasing in the
    // inspection tool — reusing it keeps this card and their console in one
    // vocabulary.
    verdictLabel(r) {
      if (r.error) return 'Check failed';
      if (r.verdict === 'pass') return 'On Google';
      if (r.verdict === '') return 'Not checked yet';
      return 'Not on Google';
    },
    verdictClass(r) {
      if (r.error) return 'is-err';
      if (r.verdict === 'pass') return 'is-ok';
      if (r.verdict === '') return 'is-dim';
      return 'is-warn';
    },
    // The healed row's second line: what it healed FROM (the group label the
    // problem stood under), and since when it's been in.
    healedNote(r) {
      const g = GROUP_META.find((x) => x.key === r.healedFrom);
      const was = g ? `Was “${g.label.toLowerCase()}” — ` : '';
      return `${was}in Google's index since ${this.day(r.healedAt)}.`;
    },
    // Remember that this row was opened in Search Console, and say so on the row
    // from now on.
    //
    // Google keeps no memory of "indexing requested" — a fresh inspection shows
    // a plain REQUEST INDEXING again and no API exposes a pending state — so the
    // only thing anyone can honestly record is the owner's own click. The note
    // therefore says "console opened {date}", which is true, and never "indexing
    // requested", which nobody here can know.
    //
    // Fire-and-forget: the link opens in its own tab regardless, and failing to
    // write a note must never interrupt going to Search Console.
    noteOpened(r) {
      if (!r || !r.url) return;
      r.openedAt = Math.floor(Date.now() / 1000); // Optimistic — the row is ours to paint.
      if (this.api && this.api.markGoogleIndexOpened) {
        this.api.markGoogleIndexOpened(r.url).catch(() => {});
      }
    },
    // Ratio-bar segment width, of the site pages actually checked.
    pct(n) {
      return this.rail.checked ? `${(Number(n || 0) / this.rail.checked) * 100}%` : '0%';
    },
    // Per-row second line: only when there is something worth saying. Google's
    // own coverage sentence carries the "why not"; our clauses name the silent
    // killers — canonical swaps, robots/noindex blocks, fetch failures, a page
    // no sitemap ever mentioned, rich-result issues.
    rowNote(r, grouped = false) {
      if (r.error) return r.error;
      const bits = [];
      // Inside a problem GROUP the state is the heading — repeating it on
      // every row is what made the flat list read as noise.
      if (!grouped && r.verdict && r.verdict !== 'pass' && r.state) bits.push(r.state);
      if (r.canonicalDiffers) bits.push(`Google treats ${r.googleCanonical} as the real address of this page`);
      if (r.robotsBlocked) bits.push('robots.txt blocks Google here');
      if (r.noindex) bits.push('a noindex tag or header asks Google to skip this page');
      const fetch = {
        SOFT_404: 'the page answered, but looked like a “not found” page (soft 404)',
        NOT_FOUND: 'the page answered “not found” when Google fetched it',
        SERVER_ERROR: 'the server failed to answer Google’s fetch',
        ACCESS_DENIED: 'the page refused Google’s fetch',
      }[r.fetchState];
      if (fetch) bits.push(fetch);
      // Clauses hoisted to the one site-level line stay off grouped rows.
      const hoistSitemap = grouped && this.hoistedClauses.some((c) => c.includes('sitemap'));
      const hoistLinks = grouped && this.hoistedClauses.some((c) => c.includes('links'));
      if (!hoistSitemap && r.verdict && r.verdict !== 'pass' && !r.inSitemap) bits.push('no sitemap Google knows lists this address');
      if (!hoistLinks && r.verdict && r.verdict !== 'pass' && !r.referrers) bits.push('no page Google knows links to this one');
      if (r.richIssues > 0) bits.push(`${r.richIssues} rich-result issue${r.richIssues > 1 ? 's' : ''}${r.richTypes ? ` (${r.richTypes})` : ''}`);
      return bits.join(' · ');
    },
    // A group's fetched rows and paging position, shape-guaranteed for the
    // template even before the first fetch.
    groupState(key) {
      return this.groupData[key] || { rows: [], page: 1, pages: 0, total: 0, busy: false, loaded: false, error: '' };
    },
    onGroupToggle(key, e) {
      const open = !!(e && e.target && e.target.open);
      this.openGroups = { ...this.openGroups, [key]: open };
      const g = this.groupState(key);
      if (open && !g.loaded && !g.busy) this.fetchGroup(key, 1);
    },
    // One page of one state, from the STORED answers — a small REST call, no
    // Google request, no quota. Replaces the rows (pagination, not append).
    async fetchGroup(key, page) {
      const prev = this.groupState(key);
      this.groupData = { ...this.groupData, [key]: { ...prev, busy: true, error: '' } };
      try {
        const out = await this.api.googleIndexProblems(key, page);
        if (!out.rows.length && out.page > 1 && out.pages > 0) {
          // The data shifted under a stale page number (a run finished
          // between clicks) — land on the first page instead of a blank.
          return this.fetchGroup(key, 1);
        }
        this.groupData = {
          ...this.groupData,
          [key]: { rows: out.rows, page: out.page, pages: out.pages, total: out.total, busy: false, loaded: true, error: '' },
        };
      } catch (e) {
        this.groupData = {
          ...this.groupData,
          [key]: { ...prev, busy: false, error: e && e.message ? e.message : 'Could not load these pages.' },
        };
      }
    },
    gotoPage(key, n) {
      const g = this.groupState(key);
      if (g.busy || n < 1 || (g.pages && n > g.pages) || n === g.page) return;
      this.fetchGroup(key, n);
    },
    // Fresh answers may have landed (a run finished): open groups re-read
    // their current page quietly; closed ones just forget, and refetch when
    // next opened.
    refreshGroups() {
      for (const key of Object.keys(this.groupData)) {
        if (this.openGroups[key]) {
          this.fetchGroup(key, this.groupState(key).page);
        } else {
          const next = { ...this.groupData };
          delete next[key];
          this.groupData = next;
        }
      }
    },
    rangeText(key) {
      const g = this.groupState(key);
      if (!g.total) return '';
      const start = (g.page - 1) * 50 + 1;
      const end = Math.min(g.total, start - 1 + g.rows.length);
      return `${start.toLocaleString()}–${end.toLocaleString()} of ${g.total.toLocaleString()}`;
    },
    // One page's STORED answer — the coverage map remembers every checked
    // page, so this needs no live call and spends no quota.
    async runLookup() {
      const q = this.lookupQuery.trim();
      if (!this.api || !q || this.lookupBusy) return;
      this.lookupBusy = true;
      this.lookupOut = null;
      this.lookupFor = q;
      try {
        this.lookupOut = await this.api.lookupGoogleIndex(q);
      } catch (e) {
        this.lookupOut = { status: 'error', row: null, message: e && e.message ? e.message : 'Lookup failed.' };
      } finally {
        this.lookupBusy = false;
      }
    },
    // ONE live inspection, on an explicit click. The lookup above reads storage
    // and costs nothing; this asks Google and spends one of the day's 2,000, so
    // it is never automatic and never part of loading the card.
    //
    // The answer replaces the stored one server-side, and the whole view comes
    // back with it: one fresh verdict can empty a problem group and moves the
    // site counts, so repainting only the row would leave the totals above it
    // describing a site that no longer exists.
    //
    // NOT checkNow(): that name was already taken by the whole-watchlist sweep
    // above. Two methods of one name in a single object is not an error in JS —
    // the later one silently wins — so this replaced the sweep, and because the
    // sweep's button binds @click="checkNow" with no parentheses, Vue handed it
    // the click EVENT as the url. The result was a card whose "Check Now" no
    // longer swept and answered "Invalid parameter(s): url" instead.
    async recheckUrl(url) {
      if (!this.api || !url || this.checkingUrl) return;
      this.checkingUrl = url;
      this.checkError = '';
      try {
        const r = await this.api.checkGoogleIndexUrl(url);
        if (r && r.view) this.view = r.view;
        // The lookup answer, when it happens to be about the same page.
        if (r && r.row && this.lookupOut && this.lookupOut.row && this.lookupOut.row.url === url) {
          this.lookupOut = { status: 'found', row: r.row, cycleDays: this.site.cycleDays };
        }
        // Problem groups are fetched separately from the view, so a page that
        // just healed would otherwise sit in its old bucket until something
        // else reloaded it — the group would contradict the counts above it.
        this.refreshGroups();
      } catch (e) {
        // The server's own sentence when it sent one; the bare status is a last
        // resort, because "Request failed (400)" tells nobody which of the four
        // refusals happened.
        this.checkError = (e && e.message) || 'The check didn’t come back — try again.';
      } finally {
        this.checkingUrl = '';
      }
    },
    // The row-level door: short, because it shares a 158px lane with the
    // Search Console link, and "Re-check" is the truth on a row that already
    // carries an answer.
    checkLabel(url) {
      return this.checkingUrl === url ? 'Asking…' : 'Re-check';
    },
    day(ts) {
      const t = Number(ts || 0) * 1000;
      return t ? formatDate(new Date(t), true) : '';
    },
    agoMin(ts) {
      return relTimeShort(Number(ts || 0) * 1000);
    },
    goSettings() {
      this.$emit('navigate', { tab: 'settings', anchor: 'ar-sec-google' });
    },
  },
};
</script>

<template>
  <div class="ar-gidx">
    <!-- First fetch in flight: the card's own shape in skeleton — a blank
         stretch under the caption read as a broken screen, not a loading one. -->
    <section v-if="!loaded" class="ar-card" role="status" aria-label="Loading Google index data">
      <div class="ar-skel-head" aria-hidden="true">
        <span class="ar-skel ar-skel--title"></span>
        <span class="ar-skel ar-skel--line" style="width: 58%"></span>
      </div>
      <div class="ar-skel-tiles" aria-hidden="true">
        <span class="ar-skel ar-skel--tile"></span>
        <span class="ar-skel ar-skel--tile"></span>
        <span class="ar-skel ar-skel--tile"></span>
      </div>
      <div class="ar-skel-lines" aria-hidden="true">
        <span class="ar-skel ar-skel--line" style="width: 72%"></span>
        <span class="ar-skel ar-skel--line" style="width: 44%"></span>
      </div>
    </section>
    <!-- Off: one quiet pointer, no form, no nagging. -->
    <section v-else-if="!connected" class="ar-card ar-card--muted">
      <h2 class="ar-card__title">In Google's Index <span class="ar-card__tag">Off</span></h2>
      <p class="ar-card__lead ar-gidx__offlead">
        Google's index is what AI Overviews, AI Mode and Gemini read. Connect Google
        Search Console and this card checks every day that your key pages — homepage,
        busiest pages, newest posts — are actually in it.
        <button type="button" class="ar-linkbtn" @click="goSettings">
          Connect Google in Settings → Data Sources
        </button>
      </p>
    </section>

    <section v-else-if="connected" class="ar-card">
      <div class="ar-card__titlewrap">
        <h2 class="ar-card__title">In Google's Index <span class="ar-card__tag">Google · checked daily</span></h2>
        <RefreshCrank
          :busy="loading"
          :aria-label="loading ? 'Re-reading Google index data…' : 'Re-read Google index data'"
          :title="loading ? 'Re-reading…' : 'Re-read Google index data'"
          @refresh="load"
        />
      </div>
      <p class="ar-card__lead">
        Whether Google’s index holds this site’s pages. Not how they rank or earn — that
        is Search Performance below — only whether they are in. Everything finds pages
        through this one index: the classic Google Search people use, and the AI surfaces
        (AI Overviews, AI Mode, Gemini). So fixing a page here helps both audiences at once.
      </p>

      <div class="ar-mcp-rail" data-state="running">
        <span class="ar-mcp-rail__dot" aria-hidden="true"></span>
        <strong>Connected</strong>
        <template v-if="view.checkedAt">
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
          <span>checked {{ agoMin(view.checkedAt) }}</span>
        </template>
        <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
        <button type="button" class="ar-linkbtn ar-linkbtn--act" :disabled="checking" @click="checkNow">
          {{ checking ? (view.pending > 0 ? `Checking… ${view.pending} to go` : 'Checking…') : 'Check Now' }}
        </button>
        <!-- A minute-long loop the owner started by hand needs a way out of
             it. Its own control rather than a toggled label, so the progress
             count stays readable while the way out sits beside it. -->
        <template v-if="checking">
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
          <button type="button" class="ar-linkbtn ar-linkbtn--act" :disabled="cancelling" @click="cancelCheck">
            {{ cancelling ? 'Finishing this page…' : 'Cancel' }}
          </button>
        </template>
        <template v-if="!checking && view.pending > 0">
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
          <!-- Honest about the machinery: the daily check WILL finish these,
               but on a heavily cached site wp-cron can take hours over what
               Check Now does in a minute — never promise "fills in as it
               arrives" for a trickle. A run the owner stopped names itself:
               the same sentence without it would read as work in progress. -->
          <span v-if="stopped">Stopped — {{ view.pending }} pages still to check. Press Check Now to finish, or the daily check picks them up.</span>
          <span v-else>{{ view.pending }} pages still to check — press Check Now to finish, or the daily check picks them up</span>
        </template>
        <template v-if="view.lastError">
          <span class="ar-mcp-rail__sep ar-gidx__sep-warn" aria-hidden="true">·</span>
          <span class="ar-warn">Last check failed: {{ view.lastError }} — showing the last good answers.</span>
        </template>
      </div>

      <!-- A checkNow loop that dies must say so HERE, where the owner is
           looking — the closing error slot below only renders disconnected. -->
      <p v-if="error" class="ar-log__error" role="alert">{{ error }} — try Refresh, and if it persists, reload the page.</p>

      <!-- Quota is a state, not a fault — named in its own words so a partial
           list never reads as a partial site. -->
      <p v-if="view.quotaHit" class="ar-srcline ar-gidx__quota">
        Google's inspection budget for today ran out mid-check — pages checked before
        that keep fresh answers, the rest show their last good ones. The daily check
        resumes tomorrow.
      </p>

      <div v-if="!hasRows" class="ar-wd-empty">
        No answers yet. The first check runs within moments of connecting — or press
        Check Now.
      </div>
      <template v-else>
        <!-- The story before the list — the Bing card's own tile grammar, so
             the two index cards read as siblings. Color only where it means
             something: a zero stays quiet, a nonzero problem count warms up.
             These numbers are the WHOLE SITE's: a green watch sample leading
             a card whose site is half out of the index would be a lie by
             framing. -->
        <div class="ar-wd-stats ar-act-stats ar-act-stats--4">
          <div class="ar-wd-stat"><strong>{{ rail.on }} / {{ rail.checked }}</strong><span>In Google's index</span></div>
          <div class="ar-wd-stat"><strong :class="{ 'ar-gidx__hot': rail.not }">{{ rail.not }}</strong><span>Not in the index</span></div>
          <div class="ar-wd-stat"><strong :class="{ 'ar-gidx__hot': rail.diff }">{{ rail.diff }}</strong><span>Different address chosen</span></div>
          <div class="ar-wd-stat"><strong :class="{ 'ar-gidx__hot': rail.failed }">{{ rail.failed }}</strong><span>Checks failed</span></div>
          <div
            class="ar-gidx__ratio"
            role="img"
            :aria-label="`${rail.on} of ${rail.checked} checked pages are in Google's index`"
          >
            <span class="ar-gidx__ratio-seg is-ok" :style="{ width: pct(rail.on) }"></span>
            <span class="ar-gidx__ratio-seg is-warn" :style="{ width: pct(Math.max(0, rail.not - rail.failed)) }"></span>
            <span class="ar-gidx__ratio-seg is-err" :style="{ width: pct(rail.failed) }"></span>
          </div>
        </div>
        <p v-if="siteLine" class="ar-gidx__siteline">{{ siteLine }}</p>
        <!-- THE ROW GRAMMAR, card-wide: one line, four fixed lanes —
             title | date | console door | chip. Empty lanes still render, so
             all four columns run straight from the first problem row to the
             last folded watch row; the clause line sits under the title,
             capped to the title lane. The door is on EVERY checked row (the
             deep link is the honest "Request indexing" — that button has no
             API). -->

        <!-- Rows are for what needs eyes — so problems come FIRST, and a page
             that healed announces itself instead of vanishing: the missing
             problem row IS the news the owner was waiting for. -->
        <template v-if="site.healed && site.healed.length">
          <div class="ar-gidx__sec ar-gidx__siteeyebrow">
            <span class="ar-perf__eyebrow ar-gidx__secname">Now on Google</span>
            <span class="ar-gidx__seccount">{{ site.healedTotal }}</span>
          </div>
          <p class="ar-gidx__gsub">
            These were problems — Google's index holds them now. Announced here
            for about two days, then they go quiet like every healthy page.
          </p>
          <div class="ar-gidx__rowsbox">
            <ul class="ar-gidx__list ar-gidx__list--grouped ar-gidx__scrollbox">
              <li v-for="r in site.healed" :key="r.url" class="ar-gidx__row">
                <div class="ar-gidx__main">
                  <span class="ar-gidx__page">
                    <a :href="r.url" target="_blank" rel="noopener" class="ar-gidx__title">{{ r.title }}</a>
                  </span>
                  <span class="ar-gidx__crawl">{{ r.lastCrawl ? `visited ${day(r.lastCrawl)}` : 'never visited' }}</span>
                  <span class="ar-gidx__door">
                    <button type="button" class="ar-linkbtn ar-linkbtn--act ar-gidx__recheck" :disabled="!!checkingUrl" @click="recheckUrl(r.url)">{{ checkLabel(r.url) }}</button>
                    <span v-if="r.gscLink" class="ar-gidx__doorsep" aria-hidden="true">·</span>
                    <a v-if="r.gscLink" class="ar-gidx__gsc" :href="r.gscLink" target="_blank" rel="noopener" @click="noteOpened(r)">Open in Search Console</a>
                    <span v-if="r.openedAt" class="ar-gidx__opened">console opened {{ day(r.openedAt) }}</span>
                  </span>
                  <span class="ar-gidx__chip is-ok">On Google</span>
                  <p class="ar-gidx__note">{{ healedNote(r) }}</p>
                </div>
              </li>
            </ul>
          </div>
        </template>

        <!-- Re-check is on every row, so its failure has to be visible from every
             row. It used to render only inside the lookup box, which meant a
             row-triggered error appeared nowhere at all and the only evidence
             was a 400 in the browser console. -->
        <p v-if="checkError" class="ar-gidx__note is-err ar-gidx__checkerr">{{ checkError }}</p>

        <template v-if="site.problemsTotal > 0">
          <div class="ar-gidx__sec ar-gidx__siteeyebrow">
            <span class="ar-perf__eyebrow ar-gidx__secname">Needs a look</span>
            <span class="ar-gidx__seccount">{{ site.problemsTotal }}</span>
          </div>
          <p class="ar-gidx__gsub">{{ problemsCadence }}</p>
          <!-- A clause true of EVERY problem row is a site fact — said once
               here, lag named, not repeated down the list. -->
          <p v-if="hoistLine" class="ar-gidx__note is-warn ar-gidx__hoist">{{ hoistLine }}</p>
          <!-- One collapsible per state (the Search screen's own fold), every
               heading carrying its TRUE count — groups always appear, rows
               only on demand: opening fetches one stored-data page of 50,
               the pagination turns pages, and the fixed-height list scrolls
               so no group can swallow the card. An AI-era site writes faster
               than Google visits; a group holding 500 pages must cost the
               reader nothing until asked. -->
          <details
            v-for="g in problemGroups"
            :key="g.key"
            class="ar-fold ar-opp__noiselist ar-gidx__grp"
            @toggle="onGroupToggle(g.key, $event)"
          >
            <summary>{{ g.label }} ({{ g.count.toLocaleString() }} {{ g.count === 1 ? 'page' : 'pages' }})</summary>
            <p v-if="g.sub" class="ar-gidx__gsub">{{ g.sub }}</p>
            <p v-if="groupState(g.key).error" class="ar-gidx__note is-err">{{ groupState(g.key).error }}</p>
            <p v-else-if="groupState(g.key).busy && !groupState(g.key).rows.length" class="ar-gidx__scope">Loading…</p>
            <ul
              v-if="groupState(g.key).rows.length"
              class="ar-gidx__list ar-gidx__list--grouped ar-gidx__scrollbox"
              :class="{ 'is-busy': groupState(g.key).busy }"
            >
              <li v-for="r in groupState(g.key).rows" :key="r.url" class="ar-gidx__row">
                <div class="ar-gidx__main">
                  <span class="ar-gidx__page">
                    <a :href="r.url" target="_blank" rel="noopener" class="ar-gidx__title">{{ r.title }}</a>
                  </span>
                  <span class="ar-gidx__crawl">{{ r.lastCrawl ? `visited ${day(r.lastCrawl)}` : (r.error ? '' : 'never visited') }}</span>
                  <span class="ar-gidx__door">
                    <button type="button" class="ar-linkbtn ar-linkbtn--act ar-gidx__recheck" :disabled="!!checkingUrl" @click="recheckUrl(r.url)">{{ checkLabel(r.url) }}</button>
                    <span v-if="r.gscLink" class="ar-gidx__doorsep" aria-hidden="true">·</span>
                    <a v-if="r.gscLink" class="ar-gidx__gsc" :href="r.gscLink" target="_blank" rel="noopener" @click="noteOpened(r)">Open in Search Console</a>
                    <span v-if="r.openedAt" class="ar-gidx__opened">console opened {{ day(r.openedAt) }}</span>
                  </span>
                  <span class="ar-gidx__chip" :class="verdictClass(r)">{{ verdictLabel(r) }}</span>
                  <p v-if="rowNote(r, true)" class="ar-gidx__note" :class="r.error ? 'is-err' : 'is-warn'">{{ rowNote(r, true) }}</p>
                </div>
              </li>
            </ul>
            <div v-if="groupState(g.key).pages > 1" class="ar-gidx__pagefoot">
              <span class="ar-gidx__range">{{ rangeText(g.key) }}</span>
              <PagerBar
                :page="groupState(g.key).page"
                :pages="groupState(g.key).pages"
                :busy="groupState(g.key).busy"
                :label="`${g.label} pages`"
                @go="(n) => gotoPage(g.key, n)"
              />
            </div>
          </details>
        </template>

        <!-- No "watched daily" section: which pages get asked about first is a
             SCHEDULE, not a display. It only changes what a reader sees on a
             site too big for one day's rotation — and there the cadence
             sentence above says it in words. Rendering it as a list put a
             standing block of never-changing green rows on the card, which is
             the same noise the rotation's healthy pages were never allowed to
             make. A watched page still surfaces the moment it has news: as a
             problem above, as an announcement when it heals, or by URL in the
             lookup. -->

        <!-- The site-level pointers, in the shared facts grammar: a hairline
             off the rows, each line carrying the right rail's ↳ mark. The
             per-page lookup below gets the card's full width — a found row
             answers in the same four-lane grammar as the problem rows above. -->
        <div class="ar-gidx__facts">
          <p class="ar-gidx__sitemap">
            The complete list, every state, lives in
            <a v-if="gscPagesLink" class="ar-gidx__gsc" :href="gscPagesLink" target="_blank" rel="noopener">Search Console's Pages report</a>
            — the group counts above are complete.
          </p>
          <p v-if="sitemapNote" class="ar-gidx__sitemap" :class="{ 'is-warn': sitemapNote.warn }">
            {{ sitemapNote.text }}
            <a v-if="sitemapNote.warn && gscSitemapsLink" class="ar-gidx__gsc" :href="gscSitemapsLink" target="_blank" rel="noopener">Open Sitemaps in Search Console</a>
          </p>
        </div>

        <div class="ar-gidx__lookupsec">
            <span class="ar-perf__eyebrow ar-gidx__secname">Look up a page — from the stored checks</span>
            <div class="ar-gidx__lookup">
              <input
                type="text"
                v-model="lookupQuery"
                :disabled="lookupBusy"
                placeholder="Paste one of this site's page URLs — e.g. /my-post/"
                aria-label="Look up a page by URL"
                @keyup.enter="runLookup"
              />
              <button type="button" class="ar-gidx__lookupbtn" :disabled="lookupBusy" @click="runLookup">Look up</button>
            </div>
            <template v-if="lookupOut">
              <ul v-if="lookupOut.status === 'found' && lookupOut.row" class="ar-gidx__list ar-gidx__list--grouped">
                <li class="ar-gidx__row">
                  <div class="ar-gidx__main">
                    <span class="ar-gidx__page">
                      <a :href="lookupOut.row.url" target="_blank" rel="noopener" class="ar-gidx__title">{{ lookupOut.row.title }}</a>
                    </span>
                    <span class="ar-gidx__crawl">{{ lookupOut.row.lastCrawl ? `visited ${day(lookupOut.row.lastCrawl)}` : (lookupOut.row.error ? '' : 'never visited') }}</span>
                    <span class="ar-gidx__door">
                      <a v-if="lookupOut.row.gscLink" class="ar-gidx__gsc" :href="lookupOut.row.gscLink" target="_blank" rel="noopener" @click="noteOpened(lookupOut.row)">Open in Search Console</a>
                      <span v-if="lookupOut.row.openedAt" class="ar-gidx__opened">console opened {{ day(lookupOut.row.openedAt) }}</span>
                    </span>
                    <span class="ar-gidx__chip" :class="verdictClass(lookupOut.row)">{{ verdictLabel(lookupOut.row) }}</span>
                    <p v-if="rowNote(lookupOut.row)" class="ar-gidx__note" :class="lookupOut.row.error ? 'is-err' : 'is-warn'">{{ rowNote(lookupOut.row) }}</p>
                  </div>
                </li>
              </ul>
              <p v-else-if="lookupOut.status === 'unchecked'" class="ar-gidx__note is-warn ar-gidx__lookupmsg">
                This page hasn't been checked yet — {{ lookupOut.cycleDays <= 1 ? 'the daily rotation reaches every page within a day' : `the rotation reaches every page within about ${lookupOut.cycleDays} days` }}.
              </p>
              <p v-else-if="lookupOut.status === 'foreign'" class="ar-gidx__note is-warn ar-gidx__lookupmsg">
                That address isn't on this site — only this site's pages are checked.
              </p>
              <p v-else-if="lookupOut.status === 'error'" class="ar-gidx__note is-err ar-gidx__lookupmsg">{{ lookupOut.message }}</p>

              <!-- The one place a live call is offered, and only after a stored
                   answer has been shown: the stored answer is free and usually
                   enough, so asking Google is the deliberate second step, not
                   the default. Offered for a page never checked too — that is
                   exactly when waiting for the rotation is the wrong answer. -->
              <p v-if="lookupOut.status === 'found' || lookupOut.status === 'unchecked'" class="ar-gidx__checkrow">
                <button type="button" class="ar-linkbtn ar-linkbtn--act" :disabled="!!checkingUrl" @click="recheckUrl(lookupOut.row ? lookupOut.row.url : lookupFor)">
                  {{ checkingUrl ? 'Asking Google…' : 'Check It Now' }}
                </button>
                <span class="ar-gidx__checkhint">Asks Google about this one page and spends one of today's checks.</span>
              </p>
            </template>
            <p class="ar-gidx__lookuphint">
              Answers come from the stored daily checks, not a live call. This is
              your own record of what Google said, not a new question to Google.
              A page not checked yet says so. When the stored answer isn't fresh
              enough, <strong>Re-check</strong> on the answer asks Google live,
              spending one of the day's inspections.
            </p>
        </div>

        <p class="ar-card__note ar-cf-note">
          All answers come from Google's own URL Inspection tool, once a day: the pages
          watched daily — homepage, busiest, newest, plus problem pages until they heal —
          and the rest of the site in rotation, up to {{ watched.rotationDaily }} inspections
          a day. Together a small slice of the {{ watched.dailyCap.toLocaleString() }} Google
          allows. Every verdict is Google's own wording, never a guess.
        </p>
      </template>
    </section>

    <p v-else-if="error" class="ar-log__error" role="alert">{{ error }}</p>
  </div>
</template>
