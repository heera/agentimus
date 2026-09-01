<script>
/**
 * "What Your Site Runs" — the Dashboard's systems roll-up.
 *
 * Four panels, each led by ONE number in the audience card's own type, each
 * ending in the one link that opens its home screen: AI access (what machines
 * can reach and do), Telling engines (what the site announces), Search (what
 * the engines show), Content (what the writing holds). The card answers
 * "what is running, and where does it stand" — Findings keeps "what needs
 * you"; nothing here duplicates the rail (score), the bell (review queue) or
 * the audience card (people/machines counts).
 *
 * Number sourcing is a deliberate split, so the card can never disagree with
 * the screens it points at:
 *   - `data` = activity.systems, server-assembled from option reads and two
 *     indexed COUNTs, riding the poll the dashboard already makes;
 *   - findings counts and the worklist preview come from the SAME boot
 *     payload the nav tab and worklist screens read — one promise, one
 *     number, stated twice. Search opportunity counts appear on NO row here:
 *     the honest count needs the full search report (too dear for a poll),
 *     and a row-count stand-in would differ from the Search screen's tally.
 *
 * Grammar notes carried from the audience card: statuses are words before
 * digits ("On", "Served", "Not connected"), a switched-off system is stated
 * in the faint ink rather than hidden (off is information), and waiting
 * findings are words with no digit — a number on this card is a promise that
 * work makes it go down, and waiting is not workable.
 */
import { formatDate, relTimeShort } from '../js/wpDate.js';

export default {
  name: 'SiteSystems',
  props: {
    data: { type: Object, default: null },
    loaded: { type: Boolean, default: false },
    /* boot.findings.counts — the nav tab's own numbers. */
    findings: { type: Object, default: null },
    /* boot.worklistPreview — the worklist screen's own numbers. */
    worklist: { type: Object, default: null },
  },
  emits: ['navigate'],
  computed: {
    doors() { return (this.data && this.data.doors) || null; },
    signals() { return (this.data && this.data.signals) || null; },
    search() { return (this.data && this.data.search) || null; },
    content() { return (this.data && this.data.content) || null; },

    /* Live over the signals this install HAS: Cloudflare only joins the
       denominator once it has ever been connected. */
    signalsLive() {
      const s = this.signals;
      if ( ! s ) return { on: 0, of: 0 };
      const pairs = [
        [ !! s.indexnowOn, true ],
        [ !! s.sitemapCovered, true ],
        [ !! s.robotsAi, true ],
        [ !! s.digestOn, true ],
        [ 'connected' === s.cloudflare, null !== s.cloudflare ],
      ];
      return {
        on: pairs.filter( ( p ) => p[1] && p[0] ).length,
        of: pairs.filter( ( p ) => p[1] ).length,
      };
    },

    searchConnected() {
      const s = this.search;
      return !! ( s && ( s.googleOn || s.bingOn ) );
    },

    /* The Findings tab counts urgent + worth; this card states the same
       number, from the same payload. */
    findingsOpen() {
      const c = this.findings;
      return c ? ( c.urgent || 0 ) + ( c.worth || 0 ) : 0;
    },
    findingsQuiet() {
      const c = this.findings;
      return c ? ( c.waiting || 0 ) + ( c.later || 0 ) : 0;
    },
  },
  methods: {
    go( tab, anchor ) {
      this.$emit( 'navigate', anchor ? { tab, anchor } : { tab } );
    },
    ago( ts ) {
      return ts ? relTimeShort( ts * 1000 ) : '';
    },
    onDate( ts ) {
      return ts ? formatDate( new Date( ts * 1000 ) ) : '';
    },
    n( v ) {
      return 'number' === typeof v ? v.toLocaleString() : v;
    },
  },
};
</script>

