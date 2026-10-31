/**
 * The Cloudflare card keeps the status it loaded. Turning the block switch off
 * and saving removed the rule, then turning it back on showed "10 clients blocked
 * at Cloudflare" when there were none. Caught on heera.it 11-01: "After turned on
 * I see 10 blocked but I did nothing".
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const card = read('../../resources/admin/components/CloudflareCard.vue');
const manager = read('../../resources/admin/components/ClientManager.vue');

test('the card re-reads Cloudflare when the block switch changes', () => {
  assert.match(card, /'settings\.cf_edge_block'\(\)\s*\{\s*this\.refreshStatus\(\);/);
});

test('the manager tells the card what it found when it opens', () => {
  assert.match(manager, /this\.edge = await this\.api\.getEdgeBlocks\(\);\s*this\.\$emit\('edge-changed'\);/);
});

test('the manager explains an unsaved or switched-off Cloudflare block', () => {
  assert.match(manager, /edge\.reason === 'switched_off'/);
});

test('the cache switch no longer promises a read-only token', () => {
  assert.doesNotMatch(card, /keep the token read-only/);
});
