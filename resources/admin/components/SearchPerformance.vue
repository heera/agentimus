<script>
/**
 * Search Performance — the Visibility screen's Search view: what the
 * site earned in the window, the queries that brought it, and the pages that
 * did the earning. People, counted by the engines themselves.
 *
 * The counterpart to Search Opportunities, seated directly below on this same
 * view: the same stored snapshot, the opposite lens. This card answers "how am
 * I doing?"; that one answers "what should I fix?" — the numbers above the
 * to-dos.
 *
 * Off state = one quiet pointer at Settings → Data Sources. No form here: the
 * keys have exactly one home.
 */
import { formatDate } from '../js/wpDate.js';
import RefreshCrank from './RefreshCrank.vue';

export default {
  name: 'SearchPerformance',
  components: { RefreshCrank },
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show, so it stays mounted across tab switches.
    active: { type: Boolean, default: false },
  },
  emits: ['navigate'],
  data() {
    return {
      data: null,
      loading: false,
      loaded: false,
      // Asking the engine again, which is a slower thing than reading — kept
      // apart from `loading` so the control can say which one is happening.
      polling: false,
      error: '',
      source: '', // The source the owner picked, '' = let the server choose.
    };
  },
  computed: {
    sources() {
      return (this.data && this.data.sources) || {};
    },
    // Whose numbers are on screen — so the control can name the engine it is
    // about to go and ask, rather than saying "refresh" and leaving the owner
    // to guess which of the two it means.
    engineName() {
      const active = this.source || (this.data && this.data.source) || '';
      if ('bing' === active) return 'Bing';
      if ('google' === active) return 'Google';
      return 'the search engine';
    },
    anyConnected() {
      const s = this.sources;
      return !!((s.bing && s.bing.connected) || (s.google && s.google.connected));
    },
    bothConnected() {
      const s = this.sources;
      return !!(s.bing && s.bing.hasData && s.google && s.google.hasData);
    },
    active_source() {
      return (this.data && this.data.source) || '';
    },
    sourceLabel() {
      return this.active_source === 'google' ? 'Google Search Console' : 'Bing Webmaster Tools';
    },
    totals() {
      return (this.data && this.data.performance && this.data.performance.totals) || null;
    },
    topQueries() {
      return (this.data && this.data.performance && this.data.performance.top_queries) || [];
    },
    hasProbes() {
      return this.topQueries.some((q) => q.is_probe);
    },
    probeShare() {
      return Number(this.totals && this.totals.probeShare) || 0;
    },
    // Totals behind each top list. A capped list that never says it is capped
    // reads as the whole picture — the same rule the worklist follows.
    counts() {
      return (this.data && this.data.performance && this.data.performance.counts) || { queries: 0, pages: 0 };
    },
    // >0 when the source only reports page detail for its busiest pages, which
    // scopes every page figure on this screen and in the worklist.
    pageCap() {
      const s = this.sources[this.active_source];
      return s ? Number(s.pageCap) || 0 : 0;
    },
    topPages() {
      return (this.data && this.data.performance && this.data.performance.top_pages) || [];
    },
    rangeText() {
      const r = this.data && this.data.range;
      if (!r || !r.start) return '';
      const d = (s) => formatDate(new Date(`${s}T00:00:00`));
      return `${d(r.start)} – ${d(r.end)}`;
    },
    trend() {
      return (this.data && this.data.trend) || null;
    },
    // "Reported days", not "days": the window ends where Search Console's
    // numbers are final, two days back — the provenance line names the dates.
    weeklyLine() {
      const w = this.trend && this.trend.weekly;
      if (!w || !w.ready) return '';
      let line = `The last 7 reported days: shown ${this.num(w.thisWeek.impressions)} times, ${this.num(w.thisWeek.clicks)} visits — the 7 days before: ${this.num(w.lastWeek.impressions)} and ${this.num(w.lastWeek.clicks)}.`;
      // Last-good data must not pose as current: past ~3 days without a
      // successful refresh, the trend says its own age.
      const at = Number(this.trend.updatedAt) || 0;
      if (at && Date.now() / 1000 - at > 3 * 86400) {
        line += ` (Trend last refreshed ${formatDate(new Date(at * 1000))} — recent daily polls couldn't update it.)`;
      }
      return line;
    },
    discoverLine() {
      const d = this.trend && this.trend.discover;
      if (!d || !d.impressions) return '';
      return `Google Discover also showed your pages ${this.num(d.impressions)} times this window${d.clicks ? ` (${this.num(d.clicks)} visits)` : ''} — that’s Google’s feed, separate from search.`;
    },
  },
  watch: {
    // Fetch on every reveal: a connect or an overnight poll must show without
    // a page reload (same contract as the Bing card beside it).
    active(on) {
      if (on) this.load();
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    async load() {
      if (this.loading || !this.api || !this.api.getSearchPerformance) return;
      this.loading = true;
      this.error = '';
      try {
        this.data = await this.api.getSearchPerformance(this.source);
      } catch (e) {
        this.error = (e && e.message) || 'Could not read search performance.';
      } finally {
        this.loading = false;
        this.loaded = true;
      }
    },
    // Ask the engine again, then read what it said.
    //
    // ⚠️ This control used to re-READ the stored snapshot and nothing more —
    // and for Google there was no way to re-poll at all short of reconnecting
    // the key, so a card whose numbers had quietly stopped updating offered a
    // button that could not fix it. It polls the ACTIVE source now: whichever
    // engine's numbers you are looking at is the one that gets asked.
    async askAgain() {
      if (this.polling || this.loading || !this.api) return;
      const asked = this.source;
      this.polling = true;
      this.error = '';
      try {
        if ('bing' === asked && this.api.refreshBingSummary) await this.api.refreshBingSummary(30);
        else if (this.api.refreshGoogleSearch) await this.api.refreshGoogleSearch();
      } catch (e) {
        this.error = (e && e.message) || 'Could not ask the search engine again.';
      } finally {
        this.polling = false;
      }
      // Read either way: a failed poll leaves the previous snapshot standing,
      // and the card should still show it rather than go blank.
      await this.load();
    },
    pick(source) {
      if (this.source === source) return;
      this.source = source;
      this.load();
    },
    num(n) {
      return Number(n || 0).toLocaleString();
    },
  },
};
</script>

