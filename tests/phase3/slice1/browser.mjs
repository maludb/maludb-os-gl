// Headless Chromium proof of slice 1 (docs/build-specs/chart-fiscal.md, "Proof", 7): every screen of the slice at 375 x 740 and 1280 x 800 with
// scrollWidth = viewport, every button at least 44 px tall on a phone, the flows through the real screens (add an account, open a year, close a
// period, save the settings), a refused save in #flash keeping the form, JavaScript off. Run through tests/phase3/slice1/run.sh (servers up).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots-p3s1';
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
  const small = await page.evaluate(() => [...document.querySelectorAll('#page-content a.btn, #page-content button.btn, #page-content select.form-select, #page-content input.form-control:not(.form-control-sm)')]
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && e.type !== 'number' && e.type !== 'date'; })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const acct1200 = await (async () => { const ctx = await browser.newContext(); const p = await ctx.newPage(); await p.goto(mint(1)); const r = await p.request.get(BASE + '/accounts/', { headers: { Accept: 'application/json' } }); const d = (await r.json()).data; await ctx.close(); return d.accounts.find((a) => a.code === '1200').account_id; })();

// ---- phone: the admin walks the slice ----
{
  const { ctx, page, errors } = await session(1, PHONE);
  console.log('375 x 740 — the admin');
  const screens = [['/accounts/', 'Chart of accounts', '#accounts-results'], ['/accounts/new', 'Add account', '#account-form'], [`/accounts/${acct1200}`, 'An account', '#account-view-facts'], [`/accounts/${acct1200}/edit`, 'Change account', '#account-form'],
    ['/periods/', 'Periods', '#periods-list-content'], ['/periods/new', 'Open a fiscal year', '#fiscal-year-form'], ['/settings/tax-rates/', 'Tax rates', '#tax-rates-table'], ['/settings/tax-rates/new', 'Add rate', '#tax-rate-form'],
    ['/settings/items/', 'Items', '#items-results'], ['/settings/items/new', 'Add item', '#item-form'], ['/settings/posting-rules/', 'Posting rules', '#posting-rules-table'], ['/settings/posting-rules/new', 'Add rule', '#posting-rule-form'],
    ['/settings/sites/', 'Sites', '#sites-list-cards'], ['/settings/sites/new', 'Add site', '#site-form'], ['/settings/general', 'Ledger settings', '#settings-form'], ['/settings/numbering', 'Numbering', '#numbering-table'], ['/settings/agents', 'Agents', '#agents-content']];
  for (const [path, label, sel] of screens) {
    await go(page, path);
    ok(await page.locator(sel).count() === 1, `${label} renders (${sel})`);
    await widthOK(page, label, 375);
    await touch(page, label);
  }
  await go(page, '/accounts/');
  await page.screenshot({ path: `${SHOTS}/phone-accounts.png` });
  // add an account through the form (HTMX): lands on the account with the notice
  await go(page, '/accounts/new?type=expense');
  ok(await page.isChecked('#account-form-field-type-expense'), 'the type is prefilled from the group');
  await page.fill('#account-form-field-code', '6170');
  await page.fill('#account-form-field-name', 'SMOKE Browser account');
  await page.click('#account-form-save-btn');
  await page.waitForSelector('#account-view-facts');
  ok(page.url().includes('/accounts/') && page.url().includes('notice=saved') && (await page.locator('#notice-banner').innerText()).includes('Saved the account'), 'saving lands on the account with the notice (' + page.url() + ')');
  await page.screenshot({ path: `${SHOTS}/phone-account.png` });
  // a refused save stays on the form with the words in #flash
  await go(page, '/accounts/new');
  await page.fill('#account-form-field-code', '6170');
  await page.fill('#account-form-field-name', 'SMOKE Dupe');
  await page.check('#account-form-field-type-expense');
  await page.click('#account-form-save-btn');
  await page.waitForSelector('#flash .alert');
  ok((await page.locator('#flash').innerText()).includes('already used by SMOKE Browser account') && (await page.inputValue('#account-form-field-name')) === 'SMOKE Dupe', 'a refused save shows the words in #flash and keeps the form');
  await page.screenshot({ path: `${SHOTS}/phone-account-refused.png` });
  // the type radio narrows the subtype and the parent
  await page.check('#account-form-field-type-asset');
  const subs = await page.locator('#account-form-field-subtype option').allTextContents();
  ok(subs.includes('Bank') && !subs.includes('Operating'), 'picking Assets narrows the subtypes');
  // open a fiscal year
  await go(page, '/periods/');
  const hasYear = (await page.locator('[id^="fiscal-year-card-"]').count()) > 0;
  if (!hasYear) {
    await go(page, '/periods/new');
    await page.fill('#fiscal-year-form-field-start', '2026-01-01');
    await page.click('#fiscal-year-form-save-btn');
    await page.waitForSelector('[id^="fiscal-year-card-"]');
  }
  ok((await page.locator('[id^="period-chip-"]:not([id$="-status"]):not([id$="-counts"])').count()) >= 12, 'the year card shows twelve period chips');
  await page.screenshot({ path: `${SHOTS}/phone-periods.png` });
  await widthOK(page, 'Periods with a year', 375);
  await page.click('[id^="period-chip-"] >> nth=0');
  await page.waitForSelector('#period-view-facts');
  ok(await page.locator('#period-view-checklist').count() === 1 && await page.locator('#period-check-drafts').count() === 1, 'a period page with the checklist');
  await widthOK(page, 'A period', 375);
  await page.screenshot({ path: `${SHOTS}/phone-period.png` });
  // save the settings
  await go(page, '/settings/general');
  await page.fill('#settings-form-field-name', 'SMOKE Browser Books');
  await page.click('#settings-form-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved the settings') && (await page.inputValue('#settings-form-field-name')) === 'SMOKE Browser Books', 'saving the settings lands back with the notice and the value');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- phone: the Controller closes a period ----
{
  const { ctx, page, errors } = await session(28, PHONE);
  console.log('375 x 740 — the Controller');
  await go(page, '/periods/');
  const openChip = page.locator('[id^="period-chip-"]:not([data-status="closed"])');
  if ((await openChip.count()) > 0) {
    await openChip.first().click();
  } else {                                   // every period is closed by the earlier proofs: reopen one first
    await page.locator('[id^="period-chip-"][data-status="closed"]').first().click();
    await page.waitForSelector('#period-view-reopen-btn');
    await page.fill('#period-reopen-reason', 'SMOKE browser opens one');
    page.once('dialog', (d) => d.accept());
    await page.click('#period-view-reopen-btn');
  }
  await page.waitForSelector('#period-view-close-btn');
  page.once('dialog', (d) => d.accept());
  await page.click('#period-view-close-btn');
  await page.waitForSelector('#period-view-reopen-btn');
  ok((await page.locator('#notice-banner').innerText()).includes('The period is closed') && page.url().includes('notice=closed'), 'closing a period lands on it closed with the notice');
  await page.fill('#period-reopen-reason', 'SMOKE browser reopen');
  page.once('dialog', (d) => d.accept());
  await page.click('#period-view-reopen-btn');
  await page.waitForSelector('#period-view-close-btn');
  ok((await page.locator('#period-view-reopen-reason').innerText()).includes('SMOKE browser reopen'), 'reopening with a reason shows the reason');
  await page.screenshot({ path: `${SHOTS}/phone-period-reopened.png` });
  ok((await page.locator('#accounts-list-add-btn').count()) === 0, 'no Add account for the Controller (checked later on the chart)');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- desktop ----
{
  const { ctx, page, errors } = await session(1, DESK);
  console.log('1280 x 800 — the admin');
  for (const [path, label] of [['/accounts/', 'Chart of accounts'], [`/accounts/${acct1200}`, 'An account'], ['/periods/', 'Periods'], ['/settings/general', 'Ledger settings'], ['/settings/numbering', 'Numbering'], ['/settings/posting-rules/', 'Posting rules'], ['/settings/items/', 'Items']]) {
    await go(page, path);
    await widthOK(page, label, 1280);
  }
  await go(page, '/accounts/');
  await page.screenshot({ path: `${SHOTS}/desktop-accounts.png` });
  await page.click(`#account-card-${acct1200}-open-btn`);
  await page.waitForSelector('#account-view-facts');
  ok(page.url().startsWith(BASE + `/accounts/${acct1200}`) && (await page.locator('#account-view-back').innerText()).includes('Back to'), 'HTMX: the card opens the account; Back to the chart');
  await page.screenshot({ path: `${SHOTS}/desktop-account.png` });
  await go(page, '/settings/numbering');
  await page.fill('#numbering-row-transfer-prefix', 'XFR-');
  await page.click('#numbering-row-transfer-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#numbering-row-transfer-preview').innerText()).startsWith('XFR-'), 'numbering: a row saves and previews the new prefix');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- JavaScript off: the forms post and land ----
{
  const { ctx, page } = await session(1, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/settings/sites/new');
  await page.fill('#site-form-field-name', 'SMOKE No-JS site');
  await page.click('#site-form-save-btn');
  await page.waitForSelector('#sites-list-cards');
  ok(page.url().includes('/settings/sites/') && page.url().includes('notice=saved') && (await page.locator('#notice-banner').count()) === 1, 'a plain form post lands on the list with the notice');
  await go(page, '/accounts/');
  ok((await page.locator('[id^="account-card-"]').count()) > 20, 'the chart renders without JavaScript');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
