---
name: the-chart-and-posting-rules
description: The seeded chart of accounts of General Ledger, how a document finds its accounts (the posting rules), when to add an account and when not, and what debit and credit mean for each type. Use when choosing an account for a line, explaining what a document posted, or asked to add an account.
---

# The chart and the posting rules

## Debits and credits
Assets and expenses grow by **debit**; liabilities, equity and income grow by **credit**. An entry balances when its debits
equal its credits. "DR 6200 / CR 2000" means an expense recognised and a payable owed.

## The seeded chart (renameable; system accounts never deleted)
`1000` Cash on hand · `1010` Business checking (bank) · `1050` Undeposited funds · `1200` Accounts receivable (control) · `1400`
Inventory · `1500` Fixed assets · `2000` Accounts payable (control) · `2100` Sales tax payable · `2200` Payroll liabilities ·
`2300` Credit card (bank) · `3000` Owner equity · `3900` Retained earnings · `4000` Sales revenue · `4100` Service revenue ·
`5000` Cost of goods sold · `6000` Salaries and wages · `6050` AI and model usage · `6100` Rent · `6200` Software and
subscriptions · `6300` Professional services · `6400` Advertising and marketing · `6500` Bank and payment fees · `6600` Bad
debt · `6900` Other expenses · `7000` Other income.

## What posts where (the rules, read by the tools — never hard-coded)
| Document | Posts |
|---|---|
| Invoice issued | DR 1200 (the customer) / CR each line's income account / CR 2100 the tax |
| Credit note issued | the reverse |
| Receipt recorded | DR 1050 Undeposited funds (or the bank it names) / CR 1200 (the customer) |
| Deposit recorded | DR the bank / CR 1050 |
| Write-off | DR 6600 / CR 1200 (the customer) |
| Bill approved | DR each line's expense account for the line's GROSS total (purchase tax is a cost) / CR 2000 (the vendor) |
| Vendor credit issued | the reverse |
| Payment recorded | DR 2000 (the vendor) / CR the bank |
| The AI statement's bills | DR 6050 by department / CR 2000 (the provider) |
| A fee or interest on the bank's line | DR 6500 / CR the bank, or DR the bank / CR 7000 |
| Transfer | DR the receiving bank / CR the sending bank |

## When to add an account
Add one when a NEW kind of thing recurs and the statements should show it apart (a second revenue line, a new category of
expense). Do not add one for a vendor, a customer, a project or a month — those are parties and dimensions. A new account is
`account_save` with a code in its type's range, under a parent of the same type; a person with settings.manage does it.
