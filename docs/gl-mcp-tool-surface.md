# General Ledger — MCP tool surface

2026-10-05 · **Phase 1, for the checkpoint.** The read tools every question in `docs/gl-design.md` §7 (GL1–GL14, AR1–AR10,
AP1–AP8, AI1–AI4, CM1–CM5, ACT1–ACT3) is answered by. Writes are never here: they are the action tools the kernel's
Actions MCP builds from `docs/gl-action-manifest.md`.

Conventions (mcp-and-api.md): Python 3 + FastMCP, streamable HTTP at `/mcp`, loopback, one systemd unit each; `db.py` and
`server_common.py` copied from Help Desk (already in `mcp/`); every query runs with `app.member_id` set transaction-locally
from the verified bearer, so the `mcp_*` views (db/016) decide the rows and the statement functions (db/015) answer only a
caller with `reports.view`. Tool names are snake_case; every tool takes one `params` object; every list tool takes `limit`
(1–100, default 25); every tool carries `readOnlyHint: true`, `openWorldHint: false`; results are JSON strings. Money is a
string with two decimals and its currency beside it; dates are `YYYY-MM-DD`; a period is `from`/`to` or a name (`2026-09`,
`FY2026`, `this_month`, `last_month`, `this_year`, `last_year`).

Gates are what the view already enforces — the tool only trims. Words used below (db/004, db/016, the design §3):

| Word | Means |
|---|---|
| `view` | `reports.view` — every role: the statements, the chart, the periods, the agings, the AI statement, the cash position |
| `docs` | `documents.view` — every role: the journal, invoices, bills, receipts, payments, deposits, bank lines |
| `parties` | `parties.view` — every role: customers and vendors by name, terms and balance |
| `ar` / `ap` | `ar.write` / `ap.write` — a customer's or a vendor's contact details and notes |
| `own` | the caller's own notifications and tokens |
| `member` | any admitted member: names, departments, sites, the settings' public facts |

**The books are business-wide** (design §16, decision 1): a holder of a role sees every row the role admits; departments and
sites are filters a tool accepts, never walls. **A customer by the secure link is not a caller here**: PHP serves them from
base tables through `gl_secure_link_invoice()`.

Two token shapes, one key: a person's own `mcp_` token (`mcp_resolve_token`), or the tenant's signed run token
(`ACTION_TOKEN_KEY`), for which the server asks the kernel's run-facts call once per run and offers exactly the tools
granted — fail closed; no tool acts when `is_eval`. The kernel's own token reaches `app_roles` and the three `shares[]`
tools (`account_balances`, `ap_due_summary`, `ar_aging_summary`) and nothing else.

## Records server — `gl_records_mcp` (role `gl_records_ro`, `MCP_RECORDS_PORT`)

### Roles (kernel db/145)
`app_roles` — no arguments — answers `os.app-roles/1`: the five roles (key, name, description, capability, is_admin,
rights[]) and the fourteen rights, from `mcp_app_roles` (db/004).

### People and dimensions
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_members` | Looking up a person or an agent by name — who approved, who to send the morning note to, an employee who is a vendor. `q` alone answers a plain list (`member_id`, `display_name`) | ACT2 | `mcp_members` | `q?`, `kind?` (human, agent), `limit` | member (names, kind, roles); no email but one's own |
| `find_departments` | The departments (the first dimension) by name, with the live ones first. `q` alone resolves | GL1 | `mcp_departments` | `q?`, `include_archived?` | member |
| `find_sites` | The sites (the second dimension) by name, with the kernel location when pinned. `q` alone resolves | GL1 | `mcp_sites` | `q?`, `include_archived?` | member |
| `gl_settings` | The ledger's public facts: business name, base currency, fiscal year start, the control accounts by code, terms, the link's life, reminder days, numbering prefixes | GL6 | `mcp_settings`, `mcp_accounts` | — | member |

### The chart, the rules, fiscal time
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_accounts` | The chart, or one account: by code, name or words, by type, bank or control flags, archived or not; each with its balance as of a date in its normal sense. **Call before naming an account on a line.** `q` alone answers a plain list (`account_id`, `label` = "6200 — Software and subscriptions") | GL6 | `mcp_accounts`, `gl_account_balance()` | `q?`, `account_type?`, `subtype?`, `is_bank?`, `is_control?`, `include_archived?`, `as_of?`, `limit` | view |
| `account_balance` | One account's balance as of a date, optionally for one department or site | GL5 | `gl_account_balance()` | `account_id` or `code`, `as_of?`, `department_id?`, `site_id?` | view |
| `account_activity` | One account's lines over a period with a running balance: date, entry, source, memo, debit, credit, the party, the dimensions | GL5 | `gl_account_activity()` | `account_id` or `code`, `from`, `to`, `department_id?`, `site_id?`, `limit` (default 100) | view |
| `posting_rules` | Which source posts to which accounts (debit, credit, tax), the keyed overrides, active or not | GL13 | `mcp_posting_rules` | `source?` | view |
| `find_periods` | The fiscal years and their periods: open, closed, reopened (by whom, why, how many times), posted and draft counts; the period a date falls in. `q` alone resolves a period (`2026-09`) or a year (`FY2026`) | GL10, GL14 | `mcp_fiscal_years`, `mcp_fiscal_periods` | `q?`, `fiscal_year_id?`, `status?`, `date?`, `limit` | view |

