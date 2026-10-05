// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md, "Proof before Phase 3"):
//   at 375 x 740 and 1280 x 800 — scrollWidth = viewport, the bottom tab bar on a phone and the sidebar on a desktop,
//   the command bar and tab bar thumb-reachable, HTMX navigation pushing URL and title, no console errors, the dashboard
//   whole with JavaScript off, the web app manifest installable (no service worker); the menu per role.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the
// kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots';
import crypto from 'node:crypto';
import fs from 'node:fs';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.gl.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get viewer() { return mint(26); }, get bookkeeper() { return mint(27); }, get admin() { return mint(1); } };
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0; }, sel);
const groupsOf = async (page) => (await page.locator('.nxl-navbar .nxl-caption label').allInnerTexts()).map((g) => g.trim().toLowerCase());

// ---- phone: the Bookkeeper (Bea) ----
{
  const { ctx, page, errors } = await session(urls.bookkeeper, { width: 375, height: 740 });
  console.log('375 x 740 — the Bookkeeper');
  ok(page.url() === BASE + '/', 'signed on and landed on the dashboard (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the dashboard (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#app-tabbar'), 'the bottom tab bar is shown on a phone');
  const box = await page.locator('#app-tabbar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  const tabs = await page.locator('#app-tabbar .app-tab').count();
  ok(tabs === 5, `five tabs: Home, Statements, Invoices, Bills, More (${tabs})`);
  const small = await page.evaluate(() => [...document.querySelectorAll('#app-tabbar .app-tab')].filter((a) => a.getBoundingClientRect().height < 44 || a.getBoundingClientRect().width < 44).length);
  ok(small === 0, 'every tab is at least 44 x 44 px');
  ok(await shown(page, '#home-waiting') && await shown(page, '#home-cash') && await shown(page, '#home-receivables') && await shown(page, '#home-no-fiscal-year') && await shown(page, '#dashboard-new-entry-btn'), 'What waits, Cash, Who owes us, the fiscal-year banner and the New entry button are shown');
  const smallCards = await page.evaluate(() => [...document.querySelectorAll('#home-waiting a')].filter((a) => a.getBoundingClientRect().height < 44).length);
  ok(smallCards === 0, 'every What-waits card is tappable (≥ 44 px)');
  ok((await page.locator('#header-role-badge').innerText()).includes('Bookkeeper'), 'the header badge reads Bookkeeper');
  await page.screenshot({ path: `${SHOTS}/phone-dashboard-bookkeeper.png` });
  await page.click('#tab-statements');
  await page.waitForURL(BASE + '/statements/');
  await page.waitForSelector('#statements-cards');
  ok(page.url() === BASE + '/statements/', 'the Statements tab pushed /statements/ without a page load');
  ok((await page.title()).startsWith('Statements'), 'and set the title (' + (await page.title()) + ')');
  ok(await page.evaluate(() => document.querySelector('#tab-statements').classList.contains('active')), 'and highlighted the tab');
  ok(await page.evaluate(() => !document.querySelector('#nav-home .nxl-link').classList.contains('active') && document.querySelector('#nav-statements .nxl-link').classList.contains('active')), 'and the sidebar highlights the screen, not Home');
  ok(await page.evaluate(() => document.getElementById('page-content').dataset.screen === 'statements'), 'and re-stamped #page-content data-screen for the command bar');
  await page.screenshot({ path: `${SHOTS}/phone-statements.png` });
  await page.click('#tab-bills');
  await page.waitForURL(BASE + '/bills/');
  await page.waitForSelector('#bills-coming');
  ok(true, 'the Bills tab opens its placeholder (slice 5 builds it)');
  await page.click('#tab-more');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  ok(await shown(page, '#nav-journal') && await shown(page, '#nav-match'), 'More opens the menu; the Bookkeeper sees the Journal and Match the bank');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'books,receivables,payables,cash,settings,me', 'the Bookkeeper\'s groups: ' + groups.join(', '));
  ok((await page.locator('#nav-general').count()) === 0 && (await page.locator('#nav-audit').count()) === 0 && (await page.locator('#nav-my-settings').count()) === 1, 'Settings holds My settings only for the Bookkeeper (no ledger settings, no Audit)');
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${SHOTS}/phone-more-menu.png` });
  await page.evaluate(() => { const l = document.querySelector('.nxl-navbar'); l.parentElement.scrollTop = l.parentElement.scrollHeight; });
  const lastAfter = await page.locator('.nxl-navbar .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await page.click('#nav-tokens .nxl-link');
  await page.waitForSelector('#tokens-connect');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Tokens');
  await page.goto(BASE + '/settings/', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375 && await shown(page, '#prefs-save-btn'), 'no sideways scroll on My settings; the Save button is there');
  await page.screenshot({ path: `${SHOTS}/phone-settings.png` });
  await page.goto(BASE + '/activity', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Activity');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.fill('#assistant-input', 'Who owes us the most?');
  await page.click('#assistant-send-btn');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'the command bar shows the expert\'s reply above the bar');
  await page.screenshot({ path: `${SHOTS}/phone-assistant.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: a Viewer (Vera) ----
{
  const { ctx, page, errors } = await session(urls.viewer, { width: 375, height: 740 });
  console.log('375 x 740 — a Viewer');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'books,receivables,payables,cash,settings,me', 'a Viewer\'s groups: ' + groups.join(', '));
  ok((await page.locator('#nav-match').count()) === 0 && (await page.locator('#nav-general').count()) === 0 && (await page.locator('#nav-statements').count()) === 1, 'no Match the bank, no ledger settings; Statements is there');
  ok(!(await shown(page, '#dashboard-new-entry-btn')) && await shown(page, '#home-no-fiscal-year') && (await page.locator('#home-open-year-link').count()) === 0, 'home: no New entry button; the banner without a link to open a year');
  ok((await page.locator('#header-role-badge').innerText()).includes('Viewer'), 'the badge reads Viewer');
  ok((await page.locator('#app-tabbar .app-tab').count()) === 5, 'five tabs for a Viewer too (every role reads the statements and the documents)');
  await page.screenshot({ path: `${SHOTS}/phone-dashboard-viewer.png` });
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
}

