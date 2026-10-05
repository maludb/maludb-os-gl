# Build spec: receivables — slice 4

What exists at the end: customers, invoices written with the lines editor and issued (numbered and posted in one transaction), sent
by email with the secure link, viewed and claimed on that link by the customer, money received and applied oldest first, deposited
through Undeposited funds into one bank line, credit notes, write-offs, reminders, statements. Replicates the journal-core exemplar
(slice 2) for the lines editor, the record page, attachments, notes and the timeline, and the chart-fiscal slice (1) for the cards and forms.
Schema: `customers`, `vendors` (for `member`), `party_contacts` (db/009); `invoices`, `invoice_lines`, `credit_notes`, `credit_note_lines`,
`credit_applications`, `receipts`, `receipt_applications`, `receipt_claims`, `deposits`, `invoice_links_secure` and the `gl_invoice_*`,
`gl_credit_note_*`, `gl_receipt_*`, `gl_secure_link_*` functions and triggers (db/010); `gl_deposit_record()`, `gl_deposit_void()`, the bank-aware
`gl_receipt_post()` (db/013); `notification_outbox`, `notifications` (db/014); the views `mcp_customers`, `mcp_party_contacts`, `mcp_invoices`,
`mcp_invoice_lines`, `mcp_credit_notes`, `mcp_credit_note_lines`, `mcp_credit_applications`, `mcp_receipts`, `mcp_receipt_applications`,
`mcp_receipt_claims`, `mcp_deposits`, `mcp_bank_accounts` (db/016). Never modify them.
**The database is the referee**: line money, totals, paid and credited, status, numbering on issue, the posting, the applications' limits,
the deposit's total, the link's hash and expiry live in db/010; the handlers call the `gl_*` verbs and show their sentences.

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `customers-list` | `/customers/` | Cards: name, balance due, open invoices, last invoiced; archived hidden (`q`, `include_archived`) |
| `customer-add` / `customer-edit` | `/customers/new`, `/{id}/edit` | The customer's facts, terms, accounts, the member it is |
| `customer-view` | `/customers/{id}` | Facts; **Contacts** (add/edit/remove inline, `ar`); **Open invoices**; **Receipts**; **Credits**; **Claims**; attachments; notes; the timeline |
| `customer-statement` | `/customers/{id}/statement` | Slice 3's report with the customer fixed; **Send** |
| `invoices-list` | `/invoices/` | A table (cards on a phone): number, customer, issued, due (an **overdue** badge with days), status, total, paid, balance; filters; a totals footer per currency |
| `invoice-add` / `invoice-edit` | `/invoices/new`, `/{id}/edit` | Customer (the picker; `?customer=`), dates (due from the terms), reference, memo, terms text, department, site; the **lines editor** |
| `invoice-view` | `/invoices/{id}` | Header with status and the actions; lines; **Received** (receipts applied, credits applied, with dates); **Claims**; the entry link; the link's state and **Rotate**; reminders sent; attachments; notes; timeline |
| `invoice-print` | `/invoices/{id}/print` | The print-ready page (the bare layout): the business's name and address, the customer's, number, dates, lines, totals, received, balance due, terms, footer |
| `credit-notes-list` / `credit-note-add` / `credit-note-view` / `credit-note-edit` | `/credit-notes/…` | As invoices, with the lines editor; where applied |
| `receipts-list` | `/receipts/` | Received, customer, amount, method, reference, applied / **unapplied** badge, deposit or **undeposited** badge |
| `receipt-add` | `/receipts/new` | Customer, amount, date, method, reference, memo, **deposit to** (a bank account or Undeposited funds), the customer's open invoices oldest first with amounts to apply (prefilled to apply in order), `?invoice=`, `?claim=` |
| `receipt-view` | `/receipts/{id}` | The receipt, its applications (unapply), its entry, its deposit; **Void** |
| `claims` | `/receipts/claims` | Submitted claims: invoice, customer, date, method, reference, amount, note — **Confirm** (→ `receipt-add` prefilled) · **Dismiss** |
| `deposits-list` / `deposit-add` / `deposit-view` | `/deposits/…` | Group undeposited receipts (checkboxes, the total live) into a deposit to a bank account; record; void |

