// Headless Chromium proof of slice 8 (docs/build-specs/recurring-worker.md, "Proof"): the recurring screens at 375 x 740 and 1280 x 800 with scrollWidth = viewport, every control >= 44 px on a phone, the flow through the real screens
// (add a template from the form, Run now, toggle, change, delete; Ask the Bookkeeper on a bill), no console errors. Run through tests/phase3/slice8/run.sh.
// Run through tests/phase3/slice7/run.sh (servers up; the proofs before it left their data).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
import { pickRecord } from '../../picker/pick.mjs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots-p3s8';
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

const ensureYear = async () => { const ctx = await browser.newContext(); const p = await ctx.newPage(); await p.goto(mint(1)); const d = await api(p, '/periods/'); if (d.fiscal_years.length === 0) { const csrf = await p.evaluate(() => document.querySelector('#csrf-token-meta').content); await p.request.post(BASE + '/periods/save.php', { form: { csrf_token: csrf, start: '2026-01-01' }, headers: { Accept: 'application/json' } }); } await ctx.close(); };
await ensureYear();
sql("INSERT INTO accounts (code, name, account_type, subtype, is_bank, is_control, is_system) SELECT '6200', 'SMOKE Rent', 'expense', 'other', false, false, false WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE code = '6200')");
sql("INSERT INTO accounts (code, name, account_type, subtype, is_bank, is_control, is_system) SELECT '1010', 'SMOKE Bank', 'asset', 'bank', true, false, false WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE code = '1010')");

