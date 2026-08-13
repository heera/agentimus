<script>
/**
 * Your content — the second half of Today.
 *
 * The findings card above answers "is anything wrong with my site?". This
 * answers "which of my things do I work on?", and it is deliberately on the
 * same screen rather than behind a tab of its own: the point of Today is that
 * there is ONE place to look, and a second destination would have re-created
 * the problem it exists to solve.
 *
 * One row per piece of content — a post, a page, or whatever custom types the
 * site publishes. Each row names its own type, because "Pages" is a lie on a
 * site whose content is Products or Recipes.
 *
 * Fetched on demand, not shipped with the page: every row parses a page.
 */
import { formatDate, relTimeShort } from '../js/wpDate.js';

export default {
  name: 'ContentWorklist',
  props: {
    // Only for /worklist/rows — the list itself stays App-owned and arrives
    // as `data`, so this panel cannot drift into loading two truths.
    api: { type: Object, default: null },
    data: { type: Object, default: () => ({ items: [], counts: {}, capped: false, total: 0, noSearchData: 0, searchState: '', engine: '', pageCap: 0, waiting: 0 }) },
    // Cheap counts from the boot payload — no page parsed. They let the opening
    // state say something true about the site instead of just offering a button.
    preview: { type: Object, default: () => ({ published: 0, withSearch: 0, setAside: 0, searchState: '', engine: '', pageCap: 0, waiting: 0 }) },
    loaded: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    settingAside: { type: Number, default: 0 },
    // { pages: [id], seq } — a finding handing over the exact rows it counted.
    pick: { type: Object, default: null },
    // The tab was away long enough that a post may have been edited elsewhere.
    stale: { type: Boolean, default: false },
  },
  emits: ['load', 'set-aside'],
  data() {
    return {
      // Post IDs a finding asked for. Null means "show everything".
      picked: null,
      // Rows fetched for a finding's pages that sit BELOW the shortlist the
      // list payload carries — without these, a handed-over page outside the
      // top rows filtered down to "Nothing in this view" and the finding's
      // button read as a lie. Cleared with the filter.
      extraRows: [],
      pickFetching: false,
      // Which rows are open. Ids, not a flag on the row, so a refetch cannot
      // fold a row somebody has deliberately opened.
      opened: [],
    };
  },
  watch: {
    // A finding handed over its pages. Load the list if it has not been read
    // yet — landing on an unloaded card after clicking "show me those pages"
    // reads as broken — then show exactly those rows.
    pick: {
      immediate: true,
      handler(next) {
        if (!next || !next.pages || !next.pages.length) return;
        this.picked = next.pages.slice();
        this.extraRows = [];
        if (!this.loaded) this.$emit('load');
        else this.resolvePicked();
        this.bringIntoView();
      },
    },
    // The rows arrive after the request. Scrolling only on the click landed
    // while this card was still its pre-load self and a different height, so
    // the browser cancelled the smooth scroll and nothing moved at all — the
    // button looked dead. Land again once the rows are actually here.
    loaded(now) {
      if (now && this.picked) {
        this.resolvePicked();
        this.bringIntoView();
      }
    },
  },
  computed: {
    items() {
      const base = Array.isArray(this.data.items) ? this.data.items : [];
      if (!this.extraRows.length) return base;
      // The finding's below-the-shortlist rows, appended once. Dedup by id:
      // a reload can promote a page into the shortlist while its fetched
      // twin is still here.
      const have = new Set(base.map((i) => i.id));
      return base.concat(this.extraRows.filter((r) => !have.has(r.id)));
    },
    counts() {
      return this.data.counts || {};
    },
    // How many pages the source behind these rows can speak about at all.
    // 0 means "every page" — Google reports query x page directly.
    pageCap() {
      return Number(this.data.pageCap) || 0;
    },
    engine() {
      return this.data.engine || '';
    },
    // How many of the site's pages this source has not been asked about yet.
    // Only ever above zero on Bing, which answers about one page per request.
    waiting() {
      return Number(this.data.waiting) || 0;
    },
    // Why a row's search column is empty, and the reasons are NOT the same.
    //
    // Google reports every page it knows about in one go, so an empty column
    // really does mean no search has reached that page — normal for a new post,
    // and it fills in on its own. Bing answers about one page per request, so
    // its pages are worked through a few at a time; while any are still waiting,
    // an empty column may only mean this page has not had its turn. Printing the
    // first sentence in the second case tells an owner their page is invisible
    // when it may be doing perfectly well.
    emptyWhy() {
      if (this.waiting > 0) {
        return `${this.engine || 'This source'} answers about one page at a time, and ${this.waiting.toLocaleString()} of your pages have not been checked yet — so this may only mean this one has not had its turn.`;
      }
      return 'No searches have reached this one yet.';
    },
    // Chips are exclusive and add up to the list, so the numbers can be trusted.
    tabs() {
      return [
        { key: 'fixable', label: 'Worth Fixing', n: this.counts.fixable || 0 },
        { key: 'clear', label: 'Nothing to Fix', n: this.counts.clear || 0 },
        { key: 'setAside', label: 'Set Aside', n: this.counts.setAside || 0 },
      ];
    },
    // Which tab the rows on screen belong to. The SERVER decides this now —
    // it ranks and pages over every published item, so the panel can no longer
    // sort a local pile into buckets and must simply show what it was sent.
    filter() {
      return this.data.filter || 'fixable';
    },
    page() {
      return Number(this.data.page) || 1;
    },
    per() {
      return Number(this.data.per) || 20;
    },
    pages() {
      return Math.max(1, Math.ceil((Number(this.data.total) || 0) / this.per));
    },
    // How many published items are still waiting to be read for the first time.
    // Above zero means this ranking covers part of the site, not all of it —
    // a different claim, and one the screen has to make out loud.
    grading() {
      return Number(this.data.grading) || 0;
    },
    shown() {
      // A finding's subset wins over the tab: it was chosen deliberately and
      // the pages in it may sit across two tabs.
      if (this.picked) {
        const want = this.picked;
        return this.items.filter((i) => want.indexOf(i.id) > -1);
      }
      return this.items;
    },
    // Said out loud rather than implied by a list that simply stops.
    foot() {
      const bits = [];
      if (this.grading > 0) {
        // The ranking is only as complete as the reading behind it. Saying so
        // costs one sentence; not saying it presents a partial list as the
        // whole site, which is the fault this screen exists to avoid.
        bits.push(
          `Still reading your content — ${this.grading.toLocaleString()} ${this.grading === 1 ? 'item has' : 'items have'} not been looked at yet, so this order may still change.`
        );
      }
      if (this.data.noSearchData) {
        // "Normal for recent posts" is true under Google and misleading while
        // Bing is still working through the site. Same number, two different
        // things for an owner to do about it.
        bits.push(
          this.waiting > 0
            ? `${this.data.noSearchData} have no search data yet. ${this.engine || 'This source'} is still working through your pages — ${this.waiting.toLocaleString()} have not been checked — so some of these are waiting rather than quiet. Their content issues are still real.`
            : `${this.data.noSearchData} have no search data yet — normal for recent posts, and they still show their content issues.`
        );
      }
      if ('not_connected' === this.data.searchState) {
        bits.push('No search source is connected, so no row can say what it is found for.');
      } else if ('collecting' === this.data.searchState) {
        bits.push('Search data is still collecting.');
      }
      return bits.join(' ');
    },
  },
  methods: {
    /* The per-word evidence belongs to the PRIMARY search — the one the
       coverage was measured against. Another search on the same page has a
       verdict but no word-by-word reading, so it prints as plain words. */
    showEverything() {
      this.picked = null;
      this.extraRows = [];
    },
    isOpen(i) {
      return this.opened.indexOf(i.id) > -1;
    },
    // The fold opens itself — details/summary is the browser's own control, and
    // reimplementing it would mean reimplementing its keyboard and screen-reader
    // behaviour too. This only follows along, so the body can stay out of the
    // DOM until a row is actually asked for.
    onToggle(i, ev) {
      const at = this.opened.indexOf(i.id);
      if (ev.target.open && at < 0) this.opened.push(i.id);
      if (!ev.target.open && at > -1) this.opened.splice(at, 1);
    },
    // What one engine says in the width of a glance. Never merged with the
    // other's — each keeps its own name in front of its own numbers.
    engineGist(e) {
      if ('reported' === e.state) {
        const top = e.rows && e.rows[0];
        if (!top) return 'no searches';
        return `${this.rank(top.position)} · ${this.num(top.impressions)} shown`;
      }
      if ('unasked' === e.state) return 'not asked yet';
      if ('none' === e.state) return 'no searches';
      if ('error' === e.state) return 'could not ask';
      return 'not connected';
    },
    // Each engine's OWN window and its own last-checked stamp. Bing's window is
    // anchored at its own newest week and can end days before Google's, so one
    // shared "last 56 days" across both was never true of both.
    // Just the age, with no "checked" in front of it — for sentences that
    // supply their own verb.
    askedAgo(e) {
      return e.checkedAt ? relTimeShort(e.checkedAt * 1000) : '';
    },
    engineWhen(e) {
      const bits = [];
      // ⚠️ These arrive as 'YYYY-MM-DD' strings and formatDate takes a DATE
      // OBJECT — handed anything else it returns '' rather than complaining,
      // so the range silently disappeared and left a lonely dash behind.
      // Read as UTC, because that is the day the engine stamped, not ours.
      const at = (d) => (d ? formatDate(new Date(`${d}T00:00:00Z`), true) : '');
      if (e.window && e.window.start && e.window.end) {
        const from = at(e.window.start);
        const to = at(e.window.end);
        if (from && to) bits.push(`${from} – ${to}`);
      }
      // ⚠️ relTimeShort already ends in "ago". Adding another one read
      // "checked 21h ago ago" on the live screen.
      if (e.checkedAt) {
        const rel = relTimeShort(e.checkedAt * 1000);
        if (rel) bits.push(`checked ${rel}`);
      }
      return bits.join(' · ');
    },
    // Only when both engines actually reported AND their top phrase differs.
    // Silence when they agree: there is no second thing to say.
    enginesDiffer(i) {
      const es = i.engines || {};
      const said = Object.keys(es)
        .filter((k) => 'reported' === es[k].state && es[k].rows && es[k].rows.length)
        .map((k) => ({ name: es[k].name, q: es[k].rows[0].query }));
      if (said.length < 2) return '';
      if (said[0].q === said[1].q) return '';
      return said.map((s) => `${s.name} shows it most for “${s.q}”.`).join(' ');
    },
    stateChip(i) {
      const n = (i.flags ? i.flags.length : 0) + (i.moreFlags || 0);
      if (!n) return 'nothing to fix';
      return `${n} ${1 === n ? 'thing' : 'things'} to fix`;
    },
    // A tab is now a different question for the server, not a different filter
    // over the same pile — so it costs a fetch, and always starts at page one.
    pickFilter(key) {
      if (key === this.filter && !this.picked) return;
      this.picked = null;
      this.extraRows = [];
      this.$emit('load', { filter: key, page: 1 });
    },
    goPage(n) {
      const want = Math.min(this.pages, Math.max(1, Number(n) || 1));
      if (want === this.page) return;
      this.$emit('load', { filter: this.filter, page: want });
    },
    // The window of page numbers worth drawing. A site with sixty pages of
    // content should not get sixty buttons — the ends and the neighbours are
    // what anyone actually clicks.
    pageWindow() {
      const out = [];
      const last = this.pages;
      const here = this.page;
      for (let n = 1; n <= last; n++) {
        if (n === 1 || n === last || Math.abs(n - here) <= 1) out.push(n);
        else if (out[out.length - 1] !== '…') out.push('…');
      }
      return out;
    },
    // Set aside / bring back, with one wrinkle: the parent flips the row's
    // state on success by finding it in ITS list — but a row fetched for a
    // finding (extraRows) is ours, not the parent's, and used to sit there
    // unchanged after the click, looking like the button did nothing. Flip
    // it here, optimistically: the parent toasts any failure, and the next
    // fetch corrects the rare miss.
    setAsideRow(i) {
      this.$emit('set-aside', { id: i.id, aside: !i.setAside });
      if (this.extraRows.some((r) => r.id === i.id)) i.setAside = !i.setAside;
    },
    // Only the numbers that mean something on this site. A stat reading "0"
    // teaches nothing and makes the row look broken.
    previewStats() {
      const p = this.preview || {};
      const out = [{ n: p.published || 0, label: p.published === 1 ? 'published item' : 'published items' }];
      if ('ready' === p.searchState) {
        // While pages are still waiting their turn, "already found in search"
        // would read as a verdict on the ones not yet checked. Name the source
        // instead, which claims nothing about the rest.
        const waiting = Number(p.waiting) || 0;
        out.push({
          n: p.withSearch || 0,
          label: waiting > 0 ? `reported by ${p.engine || 'your search source'} so far` : 'already found in search',
        });
      }
      if (p.setAside) {
        out.push({ n: p.setAside, label: 'set aside by you' });
      }
      return out;
    },
    needsWork(i) {
      if (i.flags && i.flags.length) return true;
      const s = i.coverage && i.coverage.state;
      return !!s && 'answered' !== s;
    },
    coverMark(state) {
      return { answered: '●', scattered: '◐', barely: '○', missing: '✕' }[state] || '';
    },
    coverLabel(state) {
      return { answered: 'Answered', scattered: 'Scattered', barely: 'Barely', missing: 'Missing' }[state] || '';
    },
    /* "Google mostly shows it for" — named from the row's own source, never
       assumed. A Bing-only site must not be told Google said it. */
    reportedLead(i) {
      const e = (i.focus.reported && i.focus.reported.engine) || '';
      return e ? `${e} mostly shows it for` : 'Search engines mostly show it for';
    },
    /* One shape for one search or four: the row's searches, each with its
       own verdict. */
    searches(i) {
      if (!i.focus) return [];
      if (i.focus.all && i.focus.all.length) return i.focus.all;
      return [{ query: i.focus.query, state: i.coverage ? i.coverage.state : '' }];
    },
    /* The search EXACTLY as it was typed, word by word, with the graded words
       carrying their reading. The terms array is NOT the phrase — it is what
       the grader kept (Coverage::words() drops stopwords), so rendering it as
       the search quietly rewrote "ai crawler blocking plugin" into "crawler
       blocking plugin". The phrase leads; the marks ride along. Only the
       PRIMARY search has readings — another search on the same page has a
       verdict but no word-by-word measurement, so it prints plain. */
    phraseWords(i, query) {
      const norm = (w) => String(w).toLowerCase().replace(/^[^\w-]+|[^\w-]+$/g, '');
      const primary = i.focus && query === i.focus.query;
      const reading = new Map();
      if (primary) {
        (((i.coverage || {}).terms) || []).forEach((t) => reading.set(norm(t.word), t));
      }
      return String(query || '').split(/\s+/).filter(Boolean).map((w, n) => {
        const t = reading.get(norm(w));
        return {
          key: n + ':' + w,
          word: w,
          // A word the grader never weighed (a stopword) is neither present
          // nor missing — it stays quiet rather than claiming either.
          cls: t ? this.wordClass(t) : 'is-plain',
          tip: t ? this.wordTip(t) : '',
        };
      });
    },
    wordClass(t) {
      return t.in_passage ? 'is-passage' : (t.on_page ? 'is-page' : 'is-absent');
    },
    wordTip(t) {
      return t.in_passage
        ? 'In the passage that answers best'
        : (t.on_page ? 'On the page, but not in that passage' : 'Not on the page');
    },
    coverDetail(i) {
      const c = i.coverage;
      if (!c || 'answered' !== c.state || !c.heading) return '';
      return this.coverWhy(i);
    },
    coverWhy(i) {
      const c = i.coverage;
      if (!c) return '';
      if ('answered' === c.state) {
        return c.heading ? `One passage carries it, under “${c.heading}”.` : 'One passage carries the whole search.';
      }
      if ('scattered' === c.state) {
        return 'Every word is on the page, but never together in one passage.';
      }
      if ('barely' === c.state) {
        return 'Some of the search is here, most of it isn’t.';
      }
      return 'None of it is on the page — either the wrong search for this one, or an opening.';
    },
    // Who decided this is the page's search, in as few words as will carry it.
    focusLabel(i) {
      if (i.focus.chosen) return 'You chose';
      // Name the engine only when we know which one reported it. Otherwise say
      // the true, vaguer thing — attributing a phrase to Google that may have
      // come from Bing is a small lie that costs trust in every number beside it.
      return i.focus.engine ? `${i.focus.engine} shows it for` : 'Search engines show it for';
    },
    // The verdict in one word, for a badge's title.
    stateWord(state) {
      return {
        answered: 'Answered by one passage',
        scattered: 'Words are on the page, never together',
        barely: 'Some of the words are here',
        missing: 'None of it is on the page',
      }[state] || '';
    },
    // Put this card under the sticky header. Instant, not smooth: a smooth
    // scroll is cancelled by the re-render that follows it, and an instant one
    // that lands is worth more than an animation that does not.
    bringIntoView() {
      this.$nextTick(() => {
        const el = this.$el;
        if (!el || !el.getBoundingClientRect) return;
        const sticky = document.querySelector('.ar__sticky');
        const gap = (sticky ? sticky.getBoundingClientRect().bottom : 0) + 12;
        const y = el.getBoundingClientRect().top + window.pageYOffset - gap;
        window.scrollTo({ top: Math.max(0, y), behavior: 'auto' });
      });
    },
    rank(n) {
      return `#${Number(n).toFixed(1)}`;
    },
    num(n) {
      return Number(n || 0).toLocaleString();
    },
    isSettingAside(i) {
      return this.settingAside === i.id;
    },
  },
};
</script>

