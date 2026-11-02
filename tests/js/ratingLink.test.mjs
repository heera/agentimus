/**
 * Clicking the footer's rating stars ends the ask, like answering the Dashboard's prompt.
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { bindRatingLink } from '../../resources/admin/js/ratingLink.js';

function fakeDocument() {
  const handlers = [];
  return {
    addEventListener: (type, fn) => handlers.push({ type, fn }),
    click: (target) => handlers.filter((h) => 'click' === h.type).forEach((h) => h.fn({ target })),
    handlers,
  };
}
const inside = (selector) => ({ closest: (s) => (s === selector ? {} : null) });

test('a click on the stars records the answer, once per click', () => {
  const doc = fakeDocument();
  const answers = [];
  bindRatingLink(doc, (a) => { answers.push(a); return Promise.resolve(); });
  doc.click(inside('.agentimus-rating-link'));
  assert.deepEqual(answers, ['review']);
});

test('a click anywhere else does nothing', () => {
  const doc = fakeDocument();
  const answers = [];
  bindRatingLink(doc, (a) => { answers.push(a); return Promise.resolve(); });
  doc.click(inside('.something-else'));
  doc.click({});
  assert.deepEqual(answers, []);
});

test('a failed save never stops the link opening or throws', async () => {
  const doc = fakeDocument();
  bindRatingLink(doc, () => Promise.reject(new Error('offline')));
  doc.click(inside('.agentimus-rating-link'));
  await new Promise((r) => setTimeout(r, 0));
});

test('it never cancels the click, so the review page still opens', () => {
  const doc = fakeDocument();
  bindRatingLink(doc, () => Promise.resolve());
  let cancelled = false;
  const event = { target: inside('.agentimus-rating-link'), preventDefault: () => { cancelled = true; } };
  doc.handlers[0].fn(event);
  assert.equal(cancelled, false);
});
