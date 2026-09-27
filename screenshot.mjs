import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const screenshotsDir = path.join(__dirname, 'temporary screenshots');
if (!fs.existsSync(screenshotsDir)) fs.mkdirSync(screenshotsDir, { recursive: true });

const url = process.argv[2] || 'http://localhost:3011';
const label = process.argv[3] ? `-${process.argv[3]}` : '';

// Find next N
const existing = fs.readdirSync(screenshotsDir)
  .map(f => { const m = f.match(/^screenshot-(\d+)/); return m ? parseInt(m[1]) : 0; });
const n = existing.length ? Math.max(...existing) + 1 : 1;
const filename = `screenshot-${n}${label}.png`;
const outPath = path.join(screenshotsDir, filename);

const browser = await puppeteer.launch({
  headless: true,
  args: ['--no-sandbox', '--disable-setuid-sandbox'],
});
const page = await browser.newPage();
// deviceScaleFactor kept at 1: Chromium's fullPage capture tiling has a known bug
// where scale factors > 1 on very tall pages duplicate/repeat sections of content.
await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 1 });
await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
// Force-load lazy images: Chromium leaves un-loaded loading="lazy" images
// blank in a fullPage capture, so promote them to eager and wait (bounded).
await page.evaluate(() => {
  document.querySelectorAll('img[loading="lazy"]').forEach(i => { i.loading = 'eager'; });
});
await page.evaluate(() => {
  const pending = Array.from(document.images).filter(i => !i.complete);
  const settled = Promise.all(pending.map(i => new Promise(res => {
    i.addEventListener('load', res, { once: true });
    i.addEventListener('error', res, { once: true });
  })));
  return Promise.race([settled, new Promise(res => setTimeout(res, 8000))]);
});
await new Promise(r => setTimeout(r, 1200));
// Trigger all reveal animations
await page.evaluate(() => {
  document.querySelectorAll('.reveal').forEach(el => el.classList.add('visible'));
});
await new Promise(r => setTimeout(r, 600));
await page.screenshot({ path: outPath, fullPage: true });
// Note: cross-origin iframes (e.g. an embedded map) that are off-screen can stay
// blank in a fullPage capture even after loading — a Chromium compositing quirk,
// not a site bug. Verify those separately with a clipped in-viewport screenshot.
await browser.close();

console.log(`Saved: ${outPath}`);
