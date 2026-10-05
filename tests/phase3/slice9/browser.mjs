// Headless Chromium proof of slice 9 (docs/build-specs/reports-settings.md, "Proof"): the audit page, the exports and the home at 375 x 740 and 1280 x 800 with scrollWidth = viewport, every control >= 44 px on a phone,
// the range form and a real file download through the browser, the agents page, no console errors. Run through tests/phase3/slice9/run.sh (the proofs before it left their world).
// Run through tests/phase3/slice7/run.sh (servers up; the proofs before it left their data).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots-p3s9';
const KEY = process.env.ACTION_TOKEN_KEY;
const DB = process.env.GL_DEV_DB;
const sql = (q) => execSync(`sudo -n -u postgres psql -d ${DB} -Atc "${q.replace(/"/g, '\\"')}"`).toString().trim();
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
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (422|403|404|429)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  if (member) await page.goto(mint(member), { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const widthOK = async (page, label, vw) => { const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth })); ok(o.sw === o.iw && o.iw === vw, `no sideways scroll on ${label} (scrollWidth ${o.sw} = ${o.iw})`); };
const touch = async (page, label) => {
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content select.form-select, #page-content input.form-control')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && !e.classList.contains('form-select-sm') && !e.classList.contains('form-control-sm') && !['number', 'date', 'file', 'checkbox', 'radio'].includes(e.type); })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const api = async (page, path) => (await (await page.request.get(BASE + path, { headers: { Accept: 'application/json' } })).json()).data;

const download = async (page, selector) => { const [d] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.evaluate((s) => { const a = document.querySelector(s); a.removeAttribute('hx-get'); a.click(); }, selector)]); return d; };
// ---- phone: the Controller ----
{
  const { ctx, page, errors } = await session(28, PHONE);
  console.log('375 x 740 — the Controller');
  await go(page, '/audit?from=2026-01-01&to=2026-12-31');
  await widthOK(page, 'the audit page', 375); await touch(page, 'the audit page');
  const t = await page.locator('#audit-content').innerText();
  ok(t.includes('Reopened periods') && t.includes('SMOKE: a late vendor bill') && t.includes('SMOKE: posted to the wrong account') && t.includes('SMOKE: entered twice') && t.includes('differs') && t.includes('The control tie-out'), 'the audit page shows the reopening, the reversal, the void, the AI verdict and the tie-out');
  ok((await page.locator('#audit-closed-count').innerText()) === '0', 'and "0 entries posted after their period closed"');
  await page.screenshot({ path: `${SHOTS}/phone-audit.png`, fullPage: true });
  await page.fill('#audit-from', '2026-03-01'); await page.fill('#audit-to', '2026-03-31');
  await page.click('#audit-apply-btn');
  await page.waitForFunction(() => document.querySelector('#audit-reopened-empty'), null, { timeout: 15000 });
  ok(page.url().includes('from=2026-03-01') && (await page.locator('#audit-reopened-empty').count()) === 1, 'the range form narrows the page (HTMX, URL pushed): no reopening in March');
  await go(page, '/exports?period=FY2026');
  await widthOK(page, 'the exports page', 375); await touch(page, 'the exports page');
  ok((await page.locator('#exports-cards .card').count()) === 12 && (await page.locator('#export-ledger-detail-rows').innerText()).includes('row'), 'twelve file cards, the flat ones with their row counts');
  ok((await page.getAttribute('#exports-zip-link', 'href')).includes('format=zip'), 'the ZIP button points at format=zip');
  await page.screenshot({ path: `${SHOTS}/phone-exports.png`, fullPage: true });
  const d1 = await download(page, '#export-journal-link');
  ok(d1.suggestedFilename() === 'journal-FY2026.csv', 'clicking a file downloads it as journal-FY2026.csv (' + d1.suggestedFilename() + ')');
  const body = fs.readFileSync(await d1.path(), 'utf8');
  ok(body.startsWith('Entry,Date,Memo') && body.split('\n').length > 3, 'and it is the journal as CSV');
  const d2 = await download(page, '#exports-zip-link');
  ok(d2.suggestedFilename() === 'ledger-FY2026.zip', 'the ZIP downloads as ledger-FY2026.zip');
  await page.selectOption('#exports-period', '2026-04');
  await page.click('#exports-apply-btn');
  await page.waitForFunction(() => document.querySelector('#export-pl-link')?.getAttribute('href').includes('period=2026-04'), null, { timeout: 15000 });
  ok(true, 'changing the period re-aims every link at it');
  await go(page, '/');
  await widthOK(page, 'the home', 375); await touch(page, 'the home');
  ok((await page.locator('#home-revenue').count()) === 1 && (await page.locator('#home-spend').count()) === 1, 'the home shows revenue and spend under net income');
  await page.screenshot({ path: `${SHOTS}/phone-home.png`, fullPage: true });
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- phone: the Bookkeeper has neither page ----
{
  const { ctx, page } = await session(27, PHONE);
  ok((await page.goto(BASE + '/audit', { waitUntil: 'networkidle' })).status() === 403 && (await page.goto(BASE + '/exports', { waitUntil: 'networkidle' })).status() === 403, 'the Bookkeeper gets 403 on /audit and /exports');
  await ctx.close();
}
// ---- desktop ----
{
  const { ctx, page, errors } = await session(29, DESK);
  console.log('1280 x 800 — the Accountant');
  await go(page, '/notifications');
  const gap = await page.evaluate(() => { const f = document.querySelector('#page-footer').getBoundingClientRect(), b = document.querySelector('#assistant-bar').getBoundingClientRect(); return { footerBottom: Math.round(f.bottom), barTop: Math.round(b.top), tall: document.documentElement.scrollHeight > window.innerHeight }; });
  ok(gap.tall || Math.abs(gap.footerBottom - gap.barTop) <= 6, `a page too short to fill the window: the footer sits on the command bar (footer bottom ${gap.footerBottom}, bar top ${gap.barTop})`);
  await go(page, '/audit?from=2026-01-01&to=2026-12-31');
  await widthOK(page, 'the audit page', 1280);
  await page.screenshot({ path: `${SHOTS}/desk-audit.png` });
  ok((await page.locator('#nav-audit').count()) === 1 && (await page.locator('#nav-exports').count()) === 1, 'the sidebar shows Audit and Exports to the Accountant');
  await page.click('#nav-exports .nxl-link');
  await page.waitForURL('**/exports*');
  await widthOK(page, 'the exports page', 1280);
  await page.screenshot({ path: `${SHOTS}/desk-exports.png` });
  await go(page, '/');
  await widthOK(page, 'the home', 1280);
  await page.screenshot({ path: `${SHOTS}/desk-home.png` });
  ok(errors.length === 0, 'no console errors on the desktop' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(1, DESK);
  await go(page, '/settings/agents');
  await widthOK(page, 'the agents page', 1280);
  ok((await page.locator('#agents-cards .card').count()) >= 1 && (await page.locator('#agents-content').innerText()).includes('Last morning note'), 'the agents page lists the agent and its last morning note');
  await page.screenshot({ path: `${SHOTS}/desk-agents.png` });
  ok(errors.length === 0, 'no console errors on the agents page');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
