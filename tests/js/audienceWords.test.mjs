/**
 * The Machines card has to name what its number holds. Caught on heera.it 10-30:
 * 12,133 "fetched your agent files" above a Requests screen of tag archives and
 * posts, with page reads recorded.
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { machinesUnit, machinesSplit } from '../../resources/admin/js/audienceWords.js';

const n = (v) => v.toLocaleString('en-US');

test('a count holding page reads does not call itself agent files', () => {
  assert.equal(machinesUnit({ pagesCounted: true }), 'requests to your site');
});

test('a count of agent files alone says so', () => {
  assert.equal(machinesUnit({ pagesCounted: false }), 'fetched your agent files');
});

test('the split names both parts', () => {
  const m = { pagesCounted: true, fetches: 12134, pages: 9800, files: 2334 };
  assert.equal(machinesSplit(m, n), '9,800 pages · 2,334 agent files');
});

test('one of each reads in the singular', () => {
  const m = { pagesCounted: true, fetches: 2, pages: 1, files: 1 };
  assert.equal(machinesSplit(m, n), '1 page · 1 agent file');
});

test('no split when there is nothing to split', () => {
  assert.equal(machinesSplit({ pagesCounted: false, fetches: 166, pages: 0, files: 166 }, n), '');
  assert.equal(machinesSplit({ pagesCounted: true, fetches: 0, pages: 0, files: 0 }, n), '');
});
