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
      throw new Error(message);
    }
    return res.json();
  }

  const request = (path, options = {}) => requestUrl(`${base}${path}`, options);
  return {
    getSettings: () => request('/settings'),
    saveSettings: (settings) =>
      request('/settings', { method: 'POST', body: JSON.stringify({ settings }) }),
    resetSettings: () => request('/settings/reset', { method: 'POST' }),
    // Set aside (ignored=true) or restore (false) a page from citability grading.
    // Returns the recomputed score so the worklist, set-aside list, and counts refresh.
    ignoreOptimize: (post, ignored) =>
      request('/optimize/ignore', { method: 'POST', body: JSON.stringify({ post, ignored }) }),
    completeOnboarding: () => request('/onboarding', { method: 'POST' }),
    getReadiness: () => request('/readiness'),
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
        .filter(([, v]) => v !== '' && v !== null && v !== undefined)
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
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
    // Answer the review ask: 'review' / 'done' close it for good, 'later' snoozes a month.
    reviewAck: (answer) => request('/review-ack', { method: 'POST', body: JSON.stringify({ answer }) }),
    // The writing assistant: first the cheap skeleton (title + sections) the
    // owner can shape before any real writing happens…
    assistantOutline: (prompt) =>
      request('/assistant/outline', { method: 'POST', body: JSON.stringify({ prompt }) }),
    // …then one structured generation, optionally gated by that approved
    // outline as a contract (writes nothing either way)…
    assistantCompose: (prompt, outline) =>
      request('/assistant/compose', {
        method: 'POST',
        body: JSON.stringify(outline ? { prompt, outline } : { prompt }),
      }),
    // …or the staged pipeline: the CLIENT is the parallelism — it fires one
    // request per part (intro, each outline section, closing) plus one small
    // dressing call, all at once, and assembles them in outline order…
    assistantComposeSection: (prompt, outline, part, index = -1) =>
      request('/assistant/compose-section', {
        method: 'POST',
        body: JSON.stringify({ prompt, outline, part, index }),
      }),
    assistantComposeMeta: (prompt, outline) =>
      request('/assistant/compose-meta', { method: 'POST', body: JSON.stringify({ prompt, outline }) }),
    // …one image for one slot, on one explicit click (scene-describe → render →
    // media-library import)…
    assistantGenerateImage: (alt, title) =>
      request('/assistant/generate-image', { method: 'POST', body: JSON.stringify({ alt, title }) }),
    // …a targeted revision of the held draft ("add a section on caching")…
    assistantRefine: (draft, instruction) =>
      request('/assistant/refine', { method: 'POST', body: JSON.stringify({ draft, instruction }) }),
    // …and the explicit materialise step (drafts/pending only, never publish).
    assistantCreate: (payload) =>
      request('/assistant/create', { method: 'POST', body: JSON.stringify(payload) }),
    // Edit-existing: search the owner's posts (each with an honest can-edit
    // verdict), fetch one as an editable document, and the explicit update —
    // which NEVER carries a status: the assistant edits content, not visibility.
    assistantPosts: (q) => request('/assistant/posts?q=' + encodeURIComponent(q || '')),
    assistantPost: (id) => request('/assistant/post/' + id),
    assistantUpdate: (payload) =>
      request('/assistant/update', { method: 'POST', body: JSON.stringify(payload) }),
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

    // AI Visibility monitoring.
    getVisibilityConfig: () => request('/visibility/config'),
    saveVisibilityConfig: (config) =>
      request('/visibility/config', { method: 'POST', body: JSON.stringify(config) }),
    getVisibilityDashboard: () => request('/visibility/dashboard'),
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
