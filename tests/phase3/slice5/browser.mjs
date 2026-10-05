// Headless Chromium proof of slice 5 (docs/build-specs/payables.md, "Proof", 6): the payables screens at 375 x 740 and 1280 x 800 with scrollWidth = viewport, every button >= 44 px on a phone,
// the lines editor on a phone, the flows through the real screens (vendor, bill with its document, a line, approval by the Controller, a payment: draft, approve, record; a payment run with its live total), no console errors.
// Run through tests/phase3/slice5/run.sh (servers up; the proofs before it left their data).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots-p3s5';
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
sql("INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by) SELECT 'SMOKE Checking', 'checking', id, 'USD', 0, '2026-01-01', 1 FROM accounts WHERE code = '1010' AND NOT EXISTS (SELECT 1 FROM bank_accounts WHERE name = 'SMOKE Checking')");
{ const ctx = await browser.newContext(); const p = await ctx.newPage(); await p.goto(mint(1)); const d = await api(p, '/periods/'); if (d.fiscal_years.length === 0) { const csrf = await p.evaluate(() => document.querySelector('#csrf-token-meta').content); await p.request.post(BASE + '/periods/save.php', { form: { start: '2026-01-01', csrf_token: csrf } }); } await ctx.close(); }
fs.writeFileSync('/tmp/SMOKE-browser-bill.pdf', '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');

