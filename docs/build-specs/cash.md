# Build spec: cash — slice 6

What exists at the end: bank accounts over the chart's bank accounts; the bank's lines imported from a CSV (the file never stored) or typed;
every line matched to the cent to one ledger item or turned into an entry (a fee, interest) or ignored with a reason; transfers; a
reconciliation against a statement balance that completes only at zero difference and stamps its lines; the cash cards on the home.
Replicates the exemplar for the record pages; the match screen is this slice's own shape.
Schema: `bank_accounts`, `bank_imports`, `bank_transactions`, `transfers`, `bank_reconciliations` and `gl_bank_ledger_balance()`,
`gl_bank_cleared_balance()`, `gl_bank_item_amount()`, `gl_bank_candidates()`, `gl_bank_match()`, `gl_bank_unmatch()`, `gl_bank_ignore()`,
`gl_bank_entry_from_line()`, `gl_reconciliation_*()`, `gl_transfer_record()`, `gl_transfer_void()` (db/013); `gl_cash_position()` (db/015); the
views `mcp_bank_accounts`, `mcp_bank_imports`, `mcp_bank_transactions`, `mcp_transfers`, `mcp_bank_reconciliations`, `mcp_receipts`, `mcp_deposits`,
`mcp_payments`, `mcp_journal_entries` (db/016). Never modify them.

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `banks` | `/cash/` | Cards per bank account (`bank-card-{id}`): name, kind chip, institution · last four, **ledger balance**, **cleared balance**, the difference, unmatched lines count (a link to match), last reconciled through and balance, a reconciliation in progress chip; **Import**, **Match**, **Reconcile** buttons |
| `bank-account-view` | `/cash/banks/{id}` | The card's facts; **Lines** by status tabs (unmatched · matched · ignored · all) and date; **Reconciliations** (the history); **Ledger activity** (the account's lines, slice 1's table); **Transfers** |
| `bank-import` | `/cash/import` | `?bank_account=`: a file field (CSV), **date format** (ymd/dmy/mdy), **columns** (date, amount — or debit and credit —, description, reference: selects filled from the header row after a first upload), a **five-row preview**, then **Import**; the result: rows, inserted, skipped (duplicates by reference) |
| `bank-match` | `/cash/match` | `?bank_account=` (default the first with unmatched lines): the unmatched lines oldest first, each a card (`bank-line-{id}`) with date, amount (signed, coloured), description, reference, and its **candidates** (`gl_bank_candidates()`) as rows with a **Match** button; **Make an entry** (an account select + memo inline → `bank_entry_from_line`); **Ignore** (a reason); a line with no candidate says what to do ("record the receipt / payment first") |
| `bank-reconcile` | `/cash/reconcile` | `?bank_account=`: no reconciliation in progress → the start form (statement date, statement balance); one in progress → its figures live (opening, cleared, difference, ledger), the lines through the date (matched ✓, ignored, **unmatched in red** with a link to match), **Complete** (disabled unless zero; the endpoint refuses anyway), **Abandon** (deletes the in-progress row — not an action; `reconciliation.abandon`) |
| `reconciliation-view` | `/cash/reconciliations/{id}` | A completed or reopened one: figures, who, when, its lines; **Reopen** (`bank.reconcile`, a reason) |
| `transfers-list` / `transfer-add` / `transfer-view` | `/cash/transfers/…` | Transfers; record (from, to, amount, date, memo); a transfer's entry and the two bank lines it matched; void |
| `bank-settings` / `bank-account-add` / `bank-account-edit` | `/settings/banks/…` | Name, kind, the ledger account (`account_select()` narrowed to `is_bank`), institution, last four, currency, opening balance and date |

## Importing
- `bank_import` (`import.php`): `bank.write`; the file read from `$_FILES` in memory, never written to disk; parsed by `parse_bank_csv()` (header detection,
  the column map, the date format, `amount_sign` signed or debit/credit columns, thousands separators and parentheses); a preview (`?preview=1`, the first
  five parsed rows and the detected columns) before the commit; then one transaction: a `bank_imports` row, each line inserted with `external_ref` = the
  reference column (or a hash of date+amount+description when the bank gives none — stated on the screen), duplicates skipped on `23505` and counted; log
  `bank.import` (`after.import_id`, `after.rows`, `after.inserted`, `after.skipped`, `bank_account_id`). `bank_import_delete` removes the import's lines that
  are still unmatched (matched ones stay; the import row dated `deleted_at`); **pauses** (`deletion`).
- `bank_line_add` (`line.php`): a line typed by hand (a bank with no export): date, amount signed, description, reference; `entered_by`.

## Matching
- `bank_transaction_match` (`match.php`): `bank.write`; `gl_bank_match($line, $type, $id, me)`; the RAISEs → 422 (*"The receipt is 1000.00 but the bank line is
  1608.24: a match is to the cent"*, *"No recorded deposit 12 on this bank account"*, *"Bank line 7 is matched"*); log `bank.match` (`after.bank_transaction_id`,
  `after.item_type`, `after.item_id`, `after.amount`, `bank_account_id`); the card is removed from the match list (`#bank-line-{id}` swapped out) and the
  account's figures re-rendered (`#bank-figures` oob).