// ---- desktop: the sidebar, the admin ----
{
  const { ctx, page, errors } = await session(urls.admin, { width: 1280, height: 800 });
  console.log('1280 x 800 — the admin');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#app-tabbar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#nav-general') && await shown(page, '#nav-audit'), 'the sidebar is shown, with the ledger settings and Audit');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'books,receivables,payables,cash,settings,me', 'the admin\'s groups: ' + groups.join(', '));
  const nav = await page.locator('#left-sidenav').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Super-admin'), 'the badge reads Super-admin');
  await page.screenshot({ path: `${SHOTS}/desktop-dashboard.png` });
  await page.click('#nav-vendors .nxl-link');
  await page.waitForURL(BASE + '/vendors/');
  await page.waitForSelector('#vendors-coming');
  ok((await page.title()).startsWith('Vendors'), 'HTMX navigation: /vendors/ (a placeholder) pushed, title "' + (await page.title()) + '"');
  await page.screenshot({ path: `${SHOTS}/desktop-placeholder.png` });
  await page.goBack();
  await page.waitForURL(BASE + '/');
  ok(true, 'the browser back button returns to /');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  await page.fill('#token-form-field-label', 'proof');
  await page.click('#token-form-save-btn');
  await page.waitForSelector('#tokens-minted');
  ok((await page.locator('#tokens-minted-value').innerText()).startsWith('mcp_'), 'minting a token shows it once (mcp_…)');
  await page.goto(BASE + '/settings/tokens/', { waitUntil: 'networkidle' });
  ok((await page.locator('#tokens-minted').count()) === 0, 'and never again');
  await page.goto(BASE + '/settings/', { waitUntil: 'networkidle' });
  await page.uncheck('#prefs-field-kind-morning_note');
  await page.click('#prefs-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved how you are told'), 'saving the settings lands back on them with the notice');
  ok(!(await page.isChecked('#prefs-field-kind-morning_note')), 'and the unchecked kind stayed unchecked');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the dashboard is whole ----
{
  const { ctx, page } = await session(urls.bookkeeper, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const text = (await page.locator('#page-content').innerText()).toLowerCase();
  ok(['what waits', 'cash', 'who owes us', 'whom we owe', 'the ai bill', 'the morning note'].every((t) => text.includes(t)), 'the home regions are in the server-rendered page');
  ok(await shown(page, '#app-tabbar') && (await page.locator('#tab-statements').getAttribute('href')) === '/statements/', 'the tabs are plain links');
  await ctx.close();
}

// ---- installable: the manifest, the icons, no service worker ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.viewer, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'General Ledger', 'standalone, start_url /, named General Ledger');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  const cdp = await ctx.newCDPSession(page);
  const inst = await cdp.send('Page.getInstallabilityErrors').catch((e) => ({ installabilityErrors: [{ errorId: 'cdp:' + e.message }] }));
  const errs = (inst.installabilityErrors || []).map((e) => e.errorId);
  ok(!errs.some((e) => /manifest|icon|start-url|display|name/i.test(e)), 'Chromium reports no manifest installability error' + (errs.length ? ' (remaining: ' + errs.join(', ') + ')' : ''));
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
