/**
 * Change access on the Connected agents card: the row's words, and the real card
 * drawn from its .vue file with a connection listed and the dialog open.
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
import { connectionAccessLabel } from '../../resources/admin/js/connectionAccess.js';

const here = dirname(fileURLToPath(import.meta.url));
const componentDir = resolve(here, '../../resources/admin/components');

const CHOICES = [
  { key: 'posts', label: 'Write and edit posts', about: 'Drafts.' },
  { key: 'publish', label: 'Publish', about: 'Live.' },
  { key: 'content', label: 'Manage terms, media and the trash', about: 'Terms.' },
];

test('a row names its access in plain words', () => {
  assert.equal(connectionAccessLabel({ preset: 'everything', boxes: [] }, 'write', 6), 'Everything');
  assert.equal(connectionAccessLabel({ preset: 'some', boxes: [] }, 'read', 6), 'Read only');
  assert.equal(connectionAccessLabel({ preset: 'some', boxes: ['posts', 'publish'] }, 'write', 6), 'Only some things · 2 of 6');
});

test('a connection from before access records reads from its scope', () => {
  assert.equal(connectionAccessLabel(undefined, 'write', 6), 'Everything');
  assert.equal(connectionAccessLabel(null, 'read', 6), 'Read only');
});

async function loadCard() {
  const { descriptor } = parse(readFileSync(resolve(componentDir, 'McpServerCard.vue'), 'utf8'), { filename: 'McpServerCard.vue' });
  const script = rewriteDefault(descriptor.script.content, '__component')
    .replace(/import AppLink from '[^']+';/, 'const AppLink = { render() { return null; } };')
    .replace(/import ConnectWizard from '[^']+';/, 'const ConnectWizard = { render() { return null; } };')
    .replace(/from '(\.\.\/[^']+)'/g, (_, rel) => `from '${pathToFileURL(resolve(componentDir, rel)).href}'`);
  const { code, errors } = compileTemplate({ source: descriptor.template.content, filename: 'McpServerCard.vue', id: 'mcp-card-access', compilerOptions: { bindingMetadata: {} } });
  assert.deepEqual(errors, []);
  const dir = resolve(here, '../../node_modules/.cache/agentimus-tests');
  mkdirSync(dir, { recursive: true });
  const file = resolve(dir, `McpServerCardAccess.${process.pid}.mjs`);
  writeFileSync(file, `${script}\n${code}\n__component.render = render;\nexport default __component;\n`);
  return (await import(pathToFileURL(file).href)).default;
}

async function draw(extra) {
  const previous = globalThis.window;
  globalThis.window = globalThis.window || {};
  globalThis.AgentimusData = globalThis.AgentimusData || { restUrl: '', nonce: '' };
  const Card = await loadCard();
  const Wrapped = {
    ...Card,
    data() {
      return { ...Card.data.call(this), mcpSavedEnabled: true, ...extra };
    },
    // The date line needs a browser's document; the row's words are what is tested here.
    methods: { ...Card.methods, grantWhen: () => 'Approved today' },
  };
  const settings = { enable_mcp_server: true, enable_agent_writes: true };
  const app = createSSRApp(Wrapped, { settings, busy: false, active: false });
  app.config.warnHandler = () => {};
  // The dialog is teleported to <body>; SSR hands that part back separately.
  const ctx = {};
  const html = await renderToString(app, ctx);
  globalThis.window = previous;
  return html + Object.values(ctx.teleports || {}).join('');
}

const grant = (access) => ({ clientId: 'c1', name: 'Claude', host: 'claude.ai', scope: 'write', approved: 1, lastUsed: null, access });

test('each connection shows its access and a Change access button', async () => {
  const html = await draw({ oauthGrants: [grant({ preset: 'some', boxes: ['posts'] })], accessChoices: CHOICES });
  assert.match(html, /Only some things · 1 of 3/);
  assert.match(html, />Change access</);
});

test('the dialog offers Everything or the boxes, with the ticked ones checked', async () => {
  const html = await draw({
    oauthGrants: [grant({ preset: 'some', boxes: ['posts'] })],
    accessChoices: CHOICES,
    accessEdit: { kind: 'grant', id: 'c1', name: 'Claude', preset: 'some', boxes: ['posts'] },
  });
  assert.match(html, /What Claude May Do/);
  assert.match(html, /<strong>Everything<\/strong>/);
  assert.match(html, /<strong>Only some things<\/strong>/);
  for (const c of CHOICES) assert.match(html, new RegExp(`<strong>${c.label}</strong>`));
  const box = (label) => html.slice(html.lastIndexOf('<input', html.indexOf(`<strong>${label}</strong>`)), html.indexOf(`<strong>${label}</strong>`));
  assert.match(box('Write and edit posts'), /checked/);
  assert.ok(!/checked/.test(box('Publish')));
});

test('with Everything chosen the boxes are not shown', async () => {
  const html = await draw({
    oauthGrants: [grant({ preset: 'everything', boxes: [] })],
    accessChoices: CHOICES,
    accessEdit: { kind: 'grant', id: 'c1', name: 'Claude', preset: 'everything', boxes: ['posts', 'publish', 'content'] },
  });
  assert.match(html, /What Claude May Do/);
  assert.ok(!html.includes('<strong>Manage terms, media and the trash</strong>'));
});

// heera.it 11-22: the dialog has a fixed height and the six boxes pushed Save out of
// sight. The boxes must sit in the scrolling body, with Save outside it.
test('the boxes scroll and Save stays in view', async () => {
  const html = await draw({
    oauthGrants: [grant({ preset: 'some', boxes: ['posts'] })],
    accessChoices: CHOICES,
    accessEdit: { kind: 'grant', id: 'c1', name: 'Claude', preset: 'some', boxes: ['posts'] },
  });
  const dialog = html.slice(html.indexOf('What Claude May Do'));
  const scroll = dialog.indexOf('class="ar-modal__scroll"');
  const actions = dialog.indexOf('class="ar-modal__actions"');
  assert.ok(scroll > -1, 'The dialog has a scrolling body.');
  assert.ok(scroll < dialog.indexOf('<strong>Write and edit posts</strong>'), 'The boxes are inside it.');
  assert.ok(actions > dialog.indexOf('<strong>Manage terms, media and the trash</strong>'), 'The buttons come after the last box…');
  assert.ok(dialog.slice(actions).includes('>Save'), '…and Save is in the pinned row.');
});