- `bank_transaction_unmatch` (`unmatch.php`): the RAISE on a reconciled line → 422; log `bank.unmatch`. `bank_transaction_ignore` (`ignore.php`): a reason; log
  `bank.ignore`. `bank_entry_from_line` (`entry.php`): `journal.write`; an account (not a control: the RAISE), memo, dimensions; `gl_bank_entry_from_line()`
  posts and matches; log `bank.entry_from_line` (`after.journal_entry_number`, `after.account_code`, `after.amount`, `journal_entry_id`); **pauses** (`money_out`).
- The match page's **auto-match** button (`?auto=1` → `bank_transaction_match` for every line with exactly one candidate; a person's click, one POST per
  line through HTMX, each logged) — the Bookkeeper agent does the same through the tool, line by line.

## Reconciling
- `reconciliation_start` (`reconcile-start.php`): `bank.write`; `gl_reconciliation_start()` (the RAISEs: one in progress, a date not after the last); log
  `reconciliation.start`. The page recomputes through `gl_reconciliation_recompute()` on every load.
- `reconciliation_complete` (`reconcile-complete.php`): `bank.reconcile`; `gl_reconciliation_complete()` — the RAISE *"The reconciliation is out by −4000.00: the
  statement says 7336.44 and the matched lines say 11336.44. Match or ignore every line through 2026-06-30 first"* → 422 in those words; on success the lines
  stamped, the account's last reconciled updated; log `reconciliation.complete` (`after.statement_date`, `after.statement_balance`, `after.cleared_balance`,
  `after.lines`, `bank_account_id`). **Pauses** (`other`; the default policy refuses agents).
- `reconciliation_reopen` (`reconcile-reopen.php`): `bank.reconcile`; `gl_reconciliation_reopen()` (the latest only; a reason); log `reconciliation.reopen`. **Pauses** (`other`).
- Abandon (`reconcile-abandon.php`, not an action): deletes the in-progress row; log `reconciliation.abandon`.

## Transfers
- `transfer_record` (`transfers/save.php`): `bank.write`; `gl_transfer_record()` (DR the receiving / CR the sending; the currencies must agree); log `transfer.record`
  (`after.number`, `after.amount`, both account ids); **pauses** (`money_out`). `transfer_void`: `override`; refused while matched; the entry reversed; **pauses**.
  A transfer appears as a candidate on BOTH accounts' lines (signed each way).