<template>
  <section class="ar-card ar-perf">
    <!-- head--ruled: the masthead rule spans the CARD, not the lead's text
         column — a rule that stops mid-card reads as broken, not typographic. -->
    <div class="ar-perf__head ar-card__head--ruled">
      <div>
        <div class="ar-card__titlewrap">
          <h2 class="ar-card__title">Search Performance</h2>
          <!-- The hand-crank half of the freshness rule (reveal already
               refetches): re-reads the stored report on demand. -->
          <RefreshCrank
            :busy="polling || loading"
            :aria-label="polling ? `Asking ${engineName} again…` : `Ask ${engineName} for its latest numbers`"
            :title="polling ? `Asking ${engineName} again…` : `Ask ${engineName} again — this fetches fresh numbers, so it takes a moment`"
            @refresh="askAgain"
          />
        </div>
        <p class="ar-card__lead">
          How your pages did in search: what was searched for, how often you showed up,
          and how often those results were clicked. Every number here is reported by the
          search engine itself — nothing is estimated.
        </p>
      </div>
      <!-- The source switch appears only when there is a real choice to make;
           with ONE source the same seat holds a static label — the engine
           must be named where the eye looks for it, not only in the
           provenance line at the bottom. A label is not a button: no hover,
           no affordance, nothing pretending to be pressable. -->
      <!-- No written label: the two engine names ARE the explanation, and the
           group keeps "Show numbers from" for screen readers. Seated at the
           head's bottom edge, just above the masthead rule. -->
      <div v-if="bothConnected" class="ar-srcpick" role="group" aria-label="Show numbers from">
        <span class="ar-srcpick__set">
          <button type="button" class="ar-srcpick__btn" :class="{ 'is-on': active_source === 'google' }" @click="pick('google')">Google</button>
          <button type="button" class="ar-srcpick__btn" :class="{ 'is-on': active_source === 'bing' }" @click="pick('bing')">Bing</button>
        </span>
      </div>
      <!-- One source: just its name, bold, in the switch's seat — no pill,
           because a pill is the switch's costume and there is nothing here
           to press. -->
      <div v-else-if="active_source" class="ar-srcpick">
        <span class="ar-srcpick__solo">{{ active_source === 'google' ? 'Google' : 'Bing' }}</span>
      </div>
    </div>

    <p v-if="error" class="ar-field__hint ar-warn">{{ error }}</p>

    <!-- First fetch in flight: sketch the real layout (tiles, provenance,
         two columns) so the card says "coming" instead of ending at its own
         header. Only the FIRST load — a refetch keeps yesterday's numbers on
         screen until the new ones land. -->
    <div v-else-if="!loaded" role="status" aria-label="Loading search performance">
      <div class="ar-skel-tiles" aria-hidden="true">
        <span class="ar-skel ar-skel--tile"></span>
        <span class="ar-skel ar-skel--tile"></span>
        <span class="ar-skel ar-skel--tile"></span>
        <span class="ar-skel ar-skel--tile"></span>
      </div>
      <div class="ar-skel-lines" aria-hidden="true">
        <span class="ar-skel ar-skel--line" style="width: 64%"></span>
        <span class="ar-skel ar-skel--line" style="width: 46%"></span>
      </div>
      <div class="ar-skel-cols" aria-hidden="true">
        <div class="ar-skel-lines">
          <span class="ar-skel ar-skel--line" style="width: 40%"></span>
          <span class="ar-skel ar-skel--line"></span>
          <span class="ar-skel ar-skel--line" style="width: 92%"></span>
          <span class="ar-skel ar-skel--line" style="width: 85%"></span>
        </div>
        <div class="ar-skel-lines">
          <span class="ar-skel ar-skel--line" style="width: 40%"></span>
          <span class="ar-skel ar-skel--line"></span>
          <span class="ar-skel ar-skel--line" style="width: 92%"></span>
          <span class="ar-skel ar-skel--line" style="width: 85%"></span>
        </div>
      </div>
    </div>

    <!-- Nothing connected: one pointer, no form. -->
    <p v-else-if="loaded && !anyConnected" class="ar-perf__empty">
      Connect <strong>Google Search Console</strong> or <strong>Bing Webmaster Tools</strong> under
      <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'settings', anchor: 'ar-sec-google' })">Settings → Data Sources</button>
      and this fills itself — read-only, one daily poll, stored in your own database.
    </p>

    <!-- Connected, but the engine hasn't reported yet. -->
    <p v-else-if="loaded && !active_source" class="ar-perf__empty">
      Connected — no numbers yet. Search engines report on a delay, so the first
      window usually lands within a day or two.
    </p>

    <template v-else-if="totals">
      <div class="ar-perf__tiles">
        <div class="ar-perf__tile">
          <span class="ar-perf__label">Times shown</span>
          <span class="ar-perf__num">{{ num(totals.impressions) }}</span>
          <span class="ar-perf__hint">your pages appeared in results</span>
        </div>
        <div class="ar-perf__tile">
          <span class="ar-perf__label">Visits</span>
          <span class="ar-perf__num">{{ num(totals.clicks) }}</span>
          <span class="ar-perf__hint">someone clicked through</span>
        </div>
        <div class="ar-perf__tile">
          <span class="ar-perf__label">Click rate</span>
          <span class="ar-perf__num">{{ totals.ctr }}%</span>
          <span class="ar-perf__hint">of everyone who saw you</span>
        </div>
        <div class="ar-perf__tile">
          <span class="ar-perf__label">Average rank</span>
          <span class="ar-perf__num">{{ totals.position }}</span>
          <span class="ar-perf__hint">1 is the top result</span>
        </div>
      </div>
      <!-- The tiles are the engine's own totals and stay untouched. But "of the
           people who saw you" is only true if the views were people, so when a
           large share weren't, that has to be said next to the number. -->
      <p v-if="probeShare >= 25" class="ar-srcline ar-perf__probeline">
        <strong>{{ probeShare }}% of these views came from automated probes</strong>, not
        people — so the click rate above is lower than the rate real visitors give you.
        Nothing has been removed here; this card is the engine’s raw record. The
        <em>Search Opportunities</em> worklist below judges people-only traffic.
      </p>
      <!-- Both engines: the week-on-week line renders ONLY once 14 days of
           history exist (weekly.ready) — zeros that mean "unknown" must never
           print as "nothing happened". Google's series comes from Search
           Console's date split, Bing's from its daily traffic report; one
           payload shape, so this line needs no idea which. Discover renders
           only when nonzero: it is Google's feed, Bing has no equivalent, and
           most sites honestly sit at 0 — silence beats a zero tile. -->
      <p v-if="weeklyLine" class="ar-srcline ar-perf__trend">{{ weeklyLine }}</p>
      <p v-if="discoverLine" class="ar-srcline ar-perf__trend">{{ discoverLine }}</p>
      <p class="ar-srcline">
        These numbers come from <strong>{{ sourceLabel }}</strong><template v-if="rangeText">, covering {{ rangeText }}</template>.
        <!-- "This CARD", not "this screen". True when Search Performance stood
             alone; still right now that it holds the Search view, because
             the In-the-index view next door counts machines and nothing else.
             A sentence that claims the whole screen for one audience is the
             same over-reach the AI filter had. And the machines' home is the
             Request Log — Visitors counts PEOPLE an assistant sent, and naming
             it here as a machine screen was this card's own quiet mis-claim. -->
        This card counts <strong>people</strong> using classic search — machines reading your
        site live on the Request Log screen<template v-if="probeShare > 0"> (and when automated probes
        sneak into {{ sourceLabel }}'s own numbers, this card says so right beside them)</template>.
        <template v-if="bothConnected">Switch above to see what {{ active_source === 'google' ? 'Bing' : 'Google' }} reported instead — the two count different searchers, so they never match exactly.</template>
      </p>

      <div class="ar-perf__cols">
        <div class="ar-perf__col">
          <p class="ar-perf__eyebrow">What was searched for</p>
          <table class="ar-perf__table">
            <thead>
              <tr><th>Search</th><th>Shown</th><th>Visits</th><th>Rank</th></tr>
            </thead>
            <tbody>
              <tr v-for="q in topQueries" :key="q.query">
                <td class="ar-perf__q">
                  {{ q.query }}
                  <!-- Named for what it is. The row stays — this screen is the raw
                       record — but it must not pass for a person's question. -->
                  <span v-if="q.is_probe" class="ar-perf__probe">probe</span>
                </td>
                <td class="ar-perf__n">{{ num(q.impressions) }}</td>
                <td class="ar-perf__n">{{ num(q.clicks) }}</td>
                <td class="ar-perf__n ar-perf__n--dim">{{ q.position }}</td>
              </tr>
              <tr v-if="!topQueries.length"><td colspan="4" class="ar-perf__none">No queries in this window.</td></tr>
            </tbody>
          </table>
          <p v-if="counts.queries > topQueries.length" class="ar-perf__foot">
            Showing the {{ topQueries.length }} most-shown of
            {{ num(counts.queries) }} searches.
          </p>
          <p v-if="hasProbes" class="ar-perf__foot">
            Rows marked <span class="ar-perf__probe">probe</span> are search operators —
            <code>site:</code>, <code>intext:</code> and the like — run in bulk by scrapers
            and SEO tools. They are real results the engine reported, so they stay here, but
            nobody was ever going to click them.
          </p>
        </div>

        <div class="ar-perf__col">
          <p class="ar-perf__eyebrow">Which pages they found</p>
          <table class="ar-perf__table">
            <thead>
              <tr><th>Page</th><th>Shown</th><th>Visits</th><th>Rank</th></tr>
            </thead>
            <tbody>
              <tr v-for="p in topPages" :key="p.page_url">
                <td class="ar-perf__q">
                  <a v-if="p.edit_url" :href="p.edit_url" class="ar-perf__page">{{ p.title }}</a>
                  <span v-else>{{ p.title }}</span>
                </td>
                <td class="ar-perf__n">{{ num(p.impressions) }}</td>
                <td class="ar-perf__n">{{ num(p.clicks) }}</td>
                <td class="ar-perf__n ar-perf__n--dim">{{ p.position }}</td>
              </tr>
              <tr v-if="!topPages.length">
                <td colspan="4" class="ar-perf__none">
                  This source reports queries without naming the page.
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="counts.pages > topPages.length" class="ar-perf__foot">
            Showing the {{ topPages.length }} most-shown of {{ num(counts.pages) }} pages.
          </p>
          <!-- The cap is OURS, not the engine's: Bing has no report pairing searches
               with pages, so each page costs its own request and the poll takes the
               busiest few. Blaming the engine for our own budget would be a lie. -->
          <p v-if="pageCap" class="ar-perf__foot">
            {{ sourceLabel }} has no single report pairing searches with pages, so
            Agentimus fetches that page by page — for your {{ pageCap }} busiest pages,
            to keep the daily check small. Quieter pages can’t appear here however
            well they rank.
          </p>
          <!-- Split-report sources disagree with themselves: the tiles sum the
               engine's site-wide report, this column its per-page one, and the two
               never reconcile — a single page can out-count the Visits tile. Said
               here at the seam, and LOUD (the info-pin grammar, not a footnote):
               a reader who spots 18 > 16 must meet the explanation before blaming
               the plugin's arithmetic. Google can't clash (its totals are summed
               from these same rows), so this rides the split-source condition. -->
          <div v-if="pageCap" class="ar-edge-pin ar-edge-pin--info ar-perf__pin">
            <span class="ar-edge-pin__badge">Two reports</span>
            <p class="ar-edge-pin__body">
              The totals at the top come from {{ sourceLabel }}’s site-wide report, the
              page figures here from its per-page one — counted separately, never
              reconciled. So the two never agree exactly, and a busy page can even show
              more visits than the Visits tile. Neither is a miscount; both are exactly
              as reported.
            </p>
          </div>
        </div>
      </div>

      <!-- The worklist is a screen-mate now, not another room — the button
           just scrolls to it (same tab + anchor, goTo's own path). -->
      <p class="ar-card__note">
        This card is the whole record. What to improve — pages just off page one,
        and pages on page one being scrolled past — sits right below:
        <button type="button" class="ar-linkbtn" @click="$emit('navigate', { tab: 'visibility', view: 'performance', anchor: 'ar-group-search' })">Search Opportunities</button>
        turns these same numbers into a to-do list.
      </p>
    </template>
  </section>
</template>
