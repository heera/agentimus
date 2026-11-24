/**
 * The connect wizard (step F): its rules, its wait, and the real .vue files
 * drawn, since a helper test cannot see a template that breaks.
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
import { APPS, WAIT_MS, recipeFor, snapshot, newConnection, approvedOnly, waitOver } from '../../resources/admin/js/connectWizard.js';

const here = dirname(fileURLToPath(import.meta.url));
const componentDir = resolve(here, '../../resources/admin/components');

async function load(name, stubs = []) {
  const { descriptor } = parse(readFileSync(resolve(componentDir, `${name}.vue`), 'utf8'), { filename: `${name}.vue` });
  let script = rewriteDefault(descriptor.script.content, '__component');
  for (const stub of stubs) {
    script = script.replace(new RegExp(`import ${stub} from '[^']+';`), `const ${stub} = { render() { return null; } };`);
  }
  script = script.replace(/from '(\.\.?\/[^']+)'/g, (_, rel) => `from '${pathToFileURL(resolve(componentDir, rel)).href}'`);
  const { code, errors } = compileTemplate({ source: descriptor.template.content, filename: `${name}.vue`, id: name });
  assert.deepEqual(errors, []);
  const dir = resolve(here, '../../node_modules/.cache/agentimus-tests');
  mkdirSync(dir, { recursive: true });
  const file = resolve(dir, `${name}.${process.pid}.${Math.random().toString(36).slice(2)}.mjs`);
  writeFileSync(file, `${script}\n${code}\n__component.render = render;\nexport default __component;\n`);
  return (await import(pathToFileURL(file).href)).default;
}

async function draw(component, props, state = {}) {
  const base = component.data;
  const drawn = { ...component, data() { return { ...base.call(this), ...state }; } };
  const app = createSSRApp(drawn, props);
  app.directive('tip', {});
  // A string render puts teleported dialogs aside; read them back with the page.
  const ctx = {};
  const html = (await renderToString(app, ctx)) + Object.values(ctx.teleports || {}).join('');
  return html.replace(/<!--[\s\S]*?-->/g, '');
}

const grant = (clientId, approved, extra = {}) => ({ clientId, approved, name: clientId, scope: 'write', access: { preset: 'everything', boxes: [] }, ...extra });

/* ---- the rules -------------------------------------------------------------- */

test('a connection that existed when the wizard opened never ends the wait', () => {
  const snap = snapshot([grant('claude', 100)], 1);
  assert.equal(newConnection(snap, [grant('claude', 100)]), null);
});

test('a new connection ends the wait, and so does an old app approved again', () => {
  const snap = snapshot([grant('claude', 100)], 1);
  assert.equal(newConnection(snap, [grant('claude', 100), grant('cursor', 200)]).clientId, 'cursor');
  assert.equal(newConnection(snap, [grant('claude', 300)]).clientId, 'claude');
});

test('two new connections in one wait: the one approved last is shown', () => {
  const snap = snapshot([], 1);
  assert.equal(newConnection(snap, [grant('a', 300), grant('b', 500), grant('c', 400)]).clientId, 'b');
});

// dev16 on heera.it: Allow was recorded, the hand-back was blocked, and the
// app never signed in. Done must wait for the app, not for the click.
test('an approval whose app has not signed in does not end the wait', () => {
  const snap = snapshot([], 1);
  const waiting = grant('Claude Code', 900, { signedIn: 0 });
  assert.equal(newConnection(snap, [waiting]), null);
  assert.equal(approvedOnly(snap, [waiting]).clientId, 'Claude Code');
  const back = grant('Claude Code', 900, { signedIn: 905 });
  assert.equal(newConnection(snap, [back]).clientId, 'Claude Code');
  assert.equal(approvedOnly(snap, [back]), null);
});

test('the wait runs ten minutes, not less', () => {
  assert.equal(WAIT_MS, 600000);
  assert.equal(waitOver(0, WAIT_MS - 1), false);
  assert.equal(waitOver(0, WAIT_MS), true);
});

test('every app the wizard offers has steps, and Claude Code gets its command with this site', () => {
  for (const a of APPS) assert.ok(recipeFor(a.key, 'https://ex.test/mcp', '').steps.length, a.key);
  assert.equal(recipeFor('claude-code', 'https://ex.test/mcp', '').copy, 'claude mcp add --transport http agentimus https://ex.test/mcp');
});

/* ---- the wait ---------------------------------------------------------------- */