<template>
  <section class="ar-card ar-sys" aria-labelledby="ar-sys-title">
    <!-- The audience card's own masthead, not .ar-card__head: that class is a
         flex row built for a title sharing its line with buttons, and with a
         LEAD in the second slot it squeezed the title into a wrapped column
         and hung the rule under the lead alone. Stacked, the lead runs full
         width and its rule closes the whole header zone — the same standfirst
         the card below this one wears. -->
    <div class="ar-sys__head">
      <h2 id="ar-sys-title" class="ar-card__title">What Your Site Runs</h2>
      <p class="ar-card__lead">
        Every Agentimus system in one look &mdash; how AI assistants reach you, what your site tells the
        engines, what search shows, and what your writing holds.
      </p>
    </div>

    <!-- First poll in flight: four ghost panels hold the loaded grid's shape,
         so the card fills in place instead of leaping from one line of text
         to four panels. Same shimmer grammar as Endpoint Activity's skeleton. -->
    <template v-if="!loaded">
      <p class="ar-sys__wait">Reading the site&rsquo;s systems&hellip;</p>
      <div class="ar-sys__grid" aria-hidden="true" style="margin-top: 12px">
        <span class="ar-skel__panel"></span>
        <span class="ar-skel__panel"></span>
        <span class="ar-skel__panel"></span>
        <span class="ar-skel__panel"></span>
      </div>
    </template>

    <div v-else class="ar-sys__grid">

      <!-- AI ACCESS — what machines can reach and do. Was "Doors": his call,
           2026-08-25, that a normal owner does not read a door as a feature.
           The label now says what its own button says (Open Agent access), so
           the card and the screen it opens share one word. security.txt lives
           here, not under a "safety" heading: it is about who to contact
           regarding what is reachable, the same question access answers. -->
      <div class="ar-sys__panel">
        <p class="ar-sys__kind">
          <span class="ar-sys__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="12" height="12"><path d="M5 21V5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v16M3 21h18M12.2 12.6h.01" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          AI access
        </p>
        <p class="ar-sys__n">{{ n(doors ? doors.runsTotal : 0) }}</p>
        <p class="ar-sys__unit">tool runs by AI assistants recorded</p>
        <ul class="ar-sys__rows" v-if="doors">
          <li>
            <span class="ar-sys__row-l">MCP server</span>
            <span class="ar-sys__row-s">
              <em v-if="doors.mcpOn" class="is-good">On</em>
              <em v-else class="is-off">Off</em>
              <template v-if="doors.mcpOn"> · {{ n(doors.tools) }} tools · {{ doors.writesOn ? 'writes allowed' : 'writes off' }}</template>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">Assistants active</span>
            <span class="ar-sys__row-s"><strong>{{ n(doors.agents30) }}</strong> this month</span>
          </li>
          <li>
            <span class="ar-sys__row-l">WebMCP</span>
            <span class="ar-sys__row-s">
              <em v-if="doors.webmcpOn" class="is-good">On</em>
              <em v-else class="is-off">Off</em>
              <template v-if="doors.webmcpOn"> · {{ n(doors.webmcpTools) }} browser {{ 1 === doors.webmcpTools ? 'tool' : 'tools' }}</template>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">security.txt</span>
            <span class="ar-sys__row-s">
              <em v-if="'file' === doors.securityTxt || 'served' === doors.securityTxt" class="is-good">Served</em>
              <em v-else-if="'needs-contact' === doors.securityTxt" class="is-warn">Needs a contact</em>
              <em v-else class="is-off">Off</em>
            </span>
          </li>
        </ul>
        <button type="button" class="ar-linkbtn ar-sys__go" @click="go('agent-access')">Open Agent access <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
      </div>

      <!-- SIGNALS — what the site announces on its own. -->
      <div class="ar-sys__panel">
        <p class="ar-sys__kind">
          <span class="ar-sys__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="12" height="12"><path d="M12 20v-7M8.6 9.7a4.8 4.8 0 0 1 6.8 0M5.8 6.9a8.8 8.8 0 0 1 12.4 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          Telling engines
        </p>
        <p class="ar-sys__n">{{ signalsLive.on }}</p>
        <p class="ar-sys__unit">of {{ signalsLive.of }} signals live, telling search engines what changed</p>
        <ul class="ar-sys__rows" v-if="signals">
          <li>
            <span class="ar-sys__row-l">IndexNow</span>
            <span class="ar-sys__row-s">
              <template v-if="signals.indexnowOn && signals.indexnowLast">announced <strong>{{ ago(signals.indexnowLast) }}</strong></template>
              <em v-else-if="signals.indexnowOn" class="is-good">On</em>
              <em v-else class="is-off">Off</em>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">Sitemap · robots.txt</span>
            <span class="ar-sys__row-s">
              <em v-if="signals.sitemapCovered" class="is-good">Covered</em>
              <em v-else class="is-warn">No sitemap</em>
              <template v-if="signals.robotsAi"> · AI rules present</template>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">Weekly digest</span>
            <span class="ar-sys__row-s">
              <em v-if="signals.digestOn" class="is-good">On</em>
              <em v-else class="is-off">Off</em>
              <template v-if="signals.digestOn && signals.digestNext"> · next {{ onDate(signals.digestNext) }}</template>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">Cloudflare</span>
            <span class="ar-sys__row-s">
              <em v-if="'connected' === signals.cloudflare" class="is-good">Connected</em>
              <em v-else-if="'error' === signals.cloudflare" class="is-warn">Needs attention</em>
              <em v-else-if="'off' === signals.cloudflare" class="is-off">Disconnected</em>
              <em v-else class="is-off">Not connected</em>
            </span>
          </li>
        </ul>
        <button type="button" class="ar-linkbtn ar-sys__go" @click="go('readiness')">See Readiness <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
      </div>

      <!-- SEARCH — the standing half: what the engines hold. Opportunity and
           split counts are deliberately NOT rows here — see the sourcing note
           at the top of the file. -->
      <div class="ar-sys__panel">
        <p class="ar-sys__kind">
          <span class="ar-sys__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="12" height="12"><path d="M16.2 16.2 21 21M18.4 11a7.4 7.4 0 1 1-14.8 0 7.4 7.4 0 0 1 14.8 0Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          Search
        </p>
        <template v-if="search && search.watchTotal">
          <p class="ar-sys__n">{{ n(search.watchIndexed) }}</p>
          <p class="ar-sys__unit">of {{ n(search.watchTotal) }} watched pages in Google&rsquo;s index</p>
        </template>
        <template v-else-if="searchConnected">
          <p class="ar-sys__n">{{ (search.googleOn ? 1 : 0) + (search.bingOn ? 1 : 0) }}</p>
          <p class="ar-sys__unit">search {{ (search.googleOn && search.bingOn) ? 'engines' : 'engine' }} connected</p>
        </template>
        <template v-else>
          <p class="ar-sys__n ar-sys__n--off">&mdash;</p>
          <p class="ar-sys__unit">connect Google or Bing to read search from here</p>
        </template>
        <ul class="ar-sys__rows" v-if="search">
          <li>
            <span class="ar-sys__row-l">Google + Bing</span>
            <span class="ar-sys__row-s">
              <em v-if="search.googleOn && search.bingOn" class="is-good">Both connected</em>
              <em v-else-if="search.googleOn" class="is-good">Google connected</em>
              <em v-else-if="search.bingOn" class="is-good">Bing connected</em>
              <em v-else class="is-off">Not connected</em>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">Pages with search data</span>
            <span class="ar-sys__row-s">
              <template v-if="worklist && worklist.withSearch"><strong>{{ n(worklist.withSearch) }}</strong></template>
              <em v-else class="is-off">&mdash;</em>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">Index watch</span>
            <span class="ar-sys__row-s">
              <template v-if="search.watchTotal">checked <strong>{{ ago(search.checkedAt) }}</strong></template>
              <em v-else class="is-off">Nothing watched yet</em>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">Unknown sources</span>
            <span class="ar-sys__row-s">
              <em v-if="null === search.unknownHosts" class="is-off">Off</em>
              <template v-else-if="search.unknownHosts"><em class="is-warn">{{ n(search.unknownHosts) }}</em> unnamed hosts</template>
              <em v-else class="is-good">None to name</em>
            </span>
          </li>
        </ul>
        <button type="button" class="ar-linkbtn ar-sys__go" @click="go('visibility')">See Search <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
      </div>

      <!-- CONTENT — what the writing holds. The big number is the Findings
           tab's own count, from the same boot payload: one promise, stated
           twice, never two different numbers. Waiting stays words. -->
      <div class="ar-sys__panel">
        <p class="ar-sys__kind">
          <span class="ar-sys__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="12" height="12"><path d="M12 19.5h8.5M16.7 4.2a2 2 0 0 1 2.9 2.9L7.8 18.9l-3.9 1 1-3.9Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          Content
        </p>
        <p class="ar-sys__n">{{ n(findingsOpen) }}</p>
        <p class="ar-sys__unit">
          findings open across the site<template v-if="findingsQuiet"> &mdash; more are waiting on reports</template>
        </p>
        <ul class="ar-sys__rows">
          <li>
            <span class="ar-sys__row-l">Urgent · worth a look</span>
            <span class="ar-sys__row-s" v-if="findings">
              <em v-if="findings.urgent" class="is-warn">{{ n(findings.urgent) }}</em><strong v-else>0</strong>
              · <strong>{{ n(findings.worth || 0) }}</strong>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">Worklist</span>
            <span class="ar-sys__row-s" v-if="worklist">
              <strong>{{ n(worklist.published) }}</strong> published<template v-if="worklist.setAside"> · {{ n(worklist.setAside) }} set aside</template>
            </span>
          </li>
          <li>
            <span class="ar-sys__row-l">Focus keywords</span>
            <span class="ar-sys__row-s">
              <template v-if="content && worklist"><strong>{{ n(content.focusWith) }}</strong> of {{ n(worklist.published) }} pages carry one</template>
              <em v-else class="is-off">&mdash;</em>
            </span>
          </li>
        </ul>
        <button type="button" class="ar-linkbtn ar-sys__go" @click="go('findings')">Open Findings <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 4l4 4-4 4" /></svg></button>
      </div>

    </div>
  </section>
</template>
