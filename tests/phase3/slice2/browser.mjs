// Headless Chromium proof of slice 2 (docs/build-specs/journal-core.md, "Proof", 9): the list as cards on the phone and a table on the desktop, the lines
// editor in .table-responsive, the strip readable, the panels stacked, scrollWidth = viewport, every button ≥ 44 px, no console errors, the flows through
// the real screens (draft, add lines, post, reverse), JavaScript off. Run through tests/phase3/slice2/run.sh (servers up).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots-p3s2';
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
    .filter((e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height < 43.5 && !e.classList.contains('btn-sm') && !e.classList.contains('form-select-sm') && !e.classList.contains('form-control-sm') && e.type !== 'number' && e.type !== 'date' && e.type !== 'file'; })
    .map((e) => (e.id || e.className) + ':' + Math.round(e.getBoundingClientRect().height)));
  ok(small.length === 0, `every button and control is at least 44 px tall on ${label}` + (small.length ? ' — too small: ' + small.join(', ') : ''));
};
const go = async (page, path) => page.goto(BASE + path, { waitUntil: 'networkidle' });
const api = async (page, path, headers = {}) => (await (await page.request.get(BASE + path, { headers: { Accept: 'application/json', ...headers } })).json()).data;
// make sure a fiscal year exists (the admin)
{ const ctx = await browser.newContext(); const p = await ctx.newPage(); await p.goto(mint(1)); const d = await api(p, '/periods/'); if (d.fiscal_years.length === 0) { const csrf = await p.evaluate(() => document.querySelector('#csrf-token-meta').content); await p.request.post(BASE + '/periods/save.php', { form: { start: '2026-01-01', csrf_token: csrf } }); } await ctx.close(); }

