// Headless Chromium proof of slice 6 (docs/build-specs/cash.md, "Proof", 6): the cash screens at 375 x 740 and 1280 x 800 with scrollWidth = viewport, every button >= 44 px on a phone, the flows through the real screens
// (a bank account in the settings, a CSV previewed and imported, the sure matches in one click, a match, an entry for a fee, an ignore, a reconciliation started and completed by the Controller, a transfer), the home's cash cards and
// the twelve-month table, no console errors. Run through tests/phase3/slice6/run.sh (servers up; the proofs before it left their data).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
import { pickRecord } from '../../picker/pick.mjs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots-p3s6';
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
{ const ctx = await browser.newContext(); const p = await ctx.newPage(); await p.goto(mint(1)); const d = await api(p, '/periods/'); if (d.fiscal_years.length === 0) { const csrf = await p.evaluate(() => document.querySelector('#csrf-token-meta').content); await p.request.post(BASE + '/periods/save.php', { form: { start: '2026-01-01', csrf_token: csrf } }); } await ctx.close(); }
fs.writeFileSync('/tmp/SMOKE-browser-bank.csv', 'Date,Description,Amount,Reference\n2026-06-10,BROWSER RECEIPT,"1,234.56",BR-1\n2026-06-11,BROWSER SURE ONE,250.00,BR-2\n2026-06-12,BROWSER SURE TWO,310.00,BR-3\n2026-06-13,BROWSER FEE,(12.00),BR-4\n2026-06-14,BROWSER NOISE,(3.33),BR-5\n');
for (const [code, name] of [['1095', 'SMOKE Browser ledger'], ['1096', 'SMOKE Browser savings ledger']]) {
  sql(`INSERT INTO accounts (code, name, account_type, subtype, is_bank, is_control, is_system) SELECT '${code}', '${name}', 'asset', 'bank', true, false, false WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE code = '${code}')`);
}
sql("INSERT INTO customers (name, email, terms_days, created_by) SELECT 'SMOKE Cash Acme', 'ap@cashacme.invalid', 30, 27 WHERE NOT EXISTS (SELECT 1 FROM customers WHERE name = 'SMOKE Cash Acme')");
let bank, bank2, run;
const idOf = (ref) => sql(`SELECT id FROM bank_transactions WHERE external_ref = '${ref}'`);

