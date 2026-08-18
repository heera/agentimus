/**
 * Capture the post-editor "Agentimus" meta box for .wordpress-org/screenshot-12.png.
 *
 * The SPA rig (render-screenshots.mjs) cannot do this one: the panel is a real
 * WordPress meta box inside Gutenberg, not the admin SPA, and it enqueues its
 * OWN stylesheet handle rather than app.css.
 *
 * Prereqs, from the plugin root:
 *   npm run build
 *   npm i --no-save puppeteer-core
 *   Mint cookies into /tmp/cookies.txt (auth AND logged_in, from ONE session token).
 *
 * Run:  POST=3294 OUT=/tmp/shot12@2x.png node .dev/asset-sources/editor-screenshot.mjs
 * Then: sips --resampleWidth 1440 /tmp/shot12@2x.png --out .wordpress-org/screenshot-12.png
 *
 * WARNING: pick a post that WARNS on something, or the frame is fourteen green
 * ticks and demonstrates nothing. Post 3294 warns on two, the featured image
 * among them.
 */
import puppeteer from 'puppeteer-core';
import { readFileSync } from 'node:fs';

const POST = process.env.POST || '3294';
const OUT  = process.env.OUT  || '/tmp/screenshot-12@2x.png';
const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const cookies = readFileSync('/tmp/cookies.txt', 'utf8').trim().split('\n').map((l) => {
  const [name, value] = l.split('\t');
  return { name, value, domain: 'wpftest.test', path: '/' };
});

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: 'new',
  // ⚠️ Chrome's own async DNS does not consult /etc/hosts for .test here, so
  // navigation to wpftest.test times out with no error worth reading. Map it
  // explicitly. (curl resolves it fine, which is what makes this confusing.)
  args: ['--no-sandbox', '--hide-scrollbars', '--force-color-profile=srgb',
         '--host-resolver-rules=MAP wpftest.test 127.0.0.1'],
});
const page = await browser.newPage();
page.setDefaultTimeout(30000);
await page.setViewport({ width: 1440, height: 1600, deviceScaleFactor: 2 });
// ⭐ Force LIGHT explicitly — "system" resolves against the headless browser.
await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);
await page.setCookie(...cookies);

// ⚠️ NOT networkidle — Gutenberg's Heartbeat polls forever, so the editor
// never goes idle and the capture hangs indefinitely. Load, then wait on
// the elements we actually need.
await page.goto(`http://wpftest.test/wp-admin/post.php?post=${POST}&action=edit`, { waitUntil: 'domcontentloaded', timeout: 60000 });
console.log('loaded:', page.url());
if (/wp-login/.test(page.url())) { console.error('AUTH FAILED — cookies rejected'); await browser.close(); process.exit(1); }

// The block editor renders DARK on wpftest (the theme's editor styles).
await page.evaluate(() => {
  try { wp.data.dispatch('core/preferences').set('core/edit-post', 'themeStyles', false); } catch (e) {}
  try { wp.data.dispatch('core/preferences').set('core', 'themeStyles', false); } catch (e) {}
});

// ⚠️ Gutenberg collapses the meta-box drawer to 0px. Open it by its own button.
await page.waitForSelector('.edit-post-meta-boxes-main, .editor-meta-boxes-main', { timeout: 30000 });
await page.evaluate(() => {
  const btn = [...document.querySelectorAll('button')].find(
    (b) => /meta boxes/i.test(b.textContent || '') && b.getAttribute('aria-expanded') === 'false'
  );
  if (btn) btn.click();
});
await new Promise((r) => setTimeout(r, 1200));

await page.waitForSelector('#agentimus-panel .agentimus-panel__tabs', { timeout: 30000 });
await page.waitForFunction(
  () => document.querySelectorAll('#agentimus-panel .agentimus-pc__row').length >= 10,
  { timeout: 30000 }
);

await page.addStyleTag({ content: `
  /* ⛔ Never overflow:visible on .interface-interface-skeleton__content — it
     un-clips the editor iframe, which then paints over the meta box. */
  .edit-post-meta-boxes-main, .editor-meta-boxes-main { height: auto !important; max-height: none !important; }
  .handle-actions { display: none !important; }   /* hover-only reorder arrows */
  #agentimus-panel .postbox-header { border-bottom: 1px solid #c3c4c7; }
` });
await page.evaluate(() => document.fonts && document.fonts.ready);
await new Promise((r) => setTimeout(r, 800));

const el = await page.$('#agentimus-panel');
const box = await el.boundingBox();
console.log('captured box:', JSON.stringify({ w: Math.round(box.width), h: Math.round(box.height) }));
await el.screenshot({ path: OUT });
const rows = await page.evaluate(() => document.querySelectorAll('#agentimus-panel .agentimus-pc__row').length);
const warns = await page.evaluate(() => document.querySelectorAll('#agentimus-panel .agentimus-pc__row.is-warn').length);
console.log('rows:', rows, 'warns:', warns);
await browser.close();