### The statements
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `trial_balance` | Every account with a balance as of a date, debit and credit columns, and the totals — which must be equal; the tool says `balanced: true/false` | GL3 | `gl_trial_balance()` | `as_of?` | view |
| `profit_and_loss` | Income and expenses for a period by account, with net income; `compare` adds the prior period and the variance; by department or site | GL1, GL7, AP3 | `gl_profit_and_loss()`, `gl_net_income()` | `period` or `from`+`to`, `compare?` (previous, last_year), `department_id?`, `site_id?` | view |
| `balance_sheet` | Assets, liabilities and equity as of a date, current earnings not yet closed, and the equation's check (`difference` must be 0) | GL2, GL7 | `gl_balance_sheet()`, `gl_balance_sheet_check()` | `as_of?`, `compare?` | view |
| `cash_flow` | The indirect cash flow statement for a period: net income, the changes by section, net change in cash, cash at start and end, the check | GL4 | `gl_cash_flow()` | `period` or `from`+`to` | view |
| `control_check` | The subledger against the ledger: receivables per the invoices vs the receivable account, payables per the bills vs the payable account, each with its difference | GL11 | `gl_control_check()` | `as_of?` | view |

### The journal
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_journal_entries` | Entries by number, words in the memo, period, date range, status, source, account, party or dimension; each with totals, period and its reversal link. **`q` alone answers a plain list** (`journal_entry_id`, `label` = "JE-000123 — memo") | GL8, GL9, GL11 | `mcp_journal_entries`, `mcp_journal_lines` | `q?`, `period_id?`, `from?`, `to?`, `status?[]`, `source?[]`, `account_id?`, `customer_id?`, `vendor_id?`, `department_id?`, `site_id?`, `source_type?`+`source_id?`, `sort?`, `limit` | docs |
| `get_journal_entry` | One entry in full: head, lines with account codes and names and parties, the source document it came from (type, id, number), what it reverses or what reversed it, attachments, notes | GL9 | `mcp_journal_entries`, `mcp_journal_lines`, `mcp_attachments`, `mcp_notes` | `journal_entry_id` or `number` | docs |
| `unposted_entries` | What waits: draft entries (with whether each balances and its period's state), draft invoices and bills, bills and payments awaiting approval, unmatched bank lines, open claims, undeposited receipts, posted-through. **The Bookkeeper's morning starts here** | GL8, GL14 | `gl_unposted_summary()`, `gl_posted_through()`, `mcp_journal_entries` | `detail?` (counts only, or the draft entries listed), `limit` | docs |

### Receivables
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_customers` | Customers by name or words, with balance due, open invoices, last invoiced; contact details to `ar`. **Call before `invoice_draft`.** `q` alone answers a plain list (`customer_id`, `name`) | AR1, AR5 | `mcp_customers` | `q?`, `with_balance?`, `include_archived?`, `limit` | parties; details: ar |
| `get_customer` | One customer in full: terms, currency, accounts, contacts (`ar`), open invoices, recent receipts and credits, open claims, notes (`ar`), attachments | AR5 | `mcp_customers`, `mcp_party_contacts`, `mcp_invoices`, `mcp_receipts`, `mcp_credit_notes`, `mcp_receipt_claims`, `mcp_notes` | `customer_id` | parties; details: ar |
| `customer_statement` | A customer's statement for a period: opening balance, each invoice, receipt and credit in date order, the running balance, the closing balance — per currency | AR5 | `mcp_invoices`, `mcp_receipt_applications`, `mcp_credit_applications`, `mcp_receipts` | `customer_id`, `from`, `to` | docs |
| `find_invoices` | Invoices by number, customer, status, overdue, date range, department or site, words in the reference or memo; each with totals, paid, credited, balance, days overdue, open claims, whether its link is live. **`q` alone answers a plain list** (`invoice_id`, `label` = "INV-00042 — Acme — 1,608.24 USD") | AR2, AR3 | `mcp_invoices` | `q?`, `customer_id?`, `status?[]`, `overdue?`, `from?`, `to?`, `department_id?`, `site_id?`, `sort?`, `limit` | docs |
| `get_invoice` | One invoice in full: lines, the receipts and credits applied with dates, claims, the entry it posted (number), the write-off, the link's state (live, rotated, expires — never the token), reminders, attachments, notes | AR2, AR9 | `mcp_invoices`, `mcp_invoice_lines`, `mcp_receipt_applications`, `mcp_credit_applications`, `mcp_receipt_claims`, `mcp_attachments`, `mcp_notes` | `invoice_id` or `number` | docs |
| `ar_aging` | Who owes us as of a date, per customer and currency, in the five buckets, with open invoice counts; or one customer's open invoices bucketed | AR1 | `gl_ar_aging()`, `mcp_invoices` | `as_of?`, `customer_id?`, `currency?`, `limit` | view |
| `find_receipts` | Money received: by customer, date range, method, unapplied (money not yet against an invoice), undeposited (not yet in a deposit), with each receipt's applications and deposit | AR4 | `mcp_receipts`, `mcp_receipt_applications` | `q?`, `customer_id?`, `from?`, `to?`, `method?`, `unapplied?`, `undeposited?`, `limit` | docs |
| `find_deposits` | Deposits by bank account, status and date, with their receipts and the bank line matched | AR4, CM4 | `mcp_deposits`, `mcp_receipts`, `mcp_bank_transactions` | `q?`, `bank_account_id?`, `status?`, `from?`, `to?`, `limit` | docs |
| `find_credit_notes` | Credit notes by customer, status, the invoice they were raised against, with where they are applied | AR3 | `mcp_credit_notes`, `mcp_credit_applications` | `q?`, `customer_id?`, `status?`, `invoice_id?`, `limit` | docs |
| `open_claims` | What customers said on the link that is not yet confirmed: invoice, customer, claimed date, method, reference, amount | AR6 | `mcp_receipt_claims` | `status?` (submitted by default), `customer_id?`, `limit` | docs |
| `sales_tax` | Tax charged in a period by rate: taxable sales, tax, from issued invoices less issued credit notes | AR7 | `mcp_invoice_lines`, `mcp_credit_note_lines`, `mcp_invoices`, `mcp_credit_notes` | `from`, `to` | view |
| `days_to_pay` | Average and median days from issue to paid, overall and per customer, for invoices paid in a period | AR8 | `mcp_invoices` | `from?`, `to?`, `customer_id?`, `limit` | view |