## Bank accounts' settings
- `bank_account_save` (`settings/banks/save.php`): `settings.manage`; the guard trigger's RAISEs (*"Account 6200 is not a bank account in the chart"*, a card over
  an asset) → 422; `opening_balance` and `opening_date` editable until a reconciliation exists (then 422 *"… is reconciled through …; the opening balance is fixed"*);
  the page explains the opening entry: *"Enter the opening balance in the books too — a manual entry on 1010 dated the opening day (DR 1010 / CR 3000 Owner equity)"*
  with a link to `/journal/new?date=&template=opening` (slice 2's form prefilled with the two lines). `bank_account_archive`: no unmatched line, no reconciliation in progress.

## The home's cash cards (Phase 2's region filled)
`gl_cash_position()`: one small card per bank account (ledger, cleared, last reconciled), a total per currency; **12-month trend** as a plain table of
month-end ledger balances (a chart is an open question of slice 3).

## Files (exactly these)
- `html/cash/index.php` · `import.php` (GET the form and preview, POST the import) · `import-delete.php` · `line.php` · `match.php` (GET the page, POST the match) · `unmatch.php` · `ignore.php` · `entry.php` · `reconcile.php` (GET) · `reconcile-start.php` · `reconcile-complete.php` · `reconcile-reopen.php` · `reconcile-abandon.php`
- `html/cash/banks/view.php` — `html/cash/reconciliations/view.php` — `html/cash/transfers/index.php` · `form.php` · `view.php` · `save.php` · `void.php`
- `html/settings/banks/index.php` · `form.php` · `save.php` · `archive.php`
- `app/features/cash/queries.php` · `present.php` · `write.php` · `csv.php` (`parse_bank_csv()`, pure) · `handler.php`
- `app/views/cash/index.php` · `bank.php` · `import.php` · `match.php` · `reconcile.php` · `reconciliation.php` · `transfers.php` · `transfer-form.php` · `transfer.php` · `partials/bank-card.php` · `bank-figures.php` · `line-row.php` · `line-card.php` · `candidates.php` · `import-preview.php` · `recon-lines.php`
- `app/views/settings/banks.php` · `bank-form.php`

## Query functions (signatures fixed)
- `find_bank_accounts(PDO, bool $includeArchived = false): array` (mcp_bank_accounts) · `find_bank_account(PDO, int $id): ?array` · `save_bank_account(…)` · `archive_bank_account(…)`
- `parse_bank_csv(string $contents, array $map, string $dateFormat, string $amountSign): array` (rows: txn_date, amount, description, reference, balance_after; errors[]) · `detect_columns(array $header): array` · `import_bank_lines(PDO, int $bankAccountId, array $rows, ?string $filename, int $by): array` (import_id, rows, inserted, skipped) · `delete_import(…)` · `add_bank_line(…)`
- `find_bank_lines(PDO, int $bankAccountId, array $filters, int $limit = 100, int $offset = 0): array` · `line_candidates(PDO, int $lineId): array` (`gl_bank_candidates()`) · `match_line(PDO, int $lineId, string $type, int $itemId, int $by): array` · `unmatch_line(…)` · `ignore_line(…)` · `entry_from_line(PDO, int $lineId, int $accountId, ?string $memo, ?int $dept, ?int $site, int $by): array`
- `reconciliation_in_progress(PDO, int $bankAccountId): ?array` · `start_reconciliation(…)` · `recompute_reconciliation(…)` · `reconciliation_lines(PDO, int $id): array` (through the statement date, by status) · `complete_reconciliation(…)` · `reopen_reconciliation(…)` · `abandon_reconciliation(…)` · `find_reconciliations(PDO, int $bankAccountId): array` · `find_reconciliation(PDO, int $id): ?array`
- `record_transfer(…)` · `void_transfer(…)` · `find_transfers(…)` · `find_transfer(…)`
- `cash_position(PDO): array` · `cash_trend(PDO, int $months = 12): array`
- `cash_refused(Throwable $e): never`

## Handlers (every one: `gl_handler_begin()`; the gate; `require_visible()`; one transaction; `log_activity` with `bank_account_id` (+ `journal_entry_id`); `emit_action_status`; `HX-Trigger: bankChanged`)
As listed above. `import.php`'s preview is a GET with the file re-posted (the file travels twice: preview, then import) — or, simpler and chosen: the POST with `preview=1` parses and renders without writing; the same POST without it imports.

## Action manifest entries
- Screens: the twelve of the section. Actions (14): `bank_account_save`, `bank_account_archive`, `bank_import`, `bank_import_delete`, `bank_line_add`, `bank_transaction_match`, `bank_transaction_unmatch`, `bank_transaction_ignore`, `bank_entry_from_line`, `reconciliation_start`, `reconciliation_complete`, `reconciliation_reopen`, `transfer_record`, `transfer_void`. Agent approvals as the manifest's rows.

## Activity log events (every row: `bank_account_id`)
`bank_account.save|archive`, `bank.import|import_delete|line_add|match|unmatch|ignore|entry_from_line`, `reconciliation.start|complete|reopen|abandon`, `transfer.record|void`, `screen.view`.

## Out of scope for this slice
OFX/QFX (Extended); bank feeds (Later); the Bookkeeper's auto-matching (Phase 4 — the tool is this slice's function); the worker's "reconciliation out" notice (8).

## Proof (`tests/phase3/slice6/run.sh`: the world with the receipts, deposits and payments of slices 4–5 recorded by SQL)
- [ ] **Import.** A CSV with a header, dmy dates, parentheses for debits → five rows previewed with the detected columns, then inserted; the same file again → 0 inserted, 5 skipped; a file with no reference column → references hashed and the screen says so; a bad date → the row's error named and nothing imported; `bank.import` logged with the counts; delete the import → unmatched lines gone, a matched one kept.
- [ ] **Match.** The deposit line's one candidate is the deposit; match → `bank.match` with the amount, the card gone, the cleared balance up; a payment line matched to its payment; a fee line → an entry DR 6500 / CR 1010 posted and matched (`journal_entry_id` on the row); a wrong amount → 422 in the function's words; a receipt to undeposited funds not offered; unmatch; ignore with a reason; a reconciled line's unmatch → 422.
- [ ] **Reconcile.** May started (opening 10,000, cleared, difference 0) and completed: the three lines stamped, the account's last reconciled; June started from May's balance, out by the cheques, complete → 422 in the function's sentence with the figures; the cheques matched, complete; reopen the latest with a reason → the lines freed, the account back to May; reopen May → 422 *"latest first"*; abandon an in-progress one; the ledger balance equals the cleared balance when every line is matched.
- [ ] **Transfers.** Checking → card 500: both bank accounts' balances move, a candidate on both accounts' lines signed each way; void refused while matched.
- [ ] **Settings.** A bank account over 6200 → 422; a card over an asset → 422; the opening balance fixed once reconciled; the opening entry link prefills slice 2's form.
- [ ] The home's cash cards and the trend table; a Viewer sees the cards and 403 on match; JSON mode; 375 and 1280 (the match cards stack); the registry reads 14 actions and 12 screens.

## Open questions
**Decided 2026-10-05:** hash date + amount + description + a per-file index when the bank gives no reference; the import result says so.

*(As proposed:)*
- **Hashing references** when the bank gives none: date + amount + description — two identical lines on one day (two equal fees) would collide and the second be skipped. Proposed: add a running index per file to the hash, and say so on the import result.
