// Live self-verification. The readiness checks elsewhere infer from settings;
// this fetches the site's own agent endpoints from the ADMIN BROWSER and grades
// what an agent actually receives — through the real public URL (and any CDN in
// front of it), which a server-side loopback would bypass. The server makes no
// request here, so the plugin's zero-outbound guarantee is untouched: this runs
// only when you click "Verify live", in your browser, same-origin.

function originOf(url) {
  try {
    return new URL(url).origin;
  } catch (e) {
    return '';
  }
}

// Translate boot config into the list of { key, label, url, expect } to fetch.
// Mirrors what each feature, when enabled, is supposed to serve.
export function buildChecks(cfg) {
  const ep = (cfg && cfg.endpoints) || {};
  const de = (cfg && cfg.discovery && cfg.discovery.endpoints) || {};
  const s = (cfg && cfg.settings) || {};
  const origin = originOf(ep.robots || ep.llms || '');
  const wellKnown = (name) => (origin ? `${origin}/.well-known/${name}` : '');
  // ai_train false (or unset) means training is reserved → tdmrep.json is served.
  const reserved = !((s.content_signal || {}).ai_train);
  const samplePost = (cfg && cfg.samplePost) || '';

  const checks = [];
  const add = (key, label, url, expect) => { if (url) checks.push({ key, label, url, expect }); };

  add('robots', 'robots.txt', ep.robots, { status: 200, type: 'text/plain' });
  if (s.enable_llms_txt !== false) add('llms', '/llms.txt', ep.llms, { status: 200, type: 'text/plain', cors: true });
  if (s.enable_llms_full) add('llms_full', '/llms-full.txt', ep.llmsFull, { status: 200, type: 'text/plain', cors: true });
  if (s.enable_changes !== false) add('changes', '/agentimus-changes.json', origin ? `${origin}/agentimus-changes.json` : '', { status: 200, type: 'application/json', json: true, cors: true });
  add('discovery', '/.well-known/discovery.json', de.discovery, { status: 200, type: 'application/json', json: true, cors: true });
  add('agent_card', '/.well-known/agent-card.json', de.agentCard, { status: 200, type: 'application/json', json: true, cors: true });
  add('mcp', '/.well-known/mcp.json', de.mcp, { status: 200, type: 'application/json', json: true, cors: true });
  if (reserved && s.enable_tdmrep !== false) add('tdmrep', '/.well-known/tdmrep.json', wellKnown('tdmrep.json'), { status: 200, type: 'application/json', json: true });
  if (s.enable_security_txt) add('security', '/.well-known/security.txt', wellKnown('security.txt'), { status: 200, type: 'text/plain' });
  if (samplePost && s.enable_markdown !== false) {
    add('markdown', 'Page markdown (.md)', samplePost.replace(/\/+$/, '') + '.md', { status: 200, type: 'text/markdown', cors: true });
    add('md_link', 'Markdown link advertised', samplePost, { status: 200, header: { name: 'link', includes: 'text/markdown' } });
  }
  return checks;
}

// Fetch one check and grade it. Resolves (never rejects) to a result row; a
// network/CORS failure becomes an "unreachable" fail rather than throwing.
export async function runCheck(c) {
  try {
    // credentials omitted → the anonymous view an agent gets, not the logged-in
    // admin's. Same-origin, so every response header is readable.
    const res = await fetch(c.url, { method: 'GET', credentials: 'omit', cache: 'no-store', redirect: 'follow' });
    const ct = (res.headers.get('content-type') || '').toLowerCase();
    const problems = [];

    if (c.expect.status && res.status !== c.expect.status) problems.push(`HTTP ${res.status}`);
    if (c.expect.type && !ct.includes(c.expect.type)) problems.push(`type ${ct.split(';')[0] || 'none'}`);
    if (c.expect.cors && res.headers.get('access-control-allow-origin') !== '*') problems.push('no CORS header');
    if (c.expect.json) {
      try { await res.clone().json(); } catch (e) { problems.push('invalid JSON'); }
    }
    if (c.expect.header) {
      const val = (res.headers.get(c.expect.header.name) || '').toLowerCase();
      if (!val.includes(c.expect.header.includes.toLowerCase())) problems.push(`no ${c.expect.header.name} advertised`);
    }

    // For a header-presence check the fetched resource's own type is incidental;
    // report what we actually confirmed ("link present") instead.
    const okDetail = c.expect.header
      ? `${res.status} · ${c.expect.header.name} present`
      : `${res.status} · ${ct.split(';')[0] || 'ok'}`;

    return {
      key: c.key,
      label: c.label,
      url: c.url,
      ok: problems.length === 0,
      detail: problems.length ? problems.join(' · ') : okDetail,
    };
  } catch (e) {
    return { key: c.key, label: c.label, url: c.url, ok: false, detail: 'unreachable (blocked, offline, or wrong host)' };
  }
}

// Run every check concurrently; resolves to the array of result rows.
export function runAll(cfg) {
  return Promise.all(buildChecks(cfg).map(runCheck));
}
