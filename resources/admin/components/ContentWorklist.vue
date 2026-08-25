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
    dismissingSearch: { type: String, default: '' },
    data: { type: Object, default: () => ({ items: [], counts: {}, capped: false, total: 0, noSearchData: 0, searchState: '', engine: '', pageCap: 0, waiting: 0 }) },
    // Cheap counts from the boot payload — no page parsed. They let the opening
    // state say something true about the site instead of just offering a button.
    preview: { type: Object, default: () => ({ published: 0, withSearch: 0, setAside: 0, searchState: '', engine: '', pageCap: 0, waiting: 0 }) },
    loaded: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    settingAside: { type: Number, default: 0 },
    // { pages: [id], seq } — a finding handing over the exact rows it counted.
    pick: { type: Object, default: null },
    // The check this list is narrowed to, from the by-issue card: { id, label, count }.
    issue: { type: Object, default: null },
    // The tab was away long enough that a post may have been edited elsewhere.
    stale: { type: Boolean, default: false },
    // [{ slug, label, source, count, on, blocked }] — what this site CAN check
    // and what it does. The gear edits it; the line under the head states it.
    checkTypes: { type: Array, default: () => [] },
  },
  emits: ['load', 'set-aside', 'dismiss-search', 'open-scope', 'clear-issue'],
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
    // Types this site could check, whether or not it is checking them. The
    // ones it CANNOT check ride along in the payload for the panel's own
    // "cannot be checked" group, and must never be counted here.
    scopeAll() {
      return this.checkTypes.filter((t) => !t.blocked);
    },
    scopeOn() {
      return this.scopeAll.filter((t) => t.on);
    },
    // "Posts, Pages and Products" — written the way a person would say it, not
    // as a comma-joined machine list.
    scopeNames() {
      // ⚠️ TWO PLUGINS CAN NAME A TYPE THE SAME THING. On a site with both
      // WooCommerce and FluentCart this read "Posts, Pages, Downloads, Products
      // and Products" — a stutter that also told the owner nothing about which
      // Products was which (found live, 2026-08-18). The vendor's name is only
      // added where the label actually collides: putting it on every row would
      // turn a sentence into a manifest.
      const labels = this.scopeOn.map((t) => t.label);
      const clashes = new Set(labels.filter((l, i) => labels.indexOf(l) !== i));
      const names = this.scopeOn.map((t) => (
        clashes.has(t.label) && t.source ? `${t.label} (${t.source})` : t.label
      ));
      if (!names.length) return '';
      if (names.length === 1) return names[0];
      return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
    },
    // ⚠️ Every type switched off. The line used to render only when something
    // WAS checked, so turning everything off made the one sentence explaining
    // the empty list disappear along with the list — the screen's own
    // explanation vanishing exactly when it was needed. Off is a state this
    // card states, not one it goes quiet about.
    checkingOff() {
      return this.scopeAll.length > 0 && this.scopeOn.length === 0;
    },
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
    // How many rows on screen have stopped carrying the narrowed check. The
    // header says "the 55 pages flagged X" and was fetched before the owner
    // fixed any of them — this is what keeps that sentence true without
    // pulling the row they just fixed out from under them.
    fixedHere() {
      if (!this.data.issue) return 0;
      return this.shown.filter((row) => this.noLongerFlagged(row)).length;
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
    // …and how many were edited AFTER they were read. Every row below is
    // re-read as this page is built, so each verdict is current — but the ORDER
    // and the counts beside the tabs come from the stored reading, which for
    // these pages describes the draft before the edit.
    rechecking() {
      return Number(this.data.rechecking) || 0;
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
      if (this.rechecking > 0) {
        const n = this.rechecking.toLocaleString();
        bits.push(
          `${this.rechecking === 1 ? 'One page you edited is' : `${n} pages you edited are`} being read again — what each row says is current, but the order and the counts above still come from the earlier reading.`
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
    // What this row is asking for — and it is two different asks, one of which
    // this badge could not see. A row is in Worth Fixing when it has content
    // flags OR its search is not answered (see needsWork, and Worklist's
    // needs_work behind it). Counting only the flags meant a row admitted on
    // coverage alone came out at zero and printed "nothing to fix" — in the
    // warn colour, inside the Worth Fixing tab, beside its own ✕ mark saying
    // the opposite. Seen live on heera.it: five of the first eight rows.
    //
    // Flags lead when there are any: they are the list of edits to make. With
    // none, the coverage verdict IS the ask, so it says so in its own words
    // rather than being averaged into a count — "barely answered" and "add a
    // summary" are not interchangeable jobs, and a shared number would imply
    // they were.
    stateChip(i) {
      const n = (i.flags ? i.flags.length : 0) + (i.moreFlags || 0);
      if (n) return `${n} ${1 === n ? 'thing' : 'things'} to fix`;
      return this.coverAsk(i.coverage && i.coverage.state) || 'nothing to fix';
    },
    // The four coverage states, minus the one that asks for nothing. Anything
    // unrecognised returns '' and the badge falls back — a new state must not
    // be able to invent a demand this screen cannot explain.
    coverAsk(state) {
      return {
        scattered: 'answer is scattered',
        barely: 'barely answered',
        missing: 'search not answered',
      }[state] || '';
    },
    // A tab is now a different question for the server, not a different filter
    // over the same pile — so it costs a fetch, and always starts at page one.
    // ⭐ This row no longer carries the check the list was narrowed to — the
    // owner has just fixed it, and the row is still here on purpose: a row that
    // vanishes the moment it is fixed is a fix nobody got to see, which is the
    // failure this plugin already corrected once in the score.
    // ⛔ Tested on ids, never on the visible labels: a row shows three and
    // counts the rest as "+2 more", so a label test would call a page fixed
    // because the flag that matters happened to be in the tail.
    noLongerFlagged(row) {
      const issue = this.data.issue;
      if (!issue || !Array.isArray(row.flagIds)) return false;
      return row.flagIds.indexOf(issue) === -1;
    },
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
    // WHEN "this isn't a question for my site" is an honest thing to offer.
    //
    // ⛔ ONLY on a search an ENGINE chose. If the owner typed the focus
    // themselves, setting it aside means overruling their own decision — there
    // the honest action is to change it, which the editor's own field and
    // write-search-fields already do. The two situations look identical on a
    // row, and conflating them is how somebody sets aside a search they meant
    // to aim at.
    //
    // ⛔ And only while the coverage half is actually asking for something. On
    // "answered" there is nothing to excuse; on "unreadable" nothing was
    // measured, so the row is not asking on that account either.
    canDismissSearch(i) {
      const f = i.focus;
      const state = (i.coverage && i.coverage.state) || '';
      if (!f || !f.query || f.chosen) return false;
      return !!state && 'answered' !== state && 'unreadable' !== state;
    },
    // ⛔ Through v-tip, never title="…" — the native one ignores this admin's
    // styling and its own timing, which is why main.js registers a replacement.
    //
    // ⛔⛔ AND IT MUST NOT SOUND LIKE SEO ADVICE. This said "stops any page being
    // MARKED DOWN for not answering…", which reads as a penalty in Google — the
    // one thing a reader has most reason to fear and the one thing that is not
    // happening. It also opened in the passive, so nobody was named as doing it.
    // Now: who stops (Agentimus), what stops (checking pages against it), and the
    // two reassurances that decide the click — nothing real changes, and it is
    // reversible. The search is named because the bubble covers the row above it.
    dismissTip(i) {
      const q = (i.focus && i.focus.query) || '';
      return `Agentimus stops checking any page against “${q}”. Nothing on your site or in Google changes — you can put it back any time.`;
    },
    isDismissing(i) {
      return this.dismissingSearch && i.focus && this.dismissingSearch === i.focus.query;
    },
    // Site-wide, so the parent reloads the whole list rather than tweaking this
    // row: the same search may be judging other pages, and they all stop at once.
    dismissSearchRow(i) {
      if (!this.canDismissSearch(i) || this.dismissingSearch) return;
      this.$emit('dismiss-search', { query: i.focus.query });
    },
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
    // Whether the badge wears the warn colour. Derived from the badge's own
    // WORDS rather than re-deciding the rule beside it: the two used to be
    // computed separately and drifted apart, which is how a warn-coloured pill
    // ended up reading "nothing to fix". One source, so they cannot disagree
    // again — whatever stateChip finds to ask for is what gets coloured.
    needsWork(i) {
      return 'nothing to fix' !== this.stateChip(i);
    },
    // 'unreadable' is NOT a verdict about the page — the search itself held no
    // word the grader could look for (a non-Latin script, or only common
    // words). Neutral mark, and a label that says so. See Coverage::UNREADABLE.
    coverMark(state) {
      return { answered: '●', scattered: '◐', barely: '○', missing: '✕', unreadable: '○' }[state] || '';
    },
    coverLabel(state) {
      return {
        answered: 'Answered', scattered: 'Scattered', barely: 'Barely', missing: 'Missing',
        unreadable: 'Not measured',
      }[state] || '';
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
      if ('missing' === c.state) {
        return 'None of it is on the page — either the wrong search for this one, or an opening.';
      }
      if ('unreadable' === c.state) {
        return 'This search gives the check no word it can look for, so this page has not been judged on it.';
      }
      // ⛔ NEVER a fall-through. Every unrecognised state used to arrive at the
      // "none of it is on the page" sentence above — a verdict about the page,
      // handed out for a word we do not understand.
      return '';
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
        unreadable: 'Not measured — this search has no words the check can read',
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
      <div class="ar-work__actions">
        <button
          v-if="loaded"
          type="button"
          class="ar-linkbtn"
          :disabled="busy"
          @click="$emit('load')"
        >{{ busy ? 'Checking…' : 'Check again' }}</button>
        <!-- ⛔ No native title — tipGuard owns tooltips in this app, and a
             browser tooltip here would be the one control that behaved
             differently from every other. The aria-label is what a screen
             reader gets either way. -->
        <button
          v-if="scopeAll.length"
          v-tip="'Choose what gets checked'"
          type="button"
          class="ar-iconbtn"
          aria-label="Choose what gets checked"
          aria-haspopup="dialog"
          :disabled="busy"
          @click="$emit('open-scope')"
        >
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
               stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" />
            <circle cx="12" cy="12" r="3" />
          </svg>
        </button>
      </div>
    </div>

    <!-- What this list covers, stated where the list is. Without it the rows
         silently meant posts and pages, and a store owner had no way to learn
         that their products were never in it. -->
    <p v-if="scopeAll.length" class="ar-scope" :class="{ 'is-off': checkingOff }">
      <template v-if="checkingOff">
        <span class="ar-scope__k">Checking</span>
        <span class="ar-scope__v">Nothing</span>
        <span class="ar-scope__n">— no content types are selected</span>
      </template>
      <template v-else-if="!checkingOff">
        <span class="ar-scope__k">Checking</span>
        <span class="ar-scope__v">{{ scopeNames }}</span>
        <span v-if="scopeAll.length > 1" class="ar-scope__n">— {{ scopeOn.length }} of {{ scopeAll.length }} kinds of content</span>
      </template>
    </p>

    <!-- Off is not empty. Without this the card fell through to its opening
         invitation — a button offering to read content it had just been told
         not to read. -->
    <div v-if="checkingOff" class="ar-work__idle">
      <p>
        Nothing is being checked, so there is nothing to list. Your grades are kept —
        switch a content type back on and its rows come straight back, with no re-reading.
      </p>
      <button type="button" class="ar-btn ar-btn--small" @click="$emit('open-scope')">
        Choose what gets checked
      </button>
    </div>

    <!-- The opening state. It costs a page parse per row, so it is asked for —
         but it opens with what is already known, so the invitation is grounded
         in this site rather than being a bare button on an empty panel. -->
    <div v-if="!loaded && !checkingOff" class="ar-work__intro">
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

    <template v-else-if="!checkingOff">
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

      <!-- ⚠️ NARROWED, AND IT SAYS SO. The chips above count the whole bucket
           while these rows are one check's — the two disagree on purpose, and a
           list that shows 60 rows under a chip reading 68 without a word is the
           contradiction this feature was built to avoid. The label comes from
           the server with the rows, so it can only ever describe what arrived. -->
      <p v-else-if="data.issue" class="ar-work__picked">
        Showing the {{ data.total }} {{ data.total === 1 ? 'page' : 'pages' }} flagged “{{ data.issueLabel || (issue && issue.label) }}”.
        <!-- ⚠️ The count above was taken when this list was fetched. Fix one of
             them and it is one too many — said here rather than left to be
             noticed, and the row itself stays put and marked. -->
        <template v-if="fixedHere"> {{ fixedHere === 1 ? 'One of them is' : `${fixedHere} of them are` }} fixed since you opened it.</template>
        <button type="button" class="ar-linkbtn" @click="$emit('clear-issue')">Show everything</button>
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
                <span class="ar-work__rowtitle">
                  <span class="ar-work__type">{{ i.type }}</span>
                  <a class="ar-work__name" :href="i.edit || i.url" target="_blank" rel="noopener" @click.stop>{{ i.title }}</a>
                  <!-- ⭐ You fixed the thing this list was narrowed to, and the
                       row stayed so you could see that. ⛔ It does NOT take the
                       badge on the right: that seat belongs to the worst thing
                       still true of the page, and a page can be done with this
                       check and still be barely answering its search. -->
                  <span
                    v-if="noLongerFlagged(i)"
                    class="ar-work__fixed"
                    v-tip="'This page no longer flags the check this list was narrowed to. It stays here so you can see the fix landed; it will be gone the next time you open the list.'"
                  >fixed</span>
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
                <!-- ⛔ BESIDE THE VERDICT IT OVERRULES, not down in the row's action
                     bar. Sat there it was one word from "Set this aside" and the two
                     read as the same thing twice — he said so, and he was right:
                     adjacency was the confusion, not the wording. Up here there is
                     nothing to confuse it with, and what it acts on is the thing it
                     is standing next to. The vocabulary stays the site's own: this
                     screen has a Set Aside tab and the Search screen a "Searches set
                     aside" fold, so the same words mean the same thing throughout. -->
                <button
                  v-if="canDismissSearch(i)"
                  type="button"
                  class="ar-linkbtn ar-linkbtn--mute ar-work__aside-q"
                  :disabled="!!dismissingSearch"
                  v-tip="dismissTip(i)"
                  @click="dismissSearchRow(i)"
                >
                  {{ isDismissing(i) ? 'Setting aside…' : 'Set this search aside' }}
                  <svg width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="10" cy="10" r="7.25" /><path d="M7.6 7.6l4.8 4.8M12.4 7.6l-4.8 4.8" stroke-linecap="round" />
                  </svg>
                </button>
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
