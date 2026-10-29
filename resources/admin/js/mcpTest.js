/**
 * The connection test's verdict on the first call (initialize): which checks
 * passed, given what the site answered. Kept apart from the card so the
 * judgement can be tested against the answers a broken site really gives.
 *
 * @param {{status:number, ok:boolean, redirected?:boolean, contentType?:string}} res
 * @param {object|null} init The parsed JSON body, or null when it wasn't JSON.
 * @param {string} username The user the key belongs to.
 * @returns {{checks: Array<{state:string,label:string,note?:string}>, proceed: boolean}}
 */
export function judgeInitialize(res, init, username) {
  const checks = [];
  if (res.status === 404) {
    checks.push({ state: 'fail', label: 'Server reachable', note: 'the address returned “not found” — save the settings with the switch on, then retry' });
    return { checks, proceed: false };
  }
  checks.push({ state: 'ok', label: 'Server reachable' });
  if (res.status === 401 || res.status === 403) {
    checks.push({ state: 'fail', label: 'Sign-in accepted', note: `the site refused this key — check the username (${username}) and the key itself; a wrong username fails exactly like a wrong password` });
    return { checks, proceed: false };
  }
  // Anything short of a real MCP answer leaves the sign-in unproven: a 500 is
  // a server that died before it said, and a web page is usually a login
  // redirect or a security plugin standing in front of the endpoint.
  if (!res.ok || !init || !init.result) {
    const page = res.redirected || /text\/html/i.test(res.contentType || '');
    let note;
    if (page) {
      note = `the site answered with a web page (HTTP ${res.status}), not the MCP server — usually a login redirect or a security plugin blocking key sign-ins, so the sign-in could not be confirmed`;
    } else if (res.status >= 500) {
      note = `the server failed (HTTP ${res.status}) before it could confirm the sign-in — the site’s PHP error log names the cause`;
    } else {
      note = `unexpected answer (HTTP ${res.status}), so the sign-in could not be confirmed`;
    }
    checks.push({ state: 'fail', label: 'MCP handshake (initialize)', note });
    return { checks, proceed: false };
  }
  checks.push({ state: 'ok', label: 'Sign-in accepted', note: `as ${username}` });
  const server = init.result.serverInfo || {};
  checks.push({ state: 'ok', label: 'MCP handshake (initialize)', note: [server.name, server.version].filter(Boolean).join(' ') });
  return { checks, proceed: true };
}
