/**
 * The Blocked count on the At Cloudflare card mostly counts scanners using a
 * crawler's name. The note under it has to say how many were something else, or
 * one such block paints a 30 as if all thirty were the problem (heera.it, cohere-ai,
 * 2026-11-01: 30 blocked, 29 probes). "Other", not "real": Cloudflare counts by the
 * name a request gives, so a non-probe may still not be the crawler.
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { blockedParts, blockedBad, otherCount } from '../../resources/admin/js/edgeBlocked.js';

test('a crawler you allow, mostly probes, names the other blocks apart', () => {
  const c = { blocked: 30, probes: 29, blockedByYou: false };
  assert.deepEqual(blockedParts(c), { probes: '29 probes', other: '1 other' });
  assert.equal(blockedBad(c), true);
});

test('a crawler you allow, blocked with no probes, says so', () => {
  const c = { blocked: 5, probes: 0, blockedByYou: false };
  assert.deepEqual(blockedParts(c), { probes: 'no probes', other: '5 others' });
  assert.equal(blockedBad(c), true);
});

test('one other is singular, more are plural', () => {
  assert.equal(otherCount(1), '1 other');
  assert.equal(otherCount(2), '2 others');
});

test('a crawler you allow, only probes, has nothing else to report', () => {
  const c = { blocked: 20, probes: 20, blockedByYou: false };
  assert.deepEqual(blockedParts(c), { probes: 'all probes', other: '' });
  assert.equal(blockedBad(c), false);
});

test('a crawler you block is your rule working, so nothing else is called out', () => {
  const c = { blocked: 54, probes: 23, blockedByYou: true };
  assert.deepEqual(blockedParts(c), { probes: '23 probes', other: '' });
  assert.equal(blockedBad(c), false);
});

test('nothing blocked says nothing', () => {
  assert.deepEqual(blockedParts({ blocked: 0, probes: 0, blockedByYou: false }), { probes: '', other: '' });
});
