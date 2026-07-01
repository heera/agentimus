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
    completeOnboarding: () => request('/onboarding', { method: 'POST' }),
    getReadiness: () => request('/readiness'),
    getDiscoveryHub: () => request('/discovery/hub'),
    getActivity: () => request('/activity'),
    getActivityDay: (date) => request(`/activity/day?date=${encodeURIComponent(date)}`),
    clearActivity: () => request('/activity', { method: 'DELETE' }),
    blockAgent: (payload) =>
      request('/activity/block', { method: 'POST', body: JSON.stringify(payload) }),
    allowAgent: (payload) =>
      request('/activity/allow', { method: 'POST', body: JSON.stringify(payload) }),

    // AI Visibility monitoring.
    getVisibilityConfig: () => request('/visibility/config'),
    saveVisibilityConfig: (config) =>
      request('/visibility/config', { method: 'POST', body: JSON.stringify(config) }),
    getVisibilityDashboard: () => request('/visibility/dashboard'),
    runVisibility: () => request('/visibility/run', { method: 'POST' }),
    testVisibilityKey: (payload) =>
      request('/visibility/test', { method: 'POST', body: JSON.stringify(payload) }),
    seedVisibilityDemo: () => request('/visibility/demo/seed', { method: 'POST' }),
    clearVisibilityData: () => request('/visibility/demo/clear', { method: 'POST' }),
  };
}
