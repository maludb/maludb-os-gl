---
name: collections-review
description: The runbook for the receivables walk in General Ledger — who is past terms and by how much, claims not yet confirmed, what was reminded and when, what to draft; never a threat, never a write-off. For the Bookkeeper's duty or any agent asked who owes us.
kind: runbook
---

# Collections review (a runbook)

1. `ar_aging` as of today, per customer and currency: current, 1–30, 31–60, 61–90, over 90.
2. `open_claims`: customers who said on the link that they paid — list each (invoice, date, method, reference) for the bookkeeper to
   confirm against the bank; never confirm one yourself.
3. For each invoice past terms: `get_invoice` for its reminders. Past a reminder day in the settings and not reminded in the last
   seven days → `invoice_remind` (it pauses for a person; say it is waiting). Over 90 days → name it for a person to call; never
   a write-off (`invoice_write_off` is the controller's).
4. Report: total owed and over 90, the three largest balances, the claims to confirm, the reminders drafted — no customer's contact
   details.
