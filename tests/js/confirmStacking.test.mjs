/**
 * A confirmation asked from inside another dialog must open on top of it. Both
 * used the same stacking level and the client manager comes later in the page, so
 * "Add all that pass" opened its confirmation hidden behind the dialog. Caught on
 * heera.it 11-01: "I clicked the button twice, I see no changes".
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../resources/admin/css/app.css', import.meta.url), 'utf8');
const dialog = readFileSync(new URL('../../resources/admin/components/ConfirmDialog.vue', import.meta.url), 'utf8');

const zIndex = (selector) => {
  const start = css.indexOf(`\n${selector} {`);
  assert.notEqual(start, -1, `${selector} has no rule`);
  const body = css.slice(start, css.indexOf('}', start));
  const match = body.match(/z-index:\s*(\d+)/);
  assert.ok(match, `${selector} sets no z-index`);
  return Number(match[1]);
};

test('the confirmation stacks above every ordinary dialog', () => {
  assert.ok(zIndex('.ar-modal--confirm') > zIndex('.ar-modal'));
});

test('the confirmation dialog uses that stacking', () => {
  assert.match(dialog, /class="ar-modal ar-modal--confirm"/);
});
