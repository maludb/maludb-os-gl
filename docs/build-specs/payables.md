# Build spec: payables — slice 5

What exists at the end: vendors, bills entered with the lines editor and their document attached, approved by the controller (posted gross
against the vendor's payable), vendor credits, payments prepared by the bookkeeper and approved by the controller, recorded when the money
moved (posted against the bank), payment runs that do the same for many at once with a CSV for the bank or the cheque printer. Replicates
slice 4 (receivables) exactly, with money OUT: a bill where an invoice was, a payment where a receipt was, approval where issue was.
Schema: `vendors`, `party_contacts` (db/009); `bills`, `bill_lines`, `vendor_credits`, `vendor_credit_lines`, `vendor_credit_applications`,
`payments`, `payment_applications`, `payment_runs` and the `gl_bill_*`, `gl_vendor_credit_*`, `gl_payment_approve()`, `gl_payment_run_approve()`
functions and triggers (db/011); `gl_payment_record()`, `gl_payment_run_record()`, `gl_payment_void()` (db/013); the views `mcp_vendors`,
`mcp_party_contacts`, `mcp_bills`, `mcp_bill_lines`, `mcp_vendor_credits`, `mcp_vendor_credit_lines`, `mcp_vendor_credit_applications`,
`mcp_payments`, `mcp_payment_applications`, `mcp_payment_runs`, `mcp_bank_accounts` (db/016). Never modify them.

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `vendors-list` | `/vendors/` | Cards: name, kind chip (**AI provider** `feather-cpu`, **Employee**, **Tax authority**), balance due, open bills, last billed (`q`, `kind`, `include_archived`) |
| `vendor-add` / `vendor-edit` | `/vendors/new`, `/{id}/edit` | The vendor's facts, terms, expense account, pay method, the member it is (an employee) |
| `vendor-view` | `/vendors/{id}` | Facts; **Contacts**; **Open bills**; **Payments**; **Credits**; documents; notes; the timeline |
| `bills-list` | `/bills/` | A table: number, vendor, their reference, dated, due (**overdue** badge), status, total, paid, balance, a **no document** chip; tabs **Awaiting approval · Approved · Due this week · All**; a totals footer per currency |
| `bill-add` / `bill-edit` | `/bills/new`, `/{id}/edit` | Vendor (the picker; `?vendor=`), their reference, dates (due from the terms), memo, department, site; the **lines editor** (expense account first); **Attach the document** on the same form |
| `bill-view` | `/bills/{id}` | Header with status and the actions; lines; the document (attachments); **Paid** (payments and credits applied); the entry link; notes; timeline |
| `vendor-credits-list` / `vendor-credit-add` / `vendor-credit-view` / `vendor-credit-edit` | `/vendor-credits/…` | As bills |
| `payments-list` | `/payments/` | Number, vendor, date, amount, method, cheque no, bank account, status, run, applied; tabs **Draft · Approved · Recorded** |
| `payment-add` | `/payments/new` | Vendor, bank account (default the settings'), amount (prefilled from the bills chosen), date, method (default the vendor's), cheque no, reference, memo, the vendor's open bills oldest due first with amounts to apply, `?bill=` |
| `payment-view` | `/payments/{id}` | The payment, its applications, approve / record / void by status, its entry, the bank line it matched |
| `payment-runs-list` / `payment-run-add` / `payment-run-view` | `/payment-runs/…` | Select approved bills due by a date (checkboxes grouped by vendor, totals live) from one bank account → one payment per vendor; the run page: its payments, **Approve**, **Record as paid**, **CSV** |

## Bills
- **Draft** (`bills/save.php`, `bill_draft`): `ap.write`; vendor required; `vendor_ref` (the vendor's own number — the unique index per vendor: `23505` → 422
  *"Hosting Co's H-2026-04 is already entered as BILL-000007."* with `record_id`); `bill_date` default today; `due_date` from the vendor's terms, else the
  settings'; currency the vendor's else the base; the trigger numbers it; `lines` rows through `save_bill_line()`. The lines editor: **Expense account**
  (`account_select()` narrowed to expense and asset — a prepaid or a fixed asset is bought too; default the item's, else the vendor's, else 6900),
  **Item** (optional; fills description, price, account), **Description**, **Quantity**, **Unit price** (four decimals allowed — the AI statement's),
  **Tax rate**, **Department**, **Site**, **Line total**, **Remove**. The **document**: a file field on the same form posting to `attachment_add`
  after the draft lands (the form's second step), or drag-and-drop later on the page; a bill with none wears the **no document** chip everywhere (AP6).
- **Approve** (`approve.php`, `bill_approve`): `ap.approve`; `gl_bill_approve()` — posts DR each line's expense account for the GROSS line total /
  CR AP (the vendor); RAISEs → 422; log `bill.approve` (`after.number`, `after.amount`, `after.currency`, `after.journal_entry_number`, `after.has_document`,
  `vendor_id`, `journal_entry_id`); `did: "Approved BILL-000007 (259.80 USD)"`. **Pauses** (`money_out`). A bill approved without a document is allowed
  but the page says so and the morning note lists it.
- **Void** (`void.php`): `journal.override`; `gl_bill_void()` (refused with anything applied); the entry reversed; log `bill.void`. **Pauses** (`money_out`).
- **Delete** (`delete.php`): a draft; `ap.write`. **Pauses** (`deletion`).
- **Copy** (`?copy_of=` on the add form): a new draft with the old bill's lines and vendor, today's dates, no reference.

## Vendor credits
- As credit notes: draft with lines, `vendor_credit_issue` (`ap.approve`; `gl_vendor_credit_issue()`: DR AP (vendor) / CR each line's expense account; `apply_to`
  applies at once), `vendor_credit_apply` / `_unapply` (`ap.write`; the guard's RAISEs), `vendor_credit_void` (`override`), `vendor_credit_delete` (a draft).

## Payments and runs
- **Draft** (`payments/save.php`, `payment_draft`): `ap.write`; vendor, amount, date, method, cheque number (required for `cheque`), bank account, reference,
  memo; `bills[]` applied oldest due first up to the amount (`INSERT payment_applications`; the guard's RAISEs → 422 *"Bill BILL-000007 is owed 200.00; paying
  259.80 would overpay it"*); `payment_run` joins a draft run (the guard: a draft run of the same bank account and currency). The amount must equal the
  applications' sum at approval (422 *"The payment's 300.00 is not fully applied: 40.00 is unapplied — apply it or reduce the amount."*).
- **Approve** (`approve.php`, `payment_approve`): `ap.approve`; `gl_payment_approve()`; log `payment.approve` (`after.amount`, `after.applied[]`). **Pauses** (`money_out`).
- **Record** (`record.php`, `payment_record`): `ap.write`; approved only (the guard's sentence); `payment_date` = the day the money moved (the form asks
  *"When did the money leave the account?"*), `cheque_number`, `reference` may be set here; `gl_payment_record()` — DR AP (vendor) / CR the bank's ledger
  account; the bills recalculated (paid); log `payment.record` (`after.number`, `after.amount`, `after.method`, `after.cheque_number`, `after.bank_account_id`,
  `after.applied[]`, `vendor_id`, `bank_account_id`, `journal_entry_id`). **Pauses** (`money_out`). The page then says *"Recorded as paid on 2 Jun — match it
  when it shows at the bank"* with a link to `/cash/match?bank_account=`.
- **Void** (`void.php`): `journal.override`; `gl_payment_void()` (refused while matched to a bank line); the entry reversed; the bills recalculated; log `payment.void`. **Pauses**.
- **Delete** (`delete.php`): a draft; `ap.write`. **Pauses** (`deletion`).
- **Apply / unapply** (`apply.php`, `unapply.php`): `ap.write`; a draft or approved payment (a recorded one's applications are fixed — the guard).
- **Runs**: `payment_run_create` (`payment-runs/save.php`): bank account, pay date, memo, `bills[]` or `due_by` (every approved and partly paid bill due by then,
  in the bank account's currency) → one draft payment per vendor with the vendor's method (cheque numbers blank; the run page assigns them in sequence from
  a "first cheque number" field on **Approve**), each applied to that vendor's bills oldest due first; the run's total by the trigger. `payment_run_add` /
  `_remove` (draft payments in and out). `payment_run_approve` (`ap.approve`; `gl_payment_run_approve()`: every draft payment approved; the cheque numbers
  assigned first when given; **pauses** `money_out`). `payment_run_record` (`ap.write`; `gl_payment_run_record()`: every approved payment recorded with
  `pay_date`; **pauses**). `payment_run_void` (`override`; unrecorded payments voided, recorded ones left — the run says "partly recorded"). The **CSV**
  (`GET /payment-runs/{id}?format=csv`, logged `export.download`): one row per payment — vendor, remit address, amount, currency, method, cheque number,
  reference, the bills' references — for the bank's bulk-payment upload or the cheque printer.

## Vendors and contacts
- `vendor_save` (`ap.write`): name (unique live), legal name, kind (`vendor`, `employee`, `tax_authority` — `ai_provider` is made by the import only and shown
  read-only), email, phone, remit address, tax id, terms, currency (fixed once a document exists), expense account, pay method, `member` (the employee this
  vendor is, for reimbursements), notes. `vendor_archive` (no open bill), `vendor_delete` (`override`; no document — `23503` → 422). Contacts through slice 4's
  `contact_save` / `contact_remove` with `party_kind = vendor` (`ap.write`); the vendor page renders the same `contacts` partial.

## Files (exactly these)
- `html/vendors/index.php` · `form.php` · `view.php` · `save.php` · `archive.php` · `delete.php`
- `html/bills/index.php` · `form.php` · `view.php` · `save.php` · `line-save.php` · `line-remove.php` · `approve.php` · `void.php` · `delete.php`
- `html/vendor-credits/index.php` · `form.php` · `view.php` · `save.php` · `line-save.php` · `line-remove.php` · `issue.php` · `apply.php` · `unapply.php` · `void.php` · `delete.php`
- `html/payments/index.php` · `form.php` · `view.php` · `save.php` · `apply.php` · `unapply.php` · `approve.php` · `record.php` · `void.php` · `delete.php`
- `html/payment-runs/index.php` · `form.php` · `view.php` · `save.php` · `add.php` · `remove.php` · `approve.php` · `record.php` · `void.php`
- `app/features/vendors/queries.php` · `present.php` · `write.php` — `app/features/bills/queries.php` · `present.php` · `write.php` · `handler.php` — `app/features/vendor_credits/queries.php` · `write.php` — `app/features/payments/queries.php` · `present.php` · `write.php` — `app/features/payment_runs/queries.php` · `write.php` · `csv.php`
- `app/views/vendors/list.php` · `form.php` · `view.php` · `partials/vendor-card.php` — `app/views/bills/list.php` · `form.php` · `view.php` · `partials/bill-row.php` · `bill-card.php` · `lines.php` · `line-row.php` · `totals.php` · `paid.php` — `app/views/vendor_credits/list.php` · `form.php` · `view.php` —
  `app/views/payments/list.php` · `form.php` · `view.php` · `partials/apply-rows.php` — `app/views/payment_runs/list.php` · `form.php` · `view.php` · `partials/run-payments.php` — `app/views/shared/vendor-select.php`

## Query functions (signatures fixed)
- Vendors: `find_vendors(PDO, array $filters): array` · `find_vendor(PDO, int $id): ?array` · `save_vendor(…)` · `archive_vendor(…)` · `delete_vendor(…)` · `vendor_open_bills(PDO, int $vendorId): array`
- Bills: `find_bills(PDO, array $filters, int $limit = 50, int $offset = 0): array` · `count_bills(…)` · `bill_totals(…)` · `find_bill(PDO, int $id): ?array` · `find_bill_by_number(…)` · `draft_bill(PDO, array $head, array $lines, int $by): int` · `update_bill(…)` · `save_bill_line(…)` · `remove_bill_line(…)` · `approve_bill(PDO, int $id, int $by): array` · `void_bill(…)` · `delete_bill(…)` · `copy_bill(PDO, int $id): array` (the prefill)
- Vendor credits: `find_vendor_credits(…)` · `find_vendor_credit(…)` · `draft_vendor_credit(…)` · `update_vendor_credit(…)` · `save_vendor_credit_line(…)` · `remove_vendor_credit_line(…)` · `issue_vendor_credit(PDO, int $id, int $by, ?int $applyTo): array` · `apply_vendor_credit(…)` · `unapply_vendor_credit(…)` · `void_vendor_credit(…)` · `delete_vendor_credit(…)`
- Payments: `find_payments(…)` · `find_payment(…)` · `draft_payment(PDO, array $f, array $billIds, int $by): int` · `update_payment(…)` · `apply_payment(…)` · `unapply_payment(…)` · `approve_payment(PDO, int $id, int $by): array` · `record_payment(PDO, int $id, array $f, int $by): array` · `void_payment(…)` · `delete_payment(…)` · `payment_unapplied(PDO, int $id): string`
- Runs: `find_payment_runs(…)` · `find_payment_run(…)` · `bills_due_by(PDO, string $dueBy, string $currency): array` · `create_payment_run(PDO, array $f, array $billIds, int $by): int` · `run_add(…)` · `run_remove(…)` · `approve_run(PDO, int $id, ?string $firstCheque, int $by): array` · `record_run(PDO, int $id, string $payDate, int $by): array` · `void_run(…)` · `run_csv(PDO, int $id): string`
- `bill_refused(Throwable $e): never`

## Handlers (every one: `gl_handler_begin()`; the gate; `require_visible()`; one transaction; `log_activity` with `vendor_id` (+ `journal_entry_id`, `bank_account_id`); `emit_action_status`; `HX-Trigger: billChanged` / `paymentChanged` / `paymentRunChanged` / `vendorChanged`)
As listed above.

## Action manifest entries
- Screens: the twenty of the section. Actions (34): `vendor_save`, `vendor_archive`, `vendor_delete`, `bill_draft`, `bill_update`, `bill_line_save`, `bill_line_remove`, `bill_approve`, `bill_void`, `bill_delete`, `vendor_credit_draft`, `vendor_credit_update`, `vendor_credit_line_save`, `vendor_credit_line_remove`, `vendor_credit_issue`, `vendor_credit_apply`, `vendor_credit_unapply`, `vendor_credit_void`, `vendor_credit_delete`,
  `payment_draft`, `payment_update`, `payment_apply`, `payment_unapply`, `payment_approve`, `payment_record`, `payment_void`, `payment_delete`, `payment_run_create`, `payment_run_add`, `payment_run_remove`, `payment_run_approve`, `payment_run_record`, `payment_run_void`. Agent approvals as the manifest's rows. `PARTIAL_UPDATE_TARGETS` gains the bill, vendor credit and payment save endpoints.

## Activity log events (every row: `vendor_id`; a posting: `journal_entry_id`; a payment: `bank_account_id`)
`vendor.save|archive|delete`, `bill.draft|update|line_save|line_remove|approve|void|delete`, `vendor_credit.draft|update|line_save|line_remove|issue|apply|unapply|void|delete`, `payment.draft|update|apply|unapply|approve|record|void|delete`, `payment_run.create|add|remove|approve|record|void`, `export.download` (the run's CSV), `screen.view`.

## Notifications this slice queues
| Step | Who is told | Kind |
|---|---|---|
| a bill is drafted (by anyone) | members whose prefs include `bill_awaiting` (the controller's) | `bill_awaiting` |
| a payment or a run awaits approval | members whose prefs include `payment_run_awaiting` (the controller's; K6 text when chosen) | `payment_run_awaiting` |
| a bill is due within the reminder days | members whose prefs include `bill_due` | `bill_due` (the worker, slice 8) |

## Status vocabulary
- Bill: draft `secondary` ("awaiting approval") · approved `info` · partially_paid `warning` · paid `success` · void `dark`; **overdue** `danger` badge; **no document** `warning` chip (`feather-file`). Payment: draft · approved `info` · recorded `success` · void `dark`. Run: the same. Vendor credit: draft · issued `success` · void.
- Ids: `bill-row-{id}`, `bill-card-{id}`, `bill-lines`, `bill-line-{id}`, `bill-totals`, `bill-paid`, `bill-document`, `payment-apply-rows`, `run-payments`, `run-bills`, `vendor-card-{id}`.

## Out of scope for this slice
The bank line a payment matches (6); the AI statement's bills (7 — they appear here as drafts of source `ai_usage`, approved here like any bill); sending the outbox (8); recurring bills (8).

## Proof (`tests/phase3/slice5/run.sh`)
**Built and proven 2026-10-05 by the worker: 370 checks green** under `php -S` and under a real Apache (vendors 39, bills 69, payments 62, runs 58, credits 38, json 31, browser 73, the registry); slices 1–4, Phase 2 and Phase 0 still green. The registry reads **18 screens and 33 actions** as built (`92 screens (72 built), 124 actions (102 built)`): the manifest lists 18 and 33, not the 20 and 34 this spec says.
- [x] **Vendors.** As slice 4's customers; an `ai_provider` vendor (fixture) read-only on kind; an employee vendor linked to the member.
- [x] **The life of a bill.** Draft with two lines and the document attached (the chip gone); a duplicate vendor reference → 422 naming the bill; approve by the Bookkeeper → 403; by the Controller → posted DR 6200 (gross, tax included) / CR AP (vendor), `bill.approve` with `has_document true`; the lines fixed; void refused once paid; copy makes a fresh draft.
- [x] **Payments.** A draft applied to two bills oldest first; over-applying → 422 in the trigger's words; approval with 40.00 unapplied → 422 in the slice's sentence; approve; record before approval → 422; record → DR AP / CR 1010, both bills paid, `payment.record` with the applied list; void refused while matched (fixture from slice 6's function); void → reversed and the bills owed again.
- [x] **Runs.** `due_by` selects the three approved bills due; one payment per vendor; approve with a first cheque number → 2001, 2002, 2003; add a draft payment of another bank account → 422; record → every payment recorded on the pay date, the bills paid, the CSV with vendor, amount, cheque number and the references, logged `export.download`; void a run with one recorded payment → the others void, the run "partly recorded".
- [x] **Vendor credits.** Issued, applied, the bill's balance falls; void refused while applied.
- [x] **JSON mode**, `_partial=1`, run tokens, 375 and 1280, the registry reads 34 actions and 20 screens.

## Decisions taken while building (2026-10-05)
- **The lines editor, shared with slice 4:** `normalize_doc_line()` gained a `$side` (`income` | `expense`; the expense side accepts expense and asset accounts and reads `expense_account` or `account`) and returns `account_id`; `insert_doc_line()` picks the column by table and leaves `item_id` out for `vendor_credit_lines` (they carry none); `doc_line_request()`, `doc_lines_data()` and `answer_doc_region()` cover `bill` and `vendor_credit`; `invoices/partials/lines.php`, `line-row.php` and `totals.php` take `kind` and `side` (an "Expense account" column). A line's account when none is named: the item's, else the vendor's, else 6900.
- **`parse_unit_price()`** (`shared/money.php`) reads every document line's unit price with up to four decimals, exact (the AI statement's amounts); `parse_money()` stays at two.
- **The document rides the bill form:** `bills/save.php` takes an optional multipart `document` (attachments.write), stored in the same transaction as the draft (`attachment.add` logged, `has_document` in the answer and in `bill.draft`); the attachments panel on the bill page is the "later" way. The manifest's `bill_draft` row does not list it.
- **A duplicate vendor reference** is checked before the insert (`DuplicateBillRef`): 422 *"SMOKE Hosting Co's H-2026-04 is already entered as BILL-000007."* with the existing bill's id in `fields.record_id`; a void bill frees its reference. Bill numbers are made at creation, so a deleted draft leaves a gap.
- **Notifications are in-app rows** (`notify_members_with_right('ap.approve', …)`): `bill_awaiting` when a bill is drafted, `payment_run_awaiting` when a payment outside a run or a run is made. The email and text are slice 8's.
- **What a payment may apply to a bill** is its *payable*: the total less credits less what other live payments already hold (`bill_payable()`, the guard's own sum). `payment_draft` with `bills[]` and no amount takes the sum of the payables; the bills are applied oldest due first up to the amount, so 80.00 for one bill owed 50.00 applies 50.00 and leaves 30.00 unapplied (approval then refuses it). `payment_apply` with no amount applies what fits and, when nothing is payable, lets the guard say *"… is owed 0.00; paying … would overpay it"*. An amount below what a draft already pays is refused at update ("… already pays 160.00; unapply a bill before reducing it").
- **A payment's currency is its bank account's.** Approval and record both require it fully applied (the slice's sentence). **A cheque's number is required to RECORD, not to draft** — a run's cheques are numbered at the run's approval. `payment_record` sets the date, cheque number and reference first, then `gl_payment_record()`.
- **`payment_run_approve` takes `first_cheque_number`** (digits; kept at its width, so `0100` → `0101`); the cheque payments without a number are numbered from it in order of their payments. A vendor with no usual method pays by `ach`. `payment_run_create` takes `bills[]` or `due_by` (neither: 422 naming `bills`); bills a live payment already holds are not offered. A payment of another bank account or currency may not join (422 in words — the database does not check this).
- **`payment_run_void`:** unrecorded payments are voided; when none is recorded the run becomes `void`; when some are, the run reads `recorded` with the "partly recorded" chip and its total is what stays; a run whose payments are all recorded refuses ("void a payment to undo it"). The CSV (`?format=csv`, exports.download, logged `export.download` with `bank_account_id`) has `payment, vendor, remit_address, amount, currency, method, cheque_number, pay_date, reference, bills`.
- **The payment form with no default bank account** (settings, slice 6/9) asks for one; changing it reloads the bills in its currency.
- **Vendors:** account fields take ids (codes are for document lines); `vendor_delete` also refuses a vendor with an attached document; the cards' balances are per currency (`mcp_vendors.balance_due` sums them). An AI provider keeps its kind and key.
- **Lists:** `/bills/?vendor=ID&as_of=DATE&overdue=current|1_30|31_60|61_90|over_90|any` — the same convention as `/invoices/`, for slice 3's "whom we owe" links; `status=unpaid`, `due_within=DAYS`, `no_document=1`, `source=…`, `from`/`to` (bill date), `q`, `format=csv`.
- **Slice 6 reads:** a payment's matched bank line is read from `mcp_bank_transactions` when it exists (`matched_type = 'payment'`); the payment page links `/cash/match?bank_account=` once recorded.
- **Tests:** `tests/phase3/slice5/lib.php` (`ap_fixture`, `mk_vendor`, `mk_bill`, `ready_bill`, `mk_payment`, `clear_open_bills`); Phase 2's hops now use the Bills list and the still-unbuilt `/cash/` placeholder.

## Open questions
**Decided 2026-10-05:** cheque numbers sequential from a first number typed at the run's approval.

*(As proposed:)*
- **Cheque numbers** assigned at a run's approval from a "first cheque number" (sequential per run). Proposed: yes; a per-bank-account "next cheque number" setting is Extended.
