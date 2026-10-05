---
name: match-the-bank
description: The runbook for matching the bank's lines in General Ledger — each unmatched line to exactly one ledger item of the same amount within five days, a fee or interest to a new entry, anything doubtful left for a person; what a reconciliation needs before it completes. For the Bookkeeper's duty or any agent asked to match or reconcile.
kind: runbook
---

# Match the bank (a runbook)

1. `unmatched_bank_lines` — per bank account, oldest first. Nothing: report "the bank is matched" and stop.
2. Per line: `match_candidates`. Exactly ONE candidate to the cent within five days → `bank_transaction_match` with the candidate's `item_type` and its `item_id` as `item` (digits — never a name: the word `item` is also a product to the lookup). Several → leave
   it and list the candidates. None → leave it; say what it looks like (a fee, interest, a deposit not yet recorded, a payment not
   yet recorded) so a person records the item or makes the entry (`bank_entry_from_line` is a person's).
3. Never `bank_transaction_ignore`: deciding a line is not real is a person's.
4. A reconciliation (`reconciliation_start`, `reconciliation_complete`) is a person's; before they start one, say whether every
   line through the statement date is matched or ignored — the difference must be zero or it refuses.
5. Report: matched N, left L (each with its date, amount and what it looks like), per bank account.
