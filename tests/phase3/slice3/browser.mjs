// Headless Chromium proof of slice 3 (docs/build-specs/statements.md, "Proof", 7): the statements, the reports and the print page at 375 x 740 and 1280 x 800,
// scrollWidth = viewport, the tables in .table-responsive, every button >= 44 px, no console errors, the picker working with and without JavaScript.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots-p3s3';
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.gl.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const browser = await chromium.launch();
const PHONE = { width: 375, height: 740 }, DESK = { width: 1280, height: 800 };
async function session(member, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content select.form-select, #page-content input.form-control')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && e.type !== 'date'; })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const SCREENS = [['/statements/', 'Statements', '#statements-cards'], ['/statements/pl?from=2026-01-01&to=2026-12-31&compare=previous', 'Profit and loss', '#statement-table'], ['/statements/balance-sheet?as_of=2026-12-31', 'Balance sheet', '#statement-table'],
  ['/statements/trial-balance?as_of=2026-12-31', 'Trial balance', '#statement-table'], ['/statements/cash-flow?from=2026-01-01&to=2026-12-31', 'Cash flow', '#statement-table'], ['/reports/ar-aging?as_of=2026-09-30', 'Who owes us', '#report-content'],
  ['/reports/sales-tax?from=2026-01-01&to=2026-12-31', 'Sales tax', '#report-table'], ['/reports/spend?from=2026-01-01&to=2026-12-31&group_by=vendor', 'Spend', '#report-table'], ['/reports/customer-statement', 'Customer statement', '#report-choose-customer'],
  ['/reports/control-check?as_of=2026-12-31', 'Control check', '#report-table']];
{
  const { ctx, page, errors } = await session(26, PHONE);
  console.log('375 x 740 — a Viewer');
  for (const [path, label, sel] of SCREENS) {
    await go(page, path);
    ok(await page.locator(sel).count() >= 1, `${label} renders (${sel})`);
    await widthOK(page, label, 375); await touch(page, label);
  }
  await go(page, '/statements/pl?from=2026-01-01&to=2026-12-31');
  ok(await page.evaluate(() => !!document.querySelector('#statement-table').closest('.table-responsive')), 'the table scrolls inside .table-responsive');
  await page.screenshot({ path: `${SHOTS}/phone-pl.png` });
  // the picker: choose a period, compare, apply (HTMX)
  await page.selectOption('#period-picker-period', '2026-06');
  await page.selectOption('#period-picker-compare', 'previous');
  await page.click('#period-picker-apply-btn');
  await page.waitForFunction(() => document.querySelector('#statement-compare-note'));
  ok(page.url().includes('period=2026-06') && page.url().includes('compare=previous'), 'the picker applies in place and pushes the URL (' + page.url() + ')');
  ok((await page.locator('#statement-compare-note').innerText()).includes('2026-05'), 'the compare note names the prior period');
  await page.screenshot({ path: `${SHOTS}/phone-pl-compare.png` });
  await go(page, '/statements/');
  await page.click('#statement-card-balance-sheet-open-btn');
  await page.waitForSelector('#statement-status');
  ok((await page.locator('#statement-status').innerText()).includes('Assets'), 'a card opens its statement with the equation line');
  await page.screenshot({ path: `${SHOTS}/phone-balance-sheet.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(26, DESK);
  console.log('1280 x 800 — a Viewer');
  for (const [path, label] of SCREENS.slice(0, 6)) { await go(page, path); await widthOK(page, label, 1280); }
  await go(page, '/statements/trial-balance?as_of=2026-12-31');
  await page.screenshot({ path: `${SHOTS}/desktop-trial-balance.png` });
  const popup = page.waitForEvent('popup');
  await page.click('#period-picker-print-link');
  const pr = await popup; await pr.waitForLoadState();
  ok((await pr.locator('#print-business').innerText()) === 'SMOKE Co' && (await pr.locator('#nav-journal').count()) === 0, 'the print page opens bare with the business\'s name');
  await pr.screenshot({ path: `${SHOTS}/desktop-print.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page } = await session(26, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/statements/pl?from=2026-01-01&to=2026-12-31');
  await page.selectOption('#period-picker-period', '2026-06');
  await page.click('#period-picker-apply-btn');
  await page.waitForSelector('#statement-table');
  ok(page.url().includes('period=2026-06'), 'the picker is a plain form: it applies without JavaScript');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