// Runs the component's own tick() against a stand-in `this`.
async function tickWith(ConnectWizard, grants, ctx = {}) {
  const emitted = [];
  const self = {
    step: 3, waitState: 'waiting', checkError: false, found: null,
    _snap: snapshot([grant('claude', 100)], 1), _waitStart: Date.now(),
    api: { getOauthGrants: async () => { if (grants instanceof Error) throw grants; return { grants }; } },
    $emit: (...a) => emitted.push(a),
    stopWait() {}, tick() {},
    ...ctx,
  };
  await ConnectWizard.methods.tick.call(self);
  clearTimeout(self._pollTimer);
  return { self, emitted };
}

test('the wait moves to Done when a new connection is approved, and tells the card', async () => {
  const ConnectWizard = await load('ConnectWizard');
  const { self, emitted } = await tickWith(ConnectWizard, [grant('claude', 100), grant('ChatGPT', 900)]);
  assert.equal(self.step, 4);
  assert.equal(self.found.clientId, 'ChatGPT');
  assert.equal(emitted[0][0], 'connected');
});

test('the wait keeps asking while only old connections are there', async () => {
  const ConnectWizard = await load('ConnectWizard');
  const { self } = await tickWith(ConnectWizard, [grant('claude', 100)]);
  assert.equal(self.step, 3);
  assert.equal(self.waitState, 'waiting');
  assert.ok(self._pollTimer, 'no next ask was scheduled');
});

test('approved but not signed in: the wait stays, and says it waits for the app', async () => {
  const ConnectWizard = await load('ConnectWizard');
  const { self } = await tickWith(ConnectWizard, [grant('claude', 100), grant('Claude Code', 900, { signedIn: 0 })]);
  assert.equal(self.step, 3);
  assert.equal(self.approved.clientId, 'Claude Code');
  const html = await draw(ConnectWizard, { endpoint: 'https://ex.test/mcp' }, { step: 3, pick: 'claude-code', approved: self.approved });
  assert.match(html, /Approved\. Waiting for Claude Code to sign in/);
});

test('after ten minutes the wait stops and offers Check again', async () => {
  const ConnectWizard = await load('ConnectWizard');
  const { self } = await tickWith(ConnectWizard, [grant('claude', 100)], { _waitStart: Date.now() - WAIT_MS });
  assert.equal(self.waitState, 'timeout');
  assert.equal(self._pollTimer, undefined);
});

test('a failed ask says so and keeps waiting', async () => {
  const ConnectWizard = await load('ConnectWizard');
  const { self } = await tickWith(ConnectWizard, new Error('offline'));
  assert.equal(self.checkError, true);
  assert.equal(self.waitState, 'waiting');
  assert.ok(self._pollTimer);
});

/* ---- drawn ------------------------------------------------------------------- */

test('step 1 draws every app', async () => {
  const html = await draw(await load('ConnectWizard'), { endpoint: 'https://ex.test/mcp' });
  assert.match(html, /Connect an Assistant/);
  for (const a of APPS) assert.ok(html.includes(`>${a.label}<small>`), a.label);
});

test('step 2 draws the address and the picked app’s steps', async () => {
  const html = await draw(await load('ConnectWizard'), { endpoint: 'https://ex.test/mcp' }, { step: 2, pick: 'claude-code' });
  assert.match(html, /Add This Site to Claude Code/);
  assert.match(html, /claude mcp add --transport http agentimus https:\/\/ex\.test\/mcp/);
  assert.match(html, /I’ve added it/);
});

test('Done names the connection, its access and Change access', async () => {
  const html = await draw(
    await load('ConnectWizard'),
    { endpoint: 'https://ex.test/mcp', accessChoiceCount: 7 },
    { step: 4, pick: 'claude', waitState: 'done', found: grant('Claude', 900) },
  );
  assert.match(html, /Claude Is Connected/);
  assert.match(html, /Access: <strong>Everything<\/strong>/);
  assert.match(html, /Change access/);
});

test('the MCP card draws with its Connect an assistant button', async () => {
  const McpServerCard = await load('McpServerCard', ['AppLink', 'ConnectWizard', 'SelectMenu']);
  const html = await draw(McpServerCard, {
    settings: { enable_mcp_server: true, enable_agent_writes: true },
    mcpServer: { endpoint: 'https://ex.test/mcp' },
  });
  assert.match(html, /Connected assistants/);
  assert.match(html, /Connect an assistant<\/button>/);
});

