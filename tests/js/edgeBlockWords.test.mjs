/**
 * After a Block click the row leaves the queue, so this line is the only place
 * the owner learns whether Cloudflare took it too.
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { edgeBlockNotice, edgeAddAllMessage, edgeRowLabel } from '../../resources/admin/js/edgeBlockWords.js';

test('no Cloudflare result says nothing, as a local block always has', () => {
  assert.equal(edgeBlockNotice(undefined), null);
});

test('a name Cloudflare took says both places', () => {
  assert.deepEqual(edgeBlockNotice({ ok: true, refusals: [], error: '' }), {
    type: 'success',
    text: 'Blocked in Agentimus and at Cloudflare.',
  });
});

test('a refused name says it stayed local, and why', () => {
  const notice = edgeBlockNotice({ ok: false, error: '', refusals: [{ code: 'too_short', message: 'Names under 6 characters can be part of another name.' }] });
  assert.equal(notice.type, 'error');
  assert.equal(notice.text, 'Blocked in Agentimus only. Not sent to Cloudflare: Names under 6 characters can be part of another name.');
});

test('a Cloudflare failure says it stayed local, and what Cloudflare said', () => {
  const notice = edgeBlockNotice({ ok: false, refusals: [], error: 'request is not authorized' });
  assert.equal(notice.type, 'error');
  assert.equal(notice.text, 'Blocked in Agentimus only. Cloudflare said: request is not authorized');
});

test('a failure with no words still says it stayed local', () => {
  assert.equal(edgeBlockNotice({ ok: false, refusals: [], error: '' }).text, 'Blocked in Agentimus only. Cloudflare did not take it.');
});

test('blocking several names what goes up and what stays', () => {
  const rows = [
    { token: 'dotbot', status: 'can_add' },
    { token: 'mj12bot', status: 'can_add' },
    { token: 'sqlspider', status: 'on' },
    { token: 'oldbot', status: 'unseen' },
    { token: 'wordpress', status: 'refused' },
  ];
  const text = edgeAddAllMessage(rows);
  assert.match(text, /Block 2 clients at Cloudflare: dotbot, mj12bot\./);
  assert.match(text, /2 stay blocked in Agentimus only: oldbot, wordpress\./);
  assert.doesNotMatch(text, /sqlspider/);
});

test('with nothing left over it does not mention leftovers', () => {
  assert.doesNotMatch(edgeAddAllMessage([{ token: 'dotbot', status: 'can_add' }]), /stay blocked/);
  assert.match(edgeAddAllMessage([{ token: 'dotbot', status: 'can_add' }]), /Block 1 client at Cloudflare/);
});

test('each status has words of its own', () => {
  assert.equal(edgeRowLabel({ status: 'on' }), 'Blocked at Cloudflare');
  assert.equal(edgeRowLabel({ status: 'can_add' }), 'Blocked in Agentimus only');
  assert.equal(edgeRowLabel({ status: 'unseen' }), 'Blocked in Agentimus only. No recent visits');
  assert.equal(edgeRowLabel({ status: 'refused', reason: 'This name overlaps a client you have allowed.' }), 'This name overlaps a client you have allowed.');
});