// ---- phone: Bea enters, Carl approves ----
let bill, vendor, payId, inv;
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — the Bookkeeper');
  await go(page, '/vendors/new');
  await widthOK(page, 'Add vendor', 375); await touch(page, 'Add vendor');
  await page.fill('#vendor-form-field-name', 'SMOKE Browser Vendor');
  await page.fill('#vendor-form-field-email', 'ap@browservendor.example.invalid');
  await page.selectOption('#vendor-form-field-pay', 'ach');
  await page.click('#vendor-form-save-btn');
  await page.waitForSelector('#vendor-view-facts');
  ok(page.url().includes('notice=saved'), 'a vendor saves and lands on their page (' + page.url() + ')');
  vendor = page.url().match(/\/vendors\/(\d+)/)[1];
  await widthOK(page, 'A vendor', 375); await touch(page, 'A vendor');
  await page.fill('#vendor-contact-name', 'Browser Payable');
  await page.fill('#vendor-contact-email', 'payable@browservendor.example.invalid');
  await page.check('#vendor-contact-primary');
  await page.click('#vendor-contact-add-btn');
  await page.waitForSelector('#vendor-contacts .list-group-item[id^="contact-"]');
  ok((await page.locator('#vendor-contacts').innerText()).includes('Browser Payable'), 'a contact is added inline on a vendor');
  await page.click('#vendor-view-bill-btn');
  await page.waitForSelector('#bill-form');
  ok((await page.inputValue('#bill-form-field-vendor')) === vendor, 'New bill opens with the vendor chosen');
  await widthOK(page, 'New bill', 375); await touch(page, 'New bill');
  await page.fill('#bill-form-field-ref', 'BR-100');
  await page.fill('#bill-form-line-0-description', 'Browser hosting');
  await page.fill('#bill-form-line-0-quantity', '2');
  await page.fill('#bill-form-line-0-price', '75');
  await page.setInputFiles('#bill-form-field-document', '/tmp/SMOKE-browser-bill.pdf');
  await page.screenshot({ path: `${SHOTS}/phone-bill-form.png` });
  await page.click('#bill-form-save-btn');
  await page.waitForSelector('#bill-lines');
  bill = page.url().match(/\/bills\/(\d+)/)[1];
  ok(page.url().includes('notice=saved') && (await page.locator('#bill-totals-total').innerText()).startsWith('150.00'), 'the draft lands on its page with the total (150.00 USD)');
  ok((await page.locator('#bill-head-nodoc').count()) === 0 && (await page.locator('#bill-attachments .list-group, #bill-attachments-list a').count()) >= 1, 'the document went up with the form: no "no document" chip, the file is listed');
  await widthOK(page, 'A bill (draft)', 375); await touch(page, 'A bill (draft)');
  ok(await page.evaluate(() => { const t = document.querySelector('#bill-lines .table-responsive'); return t && t.scrollWidth > t.clientWidth; }), 'the lines editor scrolls inside .table-responsive on a phone');
  ok((await page.locator('#bill-lines-table th', { hasText: 'Expense account' }).count()) === 1, 'the editor\'s account column is "Expense account"');
  ok((await page.locator('#bill-awaiting-note').count()) === 1 && (await page.locator('#bill-approve-btn').count()) === 0, 'a Bookkeeper sees "awaiting approval", not Approve');
  await page.screenshot({ path: `${SHOTS}/phone-bill-draft.png` });
  await page.click('#bill-line-new-save-btn');
  await page.waitForSelector('#flash .alert');
  ok((await page.locator('#flash').innerText()).includes('give the line a description'), 'a line with nothing in it: the words in #flash');
  await page.fill('#bill-line-new-description', 'Extra bandwidth');
  await page.fill('#bill-line-new-price', '25');
  await page.click('#bill-line-new-save-btn');
  await page.waitForFunction(() => document.querySelector('#bill-totals-total') && document.querySelector('#bill-totals-total').textContent.startsWith('175.00'));
  ok((await page.locator('[id^="bill-line-"][id$="-save-btn"]').count()) === 3, 'adding a line re-rendered the editor and the totals (175.00 USD)');
  await page.screenshot({ path: `${SHOTS}/phone-bill-lines.png` });
  for (const [p, label] of [['/vendors/', 'Vendors'], ['/bills/', 'Bills'], ['/bills/?status=draft', 'Bills awaiting approval'], ['/payments/', 'Payments'], ['/payment-runs/', 'Payment runs'], ['/vendor-credits/', 'Vendor credits'], ['/vendor-credits/new?vendor=' + vendor, 'New vendor credit'], ['/bills/new', 'New bill']]) {
    await go(page, p); await widthOK(page, label, 375); await touch(page, label);
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- phone: Carl approves and prepares the payment ----
{
  const { ctx, page, errors } = await session(28, PHONE);
  console.log('375 x 740 — the Controller');
  await go(page, '/bills/' + bill);
  await touch(page, 'A bill (the Controller\'s)');
  page.once('dialog', (d) => d.accept());
  await page.click('#bill-approve-btn');
  await page.waitForSelector('#bill-head-entry-link');
  ok(page.url().includes('notice=approved') && (await page.locator('#bill-head-status').innerText()) === 'Approved', 'approving lands on the approved bill with its entry link');
  ok((await page.locator('#bill-lines-readonly-note, #bill-lines-table').count()) >= 1 && (await page.locator('#bill-line-new-save-btn').count()) === 0, 'the lines are read-only after approval');
  await widthOK(page, 'A bill (approved)', 375); await touch(page, 'A bill (approved)');
  await page.screenshot({ path: `${SHOTS}/phone-bill-approved.png` });
  await page.click('#bill-payment-btn');
  await page.waitForSelector('#payment-form');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.selectOption('#payment-form-field-bank', { index: 1 })]);
  await page.waitForSelector('#payment-form');
  ok((await page.inputValue('#payment-form-field-amount')) === '175.00' && (await page.locator('#payment-bill-' + bill).isChecked()), 'Prepare a payment opens with the bill checked and 175.00 filled');
  await widthOK(page, 'Prepare a payment', 375); await touch(page, 'Prepare a payment');
  await page.uncheck('#payment-bill-' + bill);
  await page.check('#payment-bill-' + bill);
  ok((await page.locator('#payment-apply-total-amount').innerText()) === '175.00', 'the chosen total follows the checkboxes live');
  await page.screenshot({ path: `${SHOTS}/phone-payment-form.png` });
  await page.fill('#payment-form-field-date', '2026-06-20');
  await page.click('#payment-form-save-btn');
  await page.waitForSelector('#payment-head');
  payId = page.url().match(/\/payments\/(\d+)/)[1];
  ok(page.url().includes('notice=saved') && (await page.locator('#payment-head-status').innerText()) === 'Draft', 'the draft payment lands on its page');
  await widthOK(page, 'A payment', 375); await touch(page, 'A payment');
  page.once('dialog', (d) => d.accept());
  await page.click('#payment-approve-btn');
  await page.waitForSelector('#payment-record-form');
  ok((await page.locator('#payment-head-status').innerText()) === 'Approved', 'approving shows the record form: "When did the money leave the account?"');
  await page.screenshot({ path: `${SHOTS}/phone-payment-approved.png` });
  await go(page, '/payments/' + payId);
  await page.fill('#payment-record-date', '2026-06-21');
  page.once('dialog', (d) => d.accept());
  await page.click('#payment-record-btn');
  await page.waitForFunction(() => document.querySelector('#payment-head-status') && document.querySelector('#payment-head-status').textContent === 'Recorded');
  ok((await page.locator('#notice-banner').innerText()).includes('match it when it shows at the bank') && (await page.locator('#payment-head-unmatched a').count()) === 1, 'recording says "match it when it shows at the bank" with a link');
  await widthOK(page, 'A payment (recorded)', 375);
  ok((await page.locator('#payment-head-date').innerText()).includes('21'), 'the payment shows the day the money moved');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- phone: a payment run ----
{
  const mk = sql(`SELECT 1`);
  const ids = [];
  for (const [ref, amt, due] of [['RUN-A', '40.00', '2026-07-01'], ['RUN-B', '60.00', '2026-07-05']]) {
    ids.push(sql(`INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency, created_by) VALUES (${vendor}, '${ref}', '2026-06-01', '${due}', 'USD', 27) RETURNING id`).split('\n')[0]);
    sql(`INSERT INTO bill_lines (bill_id, sort_order, description, quantity, unit_price, expense_account_id) SELECT ${ids[ids.length - 1]}, 1, 'Run line', 1, ${amt}, id FROM accounts WHERE code = '6200'`);
    sql(`SELECT gl_bill_approve(${ids[ids.length - 1]}, 28)`);
  }
  const { ctx, page, errors } = await session(27, PHONE);
  await go(page, '/payment-runs/new?due_by=2026-07-15');
  await page.selectOption('#payment-run-bank-field', { index: 1 });
  await page.click('#payment-run-pick-btn');
  await page.waitForSelector('#run-bills');
  await widthOK(page, 'New payment run', 375); await touch(page, 'New payment run');
  ok((await page.locator('.run-bill').count()) >= 2 && (await page.locator('#run-bill-' + ids[0]).count()) === 1, 'the approved bills due by the date are listed with checkboxes');
  for (const box of await page.locator('.run-bill').all()) { if (!ids.includes(await box.getAttribute('value'))) { await box.uncheck(); } }     // other proofs' leftovers are not this run's
  const total = await page.locator('#run-bills-total-amount').innerText();
  const first = page.locator('.run-bill').first();
  const amt = parseFloat(await first.getAttribute('data-payable'));
  await first.uncheck();
  ok(parseFloat(await page.locator('#run-bills-total-amount').innerText()) === Math.round((parseFloat(total) - amt) * 100) / 100, 'the run\'s total follows the checkboxes live');
  await first.check();
  await page.screenshot({ path: `${SHOTS}/phone-run-form.png` });
  await page.click('#payment-run-form-save-btn');
  await page.waitForSelector('#payment-run-head');
  const run = page.url().match(/\/payment-runs\/(\d+)/)[1];
  ok(page.url().includes('notice=saved') && (await page.locator('#payment-run-head-status').innerText()) === 'Draft', 'the run lands on its page as a draft');
  await widthOK(page, 'A run', 375); await touch(page, 'A run');
  await page.screenshot({ path: `${SHOTS}/phone-run-draft.png` });
  await ctx.close();
  const c2 = await session(28, PHONE);
  await go(c2.page, '/payment-runs/' + run);
  c2.page.once('dialog', (d) => d.accept());
  await c2.page.click('#payment-run-approve-btn');
  await c2.page.waitForSelector('#payment-run-record-form');
  ok((await c2.page.locator('#payment-run-head-status').innerText()) === 'Approved', 'the Controller approves the run');
  await c2.page.fill('#payment-run-record-date', '2026-07-02');
  c2.page.once('dialog', (d) => d.accept());
  await c2.page.click('#payment-run-record-btn');
  await c2.page.waitForFunction(() => document.querySelector('#payment-run-head-status') && document.querySelector('#payment-run-head-status').textContent === 'Recorded');
  ok((await c2.page.locator('#payment-run-csv-link').count()) === 1, 'a recorded run offers its CSV');
  await c2.page.screenshot({ path: `${SHOTS}/phone-run-recorded.png` });
  ok(c2.errors.length === 0 && errors.length === 0, 'no console errors' + (c2.errors.concat(errors).length ? ': ' + c2.errors.concat(errors).join(' / ') : ''));
  await c2.ctx.close();
}
// ---- desktop ----
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — the Controller');
  await go(page, '/bills/');
  ok(await page.locator('#bill-list-table').isVisible() && !(await page.locator('#bill-list-cards').isVisible()), 'a table on the desktop, the cards hidden');
  await widthOK(page, 'Bills', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-bills.png` });
  await go(page, '/bills/' + bill);
  await page.waitForSelector('#bill-head');
  await widthOK(page, 'A bill', 1280);
  ok(await page.locator('#bill-paid').isVisible() && await page.locator('#bill-document').isVisible(), 'the bill page\'s panels: paid, the document');
  ok((await page.locator('#bill-paid-payment-' + payId).count()) === 1, 'the bill lists the payment that paid it');
  await page.screenshot({ path: `${SHOTS}/desktop-bill.png` });
  for (const p of ['/vendors/', '/payments/', '/payment-runs/', '/vendor-credits/']) { await go(page, p); await widthOK(page, p, 1280); }
  await go(page, '/payments/');
  ok(await page.locator('#payment-list-table').isVisible(), 'the payments table on the desktop');
  await page.screenshot({ path: `${SHOTS}/desktop-payments.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
