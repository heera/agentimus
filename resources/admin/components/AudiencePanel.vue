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
    // Split by the half each caveat is actually about. Two columns implied a
    // grouping the list did not have — one human fact happened to land beside a
    // machine one — so the grouping is real now, and labelled.
    limitsBoth() {
      return this.limits.filter((l) => l.scope === 'both');
    },
    limitsHumans() {
      return this.limits.filter((l) => l.scope === 'humans');
    },
    limitsMachines() {
      return this.limits.filter((l) => l.scope === 'machines');
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
          never add up: humans who arrived to read, and machines that fetched.
        </p>
      </div>
    </div>

    <p v-if="!loaded" class="ar-aud__wait">Reading both halves…</p>

    <!-- Two panels, distinguished by their MARK, not by colour.
         Colouring one accent and the other neutral said that one of them was
         the primary audience — and for this plugin that is backwards twice
         over: the machine half is the half nobody else measures. Equal
         surfaces, equal weight, and the icon carries the difference. -->
    <!-- Two panels, one shape. Kicker, number, what it counts, three rows,
         one link — identical on both sides, so the eye learns the layout once
         and then only reads the numbers. Everything that was explanation has
         moved into the notice below, which exists for exactly that: source
         captions under each row turned a summary into a form. -->
    <div v-else class="ar-aud__split">
      <!-- HUMANS ------------------------------------------------------------ -->
      <div class="ar-aud__half">
        <p class="ar-aud__kind">
          <span class="ar-aud__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6" /><path d="M4.8 20.2a7.2 7.2 0 0 1 14.4 0" /></svg>
          </span>
          Humans
        </p>
        <p class="ar-aud__n">{{ n(people.arrived) }}</p>
        <p class="ar-aud__unit">
          <template v-if="people.whole">read your site</template>
          <template v-else>arrived from search or an AI answer</template>
        </p>

        <ul class="ar-aud__rows">
          <li>
            <span class="ar-aud__row-n">{{ n(people.search.clicks) }}</span>
            <span class="ar-aud__row-l">from search</span>
          </li>
          <li>
            <span class="ar-aud__row-n">{{ n(people.ai.visits) }}</span>
            <span class="ar-aud__row-l">from an AI answer</span>
          </li>
          <li>
            <span class="ar-aud__row-n">{{ n(people.all.sessions) }}</span>
            <span class="ar-aud__row-l">visits in total</span>
          </li>
        </ul>

        <button type="button" class="ar-linkbtn ar-aud__go" @click="$emit('navigate', 'readers')">
          See who sent them →
        </button>
      </div>

      <!-- MACHINES ---------------------------------------------------------- -->
      <div class="ar-aud__half">
        <p class="ar-aud__kind">
          <span class="ar-aud__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="8" width="14" height="10" rx="2.6" /><path d="M12 5.2V8" /><circle cx="12" cy="4.2" r="1.1" /><path d="M9.4 12.6v1.2M14.6 12.6v1.2" /></svg>
          </span>
          Machines
        </p>
        <p class="ar-aud__n">{{ n(machines.fetches) }}</p>
        <p class="ar-aud__unit">fetched your agent files</p>

        <ul class="ar-aud__rows">
          <li>
            <span class="ar-aud__row-n">{{ n(machines.agents) }}</span>
            <span class="ar-aud__row-l">
              different clients
              <em v-if="!machines.enabled" class="ar-aud__off">not recording</em>
            </span>
          </li>
          <li :class="{ 'is-bad': machines.impostors > 0 }">
            <span class="ar-aud__row-n">{{ n(machines.impostors) }}</span>
            <span class="ar-aud__row-l">faking an identity</span>
          </li>
          <li>
            <span class="ar-aud__row-n">{{ n(machines.today) }}</span>
            <span class="ar-aud__row-l">fetches today</span>
          </li>
        </ul>

        <button type="button" class="ar-linkbtn ar-aud__go" @click="$emit('navigate', 'log')">
          See every request →
        </button>
      </div>
    </div>

    <!-- Three labelled columns on ONE left edge. The previous version had the
         icon, the group labels and the bullets each starting at a different x,
         and the shared fact floating above the headings with no heading of its
         own — so nothing lined up with anything. Every group is a column now,
         including the shared one, and the bullets are gone: the column IS the
         grouping, so a dot in front of each line was a second one. -->
    <div v-if="loaded && limits.length" class="ar-aud__note">
      <p class="ar-aud__note-t">
        <span class="ar-aud__note-i" aria-hidden="true">i</span>
        Worth knowing
      </p>

      <div class="ar-aud__note-grid">
        <div v-if="limitsBoth.length" class="ar-aud__note-grp">
          <p class="ar-aud__note-k">Both</p>
          <p v-for="l in limitsBoth" :key="l.key">{{ l.text }}</p>
        </div>
        <div v-if="limitsHumans.length" class="ar-aud__note-grp">
          <p class="ar-aud__note-k">Humans</p>
          <p v-for="l in limitsHumans" :key="l.key">{{ l.text }}</p>
        </div>
        <div v-if="limitsMachines.length" class="ar-aud__note-grp">
          <p class="ar-aud__note-k">Machines</p>
          <p v-for="l in limitsMachines" :key="l.key">{{ l.text }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
