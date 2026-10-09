// Headless Chromium pass for the record picker in the General Ledger (design-system references/record-picker.md; adapted from the plugin's picker-browser.mjs):
// the bill form (a required vendor picker, line pickers for the account — typed: expense and asset only — and the item), the bills list's vendor filter, a bill saved
// through the UI, 375 px, dark mode. Run after scripts/prove-picker.sh has seeded the scratch database (30 "Picker Vendor", 30 "Picker Account", 30 "Picker Item") and
// with the app up:  S=/tmp/gl-picker BASE=http://127.0.0.1:8391 ACTION_TOKEN_KEY=… node tests/picker/browser.mjs   (scripts/prove-picker.sh KEEP=1 leaves the servers up.)
// Playwright comes from the kernel's web/node_modules (chromium is cached).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const S = process.env.S || '/tmp/gl-picker-shots', B = process.env.BASE || 'http://127.0.0.1:8391', KEY = process.env.ACTION_TOKEN_KEY;
fs.mkdirSync(S, { recursive: true });
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.gl.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const text = Buffer.from(JSON.stringify({ ...fixture.claims[String(member)], member_id: member })).toString('base64url');
  return B + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
let passed = 0, failed = 0;
const ok = (n, c) => { if (c) passed++; else failed++; console.log((c ? 'ok   ' : 'FAIL ') + n); };
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const page = await ctx.newPage();
const errors = []; page.on('pageerror', e => errors.push(e.message)); page.on('console', m => { if (m.type() === 'error' && !/status of (422|403|404)/.test(m.text())) errors.push(m.text()); });
await page.goto(mint(1), { waitUntil: 'networkidle' });
const V = '#bill-form-field-vendor', rows = () => page.$$('#record-picker .record-picker-row');
await page.goto(B + '/bills/new'); await page.waitForSelector(V + '-open');
await page.screenshot({ path: S + '/1-form-desktop.png' });
// the vendor picker
await page.click(V + '-open'); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker-list .record-picker-row');
ok('the modal opens with the first page of 25', (await rows()).length === 25);
await page.waitForFunction(() => document.activeElement.id === 'record-picker-search', null, { timeout: 3000 }).catch(() => {});
ok('the search box has focus', await page.evaluate(() => document.activeElement.id === 'record-picker-search'));
ok('the title is the source\'s', (await page.textContent('#record-picker-title')).trim() === 'Vendors');
ok('no create button (the GL has no inline vendor panel on a bill)', await page.evaluate(() => document.getElementById('record-picker-footer').classList.contains('d-none')));
await page.type('#record-picker-search', 'vendor 1'); await page.waitForTimeout(700);
const n = (await rows()).length; ok('typing narrows the list (' + n + ' rows)', n === 10);
await page.screenshot({ path: S + '/2-picker-desktop.png' });
await page.keyboard.press('ArrowDown'); ok('ArrowDown moves to the first row', await page.evaluate(() => document.activeElement.classList.contains('record-picker-row')));
await page.keyboard.press('ArrowDown'); const label = await page.evaluate(() => document.activeElement.dataset.label);
await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
ok('Enter picks the focused row (' + label + ')', (await page.textContent(V + '-open')).trim() === label && (await page.inputValue(V)) !== '');
await page.waitForFunction(() => document.activeElement.id === 'bill-form-field-vendor-open', null, { timeout: 3000 }).catch(() => {});
ok('focus returns to the opener', await page.evaluate(() => document.activeElement.id === 'bill-form-field-vendor-open'));
ok('a required field has no Clear button', !(await page.$(V + '-clear')));
// Enter in the search box picks the first match
await page.click(V + '-open'); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row');
ok('the chosen row is marked active', await page.evaluate(() => document.querySelector('#record-picker .record-picker-row.active')?.dataset.label === 'Picker Vendor 11'));
await page.type('#record-picker-search', 'vendor 07'); await page.waitForTimeout(600); await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
ok('Enter in the search box picks the first match', (await page.textContent(V + '-open')).trim() === 'Picker Vendor 07');
// Show more
await page.click(V + '-open'); await page.waitForSelector('#record-picker .record-picker-more');
await page.click('#record-picker-more'); await page.waitForFunction(() => document.querySelectorAll('#record-picker .record-picker-row').length > 25);
ok('Show more appends page 2 in place', (await rows()).length >= 30 && !(await page.$('#record-picker-more')));
await page.keyboard.press('Escape'); await page.waitForSelector('#record-picker', { state: 'hidden' });
ok('Escape closes the dialog and keeps the choice', (await page.textContent(V + '-open')).trim() === 'Picker Vendor 07');
// a line's account: typed to expense and asset accounts
const A = '#bill-form-line-0-account';
await page.click(A + '-open'); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row');
ok('the account picker is titled Accounts and lists by code', (await page.textContent('#record-picker-title')).trim() === 'Accounts' && (await page.evaluate(() => document.querySelector('#record-picker .record-picker-row').dataset.label)).startsWith('1000 — '));
await page.type('#record-picker-search', 'revenue'); await page.waitForTimeout(700);
ok('the declared filter holds: no income account on a bill line ("revenue" matches nothing)', (await page.textContent('#record-picker-empty')).includes('No account matches'));
await page.fill('#record-picker-search', 'cost of'); await page.waitForTimeout(700);
await page.click('#record-picker .record-picker-row'); await page.waitForSelector('#record-picker', { state: 'hidden' });
ok('picking an account fills the line (code — name) and its hidden array input', (await page.textContent(A + '-open')).trim() === '5000 — Cost of goods sold' && (await page.inputValue(A)) !== '');
ok('an optional field shows Clear once chosen; Clear empties it back to the placeholder', await (async () => { await page.click(A + '-clear'); return (await page.inputValue(A)) === '' && (await page.textContent(A + '-open')).trim() === 'Default'; })());
await page.click(A + '-open'); await page.waitForSelector('#record-picker .record-picker-row'); await page.type('#record-picker-search', '7105'); await page.waitForTimeout(600); await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
// an item, then save the bill through the UI
await page.click('#bill-form-line-0-item-open'); await page.waitForSelector('#record-picker .record-picker-row'); await page.type('#record-picker-search', 'item 03'); await page.waitForTimeout(600); await page.keyboard.press('Enter'); await page.waitForSelector('#record-picker', { state: 'hidden' });
await page.fill('#bill-form-line-0-description', 'Picker browser line'); await page.fill('#bill-form-line-0-quantity', '2'); await page.fill('#bill-form-line-0-price', '9.50');
await page.fill('#bill-form-field-memo', 'picker browser ' + Date.now());
await page.click('#bill-form-save-btn'); await page.waitForURL(/\/bills\/\d+/, { timeout: 8000 }).catch(() => {});
ok('the bill saved through the pickers and its page names the vendor (' + page.url().replace(B, '') + ')', /\/bills\/\d+/.test(page.url()) && (await page.textContent('body')).includes('Picker Vendor 07'));
// the list's vendor filter refreshes the list on change
await page.goto(B + '/bills/'); await page.waitForSelector('#bills-filter-vendor-open');
ok('the filter field is optional with the placeholder "Every vendor"', (await page.textContent('#bills-filter-vendor-open')).trim() === 'Every vendor');
await page.click('#bills-filter-vendor-open'); await page.waitForSelector('#record-picker .record-picker-row'); await page.type('#record-picker-search', 'vendor 07'); await page.waitForTimeout(600); await page.keyboard.press('Enter');
await page.waitForFunction(() => location.search.includes('vendor='), null, { timeout: 5000 }).catch(() => {});
ok('picking a filter value fires the list refresh (the URL carries vendor=)', page.url().includes('vendor='));
await page.waitForTimeout(500);
ok('the filtered list shows that vendor\'s bill', (await page.textContent('#bill-list')).includes('Picker Vendor 07'));
// phone, light
const phone = await browser.newContext({ viewport: { width: 375, height: 740 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
const p2 = await phone.newPage(); p2.on('pageerror', e => errors.push(e.message));
await p2.goto(mint(1), { waitUntil: 'networkidle' }); await p2.goto(B + '/bills/new'); await p2.waitForSelector(V + '-open');
ok('no horizontal scroll at 375px', await p2.evaluate(() => document.documentElement.scrollWidth <= 375));
await p2.screenshot({ path: S + '/3-form-phone.png' });
const openerH = await p2.evaluate(() => document.querySelector('#bill-form-field-vendor-open').getBoundingClientRect().height);
ok('the vendor field is a touch target on the phone (' + Math.round(openerH) + 'px)', openerH >= 44);
await p2.click(V + '-open'); await p2.waitForSelector('#record-picker.show'); await p2.waitForSelector('#record-picker .record-picker-row');
await p2.type('#record-picker-search', 'vendor'); await p2.waitForTimeout(600);
const box = await p2.evaluate(() => { const r = document.querySelector('#record-picker .modal-content').getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
ok('the modal fills the phone screen (' + box.join('x') + ')', box[0] >= 374 && box[1] >= 700);
const rowH = await p2.evaluate(() => document.querySelector('#record-picker .record-picker-row').getBoundingClientRect().height);
ok('rows are touch targets (' + Math.round(rowH) + 'px)', rowH >= 44);
await p2.screenshot({ path: S + '/4-picker-phone.png' });
await p2.tap('#record-picker .record-picker-row:nth-child(3)'); await p2.waitForSelector('#record-picker', { state: 'hidden' });
ok('a tap picks on the phone', (await p2.textContent(V + '-open')).trim().startsWith('Picker Vendor'));
await p2.evaluate(() => document.documentElement.classList.add('app-skin-dark')); await p2.waitForTimeout(200);
await p2.click(V + '-open'); await p2.waitForSelector('#record-picker.show'); await p2.waitForSelector('#record-picker .record-picker-row'); await p2.waitForTimeout(300);
await p2.screenshot({ path: S + '/5-picker-phone-dark.png' });
await page.goto(B + '/bills/new'); await page.evaluate(() => document.documentElement.classList.add('app-skin-dark'));
await page.click(V + '-open'); await page.waitForSelector('#record-picker.show'); await page.waitForSelector('#record-picker .record-picker-row'); await page.waitForTimeout(300);
await page.screenshot({ path: S + '/6-picker-desktop-dark.png' });
ok('no page errors', errors.length === 0);
if (errors.length) console.log('errors: ' + errors.join(' | '));
await browser.close();
console.log(`${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
