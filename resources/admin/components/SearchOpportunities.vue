<script>
/**
 * Search Opportunities — the Visibility screen's Search view, lower half:
 * the same stored snapshot Search Performance reports, turned into a to-do
 * list. The report above answers "how did search go?"; this card answers
 * "what should I fix?" — one view, one arc, the numbers above the to-dos.
 *
 * Lived on Readiness until 1.36. It moved for the same reason the content
 * worklist moved to Findings: Readiness states headlines and hands over the
 * working-through. Readiness keeps an honest pointer card; the old
 * `{ tab: 'readiness', anchor: 'ar-group-search' }` address is aliased in
 * App.goTo, so every stale emitter still lands here.
 */
import { formatDate } from '../js/wpDate.js';

export default {
  name: 'SearchOpportunities',
  props: {
    // Whether the Search view is on screen. The card stays MOUNTED across
    // tab switches (App uses v-show), so anything fetched once at mount goes
    // stale — connect a search source in Settings and this must notice on
    // return.
    active: { type: Boolean, default: false },
    // The content worklist, for the "Also in Optimize" cross-flags — the
    // fallback when a report payload predates per-card optimize_flags.
    optimize: { type: Array, default: () => [] },
    api: { type: Object, required: true },
  },
  emits: ['flash', 'state'],
  data() {
    return {
      // Search Opportunities: null until the (lazy) fetch answers.
      search: null,
      searchPick: '', // The engine the owner chose; '' = let the server pick the richer one.
      busySearchIgnore: 0, // Post id mid-flight in the search worklist's own set-aside.
      // Which groups the owner has opened or closed by hand, keyed by the
      // group's own key — NEVER by index. This list re-reads on `polled` and
      // on every return, and the cards re-sort as ranks move, so an
      // index-keyed fold would reopen a different section each refresh.
      groupsOpen: {},
    };
  },
  computed: {
    // Which story the Search Opportunities section tells, or '' to stay absent:
    // no source connected at all → absent (the Settings card is where connecting
    // lives); connected but no numbers yet → collecting; numbers but nothing to
    // fix → clear; otherwise the worklist.
    searchState() {
      if (!this.search) return '';
      const s = this.search.sources || {};
      const connected = (s.bing && s.bing.connected) || (s.google && s.google.connected);
      if (!connected) return '';
      if (!this.search.source) return 'collecting';
      // Anything to SHOW, not anything to DO. searchOpps deliberately excludes
      // pages whose work is finished, which is right for the header and for
      // Readiness's pointer — but it is the wrong question here. A site whose
      // only remaining card was waiting fell through to "clear", the groups
      // never rendered, and the Findings row's "Look at that page" landed on a
      // screen that said nothing obvious was wrong. The button promised a page
      // and the destination hid it.
      if (this.searchOpps || this.searchWaiting) return 'ready';
      // Rows exist but every one is too thin to judge — a different fact from
      // "nothing to fix", and saying the wrong one is a lie by compliment.
      const r = this.search.report;
      return (r && r.judged === false) ? 'thin' : 'clear';
    },
    // The section shows whenever there is anything to act on — a worklist, an
    // honest state, or set-aside pages waiting to be restored (a held-back page
    // must never become invisible just because today's report is empty).
    hasSearchSection() {
      return !!this.searchState || this.searchAside.length > 0;
    },
    // What Readiness's pointer card needs to know, and nothing more: whether
    // this section would show at all, and the two counts its chip states.
    pointer() {
      return {
        show: this.hasSearchSection,
        count: this.searchOpps,
        aside: this.searchAside.length,
      };
    },
    searchCounts() {
      return (this.search && this.search.report && this.search.report.counts) || {};
    },
    // Searches several pages are splitting — the wire carries the heaviest
    // few, the total says what was held back.
    searchCollisions() {
      return (this.search && this.search.report && this.search.report.collisions) || [];
    },
    searchCollisionsTotal() {
      return (this.search && this.search.report && this.search.report.collisions_total) || 0;
    },
    // The search worklist's own held-back pages (never Optimize's).
    searchAside() {
      return (this.search && this.search.set_aside) || [];
    },
    // Pages the CONTENT worklist also flags, by id → the issue labels. Free:
    // the Optimize worklist is already a prop. When both lists point at one
    // page, saying so turns two separate chores into one visit.
    optimizeFlagsById() {
      const map = {};
      (this.optimize || []).forEach((issue) => {
        (issue.pages || []).forEach((p) => {
          if (!p || !p.id) return;
          if (!map[p.id]) map[p.id] = [];
          if (issue.label && map[p.id].indexOf(issue.label) === -1) map[p.id].push(issue.label);
        });
      });
      return map;
    },
    // Pages with something left to do. A finished page is subtracted, because
    // this number is what the header and Readiness's pointer both promise as
    // work — and a page whose owner-side work is done is not work, however long
    // its card stays up waiting for a report.
    searchOpps() {
      return Math.max(0, Number(this.searchCounts.opportunities || 0) - this.searchWaiting);
    },
    // Cards that are finished and waiting. Counted over the cards actually shown:
    // the server only judges the ones it sent, and a page past the display cap
    // has not been measured, so it is never claimed either way.
    searchWaiting() {
      const r = (this.search && this.search.report) || {};
      return [...(r.almost_there || []), ...(r.seen_not_chosen || [])].filter((c) => c && c.waiting).length;
    },
    searchMedian() {
      return (this.search && this.search.report && this.search.report.median_ctr) || 0;
    },
    // 'thin' (not enough page-one traffic reported) and 'unclicked' (plenty, none
    // clicked) are different facts about a site — never print one for the other.
    searchMedianReason() {
      return (this.search && this.search.report && this.search.report.median_reason) || '';
    },
    // The bar actually applied, not the median it's derived from.
    searchCtrBar() {
      return (this.search && this.search.report && this.search.report.ctr_bar) || 0;
    },
    searchMedianRows() {
      return (this.search && this.search.report && this.search.report.median_rows) || 0;
    },
    searchMedianNeeds() {
      return (this.search && this.search.report && this.search.report.median_needs) || 5;
    },
    // Share of the reported views that were search-operator probes, not people.
    // Above the threshold it is the most important fact on the screen: it is why
    // this worklist looks empty while Search Performance shows big numbers.
    searchNoiseShare() {
      const n = this.search && this.search.report && this.search.report.noise;
      return n ? Number(n.share) || 0 : 0;
    },
    showSearchNoise() {
      return this.searchNoiseShare >= 25;
    },
    searchNoiseExamples() {
      const n = this.search && this.search.report && this.search.report.noise;
      return (n && Array.isArray(n.examples)) ? n.examples : [];
    },
    searchNoiseCount() {
      const n = this.search && this.search.report && this.search.report.noise;
      return n ? Number(n.searches) || 0 : 0;
    },
    // >0 when the source samples pages rather than reporting all of them — the
    // worklist can only ever judge what was sampled, so it has to say so.
    searchPageCap() {
      const s = (this.search && this.search.sources) || {};
      const cur = s[this.searchSource];
      return cur ? Number(cur.pageCap) || 0 : 0;
    },
    searchSourceLabel() {
      return this.search && this.search.source === 'google' ? 'Google Search Console' : 'Bing Webmaster Tools';
    },
    searchSource() {
      return (this.search && this.search.source) || '';
    },
    // The window these numbers describe — "as of when" is part of trusting them.
    searchRange() {
      const r = this.search && this.search.range;
      if (!r || !r.start) return '';
      const d = (s) => formatDate(new Date(`${s}T00:00:00`));
      return `${d(r.start)} – ${d(r.end)}`;
    },
    // Both engines can answer — only then is there a choice worth offering.
    searchBothConnected() {
      const s = (this.search && this.search.sources) || {};
      return !!(s.bing && s.bing.hasData && s.google && s.google.hasData);
    },
    searchGroups() {
      const r = (this.search && this.search.report) || {};
      return [
        {
          key: 'almost',
          label: 'Almost on Page One',
          // The header chip repeats the SAME pill the rows below carry, so the
          // group explains its own rank chips before the reader meets them.
          chip: 'Rank 8–20',
          chipTone: 'is-two',
          total: (r.page_counts && r.page_counts.almost) || 0,
          why: 'These sit around rank 8–20. A small improvement can move them up.',
          // What to actually do, in the editor, in plain words. Without this the
          // card sends people to a screen full of passing checks and no next step.
          // Static in-house copy — rendered via v-html, so no user data may ever
          // land here. Bold marks ONLY the which-title/which-description
          // clarifiers (his call: emphasis is for naming the fields, not for
          // decorating the instructions).
          // Finished pages sink to the foot of their group. They keep their card
          // — the numbers are still the story — but nothing with work left in it
          // should ever sit below something with none.
          waitingLabel: 'Your side is done. The next report decides the rank.',
          todo: 'Open the post and make it answer this search more directly: use the words people typed in the title searchers see (<strong>the SEO title in the “Search & AI” box</strong>, or <strong>the post title</strong> when that field is empty) and in an early heading, and add a paragraph that answers it plainly. Then link to this post from your other posts — the “Link to your own posts” box in the editor sidebar suggests which ones. Fixing a page does not clear its card — the card leaves when a later report shows better numbers, usually after a few weeks.',
          cards: this.sinkWaiting(r.almost_there),
        },
        {
          key: 'seen',
          label: 'Seen, but Not Clicked',
          chip: 'Page one',
          chipTone: 'is-one',
          total: (r.page_counts && r.page_counts.seen) || 0,
          why: 'Already on page one, yet people scroll past. Usually the title or description.',
          waitingLabel: 'Your side is done. Whether people click is theirs to decide.',
          todo: 'Nothing is wrong with the page itself — people just aren’t picking it out of the results. In the editor, find the “Search & AI” box in the right-hand sidebar and rewrite two fields there: <strong>the SEO title</strong> and <strong>the AI description</strong>. That pair is exactly what a searcher reads before deciding to click. Fixing a page does not clear its card — the card leaves when a later report shows better numbers, usually after a few weeks.',
          cards: this.sinkWaiting(r.seen_not_chosen),
        },
      ];
    },
  },
  mounted() {
    this.loadSearch();
  },
  watch: {
    // Re-read the worklist on every reveal: a source connected (or a page set
    // aside) in another tab must show here without a page reload — the same
    // contract the Bing and Search Performance cards keep.
    active(on) {
      if (on) this.loadSearch();
    },
    // Readiness's pointer card renders from this — announced upward on every
    // change (and once at mount, so App never holds a stale null).
    pointer: {
      immediate: true,
      handler(v) {
        this.$emit('state', v);
      },
    },
  },
  methods: {
    // Finished cards to the foot of their group, order otherwise untouched (the
    // server ranks by impressions and that ranking is still right within each
    // half). Copied before sorting — sort() mutates, and these arrays belong to
    // the fetched report.
    sinkWaiting(cards) {
      return [...(cards || [])].sort((a, b) => (a.waiting ? 1 : 0) - (b.waiting ? 1 : 0));
    },
    // When a finished page joined the worklist. Absent until a poll has recorded
    // one — a page that was already waiting before the ledger existed has no
    // honest date, and today's would restart a clock that has run for weeks.
    waitingSince(card) {
      if (!card || !card.since) return '';
      return formatDate(new Date(Number(card.since) * 1000));
    },
    // Load the opportunities report — lazily, on mount, and again after a page is
    // set aside from here (the report itself changes).
    async loadSearch(source) {
      if (!this.api || !this.api.getSearchOpportunities) return;
      try {
        this.search = await this.api.getSearchOpportunities(source || this.searchPick);
      } catch (e) {
        this.search = null; // Absent beats a broken section.
      }
    },
    // Flip which engine's numbers the worklist is built from. Never merged: the
    // two count different searchers, so a blended list would be a number neither
    // engine reported.
    pickSearchSource(source) {
      if (this.searchPick === source) return;
      this.searchPick = source;
      this.loadSearch(source);
    },
    // The citability flags for one search card. The server grades each mapped
    // card's page directly now (optimize_flags — {@see Score::page_flags}), so
    // the badge no longer depends on the page sitting in Optimize's recency
    // A group with nothing left to ask for: every card in it has finished the
    // owner's side and is only waiting on the next report.
    groupWaiting(group) {
      return group.cards.length > 0 && group.cards.every((c) => c.waiting);
    },
    // Every group starts folded (his call, 2026-08-15): the section's own
    // heading and each group's count already say what is inside, and a screen
    // that opens as a list of headings can be read in one look. The owner's
    // own opening holds across re-reads.
    groupOpen(group) {
      const chosen = this.groupsOpen[group.key];
      return undefined === chosen ? false : chosen;
    },
    onGroupToggle(key, ev) {
      this.groupsOpen[key] = !!(ev.target && ev.target.open);
    },
    // sample; the worklist map stays as the fallback for report payloads
    // fetched before that field existed.
    cardFlags(card) {
      if (Array.isArray(card.optimize_flags)) return card.optimize_flags.length ? card.optimize_flags : null;
      const m = this.optimizeFlagsById[card.page_id];
      return m && m.length ? m : null;
    },
    // Set aside (or restore) a page in the SEARCH worklist. Its own list: this
    // says "don't suggest search fixes for this page", which is a different
    // judgement from Optimize's "don't grade this for quoting". The route
    // answers with the refreshed report, so no second round-trip.
    // `ident` is { post } for mapped pages, { url } for pages with no post
    // behind them (the homepage on some sites, an archive) — the ledger keys
    // differ, and the URL is the only identity an unmapped page has.
    async setAsideSearch(ident, ignored = true) {
      const busy = (ident && (ident.post || ident.url)) || 0;
      if (this.busySearchIgnore || !busy) return;
      this.busySearchIgnore = busy;
      try {
        this.search = await this.api.ignoreSearch(ident, ignored);
        // Announce the destination (owner, 2026-08-12): this action MOVES a
        // row to another card — set aside sinks it into the ledger fold,
        // restore returns it to its collision or opportunity card — and
        // without the shared jump-ring the move read as "put in the wrong
        // place": the row landed correctly, off-screen, unannounced.
        await this.$nextTick();
        const key = String(ident.post || ident.url || '');
        let els = [];
        if (ignored) {
          const fold = this.$el.querySelector('details.ar-setaside');
          if (fold && !fold.open) fold.open = true;
          await this.$nextTick();
          els = [...this.$el.querySelectorAll('[data-aside-key="' + CSS.escape(key) + '"]')];
        } else {
          // A page can live in TWO places here — its opportunity card and a
          // collision row — and restore returns it to both. Ring every home,
          // scroll to the topmost, or the return reads as a disappearance.
          els = [...this.$el.querySelectorAll('[data-page-key="' + CSS.escape(key) + '"]')];
        }
        if (els.length) {
          els[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
          els.forEach((el) => {
            el.classList.remove('ar-jump-flash');
            void el.offsetWidth; // restart the ring when the same row moves twice
            el.classList.add('ar-jump-flash');
            setTimeout(() => el.classList.remove('ar-jump-flash'), 1600);
          });
        }
      } catch (e) {
        this.$emit('flash', 'error', (e && e.message) || 'Could not update. Try again.');
      } finally {
        this.busySearchIgnore = 0;
      }
    },
  },
};
</script>

<template>
  <!-- Search Opportunities: the same worklist idea aimed at classic search —
       queries a page already ranks for but under-earns. Seated directly under
       Search Performance because both are the SAME stored snapshot: the report
       above is the record, this card is the to-do list carved from it. Present
       only once a search source is connected; its own honest states carry the
       rest. The id stays `ar-group-search` — it is this card's address, and it
       kept it through the move so every deep link still lands. -->
  <div v-if="hasSearchSection" id="ar-group-search" class="ar-card ar-opp-card">
    <!-- The same masthead grammar as Search Performance above — two cards on
         one view must read as siblings, not as tenants from different rooms.
         The workload count sits in the head's right column, the engine switch
         at its bottom edge (pill only when there is a real choice; one source
         is a bold name, nothing pretending to be pressable). -->
    <div class="ar-perf__head ar-card__head--ruled">
      <div>
        <h2 class="ar-card__title">Search Opportunities</h2>
        <!-- Covers BOTH groups: one is ranked too low to be clicked, the other
             is ranked well and passed over. Describing only the second would
             misname half the list. -->
        <p class="ar-card__lead">
          Pages already showing up in search that could earn more — either sitting just
          off page one, or on page one and being scrolled past. Each one lists what
          people typed to find it, from the same numbers reported above.
        </p>
      </div>
      <div v-if="searchOpps || searchWaiting || searchSource" class="ar-opp-card__side">
        <span v-if="searchOpps || searchWaiting" class="ar-opp-card__count">
          <template v-if="searchOpps">{{ searchOpps }} page{{ searchOpps === 1 ? '' : 's' }} to look at</template><template v-else>nothing to do</template><!--
          --><template v-if="searchWaiting"> · {{ searchWaiting }} waiting</template><template v-if="searchCounts.set_aside"> · {{ searchCounts.set_aside }} set aside</template>
        </span>
        <!-- Its own switch, deliberately, even with Search Performance's on the
             same view: the two cards fetch independently, and a switch that
             silently changed a card the reader wasn't looking at would be
             spooky action. -->
        <span v-if="searchBothConnected" class="ar-srcpick" role="group" aria-label="Show numbers from">
          <span class="ar-srcpick__set">
            <button type="button" class="ar-srcpick__btn" :class="{ 'is-on': searchSource === 'google' }" @click="pickSearchSource('google')">Google</button>
            <button type="button" class="ar-srcpick__btn" :class="{ 'is-on': searchSource === 'bing' }" @click="pickSearchSource('bing')">Bing</button>
          </span>
        </span>
        <span v-else-if="searchSource" class="ar-srcpick">
          <span class="ar-srcpick__solo">{{ searchSource === 'google' ? 'Google' : 'Bing' }}</span>
        </span>
      </div>
    </div>

    <!-- Whose numbers these are — said in plain words, always. -->
    <p v-if="searchState !== 'collecting'" class="ar-srcline ar-opp__srcline">
      These numbers come from <strong>{{ searchSourceLabel }}</strong><template v-if="searchRange">, covering {{ searchRange }}</template>.
    </p>
    <!-- The scope this worklist can see at all. Its own line, NOT appended to the
         provenance sentence above: that line is a flex row holding the engine
         switch, and a longer sentence wraps the switch onto a row of its own,
         stranding a control away from the numbers it changes. -->
    <p v-if="searchPageCap" class="ar-srcline ar-opp__scope">
      {{ searchSourceLabel }} has no single report pairing searches with pages, so
      Agentimus fetches that page by page — for your {{ searchPageCap }} busiest pages,
      to keep the daily check small. This worklist can only see those.
    </p>

    <!-- When most of the reported demand is machines, that IS the finding, and
         it comes first: it explains both an empty worklist and why Search
         Performance — which keeps the raw record — shows bigger numbers. -->
    <p v-if="showSearchNoise" class="ar-checkgroup__blurb ar-opp__state ar-opp__noise">
      <strong>{{ searchNoiseShare }}% of the views {{ searchSourceLabel }} recorded on your
      pages weren’t people.</strong> They came from automated <code>site:</code> and
      <code>intext:</code> probes — scrapers and SEO tools running bulk searches. Those
      are left out of everything below, because no title rewrite makes a scraper click.
      Search Performance above shows the raw record including them, which is why its
      numbers are larger than what’s judged here.
    </p>
    <!-- The filter, auditable. If it ever swallows a real search, this is where
         the owner sees it — a percentage can only be believed, a list can be read.
         Shown whenever ANYTHING was dropped, not only above the note's threshold:
         the card totals below exclude these, so a single 5-view probe makes a page
         read 2,139 here and 2,144 on Search Performance. Under 25% the paragraph
         above stays hidden, and this pill would be the only way to reconcile that. -->
    <details v-if="searchNoiseExamples.length" class="ar-fold ar-opp__noiselist">
      <summary>See what was left out ({{ searchNoiseCount }} search{{ searchNoiseCount === 1 ? '' : 'es' }})</summary>
      <!-- Own scroll container: this table carries a 520px floor, and a long
           operator string must never push the page sideways on a phone. -->
      <div class="ar-opp__qwrap">
        <table class="ar-opp__queries">
          <thead>
            <!-- The column has to name its basis. These are views summed across
                 every page the search touched, so the figure is legitimately
                 larger than the single site-wide number Search Performance shows
                 for the same search — and without a header that reads as a
                 contradiction. -->
            <tr><th>Search left out</th><th>Views across your pages</th></tr>
          </thead>
          <tbody>
            <tr v-for="(ex, i) in searchNoiseExamples" :key="i">
              <td class="ar-opp__q">{{ ex.query }}</td>
              <td class="ar-opp__num">{{ ex.impressions.toLocaleString() }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="ar-opp__noisefoot">
        <template v-if="searchNoiseExamples.length > 1">Showing the {{ searchNoiseExamples.length }} biggest.</template>
        A search is only dropped when it uses an operator — <code>site:</code>
        <code>intext:</code> <code>inurl:</code> <code>filetype:</code> and the
        like — the way machines search, not people.
      </p>
    </details>

    <!-- Honest states, in the order a site meets them. -->
    <p v-if="searchState === 'collecting'" class="ar-checkgroup__blurb ar-opp__state">
      Connected, no numbers yet. Search engines report on a delay — the first
      window usually lands within a day or two, and this fills itself.
    </p>
    <p v-else-if="searchState === 'clear'" class="ar-checkgroup__blurb ar-opp__state">
      <template v-if="searchMedian">
        Nothing obvious this window. Your pages are earning the clicks their
        rankings deserve. New queries land here as {{ searchSourceLabel }} reports them.
      </template>
      <template v-else-if="searchMedianReason === 'unclicked'">
        Nothing sits on page two waiting for a push. Click rates cannot be judged yet. Your
        page-one results are being shown but hardly clicked, so there is no normal click rate
        here to measure a page against. No page is called under-earning on a
        guess.<template v-if="searchBothConnected"> Switch above for the other engine’s view.</template>
      </template>
      <template v-else>
        Nothing on page two has enough searches behind it to be worth a push, and click
        rates can’t be judged yet:
        <template v-if="searchMedianRows">only {{ searchMedianRows }} of your pages
        {{ searchMedianRows === 1 ? 'gets' : 'get' }} enough real views on page one to
        measure</template><template v-else>none of your pages get enough real views on
        page one to measure</template>, and it takes
        {{ searchMedianNeeds }} before a normal click rate for this site can be worked out.
        That’s a shortage of measurable traffic, not a verdict on your
        pages.<template v-if="searchBothConnected"> Switch above for the other engine’s view.</template>
      </template>
    </p>
    <p v-else-if="searchState === 'thin'" class="ar-checkgroup__blurb ar-opp__state">
      Not enough search traffic here to judge yet. {{ searchSourceLabel }} is reporting
      your pages, but neither the individual searches nor the pages they add up to
      carry enough views to tell a real pattern from a coincidence — so Agentimus says
      nothing rather than sending you to rewrite a title over a handful of
      impressions.<template v-if="searchBothConnected"> Switch above for the other
      engine’s view.</template>
    </p>

    <template v-else-if="searchState === 'ready'">
      <template v-for="group in searchGroups" :key="group.key">
        <!-- The group's own heading IS its disclosure — ONE control for the
             whole section, not one per card: a group whose banner says
             "nothing to do here" is a stack of receipts, and twelve folds
             would be twelve clicks to answer one question. A group with a
             live ask opens itself, because there the searches ARE the
             instruction the ask points at. -->
        <details
          v-if="group.cards.length"
          class="ar-fold ar-opp__group"
          :open="groupOpen(group)"
          @toggle="onGroupToggle(group.key, $event)"
        >
          <!-- The Google-index groups' own summary, word for word: label, the
               count in parentheses, one bordered box. Closed, this line has to
               be enough to leave it closed — so a group with no ask left in it
               says that here rather than only inside. -->
          <!-- The label stays a real heading inside the summary (HTML allows
               exactly that): these groups are named sections of one card, and
               a section that reads as a heading on screen must be one in the
               outline too — the split-search finding below is an h4, and two
               siblings should not disagree about what they are. -->
          <summary>
            <h4 class="ar-opp__foldtitle">{{ group.label }} ({{ group.cards.length }} {{ group.cards.length === 1 ? 'page' : 'pages' }})</h4>
            <span class="ar-opp__pos" :class="group.chipTone">{{ group.chip }}</span>
            <span v-if="groupWaiting(group)" class="ar-opp__groupcount">nothing to do yet</span>
          </summary>
          <p class="ar-opp__groupwhy">{{ group.why }}</p>
          <!-- The instructions go when there is nobody left to instruct. With
               every card in the group finished, "What to do: open the post and
               make it answer this search more directly" sat directly on top of a
               card saying the owner's side is done — the same contradiction the
               waiting state removes, one level up, in bolder type. -->
          <p v-if="group.cards.some((c) => !c.waiting)" class="ar-opp__todo">
            <!-- eslint-disable-next-line vue/no-v-html — group.todo is our own static copy above, never user data -->
            <strong>What to do:</strong> <span v-html="group.todo"></span>
            <!-- Never let a cap pass for completeness. -->
            <template v-if="group.total > group.cards.length">
              Showing the {{ group.cards.length }} pages with the most impressions, of {{ group.total }} —
              fix these and the next ones move up.
            </template>
          </p>
          <p v-else class="ar-opp__todo ar-opp__todo--waiting">
            <strong>Nothing to do here.</strong> Every page in this group is answered, titled and
            linked — what is left is {{ group.key === 'seen' ? 'whether searchers click' : 'the rank' }},
            and the next report decides it. The cards clear themselves when the numbers improve.
          </p>
          <ul class="ar-checks ar-opp__list">
            <li
              v-for="card in group.cards"
              :key="group.key + card.page_url"
              class="ar-check ar-opp"
              :class="card.waiting ? 'is-waiting' : 'is-warn'"
              :data-page-key="card.page_id || card.page_url"
            >
              <span class="ar-check__rule" aria-hidden="true"></span>
              <div class="ar-opp__body">
                <div class="ar-opp__top">
                  <a v-if="card.edit_url" :href="card.edit_url" target="_blank" rel="noopener" class="ar-opp__title">{{ card.title }}</a>
                  <span v-else class="ar-opp__title">{{ card.title }}</span>
                  <code class="ar-opp__path">{{ card.path }}</code>
                  <!-- Both worklists pointing at one page is worth saying: it
                       turns two separate chores into a single visit. -->
                  <span v-if="cardFlags(card)" class="ar-opp__alsoflag">
                    Also in Optimize: {{ cardFlags(card).join(' · ').toLowerCase() }}
                  </span>
                </div>
                <!-- The same verdict the editor gives this page, said here too.
                     Without it the card reads as an open chore while the post's
                     own panel says there is nothing left to do — two screens
                     describing one page, disagreeing. The card stays because the
                     numbers are still worth reading; only the ask is withdrawn. -->
                <p v-if="card.waiting" class="ar-opp__waiting">
                  <span class="ar-opp__waiting-mark" aria-hidden="true">◐</span>
                  <span>{{ group.waitingLabel }}<template v-if="waitingSince(card)"> Waiting since {{ waitingSince(card) }}.</template></span>
                </p>
                <div class="ar-opp__qwrap">
                  <table class="ar-opp__queries">
                    <thead>
                      <tr>
                        <th>What people searched</th><th>Rank</th><th>Times shown</th><th>Visits</th><th>Click rate</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="q in card.queries" :key="q.query">
                        <td class="ar-opp__q">{{ q.query }}</td>
                        <td><span class="ar-opp__pos" :class="q.position < 8 ? 'is-one' : 'is-two'">{{ q.position }}</span></td>
                        <td class="ar-opp__num">{{ q.impressions.toLocaleString() }}</td>
                        <td class="ar-opp__num">{{ q.clicks.toLocaleString() }}</td>
                        <td class="ar-opp__num" :class="group.key === 'seen' ? 'is-low' : 'is-dim'">{{ q.ctr }}%</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <!-- A page can qualify on its TOTAL when no single search does
                     (an engine may split one page's demand across dozens of
                     long-tail searches). Say so, or the small numbers in the
                     table look like they can't add up to the verdict. -->
                <p v-if="card.whole_page" class="ar-opp__more">
                  Judged on this page’s totals — <strong>{{ card.impressions.toLocaleString() }}</strong> times shown
                  across {{ card.searches }} search{{ card.searches === 1 ? '' : 'es' }}, {{ card.clicks.toLocaleString() }} visit{{ card.clicks === 1 ? '' : 's' }},
                  average rank {{ card.position }}. No single search is big on its own; together they are.
                  The biggest are listed above.
                </p>
                <!-- Every other card states the page's WHOLE demand too. The rows
                     above are only the searches that qualified for this group, so
                     without this a card totalling 815 views sits beside a Search
                     Performance row reading 9,143 for the same page and looks like a
                     contradiction. Same figures as `whole_page` above — `bucket()`
                     always carries the page's full totals — only the framing differs,
                     because here a single search earned the place, not the total. -->
                <p v-else class="ar-opp__more">
                  This page in total — <strong>{{ card.impressions.toLocaleString() }}</strong> times shown
                  across {{ card.searches }} search{{ card.searches === 1 ? '' : 'es' }}, {{ card.clicks.toLocaleString() }} visit{{ card.clicks === 1 ? '' : 's' }},
                  average rank {{ card.position }}.
                  <template v-if="card.more">Above are the {{ card.queries.length }} biggest of
                    {{ card.queries.length + card.more }} searches</template><template v-else>Above
                    {{ card.queries.length === 1 ? 'is the search' : 'are the searches' }}</template>
                  where this page {{ group.key === 'seen' ? 'is passed over' : 'sits on page two' }}.
                </p>
                <!-- Each action names the fix and lands on the box that holds
                     it. New tab, like every page link in the worklists: this
                     screen is a checklist you work down, not a place to leave. -->
                <div class="ar-opp__actions">
                  <!-- A card with no post offers no editor doors — say why, or the
                       missing buttons read as a bug. Two cases, each told only the
                       truth that fits it: the HOMEPAGE's result title really is the
                       site title + tagline (that's what core prints for the front
                       page), so it gets that instruction and a real door to
                       Settings → General; any other doorless address (an archive, a
                       gone permalink) has no single lever, so no lever is named. -->
                  <span v-if="card.doorless === 'home'" class="ar-opp__noeditor">This is your homepage — searchers see your site title and tagline as its title; its description comes from your theme.</span>
                  <span v-else-if="card.doorless" class="ar-opp__noeditor">There is no post behind this address. WordPress builds this page from other content — an archive, or an address that no longer exists — so there is no editor to open.</span>
                  <!-- A finished card keeps a door and loses the instructions.
                       Every fix link lands ON a field, so it asks for an edit —
                       and "Add internal links" asks for the very lever that
                       finishing this page required. Offering them under the
                       words "your side is done" is the same contradiction this
                       state exists to remove, one level down. One neutral link,
                       naming a destination rather than a task — in the worklist's
                       own words for that destination ({@see ContentWorklist}), so
                       "open in editor" means the same thing on every screen. NOT
                       "open the post": every other link here goes to the editor,
                       and the one that reads like the front end must not. -->
                  <a v-if="card.waiting && card.open_url" :href="card.open_url" target="_blank" rel="noopener" class="ar-opp__edit">Open in editor</a>
                  <template v-if="!card.waiting">
                    <a v-if="card.doorless === 'home' && card.general_url" :href="card.general_url" target="_blank" rel="noopener" class="ar-opp__edit is-primary">Edit site title &amp; tagline</a>
                    <a v-if="card.edit_url" :href="card.edit_url" target="_blank" rel="noopener" class="ar-opp__edit is-primary">Improve meta title &amp; description</a>
                    <a v-if="group.key === 'almost' && card.links_url" :href="card.links_url" target="_blank" rel="noopener" class="ar-opp__edit">Add internal links</a>
                  </template>
                  <!-- Readability survives, waiting or not: it belongs to the
                       OTHER worklist, and that one really does still have work.
                       "Your side is done" was a statement about search. -->
                  <a v-if="cardFlags(card) && card.read_url" :href="card.read_url" target="_blank" rel="noopener" class="ar-opp__edit">Check readability</a>
                  <button
                    v-if="card.page_id || card.page_url"
                    type="button"
                    class="ar-optcheck__restore"
                    :disabled="busySearchIgnore === (card.page_id || card.page_url)"
                    @click="setAsideSearch(card.page_id ? { post: card.page_id } : { url: card.page_url }, true)"
                  >Set this aside</button>
                </div>
              </div>
            </li>
          </ul>
          <!-- The threshold belongs to the group it defines. Left outside the
               fold it explained “not clicked enough” under a CLOSED box, to a
               reader who could not see a single one of the pages it was
               qualifying. -->
          <p v-if="group.key === 'seen' && searchMedian" class="ar-card__note">
            “Not clicked enough” means a click rate below <strong>{{ searchCtrBar }}%</strong> —
            well under the <strong>{{ searchMedian }}%</strong> your own page-one results
            typically get. Both numbers are your site’s own, not an industry figure. And a page has
            to fall clearly short to be listed here, not merely below average.
          </p>
        </details>
      </template>
    </template>

    <!-- Searches several pages are SPLITTING. The engine can only send one
         search to one page at a time, so competing pages take turns — and
         every turn a weaker page takes is a click the strong one loses. The
         id is the Findings row's landing. The winner is STATED, not asked:
         most clicks, then the better position — the same maths the detector
         ranks by — and it gets no button, because there is nothing to do to
         the page that already earns the click. Renders outside the ready
         branch on purpose: a worklist can be clear while a split still
         costs clicks. -->
    <div v-if="searchCollisions.length" id="ar-collisions" class="ar-clsn">
      <!-- This is NOT one of the folds above it and must not read as one: the
           folds are groups of a worklist, this is a finding with its own
           landing from the Findings screen, and every row in it carries a
           live decision. So it wears its own head — a rule, a mark, and the
           title in the section register. The mark is the thing itself: one
           search forking to two pages. -->
      <div class="ar-clsn__intro">
      <div class="ar-clsn__band">
        <span class="ar-clsn__mark" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2.6 12h5.2" />
            <path d="M7.8 12c4.4 0 3.2-5.6 7.6-5.6" />
            <path d="M7.8 12c4.4 0 3.2 5.6 7.6 5.6" />
            <circle cx="17.6" cy="6.4" r="2.4" />
            <circle cx="17.6" cy="17.6" r="2.4" />
          </svg>
        </span>
        <h4 class="ar-clsn__title">One Search, Several Answers</h4>
        <span class="ar-opp__pos is-two">{{ searchCollisions.length }} split search{{ searchCollisions.length === 1 ? '' : 'es' }}</span>
      </div>
      <p class="ar-clsn__why">
        These searches show more than one of your pages, and none of them wins the click. The
        clicks split between them, so each page ranks lower than one page would. Keep one page
        as the answer for each search. The others can point to it, or answer something else.
      </p>
      </div>

      <div v-for="c in searchCollisions" :key="c.query" class="ar-clsn__card">
        <div class="ar-clsn__head">
          <span class="ar-clsn__q">“{{ c.query }}”</span>
          <span class="ar-clsn__tot">{{ c.shown.toLocaleString() }} shown · {{ c.clicks.toLocaleString() }} click{{ c.clicks === 1 ? '' : 's' }}</span>
        </div>
        <div v-for="p in c.pages" :key="p.url" class="ar-clsn__row" :data-page-key="p.postId || p.url">
          <div class="ar-clsn__main">
            <a :href="p.editUrl || p.url" target="_blank" rel="noopener" class="ar-clsn__t">{{ p.title }}</a>
            <span class="ar-clsn__path">{{ p.url.replace(/^https?:\/\/[^/]+/, '') || '/' }}</span>
          </div>
          <span class="ar-clsn__nums">
            <span><b>{{ p.impressions.toLocaleString() }}</b>shown</span>
            <span><b>{{ p.clicks.toLocaleString() }}</b>clicks</span>
            <span><b>#{{ p.position }}</b>position</span>
          </span>
          <span class="ar-clsn__bar" aria-hidden="true"><i :style="{ width: Math.round(p.share * 100) + '%' }"></i></span>
          <span v-if="p.winner" class="ar-clsn__win">Earns the click</span>
          <span v-else class="ar-clsn__acts">
            <a v-if="p.editUrl" class="ar-clsn__act" :href="p.editUrl" target="_blank" rel="noopener">Open to edit</a>
            <button
              type="button"
              class="ar-clsn__act"
              :disabled="busySearchIgnore === (p.postId || p.url)"
              @click="setAsideSearch(p.postId ? { post: p.postId } : { url: p.url }, true)"
            >Set this aside</button>
          </span>
        </div>
      </div>

      <p class="ar-card__note">
        Open a weaker page and do one of two things. Point it at the winner — the editor’s
        internal-link panel suggests the link — or make it answer something the winner does
        not. “Set this aside” moves the page to the ledger below, and it stops being counted
        against the search.
        <template v-if="searchCollisionsTotal > searchCollisions.length">
          Showing the {{ searchCollisions.length }} heaviest splits of {{ searchCollisionsTotal }}.
        </template>
      </p>
    </div>

    <!-- The search worklist's OWN set-aside list — always visible, one-click
         restore, so a page held back here is never hidden. Separate from
         Optimize's list on Readiness: different judgement, different ledger,
         each shown where it was made. -->
    <!-- FOLDED, exactly as Readiness folds its own set-aside list. This one has
         no ceiling — every page ever set aside stays in it — so open it grows a
         full-width row at a time until the work above it is off the screen. The
         COUNT stays visible, because a ledger nobody can see is how pages get
         quietly excluded forever; the rows are a reference, read on the rare
         visit that restores something. Same classes as the Readiness fold on
         purpose: two lists doing one job must not look like two ideas. -->
    <details v-if="searchAside.length" class="ar-fold ar-setaside">
      <summary class="ar-setaside__head">
        <strong class="ar-setaside__title">Set aside from search <span class="ar-optcheck__n">· {{ searchAside.length }}</span></strong>
        <span class="ar-setaside__note">no search suggestions, from either engine</span>
      </summary>
      <ul class="ar-optcheck__pages">
        <li v-for="p in searchAside" :key="p.id || p.url" class="ar-optcheck__row" :data-aside-key="p.id || p.url">
          <div class="ar-optcheck__asided">
            <a :href="p.url" target="_blank" rel="noopener" class="ar-optcheck__page ar-optcheck__page--muted">{{ p.title }}</a>
          </div>
          <button
            type="button"
            class="ar-optcheck__restore"
            :disabled="busySearchIgnore === (p.id || p.url)"
            @click="setAsideSearch(p.id ? { post: p.id } : { url: p.url }, false)"
          >Restore</button>
        </li>
      </ul>
    </details>
  </div>
</template>
