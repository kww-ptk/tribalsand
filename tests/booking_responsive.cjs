// Browser layout smoke test against published property markup with local
// embed styles and scripts. No bookings are submitted. Requires Playwright.
// PLAYWRIGHT_MODULE may point to a temporary Playwright installation.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'booking-embed.php'), 'utf8');
const style = source.match(/<style>([\s\S]*?)<\/style>/)[1].replace(/<\?=[\s\S]*?\?>/g, '');
const reportScript = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?=[\s\S]*?\?>/g, '""');
const admin = fs.readFileSync(path.join(root, 'admin/booking-widgets.php'), 'utf8');
assert(!admin.includes('data-bw-option'), 'customization controls removed');
assert(admin.includes('Open in new tab'), 'new-tab action retained');
(async () => {
  const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'chrome', headless: true });
  try {
    const context = await browser.newContext();
    const markup = new Map();
    await context.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/__booking-test-host') {
        return route.fulfill({ contentType: 'text/html', body: `<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main style="width:${Number(url.searchParams.get('width'))}px;margin:0 auto"><div data-tribalsand-booking="zuri"></div><script src="https://tribalsand.com/js/booking-embed.js"></script></main></body></html>` });
      }
      if (url.pathname === '/booking-embed') {
        const venue = url.searchParams.get('venue');
        if (!markup.has(venue)) markup.set(venue, await route.fetch({ url: `https://tribalsand.com/booking-embed?venue=${encodeURIComponent(venue)}` }));
        const response = markup.get(venue);
        let html = await response.text();
        html = html.replace(/<style>[\s\S]*?<\/style>/, `<style>${style}</style>`);
        html = html.replace(/<script>\s*\/\* Talks to[\s\S]*?<\/script>/, `<script>${reportScript}</script>`);
        return route.fulfill({ response, body: html });
      }
      if (/^\/(css|js)\/[\w.-]+$/.test(url.pathname)) {
        const file = path.join(root, url.pathname);
        if (fs.existsSync(file)) return route.fulfill({ path: file });
      }
      return route.continue();
    });
    const page = await context.newPage();
    let cases = 0;
    for (const venue of (process.env.EMBED_ONLY ? [] : ['my-amani', 'zuri', 'maya_ilai'])) {
      for (const [width, height] of [[320,568],[360,640],[390,844],[412,915],[640,360],[768,1024],[1024,768],[1440,900],[1920,1080]]) {
        await page.setViewportSize({ width, height });
        await page.goto(`https://tribalsand.com/booking-embed?venue=${venue}`, { waitUntil: 'domcontentloaded' });
        await page.locator('.tse__name').waitFor();
        const layout = await page.evaluate(() => {
          const r = document.querySelector('.tse').getBoundingClientRect();
          return { left:r.left, right:r.right, width:r.width, vw:innerWidth, overflow:document.documentElement.scrollWidth > innerWidth };
        });
        assert(layout.left >= 11 && layout.right <= width - 11, `${venue} ${width}: side gutters`);
        assert(Math.abs(layout.left - (width - layout.right)) <= 2, `${venue} ${width}: centered`);
        assert(!layout.overflow && layout.width <= 440, `${venue} ${width}: contained`);
        const trigger = page.locator('#tsEmbed #bkCiBtn,#tsEmbed .dp-btn').first();
        if (await trigger.count()) {
          await trigger.click();
          const pop = page.locator('#bkDatesPop:not([hidden]),.dp-pop:not([hidden])').first();
          await pop.waitFor();
          const b = await pop.boundingBox();
          assert(b.x >= 0 && b.x + b.width <= width + 1, `${venue} ${width}: picker contained`);
          const next = pop.locator('#bkNextMonth,#_dpNext');
          assert(await next.isVisible(), `${venue} ${width}: next month visible`);
          await next.click();
          await pop.locator('.bk-pop__cta').click();
        }
        if (venue === 'zuri') {
          await page.waitForFunction(() => typeof window.tsOpenBookingModal === 'function');
          await page.evaluate(() => window.tsOpenBookingModal('my-amani-full-rental', 'Test stay', 1, 'USD'));
          await page.locator('#bkModal #bkCiBtn').click();
          await page.locator('#bkModal #bkNextMonth').click();
          await page.locator('#bkModal #bkDatesDone').click();
          const dialog = await page.locator('.bk-modal__dialog').boundingBox();
          assert(dialog.x >= 0 && dialog.x + dialog.width <= width + 1 && dialog.height <= height - 20, `${width}: booking dialog contained`);
          await page.keyboard.press('Escape');
        }
        if (venue === 'maya_ilai') {
          await page.locator('.tse__btn').click();
          const dialog = await page.locator('.mib-pop__dialog').boundingBox();
          assert(dialog && dialog.x >= 0 && dialog.x + dialog.width <= width + 1, `${width}: configurator contained`);
          await page.keyboard.press('Escape');
        }
        cases++;
        console.log(`PASS ${venue} ${width}x${height}`);
      }
    }
    // Verify the actual loader in a wide host page and narrow containers,
    // including growing AND shrinking after a calendar closes.
    await page.setViewportSize({ width: 1440, height: 900 });
    for (const width of [280, 320, 440, 900]) {
      await page.goto(`https://tribalsand.com/__booking-test-host?width=${width}`, { waitUntil: 'domcontentloaded' });
      const frame = page.frameLocator('iframe');
      try { await frame.locator('.pa-head').waitFor({ timeout: 15000 }); }
      catch (error) {
        console.error(await page.locator('iframe').evaluate(el => ({ src:el.src, height:el.offsetHeight })));
        console.error(await Promise.all(page.frames().map(async f => ({ url:f.url(), text:(await f.locator('body').innerText().catch(() => '')).slice(0,300) }))));
        throw error;
      }
      await page.waitForTimeout(800);
      const original = await page.locator('iframe').evaluate(el => el.getBoundingClientRect().height);
      await frame.locator('body').evaluate(() => window.tsOpenBookingModal('my-amani-full-rental', 'Test stay', 1, 'USD'));
      await page.waitForTimeout(800);
      const expanded = await page.locator('iframe').evaluate(el => el.getBoundingClientRect().height);
      assert(expanded === 900, `${width}: iframe expands to viewport for booking dialog`);
      await frame.locator('#bkCiBtn').click();
      await frame.locator('#bkNextMonth').click();
      await frame.locator('#bkDatesDone').click();
      await frame.locator('.bk-modal__close').click();
      await page.waitForTimeout(800);
      const closed = await page.locator('iframe').evaluate(el => el.getBoundingClientRect().height);
      assert(Math.abs(closed - original) <= 3, `${width}: iframe shrinks after calendar`);
      const bounds = await page.locator('iframe').evaluate(el => {
        const r = el.getBoundingClientRect(), p = el.parentNode.parentNode.getBoundingClientRect();
        return Math.abs((r.left-p.left)-(p.right-r.right));
      });
      assert(bounds <= 2, `${width}: iframe centered inside host container`);
      console.log(`PASS embedded container ${width}px: centering and resize`);
    }
    console.log(`${cases} responsive layouts passed (${process.env.BROWSER_CHANNEL || 'chrome'}).`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
