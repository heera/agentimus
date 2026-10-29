/**
 * The MCP connection test's verdict on the initialize call.
 *
 * ⭐ Found in the 1.53.4 audit: the card said "Sign-in accepted" for any answer
 * that wasn't 401/403 — a 500 from a fatal error, or a login page a security
 * plugin redirected to. A check may only pass on proof: the sign-in is proven
 * by a real MCP answer, and nothing else.
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { judgeInitialize } from '../../resources/admin/js/mcpTest.js';

const json = { status: 200, ok: true, contentType: 'application/json' };
const good = { result: { protocolVersion: '2025-03-26', serverInfo: { name: 'agentimus', version: '1' } } };

const labels = (v, state) => v.checks.filter((c) => c.state === state).map((c) => c.label);

test('a real MCP answer passes every check', () => {
  const v = judgeInitialize(json, good, 'admin');
  assert.deepEqual(labels(v, 'ok'), ['Server reachable', 'Sign-in accepted', 'MCP handshake (initialize)']);
  assert.equal(v.proceed, true);
});

test('401 says the key was refused', () => {
  const v = judgeInitialize({ status: 401, ok: false }, null, 'admin');
  assert.deepEqual(labels(v, 'fail'), ['Sign-in accepted']);
  assert.equal(v.proceed, false);
});

test('a 500 never claims the sign-in was accepted', () => {
  const v = judgeInitialize({ status: 500, ok: false, contentType: 'text/html' }, null, 'admin');
  assert.ok(!labels(v, 'ok').includes('Sign-in accepted'));
  assert.equal(v.proceed, false);
  assert.match(v.checks.at(-1).note, /HTTP 500/);
  assert.match(v.checks.at(-1).note, /sign-in/i);
});

test('a login page reached by redirect never claims the sign-in was accepted', () => {
  const v = judgeInitialize({ status: 200, ok: true, redirected: true, contentType: 'text/html; charset=UTF-8' }, null, 'admin');
  assert.ok(!labels(v, 'ok').includes('Sign-in accepted'));
  assert.equal(v.proceed, false);
  assert.match(v.checks.at(-1).note, /web page/);
});

test('JSON without an MCP result does not prove the sign-in either', () => {
  const v = judgeInitialize(json, { error: { code: -32600 } }, 'admin');
  assert.ok(!labels(v, 'ok').includes('Sign-in accepted'));
  assert.equal(v.proceed, false);
});

test('404 says the address is wrong, not that the server answered', () => {
  const v = judgeInitialize({ status: 404, ok: false }, null, 'admin');
  assert.deepEqual(labels(v, 'fail'), ['Server reachable']);
  assert.equal(v.checks.length, 1);
});
