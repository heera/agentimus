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

// Detect a shared/edge cache (CDN or reverse proxy) that served a STORED copy of this
// response — meaning the request never reached WordPress, so the agent-activity log never
// saw it and a freshness-sensitive endpoint (the change feed, a page's .md) may be stale.
// Same-origin, so every response header is readable. Returns { via, age } or null.
function detectCache(res) {
  const h = (n) => (res.headers.get(n) || '').toLowerCase();
  const server = h('server');
  const cf = h('cf-cache-status'); // Cloudflare: HIT / MISS / EXPIRED / DYNAMIC / BYPASS
  const ageRaw = res.headers.get('age'); // RFC: seconds since a cache stored it.
  const age = ageRaw === null ? null : parseInt(ageRaw, 10);
  // Varnish / Nginx / LiteSpeed / other proxies advertise a hit/miss here.
  const proxyKeys = ['x-cache', 'x-proxy-cache', 'x-fastcgi-cache', 'x-nginx-cache', 'x-litespeed-cache', 'x-srcache-fetch-status'];
  const proxyHit = proxyKeys.some((k) => /\bhit\b/.test(h(k)));

  let via = '';
  if ('hit' === cf) via = 'Cloudflare';
  else if (proxyHit) via = server.indexOf('cloudflare') !== -1 ? 'Cloudflare' : 'a CDN / reverse proxy';
  else if (Number.isFinite(age) && age > 0) via = server.indexOf('cloudflare') !== -1 ? 'Cloudflare' : 'a shared cache';

  return via ? { via, age: Number.isFinite(age) ? age : null } : null;
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
      // Whether a shared cache served this (so the fetch bypassed WordPress / the log).
      cache: detectCache(res),
    };
  } catch (e) {
    return { key: c.key, label: c.label, url: c.url, ok: false, detail: 'unreachable (blocked, offline, or wrong host)', cache: null };
  }
}

// Run every check concurrently; resolves to the array of result rows.
export function runAll(cfg) {
  return Promise.all(buildChecks(cfg).map(runCheck));
}

// ── Exposed-files self-check ───────────────────────────────────────────────
// Probe the site's OWN URL for files that should never be public (config backups,
// secrets, keys, DB dumps). Browser-side + same-origin, like the checks above — a
// server loopback could slip past Nginx/CDN and miss a real leak. We read only the
// HTTP status and body SIZE to decide exposed-vs-safe; the file's contents are never
// surfaced or stored.

function scanToken() {
  return Math.random().toString(36).slice(2) + Date.now().toString(36);
}

function normalizePath(p) {
  p = String(p || '').trim();
  if (!p) return '';
  try { if (/^https?:\/\//i.test(p)) return new URL(p).pathname; } catch (e) { /* not a URL */ }
  return '/' + p.replace(/^\/+/, '');
}

// Fetch one URL as an anonymous visitor → { ok, status, len }. Never throws (a
// blocked/offline/CORS failure becomes ok:false → graded "couldn't check"). Only the
// response SIZE is used (to tell a real file from a "not found" page) — never its
// contents. Prefer the Content-Length header so a large LEAKED file isn't downloaded;
// fall back to reading the body (small, in practice) only when the header is absent.
async function probePath(url) {
  try {
    const res = await fetch(url, { method: 'GET', credentials: 'omit', cache: 'no-store', redirect: 'follow' });
    // A redirect (→ homepage / login / canonical) or an HTML content type means the
    // server is HANDLING a missing-or-blocked path, not serving the raw file — capture
    // both so gradePath can reject them (a catch-all "/uploads/* → homepage" redirect
    // otherwise reads as an 80 KB "downloadable" file). res.redirected is true when the
    // final response came via one or more redirects.
    const html = (res.headers.get('content-type') || '').toLowerCase().includes('text/html');
    const cl = res.headers.get('content-length');
    if (cl !== null && cl !== '') {
      if (res.body && res.body.cancel) { try { res.body.cancel(); } catch (e) { /* ignore */ } }
      return { ok: true, status: res.status, len: parseInt(cl, 10) || 0, redirected: !!res.redirected, html };
    }
    const body = await res.text();
    return { ok: true, status: res.status, len: body.length, redirected: !!res.redirected, html };
  } catch (e) {
    return { ok: false, status: 0, len: 0, redirected: false, html: false };
  }
}

function humanSize(n) {
  if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
  if (n >= 1024) return (n / 1024).toFixed(1) + ' KB';
  return n + ' B';
}

// Grade one probed path against the "not found" baseline. A site may answer 200 for
// everything (soft 404); a genuinely exposed file returns a body that DIFFERS from that
// baseline, so we compare size rather than trusting the status alone. An exposed file
// that's 0 bytes (a fresh debug.log) is flagged as `empty` — reachable but leaking
// nothing yet, so the UI can treat it as a lesser (amber) concern than a file with data.
function gradePath(path, hit, baseline) {
  if (!hit.ok) return { path, status: null, state: 'skip', empty: false, detail: 'couldn’t check (blocked or offline)' };
  const twoxx = hit.status >= 200 && hit.status < 300;
  const matchesNotFound = baseline.ok && baseline.status === hit.status && Math.abs(hit.len - baseline.len) <= 64;
  if (twoxx && !matchesNotFound) {
    // Before calling it exposed: a response that redirected, or that is an HTML page, is
    // the server HANDLING a missing/blocked path (a catch-all "/uploads/* → homepage"
    // redirect, a soft-404 page) — NOT the raw file. A genuinely exposed file is returned
    // directly with its own, non-HTML content type. Reject these to avoid false positives.
    if (hit.redirected || hit.html) {
      return {
        path,
        status: hit.status,
        state: 'safe',
        empty: false,
        detail: hit.redirected ? 'not a file — redirects to a page' : 'not a file — HTML page',
      };
    }
    const empty = hit.len === 0;
    return {
      path,
      status: hit.status,
      state: 'exposed',
      empty,
      detail: empty ? `HTTP ${hit.status} · empty (0 bytes)` : `HTTP ${hit.status} · ${humanSize(hit.len)} — downloadable`,
    };
  }
  return { path, status: hit.status, state: 'safe', empty: false, detail: `HTTP ${hit.status}` };
}

// Learn the site's "nothing here" baseline from a made-up path, then probe every
// sensitive path concurrently. Resolves (never rejects) to { baseline, results }.
export async function runExposureScan(cfg) {
  const origin = originOf((cfg && cfg.endpoints && (cfg.endpoints.robots || cfg.endpoints.llms)) || '');
  const paths = ((cfg && cfg.exposedPaths) || []).map(normalizePath).filter(Boolean);
  if (!origin || !paths.length) return { baseline: null, results: [] };

  const baseline = await probePath(`${origin}/agentimus-not-a-real-path-${scanToken()}`);
  const results = await Promise.all(paths.map(async (p) => gradePath(p, await probePath(origin + p), baseline)));
  return { baseline, results };
}