### Payables
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_vendors` | Vendors by name or words, by kind (vendor, AI provider, employee, tax authority), with balance due, open bills, last billed; contact details to `ap`. **Call before `bill_draft`.** `q` alone answers a plain list (`vendor_id`, `name`) | AP1, AP4 | `mcp_vendors` | `q?`, `kind?`, `with_balance?`, `include_archived?`, `limit` | parties; details: ap |
| `get_vendor` | One vendor in full: terms, accounts, contacts (`ap`), open bills, recent payments and credits, notes (`ap`), attachments | AP1 | `mcp_vendors`, `mcp_party_contacts`, `mcp_bills`, `mcp_payments`, `mcp_vendor_credits`, `mcp_notes` | `vendor_id` | parties; details: ap |
| `find_bills` | Bills by number, vendor, the vendor's reference, status, due within N days, overdue, source (manual, AI usage, recurring), without a document, date range; each with totals, paid, credited, balance, days overdue. **`q` alone answers a plain list** (`bill_id`, `label` = "BILL-000007 — Hosting Co H-2026-04 — 259.80 USD") | AP1, AP2, AP6 | `mcp_bills` | `q?`, `vendor_id?`, `status?[]`, `due_within_days?`, `overdue?`, `source?`, `no_document?`, `from?`, `to?`, `department_id?`, `site_id?`, `sort?`, `limit` | docs |
| `get_bill` | One bill in full: lines with accounts and dimensions, the document attached, payments and credits applied, the entry it posted, who approved it, notes | AP5 | `mcp_bills`, `mcp_bill_lines`, `mcp_payment_applications`, `mcp_vendor_credit_applications`, `mcp_attachments`, `mcp_notes` | `bill_id` or `number` | docs |
| `ap_aging` | Whom we owe as of a date, per vendor and currency, in the five buckets, with open bill counts | AP1 | `gl_ap_aging()` | `as_of?`, `vendor_id?`, `currency?`, `limit` | view |
| `ap_due` | What is due: approved and partly paid bills by due date within N days (this week by default), bills awaiting approval, payments awaiting approval, draft runs — the controller's desk | AP1, AP2 | `mcp_bills`, `mcp_payments`, `mcp_payment_runs` | `due_within_days?` (7), `vendor_id?`, `limit` | docs |
| `spend` | What we spent in a period by account, vendor, department or site — from approved bills' lines (and bank-fee entries) | AP3, AP4 | `mcp_bill_lines`, `mcp_bills`, `gl_profit_and_loss()` | `from`, `to`, `group_by` (account, vendor, department, site), `limit` | view |
| `find_payments` | Money out: by vendor, status, bank account, method, date range, run; each with its applications, its entry, the bank line matched | AP5 | `mcp_payments`, `mcp_payment_applications`, `mcp_bank_transactions` | `q?`, `vendor_id?`, `status?[]`, `bank_account_id?`, `method?`, `from?`, `to?`, `payment_run_id?`, `limit` | docs |
| `find_payment_runs` | The runs by status and date, each with its payments, who approved, who recorded | AP5 | `mcp_payment_runs`, `mcp_payments` | `q?`, `status?`, `bank_account_id?`, `from?`, `to?`, `limit` | docs |
| `find_vendor_credits` | Vendor credits by vendor, status, the bill they are against, with where they are applied | AP1 | `mcp_vendor_credits`, `mcp_vendor_credit_applications` | `q?`, `vendor_id?`, `status?`, `bill_id?`, `limit` | docs |

### The kernel's AI statement
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `ai_statement_status` | The months as the kernel reports them and what the ledger holds of each: open or closed in the OS, pulled, imported, agrees or differs, totals per currency, late calls, the bills made. `q` alone resolves a month | AI1, AI3, AI4 | `mcp_ai_statements`, `mcp_ai_statement_bills` | `q?`, `period?`, `status?`, `limit` | view |
| `ai_statement_lines` | One month's lines verbatim: provider, model, department, agent, application, calls, late calls, tokens, amount — grouped as asked | AI1, AI4 | `mcp_ai_statement_lines` | `ai_statement_id` or `period`, `group_by?` (provider, department, agent, model), `limit` | view |
| `ai_statement_check` | AI2: the bills against the statement, provider by provider — statement amount, bill amount, difference, bill status, agrees | AI2 | `gl_ai_statement_check()` | `ai_statement_id` or `period` | view |

### Cash
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `cash_position` | Every bank account's ledger balance, cleared balance, last reconciled through and balance, unmatched lines — and the total per currency; `trend` adds month-end ledger balances for the last twelve months | CM1, CM5 | `gl_cash_position()`, `gl_bank_ledger_balance()` | `as_of?`, `trend?` | view |
| `find_bank_accounts` | The bank accounts by name or kind, with their ledger account, currency, balances, reconciliation in progress. `q` alone resolves | CM1 | `mcp_bank_accounts` | `q?`, `kind?`, `include_archived?` | docs |
| `unmatched_bank_lines` | A bank account's (or every account's) unmatched lines oldest first: date, amount, description, reference, import; `q` alone resolves a line by its reference or description | CM2 | `mcp_bank_transactions` | `q?`, `bank_account_id?`, `from?`, `to?`, `status?` (unmatched by default), `limit` | docs |
| `match_candidates` | For one unmatched line, the ledger items it could be: type, id, number, date, amount, description, days apart — exactly the function's rule (to the cent, within five days, not yet matched) | CM2 | `gl_bank_candidates()` | `bank_transaction_id` | docs |
| `in_transit` | What the ledger holds that the bank has not shown: receipts not deposited, deposits and payments and transfers recorded but not matched, entries on a bank account not matched | CM4 | `mcp_receipts`, `mcp_deposits`, `mcp_payments`, `mcp_transfers`, `mcp_bank_transactions` | `bank_account_id?`, `as_of?`, `limit` | docs |
| `find_reconciliations` | A bank account's reconciliations: statement date and balance, cleared, difference, status, who completed, reopened and why; the one in progress first. `q` alone resolves | CM3 | `mcp_bank_reconciliations` | `q?`, `bank_account_id?`, `status?`, `limit` | docs |
| `find_transfers` | Transfers between bank accounts by account, date range, status | CM4 | `mcp_transfers` | `q?`, `bank_account_id?`, `from?`, `to?`, `limit` | docs |

### Settings, recurring, me
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_items` | The products and services by name or code, with accounts, price and tax. **Call before an invoice or bill line names an item.** `q` alone resolves | — | `mcp_items` | `q?`, `kind?`, `include_archived?`, `limit` | docs |
| `find_tax_rates` | The tax rates and the default. `q` alone resolves | AR7 | `mcp_tax_rates` | `q?`, `include_archived?` | docs |
| `find_recurring` | The recurring templates: kind, cadence, next date, last run, active; one with its runs. `q` alone resolves | AP7 | `mcp_recurring_templates`, `mcp_recurring_runs` | `q?`, `kind?`, `active?`, `recurring_template_id?`, `limit` | docs |
| `recurring_due` | The templates whose next date is on or before a date (today by default) — what the morning should draft | AP7 | `mcp_recurring_templates` | `as_of?` | docs |
| `agent_dispatches` | What was handed to which agent — when, by whom, how (chat, wake, duty), the run and what came back | ACT2 | `mcp_agent_dispatches` | `agent_member_id?`, `record_type?`+`record_id?`, `since?`, `limit` | view |
| `my_notifications` | The caller's own in-app notifications, unread first | — | `mcp_notifications` | `unread_only?`, `limit` | own |
| `my_tokens` | The caller's own MCP tokens: label, scope, last used, expiry — never the value | — | `mcp_my_tokens` | — | own |
| `records_search` | The long tail: one SELECT over the `mcp_*` views and the statement functions (the docstring lists them), 5 s, 200 rows; refuses writes, a second statement, `set_config`, `current_setting`, `mcp_admit_agent`, `mcp_resolve_token`, every `gl_*` writer (the deny list in `mcp/db.py`), `next_document_number`, `pg_*`, `dblink`, large objects | the long tail | every `mcp_*` view; `gl_*` readers | `sql`, `limit` | the views' own rules |

