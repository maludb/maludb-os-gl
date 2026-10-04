---
name: gl-basics
description: How General Ledger (the business's books) is organised and which tool answers which question — the chart of accounts, journal entries and posting, fiscal periods, invoices and receipts, bills and payments, bank accounts and reconciliation, the kernel's AI statement, who sees what. Use before any question or action about the books.
---

# General Ledger basics

## The shape
- An **account** has a code (`1200`), a name, a type (asset, liability, equity, income, expense) and a subtype the statements group by.
  **Control accounts** (Accounts receivable `1200`, Accounts payable `2000`) take no line without its customer or vendor. A **bank
  account** stands behind an account flagged `is_bank`.
- A **journal entry** (`JE-000123`, numbered when posted) is dated lines of debits and credits that balance to the cent. **Draft** →
  **posted** (into an open period) → never changed; a correction is a **reversal** (a new entry, sides swapped, naming the original).
  A draft may be **voided**. Every entry carries its **source**: manual, invoice, bill, receipt, payment, deposit, transfer, ai_usage…
- A **fiscal year** has twelve **periods**; a closed period refuses posting; reopening needs a reason and is on the record. The
  **year-end close** moves the year's result to Retained earnings.
- **Receivables**: a **customer**; an **invoice** (`INV-00042`, numbered and posted when **issued**; sent, viewed through its secure
  link, partially paid, paid, void, written off); a **receipt** (customer money in: to a bank account or to **Undeposited funds**);
  a **deposit** groups undeposited receipts into one bank line; a **credit note**; a customer's **claim** ("I paid this") from the link.
- **Payables**: a **vendor**; a **bill** (`BILL-000123`, the vendor's own reference unique per vendor; posted when **approved** —
  expense gross against payable); a **payment** (money out: draft → approved → recorded; a RECORD of money a person moved — the
  ledger moves none); a **payment run** (several payments at once); a **vendor credit**.
- **Cash**: the bank's lines (imported or typed) are **matched** to the cent to a receipt, deposit, payment, transfer or entry, or
  **ignored** with a reason; a **reconciliation** against a statement balance completes only at zero difference.
- **The AI statement**: the kernel's closed month, kept verbatim, imported as one draft bill per provider, lines by department; the
  check says whether the bills agree with the statement to the cent.
- A record's **history** is its activity rows (`record_history`), not a separate log; every money event carries amount and currency.

## Which tool
| Question | Tool |
|---|---|
| The P&L, balance sheet, cash flow, trial balance | `profit_and_loss`, `balance_sheet`, `cash_flow`, `trial_balance` |
| One account's balance or activity | `account_balance`, `account_activity` |
| An entry, by number or words; what is unposted | `find_journal_entries`, `get_journal_entry`, `unposted_entries` |
| Which periods are open or closed | `find_periods` |
| Who owes us, a customer's statement, an invoice | `ar_aging`, `customer_statement`, `find_invoices`, `get_invoice` |
| Whom we owe, what is due this week, a bill | `ap_aging`, `ap_due`, `find_bills`, `get_bill` |
| Money in, money out | `find_receipts`, `find_payments`, `find_payment_runs` |
| Our cash, the bank's unmatched lines, a line's candidates | `cash_position`, `unmatched_bank_lines`, `match_candidates` |
| What the AI cost, and does it agree | `ai_statement_status`, `ai_statement_check` |
| The account for a thing | `find_accounts`, `posting_rules` |
| Who did what | `record_history`, `actor_timeline` |

## Who sees what
The books are the business's: a holder of a role sees every record the role admits, business-wide; departments and sites are
filters. Contact details of a customer or vendor are shown to those who keep that ledger. Agents follow the same rule as people.
