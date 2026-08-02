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
      return (this.view && this.view.site) || { totalUrls: 0, checked: 0, onGoogle: 0, notOnGoogle: 0, cycleDays: 0, problems: [] };
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
        let guard = 30;
        do {
          this.view = await this.api.refreshGoogleIndex();
        } while (this.view && this.view.pending > 0 && !this.view.lastError && !this.view.quotaHit && --guard > 0);
      } catch (e) {
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
      // Site-rotation rows sit under their own heading — a tag would repeat it.
      return { home: 'homepage', new: 'new post', busy: 'busiest' }[reason] || '';
    },
    // Ratio-bar segment width, of the pages actually checked.
    pct(n) {
      return this.counts.checked ? `${(Number(n || 0) / this.counts.checked) * 100}%` : '0%';
    },
    // Per-row second line: only when there is something worth saying. Google's
    // own coverage sentence carries the "why not"; our clauses name the silent
    // killers — canonical swaps, robots/noindex blocks, fetch failures, a page
    // no sitemap ever mentioned, rich-result issues.
    rowNote(r) {
      if (r.error) return r.error;
      const bits = [];
      if (r.verdict && r.verdict !== 'pass' && r.state) bits.push(r.state);
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
      if (r.verdict && r.verdict !== 'pass' && !r.inSitemap) bits.push('no sitemap Google knows lists this address');
      if (r.richIssues > 0) bits.push(`${r.richIssues} rich-result issue${r.richIssues > 1 ? 's' : ''}${r.richTypes ? ` (${r.richTypes})` : ''}`);
      return bits.join(' · ');
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
        (that's Search Performance below), just whether they're in. AI Overviews,
        AI Mode and Gemini find pages through this index.
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
          <span>{{ view.pending }} pages still being checked in the background — answers fill in as they arrive</span>
        </template>
        <template v-if="view.lastError">
          <span class="ar-mcp-rail__sep ar-gidx__sep-warn" aria-hidden="true">·</span>
          <span class="ar-warn">Last check failed: {{ view.lastError }} — showing the last good answers.</span>
        </template>
      </div>

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
        <!-- The scope lives NEXT TO the numbers it scopes — at the bottom it
             went unread and "why only 21?" was the first question asked. -->
        <p class="ar-gidx__scope">
          These tiles are the daily watchlist: your homepage, up to {{ watched.busiest }} busiest
          pages, and your {{ watched.newest }} newest posts. The rest of the site is covered
          below, in rotation.
        </p>

        <ul class="ar-gidx__list">
          <li v-for="r in rows" :key="r.url" class="ar-gidx__row">
            <div class="ar-gidx__main">
              <span class="ar-gidx__page">
                <a :href="r.url" target="_blank" rel="noopener" class="ar-gidx__title">{{ r.title }}</a>
                <span v-if="reasonLabel(r.reason)" class="ar-gidx__why">{{ reasonLabel(r.reason) }}</span>
              </span>
              <span class="ar-gidx__meta">
                <!-- "visited", not "Google last visited": the card head already
                     says Google, and repeated 21 times the long phrase made the
                     date outweigh the verdict. Always rendered, even empty —
                     it is a grid cell, and a missing cell would slide the chip
                     into the date column. -->
                <span class="ar-gidx__crawl">{{ r.lastCrawl ? `visited ${day(r.lastCrawl)}` : (r.error ? '' : 'never visited') }}</span>
                <span class="ar-gidx__chip" :class="verdictClass(r)">{{ verdictLabel(r) }}</span>
              </span>
            </div>
            <!-- A note only exists when something needs attention, so any
                 non-error note wears the warn color — a canonical swap on an
                 indexed page is as much a finding as a missing page. The
                 Search Console link is the honest "Request indexing": that
                 button has no API, but this lands one click away from it. -->
            <p v-if="rowNote(r)" class="ar-gidx__note" :class="r.error ? 'is-err' : 'is-warn'">
              {{ rowNote(r) }}<a v-if="r.gscLink" class="ar-gidx__gsc" :href="r.gscLink" target="_blank" rel="noopener">Open in Search Console</a>
            </p>
          </li>
        </ul>

        <!-- The whole-site rotation: healthy pages are a count, problems are
             rows — 500 green rows would be noise, one number isn't. -->
        <template v-if="siteLine">
          <p class="ar-perf__eyebrow ar-gidx__siteeyebrow">Across the whole site</p>
          <p class="ar-gidx__siteline">{{ siteLine }}</p>
          <ul v-if="site.problems.length" class="ar-gidx__list">
            <li v-for="r in site.problems" :key="r.url" class="ar-gidx__row">
              <div class="ar-gidx__main">
                <span class="ar-gidx__page">
                  <a :href="r.url" target="_blank" rel="noopener" class="ar-gidx__title">{{ r.title }}</a>
                </span>
                <span class="ar-gidx__meta">
                  <span class="ar-gidx__crawl">{{ r.lastCrawl ? `visited ${day(r.lastCrawl)}` : (r.error ? '' : 'never visited') }}</span>
                  <span class="ar-gidx__chip" :class="verdictClass(r)">{{ verdictLabel(r) }}</span>
                </span>
              </div>
              <p v-if="rowNote(r)" class="ar-gidx__note" :class="r.error ? 'is-err' : 'is-warn'">
                {{ rowNote(r) }}<a v-if="r.gscLink" class="ar-gidx__gsc" :href="r.gscLink" target="_blank" rel="noopener">Open in Search Console</a>
              </p>
            </li>
          </ul>
          <p v-else class="ar-gidx__siteclear">Nothing else on the site needs attention.</p>
        </template>

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