test('a row whose app never signed in says so, not "not used yet"', async () => {
  const McpServerCard = await load('McpServerCard', ['AppLink', 'ConnectWizard', 'SelectMenu']);
  // wpDate reads the site's date settings and language from the page.
  globalThis.window = globalThis.window || {};
  globalThis.document = globalThis.document || { documentElement: { lang: 'en' } };
  const g = (signedIn) => ({ clientId: 'x', name: 'X', approved: 1764000000, lastUsed: null, signedIn });
  assert.match(McpServerCard.methods.grantWhen.call({}, g(0)), /never signed in$/);
  assert.match(McpServerCard.methods.grantWhen.call({}, g(1764000100)), /not used yet$/);
  assert.match(McpServerCard.methods.grantWhen.call({}, g(null)), /not used yet$/);
});

// dev14 on heera.it: the dialog was named .ar-connect, a name the setup
// wizard's connect box already used, so the panel drew as that dashed box
// laid out in a row. The wizard's own classes must be styled only in its
// own section of the stylesheet.
test('the wizard’s own classes are styled nowhere else', () => {
  const css = readFileSync(resolve(here, '../../resources/admin/css/app.css'), 'utf8');
  const start = css.indexOf('/* ---- Connect wizard (ConnectWizard.vue');
  assert.ok(start > 0, 'the wizard’s CSS section is missing');
  // The section runs to the next section heading, or the end of the file.
  const next = css.indexOf('/* ---- ', start + 10);
  const end = next === -1 ? css.length : next;
  const own = css.slice(start, end);
  const rest = css.slice(0, start) + css.slice(end);
  // The classes this section styles first in a selector are its own; ones it
  // only reaches through (.ar-modal__head under the panel) are shared on purpose.
  const mine = [...new Set([...own.matchAll(/(?:^|[,}]\s*)\.(ar-[\w-]+)/gm)].map((m) => m[1]))];
  assert.ok(mine.length > 3, 'found too few of the wizard’s classes to check');
  for (const c of mine) {
    assert.equal(new RegExp(`\\.${c}(?![\\w-])`).test(rest), false, `.${c} is also styled outside the wizard`);
  }
});

/* ---- step G: pause and the call limit, on the card ---------------------------- */

const drawCard = async (state) => draw(await load('McpServerCard', ['AppLink', 'ConnectWizard', 'SelectMenu']), {
  settings: { enable_mcp_server: true, enable_agent_writes: true, mcp_call_limit: 0 },
  mcpServer: { endpoint: 'https://ex.test/mcp' },
}, state);

test('each connection row has Pause, and a paused one says Paused and offers Resume', async () => {
  const html = await drawCard({
    oauthGrants: [
      { clientId: 'a', name: 'Claude', approved: 1764000000, scope: 'write', access: { preset: 'everything', boxes: [] }, paused: false },
      { clientId: 'b', name: 'Cursor', approved: 1764000000, scope: 'write', access: { preset: 'everything', boxes: [] }, paused: true },
    ],
  });
  const rows = html.split('class="ar-grant"').slice(1);
  assert.equal(rows.length, 2);
  assert.match(rows[0], />Pause<\/button>/);
  assert.doesNotMatch(rows[0], />Paused</);
  assert.match(rows[1], /is-paused[^>]*>Paused</);
  assert.match(rows[1], />Resume<\/button>/);
});

test('the call limit is one choice on the card, off by default', async () => {
  const html = await drawCard({});
  assert.match(html, /id="ar-feat-mcp_call_limit"/);
  assert.match(html, /Calls per minute, per connection/);
  const card = await load('McpServerCard', ['AppLink', 'ConnectWizard', 'SelectMenu']);
  const opts = card.computed.callLimitOptions.call({});
  assert.deepEqual(opts.map((o) => o.value), [0, 30, 60, 120, 300]);
  assert.equal(opts[0].label, 'No limit');
});

test('Pause sends the opposite of the row’s state, for that row', async () => {
  const card = await load('McpServerCard', ['AppLink', 'ConnectWizard', 'SelectMenu']);
  const sent = [];
  const self = {
    pauseBusy: '', oauthError: '', oauthGrants: [], mcpToken: null,
    api: { changeConnectionPause: async (...a) => { sent.push(a); return { grants: [{ clientId: 'a', paused: true }] }; } },
  };
  await card.methods.togglePause.call(self, { kind: 'grant', id: 'a', paused: false });
  assert.deepEqual(sent[0], ['grant', 'a', true]);
  assert.equal(self.oauthGrants[0].paused, true);
  await card.methods.togglePause.call(self, { kind: 'token', id: 'shared-token', paused: true });
  assert.deepEqual(sent[1], ['token', '', false]);
});