// ---- desktop: the owner adds two bank accounts in the settings ----
{
  const { ctx, page, errors } = await session(1, DESK);
  console.log('1280 x 800 — the administrator');
  await go(page, '/settings/banks/new');
  await widthOK(page, 'Add a bank account', 1280);
  ok((await page.locator('#bank-form-opening-note').innerText()).includes('DR the bank account / CR 3000 Owner equity'), 'the form explains the opening entry');
  await page.fill('#bank-form-field-name', 'SMOKE Browser Bank');
  await pickRecord(page, '#bank-form-field-account', '1095');
  await page.fill('#bank-form-field-institution', 'SMOKE Browser CU');
  await page.fill('#bank-form-field-last4', '5555');
  await page.fill('#bank-form-field-opening-date', '2026-01-01');
  await page.click('#bank-form-save-btn');
  await page.waitForSelector('#bank-settings-cards');
  ok(page.url().includes('notice=saved') && (await page.locator('#bank-settings-cards').innerText()).includes('SMOKE Browser Bank'), 'a bank account saves and lands on the settings list');
  await go(page, '/settings/banks/new');
  await page.fill('#bank-form-field-name', 'SMOKE Browser Savings');
  await page.selectOption('#bank-form-field-kind', 'savings');
  await page.fill('#bank-form-field-opening-date', '2026-01-01');
  await pickRecord(page, '#bank-form-field-account', '1096');
  await page.click('#bank-form-save-btn');
  await page.waitForSelector('#bank-settings-cards');
  bank = sql("SELECT id FROM bank_accounts WHERE name = 'SMOKE Browser Bank'");
  bank2 = sql("SELECT id FROM bank_accounts WHERE name = 'SMOKE Browser Savings'");
  await go(page, '/settings/banks/' + bank + '/edit');
  ok(await page.locator('#bank-form-opening-link').count() === 1, 'an existing bank account links the prefilled opening entry');
  await page.screenshot({ path: `${SHOTS}/desktop-bank-form.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ledger items the lines will meet: receipts banked straight to the account
for (const [amt, date] of [['1234.56', '2026-06-10'], ['250.00', '2026-06-11'], ['310.00', '2026-06-12']]) {
  const r = sql(`INSERT INTO receipts (customer_id, received_on, amount, currency, method, reference, deposit_to_bank_account_id, recorded_by) SELECT id, '${date}', ${amt}, 'USD', 'transfer', 'BROWSER', ${bank}, 27 FROM customers WHERE name = 'SMOKE Cash Acme' LIMIT 1 RETURNING id`).split('\n')[0];
  sql(`SELECT gl_receipt_post(${r}, 27)`);
}
// ---- phone: Bea imports, matches, makes an entry, ignores ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — the Bookkeeper');
  await go(page, '/cash/');
  await widthOK(page, 'Bank accounts', 375); await touch(page, 'Bank accounts');
  ok((await page.locator('#bank-card-' + bank).count()) === 1 && (await page.locator('#bank-card-' + bank + '-kind').innerText()) === 'Checking', 'a card per bank account with its kind');
  await page.screenshot({ path: `${SHOTS}/phone-banks.png` });
  await page.click('#bank-card-' + bank + '-import-btn');
  await page.waitForSelector('#bank-import-form');
  await widthOK(page, 'Import a statement', 375); await touch(page, 'Import a statement');
  ok((await page.inputValue('#bank-import-bank')) === bank, 'Import opens with the bank account chosen');
  await page.setInputFiles('#bank-import-file', '/tmp/SMOKE-browser-bank.csv');
  await page.selectOption('#bank-import-dateformat', 'ymd');
  await page.click('#bank-import-preview-btn');
  await page.waitForSelector('#import-preview');
  ok((await page.locator('#import-preview-table tbody tr').count()) === 5 && (await page.locator('#import-preview-columns').innerText()).includes('amount: Amount'), 'the preview shows five rows and the detected columns');
  await widthOK(page, 'The import preview', 375); await touch(page, 'The import preview');
  await page.screenshot({ path: `${SHOTS}/phone-import-preview.png` });
  ok(await page.locator('#bank-import-btn').isEnabled(), 'Import is open after a clean preview');
  await page.click('#bank-import-btn');
  await page.waitForSelector('#bank-lines');
  ok(page.url().includes('/cash/match') && page.url().includes('notice=imported'), 'importing lands on the match page (' + page.url() + ')');
  ok((await page.locator('[id^="bank-line-"][id$="-amount"]').count()) === 5, 'five lines wait to be matched');
  await widthOK(page, 'Match the bank', 375); await touch(page, 'Match the bank');
  ok(await page.evaluate(() => { const a = document.querySelector('#bank-lines .card'); const b = document.querySelectorAll('#bank-lines .card')[1]; return a && b && Math.abs(a.getBoundingClientRect().left - b.getBoundingClientRect().left) < 2 && b.getBoundingClientRect().top > a.getBoundingClientRect().bottom - 1; }), 'the match cards stack on a phone');
  await page.screenshot({ path: `${SHOTS}/phone-match.png` });
  const br1 = idOf('BR-1');
  const unmatchedBefore = await page.locator('#bank-figures-unmatched').innerText();
  await page.click(`#bank-line-${br1} button[id$="-btn"][id*="-match-"]`);
  await page.waitForSelector(`#bank-line-${br1}`, { state: 'detached' });
  await page.waitForFunction((n) => document.querySelector('#bank-figures-unmatched').textContent === String(n), parseInt(unmatchedBefore, 10) - 1);
  ok(true, 'matching a line removes its card and re-renders the figures out of band (' + unmatchedBefore + ' → ' + (parseInt(unmatchedBefore, 10) - 1) + ' lines to match)');
  ok((await page.locator('#bank-match-auto-btn').innerText()).toLowerCase().includes('3 sure ones'), 'the three lines with one candidate each were offered as "sure ones" (one is matched by hand first): ' + (await page.locator('#bank-match-auto-btn').innerText()));
  await page.click('#bank-match-auto-btn');
  await page.waitForSelector(`#bank-line-${idOf('BR-2')}`, { state: 'detached', timeout: 15000 });
  await page.waitForSelector(`#bank-line-${idOf('BR-3')}`, { state: 'detached', timeout: 15000 });
  ok(sql("SELECT count(*) FROM bank_transactions WHERE external_ref IN ('BR-1','BR-2','BR-3') AND status = 'matched'") === '3', 'the button matched both sure ones, each its own click');
  const br4 = idOf('BR-4');
  await page.click(`#bank-line-${br4}-entry summary`);
  await pickRecord(page, `#bank-line-${br4}-entry-account`, '6500');
  page.once('dialog', (d) => d.accept());
  await page.click(`#bank-line-${br4}-entry-btn`);
  await page.waitForSelector(`#bank-line-${br4}`, { state: 'detached' });
  ok(sql(`SELECT matched_type FROM bank_transactions WHERE id = ${br4}`) === 'journal_entry', 'an entry for the fee posts and matches the line');
  const br5 = idOf('BR-5');
  await page.click(`#bank-line-${br5}-ignore summary`);
  await page.fill(`#bank-line-${br5}-ignore-reason`, 'SMOKE browser noise');
  page.once('dialog', (d) => d.accept());
  await page.click(`#bank-line-${br5}-ignore-btn`);
  await page.waitForSelector(`#bank-line-${br5}`, { state: 'detached' });
  ok(sql(`SELECT status FROM bank_transactions WHERE id = ${br5}`) === 'ignored', 'an ignored line leaves with its reason');
  await go(page, '/cash/match?bank_account=' + bank);
  ok(await page.locator('#bank-match-empty').count() === 1, 'nothing left to match');
  await go(page, '/cash/reconcile?bank_account=' + bank);
  await widthOK(page, 'Reconcile (start)', 375); await touch(page, 'Reconcile (start)');
  await page.fill('#bank-reconcile-date', '2026-06-30');
  await page.fill('#bank-reconcile-balance', '1,782.56');
  await page.click('#bank-reconcile-start-btn');
  await page.waitForSelector('#reconcile-figures');
  run = page.url().match(/reconciliation=(\d+)/)?.[1] || sql(`SELECT id FROM bank_reconciliations WHERE bank_account_id = ${bank} AND status = 'in_progress'`);
  ok((await page.locator('#reconcile-difference').innerText()).startsWith('0.00') && (await page.locator('#reconcile-cleared').innerText()).startsWith('1,782.56'), 'started: cleared 1,782.56 (the ignored line counts for nothing), difference 0.00');
  ok((await page.locator('#reconcile-complete-note').count()) === 1 && (await page.locator('#reconcile-complete-btn').count()) === 0, 'a Bookkeeper sees "the controller completes", not Complete');
  await widthOK(page, 'Reconcile', 375); await touch(page, 'Reconcile');
  await page.screenshot({ path: `${SHOTS}/phone-reconcile.png` });
  for (const [p, label] of [['/cash/transfers/', 'Transfers'], ['/cash/transfers/new', 'New transfer'], ['/cash/banks/' + bank, 'A bank account']]) { await go(page, p); await widthOK(page, label, 375); await touch(page, label); }
  await go(page, '/cash/transfers/new?from=' + bank + '&to=' + bank2);
  await page.fill('#transfer-form-field-amount', '100');
  await page.click('#transfer-form-save-btn');
  await page.waitForSelector('#transfer-head');
  ok(page.url().includes('notice=recorded') && (await page.locator('#transfer-head-status').innerText()) === 'Recorded', 'a transfer records and lands on its page');
  await widthOK(page, 'A transfer', 375);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- phone: Carl completes ----
{
  const { ctx, page, errors } = await session(28, PHONE);
  console.log('375 x 740 — the Controller');
  await go(page, '/cash/reconcile?bank_account=' + bank);
  await page.waitForSelector('#reconcile-figures');
  ok(await page.locator('#reconcile-complete-btn').isEnabled(), 'Complete is open at a zero difference');
  page.once('dialog', (d) => d.accept());
  await page.click('#reconcile-complete-btn');
  await page.waitForSelector('#reconciliation-head');
  ok(page.url().includes('notice=completed') && (await page.locator('#reconciliation-head-status').innerText()) === 'completed', 'completing lands on the reconciliation, completed');
  await widthOK(page, 'A reconciliation', 375); await touch(page, 'A reconciliation');
  ok((await page.locator('#reconciliation-reopen-btn').count()) === 1, 'the latest completed reconciliation offers Reopen with a reason');
  await page.screenshot({ path: `${SHOTS}/phone-reconciliation.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- desktop: the cards, the home ----
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — the Controller');
  await go(page, '/cash/');
  await widthOK(page, 'Bank accounts', 1280);
  ok((await page.locator('#bank-card-' + bank + '-reconciled').innerText()).includes('Jun 30, 2026'), 'the card says "reconciled through Jun 30, 2026"');
  await page.screenshot({ path: `${SHOTS}/desktop-banks.png` });
  await go(page, '/cash/banks/' + bank);
  await widthOK(page, 'A bank account', 1280);
  ok(await page.locator('#bank-lines-table').isVisible() && (await page.locator('#bank-recons .list-group-item').count()) === 1, 'the lines table and the reconciliation history');
  await page.screenshot({ path: `${SHOTS}/desktop-bank.png` });
  await go(page, '/');
  await widthOK(page, 'Home', 1280);
  ok(await page.locator('#home-cash-totals').count() === 1 && (await page.locator('#home-cash-' + bank).count()) === 1, 'the home: the account\'s row and the totals per currency');
  await page.click('#home-cash-trend summary');
  ok(await page.locator('#home-cash-trend-table').isVisible() && (await page.locator('#home-cash-trend-table tbody tr').count()) === 12, 'the twelve-month table opens');
  await page.screenshot({ path: `${SHOTS}/desktop-home-cash.png` });
  await go(page, '/cash/reconciliations/' + run);
  await widthOK(page, 'A reconciliation', 1280);
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
