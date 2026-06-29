/**
 * Regenerate the 6 WordPress.org screenshots from the REAL built admin app.
 *
 * Prereqs (from the plugin root):
 *   1. npm run build            -> builds assets/admin/app.{js,css}
 *   2. npm i -D puppeteer-core  -> one-off; drives your already-installed Chrome
 *   3. macOS Chrome at the default path below, or set CHROME=/path/to/chrome
 *
 * Run:  node .dev/asset-sources/render-screenshots.mjs
 * Out:  writes screenshot-1.png … screenshot-6.png into .wordpress-org/
 *
 * What each shot shows is driven entirely by ./data.js (identity, activity, the
 * `threats` that fill the nav review-bell, and the `block_*` crawler settings).
 * Paths are derived from this file's location, so it works from any clone.
 */

import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { existsSync } from 'node:fs';
import puppeteer from 'puppeteer-core';

const HERE = dirname(fileURLToPath(import.meta.url)); // .dev/asset-sources
const REPO = resolve(HERE, '..', '..'); // plugin root
const HARNESS = 'file://' + resolve(HERE, 'harness.html');
const OUT = resolve(REPO, '.wordpress-org');
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const W = 1440;

if (!existsSync(resolve(REPO, 'assets/admin/app.js'))) {
  console.error('✗ assets/admin/app.js missing — run `npm run build` first.');
  process.exit(1);
}

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: 'new',
  args: ['--no-sandbox', '--hide-scrollbars', '--force-color-profile=srgb'],
});

async function open(hash) {
  const page = await browser.newPage();
  await page.setViewport({ width: W, height: 1400, deviceScaleFactor: 1 });
  await page.goto(HARNESS + hash, { waitUntil: 'networkidle0' });
  await page.waitForSelector('.ar__bar', { timeout: 8000 });
  await page.evaluate(() => document.fonts && document.fonts.ready);
  await new Promise((r) => setTimeout(r, 900)); // settle ring anim + async activity render
  return page;
}

const clip = (w, h) => ({ x: 0, y: 0, width: Math.round(w), height: Math.round(h) });

// Shots 1 / 3 / 4 — the whole visible tab.
async function fullTab(hash, name) {
  const page = await open(hash);
  const h = await page.evaluate(() => {
    const m = document.querySelector('.ar__main');
    return m ? m.getBoundingClientRect().bottom : document.body.scrollHeight;
  });
  await page.screenshot({ path: `${OUT}/${name}.png`, clip: clip(W, h + 22), captureBeyondViewport: true });
  console.log(name, Math.round(h + 22));
  await page.close();
}

await fullTab('#dashboard', 'screenshot-1');
await fullTab('#readiness', 'screenshot-3');
await fullTab('#discovery', 'screenshot-4');

// The Settings page is now a tabbed panel; pick a group by clicking its sub-nav tab.
async function settingsGroup(page, label) {
  await page.waitForSelector('.ar-subnav__item');
  await page.evaluate((l) => {
    const b = [...document.querySelectorAll('.ar-subnav__item')].find((x) => new RegExp(l, 'i').test(x.textContent));
    if (b) b.click();
  }, label);
  await new Promise((r) => setTimeout(r, 350));
}

// Shot 2 — Settings ▸ Discovery: a toggle per readiness signal + Browser tools (WebMCP).
{
  const page = await open('#settings');
  await settingsGroup(page, 'discovery');
  await page.waitForSelector('#ar-sec-webmcp');
  const h = await page.evaluate(() => document.getElementById('ar-sec-webmcp').getBoundingClientRect().bottom);
  await page.screenshot({ path: `${OUT}/screenshot-2.png`, clip: clip(W, h + 22), captureBeyondViewport: true });
  console.log('screenshot-2', Math.round(h + 22));
  await page.close();
}

// Shot 5 — Settings ▸ AI access: crawler policy + scanner blocking (the only two cards in the group).
{
  const page = await open('#settings');
  await settingsGroup(page, 'ai access');
  await page.waitForSelector('#ar-sec-blocking');
  const h = await page.evaluate(() => document.getElementById('ar-sec-blocking').getBoundingClientRect().bottom);
  await page.screenshot({ path: `${OUT}/screenshot-5.png`, clip: clip(W, h + 22), captureBeyondViewport: true });
  console.log('screenshot-5', Math.round(h + 22));
  await page.close();
}

// Shot 6 — the "Activity to review" nav bell, dropdown open.
{
  const page = await open('#dashboard');
  await page.waitForSelector('.ar__review-btn');
  await page.click('.ar__review-btn');
  await page.waitForSelector('.ar__review-pop', { visible: true });
  await new Promise((r) => setTimeout(r, 250));
  const h = await page.evaluate(() => document.querySelector('.ar__review-pop').getBoundingClientRect().bottom);
  await page.screenshot({ path: `${OUT}/screenshot-6.png`, clip: clip(W, h + 24), captureBeyondViewport: true });
  console.log('screenshot-6', Math.round(h + 24));
  await page.close();
}

// Shot 8 — Settings ▸ Exposure: opt-in, off-by-default controls that limit what
// an anonymous crawler can read about the site. All toggles ship off, so no
// data.js staging is needed — the card renders in its default (everything off) state.
{
  const page = await open('#settings');
  await settingsGroup(page, 'exposure');
  await page.waitForSelector('#ar-sec-exposure');
  const h = await page.evaluate(() => document.getElementById('ar-sec-exposure').getBoundingClientRect().bottom);
  await page.screenshot({ path: `${OUT}/screenshot-8.png`, clip: clip(W, h + 22), captureBeyondViewport: true });
  console.log('screenshot-8', Math.round(h + 22));
  await page.close();
}

await browser.close();
console.log('done → .wordpress-org/screenshot-{1..6,8}.png');
