/**
 * The Abilities screen: its words, the real panel drawn from its .vue file, and a
 * switch that turns back when the save fails.
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
import { abilityLevelLabel, pluginOnLine, switchedOneLine, switchedAllLine } from '../../resources/admin/js/abilities.js';

const here = dirname(fileURLToPath(import.meta.url));
const componentDir = resolve(here, '../../resources/admin/components');

const shop = () => ({
  plugin: 'fixture-shop',
  title: 'Fixture Shop',
  on: 1,
  abilities: [
    { name: 'fixture-shop/list-orders', label: 'List orders', description: 'Lists orders.', level: 'read', on: true },
    { name: 'fixture-shop/update-order', label: 'Update an order', description: 'Changes one.', level: 'write', on: true },
    { name: 'fixture-shop/delete-order', label: 'Delete an order', description: 'Deletes one.', level: 'delete', on: false },
  ],
});

test('levels and counts read in plain words', () => {
  assert.equal(abilityLevelLabel('read'), 'Read');
  assert.equal(abilityLevelLabel('delete'), 'Delete');
  assert.equal(abilityLevelLabel('anything'), 'Write');
  assert.equal(pluginOnLine(shop()), '2 of 3 on');
  assert.equal(pluginOnLine({ abilities: [{ on: false }, { on: false }] }), 'All 2 off');
  assert.equal(pluginOnLine({ abilities: [{ on: true }] }), 'On');
});

async function loadPanel() {
  const { descriptor } = parse(readFileSync(resolve(componentDir, 'AbilitiesPanel.vue'), 'utf8'), { filename: 'AbilitiesPanel.vue' });
  const script = rewriteDefault(descriptor.script.content, '__component')
    .replace(/import (\w+) from '\.\/(\w+)\.vue';/g, 'const $1 = { render() { return null; } };')
    .replace(/from '(\.\.\/[^']+)'/g, (_, rel) => `from '${pathToFileURL(resolve(componentDir, rel)).href}'`);
  const { code, errors } = compileTemplate({ source: descriptor.template.content, filename: 'AbilitiesPanel.vue', id: 'abilities', compilerOptions: { bindingMetadata: {} } });
  assert.deepEqual(errors, []);
  const dir = resolve(here, '../../node_modules/.cache/agentimus-tests');
  mkdirSync(dir, { recursive: true });
  const file = resolve(dir, `AbilitiesPanel.${process.pid}.mjs`);
  writeFileSync(file, `${script}\n${code}\n__component.render = render;\nexport default __component;\n`);
  return (await import(pathToFileURL(file).href)).default;
}

async function draw(extra, props = {}) {
  const Panel = await loadPanel();
  const Wrapped = { ...Panel, data() { return { ...Panel.data.call(this), loaded: true, ...extra }; } };
  const app = createSSRApp(Wrapped, { api: null, active: false, writes: false, ...props });
  app.config.warnHandler = () => {};
  return renderToString(app);
}

test('each plugin lists its abilities with a switch, a level and a Turn all button', async () => {
  const html = await draw({ plugins: [shop()] });
  assert.match(html, /Fixture Shop/);
  assert.match(html, /2 of 3 on/);
  assert.match(html, />Turn all on</);
  assert.equal((html.match(/type="checkbox"/g) || []).length, 3);
  for (const level of ['Read', 'Write', 'Delete']) assert.match(html, new RegExp(`>${level}</span>`));
  assert.match(html, /Every ability starts off/);
});

test('at the Read level, a turned-on ability that writes says it needs Write', async () => {
  const read = await draw({ plugins: [shop()] }, { writes: false });
  assert.equal((read.match(/Needs the Write level to run/g) || []).length, 1, 'Only the one that is on and writes.');
  const write = await draw({ plugins: [shop()] }, { writes: true });
  assert.ok(!write.includes('Needs the Write level'));
});

test('an empty site says so in plain words', async () => {
  assert.match(await draw({ plugins: [] }), /No other plugin offers abilities yet/);
  assert.match(await draw({ plugins: [], available: false }), /no Abilities API/);
});

test('a switch that fails to save turns back to what is stored', async () => {
  const Panel = await loadPanel();
  const flashes = [];
  const vm = {
    ...Panel.data.call({}),
    plugins: [shop()],
    api: { setAbilities: async () => { throw new Error('Server said no.'); } },
    $emit: (...args) => flashes.push(args),
  };
  await Panel.methods.switchAbilities.call(vm, ['fixture-shop/delete-order'], true, 'x');
  assert.equal(vm.plugins[0].abilities[2].on, false, 'The flip is undone.');
  assert.deepEqual(flashes[0], ['flash', 'error', 'Server said no.']);
  assert.equal(vm.busy, '');
});

test('a switch that saves shows what the server stored', async () => {
  const Panel = await loadPanel();
  const stored = shop();
  stored.abilities[2].on = true;
  const vm = {
    ...Panel.data.call({}),
    plugins: [shop()],
    api: { setAbilities: async () => ({ plugins: [stored] }) },
    $emit: () => {},
  };
  await Panel.methods.switchAbilities.call(vm, ['fixture-shop/delete-order'], true, 'x');
  assert.equal(vm.plugins[0].abilities[2].on, true);
});

// heera.it 11-22: the lead's rule and the first plugin's rule made a double line.
test('only the plugins after the first draw their own dividing line', async () => {
  const second = { ...shop(), plugin: 'other', title: 'Other' };
  const html = await draw({ plugins: [shop(), second] });
  assert.equal((html.match(/ar-abil__plugin is-first/g) || []).length, 1);
  assert.equal((html.match(/class="ar-abil__plugin"/g) || []).length, 1);
});

// heera.it 11-22: a sliver showed on every switch mid-save (WordPress draws a
// disabled checkbox), and a saved switch said nothing.
test('mid-save the rows are marked busy and no checkbox is disabled', async () => {
  const html = await draw({ plugins: [shop()], busy: 'fixture-shop/list-orders' });
  assert.ok(!/<input type="checkbox"[^>]*disabled/.test(html), 'No checkbox is disabled.');
  assert.equal((html.match(/class="ar-toggle ar-abil__toggle" data-busy/g) || []).length, 3);
});

test('a saved switch says what changed', async () => {
  const Panel = await loadPanel();
  const flashes = [];
  const vm = {
    ...Panel.data.call({}),
    plugins: [shop()],
    api: { setAbilities: async () => ({ plugins: [shop()] }) },
    $emit: (...args) => flashes.push(args),
  };
  vm.switchAbilities = Panel.methods.switchAbilities;
  Panel.methods.toggle.call(vm, vm.plugins[0].abilities[2]);
  await new Promise((r) => setTimeout(r, 0));
  assert.deepEqual(flashes[0], ['flash', 'success', 'Delete an order is on for agents.']);
  assert.equal(switchedAllLine('core', 3, true), 'All 3 core abilities are on for agents.');
  assert.equal(switchedOneLine('Get Site Information', false), 'Get Site Information is off for agents.');
});
