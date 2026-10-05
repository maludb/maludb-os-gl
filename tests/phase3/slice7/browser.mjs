// Headless Chromium proof of slice 7 (docs/build-specs/ai-statement.md, "Proof"): the AI statement screens at 375 x 740 and 1280 x 800 with scrollWidth = viewport, every button >= 44 px on a phone, the flow through the real screens
// (the months the OS lists, Pull now, the month grouped four ways, Import as bills, the Controller approving and voiding, Check agreeing and differing with its sentence), no console errors.
// Run through tests/phase3/slice7/run.sh (servers up; the proofs before it left their data).
import { createRequire } from 'node:module';
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execSync } from 'node:child_process';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8391';
const SHOTS = process.env.SHOTS || '/tmp/gl-shots-p3s7';
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
const KS = process.env.FAKE_KERNEL_STATE;
const setKernel = (docs) => {
  const st = JSON.parse(fs.readFileSync(KS, 'utf8') || '{}');
  st.ledger_documents = docs;
  st.ledger_periods = { schema: 'os.ledger-periods/1', periods: Object.values(docs).map((d) => ({ period: d.period, period_start: d.period_start, period_end: d.period_end, status: d.status, closed_at: d.closed_at, statement_lines: d.lines.length, ledger_calls: d.lines.reduce((a, l) => a + l.calls, 0) })) };
  st.ledger_mode = 'ok';
  fs.writeFileSync(KS, JSON.stringify(st));
};
const line = (provider, model, dept, agent, calls, late, amount) => ({ provider, model, department: dept, agent, application: null, calls, late_calls: late, input_tokens: calls * 10, output_tokens: calls * 5, cache_read_tokens: 0, cache_write_tokens: 0, amount, currency: 'USD', note: late ? `${late} late calls` : null });
const doc = (period, status, lines) => { const cents = lines.reduce((a, l) => a + Math.round(parseFloat(l.amount) * 10000), 0); return { schema: 'os.ledger-period/1', business: 'SMOKE Co', period, period_start: `${period}-01`, period_end: `${period}-${new Date(parseInt(period.slice(0, 4), 10), parseInt(period.slice(5), 10), 0).getDate()}`, status, closed_at: status === 'closed' ? `${period}-28T10:00:00Z` : null, rolled_up_at: null, currencies: ['USD'], exchange: 'none — each line in the currency the provider billed; nothing is converted', amount_scale: 4, generated_at: '2026-10-02T08:00:00Z', lines, totals: [{ currency: 'USD', calls: lines.reduce((a, l) => a + l.calls, 0), late_calls: lines.reduce((a, l) => a + l.late_calls, 0), amount: `${Math.floor(cents / 10000)}.${String(cents % 10000).padStart(4, '0')}` }] }; };
setKernel({ '2026-01': doc('2026-01', 'closed', [line('anthropic', 'claude-sonnet-5-5', 'Accounting', 'Sasha', 120, 0, '5.0000'), line('anthropic', 'claude-sonnet-5-5', 'IT', 'Sysadmin', 40, 2, '1.2500'), line('openai', 'gpt-5', 'Front Office', 'Seamus', 30, 0, '2.0000')]), '2026-11': doc('2026-11', 'open', [line('anthropic', 'claude-sonnet-5-5', 'Accounting', 'Sasha', 10, 0, '0.5000')]) });
let jan;

// ---- phone: Bea pulls, reads, imports, checks ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — the Bookkeeper');
  await go(page, '/ap/ai/');
  await widthOK(page, 'AI statements', 375); await touch(page, 'AI statements');
  ok((await page.locator('[id$="-notpulled"]').count()) === 2 && (await page.locator('#ai-statements-content').innerText()).includes('has not pulled it yet'), 'the two months the OS lists show as muted cards');
  await page.screenshot({ path: `${SHOTS}/phone-ai-before.png` });
  await page.click('#ai-statements-pull-btn');
  await page.waitForFunction(() => !document.querySelector('[id$="-notpulled"]'), null, { timeout: 15000 });
  jan = sql("SELECT id FROM ai_statements WHERE period = '2026-01'");
  const dec = sql("SELECT id FROM ai_statements WHERE period = '2026-11'");
  ok((await page.locator('#ai-statement-card-' + jan + '-open-btn').count()) === 1 && (await page.locator('#ai-statement-card-' + dec + '-open-btn').count()) === 1, 'Pull now: both months become cards (the list refreshes by aiStatementChanged)');
  ok((await page.locator('#ai-statement-card-' + jan + '-kernel').innerText()).toLowerCase() === 'closed' && (await page.locator('#ai-statement-card-' + jan + '-status').innerText()).toLowerCase() === 'pulled' && (await page.locator('#ai-statement-card-' + jan + '-totals').innerText()).includes('8.25'), 'January\'s card: closed, pulled, 8.25 USD');
  await widthOK(page, 'AI statements (pulled)', 375); await touch(page, 'AI statements (pulled)');
  await page.screenshot({ path: `${SHOTS}/phone-ai-pulled.png` });
  await page.click('#ai-statement-card-' + jan + '-open-btn');
  await page.waitForSelector('#ai-lines');
  await widthOK(page, 'A month', 375); await touch(page, 'A month');
  ok((await page.locator('#ai-lines-total-USD').innerText()) === '8.2500 USD', 'the provider table\'s total equals the document\'s (8.2500 USD)');
  await page.click('#ai-lines-group-department');
  await page.waitForFunction(() => document.querySelector('#ai-lines .card-title').textContent.includes('department'));
  ok((await page.locator('#ai-lines-table tbody tr').count()) === 3 && (await page.locator('#ai-lines-total-USD').innerText()) === '8.2500 USD', 'grouped by department: three rows, the same total');
  await page.click('#ai-lines-group-model');
  await page.waitForFunction(() => document.querySelector('#ai-lines .card-title').textContent.includes('model'));
  ok((await page.locator('#ai-lines-total-USD').innerText()) === '8.2500 USD', 'by model: the same total');
  ok(await page.evaluate(() => { const t = document.querySelector('#ai-lines .table-responsive'); return !!t; }), 'the table scrolls inside .table-responsive');
  await page.screenshot({ path: `${SHOTS}/phone-ai-month.png` });
  page.once('dialog', (d) => d.accept());
  await page.click('#ai-statement-import-btn');
  await page.waitForSelector('#ai-bills-table');
  ok((await page.locator('#ai-bills-table tbody tr').count()) === 2 && (await page.locator('#ai-statement-head-status').innerText()).toLowerCase() === 'imported', 'Import makes the bills: a row per provider, the month reads imported');
  ok((await page.locator('#ai-statement-import-btn').count()) === 0 && (await page.locator('#ai-statement-check-btn').count()) === 1, 'Import is gone, Check is there');
  await page.screenshot({ path: `${SHOTS}/phone-ai-imported.png` });
  await page.click('#ai-statement-check-btn');
  await page.waitForFunction(() => document.querySelector('#ai-statement-head-status') && document.querySelector('#ai-statement-head-status').textContent.trim() === 'agrees');
  ok((await page.locator('#ai-bills-verdict').innerText()) === 'agrees', 'Check: the draft bills agree with the statement, to the cent');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- phone: Carl approves one bill and voids the other ----
