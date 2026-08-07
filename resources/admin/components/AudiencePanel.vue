<script>
/**
 * "Who reached your site" — people on one side, machines on the other.
 *
 * The plugin always held both answers and never put them next to each other, so
 * an owner had to know unaided that "166 requests" on one screen and "59 visits"
 * on another count different species. This states them side by side, in one
 * window, and — the part that matters more than the numbers — says what they
 * cannot tell you.
 *
 * Every figure arrives assembled from the server (Audience::from_stats), so the
 * two halves cannot drift apart between here and the screens they link to. This
 * component does no arithmetic of its own beyond formatting: there is deliberately
 * no combined total, because a fetch is not a visit and adding them would invent
 * a number that means nothing.
 */
export default {
  name: 'AudiencePanel',
  props: {
    // The `audience` block of the activity payload.
    data: { type: Object, default: null },
    loaded: { type: Boolean, default: false },
  },
  emits: ['navigate'],
  computed: {
    people() {
      return (this.data && this.data.people) || null;
    },
    machines() {
      return (this.data && this.data.machines) || null;
    },
    limits() {
      return (this.data && this.data.limits) || [];
    },
    window() {
      return (this.data && this.data.window) || 30;
    },
    // The AI leaderboard as one line: "ChatGPT 40 · Perplexity 12 · Claude 5".
    // Three, not the whole list the payload carries: this is a one-line summary
    // on a card, and the Readers screen is where the full list belongs.
    aiLine() {
      const top = (this.people && this.people.ai && this.people.ai.top) || [];
      return top.filter((t) => t.source).slice(0, 3).map((t) => `${t.source} ${this.n(t.hits)}`).join(' · ');
    },
  },
  methods: {
    // Grouped digits, in the site's own locale-free style — the admin's numbers
    // are already plain everywhere else.
    n(v) {
      const i = Number(v) || 0;
      return i.toLocaleString('en-US');
    },
  },
};
</script>

<template>
  <section class="ar-card ar-aud" aria-labelledby="ar-aud-title">
    <div class="ar-aud__head">
      <div>
        <h2 id="ar-aud-title" class="ar-card__title">
          Who reached your site
          <span class="ar-card__tag">last {{ window }} days</span>
        </h2>
        <p class="ar-card__lead">
          Two audiences, counted separately because they are not the same thing and
          never add up: people who arrived to read, and machines that fetched.
        </p>
      </div>
    </div>

    <p v-if="!loaded" class="ar-aud__wait">Reading both halves…</p>

    <!-- Two panels, not two columns of text. Each audience gets its own
         surface, its own mark and its own colour, so the split is legible
         before a single number is read — which is the entire idea of the card
         and was, in a field of same-weight prose, the one thing you could not
         see. -->
    <div v-else class="ar-aud__split">
      <!-- PEOPLE ------------------------------------------------------------ -->
      <div class="ar-aud__half is-people">
        <p class="ar-aud__kind">
          <span class="ar-aud__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6" /><path d="M4.8 20.2a7.2 7.2 0 0 1 14.4 0" /></svg>
          </span>
          People
        </p>
        <p class="ar-aud__n">{{ n(people.arrived) }}</p>
        <p class="ar-aud__unit">
          <template v-if="people.whole">read your site</template>
          <template v-else>arrived from search or an AI answer</template>
        </p>

        <ul class="ar-aud__rows">
          <li>
            <span class="ar-aud__row-n">{{ n(people.search.clicks) }}</span>
            <span class="ar-aud__row-l">
              from search
              <template v-if="people.search.connected"> · {{ people.search.source }}</template>
              <em v-else class="ar-aud__off">nothing connected</em>
            </span>
          </li>
          <li>
            <span class="ar-aud__row-n">{{ n(people.ai.visits) }}</span>
            <span class="ar-aud__row-l">
              sent by an AI answer
              <template v-if="people.ai.sources"> · {{ people.ai.sources }} assistant<template v-if="people.ai.sources !== 1">s</template></template>
            </span>
          </li>
          <li v-if="people.whole">
            <span class="ar-aud__row-n">{{ n(people.all.sessions) }}</span>
            <span class="ar-aud__row-l">visits · {{ n(people.all.views) }} pages opened</span>
          </li>
        </ul>

        <p v-if="aiLine" class="ar-aud__detail">{{ aiLine }}</p>

        <button type="button" class="ar-linkbtn ar-aud__go" @click="$emit('navigate', 'readers')">
          See who sent them →
        </button>
      </div>

      <!-- MACHINES ---------------------------------------------------------- -->
      <div class="ar-aud__half is-machines">
        <p class="ar-aud__kind">
          <!-- The same bot glyph the review queue uses for a crawler, so one
               mark means one thing across the plugin. -->
          <span class="ar-aud__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="8" width="14" height="10" rx="2.6" /><path d="M12 5.2V8" /><circle cx="12" cy="4.2" r="1.1" /><path d="M9.4 12.6v1.2M14.6 12.6v1.2" /></svg>
          </span>
          Machines
        </p>
        <p class="ar-aud__n">{{ n(machines.fetches) }}</p>
        <p class="ar-aud__unit">fetches of your agent files</p>

        <ul class="ar-aud__rows">
          <li>
            <span class="ar-aud__row-n">{{ n(machines.agents) }}</span>
            <span class="ar-aud__row-l">
              distinct clients
              <em v-if="!machines.enabled" class="ar-aud__off">not recording</em>
            </span>
          </li>
          <li :class="{ 'is-bad': machines.impostors > 0 }">
            <span class="ar-aud__row-n">{{ n(machines.impostors) }}</span>
            <span class="ar-aud__row-l">caught faking an identity</span>
          </li>
          <li>
            <span class="ar-aud__row-n">{{ n(machines.today) }}</span>
            <span class="ar-aud__row-l">fetched today</span>
          </li>
        </ul>

        <button type="button" class="ar-linkbtn ar-aud__go" @click="$emit('navigate', 'log')">
          See every request →
        </button>
      </div>
    </div>

    <!-- The honesty half. Shipped WITH the numbers rather than in a docs page,
         because the misreadings these prevent happen at the moment of reading. -->
    <details v-if="loaded && limits.length" class="ar-aud__limits">
      <summary>
        What these numbers can’t tell you
        <span class="ar-aud__limits-n">{{ limits.length }}</span>
      </summary>
      <ul>
        <li v-for="l in limits" :key="l.key">
          <strong>{{ l.short }}</strong>
          <span>{{ l.text }}</span>
        </li>
      </ul>
    </details>
  </section>
</template>
