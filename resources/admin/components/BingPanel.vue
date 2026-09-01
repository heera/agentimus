<script>
/**
 * In Bing's index — the Bing half of the Visibility screen's In-the-index view.
 * (Long called "Found by AI Search": the AI significance is real — ChatGPT
 * search and Copilot read this index today — but it is a STORY ABOUT the
 * data, not the data, so it now lives in a labeled pin instead of the title.)
 *
 * The visibility checks above ask assistants questions; this card reads the
 * infrastructure underneath: how much of the site sits in Bing's index — the
 * index ChatGPT search reads today, Copilot too — and how cleanly Bing's
 * crawler gets in. Fetches /bing/summary on every reveal (a connect or an
 * overnight poll must show without a page reload).
 *
 * Off state = one quiet pointer card at Settings → Data Sources. No form
 * here: the key has exactly one home.
 */
import { formatDate, relTimeShort } from '../js/wpDate.js';
import { uaTip } from '../js/uaTip.js';
import SelectMenu from './SelectMenu.vue';
import RefreshCrank from './RefreshCrank.vue';

export default {
  name: 'BingPanel',
  components: { SelectMenu, RefreshCrank },
  // The trend bars use the house hover bubble — never a native title.
  mixins: [uaTip],
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show, so it stays mounted across tab switches.
    active: { type: Boolean, default: false },
  },
  emits: ['navigate', 'flash'],
  data() {
    return {
      summary: null,
      loading: false,
      loaded: false,
      fetching: false,
      error: '',
      // The clicked bar's index; -1 = no day report open. All data is already
      // local (the trend rows carry the whole day), so there is no loading state.
      selectedDay: -1,
      // The live per-URL question — the one thing on this card that IS a call
      // to Bing right now, and its copy says so.
      lookupQuery: '',
      lookupBusy: false,
      lookupOut: null,
    };
  },
  computed: {
    connected() {
      return !!(this.summary && this.summary.connected);
    },
    totals() {
      return (this.summary && this.summary.totals) || { inIndex: 0, crawledLatest: 0, crawlErrors: 0, blockedByRobots: 0 };
    },
    trend() {
      return (this.summary && this.summary.trend) || [];
    },
    // Deep links into Bing Webmaster Tools — the doors the Google card's
    // Search Console links mirror. Bing's console addresses a site by
    // ?siteUrl=; the matched WMT site URL travels in the summary precisely
    // so these point at THIS site, not the console's site picker.
    wmtHomeLink() {
      const s = this.summary && this.summary.siteUrl;
      return s ? `https://www.bing.com/webmasters/home?siteUrl=${encodeURIComponent(s)}` : 'https://www.bing.com/webmasters/';
    },
    wmtSitemapsLink() {
      const s = this.summary && this.summary.siteUrl;
      return s ? `https://www.bing.com/webmasters/sitemaps?siteUrl=${encodeURIComponent(s)}` : '';
    },
    conflicts() {
      return (this.summary && this.summary.conflicts) || [];
    },
    // Bing's own sitemap record (GetFeeds) as one line — the same question the
    // Google card answers from sitemaps.list: does Bing know the sitemap, and
    // is it reading it? Dates are Bing's, so the line needs no age disclaimer.
    feedsNote() {
      if (!this.summary || !this.summary.connected || !Array.isArray(this.summary.feeds)) return null;
      const feeds = this.summary.feeds;
      if (!feeds.length) {
        // feedsAt 0 = the snapshot was never fetched (older install, poll not
        // run yet) — saying "no sitemap registered" then would be a guess.
        if (!this.summary.feedsAt) return null;
        return { warn: true, text: 'No sitemap is registered in Bing Webmaster Tools — submit yours once, and Bing finds new posts on its own.' };
      }
      const f = feeds[0];
      const more = feeds.length > 1 ? ` (+${feeds.length - 1} more)` : '';
      if (!f.lastReadAt) {
        return { warn: false, text: `Sitemap submitted — Bing hasn't read it yet. The first read usually lands within a day.${more}` };
      }
      return { warn: false, text: `Bing's registered sitemap: ${f.url}${more} — last read ${this.day(f.lastReadAt)}${f.urls ? `, ${this.n(f.urls)} URLs` : ''}.` };
    },
    // Whether this site announces publishes (IndexNow) — status when on,
    // one quiet pointer when off, never a nag.
    indexnowNote() {
      const inw = this.summary && this.summary.indexnow;
      if (!inw) return null;
      if (!inw.enabled) {
        return { warn: false, text: 'IndexNow is off — one switch in Settings announces each publish to search engines the moment it happens.' };
      }
      if (inw.lastError) {
        return { warn: true, text: `IndexNow is on, but the last announcement failed: ${inw.lastError}` };
      }
      if (!inw.lastAt) {
        return { warn: false, text: 'IndexNow is on — the next publish announces itself to Bing and every engine that listens.' };
      }
      return { warn: false, text: `IndexNow is on — last announced ${this.n(inw.lastUrls)} URL${inw.lastUrls === 1 ? '' : 's'}, ${this.agoMin(inw.lastAt)}.` };
    },
    siteHost() {
      const url = (this.summary && this.summary.siteUrl) || '';
      return url.replace(/^https?:\/\//, '').replace(/\/$/, '');
    },
    // Bar heights: the index trend usually moves a little on a big base, so a
    // 0-scaled chart would render as a flat wall. Scale min→max into 30→100%.
    //
    // ⛔⛔ THE SCALE READS REPORTED DAYS ONLY. Bing answers for dates it has no
    // numbers for, and those arrive as zeros; letting one into min→max pinned
    // the floor at 0, so every real day crushed against the top of the chart
    // and the silent day drew as a collapse to nothing. Both halves of that
    // were wrong: the day was not a collapse, and the other twenty-nine were
    // not identical.
    bars() {
      const vals = this.trend.filter((t) => t.reported).map((t) => t.inIndex);
      if (!vals.length) return this.trend.map(() => 0);
      const min = Math.min(...vals);
      const max = Math.max(...vals);
      // ⭐ null, never a height — a day Bing said nothing about has no size to
      // draw, and the template gives it a slot that says so instead.
      return this.trend.map((t) =>
        !t.reported ? null : max === min ? 65 : 30 + Math.round(((t.inIndex - min) / (max - min)) * 70)
      );
    },
    // The captions name the ends of what was actually MEASURED. Printing a
    // silent day's date beside "0" would put the very number this card exists
    // to stop believing under the chart in the owner's own words.
    trendFirst() {
      return this.trend.find((t) => t.reported) || null;
    },
    trendLast() {
      for (let i = this.trend.length - 1; i >= 0; i--) {
        if (this.trend[i].reported) return this.trend[i];
      }
      return null;
    },
    selected() {
      return this.selectedDay >= 0 && this.selectedDay < this.trend.length ? this.trend[this.selectedDay] : null;
    },
    dayOptions() {
      return this.trend.map((t, i) => ({
        value: String(i),
        // ⛔ "· 0 pages" in a picker is the same false claim as the bar was.
        label: t.reported
          ? `${this.day(t.date)} · ${this.n(t.inIndex)} pages`
          : `${this.day(t.date)} · nothing reported`,
      }));
    },
    // The crawl picture as rank rows, bars scaled to the day's crawled count.
    dayRows() {
      if (!this.selected) return [];
      const s = this.selected;
      return [
        { label: 'Crawled', value: s.crawled, warn: false },
        { label: 'OK (2xx)', value: s.ok, warn: false },
        { label: 'Redirects', value: s.redirects, warn: false },
        { label: 'Errors (4xx)', value: s.clientErrors, warn: s.clientErrors > 0 },
        { label: 'Server errors (5xx)', value: s.serverErrors, warn: s.serverErrors > 0 },
        { label: 'Blocked by robots.txt', value: s.blockedByRobots, warn: s.blockedByRobots > 0 },
      ];
    },
    dayRowMax() {
      return this.dayRows.reduce((m, r) => Math.max(m, r.value), 1);
    },
  },
  watch: {
    active(on) {
      if (on && !this.loading) this.load();
    },
  },
  mounted() {
    if (this.active) this.load();
    // Coming back from another BROWSER tab is a reveal too: the owner saves a
    // post over there, returns here, and the IndexNow line is the receipt —
    // it must not need a manual reload (his catch). Same listener App and
    // SettingsForm already use.
    document.addEventListener('visibilitychange', this.onTabReturn);
  },
  beforeUnmount() {
    document.removeEventListener('visibilitychange', this.onTabReturn);
  },
  methods: {
    onTabReturn() {
      if (!document.hidden && this.active && !this.loading) this.load();
    },
    async load() {
      if (!this.api || this.loading) return;
      this.loading = true;
      this.error = '';
      try {
        this.summary = await this.api.getBingSummary(30);
        this.selectedDay = -1; // fresh data, stale selection.
      } catch (e) {
        this.error = e && e.message ? e.message : 'Could not load the Bing summary.';
      } finally {
        // In finally, not the try: a failed FIRST load must still leave the
        // skeleton for the error/off states, not hang on it forever.
        this.loaded = true;
        this.loading = false;
      }
    },
    pickDay(i) {
      this.selectedDay = i;
      this.$nextTick(() => {
        if (this.$refs.bingDayDialog) this.$refs.bingDayDialog.focus();
      });
    },
    closeDay() {
      this.selectedDay = -1;
    },
    stepDay(delta) {
      const next = this.selectedDay + delta;
      if (next >= 0 && next < this.trend.length) this.selectedDay = next;
    },
    barPct(v) {
      return `${Math.round((v / this.dayRowMax) * 100)}%`;
    },
    async fetchNow() {
      if (!this.api || this.fetching) return;
      this.fetching = true;
      try {
        this.summary = await this.api.refreshBingSummary(30);
      } catch (e) {
        this.error = e && e.message ? e.message : 'Could not reach Bing.';
      } finally {
        this.fetching = false;
      }
    },
    n(v) {
      return Number(v || 0).toLocaleString();
    },
    day(iso) {
      const d = new Date(`${iso}T00:00:00Z`);
      return Number.isNaN(d.getTime()) ? iso : formatDate(d, true);
    },
    // Ask Bing about one page, live. Errors land inline in the same slot the
    // answer would — Bing's words when Bing refused, ours when the input did.
    async runLookup() {
      const q = this.lookupQuery.trim();
      if (!q || this.lookupBusy || !this.api || !this.api.checkBingUrl) return;
      this.lookupBusy = true;
      this.lookupOut = null;
      try {
        this.lookupOut = await this.api.checkBingUrl(q);
      } catch (e) {
        this.lookupOut = { error: (e && e.message) || 'Bing could not be reached.' };
      } finally {
        this.lookupBusy = false;
      }
    },
    lookupLine(info) {
      const bits = [];
      if (info.discoveredAt) bits.push(`discovered ${this.day(info.discoveredAt)}`);
      if (info.lastCrawledAt) bits.push(`last crawled ${this.day(info.lastCrawledAt)}`);
      return `Bing's crawler knows this page — ${bits.join(', ')}.`;
    },
    agoMin(ts) {
      return relTimeShort(Number(ts || 0) * 1000);
    },
    goSettings() {
      this.$emit('navigate', { tab: 'settings', anchor: 'ar-sec-bing' });
    },
  },
};
</script>

