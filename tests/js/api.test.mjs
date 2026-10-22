/**
 * The admin's REST helper, against the answers a broken site really gives.
 *
 * ⭐ Found 2026-10-22: another plugin's fatal error made every REST call come
 * back 200 with an EMPTY body, and the screen showed the browser's own words —
 * "Failed to execute 'json' on 'Response': Unexpected end of JSON input".
 * Every message here must say what happened in plain words, and a WRITE whose
 * answer can't be read must say its outcome is unknown, never that it failed.
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createApi } from '../../resources/admin/js/api.js';

const api = createApi({ restUrl: 'https://site.test/wp-json/agentimus/v1', nonce: 'n' });

// One canned answer per test: status + raw body, as fetch would hand it over.
function answer(status, body, contentType = 'application/json') {
  globalThis.fetch = async () => new Response(body, { status, headers: { 'Content-Type': contentType } });
}

async function failure(call) {
  try {
    await call();
  } catch (e) {
    return e;
  }
  assert.fail('expected the call to throw');
}

test('a good answer is returned as data', async () => {
  answer(200, '{"items":[1]}');
  assert.deepEqual(await api.getFindings(), { items: [1] });
});

test('an empty 200 says the site answered with nothing readable, in plain words', async () => {
  answer(200, '');
  const e = await failure(() => api.getFindings());
  assert.equal(e.kind, 'not_json');
  assert.equal(e.status, 200);
  assert.doesNotMatch(e.message, /Unexpected end of JSON|Failed to execute/);
  assert.match(e.message, /another plugin or a PHP error/);
});

test('a PHP notice printed before the JSON is the same failure, not a parser quote', async () => {
  answer(200, '<br /><b>Warning</b>: something<br />{"ok":true}', 'text/html');
  const e = await failure(() => api.getFindings());
  assert.equal(e.kind, 'not_json');
  assert.doesNotMatch(e.message, /Unexpected token/);
});

test('a write whose answer cannot be read says its outcome is unknown', async () => {
  answer(200, '');
  const e = await failure(() => api.saveSettings({}));
  assert.equal(e.uncertain, true);
  assert.match(e.message, /may or may not have been saved/);
});

test('a read is never marked uncertain', async () => {
  answer(200, '');
  const e = await failure(() => api.getFindings());
  assert.equal(e.uncertain, false);
});

test('an HTML error page names the HTTP status, not "Request failed"', async () => {
  answer(500, '<html><body>Critical error</body></html>', 'text/html');
  const e = await failure(() => api.getFindings());
  assert.equal(e.status, 500);
  assert.match(e.message, /HTTP 500/);
});

test('a JSON error keeps the server’s own message and its status', async () => {
  answer(403, '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that."}');
  const e = await failure(() => api.getFindings());
  assert.equal(e.status, 403);
  assert.equal(e.message, 'Sorry, you are not allowed to do that.');
});

test('an expired page nonce says to reload, not "Cookie check failed"', async () => {
  answer(403, '{"code":"rest_cookie_invalid_nonce","message":"Cookie check failed"}');
  const e = await failure(() => api.getFindings());
  assert.equal(e.kind, 'nonce');
  assert.match(e.message, /Reload the page/);
});

test('no network says the site could not be reached', async () => {
  globalThis.fetch = async () => { throw new TypeError('Failed to fetch'); };
  const e = await failure(() => api.getFindings());
  assert.equal(e.kind, 'network');
  assert.match(e.message, /Couldn’t reach your site/);
});
