<script>
/**
 * At Cloudflare — the edge half of the Request Log screen. Named for the
 * provider on purpose: numbers from two providers can never merge honestly
 * (chained CDNs double-count, sampling differs), so a second data source
 * would get its own card under its own name — never extra rows here.
 *
 * The log records what reached the server; this shows what happened BEFORE
 * that: how much AI-crawler traffic the cache absorbed, what the edge turned
 * away, and how much data each AI company pulled. Fetches /cloudflare/summary
 * on first reveal and hands the conflicts UP to the parent, which pins them
 * above the log — a breakage must be the first thing on the screen, while
 * these cards stay below the log they annotate.
 *
 * Off state = one quiet pointer card at Settings → Data Sources. No form here:
 * the token has exactly one home.
 */
import { relTimeShort } from '../js/wpDate.js';
import CardSkeleton from './CardSkeleton.vue';

export default {
  name: 'EdgePanel',
  components: { CardSkeleton },
  props: {
    api: { type: Object, default: null },
    // Rendered with v-show, so it stays mounted across tab switches. Fetch on first reveal.
    active: { type: Boolean, default: false },
  },
  emits: ['conflicts', 'navigate'],
  data() {
    return {
      summary: null,
      loading: false,
      loaded: false,
      fetching: false,
      purging: false,
      purgeNote: '',
      error: '',
    };
  },
  computed: {
    connected() {
      return !!(this.summary && this.summary.connected);
    },
    // A purge that failed AFTER clearing some of its list. The edge takes the
    // URLs in batches, so a break partway leaves half the site fresh and half
    // stale — a different thing to tell the owner than "the purge failed", and
    // it needs the counts to be worth telling at all.
    purgePartial() {
      const s = this.summary || {};
      return !!s.lastPurgeError && Number(s.lastPurgeCleared) > 0 && Number(s.lastPurgeCleared) < Number(s.lastPurgeUrls);
    },
    crawlers() {
      return (this.summary && this.summary.crawlers) || [];
    },
    companies() {
      return (this.summary && this.summary.companies) || [];
    },
    totals() {
      return (this.summary && this.summary.totals) || { requests: 0, cached: 0, origin: 0, blocked: 0, bytes: 0 };
    },
    maxCompanyBytes() {
      return this.companies.reduce((m, c) => Math.max(m, Number(c.bytes) || 0), 0);
    },
    // v1 conduct: the crawlers the owner blocks at the origin — are they still
    // knocking at the edge anyway? Computable, honest, and grows teeth later.
    conduct() {
      return this.crawlers.filter((c) => c.blockedByYou);
    },
  },
  watch: {
    // Refetch on EVERY reveal, not just the first: a connect, an un-hidden pin
    // or an hour of cron polling can all change the summary while the owner is
    // on another tab — and the read is one local REST call, never Cloudflare.
    active(on) {
      if (on && !this.loading) this.load();
    },
    // The parent renders the pinned conflict boxes — hand them up on every refresh.
    // The server's per-conflict "dismissed" store now means COLLAPSED on this
    // screen: those ride along flagged, never dropped, so a findings row
    // deep-linking to the pins always lands on evidence at whatever size the
    // owner folded it to (folding a card is tidiness, not a resolution).
    summary(s) {
      const open = (s && s.conflicts) || [];
      const folded = ((s && s.hiddenConflicts) || []).map((c) => ({ ...c, collapsed: true }));
      this.$emit('conflicts', open.concat(folded));
    },
  },
  mounted() {
    if (this.active) this.load();
  },
  methods: {
    async load() {
      if (!this.api || this.loading) return;
      this.loading = true;
      this.error = '';
      try {
        this.summary = await this.api.getCloudflareSummary(7);
        this.loaded = true;
      } catch (e) {
        this.error = e && e.message ? e.message : 'Could not load the edge summary.';
      } finally {
        this.loading = false;
      }
    },
    n(v) {
      return Number(v || 0).toLocaleString();
    },
    // "updated 25m ago" — lastPollAt is a Unix timestamp in seconds.
    agoMin(ts) {
      return relTimeShort(Number(ts || 0) * 1000);
    },
    // Fetch now: one inline poll server-side; the response IS the new summary.
    // A poll failure lands in summary.lastError and shows in this same rail.
    async fetchNow() {
      if (!this.api || this.fetching) return;
      this.fetching = true;
      try {
        this.summary = await this.api.refreshCloudflareSummary(7);
      } catch (e) {
        this.error = e && e.message ? e.message : 'Could not reach Cloudflare.';
      } finally {
        this.fetching = false;
      }
    },
    // Drop everything Cloudflare holds for the zone — the honest fix when an
    // edited page keeps serving stale from the edge. Safe for data: the cache
    // rebuilds as visitors return. Needs the token to carry the optional
    // Cache Purge permission; a refusal lands in lastPurgeError on this rail.
    async purgeEdge() {
      if (!this.api || this.purging) return;
      this.purging = true;
      this.purgeNote = '';
      try {
        const out = await this.api.purgeCloudflareCache();
        if (this.summary) {
          this.summary.lastPurgeAt = out.lastPurgeAt;
          this.summary.lastPurgeError = out.lastPurgeError;
        }
        if (out.ok) this.purgeNote = 'Edge cache cleared — Cloudflare rebuilds it as visitors return.';
      } catch (e) {
        this.error = e && e.message ? e.message : 'Could not reach Cloudflare.';
      } finally {
        this.purging = false;
      }
    },
    fmtBytes(b) {
      b = Number(b) || 0;
      if (b >= 1073741824) return `${(b / 1073741824).toFixed(1)} GB`;
      if (b >= 1048576) return `${(b / 1048576).toFixed(1)} MB`;
      if (b >= 1024) return `${Math.round(b / 1024)} KB`;
      return `${b} B`;
    },
    barWidth(bytes) {
      const max = this.maxCompanyBytes;
      return max > 0 ? `${Math.max(2, Math.round(((Number(bytes) || 0) / max) * 100))}%` : '0%';
    },
    goSettings() {
      this.$emit('navigate', { tab: 'settings', anchor: 'ar-sec-cloudflare' });
    },
  },
};
</script>

