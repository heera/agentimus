/**
 * An optimistic flip must REVERT on a failed write (his rule, from the edge
 * pins arc). The 1.53.4 audit found two that didn't: "console opened" on the
 * Google index rows and "Set this aside" on worklist rows outside the page.
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { optimistic } from '../../resources/admin/js/optimistic.js';

test('the change paints before the write answers', async () => {
  const row = { openedAt: 0 };
  let release;
  const done = optimistic(row, 'openedAt', 123, () => new Promise((r) => { release = r; }));
  assert.equal(row.openedAt, 123);
  release({ ok: true });
  await done;
  assert.equal(row.openedAt, 123);
});

test('a failed write takes the change back and still reports the failure', async () => {
  const row = { openedAt: 50 };
  await assert.rejects(optimistic(row, 'openedAt', 123, () => Promise.reject(new Error('offline'))), /offline/);
  assert.equal(row.openedAt, 50);
});

test('a write that throws before it starts is a failure too', async () => {
  const row = { setAside: false };
  await assert.rejects(optimistic(row, 'setAside', true, () => { throw new Error('no api'); }));
  assert.equal(row.setAside, false);
});

test('a newer change made meanwhile is not undone by an older failure', async () => {
  const row = { setAside: false };
  let fail;
  const first = optimistic(row, 'setAside', true, () => new Promise((_, r) => { fail = r; }));
  row.setAside = 'newer';
  fail(new Error('late'));
  await assert.rejects(first);
  assert.equal(row.setAside, 'newer');
});
