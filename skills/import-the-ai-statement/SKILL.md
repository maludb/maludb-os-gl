---
name: import-the-ai-statement
description: The runbook for bringing the kernel's closed AI statement into General Ledger — pull, check the month is closed, import as one draft bill per provider, verify the bills agree to the cent, report. For the Bookkeeper's duty or any agent asked about the AI bill.
kind: runbook
---

# Import the AI statement (a runbook)

1. `ai_statement_status` — the months the kernel reports and what the ledger holds of each.
2. A month the OS has **closed** and the ledger has **not imported**: `ai_statement_pull` (the document is kept verbatim), then
   `ai_statement_import` — one DRAFT bill per provider and currency, lines by department, rounded once to the cent, the
   provider a vendor made on first sight. Say which bills were made and their totals.
3. A month already imported: `ai_statement_check`. Every provider **agrees** → say so. One **differs** → name the provider, the
   statement's amount, the bill's amount and the difference; a person decides (a bill was edited, or voided).
4. A month still **open** in the OS: say "not yet closed in the OS"; never import it (late calls would be missed).
5. The bills await the controller's approval (`bill_approve` is a person's); say so in the morning note.