## Invoices
- **Draft**: `invoice_draft` (`save.php` without `invoice`): `ar.write`; customer required; `issue_date` default today; `due_date` default issue + the
  customer's terms, else the settings'; currency = the customer's, else the base; `lines` rows (JSON for an agent) inserted through `save_invoice_line()`;
  the trigger computes each line's money and the totals. The lines editor: **Item** (a select over `mcp_items`; picking one fills description, unit price,
  income account and tax rate), **Description**, **Quantity**, **Unit price**, **Tax rate** (default the customer's, else the default rate), **Income account**
  (`account_select()` narrowed to income; default the item's, else the customer's, else 4100), **Department**, **Site**, **Line total** (read-only), **Remove**;
  the totals panel (subtotal, tax, total) re-rendered with the lines (`#invoice-lines` + `#invoice-totals`).
- **Issue** (`issue.php`, `invoice_issue`): `ar.write`; `gl_invoice_issue()` — number (gapless), the entry posted (DR AR / CR revenue by account / CR tax),
  status issued; RAISEs → 422 (*"An invoice needs at least one line"*, *"An invoice for nothing is not issued"*, a closed period's sentence); the secure link
  minted (`gl_secure_link_mint()`), its raw token held ONLY in the outbox row for the customer's notice (sending is `invoice_send` — issuing alone sends
  nothing); log `invoice.issue` (`after.number`, `after.amount`, `after.currency`, `after.journal_entry_number`, `journal_entry_id`, `customer_id`). **Pauses** (`money_out`).
- **Send** (`send.php`, `invoice_send`): issued or later; `to_email` default the customer's primary contact's, else the customer's; the mail: the business's
  name, the number, the amount due, the due date, `message`, and the secure link `/i/<token>` (the raw token from the outbox row minted at issue, or a fresh
  mint when none is live — never read back from the hash); `sent_at`, status sent; log `invoice.send` (`after.to` count, never the address). **Pauses** (`external_send`).
- **Remind** (`remind.php`, `invoice_remind`): past due; the reminder's wording escalates by days past due (the `writing-to-customers` skill's three steps);
  `last_reminder_at`, `reminder_count`; log `invoice.remind`. **Pauses** (`external_send`).
- **Void** (`void.php`): `journal.override`; `gl_invoice_void()` (refused with anything applied); the entry reversed; log `invoice.void` (`after.reason`, `after.amount`). **Pauses** (`money_out`).
- **Write off** (`write-off.php`): `journal.override`; `gl_invoice_write_off()`; log `invoice.write_off`. **Pauses** (`money_out`).
- **Delete** (`delete.php`): a draft only; `ar.write`; log `invoice.delete` before the delete. **Pauses** (`deletion`).
- **Rotate the link** (`secure-link.php`): `ar.write`; a new link mailed to the customer (an outbox row), the old dead; log `invoice.secure_link_rotate`.
- The invoice page's **Received** panel: each receipt application (receipt number, date, method, amount; **Unapply** for `ar.write` while the receipt is not
  deposited — a deposited receipt's application is fixed: *"Take it out of the deposit first."*), each credit application; the **Claims** panel with Confirm/Dismiss.

