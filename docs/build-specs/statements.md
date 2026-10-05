# Build spec: the statements — slice 3

What exists at the end: the four statements and the reports, each for a period, compared to the one before, filtered by department or
site, per currency, as a table, a CSV and a print page — every one from the SQL functions of db/015, so a screen, a tool and a file never
disagree; and every statement says through what date the books are posted and what waits.
Schema: `gl_trial_balance()`, `gl_profit_and_loss()`, `gl_net_income()`, `gl_balance_sheet()`, `gl_balance_sheet_check()`, `gl_cash_flow()`,
`gl_account_activity()`, `gl_ar_aging()`, `gl_ap_aging()`, `gl_control_check()`, `gl_posted_through()`, `gl_unposted_summary()` (db/015);
`mcp_fiscal_periods`, `mcp_accounts`, `mcp_invoice_lines`, `mcp_credit_note_lines`, `mcp_bill_lines`, `mcp_settings` (db/016). Never modify them.

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `statements` | `/statements/` | Four cards: P&L (this period's net income), Balance sheet (assets, the equation's check), Trial balance (balanced or not), Cash flow (net change in cash); posted-through and what waits above them |
| `statement` | `/statements/{statement}` | `pl` · `balance-sheet` · `trial-balance` · `cash-flow` (`from`, `to`, `as_of`, `compare`, `department`, `site`, `format`) |
| `reports` | `/reports/{report}` | `ar-aging` · `ap-aging` · `sales-tax` · `spend` · `revenue` · `customer-statement` · `control-check` (`as_of`, `from`, `to`, `group_by`, `customer`, `vendor`, `format`) |

