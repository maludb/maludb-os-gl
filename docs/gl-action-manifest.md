# General Ledger Action Manifest

2026-10-05 · **Phase 1, for the checkpoint**

> The registry the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve
> against. A screen or action missing here cannot be reached by voice or by an agent — unfinished design, like a question
> with no tool. Pairs with `db/` (tables), `docs/gl-mcp-tool-surface.md` (read tools) and the slice specs in
> `docs/build-specs/`. Generated into `mcp/action_registry.json` by `bin/build_action_registry.php`; an action whose file
> does not exist yet is registered but not exposed. The approval categories here ARE `maludb-os.json` `approvals[]`
> (`bin/sync_approvals.php` rewrites that block from this file).

## Conventions

### Screens
- **Ids** are kebab-case `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-gl.conf`: `/{x}/new` → `form.php`, `/{x}/{id}/edit` → `form.php?id=`,
  `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`, `/statements/{name}` → `statement.php?statement=`,
  `/reports/{name}` → `report.php?report=`. Every screen is a page, never a modal; designed at 375 px first; cards for
  named things (accounts, parties, bank accounts, periods), tables for ledgers and lines.
- **Prefill params** become query-string values the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity`, `data-record-id` on `#page-content`, so "that", "this
  invoice" and "post it" resolve against the page the user is on.
- **The public door** (`/i/{token}`) is a screen with no session; its POST is **not an action** (no agent reaches it) and is
  listed apart at the end.

### Actions
- **Name** `{entity}_{verb}`; the **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with the
  audit keys: `journal_entry_id`, `period_id`, `customer_id`, `vendor_id`, `bank_account_id`, `department_id`, `site_id`) and
  what the kernel's approval policies match — so an action an agent's call must pause on has **its own log event**.
  `.delete` destroys a draft; `.void` keeps a record and reverses its entry; `.archive` keeps a party or a setting out of the way.
- **Endpoints** are POST to `/{base}/{file}` with `require_post()`, `verify_csrf()` (or the relayed action token), the right,
  the record's visibility (`require_visible()`), and `log_activity()`. Handlers report through `emit_action_status()`; a
  create's `location` ends in the new record's id. **A handler calls the `gl_*` writer function and translates its RAISE
  (`SQLSTATE P0001`) into a 422 in the same words**; it never re-implements a rule the database keeps.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the
  last parameter). A param named for a record (`account`, `customer`, `vendor`, `invoice`, `bill`, `entry`, `receipt`,
  `payment`, `deposit`, `payment_run`, `credit_note`, `vendor_credit`, `bank_account`, `bank_line`, `period`, `fiscal_year`,
  `item`, `tax_rate`, `statement`, `reconciliation`, `template`, `member`, `department`, `site`) accepts an id or a name,
  resolved through the ledger's own tool (`docs/gl-mcp-tool-surface.md`, resolution table); a document also accepts its
  number (`INV-00042`, `JE-000123`, `BILL-000007`). Dates are `YYYY-MM-DD`; money is a decimal with two places in the
  document's currency. `any field of X` = X's fields, all optional, a partial update.
- **Who** is enforced in the endpoint: `own` = the record's own person (the token's owner, their notifications); `view` =
  `reports.view`; `docs` = `documents.view`; `journal` = `journal.write`; `ar` = `ar.write`; `ap` = `ap.write`; `bank` =
  `bank.write`; `approve` = `ap.approve`; `reconcile` = `bank.reconcile`; `periods` = `periods.manage`; `override` =
  `journal.override`; `attach` = `attachments.write`; `admin` = `settings.manage`. A super-admin holds every right. An
  agent is granted like a person.
- **Undo** is the inverse `undo_last` applies; — means it cannot be undone and the reply says so (nothing posted is undone:
  it is reversed, and the reply says that).
- **Confirm (✔)** = the command bar asks first (money is made true, something leaves the building, or it is destroyed).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (D7): `money_out`
  (anything that makes an entry true or records money as moved or changes a claim on it), `external_send` (an invoice, a
  statement or a reminder leaves the building), `deletion`, `other` (periods, reconciliations, configuration — the
  default policy REFUSES `period_close`, `period_reopen`, `reconciliation_complete`, `reconciliation_reopen` and
  `year_reopen` to agents until the owner relaxes it). People are never paused.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity).
- **Log payloads** (what `before`/`after` must carry) are in `docs/gl-mcp-tool-surface.md`; **every money event carries
  `amount` and `currency` in `after`; a secure token, a token value or a party's contact details are never in a payload.**

### Result contract
```json
{"status": "success", "did": "Posted JE-000124 (1,608.24 USD)", "record_id": 124, "undo_id": null, "refresh": "journalEntryChanged"}
{"status": "pending_approval", "did": "Posting JE-000124 waits for a person's approval", "approval_request_id": 31}
{"status": "error", "message": "Period 2026-02 is closed; nothing posts into it until it is reopened."}
```