## Receipts, claims, deposits
- **Record** (`receipts/save.php`, `receipt_record`): `ar.write`; INSERT (numbered by the trigger), `gl_receipt_post()` (DR the bank named or Undeposited funds /
  CR AR); then the applications: `invoice` first, then `apply_oldest` over the customer's open invoices oldest due first, each `INSERT INTO receipt_applications`
  (the guard's RAISEs → 422: *"… would overpay it"*, *"… from another customer"*); `claim` → the claim confirmed with `receipt_id`; log `receipt.record`
  (`after.number`, `after.amount`, `after.method`, `after.deposit_to`, `after.applied[]`, `after.claim_id`, `customer_id`, `bank_account_id`). **Pauses** (`money_out`).
- **Apply / unapply** (`apply.php`, `unapply.php`): `ar.write`; `amount` empty = min(unapplied, owed); log `receipt.apply` / `.unapply`. **Pause** (`money_out`).
- **Void** (`void.php`): `journal.override`; `gl_receipt_void()` (refused while applied or deposited); log `receipt.void`. **Pauses** (`money_out`).
- **Claims** (`claim-confirm.php`, `claim-dismiss.php`): `ar.write`; confirm = `receipt_record` with the claim's facts (the form prefilled from `?claim=`;
  the action does it in one step); dismiss sets `dismissed` with a reason; log `claim.confirm` / `claim.dismiss`.
- **Deposits**: `deposit_draft` (`deposits/save.php`: bank account, date, memo, `receipts[]`), `deposit_add_receipt` / `deposit_remove_receipt` (the guards:
  undeposited only, a draft deposit only), `deposit_record` (`record.php`: `gl_deposit_record()` — DR the bank / CR Undeposited funds; log `deposit.record`
  with `after.amount`, `after.receipts`, `bank_account_id`; **pauses** `money_out`), `deposit_void` (`journal.override`; refused while matched to a bank line;
  the receipts return to undeposited; **pauses**), `deposit_delete` (a draft). The deposit page shows the bank line it matched (slice 6) when any.

## Credit notes
- As invoices: draft with lines (`credit_note_draft`, the lines editor), `credit_note_issue` (`gl_credit_note_issue()`: numbered, posted — DR revenue / DR tax /
  CR AR; `apply_to` applies it at once), `credit_note_apply` / `_unapply` (the guard's RAISEs), `credit_note_void` (`override`; unapplied only; the entry
  reversed), `credit_note_delete` (a draft). The invoice's **Credit** button opens `credit-note-add?customer=&invoice=` with the invoice's lines prefilled.