### The shares (K7 — the kernel's own token only, `maludb-os.json` `shares[]`)
| Tool | Answers | Reads | Key params |
|---|---|---|---|
| `account_balances` | Ledger balances by account (code, name, type, balance) as of a date — for another application's dashboard | `gl_trial_balance()` as the application's own reader | `as_of?` |
| `ap_due_summary` | Amounts due to vendors per week for the next eight weeks, totals only, no vendor named | `mcp_bills` | `weeks?` |
| `ar_aging_summary` | Receivables by aging bucket, totals only, no customer named | `gl_ar_aging()` | `as_of?` |

The shares run as the application's own reader (a service member row the installer makes, `member_kind = agent`, roles `{user}`),
answer no person and no party, and refuse any other caller.

**Resolve mode.** The kernel's entity resolver (`mcp/application_actions.py`) sends only `q` and reads a plain list of rows
carrying the id and label field: `find_accounts`, `find_customers`, `find_vendors`, `find_invoices`, `find_bills`,
`find_journal_entries`, `find_receipts`, `find_payments`, `find_deposits`, `find_payment_runs`, `find_credit_notes`,
`find_vendor_credits`, `find_bank_accounts`, `unmatched_bank_lines`, `find_periods`, `find_items`, `find_tax_rates`,
`ai_statement_status`, `find_reconciliations`, `find_recurring`, `find_members`, `find_departments`, `find_sites` answer that
shape when given `q` alone. Names match at the start of a word; a document number matches whole.