## Home, me and the ledger's settings

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `dashboard` | `/` | their home: the cash position, receivables and payables with their aging, what waits (drafts, bills awaiting approval, unmatched bank lines, claims), the AI bill's state, posted-through, the Bookkeeper's last morning note |
| `settings` | `/settings/` | how they are told (email, text, which events) (params: `tab`) |
| `tokens` | `/settings/tokens/` | their own access tokens for the two MCP servers |
| `notifications` | `/notifications` | their in-app notifications, unread first |
| `activity` | `/activity` | their own recent activity, or one record's history (params: `entity_type`, `entity_id`, `period`) |
| `general-settings` | `/settings/general` | the business's ledger settings: name and address on the invoice, base currency, fiscal year start, the control accounts, terms, the link's life, reminder days, the AI statement poll, who gets the morning note |
| `numbering` | `/settings/numbering` | the document prefixes and next numbers |
| `agents` | `/settings/agents` | which agents keep books here, their last dispatch and run (read-only; hiring and grants link to the kernel) |
| `audit` | `/audit` | the audit page: reopened periods, posts into reopened periods, reversals and voids, the AI statement check, the control-account tie-out (params: `from`, `to`) |
| `exports` | `/exports` | every statement and ledger as a file: the general ledger detail, the journal, the chart, the trial balance, the aging, for a period (params: `period`, `format`) |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | email_enabled (yes or no), text_enabled (yes or no), kinds[] (the events to be told about), text_kinds[] (the events to be texted about) | restore prior | | | `prefs.save` | own |
| `token_mint` | `tokens/mint.php` | **label**, scope (mcp or api) | revoke it | | | `token.mint` | own |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own |
| `notification_read` | `notifications/read.php` | notification (empty marks all read) | — | | | `notification.read` | own |
| `settings_save` | `general.php` | business_name, business_address, business_tax_id, base_currency (three letters), fiscal_year_start_month (1 to 12), timezone, ar_account, ap_account, undeposited_funds_account, sales_tax_account, ai_usage_account, retained_earnings_account, bad_debt_account, default_bank_account, invoice_terms_days (0 to 365), bill_terms_days (0 to 365), link_lifetime_days (1 to 3650), reminder_days[] (days past due), ai_statement_poll (yes or no), morning_note_member (who receives the Bookkeeper's note), invoice_footer | restore prior | | other | `settings.save` | admin |
| `numbering_save` | `numbering.php` | **kind** (invoice or credit_note or journal_entry or bill or vendor_credit or receipt or payment or deposit or payment_run or transfer), prefix (capital letters and digits and a dash up to 8), next_value (never lower than the current), padding (1 to 12) | restore prior | | other | `numbering.save` | admin |
| `morning_note_send` | `agents/morning-note.php` | **body** (what was done and what needs a person), to_member (empty sends it to the member the settings name) | — | | | `morning_note.send` | journal |

## The chart, fiscal time, tax, items, sites (slice 1)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `accounts-list` | `/accounts/` | the chart of accounts as cards grouped by type, with balances as of today (params: `type`, `include_archived`, `as_of`) |
| `account-add` | `/accounts/new` | to add an account (params: `type`, `parent`) |
| `account-view` | `/accounts/{id}` | one account: its balance and activity for a period with a running balance, its children (params: `from`, `to`, `department`, `site`) |
| `account-edit` | `/accounts/{id}/edit` | to rename, re-parent or describe an account |
| `periods-list` | `/periods/` | the fiscal years and their periods as cards: open, closed, reopened, with posted and draft counts |
| `fiscal-year-add` | `/periods/new` | to open a fiscal year with its twelve periods (params: `start`) |
| `period-view` | `/periods/{id}` | one period: its state and history, what posted in it, what is still a draft in it, the close checklist |
| `tax-rates` | `/settings/tax-rates/` | the tax rates and the default |
| `tax-rate-add` | `/settings/tax-rates/new` | to add a rate |
| `tax-rate-edit` | `/settings/tax-rates/{id}/edit` | to rename a rate or make it the default |
| `items-list` | `/settings/items/` | the products and services with their accounts and prices (params: `q`, `kind`) |
| `item-add` | `/settings/items/new` | to add an item |
| `item-edit` | `/settings/items/{id}/edit` | to change an item |
| `posting-rules` | `/settings/posting-rules/` | which source posts to which accounts |
| `posting-rule-edit` | `/settings/posting-rules/{id}/edit` | to change a rule's accounts |
| `sites-list` | `/settings/sites/` | the sites (the second dimension) |
| `site-add` | `/settings/sites/new` | to add a site |
| `site-edit` | `/settings/sites/{id}/edit` | to rename a site or pin it to the kernel's location |

Actions (base `/accounts/`, periods under `/periods/`, tax rates under `/settings/tax-rates/`, items under `/settings/items/`, posting rules under `/settings/posting-rules/`, sites under `/settings/sites/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `account_save` | `/accounts/save.php` | account (to change an existing one), **code** (digits with an optional dot part), **name**, **account_type** (asset or liability or equity or income or expense), subtype, parent (an account of the same type), is_bank (yes or no), description | restore prior | | other | `account.save` | admin |
| `account_archive` | `/accounts/archive.php` | **account** (with a zero balance), archived (yes archives and no restores) | restore | ✔ | other | `account.archive` | admin |
| `fiscal_year_create` | `/periods/save.php` | **start** (the first day of a month), name | — | ✔ | other | `fiscal_year.create` | admin |
| `period_close` | `/periods/close.php` | **period** | period_reopen | ✔ | other | `period.close` | periods |
| `period_reopen` | `/periods/reopen.php` | **period**, **reason** | period_close | ✔ | other | `period.reopen` | periods |
| `year_end_close` | `/periods/year-close.php` | **fiscal_year** (every period closed and no draft dated in it) | year_reopen | ✔ | money_out | `fiscal_year.close` | periods |
| `year_reopen` | `/periods/year-reopen.php` | **fiscal_year**, **reason** | — | ✔ | other | `fiscal_year.reopen` | periods |
| `tax_rate_save` | `/settings/tax-rates/save.php` | tax_rate (to change an existing one), **name**, **rate** (a percentage below 100), is_default (yes or no) | restore prior | | other | `tax_rate.save` | admin |
| `tax_rate_archive` | `/settings/tax-rates/archive.php` | **tax_rate** (never the default) | restore | ✔ | other | `tax_rate.archive` | admin |
| `item_save` | `/settings/items/save.php` | item (to change an existing one), **name**, code, kind (product or service), description, unit, unit_price, income_account, expense_account, tax_rate | restore prior | | other | `item.save` | admin |
| `item_archive` | `/settings/items/archive.php` | **item**, archived (yes or no) | restore | ✔ | other | `item.archive` | admin |
| `posting_rule_save` | `/settings/posting-rules/save.php` | **rule** (the source or an existing rule), match_key (an item or vendor key or empty for the default), debit_account, credit_account, tax_account, note, active (yes or no) | restore prior | | other | `posting_rule.save` | admin |
| `site_save` | `/settings/sites/save.php` | site (to change an existing one), **name**, kernel_location (the kernel's location id), address | restore prior | | other | `site.save` | admin |
| `site_archive` | `/settings/sites/archive.php` | **site**, archived (yes or no) | restore | ✔ | other | `site.archive` | admin |

## The journal (slice 2 — THE EXEMPLAR)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `journal-list` | `/journal/` | the entries as a ledger: drafts first, then by date, with period, source, totals, status (params: `period`, `status`, `source`, `account`, `q`, `from`, `to`) |
| `journal-entry-add` | `/journal/new` | to write a manual entry: date, memo, the lines editor with the balance strip (params: `date`, `reverse_of`, `template`) |
| `journal-entry-view` | `/journal/{id}` | one entry: its lines, its source document, its reversal or what it reverses, attachments, notes, the timeline |
| `journal-entry-edit` | `/journal/{id}/edit` | to correct a draft's date, memo, dimensions or lines |

Actions (base `/journal/`, attachments under `/attachments/`, notes under `/notes/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `journal_entry_draft` | `/journal/save.php` | **entry_date**, memo, department, site, currency (three letters or empty for the base), lines (account and debit or credit and description and department and site and customer and vendor as JSON rows) | journal_entry_void | | | `journal_entry.draft` | journal |
| `journal_entry_update` | `/journal/save.php` | **entry** (a draft), entry_date, memo, department, site | restore prior | | | `journal_entry.update` | journal |
| `journal_line_save` | `/journal/line-save.php` | **entry** (a draft), line (to change an existing one), **account**, debit, credit (one of the two), description, department, site, customer (on the receivable account), vendor (on the payable account), tax_rate | restore prior | | | `journal_entry.line_save` | journal |
| `journal_line_remove` | `/journal/line-remove.php` | **entry** (a draft), **line** | restore | | | `journal_entry.line_remove` | journal |
| `journal_entry_post` | `/journal/post.php` | **entry** (a balanced draft dated in an open period) | — (reverse it) | ✔ | money_out | `journal_entry.post` | journal |
| `journal_entry_reverse` | `/journal/reverse.php` | **entry** (a posted one), **reason**, reverse_date (empty uses the original's date or today when its period is closed) | — | ✔ | money_out | `journal_entry.reverse` | journal for one's own posted entries, override for any |
| `journal_entry_void` | `/journal/void.php` | **entry** (a draft), reason | — | ✔ | deletion | `journal_entry.void` | journal |
| `journal_entries_post_batch` | `/journal/post-batch.php` | **entries[]** (balanced drafts) | — | ✔ | money_out | `journal_entry.post_batch` | journal |
| `attachment_add` | `/attachments/save.php` | **record_type** (journal_entry or invoice or credit_note or receipt or deposit or bill or vendor_credit or payment or payment_run or transfer or reconciliation or customer or vendor or ai_statement), **record_id**, **file** (multipart) | attachment_delete | | | `attachment.add` | attach |
| `attachment_delete` | `/attachments/delete.php` | **attachment** | — | ✔ | deletion | `attachment.delete` | override |
| `note_add` | `/notes/save.php` | **record_type**, **record_id**, **body** | — | | | `note.add` | docs |

## Statements and reports (slice 3)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `statements` | `/statements/` | the four statements as cards with the current period's headline figures and posted-through |
| `statement` | `/statements/{statement}` | one statement — pl, balance-sheet, trial-balance, cash-flow — for a period, compare-to, by department or site, per currency, as a table, a CSV or a print page (params: `from`, `to`, `as_of`, `compare`, `department`, `site`, `format`) |
| `reports` | `/reports/{report}` | one report as a table and a CSV: ar-aging, ap-aging, sales-tax, spend, revenue, customer-statement, control-check (params: `as_of`, `from`, `to`, `group_by`, `customer`, `vendor`, `format`) |

No actions: a statement or a report is read; a CSV or a print page is a GET with `format=` logged `export.download`.

## Receivables (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `customers-list` | `/customers/` | the customers as cards with balance due and open invoices (params: `q`, `include_archived`) |
| `customer-add` | `/customers/new` | to add a customer |
| `customer-view` | `/customers/{id}` | one customer: contacts, open invoices, receipts, credits, claims, notes, attachments |
| `customer-edit` | `/customers/{id}/edit` | to change a customer |
| `customer-statement` | `/customers/{id}/statement` | the customer's statement for a period: opening balance, invoices, receipts, credits, running balance (params: `from`, `to`, `format`) |
| `invoices-list` | `/invoices/` | the invoices with status and overdue filters and a totals footer per currency (params: `status`, `overdue`, `customer`, `from`, `to`, `q`) |
| `invoice-add` | `/invoices/new` | to write an invoice (params: `customer`, `copy_of`) |
| `invoice-view` | `/invoices/{id}` | one invoice: lines, receipts and credits applied, claims, the secure link's state, attachments, notes, the timeline, the actions by status |
| `invoice-edit` | `/invoices/{id}/edit` | to change a draft invoice and its lines |
| `invoice-print` | `/invoices/{id}/print` | the print-ready invoice page (the same page the customer sees on the link) |
| `credit-notes-list` | `/credit-notes/` | the credit notes (params: `status`, `customer`) |
| `credit-note-add` | `/credit-notes/new` | to write a credit note (params: `customer`, `invoice`) |
| `credit-note-view` | `/credit-notes/{id}` | one credit note: lines, where it is applied |
| `credit-note-edit` | `/credit-notes/{id}/edit` | to change a draft credit note |
| `receipts-list` | `/receipts/` | money received: with an unapplied filter and the undeposited ones (params: `customer`, `from`, `to`, `unapplied`, `undeposited`) |
| `receipt-add` | `/receipts/new` | to record money received and apply it to open invoices oldest first (params: `customer`, `invoice`, `amount`, `claim`) |
| `receipt-view` | `/receipts/{id}` | one receipt: its applications, its entry, its deposit |
| `claims` | `/receipts/claims` | what customers said on the link that is not yet confirmed (params: `status`) |
| `deposits-list` | `/deposits/` | the deposits (params: `bank_account`, `status`) |
| `deposit-add` | `/deposits/new` | to group undeposited receipts into one bank deposit (params: `bank_account`) |
| `deposit-view` | `/deposits/{id}` | one deposit: its receipts, its entry, the bank line it matched |

Actions (base `/customers/`, invoices under `/invoices/`, credit notes under `/credit-notes/`, receipts under `/receipts/`, deposits under `/deposits/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `customer_save` | `/customers/save.php` | customer (to change an existing one), **name**, legal_name, email, phone, billing_address, shipping_address, tax_id, terms_days (0 to 365), currency (three letters), income_account, tax_rate, member (an employee or an invited external who is this customer), notes | restore prior | | | `customer.save` | ar |
| `customer_archive` | `/customers/archive.php` | **customer** (no open invoice), archived (yes or no) | restore | ✔ | | `customer.archive` | ar |
| `customer_delete` | `/customers/delete.php` | **customer** (with no document at all) | — | ✔ | deletion | `customer.delete` | override |
| `contact_save` | `/customers/contact.php` | **party_kind** (customer or vendor), **party** (the customer or vendor), contact (to change an existing one), **name**, email, phone, role, is_primary (yes or no) | restore prior | | | `contact.save` | ar for a customer and ap for a vendor |
| `contact_remove` | `/customers/contact-remove.php` | **contact** | restore | ✔ | | `contact.remove` | ar or ap |
| `invoice_draft` | `/invoices/save.php` | **customer**, issue_date, due_date (empty uses the customer's terms), reference (the customer's PO), memo, terms_text, department, site, lines (item or description and quantity and unit_price and tax_rate and income_account as JSON rows) | invoice_delete | | | `invoice.draft` | ar |
| `invoice_update` | `/invoices/save.php` | **invoice** (a draft), any field of invoice_draft | restore prior | | | `invoice.update` | ar |
| `invoice_line_save` | `/invoices/line-save.php` | **invoice** (a draft), line (to change an existing one), item (fills description and price and account), **description**, **quantity**, **unit_price**, tax_rate, income_account, department, site | restore prior | | | `invoice.line_save` | ar |
| `invoice_line_remove` | `/invoices/line-remove.php` | **invoice** (a draft), **line** | restore | | | `invoice.line_remove` | ar |
| `invoice_issue` | `/invoices/issue.php` | **invoice** (a draft with lines; numbered and posted) | — (void it) | ✔ | money_out | `invoice.issue` | ar |
| `invoice_send` | `/invoices/send.php` | **invoice** (issued; mailed with its secure link), to_email (empty uses the customer's), message (words before the invoice) | — | ✔ | external_send | `invoice.send` | ar |
| `invoice_remind` | `/invoices/remind.php` | **invoice** (past due), message | — | ✔ | external_send | `invoice.remind` | ar |
| `invoice_void` | `/invoices/void.php` | **invoice** (issued with nothing applied), **reason** | — | ✔ | money_out | `invoice.void` | override |
| `invoice_write_off` | `/invoices/write-off.php` | **invoice** (owed), **reason**, write_off_date | — | ✔ | money_out | `invoice.write_off` | override |
| `invoice_delete` | `/invoices/delete.php` | **invoice** (a draft) | — | ✔ | deletion | `invoice.delete` | ar |
| `secure_link_rotate` | `/invoices/secure-link.php` | **invoice** (a new link mailed and the old one dead) | — | ✔ | | `invoice.secure_link_rotate` | ar |
| `statement_send` | `/customers/statement-send.php` | **customer**, from, to, to_email (empty uses the customer's) | — | ✔ | external_send | `statement.send` | ar |
| `credit_note_draft` | `/credit-notes/save.php` | **customer**, invoice (the invoice it is raised against), issue_date, reason, department, site, lines (as an invoice's) | credit_note_delete | | | `credit_note.draft` | ar |
| `credit_note_update` | `/credit-notes/save.php` | **credit_note** (a draft), any field of credit_note_draft | restore prior | | | `credit_note.update` | ar |
| `credit_note_line_save` | `/credit-notes/line-save.php` | **credit_note** (a draft), line, item, **description**, **quantity**, **unit_price**, tax_rate, income_account, department, site | restore prior | | | `credit_note.line_save` | ar |
| `credit_note_line_remove` | `/credit-notes/line-remove.php` | **credit_note** (a draft), **line** | restore | | | `credit_note.line_remove` | ar |
| `credit_note_issue` | `/credit-notes/issue.php` | **credit_note** (a draft; numbered and posted), apply_to (an invoice to apply it to at once) | — | ✔ | money_out | `credit_note.issue` | ar |
| `credit_note_apply` | `/credit-notes/apply.php` | **credit_note** (issued), **invoice**, amount (empty applies what fits) | credit_note_unapply | ✔ | money_out | `credit_note.apply` | ar |
| `credit_note_unapply` | `/credit-notes/unapply.php` | **credit_note**, **invoice** | credit_note_apply | ✔ | money_out | `credit_note.unapply` | ar |
| `credit_note_void` | `/credit-notes/void.php` | **credit_note** (issued and unapplied), **reason** | — | ✔ | money_out | `credit_note.void` | override |
| `credit_note_delete` | `/credit-notes/delete.php` | **credit_note** (a draft) | — | ✔ | deletion | `credit_note.delete` | ar |
| `receipt_record` | `/receipts/save.php` | **customer**, **amount**, received_on, **method** (cash or cheque or transfer or card or other), reference, memo, deposit_to (a bank account or empty for undeposited funds), invoice (apply it to this invoice first), apply_oldest (yes applies the rest to the oldest open invoices), claim (the claim this confirms) | receipt_void | ✔ | money_out | `receipt.record` | ar |
| `receipt_apply` | `/receipts/apply.php` | **receipt**, **invoice**, amount (empty applies what fits) | receipt_unapply | ✔ | money_out | `receipt.apply` | ar |
| `receipt_unapply` | `/receipts/unapply.php` | **receipt**, **invoice** | receipt_apply | ✔ | money_out | `receipt.unapply` | ar |
| `receipt_void` | `/receipts/void.php` | **receipt** (unapplied and undeposited), **reason** | — | ✔ | money_out | `receipt.void` | override |
| `claim_confirm` | `/receipts/claim-confirm.php` | **claim** (recorded as a receipt and applied to its invoice), amount (empty takes the claim's), deposit_to | receipt_void | ✔ | money_out | `claim.confirm` | ar |
| `claim_dismiss` | `/receipts/claim-dismiss.php` | **claim**, reason | — | ✔ | | `claim.dismiss` | ar |
| `deposit_draft` | `/deposits/save.php` | **bank_account**, deposit_date, memo, receipts[] (undeposited receipts to put in) | deposit_delete | | | `deposit.draft` | ar |
| `deposit_add_receipt` | `/deposits/add.php` | **deposit** (a draft), **receipt** (undeposited) | deposit_remove_receipt | | | `deposit.add_receipt` | ar |
| `deposit_remove_receipt` | `/deposits/remove.php` | **deposit** (a draft), **receipt** | deposit_add_receipt | | | `deposit.remove_receipt` | ar |
| `deposit_record` | `/deposits/record.php` | **deposit** (a draft with receipts; posted) | deposit_void | ✔ | money_out | `deposit.record` | ar |
| `deposit_void` | `/deposits/void.php` | **deposit** (recorded and unmatched), **reason** | — | ✔ | money_out | `deposit.void` | override |
| `deposit_delete` | `/deposits/delete.php` | **deposit** (a draft) | — | ✔ | deletion | `deposit.delete` | ar |

## Payables (slice 5)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `vendors-list` | `/vendors/` | the vendors as cards with balance due and open bills; AI providers marked (params: `q`, `kind`, `include_archived`) |
| `vendor-add` | `/vendors/new` | to add a vendor |
| `vendor-view` | `/vendors/{id}` | one vendor: contacts, open bills, payments, credits, documents, notes |
| `vendor-edit` | `/vendors/{id}/edit` | to change a vendor |
| `bills-list` | `/bills/` | the bills: awaiting approval, approved, unpaid, due this week, without a document (params: `status`, `vendor`, `due_within`, `no_document`, `source`, `q`) |
| `bill-add` | `/bills/new` | to enter a bill with its lines and attach its document (params: `vendor`, `copy_of`) |
| `bill-view` | `/bills/{id}` | one bill: lines, document, payments and credits applied, its entry, notes, the timeline, the actions by status |
| `bill-edit` | `/bills/{id}/edit` | to change a draft bill and its lines |
| `vendor-credits-list` | `/vendor-credits/` | the vendor credits (params: `status`, `vendor`) |
| `vendor-credit-add` | `/vendor-credits/new` | to enter a vendor credit (params: `vendor`, `bill`) |
| `vendor-credit-view` | `/vendor-credits/{id}` | one vendor credit |
| `vendor-credit-edit` | `/vendor-credits/{id}/edit` | to change a draft vendor credit |
| `payments-list` | `/payments/` | money out: draft, approved, recorded, with the bank account and cheque number (params: `status`, `vendor`, `bank_account`, `from`, `to`) |
| `payment-add` | `/payments/new` | to prepare a payment for one or several of a vendor's bills (params: `vendor`, `bill`, `bank_account`) |
| `payment-view` | `/payments/{id}` | one payment: its applications, its entry, the bank line it matched |
| `payment-runs-list` | `/payment-runs/` | the payment runs (params: `status`) |
| `payment-run-add` | `/payment-runs/new` | to select due bills and make a run of payments from one bank account (params: `bank_account`, `due_by`) |
| `payment-run-view` | `/payment-runs/{id}` | one run: its payments, approve, record, the CSV for the bank or the cheque printer |

Actions (base `/vendors/`, bills under `/bills/`, vendor credits under `/vendor-credits/`, payments under `/payments/`, runs under `/payment-runs/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `vendor_save` | `/vendors/save.php` | vendor (to change an existing one), **name**, legal_name, kind (vendor or employee or tax_authority), email, phone, remit_address, tax_id, terms_days (0 to 365), currency (three letters), expense_account, pay_method (cheque or ach or card or cash or other), member (the employee this vendor is), notes | restore prior | | | `vendor.save` | ap |
| `vendor_archive` | `/vendors/archive.php` | **vendor** (no open bill), archived (yes or no) | restore | ✔ | | `vendor.archive` | ap |
| `vendor_delete` | `/vendors/delete.php` | **vendor** (with no document at all) | — | ✔ | deletion | `vendor.delete` | override |
| `bill_draft` | `/bills/save.php` | **vendor**, vendor_ref (the vendor's own invoice number), bill_date, due_date (empty uses the vendor's terms), memo, department, site, lines (expense_account or item and description and quantity and unit_price and tax_rate and department and site as JSON rows) | bill_delete | | | `bill.draft` | ap |
| `bill_update` | `/bills/save.php` | **bill** (a draft), any field of bill_draft | restore prior | | | `bill.update` | ap |
| `bill_line_save` | `/bills/line-save.php` | **bill** (a draft), line (to change an existing one), item, **description**, **quantity**, **unit_price**, tax_rate, **expense_account**, department, site | restore prior | | | `bill.line_save` | ap |
| `bill_line_remove` | `/bills/line-remove.php` | **bill** (a draft), **line** | restore | | | `bill.line_remove` | ap |
| `bill_approve` | `/bills/approve.php` | **bill** (a draft with lines; posted) | — (void it) | ✔ | money_out | `bill.approve` | approve |
| `bill_void` | `/bills/void.php` | **bill** (nothing applied), **reason** | — | ✔ | money_out | `bill.void` | override |
| `bill_delete` | `/bills/delete.php` | **bill** (a draft) | — | ✔ | deletion | `bill.delete` | ap |
| `vendor_credit_draft` | `/vendor-credits/save.php` | **vendor**, bill (the bill it is against), vendor_ref, credit_date, reason, department, site, lines (as a bill's) | vendor_credit_delete | | | `vendor_credit.draft` | ap |
| `vendor_credit_update` | `/vendor-credits/save.php` | **vendor_credit** (a draft), any field of vendor_credit_draft | restore prior | | | `vendor_credit.update` | ap |
| `vendor_credit_line_save` | `/vendor-credits/line-save.php` | **vendor_credit** (a draft), line, **description**, **quantity**, **unit_price**, tax_rate, **expense_account**, department, site | restore prior | | | `vendor_credit.line_save` | ap |
| `vendor_credit_line_remove` | `/vendor-credits/line-remove.php` | **vendor_credit** (a draft), **line** | restore | | | `vendor_credit.line_remove` | ap |
| `vendor_credit_issue` | `/vendor-credits/issue.php` | **vendor_credit** (a draft; posted), apply_to (a bill to apply it to at once) | — | ✔ | money_out | `vendor_credit.issue` | approve |
| `vendor_credit_apply` | `/vendor-credits/apply.php` | **vendor_credit** (issued), **bill**, amount (empty applies what fits) | vendor_credit_unapply | ✔ | money_out | `vendor_credit.apply` | ap |
| `vendor_credit_unapply` | `/vendor-credits/unapply.php` | **vendor_credit**, **bill** | vendor_credit_apply | ✔ | money_out | `vendor_credit.unapply` | ap |
| `vendor_credit_void` | `/vendor-credits/void.php` | **vendor_credit** (issued and unapplied), **reason** | — | ✔ | money_out | `vendor_credit.void` | override |
| `vendor_credit_delete` | `/vendor-credits/delete.php` | **vendor_credit** (a draft) | — | ✔ | deletion | `vendor_credit.delete` | ap |
| `payment_draft` | `/payments/save.php` | **vendor**, **amount**, payment_date, **method** (cheque or ach or card or cash or other), cheque_number, **bank_account**, reference, memo, bills[] (apply it to these bills oldest first), payment_run (a draft run to join) | payment_delete | | | `payment.draft` | ap |
| `payment_update` | `/payments/save.php` | **payment** (a draft), any field of payment_draft | restore prior | | | `payment.update` | ap |
| `payment_apply` | `/payments/apply.php` | **payment** (a draft or approved), **bill**, amount (empty applies what fits) | payment_unapply | | | `payment.apply` | ap |
| `payment_unapply` | `/payments/unapply.php` | **payment** (a draft or approved), **bill** | payment_apply | | | `payment.unapply` | ap |
| `payment_approve` | `/payments/approve.php` | **payment** (a draft applied to a bill) | payment_void | ✔ | money_out | `payment.approve` | approve |
| `payment_record` | `/payments/record.php` | **payment** (approved; the money was moved by a person; posted), payment_date (the day it moved), cheque_number, reference | — (void it) | ✔ | money_out | `payment.record` | ap |
| `payment_void` | `/payments/void.php` | **payment** (unmatched), **reason** | — | ✔ | money_out | `payment.void` | override |
| `payment_delete` | `/payments/delete.php` | **payment** (a draft) | — | ✔ | deletion | `payment.delete` | ap |
| `payment_run_create` | `/payment-runs/save.php` | **bank_account**, pay_date, memo, bills[] (one payment per vendor for these bills), due_by (or every approved bill due by this date) | payment_run_void | | | `payment_run.create` | ap |
| `payment_run_add` | `/payment-runs/add.php` | **payment_run** (a draft), **payment** (a draft of the same bank account and currency) | payment_run_remove | | | `payment_run.add` | ap |
| `payment_run_remove` | `/payment-runs/remove.php` | **payment_run** (a draft), **payment** | payment_run_add | | | `payment_run.remove` | ap |
| `payment_run_approve` | `/payment-runs/approve.php` | **payment_run** (a draft with payments; every one approved) | payment_run_void | ✔ | money_out | `payment_run.approve` | approve |
| `payment_run_record` | `/payment-runs/record.php` | **payment_run** (approved; every payment recorded as moved and posted), pay_date | — | ✔ | money_out | `payment_run.record` | ap |
| `payment_run_void` | `/payment-runs/void.php` | **payment_run** (its unrecorded payments voided), **reason** | — | ✔ | money_out | `payment_run.void` | override |

## Cash (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `banks` | `/cash/` | the bank accounts as cards: ledger balance, cleared balance, difference, unmatched lines, last reconciled |
| `bank-account-view` | `/cash/banks/{id}` | one bank account: its lines by status and date, its reconciliations, the ledger activity (params: `status`, `from`, `to`) |
| `bank-import` | `/cash/import` | to import a statement file (CSV) into a bank account: column mapping, date format, a five-row preview, duplicates counted (params: `bank_account`) |
| `bank-match` | `/cash/match` | the unmatched lines of a bank account, each with its candidates, one click to match, an entry for a fee, ignore (params: `bank_account`, `from`, `to`) |
| `bank-reconcile` | `/cash/reconcile` | to reconcile a bank account against a statement balance: start, the lines through the date, the difference live, complete (params: `bank_account`, `reconciliation`) |
| `reconciliation-view` | `/cash/reconciliations/{id}` | one reconciliation: its figures, its lines, who completed it, reopen |
| `transfers-list` | `/cash/transfers/` | the transfers between bank accounts (params: `bank_account`) |
| `transfer-add` | `/cash/transfers/new` | to record a transfer (params: `from`, `to`) |
| `transfer-view` | `/cash/transfers/{id}` | one transfer: its entry, the bank lines it matched |
| `bank-settings` | `/settings/banks/` | the bank accounts' settings |
| `bank-account-add` | `/settings/banks/new` | to add a bank account over a ledger account |
| `bank-account-edit` | `/settings/banks/{id}/edit` | to change a bank account |

Actions (base `/cash/`, bank settings under `/settings/banks/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `bank_account_save` | `/settings/banks/save.php` | bank_account (to change an existing one), **name**, kind (checking or savings or credit_card or cash or other), **account** (a ledger account flagged bank), institution, account_last4, currency (three letters), opening_balance, opening_date | restore prior | | other | `bank_account.save` | admin |
| `bank_account_archive` | `/settings/banks/archive.php` | **bank_account** (no unmatched line and no reconciliation in progress), archived (yes or no) | restore | ✔ | other | `bank_account.archive` | admin |
| `bank_import` | `/cash/import.php` | **bank_account**, **file** (multipart CSV), date_format (ymd or dmy or mdy), columns (date and amount and description and reference as JSON or empty to detect), amount_sign (signed or debit_credit) | bank_import_delete | | | `bank.import` | bank |
| `bank_import_delete` | `/cash/import-delete.php` | **import** (its unmatched lines removed) | — | ✔ | deletion | `bank.import_delete` | bank |
| `bank_line_add` | `/cash/line.php` | **bank_account**, **txn_date**, **amount** (signed), **description**, reference | — | | | `bank.line_add` | bank |
| `bank_transaction_match` | `/cash/match.php` | **bank_line**, **item_type** (receipt or deposit or payment or transfer or journal_entry), **item** (the item's id) | bank_transaction_unmatch | | | `bank.match` | bank |
| `bank_transaction_unmatch` | `/cash/unmatch.php` | **bank_line** (not reconciled) | bank_transaction_match | | | `bank.unmatch` | bank |
| `bank_transaction_ignore` | `/cash/ignore.php` | **bank_line**, **reason** | bank_transaction_unmatch | ✔ | | `bank.ignore` | bank |
| `bank_entry_from_line` | `/cash/entry.php` | **bank_line**, **account** (the fee or income account), memo, department, site | — | ✔ | money_out | `bank.entry_from_line` | journal |
| `reconciliation_start` | `/cash/reconcile-start.php` | **bank_account**, **statement_date**, **statement_balance** | — | | | `reconciliation.start` | bank |
| `reconciliation_complete` | `/cash/reconcile-complete.php` | **reconciliation** (at zero difference), note | reconciliation_reopen | ✔ | other | `reconciliation.complete` | reconcile |
| `reconciliation_reopen` | `/cash/reconcile-reopen.php` | **reconciliation** (the latest completed), **reason** | — | ✔ | other | `reconciliation.reopen` | reconcile |
| `transfer_record` | `/cash/transfers/save.php` | **from_bank_account**, **to_bank_account**, **amount**, transfer_date, memo | transfer_void | ✔ | money_out | `transfer.record` | bank |
| `transfer_void` | `/cash/transfers/void.php` | **transfer** (unmatched), **reason** | — | ✔ | money_out | `transfer.void` | override |

## The kernel's AI statement (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `ai-statements` | `/ap/ai/` | the months as the kernel reports them: open, closed, pulled, imported, agrees, differs; pull now |
| `ai-statement-view` | `/ap/ai/{id}` | one month: its lines by provider and department, its bills, the agree/differ check, import |

Actions (base `/ap/ai/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `ai_statement_pull` | `pull.php` | period (a month like 2026-09 or empty for every month the kernel lists) | — | | | `ai_statement.pull` | ap |
| `ai_statement_import` | `import.php` | **statement** (a closed month not yet imported; one draft bill per provider) | — | ✔ | | `ai_statement.import` | ap |
| `ai_statement_check` | `check.php` | **statement** (imported; the bills against the lines) | — | | | `ai_statement.check` | view |

## Recurring (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `recurring-list` | `/settings/recurring/` | the templates as cards: kind, cadence, next date, last run, on or off |
| `recurring-add` | `/settings/recurring/new` | to make a template from an entry, a bill or an invoice (params: `kind`, `from`) |
| `recurring-edit` | `/settings/recurring/{id}/edit` | to change a template |
| `recurring-view` | `/settings/recurring/{id}` | one template: what it drafts, its runs |

Actions (base `/settings/recurring/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `recurring_save` | `save.php` | template (to change an existing one), **name**, **kind** (journal or bill or invoice), **cadence** (weekly or monthly or quarterly or yearly), every_n (1 to 24), **next_date**, end_date, template (the document to draft as JSON), active (yes or no) | restore prior | | other | `recurring.save` | admin |
| `recurring_toggle` | `toggle.php` | **template**, active (yes or no) | restore prior | | other | `recurring.toggle` | admin |
| `recurring_run` | `run.php` | **template** (drafted now for its next date), run_date | — | | | `recurring.run` | journal |
| `recurring_delete` | `delete.php` | **template** | — | ✔ | deletion | `recurring.delete` | admin |

## Public door (no session; not an action)

| Screen id | URL | What happens there |
| --- | --- | --- |
| `secure-invoice` | `/i/{token}` | a customer's invoice: the document, what was received, what is owed, print; "I paid this on … by …" (`/i/{token}/claim`, CSRF, a rate limit, logged `claim.submit` with `actor_customer_id`, `source = portal`); a dead link shows one page |

## What is not an action

Reading a screen (`screen.view`), a statement or report export (a GET with `format=`, logged `export.download`), the worker's
passes (`worker.pass`: the outbox `notification.send`, the AI statement poll `ai_statement.pull` with `source = cron`, recurring
templates falling due `recurring.run`, reminders drafted `invoice.remind_due`, secure links expiring `invoice.link_expired`,
dispatch retries), the directory sync (`directory.sync`) and sign-on (`member.sign_on`) are events, not actions: nobody asks
for them.
