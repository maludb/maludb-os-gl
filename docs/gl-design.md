# General Ledger — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-04 · the accounting system the kernel decided it would never contain: **the optional application that keeps
the books**. A chart of accounts, a double-entry journal, fiscal periods, the standard financial statements,
accounts receivable (customers, invoices, receipts), accounts payable (vendors, bills, payments), and cash
management that ties every bank account in the ledger out to the bank's own statement — without a bank feed, a
payment provider, or any way to move money. Its own repository `/srv/apps/gl` (origin
`github.com/maludb/maludb-os-gl`, private), served as `gl.<domain>` (`gl.subello.com` here), built with
`htmx-php-builder` (how it is built) and fitted to `maludb-os-integration` 0.5.1 (how it fits), exactly as HR,
Projects, txtSchedules and Help Desk were (`/srv/apps/helpdesk/docs/helpdesk-design.md` is the nearest exemplar;
the kernel's retired bookkeeping, sales and expenses specs — `docs/build-specs/bookkeeping-ledger.md`,
`sales-invoicing.md`, `expenses.md` in `maludb-os-core` — are the prior art this design keeps or overturns by name).

> **What this document is.** The plan for the application, in the kernel's words, for the owner's go. The kernel's
> decision of 2026-09-22 stands: *the kernel keeps token accounting with dollar costs only — no books; the accounting
> system is an optional application that receives the export.* This is that application.
>
> **Checkpoint.** This document and the owner's answers (§13, answered §15) are Phase 0's first half; the schema in `db/`,
> `maludb-os.json`, `os/`, `skills/` and `deploy/` complete it. Phase 1 (written 2026-10-05) adds `docs/gl-mcp-tool-surface.md`,
> `docs/gl-action-manifest.md` and the ten slice specs in `docs/build-specs/`. **No feature PHP is written until the
> owner approves them together.** The decisions this document needed from the owner are §13, answered in §15 (2026-10-04).

## 1. What General Ledger is, and is not

General Ledger **owns the books**: every account, every posted entry, which period it fell in and whether that
period is closed, who owes the business and whom the business owes, and whether the ledger's cash agrees with the
bank's. It is the QuickBooks, Xero or Sage a small business or an agency would otherwise pay for, in the shape an
accountant expects — with the differences that matter here:

