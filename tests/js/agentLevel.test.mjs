/**
 * The MCP card's one question, "What can agents do on this site?", and the real card
 * drawn from its .vue file. Helper tests alone miss a template that breaks: 1.55.1-dev1
 * passed those and blanked a card, because a method shared a computed's name.
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
import { resolveConfirm } from '../../resources/admin/js/confirm.js';
import { LEVELS, levelOf, applyLevel, levelWarning, publishWarning, chooseLevel, customNote, limitFor, CUSTOM } from '../../resources/admin/js/agentLevel.js';

const here = dirname(fileURLToPath(import.meta.url));
const componentDir = resolve(here, '../../resources/admin/components');

// Arguments: MCP server, write tier, publish, newer content tools, the other two extras.
const state = (server, writes, publish, content, extras = false) => ({
  enable_mcp_server: server,
  enable_agent_writes: writes,
  agent_writes_publish: publish,
  agent_writes_content: content,
  agent_writes_settings: extras,
  agent_writes_maintenance: extras,
});

test('there are three levels, and each names what it gives and what it cannot do', () => {
  assert.deepEqual(LEVELS.map((l) => l.key), ['off', 'read', 'write']);
  for (const l of LEVELS) {
    assert.ok(l.name && l.gives && l.limit, l.key);
  }
  assert.match(LEVELS[2].gives, /^Everything in Read, plus write and edit posts and pages/);
});

test('every stored state reads as one level, and publishing does not change the level', () => {
  assert.equal(levelOf(state(false, false, false, false)), 'off');
  assert.equal(levelOf(state(true, false, false, false)), 'read');
  assert.equal(levelOf(state(true, true, false, true)), 'write');
  assert.equal(levelOf(state(true, true, true, true)), 'write');
});

test('saved 1 and 0 read the same as true and false', () => {
  assert.equal(levelOf({ enable_mcp_server: 1, enable_agent_writes: 1, agent_writes_content: 1, agent_writes_publish: 0 }), 'write');
});

test('a site that could write before the merge stays as it is, and gains nothing', () => {
  assert.equal(levelOf(state(true, true, false, false)), CUSTOM);
  assert.equal(levelOf(state(true, true, true, false)), CUSTOM);
});

test('an older setup says what it is and how to change it', () => {
  assert.match(customNote(state(true, true, false, false)), /Agents can write, without the newer tools.*Choose Write to add them/);
  assert.match(customNote(state(true, true, true, false)), /Agents can write and publish, without the newer tools.*Choose Write to add them/);
  assert.equal(customNote(state(true, true, false, true)), '');
});

test('a switch armed under an off parent does not raise the level', () => {
  assert.equal(levelOf(state(false, true, true, true)), 'off');
  assert.equal(levelOf(state(true, false, true, true)), 'read');
});

test('choosing a level sets exactly its switches', () => {
  const off = state(true, true, true, true, true);
  applyLevel(off, 'off');
  assert.deepEqual([off.enable_mcp_server, off.enable_agent_writes, off.agent_writes_content, off.agent_writes_publish], [false, false, false, false]);
  const read = state(true, true, true, true, true);
  applyLevel(read, 'read');
  assert.deepEqual([read.enable_mcp_server, read.enable_agent_writes, read.agent_writes_content, read.agent_writes_publish], [true, false, false, false]);
  const write = state(false, false, false, false);
  applyLevel(write, 'write');
  assert.deepEqual([write.enable_mcp_server, write.enable_agent_writes, write.agent_writes_content, write.agent_writes_publish], [true, true, true, false]);
  assert.equal(levelOf(write), 'write');
});

test('choosing Write never turns publishing on or off by itself', () => {
  const older = state(true, true, true, false);
  applyLevel(older, 'write');
  assert.equal(older.agent_writes_publish, true, 'it was allowed before, and stays');
  const none = state(true, false, false, false);
  applyLevel(none, 'write');
  assert.equal(none.agent_writes_publish, false, 'the riskiest power is never a side effect');
});

test('all three extras fall below Write, and survive inside it', () => {
  const s = state(true, true, true, false, true);
  applyLevel(s, 'write');
  assert.deepEqual([s.agent_writes_publish, s.agent_writes_settings, s.agent_writes_maintenance], [true, true, true]);
  applyLevel(s, 'read');
  assert.deepEqual([s.agent_writes_publish, s.agent_writes_settings, s.agent_writes_maintenance], [false, false, false]);
});

test('an unknown level changes nothing, the old names included', () => {
  for (const level of ['everything', 'edit', 'manage', 'publish']) {
    const s = state(true, true, false, true);
    applyLevel(s, level);
    assert.equal(levelOf(s), 'write', level);
    assert.equal(s.agent_writes_publish, false, level);
  }
});

test('the line under the choice tells the truth about publishing', () => {
  assert.match(limitFor(state(true, true, false, true)), /^Can’t publish or schedule\..*draft or the trash\.$/);
  assert.match(limitFor(state(true, true, true, true)), /^Can publish and schedule, with nobody checking.*draft or the trash\./);
  assert.match(limitFor(state(true, true, false, false)), /^Can’t publish or schedule\..*moving it to draft\.$/);
  assert.match(limitFor(state(true, true, true, false)), /^Can publish and schedule.*moving it to draft\.$/);
  assert.equal(limitFor(state(true, false, false, false)), 'Can’t change anything.');
  assert.equal(limitFor(state(false, false, false, false)), 'No agent can reach this site.');
});

test('only the risky moves ask first', () => {
  const write = state(true, true, false, true);
  assert.ok(levelWarning(write, 'off'), 'off');
  assert.equal(levelWarning(write, 'read'), null, 'nothing is lost: no extras are ticked');
  assert.equal(levelWarning(state(false, false, false, false), 'read'), null);
  assert.equal(levelWarning(state(true, false, false, false), 'write'), null);
  assert.equal(levelWarning(state(true, true, false, false), 'write'), null);
});

test('ticking Publish has its own warning', () => {
  const w = publishWarning();
  assert.equal(w.title, 'Let Agents Publish?');
  assert.equal(w.confirmLabel, 'Allow Publishing');
  assert.equal(w.tone, 'danger');
  assert.match(w.message, /without your review/);
});

test('moving to Read names exactly the extras it clears, Publish included', () => {
  const all = levelWarning(state(true, true, true, true, true), 'read');
  assert.equal(all.title, 'Switch to Read?');
  assert.equal(all.confirmLabel, 'Switch to Read');
  assert.equal(all.message, 'Agents will only be able to read. “Publish without your review”, “Change Agentimus settings” and “Refresh data and purge the cache” will also turn off. If you choose a higher level later, you’ll need to tick them again.');

  const publishOnly = levelWarning(state(true, true, true, true), 'read');
  assert.match(publishOnly.message, /“Publish without your review” will also turn off\. If you choose a higher level later, you’ll need to tick it again\.$/);

  const one = state(true, true, false, true);
  one.agent_writes_maintenance = true;
  const named = levelWarning(one, 'read').message;
  assert.match(named, /“Refresh data and purge the cache” will also turn off/);
  assert.ok(!named.includes('Change Agentimus settings'));
  assert.ok(!named.includes('Publish without'));
});

test('Off names the extras too, but only when ticked', () => {
  assert.match(levelWarning(state(true, true, false, true, true), 'off').message, /Change Agentimus settings/);
  assert.ok(!levelWarning(state(true, true, false, true), 'off').message.includes('Change Agentimus settings'));
  assert.equal(levelWarning(state(true, true, false, true), 'off').tone, 'danger');
});

test('cancelling a risky move changes nothing at all', async () => {
  const s = state(true, true, true, true, true);
  const before = JSON.stringify(s);
  assert.equal(await chooseLevel(s, 'off', async () => false), false);
  assert.equal(await chooseLevel(s, 'read', async () => false), false);
  assert.equal(JSON.stringify(s), before);
});

test('confirming applies the level', async () => {
  const s = state(true, true, false, true, true);
  assert.equal(await chooseLevel(s, 'off', async () => true), true);
  assert.equal(levelOf(s), 'off');
});

test('a safe move applies without asking', async () => {
  const s = state(true, false, false, false);
  let asked = 0;
  assert.equal(await chooseLevel(s, 'write', async () => { asked += 1; return false; }), true);
  assert.equal(asked, 0);
  assert.equal(levelOf(s), 'write');
});

test('choosing the level it is already on does nothing', async () => {
  const s = state(true, true, false, true);
  let asked = 0;
  assert.equal(await chooseLevel(s, 'write', async () => { asked += 1; return true; }), false);
  assert.equal(asked, 0);
});

async function loadCard() {
  const { descriptor } = parse(readFileSync(resolve(componentDir, 'McpServerCard.vue'), 'utf8'), { filename: 'McpServerCard.vue' });
  const script = rewriteDefault(descriptor.script.content, '__component')
    .replace(/import AppLink from '[^']+';/, 'const AppLink = { render() { return null; } };')
    .replace(/from '(\.\.\/[^']+)'/g, (_, rel) => `from '${pathToFileURL(resolve(componentDir, rel)).href}'`);
  const { code, errors } = compileTemplate({ source: descriptor.template.content, filename: 'McpServerCard.vue', id: 'mcp-card', compilerOptions: { bindingMetadata: {} } });
  assert.deepEqual(errors, []);
  const dir = resolve(here, '../../node_modules/.cache/agentimus-tests');
  mkdirSync(dir, { recursive: true });
  const file = resolve(dir, `McpServerCard.${process.pid}.mjs`);
  writeFileSync(file, `${script}\n${code}\n__component.render = render;\nexport default __component;\n`);
  return (await import(pathToFileURL(file).href)).default;
}

async function draw(settings) {
  const previous = globalThis.window;
  globalThis.window = globalThis.window || {};
  globalThis.AgentimusData = globalThis.AgentimusData || { restUrl: '', nonce: '' };
  const Card = await loadCard();
  const app = createSSRApp(Card, { settings, busy: false, active: false });
  app.config.warnHandler = () => {};
  const html = await renderToString(app);
  globalThis.window = previous;
  return html;
}

const inputOf = (checked) => ({ target: { checked } });

function cardContext(settings, radios = []) {
  return {
    settings,
    get agentLevel() { return levelOf(this.settings); },
    $nextTick: async () => {},
    $refs: { agentLevelList: { querySelectorAll: () => radios } },
  };
}

test('the card asks one question and marks the current level', async () => {
  const html = await draw(state(true, true, false, true));
  assert.match(html, /What can agents do on this site\?/);
  for (const name of ['Off', 'Read', 'Write']) {
    assert.match(html, new RegExp(`<strong>${name}</strong>`), name);
  }
  for (const gone of ['<strong>Edit</strong>', '<strong>Manage</strong>', '<strong>Publish</strong>', '<strong>Custom</strong>']) {
    assert.ok(!html.includes(gone), gone);
  }
  assert.equal((html.match(/ar-level__row is-sel/g) || []).length, 1);
});

test('Publish is the first extra, and its box shows the saved state', async () => {
  const on = await draw(state(true, true, true, true));
  const off = await draw(state(true, true, false, true));
  const order = ['Publish without your review', 'Change Agentimus settings', 'Refresh data and purge the cache'].map((t) => on.indexOf(`<strong>${t}</strong>`));
  assert.ok(order[0] > -1 && order[0] < order[1] && order[1] < order[2], order.join());
  const box = (html) => html.slice(html.indexOf('id="ar-feat-agent_writes_publish"'), html.indexOf('<strong>Publish without your review</strong>'));
  assert.match(box(on), /checked/);
  assert.ok(!/checked/.test(box(off)));
});

test('the Publish box does not bind straight to the setting', () => {
  const source = readFileSync(resolve(componentDir, 'McpServerCard.vue'), 'utf8');
  assert.ok(!source.includes('v-model="settings.agent_writes_publish"'), 'a tick must pass through the confirmation');
  assert.ok(!source.includes('v-model="agentLevel"'), 'a level must pass through the confirmation');
});

test('cancelling the Publish tick leaves it unticked and unsaved', async () => {
  const Card = await loadCard();
  const settings = state(true, true, false, true);
  const event = inputOf(true);
  const going = Card.methods.setAgentPublish.call(cardContext(settings), event);
  await new Promise((r) => setTimeout(r, 0));
  resolveConfirm(false);
  await going;
  assert.equal(settings.agent_writes_publish, false);
  assert.equal(event.target.checked, false, 'the box goes back');
});

test('confirming the Publish tick allows it', async () => {
  const Card = await loadCard();
  const settings = state(true, true, false, true);
  const going = Card.methods.setAgentPublish.call(cardContext(settings), inputOf(true));
  await new Promise((r) => setTimeout(r, 0));
  resolveConfirm(true);
  await going;
  assert.equal(settings.agent_writes_publish, true);
});

test('unticking Publish needs no dialog', async () => {
  const Card = await loadCard();
  const settings = state(true, true, true, true);
  await Card.methods.setAgentPublish.call(cardContext(settings), inputOf(false));
  assert.equal(settings.agent_writes_publish, false);
});

test('cancel puts the dot back on the saved level, or the same click could never ask again', async () => {
  const Card = await loadCard();
  const radios = ['off', 'read', 'write'].map((value) => ({ value, checked: 'off' === value }));
  const settings = state(true, true, false, true);
  const going = Card.methods.chooseAgentLevel.call(cardContext(settings, radios), 'off');
  await new Promise((r) => setTimeout(r, 0));
  resolveConfirm(false);
  await going;
  assert.deepEqual(radios.filter((r) => r.checked).map((r) => r.value), ['write']);
  assert.equal(levelOf(settings), 'write');
});

test('the old switches are gone from the card', async () => {
  const html = await draw(state(true, true, false, true));
  for (const old of ['Run the Agentimus MCP server', 'Let connected agents write', 'Let agents publish', 'Let agents manage content']) {
    assert.ok(!html.includes(old), old);
  }
});

test('the line under the choice is a real notice, like the others on these screens', async () => {
  const html = await draw(state(true, true, false, true));
  assert.match(html, /class="ar-card__note ar-card__note--wide ar-level__now"/);
});

test('the settings extra says it is about Agentimus, not WordPress', async () => {
  const html = await draw(state(true, true, false, true));
  assert.match(html, /<strong>Change Agentimus settings<\/strong>/);
  assert.match(html, /Never WordPress’s own settings/);
});

test('an older site shows its state, and no level is marked', async () => {
  const html = await draw(state(true, true, false, false));
  assert.match(html, /<strong>Older setup<\/strong>/);
  assert.match(html, /Choose Write to add them/);
  assert.equal((html.match(/ar-level__row is-sel/g) || []).length, 0);
  assert.match(html, /ar-level__row--custom is-sel/);
});

test('the extras keep their links, and sit dimmed below Write', async () => {
  const on = await draw(state(true, true, false, true, true));
  for (const id of ['ar-feat-agent_writes_publish', 'ar-feat-agent_writes_settings', 'ar-feat-agent_writes_maintenance', 'ar-feat-enable_mcp_server', 'ar-feat-enable_agent_writes']) {
    assert.ok(on.includes(`id="${id}"`), id);
  }
  assert.ok(!/ar-level__extras[^>]*inert/.test(on));
  const off = await draw(state(true, false, false, false));
  assert.match(off, /ar-level__extras[^>]*inert/);
  assert.match(off, /need Write/);
});

test('only the control and its name take a click, not the whole row', () => {
  const css = readFileSync(resolve(here, '../../resources/admin/css/app.css'), 'utf8');
  assert.match(css, /\.ar-level__row, \.ar-level__extra \{ pointer-events: none;/);
  assert.match(css, /\.ar-level__row input, \.ar-level__row strong, \.ar-level__extra input, \.ar-level__extra strong \{ pointer-events: auto;/);
});