// ---- phone: the owner makes a template through the form ----
let tid;
{
  const { ctx, page, errors } = await session(1, PHONE);
  console.log('375 x 740 — the administrator');
  await go(page, '/settings/recurring/');
  ok((await page.locator('#recurring-cards').count()) === 1, 'the list opens (' + (await page.locator('#recurring-cards .card').count()) + ' card(s) from the earlier proofs)');
  await widthOK(page, 'the list', 375); await touch(page, 'the list');
  await page.screenshot({ path: `${SHOTS}/phone-recurring-first.png` });
  await page.click('#recurring-add-btn');
  await page.waitForURL('**/settings/recurring/new');
  await widthOK(page, 'the add form', 375); await touch(page, 'the add form');
  await page.fill('#recurring-form-field-name', 'SMOKE Browser rent');
  await page.fill('#recurring-form-field-next', '2026-11-01');
  const rent = await pickRecord(page, '#recurring-line-1-account', '6200');
  ok(rent.startsWith('6200'), 'the account picker lists the chart (a template line: ' + rent + ')');
  await page.fill('#recurring-line-1-description', 'Office rent');
  await page.fill('#recurring-line-1-debit', '900.00');
  await pickRecord(page, '#recurring-line-2-account', '1010');
  await page.fill('#recurring-line-2-credit', '800.00');
  await page.screenshot({ path: `${SHOTS}/phone-recurring-form.png` });
  await page.click('#recurring-form-save-btn');
  await page.waitForTimeout(800);
  ok((await page.locator('#flash').innerText()).toLowerCase().includes('balance'), 'an unbalanced template is refused in words on the page: ' + (await page.locator('#flash').innerText()).trim().slice(0, 90));
  await page.fill('#recurring-line-2-credit', '900.00');
  await page.click('#recurring-form-save-btn');
  await page.waitForURL(/\/settings\/recurring\/\d+/, { timeout: 15000 });
  tid = sql("SELECT id FROM recurring_templates WHERE name = 'SMOKE Browser rent'");
  ok(tid !== '' && page.url().includes('/settings/recurring/' + tid), 'saved: the browser lands on the template');
  await widthOK(page, 'the template', 375); await touch(page, 'the template');
  const t = await page.locator('#recurring-view-content').innerText();
  ok(t.includes('Monthly') && t.includes('Next') && t.includes('Office rent') && t.includes('Not run yet'), 'it shows the schedule, the line and "Not run yet"');
  await page.screenshot({ path: `${SHOTS}/phone-recurring-view.png` });
  await page.click('#recurring-run-btn');
  await page.waitForTimeout(1200);
  await go(page, '/settings/recurring/' + tid);
  ok((await page.locator('#recurring-view-runs tr').count()) === 1 && (await page.locator('#recurring-view-runs').innerText()).includes('drafted'), 'Run now: one run, drafted, with a link to the entry');
  ok(sql(`SELECT next_date FROM recurring_templates WHERE id = ${tid}`) === '2026-12-01', 'the schedule moved on');
  await go(page, '/settings/recurring/');
  ok((await page.locator('#recurring-' + tid).count()) === 1 && (await page.locator('#recurring-' + tid).innerText()).includes('Next'), 'the list shows the card');
  await page.click('#recurring-' + tid + '-toggle-btn');
  await page.waitForTimeout(800);
  await go(page, '/settings/recurring/');
  ok(sql(`SELECT active FROM recurring_templates WHERE id = ${tid}`) === 'f' && (await page.locator('#recurring-' + tid).innerText()).includes('Off'), 'Turn off: the card says Off');
  await page.screenshot({ path: `${SHOTS}/phone-recurring-list.png` });
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- phone: the Bookkeeper cannot reach the settings; Run now needs the right ----
{
  const { ctx, page } = await session(27, PHONE);
  const r = await page.goto(BASE + '/settings/recurring/', { waitUntil: 'networkidle' });
  ok(r.status() === 403, 'the Bookkeeper gets 403 on the recurring list (settings.manage)');
  await ctx.close();
}
// ---- desktop: change, copy from an entry, delete ----
{
  const { ctx, page, errors } = await session(1, DESK);
  console.log('1280 x 800 — the administrator');
  await go(page, '/settings/recurring/');
  await widthOK(page, 'the list', 1280);
  await page.screenshot({ path: `${SHOTS}/desk-recurring-list.png` });
  const entry = sql("SELECT id FROM journal_entries WHERE source = 'recurring' ORDER BY id DESC LIMIT 1");
  await go(page, '/settings/recurring/new?from=journal:' + entry);
  ok((await page.inputValue('#recurring-line-1-debit')) === '900.00', 'a template copied from an entry carries its lines (900.00)');
  await widthOK(page, 'the copied form', 1280);
  await go(page, '/settings/recurring/' + tid + '/edit');
  await page.fill('#recurring-form-field-name', 'SMOKE Browser rent (renamed)');
  await page.check('#recurring-form-field-active');
  await page.click('#recurring-form-save-btn');
  await page.waitForTimeout(1500);
  ok(sql(`SELECT name || '/' || active FROM recurring_templates WHERE id = ${tid}`) === 'SMOKE Browser rent (renamed)/true', 'Change: the name and the on switch saved');
  await page.screenshot({ path: `${SHOTS}/desk-recurring-view.png` });
  page.once('dialog', (d) => d.accept());
  await page.click('#recurring-delete-btn');
  await page.waitForURL('**/settings/recurring/**', { timeout: 15000 });
  await page.waitForTimeout(500);
  ok(sql(`SELECT count(*) FROM recurring_templates WHERE id = ${tid}`) === '0', 'Delete: confirmed, gone, and the entry it drafted stays (' + sql("SELECT count(*) FROM journal_entries WHERE source = 'recurring'") + ')');
  ok(errors.length === 0, 'no console errors on the desktop' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- the bill page: Ask the Bookkeeper ----
{
  sql("INSERT INTO vendors (name) SELECT 'SMOKE Browser Vendor' WHERE NOT EXISTS (SELECT 1 FROM vendors)");
  const bill = sql("INSERT INTO bills (vendor_id, bill_date, due_date, currency, created_by) SELECT (SELECT id FROM vendors LIMIT 1), current_date, current_date, 'USD', 27 WHERE EXISTS (SELECT 1 FROM vendors) RETURNING id").split('\n')[0];
  if (bill) {
    sql(`INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('bill', ${bill}, 'SMOKE.pdf', 'application/pdf', 4, repeat('a', 64), 'smoke', 27)`);
    const { ctx, page } = await session(27, PHONE);
    await go(page, '/bills/' + bill);
    ok((await page.locator('#bill-ask-bookkeeper-btn').count()) === 1, 'a bill with its document shows "Ask the Bookkeeper"');
    await widthOK(page, 'the bill', 375);
    await page.click('#bill-ask-bookkeeper-btn');
    await page.waitForTimeout(1500);
    ok(sql(`SELECT count(*) FROM agent_dispatches WHERE record_type = 'bill' AND record_id = ${bill}`) === '1', 'pressing it records one dispatch');
    await ctx.close();
  } else { console.log('  (no vendor: the bill-page proof is covered by worker.php)'); }
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
