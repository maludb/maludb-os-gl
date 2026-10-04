---
name: month-end-close
description: What must be true before a fiscal period of General Ledger closes, in order — every draft posted or voided, the AI statement imported and agreeing, the bank reconciled through the month, receivables and payables tied to the ledger, the statements run; and the year-end close. For the controller's checklist and the Bookkeeper's note.
kind: runbook
---

# Month-end close (a runbook)

A period closes when nothing dated in it is still becoming true. Check, in order, and report each as done or owed:

1. **Drafts**: `unposted_entries` for the month — every draft posted or voided; every draft invoice issued or deleted; every draft
   bill approved or voided.
2. **The AI statement**: the month's statement imported and `ai_statement_check` agrees; its bills approved.
3. **Receipts and deposits**: no receipt in Undeposited funds older than the month without a deposit.
4. **The bank**: every bank account reconciled through the month's last day (`cash_position` shows last reconciled through).
5. **The subledgers**: the receivable and payable control accounts agree with the aging (the audit page's tie-out).
6. **Recurring**: every template due in the month ran.
7. **The statements**: `trial_balance` balances; `balance_sheet` holds; `profit_and_loss` and `cash_flow` for the month read.
8. Then a person with periods.manage closes the period (`period_close`). Reopening later needs a reason and is on the record.

**Year end**: every period closed, no draft dated in the year, then `year_end_close` (a person's): one entry moves the year's
income and expense to Retained earnings. Reopening the year reverses it.
