/**
 * Draws the real At Cloudflare card from its .vue file. A test of the helpers
 * alone cannot see a template that breaks: 1.55.1-dev1 passed those and the card
 * vanished on heera.it, because a new method shared its name with a computed.
 *
 * Run: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parse, compileTemplate, rewriteDefault } from '@vue/compiler-sfc';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

const here = dirname(fileURLToPath(import.meta.url));
const componentDir = resolve(here, '../../resources/admin/components');

// Compile the component to a module. It lives under node_modules so that its
// `import ... from 'vue'` finds the same copy of Vue this test renders with.
async function loadEdgePanel() {
  const { descriptor } = parse(readFileSync(resolve(componentDir, 'EdgePanel.vue'), 'utf8'), { filename: 'EdgePanel.vue' });
  const script = rewriteDefault(descriptor.script.content, '__component')
    .replace(/import CardSkeleton from '[^']+';/, 'const CardSkeleton = { render() { return null; } };')
    .replace(/from '(\.\.\/[^']+)'/g, (_, rel) => `from '${pathToFileURL(resolve(componentDir, rel)).href}'`);
  const { code, errors } = compileTemplate({ source: descriptor.template.content, filename: 'EdgePanel.vue', id: 'edge-panel' });
  assert.deepEqual(errors, []);
  const dir = resolve(here, '../../node_modules/.cache/agentimus-tests');
  mkdirSync(dir, { recursive: true });
  const file = resolve(dir, `EdgePanel.${process.pid}.mjs`);
  writeFileSync(file, `${script}\n${code}\n__component.render = render;\nexport default __component;\n`);
  return (await import(pathToFileURL(file).href)).default;
}

const crawler = (ua, requests, blocked, probes, blockedByYou) => ({
  ua, name: ua, operator: 'Op', requests, cached: 0, origin: requests - blocked, blocked, probes, bytes: 1000, blockedByYou,
});

async function draw(crawlers) {
  const EdgePanel = await loadEdgePanel();
  const summary = {
    connected: true, zoneName: 'example.com', lastPollAt: 1793468854, crawlers, companies: [], conflicts: [], hiddenConflicts: [],
    totals: { requests: 100, cached: 0, origin: 0, blocked: 30, probes: 29, bytes: 1000 },
  };
  const base = EdgePanel.data;
  const drawn = { ...EdgePanel, data() { return { ...base.call(this), summary, loaded: true }; } };
  // Comments out: the renderer wraps fragments in markers no reader ever sees.
  return (await renderToString(createSSRApp(drawn))).replace(/<!--[\s\S]*?-->/g, '');
}

const blockedCell = (html, name) => {
  const row = html.split('<tr').find((r) => r.includes(`<span>${name} `));
  assert.ok(row, `no row for ${name}`);
  return row.slice(row.indexOf('data-label="Blocked"') - 40, row.indexOf('data-label="Data"'));
};

test('the card draws, and the tile still says what the blocks were', async () => {
  const html = await draw([crawler('cohere-ai', 63, 30, 29, false)]);
  assert.match(html, /Blocked by Cloudflare/);
  assert.match(html, /<small>29 scanner probes · 1 other<\/small>/);
});

test('the note above the table says what red marks', async () => {
  const html = await draw([crawler('cohere-ai', 63, 30, 29, false)]);
  assert.match(html, /Red marks other blocked requests using the name of a crawler you allow\./);
});

test('a crawler you allow: only the other blocks are marked red', async () => {
  const html = await draw([crawler('cohere-ai', 63, 30, 29, false)]);
  assert.match(blockedCell(html, 'cohere-ai'), /29 probes<span class="ar-edge__sep"> · <\/span><span class="ar-edge__other">1 other<\/span>/);
});

test('only probes, or a crawler you block: nothing red', async () => {
  const html = await draw([crawler('oai', 395, 20, 20, false), crawler('bytespider', 67, 54, 23, true)]);
  const oai = blockedCell(html, 'oai');
  const bytespider = blockedCell(html, 'bytespider');
  assert.match(oai, /all probes/);
  assert.match(bytespider, /23 probes/);
  assert.doesNotMatch(oai + bytespider, /ar-edge__other|is-bad/);
});
