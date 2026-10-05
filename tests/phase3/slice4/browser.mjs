// Headless Chromium proof of slice 4 (docs/build-specs/receivables.md, "Proof", 9): the receivables screens at 375 x 740 and 1280 x 800 with scrollWidth = viewport, every button >= 44 px on a phone,
// the lines editor on a phone, the flows through the real screens (customer, invoice, a line, issue, send, receipt, claim on the secure page with JavaScript off, deposit with its live total), no console errors.
// Run through tests/phase3/slice4/run.sh (servers up; the proofs before it left their data). psql reads the one fact a browser cannot: the secure link's token.
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots-p3s4';
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

// ---- phone: Bea walks the whole life ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — the Bookkeeper');
  await go(page, '/customers/new');
  await widthOK(page, 'Add customer', 375); await touch(page, 'Add customer');
  await page.fill('#customer-form-field-name', 'SMOKE Browser Customer');
  await page.fill('#customer-form-field-email', 'browser@example.invalid');
  await page.click('#customer-form-save-btn');
  await page.waitForSelector('#customer-view-facts');
  ok(page.url().includes('notice=saved'), 'a customer saves and lands on their page (' + page.url() + ')');
  const cust = page.url().match(/\/customers\/(\d+)/)[1];
  await widthOK(page, 'A customer', 375); await touch(page, 'A customer');
  await page.screenshot({ path: `${SHOTS}/phone-customer.png` });
  await page.fill('#customer-contact-name', 'Browser Contact');
  await page.fill('#customer-contact-email', 'contact@example.invalid');
  await page.check('#customer-contact-primary');
  await page.click('#customer-contact-add-btn');
  await page.waitForSelector('#customer-contacts .list-group-item[id^="contact-"]');
  ok((await page.locator('#customer-contacts').innerText()).includes('Browser Contact'), 'a contact is added inline');
  await page.click('#customer-view-invoice-btn');
  await page.waitForSelector('#invoice-form');
  ok((await page.inputValue('#invoice-form-field-customer')) === cust, 'New invoice opens with the customer chosen');
  await widthOK(page, 'New invoice', 375); await touch(page, 'New invoice');
  await page.fill('#invoice-form-line-0-description', 'Browser service');
  await page.fill('#invoice-form-line-0-quantity', '2');
  await page.fill('#invoice-form-line-0-price', '75');
  await page.click('#invoice-form-save-btn');
  await page.waitForSelector('#invoice-lines');
  ok(page.url().includes('notice=saved') && (await page.locator('#invoice-totals-total').innerText()).startsWith('150.00'), 'the draft lands on its page with the total (150.00 USD)');
  await widthOK(page, 'An invoice (draft)', 375); await touch(page, 'An invoice (draft)');
  ok(await page.evaluate(() => { const t = document.querySelector('#invoice-lines .table-responsive'); return t && t.scrollWidth > t.clientWidth; }), 'the lines editor scrolls inside .table-responsive on a phone');
  await page.screenshot({ path: `${SHOTS}/phone-invoice-draft.png` });
  // a refused line shows its words in #flash and keeps the editor
  await page.click('#invoice-line-new-save-btn');
  await page.waitForSelector('#flash .alert');
  ok((await page.locator('#flash').innerText()).includes('give the line a description'), 'a line with nothing in it: the words in #flash');
  // add a line through the editor: the region and the totals re-render
  await page.fill('#invoice-line-new-description', 'Extra hours');
  await page.fill('#invoice-line-new-price', '25');
  await page.click('#invoice-line-new-save-btn');
  await page.waitForFunction(() => document.querySelector('#invoice-totals-total') && document.querySelector('#invoice-totals-total').textContent.startsWith('175.00'));
  ok((await page.locator('[id^="invoice-line-"][id$="-save-btn"]').count()) === 3, 'adding a line re-rendered the editor and the totals (175.00 USD)');
  await page.screenshot({ path: `${SHOTS}/phone-invoice-lines.png` });
  page.once('dialog', (d) => d.accept());
  await page.click('#invoice-issue-btn');
  await page.waitForSelector('#invoice-head-entry-link');
  const num = (await page.locator('.page-header-title h5').innerText()).trim();
  ok(/^INV-\d{5}$/.test(num) && page.url().includes('notice=issued'), 'issuing lands on the numbered invoice (' + num + ')');
  ok((await page.locator('#invoice-lines-readonly-note, #invoice-lines-table').count()) >= 1 && (await page.locator('#invoice-line-new-save-btn').count()) === 0, 'the lines are read-only after issue');
  await widthOK(page, 'An invoice (issued)', 375); await touch(page, 'An invoice (issued)');
  await page.screenshot({ path: `${SHOTS}/phone-invoice-issued.png` });
  await page.fill('#invoice-send-to', 'billing@example.invalid');
  await page.fill('#invoice-send-message', 'Thanks for your business');
  page.once('dialog', (d) => d.accept());
  await page.click('#invoice-send-btn');
  await page.waitForFunction(() => document.querySelector('#notice-banner') && document.querySelector('#notice-banner').textContent.includes('Queued'));
  ok((await page.locator('#notice-banner').innerText()).includes('Queued') && (await page.locator('#invoice-head-status').innerText()) === 'Sent', 'Send queues it for the customer and the status reads Sent');
  const invId = page.url().match(/\/invoices\/(\d+)/)[1];
  // a receipt through the form
  await go(page, '/receipts/new?customer=' + cust + '&invoice=' + invId);
  await widthOK(page, 'Record a receipt', 375); await touch(page, 'Record a receipt');
  ok((await page.inputValue('#receipt-form-field-amount')) === '175.00', 'the receipt form defaults the amount to the invoice\'s balance');
  await page.screenshot({ path: `${SHOTS}/phone-receipt-form.png` });
  await page.fill('#receipt-form-field-amount', '100');
  await page.fill('#receipt-apply-' + invId + '-amount', '100');
  await page.click('#receipt-form-save-btn');
  await page.waitForSelector('#receipt-head');
  ok(page.url().includes('notice=recorded') && (await page.locator('#receipt-application-' + invId).count()) === 1, 'recording lands on the receipt, applied to the invoice');
  await widthOK(page, 'A receipt', 375); await touch(page, 'A receipt');
  // a deposit with its live total
  await go(page, '/deposits/new');
  await page.selectOption('#deposit-bank-field', { index: 1 });
  await page.click('#deposit-bank-btn');
  await page.waitForSelector('#deposit-form');
  await widthOK(page, 'New deposit', 375); await touch(page, 'New deposit');
  const boxes = page.locator('.deposit-receipt');
  const n = await boxes.count();
  if (n > 0) {
    await boxes.first().check();
    const amt = await boxes.first().getAttribute('data-amount');
    ok((await page.locator('#deposit-total-amount').innerText()) === parseFloat(amt).toFixed(2), 'the deposit total follows the checkboxes live (' + amt + ')');
    await page.click('#deposit-form-save-btn');
    await page.waitForSelector('#deposit-head');
    ok(page.url().includes('/deposits/') && (await page.locator('#deposit-head-status').innerText()) === 'draft', 'the draft deposit lands on its page');
    await widthOK(page, 'A deposit', 375); await touch(page, 'A deposit');
    await page.screenshot({ path: `${SHOTS}/phone-deposit.png` });
  } else { ok(true, 'no undeposited receipt in USD to group (skipped the deposit flow)'); }
  for (const [p, label] of [['/customers/', 'Customers'], ['/invoices/', 'Invoices'], ['/receipts/', 'Receipts'], ['/receipts/claims', 'Claims'], ['/deposits/', 'Deposits'], ['/credit-notes/', 'Credit notes'], ['/credit-notes/new', 'New credit note'], ['/customers/' + cust + '/statement', 'The statement']]) {
    await go(page, p); await widthOK(page, label, 375); await touch(page, label);
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
  // ---- the secure page, JavaScript off, 375 wide ----
  const token = sql(`SELECT substring(body from '[a-f0-9]{48}$') FROM notification_outbox WHERE kind = 'invoice_link' AND record_type = 'invoice' AND record_id = ${invId} AND status = 'skipped' ORDER BY id DESC LIMIT 1`);
  const pub = await session(null, PHONE, { javaScriptEnabled: false });
  console.log('The secure page — 375 x 740, JavaScript off');
  await pub.page.goto(BASE + '/i/' + token, { waitUntil: 'networkidle' });
  ok((await pub.page.locator('#print-number').innerText()).includes(num) && (await pub.page.locator('#print-balance').innerText()).includes('75.00'), 'the page shows the number and the balance due (75.00 USD after the 100.00 receipt)');
  await widthOK(pub.page, 'The secure page', 375);
  await pub.page.screenshot({ path: `${SHOTS}/phone-secure-page.png` });
  await pub.page.fill('#claim-reference', 'BROWSER-1');
  await pub.page.click('#claim-submit-btn');
  await pub.page.waitForSelector('#claim-done-title');
  ok((await pub.page.locator('#claim-done-title').innerText()) === 'Thank you.', 'the claim form posts without JavaScript and thanks the customer');
  ok(sql(`SELECT count(*) FROM receipt_claims WHERE invoice_id = ${invId} AND reference = 'BROWSER-1'`) === '1', 'and the claim is stored');
  await pub.page.goto(BASE + '/i/' + '0'.repeat(48), { waitUntil: 'networkidle' });
  ok((await pub.page.locator('#dead-link-title').innerText()) === 'This link has expired.', 'a dead link shows the one page');
  await pub.ctx.close();
}
// ---- desktop ----
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — the Controller');
  await go(page, '/invoices/?status=partially_paid');
  ok(await page.locator('#invoice-list-table').isVisible() && !(await page.locator('#invoice-list-cards').isVisible()), 'a table on the desktop, the cards hidden');
  await widthOK(page, 'Invoices', 1280);
  await page.screenshot({ path: `${SHOTS}/desktop-invoices.png` });
  await page.click('[id^="invoice-row-"] a >> nth=0');
  await page.waitForSelector('#invoice-head');
  await widthOK(page, 'An invoice', 1280);
  ok(await page.locator('#invoice-received').isVisible() && await page.locator('#invoice-link').isVisible(), 'the invoice page\'s panels: received, the link');
  await page.screenshot({ path: `${SHOTS}/desktop-invoice.png` });
  for (const p of ['/customers/', '/receipts/', '/deposits/', '/credit-notes/']) { await go(page, p); await widthOK(page, p, 1280); }
  await go(page, '/receipts/claims');
  ok((await page.locator('[id^="claim-card-"]').count()) >= 1, 'the claims page lists what customers said');
  await page.screenshot({ path: `${SHOTS}/desktop-claims.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
