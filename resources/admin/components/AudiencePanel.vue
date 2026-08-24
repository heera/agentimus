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
// Below this many visits in the PREVIOUS window, a percentage stops being a
// measurement and becomes an accident of a small denominator — 12 → 114 reads
// "+850%", which sounds like a finding and is a fortnight of ordinary noise.
// Under the floor the card states the previous count instead and lets the
// reader size the change themselves.
const TREND_MIN_BASE = 30;

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
    // Split by the half each caveat is actually about. Both-scoped caveats are
    // deliberately NOT rendered here: the owner's sketch has no slot for them,
    // and their claim ("the instruments don't add up") is the card lead's own.
    limitsHumans() {
      return this.limits.filter((l) => l.scope === 'humans');
    },
    limitsMachines() {
      return this.limits.filter((l) => l.scope === 'machines');
    },
    window() {
      return (this.data && this.data.window) || 30;
    },
    // "from Google", "from Bing", "from Google + Bing" — the engines by name.
    //
    // This row sums whichever engines are connected, and a bare "from search"
    // over that total never said which. Two owners read the same words and one
    // of them is wrong about what the number covers. The name is short enough
    // to live in the label, so it costs no line and no caption.
    // ⭐⭐ WHERE EACH ROW LANDS — his call, 2026-08-21: a number that can be
    // walked into should be walkable. Each returns a goTo() address or null,
    // and null is a deliberate answer, not a missing one: a row links only
    // where the destination can actually ANSWER it.
    //  · clients   → the Top Clients card, which names the ones this counts
    //  · impostors → the Request Log, pre-filtered to spoofed verdicts
    //  · search    → Search Performance, the engines' own report
    //  · AI        → the Visitors screen, the same place the footer goes
    // ⛔ A zero never links. Landing on an empty filtered list is a dead end
    //   that reads as a broken screen — and "0 caught faking an identity" with
    //   nowhere to go is the honest shape when nothing was caught.
    clientsGo() {
      const m = this.machines;
      return m && m.enabled && m.agents > 0 ? { tab: 'dashboard', anchor: 'ar-act-clients' } : null;
    },
    impostorsGo() {
      const m = this.machines;
      if (!m || !(m.impostors > 0)) return null;
      // ⛔ The DATES travel with the filter. This card counts one window; the
      // Request Log defaults to everything it still retains, so without them the
      // row landed on a longer list than the number you clicked — his catch,
      // 2026-08-21. The boundary is the server's own (Repository::stats), never
      // recomputed here, or the two would disagree across a timezone.
      return { tab: 'log', log: { verdict: '2', from: m.from || '', to: m.to || '' } };
    },
    // ⛔ Lands on WHAT WAS SEARCHED FOR, not on Search Opportunities. Both live
    // on that screen, but this row counts arrivals and the reader's next question
    // is "looking for what?" — Opportunities answers "which pages need work",
    // which is a different question that happens to share the number.
    searchGo() {
      const s = this.people && this.people.search;
      return s && s.connected && s.clicks > 0
        ? { tab: 'visibility', view: 'performance', anchor: 'ar-perf-searched' }
        : null;
    },
    // ⛔ Lands on the AI card, not the top of Visitors. That screen opens with
    // "Everyone Who Visited" and "Busiest Pages" — the whole audience — so this
    // row used to hand over a number about assistants and then show a screen
    // about everybody, leaving the reader to find the part they clicked.
    aiGo() {
      const a = this.people && this.people.ai;
      return a && a.visits > 0 ? { tab: 'visitors', anchor: 'ar-ai-sources' } : null;
    },
    // "clicks", not "from" — this row is the ENGINES' own click count, not a
    // visit this site measured. Calling it a visit would put it in the same
    // unit as the sessions row below it and invite exactly the addition the
    // card exists to prevent.
    searchLabel() {
      const named = (this.people && this.people.search && this.people.search.source) || '';
      return named ? `clicks from ${named}` : 'clicks from search';
    },
    // 793 people making 878 visits is arithmetic an owner should not have to
    // do. Shown only when the two actually differ — on a site where nobody
    // came twice the line would be noise.
    cameBack() {
      if (!this.people || !this.people.whole) return false;
      return Number(this.people.all.sessions) > Number(this.people.arrived);
    },
    // Direction for the AI row. The payload already pays for the previous
    // window (Referrals::previous_window_total), and a bare count supports no
    // decision — "114" says nothing that "114, up 38%" does not say better.
    //
    // Search has no cheap previous window (Search\Table::totals takes no span),
    // so that row stays a plain count rather than inventing plumbing for it.
    aiTrend() {
      const a = (this.people && this.people.ai) || null;
      if (!a || !a.enabled) return null;
      const now = Number(a.visits) || 0;
      const prev = Number(a.prev) || 0;
      // A first month has nothing to compare against, and "+100%" grown from
      // zero would be the loudest number on the card and the least true.
      if (prev <= 0) return null;
      if (now === prev) return { dir: 'flat' };
      const dir = now > prev ? 'up' : 'down';
      if (prev < TREND_MIN_BASE) return { dir, from: prev };
      return { dir, pct: Math.abs(Math.round(((now - prev) / prev) * 100)) };
    },
    // Caveats grouped by the half they qualify, one row each, with the half
    // NAMED. The marks alone did not carry it: at 13px, in a list that wraps,
    // a machine caveat lands at the end of a line that began with a human one
    // and there is nothing to read the difference from.
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
          Who Reached Your Site
          <span class="ar-card__tag">Last {{ window }} days</span>
        </h2>
        <p class="ar-card__lead">
          Two audiences, counted separately because they are not the same thing and
          never add up: humans who arrived to read, and machines that fetched.
        </p>
      </div>
    </div>

    <!-- First poll in flight: two ghost halves hold the loaded split's shape,
         so the card fills in place instead of leaping from one line of text
         to two panels. Same shimmer grammar as Endpoint Activity's skeleton. -->
    <template v-if="!loaded">
      <p class="ar-aud__wait">Reading both halves…</p>
      <div class="ar-aud__split" aria-hidden="true" style="margin-top: 12px">
        <span class="ar-skel__panel"></span>
        <span class="ar-skel__panel"></span>
      </div>
    </template>

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
          <template v-if="people.whole">people visited your site</template>
          <template v-else>arrived from search or an AI answer</template>
        </p>
        <!-- Sessions belong WITH the headline, not under the two source rows:
             same instrument, same window, and the only pair on this card that
             can honestly be read against each other. Left where it was, its
             2,310 sat below two smaller numbers and turned the column into a
             breakdown that ended larger than its own total. -->
        <p v-if="people.whole" class="ar-aud__second">
          {{ n(people.all.sessions) }} visits
          <em v-if="cameBack" class="ar-aud__hint">some people came back</em>
        </p>

        <!-- The rows below are NOT shares of the number above — different
             instruments, different windows. The caption is the fence. -->
        <p class="ar-aud__group">Where they came from</p>
        <ul class="ar-aud__rows">
          <li>
            <component :is="searchGo ? 'button' : 'div'" :type="searchGo ? 'button' : null"
              class="ar-aud__row" :class="{ 'is-go': searchGo }"
              @click="searchGo && $emit('navigate', searchGo)">
              <span class="ar-aud__row-n">{{ n(people.search.clicks) }}</span>
              <span class="ar-aud__row-l">{{ searchLabel }}</span>
            </component>
          </li>
          <li>
            <component :is="aiGo ? 'button' : 'div'" :type="aiGo ? 'button' : null"
              class="ar-aud__row" :class="{ 'is-go': aiGo }"
              @click="aiGo && $emit('navigate', aiGo)">
            <span class="ar-aud__row-n">{{ n(people.ai.visits) }}</span>
            <span class="ar-aud__row-l">
              referred by AI answers
              <!-- An arrow and a percentage, the same convention the endpoint
                   rows above already use; the tooltip carries what it is
                   measured against, so the row stays one line. -->
              <em
                v-if="aiTrend"
                class="ar-aud__trend"
                :class="'is-' + aiTrend.dir"
                v-tip="'Against the 30 days before this window'"
              >
                <template v-if="'flat' === aiTrend.dir">level</template>
                <template v-else-if="undefined !== aiTrend.from">{{ 'up' === aiTrend.dir ? '▲' : '▼' }} from {{ n(aiTrend.from) }}</template>
                <template v-else>{{ 'up' === aiTrend.dir ? '▲' : '▼' }}{{ aiTrend.pct }}%</template>
              </em>
            </span>
            </component>
          </li>
        </ul>

        <button type="button" class="ar-linkbtn ar-aud__go" @click="$emit('navigate', 'visitors')">
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
        <!-- Same shape as the humans half: the second cut of the SAME
             instrument sits with the headline, and only the rows that answer
             a different question go below the caption.
             ⛔ It used to print "N today" — the point being that this half runs
             to NOW while the engines' half ends before today. The dashboard's
             Today line owns the day now, so the number was the same fact twice
             on one screen; the CONTRAST it was making is what matters here, and
             it says that in words. -->
        <p class="ar-aud__second">counted to this minute</p>

        <p class="ar-aud__group">Who did the fetching</p>
        <ul class="ar-aud__rows">
          <li>
            <component :is="clientsGo ? 'button' : 'div'" :type="clientsGo ? 'button' : null"
              class="ar-aud__row" :class="{ 'is-go': clientsGo }"
              @click="clientsGo && $emit('navigate', clientsGo)">
              <span class="ar-aud__row-n">{{ n(machines.agents) }}</span>
              <span class="ar-aud__row-l">
                different clients
                <em v-if="!machines.enabled" class="ar-aud__off">not recording</em>
              </span>
            </component>
          </li>
          <!-- "caught" — the count is conclusive spoof verdicts only, and it
               under-reports by design. Without that word a zero reads as "nobody
               is faking", which is a claim the measurement cannot support. -->
          <li :class="{ 'is-bad': machines.impostors > 0 }">
            <component :is="impostorsGo ? 'button' : 'div'" :type="impostorsGo ? 'button' : null"
              class="ar-aud__row" :class="{ 'is-go': impostorsGo }"
              @click="impostorsGo && $emit('navigate', impostorsGo)">
              <span class="ar-aud__row-n">{{ n(machines.impostors) }}</span>
              <span class="ar-aud__row-l">caught faking an identity</span>
            </component>
          </li>
        </ul>

        <button type="button" class="ar-linkbtn ar-aud__go" @click="$emit('navigate', 'log')">
          See every request →
        </button>
      </div>
    </div>

    <!-- The owner's sketch, matched exactly (2026-08-11): bulb + WORTH KNOWING
         in the note's gold, a short divider bar, then two equal columns — a
         rounded HUMANS / MACHINES chip beside its stacked facts, another short
         bar between them. Nothing else: the sketch has no lead line, and the
         both-halves caveat it dropped ("three instruments… don't add up") is
         already the card lead's own claim, so this block renders the two
         scoped groups only. -->
    <div v-if="loaded && (limitsHumans.length || limitsMachines.length)" class="ar-aud__note">
      <p class="ar-aud__note-t">
        <span class="ar-aud__note-i" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.8a6.6 6.6 0 0 0-3.9 11.9c.8.6 1.3 1.5 1.3 2.4h5.2c0-.9.5-1.8 1.3-2.4A6.6 6.6 0 0 0 12 2.8z" /><path d="M9.9 20h4.2" /></svg>
        </span>
        Worth knowing
      </p>
      <span class="ar-aud__note-sep" aria-hidden="true"></span>

      <div class="ar-aud__note-cols">
        <div v-if="limitsHumans.length" class="ar-aud__note-group">
          <span class="ar-aud__note-chip">Humans</span>
          <span class="ar-aud__note-facts">
            <span v-for="l in limitsHumans" :key="l.key" class="ar-aud__note-item">{{ l.text }}</span>
          </span>
        </div>
        <span v-if="limitsHumans.length && limitsMachines.length" class="ar-aud__note-sep" aria-hidden="true"></span>
        <div v-if="limitsMachines.length" class="ar-aud__note-group">
          <span class="ar-aud__note-chip">Machines</span>
          <span class="ar-aud__note-facts">
            <span v-for="l in limitsMachines" :key="l.key" class="ar-aud__note-item">{{ l.text }}</span>
          </span>
        </div>
      </div>
    </div>
  </section>
</template>