// ---- phone: Bea drafts, adds lines, posts, reverses ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — the Bookkeeper');
  await go(page, '/journal/');
  ok(await page.locator('#entry-list').count() === 1, 'the journal renders');
  await widthOK(page, 'Journal', 375); await touch(page, 'Journal');
  await page.screenshot({ path: `${SHOTS}/phone-journal.png` });
  await go(page, '/journal/new');
  await widthOK(page, 'New entry', 375); await touch(page, 'New entry');
  await page.fill('#entry-form-field-date', '2026-10-01');
  await page.fill('#entry-form-field-memo', 'SMOKE browser entry');
  const sixOne = await page.locator('#entry-form-line-0-account option', { hasText: '6100' }).getAttribute('value');
  const bank = await page.locator('#entry-form-line-1-account option', { hasText: '1010' }).getAttribute('value');
  await page.selectOption('#entry-form-line-0-account', sixOne);
  await page.fill('#entry-form-line-0-debit', '1,000.00');
  await page.selectOption('#entry-form-line-1-account', bank);
  await page.fill('#entry-form-line-1-credit', '900');
  await page.click('#entry-form-save-btn');
  await page.waitForSelector('#entry-lines');
  ok(page.url().includes('/journal/') && page.url().includes('notice=saved'), 'saving the draft lands on the entry (' + page.url() + ')');
  ok((await page.locator('#entry-balance-state').innerText()) === 'out by 100.00' && await page.locator('#entry-post-btn').isDisabled(), 'the strip says "out by 100.00" and Post is disabled');
  await widthOK(page, 'An entry (draft)', 375); await touch(page, 'An entry (draft)');
  await page.screenshot({ path: `${SHOTS}/phone-entry-draft.png` });
  const tableScrolls = await page.evaluate(() => { const t = document.querySelector('#entry-lines .table-responsive'); return t && t.scrollWidth > t.clientWidth; });
  ok(tableScrolls, 'the lines editor scrolls inside .table-responsive on a phone');
  // add a line through the editor (HTMX re-renders the region and the strip out of band)
  await page.selectOption('#entry-line-new-account', bank);
  await page.fill('#entry-line-new-credit', '100');
  await page.click('#entry-line-new-save-btn');
  await page.waitForFunction(() => document.querySelector('#entry-balance-state') && document.querySelector('#entry-balance-state').textContent === 'balances');
  ok(true, 'adding a line re-rendered the lines and the strip now says "balances"');
  ok(!(await page.locator('#entry-post-btn').isDisabled()), 'Post is enabled');
  ok((await page.locator('[id^="entry-line-"][id$="-save-btn"]').count()) === 4, 'three lines plus the add row in the editor');
  await page.screenshot({ path: `${SHOTS}/phone-entry-balanced.png` });
  // a refused line write shows in #flash and keeps the editor
  await page.selectOption('#entry-line-new-account', bank);
  await page.fill('#entry-line-new-debit', '5');
  await page.fill('#entry-line-new-credit', '5');
  await page.click('#entry-line-new-save-btn');
  await page.waitForSelector('#flash .alert');
  ok((await page.locator('#flash').innerText()).includes('A line is a debit or a credit.'), 'a refused line shows its words in #flash');
  // post
  page.once('dialog', (d) => d.accept());
  await page.click('#entry-post-btn');
  await page.waitForSelector('#entry-lines-readonly-note');
  ok(page.url().includes('notice=posted') && /JE-\d{6}/.test(await page.locator('.page-header-title h5').innerText()), 'posting lands on the numbered, read-only entry');
  await widthOK(page, 'An entry (posted)', 375);
  await page.screenshot({ path: `${SHOTS}/phone-entry-posted.png` });
  // reverse
  await page.fill('#entry-reverse-reason', 'SMOKE browser reversal');
  page.once('dialog', (d) => d.accept());
  await page.click('#entry-reverse-btn');
  await page.waitForSelector('#entry-reverses-link');
  ok(page.url().includes('notice=reversed') && (await page.locator('#entry-head-source').innerText()).startsWith('Reversal of'), 'reversing lands on the reversal, linked to the original');
  await page.screenshot({ path: `${SHOTS}/phone-entry-reversal.png` });
  // a note
  await page.fill('#entry-note-body', 'SMOKE browser note');
  await page.click('#entry-note-add-btn');
  await page.waitForSelector('[id^="note-"]');
  ok((await page.locator('#entry-notes-list').innerText()).includes('SMOKE browser note'), 'a note lands in the panel');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- desktop: the table, the timeline ----
{
  const { ctx, page, errors } = await session(28, DESK);
  console.log('1280 x 800 — the Controller');
  await go(page, '/journal/');
  await widthOK(page, 'Journal', 1280);
  ok(await page.locator('#entry-list-table').isVisible() && !(await page.locator('#entry-list-cards').isVisible()), 'a table on the desktop, the cards hidden');
  await page.screenshot({ path: `${SHOTS}/desktop-journal.png` });
  await page.click('[id^="entry-row-"] a >> nth=0');
  await page.waitForSelector('#entry-head');
  await widthOK(page, 'An entry', 1280);
  ok(await page.locator('#entry-timeline').isVisible() && (await page.locator('[id^="timeline-row-"]').count()) >= 1, 'the timeline panel with rows');
  await page.screenshot({ path: `${SHOTS}/desktop-entry.png` });
  await go(page, '/journal/?status=draft');
  ok(page.url().includes('status=draft'), 'the filter is in the URL');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- JavaScript off: the forms post and land ----
{
  const { ctx, page } = await session(27, PHONE, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await go(page, '/journal/new');
  await page.fill('#entry-form-field-date', '2026-10-02');
  await page.fill('#entry-form-field-memo', 'SMOKE no-JS entry');
  await page.click('#entry-form-save-btn');
  await page.waitForSelector('#entry-lines');
  ok(page.url().includes('notice=saved'), 'a plain form post lands on the entry');
  const bank = await page.locator('#entry-line-new-account option', { hasText: '1010' }).getAttribute('value');
  await page.selectOption('#entry-line-new-account', bank);
  await page.fill('#entry-line-new-debit', '12');
  await page.press('#entry-line-new-debit', 'Enter');          // implicit submission of the row's form (the inputs carry form="line-form-new")
  await page.waitForSelector('#entry-lines');
  ok(page.url().includes('notice=line_saved') && (await page.locator('[id^="entry-line-"][id$="-save-btn"]').count()) === 2, 'the add-line form posts and lands back on the entry with the line');
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
