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
    data: { type: Object, default: () => ({ items: [], counts: {}, capped: false, total: 0, noSearchData: 0, searchState: '' }) },
    // Cheap counts from the boot payload — no page parsed. They let the opening
    // state say something true about the site instead of just offering a button.
    preview: { type: Object, default: () => ({ published: 0, withSearch: 0, setAside: 0, searchState: '' }) },
    loaded: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    settingAside: { type: Number, default: 0 },
  },
  emits: ['load', 'set-aside', 'navigate'],
  data() {
    return { filter: 'fixable' };
  },
  computed: {
    items() {
      return Array.isArray(this.data.items) ? this.data.items : [];
    },
    counts() {
      return this.data.counts || {};
    },
    // Chips are exclusive and add up to the list, so the numbers can be trusted.
    tabs() {
      return [
        { key: 'fixable', label: 'Worth fixing', n: this.counts.fixable || 0 },
        { key: 'clear', label: 'Nothing to fix', n: this.counts.clear || 0 },
        { key: 'setAside', label: 'Set aside', n: this.counts.setAside || 0 },
      ];
    },
    shown() {
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
        bits.push(`Showing the ${this.items.length} most worth looking at, of ${this.data.total}.`);
      }
      if (this.data.noSearchData) {
        bits.push(`${this.data.noSearchData} have no search data yet — normal for recent posts, and they still show their content issues.`);
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
    // Only the numbers that mean something on this site. A stat reading "0"
    // teaches nothing and makes the row look broken.
    previewStats() {
      const p = this.preview || {};
      const out = [{ n: p.published || 0, label: p.published === 1 ? 'published item' : 'published items' }];
      if ('ready' === p.searchState) {
        out.push({ n: p.withSearch || 0, label: 'already found in search' });
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
    // The one line that makes a verdict checkable rather than a grade.
    coverWhy(i) {
      const c = i.coverage;
      if (!c) return '';
      if ('answered' === c.state) {
        return c.heading ? `One passage carries it, under “${c.heading}”.` : 'One passage carries the whole search.';
      }
      if ('scattered' === c.state) {
        return `All ${c.words} words are on the page, never together in one passage.`;
      }
      if ('barely' === c.state) {
        return `${c.on_page} of ${c.words} words appear anywhere on the page.`;
      }
      return `None of the ${c.words} words appear — probably not what this one is for.`;
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
  <div class="ar-card ar-work">

    <div class="ar-work__head">
      <div>
        <h2 class="ar-work__title">Your content</h2>
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

      <h3 class="ar-work__intro-title">See what each page is really for</h3>
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
        class="ar-btn ar-btn--reserve"
        data-reserve="Reading your content…"
        :disabled="busy"
        @click="$emit('load')"
      ><span>{{ busy ? 'Reading your content…' : 'Look at my content' }}</span></button>

      <p class="ar-work__intro-note">
        Takes a moment — every page is read once. Nothing is changed and nothing leaves your site.
      </p>
    </div>

    <template v-else>
      <div class="ar-work__tabs">
        <button
          v-for="t in tabs"
          :key="t.key"
          type="button"
          class="ar-work__tab"
          :class="{ 'is-active': filter === t.key }"
          @click="filter = t.key"
        >{{ t.label }} <span class="ar-work__tab-n">{{ t.n }}</span></button>
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
          </div>

          <div class="ar-work__for">
            <template v-if="i.focus">
              <span class="ar-work__q">{{ i.focus.query }}</span>
              <!-- Whose decision this is. A row judged against the author's own
                   choice and one judged against its busiest search are different
                   claims, and only one of them is the plugin's opinion. -->
              <span class="ar-work__src">{{ i.focus.chosen ? 'chosen in the editor' : 'its busiest search' }}</span>
              <span class="ar-work__nums">
                <span class="ar-work__rank">{{ rank(i.focus.position) }}</span>
                <span>{{ num(i.focus.impressions) }} shown</span>
                <span>{{ num(i.focus.clicks) }} visits</span>
                <span v-if="i.focus.others">+{{ i.focus.others }} more searches</span>
              </span>
              <span class="ar-work__cover" :class="'is-' + i.coverage.state">
                <span class="ar-work__cover-mark" aria-hidden="true">{{ coverMark(i.coverage.state) }}</span>
                <span class="ar-work__cover-t">{{ coverLabel(i.coverage.state) }}</span>
              </span>
              <span class="ar-work__why">{{ coverWhy(i) }}</span>
            </template>
            <span v-else class="ar-work__why">No searches have reached this one yet.</span>
          </div>

          <div class="ar-work__act">
            <a v-if="i.edit" class="ar-linkbtn" :href="i.edit" target="_blank" rel="noopener">Open in editor</a>
            <button
              type="button"
              class="ar-linkbtn ar-linkbtn--mute"
              :disabled="isSettingAside(i)"
              @click="$emit('set-aside', { id: i.id, aside: !i.setAside })"
            >{{ isSettingAside(i) ? 'Saving…' : (i.setAside ? 'Bring back' : 'Set aside') }}</button>
          </div>

        </li>
      </ul>
      <p v-else class="ar-work__empty">Nothing in this view.</p>

      <p v-if="foot" class="ar-work__foot">{{ foot }}</p>
    </template>

  </div>
</template>
