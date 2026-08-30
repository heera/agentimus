/**
 * Thin REST helper. Uses the WordPress REST nonce; no extra dependency.
 */
export function createApi(boot) {
  const base = (boot.restUrl || '').replace(/\/$/, '');
  const headers = {
    'Content-Type': 'application/json',
    'X-WP-Nonce': boot.nonce || '',
  };

  async function requestUrl(url, options = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers,
      ...options,
    });
    if (!res.ok) {
      let message = `Request failed (${res.status})`;
      try {
        const body = await res.json();
        if (body && body.message) message = body.message;
      } catch (e) {
        /* ignore */
      }
      // The status rides along so callers can react to CLASSES of failure
      // (a 404 = the route is gone) without matching localized message text.
      const err = new Error(message);
      err.status = res.status;
      throw err;
    }
    return res.json();
  }

  const request = (path, options = {}) => requestUrl(`${base}${path}`, options);
  return {
    saveSettings: (settings) =>
      request('/settings', { method: 'POST', body: JSON.stringify({ settings }) }),
    resetSettings: () => request('/settings/reset', { method: 'POST' }),
    // Set aside (ignored=true) or restore (false) a page from citability grading.
    // Returns the recomputed score so the worklist, set-aside list, and counts refresh.
    ignoreOptimize: (post, ignored) =>
      request('/optimize/ignore', { method: 'POST', body: JSON.stringify({ post, ignored }) }),
    // Set aside every page a content check flags — the full sampled set, not just
    // the rows the worklist happens to show. Same recomputed-score response.
    ignoreOptimizeIssue: (issue) =>
      request('/optimize/ignore-issue', { method: 'POST', body: JSON.stringify({ issue }) }),
    // Empty the set-aside list: every parked page returns to grading.
    restoreAllOptimize: () => request('/optimize/restore-all', { method: 'POST' }),
    // `payload` carries the skip-path `{ seed: true }` — the server then fills
    // empty identity fields from what the site already says about itself.
    completeOnboarding: (payload) =>
      request('/onboarding', { method: 'POST', body: JSON.stringify(payload || {}) }),
    getReadiness: () => request('/readiness'),
    // Today's list, re-read after the owner acts on something (a Block, an
    // Ignore, a settings save) so the front door catches up without a reload.
    getFindings: () => request('/findings'),
    // "Seen it." Retires the resolved notice early — it expires on its own
    // after a week, which is right for a win nobody read and a week too long
    // for one they have. Answers with the refreshed payload.
    markFindingsSeen: () => request('/findings/seen', { method: 'POST' }),
    // The per-item content worklist. Fetched on demand, never in the boot
    // payload: each row parses a page.
    // The list is ranked and paged on the server now: it covers every published
    // page, not the thirty one request could afford to read, so the tab and the
    // page number have to travel with the ask.
    // `issue` narrows the same list to one check's pages — the by-issue card's
    // "Show me all 60". Empty means the whole bucket.
    getWorklist: (filter = 'fixable', page = 1, issue = '') =>
      request(
        `/worklist?filter=${encodeURIComponent(filter)}&page=${Number(page) || 1}`
          + (issue ? `&issue=${encodeURIComponent(issue)}` : '')
      ),
    getWorklistRows: (ids) => request(`/worklist/rows?ids=${ids.map(Number).join(',')}`),
    // { id: modified } as the screen last saw it → rebuilt rows for whatever
    // has changed since, and nothing when nothing has.
    worklistChanged: (seen) => request('/worklist/changed', { method: 'POST', body: JSON.stringify({ seen }) }),
    getScore: () => request('/score'),
    // JSON-LD preview: the site graph (post omitted / 0) or a chosen post's graph.
    getSchemaPreview: (post = 0) => request(`/preview/schema?post=${encodeURIComponent(post || 0)}`),
    // Markdown preview: the .md twin of a page/post (per-page; site has none).
    getMarkdownPreview: (post = 0) => request(`/preview/markdown?post=${encodeURIComponent(post || 0)}`),
    // Preview targets grouped by post type. `type` + `offset` fetch one group's
    // next batch (the "Load more" button); omit them for the initial, all-types load.
    getSchemaTargets: (search = '', type = '', offset = 0) =>
      request(
        `/preview/targets?search=${encodeURIComponent(search || '')}`
        + `&type=${encodeURIComponent(type || '')}&offset=${Math.max(0, offset | 0)}`,
      ),
    getDiscoveryHub: () => request('/discovery/hub'),
    // MCP-server liveness for the settings card — authenticated, so no unauthenticated
    // probe of the auth-gated MCP endpoint (which logged a console 401). Returns { running }.
    getMcpStatus: () => request('/mcp-status'),
    // The connection token: status is metadata only; create returns the plaintext
    // exactly once (the server keeps a fingerprint), and delete disconnects every client.
    getMcpToken: () => request('/mcp-token'),
    createMcpToken: (scope) =>
      request('/mcp-token', { method: 'POST', body: JSON.stringify({ scope }) }),
    revokeMcpToken: () => request('/mcp-token', { method: 'DELETE' }),
    // Connected agents: one row per OAuth grant the owner approved on the
    // consent page. Revoking cuts off exactly one assistant.
    getOauthGrants: () => request('/oauth/grants'),
    revokeOauthGrant: (clientId) =>
      request('/oauth/grants', { method: 'DELETE', body: JSON.stringify({ clientId }) }),
    getActivity: () => request('/activity'),
    getActivityDay: (date) => request(`/activity/day?date=${encodeURIComponent(date)}`),
    // The AI-traffic screen's own report: a day range (bounded by retention, not the
    // dashboard's 30-day window), optionally narrowed to one assistant and/or a path prefix.
    getAiTraffic: (params = {}) => request(`/activity/ai-traffic?${new URLSearchParams(params)}`),
    getAiTrafficFacets: () => request('/activity/ai-traffic/facets'),
    // One day of AI-referral drill-down (source → page). Fetched on expand, never bundled
    // into the main /activity payload — a busy day holds far more pairings than are shown.
    getAiTrafficDay: (date, params = {}) =>
      request(`/activity/ai-day?${new URLSearchParams({ date, ...params })}`),
    // Filtered, keyset-paged request log. Empty filters are omitted rather than sent
    // blank — the server treats an absent param and an empty one alike, but a clean
    // query string keeps the request readable in the browser's network panel.
    getActivityLog: (params = {}) => {
      const qs = Object.entries(params)
        // ⛔ An empty ARRAY is as absent as an empty string — a multi-pick filter
        // with nothing ticked must not send `agent=`, which reads on the wire
        // like a filter for the empty string.
        .filter(([, v]) => (Array.isArray(v) ? v.length > 0 : v !== '' && v !== null && v !== undefined))
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(Array.isArray(v) ? v.join(',') : v)}`)
        .join('&');
      return request(`/activity/log${qs ? `?${qs}` : ''}`);
    },
    // The distinct clients / endpoints / networks seen in the retained window, so the
    // filters can be dropdowns rather than "type the crawler's exact name".
    getActivityLogFacets: () => request('/activity/log/facets'),
    clearActivity: () => request('/activity', { method: 'DELETE' }),
    blockAgent: (payload) =>
      request('/activity/block', { method: 'POST', body: JSON.stringify(payload) }),
    allowAgent: (payload) =>
      request('/activity/allow', { method: 'POST', body: JSON.stringify(payload) }),
    dismissAgent: (payload) =>
      request('/activity/dismiss', { method: 'POST', body: JSON.stringify(payload) }),
    // Admin "Re-check": run reverse-DNS live now on a flagged client's captured IP(s) ({ ua }).
    // Returns { status, verdict?, perIp?, message?, activity }.
    reverifyBot: (payload) =>
      request('/activity/reverify', { method: 'POST', body: JSON.stringify(payload) }),
    // "Check an IP" tool: engine-agnostic reverse-DNS identity of any address ({ ip }).
    // Returns { ip, host, engine, verdict, slow }.
    checkIp: (ip) =>
      request('/activity/check-ip', { method: 'POST', body: JSON.stringify({ ip }) }),
    // The Verified-bots add form: fetch a candidate IP-ranges URL server-side and
    // confirm it parses as a real range file before the entry is accepted.
    probeRanges: (url) =>
      request('/verifier/probe-ranges', { method: 'POST', body: JSON.stringify({ url }) }),
    // Dismiss the once-per-release "What's new" card.
    markWhatsNewSeen: () => request('/whatsnew-seen', { method: 'POST' }),
    markNextStepsSeen: () => request('/nextsteps-seen', { method: 'POST' }),
    // Answer the review ask: 'review' / 'done' close it for good, 'later' snoozes a month.
    reviewAck: (answer) => request('/review-ack', { method: 'POST', body: JSON.stringify({ answer }) }),

    // One weekly-digest email, right now, to the SAVED recipient — the settings
    // button's proof that the site can actually send mail.
    sendTestDigest: () => request('/digest/test', { method: 'POST' }),
    // The writing assistant. EVERY generating call carries the type, because the
    // type decides the shape the server writes in — an article gets an outline,
    // image slots and taxonomies; a page gets none of them and is told to stop
    // when it's done. A call that forgets to send it silently gets an article,
    // which is the failure worth designing against, so it rides in every
    // signature rather than being looked up somewhere central.
    // What the drawer can write RIGHT NOW. Re-read on open, because the boot
    // payload froze this at page load and a content type ticked in Settings
    // would not appear until a reload.
    assistantState: () => request('/assistant/state'),
    assistantOutline: (prompt, type) =>
      request('/assistant/outline', { method: 'POST', body: JSON.stringify({ prompt, type }) }),
    // …then one structured generation, optionally gated by that approved
    // outline as a contract (writes nothing either way)…
    assistantCompose: (prompt, outline, type) =>
      request('/assistant/compose', {
        method: 'POST',
        body: JSON.stringify(outline ? { prompt, outline, type } : { prompt, type }),
      }),
    // …or the staged pipeline: the CLIENT is the parallelism — it fires one
    // request per part (intro, each outline section, closing) plus one small
    // dressing call, all at once, and assembles them in outline order…
    assistantComposeSection: (prompt, outline, part, index = -1, type) =>
      request('/assistant/compose-section', {
        method: 'POST',
        body: JSON.stringify({ prompt, outline, part, index, type }),
      }),
    assistantComposeMeta: (prompt, outline, type) =>
      request('/assistant/compose-meta', { method: 'POST', body: JSON.stringify({ prompt, outline, type }) }),
    // …one image for one slot, on one explicit click (scene-describe → render →
    // media-library import)…
    assistantGenerateImage: (alt, title) =>
      request('/assistant/generate-image', { method: 'POST', body: JSON.stringify({ alt, title }) }),
    // …a targeted revision of the held draft ("add a section on caching")…
    assistantRefine: (draft, instruction, type) =>
      request('/assistant/refine', { method: 'POST', body: JSON.stringify({ draft, instruction, type }) }),
    // …and the explicit materialise step (drafts/pending only, never publish).
    assistantCreate: (payload) =>
      request('/assistant/create', { method: 'POST', body: JSON.stringify(payload) }),
    // The picker: search what the owner has already written, so a row can open
    // it in the block editor. Read-only — revising something that exists happens
    // there, through the editor's own Ask AI panel, not in the drawer. Filterable
    // by type; an empty filter means every agent-visible type.
    assistantPosts: (q, type) =>
      request(
        '/assistant/posts?q=' + encodeURIComponent(q || '') +
        (type ? '&type=' + encodeURIComponent(type) : '')
      ),
    // The full changelog for the in-admin dialog — parsed from the bundled readme,
    // no outbound call.
    getChangelog: () => request('/changelog'),
    // Short-lived token the readiness live checks carry (X-Agentimus-Selfcheck) so the
    // owner's own anonymous verification fetches stay out of the visit log.
    mintSelfcheckToken: () => request('/activity/selfcheck-token', { method: 'POST' }),
    // The client manager (Settings → AI access): every standing decision in one payload
    // — { blocked: [{token, at, known}], allowed: [...], ignored: [{key, label, at, hits}] }.
    // The two mutators return the same refreshed payload.
    getClients: () => request('/activity/clients'),
    undismissClient: (key) =>
      request('/activity/undismiss', { method: 'POST', body: JSON.stringify({ key }) }),
    removeClientToken: (token, list) =>
      request('/activity/client-remove', { method: 'POST', body: JSON.stringify({ token, list }) }),

    // Integrations: one status read (service configs sans credentials, event
    // catalog, provider presence), and one action door — { service?: webhook |
    // telegram, action: connect | save | disconnect | regenerate }; no service
    // named means the webhook. Its connect and regenerate answer with `secret`
    // exactly once; no other response ever carries a credential (Telegram's
    // token is pasted in and never echoed back).
    getIntegrations: () => request('/integrations'),
    actIntegrations: (payload) =>
      request('/integrations', { method: 'POST', body: JSON.stringify(payload) }),

    // Announcements: one paged read of the ledger (promises first), and one
    // action door — { action: cancel | retry | remove, id, page } — that
    // answers with the same page re-read.
    getAnnouncements: (page = 1) => request(`/announcements?page=${page}`),
    actAnnouncements: (payload) =>
      request('/announcements', { method: 'POST', body: JSON.stringify(payload) }),

    // Agent access: who authenticated to, and acted on, the machine surface. The payload
    // carries its own coverage verdict (see AgentAccess\Events::ability_coverage) because
    // what this screen can HONESTLY see differs per site — an empty feed is not the same
    // fact as "we cannot see anything here", and the UI must never conflate them.
    // Cursor walk, same shape as the request log: `before` is the opaque "last_at~id" cursor of the
    // last row on the page you're leaving. Page numbers would be a lie on a log that grows under you.
    getAgentAccess: (before = '') => request(`/agent-access${before ? `?before=${encodeURIComponent(before)}` : ''}`),
    markAgentAccessSeen: () => request('/agent-access/seen', { method: 'POST' }),
    clearAgentAccess: () => request('/agent-access', { method: 'DELETE' }),

    // The signed-in user's existing application passwords (names and metadata
    // only — core never returns the secrets). Powers the duplicate-name guard.
    listAppPasswords: (endpoint) => requestUrl(endpoint),
    // Mint an application password for the signed-in user via core's own
    // endpoint (absolute URL — it lives in wp/v2, not our namespace; the same
    // wp_rest nonce authenticates it). Core returns the plaintext `password`
    // exactly once, in this response — it is never retrievable again.
    createAppPassword: (endpoint, name) =>
      requestUrl(endpoint, { method: 'POST', body: JSON.stringify({ name }) }),

    // Cloudflare edge data source. The token goes IN through connect and never
    // comes back in any response; status/summary report metadata and numbers only.
    getCloudflareStatus: () => request('/cloudflare'),
    connectCloudflare: (token) =>
      request('/cloudflare', { method: 'POST', body: JSON.stringify({ token }) }),
    disconnectCloudflare: () => request('/cloudflare', { method: 'DELETE' }),
    getCloudflareSummary: (days = 7) => request(`/cloudflare/summary?days=${Math.max(1, days | 0)}`),
    // Fetch now: one inline poll, then the fresh summary in the same response.
    refreshCloudflareSummary: (days = 7) =>
      request(`/cloudflare/refresh?days=${Math.max(1, days | 0)}`, { method: 'POST' }),
    // Purge everything Cloudflare holds for the zone — needs the token to
    // carry the optional Cache Purge permission; a refusal comes back in words.
    purgeCloudflareCache: () => request('/cloudflare/purge', { method: 'POST' }),
    // Hide one conflict pin; it returns only if the conflict ends and recurs.
    dismissCloudflareConflict: (id) =>
      request('/cloudflare/dismiss', { method: 'POST', body: JSON.stringify({ id }) }),
    // Bring a hidden pin back — managed from Settings → Data sources.
    undismissCloudflareConflict: (id) =>
      request(`/cloudflare/dismiss?id=${encodeURIComponent(id)}`, { method: 'DELETE' }),

    // Bing search data source.
    getBingStatus: () => request('/bing'),
    saveBingCode: (code) => request('/bing/code', { method: 'POST', body: JSON.stringify({ code }) }),
    connectBing: (key) => request('/bing', { method: 'POST', body: JSON.stringify({ key }) }),
    disconnectBing: () => request('/bing', { method: 'DELETE' }),
    getGoogleStatus: () => request('/google'),
    connectGoogle: (keyJson) => request('/google', { method: 'POST', body: JSON.stringify({ key_json: keyJson }) }),
    disconnectGoogle: () => request('/google', { method: 'DELETE' }),
    // GA4 is its own grant on the same key: connecting it says nothing about
    // Search Console, and turning it off leaves Search Console untouched.
    connectGoogleAnalytics: (property) => request('/google/analytics', { method: 'POST', body: JSON.stringify({ property }) }),
    disconnectGoogleAnalytics: () => request('/google/analytics', { method: 'DELETE' }),
    refreshGoogleAnalytics: () => request('/google/analytics/refresh', { method: 'POST' }),
    getSearchOpportunities: (source) => request('/search/opportunities' + (source ? `?source=${encodeURIComponent(source)}` : '')),
    getSearchPerformance: (source) => request('/search/performance' + (source ? `?source=${encodeURIComponent(source)}` : '')),
    // Set aside (ignored=true) or restore (false) a page from the SEARCH worklist —
    // its own list, separate from the citability one. `ident` is { post: id } for
    // mapped pages or { url } for pages with no post behind them (the homepage on
    // some sites, an archive). Returns the refreshed report.
    ignoreSearch: (ident, ignored) =>
      request('/search/ignore', { method: 'POST', body: JSON.stringify({ ...ident, ignored }) }),
    // Set aside (dismissed=true) or restore (false) a SEARCH — the string, not a
    // page. The other three ledgers all excuse a page from a kind of work; this
    // excuses a question from being asked of any page, for when the reported
    // search is the problem and setting aside the page it landed on would
    // silence good writing. Returns the refreshed report.
    dismissSearch: (query, dismissed) =>
      request('/search/dismiss', { method: 'POST', body: JSON.stringify({ query, dismissed }) }),
    getBingSummary: (days = 30) => request(`/bing/summary?days=${Math.max(1, days | 0)}`),
    // Ask Bing about ONE page, live — the Bing twin of Google's per-URL Re-check.
    checkBingUrl: (url) => request('/bing/url-check', { method: 'POST', body: JSON.stringify({ url }) }),
    // Refresh: one inline poll, then the fresh summary in the same response.
    refreshBingSummary: (days = 30) =>
      request(`/bing/refresh?days=${Math.max(1, days | 0)}`, { method: 'POST' }),
    // Ask Search Console again, now. The performance numbers are then re-read
    // through getSearchPerformance — this only makes them fresh first.
    refreshGoogleSearch: () => request('/google/refresh', { method: 'POST' }),
    // The Google index watch: stored answers, and an inline "Check now" sweep.
    getGoogleIndex: () => request('/google/index'),
    refreshGoogleIndex: () => request('/google/index', { method: 'POST' }),
    // Stop the run. The queue lives on the server, so the stop has to as well:
    // a cancel that only ended this tab's loop left the continuation event to
    // finish the run anyway.
    cancelGoogleIndex: () => request('/google/index/cancel', { method: 'POST' }),
    lookupGoogleIndex: (url) => request(`/google/index/lookup?url=${encodeURIComponent(url)}`),
    googleIndexProblems: (state, page) => request(`/google/index/problems?state=${encodeURIComponent(state)}&page=${Math.max(1, Number(page) || 1)}`),
    // ONE live inspection, on an explicit click — spends one of the day's 2,000.
    // Answers with the fresh row AND the whole refreshed view, because a single
    // verdict moves the site counts and can empty a problem group.
    checkGoogleIndexUrl: (url) =>
      request('/google/index/check', { method: 'POST', body: JSON.stringify({ url }) }),
    // Remembers that the owner opened a row in Search Console. Writes nothing to
    // Google and asks it nothing — Google keeps no memory of "indexing
    // requested", so our record of the click is the only honest one there is.
    markGoogleIndexOpened: (url) =>
      request('/google/index/opened', { method: 'POST', body: JSON.stringify({ url }) }),

    // Citation checks (the Visibility screen's Citations tenant).
    getVisibilityConfig: () => request('/visibility/config'),
    saveVisibilityConfig: (config) =>
      request('/visibility/config', { method: 'POST', body: JSON.stringify(config) }),
    // The Report screen — one window's worth of what AI did here, from the same
    // producers the weekly email reads.
    // Called with no dates for "today" — the server owns which day that is,
    // because the rows are counted in UTC days and the browser's clock is not.
    // `live: true` asks for the slice that can change minute to minute — reads,
    // visits, impostors, assistant actions — and skips the score, search and
    // citation work behind the rest. Same collector, same window: it is what the
    // dashboard's Today line polls, so it can never drift from the full screen.
    getReport: ({ from, to, live } = {}) => {
      const params = [];
      if (from && to) params.push(`from=${encodeURIComponent(from)}`, `to=${encodeURIComponent(to)}`);
      if (live) params.push('live=1');
      return request(params.length ? `/report?${params.join('&')}` : '/report');
    },
    getVisibilityDashboard: () => request('/visibility/dashboard'),
    // One stored check in full — asked for only when someone opens it, so the
    // dashboard read stays a summary.
    getVisibilityAnswer: (id) => request(`/visibility/answer/${encodeURIComponent(id)}`),
    runVisibility: () => request('/visibility/run', { method: 'POST' }),
    testVisibilityKey: (payload) =>
      request('/visibility/test', { method: 'POST', body: JSON.stringify(payload) }),
    revealVisibilityKey: (payload) =>
      request('/visibility/reveal-key', { method: 'POST', body: JSON.stringify(payload) }),
    clearVisibilityData: () => request('/visibility/clear', { method: 'POST' }),
    suggestVisibility: (payload) =>
      request('/visibility/suggest', { method: 'POST', body: JSON.stringify(payload) }),
    suggestVisibilityAi: (payload) =>
      request('/visibility/suggest-ai', { method: 'POST', body: JSON.stringify(payload) }),
  };
}
