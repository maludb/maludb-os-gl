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
- [ ] The trial balance's totals equal and say so; after an unbalanced draft exists nothing changes (drafts are not in the books); the balance sheet's equation holds and the current earnings row equals
  `gl_net_income()` for the year to date; the P&L's net income equals the proof's hand figure; the cash flow's check row is zero and cash at end equals the bank accounts' ledger balances.
- [ ] `compare = previous` on a month shows the prior month's column and the variance; `last_year` shows zeros for a year with no books and says "no books for that period" rather than an empty table.
- [ ] A department filter on the P&L shows IT's hosting and its share of the AI bill only; a site filter the site's lines; the account rows link to the activity with the same range.
- [ ] Two currencies in the lines (fixture: one EUR invoice) render two tables and never one sum; the CSV holds both with a TOTAL row each.
- [ ] Posted-through on every statement and the waiting counts; the print page bare with the business's name; the CSV download logged `export.download` with what and the period.
- [ ] The agings match `gl_ar_aging()` / `gl_ap_aging()` row for row and link to the filtered document lists; the sales tax report equals the invoices' tax totals less credits; spend by vendor equals the
  approved bills' totals per vendor; the customer statement's running balance ends at the customer's balance due; the control check names the opening entry's 1,000.
- [ ] A Viewer reads every statement (200); an unknown statement name → 404; `php bin/build_action_registry.php --check`; the three screens read as built; 375 and 1280 with `scrollWidth` = viewport (the
  tables scroll in `.table-responsive`).

## Open questions
- Charts: tables and count cards only in v1 (Projects vendored SVG charts; the cash trend on the home is a later small SVG)? Proposed: tables only.