<template>
  <!-- id, not just a class: findings navigate here by anchor, and jumpToAnchor
       resolves with getElementById. Without it the jump looked for 90 frames,
       found nothing and gave up silently — the click filtered the list and
       left the reader exactly where they were, which reads as a dead button. -->
  <div id="ar-work" class="ar-card ar-work">

    <div class="ar-work__head">
      <div>
        <h2 class="ar-work__title">Your Content</h2>
        <p class="ar-work__sub">
          One row per post, page or whatever else you publish — what it is found for,
          whether it answers that, and anything else it needs.
        </p>
      </div>
      <button
        v-if="loaded"
        type="button"
        class="ar-linkbtn"
        :disabled="busy"
        @click="$emit('load')"
      >{{ busy ? 'Checking…' : 'Check again' }}</button>
    </div>

    <!-- The opening state. It costs a page parse per row, so it is asked for —
         but it opens with what is already known, so the invitation is grounded
         in this site rather than being a bare button on an empty panel. -->
    <div v-if="!loaded" class="ar-work__intro">
      <svg class="ar-work__intro-mark" viewBox="0 0 48 48" width="44" height="44" fill="none"
           stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="8" y="5" width="26" height="34" rx="3" />
        <path d="M14 14h14M14 21h14M14 28h9" />
        <circle cx="33" cy="32" r="8.5" />
        <path d="M39.5 38.5 44 43" />
      </svg>

      <h3 class="ar-work__intro-title">See What Each Page Is Really For</h3>
      <p class="ar-work__intro-lead">
        Agentimus reads your content and tells you which search actually brings people
        to each piece — and whether that piece answers it.
      </p>

      <div class="ar-work__stats">
        <div v-for="st in previewStats()" :key="st.label" class="ar-work__stat">
          <span class="ar-work__stat-n">{{ st.n.toLocaleString() }}</span>
          <span class="ar-work__stat-l">{{ st.label }}</span>
        </div>
      </div>

      <button
        type="button"
        class="ar-btn ar-btn--reserve ar-work__go"
        data-reserve="Reading your content…"
        :disabled="busy"
        @click="$emit('load')"
      ><span class="ar-work__gotext">
          <!-- The read takes real seconds (every page is opened once), so the
               button says so with a turning mark, not only a changed word — a
               still button with new text reads as "nothing happened". -->
          <span class="ar-spinner ar-work__spin" :class="{ 'is-idle': !busy }" aria-hidden="true"></span>
          {{ busy ? 'Reading your content…' : 'Look at my content' }}
        </span></button>

      <p class="ar-work__intro-note">
        Takes a moment — every page is read once. Nothing is changed and nothing leaves your site.
      </p>
    </div>

    <template v-else>
      <!-- Editing happens in another tab. Coming back, the screen asks the
           database which of these posts have moved on — one indexed query, no
           page read — and re-reads only those. What it CANNOT fix by itself is
           the tab counts, which are computed over the whole site, so it says so
           rather than showing a total it is no longer sure of. -->
      <p v-if="stale && loaded" class="ar-work__stale">
        Pages you edited have been re-read. The counts above them cover the whole
        site, so those may still be behind.
        <button type="button" class="ar-linkbtn" :disabled="busy" @click="$emit('load')">
          {{ busy ? 'Reading…' : 'Read everything again' }}
        </button>
      </p>

      <!-- A filtered list has to say so and offer the way out. Silently showing
           4 of 30 rows is how somebody concludes the other 26 vanished — and a
           filter that comes up EMPTY has to say why, or the finding's button
           reads as a lie ("Showing the 0 pages" was heera.it's actual words). -->
      <p v-if="picked" class="ar-work__picked">
        <template v-if="pickFetching">Fetching those pages…</template>
        <template v-else-if="shown.length">
          Showing {{ shown.length === 1 ? 'the 1 page' : `the ${shown.length} pages` }} from that finding.
        </template>
        <template v-else>
          The pages that finding counted aren't here any more — re-graded clean,
          set aside, or no longer published. The finding catches up on its next check.
        </template>
        <button type="button" class="ar-linkbtn" @click="showEverything">Show everything</button>
      </p>

      <div v-else class="ar-work__tabs">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          class="ar-work__tab"
          :class="{ 'is-active': filter === t.key }"
          :disabled="busy"
          @click="pickFilter(t.key)"
        >{{ t.label }} <span class="ar-work__tab-n">{{ t.n }}</span></button>
      </div>

      <!-- ⛔ The three-column spine is gone. It existed because a row was three
           columns that had to be read across; a row is now ONE line that opens,
           so there is nothing to align and headings over it were furniture. -->

      <ul v-if="shown.length" class="ar-work__list">
        <li v-for="i in shown" :key="i.id" class="ar-work__row" :class="{ 'is-aside': i.setAside }">

          <!-- THE FOLD the rest of the plugin already uses (ar-fold): a native
               details/summary, its own drawn triangle, one bordered box per row.
               ⭐ His law — a caret is the fold's drawn triangle, never a glyph
               and never a hand-rolled one. Same furniture as the index groups,
               so the two screens read as one plugin. -->
          <details class="ar-fold ar-work__fold" :open="isOpen(i)" @toggle="onToggle(i, $event)">
            <summary>
              <span class="ar-work__summain">
                <!-- Kind and name on ONE line. Two lines for two words put a
                     near-empty row above every title. The kind wears a chip so
                     it separates itself — a punctuation mark between them was
                     doing a border's job. -->
                <span class="ar-work__title">
                  <span class="ar-work__type">{{ i.type }}</span>
                  <a class="ar-work__name" :href="i.edit || i.url" target="_blank" rel="noopener" @click.stop>{{ i.title }}</a>
                </span>

                <span class="ar-work__gist">
                <!-- The search prints as a phrase, because that is what it is —
                     words somebody typed — with each word carrying its own
                     reading in place. -->
                <span v-if="i.focus" class="ar-work__qline" :class="'is-' + (i.coverage && i.coverage.state || 'none')">
                  <span class="ar-work__qmark" aria-hidden="true" v-tip="stateWord(i.coverage && i.coverage.state)">{{ coverMark(i.coverage && i.coverage.state) }}</span>
                  <span
                    v-for="w in phraseWords(i, i.focus.query)"
                    :key="w.key"
                    class="ar-work__w"
                    :class="w.cls"
                  >{{ w.word }}</span>
                </span>
                <span v-else class="ar-work__nofocus">No focus keyword chosen</span>

                <template v-for="(e, key) in i.engines || {}">
                  <span v-if="e.state !== 'not_connected'" :key="key" class="ar-work__sep" aria-hidden="true">·</span>
                  <span v-if="e.state !== 'not_connected'" :key="key + '-e'" class="ar-work__eng" :class="{ 'is-quiet': e.state !== 'reported' }">
                    <b>{{ e.name }}</b> <span class="ar-work__eng-n">{{ engineGist(e) }}</span>
                  </span>
                </template>
                </span>
              </span>

              <span class="ar-work__state" :class="{ 'is-clear': !needsWork(i) }">{{ stateChip(i) }}</span>
            </summary>

          <!-- OPENED: everything known, each engine speaking only for itself. -->
          <div v-if="isOpen(i)" class="ar-work__body">

            <div v-if="i.focus" class="ar-work__block">
              <div class="ar-work__bhead">
                <h4>What this page is for</h4>
                <span class="ar-work__when">{{ focusLabel(i) }}</span>
              </div>
              <span
                v-for="f in searches(i)"
                :key="f.query"
                class="ar-work__qline ar-work__qline--big"
                :class="'is-' + (f.state || 'none')"
              >
                <span class="ar-work__qmark" aria-hidden="true" v-tip="stateWord(f.state)">{{ coverMark(f.state) }}</span>
                <span
                  v-for="w in phraseWords(i, f.query)"
                  :key="w.key"
                  class="ar-work__w"
                  :class="w.cls"
                  v-tip="w.tip"
                >{{ w.word }}</span>
              </span>
              <div class="ar-work__verdict">
                <span class="ar-work__cover" :class="'is-' + i.coverage.state" v-tip="coverWhy(i)">
                  <span class="ar-work__cover-t">{{ coverLabel(i.coverage.state) }}</span>
                </span>
                <span v-if="coverDetail(i)" class="ar-work__why">— {{ coverDetail(i) }}</span>
              </div>
            </div>

            <!-- ⛔ One block per engine, NEVER one merged figure. Two engines'
                 positions averaged together is a number neither reported. -->
            <div v-for="(e, key) in i.engines || {}" :key="'blk-' + key" class="ar-work__block">
              <div class="ar-work__bhead">
                <h4>What {{ e.name }} shows this page for</h4>
                <span class="ar-work__when">{{ engineWhen(e) }}</span>
              </div>

              <div v-if="e.state === 'reported'" class="ar-work__srchbox">
                <table class="ar-work__srch">
                  <tr v-for="(r, n) in e.rows" :key="r.query">
                    <td class="ar-work__srch-q" :class="{ 'is-top': n === 0 }">{{ r.query }}</td>
                    <td class="ar-work__srch-n ar-work__srch-r">{{ rank(r.position) }}</td>
                    <td class="ar-work__srch-n">{{ num(r.impressions) }} shown</td>
                    <td class="ar-work__srch-n">{{ num(r.clicks) }} {{ r.clicks === 1 ? 'visit' : 'visits' }}</td>
                  </tr>
                </table>
              </div>
              <p v-if="e.state === 'reported' && e.more" class="ar-work__more">
                {{ e.more }} more {{ e.more === 1 ? 'search' : 'searches' }} {{ e.name }} reports for this page.
              </p>

              <!-- The four honest absences. Sharing one blank is the fault this
                   whole screen was rebuilt to remove.
                   ⚠️ Its own v-if, NOT a v-else: chained to the line above it,
                   this fired whenever an engine HAD reported but had no extra
                   searches to count — printing "not connected" directly under
                   that engine's own numbers. -->
              <!-- An absence, in a box of its own with a mark that says WHICH
                   absence at a glance: a tick for a real answer that happens to
                   be empty, a warning for a question nobody has asked yet. The
                   two used to be one blank space, and one word of difference is
                   easy to skim past. -->
              <div v-if="e.state !== 'reported'" class="ar-work__answer" :class="'is-' + e.state">
                <span class="ar-work__answer-mark" aria-hidden="true">
                  <svg v-if="e.state === 'none'" width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="10" cy="10" r="8.25" /><path d="M6.4 10.2l2.4 2.4 4.8-4.8" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  <svg v-else width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="10" cy="10" r="8.25" /><path d="M10 5.9v4.9" stroke-linecap="round" /><circle cx="10" cy="13.8" r=".85" fill="currentColor" stroke="none" />
                  </svg>
                </span>
                <div class="ar-work__answer-t">
                  <template v-if="e.state === 'unasked'">
                    <b>{{ e.name }} has not been asked about this page yet.</b>
                    <span>{{ e.name }} answers about one page per request, so Agentimus works through
                    your pages a few at a time rather than all at once.<template v-if="waiting"> {{ waiting.toLocaleString() }} still to go.</template>
                    Nothing is missing from your site — the question simply has not been put yet.</span>
                  </template>
                  <template v-else-if="e.state === 'none'">
                    <b>{{ e.name }} reports no searches for this page.</b>
                    <!-- ⚠️ Read "it was asked checked 22h ago" on the live screen:
                         engineWhen() already begins with "checked". The stamp is
                         in the heading above anyway, so this says only when. -->
                    <span>That is a real answer, not a gap<template v-if="e.checkedAt"> — it was asked {{ askedAgo(e) }}</template>.</span>
                  </template>
                  <template v-else-if="e.state === 'error'">
                    <b>{{ e.name }} refused the question.</b>
                    <span><template v-if="e.error">It said: “{{ e.error }}”. </template>It will be asked again on the next check.</span>
                  </template>
                  <template v-else>
                    <b>{{ e.name }} is not connected.</b>
                    <span>It cannot say anything about this page until it is.</span>
                  </template>
                </div>
              </div>
            </div>

            <!-- A caveat about the data in front of you: the gold voice. -->
            <div v-if="enginesDiffer(i)" class="ar-work__note">
              <b>The two engines rank this page for different phrases.</b>
              {{ enginesDiffer(i) }} Both are real — they are different audiences, not a
              mistake, and neither number corrects the other.
            </div>

            <!-- Doing on the left, undoing on the right: two verbs that read
                 alike from a distance, so they are kept apart and each carries
                 its own mark. -->
            <div class="ar-work__act">
              <a v-if="i.edit" class="ar-linkbtn ar-work__do" :href="i.edit" target="_blank" rel="noopener">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                  <path d="M13.6 3.4l3 3L7.2 15.8l-3.7.7.7-3.7z" stroke-linejoin="round" />
                </svg>
                Open in editor
              </a>
              <button
                type="button"
                class="ar-linkbtn ar-linkbtn--mute ar-work__undo"
                :disabled="isSettingAside(i)"
                @click="setAsideRow(i)"
              >
                {{ isSettingAside(i) ? 'Saving…' : (i.setAside ? 'Put this back' : 'Set this aside') }}
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                  <circle cx="10" cy="10" r="7.25" /><path d="M7.6 7.6l4.8 4.8M12.4 7.6l-4.8 4.8" stroke-linecap="round" />
                </svg>
              </button>
            </div>
          </div>
          </details>

        </li>
      </ul>
      <!-- ⚠️ This v-else-if chains to the <ul> above it. Anything inserted
           between the two silently re-points it and the empty message starts
           appearing over a list full of rows. It has caught me three times in
           one day, so: nothing goes here. -->
      <p v-else-if="!picked" class="ar-work__empty">Nothing in this view yet — pages move here as their checks change, and the other tabs hold the rest of your content.</p>

      <!-- A closed row shows the gist and nothing else, which is the point —
           but a screen full of closed boxes has to say that opening one is
           worth doing, or nobody finds out what is inside. Once, under the
           list, not on every row. -->
      <p v-if="shown.length" class="ar-work__tip">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <circle cx="10" cy="10" r="8.25" /><path d="M10 9.2v4.6" stroke-linecap="round" /><circle cx="10" cy="6.4" r=".85" fill="currentColor" stroke="none" />
        </svg>
        Tip: open a row to see what each search engine shows for that page — or hasn’t been asked yet.
      </p>

      <!-- The list covers every published item now, so it can run to more than
           one screen. The range is spelled out on the left: a pager alone leaves
           an owner counting rows to work out where they are. -->
      <div v-if="!picked && shown.length && data.total > per" class="ar-work__pager">
        <p class="ar-work__pager-count">
          Showing {{ ((page - 1) * per + 1).toLocaleString() }}–{{ Math.min(page * per, data.total).toLocaleString() }}
          of {{ Number(data.total).toLocaleString() }}
        </p>
        <div class="ar-work__pager-nav">
          <button type="button" class="ar-work__pg" :disabled="busy || page <= 1" @click="goPage(page - 1)">‹ Previous</button>
          <template v-for="(n, idx) in pageWindow()">
            <span v-if="n === '…'" :key="'gap' + idx" class="ar-work__pg-gap" aria-hidden="true">…</span>
            <button
              v-else
              :key="n"
              type="button"
              class="ar-work__pg"
              :class="{ 'is-here': n === page }"
              :disabled="busy"
              @click="goPage(n)"
            >{{ n }}</button>
          </template>
          <button type="button" class="ar-work__pg" :disabled="busy || page >= pages" @click="goPage(page + 1)">Next ›</button>
        </div>
      </div>

      <!-- The footer describes the list as shipped ("the 30 most worth looking
           at, of 67") — under a finding's filter that claim belongs to a list
           the reader isn't looking at. -->
      <p v-if="foot && !picked" class="ar-work__foot">{{ foot }}</p>
    </template>

  </div>
</template>