## Customers and contacts
- `customer_save` (`ar.write`): name (unique live → 422 *"A customer named X exists."*), legal name, email, phone, addresses, tax id, terms, currency (three
  letters, upper-cased; fixed once a document exists — 422), income account, tax rate, `member` (a member select: an employee or an invited external who
  IS this customer — the mirror's row; optional), notes. `customer_archive` (no open invoice: 422 *"Settle or void its N open invoices first."*).
  `customer_delete` (`override`; no document at all — the FKs RESTRICT: `23503` → 422 *"Acme has invoices; archive it instead."*). `contact_save` /
  `contact_remove` (`party_kind`, `party`; one primary — the index; `ar.write` for a customer, `ap.write` for a vendor; the files live under `/customers/`
  and serve vendors too, slice 5 links them). `statement_send` (`ar.write`; the report rendered to the mail; **pauses** `external_send`).
- The customer page's **Open invoices** table links each; **Receipts** the last ten; **Credits**; **Claims**; the balance due per currency.

## The public door — the secure invoice page (D9)
- `GET /i/<48 hex>` (`html/i.php`, the bare `(public)` layout, `$GLOBALS['__public_door'] = 'portal'`, no session, no acting member): `gl_secure_link_invoice()`
  → the invoice id or NULL (one dead-link page: *"This link has expired. Ask the business for a new one."*); renders the print page's content plus **Received**
  (each receipt's date and amount), **Balance due**, **Print** (`/i/<token>/print` = the print layout), and the claim form **"I paid this on … by …"** (date,
  method, reference, amount default the balance, a note) → `POST /i/<token>/claim` (`html/i/claim.php`): CSRF from an anonymous session opened for the token
  (the kit's `apiPost` pattern), honeypot, a rate limit (3 claims an hour per invoice, 20 per IP — `app_settings`-free: constants in the file), `INSERT receipt_claims`
  with `ip_address`; log `claim.submit` with `actor_customer_id`, `source = portal`, `after.amount`, `after.method`; a notice to the Bookkeeper's kind
  `claim_submitted` (the outbox: email to members whose prefs include it — slice 8 sends it; the in-app row now). The page never shows another invoice,
  a note, the customer's own email, or the token elsewhere than the URL.
- The vhost and the dev router rewrite `/i/<token>` and `/i/<token>/(claim|print)`.

## Files (exactly these)
- `html/customers/index.php` · `form.php` · `view.php` · `statement.php` · `save.php` · `archive.php` · `delete.php` · `contact.php` · `contact-remove.php` · `statement-send.php`
- `html/invoices/index.php` · `form.php` · `view.php` · `print.php` · `save.php` · `line-save.php` · `line-remove.php` · `issue.php` · `send.php` · `remind.php` · `void.php` · `write-off.php` · `delete.php` · `secure-link.php`
- `html/credit-notes/index.php` · `form.php` · `view.php` · `save.php` · `line-save.php` · `line-remove.php` · `issue.php` · `apply.php` · `unapply.php` · `void.php` · `delete.php`
- `html/receipts/index.php` · `form.php` · `view.php` · `claims.php` · `save.php` · `apply.php` · `unapply.php` · `void.php` · `claim-confirm.php` · `claim-dismiss.php`
- `html/deposits/index.php` · `form.php` · `view.php` · `save.php` · `add.php` · `remove.php` · `record.php` · `void.php` · `delete.php`
- `html/i.php` · `html/i/claim.php` · `html/i/print.php`
- `app/features/customers/queries.php` · `present.php` · `write.php` — `app/features/invoices/queries.php` · `present.php` · `write.php` · `handler.php` · `mail.php` (the invoice, reminder and statement mails: subject, text, HTML, through `app/mail.php`)
  — `app/features/credit_notes/queries.php` · `write.php` — `app/features/receipts/queries.php` · `present.php` · `write.php` — `app/features/deposits/queries.php` · `write.php` — `app/features/public/invoice.php`
- `app/views/customers/list.php` · `form.php` · `view.php` · `partials/customer-card.php` · `contacts.php` — `app/views/invoices/list.php` · `form.php` · `view.php` · `print.php` · `partials/invoice-row.php` · `invoice-card.php` · `lines.php` · `line-row.php` · `totals.php` · `received.php` · `claims.php` · `link-state.php`
  — `app/views/credit_notes/list.php` · `form.php` · `view.php` — `app/views/receipts/list.php` · `form.php` · `view.php` · `claims.php` · `partials/apply-rows.php` — `app/views/deposits/list.php` · `form.php` · `view.php` — `app/views/public/invoice.php` · `claim-done.php` · `dead-link.php`
- `app/views/shared/customer-select.php` (the ONE customer picker, searchable; slice 5 adds `vendor-select.php` beside it)

## Query functions (signatures fixed)
- Customers: `find_customers(PDO, array $filters): array` · `find_customer(PDO, int $id): ?array` (+ contacts for `ar`, balances per currency) · `save_customer(PDO, ?int $id, array $f, int $by): int` · `archive_customer(PDO, int $id, bool $archived): void` · `delete_customer(PDO, int $id): void` ·
  `save_contact(PDO, string $kind, int $partyId, ?int $contactId, array $f): int` · `remove_contact(PDO, int $id): bool` · `customer_open_invoices(PDO, int $customerId): array` (oldest due first)
- Invoices: `find_invoices(PDO, array $filters, int $limit = 50, int $offset = 0): array` · `count_invoices(PDO, array $filters): int` · `invoice_totals(PDO, array $filters): array` (per currency) · `find_invoice(PDO, int $id): ?array` (+ lines, applications, claims, link state, attachments count) · `find_invoice_by_number(PDO, string $number): ?array` ·
  `draft_invoice(PDO, array $head, array $lines, int $by): int` · `update_invoice(PDO, int $id, array $head, int $by): array` · `save_invoice_line(PDO, int $invoiceId, ?int $lineId, array $f): int` · `remove_invoice_line(PDO, int $lineId): bool` · `issue_invoice(PDO, int $id, int $by): array` · `send_invoice(PDO, int $id, ?string $to, ?string $message, int $by): array` ·
  `remind_invoice(PDO, int $id, ?string $message, int $by): array` · `void_invoice(PDO, int $id, int $by, string $reason): array` · `write_off_invoice(PDO, int $id, int $by, string $reason, ?string $date): array` · `delete_invoice(PDO, int $id, int $by): void` · `rotate_invoice_link(PDO, int $id, int $by): void` · `invoice_mail(array $invoice, string $rawToken, ?string $message, string $kind): array`
- Credit notes: `find_credit_notes(…)` · `find_credit_note(…)` · `draft_credit_note(…)` · `update_credit_note(…)` · `save_credit_note_line(…)` · `remove_credit_note_line(…)` · `issue_credit_note(PDO, int $id, int $by, ?int $applyTo): array` · `apply_credit(PDO, int $creditId, int $invoiceId, ?string $amount, int $by): array` · `unapply_credit(…)` · `void_credit_note(…)` · `delete_credit_note(…)`
- Receipts: `find_receipts(PDO, array $filters): array` · `find_receipt(PDO, int $id): ?array` · `record_receipt(PDO, array $f, array $applications, ?int $claimId, int $by): int` · `apply_receipt(PDO, int $receiptId, int $invoiceId, ?string $amount, int $by): array` · `unapply_receipt(…)` · `void_receipt(…)` · `find_claims(PDO, string $status): array` · `confirm_claim(PDO, int $claimId, array $overrides, int $by): int` · `dismiss_claim(PDO, int $claimId, ?string $reason, int $by): void`
- Deposits: `find_deposits(…)` · `find_deposit(…)` · `undeposited_receipts(PDO, ?string $currency): array` · `draft_deposit(PDO, array $f, array $receiptIds, int $by): int` · `deposit_add(…)` · `deposit_remove(…)` · `record_deposit(PDO, int $id, int $by): array` · `void_deposit(…)` · `delete_deposit(…)`
- Public: `public_invoice(string $rawToken): ?array` (`gl_secure_link_invoice()` then the base rows — as the writer, no acting member) · `submit_claim(int $invoiceId, array $f, string $ip): int`
- `invoice_refused(Throwable $e): never` (the slice's prelude, as the exemplar's `entry_refused()`)

## Handlers (every one: `gl_handler_begin()`; the gate; `require_visible()` on the record's view; one transaction; `log_activity` with `customer_id` (+ `journal_entry_id` when it posts, `bank_account_id` on a deposit or a bank receipt); `emit_action_status`; `HX-Trigger: invoiceChanged` / `receiptChanged` / `depositChanged` / `customerChanged`)
As listed above. The public handlers (`i.php`, `i/claim.php`, `i/print.php`) run with no acting member and `source = portal`, never through the views.

## Action manifest entries
- Screens: the twenty-one of the section plus the public `secure-invoice`. Actions (40): `customer_save`, `customer_archive`, `customer_delete`, `contact_save`, `contact_remove`, `invoice_draft`, `invoice_update`, `invoice_line_save`, `invoice_line_remove`, `invoice_issue`, `invoice_send`, `invoice_remind`, `invoice_void`, `invoice_write_off`, `invoice_delete`, `secure_link_rotate`, `statement_send`,
  `credit_note_draft`, `credit_note_update`, `credit_note_line_save`, `credit_note_line_remove`, `credit_note_issue`, `credit_note_apply`, `credit_note_unapply`, `credit_note_void`, `credit_note_delete`, `receipt_record`, `receipt_apply`, `receipt_unapply`, `receipt_void`, `claim_confirm`, `claim_dismiss`, `deposit_draft`, `deposit_add_receipt`, `deposit_remove_receipt`, `deposit_record`, `deposit_void`, `deposit_delete`.
  Agent approvals: as the manifest's rows. `PARTIAL_UPDATE_TARGETS` gains the invoice, credit note and deposit save endpoints.

## Activity log events (every row: `customer_id`; a posting: `journal_entry_id`)
`customer.save`, `customer.archive`, `customer.delete`, `contact.save`, `contact.remove`, `invoice.draft`, `invoice.update`, `invoice.line_save`, `invoice.line_remove`, `invoice.issue`, `invoice.send`, `invoice.remind`, `invoice.view` (portal), `invoice.void`, `invoice.write_off`, `invoice.delete`, `invoice.secure_link_rotate`, `statement.send`,
`credit_note.draft|update|line_save|line_remove|issue|apply|unapply|void|delete`, `receipt.record|apply|unapply|void`, `claim.submit` (portal), `claim.confirm`, `claim.dismiss`, `deposit.draft|add_receipt|remove_receipt|record|void|delete`, `screen.view`. **Never an address, a token, a note's text.**

## Notifications this slice queues (`notification_outbox`; the sender is slice 8)
| Step | Who is told | Kind |
|---|---|---|
| an invoice is sent / reminded / a statement sent | the customer (email with the secure link) | `invoice_sent`, `invoice_reminder`, `statement` |
| a claim is submitted on the link | members whose prefs include `claim_submitted` (the Bookkeeper's morning reads `open_claims` too) | `claim_submitted` |
| a link rotated | the customer (the new link) | `invoice_sent` |

## Status vocabulary
- Invoice: draft `secondary` · issued `info` · sent `info` · viewed `info` · partially_paid `warning` · paid `success` · void `dark` · written_off `danger`; **overdue** is a `danger` badge beside the status with the days. Credit note: draft · issued `success` · void `dark`. Receipt: recorded `success` · void `dark`; **unapplied** `warning` chip, **undeposited** `info` chip. Deposit: draft · recorded `success` · void. Claim: submitted `warning` · confirmed `success` · dismissed `secondary`.
- Ids: `invoice-row-{id}`, `invoice-card-{id}`, `invoice-lines`, `invoice-line-{id}`, `invoice-totals`, `invoice-received`, `invoice-claims`, `invoice-link`, `receipt-apply-rows`, `deposit-receipts`, `claims-list`, `customer-card-{id}`, `customer-contacts`.

## Out of scope for this slice
Vendors (5 — `contact_save` serves them from here); the bank line a deposit matches (6); sending the outbox (8 — this slice writes the rows; the proofs read them); PDF attachments (Extended, D8); online payment (Extended).

## Proof (`tests/phase3/slice4/run.sh`: the scratch database, `world()` with a fiscal year, a bank account, two customers; members through dev hand-offs; curl with action and run tokens; headless Chromium)
**Built and proven 2026-10-05 by the worker: 292 checks green** under `php -S` and under a real Apache (customers 31, invoices 44, link 27, receipts 38, deposits 27, credits 36, json 25, browser 64, the registry); slices 1–3, Phase 2 (251) and Phase 0 still green;
the registry reads the screens and actions as built (`92 screens (54 built), 124 actions (69 built)` — **38 actions**: the manifest lists 38, not the 40 this spec says).
- [x] **Customers.** A duplicate name → 422; the currency fixed after an invoice; a contact made primary un-primaries the other; archive refused with an open invoice; delete refused with any document in words; a Viewer sees the card without the email; a Bookkeeper with.
- [x] **The life of an invoice.** Draft with two lines (one taxed): the trigger's money per line, the totals; an agent's `lines` JSON lands the same; issue → `INV-00001`, posted DR AR (Acme) / CR 4100 / CR 4000 / CR 2100 (`get_journal_entry` shows the source link), the outbox row with the raw token and nothing else holding it;
  a deleted draft burns no number (the next is `INV-00002`); the lines fixed after issue (422 in the trigger's words); issue twice → 422; send → `sent_at`, the mail text carrying `/i/<token>` (grep), `invoice.send` with the count; the link opened → viewed; rotate → the old 404 on `/i/`, the new opens.
- [x] **The link and the claim.** The secure page shows the lines, received and balance, never the customer's email or a note; print; a claim submitted (CSRF, honeypot, the rate limit's fourth refused) → `receipt_claims` row with `actor_customer_id` on the log, source portal, the in-app notice for the Bookkeeper; a dead or malformed token → one page, 404.
- [x] **Receipts.** 1,000 recorded to Undeposited funds and applied to INV-00001 → partially paid, the entry DR 1050 / CR AR; over-applying → 422; another customer's invoice → 422; the rest with `apply_oldest` → paid with `paid_at`; a receipt straight to the bank posts DR 1010; unapply then void; voiding an applied receipt → 422; the claim confirmed into a receipt with `claim_id`, the claim's row confirmed.
- [x] **Deposits.** Two undeposited receipts into a draft, the total live, a bank receipt refused (*"only undeposited receipts join"*), record → DR 1010 / CR 1050 and 1050 back to zero; a receipt cannot leave a recorded deposit; void returns the receipts to undeposited and reverses the entry; delete a draft.
- [x] **Credits and write-offs.** A credit note issued and applied → `amount_credited`, the invoice partially paid; unapply; void refused while applied; write off the rest → DR 6600 / CR AR, status written_off, `write_off.php` by a Bookkeeper → 403; the statement's running balance ends at zero.
- [x] **Reminders.** `invoice_remind` on a current invoice → 422 *"… is not past due"*; on an overdue one → the outbox row with escalation step 1 wording, `reminder_count` 1; `last_reminder_at`.
- [x] **JSON mode** on every handler; `_partial=1`; a run token's rows source `agent`; the Bookkeeper agent may draft and may not issue (the kernel's pause is Phase 4's — here the right alone: the agent holds `ar.write` through the role, so the handler answers; the pause is proven in Phase 4).
- [x] **375 and 1280**: the lines editor on a phone, the invoice page's panels stacked, the public page at 375 whole with JavaScript off; the registry reads 40 actions and 22 screens as built.

## Decisions taken while building (2026-10-05)
- **A migration was needed: db/017** (`CREATE OR REPLACE gl_receipt_guard()`): `gl_deposit_void()` (db/013) set the receipts' `deposit_id` to NULL after voiding the deposit, but db/010's guard let a receipt leave only a DRAFT deposit, so a recorded deposit could never be voided ("The deposit is void; a receipt leaves a draft deposit only"). The guard now also lets a receipt leave a VOID deposit; it still refuses a recorded one. The slice 4 deposits proof covers it; Phase 0's proof did not.
- **The secure link's token lives in ONE place: an outbox row** `kind = 'invoice_link'`, `status = 'skipped'`, `detail = 'held for invoice_send'`, its `body` the URL `…/i/<48 hex>`. Issuing mints the link (settings' life) and holds it; send and remind reuse the held token while its link is live (a dead or expired one is re-minted and held again); rotate replaces it (the old row's body becomes "(replaced)") and mails the new one; void blanks it. Nothing else holds the raw token (the proof greps the log, the invoice and the link table, which keeps only the hash). **Slice 8's sender must never send a row of kind `invoice_link` (it is `skipped`, not `queued`); it sends `invoice_sent`, `invoice_reminder` and `statement` rows that are `queued`.**
- **Mail is queued, never sent here**: `queue_customer_mail()` writes `notification_outbox` rows (`status = 'queued'`, `to_email` the customer's address at send time, subject, text and HTML bodies); `invoice_send` stamps `sent_at` and lets `gl_invoice_recalculate()` own the status. The address: `to_email`, else the primary contact's, else the customer's, else a 422 in words.
- **Reminder wording** is fixed text in `invoices/mail.php`: step 1 (1–14 days past due) a friendly nudge, step 2 (15–44) firmer, step 3 (45+) "Final notice"; `reminder_count` and `last_reminder_at` are written on the invoice (the guard lets a void or written-off invoice's reminder fields change; an open one is free).
- **List filters for the ageing links** (slice 3 builds them): `/invoices/?customer=ID&as_of=DATE&overdue=current|1_30|31_60|61_90|over_90|any` — a bucket is the days between `as_of` and the due date of an invoice that is open NOW; **slice 5's `/bills/` takes the same `vendor`, `as_of` and `overdue`**. `from`/`to` filter the issue date.
- **`receipt_record` takes one more field than the manifest lists, `apply[<invoice id>] = amount`** — the form's per-invoice amounts (prefilled oldest first from the amount); the kernel's tool still sends `invoice` and `apply_oldest`. Order of application: `invoice`, then `apply[]`, then `apply_oldest` over the customer's open invoices in the receipt's currency, oldest due first. A bank named in `deposit_to` must hold the receipt's currency.
- **A receipt's currency** is the invoice's, else the customer's, else the base; a claim's amount defaults to the invoice's balance; confirming a claim records a receipt of `amount` (the real figure) with the claim's date, method and reference and applies it to the claim's invoice.
- **The claim door**: the honeypot field is `website`; the limits are the constants `CLAIM_LIMIT_PER_INVOICE_HOUR = 3` and `CLAIM_LIMIT_PER_IP_HOUR = 20` in `app/features/public/invoice.php`; the fourth claim answers 429. The bookkeepers told are the active people holding `ar.write` whose preferences include `claim_submitted` (or who never chose): an in-app row each (the email is slice 8's).
- **The public page reads base tables as the writer** (the mcp views answer nothing without an acting member) through `public_invoice()`; it shows the billing address, never the customer's email or an internal note; a dead or malformed token is the one page "This link has expired." (404).
- **`statement_send` queues** the statement mail; **the customer statement now carries write-offs** (slice 3's `customer_statement()` gained a `write_off` line: the balance written off, dated the write-off) so a written-off invoice no longer leaves the running balance open.
- **Drafts**: an invoice's `due_date` empty uses the customer's terms (else the settings'); `copy_of` on `/invoices/new` prefills the lines; `/credit-notes/new?invoice=` prefills the invoice's lines; credit-note issue with `apply_to` applies what fits at once, and the invoice page's Credit button passes the invoice. A deleted draft's lines go first (a cascade would meet the line trigger with the invoice already gone).
- **`customer_delete`** is refused in words by what exists (invoices, credit notes, receipts, posted lines); an agent's `lines` rows may name an income account by code; `normalize_doc_line()` (invoices and credit notes) is the one line reader — slice 5's bills and vendor credits reuse it with their expense account.
- **Shared pieces slice 5 reuses**: `app/views/invoices/partials/lines.php`, `line-row.php` and `totals.php` (parameterised by kind and prefix), `doc_lines_data()`, `answer_doc_region()`, `doc_line_request()`, `request_doc_rows()`, `insert_doc_line()`, `ar_refused()`, `req_id()`, `req_email()`, `req_money()`, `customers/partials/contacts.php` (takes `kind` customer or vendor), `customer_select()`, `queue_customer_mail()`, the record map's rows, `tests/phase3/slice4/lib.php`.
- **`tests/dev_router.php`** gained the vhost's page-of-a-record rule (`/{x}/{id}/{page}` → `{x}/{page}.php?id=`), which `/customers/{id}/statement` and `/invoices/{id}/print` need.

## Open questions
**Decided 2026-10-05:** the claim's amount is editable and defaults to the balance; reminder wording is three fixed steps in code.

*(As proposed:)*
- **The claim's amount**: default the balance due and let the customer change it (a partial payment), or fixed? Proposed: editable, the bookkeeper confirms the real amount.
- **Reminder wording**: the three steps live in `app/features/invoices/mail.php` as text the admin cannot edit in v1. Proposed: yes, editable templates are Extended.
