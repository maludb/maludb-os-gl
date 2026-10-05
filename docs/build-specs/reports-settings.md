# Build spec: audit, exports, the home and the settings' last pages — slice 9

What exists at the end: the audit page (GL12 and AI2 and the control tie-out on one page), the exports page (every ledger as a file for the
accountant's software), the home filled with everything the slices made, the agents page live, and the last settings pages wired. No new table.
Schema: the views and the db/015 functions; `mcp_activity_log` through PHP (the writer reads it as the acting member; the activity role's view
is the same rule). Never modify them.

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `audit` | `/audit` | `?from=&to=` (default this fiscal year): **Reopened periods** (each with who, why, when, and the entries posted into it after the reopen — `journal_entry.post` rows with `after.reopened_period`), **Reversals and voids** (entries reversed with reasons; drafts voided), **The AI statement** (each month's verdict, the differences by provider), **The control tie-out** (`gl_control_check()` as of `to`, with the lines of the receivable and payable accounts that have no document behind them — manual lines on a control account listed by entry), **Posted into a closed period**: none by construction (the trigger) — the page says so and shows the count of refusals logged (`journal_entry.post` attempts answered 422 are not logged as posts; the page states the rule) |
| `exports` | `/exports` | `?period=` (a fiscal period or year) and `format` (csv): the general ledger detail (every posted line with entry, date, account, debit, credit, party, dimensions), the journal (entries), the chart with balances as of the period's end, the trial balance, the P&L, the balance sheet, AR aging and AP aging as of the end, the invoices, the bills, the receipts, the payments — one file each, a ZIP of all (`format=zip`); every download logged `export.download` |
| `dashboard` | `/` | Phase 2's regions all filled: cash (6), who owes us / whom we owe (4, 5), what waits (2, 4, 5, 6), the AI bill (7), posted through (2), the morning note (8), plus **This month** (net income to date from `gl_net_income()`, revenue, spend) |
| `agents` | `/settings/agents` | Slice 1's page live: the two shipped agents with roles, last dispatch and the last morning note's time |

## Files (exactly these)
- `html/audit.php` · `html/exports.php` · `html/index.php` (filled) · `html/settings/agents.php` (filled)
- `app/features/audit/queries.php` · `present.php` — `app/features/exports/queries.php` · `csv.php` · `zip.php` (PHP's `ZipArchive`) — `app/features/home/queries.php` (filled)
- `app/views/audit.php` · `partials/reopened.php` · `reversals.php` · `ai-verdicts.php` · `tie-out.php` — `app/views/exports.php` — `app/views/home/dashboard.php` (filled)

## Query functions (signatures fixed)
- `audit_reopened_periods(PDO, string $from, string $to): array` · `audit_posts_after_reopen(PDO, int $periodId): array` · `audit_reversals(PDO, string $from, string $to): array` · `audit_voids(…)` · `audit_ai_verdicts(PDO, string $from, string $to): array` · `audit_control_lines(PDO, string $asOf): array` (manual lines on control accounts, by entry)
- `export_ledger_detail(PDO, string $from, string $to): array` · `export_journal(…)` · `export_chart(PDO, string $asOf): array` · `export_documents(PDO, string $kind, string $from, string $to): array` · `export_zip(PDO, array $range): string` (a temp file path)
- `home_summary(PDO): array` (filled: every region)

## Handlers
None new: every screen is a GET; the exports' downloads log `export.download` (`after.what`, `after.period`, `after.format`); `exports.download` is required for the page (the Controller, the Accountant, the admin); `audit` needs `periods.manage|journal.override`.

## Action manifest entries
- Screens: `audit`, `exports`, `dashboard` (filled), `agents` (filled). No actions.

## Activity log events
`export.download`, `screen.view`.

## Proof (`tests/phase3/slice9/run.sh`)
- [x] The audit page lists February's reopen with the reason and the late entry posted after it; the reversal with its reason; the voided draft; September's AI verdict `differs` with the OpenAI difference; the tie-out naming the opening entry's 1,000 on 1200 with the entry number; a Bookkeeper → 403; the Accountant → 200.
- [x] Exports: each file's row count equals the view's; the ledger detail sums to the trial balance; the ZIP holds twelve files; each logged; a Viewer → 403 (`exports.download`).
- [x] The home's every region filled with the world's figures; This month's net income equals `gl_net_income()`; the agents page shows the two agents and the last morning note's time.
- [x] 375 and 1280; the registry reads the four screens as built; every earlier suite still green; the installer's `plan` clean.

## Built and proven 2026-10-05 by the worker: 101 checks green (audit 23, exports 26, home 26, browser 26)
The registry reads **92 screens (92 built), 124 actions (124 built)**: every screen of the manifest is built. No schema change. Phase 2's last placeholder hop now goes to `/audit` and its gate proof no longer needs a placeholder to exist.

## Decisions taken while building (2026-10-05)
- **The audit's parts** (`app/features/audit/queries.php`): a reopened period is read from `mcp_fiscal_periods` (`reopen_count > 0`, overlapping the range) with each reopening from the activity log (`period.reopen`: who, when, `after.reason`) — the view keeps only the latest reason — and the entries posted into it after, from `journal_entry.post` rows with `after.reopened_period`. Reversals are the originals with status `reversed` in the range (reason, reversing entry, who posted it); voids are drafts voided in the range, their amount summed from the lines (a voided draft's totals are zero). Each AI month in the range shows its stored verdict, and a differing one the sentence per provider from `ai_difference_sentence()`. The control tie-out is `gl_control_check()` as of the range's end with every figure written to the cent; the lines "with no document behind them" are posted lines on the receivable and payable accounts of source manual, opening, import, recurring or closing.
- **Posted into a closed period** is a count of entries whose `posted_at` is after their closed period's `closed_at` — the invariant the trigger keeps, shown as 0 — with the rule in words: a refused post is not a post and is not logged as one.
- **The default range** is the open fiscal year containing today, else this calendar year. The page needs `periods.manage` or `journal.override` (a person lacking both gets the first right's sentence); the activity log is read through `mcp_activity_log`, whole for a holder of `reports.view`.
- **The exports** (`app/features/exports/`): twelve files from the same views and functions the screens read — ledger detail, journal, chart with balances, trial balance, profit and loss, balance sheet, receivables and payables aging, invoices, bills, receipts, payments. A flat file is a header and its rows; a statement is its page's table through `statement_csv()`. Dated files take the period's `from`/`to` (a fiscal period or year, or custom dates); balances and aging take its end. The documents' files hold every row in the range with its status (drafts included, marked). Files are named `<file>-<period>.csv`, the ZIP `ledger-<period>.zip`; each download is logged `export.download` with `what`, `period`, `format`, the range and the row count (a ZIP: `files`) — never the data.
- **The trial balance is not the ledger detail's sum**: a trial balance lists each account's net on one side, so the proof compares each account's net in the detail with the trial balance's row, and checks the detail's debits equal its credits.
- **The home** gained revenue and spend beside net income under This month (revenue and spend from the profit and loss, net income still `gl_net_income()`). The agents page shows each agent's last morning note's time from the activity log (`morning_note.send` by that agent).
- **Not built**: the agents page does not show a morning note's text (the home shows it to its recipient only; the log never holds the body).

## Open questions
- (none)