## Activity server — `gl_activity_mcp` (role `gl_activity_ro`, `MCP_ACTIVITY_PORT`)

One view, `mcp_activity_log` (one's own rows; every row to `reports.view`), plus names from `mcp_members`, `mcp_departments`
and document facts from `mcp_journal_entries`, `mcp_invoices`, `mcp_bills`.

| Tool | Call it when | Answers | Key params | Gate |
|---|---|---|---|---|
| `record_history` | A record's timeline in words and facts: drafted, lines changed, posted (with the amount), reversed (by what, why), issued, sent, viewed, reminded, received against, written off, approved, recorded, matched, reconciled, attached, noted — newest or oldest first | ACT1, AR9, AP5, GL9, GL11 | `record_type`, `record_id` (or `number`), `order?`, `limit` (default 100) | own rows; view |
| `actor_timeline` | What a person or an agent did over a period — and, for `actor_kind = agent`, what every agent drafted, imported, matched or asked to post; money events with their amounts | ACT2, AR10, AP8 | `member_id?` (default: the caller) or `actor_kind?` (human, agent), `from?`, `to?`, `action?`, `limit` | own; others: view |
| `who_touched` | Who last touched a record and what they changed; or everyone who touched it, with the first and last time | ACT1 | `record_type`, `record_id`, `limit` | view |
| `period_activity` | Everything that happened to a period: its close, reopens (who, why), every posting into it after a reopen, reversals dated in it — the audit page's rows | GL10, GL12 | `period_id` or `name`, `limit` | view |
| `config_change_log` | What configuration changed in a period and by whom: accounts, posting rules, tax rates, items, bank accounts, numbering, recurring templates, settings — before and after | GL13 | `from?`, `to?`, `entity_type?`, `limit` | view |
| `changes_since` | What has changed in the books since a time: the actions by kind with counts and the records touched (the "what happened since I last looked" answer) | ACT3 | `since`, `kinds?[]`, `limit` | own; view |
| `activity_search` | The long tail: one SELECT over `mcp_activity_log` (and the fact views), 5 s, 200 rows, the same refusals as `records_search` | — | `sql`, `limit` | the view's own rule |

## Coverage of the question inventory (§7 → tools)

GL1 `profit_and_loss` · GL2 `balance_sheet` · GL3 `trial_balance` · GL4 `cash_flow` · GL5 `account_balance` + `account_activity` ·
GL6 `find_accounts` + `gl_settings` · GL7 `profit_and_loss(compare)` + `balance_sheet(compare)` · GL8 `unposted_entries` ·
GL9 `get_journal_entry` + `find_journal_entries(source_type, source_id)` + `record_history` · GL10 `find_periods` + `period_activity` ·
GL11 `find_journal_entries(source=reversal)` + `record_history` · GL12 `period_activity` (reopens, posts after a reopen; an edit of a
posted entry cannot exist — the database refuses it) · GL13 `posting_rules` + `config_change_log` · GL14 `unposted_entries` ·
AR1 `ar_aging` · AR2 `find_invoices` + `get_invoice` · AR3 `find_invoices` + `find_credit_notes` · AR4 `find_receipts` + `find_deposits` ·
AR5 `customer_statement` + `get_customer` · AR6 `open_claims` · AR7 `sales_tax` · AR8 `days_to_pay` · AR9 `record_history(invoice)` ·
AR10 `actor_timeline(actor_kind=agent)` · AP1 `ap_aging` + `ap_due` · AP2 `ap_due` + `find_bills(status)` · AP3 `spend` ·
AP4 `spend(group_by=vendor)` · AP5 `find_payment_runs` + `record_history` · AP6 `find_bills(no_document)` · AP7 `find_recurring` +
`recurring_due` · AP8 `actor_timeline(actor_kind=agent)` · AI1 `ai_statement_status` + `ai_statement_lines` + `spend(account=6050)` ·
AI2 `ai_statement_check` · AI3 `ai_statement_status` · AI4 `ai_statement_lines` · CM1 `cash_position` · CM2 `unmatched_bank_lines` +
`match_candidates` · CM3 `find_reconciliations` + `record_history` · CM4 `in_transit` · CM5 `cash_position(trend)` · ACT1 `record_history` +
`who_touched` · ACT2 `actor_timeline` · ACT3 `changes_since` · the long tail: `records_search`, `activity_search`; the kernel: `app_roles`
and the three shares.

## What the log must carry for the activity tools (the manifest's log-payload rules)

Every row carries the audit keys it concerns: `journal_entry_id` (any event of an entry, and of a document when it posts),
`period_id` (a period's close or reopen; a post into a reopened period carries it too), `customer_id`, `vendor_id`,
`bank_account_id`, `department_id`, `site_id`. **Every money event carries `after.amount` and `after.currency`.**

`journal_entry.draft` — `after.entry_date`, `after.source`, `after.lines` (count) · `journal_entry.line_save` / `.line_remove` —
`after.account_code`, `after.debit`/`credit`, `before` of a changed line · `journal_entry.post` — `after.number`, `after.amount`
(the total), `after.currency`, `after.period`, `after.reopened_period` (true when the period had been reopened — GL12) ·
`journal_entry.reverse` — `after.reversal_id`, `after.reversal_number`, `after.reason`, `after.amount` · `journal_entry.void` —
`after.reason` · `journal_entry.post_batch` — `after.entries[]`, `after.posted`, `after.refused[]` with sentences ·
`period.close` / `.reopen` — `after.name`, `after.reason`, `after.reopen_count` · `fiscal_year.close` — `after.closing_entry_number`,
`after.amount` (net), `after.currency` · `invoice.issue` — `after.number`, `after.amount`, `after.currency`, `after.journal_entry_number` ·
`invoice.send` / `.remind` — `after.to` (count), `after.reminder_count`; never the address · `invoice.view` (the link opened, `source =
portal`) — `after.view_count` · `invoice.void` / `.write_off` — `after.reason`, `after.amount` · `credit_note.issue` / `.apply` /
`.unapply` — `after.invoice_number`, `after.amount` · `receipt.record` — `after.number`, `after.amount`, `after.method`, `after.deposit_to`,
`after.applied[]` (invoice numbers and amounts), `after.claim_id` · `receipt.apply` / `.unapply` — `after.invoice_number`, `after.amount` ·
`receipt.void` — `after.reason`, `after.amount` · `claim.submit` (portal) — `after.invoice_number`, `after.amount`, `after.method`; `claim.confirm`
/ `.dismiss` — `after.receipt_number`, `after.reason` · `deposit.record` / `.void` — `after.number`, `after.amount`, `after.receipts` (count),
`after.bank_account_id` · `bill.draft` — `after.number`, `after.vendor_ref`, `after.source` · `bill.approve` — `after.number`, `after.amount`,
`after.currency`, `after.journal_entry_number`, `after.has_document` · `bill.void` — `after.reason`, `after.amount` · `payment.draft` /
`.approve` / `.record` / `.void` — `after.number`, `after.amount`, `after.method`, `after.cheque_number`, `after.bank_account_id`,
`after.applied[]` · `payment_run.create` / `.approve` / `.record` / `.void` — `after.number`, `after.amount`, `after.payments` (count) ·
`transfer.record` / `.void` — `after.number`, `after.amount`, `after.from_bank_account_id`, `after.to_bank_account_id` · `bank.import` —
`after.import_id`, `after.rows`, `after.inserted`, `after.skipped` · `bank.match` / `.unmatch` / `.ignore` — `after.bank_transaction_id`,
`after.item_type`, `after.item_id`, `after.amount`, `after.reason` · `bank.entry_from_line` — `after.journal_entry_number`, `after.account_code`,
`after.amount` · `reconciliation.start` / `.complete` / `.reopen` — `after.statement_date`, `after.statement_balance`, `after.cleared_balance`,
`after.difference`, `after.lines` (count), `after.reason` · `ai_statement.pull` — `after.period`, `after.kernel_status`, `after.changed` ·
`ai_statement.import` — `after.period`, `after.bills[]` (numbers and amounts) · `ai_statement.check` — `after.period`, `after.verdict`,
`after.differences[]` · `recurring.run` — `after.template_id`, `after.record_type`, `after.record_id`, `after.run_date` · `attachment.add` /
`.delete` — `after.record_type`, `after.record_id`, `after.filename`, `after.byte_size`, `after.mime_type` · `note.add` — `after.excerpt`
(200 chars) · `*.save` of configuration — `before`/`after` of the changed fields · `export.download` — `after.what`, `after.period`,
`after.format` · `morning_note.send` — `after.to_member_id`, `after.length` · `screen.view` on a record page — `after.record_type`,
`after.record_id`. **A secure token, a token value, a party's contact details, a note's full text or a document's attachment are never
in a payload.**

## Entity resolution for the action tools (the registry's `resolve` block — `deploy/kernel-registry-gl.json`)

| Entity param | Tool | query param | id field | label field |
|---|---|---|---|---|
| `account`, `debit_account`, `credit_account`, `tax_account`, `income_account`, `expense_account`, `retained_earnings_account` | `find_accounts` | `q` (a code or words) | `account_id` | `label` |
| `customer` | `find_customers` | `q` (name) | `customer_id` | `name` |
| `vendor` | `find_vendors` | `q` (name) | `vendor_id` | `name` |
| `invoice`, `apply_to` (a credit note's) | `find_invoices` | `q` (a number or words) | `invoice_id` | `label` |
| `bill`, `apply_to` (a vendor credit's), `bills[]` | `find_bills` | `q` (a number or the vendor's reference) | `bill_id` | `label` |
| `entry`, `journal_entry`, `reverse_of`, `entries[]` | `find_journal_entries` | `q` (a number or words) | `journal_entry_id` | `label` |
| `receipt`, `receipts[]` | `find_receipts` | `q` (a number or reference) | `receipt_id` | `label` |
| `payment` | `find_payments` | `q` (a number or cheque number) | `payment_id` | `label` |
| `deposit` | `find_deposits` | `q` (a number) | `deposit_id` | `label` |
| `payment_run`, `run` | `find_payment_runs` | `q` (a number) | `payment_run_id` | `label` |
| `credit_note` | `find_credit_notes` | `q` (a number) | `credit_note_id` | `label` |
| `vendor_credit` | `find_vendor_credits` | `q` (a number) | `vendor_credit_id` | `label` |
| `bank_account`, `from_bank_account`, `to_bank_account`, `deposit_to` | `find_bank_accounts` | `q` (name) | `bank_account_id` | `name` |
| `bank_line`, `bank_transaction` | `unmatched_bank_lines` | `q` (a reference or description) | `bank_transaction_id` | `label` |
| `period` | `find_periods` | `q` (`2026-09`) | `fiscal_period_id` | `name` |
| `fiscal_year`, `year` | `find_periods` | `q` (`FY2026`) | `fiscal_year_id` | `fiscal_year_name` |
| `item` | `find_items` | `q` (name or code) | `item_id` | `name` |
| `tax_rate` | `find_tax_rates` | `q` (name) | `tax_rate_id` | `name` |
| `statement`, `ai_statement` | `ai_statement_status` | `q` (`2026-09`) | `ai_statement_id` | `period` |
| `reconciliation` | `find_reconciliations` | `q` (a date) | `bank_reconciliation_id` | `label` |
| `template`, `recurring` | `find_recurring` | `q` (name) | `recurring_template_id` | `name` |
| `member`, `to_member`, `morning_note_member` | `find_members` | `q` (name) | `member_id` | `display_name` |
| `department` | `find_departments` | `q` (name) | `department_id` | `name` |
| `site` | `find_sites` | `q` (name) | `site_id` | `name` |

A repeated parameter (`bills[]`, `receipts[]`, `entries[]`) is a list of ids and is never looked up by name. A document is addressed
by its number when someone types ("INV-00042"), and by id in every table.

## Size
52 records tools (`app_roles`, `records_search` and the three shares included), 7 activity tools, one guarded search each.