let openBill, anthBill;
{
  const { ctx, page, errors } = await session(28, PHONE);
  console.log('375 x 740 — the Controller');
  anthBill = sql(`SELECT bill_id FROM ai_statement_bills WHERE statement_id = ${jan} AND provider = 'anthropic'`);
  openBill = sql(`SELECT bill_id FROM ai_statement_bills WHERE statement_id = ${jan} AND provider = 'openai'`);
  await go(page, '/bills/' + anthBill);
  ok((await page.locator('#bill-head-source').innerText()).includes('AI statement'), 'the AI bill says it is from the AI statement');
  await touch(page, 'An AI bill');
  page.once('dialog', (d) => d.accept());
  await page.click('#bill-approve-btn');
  await page.waitForSelector('#bill-head-entry-link');
  ok((await page.locator('#bill-head-status').innerText()) === 'Approved', 'the Controller approves Anthropic\'s bill (its entry posts DR 6050 by department)');
  await go(page, '/bills/' + openBill);
  page.once('dialog', (d) => d.accept());
  await page.click('#bill-approve-btn');
  await page.waitForSelector('#bill-head-entry-link');
  await page.fill('#bill-void-reason', 'SMOKE browser void');
  page.once('dialog', (d) => d.accept());
  await page.click('#bill-void-btn');
  await page.waitForFunction(() => document.querySelector('#bill-head-status') && document.querySelector('#bill-head-status').textContent === 'Void');
  ok(true, 'and approves, then voids, OpenAI\'s');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
// ---- phone: the check differs; desktop: the page ----
{
  const { ctx, page, errors } = await session(27, PHONE);
  console.log('375 x 740 — the Bookkeeper checks again');
  await go(page, '/ap/ai/' + jan);
  await page.click('#ai-statement-check-btn');
  await page.waitForFunction(() => document.querySelector('#ai-bills-verdict') && document.querySelector('#ai-bills-verdict').textContent.trim() === 'differs');
  ok((await page.locator('#ai-bills-sentences').innerText()).includes('is void: the check differs by its whole 2.00 USD') && (await page.locator('#ai-bills-sentences').innerText()).includes("reference 'openai 2026-01'"), 'it differs, in the sentence: "… is void: the check differs by its whole 2.00 USD. Enter a bill for OpenAI of 2.00 USD with reference \'openai 2026-01\' …"');
  ok((await page.locator('#ai-check-openai-copy-link').count()) === 1, 'with a link that opens the bill form from the voided bill');
  await widthOK(page, 'A month that differs', 375); await touch(page, 'A month that differs');
  await page.screenshot({ path: `${SHOTS}/phone-ai-differs.png` });
  await go(page, '/ap/ai/');
  ok((await page.locator('#ai-statement-card-' + jan + '-status').innerText()).toLowerCase() === 'differs', 'the month\'s card reads differs');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(26, DESK);
  console.log('1280 x 800 — a Viewer');
  await go(page, '/ap/ai/');
  await widthOK(page, 'AI statements', 1280);
  ok((await page.locator('#ai-statements-pull-btn').count()) === 0, 'a Viewer reads the cards without Pull now');
  await page.screenshot({ path: `${SHOTS}/desktop-ai.png` });
  await go(page, '/ap/ai/' + jan);
  await widthOK(page, 'A month', 1280);
  ok(await page.locator('#ai-bills-table').isVisible() && await page.locator('#ai-lines-table').isVisible() && (await page.locator('#ai-statement-import-btn').count()) === 0, 'the month: the check and the lines side by side; no Import for a Viewer');
  await page.screenshot({ path: `${SHOTS}/desktop-ai-month.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