<template>
  <div class="ar-bing">
    <!-- First fetch in flight: the card's own shape in skeleton — a blank
         stretch under the caption read as a broken screen, not a loading one. -->
    <section v-if="!loaded" class="ar-card" role="status" aria-label="Loading Bing index data">
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
      <h2 class="ar-card__title">In Bing's Index <span class="ar-card__tag">Off</span></h2>
      <p class="ar-card__lead ar-bing__offlead">
        Bing is the index ChatGPT search reads today — Copilot too. Connect it and this card
        shows how much of your site that index holds, and warns you when something keeps
        Bing's crawler out.
        <button type="button" class="ar-linkbtn" @click="goSettings">
          Connect Bing in Settings → Data Sources
        </button>
      </p>
    </section>

    <template v-else-if="connected">
      <div v-if="conflicts.length" class="ar-edge-pins">
        <div v-for="c in conflicts" :key="c.id" class="ar-edge-pin ar-edge-pin--warn">
          <span class="ar-edge-pin__badge">Conflict</span>
          <p class="ar-edge-pin__title">{{ c.title }}</p>
          <p class="ar-edge-pin__body">{{ c.body }}</p>
          <div class="ar-edge-pin__actions">
            <a class="ar-linkbtn" :href="c.url" target="_blank" rel="noopener">Take a look</a>
          </div>
        </div>
      </div>

      <section class="ar-card">
        <div class="ar-card__titlewrap">
          <h2 class="ar-card__title">In Bing's Index <span class="ar-card__tag">Last {{ summary.days }} days</span></h2>
          <RefreshCrank
            :busy="loading"
            :aria-label="loading ? 'Re-reading Bing index data…' : 'Re-read Bing index data'"
            :title="loading ? 'Re-reading…' : 'Re-read Bing index data'"
            @refresh="load"
          />
        </div>
        <p class="ar-card__lead">
          How much of your site Bing's index holds, and how cleanly Bing's crawler gets in.
          Machines only — no people are counted on this card; searchers' clicks live on
          the Search tab.
        </p>

        <!-- The AI significance, SEPARATED from the data it annotates. Every
             number on this card is classic index machinery; what makes it
             matter for AI is a fact about who READS this index, and burying
             that in the footer (or baking it into the title, as "Found by AI
             Search" did) made users read crawl stats as AI data. A labeled
             pin says exactly what is AI about this card — and can honestly
             say "today". -->
        <div class="ar-edge-pin ar-edge-pin--info">
          <span class="ar-edge-pin__badge">AI search</span>
          <p class="ar-edge-pin__body">
            This is the index AI search reads today: ChatGPT search finds pages through
            Bing, and Microsoft Copilot rides it too. There is no separate AI index to
            show — when Bing's index holds you, they can find you. If that coupling ever
            changes, this card still tells you how Bing sees you.
          </p>
        </div>

        <div class="ar-mcp-rail" data-state="running">
          <span class="ar-mcp-rail__dot" aria-hidden="true"></span>
          <strong>Connected</strong>
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span><span class="ar-bing__site">{{ siteHost }}</span>
          <template v-if="summary.lastPollAt">
            <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
            <span>updated {{ agoMin(summary.lastPollAt) }}</span>
          </template>
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
          <button type="button" class="ar-linkbtn ar-linkbtn--act" :disabled="fetching" @click="fetchNow">
            {{ fetching ? 'Refreshing…' : 'Refresh' }}
          </button>
          <template v-if="summary.lastError">
            <span class="ar-mcp-rail__sep ar-bing__sep-warn" aria-hidden="true">·</span>
            <span class="ar-warn">Last poll failed: {{ summary.lastError }} — showing the last good numbers.</span>
          </template>
        </div>

        <!-- A manual Refresh that fails must say so HERE, where the owner is
             looking — the closing error slot below only renders disconnected. -->
        <p v-if="error" class="ar-log__error" role="alert">{{ error }} — try Refresh, and if it persists, reload the page.</p>

        <div v-if="!trend.length" class="ar-wd-empty">
          No numbers from Bing yet. The first daily numbers usually appear within a day of
          connecting — sooner after a Refresh.
        </div>
        <template v-else>
          <div class="ar-wd-stats ar-act-stats ar-act-stats--4">
            <div class="ar-wd-stat"><strong>{{ n(totals.inIndex) }}</strong><span>Pages in Bing's index</span></div>
            <div class="ar-wd-stat"><strong>{{ n(totals.crawledLatest) }}</strong><span>Pages crawled, latest day</span></div>
            <div class="ar-wd-stat"><strong :class="{ 'ar-bing__hot': totals.crawlErrors }">{{ n(totals.crawlErrors) }}</strong><span>Crawl errors, {{ summary.days }} days</span></div>
            <div class="ar-wd-stat"><strong :class="{ 'ar-bing__hot': totals.blockedByRobots }">{{ n(totals.blockedByRobots) }}</strong><span>Blocked by robots.txt</span></div>
          </div>

          <p class="ar-ai__sub ar-bing__sub">Pages in the index — day by day</p>
          <!-- Each bar is a button: hover says the day, a click opens the day
               report — the same modal contract every chart in the app keeps.
               On phones the scroller keeps bars touch-wide and opens at the
               NEWEST days (rtl outside, ltr inside — the dashboard's recipe). -->
          <div class="ar-bing__scrollwrap">
          <div class="ar-bing__trend">
            <!-- ⭐ A day Bing said nothing about keeps its PLACE in the row —
                 closing the gap would hide that the day existed at all, and
                 the days either side of it would read as consecutive. It draws
                 as an outline, not a bar: nothing to compare, and it says so
                 to a pointer and to a screen reader alike. -->
            <button
              v-for="(h, i) in bars"
              :key="i"
              type="button"
              class="ar-bing__bar"
              :class="{ 'is-active': selectedDay === i, 'is-silent': h === null }"
              :style="h === null ? null : { height: h + '%' }"
              :aria-label="h === null
                ? `${day(trend[i].date)} — Bing reported nothing for this day`
                : `${day(trend[i].date)} — ${n(trend[i].inIndex)} pages in Bing's index`"
              @click="pickDay(i)"
              @mouseenter="showUaTip($event, h === null
                ? `${day(trend[i].date)} · Bing reported nothing`
                : `${day(trend[i].date)} · ${n(trend[i].inIndex)} pages`, '', 'cursor')"
              @mouseleave="hideUaTip"
            ></button>
          </div>
          </div>
          <div class="ar-bing__cap">
            <span v-if="trendFirst">{{ day(trendFirst.date) }} · {{ n(trendFirst.inIndex) }}</span>
            <span v-if="trendLast">{{ day(trendLast.date) }} · {{ n(trendLast.inIndex) }}</span>
          </div>
        </template>

        <!-- The machine lines, in the shared facts grammar the Google card
             uses: does Bing know the sitemap (Bing's own record and dates),
             and does this site announce publishes (IndexNow)? -->
        <div v-if="feedsNote || indexnowNote" class="ar-gidx__facts">
          <p v-if="feedsNote" class="ar-gidx__sitemap" :class="{ 'is-warn': feedsNote.warn }">
            {{ feedsNote.text }}
            <a v-if="feedsNote.warn && wmtSitemapsLink" class="ar-gidx__gsc" :href="wmtSitemapsLink" target="_blank" rel="noopener">Open Sitemaps in Webmaster Tools</a>
          </p>
          <p v-if="indexnowNote" class="ar-gidx__sitemap" :class="{ 'is-warn': indexnowNote.warn }">{{ indexnowNote.text }}</p>
        </div>

        <!-- The live per-URL question — Bing's twin of the Google card's
             lookup, with the semantics reversed and SAID: Google's box reads
             the stored checks, this one asks Bing right now. Same markup
             grammar as Google's box, no new CSS. -->
        <div class="ar-bing__lookup ar-gidx__lookupsec">
          <span class="ar-perf__eyebrow ar-gidx__secname">Ask Bing about a page</span>
          <div class="ar-gidx__lookup">
            <input
              type="text"
              v-model="lookupQuery"
              :disabled="lookupBusy"
              placeholder="Paste one of this site's page URLs — e.g. /my-post/"
              aria-label="Ask Bing about a page by URL"
              @keyup.enter="runLookup"
            />
            <button type="button" class="ar-gidx__lookupbtn" :disabled="lookupBusy" @click="runLookup">Ask Bing</button>
          </div>
          <p v-if="lookupBusy" class="ar-gidx__note ar-gidx__lookupmsg">Asking Bing…</p>
          <template v-else-if="lookupOut">
            <p v-if="lookupOut.error" class="ar-gidx__note is-err ar-gidx__lookupmsg">{{ lookupOut.error }}</p>
            <p v-else-if="lookupOut.info && lookupOut.info.known" class="ar-gidx__note ar-gidx__lookupmsg">
              {{ lookupLine(lookupOut.info) }}
            </p>
            <p v-else class="ar-gidx__note is-warn ar-gidx__lookupmsg">
              Bing's crawler has never seen this page — too new, or nothing it has read links here yet.
            </p>
          </template>
          <p class="ar-field__hint">
            A live question to Bing, one call per check. Bing answers what its crawler
            knows — discovered and last crawled. Its API doesn't say per page whether a
            URL made the index, so the tiles above stay the index-level truth.
          </p>
        </div>

        <!-- Provenance only — the AI story lives in its own labeled pin above,
             where it can be seen instead of skimmed past. -->
        <p class="ar-card__note ar-cf-note">
          Numbers come from
          <a class="ar-gidx__gsc" :href="wmtHomeLink" target="_blank" rel="noopener">Bing Webmaster Tools</a>,
          one poll a day, kept in your own database —
          your history keeps growing where Bing's own window ends.
        </p>
      </section>
    </template>

    <p v-else-if="error" class="ar-log__error" role="alert">{{ error }}</p>

    <!-- Day report: the dashboard day modal's twin, same conventions — fixed
         focus, Esc/Close only (no backdrop close), arrow keys switch days. -->
    <Teleport to="body">
      <transition name="ar-modal">
        <div v-if="selected" class="ar-modal">
          <div
            ref="bingDayDialog"
            class="ar-modal__panel ar-modal__panel--day"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ar-bing-day-title"
            tabindex="-1"
            @keydown.esc="closeDay"
            @keydown.left="stepDay(-1)"
            @keydown.right="stepDay(1)"
          >
            <div class="ar-modal__head">
              <div class="ar-day-head">
                <h2 id="ar-bing-day-title" class="ar-modal__title">{{ day(selected.date) }}</h2>
                <div class="ar-day-nav" role="group" aria-label="Switch day">
                  <button type="button" class="ar-day-nav__btn" :disabled="selectedDay <= 0" aria-label="Previous day" @click="stepDay(-1)">
                    <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 4l-4 4 4 4" /></svg>
                  </button>
                  <SelectMenu
                    class="ar-day-picker"
                    :model-value="String(selectedDay)"
                    :options="dayOptions"
                    aria-label="Jump to a day"
                    @update:model-value="selectedDay = Number($event)"
                  />
                  <button type="button" class="ar-day-nav__btn" :disabled="selectedDay >= trend.length - 1" aria-label="Next day" @click="stepDay(1)">
                    <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg>
                  </button>
                </div>
              </div>
              <!-- ⚠️ The lead promises a crawl picture. On a day Bing said
                   nothing about there isn't one, and promising it above a line
                   explaining the absence sets up the reader to look for numbers
                   that were never there. -->
              <p v-if="selected.reported" class="ar-modal__lead">
                Bing's crawl picture for this day — every answer your site gave its crawler,
                and where the index stood.
              </p>
              <p v-else class="ar-modal__lead">
                Bing had no crawl picture for this day. The days either side of it stand on
                their own.
              </p>
            </div>

            <div class="ar-modal__body">
              <div class="ar-modal__scroll">
                <!-- One story, full width — .ar-daybreak is the two-column grid
                     and a lone column in it packs everything left. -->
                <div class="ar-bing__daybody">
                  <!-- ⛔ A day Bing said nothing about must NOT print six zeros
                       under real headings. Every one of them would be a
                       measurement nobody made, and "Pages in the index 0" is
                       the worst of them: it says the site left the index. -->
                  <template v-if="!selected.reported">
                    <h3 class="ar-daybreak__h">Bing reported nothing for this day</h3>
                    <p class="ar-bing__silent">
                      Bing answered for {{ day(selected.date) }} without any numbers in it — no
                      index count, no crawl, no answers. That is a gap in Bing's own reporting,
                      not a day your site was missing from the index or went uncrawled. The days
                      either side of it stand on their own.
                    </p>
                  </template>
                  <template v-else>
                    <h3 class="ar-daybreak__h">Pages in the index <span class="ar-daybreak__n">{{ n(selected.inIndex) }}</span></h3>
                    <ul class="ar-act-rank">
                      <li v-for="r in dayRows" :key="r.label">
                        <span class="ar-act-rank__label">{{ r.label }}</span>
                        <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :class="{ 'ar-bing__bar--warn': r.warn }" :style="{ width: barPct(r.value) }"></span></span>
                        <span class="ar-act-rank__n" :class="{ 'ar-warn': r.warn }">{{ n(r.value) }}</span>
                      </li>
                    </ul>
                    <p class="ar-act-more-note">
                      Answers are Bing's own daily counts — one row per day, so there are no
                      per-request times here.
                    </p>
                  </template>
                </div>
              </div>
            </div>

            <div class="ar-modal__actions">
              <button type="button" class="ar-btn ar-btn--ghost" @click="closeDay">Close</button>
            </div>
          </div>
        </div>
      </transition>
    </Teleport>

    <!-- Rendered at body level like the activity feed's bubble — anchored inside
         the card it could be clipped. -->
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
  </div>
</template>
