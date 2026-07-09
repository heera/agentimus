/**
 * Thin REST helper. Uses the WordPress REST nonce; no extra dependency.
 */
export function createApi(boot) {
  const base = (boot.restUrl || '').replace(/\/$/, '');
  const headers = {
    'Content-Type': 'application/json',
    'X-WP-Nonce': boot.nonce || '',
  };

  async function request(path, options = {}) {
    const res = await fetch(`${base}${path}`, {
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
    getActivity: () => request('/activity'),
    getActivityDay: (date) => request(`/activity/day?date=${encodeURIComponent(date)}`),
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
  };
}
