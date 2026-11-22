/**
 * The Skills screen: agent-saved skills wait at the top for the owner, every change
 * confirms itself, a failed switch turns back, and the editor keeps Save in view.
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
import { skillByLabel, waitingSkills } from '../../resources/admin/js/skills.js';

const here = dirname(fileURLToPath(import.meta.url));
const componentDir = resolve(here, '../../resources/admin/components');

const skills = () => [
  { id: 1, name: 'Publishing a post', when: 'Before publishing', steps: 'Add an image.', on: true, by: 'person' },
  { id: 2, name: 'Weekend rule', when: '', steps: 'Never on weekends.', on: false, by: 'agent' },
  { id: 3, name: 'Old note', when: '', steps: 'Off by the owner.', on: false, by: 'person' },
];

test('who wrote a skill, and which wait for the owner', () => {
  assert.equal(skillByLabel('agent'), 'Saved by an agent');
  assert.equal(skillByLabel('person'), 'Written by you');
  assert.deepEqual(waitingSkills(skills()).map((s) => s.id), [2], 'Only an agent’s skill that is off waits.');
});

async function loadPanel() {
  const { descriptor } = parse(readFileSync(resolve(componentDir, 'SkillsPanel.vue'), 'utf8'), { filename: 'SkillsPanel.vue' });
  const script = rewriteDefault(descriptor.script.content, '__component')
    .replace(/import (\w+) from '\.\/(\w+)\.vue';/g, 'const $1 = { render() { return null; } };')
    .replace(/from '(\.\.\/[^']+)'/g, (_, rel) => `from '${pathToFileURL(resolve(componentDir, rel)).href}'`);
  const { code, errors } = compileTemplate({ source: descriptor.template.content, filename: 'SkillsPanel.vue', id: 'skills', compilerOptions: { bindingMetadata: {} } });
  assert.deepEqual(errors, []);
  const dir = resolve(here, '../../node_modules/.cache/agentimus-tests');
  mkdirSync(dir, { recursive: true });
  const file = resolve(dir, `SkillsPanel.${process.pid}.mjs`);
  writeFileSync(file, `${script}\n${code}\n__component.render = render;\nexport default __component;\n`);
  return (await import(pathToFileURL(file).href)).default;
}

async function draw(extra) {
  globalThis.window = globalThis.window || {};
  const Panel = await loadPanel();
  const Wrapped = { ...Panel, data() { return { ...Panel.data.call(this), loaded: true, ...extra }; } };
  const app = createSSRApp(Wrapped, { api: null, active: false });
  app.config.warnHandler = () => {};
  const ctx = {};
  const html = await renderToString(app, ctx);
  return html + Object.values(ctx.teleports || {}).join('');
}

test('an agent’s skill waits at the top with Turn on; the owner’s are below', async () => {
  const html = await draw({ skills: skills() });
  const waitAt = html.indexOf('Waiting for you');
  assert.ok(waitAt > -1);
  assert.ok(html.indexOf('Weekend rule') > waitAt);
  assert.ok(html.indexOf('Weekend rule') < html.indexOf('Publishing a post'), 'Waiting first.');
  assert.equal((html.match(/>Turn on</g) || []).length, 1, 'Only the agent’s skill offers Turn on.');
  assert.match(html, /Saved by an agent/);
  assert.match(html, /Off until you turn it on/);
  assert.equal((html.match(/>Edit</g) || []).length, 3);
  assert.equal((html.match(/>Delete</g) || []).length, 3);
});

test('mid-save the rows are marked busy and no checkbox is disabled', async () => {
  const html = await draw({ skills: skills(), busy: 1 });
  assert.ok(!/<input type="checkbox"[^>]*disabled/.test(html));
  assert.equal((html.match(/ar-skills__toggle" data-busy/g) || []).length, 3);
});

test('an empty screen says what a skill is', async () => {
  assert.match(await draw({ skills: [] }), /No skills yet/);
});

test('the editor keeps its fields in the scrolling body and Save in view', async () => {
  const html = await draw({ skills: [], edit: { id: 0, name: '', when: '', steps: '', on: true } });
  const dialog = html.slice(html.indexOf('Add a Skill'));
  const scroll = dialog.indexOf('class="ar-modal__scroll"');
  assert.ok(scroll > -1 && scroll < dialog.indexOf('id="ar-skill-steps"'));
  assert.ok(dialog.indexOf('class="ar-modal__actions"') > dialog.indexOf('id="ar-skill-steps"'));
  assert.match(dialog, /maxlength="8000"/);
});

test('a switch confirms itself, and turns back if the save fails', async () => {
  const Panel = await loadPanel();
  const flashes = [];
  const ok = { ...Panel.data.call({}), skills: skills(), api: { setSkillOn: async () => ({ skills: skills() }) }, $emit: (...a) => flashes.push(a) };
  ok.take = Panel.methods.take;
  await Panel.methods.switchSkill.call(ok, ok.skills[1]);
  assert.deepEqual(flashes[0], ['flash', 'success', 'Weekend rule is on: agents follow it now.']);

  const bad = { ...Panel.data.call({}), skills: skills(), api: { setSkillOn: async () => { throw new Error('No.'); } }, $emit: (...a) => flashes.push(a) };
  bad.take = Panel.methods.take;
  const row = bad.skills[1];
  await Panel.methods.switchSkill.call(bad, row);
  assert.equal(row.on, false, 'Turned back.');
  assert.deepEqual(flashes[1], ['flash', 'error', 'No.']);
});