## The statements
- **Period pickers** (`period-picker` partial, shared): a select of fiscal periods and years (`mcp_fiscal_periods`) plus "custom" with `from`/`to`
  (or `as_of` for the balance sheet and the trial balance); **Compare to** (none · the previous period · the same period last year); **Department**
  and **Site** selects (P&L and account activity only); **Format**: the table (default), CSV (`format=csv`, a download logged `export.download`),
  Print (`format=print`: the bare layout, the business's name and address from the settings, the period, the statement, "posted through …").
- **Every statement carries the posted-through line** at the top — *"Posted through 30 Sep 2026 · 3 drafts and 1 bill waiting"* from
  `gl_posted_through()` and `gl_unposted_summary()` — and renders **per currency**: one table per currency found in the lines, never a sum across.
- **P&L** (`pl`): Income by account (subtotal), Expenses by account (grouped by subtype: cost of goods sold, operating, other; subtotals), **Net income**;
  with `compare`: a prior column and a variance column (amount and %); department or site filters; account rows link to `/accounts/{id}?from=&to=`.
- **Balance sheet** (`balance-sheet`): Assets (by subtype), Liabilities, Equity with **Current earnings (not yet closed)**; totals; the equation line
  *"Assets 25,000.00 = Liabilities 4,200.00 + Equity 20,800.00 ✓"* in `success`, or in `danger` with the difference when it does not hold (it cannot —
  the trigger — but the screen says so loudly rather than hiding it); `compare` adds the prior as-of column.
- **Trial balance** (`trial-balance`): every account with a balance, debit and credit columns, the totals row **equal** (`success`) or not (`danger`,
  loudly); account rows link to the activity.
- **Cash flow** (`cash-flow`): the rows of `gl_cash_flow()` in three sections with subtotals, net change in cash, cash at start and end, and the
  check row — `success` at zero.

## The reports
- `ar-aging` / `ap-aging`: per party and currency, the five buckets, the total, open document count; each party links to its page; each bucket's
  amount links to the documents list filtered (`/invoices/?customer=&overdue=…`); a totals row per currency; `as_of`.
- `sales-tax`: for a period, by tax rate: taxable sales, tax charged, from issued invoice lines less issued credit note lines (`mcp_invoice_lines`,
  `mcp_credit_note_lines` joined to their documents' dates and statuses).
- `spend`: for a period, by account (default), vendor, department or site: from approved bills' lines (`mcp_bill_lines` + `mcp_bills`) plus bank-fee
  entries (`gl_profit_and_loss()` for the expense accounts when `group_by = account`); each row links to the bills list filtered.
- `revenue`: for a period, by account, customer, department or site: from issued invoice lines less credit notes; links to the invoices list.
- `customer-statement`: `?customer=` (the picker), `from`/`to`: opening balance (what was owed at `from`), then each invoice, receipt and credit in date
  order with a running balance, the closing balance; a Send button (slice 4's `statement_send`); print and CSV. (Slice 4's `customer-statement`
  screen at `/customers/{id}/statement` is this report with the customer fixed.)
- `control-check`: `gl_control_check()` — receivable and payable, subledger vs ledger, the difference; `success` at zero, `danger` otherwise with a
  line *"The ledger holds 1,000.00 of receivables with no invoice behind it — an opening balance entry; the audit page lists the lines."*

## Files (exactly these)
- `html/statements/index.php` · `statement.php` (the four, by `?statement=`) — `html/reports/report.php` (the seven, by `?report=`)
- `app/features/statements/queries.php` (one function per statement over the db/015 functions) · `present.php` (rows → tables per currency, the compare columns, the CSV rows) · `periods.php` (`period_range()`: a name or from/to → dates; `previous_range()`, `last_year_range()`)
- `app/views/statements/index.php` · `statement.php` · `print.php` (the bare layout) · `partials/period-picker.php` · `statement-table.php` · `posted-through.php`
- `app/views/reports/report.php` · `partials/aging-table.php` · `grouped-table.php` · `customer-statement.php`

## Query functions (signatures fixed)
- `period_range(PDO, array $params): array` (`from`, `to`, `as_of`, `label`; defaults: the current fiscal period, or this month when no fiscal year) · `previous_range(array $range): array` · `last_year_range(array $range): array`
- `trial_balance(PDO, string $asOf): array` (per currency: rows, totals, `balanced`) · `profit_and_loss(PDO, string $from, string $to, ?int $dept, ?int $site, ?array $compare): array` · `balance_sheet(PDO, string $asOf, ?string $compareAsOf): array` (sections, totals, the check) · `cash_flow(PDO, string $from, string $to): array`
- `ar_aging(PDO, string $asOf, ?int $customerId): array` · `ap_aging(PDO, string $asOf, ?int $vendorId): array` · `sales_tax(PDO, string $from, string $to): array` · `spend(PDO, string $from, string $to, string $groupBy): array` · `revenue(PDO, string $from, string $to, string $groupBy): array` ·
  `customer_statement(PDO, int $customerId, string $from, string $to): array` · `control_check(PDO, string $asOf): array` · `posted_through(PDO): array` (date + the summary)
- `statement_csv(array $tables): string` (one header pair per currency table, the rows, a TOTAL row)

## Handlers
None: every screen is a GET. `format=csv` and `format=print` are logged `export.download` (`after.what`, `after.period`, `after.format`); the table view logs `screen.view` with the statement's name.
A statement needs `reports.view` (every role); the customer statement and the aging need it too; `exports.download` is NOT required for a single statement's CSV (the Exports page, slice 9, is the bulk download).

## Action manifest entries
- Screens: `statements`, `statement`, `reports`. No actions.

## Activity log events
`screen.view` (statements, statement with `after.statement`, reports with `after.report`), `export.download`.

## Out of scope for this slice
The Exports page (9); the audit page (9); the home's cards (Phase 2 shows the counts; this slice makes the statements they link to); sending a statement (4).

## Proof (`tests/phase3/slice3/run.sh`, scratch database with the Phase 0 proof's world rebuilt by `tests/phase3/lib.php` `world()` — the opening entry, the invoices, the bills, the receipts, the AI bills, the bank — `php -S` + headless Chromium)
**Built and proven 2026-10-05 by the worker: 127 checks green** (statements 17, compare 12, filters 8, formats 11, reports 23, rights 6, currency 6, browser 44, the registry); slices 1 (219), 2 (161), Phase 2 (251, two checks updated now that the statements exist) and Phase 0 still green;
the registry reads `statements`, `statement` and `reports` as built (`92 screens (33 built), 124 actions (31 built)`). **Two things are NOT done and are written under "Open questions": the per-currency split of the four statements, and the AI-bill share in the department filter (slice 8's data).**
- [x] The trial balance's totals equal and say so; after an unbalanced draft exists nothing changes (drafts are not in the books); the balance sheet's equation holds and the current earnings row equals
  `gl_net_income()` for the year to date; the P&L's net income equals the proof's hand figure; the cash flow's check row is zero and cash at end equals the bank accounts' ledger balances.
- [x] `compare = previous` on a month shows the prior month's column and the variance; `last_year` shows zeros for a year with no books and says "no books for that period" rather than an empty table.
- [x] A department filter on the P&L shows IT's hosting and its share of the AI bill only; a site filter the site's lines; the account rows link to the activity with the same range.
- [ ] Two currencies in the lines (fixture: one EUR invoice) render two tables and never one sum; the CSV holds both with a TOTAL row each.
- [x] Posted-through on every statement and the waiting counts; the print page bare with the business's name; the CSV download logged `export.download` with what and the period.
- [x] The agings match `gl_ar_aging()` / `gl_ap_aging()` row for row and link to the filtered document lists; the sales tax report equals the invoices' tax totals less credits; spend by vendor equals the
  approved bills' totals per vendor; the customer statement's running balance ends at the customer's balance due; the control check names the opening entry's 1,000.
- [x] A Viewer reads every statement (200); an unknown statement name → 404; `php bin/build_action_registry.php --check`; the three screens read as built; 375 and 1280 with `scrollWidth` = viewport (the
  tables scroll in `.table-responsive`).

## Decisions taken while building (2026-10-05)
- **No bcmath on the host**: exact money arithmetic is `app/money_math.php` (`m_add`, `m_sub`, `m_neg`, `m_cmp`, `m_pct`, in whole cents), loaded by the bootstrap; never use floats or `bc*` for money.
- **One generic table shape** (`app/features/statements/present.php`: `t_row()`, a table = title, currency, columns, `text_cols`, rows of kind head/row/subtotal/total/check, an optional status) feeds the screen (`statement-table.php`), the print page and the CSV, so they cannot disagree. The CSV holds a header pair (title; `Currency,USD`) per table, section rows in capitals, and the `total` row as `TOTAL <label>`.
- **Ageing links**: a party opens its page; a bucket's amount opens the document list filtered with `?customer=ID|vendor=ID&as_of=DATE&overdue=current|1_30|31_60|61_90|over_90` (`any` for the total). **Slice 4's invoices list and slice 5's bills list must honour `overdue` and `as_of`.** Spend and revenue rows link `/bills/` or `/invoices/` with `?<group_by>=ID&from=&to=` (`vendor`, `customer`, `account`, `department`, `site`): the lists should accept those too.
- **The default period** with no parameters is the fiscal period covering today, else this calendar month; `period` accepts a fiscal period name (`2026-06`) or a year (`FY2026`) or `custom`; the aging and control check default to today when no date is given. `compare=previous` of a calendar month is the previous month, of anything else the same number of days before; `last_year` is the same dates a year earlier. The balance sheet compares as-of dates.
- **Posted-through**: `Posted through 30 Sep 2026 · 3 drafts, 1 draft bill waiting` from `gl_posted_through()` and `gl_unposted_summary()` — the date is the last POSTED entry, drafts only count as waiting.
- **No books**: a P&L range with no posted entry, a balance sheet with no balances and a trial balance with no rows say "No books for that period." and render no table.
- **`log_screen_view()` takes an `$opts` array** (`after`, audit keys), so a statement logs `after.statement` and a report `after.report`; the journal entry page's `after.record_type` (asked for in slice 2 and dropped silently) now lands too.
- **The control check's sentence**: "The ledger holds X of receivables and the documents hold Y — an opening balance entry or a manual line sits behind the difference; the audit page lists the lines." when the subledger is below the ledger, "documents are open that the ledger does not hold" when above.
- **The sales tax report** groups invoice lines (issued, not draft or void) less issued credit note lines by tax rate; a line with no rate is "No tax"; spend is approved/partially paid/paid bills' line subtotals (net of tax: the ledger posts bills gross, the report shows the bill's lines).
- **Proof helper for slices 4–9**: `tests/phase3/lib.php` `world()` rebuilds the Phase 0 proof's books by SQL through the writer functions (opening entry, two invoices, a receipt banked, a credit note, three bills) — hand figures in its header; `direct_net()` sums the lines straight from the tables.

## Open questions
**BLOCKED — for the owner (2026-10-05, found while building):** the four statements must "render per currency … one table per currency found in the lines, never a sum across", and the proof asks for a EUR invoice to produce two tables. But the db/015 functions the spec says never to modify (`gl_trial_balance`, `gl_profit_and_loss`, `gl_net_income`, `gl_balance_sheet`, `gl_balance_sheet_check`, `gl_cash_flow`, `gl_account_balance`) sum `gl_book_lines` with no currency column, so a EUR line is added to the USD figures. What was built instead: every statement is ONE table in the base currency, and when a posted entry in another currency exists the page says so in a warning ("These figures add every posted entry as it stands — nothing is converted, and the currencies are not yet shown apart"). The reports that read documents (the agings, whose functions DO return a currency) do render one table per currency and the CSV carries both with a TOTAL each — proven by `currency.php`. Decide one of: (a) a new migration (db/017) adding `p_currency` variants of the statement functions, after which the statements loop over `books_currencies()`; (b) accept the base-currency-only statements with the warning and drop the "two tables" check from the spec; (c) forbid a non-base-currency document in v1. Nothing was guessed: the structure (`present.php` builds one table per currency from a list) is ready for (a).
- **Department filter and the AI bill**: the spec's proof says IT's filter shows "its hosting and its share of the AI bill"; the AI statement import is slice 8, so only the hosting bill is in the fixture — the filter itself works on any line's department and needs no change when slice 8 posts the AI bills.

**Decided 2026-10-05:** tables and count cards only in v1; no charts.

*(As proposed:)*
- Charts: tables and count cards only in v1 (Projects vendored SVG charts; the cash trend on the home is a later small SVG)? Proposed: tables only.
