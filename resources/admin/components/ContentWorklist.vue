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
export default {
  name: 'ContentWorklist',
  props: {
    // Only for /worklist/rows — the list itself stays App-owned and arrives
    // as `data`, so this panel cannot drift into loading two truths.
    api: { type: Object, default: null },
    data: { type: Object, default: () => ({ items: [], counts: {}, capped: false, total: 0, noSearchData: 0, searchState: '', engine: '', pageCap: 0 }) },
    // Cheap counts from the boot payload — no page parsed. They let the opening
    // state say something true about the site instead of just offering a button.
    preview: { type: Object, default: () => ({ published: 0, withSearch: 0, setAside: 0, searchState: '', engine: '', pageCap: 0 }) },
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
      filter: 'fixable',
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
    // Why a row's focus column is empty, and the two reasons are NOT the same.
    //
    // Google looks at every page, so an empty column really does mean no search
    // has reached it — normal for a new post, and it will fill in on its own.
    // Bing has no query x page report at all: the poll buys page detail one HTTP
    // call at a time and stops at the busiest few, so outside that sample the
    // column is empty because nobody ASKED, and waiting will never fill it.
    // Printing the first sentence in the second case tells an owner their page
    // is invisible when it may be doing fine.
    emptyWhy() {
      if (this.pageCap > 0) {
        return `${this.engine || 'This source'} only reports searches for your ${this.pageCap} busiest pages — this one is outside that list.`;
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
    shown() {
      // A finding's subset wins over the tab: it was chosen deliberately and
      // the pages in it may sit across two tabs.
      if (this.picked) {
        const want = this.picked;
        return this.items.filter((i) => want.indexOf(i.id) > -1);
      }
      return this.items.filter((i) => {
        if ('setAside' === this.filter) return i.setAside;
        if (i.setAside) return false;
        return 'fixable' === this.filter ? this.needsWork(i) : !this.needsWork(i);
      });
    },
    // Said out loud rather than implied by a list that simply stops.
    foot() {
      const bits = [];
      if (this.data.capped) {
        // Parked rows ride along so the Set aside view is complete, but they
        // are not part of the "worth looking at" claim — counting them here
        // would inflate a sentence about a different list.
        const ranked = this.items.filter((i) => !i.setAside).length;
        bits.push(`Showing the ${ranked} most worth looking at, of ${this.data.total}.`);
      }
      if (this.data.noSearchData) {
        // "Normal for recent posts" is true under Google and misleading under
        // Bing, where the blank column is the sample's edge rather than the
        // page's age. Same number, two different things to do about it.
        bits.push(
          this.pageCap > 0
            ? `${this.data.noSearchData} sit outside the ${this.pageCap} pages ${this.engine || 'this source'} reports searches for, so nothing here can say what they are found for. Their content issues are still real.`
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
        // Under Bing this can never exceed the poll's page budget, so calling it
        // "already found in search" would read as a verdict on the other 290
        // pages when it is only the shape of the report.
        const cap = Number(p.pageCap) || 0;
        out.push({
          n: p.withSearch || 0,
          label: cap > 0 ? `reported by ${p.engine || 'your search source'}` : 'already found in search',
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
          @click="filter = t.key"
        >{{ t.label }} <span class="ar-work__tab-n">{{ t.n }}</span></button>
      </div>

      <!-- The grid's spine: three columns with no headings made the reader
           infer the pattern row by row. Hidden from screen readers — the row
           content names itself, and a fake table header would announce a
           table this list is not. -->
      <div v-if="shown.length" class="ar-work__cols" aria-hidden="true">
        <span>Content</span>
        <span>Search</span>
        <span>Actions</span>
      </div>

      <ul v-if="shown.length" class="ar-work__list">
        <li v-for="i in shown" :key="i.id" class="ar-work__row" :class="{ 'is-aside': i.setAside }">

          <div class="ar-work__thing">
            <span class="ar-work__type">{{ i.type }}</span>
            <a class="ar-work__name" :href="i.edit || i.url" target="_blank" rel="noopener">{{ i.title }}</a>
            <span v-if="i.flags && i.flags.length" class="ar-work__flags">
              <span v-for="f in i.flags" :key="f" class="ar-work__flag">{{ f }}</span>
              <span v-if="i.moreFlags" class="ar-work__flag is-more">+{{ i.moreFlags }}</span>
            </span>
            <span v-else-if="!needsWork(i)" class="ar-work__flags">
              <span class="ar-work__flag is-clear">nothing else to fix</span>
            </span>

            <!-- The search sits WITH the thing it describes. It used to open the
                 verdict column, which put a quoted phrase at the top of a block
                 of judgements about it — and left this column half empty while
                 that one ran long. -->
            <span v-if="i.focus" class="ar-work__forline">
              <span class="ar-work__forlabel">{{ focusLabel(i) }}</span>
              <!-- The search reads as a phrase, because that is what it is —
                   words somebody typed. Each word carries its own reading in
                   place: the ones the answering passage holds, the ones that
                   are on the page elsewhere, and the ones that never appear.
                   The filled badges this replaced said the same thing twice —
                   once whole in this column, once chopped into chips in the
                   next — in two visual languages that competed. -->
              <span
                v-for="f in searches(i)"
                :key="f.query"
                class="ar-work__qline"
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
              <!-- Reality, when it disagrees with the choice. Only rendered
                   for a chosen focus whose engine-reported search is a
                   different phrase — the mismatch is the whole point, and a
                   row where they agree has nothing to add. -->
              <span v-if="i.focus.reported" class="ar-work__reported">
                {{ reportedLead(i) }}
                <strong>{{ i.focus.reported.query }}</strong>
                <span aria-hidden="true" class="ar-work__dot"> · </span>
                <span class="ar-work__reported-n">{{ rank(i.focus.reported.position) }} · {{ num(i.focus.reported.impressions) }} shown</span>
              </span>
            </span>
          </div>

          <div class="ar-work__for">
            <template v-if="i.focus">
              <!-- The search, with a label saying WHOSE it is before you read it.
                   A row judged against the author's own choice and one judged
                   against a search Google reported are different claims, and
                   only one of them is the plugin's opinion — so the label names
                   the source rather than leaving the phrase to speak for itself. -->
              <span class="ar-work__nums">
                <span class="ar-work__rank">{{ rank(i.focus.position) }}</span>
                <span>{{ num(i.focus.impressions) }} shown</span>
                <span aria-hidden="true" class="ar-work__dot">·</span>
                <span>{{ num(i.focus.clicks) }} visits</span>
                <template v-if="i.focus.others">
                  <span aria-hidden="true" class="ar-work__dot">·</span>
                  <span>+{{ i.focus.others }} more searches</span>
                </template>
              </span>
              <span class="ar-work__cover" :class="'is-' + i.coverage.state" v-tip="coverWhy(i)">
                <span class="ar-work__cover-mark" aria-hidden="true">{{ coverMark(i.coverage.state) }}</span>
                <span class="ar-work__cover-t">{{ coverLabel(i.coverage.state) }}</span>
              </span>
              <span v-if="coverDetail(i)" class="ar-work__why">{{ coverDetail(i) }}</span>
            </template>
            <span v-else class="ar-work__why">{{ emptyWhy }}</span>
          </div>

          <div class="ar-work__act">
            <a v-if="i.edit" class="ar-linkbtn" :href="i.edit" target="_blank" rel="noopener">Open in editor</a>
            <button
              type="button"
              class="ar-linkbtn ar-linkbtn--mute"
              :disabled="isSettingAside(i)"
              @click="setAsideRow(i)"
            >{{ isSettingAside(i) ? 'Saving…' : (i.setAside ? 'Put this back' : 'Set this aside') }}</button>
          </div>

        </li>
      </ul>
      <!-- Not in the picked view: its own line above already explains an empty
           result, and stacking "Nothing in this view" under it read as three
           contradictory sentences about one list. -->
      <p v-else-if="!picked" class="ar-work__empty">Nothing in this view yet — pages move here as their checks change, and the other tabs hold the rest of your content.</p>

      <!-- The footer describes the list as shipped ("the 30 most worth looking
           at, of 67") — under a finding's filter that claim belongs to a list
           the reader isn't looking at. -->
      <p v-if="foot && !picked" class="ar-work__foot">{{ foot }}</p>
    </template>

  </div>
</template>