- **It is the one place money is true.** Every other application keeps its own facts (HR a pay run, Reservations
  a day's covers, Cidery a customer order); the ledger keeps what those facts cost or earned, as journal entries
  with a source. Nothing posts twice, nothing posts into a closed period, nothing posted is edited — the database
  refuses, not the screen.
- **The kernel's AI spend is a supplier's bill.** The kernel's closed-month statement (`os.ledger-period/1`, A5)
  is pulled on a timer and becomes **one bill per provider per month**, lines by department, the provider a
  vendor; paying it is an ordinary vendor payment matched to the card statement. "What did the agents cost us in
  September, by department, and is it paid?" is then a ledger question like any other (§8).
- **It never moves money.** No bank feed, no payment provider, no transfer initiated here. A payment or a receipt
  is a *record* of money that moved elsewhere (a cheque written, a transfer made, a card charged), entered by a
  person or proposed by an agent and confirmed by a person. The kernel's standing rule — *no money-out actions in
  the applications beyond what an approval category covers* — is kept by construction.
- **Agents keep the books beside people.** The shipped Bookkeeper drafts, imports, matches and flags; it posts
  nothing, pays nothing and sends nothing without a person — those actions pause in the kernel under
  `money_out` or `external_send`. A person doing the same is never paused (§5).
- **Full MCP coverage is a requirement**, not a feature: every statement a screen shows is a records tool, every
  button an action tool (`maludb-os-integration`, `mcp-and-api.md`).
- **Nothing of identity lives here.** The kernel signs people in; the ledger mirrors the directory. Customers and
  vendors are the ledger's own parties, not members; an invoice reaches a customer by email and a secure link,
  never a login (§4).

Not in version 1: bank feeds and OFX import (CSV and hand entry only); online payment collection (Stripe) and
refunds; statutory payroll and tax filing (a standing decision — HR records pay, the accountant files); multi-entity
consolidation (one installation is one set of books; a second company is a second installation); multi-currency
revaluation (each document keeps its currency, statements render per currency, nothing is converted); inventory
valuation (a stock movement may post, through a share, later); fixed-asset depreciation schedules (a journal entry a
person writes, or the kernel's Assets register posts, later); budgets; a PDF renderer (an invoice is a print-ready
page behind its secure link in v1 — §13.8). Each is an Extended item (§11).

## 2. Who uses it (the actors, in the OS's words)

**A word about words.** "Agent" always means an OS AI agent. The person who keeps the books is the **bookkeeper**;
the one who closes periods and approves payment is the **controller**; the outside accountant is a kernel
member flagged external, admitted by a grant. A **customer** or a **vendor** is a party in the ledger, never a
member, never signed in.

| Actor | Who | How they reach the ledger |
|---|---|---|
| **Viewer** | A manager who may read statements and their department's figures | The launcher → `gl.<domain>`; the command bar |
| **Bookkeeper** | Enters and posts journals, invoices, bills, receipts and payments; matches the bank; drafts reconciliations | The application, the command bar |
| **Controller** | Approves bills and payment runs, completes reconciliations, closes and reopens periods, reverses, voids, writes off | The application |
| **Accountant** (external) | Reads everything, downloads every export, posts adjusting entries; a kernel member with `is_external`, granted by the super-admin (the kernel's db/159 opens the launcher to them) | The launcher, like an employee |
| **GL admin** | The chart, fiscal years, numbering, tax rates, posting rules, bank accounts, settings. A super-admin holds it (the kernel gives them the admin role) | The settings area |
| **A customer** | A party the business invoices | Email, and the invoice's **secure link** `/i/<token>` (view, print, "I have paid this on …") |
| **A vendor** | A party the business pays | None — a vendor is a record; their bill is an attachment |
| **An agent keeping books** | The shipped **expert** and **bookkeeper** agents, and any agent a super-admin grants a role (the Accounting department's Sasha, Becky and Jack are the obvious candidates — §5) | Records MCP (run token) · Actions MCP (relayed) · a duty (§5) |
| **The kernel** | Signs people in, delivers the directory, hires and runs the agents, pauses their actions for approval, serves the month's AI statement, answers the command bar | — |
| **System** | The AI statement poll, recurring entries falling due, reminders falling due, the outbox — the one timer worker | `source = 'cron'`, every row logged |

Tenancy: the business running the ledger is the tenant — one installation, one database, one memory with every
other application's episodes. **One installation keeps one set of books** (§3, scope).

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered as shown). The base role's
key is `user` on purpose — the kernel's installer grants standing departments the role keyed `member`, `user` or
`write`. **General Ledger is never installed with `--grant-standing-departments`** (§10): the books are granted
person by person, and the installer's `plan` says so.

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `user` | Viewer | read | `reports.view` (statements, aging, dashboards), `parties.view`, `documents.view` |
| `bookkeeper` | Bookkeeper | write | Viewer's + `journal.write` (draft, post, reverse own drafts), `ar.write` (customers, invoices, receipts, credit notes, send), `ap.write` (vendors, bills, vendor credits, payments **recorded**), `bank.write` (import, match, draft a reconciliation), `attachments.write` |
| `controller` | Controller | write | Bookkeeper's + `ap.approve` (bills, payment runs), `bank.reconcile` (complete, reopen), `periods.manage` (close, reopen, year-end), `journal.override` (reverse any, void, write off, post into a reopened period), `exports.download` |
| `accountant` | Accountant | write | Controller's rights **without** `ap.approve` — reads all, adjusts, exports; meant for an external |
| `admin` | GL admin (is_admin) | admin | every right + `settings.manage` (chart, fiscal years, numbering, tax, posting rules, bank accounts, recurring templates, agents' settings) |

**The rules** (`gl_has_right(right)` in SQL for the views and in PHP for the handlers and the menu; caller checks as
uncorrelated sets tested once per statement, the kernel's db/160 lesson):

- **There is no "my" journal entry.** The books are the business's: a holder of a role sees every record the role
  admits. The one narrowing is the **department dimension**: a Viewer who is a dept-admin in the kernel (the mirror
  carries `department_members.is_admin`) sees the P&L, account activity and documents **filtered to their
  departments** unless they also hold Bookkeeper or above; a super-admin holds every right.
- **A posted entry is immutable** — the trigger refuses, for every role, including admin. A correction is a
  reversal (a new entry that names the old one) and a re-entry.
- **A closed period refuses posting** for every role; `periods.manage` reopens it with a reason, logged, and the
  reopen is itself what `books_audit` reports.
- **A customer sees one invoice** through its secure link: the document, its payments, its balance; never another
  document, never a note. The link is hashed, rotatable, and dead N days after the invoice is paid (setting;
  default 180).
- **An agent sees what its member id may see** — the same function, no special case; the run-facts gate decides
  which tools it is offered; `is_eval` refuses every write.
- **Scope**: **none**. One installation, one legal entity, one base currency, one chart. Departments are a
  dimension on every line (`department_id` from the mirror), used for departmental P&L and the AI bill's lines —
  not a wall. Sites (a restaurant each) are a dimension too (`location_id` from the mirror's locations, nullable),
  so a scoped sibling such as Reservations or txtSchedules can post per site and the P&L can be read per site. A
  business with two legal entities installs twice (`gl` and a second catalog key is the installer's question, not
  this design's).

## 4. Parties without an account — customers, vendors, and the secure invoice

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the
application's own*; for a page someone with no account must reach, **a token is the authority** (Help Desk's secure
ticket link, D1 there). A customer is not a member and must never need to be.

**Recommended (D-A): a customer is a party; an invoice travels by email with a secure link.**

- `customers` and `vendors` are the ledger's own tables (name, emails, phone, billing and remit-to addresses, tax
  id, payment terms, currency, default accounts, notes, `member_id` nullable — a vendor who is an employee being
  reimbursed, a customer who is also an invited external member). No login, no password.
- `invoice_send` mails the invoice (MaluMail, the application holds its own key as Help Desk does) with the
  **secure link** `/i/<48 hex>`: a print-ready page (the browser's "Save as PDF" is the PDF in v1), the amount
  due, the payments received, and one button — **"I paid this on … by …"** — which records a *customer's claim*
  (`receipt_claims`) for the bookkeeper to confirm as a receipt once it shows at the bank; it never records money.
  Opening the link stamps `viewed_at` (the old S12). Statements and reminders travel the same way.
- `vendors` get nothing public: a bill arrives as a PDF or a photo someone attaches, or by email to a MaluMail
  mailbox `bills@<domain>` whose attachments become **inbox bills** for the bookkeeper to turn into bills
  (Extended; the schema keeps `inbound_documents`).
- Public paths — `/i/<token>`, `/channels/malumail/mail` (Extended), `/api/v1/health` — are named in the vhost's
  allow-list; nothing else answers without a session.

**Rejected:** inviting customers as members (the help desk's reasoning, and the ledger would need
`directory_writes`, HR's alone); a customer portal with a login (forbidden by the contract; the magic-link "my
invoices" page is an Extended item on the help desk's pattern); online payment on the link (needs a provider —
Extended, with Stripe already in the catalog).

## 5. Agents and the ledger — the OS requirement, in detail

**Drafting is free; posting, paying and sending are a person's.** The whole of the control in a ledger is who may
make a thing true. So, for every agent:

| Action | Category | Why |
|---|---|---|
| `journal_entry_post`, `journal_entry_reverse`, `journal_entries_post_batch`, `year_end_close` | `money_out` | Posting makes an entry true in the books |
| `bill_approve`, `payment_record`, `payment_run_approve`, `payment_void`, `receipt_record`, `receipt_void`, `credit_note_issue`, `vendor_credit_issue`, `invoice_write_off`, `invoice_void`, `bill_void` | `money_out` | Money recorded as moved, or a claim on it changed |
| `invoice_send`, `invoice_remind`, `statement_send` | `external_send` | Words and a document leave the building |
| `reconciliation_complete`, `period_close`, `period_reopen` | `other` | The strongest controls in the module; **policy default: refuse for agents** (a person only) |
| `customer_delete`, `vendor_delete`, `attachment_delete`, `bank_import_delete`, `recurring_delete` | `deletion` | |
| `*_save` of settings: accounts, fiscal years, tax rates, posting rules, bank accounts, numbering, templates | `other` | Configuration |
| `journal_entry_draft`, `journal_line_save`, `invoice_draft`, `bill_draft`, `customer_save`, `vendor_save`, `bank_transaction_match`/`unmatch`/`ignore`, `bank_import` (CSV rows into the unmatched ledger), `ai_statement_import` (a bill in draft), `reconciliation_start`, `receipt_claim_confirm` (a draft receipt), `attachment_add`, `note_add` | — | The Bookkeeper must do these alone |

**Working — how an agent learns there is work.** As Help Desk: a **duty** (cron) per shipped agent; a person's
command-bar turn; and, when the kernel's K8 (wake an agent from an application) lands, a wake on each event that
needs it (a bill attached, a reconciliation out of balance, a statement closed). Until K8, the duty.

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install** at the owner's word, as Projects' Scrum
Master was — §13.6):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — GL Expert | The command bar: answers "what is our cash position", "who owes us over 60 days", "what did we spend on hosting this year", "what did INV-00042 post"; explains a statement line; **drafts** a journal entry, an invoice or a bill from what the asker says and hands it back as a draft; suggests the account for a line; never posts, pays or sends unless the asker says so, and then pauses | every records tool, `record_history`, `actor_timeline` | `journal_entry_draft`, `journal_line_save`, `invoice_draft`, `invoice_line_save`, `bill_draft`, `bill_line_save`, `customer_save`, `vendor_save`, `note_add`, `invoice_send` (paused), `journal_entry_post` (paused) | `invoice_send`, `journal_entry_post` |
| `bookkeeper` — Bookkeeper | A duty every morning (`30 6 * * *`): pull any newly **closed** kernel AI period and import it (one draft bill per provider — then ask for approval of each); run the recurring templates that fell due (drafts); suggest a match for every unmatched bank line (exact amount ±5 days; a match is a draft until confirmed, unless the policy relaxes it); list what is unposted, unbalanced, overdue (AR) and due this week (AP) into one **morning note** to the controller; draft reminders for invoices past terms (paused as `external_send`) | `unposted_entries`, `unmatched_bank_lines`, `match_candidates`, `ar_aging`, `ap_due`, `ai_statement_status`, `recurring_due`, `get_*`, `find_*` | `ai_statement_import`, `recurring_run`, `bank_transaction_match`, `journal_entry_draft`, `invoice_remind`, `note_add`, `message_send` (the kernel's, to the controller's assistant) | `invoice_remind`, `bill_approve` if ever granted |

**The Accounting department's agents.** The kernel kept Sasha, Becky and Jack (agents 43, 44, 42) and re-versioned
their duties around the token ledger (decision 2026-09-22). This design does **not** replace them: the super-admin
may grant them a role here (Viewer for the two, Controller for Jack if the owner wishes) and their next version can
read the ledger through its MCP — "what did the AI cost" then has two answers that must agree (§8, the audit
check). Whether to fold the Bookkeeper's duty into Becky's instead of hiring a fourth agent is §13.6.

**Skills** (`skills/`): `gl-basics` (what an account, an entry, a period, a control account *is*; which tool answers
what; debits and credits in one page), `the-chart-and-posting-rules` (the seeded chart, how a document finds its
accounts, when to add an account and when not), `writing-to-customers` (tone; an invoice email and a reminder, never
a threat, never another customer's name); runbooks (`kind: runbook`): `import-the-ai-statement`, `match-the-bank`,
`month-end-close` (what must be true before a period closes, in order), `collections-review` (the aging walk).

## 6. Memory model — what General Ledger remembers

**Record memory** (PostgreSQL 17, `<tenant>_gl`, db/001–0NN). Money columns are `numeric(14,2)`; AI amounts arrive
at four decimals and are rounded to cents **once**, on the bill line, the remainder on the last line so the bill
equals the statement to the cent.

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members`, `department_members`, `departments`, `locations`, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; `members.roles text[]` from the claims and `access[]`; departments and sites as dimensions |
| Roles | `gl_rights`, `gl_roles`, `gl_role_rights`, `mcp_app_roles` | `gl_has_right(right)` |
| Settings | `gl_settings` (one row: base currency, fiscal year start month, numbering prefixes, retained-earnings account, AR and AP control accounts, default bank account, undeposited-funds account, sales-tax payable account, AI-usage expense account, invoice terms, link lifetime, reminder cadence), `tax_rates`, `document_sequences` (gapless, a table not a SEQUENCE) | seeded |
| The chart | `accounts` (code, name, type asset/liability/equity/income/expense, subtype — bank, receivable, payable, current/fixed, tax, equity, revenue, cogs, operating, other —, parent, `normal_balance`, `is_bank`, `is_control`, `is_system`, archived, description), `account_types` (fixed), `posting_rules` (source × match key → debit account, credit account, tax account) | **the retired db/056 chart seeded again**: 1000 Cash on hand, 1010 Business checking (bank), 1050 Undeposited funds, 1200 Accounts receivable (control), 1400 Inventory, 1500 Fixed assets, 2000 Accounts payable (control), 2100 Sales tax payable, 2200 Payroll liabilities, 2300 Credit card (bank), 3000 Owner equity, 3900 Retained earnings, 4000 Sales revenue, 4100 Service revenue, 5000 Cost of goods sold, 6000 Salaries and wages, 6050 AI and model usage, 6100 Rent, 6200 Software and hosting, 6500 Bank and payment fees, 6900 Other expenses, 7000 Other income; renameable, system ones undeletable; a control account takes no manual line |
| Fiscal time | `fiscal_years` (start, end, status open/closed, closing entry), `fiscal_periods` (year, name `2026-09`, range with an `EXCLUDE` on overlap, status open/closed, closed_by/at, reopened_by/at/reason) | a year is made with its twelve periods in one action; the first by the admin on install |
| The journal | `journal_entries` (number `JE-000123` on post, date, period, memo, `source` manual/invoice/credit_note/receipt/deposit/bill/vendor_credit/payment/transfer/bank_fee/ai_usage/recurring/reversal/closing/opening, `source_type`/`source_id`, status draft/posted/reversed/void, `reversal_of`, `reversed_by`, currency, totals written by the trigger, department, location, posted_by/at, created_by, attachments), `journal_lines` (account, debit XOR credit, description, department, location, customer, vendor, tax rate) | triggers from the retired db/056, kept verbatim in intent: balance, period open, immutable after post, `posted → draft` refused; one entry per source document (`UNIQUE (source_type, source_id)` where not null) |
| Parties | `customers`, `vendors` (§4), `party_contacts` (name, email, role), `customer_statements` is a report, not a table | a vendor is auto-made per AI provider on first import (`vendors.kind = 'ai_provider'`, `provider_key`) |
| Items | `items` (code, name, kind product/service, income account, expense account, unit price, tax rate, archived) | the old `catalog_items` |
| Accounts receivable | `invoices` (number gapless on issue, customer, dates, terms, currency, status draft/issued/sent/viewed/partially_paid/paid/void/written_off, subtotal/tax/total, `amount_paid`, `amount_credited`, `balance_due` generated, memo, terms text, `journal_entry_id`, `location_id`, `department_id`, external ref, `viewed_at`, reminders), `invoice_lines` (item, description, qty, unit price, tax rate, line totals, income account, department), `credit_notes` + lines (draft/issued/void; an issued one applies to invoices through `credit_applications`), `receipts` (customer money in: date, amount, method cash/cheque/transfer/card/other, reference, customer, **deposit-to** = a bank account or Undeposited funds, status recorded/deposited/void, journal_entry_id), `receipt_applications` (receipt → invoice; the sum never exceeds either side), `receipt_claims` (what a customer said on the secure link), `deposits` (a bank deposit grouping receipts from Undeposited funds into one bank line — the thing that makes a deposit match one line at the bank), `invoice_links_secure` (hash, expiry, rotated) | the retired db/037 shape, with `receipts` (money in) and `payments` (money out) named apart so no screen ever confuses them |
| Accounts payable | `bills` (internal number `BILL-000123` at creation, vendor, vendor's own reference (unique per vendor — the double-entry guard), dates, due, terms, currency, status draft/approved/partially_paid/paid/void, totals, `amount_paid`, `amount_credited`, `balance_due` generated, `journal_entry_id`, attachments, `source` manual/ai_usage/inbox/recurring), `bill_lines` (expense account, description, qty, unit price, tax rate, department, location, item), `vendor_credits` + lines, `payments` (money out: date, amount, method cheque/ach/card/cash/other, cheque number, bank account, vendor, status draft/approved/recorded/void, `payment_run_id`, journal_entry_id), `payment_applications` (payment → bill), `payment_runs` (a batch: selected bills, approved_by, recorded_at — the cheque run or the day's transfers as one list and one CSV) | approval of a bill is a person's act (`ap.approve`); a bill posts on approval, a payment on recording |
| The kernel's AI spend | `ai_statements` (kernel period `2026-09`, status open/closed as the kernel said, document hash, pulled_at, imported_at, bills made, `late_calls`, totals per currency as the kernel sent them), `ai_statement_lines` (the document's lines verbatim: provider, model, department, agent, application, tokens, amount, currency) | the reconciliation's evidence: the bills must equal these lines (§8) |
| Cash | `bank_accounts` (name, kind checking/savings/credit_card/cash, `account_id` → an `is_bank` account, institution, last four, currency, opening balance + date, `last_reconciled_through`), `bank_imports` (batch: file name, rows, inserted, skipped, by, at — the file itself never stored), `bank_transactions` (bank account, date, amount signed, description, `external_ref` unique per account, status unmatched/matched/ignored, `matched_type` receipt/deposit/payment/journal_entry/transfer, `matched_id`, matched_by/at, `reconciliation_id`), `transfers` (between two bank accounts, one entry), `bank_reconciliations` (bank account, statement date, statement balance, cleared balance, difference, status in_progress/completed/reopened, completed_by/at, notes) | the old db/056 bank half, plus the deposits and transfers the tie-out needs |
| Recurring | `recurring_templates` (kind journal/bill/invoice, cadence, next date, end, the template document as jsonb, last run), `recurring_runs` | rent, subscriptions, the monthly depreciation a person wrote once |
| Documents | `attachments` (on an entry, invoice, bill, receipt, payment, reconciliation; sha256; under `storage/attachments/<kind>/<id>/`; served through the gate), `inbound_documents` (Extended: the `bills@` mailbox) | a bill without its document is flagged on the AP screens |
| Talking | `notes` (internal, on any record), `notifications`, `notification_prefs`, `notification_outbox` (email; K6 texts for the controller's "a payment run awaits you") | txtSchedules' outbox, as Help Desk copied it |
| Agents | `agent_dispatches` (record, agent, kind, run id, status) | §5 |
| Tokens | `mcp_access_tokens` | the contract's; a person's own, read-only |

**Views**: `mcp_*` over every table above with `security_barrier`, the caller's rights tested once per statement;
the statements themselves are **SQL functions** over posted lines — `gl_trial_balance(period)`, `gl_profit_and_loss
(from, to, department, location)`, `gl_balance_sheet(as_of)`, `gl_cash_flow(from, to)` (indirect: net income, then
the change in every balance-sheet account by subtype), `gl_account_activity(account, from, to)`, `gl_ar_aging(as_of)`,
`gl_ap_aging(as_of)` — so a screen, a tool and a CSV never disagree.

**Activity memory**: `activity_log` through one `log_activity()`, `entity.verb` events, shipped to the tenant's one
MaluDB as `activity` episodes tagged `gl`. **There is no audit table**: a record's history is
`mcp_activity_log WHERE entity_type = … AND entity_id = …` (the sibling rule). Money events carry `amount` and
`currency` in `after` — always. Events (the manifest names them): `journal_entry.draft|post|reverse|void`,
`journal_entries.post_batch`, `period.close|reopen`, `fiscal_year.open|close`, `account.*`, `posting_rule.*`,
`customer.*`, `vendor.*`, `invoice.draft|issue|send|view|remind|void|write_off`, `credit_note.*`,
`receipt.record|apply|deposit|void`, `receipt_claim.submit|confirm|dismiss`, `bill.draft|approve|void`,
`vendor_credit.*`, `payment.draft|approve|record|void`, `payment_run.create|approve|record`, `bank.import|match|
unmatch|ignore|transfer`, `reconciliation.start|complete|reopen`, `ai_statement.pull|import|reconcile`,
`recurring.*`, `attachment.*`, `note.add`, `export.download`, `token.mint|revoke`, `member.sign_on`,
`directory.sync`, `screen.view`. A secure token is never logged; a customer's email never beyond the party card.

## 7. The question inventory — what General Ledger exists to answer

Kind R = record (the records MCP), A = activity (the activity MCP). Who = the lowest role that may ask.

**Statements and the chart**
| # | Question | Kind | Who |
|---|---|---|---|
| GL1 | What is the profit and loss for a period, by account — and by department or site? | R | Viewer |
| GL2 | What is the balance sheet as of a date, and does assets = liabilities + equity? | R | Viewer |
| GL3 | What is the trial balance for a period, and does it balance? | R | Viewer |
| GL4 | Where did cash come from and go to in a period (the cash flow statement)? | R | Viewer |
| GL5 | What is the balance and activity on account X for a period? | R | Viewer |
| GL6 | What accounts exist, what type is each, which are banks and controls, which are unused? | R | Viewer |
| GL7 | How does this period compare to the last one, or the same period last year? | R | Viewer |

**The journal and the periods**
| # | Question | Kind | Who |
|---|---|---|---|
| GL8 | Which entries are still in draft, which do not balance, and which period would each fall in? | R | Bookkeeper |
| GL9 | What did this invoice / bill / receipt / payment / deposit post to the books? | R | Bookkeeper |
| GL10 | Which periods are open, closed or reopened, by whom and why? | R+A | Viewer |
| GL11 | What was reversed or voided, and why? | R+A | Bookkeeper |
| GL12 | Did anyone post into a closed period, reopen one, or try to change a posted entry? *(the audit check — must be none but the reopens)* | A | Controller |
| GL13 | Which posting rule sends a source to which accounts? | R | Bookkeeper |
| GL14 | What is unposted, and through what date are the books posted? | R | Bookkeeper |

**Receivables**
| # | Question | Kind | Who |
|---|---|---|---|
| AR1 | Who owes us, how much, and how overdue (current, 1–30, 31–60, 61–90, 90+)? | R | Viewer |
| AR2 | What is the status of invoice N — issued, sent, viewed, paid, balance? | R | Viewer |
| AR3 | What did we invoice in a period, in total, by customer, by item, by department or site? | R | Viewer |
| AR4 | What did we collect in a period, from whom, by what method; what sits in Undeposited funds? | R | Bookkeeper |
| AR5 | What does customer X's statement look like (opening balance, invoices, receipts, credits, running balance)? | R | Viewer |
| AR6 | Which customers claimed a payment on the link that we have not yet confirmed? | R | Bookkeeper |
| AR7 | How much sales tax did we charge in a period, by rate? | R | Bookkeeper |
| AR8 | What is our average days-to-pay, overall and per customer? | R+A | Viewer |
| AR9 | When was invoice N sent, viewed, reminded and paid, and by whom? | A | Bookkeeper |
| AR10 | Which invoices were drafted or sent by an agent? | A | Controller |

**Payables**
| # | Question | Kind | Who |
|---|---|---|---|
| AP1 | Whom do we owe, how much, and when is it due (the AP aging and the due-this-week list)? | R | Viewer |
| AP2 | Which bills await approval, and which approved bills are unpaid? | R | Bookkeeper |
| AP3 | What did we spend in a period, by account, vendor, department or site? | R | Viewer |
| AP4 | Who are our biggest vendors this year? | R | Viewer |
| AP5 | What is in payment run N, who approved it, and what was recorded as paid? | R+A | Bookkeeper |
| AP6 | Which bills have no attached document? | R | Bookkeeper |
| AP7 | What are our recurring costs per month, and which fell due unposted? | R | Bookkeeper |
| AP8 | Which bills or payments did an agent draft, approve or record this month? | A | Controller |

**The AI bill (the kernel's export)**
| # | Question | Kind | Who |
|---|---|---|---|
| AI1 | What did the AI workforce cost in a period, by provider, department and agent — as the kernel says, and as the books say? | R | Viewer |
| AI2 | Does the AI expense posted for a period equal the kernel's statement, line for line? *(the audit check — must always agree)* | R | Controller |
| AI3 | Which kernel periods are pulled, imported, approved, paid; which closed month is not yet in the books? | R | Bookkeeper |
| AI4 | What late calls did the kernel fold into a month, and which bill carries them? | R | Bookkeeper |

**Cash**
| # | Question | Kind | Who |
|---|---|---|---|
| CM1 | What is the ledger balance of each bank account, what has cleared, and what is the bank's balance per the last reconciliation? | R | Viewer |
| CM2 | Which bank lines are unmatched, and what are the candidates for each? | R | Bookkeeper |
| CM3 | Is the bank reconciled through a date, and who completed it; what is the difference now? | R+A | Viewer |
| CM4 | What is in transit — receipts not deposited, payments recorded but not cleared? | R | Bookkeeper |
| CM5 | What is our cash position trend over the last 12 months? | R | Viewer |

**Activity**
| # | Question | Kind | Who |
|---|---|---|---|
| ACT1 | Who did what to this record, in order? | A | Bookkeeper |
| ACT2 | What did person or agent X do in the ledger this week? | A | Controller |
| ACT3 | What has changed in the books since I last looked? | A | Viewer |

Every question is a named tool on the records or activity server (Phase 1 fixes the names); the statements are the
same SQL functions the screens call.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit copied from Help Desk (the newest).
  The mirror carries departments and locations as dimensions.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it alone (`roles-and-rights.md`).
- **The AI statement** (A5, built): the worker pulls `GET {OS_INTERNAL_URL}/api/v1/ledger/periods.php` (the
  months and their status) and, for each **closed** month not yet imported, `?period=`; the document is kept
  verbatim in `ai_statements`/`ai_statement_lines`; `ai_statement_import` makes **one draft bill per provider**
  (vendor auto-made, `bill.vendor_ref = "<provider> <period>"`, lines per department at the AI-usage expense
  account, rounded once, late calls kept on their own line and named in the memo), for the controller to approve;
  approval posts DR 6050 by department / CR 2000. An **open** month is shown on the AI screen as "not yet closed
  in the OS" and never imported — a late call lands in the next closed month, exactly as the kernel folds it. The
  check AI2 compares `ai_statement_lines` totals to the bills' lines per provider and department and says
  **agree** or names the difference; the Bookkeeper's morning note carries it.
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); the Actions MCP from the
  registry; the approval hook before every action with a category; the chat endpoint for the command bar; the
  duty for the Bookkeeper.
- **K6 texts**: the controller's "a payment run awaits your approval" and "the reconciliation is out by …" by text
  when they chose it; every refusal falls back to email; no Twilio key here.
- **K7 reads** (`reads[]`, each `why` stated): none in version 1. Extended (§11): HR's payroll totals per
  department (a share HR owes, people-free), Reservations' or Cidery's daily sales — each a **source import** that
  becomes a draft journal.
- **Shares** (`shares[]`): `account_balances` (ledger balances by account as of a date — for a dashboard elsewhere),
  `ap_due_summary` (amounts due per week), `ar_aging_summary`; none `scoped`, none about a person. Any consumer
  needs a super-admin-approved connection.
- **Email is the application's own**: MaluMail with `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME`; invoices,
  statements and reminders out, threaded; the `bills@` mailbox Extended.
- **Public doors** (allow-listed in the vhost; everything else needs a session): `/i/<token>`, `/api/v1/health`,
  `/mcp/records`, `/mcp/activity`. Each is CSRF-protected or signature-checked, rate-limited, and logged with
  `source = 'portal'`.

## 9. Screens (the nxl look; designed at 375 px first; no modals; cards for named things — accounts, parties,
bank accounts, periods —, tables for ledgers and lines; every name a link; every page a way back)

**Home** — cash position (each bank account: ledger, cleared, last reconciled), AR and AP totals with their aging
in five count cards each a link, unposted and draft counts, the AI bill's state for the last closed month, posted-
through date, the Bookkeeper's last morning note.

**Chart and journal:** Chart of accounts (cards grouped by type, balances as of today; add, edit, archive;
`account-view` — activity by period) · Journal (list with period, status and source filters; `journal-entry-view`
with lines, the source document, the reversal link, attachments, the timeline from the activity log;
`journal-entry-form` — the lines editor with the balance strip, Post disabled until it balances and refused anyway)
· Posting rules · Recurring templates.

**Statements** (`/statements/{pl|balance-sheet|trial-balance|cash-flow}`): period pickers, compare-to, department
and site filters, per currency, a CSV and a print view each, "posted through … · N documents waiting" on every one.

**Receivables:** Customers (cards; `customer-view` with the statement, open invoices, contacts, claims) · Invoices
(list with status and overdue filters, a totals footer per currency; `invoice-form` with the lines editor, items,
tax; `invoice-view` with actions by status — Issue, Send, Remind, Record receipt, Credit, Void, Write off; the
secure link shown and rotatable) · Receipts (list, `unapplied` filter; `receipt-form` applying to open invoices
oldest first; deposit-to) · Deposits (group undeposited receipts into one) · Credit notes · AR aging · Customer
statement (and Send) · Sales tax report · Claims inbox.

**Payables:** Vendors (cards; `vendor-view` with open bills, payments, documents) · Bills (list: awaiting approval
/ approved / unpaid / due this week; `bill-form` with the lines editor and the attachment; `bill-view` — Approve,
Pay, Credit, Void) · Vendor credits · Payments (list; `payment-form` for one bill or several; cheque number) ·
Payment runs (select due bills → a run → Approve → Record as paid → the CSV for the bank or the cheque printer) ·
AP aging · Spend report · AI statements (`/ap/ai`: the months as the kernel reports them, pull, import, the
agree/differ check, the bills).

**Cash:** Bank accounts (cards with ledger, cleared, difference; `bank-account-view` — the transactions by status)
· Import (`/cash/import?bank_account=`: CSV, a date-format choice, a five-row preview, duplicates counted) ·
Match (`/cash/match?bank_account=`: each unmatched line with its candidates — receipt, deposit, payment, entry,
transfer — one click to confirm, "make an entry" for a fee or interest, Ignore) · Transfers · Reconcile
(`/cash/reconcile?bank_account=`: statement date and balance, the cleared lines ticked, the difference live,
Complete refused until zero; history of completed reconciliations).

**Periods:** Fiscal years and periods (cards by year; close, reopen with reason; year-end close writes the closing
entry to Retained earnings and can be reopened, which reverses it) · Audit (GL12 and AI2 on one page).

**Admin:** Settings (base currency, year start, numbering, control accounts, link lifetime, reminders) · Tax rates
· Items · Posting rules · Bank accounts · Recurring templates · Agents (which agents keep books here, their last
run — read-only; hiring and grants link to the kernel) · My MCP tokens · Exports (every statement and ledger as CSV;
the general ledger detail for the accountant's software).

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers (§13); the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2, the life of an invoice and a bill, posting refusals, a closed period, a reconciliation that will not complete out of balance, the AI statement rounding to the cent); the kit (Help Desk's identity, roles, sign-on, MCP common files, HTTP helper and sync), proven without a kernel (`tests/phase0/run.sh`); `maludb-os.json` (every port env in `env.required`), `os/{expert,bookkeeper}.md`, seven skills (four runbooks), `deploy/` templates with the public doors and `ROOT_STEPS.sh` (the port pin first); the installer's `plan` reads the repository clean | the owner's go on §13 |
| 1 | `docs/gl-mcp-tool-surface.md` (every question of §7 a tool; the resolve table; the log-payload rules), `docs/gl-action-manifest.md` (screens and actions with their categories), `mcp/action_registry.json`, and the slice specs in `docs/build-specs/` (`sso-shell` and the nine slices; **journal-core is the exemplar**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles` from the claims and the feed, `gl_has_right()`, the ingest bridge, the shell (phone-first, command bar through the chat endpoint), `/api/v1/health`, the vhost with the public allow-list | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | the slices, in order: **(1) the chart and fiscal time** (accounts, types, fiscal years with their periods, settings, tax rates, items, posting rules — the CRUD exemplar for workers) → **(2) journal core — the exemplar slice** (entries, the lines editor and balance strip, post, reverse, void, attachments, notes, the timeline, close and reopen a period, `unposted_entries`, `posted_through`) → **(3) statements** (the four statements, account activity, compare-to, department and site filters, CSV and print, posted-through on every one) → **(4) receivables** (customers, invoices with gapless numbering on issue, credit notes, receipts and applications, deposits and Undeposited funds, the secure link and the customer's claim, AR aging, statements, sales tax; posting through the rules; send, remind — email through MaluMail) → **(5) payables** (vendors, bills with the vendor-ref guard and the attachment flag, approval, vendor credits, payments and applications, payment runs with their CSV, AP aging, spend report; posting) → **(6) cash** (bank accounts, CSV import with preview and duplicate counting, matching with candidates, transfers, fees and interest as entries, reconciliation refused out of balance, reopen) → **(7) the AI bill** (the pull, the statement kept verbatim, import to bills, the agree/differ check, late calls, the AI screen) → **(8) recurring, year-end and the worker** (templates, their runs, the closing entry and its reversal on reopen, reminders falling due, the outbox, K6, the morning note, the one timer) → **(9) reports, audit, exports and settings** (home, the audit page, every export, the Agents page, MCP tokens) | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database, the installed application never touched |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the `shares[]` tools admitted to the kernel's token, the registry wrapper (`deploy/kernel-registry-gl.json`), the two agents declared with their grants and the duty; the kernel's Actions-MCP contract proved against its own `application_actions.register()` with a stubbed hook | the command bar answers "who owes us over 60 days" (`ar_aging`); an agent's `journal_entry_post` pauses; the Bookkeeper's duty posts nothing, pays nothing, sends nothing |
| 5 | the owner's `app_install.php apply` (ports pinned first — §12), DNS and TLS for `gl.<domain>`, the MaluMail key, the hires, the first fiscal year and the opening balances; the end-to-end proof | **an invoice is issued, sent, viewed on its link, claimed, received and deposited; the deposit matches one bank line; a bill is attached, approved, paid in a run; the kernel's closed month becomes bills that agree with the statement; the month is reconciled and closed; the trial balance balances, the balance sheet holds, and the cash flow statement explains the change in cash** |

**Extended** (after version 1, each its own spec, §11). Size: ten specs (sso-shell + nine slices), about 60 screens,
110 actions, 45 records tools, 6 activity tools — larger than Help Desk (65 screens, 96 actions, 36 + 6 tools, three
days on the exemplar and workers); **four days is the honest estimate**, receivables and payables being half of it,
plus one for Phase 5's proof with the owner.

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): a PDF renderer so an invoice travels as an attachment too
(dompdf vendored by composer, as Cidery's stack already carries composer) · the `bills@` MaluMail mailbox and
`inbound_documents` (a vendor's emailed PDF becomes a draft bill with its attachment) · **source imports**: a journal
drafted from another application's share — HR's `payroll_by_department` (a share HR owes; DR Salaries by department /
CR Payroll liabilities, and the payment when HR marks the run paid), Reservations' and Cidery's daily sales
(DR Cash or AR / CR Revenue, CR Sales tax), the kernel's Assets depreciation — one mapping screen, one draft per
period, the same rules engine · online payment on the secure link (Stripe, in the catalog) and the provider's payouts
and fees matched like any bank line · the customer's magic-link "my invoices" page (the help desk's pattern) · OFX/QFX
import · budgets and budget-vs-actual · a departmental P&L by site as a shared read for the scoped applications · K8
wakes replacing the morning duty's polling.

**Later**: bank feeds (a provider's aggregator — the one thing that would let money move and needs the kernel's
secret store), multi-entity consolidation, revaluation, inventory valuation, 1099/VAT returns.

## 12. What the kernel and its siblings owe this application

| # | Item | Why the application cannot do it | Until then |
|---|---|---|---|
| **K13** | **Catalog entry `gl` (kind `ours`) seeded by a migration** in `maludb-os-core`, business area **Finance** (exists), category `accounting`, icon `feather-book-open`, criticality `high` — so every installation shows "General Ledger — from us, not installed" beside QuickBooks, Xero and Sage, and the installer's `apply` finds its row | the catalog is the kernel's; Projects' row was K1 the same way (db/158) | `application_catalog_save` by hand on this install |
| **K14** | **The installer's `plan` warns on `--grant-standing-departments` for an application whose `maludb-os.json` says `"grant_standing_departments": false`** (a new optional key, 0.5.2 of the plugin) | the books must never be opened to every insider by the default switch | the owner omits the switch (§10, §13.3) |
| K15 | **The kernel's Accounting agents' next version** names General Ledger's tools in their grants (Viewer for Sasha and Becky, Controller for Jack if the owner wishes), so "what did the AI cost" has the kernel's answer and the books' answer side by side | the agents are the kernel's hires | the Bookkeeper's AI2 check |
| K16 | **K8 (wake an agent from an application)** — already owed to Help Desk; the ledger uses it for "a bill was attached", "a reconciliation is out", "a month closed in the OS" | messaging is an agent's tool | the morning duty |
| H1 | **HR shares `payroll_by_department`** (approved pay runs' totals per department per period, no person named — so it needs no `people` flag) | the pay run is HR's | a manual payroll journal, or a recurring template |

Nothing else is owed: sign-on, the directory, the ledger feed (A5), the chat endpoint, K6 texts and K7 reads all
exist. K13 is small and comes first; K14 is a one-line guard worth having before Phase 5.

## 13. Decisions the owner is asked for (recommendation first)

1. **Name and key** — "General Ledger", catalog key `gl` (`gl.<domain>`, `maludb-os-gl`, `<tenant>_gl`, roles
   `gl_rw` / `gl_records_ro` / `gl_activity_ro`). The alternative `accounting` is clearer to a non-accountant and
   collides with the catalog's `accounting` *category*; `ledger` collides with the kernel's token ledger. Confirm.
2. **Roles and names** — Viewer (`user`), Bookkeeper, Controller, Accountant (an external's role), GL admin.
   Confirm the words, and whether Accountant should hold `ap.approve` (recommended: no).
3. **Nobody by default** — not a default application; never installed with `--grant-standing-departments`; the
   super-admin grants each person. The repository stays **private** (not one of the three public defaults).
   Confirm.
4. **Scope none; departments and sites as dimensions**, one legal entity per installation. Confirm.
5. **The AI statement becomes one bill per provider per closed month**, lines by department, the provider a vendor,
   approved by the controller like any bill; open months are shown and never imported. Confirm, or ask for one
   journal entry per month instead (loses "is it paid").
6. **Two shipped agents, hired on install** — the expert (command bar) and the Bookkeeper (a 06:30 daily duty:
   pull and import, recurring drafts, match suggestions, the morning note, reminder drafts). Alternative: fold the
   Bookkeeper's duty into Becky's next version and ship only the expert. Confirm, and name the controller who
   receives the morning note (default: the first super-admin).
7. **Agents draft; a person posts, pays, sends, closes.** Every posting and money action pauses under `money_out`,
   sending under `external_send`, and `period_close` / `reconciliation_complete` are refused to agents by the
   default policy until you relax it. Confirm.
8. **No PDF renderer in v1** — an invoice is a print-ready page behind its secure link, mailed as a link and an
   HTML summary; dompdf is the first Extended item. Confirm, or pull it into slice 4.
9. **The customer's claim** — the secure page offers "I paid this on … by …", a claim the bookkeeper confirms
   when the money shows; the link dies 180 days after the invoice is paid. Confirm the button and the lifetime.
10. **Numbering** — invoices and credit notes gapless on issue (`INV-00001`, `CN-00001`); journal entries numbered
    on post (`JE-000001`); bills at creation (`BILL-000001`, the vendor's own reference unique per vendor);
    receipts `RCT-`, payments `PAY-`, deposits `DEP-`, payment runs `RUN-`; prefixes settings. Confirm.
11. **The seeded chart** — the kernel's retired 21 accounts plus Undeposited funds, Credit card, Rent, Software
    and hosting, Other income (§6); US-style sales tax (output tax split to 2100, purchase tax posted gross; a VAT
    business adds an input-tax account later). Confirm, or give your chart.
12. **The cash flow statement is indirect** (net income adjusted by balance-sheet changes by subtype) in v1;
    a direct one is Extended. Confirm.
13. **Kernel items K13–K16 and H1** approved and built in their repositories beside this application, K13 first.
    Approve.
14. **Ports pinned** before `apply`: `APP_INTERNAL_PORT=8184`, `MCP_RECORDS_PORT=8829`, `MCP_ACTIVITY_PORT=8830`
    (the block after Cidery's 8183/8827/8828). Confirm.

## 14. Ports, names and files

- Repository `/srv/apps/gl` (origin `github.com/maludb/maludb-os-gl`, private); database `<tenant>_gl`
  (`subello_gl` here); roles `gl_rw`, `gl_records_ro`, `gl_activity_ro`; MaluDB: the tenant's one memory, episodes
  tagged `gl`.
- `gl.<domain>`; `APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT` **all three in `env.required`** and
  written to `config/.env` before `apply` (§13.14); `deploy/` files are templates (`{{APP_FQDN}}`, `{{APP_DIR}}`,
  `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`).
- Units: `gl-records-mcp`, `gl-activity-mcp`, `gl-activity-ingest` (+ timer, every minute), `gl-directory-sync`
  (+ timer, every minute), `gl-worker` (+ timer, every minute: the outbox, the AI statement poll (hourly inside the
  worker), recurring templates falling due, reminders falling due, claims aging, dispatch).
- `maludb-os.json`: `catalog_key gl`, `name "General Ledger"`, `business_area Finance`, `category accounting`,
  `criticality high`, `grant_standing_departments false` (K14), no `scopes`, `sso`, `directory {reads: true,
  writes: false}`, `assistant {command_bar: true, agent: expert}`, `agents [expert, bookkeeper]` each
  `hired_on_install: true`, `approvals` (§5), endpoints (records, activity, web, health), services (above),
  `shares [account_balances, ap_due_summary, ar_aging_summary]`, no `reads` in version 1.
- Env: the contract's required keys plus `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` (optional, as Help
  Desk), `ATTACHMENT_MAX_BYTES`, `GL_LINK_LIFETIME_DAYS`.
- Vhost allow-list (public): `/i/`, `/api/v1/health`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/gl.json` (made by the installer), the K13 catalog seed migration.

## 15. The owner's answers (2026-10-04 — rules, not questions)

Every recommendation of §13 was taken, in one sitting, the same day the plan was written.

| # | Decision | Where it lives |
|---|---|---|
| D1 | **"General Ledger", catalog key `gl`** — `gl.<domain>`, `maludb-os-gl`, `<tenant>_gl`, roles `gl_rw` / `gl_records_ro` / `gl_activity_ro` | `maludb-os.json`; §14 |
| D2 | **Five roles**: Viewer (key `user`), Bookkeeper, Controller, Accountant (an external's role, **without** `ap.approve`), GL admin | §3; `gl_roles`; `app_roles` |
| D3 | **Nobody by default**: not a default application; never installed with `--grant-standing-departments`; each person granted by the super-admin; the repository stays **private** | §10; K14; the org |
| D4 | **Scope none**; departments and sites are dimensions on every line; one installation is one legal entity, one chart, one base currency | §3; `journal_lines.department_id` / `location_id` |
| D5 | **The kernel's AI statement becomes one draft bill per provider per closed month**, lines by department, the provider a vendor, approved by the controller like any bill; open months shown, never imported | §8; slice 7; `ai_statements`, `bills.source = 'ai_usage'` |
| D6 | **Two shipped agents, hired on install**: the expert (command bar) and the Bookkeeper (`30 6 * * *`); the morning note goes to the first super-admin until the owner names another | §5; `os/{expert,bookkeeper}.md`; `agents[]` |
| D7 | **Agents draft; a person posts, pays, sends, closes**: posting and every money action pause under `money_out`, sending under `external_send`, `period_close` and `reconciliation_complete` refused to agents by the default policy | §5; `approvals[]` |
| D8 | **No PDF renderer in v1**: an invoice is a print-ready page behind its secure link, mailed as a link plus an HTML summary; dompdf is the first Extended item | §4; §11; slice 4 |
| D9 | **The customer's claim**: the secure page offers "I paid this on … by …" (`receipt_claims`, confirmed by the bookkeeper when the money shows); the link dies **180 days** after the invoice is paid | §4; `invoice_links_secure`; `gl_settings.link_lifetime_days` |
| D10 | **Numbering**: invoices and credit notes gapless on issue (`INV-00001`, `CN-00001`); journal entries on post (`JE-000001`); bills at creation (`BILL-000001`, vendor reference unique per vendor); `RCT-`, `PAY-`, `DEP-`, `RUN-`; prefixes are settings | `document_sequences`; `gl_settings` |
| D11 | **The seeded chart**: the kernel's retired 21 accounts plus Undeposited funds, Credit card, Rent, Software and hosting, Other income; **US-style sales tax** (output tax to 2100, purchase tax posted gross; input tax a later account) | §6; db/00N seed; `posting_rules` |
| D12 | **Cash flow statement indirect** in v1 (net income adjusted by balance-sheet changes by subtype); the direct method Extended | §6 `gl_cash_flow()`; slice 3 |
| D13 | **K13–K16 and H1 approved**, built in their own repositories beside this application, **K13 first** | §12 |
| D14 | **Ports pinned before `apply`**: `APP_INTERNAL_PORT=8184`, `MCP_RECORDS_PORT=8829`, `MCP_ACTIVITY_PORT=8830`, all three in `env.required` | §14; `config/.env`; `deploy/ROOT_STEPS.sh` |

## 16. State

**SLICE 5 — payables, BUILT 2026-10-05 by the worker (Sonnet 5.5)** (`docs/build-specs/payables.md`): vendors and contacts (cards; AI providers marked and read-only on kind), bills (the lines editor on the expense side, their document on the same form, the vendor's reference unique, approved by the Controller and posted gross against the vendor, void, copy, the "no document" chip, tabs and filters, CSV), vendor credits (draft, issue by the Controller, apply, unapply, void), payments (prepared by the bookkeeper, approved by the controller, recorded when the money moved: DR AP / CR the bank; void while unmatched), and payment runs (one payment per vendor from a due-by date, cheque numbers from a first number at approval, record on the pay date, the CSV, void with "partly recorded"). 33 actions, 18 screens. **370 checks green** (`tests/phase3/slice5/run.sh`, under `php -S` and Apache); slices 1–4, Phase 2 and Phase 0 still green. No schema change. **Four decimals** on a unit price now everywhere (`parse_unit_price`). Next: slice 6 (cash).

**SLICE 4 — receivables, BUILT 2026-10-05 by the worker (Sonnet 5.5)** (`docs/build-specs/receivables.md`): customers and contacts (cards; the address and notes only for `ar.write`), invoices (the lines editor, issue → numbered and posted with the link held, send, remind in three steps, void, write-off, delete a draft, rotate the link, the print page), credit notes (drafted, issued, applied, voided), receipts (recorded to Undeposited funds or the bank, applied oldest first, voided), the customer's claims (confirm into a receipt or dismiss), deposits (draft, record, void), the customer statement with Send, and the public door — the customer's secure page `/i/<token>` with its claim form, honeypot and rate limit. 38 actions, 21 screens. **292 checks green** (`tests/phase3/slice4/run.sh`, under `php -S` and Apache); slices 1–3, Phase 2 and Phase 0 still green. **A schema fix: db/017** lets a void deposit release its receipts (voiding a recorded deposit never worked). **For slice 8: the sender never sends an outbox row of kind `invoice_link` (the held link, `skipped`).** Next: slice 5 (payables).

**SLICE 3 — the statements, BUILT 2026-10-05 by the worker (Sonnet 5.5)** (`docs/build-specs/statements.md`): the Statements page (four cards with their state and the posted-through line), the profit and loss (compare previous / last year, department and site), the balance sheet (the equation, current earnings, an as-of compare), the trial balance, the cash flow with its check; the reports — who owes us / whom we owe (per currency, linked), sales tax, spend, revenue, the customer statement, the control check; the table, the CSV (logged `export.download`) and the print page from one table shape. **127 checks green** (`tests/phase3/slice3/run.sh`); slices 1–2, Phase 2 and Phase 0 still green. **ONE QUESTION FOR THE OWNER, written in the spec's Open questions: the statement functions of db/015 carry no currency, so the four statements are one base-currency table (with a warning when another currency is in the books) rather than one per currency; the agings do split.** Next: slice 4 (receivables).

**SLICE 2 — journal core, THE EXEMPLAR, BUILT and proven 2026-10-05** (`docs/build-specs/journal-core.md`): the journal as a ledger (drafts first, filters, 50 a page, totals per
currency, the CSV), the entry page (head, the lines editor with the balance strip and Post, the source document, the reversal links, attachments, notes, the timeline, the actions
by status), draft / update / line save / line remove / post / post batch / reverse / void with the database as the referee, attachments and notes generic over the record map
(`RECORD_VIEWS`), the eval guard in the kit; 11 actions, 4 screens. **Proof:** `tests/phase3/slice2/run.sh` — **161 checks green** (draft 28, post 23, reverse 20, records 23, list 20,
agents 10, json 10, browser 27); slice 1 (219), Phase 2 (251) and Phase 0 still green. **THE HANDOFF POINT: slices 3–9 are the worker's** (gl CLAUDE.md "Build order and the handoff").

**SLICE 1 — the chart and fiscal time, BUILT and proven 2026-10-05** (`docs/build-specs/chart-fiscal.md`, the CRUD exemplar for workers): the chart as cards by type with
balances as of a date, the account page with its activity, running balance, children and CSV, add/edit/archive (the trigger and the archive guard as the referee), the fiscal
years with twelve period chips, the period page with what posted, drafts, the close checklist and the close/reopen/year-close/year-reopen buttons, tax rates, items, posting
rules, sites, the ledger settings, numbering, the agents screen; 16 actions, 21 screens; `account_select()` and the bootstrap-loaded money formatter for every later slice.
**Proof:** `tests/phase3/slice1/run.sh` — **219 checks green** (rights 13, chart 33, fiscal 30, settings 48, json 17, browser 77); Phase 2 (251) and Phase 0 still green. Next: slice 2
(`journal-core.md`, THE EXEMPLAR), then the handoff to Sonnet 5.5.

**PHASE 2 — BUILT and proven 2026-10-05** (`docs/build-specs/sso-shell.md`): the nxl shell phone first (the tab bar Home · Statements · Invoices · Bills · More, the sidebar from 992 px
with the groups Books / Receivables / Payables / Cash / Settings / Me shown by right, the role badge, the bell, the command bar through the kernel's chat endpoint), the dashboard's regions as
empty states (the fiscal-year banner, What waits as six count cards, Cash, This month, Who owes us, Whom we owe, The AI bill, The morning note, posted-through), My settings (how I am told:
`prefs_save`), Tokens (`token_mint`/`token_revoke`), Notifications (`notification_read`, record links), Activity (one's own trail, a record's history by the audit keys), the one money formatter,
own sign-out, the web manifest and icons, thirty placeholder screens that name their slice. **Proof:** `tests/phase2/run.sh` — **251 checks green** under `php -S` and under a real Apache serving the
rendered vhost (`GL_APP=apache`): sso 43, gates 83 (the 36 screens × 5 roles matrix), sync 25, ingest 12, kernel_compat 9, vhost 27, browser 52 (375 × 740 and 1280 × 800, JS off, installable), the
registry check; Phase 0 (15 + 151) still green; the installer's `plan` clean. Owed: ▲ the live launch after the owner's `apply`. Next: slice 1 (`chart-fiscal.md`).

**PHASE 1 — written 2026-10-05, for the checkpoint.** The tool surface (`docs/gl-mcp-tool-surface.md`: 52 records tools — `app_roles`,
`records_search` and the three K7 shares included — and 7 activity tools; every question of §7 named to a tool; the resolve table of 24
entities; the log-payload rules), the action manifest (`docs/gl-action-manifest.md`: **92 screens, 124 actions in 9 sections; 69 actions carry
an approval category** — 33 `money_out` (every posting and every record of money moved or a claim on it changed), 3 `external_send`
(invoice, reminder, statement), 12 `deletion`, 21 `other` (configuration and the period/reconciliation controls the default policy refuses to
agents)), the registry built from it clean (`mcp/action_registry.json`, no unresolved endpoint, no prose parameter), `maludb-os.json`'s
`approvals[]` regenerated from the manifest by `bin/sync_approvals.php` (one source; `--check` in every proof), and ten specs in
`docs/build-specs/`: `sso-shell` (Phase 2), `chart-fiscal` (1, the CRUD exemplar for workers), **`journal-core` (2, THE EXEMPLAR)**, `statements`
(3), `receivables` (4), `payables` (5), `cash` (6), `ai-statement` (7), `recurring-worker` (8), `reports-settings` (9). Every action and screen
name in the specs matches the manifest. The installer's `plan` reads the repository clean with the registry present.

**Phase 1 — APPROVED by the owner 2026-10-05**, with the nine answers below taken as recommended: (1) a document-born entry is reversed only
by the document's void; (2) manual lines on a bank account's ledger account are allowed and offered as match candidates; (3) tables only, no
charts in v1; (4) the claim's amount editable, defaulting to the balance; (5) reminder wording fixed in code; (6) cheque numbers sequential from a
first number at a run's approval; (7) hashed references with a per-file index, stated on the result; (8) the worker sends first reminders once
the reminder days are set; (9) the stand-in icon until the owner's artwork. Phase 2 begins the same day.

**Questions for the owner at the checkpoint** (answered above, 2026-10-05):
1. **Reversing a document-born entry from the journal page** — refused; the document's void does it (journal-core). Proposed: keep the refusal.
2. **Manual lines on a bank account's ledger account** — allowed, and they become match candidates (journal-core). Proposed: yes.
3. **Charts** — tables and count cards only in v1 (statements). Proposed: tables only.
4. **The customer's claim amount** — editable on the link, the bookkeeper confirms the real amount (receivables). Proposed: editable.
5. **Reminder wording** — three fixed steps in code, editable templates Extended (receivables). Proposed: fixed in v1.
6. **Cheque numbers** — assigned sequentially at a run's approval from a "first cheque number" (payables). Proposed: yes.
7. **Hashed references** for a bank without one — date + amount + description + a per-file index (cash). Proposed: yes, stated on the result.
8. **Reminders sent by the worker on its own** once `reminder_days` is set, or only drafted for a person to release (recurring-worker). Proposed: the worker sends — the setting is the consent.
9. **The logo** — `feather-book-open` stands in until your artwork (sso-shell).

Next, on approval: **Phase 2** (`docs/build-specs/sso-shell.md`), then slice 1 and slice 2 (the exemplar), then the handoff.

**PHASE 0 COMPLETE — 2026-10-04.** The schema `db/001`–`016` (2,900 lines: the mirror with sites as the ledger's own dimension;
the activity log with the audit keys on every row; tokens; five roles and fourteen rights with `gl_has_right()`; the chart with the
control-account and bank guards and the seeded chart with 14 posting rules; settings and gapless numbering; fiscal years made with
their periods, close and reopen as functions; the journal's triggers — born a draft, posts only balanced in an open period with
every control line naming its party, immutable once posted, reversal and void, the year-end close and its reversal; parties,
attachments, notes; receivables — invoices numbered and posted on issue, credit notes, receipts and applications, the customer's
claim, deposits through Undeposited funds, the secure link; payables — bills with the vendor-reference guard posted on approval,
vendor credits, payments approved then recorded, payment runs; the kernel's AI statement kept verbatim and imported as one bill per
provider rounded once to the cent, with the agree/differ check; cash — bank accounts over `is_bank` accounts, imported lines with
the duplicate guard, matching to the cent, entries from fee lines, transfers, reconciliation that refuses out of balance and stamps
its lines; recurring templates, notifications, the outbox, dispatches; the statements as SQL functions; 49 `mcp_*` views).
**Proof:** `db/proof/phase0_proof.sql` — **151 checks** on the scratch database (every actor of §2, the life of an invoice and a
bill and a receipt and a payment, every refusal by name, the statements to the cent, the AI bill's rounding, May and June
reconciled, the close and its reversal, who sees what as the records and activity roles). `tests/phase0/run.sh` — the kit
(Help Desk's identity, roles, sign-on, handler, HTTP and MCP common files, un-ticketed) against `php -S` and the fixture: **15
checks**. The kernel's installer `plan` reads the repository clean (16 migrations, the catalog row K13 seeded by db/167, 50
approval categories covered, seven skills, two agents proposed; the ports unfilled until `ROOT_STEPS.sh` step 0 pins them — D14).
`maludb-os.json` (every port env in `env.required`, `grant_standing_departments: false` for K14, the three `shares[]`, the two agents
with their grants and the Bookkeeper's duty, 50 approvals), `os/{expert,bookkeeper}.md`, seven skills (four runbooks),
`deploy/` templates with the one public door `/i/`, `ROOT_STEPS.sh`, `kernel-registry-gl.json` (23 resolved entities).

**Decisions taken while building** (conservative readings; the owner may overturn any):
1. **Departments and sites are filters, never walls** (§3's "a Viewer who is a dept-admin sees only their departments" is NOT built):
   a holder of a role sees the business's books whole — a trial balance with rows missing is worse than none. Per-department
   visibility is an Extended option if a business wants it.
2. **Sites are the ledger's own table** (`sites`, with an optional `kernel_location_id`): the directory feed carries no locations
   and the ledger is unscoped, so the admin names a site and may pin it to the kernel's location for a scoped sibling's imports.
3. **A document posts when it becomes true**: an invoice on ISSUE, a credit note on issue, a bill on APPROVAL, a receipt when
   recorded, a deposit when recorded, a payment when RECORDED (after approval), a transfer at once — one transaction each, the
   entry naming its source; there is no deferred "posting run" (the retired kernel spec's was a workaround for separate modules).
4. **A reversed entry stays in the books** (status `reversed`, its reversal `posted`): the statements count both and the pair nets
   to zero; the original period still shows the original. Only `void` (a draft) leaves the books.
5. **A bank account's `opening_balance` is the bank's balance at the END of its opening date**; the ledger carries that balance by an
   opening journal entry (source `opening`), so the ledger balance is the ledger's alone and "ledger = cleared" is a real check.
6. **The year-end close needs every period closed and no draft dated in the year**; it opens the last period for one entry and
   closes it again; reopening the year reverses the entry and leaves the periods closed.
7. **The AI import's remainder goes on the largest department line**, once; a provider × currency with a zero total makes no bill;
   a line with no department names "No department".
8. **A match is to the cent and signed**, a transfer may be matched on both its accounts, a reconciled line is never unmatched or
   deleted, only the latest completed reconciliation reopens, one in progress per account.
9. **Writer functions are revoked from PUBLIC** (a function is PUBLIC-executable by default): the records role may read the
   statements and the candidates and nothing that writes; the search tool's deny list names every `gl_*` writer.
10. The kit's `require_visible()` replaces Help Desk's ticket gates: a record the member may not see does not exist to them.

Next: **Phase 1** — `docs/gl-mcp-tool-surface.md` (every question of §7 a tool, the resolve table of `kernel-registry-gl.json`),
`docs/gl-action-manifest.md` (the screens and the 50-odd actions with the categories `maludb-os.json` already names),
`mcp/action_registry.json` built clean, and the ten specs in `docs/build-specs/` (`sso-shell` + slices 1–9, `journal-core` the
exemplar) — the checkpoint before any PHP. Then Phase 2, slice 1, slice 2 (the exemplar), and the handoff to the worker model
(CLAUDE.md, "Build order and the handoff").

**2026-10-04 — the plan approved.** The plan written (this document) and the repository `maludb/maludb-os-gl` created
private in the org and cloned to `/srv/apps/gl`; the owner answered all fourteen questions of §13 the same day, every
recommendation taken (§15, D1–D14).
