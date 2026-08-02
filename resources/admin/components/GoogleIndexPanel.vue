<script>
/**
 * In Google's Index — the Google half of the AI-search infrastructure story,
 * seated beside the Bing card. Bing's card answers "how much of the site does
 * Bing hold?"; this one answers the question Google actually lets us ask:
 * "are the pages that MATTER in Google's index?" — the index AI Overviews,
 * AI Mode and Gemini read.
 *
 * The scope is a stated sample, never "the site": Google allows 2,000 URL
 * inspections a day and has no bulk index report, so the daily check covers
 * the homepage, the busiest pages, and the newest posts. Every count on this
 * card names that scope — presence, not traffic (traffic lives one card down
 * in Search Performance).
 *
 * Off state = one quiet pointer at Settings → Data sources. No form here.
 */
import { formatDate } from '../wpDate.js';

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
      error: '',
      watchOpen: false,
      openGroups: {},
      lookupQuery: '',
      lookupOut: null,
      lookupBusy: false,
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
    site() {
      return (this.view && this.view.site) || { totalUrls: 0, checked: 0, onGoogle: 0, notOnGoogle: 0, cycleDays: 0, problems: [], problemsTotal: 0 };
    },
    // The whole-site summary in one sentence, honest about partial passes.
    siteLine() {
      const s = this.site;
      if (!s.totalUrls || !s.checked) return '';
      const all = s.checked >= s.totalUrls;
      const head = all
        ? `All ${s.totalUrls} pages of this site have been checked — ${s.onGoogle} are in Google's index.`
        : `${s.checked} of this site's ${s.totalUrls} pages checked so far — ${s.onGoogle} in Google's index.`;
      const cadence = s.cycleDays <= 1
        ? 'Every page is re-checked daily.'
        : `Every page gets re-checked about every ${s.cycleDays} days.`;
      return `${head} ${cadence}`;
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
    // Problems grouped by the SERVER's stateKey, so the state is said ONCE
    // per group instead of on every row — and the count pill reads from
    // site.problemStates, the pre-cap totals: counts are always the whole
    // truth, rows are the bounded slice.
    problemGroups() {
      const cap = 8;
      const byKey = new Map();
      for (const r of this.site.problems) {
        const key = r.stateKey || 'other';
        if (!byKey.has(key)) byKey.set(key, []);
        byKey.get(key).push(r);
      }
      const totals = this.site.problemStates || {};
      return GROUP_META.filter((g) => byKey.has(g.key)).map((g) => {
        const all = byKey.get(g.key);
        const open = !!this.openGroups[g.key];
        return {
          key: g.key,
          label: g.label,
          sub: g.sub,
          count: Number(totals[g.key]) || all.length,
          rows: open ? all : all.slice(0, cap),
          more: open ? 0 : Math.max(0, all.length - cap),
          open,
        };
      });
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
  },
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    async load() {
      if (!this.api || this.loading) return;
      this.loading = true;
      this.error = '';
      try {
        this.view = await this.api.getGoogleIndex();
        this.loaded = true;
        // A run left mid-flight (a refresh killed the loop, a guard ran out)
        // resumes by itself: this is the owner's own browser — the same
        // hands that pressed Check now — and wp-cron on a cached site can
        // take hours over what this loop finishes in a minute.
        if (this.view && this.view.pending > 0 && !this.checking) this.checkNow();
      } catch (e) {
        this.error = e && e.message ? e.message : 'Could not load the Google index status.';
      } finally {
        this.loading = false;
      }
    },
    // The sweep is chunked server-side (a full watchlist in one request held
    // a worker long enough to 502) — so "Check now" is a LOOP of short
    // requests, each returning the rows it managed plus how many remain.
    // Answers fill in live between chunks; a hard cap bounds the loop even
    // if `pending` never falls (it always does — every chunk makes progress).
    async checkNow() {
      if (!this.api || this.checking) return;
      this.checking = true;
      this.error = '';
      try {
        // 120 ≥ one full run (watchlist + the whole daily rotation) even at
        // the slow-origin worst case of one inspection per chunk — the cap
        // exists for runaways, not as a working limit that strands a queue.
        let guard = 120;
        do {
          this.view = await this.api.refreshGoogleIndex();
        } while (this.view && this.view.pending > 0 && !this.view.lastError && !this.view.quotaHit && --guard > 0);
      } catch (e) {
        // Surfaced inside the card ({{ error }} under the rail) — a loop
        // that dies must say so where the owner is looking.
        this.error = e && e.message ? e.message : 'Could not reach Google.';
      } finally {
        this.checking = false;
      }
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
    reasonLabel(reason) {
      // One word each, naming why the row is WATCHED — "new post" claimed the
      // post was new; "newest" claims only its place on the list.
      return { home: 'home', new: 'newest', busy: 'busiest' }[reason] || '';
    },
    // Ratio-bar segment width, of the pages actually checked.
    pct(n) {
      return this.counts.checked ? `${(Number(n || 0) / this.counts.checked) * 100}%` : '0%';
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
    toggleGroup(key) {
      this.openGroups = { ...this.openGroups, [key]: !this.openGroups[key] };
    },
    // One page's STORED answer — the coverage map remembers every checked
    // page, so this needs no live call and spends no quota.
    async runLookup() {
      const q = this.lookupQuery.trim();
      if (!this.api || !q || this.lookupBusy) return;
      this.lookupBusy = true;
      this.lookupOut = null;
      try {
        this.lookupOut = await this.api.lookupGoogleIndex(q);
      } catch (e) {
        this.lookupOut = { status: 'error', row: null, message: e && e.message ? e.message : 'Lookup failed.' };
      } finally {
        this.lookupBusy = false;
      }
    },
    day(ts) {
      const t = Number(ts || 0) * 1000;
      return t ? formatDate(new Date(t), true) : '';
    },
    agoMin(ts) {
      const t = Number(ts || 0) * 1000;
      if (!t) return '';
      const m = Math.round((Date.now() - t) / 60000);
      if (m < 1) return 'just now';
      if (m < 60) return `${m}m ago`;
      const h = Math.round(m / 60);
      if (h < 24) return `${h}h ago`;
      return `${Math.round(h / 24)}d ago`;
    },
    goSettings() {
      this.$emit('navigate', { tab: 'settings', anchor: 'ar-sec-google' });
    },
  },
};
</script>

<template>
  <div class="ar-gidx">
    <!-- Off: one quiet pointer, no form, no nagging. -->
    <section v-if="loaded && !connected" class="ar-card ar-card--muted">
      <h2 class="ar-card__title">In Google's Index <span class="ar-card__tag">Off</span></h2>
      <p class="ar-card__lead ar-gidx__offlead">
        Google's index is what AI Overviews, AI Mode and Gemini read. Connect Google
        Search Console and this card checks every day that your key pages — homepage,
        busiest pages, newest posts — are actually in it.
        <button type="button" class="ar-linkbtn" @click="goSettings">
          Connect Google in Settings → Data sources
        </button>
      </p>
    </section>

    <section v-else-if="connected" class="ar-card">
      <h2 class="ar-card__title">In Google's Index <span class="ar-card__tag">Google · checked daily</span></h2>
      <p class="ar-card__lead">
        Whether Google's index holds the pages that matter — not how they rank or earn
        (that's Search Performance below), just whether they're in. Everything finds
        pages through this one index: classic Google Search that people use, and the
        AI surfaces (AI Overviews, AI Mode, Gemini) alike — fixing a page here helps
        both audiences at once.
      </p>

      <div class="ar-mcp-rail" data-state="running">
        <span class="ar-mcp-rail__dot" aria-hidden="true"></span>
        <strong>Connected</strong>
        <template v-if="view.checkedAt">
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
          <span>checked {{ agoMin(view.checkedAt) }}</span>
        </template>
        <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
        <button type="button" class="ar-linkbtn" :disabled="checking" @click="checkNow">
          {{ checking ? (view.pending > 0 ? `Checking… ${view.pending} to go` : 'Checking…') : 'Check now' }}
        </button>
        <template v-if="!checking && view.pending > 0">
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
          <!-- Honest about the machinery: the daily check WILL finish these,
               but on a heavily cached site wp-cron can take hours over what
               Check now does in a minute — never promise "fills in as it
               arrives" for a trickle. -->
          <span>{{ view.pending }} pages still to check — press Check now to finish, or the daily check picks them up</span>
        </template>
        <template v-if="view.lastError">
          <span class="ar-mcp-rail__sep ar-gidx__sep-warn" aria-hidden="true">·</span>
          <span class="ar-warn">Last check failed: {{ view.lastError }} — showing the last good answers.</span>
        </template>
      </div>

      <!-- A checkNow loop that dies must say so HERE, where the owner is
           looking — the closing error slot below only renders disconnected. -->
      <p v-if="error" class="ar-log__error" role="alert">{{ error }}</p>

      <!-- Quota is a state, not a fault — named in its own words so a partial
           list never reads as a partial site. -->
      <p v-if="view.quotaHit" class="ar-srcline ar-gidx__quota">
        Google's inspection budget for today ran out mid-check — pages checked before
        that keep fresh answers, the rest show their last good ones. The daily check
        resumes tomorrow.
      </p>

      <div v-if="!hasRows" class="ar-wd-empty">
        No answers yet. The first check runs within moments of connecting — or press
        Check now.
      </div>
      <template v-else>
        <!-- The story before the list — the Bing card's own tile grammar, so
             the two index cards read as siblings. Color only where it means
             something: a zero stays quiet, a nonzero problem count warms up. -->
        <div class="ar-wd-stats ar-act-stats ar-act-stats--4">
          <div class="ar-wd-stat"><strong>{{ counts.onGoogle }} / {{ counts.checked }}</strong><span>In Google's index</span></div>
          <div class="ar-wd-stat"><strong :class="{ 'ar-gidx__hot': counts.notOnGoogle }">{{ counts.notOnGoogle }}</strong><span>Not in the index</span></div>
          <div class="ar-wd-stat"><strong :class="{ 'ar-gidx__hot': counts.canonicalDiffers }">{{ counts.canonicalDiffers }}</strong><span>Different address chosen</span></div>
          <div class="ar-wd-stat"><strong :class="{ 'ar-gidx__hot': counts.errors }">{{ counts.errors }}</strong><span>Checks failed</span></div>
        </div>
        <div
          class="ar-gidx__ratio"
          role="img"
          :aria-label="`${counts.onGoogle} of ${counts.checked} checked pages are in Google's index`"
        >
          <span class="ar-gidx__ratio-seg is-ok" :style="{ width: pct(counts.onGoogle) }"></span>
          <span class="ar-gidx__ratio-seg is-warn" :style="{ width: pct(counts.notOnGoogle) }"></span>
          <span class="ar-gidx__ratio-seg is-err" :style="{ width: pct(counts.errors) }"></span>
        </div>
        <!-- THE ROW GRAMMAR, card-wide: one line, four fixed lanes —
             title | date | console door | chip. Empty lanes still render, so
             all four columns run straight from the first watchlist row to the
             last problem row; the clause line sits under the title, capped to
             the title lane. The door is on EVERY checked row (the deep link
             is the honest "Request indexing" — that button has no API). -->
        <div class="ar-gidx__sec">
          <span class="ar-perf__eyebrow ar-gidx__secname">Watchlist</span>
          <span class="ar-gidx__seccount">{{ rows.length }}</span>
        </div>
        <p class="ar-gidx__scope">
          Checked every day: your homepage, up to {{ watched.busiest }} busiest
          pages, and up to {{ watched.newest }} newest posts. The rest of the site
          is covered below, in rotation.
        </p>

        <ul class="ar-gidx__list">
          <li v-for="r in (watchOpen ? rows : rows.slice(0, 8))" :key="r.url" class="ar-gidx__row">
            <div class="ar-gidx__main">
              <span class="ar-gidx__page">
                <a :href="r.url" target="_blank" rel="noopener" class="ar-gidx__title">{{ r.title }}</a>
                <span v-if="reasonLabel(r.reason)" class="ar-gidx__why">{{ reasonLabel(r.reason) }}</span>
              </span>
              <span class="ar-gidx__crawl">{{ r.lastCrawl ? `visited ${day(r.lastCrawl)}` : (r.error ? '' : 'never visited') }}</span>
              <span class="ar-gidx__door"><a v-if="r.gscLink" class="ar-gidx__gsc" :href="r.gscLink" target="_blank" rel="noopener">Open in Search Console ↗</a></span>
              <span class="ar-gidx__chip" :class="verdictClass(r)">{{ verdictLabel(r) }}</span>
              <p v-if="rowNote(r)" class="ar-gidx__note" :class="r.error ? 'is-err' : 'is-warn'">{{ rowNote(r) }}</p>
            </div>
          </li>
        </ul>
        <button v-if="rows.length > 8" type="button" class="ar-gidx__fold" @click="watchOpen = !watchOpen">
          {{ watchOpen ? 'Show fewer ↑' : `Show all ${rows.length} ↓` }}
        </button>

        <!-- The whole-site rotation: healthy pages are a count, problems are
             rows — 500 green rows would be noise, one number isn't. -->
        <template v-if="siteLine">
          <div class="ar-gidx__sec ar-gidx__siteeyebrow">
            <span class="ar-perf__eyebrow ar-gidx__secname">Across the whole site</span>
          </div>
          <p class="ar-gidx__siteline">{{ siteLine }}</p>
          <!-- A clause true of EVERY problem row is a site fact — said once
               here, lag named, not repeated down the list. -->
          <p v-if="site.problems.length && hoistLine" class="ar-gidx__note is-warn ar-gidx__hoist">{{ hoistLine }}</p>
          <template v-if="site.problems.length">
            <!-- Problems grouped by the server's stateKey: the heading carries
                 the state ONCE (with Google's own wording quoted below it),
                 the count pill reads the PRE-CAP totals, and past 8 rows the
                 group unfolds in place — counts are truth, rows are the
                 bounded slice. -->
            <template v-for="g in problemGroups" :key="g.key">
              <h4 class="ar-gidx__group">{{ g.label }} <span class="ar-gidx__groupcount">{{ g.count }}</span></h4>
              <p v-if="g.sub" class="ar-gidx__gsub">{{ g.sub }}</p>
              <ul class="ar-gidx__list ar-gidx__list--grouped">
                <li v-for="r in g.rows" :key="r.url" class="ar-gidx__row">
                  <div class="ar-gidx__main">
                    <span class="ar-gidx__page">
                      <a :href="r.url" target="_blank" rel="noopener" class="ar-gidx__title">{{ r.title }}</a>
                    </span>
                    <span class="ar-gidx__crawl">{{ r.lastCrawl ? `visited ${day(r.lastCrawl)}` : (r.error ? '' : 'never visited') }}</span>
                    <span class="ar-gidx__door"><a v-if="r.gscLink" class="ar-gidx__gsc" :href="r.gscLink" target="_blank" rel="noopener">Open in Search Console ↗</a></span>
                    <span class="ar-gidx__chip" :class="verdictClass(r)">{{ verdictLabel(r) }}</span>
                    <p v-if="rowNote(r, true)" class="ar-gidx__note" :class="r.error ? 'is-err' : 'is-warn'">{{ rowNote(r, true) }}</p>
                  </div>
                </li>
              </ul>
              <button v-if="g.more || g.open" type="button" class="ar-gidx__morebtn" @click="toggleGroup(g.key)">
                {{ g.open ? 'Show fewer' : `Show the other ${g.more}` }}
              </button>
            </template>
            <p v-if="site.problemsTotal > site.problems.length" class="ar-gidx__more">
              These groups cover the first {{ site.problems.length }} of {{ site.problemsTotal }} problem
              pages — the daily rotation keeps re-checking them all.
              <a v-if="gscPagesLink" class="ar-gidx__gsc" :href="gscPagesLink" target="_blank" rel="noopener">See the complete list in Search Console ↗</a>
            </p>
          </template>
          <p v-else class="ar-gidx__siteclear">Nothing else on the site needs attention.</p>
        </template>

        <!-- Look up one page: answered from the STORED daily checks — the
             coverage map remembers every checked page, including the healthy
             ones that never earn a row. No live call, no quota spent. -->
        <div class="ar-gidx__sec ar-gidx__siteeyebrow">
          <span class="ar-perf__eyebrow ar-gidx__secname">Look up a page</span>
        </div>
        <div class="ar-gidx__lookup">
          <input
            type="text"
            v-model="lookupQuery"
            :disabled="lookupBusy"
            placeholder="Paste one of this site's page URLs — e.g. /my-post/"
            aria-label="Look up a page by URL"
            @keyup.enter="runLookup"
          />
        </div>
        <template v-if="lookupOut">
          <ul v-if="lookupOut.status === 'found' && lookupOut.row" class="ar-gidx__list ar-gidx__list--grouped">
            <li class="ar-gidx__row">
              <div class="ar-gidx__main">
                <span class="ar-gidx__page">
                  <a :href="lookupOut.row.url" target="_blank" rel="noopener" class="ar-gidx__title">{{ lookupOut.row.title }}</a>
                </span>
                <span class="ar-gidx__crawl">{{ lookupOut.row.lastCrawl ? `visited ${day(lookupOut.row.lastCrawl)}` : (lookupOut.row.error ? '' : 'never visited') }}</span>
                <span class="ar-gidx__door"><a v-if="lookupOut.row.gscLink" class="ar-gidx__gsc" :href="lookupOut.row.gscLink" target="_blank" rel="noopener">Open in Search Console ↗</a></span>
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
        </template>
        <p class="ar-gidx__lookuphint">
          Answers come from the stored daily checks, not a live call. A page not
          checked yet says so.
        </p>

        <!-- Registration health — the failure only Google's bookkeeping can
             see: a perfect on-site file whose registration points at an
             address that moved. Quiet when healthy, amber when the owner
             should act, silent until the first look. -->
        <p v-if="sitemapNote" class="ar-gidx__sitemap" :class="{ 'is-warn': sitemapNote.warn }">
          {{ sitemapNote.text }}
          <a v-if="sitemapNote.warn && gscSitemapsLink" class="ar-gidx__gsc" :href="gscSitemapsLink" target="_blank" rel="noopener">Open Sitemaps in Search Console</a>
        </p>

        <p class="ar-card__note ar-cf-note">
          All answers come from Google's own URL Inspection tool, once a day: the watchlist
          above is checked every day, and the rest of the site rotates through up to
          {{ watched.rotationDaily }} inspections a day — together a small slice of the
          {{ watched.dailyCap.toLocaleString() }} Google allows. Every verdict is Google's
          own wording, never a guess.
        </p>
      </template>
    </section>

    <p v-else-if="error" class="ar-log__error" role="alert">{{ error }}</p>
  </div>
</template>