<template>
  <div class="ar-edge">
    <!-- A load error before the first summary lands: surface it, not a skeleton. -->
    <p v-if="!loaded && error" class="ar-log__error" role="alert">{{ error }} — try Refresh, and if it persists, reload the page.</p>

    <!-- First reveal in flight: a skeleton, not a blank card — the same placeholder
         the other data screens show while their first fetch is out. -->
    <CardSkeleton v-else-if="!loaded" lead="Loading edge activity…" />

    <!-- Off: one quiet pointer, no form, no nagging. -->
    <section v-else-if="!connected" class="ar-card ar-card--muted">
      <h2 class="ar-card__title">At Cloudflare <span class="ar-card__tag">Off</span></h2>
      <p class="ar-card__lead ar-edge__offlead">
        Cloudflare answers many AI requests before they reach this log. This card can show
        them, and warn you when Cloudflare disagrees with your policy.
        <button type="button" class="ar-linkbtn" @click="goSettings">
          Connect Cloudflare in Settings → Data Sources
        </button>
      </p>
    </section>

    <template v-else>
      <section class="ar-card">
        <h2 class="ar-card__title">At Cloudflare <span class="ar-card__tag">Last {{ summary.days }} days</span></h2>
        <p class="ar-card__lead">
          What Cloudflare saw from AI crawlers before your server did. The log above records
          only the “Reached your server” part — Cloudflare answered the rest from its saved
          copies (its cache), or blocked it.
        </p>

        <!-- One line while it fits; on narrow containers the break element folds
             the rail deliberately — identity, then action, then the warning on
             its own full line — instead of luck deciding where it wraps. -->
        <div class="ar-mcp-rail" data-state="running">
          <span class="ar-mcp-rail__dot" aria-hidden="true"></span>
          <strong>Connected</strong>
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span><span class="ar-edge__zone">{{ summary.zoneName }}</span>
          <span class="ar-edge__railbreak" aria-hidden="true"></span>
          <template v-if="summary.lastPollAt">
            <span class="ar-mcp-rail__sep ar-edge__sep-upd" aria-hidden="true">·</span>
            <span>updated {{ agoMin(summary.lastPollAt) }}</span>
          </template>
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
          <button type="button" class="ar-linkbtn" :disabled="fetching" @click="fetchNow">
            {{ fetching ? 'Refreshing…' : 'Refresh' }}
          </button>
          <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
          <button type="button" class="ar-linkbtn" :disabled="purging" @click="purgeEdge">
            {{ purging ? 'Purging…' : 'Purge edge cache' }}
          </button>
          <template v-if="summary.lastError">
            <span class="ar-mcp-rail__sep ar-edge__sep-warn" aria-hidden="true">·</span>
            <span class="ar-warn">Last poll failed: {{ summary.lastError }} — showing the last good numbers.</span>
          </template>
          <!-- A purge that got PART of the way is its own situation, not a
               milder failure: the pages before the break are fresh and the ones
               after are not, and only a count can say which. It takes the same
               seat as the failure line — bad news never stacks — and names the
               one action that finishes the job, because a permission hint would
               be wrong here (a refused token fails the FIRST batch, clearing
               nothing). -->
          <template v-if="purgePartial">
            <span class="ar-mcp-rail__sep ar-edge__sep-warn" aria-hidden="true">·</span>
            <span class="ar-warn">Last purge cleared {{ summary.lastPurgeCleared }} of {{ summary.lastPurgeUrls }} pages, then failed:
              {{ summary.lastPurgeError }} — the rest still hold old copies. Press Purge edge cache to finish clearing them.</span>
          </template>
          <template v-else-if="summary.lastPurgeError">
            <span class="ar-mcp-rail__sep ar-edge__sep-warn" aria-hidden="true">·</span>
            <!-- The failure names its own fix, at the moment it happens: edit the
                 token (which keeps its secret valid — rolling would not), add the
                 one permission, done. Nobody should need to research this line. -->
            <span class="ar-warn">Last purge failed: {{ summary.lastPurgeError }} — the token may lack the Cache Purge permission.
              <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Edit the token in Cloudflare</a>
              and add Zone → Cache Purge → Purge. Editing keeps the stored token valid.</span>
          </template>
          <template v-else-if="purgeNote">
            <span class="ar-mcp-rail__sep" aria-hidden="true">·</span>
            <span>{{ purgeNote }}</span>
          </template>
        </div>

        <div v-if="!totals.requests" class="ar-wd-empty">
          No AI-crawler traffic in Cloudflare’s numbers yet. The first hourly numbers usually
          appear within the hour.
        </div>
        <template v-else>
          <div class="ar-wd-stats ar-act-stats ar-act-stats--4">
            <div class="ar-wd-stat"><strong>{{ n(totals.requests) }}</strong><span>AI requests Cloudflare saw</span></div>
            <div class="ar-wd-stat"><strong>{{ n(totals.cached) }}</strong><span>Served from Cloudflare’s cache</span></div>
            <div class="ar-wd-stat"><strong>{{ n(totals.origin) }}</strong><span>Reached your server</span></div>
            <div class="ar-wd-stat"><strong :class="{ 'ar-edge__hot': totals.blocked }">{{ n(totals.blocked) }}</strong><span>Blocked by Cloudflare</span></div>
          </div>

          <p class="ar-ai__sub ar-edge__sub">How far each crawler's requests got</p>
          <div class="ar-edge__scroll">
            <table class="ar-act-table ar-act-table--cards">
              <thead>
                <tr>
                  <th scope="col">Crawler</th>
                  <th scope="col" class="ar-edge__n">Total</th>
                  <th scope="col" class="ar-edge__n">From cache</th>
                  <th scope="col" class="ar-edge__n">Reached server</th>
                  <th scope="col" class="ar-edge__n">Blocked</th>
                  <th scope="col" class="ar-edge__n">Data</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in crawlers" :key="c.ua">
                  <!-- One span, not loose text + span: the cards idiom lays the cell
                       out as a flex row, and two sibling items can't wrap as one line. -->
                  <td class="ar-edge__crawler" data-label="Crawler"><span>{{ c.name }} <span v-if="c.operator" class="ar-edge__op"><span class="ar-edge__dash" aria-hidden="true">— </span>{{ c.operator }}</span></span></td>
                  <!-- The glyphs render only in the phone cards (hidden in the wide
                       table): line-art chips in the house ink, no pastel — the one
                       chip allowed a color is Blocked, and only when it counts. -->
                  <td class="ar-edge__n" data-label="Total">
                    <svg class="ar-edge__ico" viewBox="0 0 24 24" aria-hidden="true"><polyline points="3 12 7 12 10 5 14 19 17 12 21 12" /></svg>
                    <span class="ar-edge__v">{{ n(c.requests) }}</span>
                  </td>
                  <td class="ar-edge__n" data-label="From cache">
                    <svg class="ar-edge__ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="4" rx="1" /><path d="M5 8v11h14V8" /><line x1="10" y1="12.5" x2="14" y2="12.5" /></svg>
                    <span class="ar-edge__v">{{ n(c.cached) }}</span>
                  </td>
                  <td class="ar-edge__n" data-label="Reached server">
                    <svg class="ar-edge__ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="1.5" /><rect x="3" y="13" width="18" height="7" rx="1.5" /><line x1="7" y1="7.5" x2="7" y2="7.5" /><line x1="7" y1="16.5" x2="7" y2="16.5" /></svg>
                    <span class="ar-edge__v">{{ n(c.origin) }}</span>
                  </td>
                  <td class="ar-edge__n" data-label="Blocked" :class="{ 'is-bad': c.blocked }">
                    <svg class="ar-edge__ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 7.6-7 9-4-1.4-7-4.5-7-9V6z" /></svg>
                    <span class="ar-edge__v">{{ n(c.blocked) }}</span>
                  </td>
                  <td class="ar-edge__n" data-label="Data">{{ fmtBytes(c.bytes) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <p class="ar-card__note ar-cf-note">
          Cloudflare’s Free plan keeps hourly numbers for a few days only. Agentimus copies them
          into your own database every hour, so this history keeps growing and stays yours.
          On a very busy site the counts are close estimates, not exact: Cloudflare counts
          a sample of requests, not every one.
        </p>
      </section>

      <section v-if="companies.length" class="ar-card">
        <h2 class="ar-card__title">Who Takes What <span class="ar-card__tag">Last {{ summary.days }} days</span></h2>
        <p class="ar-card__lead">
          What each AI company takes from your site — and whether a crawler you block keeps
          knocking anyway.
        </p>
        <div class="ar-ai__cols">
          <div>
            <p class="ar-ai__sub">Data pulled — by company</p>
            <!-- Names its own relationship to the crawler table: the same bytes,
                 one company at a time, so "20.5 MB in both places" reads as a
                 rollup and not a duplicate. -->
            <p class="ar-field__hint ar-edge__subnote">
              The same “Data” as the crawler table above — a company’s crawlers added up.
            </p>
            <ul class="ar-act-rank">
              <li v-for="co in companies" :key="co.operator">
                <span class="ar-act-rank__label">{{ co.operator }}</span>
                <span class="ar-act-rank__track"><span class="ar-act-rank__bar" :style="{ width: barWidth(co.bytes) }"></span></span>
                <span class="ar-act-rank__n">{{ fmtBytes(co.bytes) }}</span>
              </li>
            </ul>
          </div>
          <div>
            <!-- "Crawlers YOU block", not "blocked crawlers": the table's Blocked
                 column means blocked BY CLOUDFLARE — same word, different actor,
                 and the two must not read as one fact. -->
            <p class="ar-ai__sub">Crawlers you block — still knocking?</p>
            <p v-if="!conduct.length" class="ar-field__hint">
              You don’t block any crawlers in your settings, so there is nothing to check
              here yet.
            </p>
            <ul v-else class="ar-edge__conduct">
              <li v-for="c in conduct" :key="c.ua">
                <span class="ar-edge__tag" :class="c.requests ? 'ar-edge__tag--warn' : 'ar-edge__tag--pass'">
                  {{ c.requests ? 'Knocking' : 'Quiet' }}
                </span>
                <span class="ar-edge__who">{{ c.name }}</span>
                <span v-if="c.requests" class="ar-edge__what">made {{ n(c.requests) }} requests even though you block it</span>
              </li>
            </ul>
          </div>
        </div>
        <p class="ar-card__note ar-cf-note">
          This panel reads numbers only. The rules themselves live in your Cloudflare
          dashboard — <a :href="summary.dashUrl" target="_blank" rel="noopener">this link takes you to the right screen</a>.
        </p>
      </section>
    </template>
  </div>
</template>
